<?php

declare(strict_types=1);

namespace WebcamPortal\Rules;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Logs\LogData;
use WebcamPortal\Service\Commands;
use WebcamPortal\Service\ServiceSettings;

/**
 * Motore delle regole (bin/rules.php, ogni 5 minuti): per ogni webcam in
 * servizio, o con avvisi aperti, valuta le regole, aggiorna rule_state e
 * apre/aggrava/chiude gli avvisi con le regole comuni di diagnosi-v1.md
 * (dati insufficienti, manutenzione, silenzio, isteresi, riapertura,
 * gravità solo crescente, scadenze dell'impegno di disponibilità).
 */
final class Engine
{
	/** @var array<string,int> */
	public array $stats = ['webcams' => 0, 'evaluated' => 0, 'opened' => 0, 'updated' => 0, 'closed' => 0, 'errors' => 0];

	/** @param list<string>|null $only regole da valutare (null = tutte) */
	public function __construct(private int $now, private ?array $only = null, private ?int $webcamId = null)
	{
	}

	public function run(): array
	{
		Commands::expire(null, $this->now);
		$this->closeRetired();
		$sql = "SELECT * FROM webcams w WHERE (w.status = 1 OR EXISTS (SELECT 1 FROM alerts a WHERE a.webcam_id = w.id AND a.status = 'open'))";
		$p = [];
		if ($this->webcamId !== null) {
			$sql .= ' AND w.id = ?';
			$p[] = $this->webcamId;
		}
		$shared = ['logs' => LogData::load($this->now)];
		foreach (Db::all($sql . ' ORDER BY w.id', $p) as $w) {
			$this->stats['webcams']++;
			try {
				$this->webcam($w, $shared);
			} catch (\Throwable $e) {
				$this->stats['errors']++;
				Log::error("regole webcam {$w['id']}: " . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), 'rules');
			}
		}
		return $this->stats;
	}

	/** @param array<string,mixed> $w */
	private function webcam(array $w, array $shared): void
	{
		$wid = (int) $w['id'];
		$service = ServiceSettings::get($wid);
		$open = [];
		foreach (Db::all("SELECT * FROM alerts WHERE webcam_id = ? AND status = 'open'", [$wid]) as $a) {
			$open[$a['rule_id']] = $a;
		}
		$conf = [];
		foreach (Db::all('SELECT * FROM rule_config WHERE webcam_id = ?', [$wid]) as $r) {
			$conf[$r['rule_id']] = $r;
		}
		$states = [];
		foreach (Db::all('SELECT * FROM rule_state WHERE webcam_id = ?', [$wid]) as $r) {
			$states[$r['rule_id']] = $r;
		}
		$globalParams = (array) Config::get()->value('rules.params', []);
		$globalOff = (array) Config::get()->value('rules.disabled', []);

		// telemetry.stalled prima di camera.stalled (dipendenza)
		foreach (Registry::all() as $rule) {
			$rid = $rule->id();
			if ($this->only !== null && !in_array($rid, $this->only, true) && !in_array($rule->group(), $this->only, true)) {
				continue;
			}
			$c = new Context($w, $service, $this->now);
			$c->shared = $shared;
			$c->openAlerts = $open;
			$c->alert = $open[$rid] ?? null;
			$c->state = $states[$rid] ?? null;
			$c->params = array_merge(
				$rule->defaults(),
				(array) ($globalParams[$rid] ?? []),
				(array) (json_decode((string) ($conf[$rid]['params'] ?? ''), true) ?: [])
			);
			$enabled = !in_array($rid, $globalOff, true) && (int) ($conf[$rid]['enabled'] ?? 1) === 1;
			if (!$enabled) {
				$this->saveState($c, $rid, 'disabled', []);
				continue;
			}
			$this->stats['evaluated']++;
			$o = $rule->evaluate($c);
			$this->apply($c, $rule, $o);
			$a = Alerts::openFor($wid, $rid);
			if ($a !== null) {
				$open[$rid] = $a;
				$c->alert = $a;
				$rule->whileOpen($c, $o);
			} else {
				unset($open[$rid]);
			}
		}
		$this->deadlines($w, $service);
	}

	private function apply(Context $c, Rule $rule, Outcome $o): void
	{
		$rid = $rule->id();
		$alert = $c->alert;
		$st = $c->state;
		$prevState = $st['state'] ?? 'unknown';
		$since = isset($st['state_since']) ? (int) Db::unix($st['state_since']) : $this->now;
		$muted = isset($st['muted_until']) && $st['muted_until'] !== null && (int) Db::unix($st['muted_until']) > $this->now;
		$values = $o->values;

		switch ($o->state) {
			case Outcome::UNKNOWN:
				$this->saveState($c, $rid, 'unknown', $values, $o->dataAt);
				if ($alert !== null && $prevState === 'unknown' && $this->now - $since >= 86400) {
					$d = Alerts::details($alert);
					if (($d['note'] ?? '') !== 'dati insufficienti') {
						$d['note'] = 'dati insufficienti';
						Db::exec('UPDATE alerts SET details = ? WHERE id = ?', [Alerts::json($d), $alert['id']]);
					}
				}
				return;

			case Outcome::ALARM:
				if ($alert !== null) {
					if (Alerts::escalate($c, $rule, $alert, $o)) {
						$this->stats['updated']++;
					} else {
						Alerts::refresh($alert, $o);
					}
					$this->saveState($c, $rid, 'alarm', $values, $o->dataAt, (int) $alert['id']);
					return;
				}
				$blocked = $muted ? 'silenziata' : (($c->inMaintenance() && !($rule->evaluateInMaintenance() && (int) $c->webcam['status'] === 1)) ? 'manutenzione' : null);
				if ($blocked !== null) {
					$this->saveState($c, $rid, 'pending', $values + ['blocked' => $blocked], $o->dataAt);
					return;
				}
				$id = Alerts::open($c, $rule, $o);
				$this->stats['opened']++;
				$this->saveState($c, $rid, 'alarm', $values, $o->dataAt, $id);
				$c->alert = Alerts::openFor($c->id(), $rid);
				try {
					$rule->afterOpen($c, $id);
				} catch (\Throwable $e) {
					Log::warning("regola $rid webcam {$c->id()}: azione dopo l'apertura non eseguita: " . $e->getMessage(), 'rules');
				}
				return;

			case Outcome::OK:
				if ($alert === null) {
					$this->saveState($c, $rid, 'ok', $values, $o->dataAt);
					return;
				}
				if ($o->clearFor > 0) {
					$recSince = $prevState === 'recovering' ? $since : $this->now;
					if ($this->now - $recSince < $o->clearFor) {
						$this->saveState($c, $rid, 'recovering', $values, $o->dataAt, (int) $alert['id']);
						return;
					}
				}
				Alerts::close($c, $rule, $alert, $o->closeReason, $o->closeNote);
				$this->stats['closed']++;
				$this->saveState($c, $rid, 'ok', $values, $o->dataAt, (int) $alert['id']);
				return;

			default: // HOLD
				$state = $alert !== null ? 'alarm' : ($o->pending ? 'pending' : 'ok');
				if ($alert !== null) {
					Alerts::refresh($alert, $o);
				}
				$this->saveState($c, $rid, $state, $values, $o->dataAt, $alert !== null ? (int) $alert['id'] : null);
		}
	}

	private function saveState(Context $c, string $rid, string $state, array $values, ?int $dataAt = null, ?int $alertId = null): void
	{
		$st = $c->state;
		$same = $st !== null && $st['state'] === $state;
		$values = array_filter($values, static fn ($v) => $v !== null);
		Db::exec(
			'INSERT INTO rule_state (webcam_id, rule_id, state, state_since, streak, last_eval_at, last_data_at, last_value, alert_id)
			 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE state = VALUES(state), state_since = IF(state = VALUES(state), state_since, VALUES(state_since)),
			   streak = IF(state = VALUES(state), LEAST(streak + 1, 65535), 1), last_eval_at = VALUES(last_eval_at),
			   last_data_at = COALESCE(VALUES(last_data_at), last_data_at), last_value = VALUES(last_value),
			   alert_id = COALESCE(VALUES(alert_id), alert_id)',
			[
				$c->id(), $rid, $state, $same ? $st['state_since'] : Db::dt($this->now), Db::dt($this->now),
				$dataAt === null ? null : Db::dt($dataAt), $values === [] ? null : Alerts::json($values), $alertId,
			]
		);
		// MySQL valuta le assegnazioni di ON DUPLICATE KEY da sinistra: state_since
		// va confrontato con lo stato vecchio, quindi si corregge qui.
		if (!$same) {
			Db::exec('UPDATE rule_state SET state_since = ?, streak = 1 WHERE webcam_id = ? AND rule_id = ?', [Db::dt($this->now), $c->id(), $rid]);
		}
		$c->state = ['state' => $state, 'state_since' => $same ? $st['state_since'] : Db::dt($this->now)] + ($st ?? []);
	}

	/**
	 * Impegno di disponibilità: 24 ore prima della scadenza un messaggio nel
	 * ticket e gravità critical; scadenza superata: sla_breached = 1.
	 */
	private function deadlines(array $w, array $service): void
	{
		$rows = Db::all("SELECT * FROM alerts WHERE webcam_id = ? AND status = 'open' AND due_at IS NOT NULL", [$w['id']]);
		foreach ($rows as $a) {
			$due = (int) Db::unix($a['due_at']);
			$rule = Registry::get((string) $a['rule_id']);
			if ($rule === null) {
				continue;
			}
			$c = new Context($w, $service, $this->now);
			$d = Alerts::details($a);
			if ($this->now >= $due && (int) $a['sla_breached'] === 0) {
				Db::exec('UPDATE alerts SET sla_breached = 1 WHERE id = ?', [$a['id']]);
				Alerts::note($c, $rule, $a, 'critical', 'Scadenza dell\'intervento superata (' . $c->local($due, 'd/m/Y') . ')', ['reason' => 'sla_breached']);
				Log::warning("avviso {$a['id']}: scadenza dell'impegno di disponibilità superata", 'rules');
			} elseif ($this->now >= $due - 86400 && $this->now < $due && empty($d['due_soon'])) {
				$d['due_soon'] = Db::dt($this->now);
				Db::exec("UPDATE alerts SET details = ?, severity = 'critical', dolibarr_synced_at = NULL WHERE id = ?", [Alerts::json($d), $a['id']]);
				Alerts::note($c, $rule, $a, 'critical', 'Intervento entro il ' . $c->local($due, 'd/m/Y') . ': mancano meno di 24 ore', ['reason' => 'due_soon', 'from' => $a['severity']]);
				$this->stats['updated']++;
			}
		}
	}

	private function closeRetired(): void
	{
		foreach (Db::all("SELECT a.id FROM alerts a JOIN webcams w ON w.id = a.webcam_id WHERE a.status = 'open' AND w.status = 9") as $a) {
			Alerts::closeById((int) $a['id'], 'webcam_retired', 'webcam ritirata', null, $this->now);
			$this->stats['closed']++;
		}
	}
}
