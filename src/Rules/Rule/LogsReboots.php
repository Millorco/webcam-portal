<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Logs\LogData;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * logs.reboots: riavvii inattesi ripetuti. Eventi boot del database e righe
 * "Linux version" di VictoriaLogs: si prende il massimo, non la somma.
 */
final class LogsReboots extends Rule
{
	public function id(): string
	{
		return 'logs.reboots';
	}

	public function label(): string
	{
		return 'Riavvii inattesi ripetuti';
	}

	public function severity(): string
	{
		return 'warning';
	}

	public function action(): string
	{
		return 'agenda';
	}

	public function group(): string
	{
		return 'logs';
	}

	public function defaults(): array
	{
		return ['open_24h' => 2, 'ticket_24h' => 4, 'ticket_days' => 3, 'per_day' => 2, 'clear_for' => 72];
	}

	public function evaluate(Context $c): Outcome
	{
		$logs = LogData::logCounts($c->shared['logs'] ?? [], $c->webcam);
		if ($logs['state'] === 'not_unique') {
			return Outcome::unknown('hostname non univoco');
		}
		$clear = (int) ($c->f('clear_for') * 3600);
		$boots = LogData::boots($c->id(), $c->now - max(7 * 86400, $clear), $c->now);
		$days = LogData::days($c->id(), $c->now, $boots, LogData::cameraEvents($c->id(), $c->now - 7 * 86400, $c->now));
		$last24 = array_filter($boots, static fn ($b) => $b['t'] > $c->now - 86400);
		$evUnexp = count(array_filter($last24, static fn ($b) => $b['unexpected']));
		$evExp = count($last24) - $evUnexp;
		$logN = $logs['counts']['avvii'] ?? null;
		$n = max($evUnexp, $logN === null ? 0 : $logN - $evExp);
		$v = ['unexpected_24h' => $n, 'events_24h' => count($last24), 'log_24h' => $logN, 'logs' => $logs['state']];

		// giorni con almeno per_day riavvii inattesi negli ultimi 7
		$badDays = 0;
		foreach ($days as $d) {
			$u = (int) $d['boots_unexpected'];
			if ($d['boots_log'] !== null) {
				$u = max($u, (int) $d['boots_log'] - ((int) $d['boots_events'] - (int) $d['boots_unexpected']));
			}
			$badDays += $u >= $c->i('per_day') ? 1 : 0;
		}
		$v['bad_days'] = $badDays;

		if ($c->alert !== null) {
			$recent = array_filter($boots, static fn ($b) => $b['unexpected'] && $b['t'] > $c->now - $clear);
			if ($recent === [] && ($logN === null || $logN - $evExp <= 0)) {
				$o = Outcome::ok($v);
				$o->closeNote = 'nessun riavvio inatteso da ' . (int) round($clear / 3600) . ' ore';
				return $o;
			}
		}
		if ($n < $c->i('open_24h')) {
			return $c->alert !== null ? Outcome::hold($v) : ($n > 0 ? Outcome::hold($v, true) : Outcome::ok($v));
		}
		$minV = Db::scalar('SELECT MIN(battery_v) FROM measurements WHERE webcam_id = ? AND ts > ? AND ts <= ?', [$c->id(), Db::dt($c->now - 86400), Db::dt($c->now)]);
		$lines = ["$n riavvii inattesi nelle ultime 24 ore (eventi: " . count($last24) . ($logN !== null ? ", righe di log: $logN" : '') . ')'];
		foreach (array_slice(array_filter($last24, static fn ($b) => $b['unexpected']), -6) as $b) {
			$lines[] = $c->local($b['t'], 'd/m H:i') . ($b['type'] === 'board.power_cycle' ? ' (watchdog della scheda)' : '');
		}
		$lines[] = "giorni con almeno {$c->i('per_day')} riavvii negli ultimi 7: $badDays";
		$lines[] = 'tensione minima della batteria nelle ultime 24 ore: ' . ($minV === null ? '—' : Context::num((float) $minV, 2) . ' V');
		$o = Outcome::alarm("$n riavvii inattesi in 24 ore", $lines, $v + ['battery_v_min24' => $minV === null ? null : (float) $minV]);
		$o->check = 'Alimentazione e batteria (tensione minima), watchdog della scheda, cavo di alimentazione del router.';
		if ($n >= $c->i('ticket_24h') || $badDays >= $c->i('ticket_days')) {
			$o->severity = 'error';
			$o->action = 'ticket';
		}
		return $o;
	}
}
