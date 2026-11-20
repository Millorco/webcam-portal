<?php
/**
 * Motore delle regole di diagnosi (contratto 1.1, docs/contratto/diagnosi-v1.md).
 * Da eseguire ogni 5 minuti: valuta le regole per le webcam in servizio,
 * apre, aggrava e chiude gli avvisi, mette in coda i comandi al router,
 * legge VictoriaLogs al più ogni 15 minuti e, se Dolibarr è configurato,
 * apre/aggiorna/chiude i ticket.
 *
 *   php bin/rules.php [--webcam ID] [--regole camera.stalled,glass] [--logs] [--now "2026-11-18 15:00"] [--senza-dolibarr]
 *
 *   --webcam ID         solo questa webcam
 *   --regole LISTA      solo queste regole (id o gruppi data, photo, logs)
 *   --logs              interroga VictoriaLogs adesso (senza aspettare i 15 minuti)
 *   --now ORA           valuta come se fosse quest'ora (UTC; Unix o testo) - per prove e ricalcoli
 *   --senza-dolibarr    non sincronizzare i ticket
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Dolibarr\TicketSync;
use WebcamPortal\Log;
use WebcamPortal\Logs\VictoriaLogs;
use WebcamPortal\Rules\Engine;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$now = Cli::time($a['now'] ?? null);
$lock = Cli::lock('rules');
if ($lock === null) {
	Log::info('bin/rules.php già in esecuzione: salto questo giro', 'rules');
	exit(0);
}
try {
	VictoriaLogs::refresh($now, isset($a['logs']));
	$only = is_string($a['regole'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $a['regole'])))) : null;
	$engine = new Engine($now, $only, is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null);
	$stats = $engine->run();
	Log::info('regole: ' . json_encode($stats), 'rules');
	$rc = $stats['errors'] > 0 ? 1 : 0;
	if (!isset($a['senza-dolibarr'])) {
		$ts = TicketSync::fromConfig();
		if ($ts !== null) {
			$rc = max($rc, $ts->run($now));
		}
	}
	exit($rc);
} catch (Throwable $e) {
	Log::error('regole interrotte: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'rules');
	exit(1);
}
