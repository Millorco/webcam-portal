<?php
/**
 * Router simulato per l'anteprima locale: produce foto (JPEG generato con GD,
 * cielo sfumato con data e ora), JSON photo/1, lotti telemetry/1 con valori
 * realistici (giorno/notte, riscaldamento, Victron) ed eventi event/1, e li
 * invia al portal con il token della webcam, come farà luci-app-webcam 1.1.
 *
 *   php tools/simula-router.php --url http://localhost/webcam-portal --token wct_... --mac 94:83:c4:12:ab:cd
 *
 * Opzioni:
 *   --url URL         radice del portal (predefinito http://localhost/webcam-portal)
 *   --token TOKEN     token della webcam (obbligatorio)
 *   --mac MAC         MAC della webcam del token (predefinito 94:83:c4:12:ab:cd)
 *   --nome NOME       hostname del router simulato (predefinito simulata)
 *   --giorni N        riempie lo storico: N giorni di telemetria ogni 5 minuti
 *                     e una foto ogni ora, poi esce
 *   --una-volta       un solo giro (mezz'ora di telemetria, una foto, eventi) e esce
 *   --intervallo MIN  intervallo tra le foto in modalità continua (predefinito 10)
 *   --ogni SEC        secondi tra un giro e l'altro in modalità continua (predefinito 300)
 *   --larghezza PX    larghezza della foto (predefinito 1280; 640 con --giorni)
 *   --boot-id HEX     boot_id fisso di 8 cifre esadecimali (predefinito casuale)
 *
 * Senza --giorni né --una-volta gira finché non si preme Ctrl+C: un campione
 * ogni --ogni secondi, una foto ogni --intervallo minuti.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("Solo da riga di comando\n");
}
if (!function_exists('imagecreatetruecolor')) {
	fwrite(STDERR, "ERRORE: serve l'estensione GD di PHP (in XAMPP: togliere il ; davanti a extension=gd in C:\\xampp\\php\\php.ini)\n");
	exit(1);
}

$opt = getopt('', ['url:', 'token:', 'mac:', 'nome:', 'giorni:', 'una-volta', 'intervallo:', 'ogni:', 'larghezza:', 'boot-id:', 'aiuto', 'help']);
if (isset($opt['aiuto']) || isset($opt['help']) || empty($opt['token'])) {
	$doc = (string) file_get_contents(__FILE__);
	preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
	echo preg_replace('/^\s*\* ?/m', '', $m[1] ?? '') . "\n";
	exit(empty($opt['token']) && !isset($opt['aiuto']) && !isset($opt['help']) ? 1 : 0);
}

$sim = new RouterSimulato(
	rtrim((string) ($opt['url'] ?? 'http://localhost/webcam-portal'), '/'),
	(string) $opt['token'],
	strtolower((string) ($opt['mac'] ?? '94:83:c4:12:ab:cd')),
	(string) ($opt['nome'] ?? 'simulata'),
	isset($opt['boot-id']) ? strtolower((string) $opt['boot-id']) : null
);

if (isset($opt['giorni'])) {
	$sim->width = (int) ($opt['larghezza'] ?? 640);
	$sim->backfill(max(1, (int) $opt['giorni']));
} elseif (isset($opt['una-volta'])) {
	$sim->width = (int) ($opt['larghezza'] ?? 1280);
	$sim->once();
} else {
	$sim->width = (int) ($opt['larghezza'] ?? 1280);
	$sim->loop(max(5, (int) ($opt['ogni'] ?? 300)), max(1, (int) ($opt['intervallo'] ?? 10)));
}
exit($sim->errors > 0 ? 1 : 0);

final class RouterSimulato
{
	public int $width = 1280;
	public int $errors = 0;
	private string $bootId;
	private int $seq = 0;
	private int $eventSeq = 0;
	private int $bootTime;
	private DateTimeZone $tz;
	// stato fisico
	private float $soc = 0.7;
	private bool $heater = false;
	private int $yieldToday = 0;
	private int $yieldTotal = 45000;
	private string $yieldDay = '';
	private int $rx = 0;
	private int $tx = 0;
	private int $shots = 48000;
	private float $cameraHours = 3100.0;
	private int $powerCycles = 55;
	private int $captureErrors = 12;
	private ?array $last = null;

	public function __construct(
		private string $url,
		private string $token,
		private string $mac,
		private string $hostname,
		?string $bootId
	) {
		$this->bootId = ($bootId !== null && preg_match('/^[0-9a-f]{8}$/', $bootId)) ? $bootId : bin2hex(random_bytes(4));
		$this->tz = new DateTimeZone('Europe/Rome');
		$this->bootTime = time();
	}

	// ----------------------------------------------------------- modalità

	public function backfill(int $days): void
	{
		$now = time();
		$t0 = $now - $days * 86400;
		$t0 -= $t0 % 300;
		$this->bootTime = $t0 - 41;
		$this->say("Riempio $days giorni di storico (dal " . $this->local($t0, 'd/m/Y H:i') . "), boot_id {$this->bootId}");
		$this->whoami();
		$events = [$this->event($this->bootTime + 41, 'boot', 'notice', 'Avvio del router (simulato)', ['board_reset_cause' => 'power_on', 'previous_uptime' => null])];
		$samples = [];
		$photos = 0;
		for ($t = $t0; $t <= $now; $t += 300) {
			$samples[] = $this->sample($t);
			if ($t % 3600 === 0) {
				if (mt_rand(1, 48) === 1) {
					$this->powerCycles++;
					$events[] = $this->event($t - 20, 'camera.power_cycle', 'warning',
						'primo tentativo di scatto fallito, riavvio la fotocamera e riprovo (simulato)',
						['file' => $this->fileName($t), 'gphoto2' => "*** Error (-7: 'I/O problem') ***"]);
				}
				$this->photo($t, true);
				$photos++;
			}
			if (count($samples) === 288) {
				$this->sendTelemetry($samples, $t);
				$samples = [];
			}
		}
		if ($samples) {
			$this->sendTelemetry($samples, $now);
		}
		foreach (array_chunk($events, 100) as $chunk) {
			$this->sendEvents($chunk);
		}
		$this->say("Fatto: {$this->seq} campioni, $photos foto, " . count($events) . ' eventi');
	}

	public function once(): void
	{
		$now = time();
		$now -= $now % 300;
		$this->bootTime = $now - 1800 - 41;
		$this->whoami();
		$this->sendEvents([
			$this->event($this->bootTime + 41, 'boot', 'notice', 'Avvio del router (simulato)', ['board_reset_cause' => 'watchdog', 'previous_uptime' => 1296000]),
			$this->event($this->bootTime + 75, 'ntp.synced', 'debug', 'Ora sincronizzata con NTP', ['offset_s' => 3]),
		]);
		$samples = [];
		for ($t = $now - 1500; $t <= $now; $t += 300) {
			$samples[] = $this->sample($t);
		}
		$this->sendTelemetry($samples, time());
		$this->photo(time(), true);
	}

	public function loop(int $every, int $interval): void
	{
		$this->say("Modalità continua: un campione ogni $every s, una foto ogni $interval min (Ctrl+C per uscire)");
		$this->whoami();
		$this->sendEvents([$this->event(time(), 'boot', 'notice', 'Avvio del router (simulato)', ['board_reset_cause' => 'power_on'])]);
		$lastPhoto = 0;
		while (true) {
			$t = time();
			$this->sendTelemetry([$this->sample($t)], $t);
			if ($t - $lastPhoto >= $interval * 60) {
				$this->photo($t, true);
				$lastPhoto = $t;
			}
			sleep($every);
		}
	}

	// ----------------------------------------------------------- dati

	private function local(int $t, string $fmt): string
	{
		return (new DateTimeImmutable('@' . $t))->setTimezone($this->tz)->format($fmt);
	}

	private function hourOf(int $t): float
	{
		$d = (new DateTimeImmutable('@' . $t))->setTimezone($this->tz);
		return (int) $d->format('G') + (int) $d->format('i') / 60;
	}

	/**
	 * Numero pseudocasuale stabile per giorno (nuvole, temperatura del
	 * giorno), sfumato verso quello del giorno dopo per non avere salti a mezzanotte.
	 */
	private function daily(int $t, int $salt): float
	{
		$v = fn (int $tt): float => (crc32($this->local($tt, 'Ymd') . $salt) % 1000) / 1000;
		$f = $this->hourOf($t) / 24;
		return $v($t) * (1 - $f) + $v($t + 86400) * $f;
	}

	private function sunFactor(int $t): float
	{
		$h = $this->hourOf($t);
		return ($h > 7.0 && $h < 16.8) ? sin(M_PI * ($h - 7.0) / 9.8) : 0.0;
	}

	private static function satVapour(float $t): float
	{
		return 6.112 * exp(17.62 * $t / (243.12 + $t));
	}

	private static function absHumidity(float $t, float $rh): float
	{
		return round(216.7 * ($rh / 100 * self::satVapour($t)) / (273.15 + $t), 2);
	}

	private static function dewpoint(float $t, float $rh): float
	{
		$g = log(max(0.01, $rh) / 100) + 17.62 * $t / (243.12 + $t);
		return round(243.12 * $g / (17.62 - $g), 1);
	}

	private static function noise(float $amp): float
	{
		return (mt_rand(-1000, 1000) / 1000) * $amp;
	}

	private function sample(int $t): array
	{
		$h = $this->hourOf($t);
		$sun = $this->sunFactor($t);
		$cloud = 0.25 + 0.75 * $this->daily($t, 1);
		$tExt = 6.5 + 5.0 * sin(2 * M_PI * ($h - 9.0) / 24) + ($this->daily($t, 2) - 0.5) * 6 + self::noise(0.15);
		$rhExt = max(35.0, min(99.0, 92 - 4.2 * ($tExt - 5) + self::noise(1.5)));
		$ah = self::absHumidity($tExt, $rhExt);
		// dentro la custodia: stessa umidità assoluta, più caldo
		$tIn = $tExt + 2.5 + 6.0 * $sun * $cloud + ($this->heater ? 2.5 : 0.0) + self::noise(0.1);
		$rhIn = min(99.0, $ah * (273.15 + $tIn) / 216.7 / self::satVapour($tIn) * 100);
		if ($this->heater ? ($rhIn < 70 && $tIn > 5) : ($rhIn > 80 || $tIn < 3)) {
			$this->heater = !$this->heater;
		}

		// Victron SmartSolar 75/15 + batteria 12 V 50 Ah
		$day = $this->local($t, 'Ymd');
		if ($day !== $this->yieldDay) {
			$this->yieldDay = $day;
			$this->yieldToday = 0;
		}
		$panelW = (int) round(max(0, 62 * $sun * $cloud + ($sun > 0 ? self::noise(2) : 0)));
		$loadA = 0.42 + ($this->heater ? 0.55 : 0.0);
		$inWh = $panelW * 5 / 60 * 0.92;
		$outWh = 12.8 * $loadA * 5 / 60;
		$this->soc = max(0.05, min(1.0, $this->soc + ($inWh - $outWh) / 600));
		$this->yieldToday += (int) round($panelW * 5 / 60);
		$this->yieldTotal += (int) round($panelW * 5 / 60);
		$battV = 11.85 + 1.05 * $this->soc + ($panelW > 3 ? 0.55 * min(1, $panelW / 30) : 0) + self::noise(0.02);
		$battA = round($panelW * 0.92 / $battV - $loadA, 2);
		$cs = $panelW <= 1 ? 0 : ($this->soc < 0.85 ? 3 : ($this->soc < 0.98 ? 4 : 5));

		$this->rx += 2600 + mt_rand(0, 1500);
		$this->tx += 3500 + mt_rand(0, 2000);
		$id = $this->bootId . '-' . (++$this->seq);
		$tIn = round($tIn, 1);
		$rhIn = round($rhIn, 1);
		$tExt = round($tExt, 1);
		$rhExt = round($rhExt, 1);
		return $this->last = [
			'id' => $id,
			'time' => $t,
			'time_synced' => true,
			'uptime' => max(0, $t - $this->bootTime),
			'climate' => [
				'time' => $t,
				'sensor_ok' => true,
				'temperature' => $tIn,
				'humidity' => $rhIn,
				'dewpoint' => self::dewpoint($tIn, $rhIn),
				'abs_humidity' => self::absHumidity($tIn, $rhIn),
				'ext_sensor' => true,
				'ext_sensor_ok' => true,
				'temperature_ext' => $tExt,
				'humidity_ext' => $rhExt,
				'dewpoint_ext' => self::dewpoint($tExt, $rhExt),
				'abs_humidity_ext' => self::absHumidity($tExt, $rhExt),
				'heating_enabled' => true,
				'heater' => $this->heater,
				'fan' => $this->heater,
				'board_fw' => '2.4',
			],
			'power' => [
				'time' => $t,
				'source' => 'vedirect',
				'product_id' => '0xA053',
				'serial' => 'HQ2231SIMUL',
				'firmware' => '1.64',
				'battery_v' => round($battV, 2),
				'battery_a' => $battA,
				'panel_v' => $panelW > 0 ? round(17.6 + 1.4 * $sun + self::noise(0.2), 2) : round(0.4 + self::noise(0.1), 2),
				'panel_w' => $panelW,
				'load_a' => round($loadA, 2),
				'load_on' => true,
				'charge_state' => $cs,
				'mppt_state' => $panelW > 1 ? 2 : 0,
				'off_reason' => $panelW > 1 ? '0x00000000' : '0x00000001',
				'error' => 0,
				'soc_pct' => null,
				'yield_today_wh' => $this->yieldToday,
				'yield_total_wh' => $this->yieldTotal,
				'max_power_today_w' => 61,
			],
			'net' => [
				'iface' => 'wwan',
				'type' => 'lte',
				'operator' => 'I TIM',
				'rssi_dbm' => -71 + mt_rand(-4, 4),
				'rsrp_dbm' => -98 + mt_rand(-3, 3),
				'rsrq_db' => -11.0,
				'sinr_db' => round(9.5 + self::noise(2), 1),
				'rx_bytes' => $this->rx,
				'tx_bytes' => $this->tx,
				'wan_ip' => '10.172.33.18',
				'vpn_up' => true,
			],
			'sys' => [
				'load1' => round(0.15 + mt_rand(0, 30) / 100, 2),
				'mem_free_kb' => 28000 + mt_rand(-2000, 1000),
				'tmp_free_kb' => 25120,
				'disk_free_kb' => 4120,
				'queue_photos' => 0,
				'queue_kb' => 0,
				'queue_samples' => 0,
				'maintenance' => false,
			],
			'camera' => ['present' => true, 'power' => true],
		];
	}

	private function event(int $t, string $type, string $severity, string $message, ?array $data = null): array
	{
		return [
			'id' => $this->bootId . '-E' . (++$this->eventSeq),
			'time' => $t,
			'time_synced' => true,
			'uptime' => max(0, $t - $this->bootTime),
			'type' => $type,
			'severity' => $severity,
			'message' => $message,
			'data' => $data,
		];
	}

	private function fileName(int $t): string
	{
		return 'raw_' . $this->local($t, 'Ymd-Hi') . '.jpg';
	}

	// ----------------------------------------------------------- foto

	private function jpeg(int $t): string
	{
		$w = max(320, min(6000, $this->width));
		$h = (int) round($w * 9 / 16);
		$im = imagecreatetruecolor($w, $h);
		$sun = $this->sunFactor($t);
		$hour = $this->hourOf($t);
		$cloud = $this->daily($t, 1);
		$twilight = max(0.0, 1 - min(abs($hour - 7.0), abs($hour - 16.8)) / 1.2);
		$night = [[10, 18, 44], [27, 40, 74]];
		$dayTop = [(int) (58 + 90 * (1 - $cloud)), (int) (118 + 50 * (1 - $cloud)), (int) (200 - 10 * (1 - $cloud))];
		$dayBottom = [190, 216, 240];
		$k = min(1.0, $sun * 2.5);
		$mix = static fn (array $a, array $b, float $f): array => [
			(int) ($a[0] + ($b[0] - $a[0]) * $f), (int) ($a[1] + ($b[1] - $a[1]) * $f), (int) ($a[2] + ($b[2] - $a[2]) * $f),
		];
		$top = $mix($night[0], $dayTop, $k);
		$bottom = $mix($night[1], $dayBottom, $k);
		$bottom = $mix($bottom, [240, 150, 80], $twilight * 0.7);
		$horizon = (int) ($h * 0.68);
		for ($y = 0; $y < $h; $y++) {
			$c = $mix($top, $bottom, min(1.0, $y / $horizon));
			imageline($im, 0, $y, $w, $y, imagecolorallocate($im, $c[0], $c[1], $c[2]));
		}
		// sole o luna
		if ($sun > 0) {
			$sx = (int) ($w * ($hour - 7.0) / 9.8);
			$sy = (int) ($horizon - $sun * $horizon * 0.8);
			imagefilledellipse($im, $sx, $sy, (int) ($w * 0.05), (int) ($w * 0.05), imagecolorallocate($im, 255, 236, 160));
		} else {
			imagefilledellipse($im, (int) ($w * 0.8), (int) ($h * 0.18), (int) ($w * 0.03), (int) ($w * 0.03), imagecolorallocate($im, 220, 224, 235));
		}
		// colline
		$g = $mix([12, 22, 16], [58, 98, 52], $k);
		$pts = [0, $h];
		for ($x = 0; $x <= $w; $x += (int) max(8, $w / 40)) {
			$pts[] = $x;
			$pts[] = (int) ($horizon - $h * 0.06 * sin($x / $w * 5.1) - $h * 0.04 * sin($x / $w * 13.7 + 1));
		}
		$pts[] = $w;
		$pts[] = $h;
		imagefilledpolygon($im, $pts, imagecolorallocate($im, $g[0], $g[1], $g[2]));
		// testo: font interno di GD ingrandito
		$text = $this->hostname . ' - router simulato   ' . $this->local($t, 'd/m/Y H:i');
		$small = imagecreatetruecolor(imagefontwidth(5) * strlen($text) + 8, imagefontheight(5) + 6);
		imagefill($small, 0, 0, imagecolorallocate($small, 0, 0, 0));
		imagestring($small, 5, 4, 3, $text, imagecolorallocate($small, 255, 255, 255));
		$scale = max(1, (int) floor($w / 640));
		imagecopyresized($im, $small, 16, $h - imagesy($small) * $scale - 16, 0, 0, imagesx($small) * $scale, imagesy($small) * $scale, imagesx($small), imagesy($small));
		imagedestroy($small);
		ob_start();
		imagejpeg($im, null, 85);
		imagedestroy($im);
		return (string) ob_get_clean();
	}

	private function photo(int $t, bool $current): void
	{
		$jpeg = $this->jpeg($t);
		$file = $this->fileName($t);
		// climate e power dell'ultimo campione (come capture con status.json)
		$sample = ($this->last !== null && abs($t - $this->last['time']) <= 900) ? $this->last : $this->sample($t);
		$this->shots++;
		$this->tx += strlen($jpeg);
		$day = (new DateTimeImmutable('@' . $t))->setTimezone($this->tz)->setTime(0, 0)->getTimestamp();
		$meta = [
			'schema' => 'photo/1',
			'time' => $t,
			'datetime' => $this->local($t, 'Y-m-d\TH:i:sO'),
			'time_synced' => true,
			'mac' => $this->mac,
			'hostname' => $this->hostname,
			'camera' => $this->hostname,
			'file' => $file,
			'sha256' => hash('sha256', $jpeg),
			'size' => strlen($jpeg),
			'mode' => $this->sunFactor($t) > 0 ? 'day' : 'night',
			'special' => null,
			'interval' => 10,
			'settings' => $this->sunFactor($t) > 0
				? ['iso' => 'Auto', 'aperture' => '8', 'shutterspeed' => '1/500']
				: ['iso' => '1600', 'aperture' => '4', 'shutterspeed' => '15'],
			'day_start' => $day + 7 * 3600,
			'day_end' => $day + (int) (16.8 * 3600),
			'lat' => 41.7531,
			'lon' => 12.7098,
			'app_version' => '1.1.0',
			'board_fw' => '2.4',
			'capture' => ['attempts' => 1, 'camera_power_cycled' => false, 'duration_s' => 9],
			'camera_info' => [
				'model' => 'Canon EOS 2000D', 'serial' => '123456789012', 'firmware' => '1.0.1',
				'lens' => 'EF-S18-55mm f/3.5-5.6 III', 'shutter_count' => null, 'battery_level' => '100%',
				'read_at' => $t - ($t % 3600),
			],
			'climate' => $sample['climate'],
			'power' => $sample['power'],
		];
		$boundary = '----simula' . bin2hex(random_bytes(8));
		$body = "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"$file\"\r\nContent-Type: image/jpeg\r\n\r\n$jpeg\r\n"
			. "--$boundary\r\nContent-Disposition: form-data; name=\"meta\"; filename=\"" . substr($file, 0, -4) . ".json\"\r\nContent-Type: application/json\r\n\r\n"
			. json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\r\n"
			. "--$boundary\r\nContent-Disposition: form-data; name=\"current\"\r\n\r\n" . ($current ? '1' : '0') . "\r\n"
			. "--$boundary--\r\n";
		[$st, $res] = $this->http('POST', '/api/v1/photo', "multipart/form-data; boundary=$boundary", $body);
		$this->report($st, $res, "foto $file (" . round(strlen($jpeg) / 1024) . ' KB)', in_array($st, [200, 201], true)
			? ($res['duplicate'] ?? false ? 'già ricevuta' : 'id ' . ($res['photo_id'] ?? '?')) : '');
	}

	// ----------------------------------------------------------- invio

	private function sendTelemetry(array $samples, int $sentAt): void
	{
		$batch = [
			'schema' => 'telemetry/1',
			'mac' => $this->mac,
			'hostname' => $this->hostname,
			'app_version' => '1.1.0',
			'board_fw' => '2.4',
			'boot_id' => $this->bootId,
			'sent_at' => min(time(), $sentAt),
			'time_synced' => true,
			'camera' => [
				'model' => 'Canon EOS 2000D', 'serial' => '123456789012', 'shutter_count' => null,
				'shots_total' => $this->shots, 'power_on_hours' => round($this->cameraHours += count($samples) * 5 / 60, 1),
				'power_cycles' => $this->powerCycles, 'capture_errors' => $this->captureErrors,
				'battery_level' => '100%', 'read_at' => $sentAt - ($sentAt % 3600),
			],
			'samples' => $samples,
		];
		$json = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		$gz = function_exists('gzencode') && strlen($json) > 4096;
		[$st, $res] = $this->http('POST', '/api/v1/telemetry', 'application/json', $gz ? gzencode($json) : $json, $gz ? ['Content-Encoding: gzip'] : []);
		$this->report($st, $res, count($samples) . ' campioni', $st === 200
			? sprintf('accettati %d, duplicati %d, rifiutati %d', $res['accepted'] ?? 0, $res['duplicates'] ?? 0, count($res['rejected'] ?? []))
			: '');
	}

	private function sendEvents(array $events): void
	{
		$batch = [
			'schema' => 'event/1', 'mac' => $this->mac, 'hostname' => $this->hostname, 'app_version' => '1.1.0',
			'sent_at' => time(), 'time_synced' => true, 'events' => $events,
		];
		[$st, $res] = $this->http('POST', '/api/v1/event', 'application/json', (string) json_encode($batch, JSON_UNESCAPED_SLASHES));
		$this->report($st, $res, count($events) . ' eventi', $st === 200
			? sprintf('accettati %d, duplicati %d', $res['accepted'] ?? 0, $res['duplicates'] ?? 0) : '');
	}

	private function whoami(): void
	{
		[$st, $res] = $this->http('GET', '/api/v1/whoami', null, null);
		if ($st !== 200) {
			$this->report($st, $res, 'prova connessione', '');
			fwrite(STDERR, "Controlla --url, --token e --mac.\n");
			exit(1);
		}
		$w = $res['webcam'] ?? [];
		$this->say("Connesso come webcam {$w['id']} \"{$w['name']}\" ({$w['slug']}, {$w['mac']})");
		if (($w['mac'] ?? '') !== $this->mac) {
			fwrite(STDERR, "ATTENZIONE: il MAC della webcam del token è {$w['mac']}: uso quello\n");
			$this->mac = (string) $w['mac'];
		}
	}

	/** @return array{0:int, 1:array} */
	private function http(string $method, string $path, ?string $contentType, ?string $body, array $extra = []): array
	{
		$headers = array_merge([
			'Authorization: Bearer ' . $this->token,
			'User-Agent: luci-app-webcam/1.1.0 (simulatore)',
			'Accept: application/json',
		], $extra);
		if ($contentType !== null) {
			$headers[] = 'Content-Type: ' . $contentType;
		}
		$ctx = stream_context_create(['http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'content' => $body ?? '',
			'timeout' => 120,
			'ignore_errors' => true,
		]]);
		$http_response_header = [];
		$raw = @file_get_contents($this->url . $path, false, $ctx);
		$status = 0;
		foreach ($http_response_header as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
			}
		}
		if ($raw === false && $status === 0) {
			return [0, ['message' => error_get_last()['message'] ?? 'errore di rete']];
		}
		$data = json_decode((string) $raw, true);
		return [$status, is_array($data) ? $data : ['message' => substr((string) $raw, 0, 200)]];
	}

	private function report(int $status, array $res, string $what, string $ok): void
	{
		if ($status >= 200 && $status < 300) {
			$this->say("$what: $status $ok");
			return;
		}
		$this->errors++;
		$this->say("$what: ERRORE $status " . ($res['error'] ?? '') . ' ' . ($res['message'] ?? ''));
	}

	private function say(string $msg): void
	{
		echo date('H:i:s') . ' ' . $msg . PHP_EOL;
	}
}
