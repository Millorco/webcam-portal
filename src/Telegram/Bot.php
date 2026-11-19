<?php

declare(strict_types=1);

namespace WebcamPortal\Telegram;

use WebcamPortal\Api\ApiError;
use WebcamPortal\Api\RateLimiter;
use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Renderer;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\PhotoStore;
use WebcamPortal\Service\ServiceSettings;
use WebcamPortal\Service\Stats;
use WebcamPortal\Site\Images;

/**
 * Bot Telegram del portal (O10, servizi-v1.md "Bot Telegram"): comandi,
 * abbinamento con codice monouso, coda dei messaggi in uscita. Il bot non
 * manda mai comandi al router: /foto è l'ultima foto ricevuta.
 */
final class Bot
{
	private const LOG = 'telegram';
	/** Crockford base32 senza I, L, O, U. */
	public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	public const ALERTS = ['offline', 'online', 'photo_of_day', 'frost', 'bad_weather', 'timelapse', 'report'];
	public const ALERT_LABELS = [
		'offline' => 'webcam ferma', 'online' => 'webcam di nuovo attiva', 'photo_of_day' => 'foto del giorno', 'frost' => 'gelo',
		'bad_weather' => 'giornata di maltempo', 'timelapse' => 'timelapse pronti', 'report' => 'rapporto mensile',
	];
	private const NOT_LINKED = 'Questa chat non è collegata: chiedi un codice a Viewlapse e scrivi /iscrivi CODICE';
	private const HELP = "Comandi:\n/foto [webcam] - ultima foto\n/meteo [webcam] - temperatura e umidità\n/webcam - le tue webcam\n/avvisi - scegli gli avvisi\n/stop - scollega questa chat\n/aiuto - questo elenco";

	public static function configured(): bool
	{
		return (bool) Config::get()->value('telegram.enabled', false) && trim((string) Config::get()->value('telegram.bot_token', '')) !== '';
	}

	// ------------------------------------------------------------ codici di abbinamento

	public static function normalize(string $code): string
	{
		return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
	}

	/**
	 * Nuovo codice per un cliente (tutte le webcam o una): restituisce il
	 * codice da mostrare una volta (K7QM2-X9TRB); nel database solo lo SHA-256.
	 *
	 * @return array{id:int, code:string, expires_at:string}
	 */
	public static function createCode(int $customerId, ?int $webcamId, ?string $label, ?int $userId = null): array
	{
		if (Db::scalar('SELECT id FROM customers WHERE id = ?', [$customerId]) === null) {
			throw new \InvalidArgumentException('Cliente inesistente');
		}
		if ($webcamId !== null && (int) Db::scalar('SELECT customer_id FROM webcams WHERE id = ?', [$webcamId]) !== $customerId) {
			throw new \InvalidArgumentException('La webcam non è del cliente');
		}
		$raw = '';
		for ($i = 0; $i < 10; $i++) {
			$raw .= self::ALPHABET[random_int(0, 31)];
		}
		$days = max(1, (int) Config::get()->value('telegram.code_ttl_days', 7));
		$exp = Db::dt(time() + $days * 86400);
		Db::exec(
			'INSERT INTO telegram_pair_codes (customer_id, webcam_id, code_hash, label, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
			[$customerId, $webcamId, hash('sha256', $raw), $label === null || trim($label) === '' ? null : mb_substr(trim($label), 0, 100), $userId, $exp]
		);
		return ['id' => (int) Db::pdo()->lastInsertId(), 'code' => substr($raw, 0, 5) . '-' . substr($raw, 5), 'expires_at' => $exp];
	}

	// ------------------------------------------------------------ aggiornamenti

