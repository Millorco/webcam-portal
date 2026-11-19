<?php
/**
 * Crea o aggiorna lo schema del database applicando le migrazioni in
 * migrations/ (tabella schema_migrations), poi prepara le partizioni dei mesi
 * prossimi e le cartelle di storage. Si può rieseguire senza danni.
 *
 *   php bin/install.php
 *   C:\xampp\php\php.exe bin\install.php        (Windows, XAMPP)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Config;
use WebcamPortal\Db;
use WebcamPortal\Service\Migrator;
use WebcamPortal\Service\Partitions;

Cli::only();
$cfg = Config::get();
try {
	$pdo = Db::pdo();
} catch (PDOException $e) {
	Cli::fail('Connessione a MariaDB fallita (' . $cfg->value('db.user') . '@' . $cfg->value('db.host') . '/' . $cfg->value('db.name') . '): ' . $e->getMessage()
		. "\nControlla config/config.php.");
}
Cli::out('MariaDB ' . $pdo->query('SELECT VERSION()')->fetchColumn() . ', database ' . $cfg->value('db.name'));

$applied = (new Migrator($pdo, WEBCAM_PORTAL_ROOT . '/migrations'))->migrate([Cli::class, 'out']);
Cli::out($applied ? 'Migrazioni applicate: ' . implode(', ', $applied) : 'Schema già aggiornato.');

$created = Partitions::ensure(2);
Cli::out($created ? 'Partizioni create: ' . implode(', ', $created) : 'Partizioni dei prossimi mesi già presenti.');

$storage = $cfg->storageDir();
// 0.4.0 (contratto 1.3): loghi, timelapse, PDF, registro delle impronte, blocchi e stato dei servizi
foreach (['photos', 'logs', 'sessions', 'ratelimit', 'reference', 'cache', 'overlay', 'timelapse', 'reports', 'certificates', 'register', 'locks', 'state', 'tmp'] as $d) {
	if (!is_dir("$storage/$d") && !@mkdir("$storage/$d", 0775, true)) {
		Cli::fail("Impossibile creare $storage/$d");
	}
}
if (!is_writable("$storage/photos")) {
	Cli::fail("$storage/photos non è scrivibile dal processo PHP");
}
Cli::out("Cartella dati: $storage");

$users = (int) Db::scalar("SELECT COUNT(*) FROM users WHERE role IN ('admin','operator')");
if ($users === 0) {
	Cli::out('Nessun operatore: crea il primo con  php bin/user-add.php --email tu@esempio.it --ruolo admin');
}
Cli::out('Fatto.');
