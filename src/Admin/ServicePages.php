<?php

declare(strict_types=1);

namespace WebcamPortal\Admin;

use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Http\Response;
use WebcamPortal\Log;
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\Ffmpeg;
use WebcamPortal\Service\PhotoOfDay;
use WebcamPortal\Service\PhotoScores;
use WebcamPortal\Service\Reports;
use WebcamPortal\Service\ServiceSettings;
use WebcamPortal\Service\Stats;
use WebcamPortal\Service\Timelapse;
use WebcamPortal\Site\Media;
use WebcamPortal\Telegram\Bot;

/**
 * Pagine dei servizi della fase 3b (servizi-v1.md) nel pannello:
 * per webcam (/admin/webcams/{id}/services: listino, sovrimpressione, logo,
 * sponsor e infopoint, timelapse, foto del giorno, statistiche, registro)
 * e generali (/admin/reports: rapporti e certificati; /admin/telegram).
 * Usato da AdminController.
 */
trait ServicePages
{
	/** @return bool true se il percorso era di queste pagine */
	private function serviceRoutes(string $p, string $m): bool
	{
		if ($p === '/reports') {
			$this->reportsIndex();
			return true;
		}
		if (preg_match('#^/reports/(report|certificate)/(\d+)/(regenerate|download)$#', $p, $mm)) {
			$mm[3] === 'download' ? $this->download($mm[1] === 'report' ? 'rep' : 'cert', $mm[2]) : ($m === 'POST' ? $this->regeneratePost($mm[1], (int) $mm[2]) : Response::notFound());
			return true;
		}
		if ($p === '/telegram') {
			$m === 'POST' ? $this->telegramCodePost() : $this->telegramIndex();
			return true;
		}
		if (preg_match('#^/telegram/(chats|codes)/(\d+)/(unlink|revoke)$#', $p, $mm) && $m === 'POST') {
			$this->telegramActionPost($mm[1], (int) $mm[2]);
			return true;
		}
		if (!preg_match('#^/webcams/(\d+)/services(?:/(plan|overlay|logo|items|items/(\d+)/delete|timelapse|timelapse/request|timelapse/(\d+)/(?:requeue|download|poster)|pod|good|certificate|register/(\d{4}-\d{2}-\d{2})))?$#', $p, $mm)) {
			return false;
		}
		$w = Db::one('SELECT * FROM webcams WHERE id = ?', [(int) $mm[1]]);
		$action = $mm[2] ?? '';
		if ($w === null) {
			Response::notFound();
			return true;
		}
		if ($action === '' && $m === 'GET') {
			$this->servicesShow($w);
			return true;
		}
		if (str_starts_with($action, 'timelapse/') && preg_match('#/(download|poster)$#', $action, $dm) && $m === 'GET') {
			$t = Db::one('SELECT id FROM timelapses WHERE id = ? AND webcam_id = ?', [(int) $mm[4], $w['id']]);
			$t === null ? Response::notFound() : $this->download($dm[1] === 'download' ? 'tl' : 'tlp', (string) $t['id']);
			return true;
		}
		if (str_starts_with($action, 'register/') && $m === 'GET') {
			$this->download('reg', $w['id'] . '-' . str_replace('-', '', (string) $mm[5]));
			return true;
		}
		if ($m !== 'POST') {
			Response::notFound();
			return true;
		}
		$wid = (int) $w['id'];
		$uid = (int) $this->user['id'];
		$anchor = 'servizi';
		try {
			switch (true) {
				case $action === 'plan':
					$this->planPost($w);
					$anchor = 'listino';
					break;
				case $action === 'overlay':
					$cur = Overlay::config($wid);
					Overlay::save($wid, $_POST + ['logo_asset_id' => $cur['logo_asset_id'] ?? null, 'fields' => [], 'apply_to' => []], $uid);
					$this->flash('ok', 'Sovrimpressione salvata: le immagini si rifanno alla prossima richiesta.');
					$anchor = 'sovrimpressione';
					break;
				case $action === 'logo':
					$f = $_FILES['logo'] ?? null;
					if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
						throw new \InvalidArgumentException('Scegliere un file PNG o JPEG (al più 2 MB)');
					}
					$kind = ($_POST['kind'] ?? 'logo') === 'sponsor' ? 'sponsor' : 'logo';
					$aid = Overlay::addAsset((string) $f['tmp_name'], $kind, $w['customer_id'] === null ? null : (int) $w['customer_id'], (string) ($_POST['label'] ?? $f['name']), $uid);
					if ($kind === 'logo') {
						$cfg = Overlay::config($wid) ?? [];
						Overlay::save($wid, ['logo_asset_id' => $aid, 'fields' => explode(',', (string) ($cfg['fields'] ?? 'datetime,temperature_ext,humidity_ext,name')),
							'apply_to' => explode(',', (string) ($cfg['apply_to'] ?? implode(',', Overlay::APPLY)))] + $cfg, $uid);
					}
					$this->flash('ok', $kind === 'logo' ? 'Logo caricato.' : 'Immagine caricata: sceglierla nello sponsor.');
					$anchor = 'sovrimpressione';
					break;
				case $action === 'items':
					Overlay::saveItem($wid, $_POST, ($_POST['item_id'] ?? '') !== '' ? (int) $_POST['item_id'] : null, $uid);
					$this->flash('ok', 'Elemento salvato.');
					$anchor = 'sponsor';
					break;
				case str_starts_with($action, 'items/'):
					Overlay::deleteItem($wid, (int) $mm[3]);
					$this->flash('ok', 'Elemento tolto.');
					$anchor = 'sponsor';
					break;
				case $action === 'timelapse':
					Timelapse::saveParams($wid, ['crop_x_pct' => $_POST['crop_x_pct'] ?? 50, 'crop_y_pct' => $_POST['crop_y_pct'] ?? 50, 'same_light_time' => $_POST['same_light_time'] ?? '12:00']);
					$this->flash('ok', 'Parametri dei timelapse salvati.');
					$anchor = 'timelapse';
					break;
				case $action === 'good':
					PhotoScores::saveParams($wid, (array) ($_POST['good'] ?? []));
					$this->flash('ok', 'Soglie delle foto buone salvate: valgono per le foto nuove.');
					$anchor = 'timelapse';
					break;
				case $action === 'timelapse/request':
					$id = Timelapse::request($wid, (string) ($_POST['kind'] ?? 'custom'), (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), (string) ($_POST['format'] ?? 'landscape'),
						['priority' => 1, 'requested_by' => 'operator', 'user_id' => $uid, 'requeue' => true] + (($_POST['selection'] ?? '') === '' ? [] : ['selection' => (string) $_POST['selection']]));
					$this->flash(Timelapse::available() ? 'ok' : 'error', Timelapse::available() ? "Timelapse $id in coda (bin/timelapse.php)." : Ffmpeg::MISSING_MESSAGE);
					$anchor = 'timelapse';
					break;
				case str_starts_with($action, 'timelapse/'):
					Db::exec("UPDATE timelapses SET status = 'queued', status_reason = NULL, attempts = 0, priority = 1 WHERE id = ? AND webcam_id = ? AND status IN ('failed','cancelled','skipped','expired')", [(int) $mm[4], $wid]);
					$this->flash('ok', 'Timelapse rimesso in coda.');
					$anchor = 'timelapse';
					break;
				case $action === 'pod':
					$day = PhotoOfDay::choose($w, (int) ($_POST['photo_id'] ?? 0), 'operator', $uid);
					$this->flash('ok', "Foto del giorno del $day scelta.");
					$anchor = 'foto-del-giorno';
					break;
				case $action === 'certificate':
					if ($w['customer_id'] === null) {
						throw new \InvalidArgumentException('La webcam non ha un cliente');
					}
					$id = Reports::requestCertificate($wid, (int) $w['customer_id'], (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), $uid);
					(new Reports(time()))->process($id, 'certificate');
					$this->flash('ok', "Certificato $id: " . (string) Db::scalar('SELECT status FROM weather_certificates WHERE id = ?', [$id]));
					$anchor = 'registro';
					break;
			}
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url("/admin/webcams/$wid/services#$anchor"));
		return true;
	}

	/** Livello e opzioni a mano (solo se non arrivano da Dolibarr). */
	private function planPost(array $w): void
	{
		$wid = (int) $w['id'];
		$svc = ServiceSettings::get($wid);
		if ($svc['source'] === 'dolibarr' && $w['dolibarr_id']) {
			throw new \InvalidArgumentException('Listino e opzioni arrivano da Dolibarr: cambiali nella scheda della webcam in Dolibarr.');
		}
		$level = in_array($_POST['plan_level'] ?? '', Features::LEVELS, true) ? (string) $_POST['plan_level'] : 'base';
		$opt = Features::plan($wid)['options'];
		$opt['weather_cert'] = !empty($_POST['opt_weather_cert']);
		$opt['sponsor'] = ['enabled' => !empty($_POST['opt_sponsor']), 'slots' => max(1, (int) ($_POST['opt_sponsor_slots'] ?? 2))] + (array) ($opt['sponsor'] ?? []);
		$opt['sponsor']['enabled'] = !empty($_POST['opt_sponsor']);
		$film = (string) ($_POST['opt_film'] ?? '');
		$opt['film'] = ['option' => in_array($film, ['auto', 'edited'], true) ? $film : ''] + (array) ($opt['film'] ?? []);
		$opt['film']['option'] = in_array($film, ['auto', 'edited'], true) ? $film : '';
		$date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
		Db::exec("INSERT IGNORE INTO webcam_service (webcam_id, source) VALUES (?, 'portal')", [$wid]);
		Db::exec('UPDATE webcam_service SET plan_level = ?, plan_options = ?, rental_start = ?, rental_end = ? WHERE webcam_id = ?',
			[$level, json_encode($opt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $date($_POST['rental_start'] ?? null), $date($_POST['rental_end'] ?? null), $wid]);
		Features::reset();
		Log::info("webcam $wid: listino $level impostato da {$this->user['email']}");
		$this->flash('ok', 'Listino salvato.');
	}

	/** I file si scaricano con un URL firmato di breve durata (come per i siti). */
	private function download(string $type, string $id): void
	{
		$ext = ['tl' => 'mp4', 'tlp' => 'jpg', 'rep' => 'pdf', 'cert' => 'pdf', 'reg' => 'txt'][$type];
		Response::redirect(Media::url($this->req->baseUrl(), $type, $id, $ext, (int) (ceil((time() + 7200) / 3600) * 3600)), 302);
	}

	private function servicesShow(array $w): void
	{
		$wid = (int) $w['id'];
		$tz = ServiceSettings::tz($w);
		$today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
		$from30 = (new \DateTimeImmutable('now', $tz))->modify('-29 days')->format('Y-m-d');
		$pods = Db::all('SELECT d.*, p.taken_at FROM photo_of_day d JOIN photos p ON p.id = d.photo_id WHERE d.webcam_id = ? ORDER BY d.day DESC LIMIT 14', [$wid]);
		$best = Db::all(
			'SELECT s.photo_id, s.score, s.taken_at FROM photo_scores s WHERE s.webcam_id = ? AND s.day >= ? AND s.good = 1 ORDER BY s.score DESC LIMIT 12',
			[$wid, (new \DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d')]
		);
		$this->page('services', 'Servizi - ' . ($w['name'] ?: $w['slug']), [
			'w' => $w,
			'tz' => $tz->getName(),
			'svc' => ServiceSettings::get($wid),
			'plan' => Features::plan($wid),
			'features' => Features::forWebcam($w),
			'server' => Features::server(),
			'ffmpeg' => Ffmpeg::status(),
			'overlay' => Overlay::config($wid),
			'overlayActive' => Overlay::active($w) !== null,
			'assets' => Db::all('SELECT * FROM overlay_assets WHERE deleted_at IS NULL AND (customer_id IS NULL OR customer_id = ?) ORDER BY kind, id DESC', [(int) $w['customer_id']]),
			'items' => Db::all('SELECT * FROM overlay_items WHERE webcam_id = ? ORDER BY kind, sort_order, id', [$wid]),
			'slots' => Overlay::slots($wid),
			'tlParams' => Timelapse::params($wid),
			'good' => PhotoScores::params($wid)[0],
			'timelapses' => Db::all('SELECT * FROM timelapses WHERE webcam_id = ? ORDER BY created_at DESC, id DESC LIMIT 30', [$wid]),
			'scores' => Db::one('SELECT COUNT(*) n, SUM(good) good FROM photo_scores WHERE webcam_id = ? AND day >= ?', [$wid, $from30]),
			'pods' => $pods,
			'best' => $best,
			'stats' => Stats::report($wid, $from30, $today),
			'register' => Db::all('SELECT * FROM daily_register WHERE webcam_id = ? ORDER BY day DESC LIMIT 14', [$wid]),
			'today' => $today,
		]);
	}

	private function reportsIndex(): void
	{
		$this->page('reports', 'Rapporti e certificati', [
			'reports' => Db::all('SELECT r.*, w.slug, w.name AS wname, c.name AS cname FROM monthly_reports r JOIN webcams w ON w.id = r.webcam_id JOIN customers c ON c.id = r.customer_id ORDER BY r.month DESC, r.id DESC LIMIT 200'),
			'certs' => Db::all('SELECT r.*, w.slug, w.name AS wname, c.name AS cname FROM weather_certificates r JOIN webcams w ON w.id = r.webcam_id JOIN customers c ON c.id = r.customer_id ORDER BY r.period_from DESC, r.id DESC LIMIT 200'),
			'enabled' => (bool) Config::get()->value('reports.enabled', true),
			'day' => (int) Config::get()->value('reports.day', 3),
			'time' => (string) Config::get()->value('reports.time', '06:00'),
		]);
	}

	private function regeneratePost(string $kind, int $id): void
	{
		try {
			$r = new Reports(time());
			$r->regenerate($kind, $id, !empty($_POST['email']));
			$this->flash($r->stats['failed'] > 0 ? 'error' : 'ok', ($kind === 'report' ? 'Rapporto ' : 'Certificato ') . $id . ($r->stats['failed'] > 0 ? ' non generato: vedere il log reports.' : ' rigenerato.'));
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url('/admin/reports'));
	}

	private function telegramIndex(?array $newCode = null): void
	{
		$this->page('telegram', 'Telegram', [
			'configured' => Bot::configured(),
			'mode' => (string) Config::get()->value('telegram.mode', 'poll'),
			'chats' => Db::all('SELECT t.*, c.name AS cname, (SELECT COUNT(*) FROM telegram_subscriptions s WHERE s.chat_ref = t.id) AS subs FROM telegram_chats t LEFT JOIN customers c ON c.id = t.customer_id ORDER BY t.status, t.id DESC LIMIT 200'),
			'codes' => Db::all('SELECT p.*, c.name AS cname, w.slug FROM telegram_pair_codes p JOIN customers c ON c.id = p.customer_id LEFT JOIN webcams w ON w.id = p.webcam_id ORDER BY p.id DESC LIMIT 50'),
			'customers' => Db::all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name'),
			'queue' => (int) Db::scalar("SELECT COUNT(*) FROM telegram_messages WHERE status = 'queued'"),
			'newCode' => $newCode,
		]);
	}

	private function telegramCodePost(): void
	{
		try {
			$c = Bot::createCode((int) ($_POST['customer_id'] ?? 0), ($_POST['webcam_id'] ?? '') !== '' ? (int) $_POST['webcam_id'] : null, (string) ($_POST['label'] ?? ''), (int) $this->user['id']);
			Log::info("codice Telegram {$c['id']} creato da {$this->user['email']}");
			$this->telegramIndex($c);
			return;
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url('/admin/telegram'));
	}

	private function telegramActionPost(string $what, int $id): void
	{
		if ($what === 'chats') {
			$c = Db::one('SELECT id, chat_id FROM telegram_chats WHERE id = ?', [$id]);
			if ($c !== null) {
				Bot::stop((int) $c['chat_id'], 'stopped', (int) $c['id']);
				$this->flash('ok', 'Chat scollegata.');
			}
		} else {
			Db::exec('UPDATE telegram_pair_codes SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL', [$id]);
			$this->flash('ok', 'Codice revocato.');
		}
		Response::redirect($this->req->url('/admin/telegram'));
	}
}
