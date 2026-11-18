<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;

/**
 * shutter.life: otturatore al 90% e a fine vita, solo per il pannello. Gli
 * avvisi in Dolibarr li scrive il modulo (report e counters): qui gli eventi
 * alert.* hanno forward = false. Soglie lette da Dolibarr (webcam_service),
 * altrimenti cameras.shutter_rated o 100.000 e 90%.
 */
final class ShutterLife extends Rule
{
	public function id(): string
	{
		return 'shutter.life';
	}

	public function label(): string
	{
		return 'Otturatore della reflex a fine vita';
	}

	public function severity(): string
	{
		return 'notice';
	}

	public function action(): string
	{
		return 'panel';
	}

	public function forward(): bool
	{
		return false;
	}

	public function defaults(): array
	{
		return ['life' => 100000, 'warn_pct' => 90];
	}

	public function evaluate(Context $c): Outcome
	{
		$cc = Db::one(
			'SELECT cc.shutter_count, cc.shots_total, c.serial, c.model, c.shutter_rated FROM camera_counters cc
			   LEFT JOIN cameras c ON c.id = cc.camera_id
			  WHERE cc.webcam_id = ? AND cc.ts <= ? ORDER BY cc.ts DESC, cc.id DESC LIMIT 1',
			[$c->id(), Db::dt($c->now + 600)]
		);
		if ($cc === null || ($cc['shutter_count'] === null && $cc['shots_total'] === null)) {
			return Outcome::unknown('nessun contatore degli scatti');
		}
		$used = (int) ($cc['shutter_count'] ?? $cc['shots_total']);
		$life = (int) ($c->service['shutter_life'] ?? $cc['shutter_rated'] ?? $c->i('life'));
		$life = $life > 0 ? $life : 100000;
		$warn = (int) ($c->service['shutter_warn_pct'] ?? $c->i('warn_pct'));
		$pct = round($used / $life * 100, 1);
		$v = ['used' => $used, 'life' => $life, 'warn_pct' => $warn, 'pct' => $pct, 'serial' => $cc['serial'],
			'source' => $cc['shutter_count'] !== null ? 'shutter_count' : 'shots_total'];

		if ($c->alert !== null) {
			$was = Alerts::details($c->alert)['values']['serial'] ?? null;
			if ($was !== null && $cc['serial'] !== null && $was !== $cc['serial']) {
				$o = Outcome::ok($v);
				$o->closeReason = 'camera_changed';
				$o->closeNote = "fotocamera cambiata: $was -> {$cc['serial']}";
				return $o;
			}
		}
		if ($pct < $warn) {
			return Outcome::ok($v);
		}
		$end = $used >= $life;
		$o = Outcome::alarm($end ? 'Otturatore della reflex a fine vita' : "Otturatore della reflex al $warn% della vita", [
			number_format($used, 0, ',', '.') . ' scatti su ' . number_format($life, 0, ',', '.') . " ($pct%), da " . $v['source']
				. ($cc['serial'] ? ' (fotocamera ' . $cc['model'] . ' n. ' . $cc['serial'] . ')' : ''),
		], $v);
		$o->severity = $end ? 'warning' : 'notice';
		$o->check = 'Preparare una fotocamera di ricambio.';
		return $o;
	}
}
