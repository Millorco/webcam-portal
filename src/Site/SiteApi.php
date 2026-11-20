<?php

declare(strict_types=1);

namespace WebcamPortal\Site;

use WebcamPortal\Api\ApiError;
use WebcamPortal\Api\RateLimiter;
use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Service\Customers;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\ServiceSettings;
use WebcamPortal\Service\SiteKeys;
use WebcamPortal\Service\Stats;

/**
 * API in sola lettura per i siti (plugin WordPress), api-siti-v1.md:
 *
 *   GET /api/site/v1/whoami
 *   GET /api/site/v1/webcams
 *   GET /api/site/v1/webcams/{slug}
 *   GET /api/site/v1/webcams/{slug}/photos?date=AAAA-MM-GG
 *   GET /api/site/v1/webcams/{slug}/days?month=AAAA-MM
 *   GET /api/site/v1/webcams/{slug}/series?from=&to=&fields=
 *   GET /api/site/v1/webcams/{slug}/compare?a=&b=
 *
 * Servizi 1.1 (contratto 1.3): same-moment, timelapses, photo-of-day,
 * photos-of-day, overlay-items, stats, reports, weather-days, certificates
 * (ognuno solo con la sua funzione in features) e il beacon
 * POST /api/site/v1/views (GET views.gif) per le statistiche.
 *
 * Senza chiave: solo le webcam pubbliche. Con chiave vls_ (Authorization:
 * Bearer): scope public (webcam pubbliche del cliente, o tutte) o client
 * (tutte le webcam del cliente). Mai MAC, IP, token, id Dolibarr, avvisi o
 * ticket nelle risposte.
 */
final class SiteApi
{
	private const SLUG = '[a-z0-9][a-z0-9-]{0,63}';
	private const SERIES_FIELDS = [
		'temperature_ext' => ['t_ext_avg', 't_ext_min', 't_ext_max'],
		'humidity_ext' => ['rh_ext_avg', 'rh_ext_min', 'rh_ext_max'],
		'dewpoint_ext' => ['dp_ext_avg', null, null],
	];

	/** scope: anon | public | client */
	private string $scope = 'anon';
	private ?array $key = null;
	private ?array $customer = null;
	private ?string $origin = null;
	private string $base;

	public function __construct(private Request $req)
	{
		$this->base = $req->baseUrl();
		$o = $req->header('Origin');
		$this->origin = $o === null || trim($o) === '' || $o === 'null' ? null : trim($o);
	}

	public static function handle(Request $req): void
	{
		(new self($req))->run();
	}

