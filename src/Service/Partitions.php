<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Partizioni mensili di measurements (schema-mariadb.sql, in fondo):
 *  - ensure(): crea le partizioni dei mesi prossimi spezzando p_future
 *  - dropExpired(): cancella i mesi oltre la retention (dopo aver
 *    ricalcolato le medie orarie di quei mesi) e svuota p_old quando tutto
 *    il suo intervallo è oltre la retention.
 * Funziona da MariaDB 10.4 (REORGANIZE PARTITION, niente CONVERT PARTITION).
 */
final class Partitions
{
	/** @return list<array{name:string, less_than:?string}> in ordine */
	public static function list(): array
	{
		$rows = Db::all(
			"SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS descr
			   FROM information_schema.PARTITIONS
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'measurements' AND PARTITION_NAME IS NOT NULL
			  ORDER BY PARTITION_ORDINAL_POSITION"
		);
		$out = [];
		foreach ($rows as $r) {
			$d = trim((string) $r['descr'], "'");
			$out[] = ['name' => (string) $r['name'], 'less_than' => strtoupper($d) === 'MAXVALUE' ? null : $d];
		}
		return $out;
	}

	/**
	 * Crea le partizioni mancanti fino al mese corrente + $monthsAhead
	 * (predefinito 2: il mese prossimo e quello dopo).
	 *
	 * @return list<string> partizioni create
	 */
	public static function ensure(int $monthsAhead = 2, ?int $now = null): array
	{
		$parts = self::list();
		if ($parts === []) {
			throw new \RuntimeException('measurements non è partizionata');
		}
		$hasFuture = false;
		$highest = null;
		foreach ($parts as $p) {
			if ($p['less_than'] === null) {
				$hasFuture = $p['name'] === 'p_future';
			} elseif ($highest === null || $p['less_than'] > $highest) {
				$highest = $p['less_than'];
			}
		}
		if (!$hasFuture) {
			throw new \RuntimeException('Partizione p_future (MAXVALUE) mancante');
		}
		$created = [];
		$month = (new \DateTimeImmutable('@' . ($now ?? time())))->modify('first day of this month')->setTime(0, 0);
		for ($i = 0; $i <= $monthsAhead; $i++) {
			$start = $month->modify("+$i months");
			$end = $start->modify('+1 month');
			$endStr = $end->format('Y-m-d H:i:s');
			if ($highest !== null && $endStr <= $highest) {
				continue; // già coperto
			}
			$name = 'p' . $start->format('Y_m');
			Db::pdo()->exec(
				"ALTER TABLE measurements REORGANIZE PARTITION p_future INTO (
				   PARTITION $name VALUES LESS THAN ('$endStr'),
				   PARTITION p_future VALUES LESS THAN (MAXVALUE))"
			);
			$highest = $endStr;
			$created[] = $name;
		}
		return $created;
	}

	/**
	 * Cancella le partizioni interamente più vecchie di $months mesi.
	 *
	 * @return list<string> partizioni cancellate o svuotate
	 */
	public static function dropExpired(int $months, bool $dryRun = false, ?int $now = null): array
	{
		$cutoff = (new \DateTimeImmutable('@' . ($now ?? time())))
			->modify('first day of this month')->setTime(0, 0)->modify("-$months months");
		$cutoffStr = $cutoff->format('Y-m-d H:i:s');
		$done = [];
		$lower = '1970-01-01 00:00:00';
		foreach (self::list() as $p) {
			if ($p['less_than'] === null) {
				break;
			}
			if ($p['less_than'] > $cutoffStr) {
				break;
			}
			if (!$dryRun) {
				// le medie orarie restano per sempre: ultime ricalcolate prima di cancellare
				$from = max(0, (int) Db::unix($lower));
				HourlyAggregator::aggregate($from, (int) Db::unix($p['less_than']));
				if ($p['name'] === 'p_old') {
					Db::pdo()->exec('ALTER TABLE measurements TRUNCATE PARTITION p_old');
				} else {
					Db::pdo()->exec('ALTER TABLE measurements DROP PARTITION ' . $p['name']);
				}
			}
			$done[] = $p['name'];
			$lower = $p['less_than'];
		}
		return $done;
	}
}
