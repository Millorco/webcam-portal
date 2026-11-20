<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Api\ApiError;
use WebcamPortal\Api\RateLimiter;
use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Site\Images;

/**
 * Statistiche di visualizzazione (M8, servizi-v1.md): solo contatori
 * aggregati per webcam, giorno locale, fonte e chiave. Nessun IP in chiaro,
 * nessun cookie: i visitatori distinti si contano con un'impronta HMAC con
 * un segreto del giorno che si cancella dopo 48 ore.
 */
final class Stats
{
	private const BOTS = ['bot', 'crawl', 'spider', 'slurp', 'preview', 'monitor', 'curl', 'wget', 'python', 'headless'];

	public static function enabled(): bool
	{
		return (bool) Config::get()->value('stats.enabled', true);
	}

	private static function day(int $webcamId, ?int $t = null): string
	{
		static $tz = [];
		if (!isset($tz[$webcamId])) {
			$tz[$webcamId] = ServiceSettings::tz((array) Db::one('SELECT timezone FROM webcams WHERE id = ?', [$webcamId]));
		}
		return (new \DateTimeImmutable('@' . ($t ?? time())))->setTimezone($tz[$webcamId])->format('Y-m-d');
	}

	/** Una richiesta (fonti image, api, telegram) o un evento già filtrato. */
	public static function count(int $webcamId, string $source, int $keyId = 0, array $add = ['views' => 1], ?int $t = null): void
	{
		if (!self::enabled()) {
			return;
		}
		try {
			$cols = array_intersect_key($add, array_flip(['views', 'visitors', 'view_seconds', 'timelapse_plays', 'photo_opens']));
			if ($cols === []) {
				return;
			}
			$names = array_keys($cols);
			Db::exec(
				'INSERT INTO view_stats_daily (webcam_id, day, source, site_key_id, ' . implode(', ', $names) . ') VALUES (?, ?, ?, ?, ' . implode(', ', array_fill(0, count($names), '?')) . ')
				 ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = $c + VALUES($c)", $names)),
				array_merge([$webcamId, self::day($webcamId, $t), $source, $keyId], array_values(array_map('intval', $cols)))
			);
		} catch (\Throwable $e) {
			Log::error('statistiche non salvate: ' . $e->getMessage(), 'stats');
		}
	}

	// ------------------------------------------------------------ beacon

	/** Token del beacon: <webcam_id>.<site_key_id>.<scadenza>.<firma>, scadenza a 3 giorni arrotondata al giorno. */
	public static function beaconToken(int $webcamId, int $keyId, ?int $now = null): string
	{
		$exp = (int) (ceil((($now ?? time()) + 3 * 86400) / 86400) * 86400);
		return "$webcamId.$keyId.$exp." . self::sign($webcamId, $keyId, $exp);
	}