	private function run(): void
	{
		$extra = [];
		try {
			$sub = rtrim(substr($this->req->path, strlen('/api/site/v1')), '/');
			if ($sub === '/views' || $sub === '/views.gif') {
				$this->beacon($sub === '/views.gif');
				return;
			}
			header('Vary: Origin, Authorization');
			if ($this->req->method === 'OPTIONS') {
				$this->preflight();
				return;
			}
			if (!in_array($this->req->method, ['GET', 'HEAD'], true)) {
				throw new ApiError(405, 'method_not_allowed', 'API in sola lettura: solo GET', ['Allow' => 'GET, HEAD, OPTIONS']);
			}
			if (!Config::get()->isDev() && !$this->req->isHttps()) {
				throw new ApiError(403, 'https_required', 'Le API accettano solo HTTPS');
			}
			$this->authenticate();
			$extra = $this->corsHeaders();
			[$data, $cache] = $this->route(rtrim(substr($this->req->path, strlen('/api/site/v1')), '/'));
			$this->send(200, $data, $extra + ['Cache-Control' => $cache]);
		} catch (ApiError $e) {
			if ($e->status >= 500) {
				Log::error("api siti {$this->req->path}: {$e->error} {$e->getMessage()}", 'site-api');
			}
			$h = $e->headers + ($e->status === 403 && $e->error === 'origin_denied' ? [] : ($extra ?: $this->anonCors()));
			$this->send($e->status, ['ok' => false, 'error' => $e->error, 'message' => $e->getMessage()] + $e->extra, $h + ['Cache-Control' => 'no-store']);
		} catch (\Throwable $e) {
			Log::error("api siti {$this->req->path}: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'site-api');
			$this->send($e instanceof \PDOException ? 503 : 500, ['ok' => false, 'error' => 'server_error', 'message' => 'Errore del server, riprovare più tardi'], ($extra ?: $this->anonCors()) + ['Cache-Control' => 'no-store']);
		}
	}

	/** @param array<string,string> $headers */
	private function send(int $status, array $data, array $headers): void
	{
		$data['server_time'] = time();
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		foreach ($headers as $k => $v) {
			header($k . ': ' . $v);
		}
		if ($this->req->method !== 'HEAD') {
			echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		}
	}

	// ------------------------------------------------------------ chiavi e CORS

	private function preflight(): void
	{
		http_response_code(204);
		if ($this->origin !== null) {
			// la chiave non arriva nella richiesta preliminare: l'origine si
			// controlla sulla richiesta vera (403 origin_denied)
			header('Access-Control-Allow-Origin: ' . $this->origin);
			header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
			header('Access-Control-Allow-Headers: Authorization');
			header('Access-Control-Max-Age: 600');
		}
	}

	/** @return array<string,string> */
	private function anonCors(): array
	{
		return $this->origin !== null && $this->key === null ? ['Access-Control-Allow-Origin' => '*', 'Access-Control-Expose-Headers' => 'Retry-After'] : [];
	}

	/** @return array<string,string> */
	private function corsHeaders(): array
	{
		if ($this->origin === null) {
			return [];
		}
		if ($this->key === null) {
			return $this->anonCors();
		}
		return ['Access-Control-Allow-Origin' => $this->origin, 'Access-Control-Expose-Headers' => 'Retry-After'];
	}

	private function authenticate(): void
	{
		$h = trim((string) $this->req->header('Authorization'));
		if ($h === '') {
			$limit = (int) Config::get()->value('site_api.anon_rate_limit_per_min', 120);
			RateLimiter::hitBucket('ip-' . sha1($this->req->clientIp()), $limit);
			return;
		}
		if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m) || !preg_match(SiteKeys::PATTERN, $m[1])) {
			throw new ApiError(401, 'key_unknown', 'Chiave sconosciuta: serve Authorization: Bearer vls_...');
		}
		$row = Db::one(
			'SELECT k.*, c.name AS customer_name, c.active AS customer_active FROM site_keys k LEFT JOIN customers c ON c.id = k.customer_id WHERE k.key_hash = ?',
			[SiteKeys::hash($m[1])]
		);
		if ($row === null) {
			throw new ApiError(401, 'key_unknown', 'Chiave sconosciuta');
		}
		if ($row['revoked_at'] !== null) {
			throw new ApiError(403, 'key_revoked', 'Chiave revocata');
		}
		if ($row['expires_at'] !== null && Db::unix($row['expires_at']) <= time()) {
			throw new ApiError(403, 'key_expired', 'Chiave scaduta');
		}
		if ($row['customer_id'] !== null && (int) $row['customer_active'] !== 1) {
			throw new ApiError(403, 'customer_inactive', 'Cliente disattivato');
		}
		if ($this->origin !== null && !SiteKeys::originAllowed($this->origin, SiteKeys::origins($row['origins']))) {
			throw new ApiError(403, 'origin_denied', 'Origine non ammessa per questa chiave');
		}
		$limit = (int) ($row['rate_limit_per_min'] ?? 0) ?: (int) Config::get()->value('site_api.rate_limit_per_min', 600);
		RateLimiter::hitBucket('k' . (int) $row['id'], $limit);
		if ($row['last_used_at'] === null || Db::unix($row['last_used_at']) < time() - 60 || (string) $row['last_used_origin'] !== (string) $this->origin) {
			Db::exec('UPDATE site_keys SET last_used_at = UTC_TIMESTAMP(), last_used_origin = ? WHERE id = ?', [$this->origin === null ? null : mb_substr($this->origin, 0, 255), $row['id']]);
		}
		$this->key = $row;
		$this->scope = (string) $row['scope'];
		$this->customer = $row['customer_id'] === null ? null : ['id' => (int) $row['customer_id'], 'name' => (string) $row['customer_name']];
	}

	// ------------------------------------------------------------ percorsi

	/** @return array{0: array<string,mixed>, 1: string} dati e Cache-Control */
	private function route(string $p): array
	{
		$cache = ($this->key === null ? 'public' : 'private') . ', max-age=' . max(0, (int) Config::get()->value('site_api.cache_max_age', 60));
		if ($p === '/whoami') {
			return [$this->whoami(), 'no-store'];
		}
		if ($p === '/webcams') {
			return [$this->listWebcams(), $cache];
		}
		if (preg_match('#^/webcams/(' . self::SLUG . ')(?:/(photos|days|series|compare|same-moment|timelapses|photo-of-day|photos-of-day|overlay-items|stats|reports|weather-days|certificates))?$#', $p, $m)) {
			$w = $this->webcam($m[1]);
			$data = match ($m[2] ?? '') {
				'' => $this->detail($w),
				'photos' => $this->photos($w),
				'days' => $this->days($w),
				'series' => $this->series($w),
				'compare' => $this->compare($w),
				'same-moment' => $this->sameMoment($w),
				'timelapses' => $this->timelapses($w),
				'photo-of-day' => $this->photoOfDay($w),
				'photos-of-day' => $this->photosOfDay($w),
				'overlay-items' => $this->overlayItems($w),
				'stats' => $this->stats($w),
				'reports' => $this->reports($w),
				'weather-days' => $this->weatherDays($w),
				'certificates' => $this->certificates($w),
			};
			Stats::count((int) $w['id'], 'api', $this->key === null ? 0 : (int) $this->key['id']);
			$private = in_array($m[2] ?? '', ['stats', 'reports', 'weather-days', 'certificates'], true);
			return [$data, $private ? 'private, no-store' : $cache];
		}
		throw new ApiError(404, 'not_found', 'Endpoint inesistente');
	}

	private function whoami(): array
	{
		return [
			'ok' => true,
			'scope' => $this->scope,
			'customer' => $this->customer,
			'key' => $this->key === null ? null : [
				'prefix' => $this->key['key_prefix'], 'label' => $this->key['label'],
				'origins' => SiteKeys::origins($this->key['origins']),
				'expires_at' => $this->key['expires_at'] === null ? null : Db::unix($this->key['expires_at']),
			],
			'sizes' => Images::sizesFor($this->scope),
			'rate_limit_per_min' => $this->key === null
				? (int) Config::get()->value('site_api.anon_rate_limit_per_min', 120)
				: ((int) ($this->key['rate_limit_per_min'] ?? 0) ?: (int) Config::get()->value('site_api.rate_limit_per_min', 600)),
			'server' => Features::server(),
		];
	}

	// ------------------------------------------------------------ visibilità

	/** @return array{0:string, 1:list<mixed>} condizione SQL sulle webcam visibili */
	private function webcamFilter(): array
	{
		if ($this->scope === 'client') {
			return ['w.customer_id = ? AND w.status <> 9', [$this->customer['id']]];
		}
		if ($this->customer !== null) {
			return ['w.customer_id = ? AND w.is_public = 1 AND w.status = 1', [$this->customer['id']]];
		}
		return ['w.is_public = 1 AND w.status = 1', []];
	}

	private function webcam(string $slug): array
	{
		[$cond, $p] = $this->webcamFilter();
		$w = Db::one("SELECT w.* FROM webcams w WHERE w.slug = ? AND $cond", array_merge([$slug], $p));
		if ($w === null && $this->scope === 'client') {
			$w = Db::one('SELECT w.* FROM webcams w WHERE w.slug = ? AND w.status <> 9', [$slug]);
			$arch = $w === null ? [] : $this->archived((int) $w['id']);
			if ($w !== null && isset($arch[(int) $w['id']])) {
				$w['_archived'] = $arch[(int) $w['id']];
			} else {
				$w = null;
			}
		}
		if ($w === null) {
			// 404 anche per le webcam private: non si rivela quali esistono
			throw new ApiError(404, 'not_found', 'Webcam inesistente o non visibile con questa chiave');
		}
		$w['_ranges'] = $this->ranges($w);
		return $w;
	}

	/**
	 * Webcam in archivio per il cliente della chiave (M13): non più sue, con
	 * l'ultima assegnazione chiusa e l'archivio non ancora scaduto
	 * (archive_until, altrimenti archive.default_days da valid_to).
	 *
	 * @return array<int,string> webcam_id => ultimo giorno visibile AAAA-MM-GG
	 */
	private function archived(?int $webcamId = null): array
	{
		if ($this->scope !== 'client') {
			return [];
		}
		$sql = 'SELECT cw.webcam_id, cw.valid_to, cw.archive_until, w.timezone FROM customer_webcams cw JOIN webcams w ON w.id = cw.webcam_id
		         WHERE cw.customer_id = ? AND cw.valid_to IS NOT NULL AND w.status <> 9
		           AND (w.customer_id IS NULL OR w.customer_id <> cw.customer_id)';
		$p = [$this->customer['id']];
		if ($webcamId !== null) {
			$sql .= ' AND cw.webcam_id = ?';
			$p[] = $webcamId;
		}
		$days = max(0, (int) Config::get()->value('archive.default_days', 30));
		$out = [];
		foreach (Db::all($sql . ' ORDER BY cw.valid_to', $p) as $r) {
			$tz = ServiceSettings::tz($r);
			$until = $r['archive_until'] !== null ? (string) $r['archive_until']
				: (new \DateTimeImmutable('@' . Db::unix((string) $r['valid_to'])))->setTimezone($tz)->modify("+$days days")->format('Y-m-d');
			$out[(int) $r['webcam_id']] = $until; // l'ultima assegnazione chiusa vince
		}
		$today = [];
		foreach ($out as $id => $until) {
			$tz = ServiceSettings::tz((array) Db::one('SELECT timezone FROM webcams WHERE id = ?', [$id]));
			if ((new \DateTimeImmutable('now', $tz))->format('Y-m-d') <= $until) {
				$today[$id] = $until;
			}
		}
		return $today;
	}

	/** Funzioni della webcam per questa risposta (in archivio: solo quelle di consultazione). */
	private function features(array $w): array
	{
		if (isset($w['_archived'])) {
			return Features::ARCHIVED;
		}
		return Features::forWebcam($w);
	}

	/** 403 feature_unavailable / scope_client_required. */
	private function need(array $w, string $feature, bool $clientOnly = false): void
	{
		if ($clientOnly && $this->scope !== 'client') {
			throw new ApiError(403, 'scope_client_required', 'Serve una chiave con scope client');
		}
		if (!in_array($feature, $this->features($w), true)) {
			throw new ApiError(403, 'feature_unavailable', 'Funzione non compresa nel servizio di questa webcam');
		}
	}

	/**
	 * Periodi visibili della webcam [da, a|null]: per lo scope client quelli
	 * in cui la webcam era del cliente; per il pubblico dall'inizio
	 * dell'assegnazione in corso (le foto del cliente di prima restano sue).
	 *
	 * @return list<array{0:int,1:?int}>
	 */
	private function ranges(array $w): array
	{
		if ($this->scope === 'client') {
			return Customers::periods($this->customer['id'], (int) $w['id']);
		}
		return [[Customers::currentSince((int) $w['id']) ?? 0, null]];
	}

	/** @return array{0:string, 1:list<mixed>} */
	private static function rangeSql(array $w, string $col, ?int $from = null, ?int $to = null): array
	{
		$parts = [];
		$p = [];
		foreach ($w['_ranges'] as [$a, $b]) {
			$a = max($a, $from ?? 0);
			$b = $to === null ? $b : ($b === null ? $to : min($b, $to));
			if ($b !== null && $b <= $a) {
				continue;
			}
			$parts[] = $b === null ? "$col >= ?" : "($col >= ? AND $col < ?)";
			$p[] = Db::dt($a);
			if ($b !== null) {
				$p[] = Db::dt($b);
			}
		}
		return $parts === [] ? ['0 = 1', []] : ['(' . implode(' OR ', $parts) . ')', $p];
	}

	// ------------------------------------------------------------ oggetti

	private function sizes(): array
	{
		$all = Images::sizesFor($this->scope);
		$want = $_GET['sizes'] ?? null;
		if (!is_string($want) || trim($want) === '') {
			return $all;
		}
		$list = array_values(array_intersect($all, array_map('trim', explode(',', $want))));
		if ($list === []) {
			throw new ApiError(400, 'invalid_param', 'sizes: valori ammessi ' . implode(',', $all));
		}
		return $list;
	}

	private function photoObj(array $p, \DateTimeZone $tz, array $sizes, ?int $exp = null): array
	{
		$t = (int) Db::unix((string) $p['taken_at']);
		return [
			'id' => (int) $p['id'],
			'time' => $t,
			'datetime' => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d\TH:i:sP'),
			'mode' => $p['mode'],
			'temperature_ext' => $p['t_ext'] === null ? null : (float) $p['t_ext'],
			'humidity_ext' => $p['rh_ext'] === null ? null : (float) $p['rh_ext'],
			'width' => $p['width'] === null ? null : (int) $p['width'],
			'height' => $p['height'] === null ? null : (int) $p['height'],
			'images' => Images::urls($this->base, (int) $p['id'], $sizes, $exp),
		];
	}

	private const PHOTO_COLS = 'p.id, p.taken_at, p.mode, p.t_ext, p.rh_ext, p.width, p.height';

	private function lastPhoto(array $w): ?array
	{
		[$rc, $rp] = self::rangeSql($w, 'p.taken_at');
		return Db::one(
			'SELECT ' . self::PHOTO_COLS . " FROM photos p WHERE p.webcam_id = ? AND p.deleted_at IS NULL AND p.is_public = 1 AND $rc
			  ORDER BY p.taken_at DESC, p.id DESC LIMIT 1",
			array_merge([$w['id']], $rp)
		);
	}

	private function state(array $w): string
	{
		if (isset($w['_archived'])) {
			return 'archived';
		}
		if ((int) $w['status'] === 0) {
			return 'not_installed';
		}
		$seen = $w['last_seen_at'] === null ? null : time() - (int) Db::unix($w['last_seen_at']);
		if ($seen === null) {
			return 'never';
		}
		if ($seen < 2400) {
			$m = Db::scalar(
				"SELECT JSON_VALUE(extra, '$.sys.maintenance') FROM measurements WHERE webcam_id = ? AND ts >= ? ORDER BY ts DESC LIMIT 1",
				[$w['id'], Db::dt(time() - 3600)]
			);
			return $m === 'true' ? 'maintenance' : 'online';
		}
		return $seen < 4 * 3600 ? 'late' : 'offline';
	}

	private function webcamObj(array $w, ?array $sizes = null): array
	{
		$tz = ServiceSettings::tz($w);
		$last = $this->lastPhoto($w);
		$public = (int) $w['is_public'] === 1 && (int) $w['status'] === 1 && !isset($w['_archived']);
		$features = $this->features($w);
		$obj = [
			'slug' => $w['slug'],
			'name' => $w['name'],
			'location' => $w['location'],
			'latitude' => $w['latitude'] === null ? null : (float) $w['latitude'],
			'longitude' => $w['longitude'] === null ? null : (float) $w['longitude'],
			'altitude_m' => $w['altitude_m'] === null ? null : (int) $w['altitude_m'],
			'timezone' => $tz->getName(),
			'public' => $public,
			'state' => $this->state($w),
			'last_seen' => $w['last_seen_at'] === null ? null : Db::unix($w['last_seen_at']),
			'last_photo' => $last === null ? null : $this->photoObj($last, $tz, $sizes ?? $this->sizes()),
			'current_url' => $public ? $this->base . '/cam/' . $w['slug'] . '/current.jpg' : null,
			'features' => $features,
			'overlay' => !isset($w['_archived']) && Overlay::appliesTo(Overlay::active($w), '640'),
			'beacon' => ['url' => $this->base . '/api/site/v1/views', 'token' => Stats::beaconToken((int) $w['id'], $this->key === null ? 0 : (int) $this->key['id'])],
		];
		if (isset($w['_archived'])) {
			$obj['archive_until'] = $w['_archived'];
		}
		return $obj;
	}

	// ------------------------------------------------------------ endpoint

	private function listWebcams(): array
	{
		[$cond, $p] = $this->webcamFilter();
		$sizes = $this->sizes();
		$out = [];
		$rows = Db::all("SELECT w.* FROM webcams w WHERE $cond ORDER BY w.name, w.slug", $p);
		$arch = $this->archived();
		if ($arch !== []) {
			foreach (Db::all('SELECT w.* FROM webcams w WHERE w.id IN (' . implode(',', array_map('intval', array_keys($arch))) . ')') as $w) {
				$w['_archived'] = $arch[(int) $w['id']];
				$rows[] = $w;
			}
			usort($rows, static fn ($a, $b) => [(string) $a['name'], $a['slug']] <=> [(string) $b['name'], $b['slug']]);
		}
		foreach ($rows as $w) {
			$w['_ranges'] = $this->ranges($w);
			$out[] = $this->webcamObj($w, $sizes);
		}
		return ['ok' => true, 'scope' => $this->scope, 'customer' => $this->customer, 'count' => count($out), 'webcams' => $out];
	}

	private function detail(array $w): array
	{
		$tz = ServiceSettings::tz($w);
		$obj = $this->webcamObj($w);
		$obj['interval_min'] = $w['interval_min'] === null ? null : (int) $w['interval_min'];

		// ultime misure esterne (al più di 3 ore fa)
		[$rc, $rp] = self::rangeSql($w, 'ts', time() - 3 * 3600);
		$m = Db::one(
			"SELECT ts, t_ext, rh_ext, dp_ext FROM measurements WHERE webcam_id = ? AND $rc AND (t_ext IS NOT NULL OR rh_ext IS NOT NULL) ORDER BY ts DESC LIMIT 1",
			array_merge([$w['id']], $rp)
		);
		$obj['weather'] = $m === null || isset($w['_archived']) ? null : [
			'time' => Db::unix($m['ts']),
			'temperature_ext' => $m['t_ext'] === null ? null : (float) $m['t_ext'],
			'humidity_ext' => $m['rh_ext'] === null ? null : (float) $m['rh_ext'],
			'dewpoint_ext' => $m['dp_ext'] === null ? null : (float) $m['dp_ext'],
		];

		// disponibilità del mese: sempre per il cliente, per il pubblico solo con availability_public
		$month = (new \DateTimeImmutable('now', $tz))->format('Y-m');
		$av = null;
		if ($this->scope === 'client' || ((bool) Config::get()->value('availability_public', false) && $obj['public'])) {
			$av = Db::one('SELECT month, pct, final FROM availability_monthly WHERE webcam_id = ? AND month = ?', [$w['id'], $month]);
		}
		$obj['availability'] = $av === null || isset($w['_archived']) ? null : ['month' => $av['month'], 'pct' => $av['pct'] === null ? null : (float) $av['pct'], 'final' => (bool) $av['final']];

		// giorni con foto del mese in corso e primo/ultimo giorno dell'archivio
		$obj['month_days'] = ['month' => $month, 'days' => array_column($this->dayCounts($w, $month), 'date')];
		[$rc, $rp] = self::rangeSql($w, 'taken_at');
		$r = Db::one("SELECT MIN(taken_at) AS a, MAX(taken_at) AS b FROM photos WHERE webcam_id = ? AND deleted_at IS NULL AND is_public = 1 AND $rc", array_merge([$w['id']], $rp));
		$fmt = static fn (?string $dt): ?string => $dt === null ? null : (new \DateTimeImmutable('@' . Db::unix($dt)))->setTimezone($tz)->format('Y-m-d');
		$obj['archive'] = ['first_day' => $fmt($r['a'] ?? null), 'last_day' => $fmt($r['b'] ?? null)];
		$obj['sizes'] = Images::sizesFor($this->scope);
		return ['ok' => true, 'webcam' => $obj];
	}

	private function localDay(\DateTimeZone $tz, string $param, ?string $default): \DateTimeImmutable
	{
		$v = $_GET[$param] ?? $default;
		if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
			throw new ApiError(400, 'invalid_param', "$param: data AAAA-MM-GG");
		}
		return new \DateTimeImmutable($v . ' 00:00:00', $tz);
	}

	private function photos(array $w): array
	{
		$tz = ServiceSettings::tz($w);
		$day = $this->localDay($tz, 'date', (new \DateTimeImmutable('now', $tz))->format('Y-m-d'));
		$every = $_GET['every'] ?? null;
		if ($every !== null && (!is_string($every) || !ctype_digit($every) || (int) $every < 1 || (int) $every > 1440)) {
			throw new ApiError(400, 'invalid_param', 'every: minuti 1-1440');
		}
		$order = ($_GET['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
		$sizes = $this->sizes();
		[$rc, $rp] = self::rangeSql($w, 'p.taken_at', $day->getTimestamp(), $day->modify('+1 day')->getTimestamp());
		$rows = Db::all(
			'SELECT ' . self::PHOTO_COLS . " FROM photos p WHERE p.webcam_id = ? AND p.deleted_at IS NULL AND p.is_public = 1 AND $rc
			  ORDER BY p.taken_at $order, p.id $order LIMIT 2000",
			array_merge([$w['id']], $rp)
		);
		$exp = Images::expiry();
		$out = [];
		$seen = [];
		foreach ($rows as $r) {
			if ($every !== null) {
				$b = intdiv((int) Db::unix($r['taken_at']) - $day->getTimestamp(), 60 * (int) $every);
				if (isset($seen[$b])) {
					continue;
				}
				$seen[$b] = true;
			}
			$out[] = $this->photoObj($r, $tz, $sizes, $exp);
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'date' => $day->format('Y-m-d'), 'timezone' => $tz->getName(), 'count' => count($out), 'photos' => $out];
	}

	/** @return list<array{date:string, photos:int, first:int, last:int}> */
	private function dayCounts(array $w, string $month): array
	{
		$tz = ServiceSettings::tz($w);
		$start = new \DateTimeImmutable($month . '-01 00:00:00', $tz);
		[$rc, $rp] = self::rangeSql($w, 'taken_at', $start->getTimestamp(), $start->modify('+1 month')->getTimestamp());
		$days = [];
		$st = Db::pdo()->prepare("SELECT UNIX_TIMESTAMP(taken_at) AS t FROM photos WHERE webcam_id = ? AND deleted_at IS NULL AND is_public = 1 AND $rc ORDER BY taken_at");
		$st->execute(array_merge([$w['id']], $rp));
		while (($t = $st->fetchColumn()) !== false) {
			$t = (int) $t;
			$d = (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
			if (!isset($days[$d])) {
				$days[$d] = ['date' => $d, 'photos' => 0, 'first' => $t, 'last' => $t];
			}
			$days[$d]['photos']++;
			$days[$d]['last'] = $t;
		}
		return array_values($days);
	}

	private function days(array $w): array
	{
		$tz = ServiceSettings::tz($w);
		$month = $_GET['month'] ?? (new \DateTimeImmutable('now', $tz))->format('Y-m');
		if (!is_string($month) || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) {
			throw new ApiError(400, 'invalid_param', 'month: mese AAAA-MM');
		}
		$days = $this->dayCounts($w, $month);
		return ['ok' => true, 'webcam' => $w['slug'], 'month' => $month, 'timezone' => $tz->getName(), 'days' => $days];
	}

	/** Istante da secondi Unix, AAAA-MM-GG (mezzanotte locale) o AAAA-MM-GGTHH:MM[:SS] (ora locale). */
	private function instant(\DateTimeZone $tz, string $param, bool $required, ?int $default = null): ?int
	{
		$v = $_GET[$param] ?? null;
		if ($v === null || $v === '') {
			if ($required) {
				throw new ApiError(400, 'invalid_param', "$param obbligatorio (secondi Unix, AAAA-MM-GG o AAAA-MM-GGTHH:MM)");
			}
			return $default;
		}
		if (is_string($v) && preg_match('/^\d{1,11}$/', $v)) {
			return (int) $v;
		}
		if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?$/', $v)) {
			$d = \DateTimeImmutable::createFromFormat(strlen($v) === 10 ? '!Y-m-d' : (strlen($v) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s'), $v, $tz);
			$err = \DateTimeImmutable::getLastErrors();
			if ($d !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
				return $d->getTimestamp();
			}
		}
		throw new ApiError(400, 'invalid_param', "$param: secondi Unix, AAAA-MM-GG o AAAA-MM-GGTHH:MM");
	}

	private function series(array $w): array
	{
		$tz = ServiceSettings::tz($w);
		$now = time();
		$from = (int) $this->instant($tz, 'from', false, $now - 48 * 3600);
		$to = (int) $this->instant($tz, 'to', false, $now);
		if (isset($_GET['to']) && is_string($_GET['to']) && strlen($_GET['to']) === 10) {
			$to = (new \DateTimeImmutable('@' . $to))->setTimezone($tz)->modify('+1 day')->getTimestamp(); // giorno compreso
		}
		if ($to <= $from) {
			throw new ApiError(400, 'invalid_param', 'to deve essere dopo from');
		}
		$maxDays = (int) Config::get()->value('site_api.series_max_days', 400);
		if ($to - $from > $maxDays * 86400) {
			throw new ApiError(400, 'invalid_param', "Intervallo massimo $maxDays giorni");
		}
		$fields = $_GET['fields'] ?? 'temperature_ext,humidity_ext';
		$fields = is_string($fields) ? array_values(array_unique(array_filter(array_map('trim', explode(',', $fields))))) : [];
		if ($fields === [] || array_diff($fields, array_keys(self::SERIES_FIELDS)) !== []) {
			throw new ApiError(400, 'invalid_param', 'fields: ' . implode(',', array_keys(self::SERIES_FIELDS)));
		}
		$cols = [];
		foreach ($fields as $f) {
			foreach (self::SERIES_FIELDS[$f] as $c) {
				if ($c !== null) {
					$cols[] = $c;
				}
			}
		}
		$fromH = $from - ($from % 3600);
		[$rc, $rp] = self::rangeSql($w, 'hour_ts', $fromH, $to);
		$rows = Db::all(
			'SELECT hour_ts, ' . implode(', ', $cols) . " FROM measurements_hourly WHERE webcam_id = ? AND samples > 0 AND $rc ORDER BY hour_ts",
			array_merge([$w['id']], $rp)
		);
		$num = static fn ($v): ?float => $v === null ? null : (float) $v;
		$points = [];
		foreach ($rows as $r) {
			$pt = ['time' => (int) Db::unix($r['hour_ts'])];
			$any = false;
			foreach ($fields as $f) {
				[$avg, $min, $max] = self::SERIES_FIELDS[$f];
				$pt[$f] = $num($r[$avg]);
				$any = $any || $pt[$f] !== null;
				if ($min !== null) {
					$pt[$f . '_min'] = $num($r[$min]);
					$pt[$f . '_max'] = $num($r[$max]);
				}
			}
			if ($any) {
				$points[] = $pt;
			}
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'from' => $from, 'to' => $to, 'timezone' => $tz->getName(), 'interval' => 3600, 'fields' => $fields, 'points' => $points];
	}

	private function compare(array $w): array
	{
		$tz = ServiceSettings::tz($w);
		$sizes = $this->sizes();
		$exp = Images::expiry();
		$out = ['ok' => true, 'webcam' => $w['slug'], 'timezone' => $tz->getName()];
		foreach (['a', 'b'] as $k) {
			$t = (int) $this->instant($tz, $k, true);
			$best = null;
			foreach (['<=' => 'DESC', '>' => 'ASC'] as $op => $ord) {
				[$rc, $rp] = self::rangeSql($w, 'p.taken_at');
				$p = Db::one(
					'SELECT ' . self::PHOTO_COLS . " FROM photos p WHERE p.webcam_id = ? AND p.deleted_at IS NULL AND p.is_public = 1 AND $rc
					   AND p.taken_at $op ? ORDER BY p.taken_at $ord LIMIT 1",
					array_merge([$w['id']], $rp, [Db::dt($t)])
				);
				if ($p !== null && ($best === null || abs((int) Db::unix($p['taken_at']) - $t) < abs((int) Db::unix($best['taken_at']) - $t))) {
					$best = $p;
				}
			}
			$out[$k] = [
				'requested' => $t,
				'photo' => $best === null ? null : $this->photoObj($best, $tz, $sizes, $exp),
				'delta_s' => $best === null ? null : (int) Db::unix($best['taken_at']) - $t,
			];
		}
		return $out;
	}

	// ------------------------------------------------------------ servizi 1.1

	private function dateParam(string $param, string $default): string
	{
		$v = $_GET[$param] ?? $default;
		if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
			throw new ApiError(400, 'invalid_param', "$param: data AAAA-MM-GG");
		}
		return $v;
	}

	private function intParam(string $param, int $default, int $min, int $max): int
	{
		$v = $_GET[$param] ?? null;
		if ($v === null || $v === '') {
			return $default;
		}
		if (!is_string($v) || !ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
			throw new ApiError(400, 'invalid_param', "$param: numero $min-$max");
		}
		return (int) $v;
	}

	private function monthParam(\DateTimeZone $tz): string
	{
		$month = $_GET['month'] ?? (new \DateTimeImmutable('now', $tz))->format('Y-m');
		if (!is_string($month) || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) {
			throw new ApiError(400, 'invalid_param', 'month: mese AAAA-MM');
		}
		return $month;
	}

	private function yearParam(\DateTimeZone $tz): string
	{
		$y = $_GET['year'] ?? (new \DateTimeImmutable('now', $tz))->format('Y');
		if (!is_string($y) || !preg_match('/^(19|20)\d{2}$/', $y)) {
			throw new ApiError(400, 'invalid_param', 'year: anno AAAA');
		}
		return $y;
	}

	/** Il giorno locale [00:00, 24:00) tocca un periodo visibile? */
	private static function dayVisible(array $w, string $from, string $to, \DateTimeZone $tz): bool
	{
		$a = (new \DateTimeImmutable($from . ' 00:00:00', $tz))->getTimestamp();
		$b = (new \DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
		foreach ($w['_ranges'] as [$ra, $rb]) {
			if ($b > $ra && ($rb === null || $a < $rb)) {
				return true;
			}
		}
		return false;
	}

	/** Foto visibile per id (con i periodi della chiave), o null. */
	private function visiblePhoto(array $w, int $photoId): ?array
	{
		[$rc, $rp] = self::rangeSql($w, 'p.taken_at');
		return Db::one(
			'SELECT ' . self::PHOTO_COLS . " FROM photos p WHERE p.id = ? AND p.webcam_id = ? AND p.deleted_at IS NULL AND p.is_public = 1 AND $rc",
			array_merge([$photoId, $w['id']], $rp)
		);
	}

	private function sameMoment(array $w): array
	{
		$this->need($w, 'same_moment');
		$tz = ServiceSettings::tz($w);
		$time = $_GET['time'] ?? '12:00';
		if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $hm)) {
			throw new ApiError(400, 'invalid_param', 'time: ora HH:MM');
		}
		$today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
		$to = $this->dateParam('to', $today);
		$from = $this->dateParam('from', (new \DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d'));
		if ($from > $to) {
			throw new ApiError(400, 'invalid_param', 'from deve essere prima di to');
		}
		$step = $_GET['step'] ?? 'day';
		if (!in_array($step, ['day', 'week', 'month', 'year'], true)) {
			throw new ApiError(400, 'invalid_param', 'step: day, week, month, year');
		}
		$tol = $this->intParam('tolerance', 30, 1, 180) * 60;
		$good = ($_GET['good'] ?? '0') === '1';
		$sizes = $this->sizes();

		// giorni dal più recente (to) indietro, poi in ordine dal più vecchio
		$dates = [];
		$t = new \DateTimeImmutable($to);
		[$ty, $tm, $td] = array_map('intval', explode('-', $to));
		for ($i = 0; ; $i++) {
			if ($step === 'day' || $step === 'week') {
				$d = $t->modify('-' . ($i * ($step === 'week' ? 7 : 1)) . ' days')->format('Y-m-d');
			} elseif ($step === 'month') {
				$y = $ty;
				$m = $tm - $i;
				while ($m < 1) {
					$m += 12;
					$y--;
				}
				$d = checkdate($m, $td, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $td) : null;
				if ($d === null) {
					if (sprintf('%04d-%02d-31', $y, $m) < $from) {
						break;
					}
					continue; // mese senza quel giorno: si salta
				}
			} else {
				$y = $ty - $i;
				$d = checkdate($tm, $td, $y) ? sprintf('%04d-%02d-%02d', $y, $tm, $td) : null;
				if ($d === null) {
					if (sprintf('%04d-12-31', $y) < $from) {
						break;
					}
					continue;
				}
			}
			if ($d < $from) {
				break;
			}
			$dates[] = $d;
			if (count($dates) > 400) {
				throw new ApiError(400, 'invalid_param', 'Al più 400 elementi: ridurre l\'intervallo o allargare step');
			}
		}
		$dates = array_reverse($dates);
		$exp = Images::expiry();
		$items = [];
		foreach ($dates as $d) {
			$req = (new \DateTimeImmutable("$d $time:00", $tz))->getTimestamp();
			[$rc, $rp] = self::rangeSql($w, 'p.taken_at', $req - $tol, $req + $tol + 1);
			$rows = Db::all(
				'SELECT ' . self::PHOTO_COLS . ", s.good AS _good FROM photos p LEFT JOIN photo_scores s ON s.photo_id = p.id
				  WHERE p.webcam_id = ? AND p.deleted_at IS NULL AND p.is_public = 1 AND $rc
				  ORDER BY ABS(TIMESTAMPDIFF(SECOND, ?, p.taken_at)), p.id LIMIT 200",
				array_merge([$w['id']], $rp, [Db::dt($req)])
			);
			$pick = $rows[0] ?? null;
			if ($good) {
				foreach ($rows as $r) {
					if ((int) $r['_good'] === 1) {
						$pick = $r;
						break;
					}
				}
			}
			$items[] = [
				'date' => $d,
				'requested' => $req,
				'photo' => $pick === null ? null : $this->photoObj($pick, $tz, $sizes, $exp),
				'delta_s' => $pick === null ? null : (int) Db::unix((string) $pick['taken_at']) - $req,
				'good' => $pick === null || $pick['_good'] === null ? ($pick === null ? null : false) : (int) $pick['_good'] === 1,
			];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'timezone' => $tz->getName(), 'time' => $time, 'step' => $step, 'count' => count($items), 'items' => $items];
	}

	private function timelapses(array $w): array
	{
		$this->need($w, 'timelapse');
		$tz = ServiceSettings::tz($w);
		$f = $this->features($w);
		$archived = isset($w['_archived']);
		$client = $this->scope === 'client';
		$kinds = ['day', 'week', 'month'];
		if ($client && ($archived || in_array('timelapse_custom', $f, true))) {
			$kinds[] = 'custom';
		}
		if ($client && ($archived || in_array('final_film', $f, true))) {
			$kinds[] = 'final';
		}
		$formats = ['landscape'];
		if ($archived || in_array('timelapse_portrait', $f, true)) {
			$formats[] = 'portrait';
		}
		$want = function (string $param, array $all, array $allowed): array {
			$v = $_GET[$param] ?? null;
			if ($v === null || $v === '') {
				return $allowed;
			}
			$list = is_string($v) ? array_values(array_unique(array_map('trim', explode(',', $v)))) : [];
			if ($list === [] || array_diff($list, $all) !== []) {
				throw new ApiError(400, 'invalid_param', "$param: " . implode(',', $all));
			}
			if (array_diff($list, $allowed) !== []) {
				throw new ApiError(403, 'feature_unavailable', "$param: " . implode(',', array_diff($list, $allowed)) . ' non compreso nel servizio o nello scope della chiave');
			}
			return $list;
		};
		$reqKinds = $want('kind', ['day', 'week', 'month', 'custom', 'final'], $kinds);
		$reqFormats = $want('format', ['landscape', 'portrait'], $formats);
		$from = isset($_GET['from']) && $_GET['from'] !== '' ? $this->dateParam('from', '') : null;
		$to = isset($_GET['to']) && $_GET['to'] !== '' ? $this->dateParam('to', '') : null;
		$limit = $this->intParam('limit', 50, 1, 200);

		$in = static fn (array $l): string => implode(',', array_fill(0, count($l), '?'));
		$sql = "SELECT * FROM timelapses WHERE webcam_id = ? AND status = 'done' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
		          AND kind IN (" . $in($kinds) . ') AND format IN (' . $in($formats) . ')';
		$p = array_merge([$w['id']], $kinds, $formats);
		if ($client) {
			$sql .= ' AND customer_id = ?';
			$p[] = $this->customer['id'];
		} else {
			$sql .= " AND kind IN ('day','week','month')";
			if ($w['customer_id'] !== null) {
				$sql .= ' AND customer_id = ?';
				$p[] = $w['customer_id'];
			}
		}
		$rows = Db::all($sql . ' ORDER BY period_to DESC, id DESC LIMIT 2000', $p);
		$latest = [];
		$out = [];
		$exp = Images::expiry();
		foreach ($rows as $t) {
			if (!self::dayVisible($w, (string) $t['period_from'], (string) $t['period_to'], $tz)) {
				continue;
			}
			$latest[$t['kind']][$t['format']] ??= (int) $t['id'];
			if (!in_array($t['kind'], $reqKinds, true) || !in_array($t['format'], $reqFormats, true)
				|| ($from !== null && (string) $t['period_to'] < $from) || ($to !== null && (string) $t['period_from'] > $to) || count($out) >= $limit) {
				continue;
			}
			$out[] = [
				'id' => (int) $t['id'], 'kind' => $t['kind'], 'period_from' => $t['period_from'], 'period_to' => $t['period_to'],
				'format' => $t['format'], 'width' => (int) $t['width'], 'height' => (int) $t['height'], 'fps' => (int) $t['fps'],
				'duration_s' => $t['duration_s'] === null ? null : (float) $t['duration_s'],
				'frames' => $t['frames_used'] === null ? null : (int) $t['frames_used'],
				'size_bytes' => $t['size_bytes'] === null ? null : (int) $t['size_bytes'],
				'overlay' => (int) $t['overlay'] === 1,
				'created' => Db::unix((string) ($t['finished_at'] ?? $t['created_at'])),
				'video_url' => Media::url($this->base, 'tl', (int) $t['id'], 'mp4', $exp),
				'poster_url' => $t['poster_path'] === null ? null : Media::url($this->base, 'tlp', (int) $t['id'], 'jpg', $exp),
			];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'timezone' => $tz->getName(), 'count' => count($out), 'latest' => $latest === [] ? (object) [] : $latest, 'timelapses' => $out];
	}

	private function podRow(array $w, string $day): ?array
	{
		$r = Db::one('SELECT * FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$w['id'], $day]);
		if ($r === null) {
			return null;
		}
		$r['_photo'] = $this->visiblePhoto($w, (int) $r['photo_id']);
		return $r['_photo'] === null ? null : $r;
	}

	private function photoOfDay(array $w): array
	{
		$this->need($w, 'photo_of_day');
		$tz = ServiceSettings::tz($w);
		$now = new \DateTimeImmutable('now', $tz);
		if (isset($_GET['date']) && $_GET['date'] !== '') {
			$day = $this->dateParam('date', '');
			$r = $this->podRow($w, $day);
		} else {
			$day = $now->format('Y-m-d');
			$r = $this->podRow($w, $day);
			if ($r === null) {
				$day = $now->modify('-1 day')->format('Y-m-d');
				$r = $this->podRow($w, $day);
			}
		}
		$photo = null;
		if ($r !== null) {
			$exp = Images::expiry();
			$photo = $this->photoObj($r['_photo'], $tz, $this->sizes(), $exp);
			$social = Images::urls($this->base, (int) $r['photo_id'], array_values(Images::SOCIAL_NAMES), $exp);
			$photo['social'] = [];
			foreach (Images::SOCIAL_NAMES as $name => $size) {
				$photo['social'][$name] = $social[$size];
			}
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'date' => $day, 'chosen_by' => $r['chosen_by'] ?? null,
			'score' => $r === null || $r['score'] === null ? null : (float) $r['score'], 'photo' => $photo];
	}

	private function photosOfDay(array $w): array
	{
		$this->need($w, 'photo_of_day');
		$tz = ServiceSettings::tz($w);
		$month = $this->monthParam($tz);
		$sizes = $this->sizes();
		$exp = Images::expiry();
		$days = [];
		foreach (Db::all('SELECT * FROM photo_of_day WHERE webcam_id = ? AND day BETWEEN ? AND ? ORDER BY day', [$w['id'], $month . '-01', $month . '-31']) as $r) {
			$p = $this->visiblePhoto($w, (int) $r['photo_id']);
			if ($p === null) {
				continue;
			}
			$days[] = ['date' => $r['day'], 'chosen_by' => $r['chosen_by'], 'score' => $r['score'] === null ? null : (float) $r['score'], 'photo' => $this->photoObj($p, $tz, $sizes, $exp)];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'month' => $month, 'days' => $days];
	}

	private function overlayItems(array $w): array
	{
		$this->need($w, 'sponsor');
		$slots = Overlay::slots((int) $w['id']);
		$ov = Overlay::active($w);
		$burnedIds = $ov === null ? [] : array_map(static fn ($i) => (int) $i['id'], Overlay::burnedSponsors($w));
		$mimes = [];
		foreach (Db::all('SELECT i.id, a.mime FROM overlay_items i JOIN overlay_assets a ON a.id = i.asset_id WHERE i.webcam_id = ? AND a.deleted_at IS NULL', [$w['id']]) as $r) {
			$mimes[(int) $r['id']] = (string) $r['mime'];
		}
		$sponsors = [];
		foreach (array_slice(Overlay::items($w, 'sponsor'), 0, $slots) as $i) {
			$sponsors[] = [
				'id' => (int) $i['id'], 'label' => $i['label'], 'text' => $i['text'], 'url' => $i['url'],
				'logo_url' => Media::logoUrl($this->base, (int) $i['id'], $mimes[(int) $i['id']] ?? null),
				'burned' => in_array((int) $i['id'], $burnedIds, true),
			];
		}
		$points = [];
		foreach (Overlay::items($w, 'infopoint') as $i) {
			$points[] = [
				'id' => (int) $i['id'], 'label' => $i['label'], 'text' => $i['text'], 'url' => $i['url'],
				'x_pct' => (float) $i['x_pct'], 'y_pct' => (float) $i['y_pct'],
				'icon_url' => Media::logoUrl($this->base, (int) $i['id'], $mimes[(int) $i['id']] ?? null),
				'burned' => $ov !== null && (int) $i['burn'] === 1,
			];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'slots' => $slots, 'sponsors' => $sponsors, 'infopoints' => $points];
	}

	private function stats(array $w): array
	{
		$this->need($w, 'stats', true);
		$tz = ServiceSettings::tz($w);
		$today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
		$to = $this->dateParam('to', $today);
		$from = $this->dateParam('from', (new \DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d'));
		if ($from > $to) {
			throw new ApiError(400, 'invalid_param', 'from deve essere prima di to');
		}
		if ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days >= 400) {
			throw new ApiError(400, 'invalid_param', 'Intervallo massimo 400 giorni');
		}
		$group = $_GET['group'] ?? 'day';
		if (!in_array($group, ['day', 'month'], true)) {
			throw new ApiError(400, 'invalid_param', 'group: day o month');
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'timezone' => $tz->getName(), 'from' => $from, 'to' => $to, 'group' => $group]
			+ Stats::report((int) $w['id'], $from, $to, $group);
	}

	private function reports(array $w): array
	{
		$this->need($w, 'monthly_report', true);
		$y = $this->yearParam(ServiceSettings::tz($w));
		$exp = Media::docExpiry();
		$out = [];
		foreach (Db::all(
			"SELECT * FROM monthly_reports WHERE webcam_id = ? AND customer_id = ? AND status IN ('ready','sent','emailed') AND month LIKE ? ORDER BY month DESC",
			[$w['id'], $this->customer['id'], $y . '-%']
		) as $r) {
			if (!self::dayVisible($w, (string) $r['period_from'], (string) $r['period_to'], ServiceSettings::tz($w))) {
				continue;
			}
			$d = json_decode((string) ($r['data'] ?? ''), true);
			$d = is_array($d) ? $d : [];
			$out[] = [
				'id' => (int) $r['id'], 'month' => $r['month'], 'period_from' => $r['period_from'], 'period_to' => $r['period_to'],
				'generated' => Db::unix($r['generated_at']), 'pages' => $r['pages'] === null ? null : (int) $r['pages'],
				'size_bytes' => $r['size_bytes'] === null ? null : (int) $r['size_bytes'], 'sha256' => $r['sha256'],
				'emailed' => Db::unix($r['emailed_at']),
				'summary' => ['availability_pct' => $d['availability_pct'] ?? null, 'photos' => $d['photos'] ?? null, 'bad_weather_days' => $d['bad_weather_days'] ?? null],
				'pdf_url' => Media::url($this->base, 'rep', (int) $r['id'], 'pdf', $exp),
			];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'reports' => $out];
	}

	private function weatherDays(array $w): array
	{
		$this->need($w, 'weather_cert', true);
		$tz = ServiceSettings::tz($w);
		$month = $this->monthParam($tz);
		$exp = Media::docExpiry();
		$days = [];
		$sum = ['days' => 0, 'bad_weather' => 0, 'not_certifiable' => 0];
		$hours = null;
		foreach (Db::all(
			'SELECT * FROM daily_register WHERE webcam_id = ? AND customer_id = ? AND sealed_at IS NOT NULL AND day BETWEEN ? AND ? ORDER BY day',
			[$w['id'], $this->customer['id'], $month . '-01', $month . '-31']
		) as $r) {
			$hours = (string) $r['work_hours'];
			$wd = \WebcamPortal\Service\Register::workHours((int) $w['id'], (string) $r['day'], $tz)['workday'];
			$sum['days']++;
			if ($r['classification'] === 'bad_weather') {
				$sum['bad_weather']++;
			} elseif ($r['classification'] === 'not_certifiable') {
				$sum['not_certifiable']++;
			}
			$num = static fn ($v): ?float => $v === null ? null : (float) $v;
			$days[] = [
				'date' => $r['day'], 'workday' => $wd, 'classification' => $r['classification'],
				'reasons' => $r['reasons'] === null || $r['reasons'] === '' ? [] : explode(',', (string) $r['reasons']),
				'wet_hours' => (int) $r['wet_hours'], 'frost_hours' => (int) $r['frost_hours'], 'fog_hours' => (int) $r['fog_hours'],
				't_ext_min' => $num($r['t_ext_min']), 't_ext_max' => $num($r['t_ext_max']), 'rh_ext_avg' => $num($r['rh_ext_avg']),
				'photos' => (int) $r['photos_total'], 'photos_unsynced' => (int) $r['photos_unsynced'], 'late_photos' => (int) $r['late_photos'],
				'day_hash' => $r['day_hash'], 'registered_at' => Db::unix($r['dolibarr_registered_at']),
				'manifest_url' => $r['manifest_path'] === null ? null : Media::url($this->base, 'reg', (int) $w['id'] . '-' . str_replace('-', '', (string) $r['day']), 'txt', $exp),
			];
		}
		if ($hours === null) {
			$hours = \WebcamPortal\Service\Register::workHours((int) $w['id'], $month . '-01', $tz)['hours'];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'month' => $month, 'timezone' => $tz->getName(), 'work_hours' => $hours, 'days' => $days, 'summary' => $sum];
	}

	private function certificates(array $w): array
	{
		$this->need($w, 'weather_cert', true);
		$y = $this->yearParam(ServiceSettings::tz($w));
		$exp = Media::docExpiry();
		$out = [];
		foreach (Db::all(
			"SELECT * FROM weather_certificates WHERE webcam_id = ? AND customer_id = ? AND status IN ('ready','sent','emailed') AND YEAR(period_from) = ? ORDER BY period_from DESC, id DESC",
			[$w['id'], $this->customer['id'], (int) $y]
		) as $c) {
			$out[] = [
				'id' => (int) $c['id'], 'kind' => $c['kind'], 'period_from' => $c['period_from'], 'period_to' => $c['period_to'],
				'days_bad' => $c['days_bad'] === null ? null : (int) $c['days_bad'],
				'days_not_certifiable' => $c['days_not_certifiable'] === null ? null : (int) $c['days_not_certifiable'],
				'generated' => Db::unix($c['generated_at']), 'sha256' => $c['sha256'],
				'size_bytes' => $c['size_bytes'] === null ? null : (int) $c['size_bytes'], 'emailed' => Db::unix($c['emailed_at']),
				'pdf_url' => Media::url($this->base, 'cert', (int) $c['id'], 'pdf', $exp),
			];
		}
		return ['ok' => true, 'webcam' => $w['slug'], 'certificates' => $out];
	}

	// ------------------------------------------------------------ beacon

	/** POST /views e GET /views.gif: sempre 204 (o il GIF), mai un errore al visitatore. */
	private function beacon(bool $gif): void
	{
		$m = $this->req->method;
		header('Access-Control-Allow-Origin: *');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		if ($m === 'OPTIONS') {
			header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type');
			http_response_code(204);
			return;
		}
		try {
			$in = [];
			if (!$gif && $m === 'POST') {
				$body = (string) file_get_contents('php://input', false, null, 0, 4096);
				parse_str($body, $in);
			} elseif ($gif && in_array($m, ['GET', 'HEAD'], true)) {
				$in = $_GET;
			}
			if ($in !== []) {
				Stats::beacon($in, $this->req->clientIp(), (string) $this->req->header('User-Agent'), $this->origin, $this->req->header('Referer'));
			}
		} catch (\Throwable $e) {
			Log::error('beacon: ' . $e->getMessage(), 'stats');
		}
		if ($gif) {
			http_response_code(200);
			header('Content-Type: image/gif');
			$g = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
			header('Content-Length: ' . strlen($g));
			if ($m !== 'HEAD') {
				echo $g;
			}
			return;
		}
		http_response_code(204);
	}
}
