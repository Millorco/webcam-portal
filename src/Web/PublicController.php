<?php

declare(strict_types=1);

namespace WebcamPortal\Web;

use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Http\Response;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Service\PhotoStore;
use WebcamPortal\Service\Stats;
use WebcamPortal\Site\Images;

/**
 * Pagine pubbliche: /cam/{slug}, /cam/{slug}/current.jpg, /cam/{slug}/current.json.
 * Solo webcam con is_public = 1 e status = 1, altrimenti 404.
 */
final class PublicController
{
	private static function webcam(string $slug): ?array
	{
		return Db::one('SELECT * FROM webcams WHERE slug = ? AND is_public = 1 AND status = 1', [$slug]);
	}

	public static function currentJpg(Request $req, string $slug): void
	{
		$w = self::webcam($slug);
		$cur = $w ? PhotoStore::current((int) $w['id']) : null;
		if ($w === null || $cur === null) {
			Response::notFound();
			return;
		}
		Stats::imageHit((int) $w['id']); // anche i 304 (api-portal-v1.md 1.2)
		$file = PhotoStore::currentFile((int) $w['id']);
		$etag = $cur['sha256'];
		$o = self::overlayed($w, $cur);
		if ($o !== null) {
			[$file, $rev] = $o;
			$etag .= '-o' . $rev;
		}
		self::sendJpeg($req, $file, $etag, $cur['taken_at'], 'public, max-age=60');
	}

	/**
	 * current.jpg con la sovrimpressione (contratto 1.3): riduzione a
	 * current_size con data, misure e logo, in cache come le altre. null =
	 * l'originale (sovrimpressione spenta, current_size orig o errore).
	 *
	 * @return array{0:string,1:int,2:?array}|null file, overlay_rev, foto
	 */
	private static function overlayed(array $w, array $cur, bool $build = true): ?array
	{
		$ov = Overlay::active($w);
		if (!Overlay::appliesTo($ov, 'current') || $ov['current_size'] === 'orig' || $cur['photo_id'] <= 0) {
			return null;
		}
		$p = Db::one('SELECT id, webcam_id, sha256, taken_at, time_synced, t_ext, rh_ext, width, height FROM photos WHERE id = ?', [$cur['photo_id']]);
		if ($p === null) {
			return null;
		}
		if (!$build) {
			return ['', (int) $ov['overlay_rev'], $p];
		}
		try {
			$file = Images::derived($p, PhotoStore::currentFile((int) $w['id']), (string) $ov['current_size'], $w, $ov);
			return [$file, (int) $ov['overlay_rev'], $p];
		} catch (\Throwable $e) {
			Log::warning("current.jpg di {$w['slug']} senza sovrimpressione: " . $e->getMessage(), 'site-api');
			return null;
		}
	}

	/** Invia un JPEG con ETag, Last-Modified e 304 (usato anche dal pannello). */
	public static function sendJpeg(Request $req, string $file, string $sha, int $takenAt, string $cache): void
	{
		$etag = '"' . $sha . '"';
		header('ETag: ' . $etag);
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $takenAt) . ' GMT');
		header('Cache-Control: ' . $cache);
		header('X-Content-Type-Options: nosniff');
		$inm = $req->header('If-None-Match');
		if ($inm !== null) {
			$tags = array_map('trim', explode(',', $inm));
			if (in_array($etag, $tags, true) || in_array('W/' . $etag, $tags, true) || in_array('*', $tags, true)) {
				http_response_code(304);
				return;
			}
		} else {
			$ims = $req->header('If-Modified-Since');
			if ($ims !== null && ($t = strtotime($ims)) !== false && $t >= $takenAt) {
				http_response_code(304);
				return;
			}
		}
		$size = @filesize($file);
		if ($size === false) {
			Response::notFound();
			return;
		}
		http_response_code(200);
		header('Content-Type: image/jpeg');
		header('Content-Length: ' . $size);
		if ($req->method !== 'HEAD') {
			readfile($file);
		}
	}

	public static function currentJson(Request $req, string $slug): void
	{
		$w = self::webcam($slug);
		$data = $w ? self::currentData($req, $w) : null;
		if ($data === null) {
			Response::json(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Webcam inesistente o non pubblica']);
			return;
		}
		http_response_code(200);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: public, max-age=60');
		header('Access-Control-Allow-Origin: *');
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
	}

	/** Dati pubblici dell'ultima foto (niente MAC, IP, token o dati tecnici). */
	public static function currentData(Request $req, array $w): ?array
	{
		$cur = PhotoStore::current((int) $w['id']);
		if ($cur === null) {
			return null;
		}
		$p = Db::one('SELECT mode, t_ext, rh_ext, width, height FROM photos WHERE id = ?', [$cur['photo_id']]) ?? [];
		$o = self::overlayed($w, $cur, false);
		$width = isset($p['width']) ? (int) $p['width'] : null;
		$height = isset($p['height']) ? (int) $p['height'] : null;
		if ($o !== null && $width && $height) {
			$scale = min(1.0, (int) Overlay::active($w)['current_size'] / max($width, $height));
			if ($scale < 1) {
				$width = max(1, (int) round($width * $scale));
				$height = max(1, (int) round($height * $scale));
			}
		}
		try {
			$tz = new \DateTimeZone((string) $w['timezone']);
		} catch (\Throwable $e) {
			$tz = new \DateTimeZone('UTC');
		}
		$dt = (new \DateTimeImmutable('@' . $cur['taken_at']))->setTimezone($tz);
		return [
			'slug' => $w['slug'],
			'name' => $w['name'],
			'time' => $cur['taken_at'],
			'datetime' => $dt->format('Y-m-d\TH:i:sO'),
			'mode' => $p['mode'] ?? null,
			'temperature_ext' => isset($p['t_ext']) ? (float) $p['t_ext'] : null,
			'humidity_ext' => isset($p['rh_ext']) ? (float) $p['rh_ext'] : null,
			'url' => $req->baseUrl() . '/cam/' . $w['slug'] . '/current.jpg',
			'sha256' => $cur['sha256'],
			'overlay' => $o !== null,
			'width' => $width ?: null,
			'height' => $height ?: null,
		];
	}

	public static function page(Request $req, string $slug): void
	{
		$w = self::webcam($slug);
		if ($w === null) {
			Response::notFound();
			return;
		}
		$data = self::currentData($req, $w);
		Response::html(200, View::render('public/cam', ['req' => $req, 'w' => $w, 'data' => $data, 'avail' => \WebcamPortal\Service\Availability::publicMonth($w)]));
	}
}
