<?php
/**
 * Punteggio delle foto "buone" (O8, servizi-v1.md "Foto buone"): usa le
 * metriche della diagnosi o le calcola per una foto ogni scores.every_min
 * minuti. Risultato in photo_scores. Da eseguire ogni 5 minuti.
 *
 *   php bin/photo-scores.php [--webcam ID] [--now ORA]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Service\PhotoScores;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$lock = Cli::lock('photo-scores');
if ($lock === null) {
	Log::info('bin/photo-scores.php già in esecuzione: salto questo giro', 'photo-scores');
	exit(0);
}
try {
	$s = new PhotoScores(Cli::time($a['now'] ?? null));
	$stats = $s->run(is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null);
	Log::info('punteggi: ' . json_encode($stats), 'photo-scores');
	exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
	Log::error('punteggi interrotti: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'photo-scores');
	exit(1);
}
