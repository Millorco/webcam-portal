<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Chiavi API dei siti (api-siti-v1.md): vls_ + 32 byte casuali in base64url
 * (47 caratteri). Nel database c'è solo hex(sha256(chiave)); la chiave in
 * chiaro si vede una volta sola, alla creazione.
 */
final class SiteKeys
{
	public const PATTERN = '/^vls_[A-Za-z0-9_-]{43}$/';
	public const SCOPES = ['public', 'client'];

	public static function generate(): string
	{
		return 'vls_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	public static function hash(string $key): string
	{
		return hash('sha256', $key);
	}

	/**
	 * Crea una chiave e la restituisce in chiaro (unica volta).
	 *
	 * @param list<string>|string $origins
	 * @return array{id:int, key:string}
	 */
	public static function create(?int $customerId, string $scope, array|string $origins = [], ?string $label = null, ?int $userId = null, ?int $ratePerMin = null, ?int $expiresAt = null): array
	{
		if (!in_array($scope, self::SCOPES, true)) {
			throw new \InvalidArgumentException('Scope non valido: public o client');
		}
		if ($scope === 'client' && $customerId === null) {
			throw new \InvalidArgumentException('Una chiave con scope client deve essere di un cliente');
		}
		if ($customerId !== null && Db::scalar('SELECT id FROM customers WHERE id = ?', [$customerId]) === null) {
			throw new \InvalidArgumentException("Cliente $customerId inesistente");
		}
		$list = self::parseOrigins($origins);
		if ($scope === 'client' && in_array('*', $list, true)) {
			throw new \InvalidArgumentException('Origine "*" ammessa solo con scope public');
		}
		if ($ratePerMin !== null && ($ratePerMin < 1 || $ratePerMin > 60000)) {
			throw new \InvalidArgumentException('Limite di frequenza fuori intervallo (1-60000 al minuto)');
		}
		$key = self::generate();
		$label = trim((string) $label);
		Db::exec(
			'INSERT INTO site_keys (customer_id, key_hash, key_prefix, label, scope, origins, rate_limit_per_min, created_by, created_at, expires_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)',
			[
				$customerId, self::hash($key), substr($key, 0, 12), $label === '' ? null : mb_substr($label, 0, 100), $scope,
				$list === [] ? null : implode("\n", $list), $ratePerMin, $userId, $expiresAt === null ? null : Db::dt($expiresAt),
			]
		);
		return ['id' => (int) Db::pdo()->lastInsertId(), 'key' => $key];
	}

	public static function revoke(int $id): bool
	{
		return Db::exec('UPDATE site_keys SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL', [$id]) > 0;
	}

	/** @param list<string> $origins */
	public static function setOrigins(int $id, array|string $origins): void
	{
		$list = self::parseOrigins($origins);
		$scope = (string) Db::scalar('SELECT scope FROM site_keys WHERE id = ?', [$id]);
		if ($scope === 'client' && in_array('*', $list, true)) {
			throw new \InvalidArgumentException('Origine "*" ammessa solo con scope public');
		}
		Db::exec('UPDATE site_keys SET origins = ? WHERE id = ?', [$list === [] ? null : implode("\n", $list), $id]);
	}

	/**
	 * Origini da testo (una per riga, o separate da virgole e spazi) o da
	 * elenco: https://www.hotel.it, http://localhost:8080, https://*.comune.it
	 * (solo i sottodomini), * (tutte, solo scope public). Niente percorsi.
	 *
	 * @param list<string>|string $in
	 * @return list<string>
	 */
	public static function parseOrigins(array|string $in): array
	{
		$items = is_array($in) ? $in : (preg_split('/[\s,]+/', $in) ?: []);
		$out = [];
		foreach ($items as $o) {
			$o = strtolower(trim((string) $o));
			if ($o === '') {
				continue;
			}
			$o = rtrim($o, '/');
			if ($o !== '*' && !preg_match('#^https?://(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$#', $o)) {
				throw new \InvalidArgumentException("Origine non valida: $o (es. https://www.hotel.it, senza percorso)");
			}
			$out[$o] = true;
		}
		if (count($out) > 50) {
			throw new \InvalidArgumentException('Al massimo 50 origini per chiave');
		}
		return array_keys($out);
	}

	/** @return list<string> */
	public static function origins(?string $stored): array
	{
		return array_values(array_filter(array_map('trim', explode("\n", (string) $stored))));
	}

	/** L'intestazione Origin è tra quelle ammesse dalla chiave? */
	public static function originAllowed(string $origin, array $allowed): bool
	{
		$origin = strtolower(rtrim(trim($origin), '/'));
		if (!preg_match('#^(https?)://([^/:]+)(:\d+)?$#', $origin, $m)) {
			return false;
		}
		foreach ($allowed as $a) {
			if ($a === '*' || $a === $origin) {
				return true;
			}
			if (preg_match('#^(https?)://\*\.([^/:]+)(:\d+)?$#', $a, $w)
				&& $w[1] === $m[1] && ($w[3] ?? '') === ($m[3] ?? '') && str_ends_with($m[2], '.' . $w[2])) {
				return true;
			}
		}
		return false;
	}
}
