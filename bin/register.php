<?php
/**
 * Registro delle impronte e giornate di maltempo (O2, servizi-v1.md
 * "Registro"): sigilla i giorni chiusi alle 01:30 locali del giorno dopo
 * (elenco vlreg1, day_hash a catena, classificazione meteo), registra le foto
 * arrivate in ritardo e deposita gli hash in Dolibarr. Da eseguire ogni ora.
 *
 *   php bin/register.php [--webcam ID] [--now ORA]
 *   php bin/register.php --verifica [--webcam ID]   ricontrolla gli elenchi con day_hash
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Log;
use WebcamPortal\Service\Register;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$wid = is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null;
if (isset($a['verifica']) || isset($a['verify'])) {
	$bad = Register::verify($wid);
	Cli::out($bad === [] ? 'registro: tutti gli elenchi corrispondono al day_hash' : 'registro: ' . count($bad) . ' giorni con problemi: ' . json_encode($bad));
	exit($bad === [] ? 0 : 1);
}
$lock = Cli::lock('register');
if ($lock === null) {
	Log::info('bin/register.php già in esecuzione: salto questo giro', 'register');
	exit(0);
}
try {
	$stats = (new Register(Cli::time($a['now'] ?? null)))->run($wid);
	Log::info('registro: ' . json_encode($stats), 'register');
	exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
	Log::error('registro interrotto: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'register');
	exit(1);
}
