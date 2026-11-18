<?php
/**
 * Configurazione di webcam-portal.
 *
 * Copia questo file in config/config.php (ignorato da git) e cambia i valori.
 * Ogni chiave si può anche impostare con una variabile d'ambiente (utile in
 * Docker): il nome è indicato nel commento e ha la precedenza sul file.
 */

return [
	// prod | dev. Con "dev" le API accettano anche HTTP senza TLS (anteprima
	// XAMPP, test). In produzione lasciare "prod".             APP_ENV
	'app_env' => 'prod',

	// Database MariaDB del portal (separato da quello di Dolibarr).
	'db' => [
		'host' => '127.0.0.1',      // DB_HOST (in Docker: host.docker.internal o il nome del container)
		'port' => 3306,             // DB_PORT
		'name' => 'webcam2',        // DB_NAME
		'user' => 'webcam2',        // DB_USER
		'pass' => '',               // DB_PASS
	],

	// URL pubblico del portal, senza barra finale: serve per gli URL assoluti
	// di current.jpg (current.json, Dolibarr). Vuoto = ricavato dalla richiesta
	// (dagli script CLI resta vuoto).                          PUBLIC_BASE_URL
	// Esempi: https://portal.viewlapse.com   http://localhost/webcam-portal
	'public_base_url' => '',

	// Sottocartella del sito ('' = automatico). Es. '/webcam-portal'. BASE_PATH
	'base_path' => '',

	// Dietro Nginx Proxy Manager: fidarsi di X-Forwarded-Proto e
	// X-Forwarded-For. Solo se il portal non è raggiungibile direttamente. TRUST_PROXY
	'trust_proxy' => false,

	// Cartella dei dati (foto, log, sessioni). Deve stare fuori da public/.
	// Vuoto = storage/ nella cartella del portal.             STORAGE_DIR
	'storage_dir' => '',

	// Limiti delle API (api-portal-v1.md, "Limiti").
	'photo_max_bytes' => 25 * 1024 * 1024,     // PHOTO_MAX_BYTES
	'rate_limit_per_min' => 120,               // RATE_LIMIT_PER_MIN, per token

	// Retention (giorni/mesi).
	'retention_measurements_months' => 12,     // RETENTION_MEASUREMENTS_MONTHS
	'retention_photos_days' => 90,             // RETENTION_PHOTOS_DAYS: poi una foto l'ora

	// Eventi generati dal portal.
	'low_battery_v' => 11.8,                   // LOW_BATTERY_V: sotto questa tensione power.low_battery

	// Dolibarr (modulo webcams, api-dolibarr-v2.md). Vuoto = sincronizzazione spenta.
	'dolibarr' => [
		// URL delle API, fino a index.php compreso.             DOLIBARR_URL
		// Es. http://localhost/dolibarr/api/index.php (XAMPP), oppure
		// http://localhost/dolibarr/htdocs/api/index.php se Dolibarr è nella sua cartella htdocs
		'url' => '',
		'api_key' => '',            // DOLIBARR_API_KEY (utente webcam-portal)
		'timeout' => 20,            // DOLIBARR_TIMEOUT, secondi
		'verify_tls' => true,       // DOLIBARR_VERIFY_TLS
	],
];
