<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Renderer;
use WebcamPortal\Photo\Analyzer;
use WebcamPortal\Photo\Metrics;

/**
 * Foto "buone" e punteggio (O8, servizi-v1.md "Foto buone"): usa le metriche
 * della diagnosi (photo_metrics) o le calcola per una foto ogni
 * scores.every_min minuti; le altre ereditano quelle della foto analizzata
 * più vicina. Risultato in photo_scores (bin/photo-scores.php, ogni 5 minuti).
 */
final class PhotoScores
{
	public const DEFAULTS = [
		'bright_min' => 60, 'bright_max' => 220, 'blur_min' => 0.7, 'rh_max' => 95.0, 'spread_min' => 1.0, 'flicker_pct' => 35,
	];
	private const BAD_CLASS = ['fog', 'fog_ext', 'blurry', 'dirty', 'moved'];
	private const ALERT_RULES = ['glass.fog', 'glass.dirty', 'camera.moved'];
	private const LOG = 'photo-scores';

	public array $stats = ['scored' => 0, 'good' => 0, 'own' => 0, 'inherited' => 0, 'errors' => 0];
	private int $budget;

	public function __construct(private int $now)
	{
		$this->budget = max(1, (int) Config::get()->value('scores.max_per_run', 400));
	}

	/** Soglie della webcam (predefiniti + timelapse_params.good) e versione. @return array{0:array<string,float>,1:int} */
	public static function params(int $webcamId): array
	{
		$tp = Timelapse::params($webcamId);
		$good = is_array($tp['good'] ?? null) ? $tp['good'] : [];
		$out = self::DEFAULTS;
		foreach ($out as $k => $v) {
			if (isset($good[$k]) && is_numeric($good[$k])) {
				$out[$k] = (float) $good[$k];
			}
		}
		return [$out, max(1, (int) ($tp['good_rev'] ?? 1))];
	}

