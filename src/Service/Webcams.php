<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Creazione e modifica delle webcam (pannello e bin/webcam-add.php).
 */
final class Webcams
{
	public static function normalizeMac(string $mac): string
	{
		$hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '');
		if (strlen($hex) !== 12) {
			throw new \InvalidArgumentException('MAC non valido: servono 12 cifre esadecimali (es. 94:83:c4:12:ab:cd)');
		}
		return implode(':', str_split($hex, 2));
	}

	public static function slugify(string $s): string
	{
		$s = strtolower(trim($s));
		$t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
		if (is_string($t) && $t !== '') {
			$s = strtolower($t);
		}
		$s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
		return substr(trim($s, '-'), 0, 64);
	}

	public static function validSlug(string $slug): bool
	{
		return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug);
	}

	public static function validTimezone(string $tz): bool
	{
		return in_array($tz, \DateTimeZone::listIdentifiers(), true) || $tz === 'UTC';
	}

	/**
	 * @param array{is_public?:bool, timezone?:string, status?:int, location?:?string} $opt
	 * @return int id della webcam
	 */
	public static function create(string $mac, string $name, string $slug = '', array $opt = []): int
	{
		$mac = self::normalizeMac($mac);
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Nome obbligatorio');
		}
		$slug = $slug === '' ? self::slugify($name) : strtolower(trim($slug));
		if (!self::validSlug($slug)) {
			throw new \InvalidArgumentException('Slug non valido: lettere minuscole, cifre e trattini (es. monte-cavo)');
		}
		$tz = $opt['timezone'] ?? 'Europe/Rome';
		if (!self::validTimezone($tz)) {
			throw new \InvalidArgumentException("Fuso orario sconosciuto: $tz");
		}
		$status = (int) ($opt['status'] ?? 1);
		if (!in_array($status, [0, 1, 9], true)) {
			throw new \InvalidArgumentException('Stato non valido (0, 1 o 9)');
		}
		if (Db::scalar('SELECT id FROM webcams WHERE mac = ?', [$mac]) !== null) {
			throw new \InvalidArgumentException("Esiste già una webcam con MAC $mac");
		}
		if (Db::scalar('SELECT id FROM webcams WHERE slug = ?', [$slug]) !== null) {
			throw new \InvalidArgumentException("Slug già usato: $slug");
		}
		Db::exec(
			'INSERT INTO webcams (mac, slug, name, status, is_public, timezone, location, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
			[$mac, $slug, mb_substr($name, 0, 255), $status, !empty($opt['is_public']) ? 1 : 0, $tz, $opt['location'] ?? null]
		);
		return (int) Db::pdo()->lastInsertId();
	}

	/** @param array<string,mixed> $in campi del modulo di modifica */
	public static function update(int $id, array $in): void
	{
		$name = trim((string) ($in['name'] ?? ''));
		$slug = strtolower(trim((string) ($in['slug'] ?? '')));
		$tz = trim((string) ($in['timezone'] ?? 'Europe/Rome'));
		$status = (int) ($in['status'] ?? 1);
		if ($name === '') {
			throw new \InvalidArgumentException('Nome obbligatorio');
		}
		if (!self::validSlug($slug)) {
			throw new \InvalidArgumentException('Slug non valido');
		}
		if (!self::validTimezone($tz)) {
			throw new \InvalidArgumentException("Fuso orario sconosciuto: $tz");
		}
		if (!in_array($status, [0, 1, 9], true)) {
			throw new \InvalidArgumentException('Stato non valido');
		}
		if (Db::scalar('SELECT id FROM webcams WHERE slug = ? AND id <> ?', [$slug, $id]) !== null) {
			throw new \InvalidArgumentException("Slug già usato: $slug");
		}
		$geo = [];
		foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180], 'altitude_m' => [-500, 9000]] as $k => [$lo, $hi]) {
			if (!array_key_exists($k, $in)) {
				continue;
			}
			$v = str_replace(',', '.', trim((string) $in[$k]));
			if ($v !== '' && (!is_numeric($v) || (float) $v < $lo || (float) $v > $hi)) {
				throw new \InvalidArgumentException("Valore non valido per $k: $v");
			}
			$geo[$k] = $v === '' ? null : ($k === 'altitude_m' ? (int) round((float) $v) : round((float) $v, 6));
		}
		if ($geo !== []) {
			Db::exec('UPDATE webcams SET ' . implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($geo))) . ' WHERE id = ?', array_merge(array_values($geo), [$id]));
		}
		$loc = trim((string) ($in['location'] ?? ''));
		$windyId = trim((string) ($in['windy_id'] ?? ''));
		Db::exec(
			'UPDATE webcams SET name = ?, slug = ?, timezone = ?, status = ?, is_public = ?, location = ?,
			   windy_published = ?, windy_id = ? WHERE id = ?',
			[
				mb_substr($name, 0, 255), $slug, $tz, $status, !empty($in['is_public']) ? 1 : 0,
				$loc === '' ? null : mb_substr($loc, 0, 255), !empty($in['windy_published']) ? 1 : 0,
				$windyId === '' ? null : substr($windyId, 0, 64), $id,
			]
		);
	}
}
