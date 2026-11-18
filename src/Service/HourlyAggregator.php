<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Medie orarie (measurements_hourly) dai campioni grezzi. Usa solo i campioni
 * con time_synced = 1; quelli senza ora certa si contano in samples_unsynced.
 * Si può rieseguire quante volte si vuole sulle stesse ore (upsert).
 */
final class HourlyAggregator
{
	/**
	 * Ricalcola le ore che iniziano in [from, to).
	 *
	 * @return int righe inserite o aggiornate
	 */
	public static function aggregate(int $fromUnix, int $toUnix, ?int $webcamId = null): int
	{
		$from = $fromUnix - ($fromUnix % 3600);
		$s = static fn (string $x): string => "CASE WHEN m.time_synced = 1 THEN $x END";
		$cols = [
			'samples' => 'LEAST(SUM(m.time_synced = 1), 255)',
			'samples_unsynced' => 'LEAST(SUM(m.time_synced = 0), 255)',
			't_in_avg' => 'ROUND(AVG(' . $s('m.t_in') . '), 1)',
			't_in_min' => 'MIN(' . $s('m.t_in') . ')',
			't_in_max' => 'MAX(' . $s('m.t_in') . ')',
			'rh_in_avg' => 'ROUND(AVG(' . $s('m.rh_in') . '), 1)',
			'rh_in_max' => 'MAX(' . $s('m.rh_in') . ')',
			't_ext_avg' => 'ROUND(AVG(' . $s('m.t_ext') . '), 1)',
			't_ext_min' => 'MIN(' . $s('m.t_ext') . ')',
			't_ext_max' => 'MAX(' . $s('m.t_ext') . ')',
			'rh_ext_avg' => 'ROUND(AVG(' . $s('m.rh_ext') . '), 1)',
			'rh_ext_min' => 'MIN(' . $s('m.rh_ext') . ')',
			'rh_ext_max' => 'MAX(' . $s('m.rh_ext') . ')',
			'dp_ext_avg' => 'ROUND(AVG(' . $s('m.dp_ext') . '), 1)',
			'ah_ext_avg' => 'ROUND(AVG(' . $s('m.ah_ext') . '), 2)',
			'heater_min' => 'LEAST(SUM(' . $s('m.heater') . ') * 5, 255)',
			'fan_min' => 'LEAST(SUM(' . $s('m.fan') . ') * 5, 255)',
			'battery_v_avg' => 'ROUND(AVG(' . $s('m.battery_v') . '), 2)',
			'battery_v_min' => 'MIN(' . $s('m.battery_v') . ')',
			'battery_v_max' => 'MAX(' . $s('m.battery_v') . ')',
			'battery_a_avg' => 'ROUND(AVG(' . $s('m.battery_a') . '), 2)',
			'panel_w_avg' => 'ROUND(AVG(' . $s('m.panel_w') . '))',
			'panel_w_max' => 'MAX(' . $s('m.panel_w') . ')',
			'panel_wh' => 'ROUND(SUM(' . $s('m.panel_w') . ') * 5 / 60, 1)',
			'load_a_avg' => 'ROUND(AVG(' . $s('m.load_a') . '), 2)',
			'soc_avg' => 'ROUND(AVG(' . $s('m.soc_pct') . '), 1)',
			'soc_min' => 'MIN(' . $s('m.soc_pct') . ')',
			'rssi_avg' => 'ROUND(AVG(' . $s('m.rssi_dbm') . '))',
			'rssi_min' => 'MIN(' . $s('m.rssi_dbm') . ')',
			'rsrp_avg' => 'ROUND(AVG(' . $s('m.rsrp_dbm') . '))',
			'rx_bytes' => 'SUM(' . $s('m.rx_delta') . ')',
			'tx_bytes' => 'SUM(' . $s('m.tx_delta') . ')',
			'mem_free_min' => 'MIN(' . $s('m.mem_free_kb') . ')',
			'disk_free_min' => 'MIN(' . $s('m.disk_free_kb') . ')',
			'queue_photos_max' => 'MAX(' . $s('m.queue_photos') . ')',
		];
		$names = implode(', ', array_keys($cols));
		$exprs = implode(",\n\t\t\t", $cols);
		$upd = implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", array_keys($cols)));
		$where = 'ts >= ? AND ts < ?';
		$params = [Db::dt($from), Db::dt($toUnix)];
		if ($webcamId !== null) {
			$where .= ' AND webcam_id = ?';
			$params[] = $webcamId;
		}
		$sql = "INSERT INTO measurements_hourly (webcam_id, hour_ts, $names, photos)
			SELECT m.webcam_id, m.h, $exprs,
			  (SELECT LEAST(COUNT(*), 65535) FROM photos p
			    WHERE p.webcam_id = m.webcam_id AND p.taken_at >= m.h AND p.taken_at < m.h + INTERVAL 1 HOUR)
			  FROM (SELECT *, CAST(DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS DATETIME) AS h
			          FROM measurements WHERE $where) m
			 WHERE m.webcam_id IN (SELECT id FROM webcams)
			 GROUP BY m.webcam_id, m.h
			ON DUPLICATE KEY UPDATE $upd, photos = VALUES(photos)";
		return Db::exec($sql, $params);
	}
}
