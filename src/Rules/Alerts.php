<?php

declare(strict_types=1);

namespace WebcamPortal\Rules;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Service\Events;

/**
 * Apertura, aggravamento e chiusura degli avvisi (tabella alerts), con le
 * righe alert.open / alert.update / alert.close in events.
 *
 * events.data.channel dice chi deve mandare l'evento a Dolibarr:
 * "ticket" (TicketSync, ticket del modulo 1.3), "agenda" (Sync, come gli
 * eventi >= warning), "none" (solo pannello).
 */
final class Alerts
{
	public const SEVERITY_RANK = ['notice' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
	public const ACTION_RANK = ['panel' => 1, 'command' => 2, 'agenda' => 3, 'ticket' => 4];
	public const SEVERITY_LABEL = ['notice' => 'avviso', 'warning' => 'attenzione', 'error' => 'errore', 'critical' => 'critica'];

	public static function channel(string $action): string
	{
		return match ($action) {
			'ticket' => 'ticket',
			'agenda' => 'agenda',
			default => 'none',
		};
	}

	public static function openFor(int $webcamId, string $ruleId): ?array
	{
		return Db::one("SELECT * FROM alerts WHERE webcam_id = ? AND rule_id = ? AND status = 'open'", [$webcamId, $ruleId]);
	}

	/** @return array<string,mixed> */
	public static function details(array $alert): array
	{
		$d = json_decode((string) ($alert['details'] ?? ''), true);
		return is_array($d) ? $d : [];
	}

	/**
	 * Apre un avviso, o riapre lo stesso se la regola si era chiusa da meno
	 * di reopen_within ore.
	 *
	 * @return int id dell'avviso
	 */
	public static function open(Context $c, Rule $rule, Outcome $o): int
	{
		$wid = $c->id();
		$now = $c->now;
		$sev = $o->severity ?? $rule->severity();
		$action = $o->action ?? $rule->action();
		$title = mb_substr($o->title ?? $rule->label(), 0, 255);
		$reopenH = (float) Config::get()->value('rules.reopen_within_h', 24);
		$details = self::buildDetails($o);
		$due = self::dueAt($c, $action, $now);

		$prev = Db::one(
			"SELECT * FROM alerts WHERE webcam_id = ? AND rule_id = ? AND status = 'closed' AND closed_at >= ?
			  ORDER BY closed_at DESC LIMIT 1",
			[$wid, $rule->id(), Db::dt((int) ($now - $reopenH * 3600))]
		);
		$reopened = $prev !== null;
		if ($reopened) {
			$id = (int) $prev['id'];
			$keep = self::details($prev);
			$details['previous'] = array_filter(['closed_at' => $prev['closed_at'], 'close_reason' => $prev['close_reason'], 'opened_at' => $prev['opened_at']]);
			if (isset($keep['history'])) {
				$details['history'] = $keep['history'];
			}
			Db::exec(
				"UPDATE alerts SET status = 'open', severity = ?, action = ?, title = ?, details = ?, opened_at = ?, closed_at = NULL,
				   close_reason = NULL, close_event_id = NULL, reopen_count = reopen_count + 1, due_at = ?, sla_breached = 0,
				   acknowledged_by = NULL, acknowledged_at = NULL, dolibarr_synced_at = NULL WHERE id = ?",
				[$sev, $action, $title, self::json($details), Db::dt($now), $due, $id]
			);
		} else {
			Db::exec(
				'INSERT INTO alerts (webcam_id, rule_id, status, severity, action, title, details, opened_at, due_at)
				 VALUES (?, ?, \'open\', ?, ?, ?, ?, ?, ?)',
				[$wid, $rule->id(), $sev, $action, $title, self::json($details), Db::dt($now), $due]
			);
			$id = (int) Db::pdo()->lastInsertId();
		}
		$eid = self::event($c, $rule, $id, 'alert.open', $sev, $action, ($reopened ? 'Riaperto: ' : '') . $title, [
			'reopened' => $reopened,
		]);
		Db::exec('UPDATE alerts SET open_event_id = ? WHERE id = ?', [$eid, $id]);
		Log::info("avviso $id " . ($reopened ? 'riaperto' : 'aperto') . " webcam=$wid regola={$rule->id()} gravità=$sev: $title", 'rules');
		\WebcamPortal\Telegram\Notify::alert($id, true); // offline (bot Telegram, contratto 1.3)
		return $id;
	}

	/** La gravità sale (mai scende): alert.update. */
	public static function escalate(Context $c, Rule $rule, array $alert, Outcome $o, string $reason = 'escalation'): bool
	{
		$sev = $o->severity ?? $alert['severity'];
		$action = $o->action ?? $alert['action'];
		if (self::SEVERITY_RANK[$sev] <= self::SEVERITY_RANK[$alert['severity']]) {
			return false;
		}
		if (self::ACTION_RANK[$action] < self::ACTION_RANK[$alert['action']]) {
			$action = $alert['action'];
		}
		$details = self::details($alert);
		$details = array_merge($details, self::buildDetails($o));
		$details['history'][] = ['at' => Db::dt($c->now), 'from' => $alert['severity'], 'to' => $sev, 'reason' => $reason];
		$title = mb_substr($o->title ?? $alert['title'], 0, 255);
		$due = $alert['due_at'] ?? self::dueAt($c, $action, $c->now);
		Db::exec(
			'UPDATE alerts SET severity = ?, action = ?, title = ?, details = ?, due_at = ?, dolibarr_synced_at = NULL WHERE id = ?',
			[$sev, $action, $title, self::json($details), $due, $alert['id']]
		);
		self::event($c, $rule, (int) $alert['id'], 'alert.update', $sev, $action, "Aggravato ($sev): $title", ['from' => $alert['severity'], 'reason' => $reason]);
		Log::info("avviso {$alert['id']} aggravato {$alert['severity']} -> $sev webcam={$c->id()} regola={$rule->id()}", 'rules');
		return true;
	}

	/** Aggiorna i valori misurati di un avviso aperto (senza evento). */
	public static function refresh(array $alert, Outcome $o): void
	{
		$d = array_merge(self::details($alert), self::buildDetails($o));
		Db::exec('UPDATE alerts SET details = ? WHERE id = ?', [self::json($d), $alert['id']]);
	}

	public static function close(Context $c, Rule $rule, array $alert, string $reason = 'auto', ?string $note = null): void
	{
		$now = $c->now;
		$d = self::details($alert);
		if ($note !== null) {
			$d['close_note'] = $note;
		}
		Db::exec(
			"UPDATE alerts SET status = 'closed', closed_at = ?, close_reason = ?, details = ?, dolibarr_synced_at = NULL WHERE id = ?",
			[Db::dt($now), $reason, self::json($d), $alert['id']]
		);
		$msg = 'Chiuso: ' . $alert['title'] . ($note ? " ($note)" : '');
		$eid = self::event($c, $rule, (int) $alert['id'], 'alert.close', 'notice', (string) $alert['action'], $msg, ['reason' => $reason, 'note' => $note]);
		Db::exec('UPDATE alerts SET close_event_id = ? WHERE id = ?', [$eid, $alert['id']]);
		Log::info("avviso {$alert['id']} chiuso ($reason) webcam={$c->id()} regola={$rule->id()}", 'rules');
		\WebcamPortal\Telegram\Notify::alert((int) $alert['id'], false); // online
	}

	/** Chiusura senza valutazione (operatore, fotocamera cambiata, webcam ritirata). */
	public static function closeById(int $alertId, string $reason, ?string $note = null, ?int $userId = null, ?int $now = null): bool
	{
		$a = Db::one("SELECT * FROM alerts WHERE id = ? AND status = 'open'", [$alertId]);
		if ($a === null) {
			return false;
		}
		$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$a['webcam_id']]);
		$c = new Context($w, \WebcamPortal\Service\ServiceSettings::get((int) $a['webcam_id']), $now ?? time());
		$rule = Registry::get((string) $a['rule_id']);
		if ($rule === null) {
			return false;
		}
		self::close($c, $rule, $a, $reason, $note);
		if ($userId !== null) {
			Db::exec('UPDATE events SET source = \'operator\', user_id = ? WHERE id = (SELECT close_event_id FROM alerts WHERE id = ?)', [$userId, $alertId]);
		}
		return true;
	}

