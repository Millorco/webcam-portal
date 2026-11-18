<?php
/**
 * Avvio comune di public/index.php, degli script in bin/ e dei test:
 * autoload PSR-4 (namespace WebcamPortal\ -> src/), fuso UTC, configurazione.
 */

declare(strict_types=1);

const WEBCAM_PORTAL_VERSION = '0.2.0';
define('WEBCAM_PORTAL_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
	$prefix = 'WebcamPortal\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

// Tutte le date interne sono UTC; il fuso della webcam serve solo a mostrarle.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
