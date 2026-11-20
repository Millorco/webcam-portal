<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Dolibarr\DolibarrException;
use WebcamPortal\Dolibarr\Services as DolibarrServices;
use WebcamPortal\Log;
use WebcamPortal\Logs\LogData;
use WebcamPortal\Pdf\Pdf;
use WebcamPortal\Rules\Registry;
use WebcamPortal\Site\Images;
use WebcamPortal\Telegram\Notify;

/**
 * Rapporto mensile (O3) e certificato delle giornate di maltempo (O2) in
 * PDF con il generatore del portal, allegati in Dolibarr che manda l'email
 * al cliente (api-dolibarr-v2.md 2.2, POST webcams/{id}/documents).
 */
final class Reports
{
	public const MONTHS = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
	private const LOG = 'reports';
	private const MAX_BYTES = 10 * 1048576;
	private const CLASS_IT = ['ok' => 'regolare', 'bad_weather' => 'maltempo', 'not_certifiable' => 'non certificabile', 'no_data' => 'nessun dato'];
	private const REASON_IT = ['wet' => 'umidità', 'frost' => 'gelo', 'fog' => 'nebbia', 'dark' => 'scuro'];
	private const NC_IT = ['time_unsynced' => 'ora non sincronizzata', 'sensor_missing' => 'sensore esterno assente', 'few_samples' => 'pochi campioni', 'few_photos' => 'poche foto'];

	public array $stats = ['created' => 0, 'generated' => 0, 'sent' => 0, 'emailed' => 0, 'failed' => 0];
	private bool $resend = false;

	public function __construct(private int $now)
	{
	}

	public static function monthLabel(string $month): string
	{
		return self::MONTHS[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
	}

	private static function fmtDay(string $d): string
	{
		return substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
	}

	private static function dt(int $t, \DateTimeZone $tz, string $fmt = 'd/m/Y H:i'): string
	{
		return (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format($fmt);
	}

	private static function num(?float $v, int $dec = 1): string
	{
		return $v === null ? '-' : number_format($v, $dec, ',', '.');
	}

	// ------------------------------------------------------------ pianificazione

	/** Dal giorno reports.day alle reports.time (ora locale): rapporti e certificati del mese prima. */
	public function schedule(?int $onlyWebcam = null): void
	{
		if (!(bool) Config::get()->value('reports.enabled', true)) {
			return;
		}
		$day = max(1, min(28, (int) Config::get()->value('reports.day', 3)));
		$time = (string) Config::get()->value('reports.time', '06:00');
		[$hh, $mm] = array_map('intval', explode(':', preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '06:00'));
		$sql = 'SELECT * FROM webcams WHERE status <> 9';
		$p = [];
		if ($onlyWebcam !== null) {
			$sql .= ' AND id = ?';
			$p[] = $onlyWebcam;
		}
		foreach (Db::all($sql, $p) as $w) {
			Features::reset();
			$tz = ServiceSettings::tz($w);
			$loc = (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz);
			$due = $loc->modify('first day of this month')->setTime($hh, $mm)->modify('+' . ($day - 1) . ' days');
			if ($loc < $due) {
				continue;
			}
			$month = $loc->modify('first day of last month')->format('Y-m');
			foreach ($this->assignments($w, $month) as $a) {
				if (Features::has($w, 'monthly_report')) {
					$n = Db::exec('INSERT IGNORE INTO monthly_reports (webcam_id, customer_id, month, period_from, period_to) VALUES (?, ?, ?, ?, ?)', [$w['id'], $a['customer_id'], $month, $a['from'], $a['to']]);
					$this->stats['created'] += $n;
				}
				if (Features::has($w, 'weather_cert')) {
					$this->stats['created'] += Db::exec("INSERT IGNORE INTO weather_certificates (webcam_id, customer_id, kind, period_from, period_to) VALUES (?, ?, 'month', ?, ?)", [$w['id'], $a['customer_id'], $a['from'], $a['to']]);
				}
			}
		}
	}

	/**
	 * Parti del mese (giorni locali) in cui la webcam era di ogni cliente.
	 *
	 * @return list<array{customer_id:int, from:string, to:string}>
	 */
	public function assignments(array $w, string $month): array
	{
		$tz = ServiceSettings::tz($w);
		$m0 = new \DateTimeImmutable($month . '-01 00:00:00', $tz);
		$m1 = $m0->modify('+1 month');
		$out = [];
		foreach (Db::all('SELECT customer_id, valid_from, valid_to FROM customer_webcams WHERE webcam_id = ? AND valid_from < ? AND (valid_to IS NULL OR valid_to > ?) ORDER BY valid_from', [$w['id'], Db::dt($m1->getTimestamp()), Db::dt($m0->getTimestamp())]) as $r) {
			$a = max($m0->getTimestamp(), (int) Db::unix((string) $r['valid_from']));
			$b = min($m1->getTimestamp(), $r['valid_to'] === null ? PHP_INT_MAX : (int) Db::unix((string) $r['valid_to'])) - 1;
			$from = (new \DateTimeImmutable('@' . $a))->setTimezone($tz)->format('Y-m-d');
			$to = (new \DateTimeImmutable('@' . $b))->setTimezone($tz)->format('Y-m-d');
			$cid = (int) $r['customer_id'];
			if (isset($out[$cid])) {
				$out[$cid]['from'] = min($out[$cid]['from'], $from);
				$out[$cid]['to'] = max($out[$cid]['to'], $to);
			} else {
				$out[$cid] = ['customer_id' => $cid, 'from' => $from, 'to' => $to];
			}
		}
		return array_values($out);
	}

	/** Lavora i rapporti e i certificati da fare (fino a 5 tentativi, uno l'ora). */
	public function process(?int $onlyId = null, string $what = 'all'): void
	{
		foreach (['report' => 'monthly_reports', 'certificate' => 'weather_certificates'] as $kind => $table) {
			if ($what !== 'all' && $what !== $kind) {
				continue;
			}
			$sql = "SELECT * FROM $table WHERE (status = 'pending' OR (status = 'failed' AND attempts < 5 AND updated_at < ?) OR (status = 'ready' AND status_reason = 'dolibarr_error' AND attempts < 5 AND updated_at < ?))";
			$p = [Db::dt($this->now - 3500), Db::dt($this->now - 3500)];
			if ($onlyId !== null) {
				$sql = "SELECT * FROM $table WHERE id = ?";
				$p = [$onlyId];
			}
			foreach (Db::all($sql . ' ORDER BY id LIMIT 50', $p) as $r) {
				$kind === 'report' ? $this->doReport($r) : $this->doCertificate($r);
			}
		}
	}

	private function doReport(array $r, bool $overwrite = false): void
	{
		$id = (int) $r['id'];
		$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$r['webcam_id']]);
		Db::exec("UPDATE monthly_reports SET status = 'generating', attempts = attempts + 1 WHERE id = ?", [$id]);
		try {
			if ($r['storage_path'] === null || $r['status'] !== 'ready' || $overwrite) {
				[$pdf, $data, $pages] = $this->buildReport($w, $r);
				$rel = sprintf('reports/%d/%s-%d.pdf', $w['id'], $r['month'], $r['customer_id']);
				$this->store($rel, $pdf);
				Db::exec(
					"UPDATE monthly_reports SET status = 'ready', status_reason = NULL, data = ?, storage_path = ?, size_bytes = ?, pages = ?, sha256 = ?, generated_at = UTC_TIMESTAMP() WHERE id = ?",
					[json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $rel, strlen($pdf), $pages, hash('sha256', $pdf), $id]
				);
				$this->stats['generated']++;
			}
			$this->sendReport($w, (array) Db::one('SELECT * FROM monthly_reports WHERE id = ?', [$id]), $overwrite);
		} catch (\Throwable $e) {
			$this->failed('monthly_reports', $r, $w, $e);
		}
	}

