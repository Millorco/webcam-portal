<?php

declare(strict_types=1);

namespace WebcamPortal\Telegram;

use WebcamPortal\Config;
use WebcamPortal\Log;

/**
 * Client minimo dell'API dei bot di Telegram. Il token è nell'URL: non si
 * scrive mai nei log (solo il nome del metodo).
 */
final class Api
{
	public function __construct(private string $token, private string $base = 'https://api.telegram.org', private int $timeout = 30)
	{
		$this->base = rtrim($base, '/');
	}

	public static function fromConfig(): ?self
	{
		$c = Config::get();
		$t = trim((string) $c->value('telegram.bot_token', ''));
		if ($t === '') {
			return null;
		}
		return new self($t, (string) $c->value('telegram.api_url', 'https://api.telegram.org'));
	}

	/**
	 * Chiama un metodo. Con $file (campo => percorso) manda multipart.
	 *
	 * @param array<string,mixed> $params
	 * @param array<string,string> $files
	 * @return array{status:int, body:?array}
	 */
	public function call(string $method, array $params = [], array $files = [], ?int $timeout = null): array
	{
		$url = $this->base . '/bot' . $this->token . '/' . $method;
		foreach ($params as $k => $v) {
			if (is_array($v)) {
				$params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
		}
		if ($files !== []) {
			$b = '----vl' . bin2hex(random_bytes(8));
			$body = '';
			foreach ($params as $k => $v) {
				$body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n" . $v . "\r\n";
			}
			foreach ($files as $k => $path) {
				$body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"" . basename($path) . "\"\r\nContent-Type: image/jpeg\r\n\r\n"
					. (string) file_get_contents($path) . "\r\n";
			}
			$body .= "--$b--\r\n";
			$ct = "multipart/form-data; boundary=$b";
		} else {
			$body = http_build_query($params);
			$ct = 'application/x-www-form-urlencoded';
		}
		$ctx = stream_context_create(['http' => [
			'method' => 'POST', 'header' => "Content-Type: $ct\r\nUser-Agent: webcam-portal/" . WEBCAM_PORTAL_VERSION,
			'content' => $body, 'timeout' => $timeout ?? $this->timeout, 'ignore_errors' => true,
		]]);
		$http_response_header = [];
		$raw = @file_get_contents($url, false, $ctx);
		$status = 0;
		foreach ($http_response_header as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
			}
		}
		if ($raw === false && $status === 0) {
			Log::warning("Telegram $method: rete non raggiungibile", 'telegram');
		}
		$j = json_decode((string) $raw, true);
		return ['status' => $status, 'body' => is_array($j) ? $j : null];
	}
}
