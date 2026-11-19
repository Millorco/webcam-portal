<?php
/**
 * Test della diagnosi (incluso da tests/run.php, stesse variabili):
 * scenari di guasto del simulatore, comandi al router, metriche foto,
 * disponibilità, ticket Dolibarr 1.3/1.2 e VictoriaLogs.
 *
 * Ogni scenario usa una webcam a sé: il simulatore riempie lo storico con
 * --dal/--al e il guasto tra --guasto-dal e --guasto-al, poi le metriche
 * foto e le regole girano ora per ora con l'ora finta (come --now).
 */

declare(strict_types=1);

use WebcamPortal\Db;
use WebcamPortal\Photo\Metrics;
use WebcamPortal\Rules\Engine;
use WebcamPortal\Service\Availability;
use WebcamPortal\Service\Commands;
use WebcamPortal\Service\ServiceSettings;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;

$simBin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/tools/simula-router.php');
$runSim = static function (string $token, string $mac, array $args) use ($simBin, $base): array {
	$cmd = $simBin . ' --url ' . escapeshellarg($base) . ' --token ' . escapeshellarg($token) . ' --mac ' . escapeshellarg($mac);
	foreach ($args as $k => $v) {
		$cmd .= ' --' . $k . ($v === true ? '' : ' ' . escapeshellarg((string) $v));
	}
	$out = [];
	exec($cmd . ' 2>&1', $out, $rc);
	return [$rc, implode("\n", $out)];
};
$rome = new DateTimeZone('Europe/Rome');
// mezzanotte (ora di Roma) di N giorni fa
$midnight = static fn (int $daysAgo): int => (new DateTimeImmutable('today', $rome))->modify("-$daysAgo days")->getTimestamp();
$n = 0;
$newCam = static function (string $slug) use (&$n): array {
	$n++;
	$mac = sprintf('94:83:c4:20:%02x:%02x', intdiv($n, 256), $n % 256);
	$id = Webcams::create($mac, 'Scenario ' . $slug, $slug, ['timezone' => 'Europe/Rome']);
	Db::exec('UPDATE webcams SET status = 1 WHERE id = ?', [$id]);
	return [$id, Tokens::create($id), $mac];
};
/** Metriche foto e regole ogni $step secondi da $from a $to (ora finta). */
$evaluate = static function (int $wid, int $from, int $to, int $step, bool $photos): void {
	for ($t = $from; $t <= $to; $t += $step) {
		if ($photos) {
			(new Metrics($t))->run($wid);
		}
		(new Engine($t, null, $wid))->run();
	}
};
$alertsOf = static function (int $wid): string {
	$r = Db::all('SELECT rule_id, status, close_reason, opened_at, closed_at FROM alerts WHERE webcam_id = ? ORDER BY id', [$wid]);
	return implode('; ', array_map(static fn ($a) => "{$a['rule_id']} {$a['status']} {$a['opened_at']}-{$a['closed_at']} {$a['close_reason']}", $r)) ?: 'nessun avviso';
};

// ------------------------------------------------------------ scenari di guasto
section('Scenari di guasto (simulatore, metriche foto e regole)');
Db::exec('UPDATE webcams SET status = 9 WHERE id IN (?, ?)', [$wid, $wid2]); // le webcam dei test precedenti non servono più

// [scenario, regola, giorni di storico, inizio guasto (ore dalla mezzanotte del primo giorno), fine guasto, foto ogni, passo, foto analizzate]
$scenarios = [
	['camera-ferma', 'camera.stalled', 2, 32, 37, 30, 1800, false],
	['riscaldamento', 'enclosure.heater', 4, 24, 60, 60, 1800, false],
	['sole', 'enclosure.overheat', 6, 24, 72, 60, 3600, false],
	['guarnizione', 'enclosure.seal', 13, 24, 120, 60, 3 * 3600, false],
	['vetro-appannato', 'glass.fog', 3, 33, 37, 30, 1800, true],
	['vetro-sporco', 'glass.dirty', 5, 33, 81, 30, 1800, true],
	['camera-mossa', 'camera.moved', 3, 33, 37, 30, 1800, true],
];
$sim = [];
foreach ($scenarios as [$name, $rule, $days, $f0, $f1, $every, $step, $photos]) {
	[$id, $tok, $mac] = $newCam('scenario-' . $name);
	$start = $midnight($days);
	$end = min(time() - 60, $start + $days * 86400 + 6 * 3600);
	if ($name === 'vetro-appannato') {
		ServiceSettings::save($id, ['photo_metrics' => 1]);
	}
	$t0 = microtime(true);
	[$rc, $out] = $runSim($tok, $mac, [
		'dal' => '@' . $start, 'al' => '@' . $end, 'foto-ogni' => $every, 'scenario' => $name,
		'guasto-dal' => '@' . ($start + $f0 * 3600), 'guasto-al' => '@' . ($start + $f1 * 3600),
	]);
	$sim[$name] = round(microtime(true) - $t0, 1);
	ok($rc === 0, "simulatore --scenario $name: dati inviati ({$sim[$name]} s)", substr($out, -800));
	$t0 = microtime(true);
	$evaluate($id, $start + 12 * 3600, $end, $step, $photos);
	$a = Db::one('SELECT * FROM alerts WHERE webcam_id = ? AND rule_id = ? ORDER BY id LIMIT 1', [$id, $rule]);
	$opened = $a !== null ? (int) Db::unix($a['opened_at']) : null;
	ok($a !== null && $opened >= $start + $f0 * 3600 && $opened <= $start + $f1 * 3600 + 6 * 3600,
		"$name: $rule si apre durante il guasto (" . round(microtime(true) - $t0, 1) . ' s di valutazione)', $alertsOf($id));
	ok($a !== null && $a['status'] === 'closed' && $a['close_reason'] === 'auto' && (int) Db::unix($a['closed_at']) >= $start + $f1 * 3600,
		"$name: $rule si chiude da solo dopo la riparazione", $alertsOf($id));
	// camera-ferma manda anche camera.error: logs.camera_errors è atteso
	$also = $name === 'camera-ferma' ? ['logs.camera_errors'] : [];
	$others = array_diff(array_column(Db::all('SELECT DISTINCT rule_id FROM alerts WHERE webcam_id = ? AND rule_id <> ?', [$id, $rule]), 'rule_id'), $also);
	ok($others === [], "$name: nessun altro avviso" . ($also ? ' (oltre a ' . implode(', ', $also) . ')' : ''), $alertsOf($id));
	$scen[$name] = ['id' => $id, 'token' => $tok, 'mac' => $mac, 'start' => $start, 'end' => $end, 'alert' => $a];
}

