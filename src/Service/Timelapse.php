<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Dolibarr\Services as DolibarrServices;
use WebcamPortal\Log;
use WebcamPortal\Site\Images;
use WebcamPortal\Telegram\Notify;

/**
 * Timelapse (M7, O8, M10), servizi-v1.md "Timelapse": lavori in coda nella
 * tabella timelapses, fotogrammi scelti dai punteggi delle foto buone,
 * preparati con GD (ritaglio, riduzione, sovrimpressione) e montati con
 * ffmpeg se c'è. Un lavoro alla volta per server (storage/locks/timelapse.lock).
 */
final class Timelapse
{
	public const FPS = ['day' => 12, 'week' => 15, 'month' => 25, 'custom' => 25, 'final' => 25];
	private const LOG = 'timelapse';
	private const MIN_FRAMES = 24;

	// ------------------------------------------------------------ parametri

	/** timelapse_params della webcam (crop_x_pct, crop_y_pct, same_light_time, good...). */
	public static function params(int $webcamId): array
	{
		$v = Db::scalar('SELECT timelapse_params FROM webcam_service WHERE webcam_id = ?', [$webcamId]);
		$p = is_string($v) ? json_decode($v, true) : null;
		$p = is_array($p) ? $p : [];
		return $p + ['crop_x_pct' => 50, 'crop_y_pct' => 50, 'same_light_time' => '12:00'];
	}

