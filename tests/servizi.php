<?php
/**
 * Test dei servizi della fase 3b (contratto 1.3, servizi-v1.md), incluso da
 * tests/run.php dopo siti.php con le stesse variabili: funzioni dal listino
 * (Dolibarr 1.4), sovrimpressione e current.jpg, foto buone, timelapse con e
 * senza ffmpeg, stesso momento, foto del giorno e formati social, beacon e
 * statistiche, registro delle impronte, rapporto mensile e certificato in PDF
 * (con pdftotext), file firmati /media, bot Telegram, pannello, script.
 */

declare(strict_types=1);

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Service\Customers;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\PhotoOfDay;
use WebcamPortal\Service\PhotoRetention;
use WebcamPortal\Service\PhotoScores;
use WebcamPortal\Service\Register;
use WebcamPortal\Service\Reports;
use WebcamPortal\Service\SiteKeys;
use WebcamPortal\Service\Stats;
use WebcamPortal\Service\Timelapse;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;
use WebcamPortal\Site\Images;
use WebcamPortal\Site\Media;
use WebcamPortal\Telegram\Bot;

section('Servizi: funzioni dal listino');
$m = Features::compute('base', []);
ok($m === ['archive', 'availability'], 'base: archivio e disponibilità', json_encode($m));
$m = Features::compute('standard', []);
ok(in_array('timelapse', $m, true) && in_array('overlay', $m, true) && in_array('telegram', $m, true) && !in_array('stats', $m, true) && !in_array('sponsor', $m, true), 'standard: overlay, timelapse, telegram; niente stats e sponsor', json_encode($m));
$m = Features::compute('pro', []);
ok(in_array('stats', $m, true) && in_array('weather_cert', $m, true) && in_array('timelapse_portrait', $m, true) && !in_array('final_film', $m, true), 'pro: tutto tranne il filmato finale (opzione)', json_encode($m));
$m = Features::compute('standard', ['sponsor' => ['enabled' => true, 'start' => '2026-12-01', 'end' => '2027-01-31'], 'weather_cert' => true, 'film' => ['option' => 'auto']], '2026-11-19');
ok(!in_array('sponsor', $m, true) && in_array('weather_cert', $m, true) && in_array('final_film', $m, true), 'opzioni: sponsor fuori periodo no, certificato e filmato sì', json_encode($m));
ok(in_array('sponsor', Features::compute('standard', ['sponsor' => ['enabled' => true, 'start' => '2026-12-01', 'end' => '2027-01-31']], '2026-12-10'), true), 'opzione sponsor nel periodo');
ok(Features::compute('oro', []) === Features::compute('base', []), 'livello sconosciuto = base');

// ------------------------------------------------------------ configurazione e dati
$botToken = 'token-di-prova';
$hookSecret = str_repeat('s3', 20);
$tgLog = "$tmp/telegram.log";
$tg = $startServer(WEBCAM_PORTAL_ROOT . '/tests/fake-telegram.php', ['FAKE_TG_LOG' => $tgLog], WEBCAM_PORTAL_ROOT . '/tests');
$dlog14 = "$tmp/dolibarr14.log";
$dstate14 = "$tmp/dolibarr14.json";
$d14 = $startServer(WEBCAM_PORTAL_ROOT . '/tests/fake-dolibarr.php', ['FAKE_DOLIBARR_MODE' => '14', 'FAKE_DOLIBARR_LOG' => $dlog14, 'FAKE_DOLIBARR_STATE' => $dstate14], WEBCAM_PORTAL_ROOT . '/tests');
$svcValues = [
	'public_base_url' => 'https://portal.example',
	'dolibarr' => ['url' => "$d14/api/index.php", 'api_key' => 'chiave-di-prova', 'timeout' => 5, 'verify_tls' => true],
	'ffmpeg' => ['path' => '/usr/bin/ffmpeg', 'timeout' => 300, 'threads' => 1, 'nice' => 0],
	'telegram' => ['enabled' => true, 'bot_token' => $botToken, 'mode' => 'webhook', 'webhook_secret' => $hookSecret, 'api_url' => $tg, 'quiet_hours' => '', 'daily_limit' => 50],
	'reports' => ['enabled' => true, 'day' => 3, 'time' => '06:00', 'link_url' => 'https://clienti.example/webcam'],
] + $cfgValues;
$writeCfg("$tmp/config-servizi.php", $svcValues);
$writeCfg("$tmp/config-noff.php", ['ffmpeg' => ['path' => '/percorso/inesistente/ffmpeg']] + $svcValues);
$baseS = $startServer(WEBCAM_PORTAL_ROOT . '/public/index.php', ['WEBCAM_CONFIG' => "$tmp/config-servizi.php"], WEBCAM_PORTAL_ROOT . '/public');
$baseN = $startServer(WEBCAM_PORTAL_ROOT . '/public/index.php', ['WEBCAM_CONFIG' => "$tmp/config-noff.php"], WEBCAM_PORTAL_ROOT . '/public');
$useCfg = static function (string $file): void {
	putenv("WEBCAM_CONFIG=$file");
	Config::set(Config::load());
	Features::reset();
	Overlay::reset();
};
$useCfg("$tmp/config-servizi.php");
$bin = static function (string $script, string $args = '', string $cfg = 'config-servizi.php') use ($tmp): array {
	$out = [];
	exec('WEBCAM_CONFIG=' . escapeshellarg("$tmp/$cfg") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . "/bin/$script") . " $args 2>&1", $out, $rc);
	return [$rc, implode("\n", $out)];
};
$SS = "$baseS/api/site/v1";
// gli URL firmati usano public_base_url (https://portal.example): nei test si chiedono al server di prova
$L = static fn (?string $u): string => str_replace('https://portal.example', $baseS, (string) $u);

