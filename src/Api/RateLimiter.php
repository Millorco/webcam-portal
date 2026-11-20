<?php

declare(strict_types=1);

namespace WebcamPortal\Api;

use WebcamPortal\Config;

/**
 * Limite di richieste al minuto (finestra fissa di 60 s), con un file per
 * contatore in storage/ratelimit/: per token dei router (t<id>), per chiave
 * dei siti (k<id>) e per indirizzo senza chiave (ip-<sha1>). Basta per un
 * solo server.
 */
final class RateLimiter
{
	public static function hit(int $tokenId): void
	{
		self::hitBucket('t' . $tokenId, (int) Config::get()->value('rate_limit_per_min', 120));
	}

	/**
	 * Conta una richiesta nel contatore $bucket ([a-z0-9-]) e lancia 429 oltre
	 * $limit al minuto. Restituisce le richieste ancora possibili nel minuto.
	 */
	public static function hitBucket(string $bucket, int $limit): int
	{
		if ($limit <= 0) {
			return PHP_INT_MAX;
		}
		$dir = Config::get()->storageDir() . '/ratelimit';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		$fh = @fopen($dir . '/' . preg_replace('/[^a-z0-9-]/', '', strtolower($bucket)), 'c+');
		if ($fh === false) {
			return $limit; // senza file nessun limite: meglio che bloccare i router
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
			return $limit - $count;
		} finally {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}
}
