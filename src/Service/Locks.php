<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;

/**
 * Blocchi esclusivi dei lavori pesanti (servizi-v1.md, "Fuori dalle
 * richieste"): un file per tipo in storage/locks/, uno alla volta per server.
 */
final class Locks
{
	/** @var array<string,resource> */
	private static array $held = [];

	public static function acquire(string $name): bool
	{
		if (isset(self::$held[$name])) {
			return true;
		}
		$dir = Config::get()->storageDir() . '/locks';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$fh = @fopen($dir . '/' . preg_replace('/[^a-z0-9-]/', '', $name) . '.lock', 'c');
		if ($fh === false) {
			return true; // senza cartella scrivibile si prosegue senza blocco
		}
		if (!flock($fh, LOCK_EX | LOCK_NB)) {
			fclose($fh);
			return false;
		}
		self::$held[$name] = $fh;
		return true;
	}

	public static function release(string $name): void
	{
		if (isset(self::$held[$name])) {
			flock(self::$held[$name], LOCK_UN);
			fclose(self::$held[$name]);
			unset(self::$held[$name]);
		}
	}
}
