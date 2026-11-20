<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Config;

/**
 * Finestra delle ore accettate: da now - 400 giorni (o dall'inizio della
 * retention delle misure, se più recente) a now + 10 minuti.
 */
final class TimeWindow
{
	public static function min(bool $measurements = false, ?int $now = null): int
	{
		$now ??= time();
		$min = $now - 400 * 86400;
		if ($measurements) {
			$months = (int) Config::get()->value('retention_measurements_months', 12);
			$ret = (new \DateTimeImmutable('@' . $now))->modify("-$months months")->getTimestamp();
			$min = max($min, $ret);
		}
		return $min;
	}

	public static function max(?int $now = null): int
	{
		return ($now ?? time()) + 600;
	}

	public static function ok(int $t, bool $measurements = false): bool
	{
		return $t >= self::min($measurements) && $t <= self::max();
	}
}
