<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Log;
use WebcamPortal\Service\PhotoStore;

/**
 * POST /api/v1/photo: JPEG + JSON photo/1 (o photo/0) in multipart.
 */
final class PhotoApi
{
	private const META_MAX = 65536;
	private const FILE_PATTERN = '/^[A-Za-z0-9._-]{1,200}\.jpg$/';

	/** @return array{0:int, 1:array<string,mixed>} */
	public static function handle(Request $req): array
	{
		$cfg = Config::get();
		$max = (int) $cfg->value('photo_max_bytes', 25 * 1024 * 1024);

		$auth = TokenAuth::authenticate($req, 'photo');
		$webcam = $auth['webcam'];
		$wid = (int) $webcam['id'];

		$ct = strtolower((string) $req->header('Content-Type'));
		if (!str_starts_with($ct, 'multipart/form-data')) {
			throw new ApiError(415, 'unsupported_media_type', 'Serve multipart/form-data con le parti photo e meta');
		}
		$len = $req->contentLength();
		if ($len > $max + self::META_MAX + 65536) {
			throw new ApiError(413, 'too_large', "Richiesta troppo grande (massimo $max byte per il JPEG)");
		}
		if ($len > 0 && $len > self::iniBytes((string) ini_get('post_max_size')) && empty($_FILES)) {
			throw new ApiError(413, 'too_large', 'Richiesta oltre post_max_size di PHP: alzare post_max_size e upload_max_filesize in php.ini');
		}

		// --- parte meta (file o campo di testo)
		$metaRaw = null;
		if (isset($_FILES['meta']) && is_array($_FILES['meta']) && ($_FILES['meta']['error'] ?? 1) === UPLOAD_ERR_OK) {
			if ((int) $_FILES['meta']['size'] > self::META_MAX) {
				throw new ApiError(413, 'meta_too_large', 'JSON della foto oltre 64 KB');
			}
			$metaRaw = (string) file_get_contents($_FILES['meta']['tmp_name']);
		} elseif (isset($_POST['meta']) && is_string($_POST['meta'])) {
			$metaRaw = $_POST['meta'];
		}
		if ($metaRaw === null || $metaRaw === '') {
			throw new ApiError(400, 'missing_part', 'Parte meta mancante');
		}
		if (strlen($metaRaw) > self::META_MAX) {
			throw new ApiError(413, 'meta_too_large', 'JSON della foto oltre 64 KB');
		}

		// --- parte photo
		$f = $_FILES['photo'] ?? null;
		if (!is_array($f) || is_array($f['error'] ?? null)) {
			throw new ApiError(400, 'missing_part', 'Parte photo mancante');
		}
		switch ((int) $f['error']) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				throw new ApiError(413, 'too_large', 'JPEG oltre upload_max_filesize di PHP');
			case UPLOAD_ERR_NO_FILE:
				throw new ApiError(400, 'missing_part', 'Parte photo mancante');
			case UPLOAD_ERR_PARTIAL:
				throw new ApiError(400, 'partial_upload', 'Upload del JPEG interrotto');
			default:
				throw new ApiError(500, 'upload_error', 'Errore del server nel ricevere il file (' . (int) $f['error'] . ')');
		}
		$tmp = (string) $f['tmp_name'];
		$size = (int) filesize($tmp);
		if ($size > $max) {
			throw new ApiError(413, 'too_large', "JPEG oltre il limite di $max byte");
		}
		$partType = strtolower(trim(explode(';', (string) ($f['type'] ?? ''))[0]));
		if ($partType !== '' && !in_array($partType, ['image/jpeg', 'image/jpg', 'image/pjpeg', 'application/octet-stream'], true)) {
			throw new ApiError(415, 'unsupported_media_type', "La parte photo deve essere image/jpeg (ricevuto $partType)");
		}
		$fh = fopen($tmp, 'rb');
		$magic = $fh ? (string) fread($fh, 3) : '';
		if ($fh) {
			fclose($fh);
		}
		if ($size < 4 || $magic !== "\xFF\xD8\xFF") {
			throw new ApiError(415, 'not_jpeg', 'Il file non è un JPEG (deve iniziare con FF D8 FF)');
		}

