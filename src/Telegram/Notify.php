<?php

declare(strict_types=1);

namespace WebcamPortal\Telegram;

use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\ServiceSettings;

/**
 * Avvisi del bot Telegram (servizi-v1.md, "Avvisi"): in coda per ogni chat
 * attiva iscritta all'avviso della webcam, una volta sola (dedupe_key).
 * Niente dettagli tecnici. Gli errori non devono mai fermare chi chiama.
 */
final class Notify
{
	private static function name(array $w): string
	{
		return (string) ($w['name'] ?: $w['slug']);
	}

	private static function at(array $w, int $t, string $fmt = 'H:i'): string
	{
		return (new \DateTimeImmutable('@' . $t))->setTimezone(ServiceSettings::tz($w))->format($fmt);
	}

	/** @return int messaggi messi in coda */
	public static function enqueue(array $w, string $alert, string $kind, string $text, ?int $photoId, string $dedupe): int
	{
		if (!Bot::configured()) {
			return 0;
		}
		try {
			if (!Features::has($w, 'telegram')) {
				return 0;
			}
			$n = 0;
			foreach (Db::all(
				"SELECT c.id FROM telegram_subscriptions s JOIN telegram_chats c ON c.id = s.chat_ref
				  WHERE s.webcam_id = ? AND c.status = 'active' AND c.customer_id = ? AND FIND_IN_SET(?, s.alerts) > 0",
				[$w['id'], (int) $w['customer_id'], $alert]
			) as $c) {
				Bot::queue((int) $c['id'], (int) $w['id'], $kind, $text, $photoId, $alert, mb_substr($dedupe, 0, 80));
				$n++;
			}
			return $n;
		} catch (\Throwable $e) {
			Log::error("avviso Telegram $alert non messo in coda: " . $e->getMessage(), 'telegram');
			return 0;
		}
	}

	/** Avviso camera.stalled / telemetry.stalled aperto o chiuso (Rules\Alerts). */
	public static function alert(int $alertId, bool $opened): void
	{
		try {
			$a = Db::one('SELECT * FROM alerts WHERE id = ?', [$alertId]);
			if ($a === null || !in_array($a['rule_id'], ['camera.stalled', 'telemetry.stalled'], true)) {
				return;
			}
			$w = Db::one('SELECT * FROM webcams WHERE id = ?', [$a['webcam_id']]);
			if ($w === null || $w['customer_id'] === null) {
				return;
			}
			$last = $w['last_photo_at'] === null ? null : (int) Db::unix((string) $w['last_photo_at']);
			if ($opened) {
				$text = self::name($w) . ($last === null ? ' non manda foto.' : ' non manda foto dalle ' . self::at($w, $last) . '.') . ' Il servizio tecnico è già avvisato.';
				self::enqueue($w, 'offline', 'alert', $text, null, "offline:$alertId:{$a['reopen_count']}");
			} else {
				$text = self::name($w) . ' è di nuovo attiva' . ($last === null ? '.' : ' (ultima foto ' . self::at($w, $last) . ').');
				self::enqueue($w, 'online', 'alert', $text, null, "online:$alertId:{$a['reopen_count']}");
			}
		} catch (\Throwable $e) {
			Log::error('avviso Telegram per l\'avviso ' . $alertId . ': ' . $e->getMessage(), 'telegram');
		}
	}

	public static function photoOfDay(array $w, string $day, int $photoId): void
	{
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
		self::enqueue($w, 'photo_of_day', 'photo_of_day', 'Foto del giorno, ' . ($d ? $d->format('d/m/Y') : $day) . ' · ' . self::name($w), $photoId, "pod:{$w['id']}:$day");
	}

	public static function timelapse(array $w, array $t): void
	{
		$what = $t['kind'] === 'week' ? 'della settimana' : 'del mese';
		$link = trim((string) \WebcamPortal\Config::get()->value('reports.link_url', ''));
		$text = self::name($w) . ": timelapse $what pronto (" . $t['period_from'] . ' - ' . $t['period_to'] . ').' . ($link !== '' ? "\n$link" : '');
		$poster = null;
		$frames = json_decode((string) ($t['frames'] ?? '[]'), true);
		if (is_array($frames) && $frames !== []) {
			$poster = (int) $frames[intdiv(count($frames), 2)];
		}
		self::enqueue($w, 'timelapse', 'alert', $text, $poster, "tl:{$t['id']}");
	}

	public static function report(array $w, array $r, string $monthLabel, ?string $to): void
	{
		self::enqueue($w, 'report', 'alert', "Il rapporto di $monthLabel di " . self::name($w) . ' è stato inviato' . ($to ? " a $to." : '.'), null, "rep:{$r['id']}");
	}

	public static function badWeather(array $w, array $row): void
	{
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['day']);
		self::enqueue($w, 'bad_weather', 'alert', self::name($w) . ': ' . ($d ? $d->format('d/m/Y') : $row['day']) . ' giornata di maltempo registrata: ' . (int) $row['wet_hours'] . ' ore umide.', null, "bw:{$w['id']}:{$row['day']}");
	}

	/** Primo campione del giorno con t_ext <= 0 °C (avviso frost). */
	public static function scanFrost(int $now): int
	{
		if (!Bot::configured()) {
			return 0;
		}
		$n = 0;
		foreach (Db::all("SELECT DISTINCT w.* FROM webcams w JOIN telegram_subscriptions s ON s.webcam_id = w.id WHERE w.status = 1 AND FIND_IN_SET('frost', s.alerts) > 0") as $w) {
			$tz = ServiceSettings::tz($w);
			$start = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
			$m = Db::one('SELECT ts, t_ext FROM measurements WHERE webcam_id = ? AND ts >= ? AND ts <= ? AND t_ext <= 0 ORDER BY ts LIMIT 1', [$w['id'], Db::dt($start->getTimestamp()), Db::dt($now)]);
			if ($m !== null) {
				$t = (int) Db::unix((string) $m['ts']);
				$n += self::enqueue($w, 'frost', 'alert', self::name($w) . ': gelo, ' . number_format((float) $m['t_ext'], 1, ',', '') . ' °C alle ' . self::at($w, $t) . '.', null, "frost:{$w['id']}:" . $start->format('Y-m-d'));
			}
		}
		return $n;
	}
}
