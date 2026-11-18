<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;
use WebcamPortal\Log;

/**
 * Disponibilità (diagnosi-v1.md, "Impegno di disponibilità"), a ore locali
 * della webcam:
 *
 * - ora prevista: dentro sla_hours/sla_weekdays (tutte se vuoti), webcam in servizio;
 * - ora esclusa: prevista ma con manutenzione (sys.maintenance in un campione o
 *   eventi maintenance.on/off), un intervento, o in availability_exclusions;
 * - ora disponibile: prevista, non esclusa, con almeno una foto con taken_at
 *   nell'ora e time_synced = 1 (anche arrivata in ritardo);
 * - disponibilità = disponibili / (previste - escluse) x 100, NULL senza ore utili.
 *
 * availability_daily si ricalcola per oggi e i 7 giorni prima;
 * availability_monthly è provvisoria fino al 3 del mese dopo, poi definitiva.
 */
final class Availability
{
	public array $stats = ['webcams' => 0, 'days' => 0, 'months' => 0, 'final' => 0];

	public function __construct(private int $now)
	{
	}

	public function run(?int $webcamId = null, ?string $fromDay = null, ?string $toDay = null): array
	{
		$sql = 'SELECT * FROM webcams WHERE status = 1';
		$p = [];
		if ($webcamId !== null) {
			$sql .= ' AND id = ?';
			$p[] = $webcamId;
		}
		foreach (Db::all($sql . ' ORDER BY id', $p) as $w) {
			$this->stats['webcams']++;
			$svc = ServiceSettings::get((int) $w['id']);
			$tz = ServiceSettings::tz($w);
			$today = (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz)->setTime(0, 0);
			$from = $fromDay !== null ? new \DateTimeImmutable($fromDay, $tz) : $today->modify('-7 days');
			$to = $toDay !== null ? new \DateTimeImmutable($toDay, $tz) : $today;
			$months = [];
			for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
				$this->day($w, $svc, $tz, $d->format('Y-m-d'));
				$months[$d->format('Y-m')] = true;
			}
			$prev = $today->modify('first day of previous month')->format('Y-m');
			$months[$prev] = true;
			ksort($months);
			foreach (array_keys($months) as $m) {
				$this->month($w, $svc, $tz, $m);
			}
		}
		return $this->stats;
	}

	/** @return array<string,mixed> riga di availability_daily */
	public function day(array $w, array $svc, \DateTimeZone $tz, string $day): array
	{
		$wid = (int) $w['id'];
		$start = (new \DateTimeImmutable($day . ' 00:00:00', $tz))->getTimestamp();
		$end = (new \DateTimeImmutable($day . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
		$range = [Db::dt($start), Db::dt($end)];
		$idx = static fn (string $col): string => "FLOOR((UNIX_TIMESTAMP($col) - $start) / 3600)";
		$maint = [];
		foreach (Db::all(
			'SELECT ' . $idx('ts') . " AS h FROM measurements WHERE webcam_id = ? AND ts >= ? AND ts < ? AND JSON_VALUE(extra, '$.sys.maintenance') = 'true' GROUP BY h",
			array_merge([$wid], $range)
		) as $r) {
			$maint[(int) $r['h']] = true;
		}
		foreach (Db::all(
			'SELECT ' . $idx('ts') . " AS h FROM events WHERE webcam_id = ? AND ts >= ? AND ts < ? AND type IN ('maintenance.on','maintenance.off','intervention') GROUP BY h",
			array_merge([$wid], $range)
		) as $r) {
			$maint[(int) $r['h']] = true;
		}
		$photos = [];
		foreach (Db::all(
			'SELECT ' . $idx('taken_at') . ' AS h, COUNT(*) AS n FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ? AND time_synced = 1 GROUP BY h',
			array_merge([$wid], $range)
		) as $r) {
			$photos[(int) $r['h']] = (int) $r['n'];
		}
		$excl = Db::all(
			'SELECT UNIX_TIMESTAMP(from_at) f, UNIX_TIMESTAMP(to_at) t FROM availability_exclusions WHERE webcam_id = ? AND to_at > ? AND from_at < ?',
			array_merge([$wid], $range)
		);
		$exp = $ex = $av = $np = 0;
		$active = (int) $w['status'] === 1;
		for ($i = 0, $t = $start; $t < $end; $i++, $t += 3600) {
			if ($t + 3600 > $this->now) {
				break; // ore non ancora finite
			}
			if (!$active || !ServiceSettings::expectedAt($svc, $tz, $t)) {
				continue;
			}
			$exp++;
			$isEx = isset($maint[$i]);
			foreach ($excl as $e) {
				if ((int) $e['f'] < $t + 3600 && (int) $e['t'] > $t) {
					$isEx = true;
				}
			}
			if ($isEx) {
				$ex++;
				continue;
			}
			$np += $photos[$i] ?? 0;
			if (($photos[$i] ?? 0) > 0) {
				$av++;
			}
		}
		$useful = $exp - $ex;
		$pct = $useful > 0 ? round($av / $useful * 100, 2) : null;
		Db::exec(
			'INSERT INTO availability_daily (webcam_id, day, expected_h, excluded_h, available_h, photos, pct, computed_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
			 ON DUPLICATE KEY UPDATE expected_h = VALUES(expected_h), excluded_h = VALUES(excluded_h), available_h = VALUES(available_h),
			   photos = VALUES(photos), pct = VALUES(pct), computed_at = UTC_TIMESTAMP()',
			[$wid, $day, $exp, $ex, $av, min(65535, $np), $pct]
		);
		$this->stats['days']++;
		return ['day' => $day, 'expected_h' => $exp, 'excluded_h' => $ex, 'available_h' => $av, 'photos' => $np, 'pct' => $pct];
	}

	/** Somma del mese; definitiva dal 3 del mese dopo (poi non si ricalcola più). */
	public function month(array $w, array $svc, \DateTimeZone $tz, string $month): ?array
	{
		$wid = (int) $w['id'];
		$cur = Db::one('SELECT * FROM availability_monthly WHERE webcam_id = ? AND month = ?', [$wid, $month]);
		if ($cur !== null && (int) $cur['final'] === 1) {
			return $cur;
		}
		$first = new \DateTimeImmutable($month . '-01 00:00:00', $tz);
		$next = $first->modify('+1 month');
		$s = Db::one(
			'SELECT COUNT(*) n, SUM(expected_h) e, SUM(excluded_h) x, SUM(available_h) a FROM availability_daily WHERE webcam_id = ? AND day >= ? AND day < ?',
			[$wid, $first->format('Y-m-d'), $next->format('Y-m-d')]
		);
		if ((int) $s['n'] === 0) {
			return null;
		}
		$range = [Db::dt($first->getTimestamp()), Db::dt($next->getTimestamp())];
		$inc = (int) Db::scalar("SELECT COUNT(*) FROM alerts WHERE webcam_id = ? AND rule_id IN ('camera.stalled','telemetry.stalled') AND opened_at >= ? AND opened_at < ?", array_merge([$wid], $range));
		$br = (int) Db::scalar('SELECT COUNT(*) FROM alerts WHERE webcam_id = ? AND sla_breached = 1 AND opened_at >= ? AND opened_at < ?', array_merge([$wid], $range));
		$e = (int) $s['e'];
		$x = (int) $s['x'];
		$a = (int) $s['a'];
		$pct = $e - $x > 0 ? round($a / ($e - $x) * 100, 2) : null;
		$sla = (int) $svc['sla_enabled'] === 1;
		$target = (float) $svc['sla_target_pct'];
		$met = $sla ? (int) ($pct !== null && $pct >= $target && $br === 0) : null;
		$final = $this->now >= $next->modify('+2 days')->getTimestamp() ? 1 : 0;
		Db::exec(
			'INSERT INTO availability_monthly (webcam_id, month, expected_h, excluded_h, available_h, pct, sla_enabled, target_pct, incidents,
			   downtime_h, response_breaches, sla_met, final, computed_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
			 ON DUPLICATE KEY UPDATE expected_h = VALUES(expected_h), excluded_h = VALUES(excluded_h), available_h = VALUES(available_h),
			   pct = VALUES(pct), sla_enabled = VALUES(sla_enabled), target_pct = VALUES(target_pct), incidents = VALUES(incidents),
			   downtime_h = VALUES(downtime_h), response_breaches = VALUES(response_breaches), sla_met = VALUES(sla_met), final = VALUES(final),
			   computed_at = UTC_TIMESTAMP()',
			[$wid, $month, min(65535, $e), min(65535, $x), min(65535, $a), $pct, $sla ? 1 : 0, $sla ? $target : null, min(65535, $inc),
				max(0, $e - $x - $a), min(65535, $br), $met, $final]
		);
		if ($final) {
			// definitivo: va mandato a Dolibarr (anche se il provvisorio era già partito oggi)
			Db::exec('UPDATE availability_monthly SET dolibarr_synced_at = NULL WHERE webcam_id = ? AND month = ?', [$wid, $month]);
			$this->stats['final']++;
			Log::info("disponibilità $month della webcam $wid definitiva: " . ($pct === null ? '—' : $pct . '%'), 'availability');
		}
		$this->stats['months']++;
		return Db::one('SELECT * FROM availability_monthly WHERE webcam_id = ? AND month = ?', [$wid, $month]);
	}
}
