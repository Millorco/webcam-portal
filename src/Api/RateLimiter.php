<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Config;

/**
 * Limite di richieste al minuto per token (finestra fissa di 60 s), con un
 * file per token in storage/ratelimit/. Basta per un solo server.
 */
final class RateLimiter
{
	public static function hit(int $tokenId): void
	{
		$limit = (int) Config::get()->value('rate_limit_per_min', 120);
		if ($limit <= 0) {
			return;
		}
		$dir = Config::get()->storageDir() . '/ratelimit';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$fh = @fopen($dir . '/t' . $tokenId, 'c+');
		if ($fh === false) {
			return; // senza file nessun limite: meglio che bloccare i router
		}
		try {
			flock($fh, LOCK_EX);
			$now = time();
			$window = $now - ($now % 60);
			$data = explode(' ', trim((string) stream_get_contents($fh)));
			$count = (count($data) === 2 && (int) $data[0] === $window) ? (int) $data[1] : 0;
			$count++;
			ftruncate($fh, 0);
			rewind($fh);
			fwrite($fh, $window . ' ' . $count);
			if ($count > $limit) {
				$retry = max(1, $window + 60 - $now);
				throw new ApiError(429, 'rate_limited', "Troppe richieste: massimo $limit al minuto", ['Retry-After' => (string) $retry]);
			}
		} finally {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}
}
