<?php
/**
 * Manutenzione delle partizioni mensili di measurements: crea quelle dei
 * prossimi mesi e cancella quelle oltre la retention (predefinita 12 mesi,
 * retention_measurements_months). Da eseguire una volta al giorno o il 1° del
 * mese: se non c'è niente da fare non fa niente.
 *
 *   php bin/partitions.php              crea e cancella
 *   php bin/partitions.php --prova      mostra cosa cancellerebbe
 *   php bin/partitions.php --elenco     elenca le partizioni
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Config;
use WebcamPortal\Log;
use WebcamPortal\Service\Partitions;

Cli::only();
$a = Cli::args($argv);
if (isset($a['elenco'])) {
	foreach (Partitions::list() as $p) {
		Cli::out(sprintf('%-10s < %s', $p['name'], $p['less_than'] ?? 'MAXVALUE'));
	}
	exit(0);
}
$dry = isset($a['prova']);
$months = (int) Config::get()->value('retention_measurements_months', 12);
$created = $dry ? [] : Partitions::ensure(2);
$dropped = Partitions::dropExpired($months, $dry);
$msg = 'partizioni: create [' . implode(', ', $created) . '], ' . ($dry ? 'da cancellare' : 'cancellate') . ' [' . implode(', ', $dropped) . "] (retention $months mesi)";
if (!$dry) {
	Log::info($msg, 'cron');
}
Cli::out($msg);