	public function run(?int $webcamId = null): array
	{
		$sql = 'SELECT * FROM webcams WHERE status <> 9';
		$p = [];
		if ($webcamId !== null) {
			$sql .= ' AND id = ?';
			$p[] = $webcamId;
		}
		Metrics::prepareMemory();
		foreach (Db::all($sql . ' ORDER BY id', $p) as $w) {
			if ($this->budget <= 0) {
				break;
			}
			try {
				$this->webcam($w);
			} catch (\Throwable $e) {
				$this->stats['errors']++;
				Log::error("punteggi webcam {$w['id']}: " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), self::LOG);
			}
		}
		return $this->stats;
	}

	private function webcam(array $w): void
	{
		$wid = (int) $w['id'];
		[$par, $rev] = self::params($wid);
		$rows = Db::all(
			'SELECT p.id, p.taken_at, p.mode, p.is_public, p.deleted_at, p.storage_path, p.t_ext, p.rh_ext,
			        JSON_VALUE(p.meta, \'$.day_end\') AS day_end
			   FROM photos p LEFT JOIN photo_scores s ON s.photo_id = p.id
			  WHERE p.webcam_id = ? AND p.taken_at >= ? AND (s.photo_id IS NULL OR s.params_rev < ?)
			  ORDER BY p.taken_at, p.id LIMIT ' . (int) $this->budget,
			[$wid, Db::dt($this->now - 90 * 86400), $rev]
		);
		if ($rows === []) {
			return;
		}
		$tz = ServiceSettings::tz($w);
		$every = max(1, (int) Config::get()->value('scores.every_min', 15)) * 60;
		$p90 = $this->p90($wid);
		$batch = [];
		foreach ($rows as $r) {
			$this->budget--;
			$t = (int) Db::unix((string) $r['taken_at']);
			$s = [
				'photo_id' => (int) $r['id'], 'webcam_id' => $wid, 'taken_at' => $r['taken_at'],
				'day' => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d'),
				'good' => 0, 'reject_reason' => null, 'score' => null, 'brightness' => null, 'contrast' => null,
				'sharpness_tiles' => null, 'blur_ratio' => null, 'rh_ext' => $r['rh_ext'] === null ? null : (float) $r['rh_ext'],
				'spread_ext' => null, 'source' => 'own', 'inherited_from' => null, 'params_rev' => $rev, '_t' => $t,
				'_day_end' => $r['day_end'] === null ? null : (int) $r['day_end'],
			];
			$dp = Renderer::dewpoint($r['t_ext'] === null ? null : (float) $r['t_ext'], $s['rh_ext']);
			$s['spread_ext'] = $dp === null ? null : round((float) $r['t_ext'] - $dp, 1);
			if ((int) $r['is_public'] !== 1 || $r['deleted_at'] !== null) {
				$s['reject_reason'] = 'hidden';
			} elseif ($r['mode'] === 'night') {
				$s['reject_reason'] = 'night'; // senza analisi
			} else {
				$class = $this->metrics($w, $r, $t, $every, $s);
				if ($s['brightness'] === null) {
					$s['reject_reason'] = 'dark';
				} else {
					$s['reject_reason'] = $this->criteria($wid, $t, $s, $class, $par, $p90);
				}
			}
			$batch[] = $s;
		}
		// criterio 8 (sbalzo di luce) con le foto buone vicine, poi punteggio
		$this->flicker($wid, $batch, $par);
		foreach ($batch as $s) {
			if ($s['reject_reason'] === null) {
				$s['good'] = 1;
				$s['score'] = self::score($s, $p90);
				$this->stats['good']++;
			}
			$this->save($s);
		}
	}

	/**
	 * Metriche della foto: photo_metrics (metrics), ereditate dalla foto
	 * analizzata più vicina entro every_min (inherited) o calcolate ora (own).
	 * Restituisce la classificazione della diagnosi (o null).
	 */
	private function metrics(array $w, array $r, int $t, int $every, array &$s): ?string
	{
		$pm = Db::one('SELECT brightness, contrast, sharpness_tiles, blur_ratio, classification FROM photo_metrics WHERE photo_id = ? AND brightness IS NOT NULL', [$r['id']]);
		if ($pm !== null) {
			$s['source'] = 'metrics';
			$this->copy($pm, $s);
			return (string) $pm['classification'];
		}
		$near = Db::one(
			"SELECT s.photo_id, s.brightness, s.contrast, s.sharpness_tiles, s.blur_ratio, m.classification
			   FROM photo_scores s LEFT JOIN photo_metrics m ON m.photo_id = s.photo_id
			  WHERE s.webcam_id = ? AND s.source IN ('metrics','own') AND s.brightness IS NOT NULL AND s.taken_at BETWEEN ? AND ?
			  ORDER BY ABS(TIMESTAMPDIFF(SECOND, s.taken_at, ?)) LIMIT 1",
			[$w['id'], Db::dt($t - $every), Db::dt($t + $every), Db::dt($t)]
		);
		if ($near === null) {
			$near = Db::one(
				"SELECT photo_id, brightness, contrast, sharpness_tiles, blur_ratio, classification FROM photo_metrics
				  WHERE webcam_id = ? AND brightness IS NOT NULL AND taken_at BETWEEN ? AND ?
				  ORDER BY ABS(TIMESTAMPDIFF(SECOND, taken_at, ?)) LIMIT 1",
				[$w['id'], Db::dt($t - $every), Db::dt($t + $every), Db::dt($t)]
			);
		}
		if ($near !== null) {
			$s['source'] = 'inherited';
			$s['inherited_from'] = (int) $near['photo_id'];
			$this->copy($near, $s);
			$this->stats['inherited']++;
			return $near['classification'] === null ? null : (string) $near['classification'];
		}
		$file = PhotoStore::abs((string) $r['storage_path']);
		try {
			$im = Analyzer::loadJpeg($file);
			$img = Analyzer::gray($im);
			imagedestroy($im);
			$st = Analyzer::stats($img);
			$s['source'] = 'own';
			$s['brightness'] = $st['brightness'];
			$s['contrast'] = $st['contrast'];
			$s['sharpness_tiles'] = $st['sharpness_tiles'];
			$this->stats['own']++;
		} catch (\Throwable $e) {
			Log::warning("foto {$r['id']} non analizzata per il punteggio: " . $e->getMessage(), self::LOG);
			$s['source'] = 'own';
		}
		return null;
	}

	private function copy(array $m, array &$s): void
	{
		foreach (['brightness', 'contrast', 'sharpness_tiles', 'blur_ratio'] as $k) {
			$s[$k] = $m[$k] === null ? null : (float) $m[$k];
		}
	}

	/** Criteri 3-7 nell'ordine del contratto; null = superati. */
	private function criteria(int $wid, int $t, array $s, ?string $class, array $par, ?float $p90): ?string
	{
		if ($s['brightness'] < $par['bright_min']) {
			return 'dark';
		}
		if ($s['brightness'] > $par['bright_max']) {
			return 'overexposed';
		}
		if ($class !== null && in_array($class, self::BAD_CLASS, true)) {
			return $class;
		}
		if ($s['blur_ratio'] !== null) {
			if ($s['blur_ratio'] < $par['blur_min']) {
				return 'blurry';
			}
		} elseif ($p90 !== null && $s['sharpness_tiles'] !== null && $s['sharpness_tiles'] < 0.5 * $p90) {
			return 'blurry';
		}
		$alert = Db::scalar(
			'SELECT COUNT(*) FROM alerts WHERE webcam_id = ? AND rule_id IN (' . implode(',', array_fill(0, count(self::ALERT_RULES), '?')) . ')
			   AND opened_at <= ? AND (closed_at IS NULL OR closed_at > ?)',
			array_merge([$wid], self::ALERT_RULES, [Db::dt($t), Db::dt($t)])
		);
		if ((int) $alert > 0) {
			return 'alert';
		}
		if ($s['rh_ext'] !== null && ($s['rh_ext'] >= $par['rh_max'] || ($s['spread_ext'] !== null && $s['spread_ext'] < $par['spread_min']))) {
			return 'humid';
		}
		return null;
	}

	/** @param list<array<string,mixed>> $batch */
	private function flicker(int $wid, array &$batch, array $par): void
	{
		if ($batch === []) {
			return;
		}
		$from = $batch[0]['_t'] - 86400;
		$to = $batch[count($batch) - 1]['_t'] + 86400;
		$good = [];
		$inBatch = array_flip(array_column($batch, 'photo_id'));
		foreach (Db::all('SELECT photo_id, taken_at, brightness FROM photo_scores WHERE webcam_id = ? AND good = 1 AND taken_at BETWEEN ? AND ?', [$wid, Db::dt($from), Db::dt($to)]) as $r) {
			if (!isset($inBatch[(int) $r['photo_id']])) {
				$good[] = ['t' => (int) Db::unix($r['taken_at']), 'b' => (float) $r['brightness'], 'id' => (int) $r['photo_id']];
			}
		}
		foreach ($batch as $s) {
			if ($s['reject_reason'] === null) {
				$good[] = ['t' => $s['_t'], 'b' => (float) $s['brightness'], 'id' => $s['photo_id']];
			}
		}
		usort($good, static fn ($a, $b) => [$a['t'], $a['id']] <=> [$b['t'], $b['id']]);
		$pos = [];
		foreach ($good as $i => $g) {
			$pos[$g['id']] = $i;
		}
		foreach ($batch as &$s) {
			if ($s['reject_reason'] !== null || !isset($pos[$s['photo_id']])) {
				continue;
			}
			$i = $pos[$s['photo_id']];
			$near = [];
			foreach ([$i - 2, $i - 1, $i + 1, $i + 2] as $j) {
				if (isset($good[$j]) && abs($good[$j]['t'] - $s['_t']) <= 3 * 3600) {
					$near[] = $good[$j]['b'];
				}
			}
			if (count($near) < 2) {
				continue;
			}
			sort($near);
			$n = count($near);
			$med = $n % 2 ? $near[intdiv($n, 2)] : ($near[$n / 2 - 1] + $near[$n / 2]) / 2;
			if ($med > 0 && abs((float) $s['brightness'] - $med) > $par['flicker_pct'] / 100 * $med) {
				$s['reject_reason'] = 'flicker';
			}
		}
		unset($s);
	}

	/** Punteggio 0-100 (servizi-v1.md, "Punteggio"). */
	public static function score(array $s, ?float $p90): float
	{
		if ($s['blur_ratio'] !== null) {
			$sharp = min(1.0, (float) $s['blur_ratio']);
		} elseif ($p90 !== null && $p90 > 0 && $s['sharpness_tiles'] !== null) {
			$sharp = min(1.0, (float) $s['sharpness_tiles'] / $p90);
		} else {
			$sharp = 1.0;
		}
		$light = max(0.0, 1 - abs((float) $s['brightness'] - 125) / 125);
		$contrast = $s['contrast'] === null ? 1.0 : min(1.0, (float) $s['contrast'] / 50);
		$score = 100 * $sharp * sqrt($light) * $contrast;
		$end = $s['_day_end'] ?? null;
		if ($end !== null && $end - $s['_t'] > 0 && $end - $s['_t'] <= 3600) {
			$score *= 1.1; // luce del tramonto
		}
		return round(min(100.0, max(0.0, $score)), 1);
	}

	/** 90° percentile di sharpness_tiles della webcam negli ultimi 14 giorni. */
	private function p90(int $wid): ?float
	{
		$v = array_map('floatval', array_column(Db::all(
			"SELECT sharpness_tiles FROM photo_metrics WHERE webcam_id = ? AND taken_at >= ? AND sharpness_tiles IS NOT NULL
			 UNION ALL SELECT sharpness_tiles FROM photo_scores WHERE webcam_id = ? AND source = 'own' AND taken_at >= ? AND sharpness_tiles IS NOT NULL",
			[$wid, Db::dt($this->now - 14 * 86400), $wid, Db::dt($this->now - 14 * 86400)]
		), 'sharpness_tiles'));
		if (count($v) < 5) {
			return null;
		}
		sort($v);
		return $v[(int) floor(0.9 * (count($v) - 1))];
	}

	private function save(array $s): void
	{
		$cols = ['photo_id', 'webcam_id', 'taken_at', 'day', 'good', 'reject_reason', 'score', 'brightness', 'contrast', 'sharpness_tiles', 'blur_ratio', 'rh_ext', 'spread_ext', 'source', 'inherited_from', 'params_rev'];
		$vals = [];
		foreach ($cols as $c) {
			$v = $s[$c];
			if (in_array($c, ['brightness', 'contrast'], true) && $v !== null) {
				$v = max(-9999.9, min(9999.9, round((float) $v, 1)));
			}
			if ($c === 'blur_ratio' && $v !== null) {
				$v = min(99.999, round((float) $v, 3));
			}
			$vals[] = $v;
		}
		Db::exec(
			'REPLACE INTO photo_scores (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
			$vals
		);
		$this->stats['scored']++;
	}

	/** Le soglie sono cambiate: params_rev cresce e si ricalcolano gli ultimi 90 giorni. */
	public static function saveParams(int $webcamId, array $good): void
	{
		$tp = Timelapse::params($webcamId);
		$clean = [];
		foreach (self::DEFAULTS as $k => $d) {
			if (isset($good[$k]) && trim((string) $good[$k]) !== '' && is_numeric(str_replace(',', '.', (string) $good[$k]))) {
				$clean[$k] = (float) str_replace(',', '.', (string) $good[$k]);
			}
		}
		$tp['good'] = $clean;
		$tp['good_rev'] = max(1, (int) ($tp['good_rev'] ?? 1)) + 1;
		Timelapse::saveParams($webcamId, $tp);
	}
}
