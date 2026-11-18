<?php
/**
 * Finto Dolibarr per i test (php -S ... tests/fake-dolibarr.php).
 * FAKE_DOLIBARR_MODE=11: modulo webcams 1.1, senza endpoint v2 (404 di Restler).
 * FAKE_DOLIBARR_MODE=12: modulo 1.2 con gli endpoint v2 del contratto.
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
if ($mode === '12') {
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
