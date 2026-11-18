<?php

declare(strict_types=1);

namespace WebcamPortal\Admin;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Request;
use WebcamPortal\Http\Response;
use WebcamPortal\Log;
use WebcamPortal\Service\PhotoStore;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;
use WebcamPortal\Web\PublicController;
use WebcamPortal\Web\View;

/**
 * Pannello operatore minimo (/admin/...): login, elenco webcam, scheda,
 * creazione webcam e rigenerazione del token.
 */
final class AdminController
{
	private ?array $user = null;

	public function __construct(private Request $req)
	{
	}

	public function dispatch(): void
	{
		$this->startSession();
		$p = rtrim(substr($this->req->path, strlen('/admin')), '/');
		$m = $this->req->method;

		if ($p === '/login') {
			$m === 'POST' ? $this->loginPost() : $this->loginForm();
			return;
		}
		$this->user = $this->currentUser();
		if ($this->user === null) {
			Response::redirect($this->req->url('/admin/login'));
			return;
		}
		if ($m === 'POST' && !$this->checkCsrf()) {
			Response::html(400, 'Richiesta non valida (CSRF): ricarica la pagina e riprova.');
			return;
		}
		if ($p === '/logout' && $m === 'POST') {
			$_SESSION = [];
			session_regenerate_id(true);
			Response::redirect($this->req->url('/admin/login'));
			return;
		}
		if ($p === '') {
			$this->index();
			return;
		}
		if ($p === '/webcams/new') {
			$m === 'POST' ? $this->createPost() : $this->createForm();
			return;
		}
		if (preg_match('#^/webcams/(\d+)(?:/(edit|token|current\.jpg|events/(\d+)))?$#', $p, $mm)) {
			$id = (int) $mm[1];
			$w = Db::one('SELECT * FROM webcams WHERE id = ?', [$id]);
			if ($w === null) {
				Response::notFound();
				return;
			}
			$action = $mm[2] ?? '';
			if ($action === '' && $m === 'GET') {
				$this->show($w);
			} elseif ($action === 'edit' && $m === 'POST') {
				$this->editPost($w);
			} elseif ($action === 'token' && $m === 'POST') {
				$this->tokenPost($w);
			} elseif ($action === 'current.jpg') {
				$this->currentJpg($w);
			} elseif (str_starts_with($action, 'events/')) {
				Response::redirect($this->req->url('/admin/webcams/' . $id . '#ev-' . (int) $mm[3]));
			} else {
				Response::notFound();
			}
			return;
		}
		Response::notFound();
	}

	// ------------------------------------------------------------ sessione

