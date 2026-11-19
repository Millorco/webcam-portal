<?php

declare(strict_types=1);

namespace WebcamPortal\Admin;

use WebcamPortal\Web\View;

/**
 * Grafico a linee in SVG generato sul server (niente JavaScript né CDN).
 * Una sola scala verticale; più serie con legenda ed etichetta in fondo alla
 * linea; ogni punto ha un <title> che il browser mostra al passaggio del mouse.
 */
final class SvgChart
{
	/**
	 * @param list<array{label:string, class:string, points:list<array{0:int,1:?float}>}> $series
	 *        punti [ora Unix, valore]; null = buco nella linea
	 * @param array{gap?:int, empty?:string} $opt gap: secondi senza punti che interrompono la linea (20 min)
	 */
	public static function render(string $title, string $unit, array $series, int $from, int $to, string $tz, int $dec = 1, array $opt = []): string
	{
		$gap = (int) ($opt['gap'] ?? 1200);
		$w = 720;
		$h = 240;
		$l = 44;
		$r = 76;
		$t = 14;
		$b = 28;
		$pw = $w - $l - $r;
		$ph = $h - $t - $b;

		$vals = [];
		foreach ($series as $s) {
			foreach ($s['points'] as $p) {
				if ($p[1] !== null) {
					$vals[] = $p[1];
				}
			}
		}
		$out = '<figure class="chart"><figcaption>' . View::e($title) . '</figcaption>';
		$out .= '<ul class="legend">';
		foreach ($series as $s) {
			$out .= '<li><span class="swatch ' . View::e($s['class']) . '"></span>' . View::e($s['label']) . '</li>';
		}
		$out .= '</ul>';
		if ($vals === []) {
			return $out . '<p class="muted">' . View::e($opt['empty'] ?? 'Nessun dato nelle ultime 24 ore.') . '</p></figure>';
		}
		[$min, $max, $step] = self::scale(min($vals), max($vals));
		$x = static fn (int $ts): float => $l + ($ts - $from) / max(1, $to - $from) * $pw;
		$y = static fn (float $v): float => $t + $ph - ($v - $min) / ($max - $min) * $ph;

		$svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . View::e($title) . '" class="svgchart">';
		// griglia orizzontale e asse y
		for ($v = $min; $v <= $max + $step / 2; $v += $step) {
			$yy = round($y($v), 1);
			$svg .= '<line class="grid" x1="' . $l . '" x2="' . ($l + $pw) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
			$svg .= '<text class="axis" x="' . ($l - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">'
				. View::e(View::num($v, $step < 1 ? 1 : 0)) . '</text>';
		}
		// asse x: un'etichetta ogni 3 ore (ora locale), o una al giorno oltre le 48 ore
		$zone = new \DateTimeZone($tz);
		$days = $to - $from > 172800;
		$first = $from - ($from % 3600) + 3600;
		for ($ts = $first; $ts <= $to; $ts += 3600) {
			$d = (new \DateTimeImmutable('@' . $ts))->setTimezone($zone);
			if ($days ? (int) $d->format('G') !== 0 : (int) $d->format('G') % 3 !== 0) {
				continue;
			}
			$xx = round($x($ts), 1);
			$svg .= '<line class="tick" x1="' . $xx . '" x2="' . $xx . '" y1="' . ($t + $ph) . '" y2="' . ($t + $ph + 4) . '"/>';
			$svg .= '<text class="axis" x="' . $xx . '" y="' . ($h - 8) . '" text-anchor="middle">' . $d->format($days ? 'd/m' : 'H:i') . '</text>';
		}
		$svg .= '<line class="baseline" x1="' . $l . '" x2="' . ($l + $pw) . '" y1="' . ($t + $ph) . '" y2="' . ($t + $ph) . '"/>';

		$labels = [];
		foreach ($series as $s) {
			$d = '';
			$pen = false;
			$prevTs = null;
			$lastPt = null;
			$hits = '';
			foreach ($s['points'] as [$ts, $v]) {
				// un buco di oltre $gap secondi interrompe la linea
				if ($v === null || ($prevTs !== null && $ts - $prevTs > $gap)) {
					$pen = false;
				}
				if ($v !== null) {
					$px = round($x($ts), 1);
					$py = round($y($v), 1);
					$d .= ($pen ? 'L' : 'M') . $px . ' ' . $py . ' ';
					$pen = true;
					$lastPt = [$px, $py, $v];
					$when = (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('d/m H:i');
					$hits .= '<circle class="hit" cx="' . $px . '" cy="' . $py . '" r="5"><title>'
						. View::e($s['label'] . ' ' . $when . ': ' . View::num($v, $dec, $unit)) . '</title></circle>';
				}
				$prevTs = $ts;
			}
			if ($d === '') {
				continue;
			}
			$svg .= '<path class="line ' . View::e($s['class']) . '" d="' . trim($d) . '"/>' . $hits;
			if ($lastPt !== null) {
				$labels[] = [$lastPt, $s];
			}
		}
		// etichette dirette a destra, distanziate se si sovrappongono
		usort($labels, static fn ($a, $c) => $a[0][1] <=> $c[0][1]);
		$lastY = -INF;
		foreach ($labels as [$pt, $s]) {
			$ly = max($pt[1] + 4, $lastY + 14);
			$lastY = $ly;
			$svg .= '<text class="direct" x="' . ($l + $pw + 6) . '" y="' . round($ly, 1) . '">'
				. View::e(View::num($pt[2], $dec, $unit)) . ' ' . View::e($s['short'] ?? '') . '</text>';
		}
		$svg .= '</svg>';
		return $out . $svg . '</figure>';
	}

	/** @return array{0:float,1:float,2:float} minimo, massimo e passo "tondi" */
	private static function scale(float $min, float $max): array
	{
		if ($max - $min < 1) {
			$min -= 0.5;
			$max += 0.5;
		}
		$raw = ($max - $min) / 5;
		$mag = 10 ** floor(log10($raw));
		$step = $mag;
		foreach ([1, 2, 2.5, 5, 10] as $m) {
			if ($m * $mag >= $raw) {
				$step = $m * $mag;
				break;
			}
		}
		return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
	}
}
