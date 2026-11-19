<?php
/**
 * Retention delle foto: dopo N giorni (retention_photos_days, predefinito 90)
 * cancella gli originali dal disco tenendo una foto l'ora per webcam. I record
 * restano in photos (deleted_at) con lo SHA-256. Pulisce anche la cache delle
 * foto ridotte per i siti (storage/cache/img): riduzioni delle foto cancellate
 * e quelle non usate da site_api.cache_days giorni. Da eseguire una volta al giorno.
 *
 *   php bin/retention-photos.php [--giorni 90] [--cache-giorni 30] [--prova]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Config;
use WebcamPortal\Log;
use WebcamPortal\Service\PhotoRetention;
use WebcamPortal\Site\Images;

Cli::only();
$a = Cli::args($argv);
$days = (int) (is_string($a['giorni'] ?? null) ? $a['giorni'] : Config::get()->value('retention_photos_days', 90));
if ($days < 1) {
	Cli::fail('--giorni deve essere almeno 1');
}
$dry = isset($a['prova']);
$s = PhotoRetention::run($days, $dry, [Cli::class, 'out']);
$msg = sprintf(
	'retention foto oltre %d giorni%s: %d cancellate (%.1f MB), %d tenute (una per ora), %d file già assenti',
	$days, $dry ? ' [prova]' : '', $s['deleted'], $s['bytes'] / 1048576, $s['kept'], $s['missing']
);
$cacheDays = (int) (is_string($a['cache-giorni'] ?? null) ? $a['cache-giorni'] : Config::get()->value('site_api.cache_days', 30));
if ($cacheDays >= 1) {
	$c = Images::prune($cacheDays, $dry);
	$msg .= sprintf('; cache delle riduzioni: %d file non usati da %d giorni (%.1f MB)', $c['files'], $cacheDays, $c['bytes'] / 1048576);
}
if (!$dry) {
	Log::info($msg, 'cron');
}
Cli::out($msg);
