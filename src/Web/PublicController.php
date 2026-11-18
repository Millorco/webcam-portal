<?php

declare(strict_types=1);

namespace WebcamPortal\Web;

use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Http\Response;
use WebcamPortal\Service\PhotoStore;

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
		self::sendJpeg($req, PhotoStore::currentFile((int) $w['id']), $cur['sha256'], $cur['taken_at'], 'public, max-age=60');
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
		$p = Db::one('SELECT mode, t_ext, rh_ext FROM photos WHERE id = ?', [$cur['photo_id']]) ?? [];
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
		Response::html(200, View::render('public/cam', ['req' => $req, 'w' => $w, 'data' => $data]));
	}
}
