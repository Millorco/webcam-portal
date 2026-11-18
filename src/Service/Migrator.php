<?php

declare(strict_types=1);

namespace WebcamPortal\Service;

use PDO;

/**
 * Applica i file migrations/NNN_*.sql non ancora registrati in
 * schema_migrations. La versione è il numero del file senza zeri iniziali
 * ("001_schema.sql" -> "1"), come l'INSERT in fondo allo schema del contratto.
 */
final class Migrator
{
	public function __construct(private PDO $pdo, private string $dir)
	{
	}

	/** @return list<string> versioni applicate ora */
	public function migrate(?callable $out = null): array
	{
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
			version VARCHAR(32) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			note VARCHAR(255) NULL, PRIMARY KEY (version)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$done = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
		$applied = [];
		foreach ($this->files() as $version => $file) {
			if (in_array((string) $version, $done, true)) {
				continue;
			}
			if ($out) {
				$out("Migrazione $version: " . basename($file));
			}
			foreach (self::split((string) file_get_contents($file)) as $sql) {
				$this->pdo->exec($sql);
			}
			$st = $this->pdo->prepare('INSERT IGNORE INTO schema_migrations (version, note) VALUES (?, ?)');
			$st->execute([(string) $version, basename($file)]);
			$applied[] = (string) $version;
		}
		return $applied;
	}

	/** @return array<int,string> */
	public function files(): array
	{
		$list = [];
		foreach (glob($this->dir . '/*.sql') ?: [] as $f) {
			if (preg_match('/^(\d+)_.*\.sql$/', basename($f), $m)) {
				$list[(int) $m[1]] = $f;
			}
		}
		ksort($list);
		return $list;
	}

	/**
	 * Divide un file SQL in istruzioni, saltando i commenti "--" e "#" e
	 * rispettando le stringe tra apici (che possono contenere ";").
	 *
	 * @return list<string>
	 */
	public static function split(string $sql): array
	{
		$out = [];
		$cur = '';
		$len = strlen($sql);
		$quote = null;
		for ($i = 0; $i < $len; $i++) {
			$c = $sql[$i];
			if ($quote !== null) {
				$cur .= $c;
				if ($c === '\\') {
					$cur .= $sql[++$i] ?? '';
				} elseif ($c === $quote) {
					$quote = null;
				}
				continue;
			}
			if ($c === "'" || $c === '"' || $c === '`') {
				$quote = $c;
				$cur .= $c;
				continue;
			}
			$isDash = $c === '-' && ($sql[$i + 1] ?? '') === '-'
				&& in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\r", "\n"], true);
			if ($isDash || $c === '#') {
				$nl = strpos($sql, "\n", $i);
				$i = $nl === false ? $len : $nl;
				$cur .= "\n";
				continue;
			}
			if ($c === ';') {
				if (trim($cur) !== '') {
					$out[] = trim($cur);
				}
				$cur = '';
				continue;
			}
			$cur .= $c;
		}
		if (trim($cur) !== '') {
			$out[] = trim($cur);
		}
		return $out;
	}
}