	/** Un aggiornamento di Telegram (webhook o getUpdates). */
	public static function handle(array $u): void
	{
		try {
			if (isset($u['callback_query']) && is_array($u['callback_query'])) {
				self::callback($u['callback_query']);
				return;
			}
			$m = $u['message'] ?? ($u['channel_post'] ?? null);
			if (!is_array($m) || !isset($m['chat']['id']) || !is_string($m['text'] ?? null)) {
				return;
			}
			self::command($m);
		} catch (\Throwable $e) {
			Log::error('aggiornamento Telegram non gestito: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), self::LOG);
		}
	}

	/** Chat abbinata attiva (l'ultima, se la stessa chat ha più clienti). */
	public static function chat(int $chatId): ?array
	{
		return Db::one("SELECT * FROM telegram_chats WHERE chat_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$chatId]);
	}

	/** @return list<array<string,mixed>> chat attive di questo chat_id */
	private static function chats(int $chatId): array
	{
		return Db::all("SELECT * FROM telegram_chats WHERE chat_id = ? AND status = 'active' ORDER BY id", [$chatId]);
	}

	/**
	 * Webcam che la chat vede: quelle attuali del cliente (o quella del
	 * codice), con la funzione telegram.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function webcamsOf(array $chat): array
	{
		$sql = 'SELECT * FROM webcams WHERE customer_id = ? AND status <> 9';
		$p = [$chat['customer_id']];
		if ($chat['webcam_id'] !== null) {
			$sql .= ' AND id = ?';
			$p[] = $chat['webcam_id'];
		}
		$out = [];
		foreach (Db::all($sql . ' ORDER BY name, slug', $p) as $w) {
			if (Features::has($w, 'telegram')) {
				$out[] = $w;
			}
		}
		return $out;
	}

	/** @return list<array<string,mixed>> webcam di tutte le chat attive di questo chat_id */
	private static function allWebcams(int $chatId): array
	{
		$out = [];
		foreach (self::chats($chatId) as $c) {
			foreach (self::webcamsOf($c) as $w) {
				$w['_chat_ref'] = (int) $c['id'];
				$out[(int) $w['id']] = $w;
			}
		}
		return array_values($out);
	}

	private static function command(array $m): void
	{
		$chatId = (int) $m['chat']['id'];
		$text = trim((string) $m['text']);
		if ($text === '' || $text[0] !== '/') {
			if (self::chat($chatId) === null) {
				self::reply($chatId, self::NOT_LINKED);
			}
			return;
		}
		$parts = preg_split('/\s+/', $text, 2) ?: [$text];
		$cmd = strtolower((string) preg_replace('/@.*$/', '', substr($parts[0], 1)));
		$arg = trim($parts[1] ?? '');
		$known = ['start', 'iscrivi', 'subscribe', 'foto', 'photo', 'meteo', 'weather', 'webcam', 'avvisi', 'alerts', 'stop', 'aiuto', 'help'];
		if (in_array($cmd, $known, true) && !in_array($cmd, ['foto', 'photo'], true)) {
			try {
				RateLimiter::hitBucket('tg-' . ($chatId < 0 ? 'n' . -$chatId : (string) $chatId), 20);
			} catch (ApiError $e) {
				return; // oltre 20 comandi al minuto: si ignorano
			}
		}
		switch ($cmd) {
			case 'start':
				self::reply($chatId, "Ciao! Sono il bot di Viewlapse.\n" . (self::chat($chatId) ? self::HELP : 'Per collegare questa chat alle tue webcam scrivi /iscrivi CODICE (il codice te lo dà Viewlapse).'));
				return;
			case 'iscrivi':
			case 'subscribe':
				self::pair($m, $arg);
				return;
			case 'aiuto':
			case 'help':
				self::reply($chatId, self::HELP);
				return;
		}
		if (!in_array($cmd, $known, true)) {
			self::reply($chatId, self::chat($chatId) ? "Comando sconosciuto.\n" . self::HELP : self::NOT_LINKED);
			return;
		}
		$chat = self::chat($chatId);
		if ($chat === null) {
			self::reply($chatId, self::NOT_LINKED);
			return;
		}
		Db::exec('UPDATE telegram_chats SET last_command_at = UTC_TIMESTAMP() WHERE chat_id = ? AND status = \'active\'', [$chatId]);
		switch ($cmd) {
			case 'foto':
			case 'photo':
			case 'meteo':
			case 'weather':
				$kind = in_array($cmd, ['foto', 'photo'], true) ? 'f' : 'm';
				$list = self::allWebcams($chatId);
				$w = self::pick($chatId, $list, $arg, $kind);
				if ($w !== null) {
					$kind === 'f' ? self::photo($chatId, $w) : self::weather($chatId, $w);
				}
				return;
			case 'webcam':
				$list = self::allWebcams($chatId);
				self::reply($chatId, $list === [] ? 'Nessuna webcam collegata a questa chat.' : "Le tue webcam:\n" . implode("\n", array_map(static fn ($w) => '- ' . ($w['name'] ?: $w['slug']) . ' (' . $w['slug'] . ')', $list)));
				return;
			case 'avvisi':
			case 'alerts':
				self::alertsMenu($chatId);
				return;
			case 'stop':
				self::stop($chatId, 'stopped');
				self::reply($chatId, 'Non riceverai più messaggi. Per ricollegarti serve un nuovo codice.', true);
				Log::info('chat Telegram scollegata con /stop', self::LOG);
				return;
		}
	}

	/** Webcam dall'argomento (nome o slug, anche parziale se univoco) o pulsanti. */
	private static function pick(int $chatId, array $list, string $arg, string $kind): ?array
	{
		if ($list === []) {
			self::reply($chatId, 'Nessuna webcam collegata a questa chat.');
			return null;
		}
		if ($arg === '') {
			if (count($list) === 1) {
				return $list[0];
			}
			self::reply($chatId, 'Quale webcam?', false, ['inline_keyboard' => array_map(static fn ($w) => [['text' => (string) ($w['name'] ?: $w['slug']), 'callback_data' => $kind . ':' . (int) $w['id']]], $list)]);
			return null;
		}
		$a = mb_strtolower($arg);
		$exact = array_values(array_filter($list, static fn ($w) => mb_strtolower((string) $w['slug']) === $a || mb_strtolower((string) $w['name']) === $a));
		if (count($exact) === 1) {
			return $exact[0];
		}
		$part = array_values(array_filter($list, static fn ($w) => str_contains(mb_strtolower((string) $w['slug']), $a) || str_contains(mb_strtolower((string) $w['name']), $a)));
		if (count($part) === 1) {
			return $part[0];
		}
		self::reply($chatId, $part === [] ? 'Webcam non trovata. Scrivi /webcam per l\'elenco.' : 'Più webcam con questo nome: scrivi il nome completo.');
		return null;
	}

	private static function callback(array $q): void
	{
		$chatId = (int) ($q['message']['chat']['id'] ?? 0);
		$data = (string) ($q['data'] ?? '');
		$api = Api::fromConfig();
		if ($api !== null && isset($q['id'])) {
			$api->call('answerCallbackQuery', ['callback_query_id' => (string) $q['id']], [], 5);
		}
		if ($chatId === 0 || self::chat($chatId) === null) {
			return;
		}
		$list = [];
		foreach (self::allWebcams($chatId) as $w) {
			$list[(int) $w['id']] = $w;
		}
		if (preg_match('/^([fm]):(\d+)$/', $data, $mm)) {
			$w = $list[(int) $mm[2]] ?? null; // sempre ricontrollato contro le webcam della chat
			if ($w !== null) {
				$mm[1] === 'f' ? self::photo($chatId, $w) : self::weather($chatId, $w);
			}
			return;
		}
		if (preg_match('/^a:(\d+):([a-z_]+)$/', $data, $mm) && isset($list[(int) $mm[1]]) && in_array($mm[2], self::ALERTS, true)) {
			$ref = $list[(int) $mm[1]]['_chat_ref'];
			$cur = Db::scalar('SELECT alerts FROM telegram_subscriptions WHERE chat_ref = ? AND webcam_id = ?', [$ref, $mm[1]]);
			$set = $cur === null ? ['offline', 'online', 'photo_of_day'] : array_filter(explode(',', (string) $cur));
			$set = in_array($mm[2], $set, true) ? array_diff($set, [$mm[2]]) : array_merge($set, [$mm[2]]);
			Db::exec(
				'INSERT INTO telegram_subscriptions (chat_ref, webcam_id, alerts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE alerts = VALUES(alerts)',
				[$ref, $mm[1], implode(',', array_values(array_intersect(self::ALERTS, $set)))]
			);
			self::alertsMenu($chatId);
		}
	}

	// ------------------------------------------------------------ abbinamento

	private static function pair(array $m, string $arg): void
	{
		$chatId = (int) $m['chat']['id'];
		$hour = gmdate('Y-m-d H:00:00');
		$fails = (int) Db::scalar('SELECT COALESCE(SUM(failures), 0) FROM telegram_pair_failures WHERE chat_id = ? AND hour_ts >= ?', [$chatId, Db::dt(time() - 3600)]);
		if ($fails >= 5) {
			self::reply($chatId, 'Troppi tentativi, riprova tra un\'ora.');
			return;
		}
		$code = self::normalize($arg);
		$row = strlen($code) === 10 ? Db::one(
			'SELECT k.*, c.active AS customer_active FROM telegram_pair_codes k JOIN customers c ON c.id = k.customer_id WHERE k.code_hash = ?',
			[hash('sha256', $code)]
		) : null;
		$ok = $row !== null && $row['used_at'] === null && $row['revoked_at'] === null && Db::unix((string) $row['expires_at']) > time() && (int) $row['customer_active'] === 1;
		$webcams = [];
		if ($ok) {
			$webcams = self::webcamsOf(['customer_id' => (int) $row['customer_id'], 'webcam_id' => $row['webcam_id'] === null ? null : (int) $row['webcam_id']]);
			$ok = $webcams !== [];
		}
		if (!$ok) {
			Db::exec('INSERT INTO telegram_pair_failures (chat_id, hour_ts, failures) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE failures = failures + 1', [$chatId, $hour]);
			self::reply($chatId, 'Codice non valido o scaduto. Controlla il codice o chiedine uno nuovo a Viewlapse.');
			return;
		}
		$type = in_array($m['chat']['type'] ?? '', ['private', 'group', 'supergroup', 'channel'], true) ? (string) $m['chat']['type'] : 'private';
		$name = trim((string) ($m['chat']['title'] ?? trim(($m['chat']['first_name'] ?? '') . ' ' . ($m['chat']['last_name'] ?? ''))));
		$user = $m['chat']['username'] ?? ($m['from']['username'] ?? null);
		$lang = substr((string) ($m['from']['language_code'] ?? 'it'), 0, 2);
		$existing = Db::one("SELECT id FROM telegram_chats WHERE chat_id = ? AND customer_id = ? AND status = 'active'", [$chatId, $row['customer_id']]);
		if ($existing !== null) {
			$ref = (int) $existing['id'];
			Db::exec('UPDATE telegram_chats SET webcam_id = ?, display_name = ?, username = ?, pair_code_id = ?, paired_at = UTC_TIMESTAMP() WHERE id = ?', [$row['webcam_id'], $name === '' ? null : mb_substr($name, 0, 128), $user === null ? null : mb_substr((string) $user, 0, 64), $row['id'], $ref]);
		} else {
			Db::exec(
				'INSERT INTO telegram_chats (chat_id, chat_type, customer_id, webcam_id, display_name, username, language, pair_code_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				[$chatId, $type, $row['customer_id'], $row['webcam_id'], $name === '' ? null : mb_substr($name, 0, 128), $user === null ? null : mb_substr((string) $user, 0, 64), preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'it', $row['id']]
			);
			$ref = (int) Db::pdo()->lastInsertId();
		}
		foreach ($webcams as $w) {
			Db::exec('INSERT IGNORE INTO telegram_subscriptions (chat_ref, webcam_id) VALUES (?, ?)', [$ref, $w['id']]);
		}
		Db::exec('UPDATE telegram_pair_codes SET used_at = UTC_TIMESTAMP(), used_chat = ? WHERE id = ?', [$ref, $row['id']]);
		Log::info("chat Telegram abbinata al cliente {$row['customer_id']} (codice {$row['id']}, chat $ref)", self::LOG);
		self::reply($chatId, "Collegato! Webcam:\n" . implode("\n", array_map(static fn ($w) => '- ' . ($w['name'] ?: $w['slug']), $webcams)) . "\n\n" . self::HELP);
	}

	/** /stop, bot bloccato o revoca: iscrizioni cancellate, nome e username tolti. */
	public static function stop(int $chatId, string $status = 'stopped', ?int $chatRef = null): void
	{
		$rows = $chatRef !== null ? Db::all('SELECT id FROM telegram_chats WHERE id = ?', [$chatRef]) : Db::all("SELECT id FROM telegram_chats WHERE chat_id = ? AND status = 'active'", [$chatId]);
		foreach ($rows as $r) {
			Db::exec('DELETE FROM telegram_subscriptions WHERE chat_ref = ?', [$r['id']]);
			Db::exec("UPDATE telegram_chats SET status = ?, stopped_at = UTC_TIMESTAMP(), display_name = NULL, username = NULL WHERE id = ?", [$status === 'blocked' ? 'blocked' : 'stopped', $r['id']]);
			Db::exec("UPDATE telegram_messages SET status = 'dropped', status_reason = ? WHERE chat_ref = ? AND status = 'queued' AND kind <> 'system'", [$status, $r['id']]);
		}
	}

	// ------------------------------------------------------------ risposte

	private static function photo(int $chatId, array $w): void
	{
		$ref = (int) ($w['_chat_ref'] ?? self::chat($chatId)['id']);
		$last = Db::scalar("SELECT MAX(created_at) FROM telegram_messages WHERE chat_ref IN (SELECT id FROM telegram_chats WHERE chat_id = ?) AND kind = 'reply' AND photo_id IS NOT NULL", [$chatId]);
		if ($last !== null && Db::unix((string) $last) > time() - 60) {
			self::reply($chatId, 'Una foto al minuto: riprova tra poco.');
			return;
		}
		$today = (int) Db::scalar("SELECT COUNT(*) FROM telegram_messages WHERE chat_ref IN (SELECT id FROM telegram_chats WHERE chat_id = ?) AND kind = 'reply' AND photo_id IS NOT NULL AND created_at >= ?", [$chatId, Db::dt(time() - 86400)]);
		if ($today >= 30) {
			self::reply($chatId, 'Limite di 30 foto al giorno raggiunto.');
			return;
		}
		$p = Db::one('SELECT * FROM photos WHERE webcam_id = ? AND is_public = 1 AND deleted_at IS NULL ORDER BY taken_at DESC, id DESC LIMIT 1', [$w['id']]);
		Stats::count((int) $w['id'], 'telegram');
		if ($p === null) {
			self::reply($chatId, ($w['name'] ?: $w['slug']) . ': nessuna foto ricevuta.');
			return;
		}
		$caption = self::caption($w, $p);
		$t = (int) Db::unix((string) $p['taken_at']);
		$interval = max(1, (int) ($w['interval_min'] ?? 10));
		if (time() - $t > 2 * $interval * 60 + 1800) {
			$caption .= "\nLa webcam non manda foto dalle " . self::localTime($w, $t);
		}
		self::queue($ref, (int) $w['id'], 'reply', $caption, (int) $p['id']);
		self::sendQueued($ref);
	}

	public static function caption(array $w, array $p): string
	{
		$tz = ServiceSettings::tz($w);
		$parts = [(string) ($w['name'] ?: $w['slug']), (new \DateTimeImmutable('@' . (int) Db::unix((string) $p['taken_at'])))->setTimezone($tz)->format('d/m/Y H:i')];
		if ($p['t_ext'] !== null) {
			$parts[] = number_format((float) $p['t_ext'], 1, ',', '') . ' °C';
		}
		if ($p['rh_ext'] !== null) {
			$parts[] = number_format((float) $p['rh_ext'], 0, ',', '') . ' %';
		}
		return implode(' · ', $parts);
	}

	private static function localTime(array $w, int $t, string $fmt = 'H:i'): string
	{
		return (new \DateTimeImmutable('@' . $t))->setTimezone(ServiceSettings::tz($w))->format($fmt);
	}

	private static function weather(int $chatId, array $w): void
	{
		Stats::count((int) $w['id'], 'telegram');
		$name = (string) ($w['name'] ?: $w['slug']);
		$m = Db::one('SELECT ts, t_ext, rh_ext, dp_ext FROM measurements WHERE webcam_id = ? AND t_ext IS NOT NULL AND ts >= ? ORDER BY ts DESC LIMIT 1', [$w['id'], Db::dt(time() - 86400)]);
		if ($m === null) {
			self::reply($chatId, "$name: sensore esterno non installato o nessun dato nelle ultime 24 ore.");
			return;
		}
		$f = static fn ($v, int $d = 1): string => number_format((float) $v, $d, ',', '');
		$t = (int) Db::unix((string) $m['ts']);
		$tz = ServiceSettings::tz($w);
		$start = (new \DateTimeImmutable('today', $tz))->getTimestamp();
		$mm = Db::one('SELECT MIN(t_ext) a, MAX(t_ext) b FROM measurements WHERE webcam_id = ? AND ts >= ? AND t_ext IS NOT NULL', [$w['id'], Db::dt($start)]);
		$old = Db::one('SELECT t_ext FROM measurements WHERE webcam_id = ? AND t_ext IS NOT NULL AND ts BETWEEN ? AND ? ORDER BY ts LIMIT 1', [$w['id'], Db::dt($t - 3 * 3600 - 900), Db::dt($t - 3 * 3600 + 900)]);
		$lines = ["$name alle " . self::localTime($w, $t), 'Temperatura ' . $f($m['t_ext']) . ' °C'];
		if ($m['rh_ext'] !== null) {
			$lines[] = 'Umidità ' . $f($m['rh_ext'], 0) . ' %';
		}
		$dp = $m['dp_ext'] !== null ? (float) $m['dp_ext'] : Renderer::dewpoint((float) $m['t_ext'], $m['rh_ext'] === null ? null : (float) $m['rh_ext']);
		if ($dp !== null) {
			$lines[] = 'Punto di rugiada ' . $f($dp) . ' °C';
		}
		if ($mm !== null && $mm['a'] !== null) {
			$lines[] = 'Oggi: minima ' . $f($mm['a']) . ' °C, massima ' . $f($mm['b']) . ' °C';
		}
		if ($old !== null) {
			$d = (float) $m['t_ext'] - (float) $old['t_ext'];
			$lines[] = 'Ultime 3 ore: ' . ($d >= 0 ? '+' : '') . $f($d) . ' °C';
		}
		self::reply($chatId, implode("\n", $lines));
	}

	private static function alertsMenu(int $chatId): void
	{
		$rows = [];
		$lines = ['Avvisi (tocca per accendere o spegnere):'];
		foreach (self::allWebcams($chatId) as $w) {
			$cur = Db::scalar('SELECT alerts FROM telegram_subscriptions WHERE chat_ref = ? AND webcam_id = ?', [$w['_chat_ref'], $w['id']]);
			$set = $cur === null ? [] : explode(',', (string) $cur);
			$lines[] = ($w['name'] ?: $w['slug']) . ': ' . ($set === [] || $set === [''] ? 'nessuno' : implode(', ', array_map(static fn ($a) => self::ALERT_LABELS[$a] ?? $a, $set)));
			foreach (self::ALERTS as $a) {
				$rows[] = [['text' => (in_array($a, $set, true) ? '✅ ' : '⬜ ') . ($w['name'] ?: $w['slug']) . ': ' . self::ALERT_LABELS[$a], 'callback_data' => 'a:' . (int) $w['id'] . ':' . $a]];
			}
		}
		self::reply($chatId, implode("\n", $lines), false, $rows === [] ? null : ['inline_keyboard' => $rows]);
	}

	/**
	 * Risposta di testo, mandata subito (le foto, lente, vanno nella coda).
	 * Un 403 di Telegram (bot bloccato) scollega la chat.
	 */
	private static function reply(int $chatId, string $text, bool $evenStopped = false, ?array $markup = null): void
	{
		$api = self::configured() ? Api::fromConfig() : null;
		if ($api === null) {
			return;
		}
		$p = ['chat_id' => (string) $chatId, 'text' => $text];
		if ($markup !== null) {
			$p['reply_markup'] = $markup;
		}
		$res = $api->call('sendMessage', $p, [], 10);
		if ($res['status'] === 403 && !$evenStopped) {
			self::stop($chatId, 'blocked');
		}
	}

	/** Mette un messaggio in coda. Con dedupe_key uguale per la stessa chat non si duplica. */
	public static function queue(int $chatRef, ?int $webcamId, string $kind, string $text, ?int $photoId = null, ?string $alertType = null, ?string $dedupe = null): void
	{
		Db::exec(
			'INSERT IGNORE INTO telegram_messages (chat_ref, webcam_id, kind, alert_type, dedupe_key, text, photo_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
			[$chatRef, $webcamId, $kind, $alertType, $dedupe, mb_substr($text, 0, 1024), $photoId]
		);
	}

	// ------------------------------------------------------------ invio

	private static function inQuiet(?array $w, int $now): bool
	{
		$q = trim((string) Config::get()->value('telegram.quiet_hours', ''));
		if ($q === '' || !preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $q, $m)) {
			return false;
		}
		$loc = (new \DateTimeImmutable('@' . $now))->setTimezone(ServiceSettings::tz($w ?? []));
		$min = (int) $loc->format('G') * 60 + (int) $loc->format('i');
		$a = (int) $m[1] * 60 + (int) $m[2];
		$b = (int) $m[3] * 60 + (int) $m[4];
		return $a <= $b ? ($min >= $a && $min < $b) : ($min >= $a || $min < $b);
	}

	/**
	 * Svuota la coda (tutte le chat o una): ore di silenzio, limite
	 * giornaliero, retry_after dei 429, chat bloccate (403).
	 *
	 * @return array{sent:int, failed:int, dropped:int, waiting:int}
	 */
	public static function sendQueued(?int $chatRef = null, ?int $now = null): array
	{
		$stats = ['sent' => 0, 'failed' => 0, 'dropped' => 0, 'waiting' => 0];
		$api = self::configured() ? Api::fromConfig() : null;
		if ($api === null) {
			return $stats;
		}
		$now ??= time();
		$sql = "SELECT m.*, c.chat_id, c.status AS chat_status, c.messages_today, c.messages_day FROM telegram_messages m JOIN telegram_chats c ON c.id = m.chat_ref WHERE m.status = 'queued'";
		$p = [];
		if ($chatRef !== null) {
			$sql .= ' AND m.chat_ref = ?';
			$p[] = $chatRef;
		}
		$lastSent = [];
		$limit = max(1, (int) Config::get()->value('telegram.daily_limit', 20));
		foreach (Db::all($sql . ' ORDER BY m.id LIMIT 200', $p) as $m) {
			$w = $m['webcam_id'] === null ? null : Db::one('SELECT * FROM webcams WHERE id = ?', [$m['webcam_id']]);
			$isAlert = in_array($m['kind'], ['alert', 'photo_of_day'], true);
			if ($m['chat_status'] !== 'active' && $m['kind'] !== 'system') {
				self::mark((int) $m['id'], 'dropped', (string) $m['chat_status']);
				$stats['dropped']++;
				continue;
			}
			if ($isAlert && self::inQuiet($w, $now)) {
				if ($m['alert_type'] === 'online') {
					$off = Db::one("SELECT id FROM telegram_messages WHERE chat_ref = ? AND webcam_id = ? AND alert_type = 'offline' AND status = 'queued' AND id < ?", [$m['chat_ref'], $m['webcam_id'], $m['id']]);
					if ($off !== null) {
						self::mark((int) $off['id'], 'dropped', 'quiet_hours');
						self::mark((int) $m['id'], 'dropped', 'quiet_hours');
						$stats['dropped'] += 2;
						continue;
					}
				}
				$stats['waiting']++;
				continue; // resta in coda fino alla fine delle ore di silenzio
			}
			$today = gmdate('Y-m-d', $now);
			$count = $m['messages_day'] === $today ? (int) $m['messages_today'] : 0;
			if ($isAlert && $count >= $limit) {
				self::mark((int) $m['id'], 'dropped', 'daily_limit');
				$stats['dropped']++;
				continue;
			}
			$cid = (int) $m['chat_id'];
			if (isset($lastSent[$cid]) && microtime(true) - $lastSent[$cid] < 1.0) {
				usleep((int) ((1.0 - (microtime(true) - $lastSent[$cid])) * 1e6)); // un messaggio al secondo per chat
			}
			$text = (string) $m['text'];
			$params = ['chat_id' => (string) $cid];
			$file = $m['photo_id'] !== null ? self::photoFile((int) $m['photo_id']) : null;
			if ($file !== null) {
				$res = $api->call('sendPhoto', $params + ['caption' => mb_substr($text, 0, 1024)], ['photo' => $file], 60);
			} else {
				$res = $api->call('sendMessage', $params + ['text' => $text], [], 20);
			}
			$lastSent[$cid] = microtime(true);
			$st = $res['status'];
			if ($st === 200 && !empty($res['body']['ok'])) {
				Db::exec("UPDATE telegram_messages SET status = 'sent', sent_at = UTC_TIMESTAMP(), attempts = attempts + 1, telegram_message_id = ? WHERE id = ?", [(int) ($res['body']['result']['message_id'] ?? 0) ?: null, $m['id']]);
				if ($isAlert) {
					Db::exec('UPDATE telegram_chats SET messages_today = IF(messages_day = ?, messages_today + 1, 1), messages_day = ? WHERE id = ?', [$today, $today, $m['chat_ref']]);
				}
				$stats['sent']++;
			} elseif ($st === 429) {
				$retry = (int) ($res['body']['parameters']['retry_after'] ?? 30);
				Log::warning("Telegram 429: riprovo tra $retry s", self::LOG);
				Db::exec('UPDATE telegram_messages SET attempts = attempts + 1 WHERE id = ?', [$m['id']]);
				break;
			} elseif ($st === 403) {
				Log::info("chat Telegram {$m['chat_ref']}: bot bloccato dall'utente", self::LOG);
				self::stop($cid, 'blocked', (int) $m['chat_ref']);
				self::mark((int) $m['id'], 'failed', 'blocked');
				$stats['failed']++;
			} elseif ($st >= 400 && $st < 500) {
				Log::warning("Telegram {$st} per il messaggio {$m['id']}: " . substr((string) ($res['body']['description'] ?? ''), 0, 200), self::LOG);
				self::mark((int) $m['id'], 'failed', 'telegram_' . $st);
				$stats['failed']++;
			} else {
				Db::exec('UPDATE telegram_messages SET attempts = attempts + 1 WHERE id = ?', [$m['id']]);
				if ((int) $m['attempts'] + 1 >= 5) {
					self::mark((int) $m['id'], 'failed', 'network');
					$stats['failed']++;
				}
			}
		}
		return $stats;
	}

	private static function mark(int $id, string $status, string $reason): void
	{
		Db::exec('UPDATE telegram_messages SET status = ?, status_reason = ? WHERE id = ?', [$status, $reason, $id]);
	}

	/** Versione 1280 (con sovrimpressione se attiva) dell'ultima foto, per sendPhoto. */
	private static function photoFile(int $photoId): ?string
	{
		$p = Db::one('SELECT p.*, p.storage_path FROM photos p WHERE p.id = ? AND p.deleted_at IS NULL', [$photoId]);
		if ($p === null) {
			return null;
		}
		$orig = PhotoStore::abs((string) $p['storage_path']);
		if (!is_file($orig)) {
			return null;
		}
		$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$p['webcam_id']]);
		try {
			return Images::derived($p, $orig, '1280', $w, Images::overlayFor($w, '1280'));
		} catch (\Throwable $e) {
			Log::warning("foto $photoId per Telegram non preparata: " . $e->getMessage(), self::LOG);
			return null;
		}
	}

	// ------------------------------------------------------------ collegamento

	/** getUpdates (XAMPP, senza URL pubblico): lavora al più $seconds secondi. */
	public static function poll(int $seconds = 55): int
	{
		$api = self::configured() ? Api::fromConfig() : null;
		if ($api === null) {
			return 0;
		}
		$file = Config::get()->storageDir() . '/state/telegram-offset';
		$offset = is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;
		$end = time() + $seconds;
		$n = 0;
		while (time() < $end) {
			$timeout = max(0, min(25, $end - time() - 5));
			$res = $api->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message', 'callback_query']], [], $timeout + 10);
			if ($res['status'] !== 200 || !is_array($res['body']['result'] ?? null)) {
				Log::warning('Telegram getUpdates: ' . $res['status'], self::LOG);
				break;
			}
			foreach ($res['body']['result'] as $u) {
				$offset = max($offset, (int) ($u['update_id'] ?? 0) + 1);
				@mkdir(dirname($file), 0775, true);
				file_put_contents($file, (string) $offset, LOCK_EX);
				self::handle((array) $u);
				$n++;
			}
			if ($res['body']['result'] === [] && $timeout === 0) {
				break;
			}
		}
		return $n;
	}

	/** setWebhook con secret_token (VPS). */
	public static function setWebhook(string $url): array
	{
		$api = Api::fromConfig();
		$secret = (string) Config::get()->value('telegram.webhook_secret', '');
		if ($api === null || strlen($secret) < 32) {
			throw new \RuntimeException('Servono telegram.bot_token e telegram.webhook_secret (32+ caratteri)');
		}
		return $api->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']]);
	}

	/**
	 * POST /telegram/webhook: solo con X-Telegram-Bot-Api-Secret-Token
	 * uguale a telegram.webhook_secret (tempo costante), altrimenti 403.
	 * Risponde 200 subito; le risposte partono dopo.
	 */
	public static function webhook(?string $secretHeader, string $body): int
	{
		$secret = (string) Config::get()->value('telegram.webhook_secret', '');
		if (!self::configured() || strlen($secret) < 32 || $secretHeader === null || !hash_equals($secret, $secretHeader)) {
			return 403;
		}
		$u = json_decode($body, true);
		http_response_code(200);
		header('Content-Type: application/json');
		echo '{"ok":true}';
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		if (is_array($u)) {
			self::handle($u);
		}
		return 200;
	}

	/**
	 * Pulizia: iscrizioni delle webcam passate a un altro cliente, chat
	 * scollegate dopo forget_days, messaggi oltre 90 giorni, tentativi vecchi.
	 */
	public static function cleanup(?int $now = null): void
	{
		$now ??= time();
		Db::exec('DELETE s FROM telegram_subscriptions s JOIN telegram_chats c ON c.id = s.chat_ref JOIN webcams w ON w.id = s.webcam_id WHERE w.customer_id IS NULL OR w.customer_id <> c.customer_id');
		$days = max(1, (int) Config::get()->value('telegram.forget_days', 30));
		Db::exec("DELETE FROM telegram_chats WHERE status <> 'active' AND stopped_at < ?", [Db::dt($now - $days * 86400)]);
		Db::exec('DELETE FROM telegram_messages WHERE created_at < ?', [Db::dt($now - 90 * 86400)]);
		Db::exec('DELETE FROM telegram_pair_failures WHERE hour_ts < ?', [Db::dt($now - 86400)]);
		// nuove webcam del cliente per le chat "tutte le webcam"
		foreach (Db::all("SELECT * FROM telegram_chats WHERE status = 'active' AND webcam_id IS NULL") as $c) {
			foreach (self::webcamsOf($c) as $w) {
				Db::exec('INSERT IGNORE INTO telegram_subscriptions (chat_ref, webcam_id) VALUES (?, ?)', [$c['id'], $w['id']]);
			}
		}
	}
}