// disponibilità del giorno del guasto, prima che il test della riapertura cancelli le foto
$cf = $scen['camera-ferma'];
$ferDay = (new Availability(time()))->day((array) Db::one('SELECT * FROM webcams WHERE id = ?', [$cf['id']]), ServiceSettings::get($cf['id']), $rome,
	(new DateTimeImmutable('@' . ($cf['start'] + 86400)))->setTimezone($rome)->format('Y-m-d'));
$fog = $scen['vetro-appannato'];
$boost = Db::one("SELECT * FROM commands WHERE webcam_id = ? AND type = 'heater.boost' AND created_by = 'rule'", [$fog['id']]);
ok($boost !== null && (int) $boost['alert_id'] === (int) ($fog['alert']['id'] ?? 0) && json_decode((string) $boost['params'], true) === ['duration_s' => 1800],
	'glass.fog: heater.boost di 30 minuti collegato all\'avviso', json_encode($boost));
$ev = Db::all("SELECT type, JSON_VALUE(data, '$.channel') ch FROM events WHERE alert_id = ? ORDER BY id", [(int) ($fog['alert']['id'] ?? 0)]);
ok(($ev[0]['type'] ?? '') === 'alert.open' && end($ev)['type'] === 'alert.close', 'eventi alert.open e alert.close con alert_id', json_encode($ev));
$heat = $scen['riscaldamento']['alert'];
ok($heat !== null && $heat['severity'] === 'error' && $heat['action'] === 'ticket' && Db::scalar("SELECT JSON_VALUE(data, '$.channel') FROM events WHERE id = ?", [$heat['open_event_id']]) === 'ticket',
	'enclosure.heater: error con canale ticket');
$moved = $scen['camera-mossa'];
$sh = Db::one("SELECT MAX(shift_px) m FROM photo_metrics WHERE webcam_id = ? AND classification = 'moved'", [$moved['id']]);
ok((float) ($sh['m'] ?? 0) >= 8, 'camera-mossa: spostamento misurato ' . ($sh['m'] ?? '—') . ' px');
$dirty = Db::one("SELECT MIN(blur_ratio) b FROM photo_metrics WHERE webcam_id = ? AND classification = 'dirty'", [$scen['vetro-sporco']['id']]);
ok($dirty !== null && $dirty['b'] !== null && (float) $dirty['b'] < 0.6, 'vetro-sporco: foto classificate dirty, nitidezza ' . ($dirty['b'] ?? '—'));
$refs = (int) Db::scalar('SELECT COUNT(*) FROM reference_photos WHERE webcam_id = ? AND valid_to IS NULL', [$fog['id']]);
ok($refs >= 8, "riferimenti scelti in automatico per fascia ($refs)");
$stalled = Db::all('SELECT rule_id, state FROM rule_state WHERE webcam_id = ?', [$scen['camera-ferma']['id']]);
ok(in_array(['rule_id' => 'camera.stalled', 'state' => 'ok'], $stalled, true), 'rule_state: camera.stalled torna ok', json_encode($stalled));

// riapertura entro 24 ore: stesso avviso, reopen_count + 1
$aid = (int) $cf['alert']['id'];
$reT = (int) Db::unix($cf['alert']['closed_at']) + 3 * 3600;
Db::exec('DELETE FROM photos WHERE webcam_id = ? AND taken_at > ?', [$cf['id'], Db::dt($reT - 4 * 3600)]);
(new Engine($reT, null, $cf['id']))->run();
$re = Db::one('SELECT * FROM alerts WHERE webcam_id = ? AND rule_id = ? ORDER BY id DESC LIMIT 1', [$cf['id'], 'camera.stalled']);
ok((int) $re['id'] === $aid && $re['status'] === 'open' && (int) $re['reopen_count'] === 1, 'riaperto entro 24 ore: stesso avviso, reopen_count 1', json_encode($re));

// ------------------------------------------------------------ webcam sana
section('Webcam sana');
[$hid, $htok, $hmac] = $newCam('sana');
$hs = $midnight(3);
$he = time() - 60;
[$rc, $out] = $runSim($htok, $hmac, ['dal' => '@' . $hs, 'al' => '@' . $he, 'foto-ogni' => 30]);
ok($rc === 0, 'simulatore senza scenario', substr($out, -500));
$evaluate($hid, $hs + 12 * 3600, $he, 3600, true);
ok((int) Db::scalar('SELECT COUNT(*) FROM alerts WHERE webcam_id = ?', [$hid]) === 0, 'nessun avviso su una webcam sana in 3 giorni', $alertsOf($hid));
$st = Db::all('SELECT rule_id, state FROM rule_state WHERE webcam_id = ? ORDER BY rule_id', [$hid]);
ok(count($st) === 12, 'stato di tutte le 12 regole', json_encode($st));
$heaterCycles = (int) Db::scalar('SELECT COUNT(*) FROM measurements WHERE webcam_id = ? AND heater = 1', [$hid]);
$m1 = Db::one('SELECT heat_reason, dewpoint_margin, cam_scheduled, cam_interval_min FROM measurements WHERE webcam_id = ? ORDER BY ts DESC LIMIT 1', [$hid]);
ok($m1['dewpoint_margin'] !== null && $m1['heat_reason'] !== null && (int) $m1['cam_scheduled'] === 1 && (int) $m1['cam_interval_min'] === 10, 'campi climate/camera 1.1 salvati', json_encode($m1));
$cc = Db::one('SELECT shutter_count, shots_total FROM camera_counters WHERE webcam_id = ? ORDER BY ts DESC LIMIT 1', [$hid]);
ok($cc !== null && (int) $cc['shutter_count'] > (int) $cc['shots_total'], 'shutter_count dal simulatore', json_encode($cc));

