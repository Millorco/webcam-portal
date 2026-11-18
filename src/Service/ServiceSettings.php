<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Impostazioni di servizio per webcam (tabella webcam_service): soglie del
 * contratto di noleggio (sla_*), vita dell'otturatore e link rttys copiati da
 * Dolibarr (GET webcams/{id}/portal), oppure impostati nel pannello.
 */
final class ServiceSettings
{
	public const DEFAULTS = [
		'sla_enabled' => 0, 'sla_offline_hours' => 3, 'sla_response_days' => 3, 'sla_target_pct' => 98.0,
		'sla_hours' => null, 'sla_weekdays' => null, 'shutter_life' => null, 'shutter_warn_pct' => null,
		'rttys_terminal_url' => null, 'rttys_luci_url' => null, 'photo_metrics' => 1, 'source' => 'portal', 'synced_at' => null,
	];

	/** @return array<string,mixed> */
	public static function get(int $webcamId): array
	{
		$r = Db::one('SELECT * FROM webcam_service WHERE webcam_id = ?', [$webcamId]);
		$out = self::DEFAULTS;
		if ($r !== null) {
			foreach (self::DEFAULTS as $k => $v) {
				$out[$k] = $r[$k];
			}
		}
		$out['sla_enabled'] = (int) $out['sla_enabled'];
		$out['sla_offline_hours'] = (int) $out['sla_offline_hours'];
		$out['sla_response_days'] = (int) $out['sla_response_days'];
		$out['sla_target_pct'] = (float) $out['sla_target_pct'];
		$out['photo_metrics'] = (int) $out['photo_metrics'];
		$out['exists'] = $r !== null;
		return $out;
	}

	/**
	 * Scrive le impostazioni (solo le chiavi note). Controlla sla_hours e
	 * sla_weekdays come i CHECK della tabella.
	 *
	 * @param array<string,mixed> $in
	 */
	public static function save(int $webcamId, array $in, string $source = 'portal'): void
	{
		$cur = self::get($webcamId);
		$v = [];
		foreach (self::DEFAULTS as $k => $d) {
			if (in_array($k, ['source', 'synced_at'], true)) {
				continue;
			}
			$v[$k] = array_key_exists($k, $in) ? $in[$k] : $cur[$k];
		}
		$v['sla_hours'] = self::normHours($v['sla_hours']);
		$v['sla_weekdays'] = self::normWeekdays($v['sla_weekdays']);
		$v['sla_enabled'] = !empty($v['sla_enabled']) ? 1 : 0;
		$v['photo_metrics'] = !empty($v['photo_metrics']) ? 1 : 0;
		foreach (['sla_offline_hours' => [1, 720], 'sla_response_days' => [1, 365]] as $k => [$min, $max]) {
			$n = (int) $v[$k];
			if ($n < $min || $n > $max) {
				throw new \InvalidArgumentException("$k fuori intervallo ($min-$max)");
			}
			$v[$k] = $n;
		}
		$t = (float) str_replace(',', '.', (string) $v['sla_target_pct']);
		if ($t < 0 || $t > 100) {
			throw new \InvalidArgumentException('sla_target_pct tra 0 e 100');
		}
		$v['sla_target_pct'] = round($t, 2);
		$cols = array_keys($v);
		$sql = 'INSERT INTO webcam_service (webcam_id, ' . implode(', ', $cols) . ', source, synced_at) VALUES (?, '
			. implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?) ON DUPLICATE KEY UPDATE '
			. implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", $cols)) . ', source = VALUES(source), synced_at = VALUES(synced_at)';
		Db::exec($sql, array_merge([$webcamId], array_values($v), [$source, $source === 'dolibarr' ? Db::dt(time()) : $cur['synced_at']]));
	}

	public static function normHours(mixed $h): ?string
	{
		$h = trim((string) ($h ?? ''));
		if ($h === '') {
			return null;
		}
		if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]-(([01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/', $h)) {
			throw new \InvalidArgumentException('Ore di scatto previste nel formato HH:MM-HH:MM (es. 07:00-19:00)');
		}
		return $h;
	}

	public static function normWeekdays(mixed $d): ?string
	{
		$d = preg_replace('/[^0-9]/', '', (string) ($d ?? '')) ?? '';
		if ($d === '') {
			return null;
		}
		if (!preg_match('/^[1-7]{1,7}$/', $d)) {
			throw new \InvalidArgumentException('Giorni previsti: cifre da 1 (lunedì) a 7 (domenica)');
		}
		$u = array_unique(str_split($d));
		sort($u);
		return implode('', $u);
	}

	/**
	 * L'istante cade nelle ore di scatto previste (sla_hours, sla_weekdays,
	 * ora locale della webcam)? Vuoti = sempre.
	 *
	 * @param array<string,mixed> $s
	 */
	public static function expectedAt(array $s, \DateTimeZone $tz, int $ts): bool
	{
		$d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
		if (!empty($s['sla_weekdays']) && !str_contains((string) $s['sla_weekdays'], $d->format('N'))) {
			return false;
		}
		if (empty($s['sla_hours'])) {
			return true;
		}
		[$a, $b] = explode('-', (string) $s['sla_hours']);
		$min = (int) $d->format('G') * 60 + (int) $d->format('i');
		$from = (int) substr($a, 0, 2) * 60 + (int) substr($a, 3, 2);
		$to = (int) substr($b, 0, 2) * 60 + (int) substr($b, 3, 2);
		if ($from <= $to) {
			return $min >= $from && $min < $to;
		}
		return $min >= $from || $min < $to; // fascia a cavallo della mezzanotte
	}

	public static function tz(array $webcam): \DateTimeZone
	{
		try {
			return new \DateTimeZone((string) ($webcam['timezone'] ?? 'UTC'));
		} catch (\Throwable $e) {
			return new \DateTimeZone('UTC');
		}
	}
}
