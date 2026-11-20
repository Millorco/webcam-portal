<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Service\Commands;

/**
 * glass.fog: due foto di fila sfocate con umidità alta nella custodia.
 * All'apertura un comando heater.boost; se dopo boost_fail boost in 6 ore la
 * foto resta sfocata, warning e ticket.
 */
final class GlassFog extends PhotoRule
{
	public function id(): string
	{
		return 'glass.fog';
	}

	public function label(): string
	{
		return 'Vetro appannato';
	}

	public function severity(): string
	{
		return 'notice';
	}

	public function action(): string
	{
		return 'command';
	}

	public function defaults(): array
	{
		return ['fog_ratio' => 0.5, 'clear_ratio' => 0.75, 'rh_in_min' => 85, 'spread_max' => 2, 'boost_s' => 1800, 'boost_max_day' => 4, 'boost_fail' => 2];
	}

	private function humid(Context $c, array $r): bool
	{
		return ($r['rh_in'] !== null && (float) $r['rh_in'] >= $c->f('rh_in_min'))
			|| ($r['spread_in'] !== null && (float) $r['spread_in'] < $c->f('spread_max'));
	}

	/** @return list<array{id:int,end:int,status:string}> boost di questo avviso */
	private function boosts(Context $c, int $alertId, int $since): array
	{
		$out = [];
		foreach (Db::all(
			"SELECT * FROM commands WHERE alert_id = ? AND type = 'heater.boost' AND created_at > ? AND created_at <= ? ORDER BY id",
			[$alertId, Db::dt($since), Db::dt($c->now)]
		) as $b) {
			$p = json_decode((string) $b['params'], true) ?: [];
			$res = json_decode((string) ($b['result'] ?? ''), true) ?: [];
			$start = Db::unix($b['ack_at'] ?? null) ?? (int) Db::unix($b['created_at']);
			$end = isset($res['until']) && is_int($res['until']) ? $res['until'] : $start + (int) ($p['duration_s'] ?? 1800);
			$out[] = ['id' => (int) $b['id'], 'end' => $end, 'status' => (string) $b['status']];
		}
		return $out;
	}

	public function evaluate(Context $c): Outcome
	{
		$rows = $this->metrics($c, 2 * 86400);
		if (count($rows) < 2) {
			return Outcome::unknown('meno di due foto analizzate con il riferimento');
		}
		$last2 = array_slice($rows, -2);
		$last = end($rows);
		$v = ['blur_ratio' => self::r($last['blur_ratio']), 'rh_in' => self::r($last['rh_in']), 'spread_in' => self::r($last['spread_in']), 'photo_id' => (int) $last['photo_id']];
		$clear = $c->f('clear_ratio');

		if ($c->alert !== null) {
			$aid = (int) $c->alert['id'];
			if ((float) $last2[0]['blur_ratio'] >= $clear && (float) $last2[1]['blur_ratio'] >= $clear) {
				$o = Outcome::ok($v);
				$o->closeNote = 'foto di nuovo nitide';
				return $o;
			}
			$boosts = $this->boosts($c, $aid, $c->now - 6 * 3600);
			$done = array_values(array_filter($boosts, static fn ($b) => $b['status'] === 'done'));
			if ($done !== []) {
				$end = end($done)['end'];
				$after = array_values(array_filter($rows, static fn ($r) => (int) $r['t'] > $end));
				if ($after !== [] && (float) $after[0]['blur_ratio'] >= $clear) {
					$o = Outcome::ok($v);
					$o->closeNote = 'risolto con il riscaldamento';
					return $o;
				}
				if ($after !== [] && count($done) >= $c->i('boost_fail')) {
					$o = Outcome::alarm('Vetro appannato nonostante il riscaldamento', [
						count($done) . ' riscaldamenti forzati (' . (int) round($c->f('boost_s') / 60) . ' minuti) nelle ultime 6 ore senza effetto',
						'ultima foto: nitidezza ' . Context::num((float) $last['blur_ratio'] * 100, 0) . '% del riferimento, umidità interna '
							. Context::num(self::r($last['rh_in']), 0) . '%, margine sul punto di rugiada ' . Context::num(self::r($last['spread_in'])) . ' °C',
					], $v + ['boosts' => count($done)]);
					$o->severity = 'warning';
					$o->action = 'ticket';
					$o->check = 'Riscaldamento (vedi anche enclosure.heater), guarnizione (enclosure.seal), sacchetti di gel di silice.';
					return $o;
				}
			}
			return Outcome::hold($v + ['boosts' => count($done)]);
		}

		$fog = 0;
		foreach ($last2 as $r) {
			if ((float) $r['blur_ratio'] < $c->f('fog_ratio') && $r['classification'] !== 'fog_ext' && $this->humid($c, $r)) {
				$fog++;
			}
		}
		if ($fog === 2) {
			$o = Outcome::alarm($this->label(), [
				'due foto di fila sfocate: nitidezza ' . Context::num((float) $last2[0]['blur_ratio'] * 100, 0) . '% e ' . Context::num((float) $last2[1]['blur_ratio'] * 100, 0)
					. '% del riferimento (soglia ' . Context::num($c->f('fog_ratio') * 100, 0) . '%)',
				'umidità interna ' . Context::num(self::r($last['rh_in']), 0) . '%, margine sul punto di rugiada ' . Context::num(self::r($last['spread_in'])) . ' °C',
			], $v);
			$o->dataAt = (int) $last['t'];
			return $o;
		}
		return Outcome::hold($v, $fog > 0);
	}

