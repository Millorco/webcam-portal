<?php
/**
 * Invia a Dolibarr (modulo webcams, API v2) per ogni webcam trovata per MAC:
 * URL di current.jpg e dati Windy, contatori della reflex, sintesi Victron
 * ed eventi con gravità >= warning; dal modulo 1.3 legge le soglie del
 * contratto di noleggio (GET webcams/{id}/portal), manda la disponibilità
 * mensile e sincronizza i ticket degli avvisi. Da eseguire ogni ora.
 *
 *   php bin/sync-dolibarr.php [--mac aa:bb:cc:dd:ee:ff] [--prova]
 *
 * Se il modulo è ancora alla 1.1 (endpoint v2 assenti) scrive un avviso nel
 * log (storage/logs/sync-dolibarr.log) ed esce senza errori.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Dolibarr\Missing;
use WebcamPortal\Dolibarr\Sync;
use WebcamPortal\Dolibarr\TicketSync;
use WebcamPortal\Log;
use WebcamPortal\Service\Webcams;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$sync = Sync::fromConfig();
if ($sync === null) {
	Log::info('Dolibarr non configurato (dolibarr.url e dolibarr.api_key in config.php): niente da fare', 'sync-dolibarr');
	exit(0);
}
try {
	$mac = is_string($a['mac'] ?? null) ? Webcams::normalizeMac($a['mac']) : null;
	Missing::clear(); // una volta l'ora si riprovano gli endpoint mancanti (modulo aggiornato?)
	$rc = $sync->run($mac, isset($a['prova']));
	$tickets = isset($a['prova']) ? null : TicketSync::fromConfig();
	if ($tickets !== null && $rc === 0) {
		$rc = $tickets->run();
	}
	exit($rc);
} catch (Throwable $e) {
	Log::error('sincronizzazione interrotta: ' . get_class($e) . ' ' . $e->getMessage(), 'sync-dolibarr');
	exit(1);
}
