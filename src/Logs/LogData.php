<?php

declare(strict_types=1);

namespace WebcamPortal\Logs;

use WebcamPortal\Config;
use WebcamPortal\Db;

/**
 * Riavvii ed errori della fotocamera per le regole logs.*: eventi del
 * database (sempre) e conteggi di VictoriaLogs dell'ultima query (se
 * configurato, riuscito, abbastanza recente e con hostname univoco).
 */
final class LogData
{
	/** Eventi che rendono "atteso" un avvio nei 15 minuti dopo. */
	private const EXPECTED = ['app.version_change', 'config.change', 'maintenance.on', 'maintenance.off', 'intervention'];

	/** @return array<string,mixed> dati condivisi della valutazione */
	public static function load(int $now): array
	{
		$configured = VictoriaLogs::fromConfig() !== null;
		$cache = $configured ? VictoriaLogs::cache() : null;
		$fresh = $cache !== null && !empty($cache['ok']) && abs($now - (int) ($cache['time'] ?? 0)) <= 1800;
		return [
			'configured' => $configured,
			'ok' => $fresh,
			'time' => $cache['time'] ?? null,
			'counts' => $fresh ? (array) ($cache['counts'] ?? []) : [],
			'unique' => self::uniqueHosts(),
		];
	}

	/** hostname => webcam_id, solo per gli hostname di una sola webcam in servizio. */
	public static function uniqueHosts(): array
	{
		$out = [];
		foreach (Db::all("SELECT hostname, MIN(id) id, COUNT(*) n FROM webcams WHERE status = 1 AND hostname IS NOT NULL AND hostname <> '' GROUP BY hostname") as $r) {
			if ((int) $r['n'] === 1) {
				$out[(string) $r['hostname']] = (int) $r['id'];
			}
		}
		return $out;
	}

	/**
	 * Conteggi delle ultime 24 ore di VictoriaLogs per la webcam.
	 *
	 * @return array{state:string, counts:?array} state: off (non configurato), down (non ha risposto), not_unique, ok
	 */
	public static function logCounts(array $shared, array $webcam): array
	{
		if (empty($shared['configured'])) {
			return ['state' => 'off', 'counts' => null];
		}
		$h = (string) ($webcam['hostname'] ?? '');
		if ($h === '' || !isset($shared['unique'][$h])) {
			return ['state' => 'not_unique', 'counts' => null];
		}
		if (empty($shared['ok'])) {
			return ['state' => 'down', 'counts' => null];
		}
		return ['state' => 'ok', 'counts' => $shared['counts'][$h] ?? ['avvii' => 0, 'errori' => 0, 'tentativi' => 0]];
	}

	/** Ora vera di un evento: ts se sincronizzato, altrimenti l'arrivo al portal. */
	private static function t(array $e): int
	{
		return (int) Db::unix((int) $e['time_synced'] === 1 ? $e['ts'] : $e['received_at']);
	}

	/**
	 * Avvii (eventi boot e board.power_cycle, doppioni entro 2 minuti tolti)
	 * con il flag "inatteso".
	 *
	 * @return list<array{t:int, unexpected:bool, type:string}>
	 */
	public static function boots(int $webcamId, int $from, int $now): array
	{
		$ev = Db::all(
			"SELECT type, ts, received_at, time_synced, source FROM events
			  WHERE webcam_id = ? AND ((time_synced = 1 AND ts > ? AND ts <= ?) OR (time_synced = 0 AND received_at > ? AND received_at <= ?))
			    AND (type IN ('boot', 'board.power_cycle', 'app.version_change', 'config.change', 'maintenance.on', 'maintenance.off', 'intervention') OR source = 'operator')",
			[$webcamId, Db::dt($from - 900), Db::dt($now), Db::dt($from - 900), Db::dt($now)]
		);
		$expected = [];
		$boots = [];
		foreach ($ev as $e) {
			$t = self::t($e);
			if (in_array($e['type'], self::EXPECTED, true) || $e['source'] === 'operator') {
				$expected[] = $t;
			} elseif ($t > $from) {
				$boots[] = ['t' => $t, 'type' => (string) $e['type']];
			}
		}
		foreach (Db::all('SELECT UNIX_TIMESTAMP(delivered_at) t FROM commands WHERE webcam_id = ? AND delivered_at > ? AND delivered_at <= ?', [$webcamId, Db::dt($from - 900), Db::dt($now)]) as $r) {
			$expected[] = (int) $r['t'];
		}
		usort($boots, static fn ($a, $b) => $a['t'] <=> $b['t']);
		$out = [];
		foreach ($boots as $b) {
			$lastI = count($out) - 1;
			if ($lastI >= 0 && $b['t'] - $out[$lastI]['t'] <= 120) {
				if ($b['type'] === 'board.power_cycle') {
					$out[$lastI]['type'] = 'board.power_cycle';
					$out[$lastI]['unexpected'] = true;
				}
				continue; // stesso avvio visto due volte (evento del router e boot_id cambiato)
			}
			$exp = false;
			if ($b['type'] !== 'board.power_cycle') {
				foreach ($expected as $x) {
					if ($x <= $b['t'] && $x >= $b['t'] - 900) {
						$exp = true;
						break;
					}
				}
			}
			$out[] = ['t' => $b['t'], 'type' => $b['type'], 'unexpected' => !$exp];
		}
		return $out;
	}

