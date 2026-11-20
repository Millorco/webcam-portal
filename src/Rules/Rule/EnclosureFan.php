<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * enclosure.fan: ventola comandata (fan = 1) ma ferma (fan_rpm < min_rpm).
 * Dormiente finché la scheda non manda fan_rpm: senza, vale unknown.
 */
final class EnclosureFan extends Rule
{
	public function id(): string
	{
		return 'enclosure.fan';
	}

	public function label(): string
	{
		return 'Ventola della custodia ferma';
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
		return ['min_rpm' => 300, 'samples' => 3];
	}

	public function evaluate(Context $c): Outcome
	{
		$rows = $c->samples($c->now - 86400, 'fan, fan_rpm');
		$rows = array_values(array_filter($rows, static fn ($r) => $r['fan_rpm'] !== null));
		if ($rows === []) {
			return Outcome::unknown('fan_rpm non disponibile (scheda senza tachimetro)');
		}
		$on = array_values(array_filter($rows, static fn ($r) => (int) $r['fan'] === 1));
		$n = max(1, $c->i('samples'));
		$lastN = array_slice($on, -$n);
		$min = $c->i('min_rpm');
		$v = ['rpm' => $lastN ? (int) end($lastN)['fan_rpm'] : null, 'min_rpm' => $min];
		if (count($lastN) < $n) {
			return Outcome::hold($v);
		}
		$stopped = array_filter($lastN, static fn ($r) => (int) $r['fan_rpm'] < $min);
		if (count($stopped) === $n) {
			$o = Outcome::alarm($this->label(), [
				"ventola comandata accesa ma a {$v['rpm']} giri/min (minimo $min) negli ultimi $n campioni, dal " . $c->local((int) $lastN[0]['t']),
			], $v);
			$o->check = 'Ventola, connettore sulla scheda, polvere o insetti nella ventola.';
			$o->dataAt = (int) end($lastN)['t'];
			return $o;
		}
		return $stopped === [] ? Outcome::ok($v) : Outcome::hold($v, true);
	}
}
