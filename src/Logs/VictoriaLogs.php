<?php

declare(strict_types=1);

namespace WebcamPortal\Logs;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;

/**
 * Client in sola lettura per l'API HTTP di VictoriaLogs (webcam-logserver,
 * docs/query.md "Chiamate del portal"): una sola stats_query ogni 15 minuti
 * per tutte le webcam, con riavvii, errori della fotocamera e primi
 * tentativi falliti. Dalle 00:20 UTC il giorno UTC chiuso con le due query
 * di servizi-v1.md ("Conteggi del logserver") per log_counters e il
 * rapporto mensile; un giorno non riuscito si ritenta alle esecuzioni dopo,
 * al più per 3 giorni. Timeout 5 secondi: se non risponde le regole logs.*
 * usano solo il database.
 */
final class VictoriaLogs
{
	/** Righe contate (diagnosi-v1.md, logs.reboots e logs.camera_errors; solo le righe finali degli scatti falliti). */
	public const BOOT = '"Linux version"';
	public const ERRORS = 'level:error ("anche dopo il riavvio dalla scheda" OR "scatto fallito dopo il riavvio")';
	public const RETRIES = '"primo tentativo di scatto fallito"';

	/** Nomi della query 1 del giorno chiuso (servizi-v1.md) => colonna di log_counters. */
	public const DAILY = [
		'righe' => 'log_lines', 'avvii' => 'boots_log', 'errori' => 'camera_errors_log', 'tentativi' => 'capture_retries',
		'upload_falliti' => 'upload_failures', 'upload_ok' => 'uploads_ok', 'ora_non_sincronizzata' => 'ntp_issues', 'sensori' => 'sensor_errors_log',
	];

	public function __construct(private string $url, private string $user = '', private string $pass = '', private int $timeout = 5)
	{
		$this->url = rtrim($url, '/');
	}

	public static function fromConfig(): ?self
	{
		$c = Config::get();
		$url = trim((string) $c->value('victorialogs.url', ''));
		if ($url === '') {
			return null;
		}
		return new self($url, (string) $c->value('victorialogs.user', ''), (string) $c->value('victorialogs.pass', ''), max(1, (int) $c->value('victorialogs.timeout', 5)));
	}

	/** Query unica: filtro sull'intervallo + stats by (hostname) con count() if. */
	public static function query(string $time): string
	{
		return $time . ' (' . self::BOOT . ' OR "anche dopo il riavvio dalla scheda" OR "scatto fallito dopo il riavvio" OR ' . self::RETRIES . ')'
			. ' | stats by (hostname) count() if (' . self::BOOT . ') avvii, count() if (' . self::ERRORS . ') errori, count() if (' . self::RETRIES . ') tentativi';
	}

	/** Query 1 del giorno chiuso: conteggi per il rapporto mensile. */
	public static function dailyQuery(string $from, string $to): string
	{
		return "_time:[$from, $to) | stats by (hostname) count() righe, count() if (" . self::BOOT . ') avvii, count() if (' . self::ERRORS . ') errori, count() if ('
			. self::RETRIES . ') tentativi, count() if ("upload fallito") upload_falliti, count() if ("OK foto caricata") upload_ok,'
			. ' count() if ("ora non sincronizzata" OR "ora del router non valida") ora_non_sincronizzata, count() if ("sensore" "non risponde") sensori';
	}

	/** Query 2 del giorno chiuso: ore con almeno una riga. */
	public static function hoursQuery(string $from, string $to): string
	{
		return "_time:[$from, $to) | stats by (hostname, _time:1h) count() righe | stats by (hostname) count() ore";
	}

	/**
	 * stats_query: conteggi per hostname. Un hostname senza righe non compare
	 * (vale 0).
	 *
	 * @return array<string,array{avvii:int,errori:int,tentativi:int}>
	 */
	public function counts(string $timeFilter, ?int $at = null): array
	{
		$out = [];
		foreach ($this->stats(self::query($timeFilter), ['avvii', 'errori', 'tentativi'], $at) as $host => $c) {
			$out[$host] = $c + ['avvii' => 0, 'errori' => 0, 'tentativi' => 0];
		}
		return $out;
	}

