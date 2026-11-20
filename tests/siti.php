<?php
/**
 * Test dell'API in sola lettura per i siti (api-siti-v1.md), incluso da
 * tests/run.php con le stesse variabili: clienti e storico delle
 * assegnazioni, chiavi (scope, revoca, scadenza, limite), CORS, tutti gli
 * endpoint, URL firmati delle immagini, cache delle riduzioni e memoria,
 * pannello, riga di comando e import dei clienti da Dolibarr.
 */

declare(strict_types=1);

use WebcamPortal\Db;
use WebcamPortal\Service\Customers;
use WebcamPortal\Service\SiteKeys;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;
use WebcamPortal\Site\Images;

section('API dei siti: dati di prova');
$S = "$base/api/site/v1";
$key = static fn (string $k, ?string $origin = null): array => array_values(array_filter(["Authorization: Bearer $k", $origin !== null ? "Origin: $origin" : null]));
$rome = new DateTimeZone('Europe/Rome');
$now = time();
$today = (new DateTimeImmutable('now', $rome))->format('Y-m-d');
$todayStart = (new DateTimeImmutable('today', $rome))->getTimestamp();
// ora locale di oggi già passata (almeno 2 ore fa) per foto e misure
$t0 = max($todayStart + 600, $now - 3 * 3600);

$cA = Customers::create('Hotel Alpi', ['code' => 'CU-A', 'email' => 'info@hotel.example']);
$cB = Customers::create('Comune di Prova');
$nMk = 0;
$mk = static function (string $slug, bool $public, ?int $customer, int $since) use (&$nMk) {
	$nMk++;
	$id = Webcams::create('94:83:c4:30:00:' . sprintf('%02x', $nMk), ucfirst(str_replace('-', ' ', $slug)), $slug, ['is_public' => $public, 'location' => 'Località ' . $slug]);
	Db::exec('UPDATE webcams SET latitude = 46.123456, longitude = 11.5, altitude_m = 1500, interval_min = 10, last_seen_at = UTC_TIMESTAMP(), hostname = ? WHERE id = ?', ["WC-SEGRETO-$id", $id]);
	if ($customer !== null) {
		Customers::assign($id, $customer, 'portal', null, $since);
	}
	return [$id, Tokens::create($id)];
};
[$wPubA, $tPubA] = $mk('alpi-lago', true, $cA, $now - 10 * 86400);
[$wPrivA, $tPrivA] = $mk('alpi-cantiere', false, $cA, $todayStart + 60);
[$wPubB, $tPubB] = $mk('comune-piazza', true, $cB, $now - 10 * 86400);
[$wPubN, $tPubN] = $mk('viewlapse-vetta', true, null, 0);
$macPrivA = (string) Db::scalar('SELECT mac FROM webcams WHERE id = ?', [$wPrivA]);
$macPubA = (string) Db::scalar('SELECT mac FROM webcams WHERE id = ?', [$wPubA]);
ok((int) Db::scalar('SELECT COUNT(*) FROM customer_webcams WHERE customer_id = ? AND valid_to IS NULL', [$cA]) === 2, 'assegnazioni aperte nello storico');

// foto grande (2400x1600) per le riduzioni; una scattata prima dell'assegnazione
$big = static function (int $seed, int $w = 2400, int $h = 1600): string {
	$im = imagecreatetruecolor($w, $h);
	imagefill($im, 0, 0, imagecolorallocate($im, 40 + $seed * 20 % 200, 120, 180));
	imagefilledrectangle($im, (int) ($w / 4), (int) ($h / 4), (int) ($w / 2), (int) ($h / 2), imagecolorallocate($im, 250, 250, 250));
	ob_start();
	imagejpeg($im, null, 70);
	return (string) ob_get_clean();
};
$photoAt = [];
$send = static function (string $tok, string $mac, int $t, int $seed, array $over = []) use ($base, $big, &$photoAt): int {
	$j = $big($seed);
	$r = sendPhoto($base, $tok, $j, photoMeta($j, $t, $mac, $over));
	if (!in_array($r['status'], [200, 201], true)) {
		ok(false, "upload di prova $t", $r['body']);
	}
	return (int) ($r['json']['photo_id'] ?? 0);
};
$pBefore = $send($tPrivA, $macPrivA, $todayStart + 30, 1); // 30 s dopo mezzanotte: prima dell'assegnazione (+60 s)
$pA = [];
for ($i = 0; $i < 4; $i++) {
	$pA[] = $send($tPrivA, $macPrivA, $t0 + $i * 1200, 2 + $i, ['climate' => ['temperature' => 5.0, 'humidity' => 70.0, 'temperature_ext' => 3.5 + $i, 'humidity_ext' => 80.0]]);
}
$pPub = $send($tPubA, $macPubA, $t0, 9);
$pHidden = $send($tPubA, $macPubA, $t0 + 600, 10);
Db::exec('UPDATE photos SET is_public = 0 WHERE id = ?', [$pHidden]);
ok($pBefore > 0 && count(array_filter($pA)) === 4 && $pPub > 0, 'foto caricate con /api/v1/photo');

