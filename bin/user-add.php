<?php
/**
 * Crea un operatore del pannello (o cambia la password se esiste già).
 *
 *   php bin/user-add.php --email tu@esempio.it [--nome "Mario Rossi"] [--ruolo admin|operator] [--password ...]
 *
 * Senza --password la chiede da tastiera.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Db;

Cli::only();
$a = Cli::args($argv);
$email = strtolower(trim(is_string($a['email'] ?? null) ? $a['email'] : ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	Cli::fail('uso: php bin/user-add.php --email tu@esempio.it [--nome "..."] [--ruolo admin|operator] [--password ...]');
}
$role = is_string($a['ruolo'] ?? null) ? $a['ruolo'] : 'operator';
if (!in_array($role, ['admin', 'operator'], true)) {
	Cli::fail('ruolo: admin o operator');
}
$pass = is_string($a['password'] ?? null) ? $a['password'] : Cli::prompt('Password (almeno 10 caratteri): ', true);
if (strlen($pass) < 10) {
	Cli::fail('Password troppo corta (almeno 10 caratteri)');
}
$name = is_string($a['nome'] ?? null) ? $a['nome'] : null;
$hash = password_hash($pass, PASSWORD_DEFAULT);
$exists = Db::scalar('SELECT id FROM users WHERE email = ?', [$email]);
if ($exists !== null) {
	Db::exec('UPDATE users SET password_hash = ?, role = ?, name = COALESCE(?, name), active = 1 WHERE id = ?', [$hash, $role, $name, $exists]);
	Cli::out("Utente $email aggiornato ($role).");
} else {
	Db::exec('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$email, $name, $hash, $role]);
	Cli::out("Utente $email creato ($role).");
}
