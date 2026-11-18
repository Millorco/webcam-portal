<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Log;
use WebcamPortal\Service\Events;
use WebcamPortal\Service\HourlyAggregator;

/**
 * POST /api/v1/telemetry: lotto telemetry/1 (telemetria-v1.md).
 */
final class TelemetryApi
{
	public const MAX_BYTES = 1048576;
	public const MAX_SAMPLES = 288;

	/** Colonne di measurements nell'ordine dell'INSERT. */
	private const COLS = [
		'webcam_id', 'ts', 'sample_id', 'time_synced', 'time_corrected', 'received_at', 'uptime_s',
		'sensor_ok', 't_in', 'rh_in', 'dp_in', 'ah_in', 'ext_sensor_ok', 't_ext', 'rh_ext', 'dp_ext', 'ah_ext',
		'heating_enabled', 'heater', 'fan',
		'battery_v', 'battery_a', 'panel_v', 'panel_w', 'load_a', 'load_on', 'charge_state', 'mppt_state',
		'victron_error', 'soc_pct', 'yield_today_wh', 'yield_total_wh',
		'net_type', 'operator', 'rssi_dbm', 'rsrp_dbm', 'rsrq_db', 'sinr_db', 'rx_bytes', 'tx_bytes', 'rx_delta', 'tx_delta',
		'load1', 'mem_free_kb', 'tmp_free_kb', 'disk_free_kb', 'queue_photos', 'queue_kb', 'queue_samples',
		'camera_present', 'camera_power', 'extra',
		// contratto 1.1 (migrazione 002)
		'heater_on_since', 'heater_duty_pct', 'heat_reason', 'heater_boost_until', 'dewpoint_margin', 'fan_rpm',
		'cam_scheduled', 'cam_interval_min',
	];

	/** Campi di ogni oggetto del campione che hanno una colonna (il resto va in extra). */
	private const MAPPED = [
		'climate' => ['sensor_ok', 'temperature', 'humidity', 'dewpoint', 'abs_humidity', 'ext_sensor_ok',
			'temperature_ext', 'humidity_ext', 'dewpoint_ext', 'abs_humidity_ext', 'heating_enabled', 'heater', 'fan',
			'heater_on_since', 'heater_duty_pct', 'heat_reason', 'heater_boost_until', 'dewpoint_margin', 'fan_rpm'],
		'power' => ['battery_v', 'battery_a', 'panel_v', 'panel_w', 'load_a', 'load_on', 'charge_state', 'mppt_state',
			'error', 'soc_pct', 'yield_today_wh', 'yield_total_wh'],
		'net' => ['type', 'operator', 'rssi_dbm', 'rsrp_dbm', 'rsrq_db', 'sinr_db', 'rx_bytes', 'tx_bytes'],
		'sys' => ['load1', 'mem_free_kb', 'tmp_free_kb', 'disk_free_kb', 'queue_photos', 'queue_kb', 'queue_samples'],
		'camera' => ['present', 'power', 'scheduled', 'interval_min'],
	];

