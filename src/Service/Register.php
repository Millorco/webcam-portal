<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Dolibarr\DolibarrException;
use WebcamPortal\Dolibarr\Services as DolibarrServices;
use WebcamPortal\Log;
use WebcamPortal\Telegram\Notify;

/**
 * Registro giornaliero delle impronte SHA-256 e classificazione meteo del
 * giorno (O2, servizi-v1.md "Registro delle impronte e giornate di
 * maltempo"): elenco vlreg1 per webcam e giorno locale, sigillato alle 01:30
 * del giorno dopo, hash a catena, deposito in Dolibarr. Il registro non si
 * ricalcola mai.
 */
final class Register
{
	public const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';
	public const WEATHER_DEFAULTS = [
		'work_hours' => '07:00-18:00', 'rh_wet' => 95.0, 'spread_wet' => 1.0, 'min_samples_hour' => 9, 'frost_c' => 0.0,
		'dark_ratio' => 0.6, 'min_coverage' => 0.8, 'min_wet_hours' => 4, 'min_frost_hours' => 4, 'min_fog_hours' => 4,
	];
	private const LOG = 'register';
	/** Al primo giro per una webcam si recuperano al più tanti giorni. */
	private const CATCH_UP_DAYS = 31;

	public array $stats = ['sealed' => 0, 'late' => 0, 'deposited' => 0, 'conflicts' => 0, 'errors' => 0];

	public function __construct(private int $now)
	{
	}

	/** Soglie della webcam: predefiniti, weather.params di config, webcam_service.weather_params. */
	public static function criteria(int $webcamId): array
	{
		$c = self::WEATHER_DEFAULTS;
		$cfg = (array) Config::get()->value('weather.params', []);
		$v = Db::scalar('SELECT weather_params FROM webcam_service WHERE webcam_id = ?', [$webcamId]);
		$own = is_string($v) ? (json_decode($v, true) ?: []) : [];
		foreach ([$cfg, $own] as $src) {
			foreach ($src as $k => $val) {
				if (array_key_exists($k, $c) && $val !== null && $val !== '') {
					$c[$k] = $k === 'work_hours' ? (string) $val : (float) $val;
				}
			}
		}
		return $c;
	}

	public static function manifestRel(int $webcamId, string $day): string
	{
		return sprintf('register/%d/%s/%s/%s.txt', $webcamId, substr($day, 0, 4), substr($day, 5, 2), $day);
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
			try {
				$this->sealDue($w);
				$this->late($w);
			} catch (\Throwable $e) {
				$this->stats['errors']++;
				Log::error("registro webcam {$w['id']}: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), self::LOG);
			}
		}
		$this->deposit($webcamId);
		return $this->stats;
	}

	/** Sigilla i giorni chiusi (dalle 01:30 del giorno dopo) non ancora sigillati. */
	private function sealDue(array $w): void
	{
		$tz = ServiceSettings::tz($w);
		$loc = (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz);
		$last = $loc->modify('-1 day')->format('Y-m-d');
		if ($loc < $loc->setTime(1, 30)) {
			$last = $loc->modify('-2 days')->format('Y-m-d');
		}
		$lastSealed = Db::scalar('SELECT MAX(day) FROM daily_register WHERE webcam_id = ? AND sealed_at IS NOT NULL', [$w['id']]);
		if ($lastSealed !== null) {
			$start = (new \DateTimeImmutable((string) $lastSealed, $tz))->modify('+1 day');
		} else {
			$created = (new \DateTimeImmutable('@' . (int) Db::unix((string) $w['created_at'])))->setTimezone($tz)->setTime(0, 0);
			$start = max($created, $loc->setTime(0, 0)->modify('-' . self::CATCH_UP_DAYS . ' days'));
		}
		for ($d = $start; $d->format('Y-m-d') <= $last; $d = $d->modify('+1 day')) {
			$this->seal($w, $d->format('Y-m-d'));
		}
	}