section('Servizi: listino e archivio da Dolibarr 1.4');
// la webcam 12 del finto Dolibarr diventa una webcam nuova, con un cliente di prima (socid 41) in archivio
Db::exec("UPDATE webcams SET mac = '94:83:c4:7f:00:02', dolibarr_id = NULL, status = 9 WHERE mac = '94:83:c4:12:ab:cd'");
$wS = Webcams::create('94:83:c4:55:00:01', 'Cantiere Servizi', 'cantiere-servizi', ['is_public' => true, 'location' => 'Frascati']);
Db::exec("UPDATE webcams SET mac = '94:83:c4:12:ab:cd', interval_min = 10, last_seen_at = UTC_TIMESTAMP(), created_at = ? WHERE id = ?", [Db::dt($now - 6 * 86400), $wS]);
$tS = Tokens::create($wS);
$macS = '94:83:c4:12:ab:cd';
$cOld = Customers::upsertDolibarr(41, ['name' => 'Cliente di prima']);
$oldTo = $now - 100 * 86400;
Db::exec("INSERT INTO customer_webcams (customer_id, webcam_id, valid_from, valid_to, source) VALUES (?, ?, ?, ?, 'dolibarr')", [$cOld, $wS, Db::dt($now - 200 * 86400), Db::dt($oldTo)]);
$archUntil = (new DateTimeImmutable('now', $rome))->modify('+20 days')->format('Y-m-d');
file_put_contents($dstate14, json_encode(['portal14' => [
	'sla' => ['enabled' => true, 'offline_hours' => 4, 'response_days' => 2, 'target_pct' => 97.5, 'hours' => '10:00-15:00', 'weekdays' => '1234567'],
	'archives' => [['socid' => 41, 'rental_start' => '2026-01-01', 'rental_end' => (new DateTimeImmutable('@' . $oldTo))->setTimezone($rome)->format('Y-m-d'), 'archive_until' => $archUntil]],
]]));
[$rc, $out] = $runSync($d14);
$svc = Db::one('SELECT plan_level, plan_options, source FROM webcam_service WHERE webcam_id = ?', [$wS]);
ok($rc === 0 && $svc !== null && $svc['plan_level'] === 'pro' && json_decode((string) $svc['plan_options'], true)['report']['enabled'] === true, 'sync-dolibarr: plan e options in webcam_service', json_encode($svc) . "\n" . $out);
$cw = Db::one('SELECT archive_until, archive_source FROM customer_webcams WHERE webcam_id = ? AND customer_id = ?', [$wS, $cOld]);
ok($cw !== null && $cw['archive_until'] === $archUntil && $cw['archive_source'] === 'dolibarr', 'sync-dolibarr: archive_until dell\'assegnazione chiusa (±3 giorni da rental_end)', json_encode($cw));
$cNew = (int) Db::scalar('SELECT customer_id FROM webcams WHERE id = ?', [$wS]);
ok($cNew > 0 && $cNew !== $cOld && (int) Db::scalar('SELECT dolibarr_id FROM webcams WHERE id = ?', [$wS]) === 12, 'webcam collegata e assegnata al cliente di Dolibarr');
// l'assegnazione in corso è iniziata 5 giorni fa (foto di ieri visibili al cliente e al pubblico)
Db::exec('UPDATE customer_webcams SET valid_from = ? WHERE webcam_id = ? AND customer_id = ? AND valid_to IS NULL', [Db::dt($now - 5 * 86400), $wS, $cNew]);
Features::reset();
ok(Features::has($wS, 'stats') && Features::has($wS, 'telegram') && !Features::has($wS, 'final_film'), 'funzioni pro dal listino di Dolibarr');
$kS = SiteKeys::create($cNew, 'client', '', 'portale clienti servizi')['key'];
$kSPub = SiteKeys::create($cNew, 'public', '', 'sito pubblico servizi')['key'];
$kOld = SiteKeys::create($cOld, 'client', '', 'cliente di prima')['key'];
$r = http('GET', "$SS/webcams", $key($kS));
$obj = $r['json']['webcams'][0] ?? [];
ok($r['status'] === 200 && in_array('timelapse', $obj['features'] ?? [], true) && isset($obj['beacon']['token'], $obj['beacon']['url']) && $obj['overlay'] === false, 'oggetto webcam 1.1: features, overlay, beacon', $r['body']);
$r = http('GET', "$SS/webcams", $key($kOld));
$arch = array_values(array_filter($r['json']['webcams'] ?? [], static fn ($w) => $w['slug'] === 'cantiere-servizi'))[0] ?? null;
ok($arch !== null && $arch['state'] === 'archived' && $arch['archive_until'] === $archUntil && $arch['current_url'] === null && $arch['features'] === Features::ARCHIVED, 'cliente di prima: webcam in archivio con archive_until', $r['body']);
ok(http('GET', "$SS/webcams/cantiere-servizi/overlay-items", $key($kOld))['status'] === 403, 'archivio: niente sponsor (solo funzioni di consultazione)');
$r = http('GET', "$SS/whoami", $key($kS));
ok(($r['json']['server']['timelapse'] ?? null) === true && ($r['json']['server']['telegram'] ?? null) === true, 'whoami.server con ffmpeg e Telegram', $r['body']);
$r = http('GET', "$baseN/api/site/v1/whoami");
ok(($r['json']['server']['timelapse'] ?? null) === false && ($r['json']['server']['reason']['timelapse'] ?? '') === 'ffmpeg_missing', 'whoami.server senza ffmpeg: timelapse false, ffmpeg_missing', $r['body']);
$r = http('GET', "$base/api/site/v1/whoami");
ok(($r['json']['server']['telegram'] ?? null) === false && ($r['json']['server']['reason']['telegram'] ?? '') === 'not_configured', 'whoami.server senza bot: telegram not_configured', $r['body']);

// foto di ieri dalle 10:00 alle 14:50 (ora locale) ogni 10 minuti, nitide e di giorno
$yday = (new DateTimeImmutable('now', $rome))->modify('-1 day')->format('Y-m-d');
$y0 = (new DateTimeImmutable("$yday 10:00:00", $rome))->getTimestamp();
$dayEnd = (new DateTimeImmutable("$yday 17:00:00", $rome))->getTimestamp();
$texture = static function (int $seed, int $w = 640, int $h = 427): string {
	mt_srand(1000 + $seed);
	$im = imagecreatetruecolor($w, $h);
	imagefill($im, 0, 0, imagecolorallocate($im, 110, 130, 150));
	for ($i = 0; $i < 300; $i++) {
		$c = mt_rand(40, 220);
		imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), imagecolorallocate($im, $c, (int) ($c * 0.9), min(255, $c + 20)));
	}
	imagestring($im, 5, 10, 10, "foto $seed", imagecolorallocate($im, 255, 255, 255));
	ob_start();
	imagejpeg($im, null, 85);
	return (string) ob_get_clean();
};
$pS = [];
$jS = [];
for ($i = 0; $i < 30; $i++) {
	$t = $y0 + $i * 600;
	$j = $texture($i);
	$r = sendPhoto($base, $tS, $j, photoMeta($j, $t, $macS, ['day_end' => $dayEnd, 'day_start' => $y0 - 3 * 3600]));
	$pS[$i] = (int) ($r['json']['photo_id'] ?? 0);
	$jS[$i] = $j;
}
$jn = $texture(99);
$pNight = (int) (sendPhoto($base, $tS, $jn, photoMeta($jn, $y0 + 30 * 600 + 60, $macS, ['mode' => 'night']))['json']['photo_id'] ?? 0);
ok(count(array_filter($pS)) === 30 && $pNight > 0, '30 foto di giorno e una di notte caricate');
// telemetria: umidità 98 % dalle 10 alle 15 (ore di lavoro del contratto): giornata di maltempo
$samples = [];
for ($k = 0; $k < 60; $k++) {
	$samples[] = sample('e5f6a7b8-' . ($k + 1), $y0 + $k * 300, ['climate' => ['temperature' => 8.0, 'humidity' => 70.0, 'ext_sensor_ok' => true, 'temperature_ext' => 6.0, 'humidity_ext' => 98.0, 'heater' => false]]);
}
$r = postJson($base, '/api/v1/telemetry', $tS, ['schema' => 'telemetry/1', 'mac' => $macS, 'boot_id' => 'e5f6a7b8', 'sent_at' => $now, 'time_synced' => true, 'samples' => $samples]);
ok($r['status'] === 200 && ($r['json']['accepted'] ?? 0) === 60, 'telemetria di ieri (umidità 98 %)', $r['body']);

// ------------------------------------------------------------ foto buone
section('Servizi: foto buone (O8)');
$ps = new PhotoScores($now);
$st = $ps->run($wS);
$sc = Db::all('SELECT photo_id, good, reject_reason, score, source FROM photo_scores WHERE webcam_id = ? ORDER BY photo_id', [$wS]);
$good = array_values(array_filter($sc, static fn ($s) => (int) $s['good'] === 1));
$night = array_values(array_filter($sc, static fn ($s) => (int) $s['photo_id'] === $pNight))[0] ?? null;
ok(count($sc) === 31 && count($good) >= 24, 'punteggi: ' . count($good) . ' foto buone su 31', json_encode($st) . json_encode(array_count_values(array_map(static fn ($s) => (string) $s['reject_reason'], $sc))));
ok($night !== null && $night['reject_reason'] === 'night' && $night['score'] === null, 'foto di notte: night, senza punteggio');
ok($good !== [] && min(array_map('floatval', array_column($good, 'score'))) >= 0 && max(array_map('floatval', array_column($good, 'score'))) <= 100, 'punteggio tra 0 e 100');
ok(in_array('own', array_column($sc, 'source'), true) && in_array('inherited', array_column($sc, 'source'), true), 'metriche calcolate per una foto ogni 15 minuti, le altre ereditate', json_encode(array_count_values(array_column($sc, 'source'))));
ok((new PhotoScores($now))->run($wS)['scored'] === 0 || Db::scalar('SELECT COUNT(*) FROM photo_scores WHERE webcam_id = ?', [$wS]) == 31, 'secondo giro: nessun doppione');
PhotoScores::saveParams($wS, ['bright_min' => 250]);
(new PhotoScores($now))->run($wS);
ok((int) Db::scalar('SELECT SUM(good) FROM photo_scores WHERE webcam_id = ?', [$wS]) === 0 && (int) Db::scalar('SELECT MIN(params_rev) FROM photo_scores WHERE webcam_id = ?', [$wS]) === 2, 'soglie cambiate: foto rivalutate (params_rev)');
PhotoScores::saveParams($wS, []);
(new PhotoScores($now))->run($wS);
ok((int) Db::scalar('SELECT SUM(good) FROM photo_scores WHERE webcam_id = ?', [$wS]) >= 24, 'soglie predefinite di nuovo');

