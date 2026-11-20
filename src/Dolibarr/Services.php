<?php

declare(strict_types=1);

namespace WebcamPortal\Dolibarr;

use WebcamPortal\Config;
use WebcamPortal\Log;

/**
 * Chiamate a Dolibarr dei servizi (api-dolibarr-v2.md 2.2 = modulo 1.4):
 * documenti PDF con l'email al cliente (che manda Dolibarr), deposito
 * dell'hash giornaliero del registro, stato del filmato finale.
 */
final class Services
{
	private const LOG = 'sync-dolibarr';

	public static function client(): ?Client
	{
		$c = Config::get();
		$url = (string) $c->value('dolibarr.url', '');
		$key = (string) $c->value('dolibarr.api_key', '');
		if ($url === '' || $key === '') {
			return null;
		}
		return new Client($url, $key, (int) $c->value('dolibarr.timeout', 20), (bool) $c->value('dolibarr.verify_tls', true));
	}

	/** Id della scheda Dolibarr della webcam, o null (Dolibarr spento o webcam non collegata). */
	public static function webcamId(array $w): ?int
	{
		return self::client() !== null && $w['dolibarr_id'] !== null ? (int) $w['dolibarr_id'] : null;
	}

	/**
	 * POST webcams/{id}/documents (esteso): allega il PDF e, con $email,
	 * chiede a Dolibarr di mandarlo al cliente.
	 *
	 * @param array<string,mixed>|null $email {template, link, resend?}
	 * @return array<string,mixed> risposta di Dolibarr
	 * @throws DolibarrException
	 */
	public static function document(array $w, string $file, string $filename, string $description, string $docType, string $period, string $portalDocId, ?array $email, bool $overwrite): array
	{
		$client = self::client();
		$did = self::webcamId($w);
		if ($client === null || $did === null) {
			throw new DolibarrException(0, 'Dolibarr non configurato o webcam non collegata');
		}
		$data = (string) file_get_contents($file);
		$body = [
			'filename' => $filename, 'filecontent' => base64_encode($data), 'fileencoding' => 'base64', 'overwrite' => $overwrite ? 1 : 0,
			'description' => $description, 'sha256' => hash('sha256', $data),
			'doc_type' => $docType, 'period' => $period, 'portal_doc_id' => $portalDocId,
		];
		if ($email !== null) {
			$body['send_email'] = $email;
		}
		$res = $client->post("webcams/$did/documents", $body);
		return is_array($res) ? $res : [];
	}

	/**
	 * POST webcams/{id}/register: da 1 a 31 giorni.
	 *
	 * @param list<array<string,mixed>> $days
	 * @return list<array<string,mixed>> results
	 * @throws DolibarrException
	 */
	public static function register(array $w, array $days): array
	{
		$client = self::client();
		$did = self::webcamId($w);
		if ($client === null || $did === null) {
			throw new DolibarrException(0, 'Dolibarr non configurato o webcam non collegata');
		}
		$res = $client->post("webcams/$did/register", ['days' => $days]);
		return is_array($res) && is_array($res['results'] ?? null) ? $res['results'] : [];
	}

	/** PUT webcams/{id}/portal con film_status/film_url; errori solo nel log. */
	public static function filmStatus(array $w, string $status, ?string $url): void
	{
		$client = self::client();
		$did = self::webcamId($w);
		if ($client === null || $did === null) {
			return;
		}
		$film = \WebcamPortal\Service\Features::plan((int) $w['id'])['options']['film'] ?? null;
		if (is_array($film) && ($film['status'] ?? '') === 'delivered') {
			return; // consegnato dall'operatore: il portal non lo sovrascrive
		}
		try {
			$client->put("webcams/$did/portal", array_filter(['film_status' => $status, 'film_url' => $url], static fn ($v) => $v !== null));
		} catch (DolibarrException $e) {
			Log::warning("webcam {$w['id']}: stato del filmato finale non inviato a Dolibarr: " . $e->getMessage(), self::LOG);
		}
	}
}
