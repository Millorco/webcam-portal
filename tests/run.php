<?php
/**
 * Test di webcam-portal, senza PHPUnit: usano un MariaDB vero e il server
 * integrato di PHP (php -S), come in produzione.
 *
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_NAME=webcam2_test \
 *   TEST_DB_USER=webcam2 TEST_DB_PASS=... php tests/run.php
 *
 * Su Windows (XAMPP):
 *   set TEST_DB_PASS=...
 *   C:\xampp\php\php.exe tests\run.php
 *
 * ATTENZIONE: cancella e ricrea tutte le tabelle del database di prova, che
 * deve chiamarsi *_test.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Service\HourlyAggregator;
use WebcamPortal\Service\Migrator;
use WebcamPortal\Service\Partitions;
use WebcamPortal\Service\PhotoRetention;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;

if (PHP_SAPI !== 'cli') {
	exit;
}

$db = [
	'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
	'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
	'name' => getenv('TEST_DB_NAME') ?: 'webcam2_test',
	'user' => getenv('TEST_DB_USER') ?: 'webcam2',
	'pass' => getenv('TEST_DB_PASS') ?: '',
];
if (!str_ends_with($db['name'], '_test')) {
	fwrite(STDERR, "Il database di prova deve finire con _test (è {$db['name']})\n");
	exit(2);
}

// ------------------------------------------------------------ ambiente
$tmp = sys_get_temp_dir() . '/webcam-portal-test-' . getmypid();
@mkdir($tmp . '/storage', 0775, true);
$cfgValues = [
	'app_env' => 'dev',
	'db' => $db,
	'storage_dir' => $tmp . '/storage',
	'photo_max_bytes' => 300000,
	'rate_limit_per_min' => 1000,
	'public_base_url' => '',
	'dolibarr' => ['url' => '', 'api_key' => ''],
];
$writeCfg = static function (string $file, array $values): void {
	file_put_contents($file, '<?php return ' . var_export($values, true) . ';');
};
$writeCfg("$tmp/config.php", $cfgValues);
$writeCfg("$tmp/config-prod.php", ['app_env' => 'prod'] + $cfgValues);
putenv("WEBCAM_CONFIG=$tmp/config.php");
Config::set(Config::load());

$pdo = Db::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
	$pdo->exec("DROP TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
(new Migrator($pdo, WEBCAM_PORTAL_ROOT . '/migrations'))->migrate();
Partitions::ensure(2);

$procs = [];
$startServer = static function (string $router, array $env, string $docroot) use (&$procs, $tmp): string {
	$sock = stream_socket_server('tcp://127.0.0.1:0');
	$port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
	fclose($sock);
	$cmd = [PHP_BINARY, '-d', 'upload_max_filesize=2M', '-d', 'post_max_size=3M', '-S', "127.0.0.1:$port", '-t', $docroot, $router];
	$p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server-$port.log", 'a'], 2 => ['file', "$tmp/server-$port.log", 'a']], $pipes, null, $env + getenv());
	$procs[] = $p;
	for ($i = 0; $i < 50; $i++) {
		$c = @fsockopen('127.0.0.1', $port, $en, $es, 0.1);
		if ($c) {
			fclose($c);
			return "http://127.0.0.1:$port";
		}
		usleep(100000);
	}
	throw new RuntimeException("server $router non partito");
};
register_shutdown_function(static function () use (&$procs, $tmp): void {
	foreach ($procs as $p) {
		proc_terminate($p);
	}
	if (!getenv('TEST_KEEP')) {
		exec((DIRECTORY_SEPARATOR === '\\' ? 'rmdir /s /q ' : 'rm -rf ') . escapeshellarg($tmp));
	}
});

$public = WEBCAM_PORTAL_ROOT . '/public';
$base = $startServer("$public/index.php", ['WEBCAM_CONFIG' => "$tmp/config.php"], $public);
$baseProd = $startServer("$public/index.php", ['WEBCAM_CONFIG' => "$tmp/config-prod.php"], $public);

// ------------------------------------------------------------ strumenti
$passed = 0;
$failed = 0;
function ok(bool $cond, string $name, string $detail = ''): void
{
	global $passed, $failed;
	if ($cond) {
		$passed++;
		echo "  ok    $name\n";
	} else {
		$failed++;
		echo "  FALLITO $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
	}
}
function section(string $s): void
{
	echo "\n$s\n";
}

/** @return array{status:int, json:?array, body:string, headers:array<string,string>} */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
	$ctx = stream_context_create(['http' => [
		'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
		'ignore_errors' => true, 'timeout' => 30,
	]]);
	$http_response_header = [];
	$raw = @file_get_contents($url, false, $ctx);
	$status = 0;
	$h = [];
	foreach ($http_response_header as $line) {
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
			$status = (int) $m[1];
		} elseif (str_contains($line, ':')) {
			[$k, $v] = explode(':', $line, 2);
			$h[strtolower(trim($k))] = trim($v);
		}
	}
	$raw = (string) $raw;
	$json = json_decode($raw, true);
	return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $raw, 'headers' => $h];
}

function multipart(array $parts, string &$ct): string
{
	$b = '----test' . bin2hex(random_bytes(6));
	$ct = "multipart/form-data; boundary=$b";
	$out = '';
	foreach ($parts as $p) {
		$out .= "--$b\r\nContent-Disposition: form-data; name=\"{$p['name']}\"" . (isset($p['filename']) ? "; filename=\"{$p['filename']}\"" : '') . "\r\n";
		if (isset($p['type'])) {
			$out .= "Content-Type: {$p['type']}\r\n";
		}
		$out .= "\r\n{$p['data']}\r\n";
	}
	return $out . "--$b--\r\n";
}

function jpeg(int $w = 160, int $h = 90, int $seed = 0): string
{
	$im = imagecreatetruecolor($w, $h);
	imagefill($im, 0, 0, imagecolorallocate($im, ($seed * 37) % 256, 120, 200));
	imagestring($im, 2, 4, 4, 'test ' . $seed, imagecolorallocate($im, 255, 255, 255));
	ob_start();
	imagejpeg($im, null, 80);
	return (string) ob_get_clean();
}

