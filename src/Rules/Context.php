<?php

declare(strict_types=1);

namespace WebcamPortal\Rules;

use WebcamPortal\Db;
use WebcamPortal\Service\ServiceSettings;

/**
 * Dati di una webcam per una valutazione: la webcam, le impostazioni di
 * servizio, l'ora della valutazione ($now, anche nel passato per i test e
 * per ricalcolare), l'avviso aperto della regola e le soglie.
 *
 * Tutte le query usano solo dati con ora <= $now e time_synced = 1.
 */
final class Context
{
	/** @var array<string,mixed> */
	public array $params = [];
	public ?array $alert = null;
	public ?array $state = null;
	public \DateTimeZone $tz;
	/** @var array<string,array<string,mixed>> avvisi aperti della webcam per regola */
	public array $openAlerts = [];
	/** @var array<string,mixed> dati condivisi tra le regole (log, cache) */
	public array $shared = [];

	private ?array $lastSample = null;
	private bool $lastLoaded = false;

	/** @param array<string,mixed> $webcam @param array<string,mixed> $service */
	public function __construct(public array $webcam, public array $service, public int $now)
	{
		$this->tz = ServiceSettings::tz($webcam);
	}

	public function id(): int
	{
		return (int) $this->webcam['id'];
	}

	public function p(string $k): mixed
	{
		return $this->params[$k] ?? null;
	}

	public function f(string $k): float
	{
		return (float) ($this->params[$k] ?? 0);
	}

	public function i(string $k): int
	{
		return (int) ($this->params[$k] ?? 0);
	}

	/**
	 * Campioni sincronizzati in (from, now], dal più vecchio, con t = ora Unix.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function samples(int $from, string $cols, ?int $to = null): array
	{
		return Db::all(
			"SELECT UNIX_TIMESTAMP(ts) AS t, $cols FROM measurements
			  WHERE webcam_id = ? AND ts > ? AND ts <= ? AND time_synced = 1 ORDER BY ts",
			[$this->id(), Db::dt($from), Db::dt($to ?? $this->now)]
		);
	}

	/** Ultimo campione sincronizzato fino a $now (null se non ce n'è nelle ultime 24 ore). */
	public function lastSample(): ?array
	{
		if (!$this->lastLoaded) {
			$this->lastLoaded = true;
			$r = Db::one(
				"SELECT UNIX_TIMESTAMP(ts) AS t, m.*, JSON_VALUE(extra, '$.sys.maintenance') = 'true' AS maintenance
				   FROM measurements m WHERE webcam_id = ? AND ts > ? AND ts <= ? AND time_synced = 1 ORDER BY ts DESC LIMIT 1",
				[$this->id(), Db::dt($this->now - 86400), Db::dt($this->now)]
			);
			$this->lastSample = $r;
		}
		return $this->lastSample;
	}

	/** Manutenzione: ultimo campione con sys.maintenance o webcam non in servizio. */
	public function inMaintenance(): bool
	{
		if ((int) $this->webcam['status'] !== 1) {
			return true;
		}
		$l = $this->lastSample();
		return $l !== null && (int) $l['maintenance'] === 1;
	}

	public function slaEnabled(): bool
	{
		return (int) $this->service['sla_enabled'] === 1;
	}

	public function local(int $ts, string $fmt = 'd/m/Y H:i'): string
	{
		return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz)->format($fmt);
	}

	public function day(int $ts): string
	{
		return $this->local($ts, 'Y-m-d');
	}

	/** Il sole è sopra l'orizzonte? Dalla posizione della webcam, altrimenti 07-19 ora locale. */
	public function isDaylight(int $ts): bool
	{
		$lat = $this->webcam['latitude'] ?? null;
		$lon = $this->webcam['longitude'] ?? null;
		if ($lat !== null && $lon !== null) {
			$info = date_sun_info($ts, (float) $lat, (float) $lon);
			$rise = $info['sunrise'];
			$set = $info['sunset'];
			if (is_int($rise) && is_int($set)) {
				// date_sun_info lavora sul giorno UTC di $ts: basta per l'Europa
				return $ts >= $rise && $ts <= $set;
			}
			return $rise === true; // sole di mezzanotte (true) o notte polare (false)
		}
		$h = (int) $this->local($ts, 'G');
		return $h >= 7 && $h < 19;
	}

	public static function num(?float $v, int $dec = 1): string
	{
		return $v === null ? '—' : number_format($v, $dec, ',', '.');
	}
}
