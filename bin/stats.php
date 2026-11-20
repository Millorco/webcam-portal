<?php
/**
 * Statistiche di visualizzazione (M8): somma le richieste di current.jpg
 * dal file di appoggio, cancella impronte dei visitatori e segreti del
 * giorno dopo 48 ore e aggiorna la sovrimpressione quando uno sponsor entra
 * o esce dal suo periodo. Da eseguire ogni ora.
 *
 *   php bin/stats.php [--now ORA]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Service\Stats;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$now = Cli::time($a['now'] ?? null);
try {
	$hits = Stats::flushImageHits();
	Stats::cleanup($now);
	$ov = Overlay::refreshPeriods($now);
	Log::info("statistiche: $hits richieste di current.jpg sommate; sovrimpressioni aggiornate per i periodi degli sponsor: $ov", 'stats');
	exit(0);
} catch (Throwable $e) {
	Log::error('statistiche interrotte: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'stats');
	exit(1);
}