// misure esterne: 3 ore a 5 minuti, con la temperatura che sale
$samples = [];
for ($k = 0; $k < 36; $k++) {
	$samples[] = sample(sprintf('a1b2c3d4-%d', $k + 1), $t0 - 3600 + $k * 300, ['climate' => ['temperature' => 6.0, 'humidity' => 60.0, 'temperature_ext' => 2.0 + $k * 0.1, 'humidity_ext' => 90.0 - $k * 0.5, 'heater' => false]]);
}
$r = postJson($base, '/api/v1/telemetry', $tPrivA, ['schema' => 'telemetry/1', 'mac' => $macPrivA, 'boot_id' => 'a1b2c3d4', 'sent_at' => $now, 'time_synced' => true, 'samples' => $samples]);
ok($r['status'] === 200 && ($r['json']['accepted'] ?? 0) === 36, 'telemetria di prova', $r['body']);

$kAll = SiteKeys::create(null, 'public', '*', 'tutte le pubbliche')['key'];
$kPubA = SiteKeys::create($cA, 'public', "https://www.hotel-alpi.example\nhttps://*.hotel-alpi.example", 'sito hotel')['key'];
$kCliA = SiteKeys::create($cA, 'client', '', 'portale clienti')['key'];
$kCliB = SiteKeys::create($cB, 'client', [], 'comune')['key'];
$rev = SiteKeys::create($cA, 'public', '', 'da revocare');
SiteKeys::revoke($rev['id']);
$exp = SiteKeys::create($cA, 'public', '', 'scaduta', null, null, $now - 5);
$slow = SiteKeys::create($cB, 'public', '', 'lenta', null, 3);
ok(preg_match(SiteKeys::PATTERN, $kCliA) === 1 && strlen($kCliA) === 47, 'chiave vls_ di 47 caratteri');
ok(Db::scalar('SELECT COUNT(*) FROM site_keys WHERE key_hash = ?', [SiteKeys::hash($kCliA)]) == 1
	&& !str_contains((string) json_encode(Db::all('SELECT * FROM site_keys')), $kCliA), 'nel database solo lo SHA-256 della chiave');
foreach ([[null, 'client', ''], [$cA, 'admin', ''], [$cA, 'client', '*'], [$cA, 'public', 'https://x.example/percorso'], [$cA, 'public', 'ftp://x.example']] as [$c, $sc, $o]) {
	try {
		SiteKeys::create($c, $sc, $o);
		ok(false, "chiave non valida rifiutata ($sc, $o)");
	} catch (InvalidArgumentException $e) {
		ok(true, "chiave non valida rifiutata ($sc, " . ($o ?: 'senza origini') . ', ' . ($c === null ? 'senza cliente' : 'cliente') . ')');
	}
}
ok(SiteKeys::originAllowed('https://shop.hotel-alpi.example', ['https://*.hotel-alpi.example'])
	&& !SiteKeys::originAllowed('https://hotel-alpi.example', ['https://*.hotel-alpi.example'])
	&& !SiteKeys::originAllowed('https://evilhotel-alpi.example', ['https://*.hotel-alpi.example'])
	&& !SiteKeys::originAllowed('http://shop.hotel-alpi.example', ['https://*.hotel-alpi.example'])
	&& SiteKeys::originAllowed('http://localhost:8080', ['http://localhost:8080']), 'origini: esatte e con *. solo per i sottodomini');

$bodies = [];
$get = static function (string $url, array $h = []) use (&$bodies): array {
	$r = http('GET', $url, $h);
	$bodies[] = $r['body'];
	return $r;
};

// ------------------------------------------------------------ chiavi e scope
section('API dei siti: chiavi, scope, CORS');
$slugs = static fn (array $r): array => array_column($r['json']['webcams'] ?? [], 'slug');
$r = $get("$S/webcams");
$pubs = array_column(Db::all('SELECT slug FROM webcams WHERE is_public = 1 AND status = 1'), 'slug');
ok($r['status'] === 200 && $r['json']['scope'] === 'anon' && $r['json']['customer'] === null, 'senza chiave: 200, scope anon', $r['body']);
ok(array_diff($pubs, $slugs($r)) === [] && array_diff($slugs($r), $pubs) === [] && !in_array('alpi-cantiere', $slugs($r), true), 'senza chiave: tutte e sole le webcam pubbliche', json_encode($slugs($r)));
ok(str_starts_with((string) ($r['headers']['cache-control'] ?? ''), 'public, max-age=') && str_contains((string) ($r['headers']['vary'] ?? ''), 'Origin'), 'senza chiave: Cache-Control public e Vary: Origin');
$r = $get("$S/webcams", ['Origin: https://qualsiasi.example']);
ok(($r['headers']['access-control-allow-origin'] ?? '') === '*', 'senza chiave dal browser: Access-Control-Allow-Origin *');
$r = $get("$S/webcams", $key($kAll));
ok($r['status'] === 200 && $r['json']['scope'] === 'public' && array_diff($pubs, $slugs($r)) === [], 'chiave public senza cliente: tutte le pubbliche');
$r = $get("$S/webcams", $key($kPubA, 'https://www.hotel-alpi.example'));
ok($r['status'] === 200 && $slugs($r) === ['alpi-lago'] && $r['json']['customer']['name'] === 'Hotel Alpi', 'chiave public del cliente: solo le sue pubbliche', $r['body']);
ok(($r['headers']['access-control-allow-origin'] ?? '') === 'https://www.hotel-alpi.example' && str_starts_with((string) ($r['headers']['cache-control'] ?? ''), 'private'), 'CORS: origine della chiave riflessa, Cache-Control private');
$r = $get("$S/webcams", $key($kPubA, 'https://prenota.hotel-alpi.example'));
ok($r['status'] === 200, 'CORS: sottodominio ammesso da https://*.');
$r = $get("$S/webcams", $key($kPubA, 'https://altro-sito.example'));
ok($r['status'] === 403 && $r['json']['error'] === 'origin_denied' && !isset($r['headers']['access-control-allow-origin']), 'CORS: origine non ammessa 403 senza Allow-Origin', $r['body']);
$r = $get("$S/webcams", $key($kCliA, 'https://www.hotel-alpi.example'));
ok($r['status'] === 403 && $r['json']['error'] === 'origin_denied', 'chiave client senza origini: dal browser 403');
$r = $get("$S/webcams", $key($kCliA));
$sl = $slugs($r);
sort($sl);
ok($r['status'] === 200 && $r['json']['scope'] === 'client' && $sl === ['alpi-cantiere', 'alpi-lago'], 'chiave client: tutte le webcam del cliente, anche private', json_encode($sl));
$r = $get("$S/webcams", $key($kCliB));
ok($slugs($r) === ['comune-piazza'], 'chiave client di un altro cliente: solo le sue');
$opt = http('OPTIONS', "$S/webcams", ['Origin: https://www.hotel-alpi.example', 'Access-Control-Request-Method: GET', 'Access-Control-Request-Headers: authorization']);
ok($opt['status'] === 204 && ($opt['headers']['access-control-allow-origin'] ?? '') === 'https://www.hotel-alpi.example'
	&& stripos((string) ($opt['headers']['access-control-allow-headers'] ?? ''), 'authorization') !== false, 'richiesta preliminare OPTIONS: 204 con Allow-Headers Authorization');
