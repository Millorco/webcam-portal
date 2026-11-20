<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use WebcamPortal\Db;

/**
 * Clienti del portal (copia minima dei soggetti terzi di Dolibarr) e
 * assegnazione delle webcam con lo storico (customer_webcams).
 *
 * webcams.customer_id è il cliente attuale; customer_webcams dice da quando:
 * l'API dei siti mostra a un cliente solo foto e misure dei periodi in cui
 * la webcam era sua (api-siti-v1.md, "Visibilità").
 */
final class Customers
{
	public static function create(string $name, array $opt = []): int
	{
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Nome del cliente obbligatorio');
		}
		$socid = isset($opt['dolibarr_socid']) && (int) $opt['dolibarr_socid'] > 0 ? (int) $opt['dolibarr_socid'] : null;
		if ($socid !== null && Db::scalar('SELECT id FROM customers WHERE dolibarr_socid = ?', [$socid]) !== null) {
			throw new \InvalidArgumentException("Esiste già un cliente collegato al terzo Dolibarr $socid");
		}
		$email = trim((string) ($opt['email'] ?? ''));
		Db::exec(
			'INSERT INTO customers (dolibarr_socid, name, code, email, town, active, source, note, created_at)
			 VALUES (?, ?, ?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())',
			[
				$socid, mb_substr($name, 0, 255), self::nullable($opt['code'] ?? null, 64), $email === '' ? null : mb_substr($email, 0, 255),
				self::nullable($opt['town'] ?? null, 128), ($opt['source'] ?? 'portal') === 'dolibarr' ? 'dolibarr' : 'portal',
				self::nullable($opt['note'] ?? null, 255),
			]
		);
		return (int) Db::pdo()->lastInsertId();
	}

	public static function update(int $id, array $in): void
	{
		$name = trim((string) ($in['name'] ?? ''));
		if ($name === '') {
			throw new \InvalidArgumentException('Nome del cliente obbligatorio');
		}
		$email = trim((string) ($in['email'] ?? ''));
		Db::exec(
			'UPDATE customers SET name = ?, code = ?, email = ?, town = ?, note = ?, active = ? WHERE id = ?',
			[
				mb_substr($name, 0, 255), self::nullable($in['code'] ?? null, 64), $email === '' ? null : mb_substr($email, 0, 255),
				self::nullable($in['town'] ?? null, 128), self::nullable($in['note'] ?? null, 255), !empty($in['active']) ? 1 : 0, $id,
			]
		);
	}

	/**
	 * Crea o aggiorna il cliente collegato al terzo Dolibarr $socid.
	 *
	 * @param array{name?:?string, code?:?string, email?:?string, town?:?string, active?:bool} $data
	 */
	public static function upsertDolibarr(int $socid, array $data): int
	{
		$row = Db::one('SELECT * FROM customers WHERE dolibarr_socid = ?', [$socid]);
		$name = trim((string) ($data['name'] ?? ''));
		if ($row === null) {
			$id = self::create($name !== '' ? $name : "Terzo Dolibarr $socid", [
				'dolibarr_socid' => $socid, 'code' => $data['code'] ?? null, 'email' => $data['email'] ?? null,
				'town' => $data['town'] ?? null, 'source' => 'dolibarr',
			]);
			Db::exec('UPDATE customers SET synced_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
			return $id;
		}
		$set = ['synced_at = UTC_TIMESTAMP()'];
		$p = [];
		if ($name !== '') {
			$set[] = 'name = ?';
			$p[] = mb_substr($name, 0, 255);
		}
		foreach (['code' => 64, 'email' => 255, 'town' => 128] as $k => $len) {
			if (array_key_exists($k, $data) && $data[$k] !== null && trim((string) $data[$k]) !== '') {
				$set[] = "$k = ?";
				$p[] = mb_substr(trim((string) $data[$k]), 0, $len);
			}
		}
		if (array_key_exists('active', $data)) {
			$set[] = 'active = ?';
			$p[] = $data['active'] ? 1 : 0;
		}
		$p[] = $row['id'];
		Db::exec('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
		return (int) $row['id'];
	}

	/**
	 * Assegna la webcam al cliente (null = nessun cliente) da $at (ora UTC,
	 * predefinito adesso). Chiude l'assegnazione aperta e ne apre una nuova;
	 * non fa niente se il cliente è già quello. Restituisce true se è cambiato.
	 */
	public static function assign(int $webcamId, ?int $customerId, string $source = 'portal', ?int $userId = null, ?int $at = null): bool
	{
		$at ??= time();
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			$cur = Db::one('SELECT customer_id FROM webcams WHERE id = ? FOR UPDATE', [$webcamId]);
			if ($cur === null) {
				throw new \InvalidArgumentException("Webcam $webcamId inesistente");
			}
			if ($customerId !== null && Db::scalar('SELECT id FROM customers WHERE id = ?', [$customerId]) === null) {
				throw new \InvalidArgumentException("Cliente $customerId inesistente");
			}
			$open = Db::one('SELECT * FROM customer_webcams WHERE webcam_id = ? AND valid_to IS NULL', [$webcamId]);
			$curId = $cur['customer_id'] === null ? null : (int) $cur['customer_id'];
			if ($curId === $customerId && ($customerId === null || $open !== null)) {
				$pdo->commit();
				return false;
			}
			if ($open !== null) {
				$to = max($at, (int) Db::unix($open['valid_from']));
				Db::exec('UPDATE customer_webcams SET valid_to = ? WHERE id = ?', [Db::dt($to), $open['id']]);
			}
			if ($customerId !== null) {
				Db::exec(
					'INSERT INTO customer_webcams (customer_id, webcam_id, valid_from, source, user_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
					[$customerId, $webcamId, Db::dt($at), $source === 'dolibarr' ? 'dolibarr' : 'portal', $userId]
				);
			}
			Db::exec('UPDATE webcams SET customer_id = ? WHERE id = ?', [$customerId, $webcamId]);
			$pdo->commit();
			return true;
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Periodi in cui la webcam era del cliente, come coppie [da, a] in
	 * secondi Unix (a = null: in corso).
	 *
	 * @return list<array{0:int,1:?int}>
	 */
	public static function periods(int $customerId, int $webcamId): array
	{
		$out = [];
		foreach (Db::all('SELECT valid_from, valid_to FROM customer_webcams WHERE customer_id = ? AND webcam_id = ? ORDER BY valid_from', [$customerId, $webcamId]) as $r) {
			$out[] = [(int) Db::unix($r['valid_from']), $r['valid_to'] === null ? null : (int) Db::unix($r['valid_to'])];
		}
		return $out;
	}

	/** Inizio dell'assegnazione in corso (null = webcam senza cliente). */
	public static function currentSince(int $webcamId): ?int
	{
		$v = Db::scalar('SELECT valid_from FROM customer_webcams WHERE webcam_id = ? AND valid_to IS NULL', [$webcamId]);
		return $v === null ? null : Db::unix((string) $v);
	}

	private static function nullable(mixed $v, int $len): ?string
	{
		$v = trim((string) ($v ?? ''));
		return $v === '' ? null : mb_substr($v, 0, $len);
	}
}
