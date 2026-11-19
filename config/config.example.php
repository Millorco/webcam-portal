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

	// Accesso remoto rttys per i link nei ticket, se Dolibarr (GET
	// webcams/{id}/portal) non li manda: <url>/api/connect/<ref Dolibarr>.
	'rttys_url' => 'https://remote.viewlapse.com',  // RTTYS_URL ('' = nessun link)

	// Diagnosi automatica (contratto 1.1, diagnosi-v1.md): bin/rules.php.
	'rules' => [
		'reopen_within_h' => 24,    // stessa regola entro N ore dalla chiusura: si riapre lo stesso avviso
		// Soglie diverse dai predefiniti per tutte le webcam, per regola, es.
		// 'camera.stalled' => ['hours' => 4, 'capture_now' => true],
		// (per una sola webcam: tabella rule_config dal pannello).
		'params' => [],
		'disabled' => [],           // id di regole spente per tutte le webcam, es. ['enclosure.fan']
	],

	// Analisi delle foto con GD (bin/photo-metrics.php, mai durante l'upload).
	'photo_metrics' => [
		'enabled' => true,
		// Un JPEG da 24 MP decompresso occupa circa 100 MB: sotto questo
		// limite la foto si salta (skip_reason = memory) e si scrive nel log.
		'memory_limit' => '512M',   // PHOTO_METRICS_MEMORY
		'every_min' => 30,          // al massimo una foto analizzata ogni N minuti per webcam
		'max_per_run' => 300,       // foto analizzate per esecuzione, tutte le webcam
		// Strisce in alto e in basso (% dell'altezza) escluse dal controllo
		// "camera mossa": lì di solito c'è la scritta con data e ora, che resta
		// ferma anche se l'inquadratura si sposta. 0 = nessuna esclusione.
		'mask_top_pct' => 6,
		'mask_bottom_pct' => 6,
	],

	// Log dei router in VictoriaLogs (webcam-logserver, docs/query.md), sola
	// lettura con l'API HTTP. URL vuoto = regole logs.* solo con gli eventi.
	'victorialogs' => [
		'url' => '',                // VICTORIALOGS_URL, es. http://victorialogs:9428
		'user' => '',               // VICTORIALOGS_USER (Basic Auth, es. admin)
		'pass' => '',               // VICTORIALOGS_PASS (contenuto di vl_password)
		'timeout' => 5,             // VICTORIALOGS_TIMEOUT, secondi
		'every_min' => 15,          // una query ogni N minuti (bin/rules.php gira ogni 5)
	],

	// Disponibilità mensile anche nella pagina pubblica /cam/{slug}/ delle
	// webcam pubbliche (solo la percentuale).                 AVAILABILITY_PUBLIC
	'availability_public' => false,

	// API in sola lettura per i siti (plugin WordPress), api-siti-v1.md.
	'site_api' => [
		'rate_limit_per_min' => 600,        // SITE_RATE_LIMIT_PER_MIN: per chiave (la chiave può averne uno suo)
		'anon_rate_limit_per_min' => 120,   // SITE_ANON_RATE_LIMIT_PER_MIN: senza chiave, per indirizzo IP
		'image_rate_limit_per_min' => 1200, // /img/..., per indirizzo IP
		'cache_max_age' => 60,              // Cache-Control delle risposte JSON, secondi
		// Segreto degli URL firmati delle immagini (HMAC). Vuoto = generato da
		// sé in storage/keys/site-url.secret. Cambiarlo invalida gli URL già
		// dati ai siti (si rigenerano alla richiesta successiva).  SITE_URL_SECRET
		'url_secret' => '',
		'image_url_ttl' => 7 * 86400,       // validità minima degli URL delle immagini, secondi
		'image_url_step' => 86400,          // scadenza arrotondata: lo stesso URL per un giorno
		'jpeg_quality' => 82,               // qualità delle riduzioni
		// Riduzioni in storage/cache/img non usate da N giorni: cancellate da
		// bin/retention-photos.php (si rifanno alla richiesta successiva).
		'cache_days' => 30,                 // SITE_CACHE_DAYS
		'series_max_days' => 400,           // intervallo massimo di /series
	],
];