$r = $get("$S/webcams", $key('vls_' . str_repeat('A', 43)));
ok($r['status'] === 401 && $r['json']['error'] === 'key_unknown', 'chiave sconosciuta: 401');
$r = $get("$S/webcams", ['Authorization: Bearer ' . $tPubA]);
ok($r['status'] === 401, 'token di un router al posto della chiave: 401');
$r = $get("$S/webcams", $key($rev['key']));
ok($r['status'] === 403 && $r['json']['error'] === 'key_revoked', 'chiave revocata: 403');
$r = $get("$S/webcams", $key($exp['key']));
ok($r['status'] === 403 && $r['json']['error'] === 'key_expired', 'chiave scaduta: 403');
Db::exec('UPDATE customers SET active = 0 WHERE id = ?', [$cB]);
$r = $get("$S/webcams", $key($kCliB));
ok($r['status'] === 403 && $r['json']['error'] === 'customer_inactive', 'cliente disattivato: 403');
Db::exec('UPDATE customers SET active = 1 WHERE id = ?', [$cB]);
$codes = [];
for ($i = 0; $i < 4; $i++) {
	$rr = http('GET', "$S/whoami", $key($slow['key']));
	$codes[] = $rr['status'];
}
ok($codes === [200, 200, 200, 429] && (int) ($rr['headers']['retry-after'] ?? 0) >= 1 && $rr['json']['error'] === 'rate_limited', 'limite di frequenza per chiave: 429 con Retry-After', json_encode($codes));
$r = $get("$S/whoami", $key($kCliA));
ok($r['status'] === 200 && $r['json']['scope'] === 'client' && in_array('orig', $r['json']['sizes'], true) && $r['json']['key']['prefix'] === substr($kCliA, 0, 12) && $r['headers']['cache-control'] === 'no-store', 'whoami: scope, cliente, dimensioni, prefisso', $r['body']);
$r = $get("$S/whoami");
ok($r['json']['scope'] === 'anon' && !in_array('orig', $r['json']['sizes'], true), 'whoami senza chiave: niente originale');
$r = http('POST', "$S/webcams", $key($kCliA), '{}');
ok($r['status'] === 405, 'POST: 405 (sola lettura)');
$r = $get("$S/niente");
ok($r['status'] === 404 && $r['json']['error'] === 'not_found', 'endpoint inesistente: 404');
$r = http('GET', "$baseProd/api/site/v1/webcams");
ok($r['status'] === 403 && $r['json']['error'] === 'https_required', 'APP_ENV=prod: HTTP rifiutato');

// ------------------------------------------------------------ endpoint
section('API dei siti: endpoint');
$r = $get("$S/webcams", $key($kCliA));
$byslug = array_column($r['json']['webcams'], null, 'slug');
$c = $byslug['alpi-cantiere'];
ok($c['public'] === false && $c['current_url'] === null && $c['state'] === 'online' && $c['latitude'] === 46.123456 && $c['location'] === 'Località alpi-cantiere' && $c['timezone'] === 'Europe/Rome', 'elenco: nome, luogo, coordinate, stato', json_encode($c));
ok(($c['last_photo']['id'] ?? 0) === $pA[3] && $c['last_photo']['temperature_ext'] === 6.5 && isset($c['last_photo']['images']['orig'], $c['last_photo']['images']['320']), 'elenco: ultimo scatto con le immagini firmate (originale per client)', json_encode($c['last_photo']));
$l = $byslug['alpi-lago'];
ok($l['public'] === true && str_ends_with((string) $l['current_url'], '/cam/alpi-lago/current.jpg') && ($l['last_photo']['id'] ?? 0) === $pPub, 'webcam pubblica: current_url e ultima foto non nascosta');
$r = $get("$S/webcams", $key($kAll));
$pl = array_column($r['json']['webcams'], null, 'slug')['alpi-lago'] ?? [];
ok(isset($pl['last_photo']['images']['1920']) && !isset($pl['last_photo']['images']['orig']), 'scope public: fino a 1920, niente originale');
$r = $get("$S/webcams?sizes=320,orig");
ok(array_keys(array_column($r['json']['webcams'], null, 'slug')['alpi-lago']['last_photo']['images'] ?? []) === [320], 'sizes: filtro delle dimensioni (orig ignorato senza client)');

