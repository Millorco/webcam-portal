<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;

/**
 * glass.dirty: foto sfocate col secco (niente condensa, pioggia o nebbia),
 * ripetute su più ore e almeno due giorni: vetro da pulire.
 */
final class GlassDirty extends PhotoRule
{
	public function id(): string
	{
		return 'glass.dirty';
	}

	public function label(): string
	{
		return 'Vetro della custodia da pulire';
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
		return ['dirty_ratio' => 0.6, 'clear_ratio' => 0.8, 'min_photos' => 6, 'min_hours' => 24, 'close_photos' => 4];
	}

	private function dry(array $r): bool
	{
		return $r['rh_in'] !== null && (float) $r['rh_in'] < 70
			&& $r['spread_in'] !== null && (float) $r['spread_in'] >= 5
			&& ($r['rh_ext'] === null || (float) $r['rh_ext'] < 85);
	}

	public function evaluate(Context $c): Outcome
	{
		$rows = $this->metrics($c, 7 * 86400);
		if ($rows === []) {
			return Outcome::unknown('nessuna foto analizzata con il riferimento');
		}
		$clear = $c->f('clear_ratio');
		$last = end($rows);
		$v = ['blur_ratio' => self::r($last['blur_ratio']), 'photo_id' => (int) $last['photo_id']];
		if ($c->alert !== null) {
			$n = $c->i('close_photos');
			$tail = array_slice($rows, -$n);
			if (count($tail) === $n && count(array_filter($tail, static fn ($r) => (float) $r['blur_ratio'] >= $clear)) === $n) {
				$o = Outcome::ok($v);
				$o->closeNote = "$n foto nitide di fila (vetro pulito)";
				return $o;
			}
			return Outcome::hold($v);
		}
		// foto sporche dopo l'ultima foto nitida
		$since = [];
		foreach ($rows as $r) {
			if ((float) $r['blur_ratio'] >= $clear) {
				$since = [];
				continue;
			}
			if ((float) $r['blur_ratio'] < $c->f('dirty_ratio') && $this->dry($r)) {
				$since[] = $r;
			}
		}
		$v['dirty_photos'] = count($since);
		if ($since === []) {
			return Outcome::ok($v);
		}
		$span = (int) end($since)['t'] - (int) $since[0]['t'];
		$days = array_unique(array_map(static fn ($r) => $c->day((int) $r['t']), $since));
		if (count($since) >= $c->i('min_photos') && $span >= $c->f('min_hours') * 3600 && count($days) >= 2) {
			$avg = array_sum(array_map(static fn ($r) => (float) $r['blur_ratio'], $since)) / count($since);
			$o = Outcome::alarm($this->label(), [
				count($since) . ' foto sfocate col secco dal ' . $c->local((int) $since[0]['t']) . ' (' . count($days) . ' giorni)',
				'nitidezza media ' . Context::num($avg * 100, 0) . '% del riferimento (soglia ' . Context::num($c->f('dirty_ratio') * 100, 0) . '%), umidità interna '
					. Context::num(self::r($last['rh_in']), 0) . '%, esterna ' . Context::num(self::r($last['rh_ext']), 0) . '%',
			], $v + ['reference_id' => $last['reference_id'] === null ? null : (int) $last['reference_id']]);
			$o->check = 'Pulire il vetro della custodia (sale, polvere, escrementi, ragnatele); poi scegliere un nuovo riferimento se la vista è cambiata.';
			$o->dataAt = (int) end($since)['t'];
			return $o;
		}
		return Outcome::hold($v, true);
	}
}