function photoMeta(string $jpeg, int $time, string $mac, array $over = []): array
{
	return array_merge([
		'schema' => 'photo/1', 'time' => $time, 'datetime' => gmdate('Y-m-d\TH:i:s', $time + 3600) . '+0100',
		'time_synced' => true, 'mac' => $mac, 'hostname' => 'prova', 'camera' => 'prova',
		'file' => 'raw_' . gmdate('Ymd-Hi', $time + 3600) . '.jpg', 'sha256' => hash('sha256', $jpeg),
		'size' => strlen($jpeg), 'mode' => 'day', 'settings' => ['iso' => 'Auto', 'aperture' => '8', 'shutterspeed' => '1/500'],
		'climate' => ['temperature' => 14.2, 'humidity' => 61.5, 'temperature_ext' => 9.7, 'humidity_ext' => 83.0],
		'power' => ['battery_v' => 13.21],
		'camera_info' => ['model' => 'Canon EOS 2000D', 'serial' => '123456789012'],
	], $over);
}

function sendPhoto(string $base, string $token, string $jpeg, $meta, string $current = '1', array $partOver = []): array
{
	$parts = [
		['name' => 'photo', 'filename' => 'x.jpg', 'type' => $partOver['type'] ?? 'image/jpeg', 'data' => $jpeg],
		['name' => 'meta', 'filename' => 'x.json', 'type' => 'application/json', 'data' => is_string($meta) ? $meta : json_encode($meta)],
		['name' => 'current', 'data' => $current],
	];
	if (!empty($partOver['no_meta'])) {
		unset($parts[1]);
	}
	if (!empty($partOver['no_photo'])) {
		unset($parts[0]);
	}
	$ct = '';
	$body = multipart(array_values($parts), $ct);
	return http('POST', "$base/api/v1/photo", ["Authorization: Bearer $token", "Content-Type: $ct"], $body);
}

function postJson(string $base, string $path, string $token, array $data, bool $gzip = false): array
{
	$body = (string) json_encode($data);
	$h = ["Authorization: Bearer $token", 'Content-Type: application/json'];
	if ($gzip) {
		$body = (string) gzencode($body);
		$h[] = 'Content-Encoding: gzip';
	}
	return http('POST', $base . $path, $h, $body);
}

function sample(string $id, int $t, array $over = []): array
{
	return array_merge([
		'id' => $id, 'time' => $t, 'time_synced' => true, 'uptime' => 1000,
		'climate' => ['temperature' => 14.5, 'humidity' => 61.0, 'temperature_ext' => 9.9, 'humidity_ext' => 82.0, 'heater' => false, 'board_fw' => '2.4'],
		'power' => ['battery_v' => 13.3, 'panel_w' => 28, 'charge_state' => 3, 'error' => 0, 'yield_today_wh' => 80, 'max_power_today_w' => 61],
		'net' => ['type' => 'lte', 'rssi_dbm' => -71, 'rx_bytes' => 1000, 'tx_bytes' => 2000, 'vpn_up' => true],
		'sys' => ['load1' => 0.2, 'queue_photos' => 0, 'maintenance' => false],
		'camera' => ['present' => true, 'power' => true],
	], $over);
}

// ------------------------------------------------------------ dati di base
$mac = '94:83:c4:12:ab:cd';
$wid = Webcams::create($mac, 'Monte Cavo', 'monte-cavo', ['is_public' => true]);
$token = Tokens::create($wid);
$wid2 = Webcams::create('94:83:c4:00:00:02', 'Seconda', 'seconda');
$token2 = Tokens::create($wid2);
$widRet = Webcams::create('94:83:c4:00:00:09', 'Ritirata', 'ritirata', ['status' => 9]);
$tokenRet = Tokens::create($widRet);
$tokenOld = Tokens::create($wid2);
Tokens::create($wid2, null, true); // revoca token2 e tokenOld
$token2 = Tokens::create($wid2);
$auth = static fn (string $t): array => ["Authorization: Bearer $t"];
$now = time();

// ------------------------------------------------------------ autenticazione
section('Autenticazione');
$r = http('GET', "$base/api/v1/whoami");
ok($r['status'] === 401 && ($r['json']['error'] ?? '') === 'token_missing', 'senza token: 401', $r['body']);
ok(isset($r['json']['server_time']) && str_starts_with($r['headers']['content-type'] ?? '', 'application/json'), 'risposta JSON con server_time');
$r = http('GET', "$base/api/v1/whoami", $auth('wct_' . str_repeat('A', 43)));
ok($r['status'] === 401 && $r['json']['error'] === 'token_unknown', 'token sconosciuto: 401');
$r = http('GET', "$base/api/v1/whoami", $auth('abc'));
ok($r['status'] === 401, 'token malformato: 401');
$r = http('GET', "$base/api/v1/whoami", $auth($tokenOld));
ok($r['status'] === 403 && $r['json']['error'] === 'token_revoked', 'token revocato: 403', $r['body']);
$r = http('GET', "$base/api/v1/whoami", $auth($tokenRet));
ok($r['status'] === 403 && $r['json']['error'] === 'webcam_retired', 'webcam ritirata: 403');
Db::exec('UPDATE tokens SET expires_at = ? WHERE token_hash = ?', [Db::dt($now - 10), Tokens::hash($token2)]);
$r = http('GET', "$base/api/v1/whoami", $auth($token2));
ok($r['status'] === 403 && $r['json']['error'] === 'token_expired', 'token scaduto: 403');
Db::exec('UPDATE tokens SET expires_at = NULL WHERE token_hash = ?', [Tokens::hash($token2)]);
$r = http('GET', "$base/api/v1/whoami", $auth($token));
ok($r['status'] === 200 && $r['json']['webcam']['slug'] === 'monte-cavo' && $r['json']['webcam']['mac'] === $mac, 'whoami: 200 con la webcam del token', $r['body']);
ok(Db::scalar('SELECT last_used_at FROM tokens WHERE token_hash = ?', [Tokens::hash($token)]) !== null, 'last_used_at aggiornato');
ok(strlen($token) === 47 && (bool) preg_match(Tokens::PATTERN, $token), 'formato del token: wct_ + 43 caratteri');
ok(Db::scalar('SELECT COUNT(*) FROM tokens WHERE token_hash = ?', [$token]) == 0, 'nel database non c\'è il token in chiaro');
$r = http('GET', "$baseProd/api/v1/whoami", $auth($token));
ok($r['status'] === 403 && $r['json']['error'] === 'https_required', 'APP_ENV=prod: HTTP rifiutato con 403', $r['body']);
$r = http('GET', "$base/api/v1/nulla", $auth($token));
ok($r['status'] === 404, 'endpoint inesistente: 404');
$r = http('GET', "$base/api/v1/photo", $auth($token));
ok($r['status'] === 405, 'metodo sbagliato: 405');