$r = $get("$S/webcams/alpi-cantiere");
ok($r['status'] === 404, 'webcam privata senza chiave: 404 (non 403)');
$r = $get("$S/webcams/alpi-cantiere", $key($kCliB));
ok($r['status'] === 404, 'webcam di un altro cliente: 404');
$r = $get("$S/webcams/alpi-cantiere", $key($kCliA));
$d = $r['json']['webcam'] ?? [];
ok($r['status'] === 200 && $d['interval_min'] === 10 && $d['weather'] !== null && abs($d['weather']['temperature_ext'] - 5.5) < 0.01 && $d['weather']['humidity_ext'] === 72.5, 'dettaglio: ultime misure esterne', json_encode($d['weather'] ?? null));
ok(($d['month_days']['days'] ?? null) === [$today] && $d['archive']['first_day'] === $today && $d['archive']['last_day'] === $today && in_array('orig', $d['sizes'], true), 'dettaglio: giorni del mese con foto e archivio', json_encode([$d['month_days'] ?? null, $d['archive'] ?? null]));
ok(array_key_exists('availability', $d), 'dettaglio: disponibilità del mese (null finché non è calcolata)');
Db::exec("INSERT INTO availability_monthly (webcam_id, month, expected_h, excluded_h, available_h, pct) VALUES (?, ?, 100, 0, 97, 97.00)", [$wPrivA, (new DateTimeImmutable('now', $rome))->format('Y-m')]);
$d = $get("$S/webcams/alpi-cantiere", $key($kCliA))['json']['webcam'];
ok(($d['availability']['pct'] ?? null) === 97.0 && $d['availability']['final'] === false, 'dettaglio: disponibilità per il cliente');
Db::exec("INSERT INTO availability_monthly (webcam_id, month, expected_h, excluded_h, available_h, pct) VALUES (?, ?, 100, 0, 99, 99.00)", [$wPubA, (new DateTimeImmutable('now', $rome))->format('Y-m')]);
ok($get("$S/webcams/alpi-lago")['json']['webcam']['availability'] === null, 'dettaglio pubblico: disponibilità nascosta senza availability_public');

$r = $get("$S/webcams/alpi-cantiere/photos?date=$today", $key($kCliA));
$ids = array_column($r['json']['photos'] ?? [], 'id');
ok($r['status'] === 200 && $ids === $pA && $r['json']['date'] === $today && $r['json']['count'] === 4, 'photos: archivio del giorno in ordine', json_encode($ids));
ok(!in_array($pBefore, $ids, true), 'photos: niente foto di prima dell\'assegnazione al cliente');
$p0 = $r['json']['photos'][0];
ok($p0['time'] === $t0 && str_starts_with($p0['datetime'], (new DateTimeImmutable('@' . $t0))->setTimezone($rome)->format('Y-m-d\TH:i')) && $p0['width'] === 2400 && $p0['height'] === 1600, 'photos: ora, data locale e dimensioni', json_encode($p0));
$r = $get("$S/webcams/alpi-cantiere/photos?date=$today&order=desc&every=40", $key($kCliA));
ok(array_column($r['json']['photos'], 'id') === [$pA[3], $pA[1]] || count($r['json']['photos']) <= 3, 'photos: order=desc ed every', json_encode(array_column($r['json']['photos'], 'id')));
$r = $get("$S/webcams/alpi-lago/photos?date=$today");
ok(array_column($r['json']['photos'], 'id') === [$pPub], 'photos: foto nascoste (is_public = 0) escluse');
ok($get("$S/webcams/alpi-lago/photos?date=2026-02-30")['status'] === 400 && $get("$S/webcams/alpi-lago/photos?date=ieri")['status'] === 400, 'photos: data non valida 400');

$month = substr($today, 0, 7);
$r = $get("$S/webcams/alpi-cantiere/days?month=$month", $key($kCliA));
ok($r['status'] === 200 && count($r['json']['days']) === 1 && $r['json']['days'][0]['date'] === $today && $r['json']['days'][0]['photos'] === 4 && $r['json']['days'][0]['first'] === $t0, 'days: giorni con foto e conteggio', $r['body']);
ok($get("$S/webcams/alpi-cantiere/days?month=2026-13", $key($kCliA))['status'] === 400, 'days: mese non valido 400');

