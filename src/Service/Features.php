<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;

/**
 * Funzioni attive per una webcam dal livello di listino e dalle opzioni
 * letti da Dolibarr (api-dolibarr-v2.md, "Matrice livello -> funzioni"): il
 * calcolo lo fa solo il portal; i plugin leggono il risultato (features
 * dell'API dei siti). Quello che il server sa produrre (ffmpeg, bot) è a
 * parte: server().
 */
final class Features
{
	public const LEVELS = ['base', 'standard', 'pro'];

	/** Funzioni per livello (cumulative). */
	public const MATRIX = [
		'base' => ['archive', 'availability'],
		'standard' => ['overlay', 'same_moment', 'timelapse', 'photo_of_day', 'monthly_report', 'telegram'],
		'pro' => ['timelapse_portrait', 'timelapse_custom', 'stats', 'sponsor', 'weather_cert'],
	];

	/** Etichette italiane per il pannello. */
	public const LABELS = [
		'archive' => 'archivio, misure e confronto', 'availability' => 'disponibilità del servizio',
		'overlay' => 'sovrimpressione dati e logo', 'same_moment' => 'stesso momento in giorni diversi',
		'timelapse' => 'timelapse giorno/settimana/mese', 'photo_of_day' => 'foto del giorno',
		'monthly_report' => 'rapporto mensile PDF', 'telegram' => 'bot Telegram',
		'timelapse_portrait' => 'timelapse verticali', 'timelapse_custom' => 'timelapse di un periodo scelto',
		'stats' => 'statistiche di visualizzazione', 'sponsor' => 'sponsor e infopoint',
		'weather_cert' => 'giornate di maltempo certificate', 'final_film' => 'filmato finale',
	];

	/** Funzioni di consultazione valide per le webcam in archivio (api-siti-v1.md). */
	public const ARCHIVED = ['archive', 'same_moment', 'timelapse', 'photo_of_day', 'monthly_report', 'weather_cert'];

	/** @var array<int,list<string>> */
	private static array $cache = [];

	public static function reset(): void
	{
		self::$cache = [];
	}

	/** Livello, opzioni e periodo del noleggio della webcam (webcam_service). */
	public static function plan(int $webcamId): array
	{
		$r = Db::one('SELECT plan_level, plan_options, rental_start, rental_end, timelapse_params, weather_params FROM webcam_service WHERE webcam_id = ?', [$webcamId]);
		$opt = $r !== null && $r['plan_options'] !== null ? json_decode((string) $r['plan_options'], true) : null;
		return [
			'level' => in_array($r['plan_level'] ?? null, self::LEVELS, true) ? (string) $r['plan_level'] : 'base',
			'options' => is_array($opt) ? $opt : [],
			'rental_start' => $r['rental_start'] ?? null,
			'rental_end' => $r['rental_end'] ?? null,
		];
	}

	/**
	 * Calcolo puro della matrice.
	 *
	 * @param array<string,mixed> $options come "options" di GET webcams/{id}/portal
	 * @return list<string> ordinate per nome
	 */
	public static function compute(?string $level, array $options, ?string $today = null): array
	{
		if ($level === null || $level === '') {
			$level = 'base';
		}
		if (!in_array($level, self::LEVELS, true)) {
			Log::warning("livello di listino sconosciuto \"$level\": vale base");
			$level = 'base';
		}
		$out = [];
		foreach (self::LEVELS as $l) {
			$out = array_merge($out, self::MATRIX[$l]);
			if ($l === $level) {
				break;
			}
		}
		$today ??= gmdate('Y-m-d');
		// sponsor: pro o pacchetto sponsor_enabled, e solo nel periodo del pacchetto
		$sp = is_array($options['sponsor'] ?? null) ? $options['sponsor'] : [];
		$sponsor = $level === 'pro' || !empty($sp['enabled']);
		$start = is_string($sp['start'] ?? null) && $sp['start'] !== '' ? $sp['start'] : null;
		$end = is_string($sp['end'] ?? null) && $sp['end'] !== '' ? $sp['end'] : null;
		if (($start !== null && $today < $start) || ($end !== null && $today > $end)) {
			$sponsor = false;
		}
		$out = array_values(array_diff($out, ['sponsor']));
		if ($sponsor) {
			$out[] = 'sponsor';
		}
		if (!empty($options['weather_cert'])) {
			$out[] = 'weather_cert';
		}
		$film = is_array($options['film'] ?? null) ? (string) ($options['film']['option'] ?? '') : '';
		if (in_array($film, ['auto', 'edited'], true)) {
			$out[] = 'final_film';
		}
		$out = array_values(array_unique($out));
		sort($out);
		return $out;
	}

	/** @return list<string> funzioni attive oggi per la webcam */
	public static function forWebcam(array|int $webcam): array
	{
		$w = is_array($webcam) ? $webcam : (array) Db::one('SELECT id, timezone FROM webcams WHERE id = ?', [$webcam]);
		$id = (int) ($w['id'] ?? 0);
		if (isset(self::$cache[$id])) {
			return self::$cache[$id];
		}
		$p = self::plan($id);
		$today = (new \DateTimeImmutable('now', ServiceSettings::tz($w)))->format('Y-m-d');
		return self::$cache[$id] = self::compute($p['level'], $p['options'], $today);
	}

	public static function has(array|int $webcam, string $feature): bool
	{
		return in_array($feature, self::forWebcam($webcam), true);
	}

	/**
	 * Servizi che il server sa produrre (whoami.server dell'API dei siti).
	 *
	 * @return array{timelapse:bool, telegram:bool, reason:array<string,string>}
	 */
	public static function server(bool $probe = false): array
	{
		$reason = [];
		$tl = (bool) Config::get()->value('timelapse.enabled', true);
		if (!$tl) {
			$reason['timelapse'] = 'disabled';
		} else {
			$ff = Ffmpeg::status($probe);
			if (!$ff['ok']) {
				$tl = false;
				$reason['timelapse'] = $ff['reason'];
			}
		}
		$tg = \WebcamPortal\Telegram\Bot::configured();
		if (!$tg) {
			$reason['telegram'] = 'not_configured';
		}
		return ['timelapse' => $tl, 'telegram' => $tg, 'reason' => $reason === [] ? (object) [] : $reason];
	}
}
