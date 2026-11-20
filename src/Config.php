<?php

declare(strict_types=1);

namespace WebcamPortal;

/**
 * Configurazione: valori predefiniti di config/config.example.php, poi
 * config/config.php (o il file in WEBCAM_CONFIG), poi le variabili d'ambiente.
 */
final class Config
{
	private static ?Config $instance = null;

	/** @var array<string,mixed> */
	private array $values;

	/** Variabili d'ambiente => chiave (con il punto per i livelli). */
	private const ENV = [
		'APP_ENV' => 'app_env',
		'DB_HOST' => 'db.host',
		'DB_PORT' => 'db.port',
		'DB_NAME' => 'db.name',
		'DB_USER' => 'db.user',
		'DB_PASS' => 'db.pass',
		'PUBLIC_BASE_URL' => 'public_base_url',
		'BASE_PATH' => 'base_path',
		'TRUST_PROXY' => 'trust_proxy',
		'STORAGE_DIR' => 'storage_dir',
		'PHOTO_MAX_BYTES' => 'photo_max_bytes',
		'RATE_LIMIT_PER_MIN' => 'rate_limit_per_min',
		'RETENTION_MEASUREMENTS_MONTHS' => 'retention_measurements_months',
		'RETENTION_PHOTOS_DAYS' => 'retention_photos_days',
		'LOW_BATTERY_V' => 'low_battery_v',
		'DOLIBARR_URL' => 'dolibarr.url',
		'DOLIBARR_API_KEY' => 'dolibarr.api_key',
		'DOLIBARR_TIMEOUT' => 'dolibarr.timeout',
		'DOLIBARR_VERIFY_TLS' => 'dolibarr.verify_tls',
		'RTTYS_URL' => 'rttys_url',
		'PHOTO_METRICS_MEMORY' => 'photo_metrics.memory_limit',
		'VICTORIALOGS_URL' => 'victorialogs.url',
		'VICTORIALOGS_USER' => 'victorialogs.user',
		'VICTORIALOGS_PASS' => 'victorialogs.pass',
		'VICTORIALOGS_TIMEOUT' => 'victorialogs.timeout',
		'AVAILABILITY_PUBLIC' => 'availability_public',
		'SITE_RATE_LIMIT_PER_MIN' => 'site_api.rate_limit_per_min',
		'SITE_ANON_RATE_LIMIT_PER_MIN' => 'site_api.anon_rate_limit_per_min',
		'SITE_URL_SECRET' => 'site_api.url_secret',
		'SITE_CACHE_DAYS' => 'site_api.cache_days',
		'FFMPEG_PATH' => 'ffmpeg.path',
		'TELEGRAM_BOT_TOKEN' => 'telegram.bot_token',
		'TELEGRAM_WEBHOOK_SECRET' => 'telegram.webhook_secret',
		'TELEGRAM_ENABLED' => 'telegram.enabled',
		'TELEGRAM_MODE' => 'telegram.mode',
	];

	/** @param array<string,mixed> $values */
	public function __construct(array $values)
	{
		$this->values = $values;
	}

	public static function get(): Config
	{
		if (self::$instance === null) {
			self::$instance = self::load();
		}
		return self::$instance;
	}

	public static function set(Config $config): void
	{
		self::$instance = $config;
	}

	public static function load(): Config
	{
		$root = WEBCAM_PORTAL_ROOT;
		$values = require $root . '/config/config.example.php';
		$file = getenv('WEBCAM_CONFIG') ?: $root . '/config/config.php';
		if (is_file($file)) {
			$local = require $file;
			if (is_array($local)) {
				$values = array_replace_recursive($values, $local);
			}
		}
		foreach (self::ENV as $env => $key) {
			$v = getenv($env);
			if ($v === false) {
				continue;
			}
			$parts = explode('.', $key);
			$ref = &$values;
			foreach ($parts as $p) {
				if (!isset($ref[$p]) || !is_array($ref)) {
					$ref[$p] = null;
				}
				$ref = &$ref[$p];
			}
			$ref = self::cast($v, $ref);
			unset($ref);
		}
		return new Config($values);
	}

	private static function cast(string $v, mixed $current): mixed
	{
		if (is_bool($current)) {
			return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'si'], true);
		}
		if (is_int($current)) {
			return (int) $v;
		}
		if (is_float($current)) {
			return (float) $v;
		}
		return $v;
	}

	public function value(string $key, mixed $default = null): mixed
	{
		$ref = $this->values;
		foreach (explode('.', $key) as $p) {
			if (!is_array($ref) || !array_key_exists($p, $ref)) {
				return $default;
			}
			$ref = $ref[$p];
		}
		return $ref;
	}

	public function isDev(): bool
	{
		return $this->value('app_env') === 'dev';
	}

	public function storageDir(): string
	{
		$dir = (string) $this->value('storage_dir', '');
		if ($dir === '') {
			$dir = WEBCAM_PORTAL_ROOT . '/storage';
		}
		return rtrim(str_replace('\\', '/', $dir), '/');
	}
}