// ------------------------------------------------------------ metriche foto
section('Metriche foto');
$pm = Db::one("SELECT COUNT(*) n, SUM(classification = 'skipped' AND skip_reason = 'no_reference') nr, MAX(sharpness_tiles) s FROM photo_metrics WHERE webcam_id = ?", [$hid]);
ok((int) $pm['n'] >= 40 && (float) $pm['s'] > 0, "foto analizzate: {$pm['n']}, nitidezza massima {$pm['s']}");
ok((int) Db::scalar("SELECT COUNT(*) FROM photo_metrics pm JOIN photos p ON p.id = pm.photo_id WHERE pm.webcam_id = ? AND p.mode = 'night'", [$hid]) === 0, 'le foto di notte non si analizzano');
$gap = Db::all("SELECT TIMESTAMPDIFF(MINUTE, LAG(taken_at) OVER (ORDER BY taken_at), taken_at) d FROM photo_metrics WHERE webcam_id = ? AND DATE(taken_at) = DATE(?)", [$hid, Db::dt($he - 86400)]);
$gaps = array_filter(array_map(static fn ($r) => $r['d'], $gap), static fn ($d) => $d !== null);
ok($gaps !== [] && min($gaps) >= 30, 'una foto ogni every_min (30) minuti', json_encode(array_values($gaps)));
// memoria insufficiente: foto saltata e scritta nel log
$writeCfg("$tmp/config-mem.php", ['photo_metrics' => ['memory_limit' => '4M']] + $cfgValues);
$lastP = Db::one("SELECT id FROM photos WHERE webcam_id = ? AND mode = 'day' ORDER BY taken_at DESC LIMIT 1", [$hid]);
Db::exec('DELETE FROM photo_metrics WHERE photo_id = ?', [$lastP['id']]);
$out = [];
exec('WEBCAM_CONFIG=' . escapeshellarg("$tmp/config-mem.php") . ' ' . escapeshellarg(PHP_BINARY) . ' -d memory_limit=4M ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/photo-metrics.php') . " --webcam $hid --senza-regole 2>&1", $out, $rc);
$skip = Db::one('SELECT classification, skip_reason FROM photo_metrics WHERE photo_id = ?', [$lastP['id']]);
$plogM = (string) @file_get_contents("$tmp/storage/logs/photo-metrics.log") . (string) @file_get_contents("$tmp/storage/logs/portal.log");
ok($rc === 0 && ($skip['skip_reason'] ?? '') === 'memory' && str_contains($plogM, 'memoria insufficiente'), 'memory_limit troppo basso: foto saltata e scritta nel log', implode("\n", $out) . json_encode($skip));
Db::exec('DELETE FROM photo_metrics WHERE photo_id = ?', [$lastP['id']]);
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/photo-metrics.php') . " --webcam $hid --senza-regole 2>&1", $out, $rc);
ok($rc === 0 && Db::scalar('SELECT classification FROM photo_metrics WHERE photo_id = ?', [$lastP['id']]) !== 'skipped', 'bin/photo-metrics.php con la memoria di config', implode("\n", $out));
// riferimenti dal pannello
$band = (int) Db::scalar('SELECT band FROM reference_photos WHERE webcam_id = ? AND valid_to IS NULL ORDER BY band LIMIT 1', [$hid]);
Metrics::resetBand($hid, $band);
ok(Db::scalar('SELECT COUNT(*) FROM reference_photos WHERE webcam_id = ? AND band = ? AND valid_to IS NULL', [$hid, $band]) == 0, 'Ricalcola: riferimento della fascia chiuso');
$pickP = (int) Db::scalar("SELECT photo_id FROM photo_metrics WHERE webcam_id = ? AND band = ? AND classification <> 'skipped' ORDER BY taken_at DESC LIMIT 1", [$hid, $band]);
$rid = Metrics::setReference($hid, $pickP, 'operator', null);
ok(Db::scalar('SELECT chosen_by FROM reference_photos WHERE id = ?', [$rid]) === 'operator' && is_file("$tmp/storage/reference/$hid/$band.png"), 'Usa come riferimento: scelta dell\'operatore, PNG in grigi');

// ------------------------------------------------------------ comandi al router
section('Comandi al router');
foreach ([['heater.boost', ['duration_s' => 200]], ['heater.boost', ['duration_s' => '600']], ['config.set', ['key' => 'general.heat_hysteresis', 'value' => '5.5']],
	['config.set', ['key' => 'network.wan', 'value' => '1']], ['reboot', []], ['capture.now', ['upload' => 'si']], ['telemetry.flush', ['x' => 1]]] as [$ty, $pa]) {
	try {
		Commands::validate($ty, $pa);
		ok(false, "validazione: $ty " . json_encode($pa) . ' rifiutato');
	} catch (InvalidArgumentException $e) {
		ok(true, "validazione: $ty " . json_encode($pa) . ' rifiutato');
	}
}
ok(Commands::validate('config.set', ['key' => 'general.heat_min_temp', 'value' => '-10.0']) === ['key' => 'general.heat_min_temp', 'value' => '-10.0']
	&& Commands::validate('heater.boost', ['duration_s' => 1800]) === ['duration_s' => 1800], 'validazione: valori ammessi');
