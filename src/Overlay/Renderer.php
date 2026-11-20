<?php

declare(strict_types=1);

namespace WebcamPortal\Overlay;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Service\ServiceSettings;

/**
 * Disegno della sovrimpressione con GD e FreeType (servizi-v1.md,
 * "Disegno"): fascia con data e misure della foto, logo del cliente nell'angolo,
 * sponsor nella fascia dal lato opposto al testo, infopoint stampati. Misure in
 * proporzione all'immagine derivata su cui si disegna.
 */
final class Renderer
{
	private const DEW = ['it' => 'rugiada', 'en' => 'dew point', 'de' => 'Taupunkt', 'fr' => 'point de rosée'];
	private const UNSYNCED = ['it' => ' (ora non verificata)', 'en' => ' (time not verified)', 'de' => ' (Uhrzeit nicht geprüft)', 'fr' => ' (heure non vérifiée)'];
	/** Ordine in cui si tolgono i campi che non stanno (mai la data). */
	private const DROP = ['text', 'location', 'altitude', 'dewpoint_ext', 'name', 'humidity_ext', 'temperature_ext'];

	public static function font(bool $bold = false): ?string
	{
		$f = trim((string) Config::get()->value($bold ? 'overlay.font_bold' : 'overlay.font', ''));
		if ($f === '') {
			$f = WEBCAM_PORTAL_ROOT . '/assets/fonts/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
		}
		return function_exists('imagettftext') && is_file($f) ? $f : null;
	}

	/** Punto di rugiada (Magnus, costanti di foto-v1.md). */
	public static function dewpoint(?float $t, ?float $rh): ?float
	{
		if ($t === null || $rh === null || $rh <= 0) {
			return null;
		}
		$g = log($rh / 100) + 17.62 * $t / (243.12 + $t);
		return round(243.12 * $g / (17.62 - $g), 1);
	}

	private static function num(float $v, int $dec, string $locale): string
	{
		return number_format($v, $dec, $locale === 'en' ? '.' : ',', '');
	}

	/**
	 * Testi dei campi scelti, nell'ordine del contratto. Un valore che manca
	 * non si scrive.
	 *
	 * @param array<string,mixed> $photo riga di photos (taken_at, time_synced, t_ext, rh_ext)
	 * @return array<string,string>
	 */
	public static function fieldTexts(array $w, array $photo, array $cfg): array
	{
		$loc = (string) ($cfg['locale'] ?? 'it');
		$want = explode(',', (string) ($cfg['fields'] ?? 'datetime'));
		$tz = ServiceSettings::tz($w);
		$t = $photo['t_ext'] === null ? null : (float) $photo['t_ext'];
		$rh = $photo['rh_ext'] === null ? null : (float) $photo['rh_ext'];
		$out = [];
		foreach (Overlay::FIELDS as $f) {
			if (!in_array($f, $want, true) && $f !== 'datetime') {
				continue;
			}
			$v = null;
			switch ($f) {
				case 'datetime':
					$d = (new \DateTimeImmutable('@' . (int) Db::unix((string) $photo['taken_at'])))->setTimezone($tz);
					$v = match ($loc) {
						'en' => $d->format('m/d/Y h:i A'),
						'de' => $d->format('d.m.Y H:i'),
						default => $d->format('d/m/Y H:i'),
					};
					if (isset($photo['time_synced']) && (int) $photo['time_synced'] === 0) {
						$v .= self::UNSYNCED[$loc] ?? self::UNSYNCED['it'];
					}
					break;
				case 'temperature_ext':
					$v = $t === null ? null : self::num($t, 1, $loc) . ' °C';
					break;
				case 'humidity_ext':
					$v = $rh === null ? null : self::num($rh, 0, $loc) . ' %';
					break;
				case 'dewpoint_ext':
					$dp = self::dewpoint($t, $rh);
					$v = $dp === null ? null : (self::DEW[$loc] ?? self::DEW['it']) . ' ' . self::num($dp, 1, $loc) . ' °C';
					break;
				case 'name':
					$v = $w['name'] ?? null;
					break;
				case 'location':
					$v = $w['location'] ?? null;
					break;
				case 'altitude':
					$v = isset($w['altitude_m']) && $w['altitude_m'] !== null ? (int) $w['altitude_m'] . ' m' : null;
					break;
				case 'text':
					$v = $cfg['custom_text'] ?? null;
					break;
			}
			if ($v !== null && trim((string) $v) !== '') {
				$out[$f] = (string) $v;
			}
		}
		return $out;
	}

