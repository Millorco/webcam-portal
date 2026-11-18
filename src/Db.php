<?php

declare(strict_types=1);

namespace WebcamPortal;

use PDO;

/**
 * Connessione PDO a MariaDB, sempre in UTC (SET time_zone = '+00:00').
 */
final class Db
{
	private static ?PDO $pdo = null;

	public static function pdo(): PDO
	{
		if (self::$pdo === null) {
			self::$pdo = self::connect(Config::get());
		}
		return self::$pdo;
	}

	public static function reset(): void
	{
		self::$pdo = null;
	}

	public static function connect(Config $c): PDO
	{
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
			(string) $c->value('db.host'),
			(int) $c->value('db.port', 3306),
			(string) $c->value('db.name')
		);
		$pdo = new PDO($dsn, (string) $c->value('db.user'), (string) $c->value('db.pass'), [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
			PDO::ATTR_STRINGIFY_FETCHES => false,
		]);
		$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
		return $pdo;
	}

	/** @param array<int|string,mixed> $params */
	public static function one(string $sql, array $params = []): ?array
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($params);
		$row = $st->fetch();
		return $row === false ? null : $row;
	}

	/** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
	public static function all(string $sql, array $params = []): array
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($params);
		return $st->fetchAll();
	}

	/** @param array<int|string,mixed> $params */
	public static function exec(string $sql, array $params = []): int
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($params);
		return $st->rowCount();
	}

	public static function scalar(string $sql, array $params = []): mixed
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($params);
		$v = $st->fetchColumn();
		return $v === false ? null : $v;
	}

	/** Secondi Unix -> DATETIME UTC. */
	public static function dt(int $unix): string
	{
		return gmdate('Y-m-d H:i:s', $unix);
	}

	/** DATETIME UTC -> secondi Unix. */
	public static function unix(?string $dt): ?int
	{
		if ($dt === null || $dt === '') {
			return null;
		}
		$t = strtotime($dt . ' UTC');
		return $t === false ? null : $t;
	}
}
