<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;

/**
 * Comandi al router (command/1, api-portal-v1.md "Comandi al router").
 *
 * Il router non è raggiungibile: i comandi stanno in coda (tabella commands)
 * e viaggiano nelle risposte di /photo, /telemetry, /event e GET /commands,
 * solo per le richieste con X-Webcam-Features: commands/1 e un token con lo
 * scope "commands". Si rimandano finché non arriva command.ack o scadono.
 */
final class Commands
{
	public const TYPES = ['heater.boost', 'heater.cancel', 'capture.now', 'telemetry.flush', 'config.set'];
	public const FEATURE = 'commands/1';
	public const MAX_PER_RESPONSE = 5;
	public const MAX_PER_HOUR = 10;
	public const MAX_BOOST_RULE_DAY = 4;
	public const MAX_TTL = 86400;

	/** Chiavi di config.set con il formato del valore (comando-v1.schema.json, $defs/config_set). */
	public const CONFIG_KEYS = [
		'general.heating' => '/^[01]$/',
		'general.heat_fan' => '/^[01]$/',
		'general.maintenance_mode' => '/^[01]$/',
		'general.heat_dewpoint_margin' => '/^(10(\.0)?|[1-9](\.[0-9])?)$/',
		'general.heat_hysteresis' => '/^(5(\.0)?|[0-4](\.[0-9])?)$/',
		'general.heat_min_temp' => '/^(-(10(\.0)?|[0-9](\.[0-9])?)|15(\.0)?|1[0-4](\.[0-9])?|[0-9](\.[0-9])?)$/',
		'shooting.photo_time_lapse' => '/^([1-9]|[1-5][0-9]|60)$/',
	];

	/** Etichette italiane per il pannello. */
	public const LABELS = [
		'heater.boost' => 'Riscaldamento forzato',
		'heater.cancel' => 'Fine riscaldamento forzato',
		'capture.now' => 'Scatta ora',
		'telemetry.flush' => 'Invia telemetria',
		'config.set' => 'Cambia impostazione',
	];

	/**
	 * Controlla tipo e parametri come lo schema (lista chiusa).
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed> parametri normalizzati
	 */
	public static function validate(string $type, array $params): array
	{
		if (!in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException("Tipo di comando sconosciuto: $type");
		}
		switch ($type) {
			case 'heater.boost':
				$d = $params['duration_s'] ?? null;
				if (array_diff(array_keys($params), ['duration_s']) !== [] || !is_int($d) || $d < 300 || $d > 3600) {
					throw new \InvalidArgumentException('heater.boost: duration_s intero tra 300 e 3600');
				}
				return ['duration_s' => $d];
			case 'heater.cancel':
			case 'telemetry.flush':
				if ($params !== []) {
					throw new \InvalidArgumentException("$type: nessun parametro ammesso");
				}
				return [];
			case 'capture.now':
				if (array_diff(array_keys($params), ['upload']) !== [] || (isset($params['upload']) && !is_bool($params['upload']))) {
					throw new \InvalidArgumentException('capture.now: solo upload (booleano)');
				}
				return $params;
			default: // config.set
				$k = $params['key'] ?? null;
				$v = $params['value'] ?? null;
				if (array_diff(array_keys($params), ['key', 'value']) !== [] || !is_string($k) || !is_string($v)) {
					throw new \InvalidArgumentException('config.set: servono key e value (stringa)');
				}
				if (!isset(self::CONFIG_KEYS[$k])) {
					throw new \InvalidArgumentException("config.set: chiave non ammessa: $k");
				}
				if (strlen($v) > 8 || !preg_match(self::CONFIG_KEYS[$k], $v)) {
					throw new \InvalidArgumentException("config.set: valore non valido per $k");
				}
				return ['key' => $k, 'value' => $v];
		}
	}