// ------------------------------------------------------------ sovrimpressione
section('Servizi: sovrimpressione (M2) e sponsor (M12)');
$png = (static function (): string {
	$im = imagecreatetruecolor(300, 120);
	imagesavealpha($im, true);
	imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
	imagefilledellipse($im, 150, 60, 280, 100, imagecolorallocate($im, 230, 40, 40));
	ob_start();
	imagepng($im);
	return (string) ob_get_clean();
})();
file_put_contents("$tmp/logo.png", $png);
$logoId = Overlay::addAsset("$tmp/logo.png", 'logo', $cNew, 'logo cliente');
$asset = Db::one('SELECT * FROM overlay_assets WHERE id = ?', [$logoId]);
ok($asset !== null && $asset['mime'] === 'image/png' && is_file("$tmp/storage/" . $asset['storage_path']) && (int) $asset['width'] === 300, 'logo PNG caricato e riscritto con GD');
file_put_contents("$tmp/finto.png", 'non è una immagine');
try {
	Overlay::addAsset("$tmp/finto.png", 'logo', $cNew, 'falso');
	ok(false, 'file non immagine rifiutato');
} catch (InvalidArgumentException $e) {
	ok(true, 'file non immagine rifiutato');
}
$pid = $pS[29];
$u1280 = Images::urls($baseS, $pid, ['1280'])['1280'];
$plain = http('GET', $u1280);
Overlay::save($wS, ['enabled' => 1, 'fields' => ['datetime', 'temperature_ext', 'humidity_ext', 'name'], 'apply_to' => Overlay::APPLY, 'logo_asset_id' => $logoId, 'current_size' => '1280']);
$rev = (int) Db::scalar('SELECT overlay_rev FROM overlay_config WHERE webcam_id = ?', [$wS]);
$ovr = http('GET', $u1280);
ok($plain['status'] === 200 && $ovr['status'] === 200 && $ovr['body'] !== $plain['body'] && str_contains($ovr['headers']['etag'] ?? '', "-o$rev"), "/img 1280 con sovrimpressione: altra immagine, ETag -o$rev", ($plain['headers']['etag'] ?? '') . ' ' . ($ovr['headers']['etag'] ?? ''));
$im = @imagecreatefromstring($ovr['body']);
ok($im !== false && imagesx($im) === 640, 'foto più piccola della dimensione chiesta: non ingrandita');
$u320 = Images::urls($baseS, $pid, ['320'])['320'];
ok(!str_contains(http('GET', $u320)['headers']['etag'] ?? '-o', '-o'), '320 mai con sovrimpressione');
$cache = glob("$tmp/storage/cache/img/$wS/*/$pid-1280-o$rev.jpg") ?: [];
ok(count($cache) === 1, 'cache con la versione della sovrimpressione nel nome');
$orig = Db::one('SELECT sha256, storage_path FROM photos WHERE id = ?', [$pid]);
ok(hash_file('sha256', \WebcamPortal\Service\PhotoStore::abs((string) $orig['storage_path'])) === $orig['sha256'], 'originale mai modificato (SHA-256 uguale)');
$orig = Db::one('SELECT sha256 FROM photos WHERE id = ?', [$pNight]); // current.jpg è l'ultima foto (di notte)
$c = http('GET', "$baseS/cam/cantiere-servizi/current.jpg");
$cj = http('GET', "$baseS/cam/cantiere-servizi/current.json");
ok($c['status'] === 200 && ($c['headers']['etag'] ?? '') === '"' . $orig['sha256'] . "-o$rev\"" && $c['body'] !== $jn, 'current.jpg con sovrimpressione: ETag "<sha>-o<rev>"', $c['headers']['etag'] ?? '');
ok(($cj['json']['overlay'] ?? null) === true && ($cj['json']['width'] ?? 0) === 640 && ($cj['json']['sha256'] ?? '') === $orig['sha256'], 'current.json: overlay, width/height, sha256 dell\'originale', $cj['body']);
ok(http('GET', "$baseS/cam/cantiere-servizi/current.jpg", ['If-None-Match: ' . ($c['headers']['etag'] ?? '')])['status'] === 304, 'current.jpg: 304 con l\'ETag della sovrimpressione');
$sp = Overlay::saveItem($wS, ['kind' => 'sponsor', 'label' => 'Rifugio Alpe', 'text' => 'Polenta', 'url' => 'https://rifugio.example', 'asset_id' => $logoId]);
$ip = Overlay::saveItem($wS, ['kind' => 'infopoint', 'label' => 'Monte Cavo', 'x_pct' => '61,5', 'y_pct' => 22, 'burn' => 1]);
$expired = Overlay::saveItem($wS, ['kind' => 'sponsor', 'label' => 'Scaduto', 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31']);
$rev2 = (int) Db::scalar('SELECT overlay_rev FROM overlay_config WHERE webcam_id = ?', [$wS]);
ok($rev2 > $rev && str_contains(http('GET', $u1280)['headers']['etag'] ?? '', "-o$rev2"), 'sponsor nuovo: overlay_rev cresce e cambia l\'ETag');
$r = http('GET', "$SS/webcams/cantiere-servizi/overlay-items", $key($kSPub));
$spo = $r['json']['sponsors'] ?? [];
ok($r['status'] === 200 && count($spo) === 1 && $spo[0]['label'] === 'Rifugio Alpe' && $spo[0]['burned'] === true && str_contains((string) $spo[0]['logo_url'], '/media/logo/' . $sp . '.png')
	&& ($r['json']['infopoints'][0]['x_pct'] ?? null) === 61.5 && $r['json']['slots'] === 3, 'overlay-items: sponsor nel periodo, infopoint, slots da Dolibarr', $r['body']);
$lg = http('GET', $L($spo[0]['logo_url'] ?? ''));
ok($lg['status'] === 200 && ($lg['headers']['content-type'] ?? '') === 'image/png' && ($lg['headers']['access-control-allow-origin'] ?? '') === '*', '/media/logo: PNG firmato');
$r = http('GET', "$SS/webcams/cantiere-servizi", $key($kS));
ok(($r['json']['webcam']['overlay'] ?? null) === true, 'oggetto webcam: overlay true');
Db::exec("UPDATE webcam_service SET plan_level = 'standard' WHERE webcam_id = ?", [$wS]);
ok(http('GET', "$SS/webcams/cantiere-servizi/overlay-items", $key($kS))['json']['error'] === 'feature_unavailable', 'sponsor senza la funzione: 403 feature_unavailable');
Db::exec("UPDATE webcam_service SET plan_level = 'pro' WHERE webcam_id = ?", [$wS]);
Features::reset();

// ------------------------------------------------------------ stesso momento
section('Servizi: stesso momento (M6)');
$r = http('GET', "$SS/webcams/cantiere-servizi/same-moment?time=12:00&from=$yday&to=$yday&tolerance=30&sizes=320", $key($kSPub));
$it = $r['json']['items'][0] ?? [];
$req12 = (new DateTimeImmutable("$yday 12:00:00", $rome))->getTimestamp();
ok($r['status'] === 200 && $r['json']['count'] === 1 && $it['date'] === $yday && $it['requested'] === $req12 && $it['delta_s'] === 0 && ($it['photo']['id'] ?? 0) === $pS[12] && array_map('strval', array_keys($it['photo']['images'])) === ['320'], 'same-moment: la foto più vicina alle 12:00', $r['body'] . ' atteso ' . $pS[12] . ' ' . gettype($pS[12]));
$r = http('GET', "$SS/webcams/cantiere-servizi/same-moment?time=06:00&from=$yday&to=$yday&tolerance=30", $key($kSPub));
ok(isset($r['json']['items'][0]) && array_key_exists('photo', $r['json']['items'][0]) && $r['json']['items'][0]['photo'] === null, 'same-moment: oltre la tolleranza nessuna foto', $r['body']);
$r = http('GET', "$SS/webcams/cantiere-servizi/same-moment?time=12:05&from=$yday&to=$yday&good=1", $key($kSPub));
ok(($r['json']['items'][0]['good'] ?? null) === true, 'same-moment good=1: foto buona');
$r = http('GET', "$SS/webcams/cantiere-servizi/same-moment?from=2020-01-01&to=2026-11-19", $key($kSPub));
ok($r['status'] === 400, 'same-moment: oltre 400 elementi 400');
$r = http('GET', "$SS/webcams/cantiere-servizi/same-moment?step=month&from=2026-01-01&to=2026-05-31", $key($kSPub));
ok($r['status'] === 200 && array_column($r['json']['items'], 'date') === ['2026-01-31', '2026-03-31', '2026-05-31'], 'same-moment step=month: i mesi senza quel giorno si saltano', $r['body']);
Db::exec("UPDATE webcam_service SET plan_level = 'base' WHERE webcam_id = ?", [$wS]);
ok(http('GET', "$SS/webcams/cantiere-servizi/same-moment", $key($kSPub))['status'] === 403, 'same-moment senza funzione: 403');
Db::exec("UPDATE webcam_service SET plan_level = 'pro' WHERE webcam_id = ?", [$wS]);

// ------------------------------------------------------------ timelapse
section('Servizi: timelapse (M7)');
$t0n = $now;
$tlId = Timelapse::request($wS, 'day', $yday, $yday, 'landscape', ['priority' => 1]);
$ran = Timelapse::runNext($now);
$tl = Db::one('SELECT * FROM timelapses WHERE id = ?', [$tlId]);
$mp4 = $tl['storage_path'] ? "$tmp/storage/" . $tl['storage_path'] : '';
ok($ran === $tlId && $tl['status'] === 'done' && is_file($mp4) && substr((string) file_get_contents($mp4, false, null, 4, 4), 0, 4) === 'ftyp', 'timelapse del giorno fatto con ffmpeg (mp4)', json_encode(array_intersect_key($tl, array_flip(['status', 'status_reason', 'frames_used', 'duration_s']))) . ' ' . (time() - $t0n) . ' s');
ok((int) $tl['frames_used'] === 30 && (int) $tl['fps'] === 8 && (int) $tl['width'] === 1920 && (int) $tl['height'] === 1080 && $tl['sha256'] === hash_file('sha256', $mp4), 'tutti i 30 fotogrammi del giorno, 8 fps (sotto 4 secondi), 1920x1080, sha256', json_encode($tl));
$probe = [];
exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height,codec_name -of csv=p=0 ' . escapeshellarg($mp4) . ' 2>&1', $probe);
ok(trim(implode('', $probe)) === 'h264,1920,1080', 'ffprobe: h264 1920x1080', implode("\n", $probe));
ok($tl['poster_path'] !== null && @getimagesize("$tmp/storage/" . $tl['poster_path'])[0] === 1920, 'poster JPEG');
$tlP = Timelapse::request($wS, 'day', $yday, $yday, 'portrait', ['priority' => 1]);
Timelapse::runNext($now);
$tp = Db::one('SELECT status, width, height FROM timelapses WHERE id = ?', [$tlP]);
ok($tp['status'] === 'done' && (int) $tp['width'] === 1080 && (int) $tp['height'] === 1920, 'timelapse verticale 1080x1920', json_encode($tp));
$r = http('GET', "$SS/webcams/cantiere-servizi/timelapses", $key($kSPub));
$tls = $r['json']['timelapses'] ?? [];
ok($r['status'] === 200 && count($tls) === 2 && ($r['json']['latest']['day']['landscape'] ?? 0) === $tlId && ($r['json']['latest']['day']['portrait'] ?? 0) === $tlP, 'timelapses: elenco e latest per tipo e formato', $r['body']);
$v = http('GET', $L($tls[0]['video_url'] ?? ''));
ok($v['status'] === 200 && ($v['headers']['content-type'] ?? '') === 'video/mp4' && ($v['headers']['accept-ranges'] ?? '') === 'bytes' && strlen($v['body']) === (int) ($tls[0]['size_bytes'] ?? -1), '/media/tl: video completo', json_encode($v['headers']));
$vr = http('GET', $L($tls[0]['video_url'] ?? ''), ['Range: bytes=0-99']);
ok($vr['status'] === 206 && strlen($vr['body']) === 100 && str_starts_with($vr['headers']['content-range'] ?? '', 'bytes 0-99/'), '/media/tl: Range 206 Partial Content', json_encode($vr['headers']));
ok((http('GET', $L($tls[0]['poster_url'] ?? ''))['headers']['content-type'] ?? '') === 'image/jpeg', '/media/tlp: poster');
ok((http('GET', str_replace('&s=', '&s=x', $L($tls[0]['video_url'] ?? '')))['json']['error'] ?? '') === 'bad_signature', '/media: firma sbagliata 403');
$expU = Media::url($baseS, 'tl', $tlId, 'mp4', $now - 10);
ok(http('GET', $expU)['json']['error'] === 'url_expired', '/media: URL scaduto 403');
ok(http('GET', "$SS/webcams/cantiere-servizi/timelapses?kind=custom", $key($kSPub))['status'] === 403, 'timelapses custom con chiave public: 403');
ok(http('GET', "$SS/webcams/cantiere-servizi/timelapses?kind=final", $key($kS))['json']['error'] === 'feature_unavailable', 'timelapses final senza opzione: 403 feature_unavailable');
Db::exec("UPDATE webcam_service SET plan_level = 'standard' WHERE webcam_id = ?", [$wS]);
ok(http('GET', "$SS/webcams/cantiere-servizi/timelapses?format=portrait", $key($kSPub))['status'] === 403, 'portrait solo con pro');
Db::exec("UPDATE webcam_service SET plan_level = 'pro' WHERE webcam_id = ?", [$wS]);
// senza ffmpeg: i lavori in coda falliscono con ffmpeg_missing e il messaggio è chiaro
$useCfg("$tmp/config-noff.php");
$tlX = Timelapse::request($wS, 'custom', $yday, $yday, 'landscape', ['priority' => 1]);
Timelapse::runNext($now);
ok(Db::one('SELECT status, status_reason FROM timelapses WHERE id = ?', [$tlX]) == ['status' => 'failed', 'status_reason' => 'ffmpeg_missing'], 'senza ffmpeg: lavoro in coda -> failed ffmpeg_missing');
[$rc, $out] = $bin('timelapse.php', '--stato', 'config-noff.php');
ok($rc === 1 && str_contains($out, 'ffmpeg non trovato') && str_contains($out, 'ffmpeg.path'), 'bin/timelapse.php --stato: messaggio chiaro senza ffmpeg', $out);
$r = http('GET', "$baseN/api/site/v1/webcams/cantiere-servizi/timelapses", $key($kSPub));
ok($r['status'] === 200 && count($r['json']['timelapses']) === 2, 'senza ffmpeg l\'endpoint dà quelli già fatti');
$useCfg("$tmp/config-servizi.php");
$auto = Timelapse::autoCreate((new DateTimeImmutable('today 02:30', $rome))->getTimestamp());
ok((int) Db::scalar("SELECT COUNT(*) FROM timelapses WHERE webcam_id = ? AND kind = 'day' AND period_from = ? AND requested_by = 'auto'", [$wS, $yday]) >= 1, 'lavori automatici: giorno di ieri');
Db::exec("UPDATE timelapses SET status = 'cancelled' WHERE webcam_id = ? AND status = 'queued'", [$wS]);

// ------------------------------------------------------------ foto del giorno
section('Servizi: foto del giorno (M11)');
$best = Db::one('SELECT photo_id, score FROM photo_scores WHERE webcam_id = ? AND day = ? AND good = 1 ORDER BY score DESC, taken_at LIMIT 1', [$wS, $yday]);
ok(PhotoOfDay::dueAt(Db::one('SELECT * FROM webcams WHERE id = ?', [$wS]), $yday) === $dayEnd + 45 * 60, 'ora della scelta: 45 minuti dopo day_end');
$n = PhotoOfDay::run($now, $wS);
$pod = Db::one('SELECT * FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$wS, $yday]);
ok($n >= 1 && $pod !== null && (int) $pod['photo_id'] === (int) $best['photo_id'] && $pod['chosen_by'] === 'auto', 'foto buona con il punteggio più alto', json_encode($pod));
$r = http('GET', "$SS/webcams/cantiere-servizi/photo-of-day?date=$yday", $key($kSPub));
$soc = $r['json']['photo']['social'] ?? [];
ok($r['status'] === 200 && $r['json']['chosen_by'] === 'auto' && array_keys($soc) === ['square', 'portrait', 'story'], 'photo-of-day: foto e formati social', $r['body']);
foreach (['square' => [1080, 1080], 'portrait' => [1080, 1350], 'story' => [1080, 1920]] as $nm => [$sw, $sh]) {
	$g = http('GET', $L($soc[$nm] ?? ''));
	$sz = @getimagesizefromstring($g['body']);
	ok($g['status'] === 200 && $sz !== false && $sz[0] === $sw && $sz[1] === $sh, "formato social $nm {$sw}x{$sh}", json_encode($sz));
}
$r = http('GET', "$SS/webcams/cantiere-servizi/photo-of-day", $key($kSPub));
ok($r['json']['date'] === $yday, 'photo-of-day senza data: ieri se oggi non è ancora scelta');
$r = http('GET', "$SS/webcams/cantiere-servizi/photos-of-day?month=" . substr($yday, 0, 7), $key($kSPub));
ok($r['status'] === 200 && count($r['json']['days']) === 1 && !isset($r['json']['days'][0]['photo']['social']), 'photos-of-day: giorni del mese senza social');
$wRow = Db::one('SELECT * FROM webcams WHERE id = ?', [$wS]);
PhotoOfDay::choose($wRow, $pS[3], 'operator', null, $now);
ok(Db::one('SELECT photo_id, chosen_by FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$wS, $yday]) == ['photo_id' => $pS[3], 'chosen_by' => 'operator'], 'scelta a mano dell\'operatore');
PhotoOfDay::chooseAuto($wRow, $yday);
ok((int) Db::scalar('SELECT photo_id FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$wS, $yday]) === $pS[3], 'la scelta a mano non si sostituisce in automatico');
try {
	PhotoOfDay::choose($wRow, $pS[4], 'customer', null, $now + 3 * 86400);
	ok(false, 'scelta oltre le 23:59 del giorno dopo rifiutata');
} catch (InvalidArgumentException $e) {
	ok(true, 'scelta oltre le 23:59 del giorno dopo rifiutata');
}
ok((int) Db::scalar("SELECT COUNT(*) FROM telegram_messages WHERE alert_type = 'photo_of_day'") === 0 && Db::scalar('SELECT telegram_sent_at FROM photo_of_day WHERE webcam_id = ? AND day = ?', [$wS, $yday]) !== null, 'avviso photo_of_day segnato (nessuna chat iscritta ancora)');

// ------------------------------------------------------------ statistiche
section('Servizi: statistiche (M8)');
$tok = (string) (http('GET', "$SS/webcams/cantiere-servizi", $key($kSPub))['json']['webcam']['beacon']['token'] ?? '');
$ua = ['User-Agent: Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0', 'Origin: https://www.cantiere.example', 'Content-Type: text/plain'];
$b = http('POST', "$SS/views", $ua, "t=$tok&ev=view");
ok($b['status'] === 204 && $b['body'] === '' && ($b['headers']['access-control-allow-origin'] ?? '') === '*' && ($b['headers']['cache-control'] ?? '') === 'no-store', 'POST views: 204, CORS *, no-store', json_encode($b['headers']));
http('POST', "$SS/views", $ua, "t=$tok&ev=view");
http('POST', "$SS/views", $ua, "t=$tok&ev=leave&s=42");
http('POST', "$SS/views", $ua, "t=$tok&ev=play");
http('POST', "$SS/views", ['User-Agent: Googlebot/2.1'], "t=$tok&ev=view");
ok(http('POST', "$SS/views", $ua, 't=1.2.3.abcdefghijklmnopqrstuv&ev=view')['status'] === 204, 'token sbagliato: 204 lo stesso');
$g = http('GET', "$SS/views.gif?t=" . urlencode($tok) . '&ev=open', ['User-Agent: Mozilla/5.0 Safari']);
ok($g['status'] === 200 && ($g['headers']['content-type'] ?? '') === 'image/gif' && str_starts_with($g['body'], 'GIF89a'), 'views.gif: GIF trasparente');
$day = (new DateTimeImmutable('now', $rome))->format('Y-m-d');
$vs = Db::one("SELECT SUM(views) v, SUM(visitors) u, SUM(view_seconds) s, SUM(timelapse_plays) tp, SUM(photo_opens) po FROM view_stats_daily WHERE webcam_id = ? AND day = ? AND source = 'site'", [$wS, $day]);
ok($vs !== null && (int) $vs['v'] === 1 && (int) $vs['u'] >= 1 && (int) $vs['s'] === 42 && (int) $vs['tp'] === 1 && (int) $vs['po'] === 1, 'beacon: doppioni entro 30 minuti tolti, bot esclusi, secondi e play', json_encode($vs));
$dump = json_encode(Db::all('SELECT * FROM view_visitors')) . json_encode(Db::all('SELECT * FROM view_stats_daily')) . json_encode(Db::all('SELECT * FROM view_stats_hosts'));
ok(!str_contains($dump, '127.0.0') && !str_contains($dump, 'Mozilla'), 'nessun IP né User-Agent in chiaro nel database');
ok(Db::scalar('SELECT host FROM view_stats_hosts WHERE webcam_id = ? ORDER BY views DESC LIMIT 1', [$wS]) === 'www.cantiere.example', 'host della pagina dall\'Origin');
ok((int) Db::scalar("SELECT SUM(views) FROM view_stats_daily WHERE webcam_id = ? AND source = 'api'", [$wS]) > 0, 'richieste dell\'API contate (fonte api)');
http('GET', "$baseS/cam/cantiere-servizi/current.jpg", ['User-Agent: Windy bot']);
[$rc, $out] = $bin('stats.php');
ok($rc === 0 && (int) Db::scalar("SELECT SUM(views) FROM view_stats_daily WHERE webcam_id = ? AND source = 'image'", [$wS]) === 3, 'current.jpg contato anche dai bot e dai 304 (bin/stats.php)', $out);
$r = http('GET', "$SS/webcams/cantiere-servizi/stats?group=day", $key($kS));
ok($r['status'] === 200 && $r['json']['totals']['views'] === 1 && $r['json']['totals']['view_seconds'] === 42 && $r['json']['totals']['image_requests'] === 3 && $r['json']['hosts'][0]['host'] === 'www.cantiere.example', 'stats: totali, fonti, host', $r['body']);
ok(($r['headers']['cache-control'] ?? '') === 'private, no-store', 'stats: Cache-Control private, no-store');
ok(http('GET', "$SS/webcams/cantiere-servizi/stats", $key($kSPub))['json']['error'] === 'scope_client_required', 'stats con chiave public: 403 scope_client_required');
ok(http('GET', "$SS/webcams/cantiere-servizi/stats?from=2020-01-01&to=2026-01-01", $key($kS))['status'] === 400, 'stats: oltre 400 giorni 400');

// ------------------------------------------------------------ registro
section('Servizi: registro delle impronte (O2)');
$regNow = max(time(), (new DateTimeImmutable('today 01:45', $rome))->getTimestamp());
$reg = new Register($regNow);
$st = $reg->run($wS);
$row = Db::one('SELECT * FROM daily_register WHERE webcam_id = ? AND day = ?', [$wS, $yday]);
$man = $row ? "$tmp/storage/" . $row['manifest_path'] : '';
ok($row !== null && $row['sealed_at'] !== null && (int) $row['photos_total'] === 31 && is_file($man) && hash_file('sha256', $man) === $row['day_hash'], 'giorno sigillato: elenco e day_hash = sha256 del file', json_encode($st) . json_encode($row));
$lines = file($man, FILE_IGNORE_NEW_LINES) ?: [];
ok(str_starts_with($lines[0] ?? '', 'vlreg1') && count($lines) === 32 && str_contains((string) file_get_contents($man), (string) Db::scalar('SELECT sha256 FROM photos WHERE id = ?', [$pS[0]])), 'elenco vlreg1 con lo SHA-256 di ogni foto', $lines[0] ?? '');
$prev = Db::one('SELECT day_hash FROM daily_register WHERE webcam_id = ? AND day = ?', [$wS, (new DateTimeImmutable($yday))->modify('-1 day')->format('Y-m-d')]);
ok($prev !== null && $row['prev_day_hash'] === $prev['day_hash'] && $prev['day_hash'] !== null, 'catena: prev_day_hash = day_hash del giorno prima (anche senza foto)');
ok($row['classification'] === 'bad_weather' && str_contains((string) $row['reasons'], 'wet') && (int) $row['wet_hours'] >= 4 && $row['work_hours'] === '10:00-15:00', 'classificazione: giornata di maltempo (umido) nelle ore del contratto', json_encode(array_intersect_key($row, array_flip(['classification', 'reasons', 'wet_hours', 'samples_ok', 'hours_with_photo', 'not_cert_reason']))));
ok($row['dolibarr_registered_at'] !== null, 'hash depositato in Dolibarr (POST register)');
$regCalls = array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($dlog14, FILE_IGNORE_NEW_LINES) ?: []), static fn ($c) => $c['path'] === '/webcams/12/register'));
$sent = array_column($regCalls[0]['body']['days'] ?? [], 'day_hash', 'day');
ok(count($regCalls) === 1 && ($sent[$yday] ?? '') === $row['day_hash'], 'POST webcams/12/register con i giorni sigillati');
// foto arrivata dopo il sigillo: tardiva, day_hash invariato
Db::exec('UPDATE daily_register SET sealed_at = UTC_TIMESTAMP() WHERE webcam_id = ?', [$wS]);
sleep(1);
$jl = $texture(77);
sendPhoto($base, $tS, $jl, photoMeta($jl, $y0 + 125, $macS));
$reg2 = new Register($regNow + 3600);
$reg2->run($wS);
$row2 = Db::one('SELECT day_hash, late_photos FROM daily_register WHERE webcam_id = ? AND day = ?', [$wS, $yday]);
ok((int) $row2['late_photos'] === 1 && $row2['day_hash'] === $row['day_hash'] && is_file(substr($man, 0, -4) . '.late.txt'), 'foto tardiva: late_photos e .late.txt, day_hash invariato');
ok(count(array_filter(array_map(static fn ($l) => json_decode($l, true), file($dlog14, FILE_IGNORE_NEW_LINES) ?: []), static fn ($c) => $c['path'] === '/webcams/12/register')) === 1, 'nessun secondo deposito per i giorni già depositati');
ok(Register::verify($wS) === [], 'verifica: elenchi uguali al day_hash');
file_put_contents($man, "\n", FILE_APPEND);
$bad = Register::verify($wS);
ok(count($bad) === 1 && $bad[0]['problem'] === 'mismatch' && $bad[0]['day'] === $yday, 'verifica: elenco cambiato trovato');
file_put_contents($man, substr((string) file_get_contents($man), 0, -1));
$r = http('GET', "$SS/webcams/cantiere-servizi/weather-days?month=" . substr($yday, 0, 7), $key($kS));
$wd = array_values(array_filter($r['json']['days'] ?? [], static fn ($d) => $d['date'] === $yday))[0] ?? null;
ok($r['status'] === 200 && $wd !== null && $wd['classification'] === 'bad_weather' && $wd['day_hash'] === $row['day_hash'] && $wd['registered_at'] !== null && $wd['workday'] === true, 'weather-days: giorni sigillati del cliente', $r['body']);
$mf = http('GET', $L($wd['manifest_url'] ?? ''));
ok($mf['status'] === 200 && hash('sha256', $mf['body']) === $row['day_hash'] && str_contains($mf['headers']['content-disposition'] ?? '', "registro-cantiere-servizi-$yday.txt") && ($mf['headers']['cache-control'] ?? '') === 'private, no-store', '/media/reg: elenco da verificare con sha256sum', json_encode($mf['headers']));
ok(http('GET', "$SS/webcams/cantiere-servizi/weather-days", $key($kSPub))['status'] === 403, 'weather-days con chiave public: 403');

// ------------------------------------------------------------ rapporto e certificato
section('Servizi: rapporto mensile (O3) e certificato di maltempo (O2)');
$month = substr($yday, 0, 7);
$repNow = (new DateTimeImmutable($month . '-01 00:00:00', $rome))->modify('+1 month')->modify('+2 days')->setTime(7, 0)->getTimestamp();
@unlink($dlog14);
$rep = new Reports($repNow);
$rep->schedule($wS);
$rep->process();
$mr = Db::one('SELECT * FROM monthly_reports WHERE webcam_id = ? AND month = ?', [$wS, $month]);
$pdfFile = $mr && $mr['storage_path'] ? "$tmp/storage/" . $mr['storage_path'] : '';
ok($mr !== null && $mr['status'] === 'emailed' && is_file($pdfFile) && str_starts_with((string) file_get_contents($pdfFile, false, null, 0, 8), '%PDF-1.4') && hash_file('sha256', $pdfFile) === $mr['sha256'], 'rapporto: PDF generato, allegato e mandato per email da Dolibarr', json_encode($rep->stats) . json_encode(array_intersect_key((array) $mr, array_flip(['status', 'status_reason', 'pages', 'email_to']))));
$txt = [];
exec('pdftotext -layout ' . escapeshellarg($pdfFile) . ' - 2>&1', $txt, $prc);
$txt = implode("\n", $txt);
ok($prc === 0 && str_contains($txt, 'Cantiere Servizi') && str_contains($txt, Reports::monthLabel($month)) && (int) $mr['pages'] >= 2, 'pdftotext apre il rapporto: nome della webcam e mese', substr($txt, 0, 600));
ok(str_contains($txt, 'Dati dei log non disponibili') || str_contains($txt, 'Dati dei log mancanti'), 'rapporto senza logserver: dice quali dati mancano', substr($txt, 0, 2000));
ok(!str_contains($txt, 'upload_falliti') && !preg_match('/\bupload falliti\b/i', $txt), 'conteggi FTP dei log etichettati come invii FTP (non tutti gli upload)');
$docs = array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($dlog14, FILE_IGNORE_NEW_LINES) ?: []), static fn ($c) => $c['path'] === '/webcams/12/documents'));
$repDoc = array_values(array_filter($docs, static fn ($c) => ($c['body']['doc_type'] ?? '') === 'monthly_report'))[0] ?? null;
ok($repDoc !== null && $repDoc['body']['filename'] === "rapporto-$month.pdf" && $repDoc['body']['period'] === $month && $repDoc['body']['portal_doc_id'] === 'report:' . $mr['id']
	&& ($repDoc['body']['send_email']['template'] ?? '') === 'report' && $repDoc['body']['sha256'] === $mr['sha256'], 'POST documents: doc_type, period, portal_doc_id, send_email', json_encode(array_diff_key($repDoc['body'] ?? [], ['filecontent' => 1])));