// ------------------------------------------------------------ foto
section('Foto');
$j1 = jpeg(160, 90, 1);
$t1 = $now - 600;
$m1 = photoMeta($j1, $t1, $mac);
$r = sendPhoto($base, $token, $j1, $m1);
ok($r['status'] === 201 && $r['json']['duplicate'] === false && $r['json']['current'] === true, 'prima foto: 201, current', $r['body']);
$pid1 = (int) ($r['json']['photo_id'] ?? 0);
$row = Db::one('SELECT * FROM photos WHERE id = ?', [$pid1]);
ok($row !== null && $row['sha256'] === $m1['sha256'] && (int) $row['size_bytes'] === strlen($j1), 'record in photos con sha256 e dimensione');
ok($row !== null && (int) $row['width'] === 160 && (int) $row['height'] === 90 && (int) $row['tz_offset_min'] === 60, 'larghezza, altezza, tz_offset_min');
ok($row !== null && (float) $row['t_ext'] === 9.7 && (float) $row['battery_v'] === 13.21 && $row['camera_serial'] === '123456789012', 'clima, batteria e seriale dal JSON');
$day = (new DateTimeImmutable('@' . $t1))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y/m/d');
ok($row !== null && $row['storage_path'] === "photos/$wid/$day/{$m1['file']}" && is_file("$tmp/storage/" . $row['storage_path']), 'archivio storage/photos/<id>/AAAA/MM/GG/<file>', (string) ($row['storage_path'] ?? ''));
$r = sendPhoto($base, $token, $j1, $m1);
ok($r['status'] === 200 && $r['json']['duplicate'] === true && (int) $r['json']['photo_id'] === $pid1, 'stessa foto: 200 duplicate con lo stesso photo_id', $r['body']);
ok((int) Db::scalar('SELECT COUNT(*) FROM photos WHERE webcam_id = ?', [$wid]) === 1, 'nessun doppione in photos');

$r = sendPhoto($base, $token, $j1, photoMeta($j1, $t1, $mac, ['sha256' => str_repeat('0', 64)]));
ok($r['status'] === 422 && $r['json']['error'] === 'sha256_mismatch', 'SHA-256 diverso: 422 sha256_mismatch');
$r = sendPhoto($base, $token, $j1, photoMeta($j1, $t1, $mac, ['size' => 5]));
ok($r['status'] === 422 && $r['json']['error'] === 'size_mismatch', 'dimensione diversa: 422');
$r = sendPhoto($base, $token, $j1, photoMeta($j1, $t1, '94:83:c4:99:99:99'));
ok($r['status'] === 409 && $r['json']['error'] === 'mac_mismatch', 'mac di un\'altra webcam: 409');
$m = photoMeta($j1, $t1, $mac);
unset($m['sha256']);
$r = sendPhoto($base, $token, $j1, $m);
ok($r['status'] === 400 && $r['json']['error'] === 'missing_field' && $r['json']['field'] === 'sha256', 'campo obbligatorio mancante: 400', $r['body']);
$r = sendPhoto($base, $token, $j1, photoMeta($j1, $t1, $mac, ['mode' => 'sera']));
ok($r['status'] === 422 && $r['json']['error'] === 'invalid_field', 'valore non valido: 422');
$r = sendPhoto($base, $token, $j1, photoMeta($j1, $t1, $mac, ['schema' => 'photo/9']));
ok($r['status'] === 422 && $r['json']['error'] === 'unsupported_schema', 'schema non supportato: 422');
$r = sendPhoto($base, $token, $j1, '{rotto');
ok($r['status'] === 400 && $r['json']['error'] === 'invalid_json', 'JSON rotto: 400');
$r = sendPhoto($base, $token, $j1, $m1, '1', ['no_meta' => true]);
ok($r['status'] === 400 && $r['json']['error'] === 'missing_part', 'parte meta mancante: 400');
$r = sendPhoto($base, $token, $j1, $m1, '1', ['no_photo' => true]);
ok($r['status'] === 400 && $r['json']['error'] === 'missing_part', 'parte photo mancante: 400');
$notJpeg = str_repeat('PNG?', 100);
$r = sendPhoto($base, $token, $notJpeg, photoMeta($notJpeg, $t1, $mac));
ok($r['status'] === 415 && $r['json']['error'] === 'not_jpeg', 'non JPEG (FF D8 FF): 415');
$r = sendPhoto($base, $token, $j1, $m1, '1', ['type' => 'image/png']);
ok($r['status'] === 415, 'parte photo con tipo sbagliato: 415');
$r = http('POST', "$base/api/v1/photo", array_merge($auth($token), ['Content-Type: application/json']), '{}');
ok($r['status'] === 415, 'non multipart: 415');
$big = "\xFF\xD8\xFF" . random_bytes(400000);
$r = sendPhoto($base, $token, $big, photoMeta($big, $t1, $mac));
ok($r['status'] === 413, 'JPEG oltre PHOTO_MAX_BYTES: 413', (string) $r['status']);
$j2 = jpeg(160, 90, 2);
$r = sendPhoto($base, $token, $j2, photoMeta($j2, $now - 401 * 86400, $mac));
ok($r['status'] === 422 && $r['json']['error'] === 'time_out_of_window', 'ora oltre 400 giorni fa: 422');
$r = sendPhoto($base, $token, $j2, photoMeta($j2, $now + 3600, $mac));
ok($r['status'] === 422 && $r['json']['error'] === 'time_out_of_window', 'ora nel futuro: 422');

