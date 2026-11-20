<?php

declare(strict_types=1);

namespace WebcamPortal\Dolibarr;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Rules\Registry;
use WebcamPortal\Service\ServiceSettings;

/**
 * Ticket di Dolibarr per gli avvisi con azione "ticket" (diagnosi-v1.md,
 * "Ticket in Dolibarr"; api-dolibarr-v2.md, modulo 1.3):
 *
 * - alert.open  -> POST webcams/{id}/tickets (o un messaggio nel ticket
 *   ancora aperto, se l'avviso è stato riaperto);
 * - alert.update -> PUT webcams/{id}/tickets/{ticket_id} con message (o il
 *   primo ticket, se l'avviso è appena diventato da ticket);
 * - alert.close -> PUT ... con message e close = 1;
 * - stato dei ticket aperti con GET /tickets/{id}: chiuso in Dolibarr con
 *   l'avviso aperto -> ticket_closed_by_operator = 1.
 *
 * Idempotenza: portal_event_id = events.id (lato Dolibarr) e
 * events.dolibarr_synced_at (lato portal). Con il modulo 1.2 (niente PUT)
 * gli eventi restano in coda e il log lo dice una volta ogni 6 ore.
 */
final class TicketSync
{
	private const LOG = 'sync-dolibarr';
	public const SEVERITY_CODE = ['notice' => 'LOW', 'warning' => 'NORMAL', 'error' => 'HIGH', 'critical' => 'BLOCKING'];

	public array $stats = ['created' => 0, 'messages' => 0, 'closed' => 0, 'skipped' => 0, 'polled' => 0, 'errors' => 0, 'missing' => 0];

	public function __construct(private Client $client, private string $baseUrl)
	{
	}

	public static function fromConfig(): ?self
	{
		$c = Config::get();
		$url = (string) $c->value('dolibarr.url', '');
		$key = (string) $c->value('dolibarr.api_key', '');
		if ($url === '' || $key === '') {
			return null;
		}
		return new self(
			new Client($url, $key, (int) $c->value('dolibarr.timeout', 20), (bool) $c->value('dolibarr.verify_tls', true)),
			rtrim((string) $c->value('public_base_url', ''), '/')
		);
	}

	/** @return int 0 ok, 1 errori */
	public function run(?int $now = null): int
	{
		$now ??= time();
		$events = Db::all(
			"SELECT e.id AS event_id, e.type AS event_type, e.data AS event_data, e.message AS event_message, e.ts AS event_ts, e.alert_id
			   FROM events e
			  WHERE e.dolibarr_synced_at IS NULL AND e.alert_id IS NOT NULL AND e.type IN ('alert.open','alert.update','alert.close')
			    AND JSON_VALUE(e.data, '$.channel') = 'ticket'
			  ORDER BY e.id LIMIT 200"
		);
		foreach ($events as $ev) {
			try {
				if (!$this->event($ev, $now)) {
					continue;
				}
			} catch (DolibarrException $e) {
				$this->stats['errors']++;
				Log::error("ticket per l'evento {$ev['event_id']} (avviso {$ev['alert_id']}): " . $e->getMessage(), self::LOG);
				if ($e->status === 0 || $e->status === 401) {
					Log::error('Dolibarr non raggiungibile o chiave API rifiutata: ticket rimandati al prossimo giro', self::LOG);
					return 1;
				}
			}
		}
		try {
			$this->poll($now);
		} catch (DolibarrException $e) {
			$this->stats['errors']++;
			Log::error('stato dei ticket: ' . $e->getMessage(), self::LOG);
		}
		if (array_sum($this->stats) > 0) {
			Log::info('ticket: ' . json_encode($this->stats), self::LOG);
		}
		return $this->stats['errors'] > 0 ? 1 : 0;
	}

