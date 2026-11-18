<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Logs\LogData;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * logs.camera_errors: errori ripetuti della fotocamera (eventi camera.error e
 * camera.missing, righe finali di VictoriaLogs: il massimo dei due).
 */
final class LogsCameraErrors extends Rule
{
	public function id(): string
	{
		return 'logs.camera_errors';
	}

	public function label(): string
	{
		return 'Errori ripetuti della fotocamera';
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
		return ['open_errors' => 3, 'ticket_errors' => 10, 'retry_warn' => 5, 'days_row' => 3, 'clear_for' => 48];
	}

	public function evaluate(Context $c): Outcome
	{
		$logs = LogData::logCounts($c->shared['logs'] ?? [], $c->webcam);
		if ($logs['state'] === 'not_unique') {
			return Outcome::unknown('hostname non univoco');
		}
		$clear = (int) ($c->f('clear_for') * 3600);
		$ev = LogData::cameraEvents($c->id(), $c->now - max(7 * 86400, $clear), $c->now);
		$errs = static fn (int $a, int $b): int => count(array_filter($ev, static fn ($e) => $e['type'] !== 'camera.power_cycle' && $e['t'] > $a && $e['t'] <= $b));
		$retr = static fn (int $a, int $b): int => count(array_filter($ev, static fn ($e) => $e['type'] === 'camera.power_cycle' && $e['t'] > $a && $e['t'] <= $b));
		$logE = $logs['counts']['errori'] ?? null;
		$logR = $logs['counts']['tentativi'] ?? null;
		$n = max($errs($c->now - 86400, $c->now), (int) $logE);
		$retries = max($retr($c->now - 86400, $c->now), (int) $logR);
		$v = ['errors_24h' => $n, 'retries_24h' => $retries, 'log_errors_24h' => $logE, 'logs' => $logs['state']];
		$open = $c->i('open_errors');

		if ($c->alert !== null) {
			$prev = $errs($c->now - $clear, $c->now - 86400);
			if ($n < $open && $prev < $open) {
				$o = Outcome::ok($v);
				$o->closeNote = 'meno di ' . $open . ' errori al giorno da ' . (int) round($clear / 3600) . ' ore';
				return $o;
			}
		}
		if ($n < $open) {
			return $c->alert !== null ? Outcome::hold($v) : ($n > 0 ? Outcome::hold($v, true) : Outcome::ok($v));
		}
		// giorni di fila (UTC) con almeno open_errors errori, e tentativi per giorno
		$days = LogData::days($c->id(), $c->now, LogData::boots($c->id(), $c->now - 7 * 86400, $c->now), $ev);
		krsort($days);
		$row = 0;
		$retryRow = 0;
		$stopE = false;
		$stopR = false;
		foreach ($days as $d) {
			$e = max((int) $d['camera_errors_events'], (int) ($d['camera_errors_log'] ?? 0));
			$stopE = $stopE || $e < $open;
			$row += $stopE ? 0 : 1;
			$stopR = $stopR || (int) $d['capture_retries'] <= $c->i('retry_warn');
			$retryRow += $stopR ? 0 : 1;
		}
		$lines = ["$n errori della fotocamera nelle ultime 24 ore" . ($logE !== null ? " (righe di log: $logE)" : ''),
			"$retries primi tentativi di scatto falliti nelle ultime 24 ore",
			"giorni di fila con almeno $open errori: $row"];
		if ($retryRow >= 3) {
			$lines[] = "Nota: più di {$c->i('retry_warn')} tentativi falliti al giorno da $retryRow giorni: cavo USB o fotocamera che peggiorano.";
		}
		$o = Outcome::alarm("$n errori della fotocamera in 24 ore", $lines, $v + ['days_row' => $row, 'retry_days' => $retryRow]);
		$o->check = 'Cavo USB e hub, alimentazione della fotocamera (relè, batteria finta), fotocamera.';
		if ($n >= $c->i('ticket_errors') || $row >= $c->i('days_row')) {
			$o->severity = 'error';
			$o->action = 'ticket';
		}
		return $o;
	}
}