		// --- meta: photo/1 o photo/0
		$meta = json_decode($metaRaw, true, 32);
		if (!V::isObject($meta)) {
			throw new ApiError(400, 'invalid_json', 'JSON della foto non leggibile');
		}
		$schemaVer = self::schemaVersion($meta);
		try {
			$p = self::readMeta($meta, $schemaVer, (string) ($f['name'] ?? ''));
		} catch (MissingField $e) {
			throw new ApiError(400, 'missing_field', $e->getMessage(), [], ['field' => $e->field]);
		} catch (InvalidField $e) {
			throw new ApiError(422, 'invalid_field', $e->getMessage(), [], ['field' => $e->field]);
		}
		if ($p['mac'] !== null || $schemaVer === 1) {
			TokenAuth::checkMac($webcam, $p['mac']);
		}
		if (!TimeWindow::ok($p['time'])) {
			throw new ApiError(422, 'time_out_of_window', 'Ora della foto fuori dalla finestra accettata (da 400 giorni fa a 10 minuti nel futuro)');
		}

		$sha = (string) hash_file('sha256', $tmp);
		if ($schemaVer === 1 && $sha !== $p['sha256']) {
			throw new ApiError(422, 'sha256_mismatch', 'Lo SHA-256 del file ricevuto non corrisponde a meta.sha256');
		}
		if ($p['size'] !== null && $p['size'] !== $size) {
			throw new ApiError(422, 'size_mismatch', "La dimensione del file ($size byte) non corrisponde a meta.size");
		}

		// --- idempotenza
		$dup = Db::one('SELECT id, taken_at FROM photos WHERE webcam_id = ? AND sha256 = ?', [$wid, $sha]);
		if ($dup !== null) {
			return [200, self::reply((int) $dup['id'], true, $sha, $wid)];
		}

