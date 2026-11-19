<?php

declare(strict_types=1);

namespace WebcamPortal\Admin;

use WebcamPortal\Db;
use WebcamPortal\Http\Response;
use WebcamPortal\Log;
use WebcamPortal\Photo\Metrics;
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Rules\Registry;
use WebcamPortal\Service\Commands;
use WebcamPortal\Service\PhotoStore;
use WebcamPortal\Service\ServiceSettings;
use WebcamPortal\Web\PublicController;

/**
 * Pagine della diagnosi nel pannello: avvisi, stato delle regole, metriche
 * foto e riferimenti, comandi al router, disponibilità e impostazioni di
 * servizio. Usato da AdminController.
 */
trait DiagnosisPages
{
	// ------------------------------------------------------------ avvisi

	private function alertsIndex(): void
	{
		$status = in_array($_GET['stato'] ?? 'open', ['open', 'closed', 'all'], true) ? (string) ($_GET['stato'] ?? 'open') : 'open';
		$wid = (int) ($_GET['webcam'] ?? 0);
		$rule = (string) ($_GET['regola'] ?? '');
		$sql = 'SELECT a.*, w.name, w.slug, w.timezone FROM alerts a JOIN webcams w ON w.id = a.webcam_id WHERE 1 = 1';
		$p = [];
		if ($status !== 'all') {
			$sql .= ' AND a.status = ?';
			$p[] = $status;
		}
		if ($wid > 0) {
			$sql .= ' AND a.webcam_id = ?';
			$p[] = $wid;
		}
		if ($rule !== '' && Registry::get($rule) !== null) {
			$sql .= ' AND a.rule_id = ?';
			$p[] = $rule;
		}
		$sql .= $status === 'closed' ? ' ORDER BY a.closed_at DESC' : " ORDER BY a.status = 'open' DESC, FIELD(a.severity, 'critical', 'error', 'warning', 'notice'), a.opened_at DESC";
		$this->page('alerts', 'Avvisi', [
			'alerts' => Db::all($sql . ' LIMIT 300', $p),
			'filter' => ['stato' => $status, 'webcam' => $wid, 'regola' => $rule],
			'webcams' => Db::all('SELECT id, name, slug FROM webcams ORDER BY name, slug'),
			'rules' => Registry::all(),
		]);
	}

	private function alertShow(array $w, int $aid): void
	{
		$a = Db::one('SELECT a.*, u.email AS ack_email FROM alerts a LEFT JOIN users u ON u.id = a.acknowledged_by WHERE a.id = ? AND a.webcam_id = ?', [$aid, $w['id']]);
		if ($a === null) {
			Response::notFound();
			return;
		}
		$this->page('alert', (string) $a['title'], [
			'w' => $w,
			'a' => $a,
			'details' => Alerts::details($a),
			'rule' => Registry::get((string) $a['rule_id']),
			'state' => Db::one('SELECT * FROM rule_state WHERE webcam_id = ? AND rule_id = ?', [$w['id'], $a['rule_id']]),
			'events' => Db::all('SELECT * FROM events WHERE alert_id = ? ORDER BY ts, id', [$aid]),
			'commands' => Db::all('SELECT * FROM commands WHERE alert_id = ? ORDER BY id DESC LIMIT 20', [$aid]),
			'rttys' => \WebcamPortal\Dolibarr\TicketSync::rttys($w, ServiceSettings::get((int) $w['id'])),
		]);
	}

