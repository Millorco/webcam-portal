<?php
/**
 * Foto del giorno (M11, servizi-v1.md): all'ora photo_of_day.time
 * (predefinito 45 minuti dopo il tramonto) la foto buona con il punteggio
 * più alto del giorno, per le webcam con la funzione photo_of_day; avviso
 * Telegram alle chat iscritte. Da eseguire ogni 15 minuti.
 *
 *   php bin/photo-of-day.php [--webcam ID] [--now ORA]
 *   php bin/photo-of-day.php --scegli FOTO_ID --webcam ID   scelta a mano (operatore)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Service\PhotoOfDay;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
$wid = is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null;
$now = Cli::time($a['now'] ?? null);
try {
	if (is_string($a['scegli'] ?? null)) {
		$w = $wid === null ? null : Db::one('SELECT * FROM webcams WHERE id = ?', [$wid]);
		if ($w === null) {
			Cli::fail('--webcam obbligatorio');
		}
		$day = PhotoOfDay::choose($w, (int) $a['scegli'], 'operator', null, $now);
		Cli::out("foto del giorno del $day: foto {$a['scegli']}");
		exit(0);
	}
	$n = PhotoOfDay::run($now, $wid);
	if ($n > 0) {
		Log::info("$n foto del giorno scelte", 'photo-of-day');
	}
	exit(0);
} catch (InvalidArgumentException $e) {
	Cli::fail($e->getMessage());
} catch (Throwable $e) {
	Log::error('foto del giorno interrotta: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'photo-of-day');
	exit(1);
}