[$cid, $ctok, $cmac] = $newCam('comandi');
$c1 = Commands::create($cid, 'capture.now', ['upload' => true], ['by' => 'operator']);
$c2 = Commands::create($cid, 'heater.boost', ['duration_s' => 1800], ['by' => 'operator']);
$c3 = Commands::create($cid, 'config.set', ['key' => 'general.heat_dewpoint_margin', 'value' => '2.5'], ['by' => 'operator']);
$c4 = Commands::create($cid, 'telemetry.flush', [], ['by' => 'operator', 'ttl' => 60, 'now' => time() - 120]);
$r = http('GET', "$base/api/v1/commands", ["Authorization: Bearer $ctok"]);
ok($r['status'] === 200 && ($r['json']['commands'] ?? null) === [] && !isset($r['json']['poll_after']), 'senza X-Webcam-Features: nessun comando');
$r = http('GET', "$base/api/v1/whoami", ["Authorization: Bearer $ctok", 'X-Webcam-Features: commands/1']);
ok(!isset($r['json']['commands']), 'whoami non consegna comandi');
Db::exec("UPDATE tokens SET scopes = 'photo,telemetry,event' WHERE webcam_id = ?", [$cid]);
$oneEv = static fn (string $id): array => ['schema' => 'event/1', 'mac' => $cmac, 'sent_at' => time(), 'events' => [
	['id' => $id, 'time' => time(), 'time_synced' => true, 'type' => 'ntp.synced', 'severity' => 'debug', 'message' => 'prova'],
]];
$r = http('POST', "$base/api/v1/event", ["Authorization: Bearer $ctok", 'Content-Type: application/json', 'X-Webcam-Features: commands/1'], (string) json_encode($oneEv('aa000001-E1')));
ok(!isset($r['json']['commands']), 'token senza scope commands: nessun comando', $r['body']);
Db::exec("UPDATE tokens SET scopes = 'photo,telemetry,event,commands' WHERE webcam_id = ?", [$cid]);
$r = http('POST', "$base/api/v1/event", ["Authorization: Bearer $ctok", 'Content-Type: application/json', 'X-Webcam-Features: luci/1, Commands/1'], (string) json_encode($oneEv('aa000001-E2')));
$ids = array_column($r['json']['commands'] ?? [], 'id');
ok($r['status'] === 200 && $ids === ["cmd-$c1", "cmd-$c2", "cmd-$c3"] && ($r['json']['poll_after'] ?? null) === 60, 'risposta di /event: comandi in ordine, scaduto escluso, poll_after 60 col boost', $r['body']);
ok(($r['json']['commands'][0]['schema'] ?? '') === 'command/1' && is_int($r['json']['commands'][0]['expires_at'] ?? null), 'formato command/1');
ok(Db::scalar('SELECT status FROM commands WHERE id = ?', [$c4]) === 'expired' && Db::scalar('SELECT status FROM commands WHERE id = ?', [$c1]) === 'delivered', 'stati: delivered e expired');
[$rc, $out] = $runSim($ctok, $cmac, ['una-volta' => true, 'larghezza' => 320]);
$st = Db::all('SELECT id, status, result FROM commands WHERE webcam_id = ? ORDER BY id', [$cid]);
$byId = array_column($st, null, 'id');
ok($rc === 0 && $byId[$c1]['status'] === 'done' && $byId[$c2]['status'] === 'done' && $byId[$c3]['status'] === 'done', 'simulatore: esegue i comandi e manda command.ack', $out . json_encode($st));
ok(isset(json_decode((string) $byId[$c2]['result'], true)['until']) && json_decode((string) $byId[$c3]['result'], true)['new'] === '2.5', 'result degli ack (until, new)', json_encode($st));
$m = Db::one('SELECT heat_reason, dewpoint_margin FROM measurements WHERE webcam_id = ? ORDER BY ts DESC LIMIT 1', [$cid]);
ok($m !== null && (float) $m['dewpoint_margin'] === 2.5, 'telemetry.flush: campione con il nuovo margine', json_encode($m));
$ackEv = (int) Db::scalar("SELECT COUNT(*) FROM events WHERE webcam_id = ? AND type = 'command.ack'", [$cid]);
// ack ripetuto: nessun cambiamento
$evId = (int) Db::scalar("SELECT id FROM events WHERE webcam_id = ? AND type = 'command.ack' ORDER BY id LIMIT 1", [$cid]);
Commands::ack($cid, $evId, ['command_id' => "cmd-$c2", 'status' => 'failed'], time());
ok(Db::scalar('SELECT status FROM commands WHERE id = ?', [$c2]) === 'done' && $ackEv >= 3, 'ack ripetuto: stato invariato');
$r = http('GET', "$base/api/v1/commands", ["Authorization: Bearer $ctok", 'X-Webcam-Features: commands/1']);
ok($r['status'] === 200 && $r['json']['commands'] === [], 'GET /commands: coda vuota dopo gli ack', $r['body']);
try {
	for ($i = 0; $i < 12; $i++) {
		Commands::create($cid, 'telemetry.flush', [], ['by' => 'operator']);
	}
	ok(false, 'massimo 10 comandi all\'ora per webcam');
} catch (RuntimeException $e) {
	ok(true, 'massimo 10 comandi all\'ora per webcam');
}
ok(Commands::cancel($cid, (int) Db::scalar("SELECT MAX(id) FROM commands WHERE webcam_id = ? AND status = 'pending'", [$cid])), 'annulla un comando in coda');

// pulsanti del pannello
Db::exec('DELETE FROM commands WHERE webcam_id = ?', [$cid]);
$r = $page('GET', "/admin/webcams/$cid");
ok($r['status'] === 200 && str_contains($r['body'], 'Scatta ora') && str_contains($r['body'], 'Riscaldamento 30 min') && str_contains($r['body'], 'Invia telemetria'), 'scheda: pulsanti dei comandi');
foreach (['capture', 'boost', 'flush'] as $do) {
	$r2 = $page('POST', "/admin/webcams/$cid/command", ['_csrf' => $r['csrf'], 'do' => $do]);
}
$types = Db::all("SELECT type, params, created_by FROM commands WHERE webcam_id = ? ORDER BY id", [$cid]);
ok(array_column($types, 'type') === ['capture.now', 'heater.boost', 'telemetry.flush'] && json_decode($types[1]['params'], true) === ['duration_s' => 1800] && $types[0]['created_by'] === 'operator',
	'pulsanti: capture.now, heater.boost 1800 s, telemetry.flush in coda', json_encode($types));