section('current.jpg');
$r = http('GET', "$base/cam/monte-cavo/current.jpg");
ok($r['status'] === 200 && $r['body'] === $j1 && $r['headers']['content-type'] === 'image/jpeg', 'current.jpg: 200 image/jpeg con la foto');
ok(($r['headers']['etag'] ?? '') === '"' . $m1['sha256'] . '"', 'ETag = sha256');
ok(($r['headers']['cache-control'] ?? '') === 'public, max-age=60', 'Cache-Control: public, max-age=60');
ok(($r['headers']['last-modified'] ?? '') === gmdate('D, d M Y H:i:s', $t1) . ' GMT', 'Last-Modified = ora dello scatto');
$r = http('GET', "$base/cam/monte-cavo/current.jpg", ['If-None-Match: "' . $m1['sha256'] . '"']);
ok($r['status'] === 304 && $r['body'] === '', 'If-None-Match uguale: 304');
$r = http('GET', "$base/cam/monte-cavo/current.jpg", ['If-Modified-Since: ' . gmdate('D, d M Y H:i:s', $t1) . ' GMT']);
ok($r['status'] === 304, 'If-Modified-Since: 304');

// foto più vecchia dalla coda: non sostituisce current.jpg
$j3 = jpeg(160, 90, 3);
$r = sendPhoto($base, $token, $j3, photoMeta($j3, $t1 - 3600, $mac));
ok($r['status'] === 201 && $r['json']['current'] === false, 'foto più vecchia: 201 ma current false', $r['body']);
ok((int) Db::scalar('SELECT from_queue FROM photos WHERE id = ?', [$r['json']['photo_id']]) === 1, 'arrivata in ritardo: from_queue = 1');
$r = http('GET', "$base/cam/monte-cavo/current.jpg");
ok($r['body'] === $j1, 'current.jpg resta la più recente');
// foto più recente con current=0: non tocca current.jpg
$j4 = jpeg(160, 90, 4);
$r = sendPhoto($base, $token, $j4, photoMeta($j4, $t1 + 60, $mac), '0');
ok($r['status'] === 201 && $r['json']['current'] === false && http('GET', "$base/cam/monte-cavo/current.jpg")['body'] === $j1, 'current=0: current.jpg invariato');
// foto più recente: sostituisce
$j5 = jpeg(160, 90, 5);
$m5 = photoMeta($j5, $t1 + 120, $mac);
$r = sendPhoto($base, $token, $j5, $m5);
ok($r['status'] === 201 && $r['json']['current'] === true && http('GET', "$base/cam/monte-cavo/current.jpg")['body'] === $j5, 'foto più recente: nuovo current.jpg');
$r = http('GET', "$base/cam/monte-cavo/current.json");
ok($r['status'] === 200 && $r['json']['sha256'] === $m5['sha256'] && $r['json']['time'] === $t1 + 120 && $r['json']['temperature_ext'] === 9.7, 'current.json con i dati pubblici', $r['body']);
ok(!isset($r['json']['mac']) && str_ends_with((string) ($r['json']['url'] ?? ''), '/cam/monte-cavo/current.jpg'), 'current.json senza MAC, con url');
foreach (['/cam/seconda/current.jpg', '/cam/nessuna/current.jpg', '/cam/seconda/current.json', '/cam/seconda/'] as $u) {
	ok(http('GET', $base . $u)['status'] === 404, "webcam non pubblica o inesistente: 404 ($u)");
}
$r = http('GET', "$base/cam/monte-cavo/");
ok($r['status'] === 200 && str_contains($r['body'], 'Monte Cavo') && str_contains($r['body'], 'current.jpg'), 'pagina pubblica /cam/{slug}/ (con la barra finale)');
ok(http('GET', "$base/cam/monte-cavo")['status'] === 200, 'pagina pubblica /cam/{slug} (senza barra)');

// photo/0 (luci-app-webcam 1.0-rc8)
$j6 = jpeg(160, 90, 6);
$m6 = ['time' => $now - 300, 'datetime' => gmdate('Y-m-d\TH:i:s', $now - 300) . '+0000', 'camera' => 'monte-cavo', 'file' => 'raw_old.jpg',
	'mode' => 'day', 'special' => null, 'settings' => ['iso' => 'Auto'], 'app_version' => '1.0-rc8', 'climate' => null];
$r = sendPhoto($base, $token, $j6, $m6);
$row = $r['json'] ? Db::one('SELECT schema_ver, sha256, time_synced FROM photos WHERE id = ?', [$r['json']['photo_id'] ?? 0]) : null;
ok($r['status'] === 201 && $row !== null && (int) $row['schema_ver'] === 0 && $row['sha256'] === hash('sha256', $j6) && (int) $row['time_synced'] === 0, 'photo/0 accettata: schema_ver 0, sha256 calcolato dal portal', $r['body']);

