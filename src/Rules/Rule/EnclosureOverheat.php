<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * enclosure.overheat: custodia molto più calda dell'esterno con il
 * riscaldamento spento (sole diretto, ventola ferma) o oltre abs_warn_c.
 * Agenda alla prima volta; ticket oltre abs_error_c o se si ripete.
 */
final class EnclosureOverheat extends Rule
{
	public function id(): string
	{
		return 'enclosure.overheat';
	}

	public function label(): string
	{
		return 'Custodia surriscaldata';
	}

	public function severity(): string
	{
		return 'warning';
	}

	public function action(): string
	{
		return 'agenda';
	}

	public function defaults(): array
	{
		return ['delta_c' => 15, 'for_min' => 30, 'abs_warn_c' => 40, 'abs_error_c' => 45, 'repeat_days' => 3, 'clear_for' => 72, 'recent_h' => 24];
	}

	private function hot(Context $c, array $r, float $delta): bool
	{
		if ((int) $r['heater'] === 1 || $r['t_in'] === null) {
			return false;
		}
		$tin = (float) $r['t_in'];
		if ($tin >= $c->f('abs_warn_c')) {
			return true;
		}
		return $r['t_ext'] !== null && (int) $r['ext_sensor_ok'] === 1 && $tin - (float) $r['t_ext'] >= $delta;
	}

	/** @return list<array{from:int,to:int,max_in:float,max_delta:?float,rows:list<array>}> */
	private function episodes(Context $c, array $rows, float $delta): array
	{
		$eps = [];
		$cur = [];
		$prev = null;
		$flush = function () use (&$cur, &$eps, $c): void {
			if ($cur !== [] && (int) end($cur)['t'] - (int) $cur[0]['t'] >= ($c->i('for_min') - 5) * 60) {
				$maxIn = max(array_map(static fn ($r) => (float) $r['t_in'], $cur));
				$deltas = array_map(static fn ($r) => $r['t_ext'] === null ? null : (float) $r['t_in'] - (float) $r['t_ext'], $cur);
				$deltas = array_filter($deltas, static fn ($d) => $d !== null);
				$eps[] = ['from' => (int) $cur[0]['t'], 'to' => (int) end($cur)['t'], 'max_in' => $maxIn, 'max_delta' => $deltas ? max($deltas) : null, 'rows' => $cur];
			}
			$cur = [];
		};
		foreach ($rows as $r) {
			$t = (int) $r['t'];
			if ($prev !== null && $t - $prev > 660) {
				$flush();
			}
			if ($this->hot($c, $r, $delta)) {
				$cur[] = $r;
			} else {
				$flush();
			}
			$prev = $t;
		}
		$flush();
		return $eps;
	}

	public function evaluate(Context $c): Outcome
	{
		$clear = (int) ($c->f('clear_for') * 3600);
		$rows = $c->samples($c->now - max(7 * 86400, $clear), 't_in, t_ext, ext_sensor_ok, heater, fan, fan_rpm, panel_w');
		if ($rows === []) {
			return Outcome::unknown('nessun campione');
		}
		$delta = $c->f('delta_c');
		$eps = $this->episodes($c, $rows, $delta);
		$v = ['episodes_7d' => count($eps)];

		if ($c->alert !== null) {
			$recent = array_filter($rows, static fn ($r) => (int) $r['t'] > $c->now - $clear);
			$hot = array_filter($recent, fn ($r) => $this->hot($c, $r, $delta - 3));
			$span = $recent ? (int) end($recent)['t'] - (int) reset($recent)['t'] : 0;
			if ($hot === [] && $span >= $clear - 3600) {
				$o = Outcome::ok($v);
				$o->closeNote = 'nessun surriscaldamento da ' . (int) round($clear / 3600) . ' ore';
				return $o;
			}
		}
		$current = array_values(array_filter($eps, fn ($e) => $e['to'] > $c->now - $c->f('recent_h') * 3600));
		if ($current === []) {
			return Outcome::hold($v);
		}
		$e = end($current);
		$days = array_unique(array_map(static fn ($x) => $c->day($x['from']), array_filter($eps, static fn ($x) => $x['from'] >= $c->now - 7 * 86400)));
		$cause = $this->cause($c, $e, $rows);
		$o = Outcome::alarm($this->label() . ($cause === 'sun' ? ' (sole diretto)' : ($cause === 'fan' ? ' (ventola ferma)' : '')), [
			'dalle ' . $c->local($e['from'], 'd/m H:i') . ' alle ' . $c->local($e['to'], 'H:i') . ': interno fino a ' . Context::num($e['max_in']) . ' °C'
				. ($e['max_delta'] !== null ? ', ' . Context::num($e['max_delta']) . ' °C più dell\'esterno (soglia ' . Context::num($delta) . ' °C)' : ''),
			'giorni con surriscaldamento negli ultimi 7: ' . count($days),
			'causa probabile: ' . ['sun' => 'sole diretto sulla custodia', 'fan' => 'ventola comandata ma ferma', 'unknown' => 'sconosciuta (di notte è sospetto: resistenza bloccata accesa?)'][$cause],
		], $v + ['max_in' => $e['max_in'], 'max_delta' => $e['max_delta'], 'days' => count($days)]);
		$o->cause = $cause;
		$o->dataAt = $e['to'];
		$o->check = 'Esposizione della custodia (schermo parasole, orientamento), ventola, resistenza.';
		if ($e['max_in'] >= $c->f('abs_error_c') || count($days) >= $c->i('repeat_days')) {
			$o->severity = 'error';
			$o->action = 'ticket';
		}
		return $o;
	}

	private function cause(Context $c, array $e, array $rows): string
	{
		$mid = intdiv($e['from'] + $e['to'], 2);
		foreach ($e['rows'] as $r) {
			if ((int) $r['fan'] === 1 && $r['fan_rpm'] !== null && (int) $r['fan_rpm'] === 0) {
				return 'fan';
			}
		}
		if (!$c->isDaylight($mid)) {
			return 'unknown';
		}
		$day = $c->day($mid);
		$max = 0;
		foreach ($rows as $r) {
			if ($r['panel_w'] !== null && $c->day((int) $r['t']) === $day) {
				$max = max($max, (int) $r['panel_w']);
			}
		}
		if ($max === 0) {
			return 'sun'; // senza Victron: di giorno basta il sole sopra l'orizzonte
		}
		$pw = array_filter(array_map(static fn ($r) => $r['panel_w'], $e['rows']), static fn ($x) => $x !== null);
		return ($pw && max($pw) >= 0.5 * $max) ? 'sun' : 'unknown';
	}
}
