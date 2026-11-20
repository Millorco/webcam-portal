<?php
/**
 * Analisi delle foto con GD (contratto 1.1, diagnosi-v1.md "Analisi delle
 * foto"): nitidezza (varianza del laplaciano), luminosità, contrasto, dHash,
 * spostamento rispetto alla foto di riferimento della stessa fascia oraria.
 * Poi sceglie/rinnova i riferimenti e valuta le regole glass.fog,
 * glass.dirty e camera.moved. Da eseguire ogni 5 minuti, mai durante
 * l'upload delle foto.
 *
 *   php bin/photo-metrics.php [--webcam ID] [--now ORA] [--senza-regole]
 *
 * memory_limit: photo_metrics.memory_limit in config.php (512M); una foto
 * che non ci sta si salta (skip_reason = memory) e si scrive nel log.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Config;
use WebcamPortal\Log;
use WebcamPortal\Photo\Metrics;
use WebcamPortal\Rules\Engine;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
if (!Config::get()->value('photo_metrics.enabled', true)) {
	Log::info('analisi delle foto spenta (photo_metrics.enabled)', 'photo-metrics');
	exit(0);
}
if (!function_exists('imagecreatefromjpeg')) {
	Log::error('serve l\'estensione GD di PHP con il supporto JPEG', 'photo-metrics');
	exit(1);
}
$lock = Cli::lock('photo-metrics');
if ($lock === null) {
	Log::info('bin/photo-metrics.php già in esecuzione: salto questo giro', 'photo-metrics');
	exit(0);
}
$now = Cli::time($a['now'] ?? null);
$wid = is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null;
try {
	Metrics::prepareMemory();
	$m = new Metrics($now);
	$stats = $m->run($wid);
	Log::info('foto: ' . json_encode($stats) . ' memoria massima ' . round(memory_get_peak_usage(true) / 1048576) . ' MB', 'photo-metrics');
	$rc = $stats['errors'] > 0 ? 1 : 0;
	if (!isset($a['senza-regole']) && $stats['analyzed'] > 0) {
		$r = (new Engine($now, ['photo'], $wid))->run();
		Log::info('regole delle foto: ' . json_encode($r), 'rules');
		$rc = max($rc, $r['errors'] > 0 ? 1 : 0);
	}
	exit($rc);
} catch (Throwable $e) {
	Log::error('analisi interrotta: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'photo-metrics');
	exit(1);
}
