<?php
/**
 * Disponibilità giornaliera e mensile (contratto 1.1, diagnosi-v1.md
 * "Impegno di disponibilità"). Da eseguire ogni ora: ricalcola oggi e i 7
 * giorni prima (foto arrivate in ritardo dalla coda) e i mesi toccati; il
 * mese diventa definitivo il 3 del mese dopo. L'invio a Dolibarr
 * (POST webcams/{id}/availability) lo fa bin/sync-dolibarr.php.
 *
 *   php bin/availability.php [--webcam ID] [--dal AAAA-MM-GG --al AAAA-MM-GG] [--now ORA]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Service\Availability;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$now = Cli::time($a['now'] ?? null);
foreach (['dal', 'al'] as $k) {
	if (isset($a[$k]) && (!is_string($a[$k]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a[$k]))) {
		Cli::fail("--$k nel formato AAAA-MM-GG");
	}
}
try {
	$s = (new Availability($now))->run(
		is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null,
		is_string($a['dal'] ?? null) ? $a['dal'] : null,
		is_string($a['al'] ?? null) ? $a['al'] : null
	);
	Log::info('disponibilità: ' . json_encode($s), 'availability');
	exit(0);
} catch (Throwable $e) {
	Log::error('disponibilità interrotta: ' . get_class($e) . ' ' . $e->getMessage(), 'availability');
	exit(1);
}