	/** @return array{0:int, 1:array<string,mixed>} */
	public static function handle(Request $req): array
	{
		$auth = TokenAuth::authenticate($req, 'telemetry');
		$webcam = $auth['webcam'];
		$wid = (int) $webcam['id'];
		$b = Body::json($req, self::MAX_BYTES);

		if (($b['schema'] ?? null) !== 'telemetry/1') {
			throw new ApiError(400, 'unsupported_schema', 'Serve "schema": "telemetry/1"');
		}
		TokenAuth::checkMac($webcam, $b['mac'] ?? null);
		foreach (['sent_at', 'time_synced', 'samples'] as $k) {
			if (!array_key_exists($k, $b)) {
				throw new ApiError(400, 'missing_field', "Campo obbligatorio mancante: $k", [], ['field' => $k]);
			}
		}
		if (!is_array($b['samples']) || !array_is_list($b['samples']) || $b['samples'] === []) {
			throw new ApiError(400, 'invalid_field', 'samples deve essere una lista di 1-288 campioni', [], ['field' => 'samples']);
		}
		if (count($b['samples']) > self::MAX_SAMPLES) {
			throw new ApiError(413, 'too_many_samples', 'Al massimo ' . self::MAX_SAMPLES . ' campioni per lotto');
		}
		$head = self::readHead($b);

		$now = time();
		$minTs = TimeWindow::min(true, $now);
		$maxTs = TimeWindow::max($now);
		$rows = [];
		$rejected = [];
		foreach ($b['samples'] as $i => $s) {
			$sid = is_array($s) && is_string($s['id'] ?? null) ? $s['id'] : null;
			try {
				if (!V::isObject($s)) {
					throw new InvalidField("samples[$i]", 'oggetto atteso');
				}
				$row = self::mapSample($s, $wid);
			} catch (InvalidField $e) {
				$rejected[] = ['id' => $sid, 'error' => $e instanceof MissingField ? 'missing_field' : 'invalid_field', 'field' => $e->field];
				continue;
			}
			if ($row['_t'] < $minTs || $row['_t'] > $maxTs) {
				$rejected[] = ['id' => $sid, 'error' => 'time_out_of_window'];
				continue;
			}
			$rows[] = $row;
		}

		usort($rows, static fn ($a, $c) => $a['_t'] <=> $c['_t']);
		$accepted = 0;
		$duplicates = 0;
		$hours = [];
		$lastAccepted = null;
		if ($rows !== []) {
			self::deltas($wid, $rows);
			$pdo = Db::pdo();
			$sql = 'INSERT IGNORE INTO measurements (' . implode(', ', self::COLS) . ') VALUES ('
				. implode(', ', array_fill(0, count(self::COLS), '?')) . ')';
			$st = $pdo->prepare($sql);
			$pdo->beginTransaction();
			try {
				foreach ($rows as $r) {
					$vals = [];
					foreach (self::COLS as $c) {
						$vals[] = $r[$c] ?? null;
					}
					$st->execute($vals);
					if ($st->rowCount() > 0) {
						$accepted++;
						$hours[$r['_t'] - ($r['_t'] % 3600)] = true;
						$lastAccepted = $r;
					} else {
						$duplicates++;
					}
				}
				$pdo->commit();
			} catch (\Throwable $e) {
				$pdo->rollBack();
				throw $e;
			}
		}

		if ($hours !== []) {
			$keys = array_keys($hours);
			HourlyAggregator::aggregate(min($keys), max($keys) + 3600, $wid);
		}
		self::afterInsert($webcam, $head, $lastAccepted, $b, $now);

		Log::info("telemetria webcam=$wid accettati=$accepted duplicati=$duplicates rifiutati=" . count($rejected));
		return [200, ['ok' => true, 'accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected]];
	}

	/** Campi del lotto; quelli non validi si ignorano (il lotto non si rifiuta per questo). */
	private static function readHead(array $b): array
	{
		$h = ['boot_id' => null, 'hostname' => null, 'app_version' => null, 'board_fw' => null, 'camera' => null, 'sent_at' => null];
		foreach ([
			'boot_id' => static fn () => V::str($b, 'boot_id', '', 8, '/^[0-9a-f]{8}$/'),
			'hostname' => static fn () => V::str($b, 'hostname', '', 100),
			'app_version' => static fn () => V::str($b, 'app_version', '', 30),
			'board_fw' => static fn () => V::str($b, 'board_fw', '', 30),
			'sent_at' => static fn () => V::int($b, 'sent_at', '', 0),
			'camera' => static fn () => self::readCameraCounters($b),
		] as $k => $fn) {
			try {
				$h[$k] = $fn();
			} catch (InvalidField $e) {
				Log::warning('telemetria: campo del lotto ignorato, ' . $e->getMessage());
			}
		}
		return $h;
	}

	private static function readCameraCounters(array $b): ?array
	{
		$c = V::obj($b, 'camera', '');
		if ($c === null) {
			return null;
		}
		$p = 'camera.';
		return [
			'model' => V::str($c, 'model', $p, 150),
			'serial' => V::str($c, 'serial', $p, 64),
			'shutter_count' => V::int($c, 'shutter_count', $p, 0, 4294967295),
			'shots_total' => V::int($c, 'shots_total', $p, 0, 4294967295),
			'power_on_hours' => V::num($c, 'power_on_hours', $p, 0, 9999999),
			'power_cycles' => V::int($c, 'power_cycles', $p, 0, 4294967295),
			'capture_errors' => V::int($c, 'capture_errors', $p, 0, 4294967295),
			'battery_level' => V::str($c, 'battery_level', $p, 16),
			'read_at' => V::int($c, 'read_at', $p),
		];
	}

	/** @return array<string,mixed> */
	public static function readClimate(array $c, string $p): array
	{
		return [
			'time' => V::int($c, 'time', $p),
			'sensor_ok' => V::bool($c, 'sensor_ok', $p),
			'temperature' => V::num($c, 'temperature', $p, -80, 150),
			'humidity' => V::num($c, 'humidity', $p, 0, 100),
			'dewpoint' => V::num($c, 'dewpoint', $p, -100, 150),
			'abs_humidity' => V::num($c, 'abs_humidity', $p, 0, 700),
			'ext_sensor' => V::bool($c, 'ext_sensor', $p),
			'ext_sensor_ok' => V::bool($c, 'ext_sensor_ok', $p),
			'temperature_ext' => V::num($c, 'temperature_ext', $p, -80, 150),
			'humidity_ext' => V::num($c, 'humidity_ext', $p, 0, 100),
			'dewpoint_ext' => V::num($c, 'dewpoint_ext', $p, -100, 150),
			'abs_humidity_ext' => V::num($c, 'abs_humidity_ext', $p, 0, 700),
			'heating_enabled' => V::bool($c, 'heating_enabled', $p),
			'heater' => V::bool($c, 'heater', $p),
			'fan' => V::bool($c, 'fan', $p),
			'board_fw' => V::str($c, 'board_fw', $p, 30),
			// contratto 1.1, campi per la diagnosi
			'heater_on_since' => V::int($c, 'heater_on_since', $p, 0),
			'heater_duty_pct' => V::int($c, 'heater_duty_pct', $p, 0, 100),
			'heat_reason' => V::enum($c, 'heat_reason', $p, ['dewpoint', 'min_temp', 'boost', 'capture', 'off']),
			'heater_boost_until' => V::int($c, 'heater_boost_until', $p, 0),
			'dewpoint_margin' => V::num($c, 'dewpoint_margin', $p, 0, 99),
			'fan_rpm' => V::int($c, 'fan_rpm', $p, 0, 65535),
		];
	}

	/** @return array<string,mixed> */
	public static function readPower(array $c, string $p): array
	{
		$src = $c['source'] ?? null;
		if ($src !== null && !in_array($src, ['vedirect', 'manual'], true)) {
			throw new InvalidField($p . 'source', 'valore non ammesso');
		}
		return [
			'time' => V::int($c, 'time', $p),
			'product_id' => V::str($c, 'product_id', $p, 6, '/^0x[0-9A-Fa-f]{4}$/'),
			'serial' => V::str($c, 'serial', $p, 32),
			'firmware' => V::str($c, 'firmware', $p, 16),
			'battery_v' => V::num($c, 'battery_v', $p, 0, 100),
			'battery_a' => V::num($c, 'battery_a', $p, -500, 500),
			'panel_v' => V::num($c, 'panel_v', $p, 0, 300),
			'panel_w' => V::int($c, 'panel_w', $p, 0, 10000),
			'load_a' => V::num($c, 'load_a', $p, 0, 100),
			'load_on' => V::bool($c, 'load_on', $p),
			'charge_state' => V::int($c, 'charge_state', $p, 0, 255),
			'mppt_state' => V::int($c, 'mppt_state', $p, 0, 2),
			'off_reason' => V::str($c, 'off_reason', $p, 32),
			'error' => V::int($c, 'error', $p, 0, 255),
			'soc_pct' => V::num($c, 'soc_pct', $p, 0, 100),
			'yield_today_wh' => V::int($c, 'yield_today_wh', $p, 0),
			'yield_total_wh' => V::int($c, 'yield_total_wh', $p, 0),
			'max_power_today_w' => V::int($c, 'max_power_today_w', $p, 0),
		];
	}

	/** @return array<string,mixed> */
	private static function readNet(array $c, string $p): array
	{
		return [
			'iface' => V::str($c, 'iface', $p, 16),
			'type' => V::enum($c, 'type', $p, ['lte', 'nr', 'umts', 'gsm', 'wan', 'wifi']),
			'operator' => V::str($c, 'operator', $p, 32),
			'rssi_dbm' => V::int($c, 'rssi_dbm', $p, -150, 0),
			'rsrp_dbm' => V::int($c, 'rsrp_dbm', $p, -160, 0),
			'rsrq_db' => V::num($c, 'rsrq_db', $p, -40, 10),
			'sinr_db' => V::num($c, 'sinr_db', $p, -30, 50),
			'rx_bytes' => V::int($c, 'rx_bytes', $p, 0),
			'tx_bytes' => V::int($c, 'tx_bytes', $p, 0),
			'wan_ip' => V::str($c, 'wan_ip', $p, 45),
			'vpn_up' => V::bool($c, 'vpn_up', $p),
		];
	}

	/** @return array<string,mixed> */
	private static function readSys(array $c, string $p): array
	{
		return [
			'load1' => V::num($c, 'load1', $p, 0),
			'mem_free_kb' => V::int($c, 'mem_free_kb', $p, 0),
			'tmp_free_kb' => V::int($c, 'tmp_free_kb', $p, 0),
			'disk_free_kb' => V::int($c, 'disk_free_kb', $p, 0),
			'queue_photos' => V::int($c, 'queue_photos', $p, 0),
			'queue_kb' => V::int($c, 'queue_kb', $p, 0),
			'queue_samples' => V::int($c, 'queue_samples', $p, 0),
			'maintenance' => V::bool($c, 'maintenance', $p),
		];
	}

	/** Campione -> riga di measurements (più _t, ora Unix). */
	private static function mapSample(array $s, int $wid): array
	{
		foreach (['id', 'time', 'time_synced'] as $k) {
			V::req($s, $k, '');
		}
		$id = V::str($s, 'id', '', 24, '/^[0-9a-f]{8}-[0-9]{1,10}$/');
		$t = V::int($s, 'time', '', 0);
		$synced = V::bool($s, 'time_synced', '');
		if ($id === null || $t === null || $synced === null) {
			throw new MissingField($id === null ? 'id' : ($t === null ? 'time' : 'time_synced'));
		}
		$cl = V::obj($s, 'climate', 'climate');
		$pw = V::obj($s, 'power', 'power');
		$nt = V::obj($s, 'net', 'net');
		$sy = V::obj($s, 'sys', 'sys');
		$cm = V::obj($s, 'camera', 'camera');
		$c = $cl === null ? [] : self::readClimate($cl, 'climate.');
		$p = $pw === null ? [] : self::readPower($pw, 'power.');
		$n = $nt === null ? [] : self::readNet($nt, 'net.');
		$y = $sy === null ? [] : self::readSys($sy, 'sys.');
		$k = $cm === null ? [] : [
			'present' => V::bool($cm, 'present', 'camera.'),
			'power' => V::bool($cm, 'power', 'camera.'),
			'scheduled' => V::bool($cm, 'scheduled', 'camera.'),
			'interval_min' => V::int($cm, 'interval_min', 'camera.', 0, 65535),
		];

		$clamp = static fn (?int $v, int $max): ?int => $v === null ? null : min($v, $max);
		$row = [
			'_t' => $t,
			'webcam_id' => $wid,
			'ts' => Db::dt($t),
			'sample_id' => $id,
			'time_synced' => $synced ? 1 : 0,
			'time_corrected' => V::bool($s, 'time_corrected', '') ? 1 : 0,
			'received_at' => Db::dt(time()),
			'uptime_s' => $clamp(V::int($s, 'uptime', '', 0), 4294967295),
			'sensor_ok' => V::b($c['sensor_ok'] ?? null),
			't_in' => $c['temperature'] ?? null,
			'rh_in' => $c['humidity'] ?? null,
			'dp_in' => $c['dewpoint'] ?? null,
			'ah_in' => $c['abs_humidity'] ?? null,
			'ext_sensor_ok' => V::b($c['ext_sensor_ok'] ?? null),
			't_ext' => $c['temperature_ext'] ?? null,
			'rh_ext' => $c['humidity_ext'] ?? null,
			'dp_ext' => $c['dewpoint_ext'] ?? null,
			'ah_ext' => $c['abs_humidity_ext'] ?? null,
			'heating_enabled' => V::b($c['heating_enabled'] ?? null),
			'heater' => V::b($c['heater'] ?? null),
			'fan' => V::b($c['fan'] ?? null),
			'battery_v' => $p['battery_v'] ?? null,
			'battery_a' => $p['battery_a'] ?? null,
			'panel_v' => $p['panel_v'] ?? null,
			'panel_w' => $p['panel_w'] ?? null,
			'load_a' => $p['load_a'] ?? null,
			'load_on' => V::b($p['load_on'] ?? null),
			'charge_state' => $p['charge_state'] ?? null,
			'mppt_state' => $p['mppt_state'] ?? null,
			'victron_error' => $p['error'] ?? null,
			'soc_pct' => $p['soc_pct'] ?? null,
			'yield_today_wh' => $clamp($p['yield_today_wh'] ?? null, 65535),
			'yield_total_wh' => $clamp($p['yield_total_wh'] ?? null, 4294967295),
			'net_type' => $n['type'] ?? null,
			'operator' => $n['operator'] ?? null,
			'rssi_dbm' => $n['rssi_dbm'] ?? null,
			'rsrp_dbm' => $n['rsrp_dbm'] ?? null,
			'rsrq_db' => $n['rsrq_db'] ?? null,
			'sinr_db' => $n['sinr_db'] ?? null,
			'rx_bytes' => $n['rx_bytes'] ?? null,
			'tx_bytes' => $n['tx_bytes'] ?? null,
			'load1' => isset($y['load1']) ? min($y['load1'], 999.99) : null,
			'mem_free_kb' => $clamp($y['mem_free_kb'] ?? null, 4294967295),
			'tmp_free_kb' => $clamp($y['tmp_free_kb'] ?? null, 4294967295),
			'disk_free_kb' => $clamp($y['disk_free_kb'] ?? null, 4294967295),
			'queue_photos' => $clamp($y['queue_photos'] ?? null, 65535),
			'queue_kb' => $clamp($y['queue_kb'] ?? null, 4294967295),
			'queue_samples' => $clamp($y['queue_samples'] ?? null, 65535),
			'camera_present' => V::b($k['present'] ?? null),
			'camera_power' => V::b($k['power'] ?? null),
			'extra' => self::extra($s),
			'heater_on_since' => isset($c['heater_on_since']) && TimeWindow::ok($c['heater_on_since']) ? Db::dt($c['heater_on_since']) : null,
			'heater_duty_pct' => $c['heater_duty_pct'] ?? null,
			'heat_reason' => $c['heat_reason'] ?? null,
			'heater_boost_until' => isset($c['heater_boost_until']) && $c['heater_boost_until'] < 4102444800 ? Db::dt($c['heater_boost_until']) : null,
			'dewpoint_margin' => $c['dewpoint_margin'] ?? null,
			'fan_rpm' => $c['fan_rpm'] ?? null,
			'cam_scheduled' => V::b($k['scheduled'] ?? null),
			'cam_interval_min' => $k['interval_min'] ?? null,
		];
		return $row;
	}

	/** Tutto quello che non ha una colonna, in JSON (o null). */
	private static function extra(array $s): ?string
	{
		unset($s['id'], $s['time'], $s['time_synced'], $s['time_corrected'], $s['uptime']);
		foreach (self::MAPPED as $obj => $keys) {
			if (!array_key_exists($obj, $s)) {
				continue;
			}
			if (!is_array($s[$obj])) {
				unset($s[$obj]);
				continue;
			}
			foreach ($keys as $k) {
				unset($s[$obj][$k]);
			}
			if ($s[$obj] === []) {
				unset($s[$obj]);
			}
		}
		if ($s === []) {
			return null;
		}
		return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
	}

	/**
	 * rx_delta/tx_delta: byte dal campione precedente. Un calo del contatore o
	 * un boot_id diverso vogliono dire riavvio: il delta è il contatore stesso.
	 *
	 * @param list<array<string,mixed>> $rows ordinati per ora
	 */
	private static function deltas(int $wid, array &$rows): void
	{
		$prev = Db::one(
			'SELECT sample_id, rx_bytes, tx_bytes FROM measurements
			  WHERE webcam_id = ? AND ts < ? ORDER BY ts DESC LIMIT 1',
			[$wid, $rows[0]['ts']]
		);
		foreach ($rows as &$r) {
			foreach (['rx', 'tx'] as $d) {
				$cur = $r[$d . '_bytes'];
				$old = $prev[$d . '_bytes'] ?? null;
				if ($cur === null || $prev === null || $old === null) {
					$r[$d . '_delta'] = null;
					continue;
				}
				$sameBoot = substr((string) $prev['sample_id'], 0, 8) === substr((string) $r['sample_id'], 0, 8);
				$delta = ($sameBoot && $cur >= (int) $old) ? $cur - (int) $old : $cur;
				$r[$d . '_delta'] = min($delta, 4294967295);
			}
			$prev = ['sample_id' => $r['sample_id'], 'rx_bytes' => $r['rx_bytes'], 'tx_bytes' => $r['tx_bytes']];
		}
		unset($r);
	}

	/** Aggiorna la webcam, i contatori della reflex e crea gli eventi del portal. */
	private static function afterInsert(array $webcam, array $head, ?array $last, array $b, int $now): void
	{
		$wid = (int) $webcam['id'];
		$ts = $last ? min($last['_t'], $now) : null;

		// eventi del portal: riavvio, cambio di versione
		if ($head['boot_id'] !== null && $webcam['last_boot_id'] !== null && $head['boot_id'] !== $webcam['last_boot_id']) {
			$routerBoot = Db::scalar(
				"SELECT 1 FROM events WHERE webcam_id = ? AND type = 'boot' AND event_uid LIKE ? LIMIT 1",
				[$wid, $head['boot_id'] . '-E%']
			);
			if ($routerBoot === null) {
				$bootTs = $now;
				foreach ($b['samples'] as $s) {
					if (is_array($s) && is_int($s['time'] ?? null) && is_int($s['uptime'] ?? null)
						&& str_starts_with((string) ($s['id'] ?? ''), $head['boot_id'] . '-')) {
						$bootTs = max(0, min($bootTs, $s['time'] - $s['uptime']));
					}
				}
				Events::portal($wid, 'boot', 'notice', 'Riavvio del router (boot_id cambiato)', [
					'boot_id' => $head['boot_id'], 'previous_boot_id' => $webcam['last_boot_id'],
				], $bootTs);
			}
		}
		foreach (['app_version' => 'app', 'board_fw' => 'board'] as $col => $comp) {
			$new = $head[$col];
			$old = $webcam[$col];
			if ($new === null || $old === null || $new === $old) {
				continue;
			}
			$type = $comp . '.version_change';
			$exists = Db::scalar(
				"SELECT 1 FROM events WHERE webcam_id = ? AND type = ? AND ts >= ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.to')) = ? LIMIT 1",
				[$wid, $type, Db::dt($now - 2 * 86400), $new]
			);
			if ($exists === null) {
				$label = $comp === 'app' ? 'luci-app-webcam' : 'firmware della scheda';
				Events::portal($wid, $type, 'info', "$label $old -> $new", ['component' => $comp, 'from' => $old, 'to' => $new]);
			}
		}

		// batteria sotto soglia (al massimo un evento ogni 6 ore)
		$lowV = (float) Config::get()->value('low_battery_v', 11.8);
		if ($last !== null && $last['battery_v'] !== null && $last['battery_v'] < $lowV
			&& !Events::recent($wid, 'power.low_battery', $now - 6 * 3600)) {
			Events::portal($wid, 'power.low_battery', 'warning', sprintf('Batteria a %.2f V (soglia %.2f V)', $last['battery_v'], $lowV), [
				'battery_v' => $last['battery_v'], 'threshold_v' => $lowV,
			], $ts);
		}

		Db::exec(
			'UPDATE webcams SET
			   last_telemetry_at = IF(? IS NULL, last_telemetry_at, GREATEST(COALESCE(last_telemetry_at, ?), ?)),
			   hostname = COALESCE(?, hostname), app_version = COALESCE(?, app_version),
			   board_fw = COALESCE(?, board_fw), last_boot_id = COALESCE(?, last_boot_id)
			 WHERE id = ?',
			[
				$ts, $ts === null ? null : Db::dt($ts), $ts === null ? null : Db::dt($ts),
				$head['hostname'], $head['app_version'], $head['board_fw'], $head['boot_id'], $wid,
			]
		);

		if ($head['camera'] !== null) {
			self::saveCounters($wid, $head['camera'], $head['sent_at'], $now);
		}
	}

	/** Una riga in camera_counters solo se un valore è cambiato. */
	private static function saveCounters(int $wid, array $c, ?int $sentAt, int $now): void
	{
		$cameraId = null;
		if ($c['model'] !== null && $c['serial'] !== null) {
			Db::exec(
				'INSERT INTO cameras (model, serial, current_webcam_id, first_seen_at, last_seen_at)
				 VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
				 ON DUPLICATE KEY UPDATE current_webcam_id = VALUES(current_webcam_id), last_seen_at = UTC_TIMESTAMP()',
				[$c['model'], $c['serial'], $wid]
			);
			$cameraId = (int) Db::scalar('SELECT id FROM cameras WHERE model = ? AND serial = ?', [$c['model'], $c['serial']]);
			Db::exec('UPDATE webcams SET camera_model = ? WHERE id = ?', [$c['model'], $wid]);
		}
		$vals = [
			'camera_id' => $cameraId,
			'shutter_count' => $c['shutter_count'],
			'shots_total' => $c['shots_total'],
			'power_on_hours' => $c['power_on_hours'] === null ? null : round($c['power_on_hours'], 1),
			'power_cycles' => $c['power_cycles'],
			'capture_errors' => $c['capture_errors'],
			'battery_level' => $c['battery_level'],
		];
		if (count(array_filter($vals, static fn ($v) => $v !== null)) === 0) {
			return;
		}
		$prev = Db::one(
			'SELECT camera_id, shutter_count, shots_total, power_on_hours, power_cycles, capture_errors, battery_level
			   FROM camera_counters WHERE webcam_id = ? ORDER BY ts DESC, id DESC LIMIT 1',
			[$wid]
		);
		if ($prev !== null) {
			$same = true;
			foreach ($vals as $k => $v) {
				$pv = $prev[$k];
				if ($k === 'power_on_hours') {
					$same = $same && (($pv === null && $v === null) || ($pv !== null && $v !== null && abs((float) $pv - $v) < 0.05));
				} else {
					$same = $same && ((string) $pv === (string) $v) && (($pv === null) === ($v === null));
				}
			}
			if ($same) {
				return;
			}
		}
		// ora dei contatori = invio del lotto (read_at è l'ultima lettura di
		// modello e seriale, ripetuta per un'ora mentre shots_total cresce)
		$t = $sentAt ?? $now;
		if (!TimeWindow::ok($t)) {
			$t = $now;
		}
		Db::exec(
			'INSERT IGNORE INTO camera_counters (webcam_id, camera_id, ts, shutter_count, shots_total, power_on_hours,
			   power_cycles, capture_errors, battery_level, source)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'telemetry\')',
			array_merge([$wid, $cameraId, Db::dt($t)], array_values(array_slice($vals, 1)))
		);
	}
}
