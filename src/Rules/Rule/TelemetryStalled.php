<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * telemetry.stalled: nessuna richiesta autenticata dal router da "hours" ore
 * (con l'impegno di disponibilità al più sla_offline_hours). Si chiude
 * quando arriva telemetria più recente dell'apertura e le richieste restano
 * regolari per 15 minuti.
 */
final class TelemetryStalled extends Rule
{
	public function id(): string
	{
		return 'telemetry.stalled';
	}

	public function label(): string
	{
		return 'Webcam senza collegamento';
	}

	public function severity(): string
	{
		return 'error';
	}

	public function action(): string
	{
		return 'ticket';
	}

	public function defaults(): array
	{
		return ['hours' => 2, 'regular_min' => 35, 'clear_min' => 15];
	}

	public function evaluateInMaintenance(): bool
	{
		return true;
	}

	public function evaluate(Context $c): Outcome
	{
		$seen = Db::unix($c->webcam['last_seen_at'] ?? null);
		if ($seen === null) {
			return Outcome::unknown('mai collegata');
		}
		$hours = $c->f('hours');
		if ($c->slaEnabled()) {
			$hours = min($hours, (float) $c->service['sla_offline_hours']);
		}
		$silent = $c->now - $seen;
		$v = ['silent_h' => round($silent / 3600, 1), 'hours' => $hours, 'last_seen_at' => $c->webcam['last_seen_at']];
		if ($silent >= $hours * 3600) {
			$n = (int) floor($silent / 3600);
			$last = Db::one(
				'SELECT ts, battery_v, rssi_dbm, net_type, queue_photos, queue_samples FROM measurements
				  WHERE webcam_id = ? AND ts <= ? ORDER BY ts DESC LIMIT 1',
				[$c->id(), Db::dt($c->now)]
			);
			$lines = ['ultima richiesta dal router: ' . $c->local($seen) . " ($n ore fa)"];
			if ($last !== null) {
				$lines[] = 'ultima telemetria (' . $c->local((int) Db::unix($last['ts'])) . '): batteria ' . Context::num($last['battery_v'] === null ? null : (float) $last['battery_v'], 2)
					. ' V, segnale ' . ($last['rssi_dbm'] ?? '—') . ' dBm' . ($last['net_type'] ? ' (' . $last['net_type'] . ')' : '')
					. ', coda ' . ($last['queue_photos'] ?? '—') . ' foto e ' . ($last['queue_samples'] ?? '—') . ' campioni';
			}
			$o = Outcome::alarm("Webcam senza collegamento da $n ore", $lines, $v);
			$o->check = 'Batteria scarica (tensione nell\'ultima telemetria), alimentazione, rete mobile, router bloccato.';
			$o->dataAt = $seen;
			return $o;
		}
		if ($c->alert !== null) {
			$tele = Db::unix($c->webcam['last_telemetry_at'] ?? null);
			$opened = (int) Db::unix($c->alert['opened_at']);
			if ($tele !== null && $tele > $opened && $silent <= $c->f('regular_min') * 60) {
				$o = Outcome::ok($v);
				$o->clearFor = (int) ($c->f('clear_min') * 60);
				$o->closeNote = 'collegamento tornato';
				return $o;
			}
			return Outcome::hold($v);
		}
		return Outcome::ok($v);
	}
}