	/** Larghezza in pixel di un testo con il font (o con il font interno di GD). */
	private static function width(string $text, float $size, ?string $font): int
	{
		if ($font === null) {
			return strlen($text) * imagefontwidth(3);
		}
		$b = imagettfbbox($size, 0, $font, $text);
		return $b === false ? 0 : abs($b[2] - $b[0]);
	}

	/**
	 * Disegna la sovrimpressione su $im (immagine derivata, già ridotta o
	 * ritagliata).
	 *
	 * @param list<array<string,mixed>> $sponsors elementi sponsor da stampare (Overlay::burnedSponsors)
	 * @param list<array<string,mixed>> $infopoints elementi infopoint con burn = 1
	 * @param bool $points false sui ritagli (social, verticali): le posizioni valgono per l'immagine intera
	 */
	public static function draw(\GdImage $im, array $w, array $photo, array $cfg, array $sponsors = [], array $infopoints = [], bool $points = true): void
	{
		$W = imagesx($im);
		$H = imagesy($im);
		imagealphablending($im, true);
		$scale = max(50, min(200, (int) ($cfg['font_scale_pct'] ?? 100)));
		$barH = max(18, (int) round($H * 0.032 * $scale / 100));
		$px = $barH * 0.62;
		$pt = $px * 0.75; // GD/FreeType a 96 dpi
		$margin = (int) round($barH / 2);
		$font = self::font();
		$top = ($cfg['bar_position'] ?? 'bottom') === 'top';
		$y0 = $top ? 0 : $H - $barH;
		$white = imagecolorallocate($im, 255, 255, 255);

		// fascia
		$alpha = (int) round(127 * (1 - max(0, min(90, (int) ($cfg['bar_opacity_pct'] ?? 55))) / 100));
		imagefilledrectangle($im, 0, $y0, $W - 1, $y0 + $barH - 1, imagecolorallocatealpha($im, 0, 0, 0, $alpha));

		// testo: si tolgono i campi da destra finché sta (mai la data)
		$fields = self::fieldTexts($w, $photo, $cfg);
		$sep = ' · ';
		$avail = $W - 2 * $margin;
		$text = implode($sep, $fields);
		foreach (self::DROP as $f) {
			if (self::width($text, $pt, $font) <= $avail) {
				break;
			}
			unset($fields[$f]);
			$text = implode($sep, $fields);
		}
		while (self::width($text, $pt, $font) > $avail && mb_strlen($text) > 4) {
			$text = mb_substr($text, 0, -2) . '…';
		}
		$tw = self::width($text, $pt, $font);
		$align = (string) ($cfg['text_align'] ?? 'left');

		// sponsor dal lato opposto al testo, nello spazio che resta
		$sideRight = $align !== 'right';
		$gap = $margin;
		$spItems = [];
		$spW = 0;
		$room = $avail - $tw - $gap;
		$sepW = self::width($sep, $pt, $font);
		foreach ($sponsors as $s) {
			$logo = Overlay::assetFile($s['asset_id'] === null ? null : (int) $s['asset_id']);
			$item = null;
			if ($logo !== null && ($li = self::loadLogo($logo)) !== null) {
				$lh = max(6, (int) round($px));
				$lw = max(1, (int) round(imagesx($li) * $lh / imagesy($li)));
				$item = ['logo' => $li, 'w' => $lw, 'h' => $lh];
			} else {
				$item = ['text' => (string) $s['label'], 'w' => self::width((string) $s['label'], $pt, $font)];
			}
			$need = $item['w'] + ($spItems === [] ? 0 : $sepW);
			if ($spW + $need > $room) {
				if (isset($item['logo'])) {
					imagedestroy($item['logo']);
				}
				break; // si tolgono dalla fine
			}
			$spItems[] = $item;
			$spW += $need;
		}
		$tx = match ($align) {
			'center' => (int) round(($W - $tw) / 2),
			'right' => $W - $margin - $tw,
			default => $margin,
		};
		if ($align === 'center' && $spItems !== [] && $tx + $tw > $W - $margin - $spW - $gap) {
			$tx = max($margin, $W - $margin - $spW - $gap - $tw);
		}
		self::text($im, $text, $tx, $y0, $barH, $pt, $font, $white);

		if ($spItems !== []) {
			$x = $sideRight ? $W - $margin - $spW : $margin;
			foreach ($spItems as $i => $it) {
				if ($i > 0) {
					self::text($im, $sep, $x, $y0, $barH, $pt, $font, $white);
					$x += $sepW;
				}
				if (isset($it['logo'])) {
					imagecopyresampled($im, $it['logo'], $x, $y0 + (int) round(($barH - $it['h']) / 2), 0, 0, $it['w'], $it['h'], imagesx($it['logo']), imagesy($it['logo']));
					imagedestroy($it['logo']);
				} else {
					self::text($im, $it['text'], $x, $y0, $barH, $pt, $font, $white);
				}
				$x += $it['w'];
			}
		}

		// logo del cliente nell'angolo, sopra la fascia se è dal suo lato
		$logoFile = Overlay::assetFile(isset($cfg['logo_asset_id']) ? (int) $cfg['logo_asset_id'] : null);
		if ($logoFile !== null && ($li = self::loadLogo($logoFile)) !== null) {
			$lw = min(imagesx($li), max(8, (int) round($W * max(3, min(30, (int) ($cfg['logo_width_pct'] ?? 12))) / 100)));
			$lh = max(1, (int) round(imagesy($li) * $lw / imagesx($li)));
			$m = (int) round($W * 0.02);
			$pos = (string) ($cfg['logo_position'] ?? 'tr');
			$lx = $pos[1] === 'l' ? $m : $W - $m - $lw;
			$ly = $pos[0] === 't' ? $m : $H - $m - $lh;
			if ($pos[0] === 't' && $top) {
				$ly += $barH;
			} elseif ($pos[0] === 'b' && !$top) {
				$ly -= $barH;
			}
			$scaled = imagecreatetruecolor($lw, $lh);
			imagealphablending($scaled, false);
			imagesavealpha($scaled, true);
			imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
			imagecopyresampled($scaled, $li, 0, 0, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
			imagedestroy($li);
			$op = max(10, min(100, (int) ($cfg['logo_opacity_pct'] ?? 100)));
			if ($op < 100) {
				self::fade($scaled, $op);
			}
			imagealphablending($im, true);
			imagecopy($im, $scaled, $lx, $ly, 0, 0, $lw, $lh);
			imagedestroy($scaled);
		}

		// infopoint stampati (solo sull'immagine intera, non sui ritagli)
		if ($points) {
			$d = max(4, (int) round($H * 0.012));
			$black = imagecolorallocate($im, 0, 0, 0);
			$bg = imagecolorallocatealpha($im, 0, 0, 0, (int) round(127 * 0.45));
			foreach ($infopoints as $ip) {
				$x = (int) round((float) $ip['x_pct'] / 100 * $W);
				$y = (int) round((float) $ip['y_pct'] / 100 * $H);
				imagefilledellipse($im, $x, $y, $d + 2, $d + 2, $black);
				imagefilledellipse($im, $x, $y, $d, $d, $white);
				$label = (string) $ip['label'];
				$lw = self::width($label, $pt * 0.85, $font);
				$bx = min($W - $lw - 2 * $margin, $x + $d);
				$by = max(0, min($H - $barH, $y - (int) round($barH / 2)));
				imagefilledrectangle($im, $bx, $by, $bx + $lw + $margin, $by + $barH - 1, $bg);
				self::text($im, $label, $bx + (int) round($margin / 2), $by, $barH, $pt * 0.85, $font, $white);
			}
		}
	}

	private static function text(\GdImage $im, string $text, int $x, int $y0, int $barH, float $pt, ?string $font, int $color): void
	{
		if ($font === null) {
			imagestring($im, 3, $x, $y0 + (int) round(($barH - imagefontheight(3)) / 2), $text, $color);
			return;
		}
		$b = imagettfbbox($pt, 0, $font, 'Hg');
		$h = $b === false ? (int) $pt : abs($b[7] - $b[1]);
		$base = $y0 + (int) round(($barH + $h) / 2) - ($b === false ? 0 : (int) $b[1]);
		imagettftext($im, $pt, 0, $x, $base, $color, $font, $text);
	}

	private static function loadLogo(string $file): ?\GdImage
	{
		$info = @getimagesize($file);
		if ($info === false) {
			return null;
		}
		$im = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
		if ($im === false) {
			return null;
		}
		if (!imageistruecolor($im)) {
			imagepalettetotruecolor($im);
		}
		imagealphablending($im, false);
		imagesavealpha($im, true);
		return $im;
	}

	/** Opacità di un'immagine con canale alfa (per pixel). */
	private static function fade(\GdImage $im, int $pct): void
	{
		$w = imagesx($im);
		$h = imagesy($im);
		imagealphablending($im, false);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$c = imagecolorat($im, $x, $y);
				$a = ($c >> 24) & 0x7f;
				$na = 127 - (int) round((127 - $a) * $pct / 100);
				imagesetpixel($im, $x, $y, ($c & 0xffffff) | ($na << 24));
			}
		}
	}
}
