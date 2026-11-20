<?php

declare(strict_types=1);

namespace WebcamPortal\Dolibarr;

use WebcamPortal\Config;

/**
 * Endpoint di Dolibarr trovati assenti (modulo webcams più vecchio del
 * contratto), ricordati per qualche ora in storage/cache/dolibarr-missing.json:
 * bin/rules.php gira ogni 5 minuti e non deve riprovare né riempire il log.
 */
final class Missing
{
	private static function file(): string
	{
		return Config::get()->storageDir() . '/cache/dolibarr-missing.json';
	}

	private static function load(): array
	{
		$f = self::file();
		$d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
		return is_array($d) ? $d : [];
	}

	/** L'endpoint è stato trovato assente nelle ultime ore? */
	public static function has(string $key): bool
	{
		$d = self::load();
		return isset($d[$key]) && (int) $d[$key] > time();
	}

	/**
	 * Annota per $hours ore. true se è una notizia (da scrivere nel log).
	 */
	public static function note(string $key, int $hours): bool
	{
		$d = self::load();
		$new = !isset($d[$key]) || (int) $d[$key] <= time();
		if ($new) {
			$d[$key] = time() + $hours * 3600;
			$dir = dirname(self::file());
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}
			@file_put_contents(self::file(), json_encode($d), LOCK_EX);
		}
		return $new;
	}

	public static function clear(): void
	{
		@unlink(self::file());
	}
}
