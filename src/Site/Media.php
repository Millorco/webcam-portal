<?php

declare(strict_types=1);

namespace WebcamPortal\Site;

use WebcamPortal\Api\ApiError;
use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Overlay\Overlay;

/**
 * File dei servizi con URL firmato (api-siti-v1.md 1.1, "File dei servizi"):
 *
 *   GET /media/{tipo}/{id}.{ext}?e=<scadenza>&s=<firma>
 *
 * tl (mp4, con Range), tlp (poster jpg), logo (png/jpg), rep e cert (pdf),
 * reg (elenco del registro, txt). Firma: HMAC-SHA256("med1|tipo|id|e").
 */
final class Media
{
	public const ROUTE = '#^/media/(tl|tlp|logo|rep|cert|reg)/(\d{1,19}|\d{1,10}-\d{8})\.(mp4|jpg|png|pdf|txt)$#';
	private const DOCS = ['rep', 'cert', 'reg'];

	public static function signature(string $type, string $id, int $exp): string
	{
		$mac = hash_hmac('sha256', "med1|$type|$id|$exp", Images::secret(), true);
		return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 32);
	}

	/** Scadenza dei documenti: site_api.document_url_ttl (1 giorno), arrotondata all'ora. */
	public static function docExpiry(?int $now = null): int
	{
		$ttl = max(300, (int) Config::get()->value('site_api.document_url_ttl', 86400));
		return (int) (ceil((($now ?? time()) + $ttl) / 3600) * 3600);
	}

	public static function url(string $base, string $type, string|int $id, string $ext, ?int $exp = null): string
	{
		$exp ??= in_array($type, self::DOCS, true) ? self::docExpiry() : Images::expiry();
		$id = (string) $id;
		return $base . '/media/' . $type . '/' . $id . '.' . $ext . '?e=' . $exp . '&s=' . self::signature($type, $id, $exp);
	}

	/** URL del logo di un elemento di overlay_items (estensione dal tipo del file), o null. */
	public static function logoUrl(string $base, int $itemId, ?string $mime): ?string
	{
		return $mime === null ? null : self::url($base, 'logo', $itemId, $mime === 'image/png' ? 'png' : 'jpg');
	}

	public static function serve(Request $req, string $type, string $id, string $ext): void
	{
		$exp = (string) ($_GET['e'] ?? '');
		$sig = (string) ($_GET['s'] ?? '');
		if (!ctype_digit($exp) || !hash_equals(self::signature($type, $id, (int) $exp), $sig)) {
			throw new ApiError(403, 'bad_signature', 'Firma dell\'URL non valida');
		}
		$now = time();
		if ((int) $exp <= $now) {
			throw new ApiError(403, 'url_expired', 'URL scaduto: chiedere di nuovo l\'elenco');
		}
		\WebcamPortal\Api\RateLimiter::hitBucket('img-' . sha1($req->clientIp()), (int) Config::get()->value('site_api.image_rate_limit_per_min', 1200));
		[$file, $mime, $public, $name] = self::locate($type, $id, $ext, $now);
		if ($file === null || !is_file($file)) {
			throw new ApiError(404, 'not_found', 'File inesistente');
		}
		$size = (int) filesize($file);
		$mtime = (int) filemtime($file);
		$etag = '"' . substr(sha1($type . $id . $size . $mtime), 0, 24) . '"';
		header('X-Content-Type-Options: nosniff');
		header('Access-Control-Allow-Origin: *');
		header('Cross-Origin-Resource-Policy: cross-origin');
		if (in_array($type, self::DOCS, true)) {
			header('Cache-Control: private, no-store');
			header('Content-Disposition: attachment; filename="' . $name . '"');
		} else {
			header('Cache-Control: ' . ($public ? 'public' : 'private') . ', max-age=' . max(60, min(31536000, (int) $exp - $now)) . ', immutable');
			header('ETag: ' . $etag);
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
			$inm = $req->header('If-None-Match');
			if ($inm !== null && in_array($etag, array_map(static fn ($t) => trim(str_replace('W/', '', $t)), explode(',', $inm)), true)) {
				http_response_code(304);
				return;
			}
		}
		header('Content-Type: ' . $mime);
		$start = 0;
		$end = $size - 1;
		if ($type === 'tl') {
			header('Accept-Ranges: bytes');
			$range = $req->header('Range');
			if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
				if ($m[1] === '') {
					$start = max(0, $size - (int) $m[2]);
				} else {
					$start = (int) $m[1];
					$end = $m[2] === '' ? $size - 1 : min($size - 1, (int) $m[2]);
				}
				if ($start > $end || $start >= $size) {
					http_response_code(416);
					header('Content-Range: bytes */' . $size);
					return;
				}
				http_response_code(206);
				header("Content-Range: bytes $start-$end/$size");
			} else {
				http_response_code(200);
			}
		} else {
			http_response_code(200);
		}
		header('Content-Length: ' . ($end - $start + 1));
		if ($req->method === 'HEAD') {
			return;
		}
		$fh = fopen($file, 'rb');
		if ($fh === false) {
			return;
		}
		fseek($fh, $start);
		$left = $end - $start + 1;
		while ($left > 0 && !feof($fh)) {
			$chunk = fread($fh, (int) min(65536, $left));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			$left -= strlen($chunk);
		}
		fclose($fh);
	}

	/** @return array{0:?string,1:string,2:bool,3:string} file, tipo MIME, pubblico, nome per il download */
	private static function locate(string $type, string $id, string $ext, int $now): array
	{
		$st = Config::get()->storageDir() . '/';
		$none = [null, '', false, ''];
		if ($type === 'reg') {
			if ($ext !== 'txt' || !preg_match('/^(\d+)-(\d{4})(\d{2})(\d{2})$/', $id, $m)) {
				return $none;
			}
			$day = "$m[2]-$m[3]-$m[4]";
			$r = Db::one('SELECT r.manifest_path, w.slug FROM daily_register r JOIN webcams w ON w.id = r.webcam_id WHERE r.webcam_id = ? AND r.day = ? AND r.sealed_at IS NOT NULL', [(int) $m[1], $day]);
			return $r === null || $r['manifest_path'] === null ? $none : [$st . $r['manifest_path'], 'text/plain; charset=utf-8', false, "registro-{$r['slug']}-$day.txt"];
		}
		if (!ctype_digit($id)) {
			return $none;
		}
		$id = (int) $id;
		switch ($type) {
			case 'tl':
			case 'tlp':
				if ($ext !== ($type === 'tl' ? 'mp4' : 'jpg')) {
					return $none;
				}
				$t = Db::one(
					"SELECT t.storage_path, t.poster_path, t.expires_at, w.is_public, w.status FROM timelapses t JOIN webcams w ON w.id = t.webcam_id
					  WHERE t.id = ? AND t.status = 'done'",
					[$id]
				);
				if ($t === null || ($t['expires_at'] !== null && (int) Db::unix((string) $t['expires_at']) < $now)) {
					return $none;
				}
				$rel = $type === 'tl' ? $t['storage_path'] : $t['poster_path'];
				$public = (int) $t['is_public'] === 1 && (int) $t['status'] === 1;
				return $rel === null ? $none : [$st . $rel, $type === 'tl' ? 'video/mp4' : 'image/jpeg', $public, ''];
			case 'logo':
				$r = Db::one('SELECT i.asset_id, a.mime FROM overlay_items i JOIN overlay_assets a ON a.id = i.asset_id WHERE i.id = ? AND a.deleted_at IS NULL', [$id]);
				if ($r === null || $ext !== ($r['mime'] === 'image/png' ? 'png' : 'jpg')) {
					return $none;
				}
				return [Overlay::assetFile((int) $r['asset_id']), (string) $r['mime'], true, ''];
			case 'rep':
				$r = Db::one(
					"SELECT r.storage_path, r.month, w.slug FROM monthly_reports r JOIN webcams w ON w.id = r.webcam_id WHERE r.id = ? AND r.status IN ('ready','sent','emailed')",
					[$id]
				);
				return $r === null || $r['storage_path'] === null || $ext !== 'pdf' ? $none : [$st . $r['storage_path'], 'application/pdf', false, "rapporto-{$r['slug']}-{$r['month']}.pdf"];
			case 'cert':
				$r = Db::one(
					"SELECT c.storage_path, c.kind, c.period_from, c.period_to, w.slug FROM weather_certificates c JOIN webcams w ON w.id = c.webcam_id
					  WHERE c.id = ? AND c.status IN ('ready','sent','emailed')",
					[$id]
				);
				if ($r === null || $r['storage_path'] === null || $ext !== 'pdf') {
					return $none;
				}
				return [$st . $r['storage_path'], 'application/pdf', false, "maltempo-{$r['slug']}-" . self::period($r) . '.pdf'];
		}
		return $none;
	}

	/** Periodo nel nome del file: AAAA-MM per un mese intero, altrimenti AAAAMMGG-AAAAMMGG. */
	public static function period(array $r): string
	{
		$from = (string) $r['period_from'];
		$to = (string) $r['period_to'];
		if (($r['kind'] ?? '') === 'month' || (substr($from, 8) === '01' && $to === date('Y-m-t', strtotime($from)))) {
			return substr($from, 0, 7);
		}
		return str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
	}
}
