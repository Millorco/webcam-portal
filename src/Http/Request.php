<?php

declare(strict_types=1);

namespace WebcamPortal\Http;

use WebcamPortal\Config;

/**
 * Richiesta HTTP corrente: metodo, percorso relativo alla sottocartella del
 * sito, intestazioni, HTTPS (anche dietro proxy).
 */
final class Request
{
	public string $method;
	/** Percorso senza la sottocartella, es. /api/v1/photo */
	public string $path;
	/** Sottocartella del sito, es. /webcam-portal ('' alla radice) */
	public string $basePath;

	public function __construct(private Config $config)
	{
		$this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
		$uri = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
		$this->basePath = $this->detectBasePath($uri);
		$path = substr($uri, strlen($this->basePath));
		$this->path = '/' . ltrim($path === false ? '' : $path, '/');
	}

	private function detectBasePath(string $uri): string
	{
		$cfg = rtrim((string) $this->config->value('base_path', ''), '/');
		if ($cfg !== '') {
			return $cfg;
		}
		$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
		if (basename($script) !== 'index.php') {
			return ''; // server integrato di PHP: SCRIPT_NAME può essere il percorso richiesto
		}
		$dir = rtrim(dirname($script), '/');
		if ($dir === '' || $dir === '.') {
			return '';
		}
		if ($uri === $dir || str_starts_with($uri, $dir . '/')) {
			return $dir;
		}
		// XAMPP: la cartella del repository riscrive tutto su public/
		// (http://localhost/webcam-portal/ -> .../webcam-portal/public/index.php)
		if (str_ends_with($dir, '/public')) {
			$parent = substr($dir, 0, -7);
			if ($parent === '' || $uri === $parent || str_starts_with($uri, $parent . '/')) {
				return $parent;
			}
		}
		return '';
	}

	public function header(string $name): ?string
	{
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		if (isset($_SERVER[$key])) {
			return (string) $_SERVER[$key];
		}
		if ($name === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) {
			return (string) $_SERVER['CONTENT_TYPE'];
		}
		if ($name === 'Content-Length' && isset($_SERVER['CONTENT_LENGTH'])) {
			return (string) $_SERVER['CONTENT_LENGTH'];
		}
		if ($name === 'Authorization') {
			// Apache con mod_php a volte non passa Authorization (vedi .htaccess)
			if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
				return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			}
			if (function_exists('apache_request_headers')) {
				foreach ((array) apache_request_headers() as $k => $v) {
					if (strcasecmp((string) $k, 'Authorization') === 0) {
						return (string) $v;
					}
				}
			}
		}
		return null;
	}

	public function isHttps(): bool
	{
		if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
			return true;
		}
		if ($this->config->value('trust_proxy')) {
			$proto = strtolower(trim(explode(',', (string) $this->header('X-Forwarded-Proto'))[0]));
			return $proto === 'https';
		}
		return false;
	}

	public function clientIp(): string
	{
		if ($this->config->value('trust_proxy')) {
			$fwd = $this->header('X-Forwarded-For');
			if ($fwd) {
				$ip = trim(explode(',', $fwd)[0]);
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}
		return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
	}

	/** URL assoluto della radice del sito, senza barra finale. */
	public function baseUrl(): string
	{
		$cfg = rtrim((string) $this->config->value('public_base_url', ''), '/');
		if ($cfg !== '') {
			return $cfg;
		}
		$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
		if ($this->config->value('trust_proxy') && $this->header('X-Forwarded-Host')) {
			$host = trim(explode(',', (string) $this->header('X-Forwarded-Host'))[0]);
		}
		return ($this->isHttps() ? 'https' : 'http') . '://' . $host . $this->basePath;
	}

	public function url(string $path): string
	{
		return $this->basePath . $path;
	}

	public function contentLength(): int
	{
		return (int) ($this->header('Content-Length') ?? 0);
	}
}