	private static function sign(int $webcamId, int $keyId, int $exp): string
	{
		$mac = hash_hmac('sha256', "vb1|$webcamId|$keyId|$exp", Images::secret(), true);
		return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 22);
	}

	/** @return array{0:int,1:int}|null webcam e chiave del token valido */
	public static function checkToken(string $t, ?int $now = null): ?array
	{
		if (!preg_match('/^(\d{1,10})\.(\d{1,10})\.(\d{1,11})\.([A-Za-z0-9_-]{22})$/', $t, $m)) {
			return null;
		}
		if ((int) $m[3] <= ($now ?? time()) || !hash_equals(self::sign((int) $m[1], (int) $m[2], (int) $m[3]), $m[4])) {
			return null;
		}
		return [(int) $m[1], (int) $m[2]];
	}

	public static function isBot(string $ua): bool
	{
		$ua = strtolower(trim($ua));
		if ($ua === '') {
			return true;
		}
		foreach (self::BOTS as $b) {
			if (str_contains($ua, $b)) {
				return true;
			}
		}
		return false;
	}

	/** IPv4 a /24, IPv6 a /48. */
	public static function truncateIp(string $ip): string
	{
		$bin = @inet_pton($ip);
		if ($bin === false) {
			return '-';
		}
		if (strlen($bin) === 4) {
			return (string) inet_ntop(substr($bin, 0, 3) . "\0");
		}
		return (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
	}

	/** Segreto del giorno (32 byte casuali), creato alla prima visita. */
	private static function salt(string $day): string
	{
		$s = Db::scalar('SELECT salt FROM view_salts WHERE day = ?', [$day]);
		if ($s === null) {
			Db::exec('INSERT IGNORE INTO view_salts (day, salt) VALUES (?, ?)', [$day, random_bytes(32)]);
			$s = Db::scalar('SELECT salt FROM view_salts WHERE day = ?', [$day]);
		}
		return (string) $s;
	}

	/** Host della pagina (Origin, poi Referer), senza percorso; '-' se manca. */
	public static function host(?string $origin, ?string $referer): string
	{
		foreach ([$origin, $referer] as $u) {
			if ($u !== null && $u !== '' && $u !== 'null') {
				$h = parse_url($u, PHP_URL_HOST);
				if (is_string($h) && preg_match('/^[a-z0-9.-]{1,190}$/i', $h)) {
					return strtolower($h);
				}
			}
		}
		return '-';
	}

	/**
	 * Un beacon (POST /api/site/v1/views o views.gif). Sempre silenzioso:
	 * token sbagliati, bot e oltre i 60 al minuto per IP si ignorano.
	 */
	public static function beacon(array $in, string $ip, string $ua, ?string $origin, ?string $referer, ?int $now = null): bool
	{
		if (!self::enabled()) {
			return false;
		}
		$now ??= time();
		try {
			RateLimiter::hitBucket('vb-' . sha1($ip), 60);
		} catch (ApiError $e) {
			return false;
		}
		$tok = self::checkToken((string) ($in['t'] ?? ''), $now);
		$ev = (string) ($in['ev'] ?? '');
		if ($tok === null || !in_array($ev, ['view', 'leave', 'play', 'open'], true) || self::isBot($ua)) {
			return false;
		}
		[$wid, $keyId] = $tok;
		if (Db::scalar('SELECT id FROM webcams WHERE id = ?', [$wid]) === null) {
			return false;
		}
		$source = ($in['src'] ?? '') === 'portal' ? 'portal' : 'site';
		$day = self::day($wid, $now);
		$add = [];
		if ($ev === 'view') {
			$visitor = substr(hash_hmac('sha256', self::truncateIp($ip) . '|' . $ua . '|' . $wid, self::salt($day), true), 0, 16);
			$prev = Db::scalar('SELECT last_view_at FROM view_visitors WHERE day = ? AND webcam_id = ? AND visitor = ?', [$day, $wid, $visitor]);
			$dedupe = max(0, (int) Config::get()->value('stats.dedupe_min', 30)) * 60;
			if ($prev === null) {
				Db::exec('INSERT IGNORE INTO view_visitors (day, webcam_id, visitor, last_view_at) VALUES (?, ?, ?, ?)', [$day, $wid, $visitor, Db::dt($now)]);
				$add = ['views' => 1, 'visitors' => 1];
			} else {
				Db::exec('UPDATE view_visitors SET last_view_at = ? WHERE day = ? AND webcam_id = ? AND visitor = ?', [Db::dt($now), $day, $wid, $visitor]);
				if ((int) Db::unix((string) $prev) > $now - $dedupe) {
					return true; // doppione entro dedupe_min
				}
				$add = ['views' => 1];
			}
			self::host_($wid, self::host($origin, $referer), substr($day, 0, 7));
		} elseif ($ev === 'leave') {
			$s = (int) ($in['s'] ?? 0);
			if ($s <= 0) {
				return true;
			}
			$add = ['view_seconds' => min(3600, $s)];
		} elseif ($ev === 'play') {
			$add = ['timelapse_plays' => 1];
		} else {
			$add = ['photo_opens' => 1];
		}
		self::count($wid, $source, $keyId, $add, $now);
		return true;
	}

	/** Al più 200 host per webcam e mese, gli altri in "altri". */
	private static function host_(int $wid, string $host, string $month): void
	{
		$exists = Db::scalar('SELECT 1 FROM view_stats_hosts WHERE webcam_id = ? AND month = ? AND host = ?', [$wid, $month, $host]);
		if ($exists === null && (int) Db::scalar('SELECT COUNT(*) FROM view_stats_hosts WHERE webcam_id = ? AND month = ?', [$wid, $month]) >= 200) {
			$host = 'altri';
		}
		Db::exec('INSERT INTO view_stats_hosts (webcam_id, month, host, views) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [$wid, $month, $host]);
	}

	// ------------------------------------------------------------ current.jpg

	public static function hitsFile(): string
	{
		return Config::get()->storageDir() . '/state/image-hits.log';
	}

	/** Una richiesta di current.jpg: una riga nel file di appoggio, nessuna scrittura nel database. */
	public static function imageHit(int $webcamId): void
	{
		if (!self::enabled()) {
			return;
		}
		$f = self::hitsFile();
		if (!is_dir(dirname($f))) {
			@mkdir(dirname($f), 0775, true);
		}
		@file_put_contents($f, time() . ' ' . $webcamId . "\n", FILE_APPEND | LOCK_EX);
	}

	/** Somma il file di appoggio in view_stats_daily (bin/stats.php ogni ora). @return int richieste */
	public static function flushImageHits(): int
	{
		$f = self::hitsFile();
		if (!is_file($f)) {
			return 0;
		}
		$work = $f . '.' . getmypid();
		if (!@rename($f, $work)) {
			return 0;
		}
		$sum = [];
		$n = 0;
		$fh = fopen($work, 'r');
		while ($fh !== false && ($line = fgets($fh)) !== false) {
			if (preg_match('/^(\d+) (\d+)$/', trim($line), $m)) {
				$wid = (int) $m[2];
				$key = $wid . '|' . self::day($wid, (int) $m[1]);
				$sum[$key] = ($sum[$key] ?? 0) + 1;
				$n++;
			}
		}
		if ($fh !== false) {
			fclose($fh);
		}
		foreach ($sum as $key => $views) {
			[$wid, $day] = explode('|', $key);
			if (Db::scalar('SELECT id FROM webcams WHERE id = ?', [(int) $wid]) === null) {
				continue;
			}
			Db::exec(
				"INSERT INTO view_stats_daily (webcam_id, day, source, site_key_id, views) VALUES (?, ?, 'image', 0, ?) ON DUPLICATE KEY UPDATE views = views + VALUES(views)",
				[(int) $wid, $day, $views]
			);
		}
		@unlink($work);
		return $n;
	}

	/** Dopo 48 ore: segreti del giorno e visitatori cancellati. */
	public static function cleanup(?int $now = null): void
	{
		$cut = gmdate('Y-m-d', ($now ?? time()) - 2 * 86400);
		Db::exec('DELETE FROM view_visitors WHERE day < ?', [$cut]);
		Db::exec('DELETE FROM view_salts WHERE created_at < ?', [Db::dt(($now ?? time()) - 48 * 3600)]);
	}

	/**
	 * Statistiche per l'API e il pannello.
	 *
	 * @return array<string,mixed>
	 */
	public static function report(int $webcamId, string $from, string $to, string $group = 'day'): array
	{
		$rows = Db::all('SELECT day, source, SUM(views) v, SUM(visitors) u, SUM(view_seconds) s, SUM(timelapse_plays) tp, SUM(photo_opens) po
		                   FROM view_stats_daily WHERE webcam_id = ? AND day BETWEEN ? AND ? GROUP BY day, source ORDER BY day', [$webcamId, $from, $to]);
		$tot = ['views' => 0, 'visitors' => 0, 'view_seconds' => 0, 'avg_view_s' => 0, 'timelapse_plays' => 0, 'photo_opens' => 0, 'image_requests' => 0, 'api_requests' => 0, 'telegram' => 0];
		$by = ['site' => 0, 'portal' => 0, 'image' => 0, 'api' => 0, 'telegram' => 0];
		$series = [];
		foreach ($rows as $r) {
			$k = $group === 'month' ? substr((string) $r['day'], 0, 7) : (string) $r['day'];
			$series[$k] ??= ['date' => $k, 'views' => 0, 'visitors' => 0, 'view_seconds' => 0, 'timelapse_plays' => 0, 'image_requests' => 0];
			$by[$r['source']] += (int) $r['v'];
			if (in_array($r['source'], ['site', 'portal'], true)) {
				foreach (['views' => 'v', 'visitors' => 'u', 'view_seconds' => 's', 'timelapse_plays' => 'tp'] as $f => $c) {
					$series[$k][$f] += (int) $r[$c];
					$tot[$f] += (int) $r[$c];
				}
				$tot['photo_opens'] += (int) $r['po'];
			} elseif ($r['source'] === 'image') {
				$series[$k]['image_requests'] += (int) $r['v'];
				$tot['image_requests'] += (int) $r['v'];
			} elseif ($r['source'] === 'api') {
				$tot['api_requests'] += (int) $r['v'];
			} else {
				$tot['telegram'] += (int) $r['v'];
			}
		}
		$tot['avg_view_s'] = $tot['views'] > 0 ? (int) round($tot['view_seconds'] / $tot['views']) : 0;
		$hosts = Db::all('SELECT host, SUM(views) AS views FROM view_stats_hosts WHERE webcam_id = ? AND month BETWEEN ? AND ? GROUP BY host ORDER BY views DESC LIMIT 20', [$webcamId, substr($from, 0, 7), substr($to, 0, 7)]);
		return ['totals' => $tot, 'by_source' => $by, 'series' => array_values($series), 'hosts' => array_map(static fn ($h) => ['host' => $h['host'], 'views' => (int) $h['views']], $hosts)];
	}
}