// ------------------------------------------------------------ pannello: avvisi
section('Pannello: avvisi e scheda');
$r = $page('GET', '/admin/alerts?stato=all');
ok($r['status'] === 200 && str_contains($r['body'], 'Vetro appannato') && str_contains($r['body'], 'Scenario scenario-camera-mossa'), 'pagina Avvisi (tutti)', substr(strip_tags($r['body']), 0, 300));
$r = $page('GET', '/admin/alerts?stato=closed&regola=enclosure.seal');
ok($r['status'] === 200 && str_contains($r['body'], 'Scenario scenario-guarnizione') && !str_contains($r['body'], 'Scenario scenario-sole</a>'), 'filtro per regola');
$r = $page('GET', "/admin/alerts?stato=open&webcam={$cf['id']}");
ok($r['status'] === 200 && str_contains($r['body'], 'Nessuna foto'), 'filtro per webcam: avviso aperto');
$r = $page('GET', "/admin/webcams/{$cf['id']}/alerts/$aid");
ok($r['status'] === 200 && str_contains($r['body'], 'Prendi in carico') && str_contains($r['body'], 'alert.open'), 'dettaglio avviso con la storia');
$page('POST', "/admin/webcams/{$cf['id']}/alerts/$aid", ['_csrf' => $r['csrf'], 'do' => 'ack']);
$page('POST', "/admin/webcams/{$cf['id']}/alerts/$aid", ['_csrf' => $r['csrf'], 'do' => 'close', 'note' => 'fotocamera sostituita']);
$a = Db::one('SELECT * FROM alerts WHERE id = ?', [$aid]);
ok($a['acknowledged_at'] !== null && $a['status'] === 'closed' && $a['close_reason'] === 'operator', 'presa in carico e chiusura dall\'operatore');
$page('POST', "/admin/webcams/{$cf['id']}/alerts/$aid", ['_csrf' => $r['csrf'], 'do' => 'mute', 'days' => 7]);
ok(Db::scalar('SELECT muted_until FROM rule_state WHERE webcam_id = ? AND rule_id = ?', [$cf['id'], 'camera.stalled']) !== null, 'regola silenziata per 7 giorni');
$r = $page('GET', "/admin/webcams/$hid");
ok($r['status'] === 200 && str_contains($r['body'], 'Stato delle regole') && str_contains($r['body'], 'Nitidezza delle foto') && str_contains($r['body'], 'class="line s1"')
	&& str_contains($r['body'], 'Foto di riferimento'), 'scheda: stato regole, grafico nitidezza 7 giorni, riferimenti');
$refP = (int) Db::scalar('SELECT photo_id FROM reference_photos WHERE webcam_id = ? AND valid_to IS NULL LIMIT 1', [$hid]);
ok($page('GET', "/admin/webcams/$hid/photos/$refP.jpg")['status'] === 200 && $page('GET', "/admin/webcams/$cid/photos/$refP.jpg")['status'] === 404, 'foto dal pannello solo della sua webcam');
$mv = $scen['camera-mossa']['id'];
$page('POST', "/admin/webcams/$mv/reference", ['_csrf' => $r['csrf'], 'do' => 'framing']);
ok((int) Db::scalar('SELECT COUNT(*) FROM reference_photos WHERE webcam_id = ? AND valid_to IS NULL', [$mv]) === 0 && Metrics::resetAt($mv) > 0, 'Conferma la nuova inquadratura: riferimenti azzerati');

// ------------------------------------------------------------ disponibilità
section('Disponibilità');
$av = new Availability(time());
$dayRow = $ferDay;
ok($dayRow['expected_h'] === 24 && $dayRow['available_h'] === 24 - 5, 'camera-ferma: le 5 ore senza foto non sono disponibili', json_encode($dayRow));
ServiceSettings::save($hid, ['sla_enabled' => 1, 'sla_hours' => '08:00-18:00', 'sla_weekdays' => '1234567', 'sla_target_pct' => 98]);
$d2 = (new DateTimeImmutable('@' . ($hs + 86400)))->setTimezone($rome)->format('Y-m-d');
Db::exec("INSERT INTO events (webcam_id, ts, received_at, type, severity, source, message) VALUES (?, ?, UTC_TIMESTAMP(), 'intervention', 'notice', 'operator', 'pulizia')", [$hid, Db::dt($hs + 86400 + 10 * 3600 + 600)]);
Db::exec('INSERT INTO availability_exclusions (webcam_id, from_at, to_at, reason) VALUES (?, ?, ?, ?)', [$hid, Db::dt($hs + 86400 + 14 * 3600), Db::dt($hs + 86400 + 16 * 3600), 'lavori sul tetto']);
$hw = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$hid]);
$dayRow = $av->day($hw, ServiceSettings::get($hid), $rome, $d2);
ok($dayRow['expected_h'] === 10 && $dayRow['excluded_h'] === 3 && $dayRow['available_h'] === 7 && (float) $dayRow['pct'] === 100.0, 'ore previste 08-18, intervento ed esclusione tolti', json_encode($dayRow));
$past = (new DateTimeImmutable('first day of this month', $rome))->modify('-1 month');
$pm1 = $past->format('Y-m');
$avPast = new Availability($past->modify('+1 month +1 day')->getTimestamp()); // il 2 del mese dopo: provvisoria
Db::exec('INSERT INTO availability_daily (webcam_id, day, expected_h, excluded_h, available_h, photos, pct) VALUES (?, ?, 10, 0, 9, 20, 90.00)', [$hid, $past->format('Y-m-05')]);
$mrow = $avPast->month($hw, ServiceSettings::get($hid), $rome, $pm1);
ok($mrow !== null && (int) $mrow['final'] === 0 && (float) $mrow['pct'] === 90.0 && (int) $mrow['sla_met'] === 0, 'mese provvisorio il 2 del mese dopo, impegno non rispettato', json_encode($mrow));
$avFinal = new Availability($past->modify('+1 month +2 days')->getTimestamp() + 60);
$mrow = $avFinal->month($hw, ServiceSettings::get($hid), $rome, $pm1);
ok((int) $mrow['final'] === 1 && $mrow['dolibarr_synced_at'] === null, 'definitivo dal 3 del mese dopo, da mandare a Dolibarr');
Db::exec('UPDATE availability_daily SET available_h = 10 WHERE webcam_id = ? AND day = ?', [$hid, $past->format('Y-m-05')]);
ok((float) $avFinal->month($hw, ServiceSettings::get($hid), $rome, $pm1)['pct'] === 90.0, 'un mese definitivo non si ricalcola');
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/availability.php') . " --webcam $hid 2>&1", $out, $rc);
$cur = (new DateTimeImmutable('now', $rome))->format('Y-m');
ok($rc === 0 && Db::scalar('SELECT COUNT(*) FROM availability_monthly WHERE webcam_id = ? AND month = ?', [$hid, $cur]) == 1, 'bin/availability.php: mese in corso', implode("\n", $out));
$r = $page('GET', "/admin/webcams/$hid");
ok(str_contains($r['body'], 'questo mese, provvisoria') && str_contains($r['body'], $pm1), 'scheda: disponibilità del mese e mesi chiusi');
Db::exec('UPDATE webcams SET is_public = 1 WHERE id = ?', [$hid]);
ok(!str_contains(http('GET', "$base/cam/sana")['body'], 'Disponibilità'), 'pagina pubblica: disponibilità nascosta senza availability_public');
$writeCfg("$tmp/config-pub.php", ['availability_public' => true] + $cfgValues);
$basePub = $startServer(WEBCAM_PORTAL_ROOT . '/public/index.php', ['WEBCAM_CONFIG' => "$tmp/config-pub.php"], WEBCAM_PORTAL_ROOT . '/public');
ok(str_contains(http('GET', "$basePub/cam/sana")['body'], 'Disponibilità del servizio questo mese'), 'pagina pubblica: disponibilità con availability_public');

