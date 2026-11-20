<?php
/**
 * Timelapse (M7, M10, servizi-v1.md "Timelapse"): crea i lavori automatici
 * (giorno alle 01:00 locali, settimana il lunedì, mese il 2, filmato finale
 * a fine noleggio) e ne esegue uno dalla coda con ffmpeg. Senza ffmpeg i
 * timelapse sono spenti e i lavori in coda finiscono in failed
 * (ffmpeg_missing). Da eseguire ogni 10 minuti.
 *
 *   php bin/timelapse.php [--now ORA] [--tutti]
 *   php bin/timelapse.php --richiedi --webcam ID --tipo day|week|month|custom --da AAAA-MM-GG --a AAAA-MM-GG [--formato portrait]
 *   php bin/timelapse.php --stato        dice se ffmpeg c'è
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Service\Ffmpeg;
use WebcamPortal\Service\Timelapse;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
if (isset($a['stato'])) {
	$s = Ffmpeg::status(true);
	Cli::out($s['ok'] ? 'ffmpeg: ' . $s['path'] . ' (' . $s['version'] . ')' : Ffmpeg::MISSING_MESSAGE);
	exit($s['ok'] ? 0 : 1);
}
$now = Cli::time($a['now'] ?? null);
try {
	if (isset($a['richiedi'])) {
		foreach (['webcam', 'tipo', 'da', 'a'] as $k) {
			if (!is_string($a[$k] ?? null)) {
				Cli::fail("--$k obbligatorio");
			}
		}
		$id = Timelapse::request((int) $a['webcam'], (string) $a['tipo'], (string) $a['da'], (string) $a['a'], is_string($a['formato'] ?? null) ? $a['formato'] : 'landscape',
			['priority' => 1, 'requested_by' => 'operator', 'requeue' => true]);
		Cli::out("timelapse $id in coda");
		if (!Timelapse::available()) {
			Cli::out(Ffmpeg::MISSING_MESSAGE);
		}
		exit(0);
	}
	if (!Timelapse::available()) {
		Timelapse::runNext($now); // mette in failed (ffmpeg_missing) i lavori in coda
		Log::info(Ffmpeg::MISSING_MESSAGE, 'timelapse');
		exit(0);
	}
	$n = Timelapse::autoCreate($now);
	if ($n > 0) {
		Log::info("$n timelapse automatici in coda", 'timelapse');
	}
	do {
		$id = Timelapse::runNext($now);
	} while ($id !== null && isset($a['tutti']));
	exit(0);
} catch (InvalidArgumentException $e) {
	Cli::fail($e->getMessage());
} catch (Throwable $e) {
	Log::error('timelapse interrotto: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'timelapse');
	exit(1);
}
