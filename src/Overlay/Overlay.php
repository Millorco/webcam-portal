<?php

declare(strict_types=1);

namespace WebcamPortal\Overlay;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\ServiceSettings;

/**
 * Sovrimpressione (M2) e sponsor/infopoint (M12), servizi-v1.md: configurazione
 * per webcam (overlay_config), loghi (overlay_assets), elementi con periodo di
 * validità (overlay_items). Il disegno è in Renderer; l'originale non si
 * tocca mai: si disegna solo sulle immagini derivate.
 */
final class Overlay
{
	public const FIELDS = ['datetime', 'temperature_ext', 'humidity_ext', 'dewpoint_ext', 'name', 'location', 'altitude', 'text'];
	public const APPLY = ['640', '1280', '1920', 'current', 'social', 'timelapse'];

	/** @var array<int,?array> */
	private static array $cfg = [];

	public static function reset(): void
	{
		self::$cfg = [];
	}

	public static function config(int $webcamId): ?array
	{
		if (!array_key_exists($webcamId, self::$cfg)) {
			self::$cfg[$webcamId] = Db::one('SELECT * FROM overlay_config WHERE webcam_id = ?', [$webcamId]);
		}
		return self::$cfg[$webcamId];
	}

	/** Configurazione attiva (overlay.enabled, riga abilitata, funzione overlay) o null. */
	public static function active(array $w): ?array
	{
		if (!(bool) Config::get()->value('overlay.enabled', true)) {
			return null;
		}
		$c = self::config((int) $w['id']);
		if ($c === null || (int) $c['enabled'] !== 1 || !Features::has($w, 'overlay')) {
			return null;
		}
		return $c;
	}

	/** La sovrimpressione vale per questo tipo di immagine ("640", "current", "social"...)? */
	public static function appliesTo(?array $cfg, string $what): bool
	{
		return $cfg !== null && in_array($what, explode(',', (string) $cfg['apply_to']), true);
	}

	/** overlay_rev cresce a ogni modifica (configurazione, logo, sponsor). */
	public static function bump(int $webcamId): void
	{
		Db::exec('UPDATE overlay_config SET overlay_rev = overlay_rev + 1 WHERE webcam_id = ?', [$webcamId]);
		unset(self::$cfg[$webcamId]);
	}