$r = $get("$S/webcams/alpi-cantiere/series?from=" . ($t0 - 3600) . '&to=' . ($t0 + 3 * 3600) . '&fields=temperature_ext,humidity_ext', $key($kCliA));
$pts = $r['json']['points'] ?? [];
ok($r['status'] === 200 && count($pts) >= 3 && $r['json']['interval'] === 3600 && isset($pts[0]['temperature_ext'], $pts[0]['temperature_ext_min'], $pts[0]['temperature_ext_max'], $pts[0]['humidity_ext']), 'series: medie orarie con minimo e massimo', json_encode(array_slice($pts, 0, 2)));
ok($pts !== [] && $pts[0]['time'] % 3600 === 0 && $pts[count($pts) - 1]['temperature_ext'] > $pts[0]['temperature_ext'], 'series: ore intere, valori in ordine');
$r = $get("$S/webcams/alpi-cantiere/series?from=$today&to=$today&fields=dewpoint_ext", $key($kCliA));
ok($r['status'] === 200 && $r['json']['fields'] === ['dewpoint_ext'] && $r['json']['to'] - $r['json']['from'] >= 82800, 'series: from/to come date locali (giorno compreso)', $r['body']);
ok($get("$S/webcams/alpi-cantiere/series?fields=battery_v", $key($kCliA))['status'] === 400, 'series: campo interno (batteria) rifiutato 400');
ok($get("$S/webcams/alpi-cantiere/series?from=1000&to=99999999", $key($kCliA))['status'] === 400, 'series: intervallo troppo lungo 400');

$r = $get("$S/webcams/alpi-cantiere/compare?a=" . ($t0 + 100) . '&b=' . (new DateTimeImmutable('@' . ($t0 + 3500)))->setTimezone($rome)->format('Y-m-d\TH:i'), $key($kCliA));
ok($r['status'] === 200 && $r['json']['a']['photo']['id'] === $pA[0] && $r['json']['a']['delta_s'] === -100 && $r['json']['b']['photo']['id'] === $pA[3], 'compare: foto più vicine ai due istanti', $r['body']);
$r = $get("$S/webcams/alpi-cantiere/compare?a=$today&b=" . ($todayStart + 31), $key($kCliA));
ok($r['json']['b']['photo']['id'] === $pA[0], 'compare: la foto di prima dell\'assegnazione non c\'è', $r['body']);
ok($get("$S/webcams/alpi-cantiere/compare?a=1", $key($kCliA))['status'] === 400, 'compare: b mancante 400');

// ------------------------------------------------------------ dati interni
// il token del beacon (api-siti-v1.md 1.1) è previsto dal contratto: si toglie prima del controllo
$all = preg_replace('/"beacon":\{[^}]*\}/', '"beacon":{}', implode("\n", $bodies));
$macs = array_column(Db::all('SELECT mac FROM webcams'), 'mac');
$leak = [];
foreach ($macs as $m) {
	if (str_contains($all, $m)) {
		$leak[] = $m;
	}
}
foreach (['wct_', 'WC-SEGRETO', 'dolibarr', '"mac"', 'hostname', 'token', 'ticket', '127.0.0.1"', 'battery', 'alert', $kCliA, $kPubA] as $needle) {
	if (stripos($all, $needle) !== false) {
		$leak[] = $needle;
	}
}
ok($leak === [], 'nessun dato interno nelle risposte (MAC, token, hostname, Dolibarr, ticket, IP, batteria, chiavi)', implode(', ', $leak));

