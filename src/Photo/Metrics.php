<?php

declare(strict_types=1);

namespace WebcamPortal\Photo;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Service\PhotoStore;
use WebcamPortal\Service\ServiceSettings;

/**
 * Analisi delle foto (bin/photo-metrics.php, dal cron, mai durante
 * l'upload): sceglie le foto di giorno (una ogni every_min minuti per
 * webcam, più la prima dopo la fine di ogni heater.boost), calcola le
 * metriche con Analyzer, le confronta con la foto di riferimento della
 * stessa fascia oraria e scrive photo_metrics. Poi aggiorna i riferimenti
 * (reference_photos): la foto più nitida della fascia negli ultimi 14 giorni,
 * rinnovata ogni 7 giorni, mai con un avviso glass.* o camera.moved aperto.
 */
final class Metrics
{
	private const LOG = 'photo-metrics';
	private const RULES = ['glass.fog', 'glass.dirty', 'camera.moved'];

	public array $stats = ['webcams' => 0, 'analyzed' => 0, 'skipped' => 0, 'references' => 0, 'errors' => 0];
	private int $budget;
	/** @var array<int,array> versioni in grigi dei riferimenti già aperti */
	private array $refCache = [];

	public function __construct(private int $now)
	{
		$this->budget = max(1, (int) Config::get()->value('photo_metrics.max_per_run', 300));
	}

	public static function prepareMemory(): void
	{
		$want = (string) Config::get()->value('photo_metrics.memory_limit', '512M');
		$cur = Analyzer::iniBytes((string) ini_get('memory_limit'));
		if ($cur !== 0 && Analyzer::iniBytes($want) > $cur) {
			@ini_set('memory_limit', $want);
		}
	}

