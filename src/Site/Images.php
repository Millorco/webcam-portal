<?php

declare(strict_types=1);

namespace WebcamPortal\Site;

use WebcamPortal\Api\ApiError;
use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Overlay\Renderer;
use WebcamPortal\Photo\Analyzer;
use WebcamPortal\Photo\Metrics;
use WebcamPortal\Service\PhotoStore;

/**
 * Foto ridotte per i siti (api-siti-v1.md, "Immagini"):
 *
 *   /img/{photo_id}/{size}.jpg?e=<scadenza>&s=<firma>
 *
 * size: 320, 640, 1280, 1920 (lato lungo in px) oppure orig (solo per le
 * chiavi con scope client). La firma è HMAC-SHA256 di "img1|id|size|e" con il
 * segreto site_api.url_secret (o storage/keys/site-url.secret, creato da sé):
 * l'URL vale fino a "e" e non si può cambiare dimensione o foto.
 *
 * Le riduzioni si fanno al volo con GD, una alla volta (blocco su file), e
 * restano in storage/cache/img/<webcam>/<id/1000>/<id>-<size>.jpg; la
 * pulizia è in bin/retention-photos.php.
 */
final class Images
{
	public const SIZES = [320, 640, 1280, 1920];

	/** Dimensioni ammesse per lo scope (orig solo client). @return list<string> */
	public static function sizesFor(string $scope): array
	{
		$s = array_map('strval', self::SIZES);
		if ($scope === 'client') {
			$s[] = 'orig';
		}
		return $s;
	}

	public static function secret(): string
	{
		static $secret = null;
		if ($secret !== null) {
			return $secret;
		}
		$cfg = (string) Config::get()->value('site_api.url_secret', '');
		if ($cfg !== '') {
			return $secret = $cfg;
		}
		$dir = Config::get()->storageDir() . '/keys';
		$file = $dir . '/site-url.secret';
		$v = is_file($file) ? trim((string) @file_get_contents($file)) : '';
		if (strlen($v) < 32) {
			if (!is_dir($dir)) {
				@mkdir($dir, 0700, true);
			}
			$v = bin2hex(random_bytes(32));
			$tmp = $file . '.' . getmypid();
			if (@file_put_contents($tmp, $v) === false) {
				throw new \RuntimeException("Impossibile scrivere $file: impostare site_api.url_secret");
			}
			@chmod($tmp, 0600);
			if (is_file($file) && strlen(trim((string) @file_get_contents($file))) >= 32) {
				@unlink($tmp); // un'altra richiesta l'ha appena creato
				$v = trim((string) file_get_contents($file));
			} else {
				rename($tmp, $file);
			}
		}
		return $secret = $v;
	}