// ------------------------------------------------------------ immagini
section('API dei siti: immagini firmate e cache');
$imgs = $get("$S/webcams/alpi-cantiere/photos?date=$today", $key($kCliA))['json']['photos'][0]['images'];
$u320 = $imgs['320'];
ok((bool) preg_match('#/img/' . $pA[0] . '/320\.jpg\?e=(\d+)&s=[A-Za-z0-9_-]{32}$#', $u320, $em) && (int) $em[1] >= $now + 6 * 86400, 'URL firmato con scadenza di almeno 6 giorni', $u320);
$cacheFile = Images::cacheFile($wPrivA, $pA[0], '320');
@unlink($cacheFile);
$t = microtime(true);
$r = http('GET', $u320);
$dim = @getimagesizefromstring($r['body']);
ok($r['status'] === 200 && ($r['headers']['content-type'] ?? '') === 'image/jpeg' && $dim !== false && $dim[0] === 320 && $dim[1] === 213, 'riduzione a 320 px generata con GD', $r['status'] . ' ' . json_encode($dim));
ok(is_file($cacheFile), 'riduzione salvata in storage/cache/img');
ok(str_contains((string) ($r['headers']['cache-control'] ?? ''), 'max-age=') && (int) preg_replace('/.*max-age=(\d+).*/', '$1', (string) $r['headers']['cache-control']) > 5 * 86400 && str_starts_with((string) $r['headers']['cache-control'], 'private'), 'Cache-Control lungo (private per webcam privata)', (string) ($r['headers']['cache-control'] ?? ''));
$etag = (string) ($r['headers']['etag'] ?? '');
ok($etag !== '' && http('GET', $u320, ["If-None-Match: $etag"])['status'] === 304, 'ETag e 304 con If-None-Match');
$mt = filemtime($cacheFile);
$r2 = http('GET', $u320);
ok($r2['body'] === $r['body'] && filemtime($cacheFile) === $mt, 'seconda richiesta servita dalla cache');
$r = http('GET', $imgs['1920']);
$dim = @getimagesizefromstring($r['body']);
ok($r['status'] === 200 && $dim[0] === 1920, 'riduzione a 1920 px');
$r = http('GET', $imgs['640']);
ok($r['status'] === 200 && getimagesizefromstring($r['body'])[0] === 640, 'riduzione a 640 px (dalla 1920 in cache)');
$r = http('GET', $imgs['orig']);
ok($r['status'] === 200 && hash('sha256', $r['body']) === Db::scalar('SELECT sha256 FROM photos WHERE id = ?', [$pA[0]]), 'originale per lo scope client, identico al file ricevuto');
ok(http('GET', str_replace('/320.jpg', '/orig.jpg', $u320))['status'] === 403, 'cambiare la dimensione nell\'URL: 403 (firma)');
ok(http('GET', str_replace('/' . $pA[0] . '/', '/' . $pA[1] . '/', $u320))['status'] === 403, 'cambiare la foto nell\'URL: 403 (firma)');
ok(http('GET', preg_replace('/e=\d+/', 'e=' . ($em[1] + 86400), $u320))['status'] === 403, 'allungare la scadenza: 403 (firma)');
$old = $now - 10;
$r = http('GET', "$base/img/{$pA[0]}/320.jpg?e=$old&s=" . Images::signature($pA[0], '320', $old));
ok($r['status'] === 403 && ($r['json']['error'] ?? '') === 'url_expired', 'URL scaduto: 403 url_expired', $r['body']);
$pubImg = $get("$S/webcams/alpi-lago")['json']['webcam']['last_photo']['images']['320'];
$r = http('GET', $pubImg);
ok($r['status'] === 200 && str_starts_with((string) ($r['headers']['cache-control'] ?? ''), 'public') && ($r['headers']['access-control-allow-origin'] ?? '') === '*', 'webcam pubblica: Cache-Control public', (string) ($r['headers']['cache-control'] ?? ''));
$e = Images::expiry();
$r = http('GET', "$base/img/$pHidden/320.jpg?e=$e&s=" . Images::signature($pHidden, '320', $e));
ok($r['status'] === 404, 'foto nascosta: 404 anche con firma valida');
// foto più piccola della dimensione chiesta: si serve l'originale, mai ingrandita
$small = jpeg(300, 200, 77);
$rs = sendPhoto($base, $tPubA, $small, photoMeta($small, $t0 + 900, $macPubA));
$ps = (int) ($rs['json']['photo_id'] ?? 0);
$r = http('GET', "$base/img/$ps/1280.jpg?e=$e&s=" . Images::signature($ps, '1280', $e));
ok($r['status'] === 200 && $r['body'] === $small, 'foto più piccola: originale, non ingrandita');

// memoria: un 24 MP finto (6000x4000) con poca memoria disponibile -> 503, nessun crash
$writeCfg("$tmp/config-lowmem.php", ['photo_metrics' => ['memory_limit' => '48M']] + $cfgValues);
$baseLow = $startServer(WEBCAM_PORTAL_ROOT . '/public/index.php', ['WEBCAM_CONFIG' => "$tmp/config-lowmem.php"], WEBCAM_PORTAL_ROOT . '/public', ['memory_limit' => '48M']);
// (oltre photo_max_bytes dei test: il file si mette nell'archivio direttamente)
$j24 = $big(5, 6000, 4000);
$rel24 = "photos/$wPrivA/prova/big24.jpg";
@mkdir(dirname("$tmp/storage/$rel24"), 0775, true);
file_put_contents("$tmp/storage/$rel24", $j24);
Db::exec("INSERT INTO photos (webcam_id, taken_at, time_synced, sha256, size_bytes, width, height, file_name, storage_path) VALUES (?, ?, 1, ?, ?, 6000, 4000, 'big24.jpg', ?)",
	[$wPrivA, Db::dt($t0 + 4000), hash('sha256', $j24), strlen($j24), $rel24]);
$p24 = (int) Db::pdo()->lastInsertId();
$r = http('GET', "$baseLow/img/$p24/1280.jpg?e=$e&s=" . Images::signature($p24, '1280', $e));
ok($p24 > 0 && $r['status'] === 503 && ($r['json']['error'] ?? '') === 'image_too_large' && isset($r['headers']['retry-after']), 'foto da 24 MP oltre photo_metrics.memory_limit: 503 con Retry-After', $r['status'] . ' ' . substr($r['body'], 0, 200));
$plog = (string) @file_get_contents("$tmp/storage/logs/site-api.log");
ok(str_contains($plog, 'photo_metrics.memory_limit'), 'memoria insufficiente scritta nel log', substr($plog, -300));
$r = http('GET', "$base/img/$p24/1280.jpg?e=$e&s=" . Images::signature($p24, '1280', $e));
ok($r['status'] === 200 && getimagesizefromstring($r['body'])[0] === 1280, 'stessa foto con photo_metrics.memory_limit 512M: riduzione a 1280', $r['status'] . ' ' . substr($r['body'], 0, 200));