	private function startSession(): void
	{
		$dir = Config::get()->storageDir() . '/sessions';
		if (!is_dir($dir)) {
			@mkdir($dir, 0770, true);
		}
		if (is_dir($dir) && is_writable($dir)) {
			session_save_path($dir);
		}
		session_name('wcportal');
		session_set_cookie_params([
			'lifetime' => 0,
			'path' => ($this->req->basePath === '' ? '' : $this->req->basePath) . '/admin',
			'secure' => $this->req->isHttps(),
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		session_start();
		if (empty($_SESSION['csrf'])) {
			$_SESSION['csrf'] = bin2hex(random_bytes(16));
		}
	}

	private function currentUser(): ?array
	{
		$id = (int) ($_SESSION['user_id'] ?? 0);
		if ($id <= 0) {
			return null;
		}
		return Db::one("SELECT id, email, name, role FROM users WHERE id = ? AND active = 1 AND role IN ('admin','operator')", [$id]);
	}

	private function checkCsrf(): bool
	{
		return is_string($_POST['_csrf'] ?? null) && hash_equals((string) $_SESSION['csrf'], $_POST['_csrf']);
	}

	/** @param array<string,mixed> $vars */
	private function page(string $template, string $title, array $vars = [], int $status = 200): void
	{
		$vars += ['req' => $this->req, 'user' => $this->user, 'csrf' => $_SESSION['csrf'] ?? '', 'flash' => $this->takeFlash()];
		$body = View::render('admin/' . $template, $vars);
		Response::html($status, View::render('admin/layout', $vars + ['title' => $title, 'body' => $body]));
	}

	private function flash(string $type, string $msg): void
	{
		$_SESSION['flash'][] = [$type, $msg];
	}

	private function takeFlash(): array
	{
		$f = $_SESSION['flash'] ?? [];
		unset($_SESSION['flash']);
		return $f;
	}

	// ------------------------------------------------------------ login

	private function loginForm(string $error = ''): void
	{
		$this->page('login', 'Accesso', ['error' => $error, 'email' => (string) ($_POST['email'] ?? '')], $error ? 401 : 200);
	}

	private function loginPost(): void
	{
		if (!$this->checkCsrf()) {
			$this->loginForm('Sessione scaduta: riprova.');
			return;
		}
		$email = strtolower(trim((string) ($_POST['email'] ?? '')));
		$pass = (string) ($_POST['password'] ?? '');
		$u = Db::one("SELECT * FROM users WHERE email = ? AND active = 1 AND role IN ('admin','operator')", [$email]);
		if ($u === null || !password_verify($pass, (string) $u['password_hash'])) {
			usleep(500000);
			Log::warning('login fallito per ' . $email . ' ip=' . $this->req->clientIp());
			$this->loginForm('Email o password errate.');
			return;
		}
		if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
			Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
		}
		session_regenerate_id(true);
		$_SESSION['user_id'] = (int) $u['id'];
		Db::exec('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
		Response::redirect($this->req->url('/admin/'));
	}

	// ------------------------------------------------------------ pagine

	private function index(): void
	{
		$webcams = Db::all('SELECT * FROM webcams ORDER BY status = 9, name, slug');
		foreach ($webcams as &$w) {
			$w['last'] = Db::one(
				'SELECT ts, t_in, rh_in, t_ext, rh_ext, battery_v, heater, rssi_dbm, net_type
				   FROM measurements WHERE webcam_id = ? ORDER BY ts DESC LIMIT 1',
				[$w['id']]
			);
			$w['has_current'] = PhotoStore::current((int) $w['id']) !== null;
		}
		unset($w);
		$this->page('index', 'Webcam', ['webcams' => $webcams]);
	}

	private function show(array $w, ?string $newToken = null): void
	{
		$id = (int) $w['id'];
		$to = time();
		$from = $to - 86400;
		$rows = Db::all(
			'SELECT ts, time_synced, t_in, rh_in, t_ext, rh_ext, heater, fan, battery_v, battery_a, panel_w,
			        charge_state, rssi_dbm, net_type, queue_photos
			   FROM measurements WHERE webcam_id = ? AND ts >= ? ORDER BY ts',
			[$id, Db::dt($from)]
		);
		$series = static function (string $col) use ($rows): array {
			$pts = [];
			foreach ($rows as $r) {
				$pts[] = [(int) Db::unix($r['ts']), $r[$col] === null ? null : (float) $r[$col]];
			}
			return $pts;
		};
		$tz = (string) $w['timezone'];
		$chartT = SvgChart::render('Temperatura (°C), ultime 24 ore', '°C', [
			['label' => 'Interna', 'short' => 'int', 'class' => 's1', 'points' => $series('t_in')],
			['label' => 'Esterna', 'short' => 'est', 'class' => 's2', 'points' => $series('t_ext')],
		], $from, $to, $tz);
		$chartH = SvgChart::render('Umidità relativa (%), ultime 24 ore', '%', [
			['label' => 'Interna', 'short' => 'int', 'class' => 's1', 'points' => $series('rh_in')],
			['label' => 'Esterna', 'short' => 'est', 'class' => 's2', 'points' => $series('rh_ext')],
		], $from, $to, $tz, 0);

		$this->page('webcam', (string) ($w['name'] ?: $w['slug']), [
			'w' => $w,
			'current' => PhotoStore::current($id),
			'lastPhoto' => $w['last_photo_id'] ? Db::one('SELECT * FROM photos WHERE id = ?', [$w['last_photo_id']]) : null,
			'recent' => array_slice(array_reverse($rows), 0, 24),
			'charts' => [$chartT, $chartH],
			'events' => Db::all('SELECT * FROM events WHERE webcam_id = ? ORDER BY ts DESC, id DESC LIMIT 30', [$id]),
			'counters' => Db::one(
				'SELECT cc.*, c.model, c.serial FROM camera_counters cc LEFT JOIN cameras c ON c.id = cc.camera_id
				  WHERE cc.webcam_id = ? ORDER BY cc.ts DESC, cc.id DESC LIMIT 1',
				[$id]
			),
			'tokens' => Db::all('SELECT * FROM tokens WHERE webcam_id = ? ORDER BY created_at DESC', [$id]),
			'photosToday' => (int) Db::scalar('SELECT COUNT(*) FROM photos WHERE webcam_id = ? AND taken_at >= ?', [$id, Db::dt($from)]),
			'newToken' => $newToken,
			'timezones' => \DateTimeZone::listIdentifiers(),
		]);
	}

	private function createForm(array $in = [], string $error = ''): void
	{
		$this->page('new', 'Nuova webcam', ['in' => $in + ['timezone' => 'Europe/Rome'], 'error' => $error], $error ? 422 : 200);
	}

	private function createPost(): void
	{
		$in = [
			'mac' => trim((string) ($_POST['mac'] ?? '')),
			'name' => trim((string) ($_POST['name'] ?? '')),
			'slug' => trim((string) ($_POST['slug'] ?? '')),
			'timezone' => trim((string) ($_POST['timezone'] ?? 'Europe/Rome')),
			'location' => trim((string) ($_POST['location'] ?? '')),
			'is_public' => !empty($_POST['is_public']),
		];
		try {
			$id = Webcams::create($in['mac'], $in['name'], $in['slug'], [
				'timezone' => $in['timezone'], 'is_public' => $in['is_public'],
				'location' => $in['location'] === '' ? null : $in['location'],
			]);
		} catch (\InvalidArgumentException $e) {
			$this->createForm($in, $e->getMessage());
			return;
		}
		$token = Tokens::create($id, 'creato da ' . $this->user['email']);
		Log::info("webcam $id creata da {$this->user['email']}");
		$w = (array) Db::one('SELECT * FROM webcams WHERE id = ?', [$id]);
		$this->show($w, $token);
	}

	private function editPost(array $w): void
	{
		try {
			Webcams::update((int) $w['id'], $_POST);
			$this->flash('ok', 'Webcam aggiornata.');
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url('/admin/webcams/' . (int) $w['id']));
	}

	private function tokenPost(array $w): void
	{
		$token = Tokens::create((int) $w['id'], 'rigenerato da ' . $this->user['email'], true);
		Log::info("token della webcam {$w['id']} rigenerato da {$this->user['email']}");
		$this->show($w, $token);
	}

	private function currentJpg(array $w): void
	{
		$cur = PhotoStore::current((int) $w['id']);
		if ($cur === null) {
			Response::notFound();
			return;
		}
		PublicController::sendJpeg($this->req, PhotoStore::currentFile((int) $w['id']), $cur['sha256'], $cur['taken_at'], 'private, max-age=30');
	}
}
