<?php

declare(strict_types=1);

namespace WebcamPortal\Dolibarr;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;

/**
 * Sincronizzazione verso il modulo Dolibarr webcams (api-dolibarr-v2.md):
 * per ogni webcam (trovata per MAC) invia URL di current.jpg e Windy
 * (PUT webcams/{id}/portal), contatori della reflex e sintesi Victron
 * (POST webcams/{id}/counters) ed eventi con gravità >= warning
 * (POST webcams/{id}/events).
 *
 * Con il modulo 1.1 gli endpoint v2 non esistono: si scrive nel log e si
 * prosegue senza errori.
 */
final class Sync
{
	private const CHARGE = [0 => 'off', 2 => 'fault', 3 => 'bulk', 4 => 'absorption', 5 => 'float', 7 => 'equalize', 245 => 'starting', 252 => 'external'];
	private const LOG = 'sync-dolibarr';

	/** Endpoint v2 trovati assenti in questo giro: non si riprovano. */
	private array $missing = [];
	public array $stats = ['webcams' => 0, 'linked' => 0, 'portal' => 0, 'counters' => 0, 'events' => 0, 'errors' => 0, 'missing_endpoints' => 0];

	public function __construct(private Client $client, private string $publicBaseUrl)
	{
	}

	public static function fromConfig(): ?Sync
	{
		$c = Config::get();
		$url = (string) $c->value('dolibarr.url', '');
		$key = (string) $c->value('dolibarr.api_key', '');
		if ($url === '' || $key === '') {
			return null;
		}
		return new Sync(
			new Client($url, $key, (int) $c->value('dolibarr.timeout', 20), (bool) $c->value('dolibarr.verify_tls', true)),
			rtrim((string) $c->value('public_base_url', ''), '/')
		);
	}

	/** @return int codice di uscita: 0 ok (anche con endpoint v2 assenti), 1 errori */
	public function run(?string $onlyMac = null, bool $dryRun = false): int
	{
		$sql = 'SELECT * FROM webcams WHERE status <> 9';
		$params = [];
		if ($onlyMac !== null) {
			$sql .= ' AND mac = ?';
			$params[] = $onlyMac;
		}
		foreach (Db::all($sql . ' ORDER BY id', $params) as $w) {
			$this->stats['webcams']++;
			try {
				$this->syncWebcam($w, $dryRun);
			} catch (DolibarrException $e) {
				$this->stats['errors']++;
				Log::error("webcam {$w['id']} ({$w['mac']}): " . $e->getMessage(), self::LOG);
				if ($e->status === 0 || $e->status === 401) {
					Log::error('Dolibarr non raggiungibile o chiave API rifiutata: giro interrotto', self::LOG);
					break;
				}
			}
		}
		Log::info('fine giro: ' . json_encode($this->stats), self::LOG);
		return $this->stats['errors'] > 0 ? 1 : 0;
	}

