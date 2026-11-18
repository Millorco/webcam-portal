-- ---------------------------------------------------------------------------
-- webcam-portal: schema del database (contratto v1)
--
-- Database separato da quello di Dolibarr, sullo stesso server MariaDB:
--
--   CREATE DATABASE webcam2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   CREATE USER 'webcam2'@'localhost' IDENTIFIED BY '<password lunga>';
--   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP,
--         CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES
--     ON webcam2.* TO 'webcam2'@'localhost';
--   -- DROP serve solo per ALTER TABLE ... DROP PARTITION (retention).
--
-- Compatibilità:
--   - MariaDB 11.8 (VPS) e MariaDB 10.4+ (XAMPP per Windows). Non usa UUID,
--     INET6, partizioni automatiche (PARTITION ... AUTO / INTERVAL),
--     CONVERT PARTITION né la collation utf8mb4_uca1400_ai_ci (11.x):
--     la collation è fissata a utf8mb4_unicode_ci su tutte le tabelle,
--     così un dump passa senza modifiche tra XAMPP e VPS nei due sensi.
--   - JSON in MariaDB è un alias di LONGTEXT: la validità è garantita da
--     CHECK (JSON_VALID(...)), applicato da MariaDB 10.2 in poi.
--   - Tutte le date sono DATETIME in UTC. Il portal apre la connessione con
--     SET time_zone = '+00:00' e converte nel fuso della webcam solo per
--     la visualizzazione (colonna webcams.timezone).
--   - Le tabelle partizionate InnoDB non ammettono FOREIGN KEY (né in
--     entrata né in uscita): measurements non ha vincoli, la pulizia alla
--     cancellazione di una webcam è a carico del portal.
--
-- Esecuzione: mysql -u webcam2 -p webcam2 < schema-mariadb.sql
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Versione dello schema (migrazioni del portal)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
	version      VARCHAR(32)  NOT NULL,
	applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	note         VARCHAR(255) NULL,
	PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Clienti del portale (copia minima dei soggetti terzi di Dolibarr, letti
-- dalle API di Dolibarr: mai dalle sue tabelle)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
	id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
	dolibarr_socid  INT UNSIGNED NULL COMMENT 'llx_societe.rowid, letto da GET /thirdparties',
	name            VARCHAR(255) NOT NULL,
	email           VARCHAR(255) NULL,
	active          TINYINT(1)   NOT NULL DEFAULT 1,
	synced_at       DATETIME     NULL COMMENT 'ultima lettura da Dolibarr',
	created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uk_customers_dolibarr (dolibarr_socid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Utenti del portale: amministratori, operatori (tecnici) e utenti dei clienti
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
	id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
	email           VARCHAR(255) NOT NULL,
	name            VARCHAR(255) NULL,
	password_hash   VARCHAR(255) NOT NULL COMMENT 'password_hash() di PHP (bcrypt/argon2id)',
	role            ENUM('admin','operator','customer') NOT NULL DEFAULT 'customer',
	customer_id     INT UNSIGNED NULL COMMENT 'obbligatorio per role=customer',
	active          TINYINT(1)   NOT NULL DEFAULT 1,
	last_login_at   DATETIME     NULL,
	created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uk_users_email (email),
	KEY idx_users_customer (customer_id),
	CONSTRAINT fk_users_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Webcam (una riga per router). Il MAC è lo stesso usato da inventory_report
-- e da llx_webcams_webcam.mac (minuscolo, con i due punti).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcams (
	id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
	mac                CHAR(17)     CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
	                   COMMENT 'aa:bb:cc:dd:ee:ff, minuscolo',
	slug               VARCHAR(64)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
	                   COMMENT 'nome nell URL pubblico /cam/{slug}/',
	name               VARCHAR(255) NULL,
	hostname           VARCHAR(100) NULL,
	dolibarr_id        INT UNSIGNED NULL COMMENT 'llx_webcams_webcam.rowid',
	dolibarr_ref       VARCHAR(128) NULL COMMENT 'es. WC-AABBCCDDEEFF',
	customer_id        INT UNSIGNED NULL,
	status             TINYINT UNSIGNED NOT NULL DEFAULT 1
	                   COMMENT 'come Dolibarr: 0 da installare, 1 in servizio, 9 ritirata',
	is_public          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = /cam/{slug}/current.jpg visibile a tutti',
	windy_published    TINYINT(1)   NOT NULL DEFAULT 0,
	windy_id           VARCHAR(64)  NULL,
	timezone           VARCHAR(64)  NOT NULL DEFAULT 'Europe/Rome',
	latitude           DECIMAL(9,6) NULL,
	longitude          DECIMAL(9,6) NULL,
	altitude_m         SMALLINT     NULL,
	location           VARCHAR(255) NULL,
	app_version        VARCHAR(30)  NULL,
	board_fw           VARCHAR(30)  NULL,
	camera_model       VARCHAR(150) NULL,
	interval_min       SMALLINT UNSIGNED NULL,
	last_seen_at       DATETIME     NULL COMMENT 'ultima richiesta autenticata',
	last_photo_id      BIGINT UNSIGNED NULL,
	last_photo_at      DATETIME     NULL,
	last_telemetry_at  DATETIME     NULL,
	last_boot_id       CHAR(8)      CHARACTER SET ascii COLLATE ascii_general_ci NULL,
	created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uk_webcams_mac (mac),
	UNIQUE KEY uk_webcams_slug (slug),
	UNIQUE KEY uk_webcams_dolibarr (dolibarr_id),
	KEY idx_webcams_customer (customer_id),
	KEY idx_webcams_public (is_public, status),
	CONSTRAINT fk_webcams_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_webcams_mac CHECK (mac REGEXP '^([0-9a-f]{2}:){5}[0-9a-f]{2}$'),
	CONSTRAINT ck_webcams_slug CHECK (slug REGEXP '^[a-z0-9][a-z0-9-]{0,63}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Token delle webcam (Authorization: Bearer). Si salva solo lo SHA-256 del
-- token: il token in chiaro si vede una volta sola, alla creazione.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tokens (
	id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id      INT UNSIGNED NOT NULL,
	token_hash     CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hex(sha256(token))',
	token_prefix   CHAR(12)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'primi 12 caratteri, per riconoscerlo',
	label          VARCHAR(100) NULL,
	scopes         SET('photo','telemetry','event','read') NOT NULL DEFAULT 'photo,telemetry,event',
	created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	expires_at     DATETIME     NULL,
	last_used_at   DATETIME     NULL,
	last_used_ip   VARCHAR(45)  NULL,
	revoked_at     DATETIME     NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_tokens_hash (token_hash),
	KEY idx_tokens_webcam (webcam_id),
	CONSTRAINT fk_tokens_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Foto: un record per ogni foto ricevuta (contratto foto/1, anche foto/0).
-- Il file sta su disco in storage/photos/<webcam_id>/AAAA/MM/GG/<file>.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photos (
	id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id       INT UNSIGNED NOT NULL,
	taken_at        DATETIME     NOT NULL COMMENT 'UTC, dal campo time',
	tz_offset_min   SMALLINT     NULL COMMENT 'scostamento del router dal campo datetime (+0100 = 60)',
	time_synced     TINYINT(1)   NOT NULL DEFAULT 0,
	received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	schema_ver      TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = JSON senza campo schema',
	sha256          CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	size_bytes      INT UNSIGNED NOT NULL,
	width           SMALLINT UNSIGNED NULL,
	height          SMALLINT UNSIGNED NULL,
	file_name       VARCHAR(200) NOT NULL COMMENT 'nome originale, es. raw_20261118-1430.jpg',
	storage_path    VARCHAR(255) NOT NULL COMMENT 'relativo alla cartella storage del portal',
	mode            ENUM('day','night') NULL,
	special         VARCHAR(64)  NULL,
	iso             VARCHAR(16)  NULL COMMENT 'impostazione richiesta (settings.iso)',
	aperture        VARCHAR(16)  NULL,
	shutterspeed    VARCHAR(16)  NULL,
	exif_iso        MEDIUMINT UNSIGNED NULL COMMENT 'valori reali letti dall EXIF dal portal',
	exif_exposure   VARCHAR(16)  NULL,
	exif_fnumber    DECIMAL(4,1) NULL,
	t_in            DECIMAL(4,1) NULL,
	rh_in           DECIMAL(4,1) NULL,
	t_ext           DECIMAL(4,1) NULL,
	rh_ext          DECIMAL(4,1) NULL,
	battery_v       DECIMAL(5,2) NULL,
	camera_serial   VARCHAR(64)  NULL,
	shutter_count   INT UNSIGNED NULL,
	app_version     VARCHAR(30)  NULL,
	from_queue      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = arrivata in ritardo dalla coda offline',
	is_public       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = nascosta (privacy, foto di prova)',
	meta            LONGTEXT     NULL COMMENT 'JSON originale ricevuto',
	deleted_at      DATETIME     NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_photos_sha (webcam_id, sha256),
	KEY idx_photos_webcam_time (webcam_id, taken_at),
	KEY idx_photos_received (received_at),
	CONSTRAINT fk_photos_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT ck_photos_sha CHECK (sha256 REGEXP '^[0-9a-f]{64}$'),
	CONSTRAINT ck_photos_meta CHECK (meta IS NULL OR JSON_VALID(meta))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Misure grezze: un campione ogni 5 minuti (contratto telemetria/1).
-- Partizionata per mese, conservata 1 anno: ogni mese il portal
--   1) aggiunge la partizione del mese dopo il prossimo (REORGANIZE di pmax)
--   2) toglie quelle più vecchie di 12 mesi (DROP PARTITION)
-- (vedi in fondo al file). Nessuna FOREIGN KEY: le tabelle partizionate non
-- le supportano. Ogni chiave unica deve contenere ts (colonna di partizione).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS measurements (
	webcam_id        INT UNSIGNED NOT NULL,
	ts               DATETIME     NOT NULL COMMENT 'UTC, campo time del campione',
	sample_id        VARCHAR(24)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL
	                 COMMENT '<boot_id 8 hex>-<seq>, idempotenza',
	time_synced      TINYINT(1)   NOT NULL,
	time_corrected   TINYINT(1)   NOT NULL DEFAULT 0,
	received_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	uptime_s         INT UNSIGNED NULL,
	-- clima (climate)
	sensor_ok        TINYINT(1)   NULL,
	t_in             DECIMAL(4,1) NULL,
	rh_in            DECIMAL(4,1) NULL,
	dp_in            DECIMAL(4,1) NULL,
	ah_in            DECIMAL(5,2) NULL COMMENT 'umidità assoluta g/m3',
	ext_sensor_ok    TINYINT(1)   NULL,
	t_ext            DECIMAL(4,1) NULL,
	rh_ext           DECIMAL(4,1) NULL,
	dp_ext           DECIMAL(4,1) NULL,
	ah_ext           DECIMAL(5,2) NULL,
	heating_enabled  TINYINT(1)   NULL,
	heater           TINYINT(1)   NULL,
	fan              TINYINT(1)   NULL,
	-- alimentazione (power, Victron VE.Direct)
	battery_v        DECIMAL(5,2) NULL,
	battery_a        DECIMAL(6,2) NULL COMMENT '+ in carica, - in scarica',
	panel_v          DECIMAL(5,2) NULL,
	panel_w          SMALLINT UNSIGNED NULL,
	load_a           DECIMAL(5,2) NULL,
	load_on          TINYINT(1)   NULL,
	charge_state     TINYINT UNSIGNED NULL COMMENT 'CS di VE.Direct: 0 off, 3 bulk, 4 absorption, 5 float...',
	mppt_state       TINYINT UNSIGNED NULL COMMENT 'MPPT di VE.Direct: 0 off, 1 limitato, 2 attivo',
	victron_error    TINYINT UNSIGNED NULL COMMENT 'ERR di VE.Direct, 0 = nessuno',
	soc_pct          DECIMAL(4,1) NULL COMMENT 'solo con BMV/SmartShunt o stima',
	yield_today_wh   SMALLINT UNSIGNED NULL,
	yield_total_wh   INT UNSIGNED NULL,
	-- rete (net)
	net_type         VARCHAR(8)   NULL COMMENT 'lte, umts, gsm, wan, wifi',
	operator         VARCHAR(32)  NULL,
	rssi_dbm         SMALLINT     NULL,
	rsrp_dbm         SMALLINT     NULL,
	rsrq_db          DECIMAL(4,1) NULL,
	sinr_db          DECIMAL(4,1) NULL,
	rx_bytes         BIGINT UNSIGNED NULL COMMENT 'contatore dall avvio',
	tx_bytes         BIGINT UNSIGNED NULL,
	rx_delta         INT UNSIGNED NULL COMMENT 'byte dal campione precedente, calcolato dal portal',
	tx_delta         INT UNSIGNED NULL,
	-- sistema (sys)
	load1            DECIMAL(5,2) NULL,
	mem_free_kb      INT UNSIGNED NULL,
	tmp_free_kb      INT UNSIGNED NULL,
	disk_free_kb     INT UNSIGNED NULL,
	queue_photos     SMALLINT UNSIGNED NULL,
	queue_kb         INT UNSIGNED NULL,
	queue_samples    SMALLINT UNSIGNED NULL,
	-- fotocamera (camera)
	camera_present   TINYINT(1)   NULL,
	camera_power     TINYINT(1)   NULL,
	extra            LONGTEXT     NULL COMMENT 'JSON: campi del campione non mappati in colonna',
	PRIMARY KEY (webcam_id, ts, sample_id),
	KEY idx_measurements_ts (ts),
	CONSTRAINT ck_measurements_extra CHECK (extra IS NULL OR JSON_VALID(extra))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (ts) (
	PARTITION p_old    VALUES LESS THAN ('2026-11-01 00:00:00'),
	PARTITION p2026_11 VALUES LESS THAN ('2026-12-01 00:00:00'),
	PARTITION p2026_12 VALUES LESS THAN ('2027-01-01 00:00:00'),
	PARTITION p2027_01 VALUES LESS THAN ('2027-02-01 00:00:00'),
	PARTITION p2027_02 VALUES LESS THAN ('2027-03-01 00:00:00'),
	PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

-- ---------------------------------------------------------------------------
-- Medie orarie (per sempre). Calcolate dal portal a ogni ora chiusa
-- e ricalcolate quando arrivano campioni in ritardo per quell'ora.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS measurements_hourly (
	webcam_id        INT UNSIGNED NOT NULL,
	hour_ts          DATETIME     NOT NULL COMMENT 'UTC, inizio dell ora',
	samples          TINYINT UNSIGNED NOT NULL COMMENT 'campioni nell ora (12 = completa)',
	samples_unsynced TINYINT UNSIGNED NOT NULL DEFAULT 0,
	t_in_avg         DECIMAL(4,1) NULL,
	t_in_min         DECIMAL(4,1) NULL,
	t_in_max         DECIMAL(4,1) NULL,
	rh_in_avg        DECIMAL(4,1) NULL,
	rh_in_max        DECIMAL(4,1) NULL,
	t_ext_avg        DECIMAL(4,1) NULL,
	t_ext_min        DECIMAL(4,1) NULL,
	t_ext_max        DECIMAL(4,1) NULL,
	rh_ext_avg       DECIMAL(4,1) NULL,
	rh_ext_min       DECIMAL(4,1) NULL,
	rh_ext_max       DECIMAL(4,1) NULL,
	dp_ext_avg       DECIMAL(4,1) NULL,
	ah_ext_avg       DECIMAL(5,2) NULL,
	heater_min       TINYINT UNSIGNED NULL COMMENT 'minuti con riscaldamento acceso (campioni x 5)',
	fan_min          TINYINT UNSIGNED NULL,
	battery_v_avg    DECIMAL(5,2) NULL,
	battery_v_min    DECIMAL(5,2) NULL,
	battery_v_max    DECIMAL(5,2) NULL,
	battery_a_avg    DECIMAL(6,2) NULL,
	panel_w_avg      SMALLINT UNSIGNED NULL,
	panel_w_max      SMALLINT UNSIGNED NULL,
	panel_wh         DECIMAL(7,1) NULL COMMENT 'energia stimata dai campioni',
	load_a_avg       DECIMAL(5,2) NULL,
	soc_avg          DECIMAL(4,1) NULL,
	soc_min          DECIMAL(4,1) NULL,
	rssi_avg         SMALLINT     NULL,
	rssi_min         SMALLINT     NULL,
	rsrp_avg         SMALLINT     NULL,
	rx_bytes         BIGINT UNSIGNED NULL COMMENT 'somma di rx_delta',
	tx_bytes         BIGINT UNSIGNED NULL,
	mem_free_min     INT UNSIGNED NULL,
	disk_free_min    INT UNSIGNED NULL,
	queue_photos_max SMALLINT UNSIGNED NULL,
	photos           SMALLINT UNSIGNED NULL COMMENT 'foto scattate nell ora (da photos)',
	computed_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id, hour_ts),
	KEY idx_hourly_ts (hour_ts),
	CONSTRAINT fk_hourly_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Eventi: dal router (contratto evento/1), dal portal (es. webcam muta),
-- dagli operatori (interventi) e da Dolibarr.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
	id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id               INT UNSIGNED NOT NULL,
	event_uid               VARCHAR(24)  CHARACTER SET ascii COLLATE ascii_bin NULL
	                        COMMENT 'id del router <boot>-E<seq>; NULL per eventi del portal',
	ts                      DATETIME     NOT NULL,
	time_synced             TINYINT(1)   NOT NULL DEFAULT 1,
	received_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	type                    VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	severity                ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info',
	source                  ENUM('router','portal','operator','dolibarr') NOT NULL DEFAULT 'router',
	message                 VARCHAR(500) NULL,
	data                    LONGTEXT     NULL COMMENT 'JSON',
	user_id                 INT UNSIGNED NULL COMMENT 'operatore, per source=operator',
	dolibarr_actioncomm_id  INT UNSIGNED NULL,
	dolibarr_ticket_id      INT UNSIGNED NULL,
	dolibarr_fichinter_id   INT UNSIGNED NULL,
	dolibarr_synced_at      DATETIME     NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_events_uid (webcam_id, event_uid),
	KEY idx_events_webcam_ts (webcam_id, ts),
	KEY idx_events_type_ts (type, ts),
	KEY idx_events_dolibarr_pending (dolibarr_synced_at, severity),
	CONSTRAINT fk_events_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_events_data CHECK (data IS NULL OR JSON_VALID(data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Reflex: una riga per corpo macchina (numero di serie), che può passare da
-- una webcam all'altra tra un noleggio e l'altro.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cameras (
	id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
	model              VARCHAR(150) NOT NULL,
	serial             VARCHAR(64)  NOT NULL COMMENT 'eosserialnumber o serialnumber di gphoto2',
	current_webcam_id  INT UNSIGNED NULL,
	shutter_rated      INT UNSIGNED NULL COMMENT 'vita dichiarata dell otturatore, per gli avvisi',
	first_seen_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	last_seen_at       DATETIME     NULL,
	note               VARCHAR(255) NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_cameras_serial (model, serial),
	KEY idx_cameras_webcam (current_webcam_id),
	CONSTRAINT fk_cameras_webcam FOREIGN KEY (current_webcam_id) REFERENCES webcams (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Contatori della reflex nel tempo. Si aggiunge una riga solo quando un
-- valore cambia (non a ogni lotto di telemetria).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS camera_counters (
	id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id             INT UNSIGNED NOT NULL,
	camera_id             INT UNSIGNED NULL COMMENT 'NULL se il numero di serie non è leggibile',
	ts                    DATETIME     NOT NULL,
	shutter_count         INT UNSIGNED NULL COMMENT 'scatti letti dalla fotocamera',
	shots_total           INT UNSIGNED NULL COMMENT 'scatti contati dal router con questa fotocamera',
	power_on_hours        DECIMAL(8,1) NULL COMMENT 'ore con il relè fotocamera acceso',
	power_cycles          INT UNSIGNED NULL COMMENT 'cicli di alimentazione dal relè',
	capture_errors        INT UNSIGNED NULL,
	battery_level         VARCHAR(16)  NULL COMMENT 'batterylevel di gphoto2, es. 100%',
	source                ENUM('telemetry','photo','operator','dolibarr') NOT NULL DEFAULT 'telemetry',
	dolibarr_synced_at    DATETIME     NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_counters_webcam_ts (webcam_id, ts, source),
	KEY idx_counters_camera_ts (camera_id, ts),
	CONSTRAINT fk_counters_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_counters_camera FOREIGN KEY (camera_id) REFERENCES cameras (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, note) VALUES ('1', 'contratto v1');

-- ---------------------------------------------------------------------------
-- Manutenzione mensile delle partizioni (cron del portal, il 1° del mese).
-- Esempio per il 1° marzo 2027: crea il mese di aprile 2027 spezzando
-- p_future (vuota, quindi istantaneo) e cancella i mesi più vecchi di un
-- anno. Funziona da MariaDB 10.4 (niente CONVERT PARTITION).
--
--   ALTER TABLE measurements REORGANIZE PARTITION p_future INTO (
--     PARTITION p2027_04 VALUES LESS THAN ('2027-05-01 00:00:00'),
--     PARTITION p_future VALUES LESS THAN (MAXVALUE));
--
--   -- retention di 12 mesi: prima di cancellare, measurements_hourly deve
--   -- essere completa per quei mesi
--   ALTER TABLE measurements DROP PARTITION p2026_02;
--
-- Dopo il DROP l'intervallo di quel mese ricade nella partizione successiva:
-- per questo il portal rifiuta i campioni più vecchi della retention
-- (vedi api-portal-v1.md). p_old raccoglie solo campioni con l'ora molto
-- sbagliata e si svuota con: ALTER TABLE measurements TRUNCATE PARTITION p_old;
-- ---------------------------------------------------------------------------
