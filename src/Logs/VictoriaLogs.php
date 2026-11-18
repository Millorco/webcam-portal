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
 * tentativi falliti; una volta al giorno la stessa query sul giorno UTC
 * chiuso per log_counters. Timeout 5 secondi: se non risponde le regole
 * logs.* usano solo il database.
 */
final class VictoriaLogs
{
	/** Righe contate (diagnosi-v1.md, logs.reboots e logs.camera_errors; solo le righe finali degli scatti falliti). */
	public const BOOT = '"Linux version"';
	public const ERRORS = 'level:error ("anche dopo il riavvio dalla scheda" OR "scatto fallito dopo il riavvio")';
	public const RETRIES = '"primo tentativo di scatto fallito"';

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

	/**
	 * stats_query: conteggi per hostname. Un hostname senza righe non compare
	 * (vale 0).
	 *
	 * @return array<string,array{avvii:int,errori:int,tentativi:int}>
	 */
	public function counts(string $timeFilter, ?int $at = null): array
	{
		$params = ['query' => self::query($timeFilter)];
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
			if (!is_string($name) || !is_string($host) || !in_array($name, ['avvii', 'errori', 'tentativi'], true)) {
				continue;
			}
			$out[$host] ??= ['avvii' => 0, 'errori' => 0, 'tentativi' => 0];
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
		$new = ['time' => $now, 'ok' => false, 'counts' => [], 'daily_done' => $cache['daily_done'] ?? null];
		try {
			$new['counts'] = $vl->counts('_time:24h', $now);
			$new['ok'] = true;
		} catch (\Throwable $e) {
			Log::warning('VictoriaLogs: ' . $e->getMessage() . ' (regole logs.* solo con gli eventi)', 'rules');
		}
		$yesterday = gmdate('Y-m-d', $now - 86400);
		if ($new['ok'] && $new['daily_done'] !== $yesterday) {
			try {
				$from = $yesterday . 'T00:00:00Z';
				$to = gmdate('Y-m-d', $now) . 'T00:00:00Z';
				$day = $vl->counts("_time:[$from, $to)", $now);
				self::saveDay($yesterday, $day, true);
				$new['daily_done'] = $yesterday;
			} catch (\Throwable $e) {
				Log::warning('VictoriaLogs, giorno chiuso: ' . $e->getMessage(), 'rules');
				self::saveDay($yesterday, [], false);
			}
		}
		$dir = dirname(self::cacheFile());
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@file_put_contents(self::cacheFile(), json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
	}

	/** Conteggi dei log di un giorno UTC in log_counters (solo hostname univoci). */
	private static function saveDay(string $day, array $counts, bool $ok): void
	{
		foreach (LogData::uniqueHosts() as $host => $wid) {
			$c = $counts[$host] ?? ['avvii' => 0, 'errori' => 0, 'tentativi' => 0];
			Db::exec(
				'INSERT INTO log_counters (webcam_id, day, boots_log, camera_errors_log, capture_retries, log_ok) VALUES (?, ?, ?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE boots_log = VALUES(boots_log), camera_errors_log = VALUES(camera_errors_log),
				   capture_retries = GREATEST(COALESCE(capture_retries, 0), VALUES(capture_retries)), log_ok = VALUES(log_ok)',
				[$wid, $day, $ok ? min(65535, $c['avvii']) : null, $ok ? min(65535, $c['errori']) : null, $ok ? min(65535, $c['tentativi']) : null, $ok ? 1 : 0]
			);
		}
	}
}