	private function syncWebcam(array $w, bool $dryRun): void
	{
		$wid = (int) $w['id'];
		$did = $w['dolibarr_id'] !== null ? (int) $w['dolibarr_id'] : $this->resolve($w);
		if ($did === null) {
			return;
		}
		$this->stats['linked']++;

		// 1. pubblicazione
		$portal = [
			'portal_slug' => $w['slug'],
			'url_current' => $this->publicBaseUrl !== '' ? $this->publicBaseUrl . '/cam/' . $w['slug'] . '/current.jpg' : null,
			'windy_published' => (int) $w['windy_published'],
			'windy_id' => $w['windy_id'],
		];
		if ($this->call('PUT', "webcams/$did/portal", $portal, $dryRun, $w) !== null) {
			$this->stats['portal']++;
		}

		// 2. contatori e Victron
		$body = $this->countersBody($w);
		if ($body !== null && $this->call('POST', "webcams/$did/counters", $body, $dryRun, $w) !== null) {
			$this->stats['counters']++;
			if (!$dryRun) {
				Db::exec('UPDATE camera_counters SET dolibarr_synced_at = UTC_TIMESTAMP() WHERE webcam_id = ? AND dolibarr_synced_at IS NULL', [$wid]);
			}
		}

		// 3. eventi importanti degli ultimi 7 giorni non ancora inviati
		$events = Db::all(
			"SELECT * FROM events WHERE webcam_id = ? AND dolibarr_synced_at IS NULL
			   AND severity IN ('warning','error','critical') AND ts >= ? ORDER BY ts LIMIT 50",
			[$wid, Db::dt(time() - 7 * 86400)]
		);
		foreach ($events as $e) {
			$res = $this->call('POST', "webcams/$did/events", [
				'type' => $e['type'],
				'severity' => $e['severity'],
				'time' => Db::unix($e['ts']),
				'label' => mb_substr((string) ($e['message'] ?: $e['type']), 0, 120),
				'note' => $e['message'],
				'portal_event_id' => (int) $e['id'],
				'url' => $this->publicBaseUrl !== '' ? $this->publicBaseUrl . "/admin/webcams/$wid/events/{$e['id']}" : null,
			], $dryRun, $w);
			if ($res === null) {
				break;
			}
			$this->stats['events']++;
			if (!$dryRun) {
				Db::exec('UPDATE events SET dolibarr_synced_at = UTC_TIMESTAMP(), dolibarr_actioncomm_id = ? WHERE id = ?', [
					is_array($res) && isset($res['actioncomm_id']) ? (int) $res['actioncomm_id'] : null, $e['id'],
				]);
			}
		}
	}

	/** Trova la scheda Dolibarr per MAC e salva dolibarr_id. */
	private function resolve(array $w): ?int
	{
		$obj = null;
		if (!isset($this->missing['GET webcams/bymac'])) {
			try {
				// il WAF di Dolibarr rifiuta ':' nel percorso: MAC senza separatori
				$obj = $this->client->get('webcams/bymac/' . str_replace(':', '', (string) $w['mac']));
			} catch (DolibarrException $e) {
				if ($e->endpointMissing()) {
					$this->noteMissing('GET webcams/bymac', $w);
				} elseif ($e->status !== 404) {
					throw $e;
				}
			}
		}
		if (isset($this->missing['GET webcams/bymac'])) {
			// modulo 1.1: si cerca con l'elenco standard GET webcams e sqlfilters
			try {
				$list = $this->client->get('webcams', ['sqlfilters' => "(t.mac:=:'" . $w['mac'] . "')", 'limit' => 1]);
				$obj = is_array($list) && isset($list[0]) && is_array($list[0]) ? $list[0] : null;
			} catch (DolibarrException $e) {
				if ($e->status !== 404) {
					throw $e;
				}
			}
		}
		if (is_array($obj) && isset($obj['mac']) && strtolower((string) $obj['mac']) !== $w['mac']) {
			$obj = null; // filtro ignorato o MAC diverso: meglio non collegare
		}
		if (!is_array($obj) || !isset($obj['id'])) {
			Log::warning("webcam {$w['id']} ({$w['mac']}) non trovata in Dolibarr: arriverà con il primo report del router", self::LOG);
			return null;
		}
		$id = (int) $obj['id'];
		$ref = isset($obj['ref']) && is_string($obj['ref']) ? substr($obj['ref'], 0, 128) : null;
		try {
			Db::exec('UPDATE webcams SET dolibarr_id = ?, dolibarr_ref = ? WHERE id = ?', [$id, $ref, $w['id']]);
		} catch (\PDOException $e) {
			Log::error("dolibarr_id $id già collegato a un'altra webcam (MAC {$w['mac']})", self::LOG);
			return null;
		}
		Log::info("webcam {$w['id']} ({$w['mac']}) collegata a Dolibarr id=$id ref=$ref", self::LOG);
		return $id;
	}