	public static function signature(int $photoId, string $size, int $exp): string
	{
		$mac = hash_hmac('sha256', "img1|$photoId|$size|$exp", self::secret(), true);
		return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 32);
	}

	/**
	 * Scadenza degli URL: almeno site_api.image_url_ttl secondi da adesso,
	 * arrotondata a site_api.image_url_step, così lo stesso URL resta uguale
	 * per un giorno e browser e proxy lo tengono in cache.
	 */
	public static function expiry(?int $now = null): int
	{
		$now ??= time();
		$ttl = max(60, (int) Config::get()->value('site_api.image_url_ttl', 604800));
		$step = max(1, (int) Config::get()->value('site_api.image_url_step', 86400));
		return (int) (ceil(($now + $ttl) / $step) * $step);
	}

	/** @param list<string> $sizes @return array<string,string> */
	public static function urls(string $baseUrl, int $photoId, array $sizes, ?int $exp = null): array
	{
		$exp ??= self::expiry();
		$out = [];
		foreach ($sizes as $s) {
			$out[$s] = $baseUrl . '/img/' . $photoId . '/' . $s . '.jpg?e=' . $exp . '&s=' . self::signature($photoId, $s, $exp);
		}
		return $out;
	}

	public static function cacheDir(): string
	{
		return Config::get()->storageDir() . '/cache/img';
	}

	public static function cacheFile(int $webcamId, int $photoId, string $size): string
	{
		return self::cacheDir() . '/' . $webcamId . '/' . intdiv($photoId, 1000) . '/' . $photoId . '-' . $size . '.jpg';
	}

	/** GET /img/{id}/{size}.jpg */
	public static function serve(Request $req, int $photoId, string $size): void
	{
		$exp = (string) ($_GET['e'] ?? '');
		$sig = (string) ($_GET['s'] ?? '');
		if (!ctype_digit($exp) || !hash_equals(self::signature($photoId, $size, (int) $exp), $sig)) {
			throw new ApiError(403, 'bad_signature', 'Firma dell\'URL non valida');
		}
		$now = time();
		if ((int) $exp <= $now) {
			throw new ApiError(403, 'url_expired', 'URL scaduto: chiedere di nuovo l\'elenco delle foto');
		}
		\WebcamPortal\Api\RateLimiter::hitBucket('img-' . sha1($req->clientIp()), (int) Config::get()->value('site_api.image_rate_limit_per_min', 1200));
		$p = Db::one(
			'SELECT p.id, p.webcam_id, p.sha256, p.taken_at, p.time_synced, p.t_ext, p.rh_ext, p.storage_path, p.width, p.height, w.is_public, w.status
			   FROM photos p JOIN webcams w ON w.id = p.webcam_id
			  WHERE p.id = ? AND p.deleted_at IS NULL AND p.is_public = 1',
			[$photoId]
		);
		$orig = $p === null ? '' : PhotoStore::abs((string) $p['storage_path']);
		if ($p === null || !is_file($orig)) {
			throw new ApiError(404, 'not_found', 'Foto inesistente');
		}
		$public = (int) $p['is_public'] === 1 && (int) $p['status'] === 1;
		$w = $size === 'orig' || $size === '320' ? null : Db::one('SELECT * FROM webcams WHERE id = ?', [$p['webcam_id']]);
		$ov = $w === null ? null : self::overlayFor($w, $size);
		$etag = '"' . substr((string) $p['sha256'], 0, 32) . '-' . $size . ($ov !== null ? '-o' . (int) $ov['overlay_rev'] : '') . '"';
		$cache = ($public ? 'public' : 'private') . ', max-age=' . max(60, min(31536000, (int) $exp - $now)) . ', immutable';
		$takenAt = (int) Db::unix((string) $p['taken_at']);
		header('ETag: ' . $etag);
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $takenAt) . ' GMT');
		header('Cache-Control: ' . $cache);
		header('X-Content-Type-Options: nosniff');
		header('Access-Control-Allow-Origin: *');
		header('Cross-Origin-Resource-Policy: cross-origin');
		$inm = $req->header('If-None-Match');
		if ($inm !== null && in_array($etag, array_map(static fn ($t) => trim(str_replace('W/', '', $t)), explode(',', $inm)), true)) {
			http_response_code(304);
			return;
		}
		$file = $size === 'orig' ? $orig : self::derived($p, $orig, $size, $w, $ov);
		$len = @filesize($file);
		if ($len === false) {
			throw new ApiError(404, 'not_found', 'Foto inesistente');
		}
		http_response_code(200);
		header('Content-Type: image/jpeg');
		header('Content-Length: ' . $len);
		if ($req->method !== 'HEAD') {
			readfile($file);
		}
	}

	/** Formati per i social (api-siti-v1.md 1.1): nome nell'URL => [larghezza, altezza]. */
	public const SOCIAL = ['sq1080' => [1080, 1080], 'p1350' => [1080, 1350], 's1920' => [1080, 1920]];
	public const SOCIAL_NAMES = ['square' => 'sq1080', 'portrait' => 'p1350', 'story' => 's1920'];

	/**
	 * Configurazione della sovrimpressione da applicare a questa dimensione
	 * ("640", "1280", "1920", formati social, "current", "timelapse"), o null.
	 */
	public static function overlayFor(array $w, string $size): ?array
	{
		$cfg = Overlay::active($w);
		$what = isset(self::SOCIAL[$size]) ? 'social' : $size;
		return Overlay::appliesTo($cfg, $what) ? $cfg : null;
	}

	/**
	 * File derivato di una foto: riduzione (320-1920) o formato social, con la
	 * sovrimpressione se $ov non è null. In cache accanto alle riduzioni
	 * senza sovrimpressione (<id>-<size>-o<overlay_rev>.jpg).
	 */
	public static function derived(array $p, string $orig, string $size, ?array $w = null, ?array $ov = null): string
	{
		$social = self::SOCIAL[$size] ?? null;
		if ($social === null && $ov === null) {
			return self::variant($p, $orig, (int) $size);
		}
		$name = $size . ($ov !== null ? '-o' . (int) $ov['overlay_rev'] : '');
		$file = self::cacheFile((int) $p['webcam_id'], (int) $p['id'], $name);
		if (is_file($file)) {
			if ((int) @filemtime($file) < time() - 86400) {
				@touch($file);
			}
			return $file;
		}
		$w ??= (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$p['webcam_id']]);
		if ($social !== null) {
			$tp = \WebcamPortal\Service\Timelapse::params((int) $p['webcam_id']);
			$im = self::cropped($orig, $social[0], $social[1], (float) $tp['crop_x_pct'], 50.0);
		} else {
			$base = self::variant($p, $orig, (int) $size);
			$im = self::open($base);
		}
		try {
			if ($ov !== null) {
				self::drawOverlay($im, $w, $p, $ov, $social === null);
			}
			self::saveJpeg($im, $file, (int) Config::get()->value('overlay.jpeg_quality', 85));
		} finally {
			imagedestroy($im);
		}
		return $file;
	}

	/** Disegna sovrimpressione, sponsor e (sull'immagine intera) infopoint. */
	public static function drawOverlay(\GdImage $im, array $w, array $p, array $cfg, bool $points = true): void
	{
		if (!array_key_exists('t_ext', $p) || !array_key_exists('time_synced', $p) || !isset($p['taken_at'])) {
			$p = array_merge($p, (array) Db::one('SELECT taken_at, time_synced, t_ext, rh_ext FROM photos WHERE id = ?', [$p['id']]));
		}
		$infop = array_values(array_filter(Overlay::items($w, 'infopoint'), static fn ($i) => (int) $i['burn'] === 1));
		Renderer::draw($im, $w, $p, $cfg, Overlay::burnedSponsors($w), $infop, $points);
	}

	/** Apre un JPEG controllando la memoria (503 image_too_large se non basta). */
	public static function open(string $file): \GdImage
	{
		Metrics::prepareMemory();
		try {
			return Analyzer::loadJpeg($file);
		} catch (\WebcamPortal\Photo\MemoryException $e) {
			Log::warning('immagine ' . basename($file) . ' saltata: ' . $e->getMessage() . '; alzare photo_metrics.memory_limit', 'site-api');
			throw new ApiError(503, 'image_too_large', 'Foto troppo grande per la memoria disponibile', ['Retry-After' => '300']);
		} catch (\RuntimeException $e) {
			throw new ApiError(404, 'not_found', 'Foto illeggibile');
		}
	}

	/**
	 * Ritaglio al formato $tw x $th: finestra alta (o larga) quanto l'immagine,
	 * centro a cx/cy (% 0-100), poi riduzione. Mai ingrandire oltre la finestra.
	 */
	public static function cropped(string $src, int $tw, int $th, float $cx = 50.0, float $cy = 50.0): \GdImage
	{
		$im = self::open($src);
		$w = imagesx($im);
		$h = imagesy($im);
		$ratio = $tw / $th;
		if ($w / $h > $ratio) {
			$ch = $h;
			$cw = (int) round($h * $ratio);
		} else {
			$cw = $w;
			$ch = (int) round($w / $ratio);
		}
		$x = (int) round(max(0, min($w - $cw, ($w - $cw) * max(0, min(100, $cx)) / 100)));
		$y = (int) round(max(0, min($h - $ch, ($h - $ch) * max(0, min(100, $cy)) / 100)));
		$out = imagecreatetruecolor($tw, $th);
		imagecopyresampled($out, $im, 0, 0, $x, $y, $tw, $th, $cw, $ch);
		imagedestroy($im);
		return $out;
	}

	public static function saveJpeg(\GdImage $im, string $dst, int $quality, bool $progressive = true): void
	{
		PhotoStore::ensureDir($dst);
		imageinterlace($im, $progressive);
		$tmp = $dst . '.' . getmypid() . '.tmp';
		if (!imagejpeg($im, $tmp, max(30, min(95, $quality))) || !@rename($tmp, $dst)) {
			@unlink($tmp);
			throw new \RuntimeException("Impossibile scrivere $dst");
		}
	}

	/**
	 * Restituisce il file della riduzione (dalla cache o creato ora). Se la
	 * foto è già più piccola, l'originale.
	 */
	public static function variant(array $p, string $orig, int $size): string
	{
		$w = (int) ($p['width'] ?? 0);
		$h = (int) ($p['height'] ?? 0);
		if ($w > 0 && $h > 0 && max($w, $h) <= $size) {
			return $orig;
		}
		$file = self::cacheFile((int) $p['webcam_id'], (int) $p['id'], (string) $size);
		if (is_file($file)) {
			$mt = (int) @filemtime($file);
			if ($mt < time() - 86400) {
				@touch($file); // la pulizia toglie le riduzioni non usate da N giorni
			}
			return $file;
		}
		// una riduzione alla volta: un JPEG da 24 MP decompresso occupa ~100 MB
		$lockDir = self::cacheDir();
		if (!is_dir($lockDir)) {
			@mkdir($lockDir, 0775, true);
		}
		$lock = @fopen($lockDir . '/.lock', 'c');
		if ($lock !== false) {
			flock($lock, LOCK_EX);
		}
		try {
			if (is_file($file)) {
				return $file;
			}
			$src = $orig;
			if ($size < 1920) {
				$big = self::cacheFile((int) $p['webcam_id'], (int) $p['id'], '1920');
				if (is_file($big)) {
					$src = $big;
				}
			}
			self::resize($src, $file, $size);
			return $file;
		} finally {
			if ($lock !== false) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/** Riduce $src a lato lungo $size e scrive $dst (tmp + rename). */
	public static function resize(string $src, string $dst, int $size): void
	{
		$info = @getimagesize($src);
		if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
			throw new ApiError(404, 'not_found', 'Foto illeggibile');
		}
		[$w, $h] = $info;
		$scale = $size / max($w, $h);
		if ($scale >= 1) {
			PhotoStore::ensureDir($dst);
			if (!@copy($src, $dst)) {
				throw new \RuntimeException("Impossibile scrivere $dst");
			}
			return; // mai ingrandire
		}
		$tw = max(1, (int) round($w * $scale));
		$th = max(1, (int) round($h * $scale));
		Metrics::prepareMemory();
		$limit = Analyzer::iniBytes((string) ini_get('memory_limit'));
		// GD: 4 byte per pixel più i buffer di libjpeg; margine del 25%
		$need = (int) (($w * $h * 4 + $tw * $th * 4) * 1.25) + 8 * 1048576;
		if ($limit > 0 && $limit - memory_get_usage() < $need) {
			Log::warning(sprintf('riduzione di %s (%dx%d) saltata: servono %d MB, memory_limit %s; alzare photo_metrics.memory_limit', basename($src), $w, $h, intdiv($need, 1048576), ini_get('memory_limit')), 'site-api');
			throw new ApiError(503, 'image_too_large', 'Foto troppo grande per la memoria disponibile', ['Retry-After' => '300']);
		}
		@set_time_limit(120);
		$im = @imagecreatefromjpeg($src);
		if ($im === false) {
			throw new ApiError(404, 'not_found', 'Foto illeggibile');
		}
		$out = imagecreatetruecolor($tw, $th);
		imagecopyresampled($out, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
		imagedestroy($im);
		imageinterlace($out, true);
		PhotoStore::ensureDir($dst);
		$tmp = $dst . '.' . getmypid() . '.tmp';
		$q = max(40, min(95, (int) Config::get()->value('site_api.jpeg_quality', 82)));
		$ok = imagejpeg($out, $tmp, $q);
		imagedestroy($out);
		if (!$ok || !@rename($tmp, $dst)) {
			@unlink($tmp);
			throw new \RuntimeException("Impossibile scrivere $dst");
		}
	}

	/** Toglie le riduzioni di una foto (retention). */
	public static function forget(int $webcamId, int $photoId): void
	{
		foreach (glob(dirname(self::cacheFile($webcamId, $photoId, 'x')) . '/' . $photoId . '-*.jpg') ?: [] as $f) {
			@unlink($f); // riduzioni, formati social e versioni con sovrimpressione
		}
	}

	/**
	 * Pulizia della cache: riduzioni non usate da $days giorni.
	 *
	 * @return array{files:int, bytes:int}
	 */
	public static function prune(int $days, bool $dryRun = false, ?int $now = null): array
	{
		$stats = ['files' => 0, 'bytes' => 0];
		$dir = self::cacheDir();
		if (!is_dir($dir)) {
			return $stats;
		}
		$cutoff = ($now ?? time()) - $days * 86400;
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			/** @var \SplFileInfo $f */
			if ($f->isDir()) {
				if (!$dryRun) {
					@rmdir($f->getPathname()); // solo se vuota
				}
				continue;
			}
			if (!str_ends_with($f->getFilename(), '.jpg') && !str_ends_with($f->getFilename(), '.tmp')) {
				continue;
			}
			if ($f->getMTime() < $cutoff) {
				$stats['files']++;
				$stats['bytes'] += (int) $f->getSize();
				if (!$dryRun) {
					@unlink($f->getPathname());
				}
			}
		}
		return $stats;
	}
}
