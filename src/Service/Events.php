<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Eventi creati dal portal (source = portal), non dal router.
 */
final class Events
{
	/** @param array<string,mixed>|null $data */
	public static function portal(int $webcamId, string $type, string $severity, string $message, ?array $data = null, ?int $ts = null): int
	{
		Db::exec(
			'INSERT INTO events (webcam_id, event_uid, ts, time_synced, received_at, type, severity, source, message, data)
			 VALUES (?, NULL, ?, 1, UTC_TIMESTAMP(), ?, ?, \'portal\', ?, ?)',
			[
				$webcamId, Db::dt($ts ?? time()), $type, $severity, mb_substr($message, 0, 500),
				$data === null ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			]
		);
		return (int) Db::pdo()->lastInsertId();
	}

	/** C'è già un evento di questo tipo per la webcam dopo $sinceUnix? */
	public static function recent(int $webcamId, string $type, int $sinceUnix): bool
	{
		return Db::scalar(
			'SELECT 1 FROM events WHERE webcam_id = ? AND type = ? AND ts >= ? LIMIT 1',
			[$webcamId, $type, Db::dt($sinceUnix)]
		) !== null;
	}
}