// ------------------------------------------------------------ ticket Dolibarr
section('Ticket Dolibarr (modulo 1.2 e 1.3)');
$tlog = "$tmp/dolibarr13.log";
$tstate = "$tmp/dolibarr13.json";
$fakeEnv = ['FAKE_DOLIBARR_LOG' => $tlog, 'FAKE_DOLIBARR_STATE' => $tstate];
$d13 = $startServer($fake, ['FAKE_DOLIBARR_MODE' => '13'] + $fakeEnv, WEBCAM_PORTAL_ROOT . '/tests');
$d12b = $startServer($fake, ['FAKE_DOLIBARR_MODE' => '12'] + $fakeEnv, WEBCAM_PORTAL_ROOT . '/tests');
$seal = $scen['guarnizione'];
$sa = $seal['alert'];
// la webcam dello scenario guarnizione diventa la webcam 12 del finto Dolibarr
Db::exec('UPDATE webcams SET mac = ?, dolibarr_id = NULL WHERE id = ?', ['94:83:c4:7f:00:01', $wid]);
Db::exec("UPDATE webcams SET mac = '94:83:c4:12:ab:cd', dolibarr_id = NULL WHERE id = ?", [$seal['id']]);
$calls = static function () use ($tlog): array {
	$c = array_map(static fn ($l) => json_decode($l, true), @file($tlog, FILE_IGNORE_NEW_LINES) ?: []);
	@unlink($tlog);
	return $c;
};
$state = static fn (): array => json_decode((string) @file_get_contents($tstate), true) ?: [];
$setTicket = static function (int $id, array $over) use ($tstate, $state): void {
	$s = $state();
	$s['tickets'][$id] = $over + $s['tickets'][$id];
	file_put_contents($tstate, json_encode($s));
};
$pendingOf = static fn (int $aid): int => (int) Db::scalar('SELECT COUNT(*) FROM events WHERE alert_id = ? AND dolibarr_synced_at IS NULL', [$aid]);
@unlink($tlog);
@unlink($tstate);
@unlink("$tmp/storage/cache/dolibarr-missing.json");
(new Availability(time()))->run($seal['id']);
ok($sa !== null && $pendingOf((int) $sa['id']) >= 2, 'eventi dell\'avviso in coda per Dolibarr');

// modulo 1.2: il ticket si apre, aggiornamenti e chiusura restano in coda
[$rc, $out] = $runSync($d12b);
$c = $calls();
$posts = array_values(array_filter($c, static fn ($x) => $x['method'] === 'POST' && $x['path'] === '/webcams/12/tickets'));
$sa = Db::one('SELECT * FROM alerts WHERE id = ?', [$sa['id']]);
$tid = (int) $sa['dolibarr_ticket_id'];
ok($rc === 0 && count($posts) === 1 && $tid > 0, 'modulo 1.2: ticket aperto, uscita 0', $out . json_encode($c));
ok(str_contains($out, 'modulo webcams < 1.3') && $pendingOf((int) $sa['id']) >= 1, 'modulo 1.2: PUT tickets mancante scritto nel log, chiusura in coda', $out);
$body = $posts[0]['body'] ?? [];
$msg = (string) ($body['message'] ?? '');
ok(($body['portal_rule'] ?? '') === 'enclosure.seal' && (int) ($body['portal_alert_id'] ?? 0) === (int) $sa['id'] && ($body['type_code'] ?? '') === 'ISSUE' && ($body['category_code'] ?? '') === 'WEBCAM',
	'POST tickets: portal_rule, portal_alert_id, tipo e categoria', json_encode($body));
ok(str_contains($msg, 'https://remote.viewlapse.com/api/connect/WC-9483C412ABCD') && str_contains($msg, "https://portal.example/admin/webcams/{$seal['id']}/alerts/{$sa['id']}")
	&& str_contains($msg, 'follow') && str_contains($msg, 'Guarnizione'), 'descrizione: dati misurati, link al portal e a rttys', $msg);
// bin/rules.php (ogni 5 minuti) non riprova l'endpoint mancante; sync-dolibarr.php sì, una volta l'ora
putenv("WEBCAM_CONFIG=$tmp/config-sync.php");
WebcamPortal\Config::set(WebcamPortal\Config::load());
$rcT = WebcamPortal\Dolibarr\TicketSync::fromConfig()->run();
putenv("WEBCAM_CONFIG=$tmp/config.php");
WebcamPortal\Config::set(WebcamPortal\Config::load());
$c = $calls();
ok($rcT === 0 && array_filter($c, static fn ($x) => str_contains($x['path'], 'tickets') && $x['method'] !== 'GET') === [], 'modulo 1.2: l\'endpoint mancante non si riprova a ogni giro delle regole', json_encode($c));

