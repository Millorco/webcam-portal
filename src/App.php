<?php

declare(strict_types=1);

namespace WebcamPortal;

use WebcamPortal\Admin\AdminController;
use WebcamPortal\Api\ApiError;
use WebcamPortal\Api\EventApi;
use WebcamPortal\Api\PhotoApi;
use WebcamPortal\Api\TelemetryApi;
use WebcamPortal\Api\TokenAuth;
use WebcamPortal\Http\Request;
use WebcamPortal\Http\Response;
use WebcamPortal\Web\PublicController;

/**
 * Smistamento delle richieste (front controller public/index.php).
 */
final class App
{
	public static function run(): void
	{
		$config = Config::get();
		$req = new Request($config);
		$path = $req->path;

		if (str_starts_with($path, '/api/')) {
			self::api($req, $config);
			return;
		}
		try {
			if (preg_match('#^/cam/([a-z0-9][a-z0-9-]{0,63})/current\.jpg$#', $path, $m)) {
				PublicController::currentJpg($req, $m[1]);
				return;
			}
			if (preg_match('#^/cam/([a-z0-9][a-z0-9-]{0,63})/current\.json$#', $path, $m)) {
				PublicController::currentJson($req, $m[1]);
				return;
			}
			if (preg_match('#^/cam/([a-z0-9][a-z0-9-]{0,63})/?$#', $path, $m)) {
				PublicController::page($req, $m[1]);
				return;
			}
			if ($path === '/' || $path === '') {
				Response::redirect($req->url('/admin/'), 302);
				return;
			}
			if ($path === '/admin' || str_starts_with($path, '/admin/')) {
				(new AdminController($req))->dispatch();
				return;
			}
			Response::notFound();
		} catch (\Throwable $e) {
			Log::error('errore pagina ' . $path . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
			Response::html(500, '<!doctype html><meta charset="utf-8"><title>Errore</title><p>Errore del server.</p>');
		}
	}

	private static function api(Request $req, Config $config): void
	{
		try {
			if (!$config->isDev() && !$req->isHttps()) {
				throw new ApiError(403, 'https_required', 'Le API accettano solo HTTPS');
			}
			$route = $req->method . ' ' . rtrim($req->path, '/');
			switch ($route) {
				case 'POST /api/v1/photo':
					[$status, $data] = PhotoApi::handle($req);
					break;
				case 'POST /api/v1/telemetry':
					[$status, $data] = TelemetryApi::handle($req);
					break;
				case 'POST /api/v1/event':
					[$status, $data] = EventApi::handle($req);
					break;
				case 'GET /api/v1/whoami':
					$auth = TokenAuth::authenticate($req, '');
					$w = $auth['webcam'];
					$status = 200;
					$data = [
						'ok' => true,
						'webcam' => ['id' => (int) $w['id'], 'slug' => $w['slug'], 'mac' => $w['mac'], 'name' => $w['name']],
						'scopes' => $auth['scopes'],
					];
					break;
				default:
					$known = ['/api/v1/photo', '/api/v1/telemetry', '/api/v1/event', '/api/v1/whoami'];
					if (in_array(rtrim($req->path, '/'), $known, true)) {
						throw new ApiError(405, 'method_not_allowed', 'Metodo non ammesso');
					}
					throw new ApiError(404, 'not_found', 'Endpoint inesistente');
			}
			Response::json($status, $data);
		} catch (ApiError $e) {
			if ($e->status >= 500) {
				Log::error("api {$req->path}: {$e->error} {$e->getMessage()}");
			} elseif ($e->status !== 404) {
				Log::warning("api {$req->path} {$e->status} {$e->error}: {$e->getMessage()} ip=" . $req->clientIp());
			}
			Response::json($e->status, ['ok' => false, 'error' => $e->error, 'message' => $e->getMessage()] + $e->extra, $e->headers);
		} catch (\Throwable $e) {
			Log::error("api {$req->path}: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
			$status = $e instanceof \PDOException ? 503 : 500;
			Response::json($status, ['ok' => false, 'error' => 'server_error', 'message' => 'Errore del server, riprovare più tardi']);
		}
	}
}