	private function alertPost(array $w, int $aid): void
	{
		$wid = (int) $w['id'];
		$a = Db::one('SELECT * FROM alerts WHERE id = ? AND webcam_id = ?', [$aid, $wid]);
		if ($a === null) {
			Response::notFound();
			return;
		}
		$uid = (int) $this->user['id'];
		$do = (string) ($_POST['do'] ?? '');
		if ($do === 'ack' && $a['status'] === 'open') {
			Db::exec('UPDATE alerts SET acknowledged_by = ?, acknowledged_at = UTC_TIMESTAMP() WHERE id = ?', [$uid, $aid]);
			$this->flash('ok', 'Avviso preso in carico.');
		} elseif ($do === 'close' && $a['status'] === 'open') {
			$note = trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 200));
			Alerts::closeById($aid, 'operator', $note === '' ? 'chiuso dall\'operatore ' . $this->user['email'] : $note, $uid);
			$this->flash('ok', 'Avviso chiuso.');
		} elseif ($do === 'mute') {
			$days = max(0, min(90, (int) ($_POST['days'] ?? 7)));
			$until = $days > 0 ? Db::dt(time() + $days * 86400) : null;
			Db::exec(
				"INSERT INTO rule_state (webcam_id, rule_id, state, state_since, muted_until, muted_by) VALUES (?, ?, 'unknown', UTC_TIMESTAMP(), ?, ?)
				 ON DUPLICATE KEY UPDATE muted_until = VALUES(muted_until), muted_by = VALUES(muted_by)",
				[$wid, $a['rule_id'], $until, $days > 0 ? $uid : null]
			);
			Log::info("regola {$a['rule_id']} della webcam $wid " . ($days > 0 ? "silenziata per $days giorni" : 'riattivata') . " da {$this->user['email']}");
			$this->flash('ok', $days > 0 ? "Regola silenziata per $days giorni: non apre nuovi avvisi." : 'Regola riattivata.');
		} else {
			$this->flash('error', 'Operazione non valida.');
		}
		Response::redirect($this->req->url("/admin/webcams/$wid/alerts/$aid"));
	}

	// ------------------------------------------------------------ dati della scheda

	/** @return array<string,mixed> variabili per la scheda webcam */
	private function diagnosisVars(array $w): array
	{
		$id = (int) $w['id'];
		$tz = (string) $w['timezone'];
		$to = time();
		$from = $to - 7 * 86400;
		$states = [];
		foreach (Db::all('SELECT * FROM rule_state WHERE webcam_id = ?', [$id]) as $s) {
			$states[$s['rule_id']] = $s;
		}
		$m = Db::all(
			"SELECT taken_at, sharpness_tiles, blur_ratio, brightness FROM photo_metrics
			  WHERE webcam_id = ? AND taken_at >= ? AND classification <> 'skipped' ORDER BY taken_at",
			[$id, Db::dt($from)]
		);
		$pts = static fn (string $col): array => array_map(
			static fn ($r) => [(int) Db::unix($r['taken_at']), $r[$col] === null ? null : (float) $r[$col]],
			$m
		);
		$chart = SvgChart::render('Nitidezza delle foto (mediana dei riquadri), ultimi 7 giorni', '', [
			['label' => 'Nitidezza', 'short' => '', 'class' => 's1', 'points' => $pts('sharpness_tiles')],
		], $from, $to, $tz, 0, ['gap' => 3 * 3600, 'empty' => 'Nessuna foto analizzata negli ultimi 7 giorni.']);
		$svc = ServiceSettings::get($id);
		$month = (new \DateTimeImmutable('now', ServiceSettings::tz($w)))->format('Y-m');
		$target = (float) $svc['sla_target_pct'];
		return [
			'rules' => Registry::all(),
			'states' => $states,
			'openAlerts' => Db::all("SELECT * FROM alerts WHERE webcam_id = ? AND status = 'open' ORDER BY opened_at DESC", [$id]),
			'closedAlerts' => Db::all("SELECT * FROM alerts WHERE webcam_id = ? AND status = 'closed' ORDER BY closed_at DESC LIMIT 10", [$id]),
			'sharpChart' => $chart,
			'lastMetrics' => Db::all('SELECT pm.*, p.storage_path FROM photo_metrics pm JOIN photos p ON p.id = pm.photo_id WHERE pm.webcam_id = ? ORDER BY pm.taken_at DESC LIMIT 12', [$id]),
			'references' => Db::all('SELECT * FROM reference_photos WHERE webcam_id = ? AND valid_to IS NULL ORDER BY band', [$id]),
			'commands' => Db::all('SELECT c.*, u.email FROM commands c LEFT JOIN users u ON u.id = c.user_id WHERE c.webcam_id = ? ORDER BY c.id DESC LIMIT 15', [$id]),
			'svc' => $svc,
			'availMonth' => Db::one('SELECT * FROM availability_monthly WHERE webcam_id = ? AND month = ?', [$id, $month]),
			'availMonths' => Db::all('SELECT * FROM availability_monthly WHERE webcam_id = ? AND month <> ? ORDER BY month DESC LIMIT 12', [$id, $month]),
			'daysBelow' => Db::all(
				'SELECT * FROM availability_daily WHERE webcam_id = ? AND day >= ? AND pct IS NOT NULL AND pct < ? ORDER BY day',
				[$id, $month . '-01', $target]
			),
		];
	}

	// ------------------------------------------------------------ azioni dalla scheda

	private function commandPost(array $w): void
	{
		$wid = (int) $w['id'];
		$do = (string) ($_POST['do'] ?? '');
		try {
			if ($do === 'cancel') {
				$ok = Commands::cancel($wid, (int) ($_POST['command_id'] ?? 0));
				$this->flash($ok ? 'ok' : 'error', $ok ? 'Comando annullato.' : 'Il comando non si può più annullare.');
			} else {
				[$type, $params] = match ($do) {
					'capture' => ['capture.now', ['upload' => true]],
					'boost' => ['heater.boost', ['duration_s' => 1800]],
					'flush' => ['telemetry.flush', []],
					'heater_cancel' => ['heater.cancel', []],
					default => throw new \InvalidArgumentException('Comando sconosciuto'),
				};
				$cid = Commands::create($wid, $type, $params, [
					'by' => 'operator', 'user_id' => (int) $this->user['id'],
					'reason' => 'dal pannello (' . $this->user['email'] . ')',
				]);
				$this->flash('ok', Commands::LABELS[$type] . ": comando cmd-$cid in coda. Parte con la prossima richiesta del router.");
			}
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url("/admin/webcams/$wid#comandi"));
	}

	private function referencePost(array $w): void
	{
		$wid = (int) $w['id'];
		$uid = (int) $this->user['id'];
		$do = (string) ($_POST['do'] ?? '');
		try {
			if ($do === 'band') {
				$band = (int) ($_POST['band'] ?? -1);
				if ($band < 0 || $band > 23) {
					throw new \InvalidArgumentException('Fascia non valida');
				}
				Metrics::resetBand($wid, $band);
				$this->flash('ok', sprintf('Riferimento delle %02d:00 da ricalcolare: verrà scelto tra le prossime foto.', $band));
			} elseif ($do === 'use') {
				Metrics::setReference($wid, (int) ($_POST['photo_id'] ?? 0), 'operator', $uid);
				Metrics::closeAfterOperator($wid, ['glass.dirty'], $uid, 'nuovo riferimento scelto da ' . $this->user['email']);
				$this->flash('ok', 'Foto usata come riferimento della sua fascia.');
			} elseif ($do === 'framing') {
				Metrics::resetAll($wid, 'nuova inquadratura confermata da ' . $this->user['email']);
				Metrics::closeAfterOperator($wid, ['camera.moved', 'glass.dirty'], $uid, 'nuova inquadratura confermata da ' . $this->user['email']);
				$this->flash('ok', 'Nuova inquadratura confermata: i riferimenti verranno rifatti con le prossime foto.');
			} else {
				throw new \InvalidArgumentException('Operazione non valida');
			}
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url("/admin/webcams/$wid#foto"));
	}

	private function servicePost(array $w): void
	{
		$wid = (int) $w['id'];
		$svc = ServiceSettings::get($wid);
		if ($svc['source'] === 'dolibarr' && $w['dolibarr_id']) {
			$this->flash('error', 'Le impostazioni di servizio arrivano da Dolibarr: cambiale nel contratto di noleggio.');
		} else {
			try {
				ServiceSettings::save($wid, [
					'sla_enabled' => !empty($_POST['sla_enabled']),
					'sla_offline_hours' => $_POST['sla_offline_hours'] ?? 3,
					'sla_response_days' => $_POST['sla_response_days'] ?? 3,
					'sla_target_pct' => $_POST['sla_target_pct'] ?? 98,
					'sla_hours' => $_POST['sla_hours'] ?? null,
					'sla_weekdays' => $_POST['sla_weekdays'] ?? null,
					'shutter_life' => ($_POST['shutter_life'] ?? '') === '' ? null : max(1000, (int) $_POST['shutter_life']),
					'photo_metrics' => !empty($_POST['photo_metrics']),
				]);
				$this->flash('ok', 'Impostazioni di servizio salvate.');
			} catch (\InvalidArgumentException $e) {
				$this->flash('error', $e->getMessage());
			}
		}
		Response::redirect($this->req->url("/admin/webcams/$wid#servizio"));
	}

	private function photoJpg(array $w, int $pid): void
	{
		$p = Db::one('SELECT id, sha256, taken_at, storage_path FROM photos WHERE id = ? AND webcam_id = ?', [$pid, $w['id']]);
		$file = $p ? PhotoStore::abs((string) $p['storage_path']) : '';
		if ($p === null || !is_file($file)) {
			Response::notFound();
			return;
		}
		PublicController::sendJpeg($this->req, $file, (string) $p['sha256'], (int) Db::unix($p['taken_at']), 'private, max-age=86400');
	}
}