	/** @return bool false se l'evento resta in coda */
	private function event(array $ev, int $now): bool
	{
		$a = Db::one('SELECT * FROM alerts WHERE id = ?', [$ev['alert_id']]);
		$w = $a ? Db::one('SELECT * FROM webcams WHERE id = ?', [$a['webcam_id']]) : null;
		if ($a === null || $w === null) {
			$this->done((int) $ev['event_id'], null);
			return true;
		}
		$did = $w['dolibarr_id'] !== null ? (int) $w['dolibarr_id'] : null;
		if ($did === null) {
			if (Missing::note('webcam-' . $w['id'] . '-nolink', 6)) {
				Log::warning("webcam {$w['id']} non collegata a Dolibarr (dolibarr_id vuoto): ticket in attesa di bin/sync-dolibarr.php", self::LOG);
			}
			return false;
		}
		$data = json_decode((string) $ev['event_data'], true) ?: [];
		$ticketId = $a['dolibarr_ticket_id'] !== null ? (int) $a['dolibarr_ticket_id'] : null;
		$ticketOpen = $ticketId !== null && (int) $a['ticket_closed_by_operator'] === 0 && !in_array((int) ($a['ticket_status'] ?? 0), [8, 9], true);
		$text = $this->message($a, $w, $ev);

		switch ($ev['event_type']) {
			case 'alert.open':
				if ($ticketOpen) {
					return $this->put($ev, $a, $did, ['message' => "La condizione è di nuovo presente (avviso riaperto).\n\n" . $text]);
				}
				if ($ticketId !== null && (int) $a['ticket_closed_by_operator'] === 1 && !empty($data['reopened'])) {
					// chiuso dall'operatore e riaperto entro reopen_within: nessun ticket nuovo
					$this->stats['skipped']++;
					$this->done((int) $ev['event_id'], $ticketId);
					return true;
				}
				return $this->create($ev, $a, $w, $did, $text);
			case 'alert.update':
				if ($ticketOpen) {
					return $this->put($ev, $a, $did, ['message' => $ev['event_message'] . "\n\n" . $text, 'severity_code' => self::SEVERITY_CODE[$a['severity']] ?? 'NORMAL']);
				}
				if ($ticketId === null && $a['status'] === 'open' && $a['action'] === 'ticket') {
					return $this->create($ev, $a, $w, $did, $text);
				}
				$this->stats['skipped']++;
				$this->done((int) $ev['event_id'], $ticketId);
				return true;
			default: // alert.close
				if ($ticketOpen) {
					$note = (string) ($data['note'] ?? '');
					$msg = 'Condizione rientrata il ' . $this->local($w, (int) Db::unix($ev['event_ts'])) . ($note !== '' ? ": $note" : '') . '.';
					if (($data['reason'] ?? '') === 'operator') {
						$msg = 'Avviso chiuso dall\'operatore nel portal il ' . $this->local($w, (int) Db::unix($ev['event_ts'])) . ($note !== '' ? ": $note" : '') . '.';
					}
					return $this->put($ev, $a, $did, ['message' => $msg . "\n\nPortal: " . $this->alertUrl($a), 'close' => 1], true);
				}
				$this->stats['skipped']++;
				$this->done((int) $ev['event_id'], $ticketId);
				return true;
		}
	}

	private function create(array $ev, array $a, array $w, int $did, string $text): bool
	{
		if (Missing::has('POST tickets')) {
			return false;
		}
		$body = [
			'subject' => mb_substr(($w['name'] ?: $w['slug']) . ': ' . $a['title'], 0, 255),
			'message' => $text,
			'type_code' => 'ISSUE',
			'severity_code' => self::SEVERITY_CODE[$a['severity']] ?? 'NORMAL',
			'category_code' => 'WEBCAM',
			'portal_event_id' => (int) $ev['event_id'],
			'portal_rule' => $a['rule_id'],
			'portal_alert_id' => (int) $a['id'],
		];
		if ($a['due_at'] !== null) {
			$body['due_date'] = $this->local($w, (int) Db::unix($a['due_at']), 'Y-m-d');
		}
		try {
			$r = $this->client->post("webcams/$did/tickets", $body);
		} catch (DolibarrException $e) {
			if ($e->endpointMissing()) {
				$this->missing('POST tickets', 'POST webcams/{id}/tickets non disponibile (modulo webcams < 1.2 o modulo Ticket non attivo): ticket in coda');
				return false;
			}
			throw $e;
		}
		$tid = is_array($r) && isset($r['ticket_id']) ? (int) $r['ticket_id'] : null;
		Db::exec(
			'UPDATE alerts SET dolibarr_ticket_id = ?, dolibarr_ticket_ref = ?, ticket_status = ?, ticket_closed_by_operator = 0, dolibarr_synced_at = UTC_TIMESTAMP() WHERE id = ?',
			[$tid, isset($r['ticket_ref']) ? substr((string) $r['ticket_ref'], 0, 32) : null, isset($r['status']) ? (int) $r['status'] : 0, $a['id']]
		);
		$this->done((int) $ev['event_id'], $tid);
		$this->stats['created']++;
		Log::info("ticket " . ($r['ticket_ref'] ?? $tid) . ' ' . (!empty($r['duplicate']) ? 'già aperto (' . ($r['reason'] ?? 'duplicato') . ')' : 'aperto') . " per l'avviso {$a['id']} ({$a['rule_id']})", self::LOG);
		return true;
	}