		// --- archivio
		$rel = PhotoStore::archivePath($wid, $p['time'], (string) $webcam['timezone'], $p['file'], $sha);
		$abs = PhotoStore::abs($rel);
		PhotoStore::ensureDir($abs);
		$created = !is_file($abs);
		if ($created && !(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $abs) : rename($tmp, $abs))) {
			throw new ApiError(500, 'storage_error', 'Impossibile salvare la foto');
		}

		$img = @getimagesize($abs) ?: [null, null];
		$exif = self::exif($abs);
		$c = $p['climate'];
		$pw = $p['power'];
		$ci = $p['camera_info'];
		$row = [
			'webcam_id' => $wid,
			'taken_at' => Db::dt($p['time']),
			'tz_offset_min' => $p['tz_offset_min'],
			'time_synced' => $p['time_synced'] ? 1 : 0,
			'received_at' => Db::dt(time()),
			'schema_ver' => $schemaVer,
			'sha256' => $sha,
			'size_bytes' => $size,
			'width' => $img[0] ?? null,
			'height' => $img[1] ?? null,
			'file_name' => $p['file'],
			'storage_path' => $rel,
			'mode' => $p['mode'],
			'special' => $p['special'],
			'iso' => self::cut($p['settings']['iso'] ?? null, 16),
			'aperture' => self::cut($p['settings']['aperture'] ?? null, 16),
			'shutterspeed' => self::cut($p['settings']['shutterspeed'] ?? null, 16),
			'exif_iso' => $exif['iso'],
			'exif_exposure' => $exif['exposure'],
			'exif_fnumber' => $exif['fnumber'],
			't_in' => $c['temperature'] ?? null,
			'rh_in' => $c['humidity'] ?? null,
			't_ext' => $c['temperature_ext'] ?? null,
			'rh_ext' => $c['humidity_ext'] ?? null,
			'battery_v' => $pw['battery_v'] ?? null,
			'camera_serial' => self::cut($ci['serial'] ?? null, 64),
			'shutter_count' => $ci['shutter_count'] ?? null,
			'app_version' => $p['app_version'],
			// arrivata più di 15 minuti dopo lo scatto: dalla coda offline
			'from_queue' => (time() - $p['time']) > 900 ? 1 : 0,
			'meta' => $metaRaw,
		];
		$cols = implode(', ', array_keys($row));
		$qs = implode(', ', array_fill(0, count($row), '?'));
		try {
			Db::exec("INSERT INTO photos ($cols) VALUES ($qs)", array_values($row));
		} catch (\PDOException $e) {
			if (($e->errorInfo[1] ?? 0) === 1062) { // inviata due volte in parallelo
				$id = (int) Db::scalar('SELECT id FROM photos WHERE webcam_id = ? AND sha256 = ?', [$wid, $sha]);
				return [200, self::reply($id, true, $sha, $wid)];
			}
			if ($created) {
				@unlink($abs);
			}
			throw $e;
		}
		$photoId = (int) Db::pdo()->lastInsertId();

		// --- webcam e fotocamera (app_version e board_fw li aggiorna la
		// telemetria, che ne genera gli eventi di cambio versione)
		Db::exec(
			'UPDATE webcams SET
			   last_photo_id = IF(last_photo_at IS NULL OR last_photo_at <= ?, ?, last_photo_id),
			   last_photo_at = IF(last_photo_at IS NULL OR last_photo_at <= ?, ?, last_photo_at),
			   hostname = COALESCE(?, hostname), app_version = COALESCE(app_version, ?),
			   board_fw = COALESCE(board_fw, ?), camera_model = COALESCE(?, camera_model),
			   interval_min = COALESCE(?, interval_min),
			   latitude = COALESCE(latitude, ?), longitude = COALESCE(longitude, ?)
			 WHERE id = ?',
			[
				$row['taken_at'], $photoId, $row['taken_at'], $row['taken_at'],
				self::cut($p['hostname'], 100), self::cut($p['app_version'], 30), self::cut($p['board_fw'], 30),
				self::cut($ci['model'] ?? null, 150), $p['interval'], $p['lat'], $p['lon'], $wid,
			]
		);
		if (!empty($ci['model']) && !empty($ci['serial'])) {
			Db::exec(
				'INSERT INTO cameras (model, serial, current_webcam_id, first_seen_at, last_seen_at)
				 VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
				 ON DUPLICATE KEY UPDATE current_webcam_id = VALUES(current_webcam_id), last_seen_at = UTC_TIMESTAMP()',
				[self::cut($ci['model'], 150), self::cut($ci['serial'], 64), $wid]
			);
		}

		$wantCurrent = trim((string) ($_POST['current'] ?? '1')) !== '0';
		$isCurrent = false;
		if ($wantCurrent) {
			$isCurrent = PhotoStore::updateCurrent($wid, $abs, $photoId, $sha, $p['time']);
		}
		Log::info("foto webcam=$wid id=$photoId file={$p['file']} size=$size current=" . ($isCurrent ? 1 : 0));

		return [201, ['ok' => true, 'photo_id' => $photoId, 'duplicate' => false, 'sha256' => $sha, 'current' => $isCurrent]];
	}

	/** @return array<string,mixed> */
	private static function reply(int $id, bool $dup, string $sha, int $wid): array
	{
		$cur = PhotoStore::current($wid);
		return ['ok' => true, 'photo_id' => $id, 'duplicate' => $dup, 'sha256' => $sha, 'current' => $cur !== null && $cur['photo_id'] === $id];
	}

	private static function schemaVersion(array $meta): int
	{
		if (!array_key_exists('schema', $meta)) {
			return 0;
		}
		if ($meta['schema'] === 'photo/1') {
			return 1;
		}
		$s = is_scalar($meta['schema']) ? (string) $meta['schema'] : '?';
		throw new ApiError(422, 'unsupported_schema', "Formato \"$s\" non supportato (accettati photo/0 e photo/1)");
	}

	/** @return array<string,mixed> */
	private static function readMeta(array $m, int $ver, string $uploadName): array
	{
		if ($ver === 1) {
			foreach (['schema', 'time', 'time_synced', 'mac', 'file', 'sha256', 'size', 'mode'] as $k) {
				V::req($m, $k, '');
			}
		} else {
			V::req($m, 'time', '');
		}
		$p = [];
		$p['time'] = V::int($m, 'time', '', 0);
		if ($p['time'] === null) {
			throw new MissingField('time');
		}
		$p['time_synced'] = (bool) V::bool($m, 'time_synced', '');
		$p['mac'] = V::str($m, 'mac', '', 17, '/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/');
		$p['sha256'] = V::str($m, 'sha256', '', 64, '/^[0-9a-f]{64}$/');
		$p['size'] = V::int($m, 'size', '', 1);
		$p['mode'] = V::enum($m, 'mode', '', ['day', 'night']);
		$file = $m['file'] ?? null;
		if ($ver === 0 && (!is_string($file) || !preg_match(self::FILE_PATTERN, $file))) {
			// photo/0: nome dal file caricato, ripulito
			$file = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(is_string($file) ? $file : $uploadName));
			if (!preg_match(self::FILE_PATTERN, (string) $file)) {
				$file = 'photo_' . gmdate('Ymd-His', $p['time']) . '.jpg';
			}
			$m['file'] = $file;
		}
		$p['file'] = (string) V::str($m, 'file', '', 204, self::FILE_PATTERN);
		$dt = V::str($m, 'datetime', '', 30, '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{4}$/');
		$p['tz_offset_min'] = null;
		if ($dt !== null) {
			$off = substr($dt, -5);
			$p['tz_offset_min'] = ($off[0] === '-' ? -1 : 1) * ((int) substr($off, 1, 2) * 60 + (int) substr($off, 3, 2));
		}
		$p['hostname'] = V::str($m, 'hostname', '', 100);
		V::str($m, 'camera', '', 100);
		$p['special'] = V::str($m, 'special', '', 64);
		$p['interval'] = V::int($m, 'interval', '', 1, 65535);
		$settings = V::obj($m, 'settings', '') ?? [];
		$p['settings'] = [];
		foreach (['iso', 'aperture', 'shutterspeed'] as $k) {
			$v = $settings[$k] ?? null;
			$p['settings'][$k] = is_scalar($v) ? (string) $v : null;
		}
		V::int($m, 'day_start', '');
		V::int($m, 'day_end', '');
		$p['lat'] = V::num($m, 'lat', '', -90, 90);
		$p['lon'] = V::num($m, 'lon', '', -180, 180);
		$p['app_version'] = V::str($m, 'app_version', '', 30);
		$p['board_fw'] = V::str($m, 'board_fw', '', 30);
		V::obj($m, 'capture', '');
		$ci = V::obj($m, 'camera_info', '');
		if ($ci !== null) {
			V::str($ci, 'model', 'camera_info.', 150);
			V::str($ci, 'serial', 'camera_info.', 64);
			V::int($ci, 'shutter_count', 'camera_info.', 0);
		}
		$p['camera_info'] = $ci ?? [];
		$p['climate'] = [];
		$climate = $m['climate'] ?? null;
		if (V::isObject($climate)) {
			$p['climate'] = TelemetryApi::readClimate($climate, 'climate.');
		} elseif ($climate !== null && $ver === 1) {
			throw new InvalidField('climate', 'oggetto atteso');
		}
		$p['power'] = [];
		$power = $m['power'] ?? null;
		if (V::isObject($power)) {
			$p['power'] = TelemetryApi::readPower($power, 'power.');
		} elseif ($power !== null) {
			throw new InvalidField('power', 'oggetto atteso');
		}
		return $p;
	}

	/** @return array{iso:?int, exposure:?string, fnumber:?float} */
	private static function exif(string $file): array
	{
		$out = ['iso' => null, 'exposure' => null, 'fnumber' => null];
		if (!function_exists('exif_read_data')) {
			return $out;
		}
		$e = @exif_read_data($file, 'EXIF');
		if (!is_array($e)) {
			return $out;
		}
		$iso = $e['ISOSpeedRatings'] ?? ($e['PhotographicSensitivity'] ?? null);
		if (is_array($iso)) {
			$iso = reset($iso);
		}
		if (is_numeric($iso) && (int) $iso >= 0 && (int) $iso <= 16777215) {
			$out['iso'] = (int) $iso;
		}
		if (isset($e['ExposureTime']) && is_string($e['ExposureTime'])) {
			$out['exposure'] = self::fraction($e['ExposureTime']);
		}
		if (isset($e['FNumber']) && is_string($e['FNumber']) && preg_match('#^(\d+)/(\d+)$#', $e['FNumber'], $mm) && (int) $mm[2] > 0) {
			$f = round((int) $mm[1] / (int) $mm[2], 1);
			$out['fnumber'] = $f < 1000 ? $f : null;
		}
		return $out;
	}

	/** "1/500", "10/1" -> "10", "5/10" -> "1/2". */
	private static function fraction(string $v): ?string
	{
		if (!preg_match('#^(\d+)/(\d+)$#', $v, $m) || (int) $m[2] === 0) {
			return substr($v, 0, 16);
		}
		$n = (int) $m[1];
		$d = (int) $m[2];
		if ($n >= $d) {
			$s = rtrim(rtrim(number_format($n / $d, 1, '.', ''), '0'), '.');
			return substr($s, 0, 16);
		}
		return substr('1/' . (string) round($d / max(1, $n)), 0, 16);
	}

	private static function cut(mixed $v, int $len): ?string
	{
		if ($v === null || !is_scalar($v)) {
			return null;
		}
		return mb_substr((string) $v, 0, $len);
	}

	public static function iniBytes(string $v): int
	{
		$v = trim($v);
		if ($v === '') {
			return PHP_INT_MAX;
		}
		$n = (int) $v;
		switch (strtolower(substr($v, -1))) {
			case 'g':
				$n *= 1024;
				// no break
			case 'm':
				$n *= 1024;
				// no break
			case 'k':
				$n *= 1024;
		}
		return $n <= 0 ? PHP_INT_MAX : $n;
	}
}
