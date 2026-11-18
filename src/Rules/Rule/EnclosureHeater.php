<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * enclosure.heater: il riscaldamento resta acceso almeno on_min minuti ma la
 * custodia non si scalda (sotto il punto di rugiada + margine e nessun
 * aumento rispetto all'esterno). Un ciclo lungo vale come più finestre
 * successive di on_min minuti.
 */
final class EnclosureHeater extends Rule
{
	public function id(): string
	{
		return 'enclosure.heater';
	}

	public function label(): string
	{
		return 'Riscaldamento della custodia guasto';
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
		return ['on_min' => 30, 'min_rise' => 1.0, 'open_cycles' => 2, 'default_margin' => 3.0];
	}

	/**
	 * Finestre valide di on_min minuti con il riscaldamento acceso.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function cycles(Context $c, int $from): array
	{
		$rows = $c->samples($from, 't_in, dp_in, t_ext, ext_sensor_ok, heater, heat_reason, dewpoint_margin, UNIX_TIMESTAMP(heater_on_since) AS on_since');
		$runs = [];
		$cur = [];
		$prevT = null;
		foreach ($rows as $r) {
			$t = (int) $r['t'];
			$on = (int) $r['heater'] === 1 || ($cur !== [] && $r['heat_reason'] === 'capture');
			if (!$on || ($prevT !== null && $t - $prevT > 660)) {
				if (count($cur) > 1) {
					$runs[] = $cur;
				}
				$cur = [];
			}
			if ($on && $r['t_in'] !== null && $r['dp_in'] !== null) {
				$cur[] = $r;
			}
			$prevT = $t;
		}
		if (count($cur) > 1) {
			$runs[] = $cur;
		}
		$len = max(5, $c->i('on_min')) * 60;
		$out = [];
		foreach ($runs as $run) {
			$i0 = 0;
			$n = count($run);
			while ($i0 < $n) {
				$s0 = $run[$i0];
				$ws = (int) $s0['t'];
				$i1 = null;
				for ($j = $i0 + 1; $j < $n; $j++) {
					if ((int) $run[$j]['t'] >= $ws + $len - 60) {
						$i1 = $j;
						break;
					}
				}
				if ($i1 === null) {
					break;
				}
				$s1 = $run[$i1];
				if ($s1['heat_reason'] !== 'capture' && (int) $s1['heater'] === 1) {
					$margin = $s1['dewpoint_margin'] !== null ? (float) $s1['dewpoint_margin'] : $c->f('default_margin');
					$spread = (float) $s1['t_in'] - (float) $s1['dp_in'];
					$ext = $s0['t_ext'] !== null && $s1['t_ext'] !== null && (int) $s0['ext_sensor_ok'] === 1 && (int) $s1['ext_sensor_ok'] === 1;
					$d0 = (float) $s0['t_in'] - ($ext ? (float) $s0['t_ext'] : 0.0);
					$d1 = (float) $s1['t_in'] - ($ext ? (float) $s1['t_ext'] : 0.0);
					$rise = round($d1 - $d0, 1);
					$out[] = [
						'from' => $ws, 'to' => (int) $s1['t'], 'on_since' => $run[0]['on_since'] !== null ? (int) $run[0]['on_since'] : (int) $run[0]['t'],
						't_in' => (float) $s1['t_in'], 'dp_in' => (float) $s1['dp_in'], 'spread' => round($spread, 1), 'margin' => $margin,
						'd0' => round($d0, 1), 'd1' => round($d1, 1), 'rise' => $rise, 'ext' => $ext,
						'fault' => $spread < $margin && $rise < $c->f('min_rise'),
						'heats' => $spread >= $margin || $rise >= 2 * $c->f('min_rise'),
					];
				}
				$i0 = $i1;
			}
		}
		return $out;
	}

	public function evaluate(Context $c): Outcome
	{
		$from = $c->now - 7 * 86400;
		if ($c->alert !== null) {
			$from = min($from, (int) Db::unix($c->alert['opened_at']) - 86400);
		}
		$cycles = self::cycles($c, $from);
		if ($cycles === []) {
			return Outcome::unknown('nessun ciclo di riscaldamento di almeno ' . $c->i('on_min') . ' minuti');
		}
		$last = end($cycles);
		$v = ['cycles' => count($cycles), 'spread' => $last['spread'], 'rise' => $last['rise'], 'margin' => $last['margin']];
		if ($c->alert !== null) {
			$opened = (int) Db::unix($c->alert['opened_at']);
			foreach ($cycles as $x) {
				if ($x['from'] >= $opened - 300 && $x['heats']) {
					$o = Outcome::ok($v);
					$o->closeNote = 'ciclo di riscaldamento ok alle ' . $c->local($x['to'], 'd/m H:i') . ' (' . ($x['rise'] >= 0 ? '+' : '') . Context::num($x['rise']) . ' °C)';
					$o->dataAt = $x['to'];
					return $o;
				}
			}
		}
		$n = max(1, $c->i('open_cycles'));
		$tail = array_slice($cycles, -$n);
		$faults = array_filter($tail, static fn ($x) => $x['fault']);
		if (count($tail) === $n && count($faults) === $n) {
			$x = end($tail);
			$lines = [
				'riscaldamento acceso dalle ' . $c->local($x['on_since'], 'H:i') . ' (' . (int) round(($x['to'] - $x['on_since']) / 60) . ' minuti)',
				'interno: ' . Context::num($x['t_in']) . ' °C, punto di rugiada ' . Context::num($x['dp_in']) . ' °C (margine ' . Context::num($x['spread'])
					. ' °C, richiesto ' . Context::num($x['margin']) . ' °C)',
				($x['ext'] ? 'differenza interno-esterno' : 'temperatura interna') . ': da ' . Context::num($x['d0']) . ' a ' . Context::num($x['d1'])
					. ' °C (aumento ' . Context::num($x['rise']) . ' °C, richiesto ' . Context::num($c->f('min_rise')) . ' °C)',
			];
			foreach (array_slice($tail, 0, -1) as $p) {
				$lines[] = 'stesso esito nel ciclo delle ' . $c->local($p['from'], 'H:i');
			}
			$o = Outcome::alarm($this->label(), $lines, $v);
			$o->check = 'Resistenza, relè della scheda (comando H), cablaggio.';
			$o->dataAt = $x['to'];
			return $o;
		}
		return Outcome::hold($v, $faults !== []);
	}
}
