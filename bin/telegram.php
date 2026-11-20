<?php
/**
 * Bot Telegram (O10, servizi-v1.md "Bot Telegram"). Da eseguire ogni minuto:
 *
 *   php bin/telegram.php              coda dei messaggi, avviso gelo, pulizia (e --poll se telegram.mode = poll)
 *   php bin/telegram.php --poll       getUpdates per 55 secondi (XAMPP, senza URL pubblico)
 *   php bin/telegram.php --send       solo la coda dei messaggi
 *   php bin/telegram.php --set-webhook URL   imposta il webhook (VPS), es. https://portal.example.it/telegram/webhook
 *   php bin/telegram.php --codice --cliente ID [--webcam ID] [--etichetta T]   crea un codice di abbinamento
 *
 * Il token (telegram.bot_token o TELEGRAM_BOT_TOKEN) non va mai nel codice né nei log.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Config;
use WebcamPortal\Log;
use WebcamPortal\Telegram\Bot;
use WebcamPortal\Telegram\Notify;

Cli::only();
Log::$echo = true;
$a = Cli::args($argv);
if (!Bot::configured()) {
	Cli::out('Bot Telegram spento: impostare telegram.enabled e telegram.bot_token (o TELEGRAM_BOT_TOKEN) in config.php');
	exit(0);
}
$now = Cli::time($a['now'] ?? null);
try {
	if (is_string($a['set-webhook'] ?? null)) {
		$r = Bot::setWebhook($a['set-webhook']);
		Cli::out('setWebhook: ' . ($r['status'] === 200 ? 'ok' : 'errore ' . $r['status'] . ' ' . (string) ($r['body']['description'] ?? '')));
		exit($r['status'] === 200 ? 0 : 1);
	}
	if (isset($a['codice'])) {
		if (!is_string($a['cliente'] ?? null)) {
			Cli::fail('--cliente obbligatorio');
		}
		$c = Bot::createCode((int) $a['cliente'], is_string($a['webcam'] ?? null) ? (int) $a['webcam'] : null, is_string($a['etichetta'] ?? null) ? $a['etichetta'] : null);
		Cli::out("codice {$c['code']} (valido fino al {$c['expires_at']} UTC)");
		exit(0);
	}
	$poll = isset($a['poll']) || (!isset($a['send']) && Config::get()->value('telegram.mode', 'poll') === 'poll');
	if ($poll) {
		$lock = Cli::lock('telegram-poll');
		if ($lock !== null) {
			$n = Bot::poll(isset($a['secondi']) ? max(1, (int) $a['secondi']) : 55);
			if ($n > 0) {
				Log::info("$n aggiornamenti Telegram", 'telegram');
			}
		}
	}
	if (!isset($a['poll'])) {
		if (!isset($a['send'])) {
			Notify::scanFrost($now);
			Bot::cleanup($now);
		}
		$s = Bot::sendQueued(null, $now);
		if ($s['sent'] + $s['failed'] + $s['dropped'] > 0) {
			Log::info('coda Telegram: ' . json_encode($s), 'telegram');
		}
	}
	exit(0);
} catch (InvalidArgumentException $e) {
	Cli::fail($e->getMessage());
} catch (Throwable $e) {
	Log::error('bot Telegram: ' . get_class($e) . ' ' . $e->getMessage(), 'telegram');
	exit(1);
}