// ------------------------------------------------------------ telemetria
section('Telemetria');
$boot = '3f2a9c1e';
$t0 = $now - ($now % 300) - 1800;
$batch = [
	'schema' => 'telemetry/1', 'mac' => $mac, 'hostname' => 'monte-cavo', 'app_version' => '1.1.0', 'board_fw' => '2.4',
	'boot_id' => $boot, 'sent_at' => $now, 'time_synced' => true,
	'camera' => ['model' => 'Canon EOS 2000D', 'serial' => '123456789012', 'shutter_count' => null, 'shots_total' => 48213,
		'power_on_hours' => 3120.5, 'power_cycles' => 57, 'capture_errors' => 12, 'battery_level' => '100%'],
	'samples' => [],
];
for ($i = 0; $i < 5; $i++) {
	$batch['samples'][] = sample("$boot-" . (1501 + $i), $t0 + $i * 300, ['net' => ['type' => 'lte', 'rx_bytes' => 1000 + $i * 500, 'tx_bytes' => 2000 + $i * 700]]);
}
$batch['samples'][] = sample("$boot-1506", $now - 401 * 86400);
$r = postJson($base, '/api/v1/telemetry', $token, $batch);
ok($r['status'] === 200 && $r['json']['accepted'] === 5 && $r['json']['duplicates'] === 0, 'lotto: 5 accettati', $r['body']);
ok(($r['json']['rejected'][0]['id'] ?? '') === "$boot-1506" && $r['json']['rejected'][0]['error'] === 'time_out_of_window', 'campione fuori finestra in rejected');
$m = Db::one('SELECT * FROM measurements WHERE webcam_id = ? AND sample_id = ?', [$wid, "$boot-1502"]);
ok($m !== null && (float) $m['t_in'] === 14.5 && (float) $m['rh_ext'] === 82.0 && (int) $m['panel_w'] === 28 && $m['net_type'] === 'lte', 'colonne di measurements');
ok($m !== null && (int) $m['rx_delta'] === 500 && (int) $m['tx_delta'] === 700, 'rx_delta e tx_delta dal campione precedente');
$extra = json_decode((string) ($m['extra'] ?? ''), true);
ok(($extra['power']['max_power_today_w'] ?? null) === 61 && ($extra['sys']['maintenance'] ?? null) === false && ($extra['climate']['board_fw'] ?? null) === '2.4', 'campi non mappati in extra', (string) ($m['extra'] ?? ''));
$r = postJson($base, '/api/v1/telemetry', $token, $batch, true);
ok($r['status'] === 200 && $r['json']['accepted'] === 0 && $r['json']['duplicates'] === 5, 'stesso lotto (gzip): 5 duplicati', $r['body']);
ok((int) Db::scalar('SELECT COUNT(*) FROM measurements WHERE webcam_id = ?', [$wid]) === 5, 'nessun doppione in measurements');
$h = Db::one('SELECT SUM(samples) s, MAX(photos) p FROM measurements_hourly WHERE webcam_id = ?', [$wid]);
ok((int) $h['s'] === 5, 'medie orarie calcolate alla ricezione', json_encode($h));
$cc = Db::all('SELECT * FROM camera_counters WHERE webcam_id = ?', [$wid]);
ok(count($cc) === 1 && (int) $cc[0]['shots_total'] === 48213 && $cc[0]['camera_id'] !== null, 'contatori della reflex in camera_counters');
$batch['sent_at'] = $now + 1;
postJson($base, '/api/v1/telemetry', $token, $batch);
ok((int) Db::scalar('SELECT COUNT(*) FROM camera_counters WHERE webcam_id = ?', [$wid]) === 1, 'contatori invariati: nessuna riga nuova');
$b2 = $batch;
$b2['sent_at'] = $now + 2;
$b2['camera']['shots_total'] = 48214;
$b2['samples'] = [sample("$boot-1507", $now - 60)];
postJson($base, '/api/v1/telemetry', $token, $b2);
ok((int) Db::scalar('SELECT COUNT(*) FROM camera_counters WHERE webcam_id = ?', [$wid]) === 2, 'contatore cambiato: riga nuova');
ok(Db::scalar('SELECT last_telemetry_at FROM webcams WHERE id = ?', [$wid]) === Db::dt($now - 60), 'webcams.last_telemetry_at aggiornato');

// validazione per campione
$b3 = $batch;
$b3['samples'] = [
	sample("$boot-1601", $now - 30, ['climate' => ['temperature' => 'caldo']]),
	sample("$boot-1602", $now - 20, ['power' => ['battery_v' => 500]]),
	['time' => $now, 'time_synced' => true],
	sample("$boot-1604", $now - 10),
];
$r = postJson($base, '/api/v1/telemetry', $token, $b3);
$errs = array_column($r['json']['rejected'] ?? [], 'error', 'field');
ok($r['status'] === 200 && $r['json']['accepted'] === 1 && count($r['json']['rejected']) === 3, 'campioni non validi rifiutati uno per uno', $r['body']);
ok(($errs['climate.temperature'] ?? '') === 'invalid_field' && ($errs['power.battery_v'] ?? '') === 'invalid_field' && ($errs['id'] ?? '') === 'missing_field', 'errore e campo per ogni campione rifiutato');

// lotto illeggibile
$r = http('POST', "$base/api/v1/telemetry", array_merge($auth($token), ['Content-Type: application/json']), '{rotto');
ok($r['status'] === 400 && $r['json']['error'] === 'invalid_json', 'JSON rotto: 400');
$r = postJson($base, '/api/v1/telemetry', $token, ['schema' => 'telemetry/2'] + $batch);
ok($r['status'] === 400 && $r['json']['error'] === 'unsupported_schema', 'schema diverso: 400');
$b4 = $batch;
unset($b4['samples']);
$r = postJson($base, '/api/v1/telemetry', $token, $b4);
ok($r['status'] === 400 && $r['json']['error'] === 'missing_field', 'samples mancante: 400');
$r = postJson($base, '/api/v1/telemetry', $token, ['mac' => '94:83:c4:00:00:02'] + $batch);
ok($r['status'] === 409, 'mac diverso: 409');
$b5 = $batch;
$b5['samples'] = array_fill(0, 289, sample("$boot-1", $now));
$r = postJson($base, '/api/v1/telemetry', $token, $b5);
ok($r['status'] === 413, 'più di 288 campioni: 413');
$r = http('POST', "$base/api/v1/telemetry", array_merge($auth($token), ['Content-Type: application/json']), str_repeat(' ', 1048577));
ok($r['status'] === 413, 'corpo oltre 1 MB: 413');
$r = http('POST', "$base/api/v1/telemetry", array_merge($auth($token), ['Content-Type: application/json', 'Content-Encoding: gzip']), (string) gzencode('{"a":"' . str_repeat('x', 1100000) . '"}'));
ok($r['status'] === 413, 'gzip oltre 1 MB decompresso: 413');
$r = http('POST', "$base/api/v1/telemetry", array_merge($auth($token), ['Content-Type: text/plain']), '{}');
ok($r['status'] === 415, 'Content-Type sbagliato: 415');

