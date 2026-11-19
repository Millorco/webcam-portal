<?php

declare(strict_types=1);

namespace WebcamPortal\Admin;

use WebcamPortal\Db;
use WebcamPortal\Http\Response;
use WebcamPortal\Log;
use WebcamPortal\Service\Customers;
use WebcamPortal\Service\SiteKeys;

/**
 * Pagine dei clienti nel pannello: elenco, scheda con webcam assegnate e
 * storico, chiavi API dei siti (crea, mostra una volta, origini, revoca).
 * Usato da AdminController.
 */
trait CustomerPages
{
	/** @return bool true se il percorso era di queste pagine */
	private function customerRoutes(string $p, string $m): bool
	{
		if ($p === '/customers') {
			$m === 'POST' ? $this->customerCreatePost() : $this->customersIndex();
			return true;
		}
		if (preg_match('#^/customers/(\d+)(?:/(edit))?$#', $p, $mm)) {
			$c = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $mm[1]]);
			if ($c === null) {
				Response::notFound();
			} elseif (($mm[2] ?? '') === 'edit' && $m === 'POST') {
				$this->customerEditPost($c);
			} elseif (($mm[2] ?? '') === '' && $m === 'GET') {
				$this->customerShow($c);
			} else {
				Response::notFound();
			}
			return true;
		}
		if ($p === '/keys' && $m === 'POST') {
			$this->keyCreatePost();
			return true;
		}
		if (preg_match('#^/keys/(\d+)/(revoke|origins)$#', $p, $mm) && $m === 'POST') {
			$k = Db::one('SELECT * FROM site_keys WHERE id = ?', [(int) $mm[1]]);
			if ($k === null) {
				Response::notFound();
				return true;
			}
			if ($mm[2] === 'revoke') {
				SiteKeys::revoke((int) $k['id']);
				Log::info("chiave dei siti {$k['key_prefix']} (id {$k['id']}) revocata da {$this->user['email']}");
				$this->flash('ok', 'Chiave ' . $k['key_prefix'] . '… revocata: i siti che la usano ricevono 403.');
			} else {
				try {
					SiteKeys::setOrigins((int) $k['id'], (string) ($_POST['origins'] ?? ''));
					$this->flash('ok', 'Origini aggiornate.');
				} catch (\InvalidArgumentException $e) {
					$this->flash('error', $e->getMessage());
				}
			}
			$this->backToKeys($k['customer_id'] === null ? null : (int) $k['customer_id']);
			return true;
		}
		return false;
	}

	private function backToKeys(?int $customerId): void
	{
		Response::redirect($this->req->url($customerId === null ? '/admin/customers#chiavi' : '/admin/customers/' . $customerId . '#chiavi'));
	}

	private function keysOf(?int $customerId): array
	{
		return $customerId === null
			? Db::all('SELECT * FROM site_keys WHERE customer_id IS NULL ORDER BY revoked_at IS NOT NULL, created_at DESC')
			: Db::all('SELECT * FROM site_keys WHERE customer_id = ? ORDER BY revoked_at IS NOT NULL, created_at DESC', [$customerId]);
	}

	/** Chiave appena creata: si mostra una volta sola e si toglie dalla sessione. */
	private function takeNewKey(): ?array
	{
		$k = $_SESSION['new_site_key'] ?? null;
		unset($_SESSION['new_site_key']);
		return is_array($k) ? $k : null;
	}

	private function customersIndex(): void
	{
		$rows = Db::all(
			'SELECT c.*,
			        (SELECT COUNT(*) FROM webcams w WHERE w.customer_id = c.id AND w.status <> 9) AS webcams,
			        (SELECT COUNT(*) FROM site_keys k WHERE k.customer_id = c.id AND k.revoked_at IS NULL) AS keys_active
			   FROM customers c ORDER BY c.active DESC, c.name'
		);
		$this->page('customers', 'Clienti', [
			'customers' => $rows,
			'keys' => $this->keysOf(null),
			'newKey' => $this->takeNewKey(),
			'unassigned' => (int) Db::scalar('SELECT COUNT(*) FROM webcams WHERE customer_id IS NULL AND status <> 9'),
		]);
	}

	private function customerCreatePost(): void
	{
		try {
			$id = Customers::create((string) ($_POST['name'] ?? ''), [
				'email' => $_POST['email'] ?? null, 'code' => $_POST['code'] ?? null, 'town' => $_POST['town'] ?? null,
				'dolibarr_socid' => $_POST['dolibarr_socid'] ?? null,
			]);
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
			Response::redirect($this->req->url('/admin/customers'));
			return;
		}
		Log::info("cliente $id creato da {$this->user['email']}");
		Response::redirect($this->req->url('/admin/customers/' . $id));
	}

	private function customerEditPost(array $c): void
	{
		try {
			Customers::update((int) $c['id'], $_POST);
			$this->flash('ok', 'Cliente aggiornato.');
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
		}
		Response::redirect($this->req->url('/admin/customers/' . (int) $c['id']));
	}

	private function customerShow(array $c): void
	{
		$id = (int) $c['id'];
		$this->page('customer', (string) $c['name'], [
			'c' => $c,
			'webcams' => Db::all('SELECT id, slug, name, status, is_public, timezone, last_photo_at FROM webcams WHERE customer_id = ? ORDER BY name', [$id]),
			'history' => Db::all(
				'SELECT cw.*, w.slug, w.name, w.timezone FROM customer_webcams cw JOIN webcams w ON w.id = cw.webcam_id
				  WHERE cw.customer_id = ? ORDER BY cw.valid_from DESC LIMIT 100',
				[$id]
			),
			'keys' => $this->keysOf($id),
			'newKey' => $this->takeNewKey(),
		]);
	}

	private function keyCreatePost(): void
	{
		$cid = (int) ($_POST['customer_id'] ?? 0);
		$customerId = $cid > 0 ? $cid : null;
		$rate = trim((string) ($_POST['rate_limit_per_min'] ?? ''));
		try {
			$k = SiteKeys::create(
				$customerId,
				(string) ($_POST['scope'] ?? 'public'),
				(string) ($_POST['origins'] ?? ''),
				(string) ($_POST['label'] ?? ''),
				(int) $this->user['id'],
				$rate === '' ? null : (int) $rate
			);
		} catch (\InvalidArgumentException $e) {
			$this->flash('error', $e->getMessage());
			$this->backToKeys($customerId);
			return;
		}
		$_SESSION['new_site_key'] = ['id' => $k['id'], 'key' => $k['key'], 'scope' => (string) $_POST['scope']];
		Log::info('chiave dei siti ' . substr($k['key'], 0, 12) . " (id {$k['id']}) creata da {$this->user['email']}");
		$this->backToKeys($customerId);
	}
}