	private function failed(string $table, array $r, array $w, \Throwable $e): void
	{
		$this->stats['failed']++;
		$att = (int) Db::scalar("SELECT attempts FROM $table WHERE id = ?", [$r['id']]);
		Log::error("$table {$r['id']}: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), self::LOG);
		Db::exec("UPDATE $table SET status = 'failed', status_reason = 'pdf_error' WHERE id = ?", [$r['id']]);
		if ($att >= 5) {
			Events::portal((int) $w['id'], 'report.failed', 'warning', ($table === 'monthly_reports' ? 'Rapporto mensile' : 'Certificato di maltempo') . ' non generato dopo 5 tentativi', ['id' => (int) $r['id'], 'forward' => false]);
		}
	}

	private function store(string $rel, string $pdf): void
	{
		$abs = Config::get()->storageDir() . '/' . $rel;
		PhotoStore::ensureDir($abs);
		$tmp = $abs . '.' . getmypid() . '.tmp';
		file_put_contents($tmp, $pdf);
		rename($tmp, $abs);
	}

	/** Allegato in Dolibarr e, con options.report.enabled, email al cliente (la manda Dolibarr). */
	private function sendReport(array $w, array $r, bool $overwrite): void
	{
		$did = DolibarrServices::webcamId($w);
		if ($did === null) {
			Db::exec("UPDATE monthly_reports SET status = 'ready', status_reason = 'no_dolibarr' WHERE id = ?", [$r['id']]);
			return;
		}
		$opt = Features::plan((int) $w['id'])['options'];
		$emailOn = !array_key_exists('report', $opt) || !is_array($opt['report']) || !array_key_exists('enabled', $opt['report']) || !empty($opt['report']['enabled']);
		$other = (int) Db::scalar('SELECT COUNT(*) FROM monthly_reports WHERE webcam_id = ? AND month = ? AND id < ?', [$w['id'], $r['month'], $r['id']]);
		$socid = Db::scalar('SELECT dolibarr_socid FROM customers WHERE id = ?', [$r['customer_id']]);
		$name = 'rapporto-' . $r['month'] . ($other > 0 ? '-' . ($socid ?? $r['customer_id']) : '') . '.pdf';
		$email = $emailOn && ((int) $r['email_requested'] === 0 || $this->resend) ? ['template' => 'report', 'link' => (string) Config::get()->value('reports.link_url', '')] + ($this->resend ? ['resend' => 1] : []) : null;
		$this->send($w, $r, 'monthly_reports', $name, 'Rapporto mensile, ' . self::monthLabel((string) $r['month']), 'monthly_report', (string) $r['month'], 'report:' . $r['id'], $email, $overwrite);
	}

	/** @param array<string,mixed>|null $email */
	private function send(array $w, array $r, string $table, string $name, string $desc, string $type, string $period, string $docId, ?array $email, bool $overwrite): void
	{
		try {
			$res = DolibarrServices::document($w, Config::get()->storageDir() . '/' . $r['storage_path'], $name, $desc, $type, $period, $docId, $email, $overwrite);
		} catch (DolibarrException $e) {
			if ($e->status === 409) {
				$res = ['ok' => true, 'file' => null, 'email' => null]; // già allegato: nessuna email per un file non salvato
				Log::info("$table {$r['id']}: $name già presente in Dolibarr", self::LOG);
			} else {
				Log::error("$table {$r['id']}: invio a Dolibarr fallito: " . $e->getMessage(), self::LOG);
				Db::exec("UPDATE $table SET status = 'ready', status_reason = 'dolibarr_error' WHERE id = ?", [$r['id']]);
				return;
			}
		}
		$em = is_array($res['email'] ?? null) ? $res['email'] : null;
		$sent = $em !== null && !empty($em['sent']); // sent: true con duplicate: true = già mandata
		$status = $sent || ($email === null && ($r['emailed_at'] ?? null) !== null) ? 'emailed' : 'sent'; // rigenerato senza email: resta emailed
		$reason = $email === null ? null : ($sent ? null : (string) ($em['reason'] ?? 'no_email'));
		$to = $em !== null && is_array($em['to'] ?? null) ? mb_substr(implode(', ', $em['to']), 0, 255) : null;
		$extra = $table === 'monthly_reports' ? ', dolibarr_actioncomm_id = ?' : '';
		$p = [$status, $reason, isset($res['file']) && is_string($res['file']) ? mb_substr($res['file'], 0, 255) : null, $email !== null ? 1 : (int) $r['email_requested'], $sent ? Db::dt(time()) : null, $to];
		if ($table === 'monthly_reports') {
			$p[] = $em !== null && isset($em['actioncomm_id']) ? (int) $em['actioncomm_id'] : null;
		}
		$p[] = $r['id'];
		Db::exec("UPDATE $table SET status = ?, status_reason = ?, dolibarr_file = COALESCE(?, dolibarr_file), dolibarr_sent_at = UTC_TIMESTAMP(), email_requested = ?, emailed_at = COALESCE(?, emailed_at), email_to = COALESCE(?, email_to)$extra WHERE id = ?", $p);
		$this->stats['sent']++;
		if ($sent) {
			$this->stats['emailed']++;
			if ($table === 'monthly_reports') {
				Notify::report($w, $r, self::monthLabel((string) $r['month']), $to);
			}
		}
	}

	/** Rigenerazione dal pannello (overwrite = 1, nessuna seconda email se non chiesta). */
	public function regenerate(string $kind, int $id, bool $resendEmail = false): void
	{
		$table = $kind === 'report' ? 'monthly_reports' : 'weather_certificates';
		$r = Db::one("SELECT * FROM $table WHERE id = ?", [$id]);
		if ($r === null) {
			throw new \InvalidArgumentException('Documento inesistente');
		}
		$this->resend = $resendEmail; // senza: nessuna seconda email (send_email solo se non è mai stata chiesta)
		$r['status'] = 'pending';
		$kind === 'report' ? $this->doReport($r, true) : $this->doCertificate($r, true);
	}

	// ------------------------------------------------------------ dati del rapporto

	/** @return array{0:int,1:int} intervallo UTC del periodo locale */
	private static function range(array $w, string $from, string $to): array
	{
		$tz = ServiceSettings::tz($w);
		return [(new \DateTimeImmutable($from . ' 00:00:00', $tz))->getTimestamp(), (new \DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp()];
	}

	/** Tutti i numeri del rapporto (monthly_reports.data). */
	public function reportData(array $w, array $r): array
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		[$a, $b] = self::range($w, (string) $r['period_from'], (string) $r['period_to']);
		$svc = ServiceSettings::get($wid);
		$av = Db::one('SELECT * FROM availability_monthly WHERE webcam_id = ? AND month = ?', [$wid, $r['month']]);
		$d = [
			'availability_pct' => $av && $av['pct'] !== null ? (float) $av['pct'] : null,
			'availability_final' => $av ? (bool) $av['final'] : false,
			'sla_target_pct' => $av && $av['target_pct'] !== null ? (float) $av['target_pct'] : ($svc['sla_enabled'] ? $svc['sla_target_pct'] : null),
			'sla_met' => $av && $av['sla_met'] !== null ? (bool) $av['sla_met'] : null,
			'hours_available' => $av ? (int) $av['available_h'] : null,
			'hours_expected' => $av ? (int) $av['expected_h'] - (int) $av['excluded_h'] : null,
			'incidents' => $av ? (int) $av['incidents'] : (int) Db::scalar("SELECT COUNT(*) FROM alerts WHERE webcam_id = ? AND rule_id IN ('camera.stalled','telemetry.stalled') AND opened_at >= ? AND opened_at < ?", [$wid, Db::dt($a), Db::dt($b)]),
		];
		$d['photos'] = (int) Db::scalar('SELECT COUNT(*) FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ?', [$wid, Db::dt($a), Db::dt($b)]);
		$days = [];
		foreach (Db::all('SELECT UNIX_TIMESTAMP(taken_at) t FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ?', [$wid, Db::dt($a), Db::dt($b)]) as $p) {
			$days[(new \DateTimeImmutable('@' . (int) $p['t']))->setTimezone($tz)->format('Y-m-d')] = true;
		}
		$d['days_with_photos'] = count($days);
		$d['interventions'] = (int) Db::scalar("SELECT COUNT(*) FROM events WHERE webcam_id = ? AND type = 'intervention' AND ts >= ? AND ts < ?", [$wid, Db::dt($a), Db::dt($b)]);

		// meteo e energia per giorno locale (medie orarie, ore non sincronizzate escluse)
		$daily = [];
		foreach (Db::all('SELECT hour_ts, t_ext_min, t_ext_max, t_ext_avg, rh_ext_avg, panel_wh, battery_v_min FROM measurements_hourly WHERE webcam_id = ? AND hour_ts >= ? AND hour_ts < ? AND samples > samples_unsynced ORDER BY hour_ts', [$wid, Db::dt($a), Db::dt($b)]) as $h) {
			$k = (new \DateTimeImmutable('@' . (int) Db::unix((string) $h['hour_ts'])))->setTimezone($tz)->format('Y-m-d');
			$x = &$daily[$k];
			$x ??= ['tmin' => null, 'tmax' => null, 'tavg' => [], 'rh' => [], 'wh' => null, 'vmin' => null];
			if ($h['t_ext_min'] !== null) {
				$x['tmin'] = $x['tmin'] === null ? (float) $h['t_ext_min'] : min($x['tmin'], (float) $h['t_ext_min']);
				$x['tmax'] = $x['tmax'] === null ? (float) $h['t_ext_max'] : max($x['tmax'], (float) $h['t_ext_max']);
			}
			if ($h['t_ext_avg'] !== null) {
				$x['tavg'][] = (float) $h['t_ext_avg'];
			}
			if ($h['rh_ext_avg'] !== null) {
				$x['rh'][] = (float) $h['rh_ext_avg'];
			}
			if ($h['panel_wh'] !== null) {
				$x['wh'] = ($x['wh'] ?? 0) + (float) $h['panel_wh'];
			}
			if ($h['battery_v_min'] !== null) {
				$x['vmin'] = $x['vmin'] === null ? (float) $h['battery_v_min'] : min($x['vmin'], (float) $h['battery_v_min']);
			}
			unset($x);
		}
		ksort($daily);
		$d['t_ext_min'] = $d['t_ext_max'] = $d['t_ext_min_day'] = $d['t_ext_max_day'] = null;
		$d['frost_days'] = 0;
		$solar = null;
		$vmin = null;
		foreach ($daily as $k => $x) {
			if ($x['tmin'] !== null && ($d['t_ext_min'] === null || $x['tmin'] < $d['t_ext_min'])) {
				$d['t_ext_min'] = $x['tmin'];
				$d['t_ext_min_day'] = $k;
			}
			if ($x['tmax'] !== null && ($d['t_ext_max'] === null || $x['tmax'] > $d['t_ext_max'])) {
				$d['t_ext_max'] = $x['tmax'];
				$d['t_ext_max_day'] = $k;
			}
			if ($x['tmin'] !== null && $x['tmin'] <= 0) {
				$d['frost_days']++;
			}
			if ($x['wh'] !== null) {
				$solar = ($solar ?? 0) + $x['wh'];
			}
			if ($x['vmin'] !== null) {
				$vmin = $vmin === null ? $x['vmin'] : min($vmin, $x['vmin']);
			}
		}
		$d['solar_wh'] = $solar === null ? null : (int) round($solar);
		$d['battery_v_min'] = $vmin;
		$d['bad_weather_days'] = Features::has($w, 'weather_cert') ? (int) Db::scalar("SELECT COUNT(*) FROM daily_register WHERE webcam_id = ? AND day BETWEEN ? AND ? AND classification = 'bad_weather'", [$wid, $r['period_from'], $r['period_to']]) : null;

		// log (giorni UTC del mese) e contatori della reflex
		$logs = Db::all('SELECT * FROM log_counters WHERE webcam_id = ? AND day BETWEEN ? AND ?', [$wid, substr((string) $r['period_from'], 0, 10), (string) $r['period_to']]);
		$boots = $errs = $upl = $uplOk = $hours = 0;
		$missing = 0;
		$byDay = [];
		foreach ($logs as $l) {
			$byDay[(string) $l['day']] = $l;
			$boots += max((int) $l['boots_log'], (int) $l['boots_events']);
			$errs += max((int) $l['camera_errors_log'], (int) $l['camera_errors_events']);
			$upl += (int) $l['upload_failures'];
			$uplOk += (int) $l['uploads_ok'];
			$hours += (int) $l['log_hours'];
		}
		$nDays = (int) ((strtotime((string) $r['period_to']) - strtotime((string) $r['period_from'])) / 86400) + 1;
		$logsOn = LogData::enabled();
		for ($i = 0; $i < $nDays; $i++) {
			$k = gmdate('Y-m-d', strtotime((string) $r['period_from'] . ' UTC') + $i * 86400);
			if ($k >= gmdate('Y-m-d', $this->now)) {
				continue;
			}
			$l = $byDay[$k] ?? null;
			if ($logsOn && ($l === null || (int) $l['log_ok'] !== 1 || $l['log_lines'] === null)) {
				$missing++;
			}
		}
		$d['boots'] = $boots;
		$d['camera_errors'] = $errs;
		$d['upload_failures'] = $logsOn ? $upl : null;  // solo destinazione FTP (frase "upload fallito")
		$d['uploads_ok'] = $logsOn ? $uplOk : null;
		$d['log_hours'] = $logsOn ? $hours : null;
		$d['log_days_missing'] = $logsOn ? $missing : null;
		$d['logs_configured'] = $logsOn;
		$cc = Db::all('SELECT shutter_count, shots_total FROM camera_counters WHERE webcam_id = ? AND ts >= ? AND ts < ? ORDER BY ts', [$wid, Db::dt($a), Db::dt($b)]);
		$used = null;
		if ($cc !== []) {
			$f = $cc[0]['shutter_count'] ?? $cc[0]['shots_total'];
			$l = $cc[count($cc) - 1]['shutter_count'] ?? $cc[count($cc) - 1]['shots_total'];
			$used = $f !== null && $l !== null ? max(0, (int) $l - (int) $f) : null;
			$d['shutter_total'] = $l === null ? null : (int) $l;
		}
		$d['shutter_used'] = $used;
		$d['shutter_life'] = $svc['shutter_life'] === null ? null : (int) $svc['shutter_life'];
		$d['best_photos'] = array_map(static fn ($p) => (int) $p['id'], $this->bestPhotos($w, $a, $b));
		return $d;
	}

	/** 6 foto: la migliore di ogni settimana, poi le altre per punteggio, al più una al giorno. */
	private function bestPhotos(array $w, int $a, int $b, int $n = 6): array
	{
		$tz = ServiceSettings::tz($w);
		$rows = Db::all(
			'SELECT p.id, p.taken_at, p.storage_path, p.webcam_id, p.width, p.height, p.time_synced, p.t_ext, p.rh_ext, s.score
			   FROM photo_scores s JOIN photos p ON p.id = s.photo_id
			  WHERE s.webcam_id = ? AND s.good = 1 AND p.taken_at >= ? AND p.taken_at < ? AND p.deleted_at IS NULL AND p.is_public = 1
			  ORDER BY s.score DESC, p.id LIMIT 2000',
			[$w['id'], Db::dt($a), Db::dt($b)]
		);
		$pick = [];
		$daysUsed = [];
		$weeks = [];
		foreach ($rows as $r) {
			$loc = (new \DateTimeImmutable('@' . (int) Db::unix((string) $r['taken_at'])))->setTimezone($tz);
			$wk = $loc->format('o-W');
			if (!isset($weeks[$wk]) && !isset($daysUsed[$loc->format('Y-m-d')])) {
				$weeks[$wk] = true;
				$daysUsed[$loc->format('Y-m-d')] = true;
				$pick[(int) $r['id']] = $r;
			}
		}
		foreach ($rows as $r) {
			if (count($pick) >= $n) {
				break;
			}
			$d = (new \DateTimeImmutable('@' . (int) Db::unix((string) $r['taken_at'])))->setTimezone($tz)->format('Y-m-d');
			if (!isset($daysUsed[$d]) && !isset($pick[(int) $r['id']])) {
				$daysUsed[$d] = true;
				$pick[(int) $r['id']] = $r;
			}
		}
		$pick = array_slice(array_values($pick), 0, $n);
		usort($pick, static fn ($x, $y) => strcmp((string) $x['taken_at'], (string) $y['taken_at']));
		return $pick;
	}

	/** Miniatura JPEG baseline (senza interlacciatura) per il PDF, con sovrimpressione se attiva. */
	public static function thumb(array $w, array $p, int $width, int $quality): ?string
	{
		$orig = PhotoStore::abs((string) $p['storage_path']);
		if (!is_file($orig)) {
			return null;
		}
		try {
			$src = Images::derived($p, $orig, '640', $w, Images::overlayFor($w, '640'));
			$im = Images::open($src);
		} catch (\Throwable $e) {
			Log::warning("miniatura della foto {$p['id']} non fatta: " . $e->getMessage(), self::LOG);
			return null;
		}
		if (imagesx($im) > $width) {
			$s = imagescale($im, $width, -1, IMG_BICUBIC);
			imagedestroy($im);
			if ($s === false) {
				return null;
			}
			$im = $s;
		}
		imageinterlace($im, false);
		ob_start();
		imagejpeg($im, null, $quality);
		imagedestroy($im);
		return (string) ob_get_clean();
	}

	private function header(Pdf $pdf, string $title, string $sub): float
	{
		$pdf->color(28, 64, 110);
		$pdf->rect(0, 0, Pdf::W, 64, 'F');
		$pdf->color(255, 255, 255);
		$pdf->font(true, 16);
		$pdf->text(40, 30, 'Viewlapse');
		$pdf->font(false, 10);
		$pdf->text(40, 48, $title);
		$pdf->text(Pdf::W - 40, 48, $sub, 'right');
		$pdf->color(0, 0, 0);
		return 90;
	}

	private function h2(Pdf $pdf, float $y, string $t): float
	{
		$pdf->font(true, 12);
		$pdf->color(28, 64, 110);
		$pdf->text(40, $y, $t);
		$pdf->color(28, 64, 110, false);
		$pdf->line(40, $y + 4, Pdf::W - 40, $y + 4, 0.6);
		$pdf->color(0, 0, 0);
		$pdf->color(0, 0, 0, false);
		$pdf->font(false, 10);
		return $y + 20;
	}

	private function footer(Pdf $pdf, string $what): void
	{
		$gen = (new \DateTimeImmutable('@' . $this->now))->setTimezone(new \DateTimeZone('Europe/Rome'))->format('d/m/Y H:i');
		$pdf->setFooter(static function (Pdf $p, int $n, int $tot) use ($gen, $what): void {
			$p->font(false, 7.5);
			$p->color(110, 110, 110);
			$p->text(40, Pdf::H - 24, "$what generato il $gen da Viewlapse");
			$p->text(Pdf::W - 40, Pdf::H - 24, "Pagina $n di $tot", 'right');
			$p->color(0, 0, 0);
		});
	}

	/** Il PDF del rapporto (con miniature più leggere se supera i 10 MB). @return array{0:string,1:array,2:int} */
	public function buildReport(array $w, array $r): array
	{
		$data = $this->reportData($w, $r);
		foreach ([[640, 82], [640, 60], [480, 60], [320, 55]] as [$tw, $q]) {
			[$pdf, $pages] = $this->renderReport($w, $r, $data, $tw, $q);
			if (strlen($pdf) <= self::MAX_BYTES) {
				return [$pdf, $data, $pages];
			}
		}
		throw new \RuntimeException('PDF oltre 10 MB anche con le miniature ridotte');
	}

	private function renderReport(array $w, array $r, array $d, int $thumbW, int $q): array
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		$c = (array) Db::one('SELECT name FROM customers WHERE id = ?', [$r['customer_id']]);
		$label = self::monthLabel((string) $r['month']);
		$pdf = new Pdf('Rapporto mensile ' . ($w['name'] ?: $w['slug']) . ' ' . $label);
		$this->footer($pdf, 'Rapporto');
		$name = (string) ($w['name'] ?: $w['slug']);

		// copertina
		$pdf->addPage();
		$y = $this->header($pdf, 'Rapporto mensile della webcam', ucfirst($label));
		$ov = Db::one('SELECT logo_asset_id FROM overlay_config WHERE webcam_id = ?', [$wid]);
		$logo = \WebcamPortal\Overlay\Overlay::assetFile($ov['logo_asset_id'] ?? null);
		if ($logo !== null && ($lj = self::logoJpeg($logo)) !== null) {
			$pdf->jpeg($lj, Pdf::W - 160, $y, 120);
		}
		$pdf->font(true, 22);
		$pdf->text(40, $y + 40, $name);
		$pdf->font(false, 12);
		$pdf->text(40, $y + 62, (string) ($w['location'] ?? ''));
		$pdf->text(40, $y + 84, 'Cliente: ' . ($c['name'] ?? ''));
		$pdf->text(40, $y + 102, 'Mese: ' . $label . ' (dal ' . self::fmtDay((string) $r['period_from']) . ' al ' . self::fmtDay((string) $r['period_to']) . ')');

		// sintesi
		$y = $this->h2($pdf, $y + 140, 'Sintesi');
		$sla = $d['availability_pct'] === null ? 'non calcolata' : self::num($d['availability_pct'], 2) . ' %';
		if ($d['sla_target_pct'] !== null && $d['sla_met'] !== null) {
			$sla .= ' (impegno ' . self::num($d['sla_target_pct'], 1) . ' %: ' . ($d['sla_met'] ? 'rispettato' : 'non rispettato') . ')';
		}
		if ($d['availability_pct'] !== null && !$d['availability_final']) {
			$sla .= ', provvisoria';
		}
		$rows = [
			['Disponibilità del servizio', $sla],
			['Foto ricevute', number_format($d['photos'], 0, ',', '.') . ' in ' . $d['days_with_photos'] . ' giorni'],
			['Ore disponibili / previste', $d['hours_expected'] === null ? '-' : $d['hours_available'] . ' / ' . $d['hours_expected']],
			['Interruzioni del servizio', (string) $d['incidents']],
			['Interventi del tecnico', (string) $d['interventions']],
		];
		$y = $pdf->table(40, $y, [['Voce', 200], ['Valore', 315]], $rows, 9.5);

		// foto migliori
		$best = $d['best_photos'] === [] ? [] : Db::all('SELECT * FROM photos WHERE id IN (' . implode(',', array_map('intval', $d['best_photos'])) . ') ORDER BY taken_at');
		if ($best !== []) {
			$pdf->addPage();
			$y = $this->h2($pdf, 50, 'Le foto migliori del mese');
			$col = 0;
			$cw = 250;
			foreach ($best as $p) {
				$j = self::thumb($w, $p, $thumbW, $q);
				if ($j === null) {
					continue;
				}
				$x = 40 + $col * ($cw + 15);
				$h = $pdf->jpeg($j, $x, $y, $cw);
				$pdf->font(false, 8.5);
				$pdf->text($x, $y + $h + 11, (new \DateTimeImmutable('@' . (int) Db::unix((string) $p['taken_at'])))->setTimezone($tz)->format('d/m/Y H:i'));
				$col++;
				if ($col === 2) {
					$col = 0;
					$y += $h + 26;
				}
			}
		}

		// meteo, energia, funzionamento
		$pdf->addPage();
		$y = $this->h2($pdf, 50, 'Meteo');
		[$a, $b] = self::range($w, (string) $r['period_from'], (string) $r['period_to']);
		$labels = [];
		$tmin = $tavg = $tmax = $rhs = $wh = $vmin = $avail = [];
		$hourly = Db::all('SELECT hour_ts, t_ext_min, t_ext_max, t_ext_avg, rh_ext_avg, panel_wh, battery_v_min FROM measurements_hourly WHERE webcam_id = ? AND hour_ts >= ? AND hour_ts < ? AND samples > samples_unsynced', [$wid, Db::dt($a), Db::dt($b)]);
		$byDay = [];
		foreach ($hourly as $h) {
			$byDay[(new \DateTimeImmutable('@' . (int) Db::unix((string) $h['hour_ts'])))->setTimezone($tz)->format('Y-m-d')][] = $h;
		}
		$ad = [];
		foreach (Db::all('SELECT day, pct FROM availability_daily WHERE webcam_id = ? AND day BETWEEN ? AND ?', [$wid, $r['period_from'], $r['period_to']]) as $x) {
			$ad[(string) $x['day']] = $x['pct'] === null ? null : (float) $x['pct'];
		}
		for ($t = new \DateTimeImmutable((string) $r['period_from'], $tz); $t->format('Y-m-d') <= $r['period_to']; $t = $t->modify('+1 day')) {
			$k = $t->format('Y-m-d');
			$labels[] = $t->format('j');
			$hs = $byDay[$k] ?? [];
			$vals = static fn (string $col) => array_values(array_filter(array_map(static fn ($h) => $h[$col] === null ? null : (float) $h[$col], $hs), static fn ($v) => $v !== null));
			$mn = $vals('t_ext_min');
			$mx = $vals('t_ext_max');
			$av = $vals('t_ext_avg');
			$rh = $vals('rh_ext_avg');
			$tmin[] = $mn === [] ? null : min($mn);
			$tmax[] = $mx === [] ? null : max($mx);
			$tavg[] = $av === [] ? null : array_sum($av) / count($av);
			$rhs[] = $rh === [] ? null : array_sum($rh) / count($rh);
			$whs = $vals('panel_wh');
			$wh[] = $whs === [] ? null : array_sum($whs);
			$vm = $vals('battery_v_min');
			$vmin[] = $vm === [] ? null : min($vm);
			$avail[] = $ad[$k] ?? null;
		}
		$pdf->chart(40, $y, 515, 170, $labels, [
			['label' => 'minima', 'values' => $tmin, 'color' => [40, 110, 200]],
			['label' => 'media', 'values' => $tavg, 'color' => [90, 90, 90]],
			['label' => 'massima', 'values' => $tmax, 'color' => [210, 70, 50]],
		], '°C');
		$y += 185;
		$pdf->chart(40, $y, 515, 110, $labels, [['label' => 'umidità media', 'values' => $rhs, 'color' => [40, 150, 120]]], '%', 0, 100);
		$y += 128;
		$pdf->font(false, 9.5);
		$txt = 'Minima del mese ' . self::num($d['t_ext_min']) . ' °C' . ($d['t_ext_min_day'] ? ' il ' . self::fmtDay($d['t_ext_min_day']) : '')
			. ', massima ' . self::num($d['t_ext_max']) . ' °C' . ($d['t_ext_max_day'] ? ' il ' . self::fmtDay($d['t_ext_max_day']) : '')
			. '. Giornate di gelo (minima al più 0 °C): ' . $d['frost_days'] . '.';
		if ($d['bad_weather_days'] !== null) {
			$txt .= ' Giornate di maltempo registrate: ' . $d['bad_weather_days'] . ' (dettagli nel certificato delle giornate di maltempo).';
		}
		$y = $pdf->paragraph(40, $y, 515, $txt);
		if ($d['solar_wh'] !== null) {
			$y = $this->h2($pdf, $y + 10, 'Energia');
			$pdf->chart(40, $y, 515, 110, $labels, [['label' => 'energia solare (Wh)', 'values' => $wh, 'color' => [230, 160, 30], 'bars' => true]], 'Wh', 0);
			$y += 128;
			$pdf->font(false, 9.5);
			$y = $pdf->paragraph(40, $y, 515, 'Energia solare del mese: ' . number_format((int) $d['solar_wh'], 0, ',', '.') . ' Wh. Tensione minima della batteria: ' . self::num($d['battery_v_min'], 2) . ' V.');
		}
		$pdf->addPage();
		$y = $this->h2($pdf, 50, 'Funzionamento');
		$pdf->chart(40, $y, 515, 110, $labels, [['label' => 'disponibilità per giorno (%)', 'values' => $avail, 'color' => [28, 64, 110], 'bars' => true]], '%', 0, 100);
		$y += 128;
		$frows = [['Riavvii del router', (string) $d['boots']], ['Errori della fotocamera', (string) $d['camera_errors']]];
		if ($d['logs_configured']) {
			$frows[] = ['Invii FTP falliti (dai log)', (string) $d['upload_failures']];
			$frows[] = ['Ore con log', (string) $d['log_hours']];
		}
		if ($d['shutter_used'] !== null) {
			$frows[] = ['Scatti dell\'otturatore nel mese', number_format($d['shutter_used'], 0, ',', '.') . ($d['shutter_life'] && isset($d['shutter_total']) ? ' (totale ' . number_format($d['shutter_total'], 0, ',', '.') . ', ' . self::num(100 * $d['shutter_total'] / $d['shutter_life'], 0) . ' % della vita prevista)' : '')];
		}
		$y = $pdf->table(40, $y, [['Voce', 220], ['Valore', 295]], $frows, 9.5);
		$pdf->font(false, 8);
		$note = 'I conteggi dei log sono per giorno UTC: ai bordi del mese possono differire di una o due ore dal fuso della webcam.';
		if (!$d['logs_configured']) {
			$note = 'Dati dei log non disponibili: riavvii ed errori sono contati dagli eventi del router.';
		} elseif ($d['log_days_missing'] > 0) {
			$note .= ' Dati dei log mancanti per ' . $d['log_days_missing'] . ' giorni (server dei log non raggiungibile): riavvii ed errori di quei giorni sono contati dagli eventi del router.';
		}
		$y = $pdf->paragraph(40, $y + 12, 515, $note);

		// eventi e interventi
		$ev = [];
		foreach (Db::all("SELECT rule_id, opened_at, closed_at, status FROM alerts WHERE webcam_id = ? AND action IN ('ticket','agenda') AND opened_at < ? AND (closed_at IS NULL OR closed_at >= ?) ORDER BY opened_at", [$wid, Db::dt($b), Db::dt($a)]) as $al) {
			$rule = Registry::get((string) $al['rule_id']);
			$o = (int) Db::unix((string) $al['opened_at']);
			$cl = $al['closed_at'] === null ? null : (int) Db::unix((string) $al['closed_at']);
			$ev[] = [self::dt($o, $tz), $rule ? $rule->label() : 'Guasto', $cl === null ? 'in corso' : 'dal ' . self::dt($o, $tz) . ' al ' . self::dt($cl, $tz) . ', ' . self::num(($cl - $o) / 3600, 1) . ' ore, risolto'];
		}
		$types = ['intervention' => 'Intervento del tecnico', 'maintenance.on' => 'Manutenzione programmata', 'maintenance.off' => 'Fine della manutenzione', 'camera.change' => 'Cambio della fotocamera'];
		foreach (Db::all("SELECT type, ts FROM events WHERE webcam_id = ? AND type IN ('intervention','maintenance.on','maintenance.off','camera.change') AND ts >= ? AND ts < ? ORDER BY ts", [$wid, Db::dt($a), Db::dt($b)]) as $e) {
			$ev[] = [self::dt((int) Db::unix((string) $e['ts']), $tz), $types[$e['type']], ''];
		}
		$y = $this->h2($pdf, $y + 16, 'Eventi e interventi');
		if ($ev === []) {
			$pdf->text(40, $y, 'Nessun guasto né intervento nel mese.');
			$y += 16;
		} else {
			$y = $pdf->table(40, $y, [['Quando', 90], ['Cosa', 190], ['Dettagli', 235]], $ev, 8.5);
		}

		// timelapse del mese
		$tl = Db::one("SELECT * FROM timelapses WHERE webcam_id = ? AND kind = 'month' AND period_from = ? AND status = 'done' AND format = 'landscape' ORDER BY id DESC LIMIT 1", [$wid, $r['month'] . '-01']);
		if ($tl !== null && $tl['poster_path'] !== null && is_file(Config::get()->storageDir() . '/' . $tl['poster_path'])) {
			if ($y > 560) {
				$pdf->addPage();
				$y = 50;
			}
			$y = $this->h2($pdf, $y + 16, 'Timelapse del mese');
			$im = @imagecreatefromjpeg(Config::get()->storageDir() . '/' . $tl['poster_path']);
			if ($im !== false) {
				$s = imagescale($im, $thumbW, -1, IMG_BICUBIC) ?: $im;
				imageinterlace($s, false);
				ob_start();
				imagejpeg($s, null, $q);
				$h = $pdf->jpeg((string) ob_get_clean(), 40, $y, 300);
				$y += $h + 14;
			}
			$link = trim((string) Config::get()->value('reports.link_url', ''));
			$pdf->font(false, 9.5);
			$pdf->paragraph(40, $y, 515, $link !== '' ? "Il timelapse si guarda nella pagina della webcam: $link" : 'Il timelapse si guarda nella pagina della webcam del portale clienti.');
		}
		$out = $pdf->output();
		return [$out, $pdf->pageCount()];
	}