// modulo 1.3: ticket già aperto per la regola (database ripristinato) e chiusura rifiutata perché in lavorazione
@unlink("$tmp/storage/cache/dolibarr-missing.json");
$setTicket($tid, ['status' => 3]);
Db::exec('UPDATE alerts SET dolibarr_ticket_id = NULL, dolibarr_ticket_ref = NULL WHERE id = ?', [$sa['id']]);
Db::exec('UPDATE events SET dolibarr_synced_at = NULL WHERE id = ?', [$sa['open_event_id']]);
[$rc, $out] = $runSync($d13);
$c = $calls();
$sa = Db::one('SELECT * FROM alerts WHERE id = ?', [$sa['id']]);
$t = $state()['tickets'][$tid] ?? [];
ok($rc === 0 && (int) $sa['dolibarr_ticket_id'] === $tid && count($state()['tickets']) === 1, 'modulo 1.3: open_for_rule, nessun ticket doppio', $out);
ok(str_contains($out, 'in_progress') && (int) $t['status'] === 3 && $pendingOf((int) $sa['id']) === 0, 'chiusura rifiutata (close_refused: in_progress): solo il messaggio, evento inviato', $out . json_encode($t));
$svc = ServiceSettings::get($seal['id']);
ok($svc['source'] === 'dolibarr' && $svc['sla_enabled'] === 1 && $svc['sla_hours'] === '07:00-24:00' && (int) $svc['shutter_life'] === 150000 && (float) $svc['sla_target_pct'] === 97.5,
	'GET portal: soglie sla_* e vita dell\'otturatore copiate da Dolibarr', json_encode($svc));
$avp = array_values(array_filter($c, static fn ($x) => $x['method'] === 'POST' && $x['path'] === '/webcams/12/availability'));
ok($avp !== [] && isset($avp[0]['body']['period'], $avp[0]['body']['availability_pct']), 'POST availability dei mesi', json_encode($avp[0]['body'] ?? null));
// stesso giro di nuovo: niente da mandare
[$rc, $out] = $runSync($d13);
$c = $calls();
ok($rc === 0 && array_filter($c, static fn ($x) => $x['method'] !== 'GET' && preg_match('#/(tickets|availability)#', $x['path'])) === [], 'idempotente: un secondo giro non rimanda ticket né disponibilità', json_encode(array_map(static fn ($x) => $x['method'] . ' ' . $x['path'], $c)));

// ticket chiuso in Dolibarr (409) e ticket non del portal (403): l'evento non si riprova
$closeEv = (int) $sa['close_event_id'];
$setTicket($tid, ['status' => 8]);
Db::exec('UPDATE events SET dolibarr_synced_at = NULL WHERE id = ?', [$closeEv]);
[$rc, $out] = $runSync($d13);
ok($rc === 0 && $pendingOf((int) $sa['id']) === 0, 'PUT su ticket già chiuso (409): segnato come inviato, nessun errore', $out);
$setTicket($tid, ['status' => 0, 'portal' => false]);
Db::exec('UPDATE events SET dolibarr_synced_at = NULL WHERE id = ?', [$closeEv]);
[$rc, $out] = $runSync($d13);
ok($rc === 0 && $pendingOf((int) $sa['id']) === 0 && str_contains($out, '403'), 'PUT su ticket non del portal (403): non si tocca, scritto nel log', $out);
// mese definitivo già registrato: 409 = già registrato
$s = $state();
foreach (array_keys($s['availability']) as $per) {
	$s['availability'][$per]['final'] = 1;
}
file_put_contents($tstate, json_encode($s));
Db::exec('UPDATE availability_monthly SET dolibarr_synced_at = NULL WHERE webcam_id = ?', [$seal['id']]);
[$rc, $out] = $runSync($d13);
ok($rc === 0 && (int) Db::scalar('SELECT COUNT(*) FROM availability_monthly WHERE webcam_id = ? AND final = 1 AND dolibarr_synced_at IS NULL', [$seal['id']]) === 0, 'availability: 409 su un mese definitivo = già registrato', $out);
// stato dei ticket degli avvisi aperti con GET /tickets/{id}
$setTicket($tid, ['status' => 8, 'portal' => true]);
Db::exec("INSERT INTO alerts (webcam_id, rule_id, severity, action, title, opened_at, dolibarr_ticket_id, dolibarr_synced_at) VALUES (?, 'glass.dirty', 'warning', 'ticket', 'Vetro sporco (prova)', UTC_TIMESTAMP(), ?, ?)",
	[$seal['id'], $tid, Db::dt(time() - 7200)]);
$pollId = (int) Db::pdo()->lastInsertId();
$runSync($d13);
$c = $calls();
ok((int) Db::scalar('SELECT ticket_closed_by_operator FROM alerts WHERE id = ?', [$pollId]) === 1 && array_filter($c, static fn ($x) => $x['path'] === "/tickets/$tid") !== [],
	'GET /tickets/{id}: ticket chiuso in Dolibarr con l\'avviso aperto', json_encode($c));
Db::exec("UPDATE alerts SET status = 'closed', closed_at = UTC_TIMESTAMP(), close_reason = 'operator' WHERE id = ?", [$pollId]);

