<?php
/**
 * Crea una webcam e stampa il suo token (una sola volta: nel database c'è
 * solo lo SHA-256).
 *
 *   php bin/webcam-add.php --mac 94:83:c4:12:ab:cd --nome "Monte Cavo" [--slug monte-cavo]
 *                          [--pubblica] [--fuso Europe/Rome] [--localita "Rocca di Papa"]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;

Cli::only();
$a = Cli::args($argv);
if (!isset($a['mac'], $a['nome']) || $a['mac'] === true || $a['nome'] === true) {
	Cli::fail('uso: php bin/webcam-add.php --mac aa:bb:cc:dd:ee:ff --nome "Nome" [--slug nome] [--pubblica] [--fuso Europe/Rome] [--localita "..."]');
}
try {
	$id = Webcams::create((string) $a['mac'], (string) $a['nome'], is_string($a['slug'] ?? null) ? $a['slug'] : '', [
		'is_public' => isset($a['pubblica']),
		'timezone' => is_string($a['fuso'] ?? null) ? $a['fuso'] : 'Europe/Rome',
		'location' => is_string($a['localita'] ?? null) ? $a['localita'] : null,
	]);
} catch (InvalidArgumentException $e) {
	Cli::fail($e->getMessage());
}
$token = Tokens::create($id, 'creato da riga di comando');
$w = WebcamPortal\Db::one('SELECT mac, slug FROM webcams WHERE id = ?', [$id]);
Cli::out("Webcam $id creata: mac {$w['mac']}, slug {$w['slug']}");
Cli::out('Token (copialo ora in LuCI, non verrà più mostrato):');
Cli::out($token);
