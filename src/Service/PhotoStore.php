<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;

/**
 * File delle foto in storage/photos/<webcam_id>/:
 *   AAAA/MM/GG/<file>   archivio (data dello scatto nel fuso della webcam)
 *   current.jpg         ultima foto, servita da /cam/{slug}/current.jpg
 *   current.json        photo_id, sha256 e ora di current.jpg (per ETag e Last-Modified)
 */
final class PhotoStore
{
	public static function root(): string
	{
		return Config::get()->storageDir();
	}

	public static function abs(string $relative): string
	{
		return self::root() . '/' . ltrim($relative, '/');
	}

	public static function webcamDir(int $webcamId): string
	{
		return self::root() . '/photos/' . $webcamId;
	}

	/** Percorso relativo a storage/ per una foto nuova. */
	public static function archivePath(int $webcamId, int $time, string $timezone, string $file, string $sha256): string
	{
		try {
			$tz = new \DateTimeZone($timezone);
		} catch (\Throwable $e) {
			$tz = new \DateTimeZone('UTC');
		}
		$d = (new \DateTimeImmutable('@' . $time))->setTimezone($tz);
		$rel = 'photos/' . $webcamId . '/' . $d->format('Y/m/d') . '/' . $file;
		if (is_file(self::abs($rel)) && hash_file('sha256', self::abs($rel)) !== $sha256) {
			// stesso nome, foto diversa (es. ora non sincronizzata dopo un riavvio)
			$rel = substr($rel, 0, -4) . '-' . substr($sha256, 0, 8) . '.jpg';
		}
		return $rel;
	}

	public static function ensureDir(string $absFile): void
	{
		$dir = dirname($absFile);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new \RuntimeException("Impossibile creare la cartella $dir");
		}
	}

	/**
	 * Sostituisce current.jpg con la foto indicata se è più recente di quella
	 * attuale (o se non c'è). Restituisce true se l'ha sostituita.
	 */
	public static function updateCurrent(int $webcamId, string $absSource, int $photoId, string $sha256, int $takenAt): bool
	{
		$dir = self::webcamDir($webcamId);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$lock = fopen($dir . '/current.lock', 'c');
		if ($lock === false) {
			return false;
		}
		try {
			flock($lock, LOCK_EX);
			$cur = self::current($webcamId);
			if ($cur !== null && $cur['taken_at'] > $takenAt) {
				return false;
			}
			$tmp = $dir . '/current.jpg.' . getmypid() . '.tmp';
			if (!copy($absSource, $tmp)) {
				return false;
			}
			if (!@rename($tmp, $dir . '/current.jpg')) {
				@unlink($dir . '/current.jpg');
				if (!rename($tmp, $dir . '/current.jpg')) {
					@unlink($tmp);
					return false;
				}
			}
			$meta = json_encode(['photo_id' => $photoId, 'sha256' => $sha256, 'taken_at' => $takenAt]);
			file_put_contents($dir . '/current.json', $meta, LOCK_EX);
			return true;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** @return array{photo_id:int, sha256:string, taken_at:int}|null */
	public static function current(int $webcamId): ?array
	{
		$dir = self::webcamDir($webcamId);
		if (!is_file($dir . '/current.jpg') || !is_file($dir . '/current.json')) {
			return null;
		}
		$m = json_decode((string) @file_get_contents($dir . '/current.json'), true);
		if (!is_array($m) || !isset($m['sha256'], $m['taken_at'])) {
			return null;
		}
		return ['photo_id' => (int) ($m['photo_id'] ?? 0), 'sha256' => (string) $m['sha256'], 'taken_at' => (int) $m['taken_at']];
	}

	public static function currentFile(int $webcamId): string
	{
		return self::webcamDir($webcamId) . '/current.jpg';
	}
}
