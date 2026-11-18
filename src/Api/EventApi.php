<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Log;
use WebcamPortal\Service\Commands;

/**
 * POST /api/v1/event: lotto event/1. Idempotenza su (webcam_id, event_uid).
 */
final class EventApi
{
	public const MAX_BYTES = 262144;
	public const MAX_EVENTS = 100;
	private const SEVERITIES = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

	/** @return array{0:int, 1:array<string,mixed>} */
	public static function handle(Request $req): array
	{
		$auth = TokenAuth::authenticate($req, 'event');
		$webcam = $auth['webcam'];
		$wid = (int) $webcam['id'];
		$b = Body::json($req, self::MAX_BYTES);

		if (($b['schema'] ?? null) !== 'event/1') {
			throw new ApiError(400, 'unsupported_schema', 'Serve "schema": "event/1"');
		}
		TokenAuth::checkMac($webcam, $b['mac'] ?? null);
		if (!array_key_exists('events', $b)) {
			throw new ApiError(400, 'missing_field', 'Campo obbligatorio mancante: events', [], ['field' => 'events']);
		}
		if (!is_array($b['events']) || !array_is_list($b['events']) || $b['events'] === []) {
			throw new ApiError(400, 'invalid_field', 'events deve essere una lista di 1-100 eventi', [], ['field' => 'events']);
		}
		if (count($b['events']) > self::MAX_EVENTS) {
			throw new ApiError(413, 'too_many_events', 'Al massimo ' . self::MAX_EVENTS . ' eventi per lotto');
		}

		$accepted = 0;
		$duplicates = 0;
		$rejected = [];
		$st = Db::pdo()->prepare(
			'INSERT IGNORE INTO events (webcam_id, event_uid, ts, time_synced, received_at, type, severity, source, message, data)
			 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, \'router\', ?, ?)'
		);
		foreach ($b['events'] as $i => $e) {
			$eid = is_array($e) && is_string($e['id'] ?? null) ? $e['id'] : null;
			try {
				if (!V::isObject($e)) {
					throw new InvalidField("events[$i]", 'oggetto atteso');
				}
				foreach (['id', 'time', 'time_synced', 'type', 'severity'] as $k) {
					V::req($e, $k, '');
				}
				$uid = V::str($e, 'id', '', 24, '/^[0-9a-f]{8}-E[0-9]{1,10}$/');
				$t = V::int($e, 'time', '', 0);
				$synced = V::bool($e, 'time_synced', '');
				$type = V::str($e, 'type', '', 40, '/^[a-z][a-z0-9_.]{1,39}$/');
				$sev = V::enum($e, 'severity', '', self::SEVERITIES);
				$msg = V::str($e, 'message', '', 500);
				$data = V::obj($e, 'data', '');
				V::int($e, 'uptime', '', 0);
				if ($uid === null || $t === null || $synced === null || $type === null || $sev === null) {
					throw new MissingField('id');
				}
			} catch (InvalidField $ex) {
				$rejected[] = ['id' => $eid, 'error' => $ex instanceof MissingField ? 'missing_field' : 'invalid_field', 'field' => $ex->field];
				continue;
			}
			if (!TimeWindow::ok($t)) {
				$rejected[] = ['id' => $eid, 'error' => 'time_out_of_window'];
				continue;
			}
			// campi in più dell'evento (time_corrected, uptime, sconosciuti) restano in data
			$more = array_diff_key($e, array_flip(['id', 'time', 'time_synced', 'type', 'severity', 'message', 'data']));
			if ($more !== []) {
				$data = ($data ?? []) + ['_event' => $more];
			}
			$st->execute([
				$wid, $uid, Db::dt($t), $synced ? 1 : 0, $type, $sev, $msg,
				$data === null ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
			]);
			if ($st->rowCount() > 0) {
				$accepted++;
				if ($type === 'command.ack') {
					Commands::ack($wid, (int) Db::pdo()->lastInsertId(), $data, $synced ? $t : time());
				}
			} else {
				$duplicates++;
			}
		}
		Log::info("eventi webcam=$wid accettati=$accepted duplicati=$duplicates rifiutati=" . count($rejected));
		return [200, ['ok' => true, 'accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected]];
	}
}