// eventi generati dal portal
$b6 = $batch;
$b6['boot_id'] = 'aaaabbbb';
$b6['app_version'] = '1.1.1';
$b6['samples'] = [sample('aaaabbbb-1', $now - 5, ['uptime' => 100, 'power' => ['battery_v' => 11.2, 'charge_state' => 3]])];
postJson($base, '/api/v1/telemetry', $token, $b6);
$types = Db::all("SELECT type, source FROM events WHERE webcam_id = ? AND source = 'portal'", [$wid]);
$types = array_column($types, 'type');
ok(in_array('boot', $types, true), 'boot_id cambiato: evento boot del portal');
ok(in_array('app.version_change', $types, true), 'app_version cambiata: app.version_change');
ok(in_array('power.low_battery', $types, true), 'batteria sotto soglia: power.low_battery');

// ------------------------------------------------------------ eventi
section('Eventi');
$ev = json_decode((string) file_get_contents(WEBCAM_PORTAL_ROOT . '/docs/contratto/esempi/evento-v1.json'), true);
foreach ($ev['events'] as &$e) {
	$e['time'] = $now - 100;
}
unset($e);
$r = postJson($base, '/api/v1/event', $token, $ev);
ok($r['status'] === 200 && $r['json']['accepted'] === 3, 'esempio del contratto: 3 eventi accettati', $r['body']);
$r = postJson($base, '/api/v1/event', $token, $ev);
ok($r['status'] === 200 && $r['json']['duplicates'] === 3 && $r['json']['accepted'] === 0, 'stesso lotto: 3 duplicati');
$ev2 = $ev;
$ev2['events'] = [
	['id' => '3f2a9c1e-E900', 'time' => $now, 'time_synced' => true, 'type' => 'nuovo.tipo', 'severity' => 'info'],
	['id' => '3f2a9c1e-E901', 'time' => $now, 'time_synced' => true, 'type' => 'x', 'severity' => 'info'],
	['id' => '3f2a9c1e-E902', 'time' => $now, 'time_synced' => true, 'type' => 'boot', 'severity' => 'grave'],
];
$r = postJson($base, '/api/v1/event', $token, $ev2);
ok($r['status'] === 200 && $r['json']['accepted'] === 1 && count($r['json']['rejected']) === 2, 'tipo sconosciuto accettato, campi non validi rifiutati', $r['body']);
$r = postJson($base, '/api/v1/event', $token, ['events' => array_fill(0, 101, $ev2['events'][0])] + $ev2);
ok($r['status'] === 413, 'più di 100 eventi: 413');
$r = postJson($base, '/api/v1/event', $token, ['schema' => 'telemetry/1'] + $ev);
ok($r['status'] === 400, 'schema sbagliato: 400');

// ------------------------------------------------------------ limite di frequenza
section('Limite di frequenza');
$writeCfg("$tmp/config-rl.php", ['rate_limit_per_min' => 3] + $cfgValues);
$baseRl = $startServer("$public/index.php", ['WEBCAM_CONFIG' => "$tmp/config-rl.php"], $public);
$codes = [];
for ($i = 0; $i < 5; $i++) {
	$r = http('GET', "$baseRl/api/v1/whoami", $auth($token2));
	$codes[] = $r['status'];
}
ok($codes[0] === 200 && in_array(429, $codes, true) && isset($r['headers']['retry-after']), 'oltre il limite: 429 con Retry-After', implode(',', $codes));

// ------------------------------------------------------------ manutenzione
section('Manutenzione');
Db::exec('DELETE FROM measurements_hourly');
$n = HourlyAggregator::aggregate($now - 2 * 86400, $now + 3600);
$h = Db::one('SELECT SUM(samples) s, SUM(samples_unsynced) u FROM measurements_hourly WHERE webcam_id = ?', [$wid]);
ok($n > 0 && (int) $h['s'] === (int) Db::scalar('SELECT COUNT(*) FROM measurements WHERE webcam_id = ? AND time_synced = 1', [$wid]), 'aggregate-hourly ricostruisce le ore');
$avg = Db::scalar('SELECT t_in_avg FROM measurements_hourly WHERE webcam_id = ? AND samples > 0 ORDER BY hour_ts LIMIT 1', [$wid]);
ok((float) $avg === 14.5, 'media oraria della temperatura interna', (string) $avg);
ok(Partitions::ensure(2) === [], 'partitions: idempotente');
$future = strtotime('+3 months', $now);
$created = Partitions::ensure(2, $future);
ok(count($created) >= 1, 'partitions: crea i mesi mancanti', implode(',', $created));
$drop = Partitions::dropExpired(12, true, strtotime('+14 months', $now));
ok(in_array('p_old', $drop, true) && in_array('p' . gmdate('Y_m', $now), $drop, true), 'partitions: oltre la retention (prova)', implode(',', $drop));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/partitions.php') . ' 2>&1', $out, $rc);
ok($rc === 0, 'bin/partitions.php esce con 0', implode("\n", $out));

// retention foto: 4 foto vecchie nella stessa ora, 2 in un'altra
$old = $now - 100 * 86400;
$old -= $old % 3600;
$ids = [];
foreach ([0, 600, 1200, 1800, 3600, 4200] as $k => $off) {
	$jj = jpeg(32, 18, 100 + $k);
	$rel = "photos/$wid/old/r$k.jpg";
	@mkdir(dirname("$tmp/storage/$rel"), 0775, true);
	file_put_contents("$tmp/storage/$rel", $jj);
	Db::exec('INSERT INTO photos (webcam_id, taken_at, sha256, size_bytes, file_name, storage_path, time_synced) VALUES (?, ?, ?, ?, ?, ?, 1)',
		[$wid, Db::dt($old + $off), hash('sha256', $jj), strlen($jj), "r$k.jpg", $rel]);
	$ids[] = (int) Db::pdo()->lastInsertId();
}
$s = PhotoRetention::run(90, false, null, $now);
$left = Db::all('SELECT id FROM photos WHERE id IN (' . implode(',', $ids) . ') AND deleted_at IS NULL ORDER BY id');
ok($s['deleted'] === 4 && array_column($left, 'id') === [$ids[0], $ids[4]], 'retention: una foto per ora, le altre cancellate', json_encode($s));
ok(!is_file("$tmp/storage/photos/$wid/old/r1.jpg") && is_file("$tmp/storage/photos/$wid/old/r0.jpg"), 'retention: file cancellati dal disco');
ok(http('GET', "$base/cam/monte-cavo/current.jpg")['status'] === 200, 'retention: current.jpg intatto');
$s = PhotoRetention::run(90, false, null, $now);
ok($s['deleted'] === 0, 'retention: secondo giro senza cancellazioni');

