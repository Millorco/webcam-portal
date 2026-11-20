<?php

declare(strict_types=1);

namespace WebcamPortal\Dolibarr;

/**
 * Client HTTP minimo per le API REST di Dolibarr (intestazione DOLAPIKEY).
 * Usa i flussi di PHP (nessuna estensione richiesta oltre openssl per HTTPS).
 */
final class Client
{
	public function __construct(
		private string $baseUrl,
		private string $apiKey,
		private int $timeout = 20,
		private bool $verifyTls = true
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	public function get(string $path, array $query = []): mixed
	{
		return $this->request('GET', $path, null, $query);
	}

	public function post(string $path, array $body): mixed
	{
		return $this->request('POST', $path, $body);
	}

	public function put(string $path, array $body): mixed
	{
		return $this->request('PUT', $path, $body);
	}

	public function request(string $method, string $path, ?array $body = null, array $query = []): mixed
	{
		$url = $this->baseUrl . '/' . ltrim($path, '/');
		if ($query !== []) {
			$url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
		}
		$headers = [
			'DOLAPIKEY: ' . $this->apiKey,
			'Accept: application/json',
			'User-Agent: webcam-portal/' . (defined('WEBCAM_PORTAL_VERSION') ? WEBCAM_PORTAL_VERSION : '0'),
		];
		// Con la lingua di Dolibarr su "auto" le API scelgono la lingua da
		// Accept-Language: senza, eventi e testi in agenda uscirebbero in inglese.
		$lang = (string) \WebcamPortal\Config::get()->value('dolibarr.lang', 'it-IT');
		if ($lang !== '' && preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z]{2})?$/', $lang)) {
			$headers[] = 'Accept-Language: ' . str_replace('_', '-', $lang);
		}
		$content = null;
		if ($body !== null) {
			$content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
			$headers[] = 'Content-Type: application/json';
		}
		$ctx = stream_context_create([
			'http' => [
				'method' => $method,
				'header' => implode("\r\n", $headers),
				'content' => $content ?? '',
				'timeout' => $this->timeout,
				'ignore_errors' => true,
				'follow_location' => 0,
			],
			'ssl' => ['verify_peer' => $this->verifyTls, 'verify_peer_name' => $this->verifyTls],
		]);
		$http_response_header = [];
		$raw = @file_get_contents($url, false, $ctx);
		$hdrs = $http_response_header;
		if ($raw === false && $hdrs === []) {
			$err = error_get_last()['message'] ?? 'errore di rete';
			throw new DolibarrException(0, "$method $path: $err");
		}
		$status = 0;
		foreach ($hdrs as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1]; // l'ultima riga di stato vince
			}
		}
		$data = json_decode((string) $raw, true);
		if ($status < 200 || $status >= 300) {
			$e = new DolibarrException($status, "$method $path: HTTP $status", is_array($data) ? $data : null);
			throw new DolibarrException($status, "$method $path: HTTP $status " . $e->apiMessage(), $e->body);
		}
		return $data;
	}
}