	/** @return list<array{t:int, type:string}> eventi camera.error, camera.missing, camera.power_cycle */
	public static function cameraEvents(int $webcamId, int $from, int $now): array
	{
		$out = [];
		foreach (Db::all(
			"SELECT type, ts, received_at, time_synced FROM events WHERE webcam_id = ? AND type IN ('camera.error','camera.missing','camera.power_cycle')
			   AND ((time_synced = 1 AND ts > ? AND ts <= ?) OR (time_synced = 0 AND received_at > ? AND received_at <= ?)) ORDER BY ts",
			[$webcamId, Db::dt($from), Db::dt($now), Db::dt($from), Db::dt($now)]
		) as $e) {
			$out[] = ['t' => self::t($e), 'type' => (string) $e['type']];
		}
		return $out;
	}

	/**
	 * Aggiorna in log_counters i conteggi dagli eventi per i giorni UTC degli
	 * ultimi 7 giorni e restituisce le righe (giorno => riga).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function days(int $webcamId, int $now, array $boots, array $camEv): array
	{
		$days = [];
		for ($i = 0; $i < 7; $i++) {
			$days[gmdate('Y-m-d', $now - $i * 86400)] = ['be' => 0, 'bu' => 0, 'ce' => 0, 'cr' => 0];
		}
		foreach ($boots as $b) {
			$d = gmdate('Y-m-d', $b['t']);
			if (isset($days[$d])) {
				$days[$d]['be']++;
				$days[$d]['bu'] += $b['unexpected'] ? 1 : 0;
			}
		}
		foreach ($camEv as $e) {
			$d = gmdate('Y-m-d', $e['t']);
			if (isset($days[$d])) {
				$days[$d][$e['type'] === 'camera.power_cycle' ? 'cr' : 'ce']++;
			}
		}
		foreach ($days as $d => $c) {
			Db::exec(
				'INSERT INTO log_counters (webcam_id, day, boots_events, boots_unexpected, camera_errors_events, capture_retries, log_ok)
				 VALUES (?, ?, ?, ?, ?, ?, 1)
				 ON DUPLICATE KEY UPDATE boots_events = VALUES(boots_events), boots_unexpected = VALUES(boots_unexpected),
				   camera_errors_events = VALUES(camera_errors_events), capture_retries = GREATEST(COALESCE(capture_retries, 0), VALUES(capture_retries))',
				[$webcamId, $d, $c['be'], $c['bu'], $c['ce'], $c['cr']]
			);
		}
		$rows = [];
		foreach (Db::all('SELECT * FROM log_counters WHERE webcam_id = ? AND day >= ? AND day <= ?', [$webcamId, gmdate('Y-m-d', $now - 6 * 86400), gmdate('Y-m-d', $now)]) as $r) {
			$rows[(string) $r['day']] = $r;
		}
		return $rows;
	}

	public static function enabled(): bool
	{
		return trim((string) Config::get()->value('victorialogs.url', '')) !== '';
	}
}