	private function put(array $ev, array $a, int $did, array $body, bool $closing = false): bool
	{
		if (Missing::has('PUT tickets')) {
			return false;
		}
		$tid = (int) $a['dolibarr_ticket_id'];
		$body['portal_event_id'] = (int) $ev['event_id'];
		try {
			$r = $this->client->put("webcams/$did/tickets/$tid", $body);
		} catch (DolibarrException $e) {
			if ($e->status === 409) {
				// già chiuso o annullato in Dolibarr: non si riprova
				Db::exec('UPDATE alerts SET ticket_closed_by_operator = IF(status = \'open\', 1, ticket_closed_by_operator), ticket_status = COALESCE(ticket_status, 8) WHERE id = ?', [$a['id']]);
				$this->done((int) $ev['event_id'], $tid);
				$this->stats['skipped']++;
				return true;
			}
			if ($e->status === 403) {
				// ticket non aperto dal portal (o non più collegato alla webcam): non si tocca
				Log::warning("ticket $tid dell'avviso {$a['id']} rifiutato da Dolibarr (403): non è un ticket del portal, non si aggiorna", self::LOG);
				Db::exec('UPDATE alerts SET ticket_closed_by_operator = IF(status = \'open\', 1, ticket_closed_by_operator) WHERE id = ?', [$a['id']]);
				$this->done((int) $ev['event_id'], $tid);
				$this->stats['skipped']++;
				return true;
			}
			if ($e->endpointMissing()) {
				$this->missing('PUT tickets', 'PUT webcams/{id}/tickets/{ticket_id} non disponibile (modulo webcams < 1.3): messaggi e chiusure dei ticket in coda');
				return false;
			}
			throw $e;
		}
		$status = is_array($r) && isset($r['status']) ? (int) $r['status'] : null;
		Db::exec('UPDATE alerts SET ticket_status = COALESCE(?, ticket_status), dolibarr_synced_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $a['id']]);
		$this->done((int) $ev['event_id'], $tid);
		if ($closing) {
			$this->stats['closed']++;
			if (is_array($r) && isset($r['close_refused'])) {
				Log::info("ticket $tid non chiuso: qualcuno ci sta lavorando ({$r['close_refused']})", self::LOG);
			}
		} else {
			$this->stats['messages']++;
		}
		return true;
	}

	/** Stato dei ticket degli avvisi aperti (GET /tickets/{id}), al più una volta l'ora per avviso. */
	private function poll(int $now): void
	{
		$rows = Db::all(
			"SELECT * FROM alerts WHERE status = 'open' AND dolibarr_ticket_id IS NOT NULL AND ticket_closed_by_operator = 0
			   AND (dolibarr_synced_at IS NULL OR dolibarr_synced_at < ?) LIMIT 50",
			[Db::dt(time() - 3600)]
		);
		foreach ($rows as $a) {
			try {
				$t = $this->client->get('tickets/' . (int) $a['dolibarr_ticket_id']);
			} catch (DolibarrException $e) {
				if ($e->status === 404 || $e->status === 403) {
					Db::exec('UPDATE alerts SET dolibarr_synced_at = UTC_TIMESTAMP() WHERE id = ?', [$a['id']]);
					continue;
				}
				throw $e;
			}
			$st = is_array($t) ? ($t['fk_statut'] ?? ($t['status'] ?? null)) : null;
			$st = is_numeric($st) ? (int) $st : null;
			$closed = $st !== null && in_array($st, [8, 9], true);
			Db::exec(
				'UPDATE alerts SET ticket_status = COALESCE(?, ticket_status), ticket_closed_by_operator = ?, dolibarr_synced_at = UTC_TIMESTAMP() WHERE id = ?',
				[$st, $closed ? 1 : 0, $a['id']]
			);
			if ($closed) {
				Log::info("ticket {$a['dolibarr_ticket_id']} chiuso in Dolibarr con l'avviso {$a['id']} ancora aperto: nessun nuovo ticket finché l'avviso non si chiude", self::LOG);
			}
			$this->stats['polled']++;
		}
	}

	private function done(int $eventId, ?int $ticketId): void
	{
		Db::exec('UPDATE events SET dolibarr_synced_at = UTC_TIMESTAMP(), dolibarr_ticket_id = COALESCE(?, dolibarr_ticket_id) WHERE id = ?', [$ticketId, $eventId]);
	}

	private function missing(string $key, string $msg): void
	{
		$this->stats['missing']++;
		if (Missing::note($key, 6)) {
			Log::warning($msg, self::LOG);
		}
	}

	private function local(array $w, int $ts, string $fmt = 'd/m/Y H:i'): string
	{
		return (new \DateTimeImmutable('@' . $ts))->setTimezone(ServiceSettings::tz($w))->format($fmt);
	}

	private function alertUrl(array $a): string
	{
		return ($this->baseUrl !== '' ? $this->baseUrl : '') . '/admin/webcams/' . (int) $a['webcam_id'] . '/alerts/' . (int) $a['id'];
	}

	/** Testo del ticket (diagnosi-v1.md, "Testo del ticket"): testo semplice in italiano. */
	public function message(array $a, array $w, ?array $ev = null): string
	{
		$rule = Registry::get((string) $a['rule_id']);
		$d = Alerts::details($a);
		$tz = (string) $w['timezone'];
		$svc = ServiceSettings::get((int) $w['id']);
		$out = 'Regola: ' . $a['rule_id'] . ' (' . mb_strtolower($rule ? $rule->label() : $a['title']) . '), gravità: ' . (Alerts::SEVERITY_LABEL[$a['severity']] ?? $a['severity']) . "\n";
		$out .= 'Dal: ' . $this->local($w, (int) Db::unix($a['opened_at'])) . " (ora della webcam, $tz)\n";
		if ((int) $a['reopen_count'] > 0) {
			$out .= 'Riaperto ' . (int) $a['reopen_count'] . " volte (stessa condizione entro 24 ore dalla chiusura)\n";
		}
		if (!empty($d['lines'])) {
			$out .= "\nCosa è stato misurato:\n";
			foreach ((array) $d['lines'] as $l) {
				$out .= '- ' . $l . "\n";
			}
		}
		if (!empty($d['note'])) {
			$out .= "\nNota: " . $d['note'] . "\n";
		}
		if (!empty($d['check'])) {
			$out .= "\nCosa controllare: " . $d['check'] . "\n";
		}
		if ($a['due_at'] !== null) {
			$out .= 'Intervento entro: ' . $this->local($w, (int) Db::unix($a['due_at']), 'd/m/Y') . ' (impegno di disponibilità, ' . (int) $svc['sla_response_days'] . " giorni)\n";
		}
		$out .= "\nPortal: " . $this->alertUrl($a) . "\n";
		$photo = $d['values']['photo_id'] ?? ($d['values']['last_photo_id'] ?? $w['last_photo_id']);
		if ($photo) {
			$out .= 'Ultima foto: ' . $this->baseUrl . '/admin/webcams/' . (int) $w['id'] . '/photos/' . (int) $photo . "\n";
		}
		[$term, $luci] = self::rttys($w, $svc);
		if ($term !== null) {
			$out .= "Terminale (rttys): $term\n";
		}
		if ($luci !== null) {
			$out .= "LuCI (rttys): $luci\n";
		}
		return rtrim($out);
	}

	/**
	 * Link di rttys: quelli di Dolibarr (GET webcams/{id}/portal), altrimenti
	 * rttys_url di config.php con il ref Dolibarr della webcam.
	 *
	 * @return array{0:?string,1:?string}
	 */
	public static function rttys(array $w, array $svc): array
	{
		$term = $svc['rttys_terminal_url'] ?: null;
		$luci = $svc['rttys_luci_url'] ?: null;
		$base = rtrim((string) Config::get()->value('rttys_url', ''), '/');
		$ref = (string) ($w['dolibarr_ref'] ?? '');
		if ($ref !== '' && $base !== '') {
			$term ??= $base . '/api/connect/' . rawurlencode($ref);
			$luci ??= $base . '/api/web/' . rawurlencode($ref) . '/http/127.0.0.1%3A80%2F';
		}
		return [$term, $luci];
	}
}