	public function run(?int $webcamId = null): array
	{
		$sql = 'SELECT * FROM webcams WHERE status = 1';
		$p = [];
		if ($webcamId !== null) {
			$sql .= ' AND id = ?';
			$p[] = $webcamId;
		}
		foreach (Db::all($sql . ' ORDER BY id', $p) as $w) {
			if ($this->budget <= 0) {
				break;
			}
			$svc = ServiceSettings::get((int) $w['id']);
			if ((int) $svc['photo_metrics'] !== 1) {
				continue;
			}
			$this->stats['webcams']++;
			try {
				$this->webcam($w);
				$this->references($w);
			} catch (\Throwable $e) {
				$this->stats['errors']++;
				Log::error("webcam {$w['id']}: " . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), self::LOG);
			}
		}
		return $this->stats;
	}

	private function webcam(array $w): void
	{
		$wid = (int) $w['id'];
		$every = max(1, (int) Config::get()->value('photo_metrics.every_min', 30)) * 60;
		$lastT = Db::scalar('SELECT UNIX_TIMESTAMP(MAX(taken_at)) FROM photo_metrics WHERE webcam_id = ? AND taken_at <= ?', [$wid, Db::dt($this->now)]);
		$from = $lastT === null ? $this->now - 3 * 86400 : (int) $lastT;
		$ends = [];
		foreach (Db::all("SELECT * FROM commands WHERE webcam_id = ? AND type = 'heater.boost' AND status = 'done' AND ack_at > ?", [$wid, Db::dt($from - 7200)]) as $b) {
			$res = json_decode((string) ($b['result'] ?? ''), true) ?: [];
			$par = json_decode((string) $b['params'], true) ?: [];
			$ends[] = isset($res['until']) && is_int($res['until']) ? $res['until'] : (int) Db::unix($b['ack_at']) + (int) ($par['duration_s'] ?? 1800);
		}
		sort($ends);
		$photos = Db::all(
			"SELECT p.*, UNIX_TIMESTAMP(p.taken_at) AS t FROM photos p
			  WHERE p.webcam_id = ? AND p.taken_at > ? AND p.taken_at <= ? AND p.deleted_at IS NULL AND p.mode = 'day'
			  ORDER BY p.taken_at LIMIT 2000",
			[$wid, Db::dt($from), Db::dt($this->now)]
		);
		$picked = $lastT === null ? null : (int) $lastT;
		foreach ($photos as $p) {
			if ($this->budget <= 0) {
				return;
			}
			$t = (int) $p['t'];
			$afterBoost = false;
			foreach ($ends as $e) {
				if ($e <= $t && ($picked === null || $picked < $e)) {
					$afterBoost = true;
				}
			}
			if ($picked !== null && $t - $picked < $every && !$afterBoost) {
				continue;
			}
			$this->analyze($w, $p);
			$picked = $t;
			$this->budget--;
		}
	}

	/** Analizza una foto e scrive la riga di photo_metrics. */
	public function analyze(array $w, array $p): void
	{
		$wid = (int) $w['id'];
		$t = (int) ($p['t'] ?? Db::unix($p['taken_at']));
		$tz = ServiceSettings::tz($w);
		$band = (int) (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('G');
		$row = ['photo_id' => (int) $p['id'], 'webcam_id' => $wid, 'taken_at' => Db::dt($t), 'band' => $band, 'computed_at' => Db::dt(time())];
		$clim = Db::one(
			'SELECT rh_in, t_in, dp_in, rh_ext, t_ext, dp_ext FROM measurements WHERE webcam_id = ? AND ts BETWEEN ? AND ? AND time_synced = 1
			  ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, ?)) LIMIT 1',
			[$wid, Db::dt($t - 600), Db::dt($t + 600), Db::dt($t)]
		);
		$row['rh_in'] = $clim['rh_in'] ?? $p['rh_in'];
		$row['spread_in'] = isset($clim['t_in'], $clim['dp_in']) ? round((float) $clim['t_in'] - (float) $clim['dp_in'], 1) : null;
		$row['rh_ext'] = $clim['rh_ext'] ?? $p['rh_ext'];
		$extSat = isset($clim['rh_ext'], $clim['t_ext'], $clim['dp_ext']) && (float) $clim['rh_ext'] >= 97 && (float) $clim['t_ext'] - (float) $clim['dp_ext'] < 1;

		$file = PhotoStore::abs((string) $p['storage_path']);
		$img = null;
		try {
			if (!is_file($file)) {
				throw new \RuntimeException('file mancante');
			}
			$im = Analyzer::loadJpeg($file);
			$img = Analyzer::gray($im);
			imagedestroy($im);
			unset($im);
		} catch (MemoryException $e) {
			Log::warning("foto {$p['id']} saltata: memoria insufficiente ({$e->getMessage()}); alzare photo_metrics.memory_limit", self::LOG);
			$this->skip($row, 'memory');
			return;
		} catch (\Throwable $e) {
			Log::warning("foto {$p['id']} non analizzata: " . $e->getMessage(), self::LOG);
			$this->skip($row, is_file($file) ? 'decode_error' : 'missing_file');
			return;
		}
		$s = Analyzer::stats($img);
		$row += $s;
		$row['analyzed_width'] = $img['w'];
		if ($s['brightness'] < 40 || $s['brightness'] > 230) {
			$this->skip($row, $s['brightness'] < 40 ? 'dark' : 'overexposed');
			return;
		}
		$ref = Db::one('SELECT * FROM reference_photos WHERE webcam_id = ? AND band = ? AND valid_to IS NULL', [$wid, $band]);
		if ($ref !== null && $ref['camera_serial'] !== null && $p['camera_serial'] !== null && $ref['camera_serial'] !== $p['camera_serial']) {
			self::resetAll($wid, 'fotocamera cambiata: ' . $ref['camera_serial'] . ' -> ' . $p['camera_serial'], $t);
			$ref = null;
		}
		$row['classification'] = 'ok';
		$row['skip_reason'] = null;
		if ($ref === null) {
			$row['skip_reason'] = 'no_reference';
		} else {
			$rimg = $this->refImage($ref);
			$row['reference_id'] = (int) $ref['id'];
			$rb = (float) $ref['brightness'];
			if ($rb > 0 && abs($s['brightness'] - $rb) <= 0.35 * $rb && (float) $ref['sharpness_tiles'] > 0) {
				$row['blur_ratio'] = min(99.999, round($s['sharpness_tiles'] / (float) $ref['sharpness_tiles'], 3));
			}
			if ($rimg !== null && $rimg['w'] === $img['w']) {
				$mt = (float) Config::get()->value('photo_metrics.mask_top_pct', 6);
				$mb = (float) Config::get()->value('photo_metrics.mask_bottom_pct', 6);
				$row += Analyzer::shift(Analyzer::maskStrips($rimg, $mt, $mb), Analyzer::maskStrips($img, $mt, $mb));
			}
			$br = $row['blur_ratio'] ?? null;
			$humid = ($row['rh_in'] !== null && (float) $row['rh_in'] >= 85) || ($row['spread_in'] !== null && $row['spread_in'] < 2);
			$dry = $row['rh_in'] !== null && (float) $row['rh_in'] < 70 && ($row['spread_in'] === null || $row['spread_in'] >= 5);
			if ($br !== null && $br < 0.6) {
				$row['classification'] = $extSat ? 'fog_ext' : ($humid ? 'fog' : ($dry ? 'dirty' : 'blurry'));
			} elseif (isset($row['match_score']) && ($br === null || $br >= 0.7)
				&& ((sqrt($row['shift_x'] ** 2 + $row['shift_y'] ** 2) >= 8 && $row['match_score'] >= 0.5) || $row['match_score'] < 0.35)) {
				$row['classification'] = 'moved';
			}
		}
		$this->insert($row);
		$this->stats['analyzed']++;
	}

	private function skip(array $row, string $reason): void
	{
		$row['classification'] = 'skipped';
		$row['skip_reason'] = $reason;
		$this->insert($row);
		$this->stats['skipped']++;
	}

	private function insert(array $row): void
	{
		$cols = ['photo_id', 'webcam_id', 'taken_at', 'band', 'computed_at', 'analyzed_width', 'brightness', 'contrast', 'sharpness', 'sharpness_tiles',
			'dhash', 'reference_id', 'blur_ratio', 'shift_x', 'shift_y', 'match_score', 'rh_in', 'spread_in', 'rh_ext', 'classification', 'skip_reason'];
		$vals = [];
		foreach ($cols as $c) {
			$vals[] = $row[$c] ?? ($c === 'analyzed_width' ? 640 : null);
		}
		Db::exec(
			'REPLACE INTO photo_metrics (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
			$vals
		);
	}

	private function refImage(array $ref): ?array
	{
		$id = (int) $ref['id'];
		if (!array_key_exists($id, $this->refCache)) {
			$this->refCache = array_slice($this->refCache, -8, null, true);
			$this->refCache[$id] = Analyzer::loadPng(Config::get()->storageDir() . '/' . $ref['gray_path']);
		}
		return $this->refCache[$id];
	}

	// ------------------------------------------------------------ riferimenti

	/** Momento dell'ultimo azzeramento dei riferimenti (cambio fotocamera, nuova inquadratura). */
	public static function resetAt(int $wid): int
	{
		$f = Config::get()->storageDir() . "/reference/$wid/reset.json";
		$d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
		return is_array($d) ? (int) ($d['at'] ?? 0) : 0;
	}

	/** Tutti i riferimenti da rifare (nuova inquadratura, fotocamera cambiata). */
	public static function resetAll(int $wid, string $reason, ?int $now = null): void
	{
		$now ??= time();
		Db::exec('UPDATE reference_photos SET valid_to = ? WHERE webcam_id = ? AND valid_to IS NULL', [Db::dt($now), $wid]);
		$dir = Config::get()->storageDir() . "/reference/$wid";
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@file_put_contents("$dir/reset.json", json_encode(['at' => $now, 'reason' => $reason], JSON_UNESCAPED_UNICODE));
		Log::info("webcam $wid: riferimenti azzerati ($reason)", self::LOG);
	}

	/** Riferimento di una fascia da rifare in automatico (pannello, "Ricalcola"). */
	public static function resetBand(int $wid, int $band, ?int $now = null): void
	{
		Db::exec('UPDATE reference_photos SET valid_to = ? WHERE webcam_id = ? AND band = ? AND valid_to IS NULL', [Db::dt($now ?? time()), $wid, $band]);
	}

	/**
	 * Usa la foto come riferimento della sua fascia (scelta dell'operatore o
	 * automatica). La foto deve essere già analizzata.
	 */
	public static function setReference(int $wid, int $photoId, string $by = 'auto', ?int $userId = null, ?int $now = null): int
	{
		$now ??= time();
		$p = Db::one(
			'SELECT p.*, pm.band, pm.brightness AS m_brightness, pm.sharpness AS m_sharp, pm.sharpness_tiles AS m_tiles, pm.dhash AS m_dhash
			   FROM photos p JOIN photo_metrics pm ON pm.photo_id = p.id WHERE p.id = ? AND p.webcam_id = ?',
			[$photoId, $wid]
		);
		if ($p === null || $p['m_tiles'] === null) {
			throw new \InvalidArgumentException('Foto non analizzata: impossibile usarla come riferimento');
		}
		$file = PhotoStore::abs((string) $p['storage_path']);
		if ($p['deleted_at'] !== null || !is_file($file)) {
			throw new \InvalidArgumentException('Il file della foto non esiste più');
		}
		self::prepareMemory();
		$im = Analyzer::loadJpeg($file);
		$img = Analyzer::gray($im);
		imagedestroy($im);
		$band = (int) $p['band'];
		$rel = "reference/$wid/$band.png";
		Analyzer::savePng($img, Config::get()->storageDir() . '/' . $rel);
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			Db::exec('UPDATE reference_photos SET valid_to = ? WHERE webcam_id = ? AND band = ? AND valid_to IS NULL', [Db::dt($now), $wid, $band]);
			Db::exec(
				'INSERT INTO reference_photos (webcam_id, band, photo_id, camera_serial, brightness, sharpness, sharpness_tiles, dhash, gray_path, chosen_by, user_id, valid_from)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				[$wid, $band, $photoId, $p['camera_serial'], $p['m_brightness'], $p['m_sharp'], $p['m_tiles'], $p['m_dhash'], $rel, $by === 'operator' ? 'operator' : 'auto', $userId, Db::dt($now)]
			);
			$id = (int) $pdo->lastInsertId();
			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		Log::info("webcam $wid: riferimento della fascia $band = foto $photoId ($by)", self::LOG);
		return $id;
	}

	/** Sceglie o rinnova i riferimenti delle fasce della webcam. */
	private function references(array $w): void
	{
		$wid = (int) $w['id'];
		$open = Db::scalar(
			"SELECT 1 FROM alerts WHERE webcam_id = ? AND status = 'open' AND rule_id IN ('glass.fog','glass.dirty','camera.moved') LIMIT 1",
			[$wid]
		);
		if ($open !== null) {
			return; // mai mentre è aperto un avviso del vetro o dell'inquadratura
		}
		$from = max($this->now - 14 * 86400, self::resetAt($wid));
		$bands = Db::all('SELECT DISTINCT band FROM photo_metrics WHERE webcam_id = ? AND taken_at > ? AND taken_at <= ? AND classification = \'ok\'', [$wid, Db::dt($from), Db::dt($this->now)]);
		foreach ($bands as $b) {
			$band = (int) $b['band'];
			$cur = Db::one('SELECT * FROM reference_photos WHERE webcam_id = ? AND band = ? AND valid_to IS NULL', [$wid, $band]);
			if ($cur !== null && ($cur['chosen_by'] === 'operator' || (int) Db::unix($cur['valid_from']) > $this->now - 7 * 86400)) {
				continue;
			}
			$cand = Db::one(
				"SELECT pm.photo_id FROM photo_metrics pm JOIN photos p ON p.id = pm.photo_id
				  WHERE pm.webcam_id = ? AND pm.band = ? AND pm.taken_at > ? AND pm.taken_at <= ? AND p.time_synced = 1 AND p.deleted_at IS NULL
				    AND pm.classification = 'ok' AND pm.brightness BETWEEN 70 AND 200 AND (pm.rh_ext IS NULL OR pm.rh_ext < 80)
				    AND NOT EXISTS (SELECT 1 FROM alerts a WHERE a.webcam_id = pm.webcam_id AND a.rule_id IN ('glass.fog','glass.dirty','camera.moved')
				                     AND a.opened_at <= pm.taken_at AND (a.closed_at IS NULL OR a.closed_at >= pm.taken_at))
				  ORDER BY pm.sharpness_tiles DESC LIMIT 1",
				[$wid, $band, Db::dt($from), Db::dt($this->now)]
			);
			if ($cand === null) {
				continue;
			}
			if ($cur !== null && (int) $cur['photo_id'] === (int) $cand['photo_id']) {
				Db::exec('UPDATE reference_photos SET valid_from = ? WHERE id = ?', [Db::dt($this->now), $cur['id']]);
				continue;
			}
			try {
				self::setReference($wid, (int) $cand['photo_id'], 'auto', null, $this->now);
				$this->stats['references']++;
			} catch (\Throwable $e) {
				Log::warning("webcam $wid fascia $band: riferimento non creato: " . $e->getMessage(), self::LOG);
			}
		}
	}

	/** Chiude glass.dirty / camera.moved dopo un nuovo riferimento scelto dall'operatore. */
	public static function closeAfterOperator(int $wid, array $rules, int $userId, string $note): void
	{
		foreach ($rules as $r) {
			$a = Alerts::openFor($wid, $r);
			if ($a !== null) {
				Alerts::closeById((int) $a['id'], 'operator', $note, $userId);
			}
		}
	}
}