	public function afterOpen(Context $c, int $alertId): void
	{
		$this->boost($c, $alertId);
	}

	/** Un boost nuovo se il precedente è finito e la foto dopo è ancora sfocata. */
	public function whileOpen(Context $c, Outcome $o): void
	{
		if ($o->state !== Outcome::HOLD || $c->alert === null) {
			return;
		}
		$aid = (int) $c->alert['id'];
		$boosts = $this->boosts($c, $aid, $c->now - 6 * 3600);
		$open = array_filter($boosts, static fn ($b) => in_array($b['status'], ['pending', 'delivered'], true));
		if ($open !== []) {
			return;
		}
		$done = array_values(array_filter($boosts, static fn ($b) => $b['status'] === 'done'));
		if ($done === [] || count($done) >= $c->i('boost_fail')) {
			return;
		}
		$end = end($done)['end'];
		$after = Db::scalar('SELECT 1 FROM photo_metrics WHERE webcam_id = ? AND taken_at > ? AND taken_at <= ? AND blur_ratio IS NOT NULL LIMIT 1', [$c->id(), Db::dt($end), Db::dt($c->now)]);
		if ($after !== null) {
			$this->boost($c, $aid);
		}
	}

	private function boost(Context $c, int $alertId): void
	{
		$s = $c->lastSample();
		if ($s === null || (int) ($s['heating_enabled'] ?? 0) !== 1) {
			Log::info("glass.fog webcam {$c->id()}: riscaldamento disabilitato o sconosciuto, nessun heater.boost", 'rules');
			return;
		}
		$boostUntil = Db::unix($s['heater_boost_until'] ?? null);
		if (($boostUntil !== null && $boostUntil > $c->now) || $s['heat_reason'] === 'boost' || (int) $s['heater'] === 1) {
			Log::info("glass.fog webcam {$c->id()}: riscaldamento già acceso, nessun heater.boost", 'rules');
			return;
		}
		$today = (int) Db::scalar(
			"SELECT COUNT(*) FROM commands WHERE webcam_id = ? AND type = 'heater.boost' AND created_by = 'rule' AND created_at > ?",
			[$c->id(), Db::dt($c->now - 86400)]
		);
		if ($today >= min($c->i('boost_max_day'), Commands::MAX_BOOST_RULE_DAY)) {
			return;
		}
		$dur = max(300, min(3600, $c->i('boost_s')));
		Commands::create($c->id(), 'heater.boost', ['duration_s' => $dur], [
			'by' => 'rule', 'reason' => 'glass.fog', 'alert_id' => $alertId, 'ttl' => 1800, 'now' => $c->now,
		]);
	}
}