$wc = Db::one('SELECT * FROM weather_certificates WHERE webcam_id = ? AND kind = ? AND period_from LIKE ?', [$wS, 'month', $month . '%']);
$cf = $wc && $wc['storage_path'] ? "$tmp/storage/" . $wc['storage_path'] : '';
$ctxt = [];
exec('pdftotext -layout ' . escapeshellarg($cf) . ' - 2>&1', $ctxt, $crc);
$ctxt = implode("\n", $ctxt);
ok($wc !== null && in_array($wc['status'], ['sent', 'emailed'], true) && (int) $wc['days_bad'] >= 1 && $crc === 0 && str_contains(strtolower($ctxt), 'maltempo') && str_contains($ctxt, substr((string) $row['day_hash'], 0, 16)), 'certificato: PDF con le giornate di maltempo e il day_hash', json_encode(array_intersect_key((array) $wc, array_flip(['status', 'status_reason', 'days_bad', 'days_total']))) . substr($ctxt, 0, 500));
$certDoc = array_values(array_filter($docs, static fn ($c) => ($c['body']['doc_type'] ?? '') === 'weather_certificate'))[0] ?? null;
ok($certDoc !== null && $certDoc['body']['filename'] === "maltempo-$month.pdf" && ($certDoc['body']['send_email']['template'] ?? '') === 'weather', 'certificato in Dolibarr: maltempo-AAAA-MM.pdf, modello weather', json_encode(array_diff_key($certDoc['body'] ?? [], ['filecontent' => 1])));
$certPhotos = PhotoRetention::certified($wS);
ok($certPhotos !== [] && array_intersect($certPhotos, $pS) === $certPhotos, 'foto mostrate nel certificato registrate (non cancellate dalla retention)', json_encode($certPhotos));
$rep2 = new Reports($repNow);
$rep2->regenerate('report', (int) $mr['id']);
$mr2 = Db::one('SELECT status, emailed_at FROM monthly_reports WHERE id = ?', [$mr['id']]);
$last = array_map(static fn ($l) => json_decode($l, true), file($dlog14, FILE_IGNORE_NEW_LINES) ?: []);
$lastDoc = end($last);
ok($mr2['status'] === 'emailed' && ($lastDoc['body']['overwrite'] ?? 0) === 1 && !isset($lastDoc['body']['send_email']), 'rigenerazione: overwrite 1, nessuna seconda email', json_encode(array_diff_key($lastDoc['body'] ?? [], ['filecontent' => 1])));
$rep3 = new Reports($repNow);
$rep3->regenerate('report', (int) $mr['id'], true);
ok(Db::scalar('SELECT status FROM monthly_reports WHERE id = ?', [$mr['id']]) === 'emailed', 'email già mandata (sent con duplicate): resta emailed');
$r = http('GET', "$SS/webcams/cantiere-servizi/reports?year=" . substr($month, 0, 4), $key($kS));
$rp = $r['json']['reports'][0] ?? [];
ok($r['status'] === 200 && ($rp['id'] ?? 0) === (int) $mr['id'] && $rp['emailed'] !== null && array_key_exists('availability_pct', $rp['summary']) && $rp['summary']['photos'] >= 31, 'reports: rapporto del cliente con summary', $r['body']);
$pdf = http('GET', $L($rp['pdf_url'] ?? ''));
ok($pdf['status'] === 200 && ($pdf['headers']['content-type'] ?? '') === 'application/pdf' && str_contains($pdf['headers']['content-disposition'] ?? '', "rapporto-cantiere-servizi-$month.pdf") && hash('sha256', $pdf['body']) === Db::scalar('SELECT sha256 FROM monthly_reports WHERE id = ?', [$mr['id']]), '/media/rep: PDF con il nome del contratto', json_encode($pdf['headers']));
parse_str((string) parse_url((string) $rp['pdf_url'], PHP_URL_QUERY), $q);
ok((int) $q['e'] % 3600 === 0 && (int) $q['e'] - time() <= 86400 + 3600, 'URL dei documenti: 1 giorno, arrotondato all\'ora');
$r = http('GET', "$SS/webcams/cantiere-servizi/certificates?year=" . substr($month, 0, 4), $key($kS));
$cc = $r['json']['certificates'][0] ?? [];
ok($r['status'] === 200 && ($cc['id'] ?? 0) === (int) $wc['id'] && str_contains(http('GET', $L($cc['pdf_url'] ?? ''))['headers']['content-disposition'] ?? '', "maltempo-cantiere-servizi-$month.pdf"), 'certificates e /media/cert', $r['body']);
ok(http('GET', "$SS/webcams/cantiere-servizi/reports", $key($kSPub))['json']['error'] === 'scope_client_required', 'reports con chiave public: 403');
$customCert = Reports::requestCertificate($wS, $cNew, $yday, $yday);
(new Reports($repNow))->process($customCert, 'certificate');
ok(in_array(Db::scalar('SELECT status FROM weather_certificates WHERE id = ?', [$customCert]), ['sent', 'emailed'], true), 'certificato per un periodo scelto (custom)');