	/** Corpo di POST webcams/{id}/counters, o null se non c'è niente da mandare. */
	public function countersBody(array $w): ?array
	{
		$wid = (int) $w['id'];
		$cc = Db::one(
			'SELECT cc.*, c.model, c.serial FROM camera_counters cc LEFT JOIN cameras c ON c.id = cc.camera_id
			  WHERE cc.webcam_id = ? ORDER BY cc.ts DESC, cc.id DESC LIMIT 1',
			[$wid]
		);
		$since = Db::dt(time() - 86400);
		$last = Db::one(
			'SELECT battery_v, soc_pct, charge_state, victron_error, ts FROM measurements
			  WHERE webcam_id = ? AND ts >= ? AND battery_v IS NOT NULL ORDER BY ts DESC LIMIT 1',
			[$wid, $since]
		);
		$body = ['time' => time()];
		if ($cc !== null) {
			$body['camera'] = [
				'model' => $cc['model'] ?? $w['camera_model'],
				'serial' => $cc['serial'],
				'shutter_count' => $cc['shutter_count'] === null ? null : (int) $cc['shutter_count'],
				'shots_total' => $cc['shots_total'] === null ? null : (int) $cc['shots_total'],
				'power_on_hours' => $cc['power_on_hours'] === null ? null : (float) $cc['power_on_hours'],
				'power_cycles' => $cc['power_cycles'] === null ? null : (int) $cc['power_cycles'],
			];
		}
		if ($last !== null) {
			$min = Db::scalar('SELECT MIN(battery_v) FROM measurements WHERE webcam_id = ? AND ts >= ?', [$wid, $since]);
			$body['power'] = [
				'battery_v' => (float) $last['battery_v'],
				'battery_v_min24' => $min === null ? null : (float) $min,
				'soc_pct' => $last['soc_pct'] === null ? null : (float) $last['soc_pct'],
				'charge_state' => $last['charge_state'] === null ? null : (self::CHARGE[(int) $last['charge_state']] ?? (string) $last['charge_state']),
				'yield_yesterday_wh' => $this->yieldYesterday($w),
				'error' => $last['victron_error'] === null ? null : (int) $last['victron_error'],
			];
		}
		return count($body) > 1 ? $body : null;
	}

	/** Massimo di yield_today_wh di ieri (giorno locale della webcam). */
	private function yieldYesterday(array $w): ?int
	{
		try {
			$tz = new \DateTimeZone((string) $w['timezone']);
		} catch (\Throwable $e) {
			$tz = new \DateTimeZone('UTC');
		}
		$today = (new \DateTimeImmutable('now', $tz))->setTime(0, 0);
		$from = $today->modify('-1 day')->getTimestamp();
		$to = $today->getTimestamp();
		$v = Db::scalar(
			'SELECT MAX(yield_today_wh) FROM measurements WHERE webcam_id = ? AND ts >= ? AND ts < ?',
			[$w['id'], Db::dt($from), Db::dt($to)]
		);
		return $v === null ? null : (int) $v;
	}

	/**
	 * Chiamata a un endpoint v2. Restituisce la risposta, o null se
	 * l'endpoint non esiste (già annotato nel log).
	 */
	private function call(string $method, string $path, array $body, bool $dryRun, array $w): mixed
	{
		$key = $method . ' webcams/{id}/' . basename($path);
		if (isset($this->missing[$key])) {
			return null;
		}
		if ($dryRun) {
			Log::info("[prova] $method $path " . json_encode($body, JSON_UNESCAPED_SLASHES), self::LOG);
			return [];
		}
		try {
			return $this->client->request($method, $path, $body) ?? [];
		} catch (DolibarrException $e) {
			if ($e->status === 404 && stripos($e->apiMessage(), 'webcam not found') !== false) {
				Log::warning("webcam {$w['id']}: la scheda Dolibarr {$w['dolibarr_id']} non esiste più, la ricollego al prossimo giro", self::LOG);
				Db::exec('UPDATE webcams SET dolibarr_id = NULL, dolibarr_ref = NULL WHERE id = ?', [$w['id']]);
				return null;
			}
			if ($e->endpointMissing()) {
				$this->noteMissing($key, $w);
				return null;
			}
			throw $e;
		}
	}

	private function noteMissing(string $key, array $w): void
	{
		if (isset($this->missing[$key])) {
			return;
		}
		$this->missing[$key] = true;
		$this->stats['missing_endpoints']++;
		Log::warning("$key non disponibile in Dolibarr (modulo webcams < 1.2, contratto v2 non ancora installato): salto", self::LOG);
	}
}