	/**
	 * Una stats_query qualsiasi: per hostname i valori dei nomi chiesti.
	 *
	 * @param list<string> $names
	 * @return array<string,array<string,int>>
	 */
	public function stats(string $query, array $names, ?int $at = null): array
	{
		$params = ['query' => $query];
		if ($at !== null) {
			$params['time'] = (string) $at;
		}
		$headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json', 'User-Agent: webcam-portal/' . WEBCAM_PORTAL_VERSION];
		if ($this->user !== '' || $this->pass !== '') {
			$headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->pass);
		}
		$ctx = stream_context_create(['http' => [
			'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => http_build_query($params),
			'timeout' => $this->timeout, 'ignore_errors' => true,
		]]);
		$http_response_header = [];
		$raw = @file_get_contents($this->url . '/select/logsql/stats_query', false, $ctx);
		$status = 0;
		foreach ($http_response_header as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
			}
		}
		if ($raw === false || $status !== 200) {
			throw new \RuntimeException('VictoriaLogs ' . ($status ?: 'non raggiungibile') . ': ' . substr((string) $raw, 0, 200));
		}
		$j = json_decode((string) $raw, true);
		if (!is_array($j) || ($j['status'] ?? '') !== 'success' || !is_array($j['data']['result'] ?? null)) {
			throw new \RuntimeException('VictoriaLogs: risposta non valida');
		}
		$out = [];
		foreach ($j['data']['result'] as $r) {
			$name = $r['metric']['__name__'] ?? null;
			$host = $r['metric']['hostname'] ?? null;
			$val = $r['value'][1] ?? null;
			if (!is_string($name) || !is_string($host) || !in_array($name, $names, true)) {
				continue;
			}
			$out[$host] ??= [];
			$out[$host][$name] = (int) $val;
		}
		return $out;
	}

	public static function cacheFile(): string
	{
		return Config::get()->storageDir() . '/cache/victorialogs.json';
	}

	/** @return array<string,mixed>|null */
	public static function cache(): ?array
	{
		$f = self::cacheFile();
		$d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
		return is_array($d) ? $d : null;
	}

	/**
	 * Aggiorna i conteggi delle ultime 24 ore (al più ogni every_min minuti,
	 * o subito con $force) e, una volta al giorno, log_counters del giorno UTC
	 * chiuso.
	 */
	public static function refresh(int $now, bool $force = false): void
	{
		$vl = self::fromConfig();
		if ($vl === null) {
			return;
		}
		$cache = self::cache() ?? [];
		$every = max(1, (int) Config::get()->value('victorialogs.every_min', 15)) * 60;
		if (!$force && isset($cache['time']) && abs($now - (int) $cache['time']) < $every - 30) {
			return;
		}
		$new = ['time' => $now, 'ok' => false, 'counts' => [], 'daily_done' => $cache['daily_done'] ?? null, 'daily_retry' => $cache['daily_retry'] ?? []];
		try {
			$new['counts'] = $vl->counts('_time:24h', $now);
			$new['ok'] = true;
		} catch (\Throwable $e) {
			Log::warning('VictoriaLogs: ' . $e->getMessage() . ' (regole logs.* solo con gli eventi)', 'rules');
		}
		// giorno UTC chiuso: dalle 00:20 (subito con --logs), più i giorni da ritentare (al più 3 giorni)
		$yesterday = gmdate('Y-m-d', $now - 86400);
		$days = array_values(array_filter((array) $new['daily_retry'], static fn ($d) => is_string($d) && $d >= gmdate('Y-m-d', $now - 4 * 86400) && $d < $yesterday));
		if ($new['daily_done'] !== $yesterday && !in_array($yesterday, $days, true) && ($force || $now % 86400 >= 20 * 60)) {
			$days[] = $yesterday;
		}
		$retry = [];
		if (!$new['ok']) {
			$retry = array_values(array_diff($days, [$yesterday])); // VictoriaLogs non risponde: si riprova alla prossima
			$days = [];
		}
		foreach ($days as $d) {
			try {
				$from = $d . 'T00:00:00Z';
				$to = gmdate('Y-m-d', (int) strtotime($d . ' 00:00:00 UTC') + 86400) . 'T00:00:00Z';
				$c = $vl->stats(self::dailyQuery($from, $to), array_keys(self::DAILY), $now);
				foreach ($vl->stats(self::hoursQuery($from, $to), ['ore'], $now) as $host => $h) {
					$c[$host] = ($c[$host] ?? []) + $h;
				}
				self::saveDay($d, $c, true);
				if ($d === $yesterday) {
					$new['daily_done'] = $yesterday;
				}
			} catch (\Throwable $e) {
				Log::warning("VictoriaLogs, giorno chiuso $d: " . $e->getMessage() . ' (si ritenta)', 'rules');
				self::saveDay($d, [], false);
				$retry[] = $d;
				if ($d === $yesterday) {
					$new['daily_done'] = $yesterday; // non si rifà come nuovo: è tra quelli da ritentare
				}
			}
		}
		$new['daily_retry'] = $retry;
		$dir = dirname(self::cacheFile());
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@file_put_contents(self::cacheFile(), json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
	}

	/**
	 * Conteggi dei log di un giorno UTC in log_counters (solo hostname
	 * univoci). Con $ok = false solo log_ok = 0: i valori di un tentativo
	 * riuscito prima restano.
	 */
	private static function saveDay(string $day, array $counts, bool $ok): void
	{
		foreach (LogData::uniqueHosts() as $host => $wid) {
			if (!$ok) {
				Db::exec('INSERT INTO log_counters (webcam_id, day, log_ok) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE log_ok = 0', [$wid, $day]);
				continue;
			}
			$c = $counts[$host] ?? [];
			$v = [];
			foreach (self::DAILY as $name => $col) {
				$max = $col === 'log_lines' ? 4294967295 : 65535;
				$v[$col] = min($max, (int) ($c[$name] ?? 0));
			}
			$v['log_hours'] = min(24, (int) ($c['ore'] ?? 0));
			$cols = array_keys($v);
			Db::exec(
				'INSERT INTO log_counters (webcam_id, day, ' . implode(', ', $cols) . ', log_ok) VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', 1)
				 ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => $c === 'capture_retries'
					? 'capture_retries = GREATEST(COALESCE(capture_retries, 0), VALUES(capture_retries))' : "$c = VALUES($c)", $cols)) . ', log_ok = 1',
				array_merge([$wid, $day], array_values($v))
			);
		}
	}
}
