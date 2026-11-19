<?php
/**
 * Finto Dolibarr per i test (php -S ... tests/fake-dolibarr.php).
 * FAKE_DOLIBARR_MODE=11: modulo webcams 1.1, senza endpoint v2 (404 di Restler).
 * FAKE_DOLIBARR_MODE=12: modulo 1.2 con gli endpoint v2 del contratto (ticket solo POST).
 * FAKE_DOLIBARR_MODE=13: modulo 1.3: GET portal, PUT tickets (open_for_rule,
 * close_refused, 403, 409), POST availability (409 sui mesi definitivi) e
 * GET /tickets/{id}, GET /thirdparties/57. Ticket e mesi stanno in FAKE_DOLIBARR_STATE (JSON),
 * condiviso tra i finti 1.2 e 1.3.
 * Ogni richiesta è aggiunta come riga JSON a FAKE_DOLIBARR_LOG.
 */

declare(strict_types=1);

$mode = getenv('FAKE_DOLIBARR_MODE') ?: '11';
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/index\.php#', '', $path);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true);
file_put_contents((string) getenv('FAKE_DOLIBARR_LOG'), json_encode([
	'method' => $method, 'path' => $path, 'query' => $_GET, 'key' => $_SERVER['HTTP_DOLAPIKEY'] ?? null, 'body' => $body,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
$fail = static function (int $code, string $msg): void {
	http_response_code($code);
	echo json_encode(['error' => ['code' => $code, 'message' => $msg]]);
};
if (($_SERVER['HTTP_DOLAPIKEY'] ?? '') !== 'chiave-di-prova') {
	$fail(401, 'Unauthorized: Access denied');
	return;
}
$webcam = ['id' => 12, 'ref' => 'WC-9483C412ABCD', 'mac' => '94:83:c4:12:ab:cd'];

if ($method === 'GET' && $path === '/webcams') {
	$f = (string) ($_GET['sqlfilters'] ?? '');
	echo json_encode(str_contains($f, $webcam['mac']) ? [$webcam] : []);
	return;
}
$stateFile = (string) (getenv('FAKE_DOLIBARR_STATE') ?: sys_get_temp_dir() . '/fake-dolibarr-' . getmypid() . '.json');
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state += ['next' => 341, 'tickets' => [], 'availability' => []];
$save = static function () use (&$state, $stateFile): void {
	file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
};
if (in_array($mode, ['12', '13'], true) && $method === 'POST' && $path === '/webcams/12/tickets') {
	$rule = $mode === '13' ? ($body['portal_rule'] ?? null) : null;
	if ($rule !== null && (strlen((string) $rule) > 64 || !preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_.]+$/', (string) $rule))) {
		$fail(400, 'Bad Request: portal_rule non valido');
		return;
	}
	foreach ($state['tickets'] as $id => $t) {
		if ($t['ref_ext'] === 'portal:' . ($body['portal_event_id'] ?? '')) {
			echo json_encode(['ok' => true, 'ticket_id' => (int) $id, 'ticket_ref' => $t['ref'], 'duplicate' => true, 'status' => $t['status']]);
			return;
		}
		if ($rule !== null && $t['rule'] === $rule && !in_array($t['status'], [8, 9], true)) {
			$state['tickets'][$id]['messages'][] = $body['message'] ?? '';
			$save();
			echo json_encode(['ok' => true, 'ticket_id' => (int) $id, 'ticket_ref' => $t['ref'], 'duplicate' => true, 'reason' => 'open_for_rule', 'status' => $t['status']]);
			return;
		}
	}
	$id = $state['next']++;
	$state['tickets'][$id] = ['ref' => sprintf('TS2611-%04d', $id), 'ref_ext' => 'portal:' . ($body['portal_event_id'] ?? ''), 'rule' => $mode === '13' ? $rule : null,
		'status' => 0, 'portal' => true, 'messages' => [$body['message'] ?? ''], 'events' => []];
	$save();
	echo json_encode(['ok' => true, 'ticket_id' => $id, 'ticket_ref' => $state['tickets'][$id]['ref'], 'duplicate' => false] + ($mode === '13' ? ['status' => 0] : []));
	return;
}
if ($mode === '13') {
	if ($method === 'GET' && $path === '/webcams/12/portal') {
		echo json_encode(['ok' => true, 'id' => 12, 'ref' => $webcam['ref'], 'status' => 1, 'customer' => ['id' => 57, 'name' => 'Impresa di prova'],
			'portal' => ['portal_slug' => 'x', 'url_current' => null, 'windy_published' => 0, 'windy_id' => null],
			'sla' => ['enabled' => true, 'offline_hours' => 4, 'response_days' => 2, 'target_pct' => 97.5, 'hours' => '07:00-24:00', 'weekdays' => '123456'],
			'shutter' => ['life' => 150000, 'warn_pct' => 85, 'used' => 48213, 'level' => ''], 'rttys' => (object) []]);
		return;
	}
	if ($method === 'GET' && $path === '/thirdparties/57') {
		echo json_encode(['id' => '57', 'name' => 'Impresa di prova', 'code_client' => 'CU2611-0042', 'email' => 'info@impresa.example', 'town' => 'Frascati', 'status' => '1']);
		return;
	}
	if ($method === 'PUT' && preg_match('#^/webcams/12/tickets/(\d+)$#', $path, $m)) {
		$t = $state['tickets'][$m[1]] ?? null;
		if ($t === null || !$t['portal']) {
			$fail(403, 'Forbidden: ticket non aperto dal portal');
			return;
		}
		if (in_array($t['status'], [8, 9], true)) {
			$fail(409, 'Conflict: ticket già chiuso');
			return;
		}
		if (in_array($body['portal_event_id'] ?? null, $t['events'], true)) {
			echo json_encode(['ok' => true, 'ticket_id' => (int) $m[1], 'status' => $t['status'], 'closed' => false, 'duplicate' => true]);
			return;
		}
		$t['events'][] = $body['portal_event_id'] ?? null;
		$t['messages'][] = $body['message'] ?? '';
		$res = ['ok' => true, 'ticket_id' => (int) $m[1], 'closed' => false, 'duplicate' => false];
		if (!empty($body['close'])) {
			if ($t['status'] <= 1) {
				$t['status'] = 8;
				$res['closed'] = true;
			} else {
				$res['close_refused'] = 'in_progress';
			}
		}
		$state['tickets'][$m[1]] = $t;
		$save();
		echo json_encode($res + ['status' => $t['status']]);
		return;
	}
	if ($method === 'GET' && preg_match('#^/tickets/(\d+)$#', $path, $m)) {
		$t = $state['tickets'][$m[1]] ?? null;
		if ($t === null) {
			$fail(404, 'Not Found');
			return;
		}
		echo json_encode(['id' => (int) $m[1], 'ref' => $t['ref'], 'fk_statut' => (string) $t['status']]);
		return;
	}
	if ($method === 'POST' && $path === '/webcams/12/availability') {
		$per = (string) ($body['period'] ?? '');
		if (!empty($state['availability'][$per]['final'])) {
			$fail(409, 'Conflict: mese già definitivo');
			return;
		}
		$created = !isset($state['availability'][$per]);
		$state['availability'][$per] = $body;
		$save();
		echo json_encode(['ok' => true, 'id' => 12, 'period' => $per, 'final' => !empty($body['final']), 'updated' => !$created, 'created' => $created]);
		return;
	}
}
if ($mode === '12' || $mode === '13') {
	if ($method === 'GET' && preg_match('#^/webcams/bymac/([0-9a-f]{12})$#', $path, $m)) {
		if ($m[1] === '9483c412abcd') {
			echo json_encode($webcam);
		} else {
			$fail(404, 'Not Found: Webcam not found');
		}
		return;
	}
	if ($method === 'PUT' && $path === '/webcams/12/portal') {
		echo json_encode(['ok' => true, 'id' => 12]);
		return;
	}
	if ($method === 'POST' && $path === '/webcams/12/counters') {
		echo json_encode(['ok' => true, 'id' => 12, 'updated' => array_keys((array) ($body['power'] ?? [])), 'ignored' => []]);
		return;
	}
	if ($method === 'POST' && $path === '/webcams/12/events') {
		echo json_encode(['ok' => true, 'id' => 12, 'actioncomm_id' => 8800 + (int) ($body['portal_event_id'] ?? 0), 'duplicate' => false]);
		return;
	}
}
$fail(404, 'Not Found');
