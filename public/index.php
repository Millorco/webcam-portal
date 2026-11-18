<?php
/**
 * Front controller di webcam-portal: tutte le richieste passano da qui
 * (Apache: .htaccess; server integrato: php -S ... -t public public/index.php).
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
	$file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
	if ($file !== __FILE__ && is_file($file)) {
		return false; // file statico (assets/)
	}
}

require dirname(__DIR__) . '/src/bootstrap.php';

\WebcamPortal\App::run();
