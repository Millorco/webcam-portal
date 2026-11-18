<?php

declare(strict_types=1);

namespace WebcamPortal\Photo;

/**
 * Calcoli sulle foto con GD (diagnosi-v1.md, "Analisi delle foto"), su una
 * versione in grigi larga 640 px: luminosità, contrasto, varianza del
 * laplaciano (intera e mediana su 4x4 riquadri), dHash a 64 bit e
 * spostamento rispetto a un riferimento (correlazione normalizzata dei
 * gradienti: ricerca grossolana ±12 px a 160 px, rifinitura ±2 px a 640).
 *
 * Le immagini in grigi sono array piatti di interi 0-255 (indice y*w+x).
 */
final class Analyzer
{
	public const WIDTH = 640;
	private const COARSE = 4;      // 640 / 160
	private const RANGE = 12;      // ±12 px a 160 px
	private const FINE = 2;        // ±2 px a 640 px

	/**
	 * Apre un JPEG controllando prima la memoria: un JPEG decompresso occupa
	 * circa 5 byte per pixel in GD.
	 *
	 * @throws MemoryException se non basta memory_limit
	 */
	public static function loadJpeg(string $file): \GdImage
	{
		$info = @getimagesize($file);
		if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
			throw new \RuntimeException('non è un JPEG leggibile');
		}
		$need = (int) ($info[0] * $info[1] * 5.2) + 16 * 1048576;
		$limit = self::iniBytes((string) ini_get('memory_limit'));
		if ($limit > 0 && memory_get_usage(true) + $need > $limit) {
			throw new MemoryException(sprintf('servono circa %d MB per %dx%d, memory_limit %s', (int) ($need / 1048576), $info[0], $info[1], ini_get('memory_limit')));
		}
		$im = @imagecreatefromjpeg($file);
		if ($im === false) {
			throw new \RuntimeException('decodifica JPEG fallita');
		}
		return $im;
	}

	/** @return array{w:int, h:int, g:list<int>} versione in grigi larga 640 px */
	public static function gray(\GdImage $im): array
	{
		$w0 = imagesx($im);
		$h0 = imagesy($im);
		$w = self::WIDTH;
		$h = max(8, (int) round($h0 * $w / $w0));
		$small = $w0 === $w ? $im : imagescale($im, $w, $h, IMG_BILINEAR_FIXED);
		if ($small === false) {
			throw new \RuntimeException('riduzione fallita');
		}
		$g = [];
		$pal = !imageistruecolor($small);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$c = imagecolorat($small, $x, $y);
				if ($pal) {
					$c = imagecolorsforindex($small, $c);
					$g[] = (int) (($c['red'] * 299 + $c['green'] * 587 + $c['blue'] * 114) / 1000);
				} else {
					$g[] = (int) (((($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114) / 1000);
				}
			}
		}
		if ($small !== $im) {
			imagedestroy($small);
		}
		return ['w' => $w, 'h' => $h, 'g' => $g];
	}

	/** Salva la versione in grigi come PNG a 256 livelli (riferimento). */
	public static function savePng(array $img, string $file): void
	{
		$im = imagecreate($img['w'], $img['h']);
		for ($i = 0; $i < 256; $i++) {
			imagecolorallocate($im, $i, $i, $i);
		}
		$w = $img['w'];
		foreach ($img['g'] as $i => $v) {
			imagesetpixel($im, $i % $w, intdiv($i, $w), $v);
		}
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$tmp = $file . '.tmp';
		imagepng($im, $tmp, 6);
		imagedestroy($im);
		rename($tmp, $file);
	}

	public static function loadPng(string $file): ?array
	{
		$im = @imagecreatefrompng($file);
		if ($im === false) {
			return null;
		}
		$img = self::gray($im);
		imagedestroy($im);
		return $img;
	}

	/**
	 * @return array{brightness:float, contrast:float, sharpness:float, sharpness_tiles:float, dhash:string}
	 */
	public static function stats(array $img): array
	{
		$w = $img['w'];
		$h = $img['h'];
		$g = $img['g'];
		$n = $w * $h;
		$sum = 0;
		$sq = 0;
		foreach ($g as $v) {
			$sum += $v;
			$sq += $v * $v;
		}
		$mean = $sum / $n;
		$std = sqrt(max(0, $sq / $n - $mean * $mean));

		// varianza del laplaciano (0 1 0 / 1 -4 1 / 0 1 0), intera e per riquadri 4x4
		$ls = 0.0;
		$lq = 0.0;
		$cnt = 0;
		$ts = array_fill(0, 16, 0.0);
		$tq = array_fill(0, 16, 0.0);
		$tc = array_fill(0, 16, 0);
		for ($y = 1; $y < $h - 1; $y++) {
			$ty = intdiv($y * 4, $h) * 4;
			$row = $y * $w;
			for ($x = 1; $x < $w - 1; $x++) {
				$i = $row + $x;
				$l = $g[$i - $w] + $g[$i + $w] + $g[$i - 1] + $g[$i + 1] - 4 * $g[$i];
				$l2 = $l * $l;
				$ls += $l;
				$lq += $l2;
				$t = $ty + intdiv($x * 4, $w);
				$ts[$t] += $l;
				$tq[$t] += $l2;
				$tc[$t]++;
			}
		}
		$cnt = ($w - 2) * ($h - 2);
		$lm = $ls / $cnt;
		$sharp = $lq / $cnt - $lm * $lm;
		$tiles = [];
		for ($t = 0; $t < 16; $t++) {
			$m = $ts[$t] / max(1, $tc[$t]);
			$tiles[] = $tq[$t] / max(1, $tc[$t]) - $m * $m;
		}
		sort($tiles);
		$median = ($tiles[7] + $tiles[8]) / 2;

		return [
			'brightness' => round($mean, 1),
			'contrast' => round($std, 1),
			'sharpness' => round($sharp, 2),
			'sharpness_tiles' => round($median, 2),
			'dhash' => self::dhash($img),
		];
	}

	/** dHash: 9x8 medie a blocchi, 1 dove il pixel è più chiaro di quello a destra. Stringa decimale senza segno. */
	public static function dhash(array $img): string
	{
		$w = $img['w'];
		$h = $img['h'];
		$cells = [];
		for ($cy = 0; $cy < 8; $cy++) {
			$y0 = intdiv($cy * $h, 8);
			$y1 = max($y0 + 1, intdiv(($cy + 1) * $h, 8));
			for ($cx = 0; $cx < 9; $cx++) {
				$x0 = intdiv($cx * $w, 9);
				$x1 = max($x0 + 1, intdiv(($cx + 1) * $w, 9));
				$s = 0;
				for ($y = $y0; $y < $y1; $y += 2) {
					for ($x = $x0; $x < $x1; $x += 2) {
						$s += $img['g'][$y * $w + $x];
					}
				}
				$cells[$cy][$cx] = $s;
			}
		}
		$bits = 0;
		for ($cy = 0; $cy < 8; $cy++) {
			for ($cx = 0; $cx < 8; $cx++) {
				$bits = ($bits << 1) | ($cells[$cy][$cx] > $cells[$cy][$cx + 1] ? 1 : 0);
			}
		}
		return sprintf('%u', $bits);
	}

	/** @return array{w:int, h:int, g:list<float>} modulo del gradiente (|gx| + |gy|) */
	public static function gradient(array $img): array
	{
		$w = $img['w'];
		$h = $img['h'];
		$g = $img['g'];
		$out = array_fill(0, $w * $h, 0);
		for ($y = 1; $y < $h - 1; $y++) {
			$row = $y * $w;
			for ($x = 1; $x < $w - 1; $x++) {
				$i = $row + $x;
				$out[$i] = abs($g[$i + 1] - $g[$i - 1]) + abs($g[$i + $w] - $g[$i - $w]);
			}
		}
		return ['w' => $w, 'h' => $h, 'g' => $out];
	}

	/** Media a blocchi f x f. */
	public static function downsample(array $img, int $f): array
	{
		$w = intdiv($img['w'], $f);
		$h = intdiv($img['h'], $f);
		$src = $img['g'];
		$sw = $img['w'];
		$out = [];
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$s = 0;
				for ($yy = 0; $yy < $f; $yy++) {
					$r = ($y * $f + $yy) * $sw + $x * $f;
					for ($xx = 0; $xx < $f; $xx++) {
						$s += $src[$r + $xx];
					}
				}
				$out[] = $s / ($f * $f);
			}
		}
		return ['w' => $w, 'h' => $h, 'g' => $out];
	}

	/**
	 * Correlazione normalizzata tra ref(x, y) e img(x + dx, y + dy) sulla
	 * parte comune, un pixel ogni $step.
	 */
	public static function ncc(array $ref, array $img, int $dx, int $dy, int $step = 1, int $margin = 0): float
	{
		$w = min($ref['w'], $img['w']);
		$h = min($ref['h'], $img['h']);
		$x0 = max($margin, -$dx + $margin);
		$x1 = min($w - $margin, $w - $dx - $margin);
		$y0 = max($margin, -$dy + $margin);
		$y1 = min($h - $margin, $h - $dy - $margin);
		if ($x1 - $x0 < 8 || $y1 - $y0 < 8) {
			return 0.0;
		}
		$a = $ref['g'];
		$b = $img['g'];
		$rw = $ref['w'];
		$iw = $img['w'];
		$n = 0;
		$sa = $sb = $saa = $sbb = $sab = 0.0;
		for ($y = $y0; $y < $y1; $y += $step) {
			$ra = $y * $rw;
			$rb = ($y + $dy) * $iw + $dx;
			for ($x = $x0; $x < $x1; $x += $step) {
				$va = $a[$ra + $x];
				$vb = $b[$rb + $x];
				$sa += $va;
				$sb += $vb;
				$saa += $va * $va;
				$sbb += $vb * $vb;
				$sab += $va * $vb;
				$n++;
			}
		}
		$den = ($n * $saa - $sa * $sa) * ($n * $sbb - $sb * $sb);
		return $den <= 0 ? 0.0 : ($n * $sab - $sa * $sb) / sqrt($den);
	}

	/** Correlazione normalizzata di due profili con spostamento d. */
	private static function ncc1(array $a, array $b, int $d): float
	{
		$n = count($a);
		$s1 = $s2 = $s11 = $s22 = $s12 = 0.0;
		$k = 0;
		for ($i = max(0, -$d); $i < min($n, $n - $d); $i++) {
			$x = $a[$i];
			$y = $b[$i + $d];
			$s1 += $x;
			$s2 += $y;
			$s11 += $x * $x;
			$s22 += $y * $y;
			$s12 += $x * $y;
			$k++;
		}
		$den = ($k * $s11 - $s1 * $s1) * ($k * $s22 - $s2 * $s2);
		return $den <= 0 ? 0.0 : ($k * $s12 - $s1 * $s2) / sqrt($den);
	}

	/**
	 * Spostamento della foto rispetto al riferimento (pixel a 640 px, + =
	 * verso destra/il basso) e somiglianza 0-1.
	 *
	 * @return array{shift_x:int, shift_y:int, match_score:float}
	 */
	public static function shift(array $refGray, array $img): array
	{
		$gr = self::gradient($refGray);
		$gi = self::gradient($img);
		$cr = self::downsample($gr, self::COARSE);
		$ci = self::downsample($gi, self::COARSE);

		// 1. profili di colonna e riga per una stima veloce
		$prof = static function (array $m): array {
			$cols = array_fill(0, $m['w'], 0.0);
			$rows = array_fill(0, $m['h'], 0.0);
			foreach ($m['g'] as $i => $v) {
				$cols[$i % $m['w']] += $v;
				$rows[intdiv($i, $m['w'])] += $v;
			}
			return [$cols, $rows];
		};
		[$rc, $rr] = $prof($cr);
		[$ic, $ir] = $prof($ci);
		$best = static function (array $a, array $b, int $range): int {
			$bd = 0;
			$bv = -2.0;
			for ($d = -$range; $d <= $range; $d++) {
				$v = self::ncc1($a, $b, $d);
				if ($v > $bv + 1e-9 || ($v > $bv - 1e-9 && abs($d) < abs($bd))) {
					$bv = $v;
					$bd = $d;
				}
			}
			return $bd;
		};
		$dx = $best($rc, $ic, self::RANGE);
		$dy = $best($rr, $ir, self::RANGE);

		// 2. correlazione 2D a 160 px intorno alla stima
		$bx = $dx;
		$by = $dy;
		$bs = -2.0;
		for ($yy = $dy - 2; $yy <= $dy + 2; $yy++) {
			for ($xx = $dx - 2; $xx <= $dx + 2; $xx++) {
				if (abs($xx) > self::RANGE || abs($yy) > self::RANGE) {
					continue;
				}
				$s = self::ncc($cr, $ci, $xx, $yy, 1, 1);
				if ($s > $bs) {
					$bs = $s;
					$bx = $xx;
					$by = $yy;
				}
			}
		}
		// 3. rifinitura a 640 px
		$fx = $bx * self::COARSE;
		$fy = $by * self::COARSE;
		$fs = -2.0;
		$rx = $fx;
		$ry = $fy;
		for ($yy = $fy - self::FINE; $yy <= $fy + self::FINE; $yy++) {
			for ($xx = $fx - self::FINE; $xx <= $fx + self::FINE; $xx++) {
				$s = self::ncc($gr, $gi, $xx, $yy, 3, 2);
				if ($s > $fs) {
					$fs = $s;
					$rx = $xx;
					$ry = $yy;
				}
			}
		}
		return ['shift_x' => $rx, 'shift_y' => $ry, 'match_score' => round(max(0.0, min(1.0, $bs)), 3)];
	}

	public static function iniBytes(string $v): int
	{
		$v = trim($v);
		if ($v === '' || $v === '-1') {
			return 0;
		}
		$n = (int) $v;
		switch (strtolower(substr($v, -1))) {
			case 'g':
				$n *= 1024;
				// no break
			case 'm':
				$n *= 1024;
				// no break
			case 'k':
				$n *= 1024;
		}
		return $n;
	}
}