	/** Messaggio per il ticket senza cambiare la gravità (es. scadenza vicina). */
	public static function note(Context $c, Rule $rule, array $alert, string $severity, string $message, array $data = []): int
	{
		return self::event($c, $rule, (int) $alert['id'], 'alert.update', $severity, (string) $alert['action'], $message, $data);
	}

	private static function dueAt(Context $c, string $action, int $now): ?string
	{
		if (!$c->slaEnabled() || $action !== 'ticket') {
			return null;
		}
		return Db::dt($now + max(1, (int) $c->service['sla_response_days']) * 86400);
	}

	/** @return array<string,mixed> */
	private static function buildDetails(Outcome $o): array
	{
		return array_filter([
			'values' => $o->values,
			'lines' => $o->lines,
			'check' => $o->check,
			'cause' => $o->cause,
		], static fn ($v) => $v !== null && $v !== []);
	}

	private static function event(Context $c, Rule $rule, int $alertId, string $type, string $sev, string $action, string $message, array $extra): int
	{
		$data = [
			'rule' => $rule->id(),
			'alert_id' => $alertId,
			'channel' => $rule->forward() ? self::channel($action) : 'none',
			'forward' => $rule->forward(),
			'severity' => $sev,
		] + array_filter($extra, static fn ($v) => $v !== null);
		$eid = Events::portal($c->id(), $type, $sev, $message, $data, $c->now);
		Db::exec('UPDATE events SET alert_id = ? WHERE id = ?', [$alertId, $eid]);
		return $eid;
	}

	public static function json(array $d): string
	{
		return (string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
	}
}
