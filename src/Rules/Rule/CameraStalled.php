<?php

declare(strict_types=1);

namespace WebcamPortal\Rules\Rule;

use WebcamPortal\Db;
use WebcamPortal\Rules\Context;
use WebcamPortal\Rules\Outcome;
use WebcamPortal\Rules\Rule;
use WebcamPortal\Service\Commands;
use WebcamPortal\Service\ServiceSettings;

/**
 * camera.stalled: router vivo (telemetria che arriva) ma nessuna foto nelle
 * ultime "hours" ore di scatto previste (sla_hours/sla_weekdays, non in
 * manutenzione, camera.scheduled diverso da false). Non si valuta mentre
 * telemetry.stalled è aperto.
 */
final class CameraStalled extends Rule
{
	private const STEP = 300;

	public function id(): string
	{
		return 'camera.stalled';
	}

	public function label(): string
	{
		return 'Camera ferma';
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
		return ['hours' => 3, 'capture_now' => false, 'close_photos' => 2];
	}

	public function evaluate(Context $c): Outcome
	{
		if (isset($c->openAlerts['telemetry.stalled'])) {
			return Outcome::unknown('telemetry.stalled aperto');
		}
		$last = $c->lastSample();
		if ($last === null || $c->now - (int) $last['t'] > 3600) {
			return Outcome::unknown('nessuna telemetria recente');
		}
		$hours = $c->slaEnabled() ? (float) $c->service['sla_offline_hours'] : $c->f('hours');

		if ($c->alert !== null) {
			$opened = (int) Db::unix($c->alert['opened_at']);
			$n = (int) Db::scalar(
				'SELECT COUNT(*) FROM photos WHERE webcam_id = ? AND taken_at > ? AND taken_at <= ?',
				[$c->id(), Db::dt($opened), Db::dt($c->now)]
			);
			if ($n >= $c->i('close_photos')) {
				$o = Outcome::ok(['photos_after_open' => $n]);
				$o->closeNote = 'foto di nuovo in arrivo';
				return $o;
			}
			return Outcome::hold(['photos_after_open' => $n]);
		}

		// finestra: le ultime "hours" ore previste, contando all'indietro
		$from = $c->now - 7 * 86400;
		$buckets = [];
		foreach ($c->samples($from, "JSON_VALUE(extra, '$.sys.maintenance') = 'true' AS maint, cam_scheduled") as $s) {
			$b = intdiv((int) $s['t'], self::STEP);
			$buckets[$b] = (int) $s['maint'] === 1 || ($s['cam_scheduled'] !== null && (int) $s['cam_scheduled'] === 0);
		}
		$need = (int) round($hours * 3600 / self::STEP);
		$got = 0;
		$start = null;
		for ($t = $c->now; $t > $from; $t -= self::STEP) {
			if (!ServiceSettings::expectedAt($c->service, $c->tz, $t - 1)) {
				continue;
			}
			$b = intdiv($t - 1, self::STEP);
			$off = $buckets[$b] ?? ($buckets[$b - 1] ?? ($buckets[$b + 1] ?? false));
			if ($off) {
				continue;
			}
			if (++$got >= $need) {
				$start = $t - self::STEP;
				break;
			}
		}
		if ($start === null) {
			return Outcome::ok(['expected_h' => round($got * self::STEP / 3600, 1)]);
		}
		$n = (int) Db::scalar(
			'SELECT COUNT(*) FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at <= ?',
			[$c->id(), Db::dt($start), Db::dt($c->now)]
		);
		$v = ['hours' => $hours, 'window_from' => Db::dt($start), 'photos' => $n];
		if ($n > 0) {
			return Outcome::ok($v);
		}
		$lastPhoto = Db::one('SELECT id, taken_at FROM photos WHERE webcam_id = ? AND taken_at <= ? ORDER BY taken_at DESC LIMIT 1', [$c->id(), Db::dt($c->now)]);
		$lines = [
			'ultima foto: ' . ($lastPhoto ? $c->local((int) Db::unix($lastPhoto['taken_at'])) : 'mai'),
			'nessuna foto dal ' . $c->local($start) . " ($hours ore di scatto previste)",
			'ultimo campione (' . $c->local((int) $last['t']) . '): fotocamera ' . self::yn($last['camera_present'], 'presente', 'assente')
				. ', alimentazione ' . self::yn($last['camera_power'], 'accesa', 'spenta'),
		];
		foreach (Db::all(
			"SELECT ts, type, message FROM events WHERE webcam_id = ? AND type LIKE 'camera.%' AND ts <= ? AND ts >= ? ORDER BY ts DESC LIMIT 5",
			[$c->id(), Db::dt($c->now), Db::dt($c->now - 86400)]
		) as $e) {
			$lines[] = $c->local((int) Db::unix($e['ts']), 'd/m H:i') . ' ' . $e['type'] . ($e['message'] ? ': ' . $e['message'] : '');
		}
		if ($lastPhoto) {
			$v['last_photo_id'] = (int) $lastPhoto['id'];
		}
		$o = Outcome::alarm('Nessuna foto da ' . self::h($hours) . ' ore di scatto', $lines, $v);
		$o->check = 'Fotocamera (accesa, cavo USB, batteria finta), scheda SD della fotocamera, relè della fotocamera, servizio di scatto.';
		$o->dataAt = (int) $last['t'];
		return $o;
	}

	public function afterOpen(Context $c, int $alertId): void
	{
		if (!$c->p('capture_now')) {
			return;
		}
		$done = Db::scalar("SELECT 1 FROM commands WHERE alert_id = ? AND type = 'capture.now' LIMIT 1", [$alertId]);
		if ($done === null) {
			Commands::create($c->id(), 'capture.now', ['upload' => true], [
				'by' => 'rule', 'reason' => 'camera.stalled', 'alert_id' => $alertId, 'ttl' => 3600, 'now' => $c->now,
			]);
		}
	}

	private static function yn(mixed $v, string $yes, string $no): string
	{
		return $v === null ? '—' : ((int) $v === 1 ? $yes : $no);
	}

	private static function h(float $h): string
	{
		return floor($h) === $h ? (string) (int) $h : number_format($h, 1, ',', '');
	}
}
