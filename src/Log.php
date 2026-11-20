<?php

declare(strict_types=1);

namespace WebcamPortal;

/**
 * Log testuale in storage/logs/<canale>.log (una riga per messaggio).
 * Dagli script CLI scrive anche su stderr.
 */
final class Log
{
	public static bool $echo = false;

	public static function info(string $msg, string $channel = 'portal'): void
	{
		self::write('INFO', $msg, $channel);
	}

	public static function warning(string $msg, string $channel = 'portal'): void
	{
		self::write('AVVISO', $msg, $channel);
	}

	public static function error(string $msg, string $channel = 'portal'): void
	{
		self::write('ERRORE', $msg, $channel);
	}

	private static function write(string $level, string $msg, string $channel): void
	{
		$line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . $level . ' ' . str_replace(["\r", "\n"], ' ', $msg);
		try {
			$dir = Config::get()->storageDir() . '/logs';
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}
			@file_put_contents($dir . '/' . $channel . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
		} catch (\Throwable $e) {
			// il log non deve mai far fallire la richiesta
		}
		if (self::$echo) {
			fwrite(STDERR, $line . PHP_EOL);
		}
	}
}