// pulizia: retention delle foto e cache non usata
$c1920 = Images::cacheFile($wPrivA, $pA[0], '1920');
ok(is_file($c1920), 'cache: riduzione 1920 presente');
Images::forget($wPrivA, $pA[0]);
ok(!is_file($c1920) && !is_file($cacheFile), 'cache: riduzioni tolte con la foto (retention)');
http('GET', $imgs['320']);
$st = Images::prune(30, true, $now + 31 * 86400);
ok($st['files'] >= 1 && is_file($cacheFile), 'pulizia --prova: conta senza cancellare');
$st = Images::prune(30, false, $now);
ok($st['files'] === 0 && is_file($cacheFile), 'pulizia: le riduzioni usate di recente restano');
$out = [];
exec('WEBCAM_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/retention-photos.php') . ' --cache-giorni 1 2>&1', $out, $rc);
ok($rc === 0 && str_contains(implode("\n", $out), 'cache delle riduzioni'), 'retention-photos.php pulisce anche la cache', implode("\n", $out));
touch($cacheFile, $now - 40 * 86400);
Images::prune(30);
ok(!is_file($cacheFile), 'pulizia: riduzione non usata da 40 giorni cancellata');

// ------------------------------------------------------------ cambio di cliente
section('API dei siti: cambio di cliente');
Customers::assign($wPubA, $cB, 'portal', null, $now - 60);
$r = $get("$S/webcams/alpi-lago/photos?date=$today", $key($kCliB));
ok($r['status'] === 200 && $r['json']['photos'] === [], 'cliente nuovo: non vede le foto del cliente di prima');
$r = $get("$S/webcams/alpi-lago", $key($kCliA));
ok($r['status'] === 200 && $r['json']['webcam']['state'] === 'archived' && $r['json']['webcam']['current_url'] === null && $r['json']['webcam']['weather'] === null
	&& preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['json']['webcam']['archive_until'] ?? '')), 'cliente di prima: webcam in archivio (archive.default_days), senza current_url e meteo', $r['body']);
ok($get("$S/webcams/alpi-lago", $key($kPubA))['status'] === 404, 'cliente di prima con chiave public: 404');
Db::exec('UPDATE customer_webcams SET archive_until = ? WHERE webcam_id = ? AND customer_id = ?', [gmdate('Y-m-d', $now - 3 * 86400), $wPubA, $cA]);
ok($get("$S/webcams/alpi-lago", $key($kCliA))['status'] === 404, 'cliente di prima: dopo archive_until la webcam non è più sua (404)');
ok($get("$S/webcams/alpi-lago/photos?date=$today")['json']['photos'] === [], 'pubblico: solo dall\'inizio dell\'assegnazione in corso');
ok((int) Db::scalar('SELECT COUNT(*) FROM customer_webcams WHERE webcam_id = ?', [$wPubA]) === 2 && (int) Db::scalar('SELECT customer_id FROM webcams WHERE id = ?', [$wPubA]) === $cB, 'storico: assegnazione chiusa e nuova aperta');
ok(Customers::assign($wPubA, $cB) === false, 'stesso cliente: nessuna riga nuova');
Customers::assign($wPubA, $cA);

// ------------------------------------------------------------ pannello e riga di comando
section('API dei siti: pannello e riga di comando');
$r = $page('GET', '/admin/customers');
$csrf = $r['csrf'];
ok($r['status'] === 200 && str_contains($r['body'], 'Hotel Alpi') && str_contains($r['body'], 'Chiavi API dei siti'), 'pannello: elenco clienti e chiavi senza cliente');
$r = $page('POST', '/admin/keys', ['_csrf' => $csrf, 'customer_id' => $cA, 'scope' => 'public', 'label' => 'dal pannello', 'origins' => "https://pannello.example\nhttps://www.pannello.example"]);
ok($r['status'] === 303 && str_contains($r['location'], "/admin/customers/$cA"), 'pannello: crea chiave e torna alla scheda del cliente');
$r = $page('GET', "/admin/customers/$cA");
preg_match('/(vls_[A-Za-z0-9_-]{43})/', $r['body'], $km);
ok($r['status'] === 200 && isset($km[1]) && str_contains($r['body'], 'alpi-cantiere'), 'pannello: la chiave nuova si vede nella scheda');
ok(!preg_match('/vls_[A-Za-z0-9_-]{43}/', $page('GET', "/admin/customers/$cA")['body']), 'pannello: la chiave si mostra una volta sola');
$r2 = http('GET', "$S/webcams", $key($km[1] ?? '', 'https://www.pannello.example'));
ok($r2['status'] === 200 && $slugs($r2) === ['alpi-lago'], 'la chiave creata dal pannello funziona con la sua origine', $r2['body']);
$kid = (int) Db::scalar('SELECT id FROM site_keys WHERE key_hash = ?', [SiteKeys::hash($km[1] ?? '')]);
$r = $page('POST', "/admin/keys/$kid/origins", ['_csrf' => $csrf, 'origins' => 'https://nuovo.example']);
ok(http('GET', "$S/webcams", $key($km[1] ?? '', 'https://www.pannello.example'))['status'] === 403 && http('GET', "$S/webcams", $key($km[1] ?? '', 'https://nuovo.example'))['status'] === 200, 'pannello: origini modificate');
$r = $page('POST', "/admin/keys/$kid/revoke", ['_csrf' => $csrf]);
ok($r['status'] === 303 && http('GET', "$S/webcams", $key($km[1] ?? ''))['status'] === 403, 'pannello: revoca, la chiave riceve 403');
$r = $page('POST', '/admin/keys', ['_csrf' => $csrf, 'customer_id' => 0, 'scope' => 'client', 'origins' => '']);
ok((int) Db::scalar('SELECT COUNT(*) FROM site_keys WHERE customer_id IS NULL AND scope = \'client\'') === 0, 'pannello: chiave client senza cliente rifiutata');
$r = $page('POST', "/admin/webcams/$wPubN/edit", ['_csrf' => $csrf, 'name' => 'Vetta', 'slug' => 'viewlapse-vetta', 'timezone' => 'Europe/Rome', 'status' => 1, 'is_public' => 1, 'customer_id' => $cB, 'latitude' => '45,5', 'longitude' => '9.25', 'altitude_m' => '2100']);
$wn = Db::one('SELECT customer_id, latitude, longitude, altitude_m FROM webcams WHERE id = ?', [$wPubN]);
ok((int) $wn['customer_id'] === $cB && (float) $wn['latitude'] === 45.5 && (int) $wn['altitude_m'] === 2100, 'pannello: cliente e coordinate dalla scheda della webcam', json_encode($wn));
$r = $page('POST', '/admin/customers', ['_csrf' => $csrf, 'name' => 'Cliente dal pannello', 'code' => 'P1']);
ok($r['status'] === 303 && Db::scalar("SELECT source FROM customers WHERE name = 'Cliente dal pannello'") === 'portal', 'pannello: nuovo cliente');

