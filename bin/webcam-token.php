<?php
/**
 * Genera un token nuovo per una webcam e revoca quelli vecchi.
 *
 *   php bin/webcam-token.php --slug monte-cavo      (oppure --mac aa:bb:...)
 *   php bin/webcam-token.php --slug monte-cavo --mantieni   (non revoca i vecchi)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Db;
use WebcamPortal\Service\Tokens;
use WebcamPortal\Service\Webcams;

Cli::only();
$a = Cli::args($argv);
if (is_string($a['slug'] ?? null)) {
	$w = Db::one('SELECT id, slug FROM webcams WHERE slug = ?', [$a['slug']]);
} elseif (is_string($a['mac'] ?? null)) {
	$w = Db::one('SELECT id, slug FROM webcams WHERE mac = ?', [Webcams::normalizeMac($a['mac'])]);
} else {
	Cli::fail('uso: php bin/webcam-token.php --slug <slug> | --mac <mac> [--mantieni]');
}
if ($w === null) {
	Cli::fail('Webcam non trovata');
}
$token = Tokens::create((int) $w['id'], 'rigenerato da riga di comando', !isset($a['mantieni']));
Cli::out("Nuovo token per {$w['slug']}" . (isset($a['mantieni']) ? '' : ' (i precedenti sono revocati)') . ':');
Cli::out($token);