// ------------------------------------------------------------ VictoriaLogs
section('VictoriaLogs (logs.reboots, logs.camera_errors)');
$vlLog = "$tmp/vl.log";
$vlData = "$tmp/vl.json";
$vl = $startServer(WEBCAM_PORTAL_ROOT . '/tests/fake-victorialogs.php', ['FAKE_VL_LOG' => $vlLog, 'FAKE_VL_DATA' => $vlData], WEBCAM_PORTAL_ROOT . '/tests');
[$lid] = $newCam('logs');
[$dup1] = $newCam('doppione-1');
[$dup2] = $newCam('doppione-2');
Db::exec("UPDATE webcams SET hostname = 'WC-9483C4300001' WHERE id = ?", [$lid]);
Db::exec("UPDATE webcams SET hostname = 'WC-DOPPIONE' WHERE id IN (?, ?)", [$dup1, $dup2]);
// dati minimi perché la webcam sia "viva"
Db::exec('UPDATE webcams SET status = 9 WHERE id NOT IN (?, ?, ?)', [$lid, $dup1, $dup2]);
$runRules = static function (array $vlCfg, string $args) use ($tmp, $cfgValues, $writeCfg): array {
	$writeCfg("$tmp/config-vl.php", ['victorialogs' => $vlCfg] + $cfgValues);
	$out = [];
	exec('WEBCAM_CONFIG=' . escapeshellarg("$tmp/config-vl.php") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(WEBCAM_PORTAL_ROOT . '/bin/rules.php') . " $args 2>&1", $out, $rc);
	return [$rc, implode("\n", $out)];
};
$vlCfg = ['url' => $vl, 'user' => 'vl', 'pass' => 'vl-pass', 'timeout' => 5];
file_put_contents($vlData, json_encode(['WC-9483C4300001' => ['avvii' => 3, 'errori' => 4, 'tentativi' => 1], 'WC-DOPPIONE' => ['avvii' => 9]]));
@unlink($vlLog);
[$rc, $out] = $runRules($vlCfg, '--regole logs --logs');
$req = array_map(static fn ($l) => json_decode($l, true), file($vlLog, FILE_IGNORE_NEW_LINES) ?: []);
$q = (string) ($req[0]['post']['query'] ?? '');
ok($rc === 0 && $req !== [] && $req[0]['path'] === '/select/logsql/stats_query' && $req[0]['auth'] === 'Basic ' . base64_encode('vl:vl-pass'), 'stats_query con Basic Auth', $out);
ok(str_starts_with($q, '_time:24h') && str_contains($q, 'stats by (hostname)') && str_contains($q, 'count() if ("Linux version") avvii')
	&& str_contains($q, '"anche dopo il riavvio dalla scheda" OR "scatto fallito dopo il riavvio"') && !str_contains($q, 'router.boot'), 'query di docs/query.md: count() if, solo le righe finali', $q);
$q1 = (string) ($req[1]['post']['query'] ?? '');
$q2 = (string) ($req[2]['post']['query'] ?? '');
ok(count($req) === 3 && str_starts_with($q1, '_time:[') && str_contains($q1, 'count() righe') && str_contains($q1, 'count() if ("upload fallito") upload_falliti')
	&& str_contains($q1, 'count() if ("sensore" "non risponde") sensori') && str_contains($q2, 'stats by (hostname, _time:1h) count() righe | stats by (hostname) count() ore'),
	'giorno UTC chiuso per log_counters: query 1 e 2 di servizi-v1.md', json_encode(array_column(array_column($req, 'post'), 'query')));
$la = Db::all("SELECT rule_id, status FROM alerts WHERE webcam_id = ? ORDER BY rule_id", [$lid]);
ok($la === [['rule_id' => 'logs.camera_errors', 'status' => 'open'], ['rule_id' => 'logs.reboots', 'status' => 'open']], 'avvisi logs.camera_errors e logs.reboots dai conteggi dei log', json_encode($la) . $out);
$du = Db::all("SELECT state FROM rule_state WHERE webcam_id IN (?, ?) AND rule_id = 'logs.reboots'", [$dup1, $dup2]);
ok(count($du) === 2 && array_unique(array_column($du, 'state')) === ['unknown'] && (int) Db::scalar('SELECT COUNT(*) FROM alerts WHERE webcam_id IN (?, ?)', [$dup1, $dup2]) === 0, 'hostname non univoco: dati insufficienti, nessun avviso');
ok(Db::scalar('SELECT log_ok FROM log_counters WHERE webcam_id = ? LIMIT 1', [$lid]) == 1, 'log_counters del giorno chiuso');
$lc = Db::one('SELECT boots_log, camera_errors_log, uploads_ok, ntp_issues, sensor_errors_log, log_lines, log_hours FROM log_counters WHERE webcam_id = ? AND day = ?', [$lid, gmdate('Y-m-d', time() - 86400)]);
ok($lc !== null && (int) $lc['boots_log'] === 3 && (int) $lc['camera_errors_log'] === 4 && $lc['uploads_ok'] !== null && $lc['log_lines'] !== null && $lc['log_hours'] !== null, 'log_counters: colonne nuove della migrazione 004 scritte', json_encode($lc));
// conteggi a zero (hostname senza righe = 0): gli avvisi si chiudono
file_put_contents($vlData, json_encode([]));
[$rc, $out] = $runRules($vlCfg, '--regole logs --logs');
$la = Db::all("SELECT rule_id, status FROM alerts WHERE webcam_id = ? ORDER BY rule_id", [$lid]);
ok($rc === 0 && array_unique(array_column($la, 'status')) === ['closed'], 'hostname senza righe vale 0: avvisi chiusi', json_encode($la) . $out);
// cache: entro 15 minuti nessuna nuova query
@unlink($vlLog);
$runRules($vlCfg, '--regole logs');
ok(!is_file($vlLog), 'una query ogni 15 minuti (cache)');
// password sbagliata e server spento: nessun crash, regole con i soli eventi
[$rc, $out] = $runRules(['pass' => 'sbagliata'] + $vlCfg, '--regole logs --logs');
$st = Db::scalar("SELECT JSON_VALUE(last_value, '$.logs') FROM rule_state WHERE webcam_id = ? AND rule_id = 'logs.reboots'", [$lid]);
ok($rc === 0 && str_contains($out, 'VictoriaLogs 401') && $st === 'down', 'VictoriaLogs rifiuta la password: avviso nel log, regole con gli eventi', $out . " stato=$st");
[$rc, $out] = $runRules(['url' => 'http://127.0.0.1:1', 'timeout' => 2] + $vlCfg, '--regole logs --logs');
ok($rc === 0 && str_contains($out, 'non raggiungibile'), 'VictoriaLogs spento: nessun crash', $out);
[$rc, $out] = $runRules(['url' => ''] + $vlCfg, '--regole logs --logs');
$st = Db::scalar("SELECT JSON_VALUE(last_value, '$.logs') FROM rule_state WHERE webcam_id = ? AND rule_id = 'logs.reboots'", [$lid]);
ok($rc === 0 && $st === 'off', 'URL vuoto: VictoriaLogs spento', "stato=$st");
