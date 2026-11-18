<?php
/**
 * Medie orarie (measurements_hourly). Da eseguire ogni ora, pochi minuti dopo
 * l'ora piena. Le ore toccate dai lotti in arrivo si ricalcolano già alla
 * ricezione: questo giro chiude le ore e recupera i campioni in ritardo.
 *
 *   php bin/aggregate-hourly.php                 ultime 3 ore
 *   php bin/aggregate-hourly.php --ore 48        ultime 48 ore
 *   php bin/aggregate-hourly.php --dal 2026-11-01 [--al 2026-11-18]   intervallo (UTC)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Service\HourlyAggregator;

Cli::only();
$a = Cli::args($argv);
$to = time() + 3600;
if (is_string($a['dal'] ?? null)) {
	$from = strtotime($a['dal'] . ' UTC');
	if (is_string($a['al'] ?? null)) {
		$to = strtotime($a['al'] . ' UTC') + 86400;
	}
	if ($from === false || $to === false) {
		Cli::fail('date non valide (AAAA-MM-GG)');
	}
} else {
	$hours = max(1, (int) (is_string($a['ore'] ?? null) ? $a['ore'] : 3));
	$from = time() - $hours * 3600;
}
$n = HourlyAggregator::aggregate($from, $to);
$msg = sprintf('medie orarie dal %s al %s UTC: %d righe', gmdate('Y-m-d H:00', $from), gmdate('Y-m-d H:00', $to), $n);
Log::info($msg, 'cron');
Cli::out($msg);