// ------------------------------------------------------------ Dolibarr
section('Dolibarr');
$dlog = "$tmp/dolibarr.log";
$fake = WEBCAM_PORTAL_ROOT . '/tests/fake-dolibarr.php';
$d11 = $startServer($fake, ['FAKE_DOLIBARR_MODE' => '11', 'FAKE_DOLIBARR_LOG' => $dlog], WEBCAM_PORTAL_ROOT . '/tests');
$d12 = $startServer($fake, ['FAKE_DOLIBARR_MODE' => '12', 'FAKE_DOLIBARR_LOG' => $dlog], WEBCAM_PORTAL_ROOT . '/tests');
$runSync = static function (string $url, string $key = 'chiave-di-prova') use ($tmp, $cfgValues, $writeCfg): array {
	$writeCfg("$tmp/config-sync.php", ['public_base_url' => 'https://portal.example', 'dolibarr' => ['url' => "$url/api/index.php", 'api_key' => $key, 'timeout' => 5, 'verify_tls' => true]] + $cfgValues);
	$cmd = 'WEBCAM_CONFIG=' . escapeshellarg("$tmp/config-sync.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/sync-dolibarr.php') . ' 2>&1';
	if (DIRECTORY_SEPARATOR === '\\') {
		putenv("WEBCAM_CONFIG=$tmp/config-sync.php");
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/sync-dolibarr.php') . ' 2>&1';
	}
	$out = [];
	exec($cmd, $out, $rc);
	putenv("WEBCAM_CONFIG=$tmp/config.php");
	return [$rc, implode("\n", $out)];
};
@unlink($dlog);
[$rc, $out] = $runSync($d11);
$calls = array_map(static fn ($l) => json_decode($l, true), file($dlog, FILE_IGNORE_NEW_LINES) ?: []);
ok($rc === 0, 'modulo 1.1 (endpoint v2 assenti): uscita 0, nessun crash', $out);
ok(str_contains($out, 'non disponibile'), 'modulo 1.1: avviso nel log');
ok(Db::scalar('SELECT dolibarr_id FROM webcams WHERE id = ?', [$wid]) == 12, 'modulo 1.1: webcam collegata con GET webcams?sqlfilters');
ok(($calls[0]['path'] ?? '') === '/webcams/bymac/9483c412abcd', 'bymac con il MAC senza separatori', (string) ($calls[0]['path'] ?? ''));
ok(count(array_filter($calls, static fn ($c) => $c['path'] === '/webcams/12/counters')) <= 1, 'endpoint assente: non si riprova a ogni webcam');

@unlink($dlog);
[$rc, $out] = $runSync($d12);
$calls = array_map(static fn ($l) => json_decode($l, true), file($dlog, FILE_IGNORE_NEW_LINES) ?: []);
$byPath = [];
foreach ($calls as $c) {
	$byPath[$c['method'] . ' ' . $c['path']][] = $c;
}
ok($rc === 0, 'modulo 1.2: uscita 0', $out);
$portal = $byPath['PUT /webcams/12/portal'][0]['body'] ?? [];
ok(($portal['url_current'] ?? '') === 'https://portal.example/cam/monte-cavo/current.jpg' && $portal['portal_slug'] === 'monte-cavo', 'PUT portal con url_current');
$cnt = $byPath['POST /webcams/12/counters'][0]['body'] ?? [];
ok(($cnt['camera']['shots_total'] ?? null) === 48214 && ($cnt['camera']['serial'] ?? '') === '123456789012', 'counters: contatori della reflex', json_encode($cnt));
ok(isset($cnt['power']['battery_v'], $cnt['power']['battery_v_min24']) && (float) $cnt['power']['battery_v_min24'] === 11.2 && $cnt['power']['charge_state'] === 'bulk', 'counters: sintesi Victron', json_encode($cnt['power'] ?? null));
$evs = $byPath['POST /webcams/12/events'] ?? [];
$sev = array_unique(array_map(static fn ($c) => $c['body']['severity'], $evs));
ok(count($evs) >= 2 && array_diff($sev, ['warning', 'error', 'critical']) === [], 'events: solo gravità >= warning', json_encode($sev));
ok((int) Db::scalar("SELECT COUNT(*) FROM events WHERE webcam_id = ? AND dolibarr_synced_at IS NOT NULL AND dolibarr_actioncomm_id IS NOT NULL", [$wid]) === count($evs), 'events: segnati come inviati');
@unlink($dlog);
$runSync($d12);
$calls = file($dlog, FILE_IGNORE_NEW_LINES) ?: [];
ok(count(array_filter($calls, static fn ($l) => str_contains($l, '/events'))) === 0, 'events: non si rimandano');
[$rc, $out] = $runSync($d12, 'chiave-sbagliata');
ok($rc === 1 && str_contains($out, '401'), 'chiave API sbagliata: errore nel log, uscita 1, nessun crash', $out);
[$rc, $out] = $runSync('http://127.0.0.1:1');
ok($rc === 1 && str_contains($out, 'non raggiungibile'), 'Dolibarr spento: errore nel log, nessun crash', $out);

// ------------------------------------------------------------ pannello
section('Pannello');
Db::exec("INSERT INTO users (email, password_hash, role) VALUES ('op@test.it', ?, 'operator')", [password_hash('password-di-prova', PASSWORD_DEFAULT)]);
$jar = [];
$page = static function (string $method, string $path, array $form = []) use ($base, &$jar): array {
	$h = [];
	if ($jar) {
		$h[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($jar), $jar));
	}
	$body = null;
	if ($method === 'POST') {
		$h[] = 'Content-Type: application/x-www-form-urlencoded';
		$body = http_build_query($form);
	}
	$ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0]]);
	$http_response_header = [];
	$raw = (string) @file_get_contents($base . $path, false, $ctx);
	$status = 0;
	$loc = '';
	foreach ($http_response_header as $l) {
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) {
			$status = (int) $m[1];
		} elseif (preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $l, $m)) {
			$jar[$m[1]] = $m[2];
		} elseif (preg_match('#^Location:\s*(\S+)#i', $l, $m)) {
			$loc = $m[1];
		}
	}
	preg_match('/name="_csrf" value="([^"]+)"/', $raw, $cm);
	return ['status' => $status, 'body' => $raw, 'location' => $loc, 'csrf' => $cm[1] ?? ''];
};
$r = $page('GET', '/admin/');
ok($r['status'] === 303 && str_ends_with($r['location'], '/admin/login'), 'senza login: rimanda al login');
$r = $page('GET', '/admin/login');
$r2 = $page('POST', '/admin/login', ['_csrf' => $r['csrf'], 'email' => 'op@test.it', 'password' => 'sbagliata']);
ok($r2['status'] === 401, 'password sbagliata: 401');
$r2 = $page('POST', '/admin/login', ['_csrf' => $r2['csrf'], 'email' => 'op@test.it', 'password' => 'password-di-prova']);
ok($r2['status'] === 303, 'login: 303');
$r = $page('GET', '/admin/');
ok($r['status'] === 200 && str_contains($r['body'], 'Monte Cavo') && str_contains($r['body'], '94:83:c4:12:ab:cd'), 'elenco webcam');
$r = $page('GET', "/admin/webcams/$wid");
ok($r['status'] === 200 && str_contains($r['body'], '<svg') && str_contains($r['body'], 'class="line s2"'), 'scheda webcam con grafico SVG');
ok($page('GET', "/admin/webcams/$wid2/current.jpg")['status'] === 404 && $page('GET', "/admin/webcams/$wid/current.jpg")['status'] === 200, 'current.jpg nel pannello anche per webcam non pubbliche');
$r = $page('POST', '/admin/webcams/new', ['_csrf' => $r['csrf'], 'mac' => '94-83-C4-00-00-77', 'name' => 'Nuova Città', 'slug' => '', 'timezone' => 'Europe/Rome']);
preg_match('/(wct_[A-Za-z0-9_-]{43})/', $r['body'], $tm);
ok($r['status'] === 200 && isset($tm[1]), 'creazione webcam: token mostrato una volta');
$newId = (int) Db::scalar("SELECT id FROM webcams WHERE mac = '94:83:c4:00:00:77'");
ok($newId > 0 && Db::scalar('SELECT slug FROM webcams WHERE id = ?', [$newId]) === 'nuova-citta', 'MAC normalizzato e slug dal nome');
ok(isset($tm[1]) && http('GET', "$base/api/v1/whoami", $auth($tm[1]))['status'] === 200, 'il token creato dal pannello funziona');
$r = $page('POST', "/admin/webcams/$newId/token", ['_csrf' => $r['csrf']]);
preg_match('/(wct_[A-Za-z0-9_-]{43})/', $r['body'], $tm2);
ok(isset($tm2[1]) && http('GET', "$base/api/v1/whoami", $auth($tm2[1]))['status'] === 200 && http('GET', "$base/api/v1/whoami", $auth($tm[1] ?? ''))['status'] === 403, 'rigenerazione: nuovo token valido, vecchio revocato');
$r = $page('POST', "/admin/webcams/$newId/token", ['_csrf' => 'falso']);
ok($r['status'] === 400, 'POST senza CSRF valido: 400');