	/** Elenco, day_hash a catena e classificazione meteo di un giorno. */
	public function seal(array $w, string $day): array
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		$from = new \DateTimeImmutable($day . ' 00:00:00', $tz);
		$to = $from->modify('+1 day');
		$photos = Db::all(
			'SELECT id, sha256, taken_at, received_at, file_name, size_bytes, time_synced FROM photos
			  WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ? ORDER BY taken_at, id',
			[$wid, Db::dt($from->getTimestamp()), Db::dt($to->getTimestamp())]
		);
		$prevHash = Db::scalar('SELECT day_hash FROM daily_register WHERE webcam_id = ? AND day < ? AND sealed_at IS NOT NULL ORDER BY day DESC LIMIT 1', [$wid, $day]);
		$prevHash = $prevHash === null ? self::ZERO : (string) $prevHash;
		$text = 'vlreg1|' . $w['slug'] . '|' . $day . '|' . $tz->getName() . '|' . $prevHash . "\n";
		$synced = 0;
		$unsynced = 0;
		$first = null;
		$lastAt = null;
		foreach ($photos as $p) {
			if ((int) $p['time_synced'] !== 1) {
				$unsynced++;
				continue;
			}
			$synced++;
			$first ??= $p['taken_at'];
			$lastAt = $p['taken_at'];
			$text .= self::line($p);
		}
		$rel = self::manifestRel($wid, $day);
		$abs = Config::get()->storageDir() . '/' . $rel;
		PhotoStore::ensureDir($abs);
		file_put_contents($abs, $text, LOCK_EX);
		$hash = hash('sha256', $text);
		$weather = $this->classify($w, $day, $photos);
		$cust = Timelapse::customerAt($wid, $day);
		Db::exec(
			'INSERT INTO daily_register (webcam_id, day, timezone, customer_id, photos_total, photos_unsynced, first_photo_at, last_photo_at, hash_scheme, manifest_path,
			   day_hash, prev_day_hash, work_hours, samples_expected, samples_ok, samples_unsynced, hours_with_photo, t_ext_min, t_ext_max, t_ext_avg, rh_ext_avg,
			   rh_ext_max, spread_ext_min, wet_hours, frost_hours, dark_hours, fog_hours, classification, reasons, not_cert_reason, criteria, sealed_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'vlreg1\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$wid, $day, $tz->getName(), $cust, $synced, $unsynced, $first, $lastAt, $rel, $hash, $prevHash,
				$weather['work_hours'], $weather['samples_expected'], $weather['samples_ok'], $weather['samples_unsynced'], $weather['hours_with_photo'],
				$weather['t_ext_min'], $weather['t_ext_max'], $weather['t_ext_avg'], $weather['rh_ext_avg'], $weather['rh_ext_max'], $weather['spread_ext_min'],
				$weather['wet_hours'], $weather['frost_hours'], $weather['dark_hours'], $weather['fog_hours'], $weather['classification'],
				$weather['reasons'] === [] ? null : implode(',', $weather['reasons']), $weather['not_cert_reason'], json_encode($weather['criteria']), Db::dt($this->now)]
		);
		$this->stats['sealed']++;
		if ($weather['classification'] === 'bad_weather' && Features::has($w, 'weather_cert')) {
			Notify::badWeather($w, ['day' => $day, 'wet_hours' => $weather['wet_hours']]);
		}
		return ['day_hash' => $hash] + $weather;
	}

	private static function line(array $p): string
	{
		$iso = static fn (string $dt): string => gmdate('Y-m-d\TH:i:s\Z', (int) Db::unix($dt));
		return $p['sha256'] . '|' . $iso((string) $p['taken_at']) . '|' . $iso((string) $p['received_at']) . '|' . str_replace(["|", "\n", "\r"], '_', (string) $p['file_name']) . '|' . (int) $p['size_bytes'] . "\n";
	}

	/** Ore di lavoro (sla_hours/sla_weekdays o work_hours) e giorno lavorativo. */
	public static function workHours(int $webcamId, string $day, \DateTimeZone $tz, ?array $crit = null): array
	{
		$s = ServiceSettings::get($webcamId);
		$crit ??= self::criteria($webcamId);
		$hours = $s['sla_hours'] ?: (string) $crit['work_hours'];
		$wd = (int) (new \DateTimeImmutable($day . ' 12:00:00', $tz))->format('N');
		$workday = empty($s['sla_weekdays']) || str_contains((string) $s['sla_weekdays'], (string) $wd);
		[$a, $b] = explode('-', $hours);
		return ['hours' => $hours, 'from' => (int) substr($a, 0, 2), 'to' => $b === '24:00' ? 24 : (int) substr($b, 0, 2) + ((int) substr($b, 3, 2) > 0 ? 1 : 0), 'workday' => $workday];
	}

	/**
	 * Classificazione meteo del giorno dai campioni (time_synced = 1,
	 * ext_sensor_ok = 1) e dalle foto nelle ore di lavoro.
	 *
	 * @param list<array<string,mixed>> $photos
	 */
	public function classify(array $w, string $day, array $photos): array
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		$c = self::criteria($wid);
		$wh = self::workHours($wid, $day, $tz, $c);
		$base = new \DateTimeImmutable($day . ' 00:00:00', $tz);
		$wFrom = $base->setTime($wh['from'], 0)->getTimestamp();
		$wTo = $wh['to'] >= 24 ? $base->modify('+1 day')->getTimestamp() : $base->setTime($wh['to'], 0)->getTimestamp();
		$nHours = max(0, (int) round(($wTo - $wFrom) / 3600));
		$samples = Db::all(
			'SELECT UNIX_TIMESTAMP(ts) t, time_synced, ext_sensor_ok, t_ext, rh_ext, dp_ext FROM measurements WHERE webcam_id = ? AND ts >= ? AND ts < ?',
			[$wid, Db::dt($wFrom), Db::dt($wTo)]
		);
		$byHour = [];
		$unsynced = 0;
		$okN = 0;
		$anyExt = false;
		$t = [];
		$rh = [];
		$spread = [];
		foreach ($samples as $s) {
			if ((int) $s['time_synced'] !== 1) {
				$unsynced++;
				continue;
			}
			if ($s['ext_sensor_ok'] === null || (int) $s['ext_sensor_ok'] !== 1 || $s['t_ext'] === null) {
				continue;
			}
			$anyExt = true;
			$okN++;
			$h = intdiv((int) $s['t'] - $wFrom, 3600);
			$te = (float) $s['t_ext'];
			$r = $s['rh_ext'] === null ? null : (float) $s['rh_ext'];
			$dp = $s['dp_ext'] !== null ? (float) $s['dp_ext'] : \WebcamPortal\Overlay\Renderer::dewpoint($te, $r);
			$byHour[$h][] = ['t' => $te, 'rh' => $r, 'sp' => $dp === null ? null : $te - $dp];
			$t[] = $te;
			if ($r !== null) {
				$rh[] = $r;
			}
			if ($dp !== null) {
				$spread[] = $te - $dp;
			}
		}
		// foto nelle ore di lavoro: ore con foto, nebbia (fog_ext) e ore scure
		$photoHours = [];
		$ids = [];
		foreach ($photos as $p) {
			$pt = (int) Db::unix((string) $p['taken_at']);
			if ($pt >= $wFrom && $pt < $wTo) {
				$photoHours[intdiv($pt - $wFrom, 3600)] = true;
				$ids[] = (int) $p['id'];
			}
		}
		$fogH = [];
		$bright = [];
		if ($ids !== []) {
			$in = implode(',', $ids);
			foreach (Db::all("SELECT taken_at, classification, brightness, band FROM photo_metrics WHERE photo_id IN ($in)") as $m) {
				$h = intdiv((int) Db::unix((string) $m['taken_at']) - $wFrom, 3600);
				if ($m['classification'] === 'fog_ext') {
					$fogH[$h] = true;
				}
				if ($m['brightness'] !== null) {
					$bright[$h][] = [(float) $m['brightness'], (int) $m['band']];
				}
			}
			foreach (Db::all("SELECT s.taken_at, s.brightness FROM photo_scores s LEFT JOIN photo_metrics m ON m.photo_id = s.photo_id WHERE s.photo_id IN ($in) AND m.photo_id IS NULL AND s.brightness IS NOT NULL") as $m) {
				$pt = (int) Db::unix((string) $m['taken_at']);
				$bright[intdiv($pt - $wFrom, 3600)][] = [(float) $m['brightness'], (int) (new \DateTimeImmutable('@' . $pt))->setTimezone($tz)->format('G')];
			}
		}
		$refs = [];
		foreach (Db::all('SELECT band, brightness FROM reference_photos WHERE webcam_id = ? AND valid_to IS NULL', [$wid]) as $r) {
			$refs[(int) $r['band']] = (float) $r['brightness'];
		}
		$wet = $frost = $dark = 0;
		for ($h = 0; $h < $nHours; $h++) {
			$hs = $byHour[$h] ?? [];
			if (count($hs) >= (int) $c['min_samples_hour']) {
				$rhs = array_filter(array_column($hs, 'rh'), static fn ($v) => $v !== null);
				$sps = array_filter(array_column($hs, 'sp'), static fn ($v) => $v !== null);
				$rhAvg = $rhs === [] ? null : array_sum($rhs) / count($rhs);
				$spAvg = $sps === [] ? null : array_sum($sps) / count($sps);
				if (($rhAvg !== null && $rhAvg >= $c['rh_wet']) || ($spAvg !== null && $spAvg <= $c['spread_wet'])) {
					$wet++;
				}
			}
			if ($hs !== [] && min(array_column($hs, 't')) <= $c['frost_c']) {
				$frost++;
			}
			if (isset($bright[$h])) {
				$vals = array_column($bright[$h], 0);
				sort($vals);
				$med = $vals[intdiv(count($vals), 2)];
				$band = $bright[$h][0][1];
				if (isset($refs[$band]) && $refs[$band] > 0 && $med < $c['dark_ratio'] * $refs[$band]) {
					$dark++;
				}
			}
		}
		$fog = count($fogH);
		$expected = $nHours * 12;
		$hoursPhoto = count($photoHours);
		$class = 'ok';
		$nc = null;
		if ($okN === 0 && $hoursPhoto === 0 && $unsynced === 0) {
			$class = 'no_data';
		} elseif ($okN < $c['min_coverage'] * $expected) {
			$class = 'not_certifiable';
			$nc = $anyExt ? 'few_samples' : 'sensor_missing';
		} elseif ($hoursPhoto < 0.8 * $nHours) {
			$class = 'not_certifiable';
			$nc = 'few_photos';
		} elseif ($unsynced > 0.2 * ($okN + $unsynced)) {
			$class = 'not_certifiable';
			$nc = 'time_unsynced';
		}
		$reasons = [];
		if ($wet >= $c['min_wet_hours']) {
			$reasons[] = 'wet';
		}
		if ($frost >= $c['min_frost_hours']) {
			$reasons[] = 'frost';
		}
		if ($fog >= $c['min_fog_hours']) {
			$reasons[] = 'fog';
		}
		if ($class === 'ok' && $reasons !== []) {
			$class = 'bad_weather';
		}
		if ($dark >= 2) {
			$reasons[] = 'dark';
		}
		$avg = static fn (array $a): ?float => $a === [] ? null : round(array_sum($a) / count($a), 1);
		return [
			'work_hours' => $wh['hours'], 'workday' => $wh['workday'], 'samples_expected' => $expected, 'samples_ok' => $okN, 'samples_unsynced' => $unsynced,
			'hours_with_photo' => $hoursPhoto, 't_ext_min' => $t === [] ? null : round(min($t), 1), 't_ext_max' => $t === [] ? null : round(max($t), 1),
			't_ext_avg' => $avg($t), 'rh_ext_avg' => $avg($rh), 'rh_ext_max' => $rh === [] ? null : round(max($rh), 1),
			'spread_ext_min' => $spread === [] ? null : round(min($spread), 1), 'wet_hours' => $wet, 'frost_hours' => $frost, 'dark_hours' => $dark,
			'fog_hours' => $fog, 'classification' => $class, 'reasons' => $reasons, 'not_cert_reason' => $nc, 'criteria' => $c,
		];
	}

	/** Foto ricevute dopo il sigillo: AAAA-MM-GG.late.txt e late_photos, day_hash invariato. */
	private function late(array $w): void
	{
		$rows = Db::all('SELECT day, sealed_at, late_photos, timezone FROM daily_register WHERE webcam_id = ? AND sealed_at IS NOT NULL AND day >= ?', [$w['id'], gmdate('Y-m-d', $this->now - 40 * 86400)]);
		foreach ($rows as $r) {
			$tz = new \DateTimeZone((string) $r['timezone']);
			$from = new \DateTimeImmutable($r['day'] . ' 00:00:00', $tz);
			$late = Db::all(
				'SELECT id, sha256, taken_at, received_at, file_name, size_bytes FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ?
				   AND time_synced = 1 AND received_at > ? ORDER BY taken_at, id',
				[$w['id'], Db::dt($from->getTimestamp()), Db::dt($from->modify('+1 day')->getTimestamp()), $r['sealed_at']]
			);
			if (count($late) === (int) $r['late_photos']) {
				continue;
			}
			$abs = Config::get()->storageDir() . '/' . substr(self::manifestRel((int) $w['id'], (string) $r['day']), 0, -4) . '.late.txt';
			file_put_contents($abs, implode('', array_map([self::class, 'line'], $late)), LOCK_EX);
			Db::exec('UPDATE daily_register SET late_photos = ? WHERE webcam_id = ? AND day = ?', [min(65535, count($late)), $w['id'], $r['day']]);
			$this->stats['late'] += count($late) - (int) $r['late_photos'];
		}
	}

	/** POST webcams/{id}/register dei giorni sigillati non ancora depositati (31 per chiamata). */
	public function deposit(?int $webcamId = null): void
	{
		if (DolibarrServices::client() === null) {
			return;
		}
		$sql = 'SELECT DISTINCT r.webcam_id FROM daily_register r JOIN webcams w ON w.id = r.webcam_id WHERE r.sealed_at IS NOT NULL AND r.dolibarr_synced_at IS NULL AND w.dolibarr_id IS NOT NULL';
		$p = [];
		if ($webcamId !== null) {
			$sql .= ' AND r.webcam_id = ?';
			$p[] = $webcamId;
		}
		foreach (Db::all($sql, $p) as $x) {
			$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$x['webcam_id']]);
			$rows = Db::all('SELECT * FROM daily_register WHERE webcam_id = ? AND sealed_at IS NOT NULL AND dolibarr_synced_at IS NULL ORDER BY day LIMIT 31', [$w['id']]);
			$days = array_map(static fn ($r) => [
				'day' => $r['day'], 'timezone' => $r['timezone'], 'hash_scheme' => $r['hash_scheme'], 'day_hash' => $r['day_hash'],
				'prev_day_hash' => $r['prev_day_hash'], 'photos' => (int) $r['photos_total'], 'photos_unsynced' => (int) $r['photos_unsynced'],
				'classification' => $r['classification'], 'reasons' => (string) ($r['reasons'] ?? ''),
				'wet_hours' => (int) $r['wet_hours'], 'frost_hours' => (int) $r['frost_hours'], 'fog_hours' => (int) $r['fog_hours'],
			], $rows);
			try {
				$res = DolibarrServices::register($w, $days);
			} catch (DolibarrException $e) {
				if ($e->endpointMissing()) {
					Log::warning('POST webcams/{id}/register non disponibile in Dolibarr (modulo webcams < 1.4): registro solo nel portal', self::LOG);
					return;
				}
				$this->stats['errors']++;
				Log::error("deposito del registro della webcam {$w['id']}: " . $e->getMessage(), self::LOG);
				if ($e->status === 0 || $e->status === 401) {
					return;
				}
				continue;
			}
			foreach ($res as $r) {
				$st = (string) ($r['status'] ?? '');
				if (!in_array($st, ['created', 'duplicate', 'conflict'], true) || !is_string($r['day'] ?? null)) {
					continue;
				}
				if ($st === 'conflict') {
					$this->stats['conflicts']++;
					Log::error("registro della webcam {$w['id']} del {$r['day']}: Dolibarr ha già un hash diverso (conflict)", self::LOG);
				}
				$reg = isset($r['registered_at']) && is_numeric($r['registered_at']) ? Db::dt((int) $r['registered_at']) : null;
				Db::exec('UPDATE daily_register SET dolibarr_synced_at = UTC_TIMESTAMP(), dolibarr_registered_at = ? WHERE webcam_id = ? AND day = ?', [$st === 'conflict' ? null : $reg, $w['id'], $r['day']]);
				$this->stats['deposited']++;
			}
		}
	}

	/**
	 * Controllo degli elenchi su disco contro day_hash (--verify, settimanale):
	 * i giorni diversi vanno in storage/state/register-verify.json per il pannello.
	 *
	 * @return list<array{webcam_id:int, day:string, problem:string}>
	 */
	public static function verify(?int $webcamId = null): array
	{
		$bad = [];
		$sql = 'SELECT webcam_id, day, manifest_path, day_hash FROM daily_register WHERE sealed_at IS NOT NULL';
		$p = [];
		if ($webcamId !== null) {
			$sql .= ' AND webcam_id = ?';
			$p[] = $webcamId;
		}
		foreach (Db::all($sql, $p) as $r) {
			$f = Config::get()->storageDir() . '/' . $r['manifest_path'];
			if (!is_file($f)) {
				$bad[] = ['webcam_id' => (int) $r['webcam_id'], 'day' => (string) $r['day'], 'problem' => 'missing'];
			} elseif (hash_file('sha256', $f) !== $r['day_hash']) {
				$bad[] = ['webcam_id' => (int) $r['webcam_id'], 'day' => (string) $r['day'], 'problem' => 'mismatch'];
			}
		}
		$state = Config::get()->storageDir() . '/state/register-verify.json';
		PhotoStore::ensureDir($state);
		file_put_contents($state, json_encode(['time' => time(), 'bad' => $bad]), LOCK_EX);
		foreach ($bad as $b) {
			Log::error("registro della webcam {$b['webcam_id']} del {$b['day']}: elenco " . ($b['problem'] === 'missing' ? 'mancante' : 'diverso dal day_hash'), self::LOG);
		}
		return $bad;
	}

	/** @return array<string,string> "webcam_id|giorno" => problema, dall'ultimo controllo */
	public static function verifyState(): array
	{
		$f = Config::get()->storageDir() . '/state/register-verify.json';
		$d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
		$out = [];
		foreach ((array) ($d['bad'] ?? []) as $b) {
			$out[$b['webcam_id'] . '|' . $b['day']] = (string) $b['problem'];
		}
		return $out;
	}
}
