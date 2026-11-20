<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * enclosure.seal: in una custodia chiusa l'umidità assoluta interna cambia
 * lentamente; se segue quella esterna (follow = Δah_in / Δah_ext vicino a 1)
 * la guarnizione non tiene. Finestre fisse di window_h ore (UTC).
 */
final class EnclosureSeal extends Rule
{
	public function id(): string
	{
		return 'enclosure.seal';
	}

	public function label(): string
	{
		return 'Guarnizione della custodia da controllare';
	}

	public function severity(): string
	{
		return 'warning';
	}

	public function action(): string
	{
		return 'ticket';
	}

	public function defaults(): array
	{
		return ['window_h' => 6, 'min_delta_ext' => 1.5, 'open_follow' => 0.6, 'open_windows' => 3, 'close_follow' => 0.3, 'clear_for' => 168];
	}

	/** @return list<array{from:int, to:int, follow:float, d_ext:float, d_in:float}> */
	public static function windows(Context $c, int $from): array
	{
		$w = max(1, $c->i('window_h')) * 3600;
		$rows = $c->samples($from - $w, 'ah_in, ah_ext, heater, fan, rh_in, ext_sensor_ok');
		$out = [];
		$start = $from - ($from % $w);
		$byWin = [];
		foreach ($rows as $r) {
			$byWin[intdiv((int) $r['t'] - 1, $w)][] = $r;
		}
		for ($ws = $start; $ws + $w <= $c->now; $ws += $w) {
			$list = $byWin[intdiv($ws, $w)] ?? [];
			if (count($list) < 0.8 * $w / 300) {
				continue;
			}
			$first = [];
			$last = [];
			$ok = true;
			foreach ($list as $r) {
				if ((int) $r['heater'] === 1 || (int) $r['fan'] === 1 || (int) $r['ext_sensor_ok'] !== 1
					|| $r['ah_in'] === null || $r['ah_ext'] === null || ($r['rh_in'] !== null && (float) $r['rh_in'] >= 95)) {
					$ok = false;
					break;
				}
				$t = (int) $r['t'];
				if ($t <= $ws + 900) {
					$first[] = $r;
				}
				if ($t > $ws + $w - 900) {
					$last[] = $r;
				}
			}
			if (!$ok || $first === [] || $last === []) {
				continue;
			}
			$avg = static fn (array $l, string $k): float => array_sum(array_map(static fn ($r) => (float) $r[$k], $l)) / count($l);
			$dExt = $avg($last, 'ah_ext') - $avg($first, 'ah_ext');
			if (abs($dExt) < $c->f('min_delta_ext')) {
				continue;
			}
			$dIn = $avg($last, 'ah_in') - $avg($first, 'ah_in');
			$out[] = ['from' => $ws, 'to' => $ws + $w, 'follow' => round($dIn / $dExt, 2), 'd_ext' => round($dExt, 2), 'd_in' => round($dIn, 2)];
		}
		return $out;
	}

	public function evaluate(Context $c): Outcome
	{
		$clear = (int) ($c->f('clear_for') * 3600);
		$wins = self::windows($c, $c->now - max(7 * 86400, $clear));
		if ($wins === []) {
			return Outcome::unknown('nessuna finestra valida (riscaldamento o ventola accesi, sensore esterno assente, poca variazione esterna)');
		}
		$week = array_filter($wins, static fn ($x) => $x['from'] >= $c->now - 7 * 86400);
		$bad = array_values(array_filter($week, fn ($x) => $x['follow'] >= $c->f('open_follow')));
		$days = array_unique(array_map(static fn ($x) => $c->day($x['from']), $bad));
		$v = ['windows' => count($week), 'bad' => count($bad), 'days' => count($days), 'last_follow' => end($wins)['follow']];
		$v['data_at'] = end($wins)['to'];

		if ($c->alert !== null) {
			$recent = array_filter($wins, static fn ($x) => $x['from'] >= $c->now - $clear);
			$good = array_filter($recent, fn ($x) => $x['follow'] < $c->f('close_follow'));
			$stillBad = array_filter($recent, fn ($x) => $x['follow'] >= $c->f('open_follow'));
			if (count($good) >= 2 && $stillBad === []) {
				$o = Outcome::ok($v);
				$o->closeNote = count($good) . ' finestre con custodia stagna';
				return $o;
			}
		}
		if (count($bad) >= $c->i('open_windows') && count($days) >= 2) {
			$lines = [];
			foreach (array_slice($bad, -6) as $x) {
				$lines[] = 'finestra ' . $c->local($x['from'], 'd/m H:i') . '-' . $c->local($x['to'], 'H:i')
					. ': umidità assoluta esterna ' . Context::num($x['d_ext'], 2) . ' g/m³, interna ' . Context::num($x['d_in'], 2)
					. ' g/m³ (follow ' . Context::num($x['follow'], 2) . ', soglia ' . Context::num($c->f('open_follow'), 2) . ')';
			}
			$o = Outcome::alarm($this->label(), $lines, $v);
			$o->check = 'Guarnizione del coperchio e del vetro, passacavi, viti della custodia, tappo di sfiato.';
			$o->dataAt = $v['data_at'];
			return $o;
		}
		return Outcome::hold($v, $bad !== []);
	}
}