	public static function saveParams(int $webcamId, array $p): void
	{
		$p['crop_x_pct'] = max(0, min(100, (int) ($p['crop_x_pct'] ?? 50)));
		$p['crop_y_pct'] = max(0, min(100, (int) ($p['crop_y_pct'] ?? 50)));
		$slt = (string) ($p['same_light_time'] ?? '12:00');
		$p['same_light_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slt) ? $slt : '12:00';
		$json = json_encode($p, JSON_UNESCAPED_SLASHES);
		Db::exec(
			'INSERT INTO webcam_service (webcam_id, timelapse_params) VALUES (?, ?) ON DUPLICATE KEY UPDATE timelapse_params = VALUES(timelapse_params)',
			[$webcamId, $json]
		);
	}

	/** Il server può fare video nuovi? */
	public static function available(): bool
	{
		return (bool) Config::get()->value('timelapse.enabled', true) && Ffmpeg::status()['ok'];
	}

	// ------------------------------------------------------------ richieste

	/**
	 * Mette in coda un timelapse (o restituisce quello uguale già esistente;
	 * se è failed/cancelled/expired lo rimette in coda).
	 *
	 * @param array<string,mixed> $opt selection, same_light_time, overlay, width, priority, requested_by, user_id, customer_id
	 */
	public static function request(int $webcamId, string $kind, string $from, string $to, string $format = 'landscape', array $opt = []): int
	{
		if (!isset(self::FPS[$kind]) || !in_array($format, ['landscape', 'portrait'], true)) {
			throw new \InvalidArgumentException('Tipo o formato del timelapse non valido');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
			throw new \InvalidArgumentException('Periodo non valido');
		}
		$days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
		$selection = (string) ($opt['selection'] ?? match ($kind) {
			'day' => 'all',
			'week', 'month' => 'good',
			default => $days > 62 ? 'one_per_day' : 'good',
		});
		if (!in_array($selection, ['all', 'good', 'one_per_day'], true)) {
			throw new \InvalidArgumentException('Scelta dei fotogrammi non valida');
		}
		$tp = self::params($webcamId);
		[$wd, $ht] = $format === 'portrait' ? [1080, 1920] : ((int) ($opt['width'] ?? 1920) === 1280 ? [1280, 720] : [1920, 1080]);
		$slt = $selection === 'one_per_day' ? (string) ($opt['same_light_time'] ?? $tp['same_light_time']) : null;
		$existing = Db::one('SELECT id, status FROM timelapses WHERE webcam_id = ? AND kind = ? AND period_from = ? AND period_to = ? AND format = ? AND selection = ?', [$webcamId, $kind, $from, $to, $format, $selection]);
		if ($existing !== null) {
			if (in_array($existing['status'], ['failed', 'cancelled', 'expired', 'skipped'], true) && !empty($opt['requeue'])) {
				Db::exec("UPDATE timelapses SET status = 'queued', status_reason = NULL, attempts = 0, priority = ? WHERE id = ?", [(int) ($opt['priority'] ?? 1), $existing['id']]);
			}
			return (int) $existing['id'];
		}
		$customer = $opt['customer_id'] ?? self::customerAt($webcamId, $from);
		$params = ['good' => PhotoScores::params($webcamId)[0], 'every_min' => $kind === 'week' ? 30 : 60, 'max_s' => $kind === 'final' ? (int) ($opt['duration_s'] ?? 90) : 60];
		Db::exec(
			'INSERT INTO timelapses (webcam_id, customer_id, kind, period_from, period_to, format, width, height, selection, same_light_time, fps, overlay,
			   crop_x_pct, crop_y_pct, params, priority, requested_by, user_id)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$webcamId, $customer, $kind, $from, $to, $format, $wd, $ht, $selection, $slt, self::FPS[$kind], array_key_exists('overlay', $opt) ? (int) (bool) $opt['overlay'] : 1,
				(int) $tp['crop_x_pct'], (int) $tp['crop_y_pct'], json_encode($params), (int) ($opt['priority'] ?? 5),
				(string) ($opt['requested_by'] ?? 'auto'), $opt['user_id'] ?? null]
		);
		return (int) Db::pdo()->lastInsertId();
	}

	/** Cliente dell'assegnazione in corso a mezzogiorno UTC del giorno. */
	public static function customerAt(int $webcamId, string $day): ?int
	{
		$t = Db::dt((int) strtotime($day . ' 12:00:00 UTC'));
		$v = Db::scalar('SELECT customer_id FROM customer_webcams WHERE webcam_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?) ORDER BY valid_from DESC LIMIT 1', [$webcamId, $t, $t]);
		return $v === null ? null : (int) $v;
	}

	/**
	 * Lavori automatici: day alle 01:00 del giorno dopo, week il lunedì alle
	 * 02:00, month il 2 alle 03:00 (ora locale), filmato finale a fine
	 * noleggio. Senza ffmpeg non si crea niente.
	 */
	public static function autoCreate(int $now): int
	{
		if (!self::available()) {
			return 0;
		}
		$n = 0;
		foreach (Db::all('SELECT * FROM webcams WHERE status = 1 ORDER BY id') as $w) {
			Features::reset();
			if (!Features::has($w, 'timelapse') && !Features::has($w, 'final_film')) {
				continue;
			}
			$tz = ServiceSettings::tz($w);
			$loc = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
			$formats = Features::has($w, 'timelapse_portrait') ? ['landscape', 'portrait'] : ['landscape'];
			$wid = (int) $w['id'];
			$todo = [];
			if (Features::has($w, 'timelapse')) {
				if ((int) $loc->format('G') >= 1) {
					$y = $loc->modify('-1 day')->format('Y-m-d');
					$todo[] = ['day', $y, $y];
				}
				$monday = $loc->modify('monday this week')->setTime(2, 0);
				if ($loc >= $monday) {
					$todo[] = ['week', $monday->modify('-7 days')->format('Y-m-d'), $monday->modify('-1 day')->format('Y-m-d')];
				}
				$second = $loc->modify('first day of this month')->setTime(3, 0)->modify('+1 day');
				if ($loc >= $second) {
					$pm = $loc->modify('first day of last month');
					$todo[] = ['month', $pm->format('Y-m-01'), $pm->format('Y-m-t')];
				}
			}
			foreach ($todo as [$kind, $from, $to]) {
				if (Customers::currentSince($wid) !== null && Customers::currentSince($wid) > (new \DateTimeImmutable($to . ' 23:59:59', $tz))->getTimestamp()) {
					continue; // periodo del cliente di prima: niente di nuovo per un'assegnazione chiusa
				}
				foreach ($formats as $f) {
					$exists = Db::scalar('SELECT id FROM timelapses WHERE webcam_id = ? AND kind = ? AND period_from = ? AND period_to = ? AND format = ?', [$wid, $kind, $from, $to, $f]);
					if ($exists === null) {
						self::request($wid, $kind, $from, $to, $f);
						$n++;
					}
				}
			}
			$n += self::finalFilm($w, $loc);
		}
		return $n;
	}

	/** Filmato finale (M10) alla fine del noleggio, con l'opzione film di Dolibarr. */
	private static function finalFilm(array $w, \DateTimeImmutable $loc): int
	{
		if (!Features::has($w, 'final_film')) {
			return 0;
		}
		$plan = Features::plan((int) $w['id']);
		$film = (array) ($plan['options']['film'] ?? []);
		$end = $plan['rental_end'];
		if ($end === null || $loc->format('Y-m-d') <= $end) {
			return 0;
		}
		$start = $plan['rental_start'];
		if ($start === null) {
			$since = Customers::currentSince((int) $w['id']);
			$start = $since === null ? $end : (new \DateTimeImmutable('@' . $since))->setTimezone($loc->getTimezone())->format('Y-m-d');
		}
		$fmt = (string) ($film['format'] ?? 'landscape');
		$formats = $fmt === 'both' ? ['landscape', 'portrait'] : [$fmt === 'portrait' ? 'portrait' : 'landscape'];
		$days = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
		$n = 0;
		foreach ($formats as $f) {
			$sel = $days > 62 ? 'one_per_day' : 'good';
			if (Db::scalar('SELECT id FROM timelapses WHERE webcam_id = ? AND kind = \'final\' AND period_from = ? AND period_to = ? AND format = ?', [$w['id'], $start, $end, $f]) === null) {
				self::request((int) $w['id'], 'final', $start, $end, $f, ['selection' => $sel, 'requested_by' => 'rental_end', 'duration_s' => (int) ($film['duration_s'] ?? 60) ?: 60, 'priority' => 3]);
				DolibarrServices::filmStatus($w, 'queued', null);
				$n++;
			}
		}
		return $n;
	}

	// ------------------------------------------------------------ esecuzione

	/**
	 * Prende un lavoro dalla coda e lo esegue. Senza ffmpeg i lavori in coda
	 * passano a failed (ffmpeg_missing). Restituisce l'id lavorato o null.
	 */
	public static function runNext(int $now): ?int
	{
		if (!self::available()) {
			$n = Db::exec("UPDATE timelapses SET status = 'failed', status_reason = 'ffmpeg_missing', finished_at = UTC_TIMESTAMP() WHERE status = 'queued'");
			if ($n > 0) {
				Log::warning("$n timelapse in coda non fatti: ffmpeg non trovato", self::LOG);
			}
			return null;
		}
		if (!Locks::acquire('timelapse')) {
			return null;
		}
		try {
			$job = Db::one("SELECT * FROM timelapses WHERE status = 'queued' ORDER BY priority, created_at, id LIMIT 1");
			if ($job === null) {
				return null;
			}
			Db::exec("UPDATE timelapses SET status = 'running', started_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = ?", [$job['id']]);
			$job['attempts'] = (int) $job['attempts'] + 1;
			self::execute($job, $now);
			return (int) $job['id'];
		} finally {
			Locks::release('timelapse');
		}
	}

	private static function execute(array $job, int $now): void
	{
		$id = (int) $job['id'];
		$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$job['webcam_id']]);
		$tmp = self::tempDir() . '/' . $id;
		try {
			[$frames, $candidates, $rejected] = self::frames($w, $job);
			Db::exec('UPDATE timelapses SET frames_candidates = ?, frames_rejected = ? WHERE id = ?', [$candidates, json_encode((object) $rejected), $id]);
			if ($candidates === 0) {
				self::finish($id, 'skipped', 'no_photos');
				return;
			}
			if (count($frames) < self::MIN_FRAMES) {
				self::finish($id, 'skipped', 'too_few_frames');
				return;
			}
			$params = json_decode((string) $job['params'], true) ?: [];
			$maxS = (int) ($params['max_s'] ?? ($job['kind'] === 'final' ? 90 : 60));
			$fps = (int) $job['fps'];
			if (count($frames) / $fps > $maxS) {
				$frames = self::thin($frames, $maxS * $fps);
			}
			if (count($frames) / $fps < 4) {
				$fps = max(8, min($fps, intdiv(count($frames), 4)));
			}
			self::rmdir($tmp);
			@mkdir($tmp, 0775, true);
			$cfg = (int) $job['overlay'] === 1 ? Images::overlayFor($w, 'timelapse') : null;
			$i = 0;
			foreach ($frames as $p) {
				$i++;
				self::prepareFrame($w, $p, $job, $cfg, sprintf('%s/f%06d.jpg', $tmp, $i));
			}
			$dir = Config::get()->storageDir() . '/timelapse/' . (int) $w['id'];
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}
			$mp4 = "$dir/$id.mp4";
			$vf = in_array($job['kind'], ['week', 'month', 'custom', 'final'], true) ? ['-vf', 'deflicker=mode=pm:size=5'] : [];
			$args = array_merge(
				['-hide_banner', '-loglevel', 'error', '-y', '-framerate', (string) $fps, '-i', "$tmp/f%06d.jpg"],
				$vf,
				['-c:v', 'libx264', '-preset', 'medium', '-crf', '23', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-level', '4.1',
					'-threads', (string) max(1, (int) Config::get()->value('ffmpeg.threads', 1)), '-movflags', '+faststart', '-an', $mp4]
			);
			[$rc, $err] = Ffmpeg::run($args, max(30, (int) Config::get()->value('ffmpeg.timeout', 1800)));
			if ($rc !== 0 || !is_file($mp4) || filesize($mp4) === 0) {
				$lines = implode(' | ', array_slice(array_filter(explode("\n", trim($err))), -5));
				Log::error("timelapse $id: ffmpeg uscito con $rc: $lines", self::LOG);
				@unlink($mp4);
				self::fail($job, $rc === 124 ? 'timeout' : 'ffmpeg_error');
				return;
			}
			$poster = "$dir/$id.jpg";
			$mid = sprintf('%s/f%06d.jpg', $tmp, max(1, (int) ceil($i / 2)));
			$pim = imagecreatefromjpeg($mid);
			imagejpeg($pim, $poster, 85);
			imagedestroy($pim);
			$keep = (array) Config::get()->value('timelapse.keep_days', []);
			$days = (int) ($keep[$job['kind']] ?? 0);
			Db::exec(
				"UPDATE timelapses SET status = 'done', status_reason = NULL, fps = ?, frames_used = ?, frames = ?, duration_s = ?, storage_path = ?, poster_path = ?,
				   size_bytes = ?, sha256 = ?, finished_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?",
				[$fps, $i, json_encode(array_map(static fn ($p) => (int) $p['id'], $frames)), round($i / $fps, 2),
					'timelapse/' . (int) $w['id'] . "/$id.mp4", 'timelapse/' . (int) $w['id'] . "/$id.jpg", filesize($mp4), hash_file('sha256', $mp4),
					$days > 0 ? Db::dt(time() + $days * 86400) : null, $id]
			);
			Log::info("timelapse $id ({$job['kind']} {$job['period_from']}..{$job['period_to']} {$job['format']}) pronto: $i fotogrammi a $fps fps", self::LOG);
			self::afterDone($w, (array) Db::one('SELECT * FROM timelapses WHERE id = ?', [$id]));
		} catch (\Throwable $e) {
			Log::error("timelapse $id: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), self::LOG);
			self::fail($job, $e instanceof \WebcamPortal\Api\ApiError && $e->error === 'image_too_large' ? 'memory' : 'error');
		} finally {
			self::rmdir($tmp); // sempre, anche in errore
		}
	}

	private static function afterDone(array $w, array $t): void
	{
		if (in_array($t['kind'], ['week', 'month'], true)) {
			Notify::timelapse($w, $t);
		}
		if ($t['kind'] === 'final') {
			$pending = (int) Db::scalar("SELECT COUNT(*) FROM timelapses WHERE webcam_id = ? AND kind = 'final' AND period_from = ? AND period_to = ? AND status IN ('queued','running')", [$w['id'], $t['period_from'], $t['period_to']]);
			$film = (array) (Features::plan((int) $w['id'])['options']['film'] ?? []);
			if (($film['option'] ?? '') === 'edited') {
				self::bestZip($w, $t);
			}
			if ($pending === 0) {
				$base = rtrim((string) Config::get()->value('public_base_url', ''), '/');
				DolibarrServices::filmStatus($w, 'ready', $base === '' ? null : $base . '/admin/webcams/' . (int) $w['id'] . '/timelapse');
			}
		}
	}

	/** Filmato "edited": le 50 foto con il punteggio più alto del noleggio in uno ZIP per il montaggio. */
	private static function bestZip(array $w, array $t): void
	{
		if (!class_exists(\ZipArchive::class)) {
			Log::warning('ZipArchive non disponibile: ZIP delle foto migliori non creato', self::LOG);
			return;
		}
		$tz = ServiceSettings::tz($w);
		$from = (new \DateTimeImmutable($t['period_from'] . ' 00:00:00', $tz))->getTimestamp();
		$to = (new \DateTimeImmutable($t['period_to'] . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
		$rows = Db::all(
			'SELECT p.id, p.storage_path, p.file_name FROM photo_scores s JOIN photos p ON p.id = s.photo_id
			  WHERE s.webcam_id = ? AND s.good = 1 AND p.deleted_at IS NULL AND p.taken_at >= ? AND p.taken_at < ? ORDER BY s.score DESC LIMIT 50',
			[$w['id'], Db::dt($from), Db::dt($to)]
		);
		$zip = new \ZipArchive();
		$file = Config::get()->storageDir() . '/timelapse/' . (int) $w['id'] . '/final-' . $t['period_from'] . '-best.zip';
		if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return;
		}
		foreach ($rows as $r) {
			$f = PhotoStore::abs((string) $r['storage_path']);
			if (is_file($f)) {
				$zip->addFile($f, (string) $r['file_name']);
			}
		}
		$zip->close();
	}

	private static function finish(int $id, string $status, string $reason): void
	{
		Db::exec('UPDATE timelapses SET status = ?, status_reason = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $reason, $id]);
		Log::info("timelapse $id: $status ($reason)", self::LOG);
	}

	/** Al più 3 tentativi. */
	private static function fail(array $job, string $reason): void
	{
		$status = (int) $job['attempts'] >= 3 ? 'failed' : 'queued';
		Db::exec('UPDATE timelapses SET status = ?, status_reason = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $reason, $job['id']]);
	}

	public static function tempDir(): string
	{
		$d = trim((string) Config::get()->value('timelapse.temp_dir', ''));
		return rtrim($d === '' ? Config::get()->storageDir() . '/tmp/timelapse' : $d, '/');
	}

	private static function rmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}

	/** Toglie fotogrammi a intervalli regolari fino a $max. */
	public static function thin(array $frames, int $max): array
	{
		$n = count($frames);
		if ($n <= $max || $max < 1) {
			return $frames;
		}
		$out = [];
		for ($i = 0; $i < $max; $i++) {
			$out[] = $frames[(int) floor($i * $n / $max)];
		}
		return $out;
	}

	// ------------------------------------------------------------ fotogrammi

	/**
	 * Fotogrammi del lavoro (servizi-v1.md, "Tipi e scelta dei fotogrammi").
	 *
	 * @return array{0: list<array<string,mixed>>, 1: int, 2: array<string,int>} fotogrammi, candidati, scarti per motivo
	 */
	public static function frames(array $w, array $job): array
	{
		$tz = ServiceSettings::tz($w);
		$from = (new \DateTimeImmutable($job['period_from'] . ' 00:00:00', $tz));
		$to = (new \DateTimeImmutable($job['period_to'] . ' 00:00:00', $tz))->modify('+1 day');
		$rows = Db::all(
			'SELECT p.id, p.taken_at, p.mode, p.storage_path, p.width, p.height, p.webcam_id, p.time_synced, p.t_ext, p.rh_ext, p.sha256,
			        s.good, s.score, s.reject_reason
			   FROM photos p LEFT JOIN photo_scores s ON s.photo_id = p.id
			  WHERE p.webcam_id = ? AND p.taken_at >= ? AND p.taken_at < ? AND p.deleted_at IS NULL AND p.is_public = 1
			  ORDER BY p.taken_at, p.id',
			[$w['id'], Db::dt($from->getTimestamp()), Db::dt($to->getTimestamp())]
		);
		$rejected = [];
		foreach ($rows as $r) {
			if ($r['reject_reason'] !== null) {
				$rejected[$r['reject_reason']] = ($rejected[$r['reject_reason']] ?? 0) + 1;
			}
		}
		$sel = (string) $job['selection'];
		$out = [];
		if ($sel === 'all') {
			foreach ($rows as $r) {
				if (in_array($r['reject_reason'], ['hidden', 'night', 'dark', 'overexposed'], true) || ($r['reject_reason'] === null && $r['mode'] === 'night')) {
					continue;
				}
				$out[] = $r;
			}
		} elseif ($sel === 'good') {
			$params = json_decode((string) $job['params'], true) ?: [];
			$every = max(1, (int) ($params['every_min'] ?? 60)) * 60;
			$bins = [];
			foreach ($rows as $r) {
				if ((int) $r['good'] !== 1) {
					continue;
				}
				$t = (int) Db::unix((string) $r['taken_at']);
				$loc = (new \DateTimeImmutable('@' . $t))->setTimezone($tz);
				$b = $loc->format('Y-m-d') . '|' . intdiv((int) $loc->format('G') * 3600 + (int) $loc->format('i') * 60 + (int) $loc->format('s'), $every);
				if (!isset($bins[$b]) || (float) $r['score'] > (float) $bins[$b]['score']) {
					$bins[$b] = $r;
				}
			}
			$out = array_values($bins);
		} else {
			[$hh, $mm] = array_map('intval', explode(':', (string) ($job['same_light_time'] ?: '12:00')));
			$days = [];
			foreach ($rows as $r) {
				if ((int) $r['good'] !== 1) {
					continue;
				}
				$t = (int) Db::unix((string) $r['taken_at']);
				$loc = (new \DateTimeImmutable('@' . $t))->setTimezone($tz);
				$target = $loc->setTime($hh, $mm)->getTimestamp();
				$d = abs($t - $target);
				if ($d > 3600) {
					continue;
				}
				$k = $loc->format('Y-m-d');
				if (!isset($days[$k]) || $d < $days[$k][0]) {
					$days[$k] = [$d, $r];
				}
			}
			ksort($days);
			$out = array_column(array_values($days), 1);
		}
		usort($out, static fn ($a, $b) => [$a['taken_at'], $a['id']] <=> [$b['taken_at'], $b['id']]);
		return [$out, count($rows), $rejected];
	}

	/** Un fotogramma: riduzione o ritaglio con GD, sovrimpressione, JPEG qualità 90. */
	private static function prepareFrame(array $w, array $p, array $job, ?array $cfg, string $dst): void
	{
		$orig = PhotoStore::abs((string) $p['storage_path']);
		$W = (int) $job['width'];
		$H = (int) $job['height'];
		if ($job['format'] === 'portrait') {
			$src = $orig; // sempre l'originale: dalla 1920 servirebbe ingrandire
		} else {
			$big = Images::cacheFile((int) $p['webcam_id'], (int) $p['id'], '1920');
			$src = is_file($big) ? $big : $orig;
		}
		$im = Images::cropped($src, $W, $H, (float) $job['crop_x_pct'], (float) $job['crop_y_pct']);
		try {
			if ($cfg !== null) {
				Images::drawOverlay($im, $w, $p, $cfg, false);
			}
			Images::saveJpeg($im, $dst, 90, false);
		} finally {
			imagedestroy($im);
		}
	}

	// ------------------------------------------------------------ conservazione

	/** File scaduti cancellati, status = expired. @return int timelapse scaduti */
	public static function expire(?int $now = null, bool $dryRun = false): int
	{
		$rows = Db::all("SELECT id, storage_path, poster_path FROM timelapses WHERE status = 'done' AND expires_at IS NOT NULL AND expires_at < ?", [Db::dt($now ?? time())]);
		if ($dryRun) {
			return count($rows);
		}
		foreach ($rows as $r) {
			foreach ([$r['storage_path'], $r['poster_path']] as $f) {
				if ($f !== null) {
					@unlink(Config::get()->storageDir() . '/' . $f);
				}
			}
			Db::exec("UPDATE timelapses SET status = 'expired' WHERE id = ?", [$r['id']]);
		}
		return count($rows);
	}
}
