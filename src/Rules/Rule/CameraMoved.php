<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;

/**
 * camera.moved: foto nitide ma spostate rispetto al riferimento (shift_px)
 * o molto diverse (match_score basso: camera girata o coperta).
 */
final class CameraMoved extends PhotoRule
{
	public function id(): string
	{
		return 'camera.moved';
	}

	public function label(): string
	{
		return 'Inquadratura spostata';
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
		return ['shift_open' => 8, 'shift_close' => 3, 'match_min' => 0.35, 'photos' => 3, 'sharp_ratio' => 0.7];
	}

	public function evaluate(Context $c): Outcome
	{
		$rows = array_values(array_filter($this->metrics($c, 2 * 86400), static fn ($r) => $r['match_score'] !== null && $r['shift_px'] !== null));
		$n = max(1, $c->i('photos'));
		if (count($rows) < $n) {
			return Outcome::unknown('meno di ' . $n . ' foto confrontate con il riferimento');
		}
		$tail = array_slice($rows, -$n);
		$last = end($tail);
		$v = ['shift_px' => (float) $last['shift_px'], 'shift_x' => (int) $last['shift_x'], 'shift_y' => (int) $last['shift_y'],
			'match_score' => (float) $last['match_score'], 'photo_id' => (int) $last['photo_id']];
		$moved = 0;
		$back = 0;
		foreach ($tail as $r) {
			$sharp = (float) $r['blur_ratio'] >= $c->f('sharp_ratio');
			$m = (float) $r['match_score'];
			if ($sharp && (((float) $r['shift_px'] >= $c->f('shift_open') && $m >= 0.5) || $m < $c->f('match_min'))) {
				$moved++;
			}
			if ((float) $r['shift_px'] < $c->f('shift_close') && $m >= 0.5) {
				$back++;
			}
		}
		if ($c->alert !== null) {
			if ($back === $n) {
				$o = Outcome::ok($v);
				$o->closeNote = 'inquadratura tornata al riferimento';
				return $o;
			}
			return Outcome::hold($v);
		}
		if ($moved === $n) {
			$px = (int) round((float) $last['shift_px']);
			$title = (float) $last['match_score'] < $c->f('match_min') ? 'Inquadratura molto diversa dal riferimento' : "Inquadratura spostata di $px pixel";
			$o = Outcome::alarm($title, [
				"$n foto di fila spostate rispetto al riferimento della stessa ora: " . sprintf('%+d', (int) $last['shift_x']) . ' px in orizzontale, '
					. sprintf('%+d', (int) $last['shift_y']) . ' px in verticale (su 640 px di larghezza, soglia ' . $c->i('shift_open') . ' px)',
				'somiglianza con il riferimento ' . Context::num((float) $last['match_score'], 2) . ' (sotto ' . Context::num($c->f('match_min'), 2) . ' = camera girata o coperta)',
			], $v);
			$o->check = 'Staffa e testa della custodia (vento, urti); se la nuova inquadratura va bene, confermarla nel pannello.';
			$o->dataAt = (int) $last['t'];
			return $o;
		}
		return Outcome::hold($v, $moved > 0);
	}
}