	/**
	 * Mette in coda un comando. Limiti: 10 creati per webcam all'ora, 4
	 * heater.boost al giorno dalle regole.
	 *
	 * @param array<string,mixed> $params
	 * @return int commands.id
	 */
	public static function create(int $webcamId, string $type, array $params = [], array $opt = []): int
	{
		$params = self::validate($type, $params);
		$now = (int) ($opt['now'] ?? time());
		$by = ($opt['by'] ?? 'operator') === 'rule' ? 'rule' : 'operator';
		$ttl = (int) ($opt['ttl'] ?? 3600);
		$ttl = max(60, min(self::MAX_TTL, $ttl));
		$maxHour = (int) Config::get()->value('commands.max_per_hour', self::MAX_PER_HOUR);
		$n = (int) Db::scalar('SELECT COUNT(*) FROM commands WHERE webcam_id = ? AND created_at > ?', [$webcamId, Db::dt($now - 3600)]);
		if ($n >= $maxHour) {
			throw new \RuntimeException("Troppi comandi per questa webcam nell'ultima ora (massimo $maxHour)");
		}
		if ($by === 'rule' && $type === 'heater.boost') {
			$b = (int) Db::scalar(
				"SELECT COUNT(*) FROM commands WHERE webcam_id = ? AND type = 'heater.boost' AND created_by = 'rule' AND created_at > ?",
				[$webcamId, Db::dt($now - 86400)]
			);
			if ($b >= self::MAX_BOOST_RULE_DAY) {
				throw new \RuntimeException('Già ' . self::MAX_BOOST_RULE_DAY . ' heater.boost dalle regole nelle ultime 24 ore');
			}
		}
		$reason = isset($opt['reason']) ? mb_substr((string) $opt['reason'], 0, 120) : null;
		Db::exec(
			'INSERT INTO commands (webcam_id, type, params, reason, alert_id, created_by, user_id, created_at, expires_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$webcamId, $type, json_encode((object) $params, JSON_UNESCAPED_SLASHES), $reason,
				$opt['alert_id'] ?? null, $by, $opt['user_id'] ?? null, Db::dt($now), Db::dt($now + $ttl),
			]
		);
		$id = (int) Db::pdo()->lastInsertId();
		Log::info("comando cmd-$id $type webcam=$webcamId da $by" . ($reason ? " ($reason)" : ''));
		return $id;
	}

	/** Segna scaduti i comandi non confermati oltre expires_at. */
	public static function expire(?int $webcamId = null, ?int $now = null): int
	{
		$sql = "UPDATE commands SET status = 'expired' WHERE status IN ('pending','delivered') AND expires_at <= ?";
		$p = [Db::dt($now ?? time())];
		if ($webcamId !== null) {
			$sql .= ' AND webcam_id = ?';
			$p[] = $webcamId;
		}
		return Db::exec($sql, $p);
	}

	/** Il router sa ricevere comandi? (intestazione X-Webcam-Features). */
	public static function routerAccepts(?string $featuresHeader, array $scopes): bool
	{
		if (!in_array('commands', $scopes, true) || $featuresHeader === null) {
			return false;
		}
		foreach (explode(',', $featuresHeader) as $f) {
			if (strtolower(trim($f)) === self::FEATURE) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Comandi da consegnare in questa risposta (al massimo 5, dal più
	 * vecchio) e poll_after facoltativo. Li segna come consegnati.
	 *
	 * @return array{commands: list<array<string,mixed>>, poll_after: ?int}
	 */
	public static function deliver(int $webcamId, ?int $now = null): array
	{
		$now ??= time();
		self::expire($webcamId, $now);
		$rows = Db::all(
			"SELECT * FROM commands WHERE webcam_id = ? AND status IN ('pending','delivered') AND expires_at > ?
			  ORDER BY id LIMIT " . (self::MAX_PER_RESPONSE + 1),
			[$webcamId, Db::dt($now)]
		);
		$more = count($rows) > self::MAX_PER_RESPONSE;
		$rows = array_slice($rows, 0, self::MAX_PER_RESPONSE);
		$out = [];
		foreach ($rows as $r) {
			$out[] = self::toJson($r);
		}
		if ($rows !== []) {
			$ids = implode(',', array_map(static fn ($r) => (int) $r['id'], $rows));
			Db::exec(
				"UPDATE commands SET status = IF(status = 'pending', 'delivered', status),
				   delivered_at = COALESCE(delivered_at, ?), delivery_count = LEAST(delivery_count + 1, 65535)
				 WHERE id IN ($ids)",
				[Db::dt($now)]
			);
		}
		$poll = null;
		if ($more) {
			$poll = 30;
		} elseif ($rows !== []) {
			// conferma in fretta del boost (contratto: es. 60 s mentre è in corso)
			$poll = in_array('heater.boost', array_column($rows, 'type'), true) ? 60 : 300;
		}
		return ['commands' => $out, 'poll_after' => $poll];
	}

	/** @param array<string,mixed> $r riga di commands */
	public static function toJson(array $r): array
	{
		$params = json_decode((string) ($r['params'] ?? '{}'), true);
		return [
			'schema' => 'command/1',
			'id' => 'cmd-' . (int) $r['id'],
			'type' => $r['type'],
			'created_at' => (int) Db::unix($r['created_at']),
			'expires_at' => (int) Db::unix($r['expires_at']),
			'params' => (object) (is_array($params) ? $params : []),
			'reason' => $r['reason'],
		];
	}

	/**
	 * Evento command.ack ricevuto: aggiorna il comando e collega l'evento
	 * all'avviso del comando. Un ack ripetuto non cambia niente.
	 *
	 * @param array<string,mixed>|null $data data dell'evento
	 */
	public static function ack(int $webcamId, int $eventId, ?array $data, int $ts): void
	{
		$cid = is_array($data) ? ($data['command_id'] ?? null) : null;
		$status = is_array($data) ? ($data['status'] ?? null) : null;
		if (!is_string($cid) || !preg_match('/^cmd-([0-9]{1,18})$/', $cid, $m)
			|| !in_array($status, ['done', 'rejected', 'failed', 'expired'], true)) {
			Log::warning("command.ack non valido webcam=$webcamId evento=$eventId");
			return;
		}
		$cmd = Db::one('SELECT * FROM commands WHERE id = ? AND webcam_id = ?', [(int) $m[1], $webcamId]);
		if ($cmd === null) {
			Log::warning("command.ack per un comando sconosciuto $cid webcam=$webcamId");
			return;
		}
		if ($cmd['alert_id'] !== null) {
			Db::exec('UPDATE events SET alert_id = ? WHERE id = ?', [$cmd['alert_id'], $eventId]);
		}
		if (in_array($cmd['status'], ['done', 'rejected', 'failed'], true) && $cmd['ack_event_id'] !== null) {
			return; // già confermato
		}
		$result = is_array($data['result'] ?? null) ? $data['result'] : null;
		if ($result === null && isset($data['reason']) && is_string($data['reason'])) {
			$result = ['reason' => $data['reason']];
		} elseif ($result !== null && isset($data['reason']) && is_string($data['reason'])) {
			$result['reason'] ??= $data['reason'];
		}
		Db::exec(
			'UPDATE commands SET status = ?, ack_at = ?, ack_event_id = ?, result = ? WHERE id = ?',
			[$status, Db::dt($ts), $eventId, $result === null ? null : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $cmd['id']]
		);
		Log::info("comando $cid confermato: $status" . (isset($data['reason']) && is_string($data['reason']) ? " ({$data['reason']})" : ''));
	}

	/** Annulla un comando non ancora confermato (pannello). */
	public static function cancel(int $webcamId, int $commandId): bool
	{
		return Db::exec(
			"UPDATE commands SET status = 'cancelled' WHERE id = ? AND webcam_id = ? AND status IN ('pending','delivered')",
			[$commandId, $webcamId]
		) > 0;
	}
}
