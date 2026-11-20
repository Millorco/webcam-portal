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
use WebcamPortal\Service\Commands;
use WebcamPortal\Site\Images;
use WebcamPortal\Site\Media;
use WebcamPortal\Site\SiteApi;
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

		if ($path === '/api/site/v1' || str_starts_with($path, '/api/site/v1/')) {
			SiteApi::handle($req);
			return;
		}
		if (preg_match('#^/img/(\d{1,19})/(320|640|1280|1920|orig|sq1080|p1350|s1920)\.jpg$#', $path, $m)) {
			self::image($req, (int) $m[1], $m[2]);
			return;
		}
		if (preg_match(Media::ROUTE, $path, $m)) {
			self::media($req, $m[1], $m[2], $m[3]);
			return;
		}
		if ($path === '/telegram/webhook') {
			self::telegram($req);
			return;
		}
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

	/** Foto ridotte con URL firmato (api-siti-v1.md, "Immagini"). */
	private static function image(Request $req, int $photoId, string $size): void
	{
		try {
			Images::serve($req, $photoId, $size);
		} catch (ApiError $e) {
			Response::json($e->status, ['ok' => false, 'error' => $e->error, 'message' => $e->getMessage()], $e->headers + ['Access-Control-Allow-Origin' => '*']);
		} catch (\Throwable $e) {
			Log::error("immagine $photoId/$size: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'site-api');
			Response::json(500, ['ok' => false, 'error' => 'server_error', 'message' => 'Errore del server']);
		}
	}

	/** Video, poster, loghi e documenti con URL firmato (api-siti-v1.md 1.1, "File dei servizi"). */
	private static function media(Request $req, string $type, string $id, string $ext): void
	{
		try {
			Media::serve($req, $type, $id, $ext);
		} catch (ApiError $e) {
			Response::json($e->status, ['ok' => false, 'error' => $e->error, 'message' => $e->getMessage()], $e->headers + ['Access-Control-Allow-Origin' => '*']);
		} catch (\Throwable $e) {
			Log::error("file $type/$id.$ext: " . get_class($e) . ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine(), 'site-api');
			Response::json(500, ['ok' => false, 'error' => 'server_error', 'message' => 'Errore del server']);
		}
	}

	/** POST /telegram/webhook (api-portal-v1.md 1.2). */
	private static function telegram(Request $req): void
	{
		if ($req->method !== 'POST') {
			Response::json(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Solo POST'], ['Allow' => 'POST']);
			return;
		}
		try {
			$body = (string) file_get_contents('php://input', false, null, 0, 1048576);
			if (\WebcamPortal\Telegram\Bot::webhook($req->header('X-Telegram-Bot-Api-Secret-Token'), $body) === 403) {
				Response::json(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Segreto del webhook non valido']);
			}
		} catch (\Throwable $e) {
			Log::error('webhook Telegram: ' . get_class($e) . ' ' . $e->getMessage(), 'telegram');
			if (!headers_sent()) {
				Response::json(200, ['ok' => true]);
			}
		}
	}

	/**
	 * Comandi in attesa nelle risposte di successo (api-portal-v1.md 1.1):
	 * solo con X-Webcam-Features: commands/1 e token con scope "commands".
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function withCommands(Request $req, array $data, bool $always): array
	{
		$auth = TokenAuth::$last;
		if ($auth === null || !Commands::routerAccepts($req->header('X-Webcam-Features'), $auth['scopes'])) {
			return $data;
		}
		try {
			$d = Commands::deliver((int) $auth['webcam']['id']);
		} catch (\Throwable $e) {
			// i comandi non devono mai far fallire l'invio di foto o dati
			Log::error('comandi non consegnati: ' . $e->getMessage());
			return $data;
		}
		if ($d['commands'] !== [] || $always) {
			$data['commands'] = $d['commands'];
		}
		if ($d['poll_after'] !== null) {
			$data['poll_after'] = $d['poll_after'];
		}
		return $data;
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
				case 'GET /api/v1/commands':
					TokenAuth::authenticate($req, '');
					$status = 200;
					$data = ['ok' => true, 'commands' => []];
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
					$known = ['/api/v1/photo', '/api/v1/telemetry', '/api/v1/event', '/api/v1/whoami', '/api/v1/commands'];
					if (in_array(rtrim($req->path, '/'), $known, true)) {
						throw new ApiError(405, 'method_not_allowed', 'Metodo non ammesso');
					}
					throw new ApiError(404, 'not_found', 'Endpoint inesistente');
			}
			if ($status < 300 && $route !== 'GET /api/v1/whoami') {
				$data = self::withCommands($req, $data, $route === 'GET /api/v1/commands');
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