// ------------------------------------------------------------ simulatore
section('Simulatore');
$tokSim = Tokens::create($wid2);
Db::exec('UPDATE webcams SET is_public = 1 WHERE id = ?', [$wid2]);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/tools/simula-router.php')
	. ' --url ' . escapeshellarg($base) . ' --token ' . escapeshellarg($tokSim) . ' --mac 94:83:c4:00:00:02 --giorni 1 2>&1';
$out = [];
exec($cmd, $out, $rc);
$nM = (int) Db::scalar('SELECT COUNT(*) FROM measurements WHERE webcam_id = ?', [$wid2]);
$nP = (int) Db::scalar('SELECT COUNT(*) FROM photos WHERE webcam_id = ?', [$wid2]);
ok($rc === 0 && $nM >= 288 && $nP >= 24, "simulatore --giorni 1: $nM campioni, $nP foto", implode("\n", array_slice($out, -5)));
$out = [];
exec(str_replace('--giorni 1', '--una-volta', $cmd), $out, $rc);
ok($rc === 0 && http('GET', "$base/cam/seconda/current.jpg")['status'] === 200, 'simulatore --una-volta e current.jpg', implode("\n", array_slice($out, -5)));
ok((int) Db::scalar('SELECT COUNT(*) FROM measurements_hourly WHERE webcam_id = ? AND samples > 0', [$wid2]) >= 24, 'medie orarie dal simulatore');

// ------------------------------------------------------------ errori del server
section('Log');
$logs = '';
foreach (glob("$tmp/server-*.log") ?: [] as $f) {
	$logs .= (string) file_get_contents($f);
}
ok(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $logs), 'nessun warning/notice PHP nei server', substr((string) preg_replace('/^.*(Accepted|Closing|Development Server|\[200\]|\[201\]).*$/m', '', $logs), 0, 2000));
$plog = (string) @file_get_contents("$tmp/storage/logs/portal.log");
ok(!str_contains($plog, 'ERRORE'), 'nessun ERRORE nel log del portal', substr($plog, 0, 1000));

echo "\n$passed passati, $failed falliti\n";
exit($failed > 0 ? 1 : 0);
