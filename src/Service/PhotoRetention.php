<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;
use WebcamPortal\Site\Images;

/**
 * Retention delle foto: gli originali più vecchi di N giorni (predefinito 90)
 * si cancellano dal disco tenendo una foto per ora (la prima dell'ora) per
 * webcam. Il record in photos resta (con deleted_at), così lo SHA-256 resta
 * disponibile per le certificazioni. current.jpg non si tocca mai. Con la
 * foto si tolgono le sue riduzioni in storage/cache/img (API dei siti).
 */
final class PhotoRetention
{
	/** @return array{deleted:int, kept:int, bytes:int, missing:int} */
	public static function run(int $days, bool $dryRun = false, ?callable $out = null, ?int $now = null): array
	{
		$cutoff = Db::dt(($now ?? time()) - $days * 86400);
		$stats = ['deleted' => 0, 'kept' => 0, 'bytes' => 0, 'missing' => 0];
		$webcams = Db::all('SELECT id, last_photo_id FROM webcams');
		foreach ($webcams as $w) {
			$wid = (int) $w['id'];
			$current = PhotoStore::current($wid);
			$keepIds = array_filter([(int) $w['last_photo_id'], $current['photo_id'] ?? 0]);
			$st = Db::pdo()->prepare(
				'SELECT id, taken_at, storage_path, size_bytes FROM photos
				  WHERE webcam_id = ? AND taken_at < ? AND deleted_at IS NULL
				  ORDER BY taken_at, id'
			);
			$st->execute([$wid, $cutoff]);
			$lastHour = null;
			$hourHasKept = false;
			// si tiene la prima foto ancora presente di ogni ora: nei giri
			// successivi è sempre la stessa
			while ($p = $st->fetch()) {
				$hour = substr((string) $p['taken_at'], 0, 13);
				if ($hour !== $lastHour) {
					$lastHour = $hour;
					$hourHasKept = false;
				}
				if (!$hourHasKept) {
					$hourHasKept = true;
					$stats['kept']++;
					continue;
				}
				if (in_array((int) $p['id'], $keepIds, true)) {
					$stats['kept']++;
					continue;
				}
				$abs = PhotoStore::abs((string) $p['storage_path']);
				if ($dryRun) {
					$stats['deleted']++;
					$stats['bytes'] += (int) $p['size_bytes'];
					continue;
				}
				if (is_file($abs)) {
					if (!@unlink($abs)) {
						if ($out) {
							$out("Impossibile cancellare $abs");
						}
						continue;
					}
					$stats['bytes'] += (int) $p['size_bytes'];
				} else {
					$stats['missing']++;
				}
				Db::exec('UPDATE photos SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$p['id']]);
				Images::forget($wid, (int) $p['id']); // riduzioni per i siti
				$stats['deleted']++;
			}
		}
		return $stats;
	}
}
