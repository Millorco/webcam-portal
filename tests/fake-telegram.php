<?php
/**
 * Finta API dei bot di Telegram per i test (php -S ... tests/fake-telegram.php):
 * /bot<token>/<metodo>. Con il token "token-di-prova" risponde ok a
 * sendMessage, sendPhoto, setWebhook, getUpdates (vuoto), answerCallbackQuery;
 * la chat 403 risponde 403 (bot bloccato). Ogni richiesta (senza il
 * contenuto dei file) è aggiunta come riga JSON a FAKE_TG_LOG.
 */

declare(strict_types=1);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (!preg_match('#^/bot([^/]+)/(\w+)$#', $path, $m)) {
	http_response_code(404);
	echo '{"ok":false,"error_code":404,"description":"Not Found"}';
	return;
}
$params = $_POST;
if ($params === []) {
	$j = json_decode((string) file_get_contents('php://input'), true);
	$params = is_array($j) ? $j : [];
}
file_put_contents((string) getenv('FAKE_TG_LOG'), json_encode([
	'token_ok' => $m[1] === 'token-di-prova', 'method' => $m[2], 'params' => $params,
	'files' => array_map(static fn ($f) => ['name' => $f['name'], 'size' => $f['size'], 'type' => @mime_content_type($f['tmp_name'])], $_FILES),
]) . "\n", FILE_APPEND);
if ($m[1] !== 'token-di-prova') {
	http_response_code(401);
	echo '{"ok":false,"error_code":401,"description":"Unauthorized"}';
	return;
}
if ((string) ($params['chat_id'] ?? '') === '403') {
	http_response_code(403);
	echo '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}';
	return;
}
$result = match ($m[2]) {
	'getUpdates' => [],
	'setWebhook', 'answerCallbackQuery', 'deleteWebhook' => true,
	default => ['message_id' => random_int(1000, 9999), 'chat' => ['id' => (int) ($params['chat_id'] ?? 0)], 'date' => time()],
};
echo json_encode(['ok' => true, 'result' => $result]);