	/** Logo PNG/JPEG su fondo bianco come JPEG baseline per il PDF. */
	private static function logoJpeg(string $file): ?string
	{
		$info = @getimagesize($file);
		if ($info === false) {
			return null;
		}
		$im = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
		if ($im === false) {
			return null;
		}
		$bg = imagecreatetruecolor(imagesx($im), imagesy($im));
		imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
		imagecopy($bg, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
		imageinterlace($bg, false);
		ob_start();
		imagejpeg($bg, null, 90);
		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------ certificato

	/** Certificato per un periodo qualsiasi (operatore, kind = custom). */
	public static function requestCertificate(int $webcamId, int $customerId, string $from, string $to, ?int $userId = null): int
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
			throw new \InvalidArgumentException('Periodo non valido');
		}
		Db::exec("INSERT IGNORE INTO weather_certificates (webcam_id, customer_id, kind, period_from, period_to, user_id) VALUES (?, ?, 'custom', ?, ?, ?)", [$webcamId, $customerId, $from, $to, $userId]);
		return (int) Db::scalar("SELECT id FROM weather_certificates WHERE webcam_id = ? AND customer_id = ? AND kind = 'custom' AND period_from = ? AND period_to = ?", [$webcamId, $customerId, $from, $to]);
	}

	private function doCertificate(array $r, bool $overwrite = false): void
	{
		$id = (int) $r['id'];
		$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$r['webcam_id']]);
		Db::exec("UPDATE weather_certificates SET status = 'generating', attempts = attempts + 1 WHERE id = ?", [$id]);
		try {
			if ($r['storage_path'] === null || $r['status'] !== 'ready' || $overwrite) {
				[$pdf, $data, $pages] = $this->buildCertificate($w, $r);
				$rel = sprintf('certificates/%d/%d.pdf', $w['id'], $id);
				$this->store($rel, $pdf);
				Db::exec(
					"UPDATE weather_certificates SET status = 'ready', status_reason = NULL, days_total = ?, days_bad = ?, days_not_certifiable = ?, data = ?, storage_path = ?,
					   size_bytes = ?, pages = ?, sha256 = ?, generated_at = UTC_TIMESTAMP() WHERE id = ?",
					[$data['days_total'], $data['days_bad'], $data['days_not_certifiable'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $rel, strlen($pdf), $pages, hash('sha256', $pdf), $id]
				);
				$this->stats['generated']++;
			}
			$c = (array) Db::one('SELECT * FROM weather_certificates WHERE id = ?', [$id]);
			if (DolibarrServices::webcamId($w) === null) {
				Db::exec("UPDATE weather_certificates SET status = 'ready', status_reason = 'no_dolibarr' WHERE id = ?", [$id]);
				return;
			}
			$period = $c['kind'] === 'month' ? substr((string) $c['period_from'], 0, 7) : $c['period_from'] . '_' . $c['period_to'];
			$opt = Features::plan((int) $w['id'])['options'];
			$emailOn = !isset($opt['report']['enabled']) || !empty($opt['report']['enabled']);
			$email = $emailOn && ((int) $c['email_requested'] === 0 || $this->resend) ? ['template' => 'weather', 'link' => (string) Config::get()->value('reports.link_url', '')] + ($this->resend ? ['resend' => 1] : []) : null;
			$this->send($w, $c, 'weather_certificates', 'maltempo-' . $period . '.pdf', 'Giornate di maltempo certificate, ' . ($c['kind'] === 'month' ? self::monthLabel($period) : self::fmtDay((string) $c['period_from']) . ' - ' . self::fmtDay((string) $c['period_to'])),
				'weather_certificate', $period, 'certificate:' . $id, $email, $overwrite);
		} catch (\Throwable $e) {
			$this->failed('weather_certificates', $r, $w, $e);
		}
	}

	/** @return array{0:string,1:array,2:int} */
	public function buildCertificate(array $w, array $r): array
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		$days = Db::all('SELECT * FROM daily_register WHERE webcam_id = ? AND day BETWEEN ? AND ? AND sealed_at IS NOT NULL ORDER BY day', [$wid, $r['period_from'], $r['period_to']]);
		$crit = Register::criteria($wid);
		$data = ['days_total' => count($days), 'days_bad' => 0, 'days_not_certifiable' => 0, 'criteria' => $crit, 'days' => [], 'photo_ids' => [], 'dolibarr' => DolibarrServices::webcamId($w) !== null];
		foreach ($days as $d) {
			$data['days_bad'] += $d['classification'] === 'bad_weather' ? 1 : 0;
			$data['days_not_certifiable'] += $d['classification'] === 'not_certifiable' ? 1 : 0;
			$data['days'][] = ['day' => $d['day'], 'classification' => $d['classification'], 'reasons' => $d['reasons'], 'day_hash' => $d['day_hash'],
				'registered_at' => $d['dolibarr_registered_at'] === null ? null : Db::unix((string) $d['dolibarr_registered_at'])];
		}
		foreach ([[400, 80], [400, 60], [300, 55]] as [$tw, $q]) {
			$data['photo_ids'] = [];
			[$pdf, $pages] = $this->renderCertificate($w, $r, $days, $crit, $data, $tw, $q);
			if (strlen($pdf) <= self::MAX_BYTES) {
				return [$pdf, $data, $pages];
			}
		}
		throw new \RuntimeException('PDF oltre 10 MB anche con le miniature ridotte');
	}

	private function renderCertificate(array $w, array $r, array $days, array $crit, array &$data, int $thumbW, int $q): array
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		$name = (string) ($w['name'] ?: $w['slug']);
		$c = (array) Db::one('SELECT name FROM customers WHERE id = ?', [$r['customer_id']]);
		$pdf = new Pdf('Giornate di maltempo ' . $name . ' ' . $r['period_from'] . ' - ' . $r['period_to']);
		$this->footer($pdf, 'Certificato');
		$pdf->addPage();
		$y = $this->header($pdf, 'Certificato delle giornate di maltempo', self::fmtDay((string) $r['period_from']) . ' - ' . self::fmtDay((string) $r['period_to']));
		$pdf->font(true, 16);
		$pdf->text(40, $y + 10, $name);
		$pdf->font(false, 10);
		$loc = trim((string) ($w['location'] ?? ''));
		$coord = $w['latitude'] !== null ? sprintf('%.5f, %.5f', (float) $w['latitude'], (float) $w['longitude']) : 'coordinate non indicate';
		$y = $pdf->paragraph(40, $y + 30, 515, ($loc !== '' ? "$loc - " : '') . $coord . ($w['altitude_m'] !== null ? ', quota ' . (int) $w['altitude_m'] . ' m' : ''));
		$y = $pdf->paragraph(40, $y, 515, 'Cliente: ' . ($c['name'] ?? '') . '. Periodo: dal ' . self::fmtDay((string) $r['period_from']) . ' al ' . self::fmtDay((string) $r['period_to'])
			. '. Emesso il ' . (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz)->format('d/m/Y') . '.');
		$y = $pdf->paragraph(40, $y, 515, 'Giornate registrate: ' . $data['days_total'] . ', di maltempo: ' . $data['days_bad'] . ', non certificabili: ' . $data['days_not_certifiable'] . '.');

		$y = $this->h2($pdf, $y + 10, 'Metodo e soglie');
		$wh = Register::workHours($wid, (string) $r['period_from'], $tz, $crit);
		$y = $pdf->table(40, $y, [['Condizione', 150], ['Quando', 365]], [
			['Ore di lavoro', $wh['hours'] . (ServiceSettings::get($wid)['sla_weekdays'] ? ', giorni ' . ServiceSettings::get($wid)['sla_weekdays'] . ' (1 = lunedì)' : ', tutti i giorni')],
			['Ora umida', 'umidità media almeno ' . self::num($crit['rh_wet'], 0) . ' % oppure temperatura - rugiada al più ' . self::num($crit['spread_wet']) . ' °C, con almeno ' . (int) $crit['min_samples_hour'] . ' campioni su 12'],
			['Ora di gelo', 'temperatura minima al più ' . self::num($crit['frost_c']) . ' °C'],
			['Ora di nebbia', 'almeno una foto classificata nebbia esterna dall\'analisi delle foto'],
			['Ora scura', 'luminosità mediana sotto il ' . self::num(100 * $crit['dark_ratio'], 0) . ' % della foto di riferimento (solo conferma)'],
			['Giornata di maltempo', 'almeno ' . (int) $crit['min_wet_hours'] . ' ore umide, o ' . (int) $crit['min_frost_hours'] . ' ore di gelo, o ' . (int) $crit['min_fog_hours'] . ' ore di nebbia'],
			['Non certificabile', 'campioni validi sotto l\'' . self::num(100 * $crit['min_coverage'], 0) . ' %, ore con foto sotto l\'80 %, o più del 20 % di campioni con l\'ora non sincronizzata'],
		], 8.5);
		$y = $this->h2($pdf, $y + 12, 'Limiti');
		$pdf->font(false, 8.5);
		foreach ([
			'Nessuna misura di precipitazione, vento o neve: le ore umide sono aria satura o quasi, compatibile con pioggia, nebbia o rugiada; dopo la pioggia l\'umidità resta alta per qualche ora; una pioggia breve con aria non satura può non comparire.',
			'La neve non si distingue dalla pioggia, se non per la temperatura (ore di gelo).',
			'Le misure sono puntuali, nel luogo della webcam, con un sensore SHT31 (circa 2 % di umidità, 0,3 °C), schermato dal sole come installato.',
			'Il registro prova integrità e ora delle foto e dei dati (ora sincronizzata con NTP, impronte depositate in Dolibarr il giorno dopo); la classificazione meteo è un indizio secondo soglie concordate nel contratto, non sostituisce i dati ufficiali (ARPA, stazioni meteo) né la valutazione della direzione lavori.',
		] as $t) {
			$y = $pdf->paragraph(48, $y, 507, '- ' . $t, 1.3);
		}
		if (!$data['dolibarr']) {
			$y = $pdf->paragraph(48, $y, 507, '- Le impronte di questa webcam non sono depositate in un secondo sistema: il registro vale come dato del portal.', 1.3);
		}

		// tabella dei giorni
		$pdf->addPage();
		$y = $this->h2($pdf, 50, 'Giorni del periodo');
		$rows = [];
		foreach ($days as $d) {
			$wh = Register::workHours($wid, (string) $d['day'], $tz, $crit);
			$reasons = $d['reasons'] === null || $d['reasons'] === '' ? '' : implode(', ', array_map(static fn ($x) => self::REASON_IT[$x] ?? $x, explode(',', (string) $d['reasons'])));
			if ($d['not_cert_reason']) {
				$reasons = self::NC_IT[$d['not_cert_reason']] ?? (string) $d['not_cert_reason'];
			}
			$rows[] = [self::fmtDay((string) $d['day']), $wh['workday'] ? 'sì' : 'no', self::CLASS_IT[$d['classification']] ?? (string) $d['classification'], $reasons,
				$d['wet_hours'] . '/' . $d['frost_hours'] . '/' . $d['fog_hours'], self::num($d['t_ext_min'] === null ? null : (float) $d['t_ext_min']) . ' / ' . self::num($d['t_ext_max'] === null ? null : (float) $d['t_ext_max']),
				self::num($d['rh_ext_avg'] === null ? null : (float) $d['rh_ext_avg'], 0), (string) $d['photos_total'], substr((string) $d['day_hash'], 0, 16),
				$d['dolibarr_registered_at'] === null ? '-' : self::dt((int) Db::unix((string) $d['dolibarr_registered_at']), $tz)];
		}
		$pdf->table(30, $y, [['Giorno', 48], ['Lav.', 24, 'center'], ['Esito', 58], ['Motivi', 66], ['U/G/N', 34, 'center'], ['Tmin/Tmax', 52, 'center'], ['UR %', 26, 'right'], ['Foto', 26, 'right'], ['Impronta del giorno', 98], ['Deposito', 103]], $rows, 7);

		// una pagina per giornata di maltempo
		foreach ($days as $d) {
			if ($d['classification'] !== 'bad_weather') {
				continue;
			}
			$pdf->addPage();
			$y = $this->h2($pdf, 50, 'Giornata di maltempo del ' . self::fmtDay((string) $d['day']));
			$wh = Register::workHours($wid, (string) $d['day'], $tz, $crit);
			$base = new \DateTimeImmutable($d['day'] . ' 00:00:00', $tz);
			$a = $base->setTime($wh['from'], 0)->getTimestamp();
			$b = $wh['to'] >= 24 ? $base->modify('+1 day')->getTimestamp() : $base->setTime($wh['to'], 0)->getTimestamp();
			$hrs = [];
			foreach (Db::all('SELECT UNIX_TIMESTAMP(ts) t, t_ext, rh_ext, dp_ext FROM measurements WHERE webcam_id = ? AND ts >= ? AND ts < ? AND time_synced = 1 AND ext_sensor_ok = 1', [$wid, Db::dt($a), Db::dt($b)]) as $m) {
				$hrs[intdiv((int) $m['t'] - $a, 3600)][] = $m;
			}
			$labels = $tt = $rr = $dd = [];
			for ($h = 0; $h < max(1, (int) round(($b - $a) / 3600)); $h++) {
				$labels[] = sprintf('%02d', $wh['from'] + $h);
				$ms = $hrs[$h] ?? [];
				$av = static fn (string $k) => ($v = array_filter(array_map(static fn ($m) => $m[$k] === null ? null : (float) $m[$k], $ms), static fn ($x) => $x !== null)) === [] ? null : array_sum($v) / count($v);
				$tt[] = $av('t_ext');
				$rr[] = $av('rh_ext');
				$dd[] = $av('dp_ext');
			}
			$pdf->chart(40, $y, 515, 140, $labels, [['label' => 'temperatura', 'values' => $tt, 'color' => [210, 70, 50]], ['label' => 'punto di rugiada', 'values' => $dd, 'color' => [40, 110, 200]]], '°C');
			$y += 156;
			$pdf->chart(40, $y, 515, 100, $labels, [['label' => 'umidità', 'values' => $rr, 'color' => [40, 150, 120]]], '%', 0, 100);
			$y += 118;
			$pdf->font(false, 9);
			$y = $pdf->paragraph(40, $y, 515, 'Ore umide ' . $d['wet_hours'] . ', di gelo ' . $d['frost_hours'] . ', di nebbia ' . $d['fog_hours'] . ', scure ' . $d['dark_hours'] . '. Impronta del giorno: ' . $d['day_hash']);
			// fino a 6 foto, una ogni due ore di lavoro
			$col = 0;
			for ($t = $a; $t < $b && $col < 6; $t += 7200) {
				$p = Db::one('SELECT * FROM photos WHERE webcam_id = ? AND taken_at >= ? AND taken_at < ? AND time_synced = 1 AND deleted_at IS NULL ORDER BY taken_at LIMIT 1', [$wid, Db::dt($t), Db::dt(min($b, $t + 7200))]);
				if ($p === null || ($j = self::thumb($w, $p, $thumbW, $q)) === null) {
					continue;
				}
				$data['photo_ids'][] = (int) $p['id'];
				$x = 40 + ($col % 3) * 175;
				$yy = $y + 8 + intdiv($col, 3) * 135;
				$h = $pdf->jpeg($j, $x, $yy, 165);
				$pdf->font(false, 7);
				$pdf->text($x, $yy + $h + 9, self::dt((int) Db::unix((string) $p['taken_at']), $tz, 'H:i') . ' - SHA-256 ' . substr((string) $p['sha256'], 0, 16));
				$col++;
			}
		}

		// verifica
		$pdf->addPage();
		$y = $this->h2($pdf, 50, 'Come verificare');
		$pdf->font(false, 9.5);
		foreach ([
			'Ogni giorno il portal scrive l\'elenco delle foto ricevute con l\'ora sincronizzata (file AAAA-MM-GG.txt, formato vlreg1): una riga per foto con l\'impronta SHA-256, l\'ora dello scatto e della ricezione, il nome e la dimensione del file. La prima riga contiene l\'impronta del giorno prima: gli elenchi formano una catena.',
			'Impronta di un elenco: sha256sum AAAA-MM-GG.txt deve dare l\'impronta del giorno della tabella (le prime 16 cifre sono stampate qui, quella intera è nel registro).',
			'Impronta di una foto: sha256sum raw_AAAAMMGG-HHMM.jpg e cercare il risultato nell\'elenco del giorno.',
			'Gli elenchi si scaricano dal pannello del cliente o dall\'API dei siti (registro e giornate di maltempo). Le impronte dei giorni sono depositate in Dolibarr il giorno dopo, con la loro ora di registrazione (colonna Deposito), e non si possono più cambiare.',
			'Le miniature di questo documento sono ridotte: la prova vale per le foto originali, che il cliente può chiedere finché sono conservate. Le foto delle giornate di maltempo dei certificati emessi non si cancellano.',
		] as $t) {
			$y = $pdf->paragraph(40, $y, 515, $t) + 4;
		}
		return [$pdf->output(), $pdf->pageCount()];
	}
}