// ------------------------------------------------------------ Telegram
section('Servizi: bot Telegram (O10)');
$hook = static function (array $update, ?string $secret) use ($baseS): array {
	$h = ['Content-Type: application/json'];
	if ($secret !== null) {
		$h[] = "X-Telegram-Bot-Api-Secret-Token: $secret";
	}
	return http('POST', "$baseS/telegram/webhook", $h, (string) json_encode($update));
};
$msg = static fn (int $chat, string $text, int $uid = 1) => ['update_id' => $uid, 'message' => ['message_id' => $uid, 'date' => time(), 'text' => $text,
	'chat' => ['id' => $chat, 'type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'username' => 'mrossi'], 'from' => ['id' => $chat, 'language_code' => 'it']]];
$tgCalls = static function () use ($tgLog): array {
	return array_map(static fn ($l) => json_decode($l, true), is_file($tgLog) ? (file($tgLog, FILE_IGNORE_NEW_LINES) ?: []) : []);
};
ok($hook($msg(555, '/start'), 'segreto-sbagliato')['status'] === 403 && $hook($msg(555, '/start'), null)['status'] === 403, 'webhook: segreto sbagliato o assente 403');
ok($tgCalls() === [], 'webhook rifiutato: nessuna risposta mandata');
$code = Bot::createCode($cNew, null, 'geom. Bianchi');
ok(preg_match('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/', $code['code']) === 1 && Db::scalar('SELECT code_hash FROM telegram_pair_codes WHERE id = ?', [$code['id']]) === hash('sha256', str_replace('-', '', $code['code'])), 'codice di abbinamento: 10 caratteri, nel database solo lo SHA-256');
$r = $hook($msg(555, '/iscrivi ' . strtolower($code['code'])), $hookSecret);
$chat = Db::one("SELECT * FROM telegram_chats WHERE chat_id = 555 AND status = 'active'");
ok($r['status'] === 200 && $chat !== null && (int) $chat['customer_id'] === $cNew && (int) Db::scalar('SELECT COUNT(*) FROM telegram_subscriptions WHERE chat_ref = ? AND webcam_id = ?', [$chat['id'], $wS]) === 1, '/iscrivi: chat abbinata e iscritta alle webcam del cliente', $r['body']);
$calls = $tgCalls();
ok(count($calls) >= 1 && $calls[0]['token_ok'] && $calls[0]['method'] === 'sendMessage' && str_contains((string) $calls[0]['params']['text'], 'Collegato') && str_contains((string) $calls[0]['params']['text'], 'Cantiere Servizi'), 'risposta "Collegato!" con le webcam', json_encode($calls));
$hook($msg(556, '/iscrivi ' . $code['code'], 2), $hookSecret);
ok(Db::one("SELECT id FROM telegram_chats WHERE chat_id = 556") === null, 'codice già usato: rifiutato');
for ($i = 0; $i < 5; $i++) {
	$hook($msg(557, '/iscrivi AAAAA-BBBBB', 10 + $i), $hookSecret);
}
$code2 = Bot::createCode($cNew, $wS, 'secondo');
$hook($msg(557, '/iscrivi ' . $code2['code'], 20), $hookSecret);
ok(Db::one("SELECT id FROM telegram_chats WHERE chat_id = 557") === null && str_contains((string) end($tgCalls())['params']['text'], 'Troppi tentativi'), 'dopo 5 codici sbagliati in un\'ora anche quello giusto è rifiutato');
@unlink($tgLog);
$hook($msg(555, '/foto', 3), $hookSecret);
$calls = $tgCalls();
$ph = array_values(array_filter($calls, static fn ($c) => $c['method'] === 'sendPhoto'))[0] ?? null;
ok($ph !== null && ($ph['files']['photo']['type'] ?? '') === 'image/jpeg' && str_contains((string) $ph['params']['caption'], 'Cantiere Servizi') && (string) $ph['params']['chat_id'] === '555', '/foto: foto con didascalia', json_encode($calls));
ok((int) Db::scalar("SELECT SUM(views) FROM view_stats_daily WHERE webcam_id = ? AND source = 'telegram'", [$wS]) >= 1, '/foto contato nelle statistiche (fonte telegram)');
$hook($msg(555, '/foto', 4), $hookSecret);
ok(str_contains((string) end($tgCalls())['params']['text'], 'Una foto al minuto'), '/foto: al più una al minuto');
@unlink($tgLog);
$hook($msg(555, '/meteo', 5), $hookSecret);
$mt = end($tgCalls());
ok($mt && $mt['method'] === 'sendMessage' && str_contains((string) $mt['params']['text'], 'Cantiere Servizi'), '/meteo: risposta con le ultime misure', json_encode($mt));
// avvisi: webcam ferma e di nuovo attiva (camera.stalled), una volta sola
Db::exec("INSERT INTO alerts (webcam_id, rule_id, severity, status, title, opened_at, reopen_count) VALUES (?, 'camera.stalled', 'error', 'open', 'ferma', UTC_TIMESTAMP(), 0)", [$wS]);
$aid = (int) Db::pdo()->lastInsertId();
@unlink($tgLog);
\WebcamPortal\Telegram\Notify::alert($aid, true);
\WebcamPortal\Telegram\Notify::alert($aid, true);
$s = Bot::sendQueued(null, time());
$calls = $tgCalls();
ok($s['sent'] === 1 && count($calls) === 1 && str_contains((string) $calls[0]['params']['text'], 'non manda foto') && !preg_match('/stalled|errore|MAC|WC-/i', (string) $calls[0]['params']['text']), 'avviso offline: una volta sola, senza dettagli tecnici', json_encode($s) . json_encode($calls));
\WebcamPortal\Telegram\Notify::alert($aid, false);
Bot::sendQueued(null, time());
ok(str_contains((string) end($tgCalls())['params']['text'], 'di nuovo attiva'), 'avviso online quando si chiude');
\WebcamPortal\Telegram\Notify::photoOfDay(Db::one('SELECT * FROM webcams WHERE id = ?', [$wS]), $yday, $pS[3]);
Bot::sendQueued(null, time());
ok(end($tgCalls())['method'] === 'sendPhoto' && str_contains((string) end($tgCalls())['params']['caption'], 'Foto del giorno'), 'avviso foto del giorno con la foto');
// chat che ha bloccato il bot (403): diventa blocked
Db::exec("INSERT INTO telegram_chats (chat_id, chat_type, customer_id) VALUES (403, 'private', ?)", [$cNew]);
$ref403 = (int) Db::pdo()->lastInsertId();
Db::exec('INSERT INTO telegram_subscriptions (chat_ref, webcam_id) VALUES (?, ?)', [$ref403, $wS]);
Bot::queue($ref403, $wS, 'alert', 'prova', null, 'offline', 'prova403');
Bot::sendQueued($ref403, time());
ok(Db::scalar('SELECT status FROM telegram_chats WHERE id = ?', [$ref403]) === 'blocked' && (int) Db::scalar('SELECT COUNT(*) FROM telegram_subscriptions WHERE chat_ref = ?', [$ref403]) === 0, '403 di Telegram: chat blocked, iscrizioni tolte');
$hook($msg(555, '/stop', 6), $hookSecret);
$ch = Db::one('SELECT status, display_name, username FROM telegram_chats WHERE id = ?', [$chat['id']]);
ok($ch['status'] === 'stopped' && $ch['display_name'] === null && $ch['username'] === null && (int) Db::scalar('SELECT COUNT(*) FROM telegram_subscriptions WHERE chat_ref = ?', [$chat['id']]) === 0, '/stop: iscrizioni cancellate, nome e username tolti');
$logs = '';
foreach (glob("$tmp/storage/logs/*.log") ?: [] as $f) {
	$logs .= (string) file_get_contents($f);
}
ok(!str_contains($logs, $botToken) && !str_contains($logs, $hookSecret), 'token del bot e segreto del webhook mai nei log');
[$rc, $out] = $bin('telegram.php', '--set-webhook https://portal.example/telegram/webhook');
$sw = end($tgCalls());
ok($rc === 0 && $sw['method'] === 'setWebhook' && $sw['params']['secret_token'] === $hookSecret && $sw['params']['url'] === 'https://portal.example/telegram/webhook', 'bin/telegram.php --set-webhook con secret_token', $out);
[$rc, $out] = $bin('telegram.php', '--send');
ok($rc === 0, 'bin/telegram.php --send', $out);
[$rc, $out] = $bin('telegram.php', '', 'config.php');
ok($rc === 0 && str_contains($out, 'Bot Telegram spento'), 'bin/telegram.php senza token: spento, uscita 0', $out);

// ------------------------------------------------------------ retention dei servizi
section('Servizi: retention');
Db::exec('UPDATE timelapses SET expires_at = ? WHERE id = ?', [Db::dt($now - 60), $tlP]);
$posterP = "$tmp/storage/" . Db::scalar('SELECT poster_path FROM timelapses WHERE id = ?', [$tlP]);
ok(Timelapse::expire($now) === 1 && Db::scalar('SELECT status FROM timelapses WHERE id = ?', [$tlP]) === 'expired' && !is_file($posterP), 'timelapse scaduto: file tolti, status expired');
ok(http('GET', Media::url($baseS, 'tl', $tlP, 'mp4'))['status'] === 404, 'timelapse scaduto: /media 404');
Overlay::deleteAsset($logoId);
Db::exec('UPDATE overlay_assets SET deleted_at = ? WHERE id = ?', [Db::dt($now - 31 * 86400), $logoId]);
ok(Overlay::pruneAssets($now) === 1 && !is_file("$tmp/storage/" . $asset['storage_path']), 'logo cancellato da più di 30 giorni: file tolto');
$certPid = $certPhotos[0];
Db::exec('UPDATE photos SET taken_at = taken_at - INTERVAL 200 DAY WHERE webcam_id = ? AND id IN (?, ?)', [$wS, $certPid, $certPid === $pS[0] ? $pS[1] : $pS[0]]);
PhotoRetention::run(90, false, null, $now);
ok(Db::scalar('SELECT deleted_at FROM photos WHERE id = ?', [$certPid]) === null, 'retention: la foto del certificato resta');

// ------------------------------------------------------------ pannello e script
section('Servizi: pannello e script');
$r = $page('GET', "/admin/webcams/$wS/services");
ok($r['status'] === 200 && str_contains($r['body'], 'Sovrimpressione') && str_contains($r['body'], 'Rifugio Alpe') && str_contains($r['body'], 'Timelapse') && str_contains($r['body'], 'Registro delle impronte'), 'pannello: pagina dei servizi della webcam', substr($r['body'], 0, 300));
$csrfS = $r['csrf'];
$r = $page('POST', "/admin/webcams/$wS/services/items", ['_csrf' => $csrfS, 'kind' => 'infopoint', 'label' => 'Dal pannello', 'x_pct' => '10', 'y_pct' => '20']);
ok($r['status'] === 303 && (int) Db::scalar("SELECT COUNT(*) FROM overlay_items WHERE webcam_id = ? AND label = 'Dal pannello'", [$wS]) === 1, 'pannello: nuovo infopoint');
$r = $page('POST', "/admin/webcams/$wS/services/overlay", ['_csrf' => $csrfS, 'enabled' => '1', 'fields' => ['datetime', 'name'], 'apply_to' => ['640', 'current'], 'current_size' => '1920', 'bar_position' => 'top']);
$oc = Db::one('SELECT fields, apply_to, bar_position, current_size FROM overlay_config WHERE webcam_id = ?', [$wS]);
ok($r['status'] === 303 && $oc['fields'] === 'datetime,name' && $oc['apply_to'] === '640,current' && $oc['bar_position'] === 'top', 'pannello: sovrimpressione salvata', json_encode($oc));
$r = $page('POST', "/admin/webcams/$wS/services/plan", ['_csrf' => $csrfS, 'plan_level' => 'base']);
ok(Db::scalar('SELECT plan_level FROM webcam_service WHERE webcam_id = ?', [$wS]) === 'pro', 'pannello: listino da Dolibarr non modificabile');
$r = $page('GET', '/admin/reports');
ok($r['status'] === 200 && str_contains($r['body'], 'Rapporti mensili') && str_contains($r['body'], $month), 'pannello: rapporti e certificati');
$r = $page('GET', "/admin/reports/report/{$mr['id']}/download");
ok($r['status'] === 302 && str_contains($r['location'], '/media/rep/' . $mr['id'] . '.pdf?e='), 'pannello: scarica il PDF con un URL firmato', $r['location']);
$r = $page('GET', '/admin/telegram');
ok($r['status'] === 200 && str_contains($r['body'], 'Bot Telegram') && str_contains($r['body'], 'geom. Bianchi'), 'pannello: pagina Telegram con i codici');
$r = $page('POST', '/admin/telegram', ['_csrf' => $csrfS, 'customer_id' => $cNew, 'label' => 'dal pannello']);
ok($r['status'] === 200 && preg_match('/[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}/', $r['body']) === 1, 'pannello: nuovo codice mostrato una volta');
foreach ([['photo-scores.php', ''], ['register.php', '--verifica'], ['photo-of-day.php', ''], ['reports.php', ''], ['timelapse.php', '--stato'], ['retention-photos.php', '--prova']] as [$s, $a]) {
	[$rc, $out] = $bin($s, $a);
	ok($rc === 0, "bin/$s $a: uscita 0", $out);
}
$useCfg("$tmp/config.php");
