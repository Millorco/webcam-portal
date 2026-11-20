<?php

declare(strict_types=1);

namespace WebcamPortal;

/**
 * Utilità per gli script in bin/ e tools/.
 */
final class Cli
{
	public static function only(): void
	{
		if (PHP_SAPI !== 'cli') {
			http_response_code(403);
			exit("Solo da riga di comando\n");
		}
	}

	/**
	 * --nome valore, --nome=valore, --flag
	 *
	 * @param list<string> $argv
	 * @return array<string,string|true>
	 */
	public static function args(array $argv): array
	{
		$out = [];
		$n = count($argv);
		for ($i = 1; $i < $n; $i++) {
			$a = $argv[$i];
			if (!str_starts_with($a, '--')) {
				$out['_'][] = $a;
				continue;
			}
			$a = substr($a, 2);
			if (str_contains($a, '=')) {
				[$k, $v] = explode('=', $a, 2);
				$out[$k] = $v;
			} elseif ($i + 1 < $n && !str_starts_with($argv[$i + 1], '--')) {
				$out[$a] = $argv[++$i];
			} else {
				$out[$a] = true;
			}
		}
		return $out;
	}

	/**
	 * Ora di riferimento da --now: Unix, oppure testo per strtotime (UTC).
	 * Senza: adesso.
	 */
	public static function time(mixed $v): int
	{
		if (!is_string($v) || $v === '') {
			return time();
		}
		if (ctype_digit($v)) {
			return (int) $v;
		}
		$t = strtotime($v . (preg_match('/[a-z]/i', $v) ? '' : ' UTC'));
		if ($t === false) {
			self::fail("Ora non valida: $v");
		}
		return $t;
	}

	/**
	 * Blocco esclusivo per gli script del cron (storage/cache/<nome>.lock):
	 * null se un'altra esecuzione è in corso. Va tenuto in una variabile
	 * finché lo script gira.
	 *
	 * @return resource|null
	 */
	public static function lock(string $name)
	{
		$dir = Config::get()->storageDir() . '/cache';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$fh = @fopen("$dir/$name.lock", 'c');
		if ($fh === false) {
			return STDIN; // senza cartella scrivibile si prosegue senza blocco
		}
		if (!flock($fh, LOCK_EX | LOCK_NB)) {
			fclose($fh);
			return null;
		}
		return $fh;
	}

	public static function out(string $msg): void
	{
		fwrite(STDOUT, $msg . PHP_EOL);
	}

	public static function fail(string $msg, int $code = 1): never
	{
		fwrite(STDERR, 'ERRORE: ' . $msg . PHP_EOL);
		exit($code);
	}

	/** Legge una riga da tastiera (senza eco se possibile, per le password). */
	public static function prompt(string $label, bool $hidden = false): string
	{
		fwrite(STDOUT, $label);
		$stty = $hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @shell_exec('stty -g 2>/dev/null');
		if ($stty) {
			@shell_exec('stty -echo 2>/dev/null');
		}
		$line = fgets(STDIN);
		if ($stty) {
			@shell_exec('stty echo 2>/dev/null');
			fwrite(STDOUT, PHP_EOL);
		}
		return trim((string) $line);
	}
}
