<?php

declare(strict_types=1);

namespace WebcamPortal\Pdf;

/**
 * Generatore PDF minimo del portal (servizi-v1.md, "Generatore PDF"), senza
 * librerie: PDF 1.4, A4 verticale (595 x 842 pt), font standard Helvetica e
 * Helvetica-Bold con WinAnsiEncoding, immagini JPEG incluse così come sono
 * (/DCTDecode), linee e rettangoli vettoriali, flussi compressi /FlateDecode.
 *
 * Coordinate in punti dall'angolo in alto a sinistra (y verso il basso).
 */
final class Pdf
{
	public const W = 595.28;
	public const H = 841.89;

	/** Larghezze Helvetica (1/1000 em) per i caratteri 32-126. */
	private const HELV = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
	private const HELVB = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
		975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
		333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];
	/** Caratteri senza equivalente in Windows-1252, scritti in parole. */
	private const WORDS = ['≥' => 'almeno ', '≤' => 'al più ', '→' => '->', '≈' => 'circa ', '×' => 'x', '−' => '-', '…' => '...', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-'];

	/** @var list<string> contenuto di ogni pagina */
	private array $pages = [];
	/** @var list<list<string>> immagini usate per pagina */
	private array $pageImages = [];
	/** @var array<string,array{data:string,w:int,h:int,cs:string,n:int}> */
	private array $images = [];
	private int $cur = -1;
	private string $font = 'F1';
	private float $size = 10;
	/** @var callable|null fn(Pdf $pdf, int $page, int $pages) */
	private $footer = null;

	public function __construct(private string $title = '', private string $author = 'Viewlapse')
	{
	}

	public function setFooter(callable $f): void
	{
		$this->footer = $f;
	}

	public function addPage(): void
	{
		$this->pages[] = '';
		$this->pageImages[] = [];
		$this->cur = count($this->pages) - 1;
	}

	public function pageCount(): int
	{
		return count($this->pages);
	}

	private function out(string $s): void
	{
		if ($this->cur < 0) {
			$this->addPage();
		}
		$this->pages[$this->cur] .= $s . "\n";
	}

	private static function n(float $v): string
	{
		$s = number_format($v, 2, '.', '');
		return rtrim(rtrim($s, '0'), '.') ?: '0';
	}

	/** UTF-8 -> Windows-1252 (WinAnsiEncoding). */
	public static function encode(string $s): string
	{
		$s = strtr($s, self::WORDS);
		$r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
		if ($r === false) {
			$r = (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
		}
		return $r;
	}

	private static function esc(string $s): string
	{
		return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
	}

	public function font(bool $bold = false, float $size = 10): void
	{
		$this->font = $bold ? 'F2' : 'F1';
		$this->size = $size;
	}

	/** Larghezza in punti di un testo UTF-8 con il font corrente (o indicato). */
	public function width(string $text, ?bool $bold = null, ?float $size = null): float
	{
		$tab = ($bold ?? $this->font === 'F2') ? self::HELVB : self::HELV;
		$w = 0;
		foreach (str_split(self::encode($text)) as $c) {
			$o = ord($c);
			if ($o >= 32 && $o <= 126) {
				$w += $tab[$o - 32];
			} elseif (in_array($o, [0xEC, 0xED, 0xCC, 0xCD], true)) {
				$w += 278; // ì í Ì Í
			} elseif ($o === 0xB0) {
				$w += 400; // °
			} elseif ($o === 0xB7) {
				$w += 278; // ·
			} else {
				$w += 556;
			}
		}
		return $w * ($size ?? $this->size) / 1000;
	}

	/** RGB 0-255. */
	public function color(int $r, int $g, int $b, bool $fill = true): void
	{
		$this->out(sprintf('%s %s %s %s', self::n($r / 255), self::n($g / 255), self::n($b / 255), $fill ? 'rg' : 'RG'));
	}

	/** Testo su una riga a (x, y della linea di base); align left/right/center rispetto a x. */
	public function text(float $x, float $y, string $text, string $align = 'left'): void
	{
		if ($align !== 'left') {
			$w = $this->width($text);
			$x -= $align === 'right' ? $w : $w / 2;
		}
		$this->out(sprintf('BT /%s %s Tf %s %s Td (%s) Tj ET', $this->font, self::n($this->size), self::n($x), self::n(self::H - $y), self::esc(self::encode($text))));
	}

	/**
	 * Testo a capo nella larghezza $w; restituisce la y sotto l'ultima riga.
	 */
	public function paragraph(float $x, float $y, float $w, string $text, float $leading = 1.35): float
	{
		foreach (explode("\n", $text) as $para) {
			$line = '';
			foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
				$try = $line === '' ? $word : "$line $word";
				if ($line !== '' && $this->width($try) > $w) {
					$this->text($x, $y, $line);
					$y += $this->size * $leading;
					$line = $word;
				} else {
					$line = $try;
				}
			}
			if ($line !== '') {
				$this->text($x, $y, $line);
			}
			$y += $this->size * $leading;
		}
		return $y;
	}

	public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5): void
	{
		$this->out(sprintf('%s w %s %s m %s %s l S', self::n($width), self::n($x1), self::n(self::H - $y1), self::n($x2), self::n(self::H - $y2)));
	}

	/** Spezzata (grafici), punti [x, y]; null interrompe la linea. */
	public function polyline(array $points, float $width = 1.0): void
	{
		$cmd = '';
		$open = false;
		foreach ($points as $p) {
			if ($p === null) {
				$open = false;
				continue;
			}
			$cmd .= sprintf('%s %s %s ', self::n($p[0]), self::n(self::H - $p[1]), $open ? 'l' : 'm');
			$open = true;
		}
		if ($cmd !== '') {
			$this->out(self::n($width) . ' w ' . $cmd . 'S');
		}
	}

	public function rect(float $x, float $y, float $w, float $h, string $style = 'S', float $lineWidth = 0.5): void
	{
		$op = ['S' => 'S', 'F' => 'f', 'FD' => 'B'][$style] ?? 'S';
		$this->out(sprintf('%s w %s %s %s %s re %s', self::n($lineWidth), self::n($x), self::n(self::H - $y - $h), self::n($w), self::n($h), $op));
	}

	/**
	 * JPEG incluso così com'è (/DCTDecode). $h = 0: proporzioni mantenute.
	 * Restituisce l'altezza disegnata.
	 */
	public function jpeg(string $data, float $x, float $y, float $w, float $h = 0): float
	{
		$info = @getimagesizefromstring($data);
		if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
			throw new \InvalidArgumentException('Non è un JPEG');
		}
		$key = sha1($data);
		if (!isset($this->images[$key])) {
			$ch = (int) ($info['channels'] ?? 3);
			$this->images[$key] = ['data' => $data, 'w' => (int) $info[0], 'h' => (int) $info[1], 'cs' => $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB'), 'n' => count($this->images) + 1];
		}
		$img = $this->images[$key];
		if ($h <= 0) {
			$h = $w * $img['h'] / $img['w'];
		}
		if ($this->cur < 0) {
			$this->addPage();
		}
		$this->pageImages[$this->cur][] = $key;
		$this->out(sprintf('q %s 0 0 %s %s %s cm /I%d Do Q', self::n($w), self::n($h), self::n($x), self::n(self::H - $y - $h), $img['n']));
		return $h;
	}

	/** Il documento PDF completo. */
	public function output(): string
	{
		if ($this->pages === []) {
			$this->addPage();
		}
		if ($this->footer !== null) {
			$n = count($this->pages);
			for ($i = 0; $i < $n; $i++) {
				$this->cur = $i;
				($this->footer)($this, $i + 1, $n);
			}
		}
		$objs = [];
		$add = static function (string $body) use (&$objs): int {
			$objs[] = $body;
			return count($objs);
		};
		$catalog = $add('');
		$pagesId = $add('');
		$f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
		$f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
		$imgIds = [];
		foreach ($this->images as $key => $im) {
			$decode = $im['cs'] === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
			$imgIds[$key] = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode%s /Length %d >>\nstream\n",
				$im['w'], $im['h'], $im['cs'], $decode, strlen($im['data'])) . $im['data'] . "\nendstream");
		}
		$kids = [];
		foreach ($this->pages as $i => $content) {
			$z = gzcompress($content, 6);
			$cid = $add(sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n", strlen($z)) . $z . "\nendstream");
			$xo = '';
			foreach (array_unique($this->pageImages[$i]) as $key) {
				$xo .= sprintf('/I%d %d 0 R ', $this->images[$key]['n'], $imgIds[$key]);
			}
			$res = sprintf('<< /Font << /F1 %d 0 R /F2 %d 0 R >>%s >>', $f1, $f2, $xo === '' ? '' : ' /XObject << ' . $xo . '>>');
			$kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R >>', $pagesId, self::n(self::W), self::n(self::H), $res, $cid));
		}
		$objs[$catalog - 1] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId);
		$objs[$pagesId - 1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(static fn ($k) => "$k 0 R", $kids)), count($kids));
		$date = 'D:' . gmdate('YmdHis') . "+00'00'";
		$info = $add(sprintf('<< /Title (%s) /Author (%s) /Producer (webcam-portal) /CreationDate (%s) >>', self::esc(self::encode($this->title)), self::esc(self::encode($this->author)), $date));

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objs as $i => $body) {
			$offsets[$i + 1] = strlen($pdf);
			$pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $o) {
			$pdf .= sprintf("%010d 00000 n \n", $o);
		}
		$pdf .= sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objs) + 1, $catalog, $info, $xref);
		return $pdf;
	}

	// ------------------------------------------------------------ aiuti per i documenti

	/**
	 * Tabella semplice: intestazione in grassetto, righe alternate. Va a
	 * pagina nuova quando serve (richiama $onNewPage). Restituisce la y finale.
	 *
	 * @param list<array{0:string,1:float,2?:string}> $cols titolo, larghezza, allineamento
	 * @param list<list<string>> $rows
	 */
	public function table(float $x, float $y, array $cols, array $rows, float $size = 8.5, float $bottom = 790, ?callable $onNewPage = null): float
	{
		$rh = $size * 1.7;
		$header = function (float $y) use ($x, $cols, $size, $rh): float {
			$this->color(230, 236, 242);
			$tw = array_sum(array_column($cols, 1));
			$this->rect($x, $y, $tw, $rh, 'F');
			$this->color(0, 0, 0);
			$this->font(true, $size);
			$cx = $x;
			foreach ($cols as $c) {
				$this->cell($cx, $y, $c[1], $rh, $c[0], $c[2] ?? 'left');
				$cx += $c[1];
			}
			return $y + $rh;
		};
		$y = $header($y);
		foreach ($rows as $i => $r) {
			if ($y + $rh > $bottom) {
				$this->addPage();
				$y = $onNewPage ? (float) $onNewPage($this) : 50;
				$y = $header($y);
			}
			if ($i % 2 === 1) {
				$this->color(246, 248, 250);
				$this->rect($x, $y, array_sum(array_column($cols, 1)), $rh, 'F');
				$this->color(0, 0, 0);
			}
			$this->font(false, $size);
			$cx = $x;
			foreach ($cols as $j => $c) {
				$this->cell($cx, $y, $c[1], $rh, (string) ($r[$j] ?? ''), $c[2] ?? 'left');
				$cx += $c[1];
			}
			$y += $rh;
		}
		return $y;
	}

	private function cell(float $x, float $y, float $w, float $h, string $text, string $align): void
	{
		$pad = 3;
		while ($text !== '' && $this->width($text) > $w - 2 * $pad) {
			$text = mb_substr($text, 0, -1);
		}
		$by = $y + $h / 2 + $this->size * 0.35;
		match ($align) {
			'right' => $this->text($x + $w - $pad, $by, $text, 'right'),
			'center' => $this->text($x + $w / 2, $by, $text, 'center'),
			default => $this->text($x + $pad, $by, $text),
		};
	}

	/**
	 * Grafico a linee o barre su assi semplici.
	 *
	 * @param list<string> $labels etichette dell'asse x
	 * @param list<array{label:string, values:list<?float>, color:array{0:int,1:int,2:int}, bars?:bool}> $series
	 */
	public function chart(float $x, float $y, float $w, float $h, array $labels, array $series, string $unit = '', ?float $min = null, ?float $max = null): void
	{
		$all = [];
		foreach ($series as $s) {
			foreach ($s['values'] as $v) {
				if ($v !== null) {
					$all[] = (float) $v;
				}
			}
		}
		$lo = $min ?? ($all === [] ? 0.0 : min($all));
		$hi = $max ?? ($all === [] ? 1.0 : max($all));
		if ($hi - $lo < 1e-6) {
			$hi = $lo + 1;
		}
		$pad = ($hi - $lo) * 0.08;
		if ($min === null) {
			$lo -= $pad;
		}
		if ($max === null) {
			$hi += $pad;
		}
		$left = $x + 34;
		$pw = $w - 40;
		$ph = $h - 26;
		$this->color(120, 120, 120, false);
		$this->rect($left, $y, $pw, $ph, 'S', 0.4);
		$this->font(false, 7);
		$this->color(90, 90, 90);
		for ($i = 0; $i <= 4; $i++) {
			$v = $lo + ($hi - $lo) * $i / 4;
			$yy = $y + $ph - $ph * $i / 4;
			$this->color(225, 225, 225, false);
			if ($i > 0 && $i < 4) {
				$this->line($left, $yy, $left + $pw, $yy, 0.3);
			}
			$this->text($left - 3, $yy + 2.5, number_format($v, abs($hi - $lo) < 10 ? 1 : 0, ',', '') . ($unit !== '' ? " $unit" : ''), 'right');
		}
		$n = max(1, count($labels));
		$step = $pw / $n;
		$every = max(1, (int) ceil($n / 12));
		foreach ($labels as $i => $l) {
			if ($i % $every === 0) {
				$this->text($left + $step * ($i + 0.5), $y + $ph + 10, $l, 'center');
			}
		}
		$ly = $y + $ph + 20;
		$lx = $left;
		foreach ($series as $s) {
			[$r, $g, $b] = $s['color'];
			$this->color($r, $g, $b);
			$this->color($r, $g, $b, false);
			if (!empty($s['bars'])) {
				foreach ($s['values'] as $i => $v) {
					if ($v === null) {
						continue;
					}
					$bh = $ph * (max($lo, (float) $v) - $lo) / ($hi - $lo);
					$this->rect($left + $step * $i + $step * 0.15, $y + $ph - $bh, $step * 0.7, $bh, 'F');
				}
			} else {
				$pts = [];
				foreach ($s['values'] as $i => $v) {
					$pts[] = $v === null ? null : [$left + $step * ($i + 0.5), $y + $ph - $ph * ((float) $v - $lo) / ($hi - $lo)];
				}
				$this->polyline($pts, 1.2);
			}
			$this->rect($lx, $ly - 5, 8, 4, 'F');
			$this->color(60, 60, 60);
			$this->text($lx + 11, $ly, $s['label']);
			$lx += 20 + $this->width($s['label']);
		}
		$this->color(0, 0, 0);
		$this->color(0, 0, 0, false);
	}
}
