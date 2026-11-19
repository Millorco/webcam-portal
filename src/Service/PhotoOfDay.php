<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Telegram\Notify;

/**
 * Foto del giorno (M11, servizi-v1.md): all'ora photo_of_day.time
 * (sunset+<minuti> dal day_end dell'ultima foto del giorno, oppure HH:MM
 * locale) la foto buona con il punteggio più alto del giorno. Una scelta a
 * mano (operator/customer) non si sostituisce più ed è ammessa fino alle
 * 23:59 del giorno dopo. bin/photo-of-day.php, ogni 15 minuti.
 */
final class PhotoOfDay
{
	/** Ora (secondi Unix) in cui scegliere la foto del giorno $day, o null se non si sa ancora. */
	public static function dueAt(array $w, string $day): ?int
	{
		$tz = ServiceSettings::tz($w);
		$spec = trim((string) Config::get()->value('photo_of_day.time', 'sunset+45'));
		$start = new \DateTimeImmutable($day . ' 00:00:00', $tz);
		if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $spec, $m)) {
			return $start->setTime((int) $m[1], (int) $m[2])->getTimestamp();
		}
		$min = preg_match('/^sunset\+(\d{1,3})$/', $spec, $m) ? (int) $m[1] : 45;
		$end = Db::scalar(
			"SELECT JSON_VALUE(meta, '$.day_end') FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ?
			    AND JSON_VALUE(meta, '$.day_end') IS NOT NULL ORDER BY taken_at DESC LIMIT 1",
			[$w['id'], Db::dt($start->getTimestamp()), Db::dt($start->modify('+1 day')->getTimestamp())]
		);
		if (is_numeric($end) && (int) $end > $start->getTimestamp() && (int) $end < $start->modify('+1 day')->getTimestamp()) {
			return (int) $end + 60 * $min;
		}
		// senza day_end (router vecchio): alle 21:00 locali
		return $start->setTime(21, 0)->getTimestamp();
	}

	/** Sceglie la foto del giorno di oggi e di ieri (se non scelta) per le webcam con la funzione. @return int scelte */
	public static function run(int $now, ?int $webcamId = null): int
	{
		if (!(bool) Config::get()->value('photo_of_day.enabled', true)) {
			return 0;
		}
		$sql = 'SELECT * FROM webcams WHERE status = 1';
		$p = [];
		if ($webcamId !== null) {
			$sql .= ' AND id = ?';
			$p[] = $webcamId;
		}
		$n = 0;
		foreach (Db::all($sql . ' ORDER BY id', $p) as $w) {
			try {
				if (!Features::has($w, 'photo_of_day')) {
					continue;
				}
				$today = (new \DateTimeImmutable('@' . $now))->setTimezone(ServiceSettings::tz($w));
				foreach ([$today->modify('-1 day')->format('Y-m-d'), $today->format('Y-m-d')] as $day) {
					$due = self::dueAt($w, $day);
					if ($due === null || $now < $due) {
						continue;
					}
					if (Db::one('SELECT 1 FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$w['id'], $day]) !== null) {
						continue;
					}
					if (self::chooseAuto($w, $day)) {
						$n++;
					}
				}
			} catch (\Throwable $e) {
				Log::error("foto del giorno di {$w['slug']}: " . $e->getMessage(), 'photo-of-day');
			}
		}
		return $n;
	}

	/** La foto buona con il punteggio più alto del giorno; nessuna: nessuna foto del giorno. */
	public static function chooseAuto(array $w, string $day): bool
	{
		$best = Db::one(
			'SELECT s.photo_id, s.score FROM photo_scores s JOIN photos p ON p.id = s.photo_id
			  WHERE s.webcam_id = ? AND s.day = ? AND s.good = 1 AND p.deleted_at IS NULL AND p.is_public = 1
			  ORDER BY s.score DESC, s.taken_at LIMIT 1',
			[$w['id'], $day]
		);
		if ($best === null) {
			return false;
		}
		$ins = Db::exec(
			"INSERT IGNORE INTO photo_of_day (webcam_id, day, photo_id, score, chosen_by, chosen_at) VALUES (?, ?, ?, ?, 'auto', UTC_TIMESTAMP())",
			[$w['id'], $day, $best['photo_id'], $best['score']]
		);
		if ($ins > 0) {
			Log::info("foto del giorno di {$w['slug']} $day: foto {$best['photo_id']} (punteggio {$best['score']})", 'photo-of-day');
			self::notify($w, $day);
		}
		return $ins > 0;
	}

	/** Ultimo istante in cui si può scegliere a mano la foto del giorno $day (23:59:59 del giorno dopo). */
	public static function manualUntil(array $w, string $day): int
	{
		return (new \DateTimeImmutable($day . ' 00:00:00', ServiceSettings::tz($w)))->modify('+2 days')->getTimestamp() - 1;
	}

	/**
	 * Scelta a mano di un'altra foto dello stesso giorno.
	 *
	 * @param 'operator'|'customer' $by
	 * @throws \InvalidArgumentException con il messaggio da mostrare
	 */
	public static function choose(array $w, int $photoId, string $by, ?int $userId = null, ?int $now = null): string
	{
		$now ??= time();
		$p = Db::one('SELECT id, taken_at FROM photos WHERE id = ? AND webcam_id = ? AND deleted_at IS NULL AND is_public = 1', [$photoId, $w['id']]);
		if ($p === null) {
			throw new \InvalidArgumentException('Foto inesistente o nascosta');
		}
		$day = (new \DateTimeImmutable('@' . Db::unix((string) $p['taken_at'])))->setTimezone(ServiceSettings::tz($w))->format('Y-m-d');
		if ($now > self::manualUntil($w, $day)) {
			throw new \InvalidArgumentException('La foto del giorno si può cambiare fino alle 23:59 del giorno dopo');
		}
		$score = Db::scalar('SELECT score FROM photo_scores WHERE photo_id = ?', [$photoId]);
		Db::exec(
			'INSERT INTO photo_of_day (webcam_id, day, photo_id, score, chosen_by, user_id, chosen_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
			 ON DUPLICATE KEY UPDATE photo_id = VALUES(photo_id), score = VALUES(score), chosen_by = VALUES(chosen_by), user_id = VALUES(user_id), chosen_at = VALUES(chosen_at)',
			[$w['id'], $day, $photoId, $score, $by === 'customer' ? 'customer' : 'operator', $userId]
		);
		Log::info("foto del giorno di {$w['slug']} $day scelta a mano ($by): foto $photoId", 'photo-of-day');
		self::notify($w, $day);
		return $day;
	}

	/** Avviso Telegram photo_of_day, una volta sola per giorno (telegram_sent_at). */
	private static function notify(array $w, string $day): void
	{
		$r = Db::one('SELECT photo_id, telegram_sent_at FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$w['id'], $day]);
		if ($r === null || $r['telegram_sent_at'] !== null) {
			return;
		}
		Notify::photoOfDay($w, $day, (int) $r['photo_id']);
		Db::exec('UPDATE photo_of_day SET telegram_sent_at = UTC_TIMESTAMP() WHERE webcam_id = ? AND day = ?', [$w['id'], $day]);
	}
}
