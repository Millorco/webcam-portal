<?php
/**
 * Rapporto mensile (O3) e certificato delle giornate di maltempo (O2) in
 * PDF: dal giorno reports.day alle reports.time (ora locale) crea quelli del
 * mese prima per ogni assegnazione, li genera, li allega in Dolibarr e chiede
 * l'email al cliente. Da eseguire ogni ora.
 *
 *   php bin/reports.php [--webcam ID] [--now ORA]
 *   php bin/reports.php --certificato --webcam ID --cliente ID --da AAAA-MM-GG --a AAAA-MM-GG
 *   php bin/reports.php --rigenera rapporto|certificato --id ID [--email]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Service\Reports;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$wid = is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null;
$rep = new Reports(Cli::time($a['now'] ?? null));
try {
	if (isset($a['certificato'])) {
		foreach (['webcam', 'cliente', 'da', 'a'] as $k) {
			if (!is_string($a[$k] ?? null)) {
				Cli::fail("--$k obbligatorio");
			}
		}
		$id = Reports::requestCertificate((int) $a['webcam'], (int) $a['cliente'], (string) $a['da'], (string) $a['a']);
		$rep->process($id, 'certificate');
		Cli::out("certificato $id: " . json_encode($rep->stats));
		exit($rep->stats['failed'] > 0 ? 1 : 0);
	}
	if (is_string($a['rigenera'] ?? null)) {
		if (!is_string($a['id'] ?? null)) {
			Cli::fail('--id obbligatorio');
		}
		$kind = $a['rigenera'] === 'certificato' ? 'certificate' : 'report';
		$rep->regenerate($kind, (int) $a['id'], isset($a['email']));
		Cli::out(($kind === 'report' ? 'rapporto ' : 'certificato ') . $a['id'] . ': ' . json_encode($rep->stats));
		exit($rep->stats['failed'] > 0 ? 1 : 0);
	}
	$lock = Cli::lock('reports');
	if ($lock === null) {
		Log::info('bin/reports.php già in esecuzione: salto questo giro', 'reports');
		exit(0);
	}
	$rep->schedule($wid);
	$rep->process();
	if (array_sum($rep->stats) > 0) {
		Log::info('rapporti: ' . json_encode($rep->stats), 'reports');
	}
	exit($rep->stats['failed'] > 0 ? 1 : 0);
} catch (InvalidArgumentException $e) {
	Cli::fail($e->getMessage());
} catch (Throwable $e) {
	Log::error('rapporti interrotti: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'reports');
	exit(1);
}
