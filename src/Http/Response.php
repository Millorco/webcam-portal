<?php

declare(strict_types=1);

namespace WebcamPortal\Http;

final class Response
{
	/** @param array<string,mixed> $data */
	public static function json(int $status, array $data, array $headers = []): void
	{
		if (!array_key_exists('server_time', $data)) {
			$data['server_time'] = time();
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		foreach ($headers as $k => $v) {
			header($k . ': ' . $v);
		}
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
	}

	public static function html(int $status, string $html): void
	{
		http_response_code($status);
		header('Content-Type: text/html; charset=utf-8');
		echo $html;
	}

	public static function redirect(string $url, int $status = 303): void
	{
		http_response_code($status);
		header('Location: ' . $url);
	}

	public static function notFound(): void
	{
		self::html(404, '<!doctype html><meta charset="utf-8"><title>Non trovato</title><p>Pagina non trovata.</p>');
	}
}