$cli = static function (string $args) use ($tmp): array {
	$out = [];
	exec('WEBCAM_CONFIG=' . escapeshellarg("$tmp/config.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/site-key.php') . " $args 2>&1", $out, $rc);
	return [$rc, implode("\n", $out)];
};
[$rc, $out] = $cli("--crea --cliente $cA --scope client --etichetta 'da riga di comando'");
preg_match('/^(vls_[A-Za-z0-9_-]{43})$/m', $out, $km);
ok($rc === 0 && isset($km[1]) && http('GET', "$S/whoami", $key($km[1]))['json']['scope'] === 'client', 'riga di comando: --crea scope client', $out);
$cliId = (int) Db::scalar('SELECT id FROM site_keys WHERE key_hash = ?', [SiteKeys::hash($km[1] ?? '')]);
[$rc, $out] = $cli('--elenco');
ok($rc === 0 && str_contains($out, substr($km[1] ?? 'x', 0, 12)) && !str_contains($out, $km[1] ?? 'x'), 'riga di comando: --elenco con il solo prefisso');
[$rc, $out] = $cli("--origini 'https://cli.example' --chiave $cliId");
ok($rc === 0 && Db::scalar('SELECT origins FROM site_keys WHERE id = ?', [$cliId]) === 'https://cli.example', 'riga di comando: --origini');
[$rc, $out] = $cli("--revoca $cliId");
ok($rc === 0 && http('GET', "$S/whoami", $key($km[1] ?? ''))['status'] === 403, 'riga di comando: --revoca');
[$rc, $out] = $cli('--crea --senza-cliente --scope client');
ok($rc === 1 && str_contains($out, 'cliente'), 'riga di comando: chiave client senza cliente rifiutata', $out);

// ------------------------------------------------------------ Dolibarr
section('API dei siti: clienti da Dolibarr');
// la webcam 12 del finto Dolibarr (ritirata dai test della diagnosi) torna in servizio senza cliente
Db::exec("UPDATE webcams SET status = 1, customer_id = NULL WHERE mac = '94:83:c4:12:ab:cd'");
Db::exec('DELETE FROM customers WHERE dolibarr_socid = 57');
[$rc, $out] = $runSync($d13);
$cust = Db::one('SELECT * FROM customers WHERE dolibarr_socid = 57');
$wDol = (int) Db::scalar("SELECT id FROM webcams WHERE mac = '94:83:c4:12:ab:cd'");
ok($cust !== null && $cust['name'] === 'Impresa di prova' && $cust['code'] === 'CU2611-0042' && $cust['town'] === 'Frascati' && $cust['source'] === 'dolibarr' && $cust['synced_at'] !== null, 'sync-dolibarr: cliente importato dal terzo (GET webcams/{id}/portal e thirdparties/{id})', json_encode($cust) . "\n" . $out);
ok($cust !== null && (int) Db::scalar('SELECT customer_id FROM webcams WHERE id = ?', [$wDol]) === (int) $cust['id']
	&& Db::scalar("SELECT source FROM customer_webcams WHERE webcam_id = ? AND valid_to IS NULL", [$wDol]) === 'dolibarr', 'sync-dolibarr: webcam assegnata al cliente con lo storico');
Db::exec("UPDATE customers SET name = 'Vecchio nome' WHERE dolibarr_socid = 57");
$runSync($d13);
ok(Db::scalar('SELECT name FROM customers WHERE dolibarr_socid = 57') === 'Impresa di prova' && (int) Db::scalar('SELECT COUNT(*) FROM customers WHERE dolibarr_socid = 57') === 1
	&& (int) Db::scalar('SELECT COUNT(*) FROM customer_webcams WHERE webcam_id = ? AND valid_to IS NULL', [$wDol]) === 1, 'sync-dolibarr: aggiornamento senza doppioni', json_encode(Db::all('SELECT * FROM customer_webcams WHERE webcam_id = ?', [$wDol])) . json_encode(Db::all('SELECT id, name FROM customers WHERE dolibarr_socid = 57')));