	/**
	 * Salva la configurazione dal pannello (valori controllati come i CHECK).
	 *
	 * @param array<string,mixed> $in
	 */
	public static function save(int $webcamId, array $in, ?int $userId = null): void
	{
		$fields = array_values(array_intersect(self::FIELDS, (array) ($in['fields'] ?? [])));
		if (!in_array('datetime', $fields, true)) {
			array_unshift($fields, 'datetime'); // la data c'è sempre
		}
		$apply = array_values(array_intersect(self::APPLY, (array) ($in['apply_to'] ?? [])));
		$int = static fn (string $k, int $d, int $min, int $max): int => max($min, min($max, (int) ($in[$k] ?? $d)));
		$enum = static fn (string $k, array $ok, string $d): string => in_array($in[$k] ?? null, $ok, true) ? (string) $in[$k] : $d;
		$logo = (int) ($in['logo_asset_id'] ?? 0);
		if ($logo > 0 && Db::scalar('SELECT id FROM overlay_assets WHERE id = ? AND deleted_at IS NULL', [$logo]) === null) {
			throw new \InvalidArgumentException('Logo inesistente');
		}
		$text = trim((string) ($in['custom_text'] ?? ''));
		$v = [
			!empty($in['enabled']) ? 1 : 0,
			implode(',', $fields),
			$text === '' ? null : mb_substr($text, 0, 120),
			$enum('bar_position', ['top', 'bottom'], 'bottom'),
			$enum('text_align', ['left', 'center', 'right'], 'left'),
			$int('bar_opacity_pct', 55, 0, 90),
			$int('font_scale_pct', 100, 50, 200),
			$enum('locale', ['it', 'en', 'de', 'fr'], 'it'),
			$logo > 0 ? $logo : null,
			$enum('logo_position', ['tl', 'tr', 'bl', 'br'], 'tr'),
			$int('logo_width_pct', 12, 3, 30),
			$int('logo_opacity_pct', 100, 10, 100),
			implode(',', $apply),
			$enum('current_size', ['1280', '1920', 'orig'], '1920'),
			$userId,
		];
		Db::exec(
			'INSERT INTO overlay_config (webcam_id, enabled, fields, custom_text, bar_position, text_align, bar_opacity_pct, font_scale_pct, locale,
			   logo_asset_id, logo_position, logo_width_pct, logo_opacity_pct, apply_to, current_size, user_id)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), fields = VALUES(fields), custom_text = VALUES(custom_text),
			   bar_position = VALUES(bar_position), text_align = VALUES(text_align), bar_opacity_pct = VALUES(bar_opacity_pct),
			   font_scale_pct = VALUES(font_scale_pct), locale = VALUES(locale), logo_asset_id = VALUES(logo_asset_id),
			   logo_position = VALUES(logo_position), logo_width_pct = VALUES(logo_width_pct), logo_opacity_pct = VALUES(logo_opacity_pct),
			   apply_to = VALUES(apply_to), current_size = VALUES(current_size), user_id = VALUES(user_id), overlay_rev = overlay_rev + 1',
			array_merge([$webcamId], $v)
		);
		unset(self::$cfg[$webcamId]);
	}

	// ------------------------------------------------------------ loghi

	/**
	 * Carica un logo o l'immagine di uno sponsor: PNG o JPEG, al più 2 MB e
	 * 2000 x 2000 px, riscritto con GD (si toglie ogni metadato).
	 */
	public static function addAsset(string $tmpFile, string $kind, ?int $customerId, ?string $label, ?int $userId = null): int
	{
		if (!in_array($kind, ['logo', 'sponsor'], true)) {
			throw new \InvalidArgumentException('Tipo di immagine non valido');
		}
		$size = @filesize($tmpFile);
		if ($size === false || $size === 0 || $size > 2 * 1048576) {
			throw new \InvalidArgumentException('Immagine vuota o più grande di 2 MB');
		}
		$info = @getimagesize($tmpFile);
		if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
			throw new \InvalidArgumentException('Solo PNG o JPEG');
		}
		if ($info[0] > 2000 || $info[1] > 2000 || $info[0] < 1 || $info[1] < 1) {
			throw new \InvalidArgumentException('Al più 2000 x 2000 pixel');
		}
		$png = $info[2] === IMAGETYPE_PNG;
		$im = $png ? @imagecreatefrompng($tmpFile) : @imagecreatefromjpeg($tmpFile);
		if ($im === false) {
			throw new \InvalidArgumentException('Immagine illeggibile');
		}
		if ($png) {
			imagealphablending($im, false);
			imagesavealpha($im, true);
		}
		$dir = Config::get()->storageDir() . '/overlay';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$tmp = $dir . '/upload-' . getmypid() . '-' . bin2hex(random_bytes(4)) . ($png ? '.png' : '.jpg');
		$ok = $png ? imagepng($im, $tmp, 6) : imagejpeg($im, $tmp, 90);
		imagedestroy($im);
		if (!$ok) {
			@unlink($tmp);
			throw new \RuntimeException('Impossibile salvare l\'immagine');
		}
		$sha = (string) hash_file('sha256', $tmp);
		Db::exec(
			'INSERT INTO overlay_assets (customer_id, kind, label, storage_path, mime, width, height, size_bytes, sha256, created_by)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$customerId, $kind, $label === null || trim($label) === '' ? null : mb_substr(trim($label), 0, 100), 'overlay/pending',
				$png ? 'image/png' : 'image/jpeg', $info[0], $info[1], (int) filesize($tmp), $sha, $userId]
		);
		$id = (int) Db::pdo()->lastInsertId();
		$rel = 'overlay/' . $id . ($png ? '.png' : '.jpg');
		rename($tmp, Config::get()->storageDir() . '/' . $rel);
		Db::exec('UPDATE overlay_assets SET storage_path = ? WHERE id = ?', [$rel, $id]);
		return $id;
	}

	public static function assetFile(?int $assetId): ?string
	{
		if ($assetId === null || $assetId <= 0) {
			return null;
		}
		$p = Db::scalar('SELECT storage_path FROM overlay_assets WHERE id = ? AND deleted_at IS NULL', [$assetId]);
		$f = $p === null ? null : Config::get()->storageDir() . '/' . $p;
		return $f !== null && is_file($f) ? $f : null;
	}

	/** Toglie un logo (il file si cancella 30 giorni dopo, bin/retention-photos.php). */
	public static function deleteAsset(int $assetId): void
	{
		Db::exec('UPDATE overlay_assets SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND deleted_at IS NULL', [$assetId]);
		foreach (Db::all('SELECT webcam_id FROM overlay_config WHERE logo_asset_id = ? UNION SELECT webcam_id FROM overlay_items WHERE asset_id = ?', [$assetId, $assetId]) as $r) {
			self::bump((int) $r['webcam_id']);
		}
		Db::exec('UPDATE overlay_config SET logo_asset_id = NULL WHERE logo_asset_id = ?', [$assetId]);
		Db::exec('UPDATE overlay_items SET asset_id = NULL WHERE asset_id = ?', [$assetId]);
	}

	/** File dei loghi cancellati da più di 30 giorni. @return int file tolti */
	public static function pruneAssets(?int $now = null): int
	{
		$n = 0;
		foreach (Db::all('SELECT id, storage_path FROM overlay_assets WHERE deleted_at IS NOT NULL AND deleted_at < ?', [Db::dt(($now ?? time()) - 30 * 86400)]) as $a) {
			$f = Config::get()->storageDir() . '/' . $a['storage_path'];
			if (is_file($f) && @unlink($f)) {
				$n++;
			}
		}
		return $n;
	}

	// ------------------------------------------------------------ sponsor e infopoint

	/**
	 * Elementi attivi oggi (giorno locale), in ordine.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function items(array $w, ?string $kind = null, ?int $at = null): array
	{
		$today = (new \DateTimeImmutable('@' . ($at ?? time())))->setTimezone(ServiceSettings::tz($w))->format('Y-m-d');
		$sql = 'SELECT * FROM overlay_items WHERE webcam_id = ? AND active = 1 AND (valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)';
		$p = [$w['id'], $today, $today];
		if ($kind !== null) {
			$sql .= ' AND kind = ?';
			$p[] = $kind;
		}
		return Db::all($sql . ' ORDER BY sort_order, id', $p);
	}

	/** Spazi sponsor del pacchetto Dolibarr (vuoto = 2). */
	public static function slots(int $webcamId): int
	{
		$o = Features::plan($webcamId)['options'];
		$s = is_array($o['sponsor'] ?? null) ? (int) ($o['sponsor']['slots'] ?? 0) : 0;
		return $s > 0 ? $s : 2;
	}

	/** Sponsor da stampare: attivi, burn = 1, funzione sponsor, al più slots. */
	public static function burnedSponsors(array $w, ?int $at = null): array
	{
		if (!Features::has($w, 'sponsor')) {
			return [];
		}
		$out = array_values(array_filter(self::items($w, 'sponsor', $at), static fn ($i) => (int) $i['burn'] === 1));
		return array_slice($out, 0, self::slots((int) $w['id']));
	}

	/** @param array<string,mixed> $in */
	public static function saveItem(int $webcamId, array $in, ?int $itemId = null, ?int $userId = null): int
	{
		$kind = ($in['kind'] ?? 'sponsor') === 'infopoint' ? 'infopoint' : 'sponsor';
		$label = trim((string) ($in['label'] ?? ''));
		if ($label === '') {
			throw new \InvalidArgumentException('Nome obbligatorio');
		}
		$url = trim((string) ($in['url'] ?? ''));
		if ($url !== '' && !preg_match('#^https?://[^\s]+$#', $url)) {
			throw new \InvalidArgumentException('Link: deve iniziare con https://');
		}
		$date = static function (mixed $v): ?string {
			$v = trim((string) ($v ?? ''));
			if ($v === '') {
				return null;
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
				throw new \InvalidArgumentException('Data non valida (AAAA-MM-GG)');
			}
			return $v;
		};
		$from = $date($in['valid_from'] ?? null);
		$to = $date($in['valid_to'] ?? null);
		if ($from !== null && $to !== null && $to < $from) {
			throw new \InvalidArgumentException('Il periodo finisce prima di iniziare');
		}
		$pct = static function (mixed $v): ?float {
			$v = trim(str_replace(',', '.', (string) ($v ?? '')));
			return $v === '' ? null : max(0.0, min(100.0, round((float) $v, 2)));
		};
		$x = $pct($in['x_pct'] ?? null);
		$y = $pct($in['y_pct'] ?? null);
		if ($kind === 'infopoint' && ($x === null || $y === null)) {
			throw new \InvalidArgumentException('Infopoint: posizione x e y in % obbligatorie');
		}
		$asset = (int) ($in['asset_id'] ?? 0);
		$text = trim((string) ($in['text'] ?? ''));
		$burn = array_key_exists('burn', $in) ? (!empty($in['burn']) ? 1 : 0) : ($kind === 'sponsor' ? 1 : 0);
		$v = [$kind, mb_substr($label, 0, 80), $text === '' ? null : mb_substr($text, 0, 255), $url === '' ? null : mb_substr($url, 0, 255),
			$asset > 0 ? $asset : null, $kind === 'infopoint' ? $x : null, $kind === 'infopoint' ? $y : null, $burn,
			(int) ($in['sort_order'] ?? 0), $from, $to, array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1];
		if ($itemId === null) {
			Db::exec(
				'INSERT INTO overlay_items (webcam_id, kind, label, text, url, asset_id, x_pct, y_pct, burn, sort_order, valid_from, valid_to, active, created_by)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				array_merge([$webcamId], $v, [$userId])
			);
			$itemId = (int) Db::pdo()->lastInsertId();
		} else {
			Db::exec(
				'UPDATE overlay_items SET kind = ?, label = ?, text = ?, url = ?, asset_id = ?, x_pct = ?, y_pct = ?, burn = ?, sort_order = ?,
				   valid_from = ?, valid_to = ?, active = ? WHERE id = ? AND webcam_id = ?',
				array_merge($v, [$itemId, $webcamId])
			);
		}
		self::bump($webcamId);
		return $itemId;
	}

	public static function deleteItem(int $webcamId, int $itemId): void
	{
		Db::exec('DELETE FROM overlay_items WHERE id = ? AND webcam_id = ?', [$itemId, $webcamId]);
		self::bump($webcamId);
	}

	/**
	 * Impronta degli sponsor e infopoint attivi oggi: quando cambia (uno
	 * sponsor entra o esce dal suo periodo) overlay_rev cresce. Lo chiama
	 * bin/stats.php ogni ora. Restituisce le webcam aggiornate.
	 */
	public static function refreshPeriods(?int $now = null): int
	{
		$file = Config::get()->storageDir() . '/state/overlay-items.json';
		$old = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
		$new = [];
		$n = 0;
		foreach (Db::all('SELECT w.id, w.timezone FROM webcams w JOIN overlay_config c ON c.webcam_id = w.id') as $w) {
			Features::reset();
			$ids = array_map(static fn ($i) => (int) $i['id'], array_merge(self::burnedSponsors($w, $now), array_filter(self::items($w, 'infopoint', $now), static fn ($i) => (int) $i['burn'] === 1)));
			$key = implode(',', $ids);
			$new[(string) $w['id']] = $key;
			if (array_key_exists((string) $w['id'], $old) && $old[(string) $w['id']] !== $key) {
				self::bump((int) $w['id']);
				$n++;
			}
		}
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@file_put_contents($file, json_encode($new), LOCK_EX);
		return $n;
	}
}
