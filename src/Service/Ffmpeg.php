<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;

/**
 * ffmpeg facoltativo (servizi-v1.md, "Senza ffmpeg"): percorso da
 * ffmpeg.path ('' = cerca "ffmpeg" nel PATH, 'off' = spento), prova con
 * "ffmpeg -version" al più una volta l'ora, esito in storage/state/ffmpeg.json.
 */
final class Ffmpeg
{
	public const MISSING_MESSAGE = 'Timelapse disattivati: ffmpeg non trovato. Installarlo e impostare ffmpeg.path in config.php';

	public static function stateFile(): string
	{
		return Config::get()->storageDir() . '/state/ffmpeg.json';
	}

	/** Comando da eseguire o null se spento. */
	public static function binary(): ?string
	{
		$p = trim((string) Config::get()->value('ffmpeg.path', ''));
		if (strtolower($p) === 'off') {
			return null;
		}
		return $p === '' ? 'ffmpeg' : $p;
	}

	/**
	 * @return array{ok:bool, reason:string, version:?string, path:?string, time:int}
	 */
	public static function status(bool $probe = false): array
	{
		$bin = self::binary();
		if ($bin === null) {
			return ['ok' => false, 'reason' => 'ffmpeg_missing', 'version' => null, 'path' => null, 'time' => time()];
		}
		$file = self::stateFile();
		$st = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
		if (!$probe && is_array($st) && ($st['path'] ?? null) === $bin && abs(time() - (int) ($st['time'] ?? 0)) < 3600) {
			return ['ok' => (bool) $st['ok'], 'reason' => (string) ($st['reason'] ?? ''), 'version' => $st['version'] ?? null, 'path' => $bin, 'time' => (int) $st['time']];
		}
		$version = self::probe($bin);
		$st = ['ok' => $version !== null, 'reason' => $version !== null ? '' : 'ffmpeg_missing', 'version' => $version, 'path' => $bin, 'time' => time()];
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@file_put_contents($file, json_encode($st, JSON_UNESCAPED_SLASHES), LOCK_EX);
		return $st;
	}

	/** "ffmpeg -version": prima riga, o null se non parte. */
	public static function probe(string $bin): ?string
	{
		if (!function_exists('proc_open')) {
			return null;
		}
		$p = @proc_open([$bin, '-hide_banner', '-version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($p)) {
			return null;
		}
		$out = (string) stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$rc = proc_close($p);
		if ($rc !== 0 || !str_contains($out, 'ffmpeg')) {
			return null;
		}
		return trim(strtok($out, "\n") ?: 'ffmpeg');
	}

	/**
	 * Esegue ffmpeg con un tempo massimo. Restituisce [codice, stderr].
	 *
	 * @param list<string> $args
	 * @return array{0:int, 1:string}
	 */
	public static function run(array $args, int $timeout): array
	{
		$bin = self::binary() ?? 'ffmpeg';
		$cmd = array_merge([$bin], $args);
		$nice = (int) Config::get()->value('ffmpeg.nice', 10);
		if (DIRECTORY_SEPARATOR === '/' && $nice > 0 && is_executable('/usr/bin/nice')) {
			$cmd = array_merge(['/usr/bin/nice', '-n', (string) min(19, $nice)], $cmd);
		}
		$p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($p)) {
			return [127, 'ffmpeg non avviato'];
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$err = '';
		$start = time();
		while (true) {
			$err .= (string) stream_get_contents($pipes[2]);
			stream_get_contents($pipes[1]);
			$s = proc_get_status($p);
			if (!$s['running']) {
				$err .= (string) stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($p);
				return [(int) $s['exitcode'], $err];
			}
			if (time() - $start > $timeout) {
				proc_terminate($p, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($p);
				return [124, 'timeout'];
			}
			usleep(100000);
		}
	}
}
