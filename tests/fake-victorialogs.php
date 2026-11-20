<?php
/**
 * Finta VictoriaLogs per i test (php -S ... tests/fake-victorialogs.php):
 * /select/logsql/stats_query con Basic Auth vl:vl-pass, risponde con i
 * conteggi per hostname del file FAKE_VL_DATA ({"WC-...": {"avvii": 3, ...}})
 * nel formato di VictoriaLogs (come Prometheus /api/v1/query). Ogni
 * richiesta è aggiunta come riga JSON a FAKE_VL_LOG.
 */

declare(strict_types=1);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents((string) getenv('FAKE_VL_LOG'), json_encode([
	'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'post' => $_POST,
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('vl:vl-pass')) {
	http_response_code(401);
	echo 'Unauthorized';
	return;
}
if ($path !== '/select/logsql/stats_query' || !isset($_POST['query'])) {
	http_response_code(400);
	echo 'missing query';
	return;
}
$data = json_decode((string) @file_get_contents((string) getenv('FAKE_VL_DATA')), true) ?: [];
$res = [];
foreach ($data as $host => $c) {
	foreach ($c as $name => $v) {
		$res[] = ['metric' => ['__name__' => $name, 'hostname' => $host], 'value' => [(int) ($_POST['time'] ?? time()), (string) $v]];
	}
}
echo json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $res]]);
