<?php
/**
 * Retention delle foto: dopo N giorni (retention_photos_days, predefinito 90)
 * cancella gli originali dal disco tenendo una foto l'ora per webcam. I record
 * restano in photos (deleted_at) con lo SHA-256. Da eseguire una volta al giorno.
 *
 *   php bin/retention-photos.php [--giorni 90] [--prova]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Config;
use WebcamPortal\Log;
use WebcamPortal\Service\PhotoRetention;

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
if (!$dry) {
	Log::info($msg, 'cron');
}
Cli::out($msg);
