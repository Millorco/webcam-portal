-- ---------------------------------------------------------------------------
-- webcam-portal: migrazione 004 (contratto 1.3, fase 3b: servizi che si vendono)
--
-- Si esegue dopo schema-mariadb-003.sql (migrazione 3). Aggiunge le tabelle
-- dei servizi descritti in servizi-v1.md e api-siti-v1.md (1.3):
--
--   - livello di listino e opzioni per webcam, letti da Dolibarr
--     (GET webcams/{id}/portal, modulo 1.4): colonne nuove di webcam_service;
--     archivio dopo il noleggio sulla riga di customer_webcams;
--   - sovrimpressione (M2), loghi e immagini degli sponsor, sponsor e
--     infopoint con periodo di validità (M12);
--   - punteggio delle foto "buone" (O8), usato da timelapse, foto del giorno
--     e rapporto mensile;
--   - timelapse (M7, M10): richieste e lavori, stato, file;
--   - foto del giorno (M11);
--   - rapporti mensili (O3) e invio a Dolibarr;
--   - registro giornaliero delle impronte SHA-256 e giornate di maltempo (O2),
--     certificati;
--   - statistiche di visualizzazione aggregate (M8), senza IP in chiaro;
--   - bot Telegram (O10): codici di abbinamento, chat, iscrizioni, messaggi;
--   - conteggi dei log in più per il rapporto mensile (log_counters).
--
-- Stesse regole delle migrazioni 1-3: MariaDB 10.4+ e 11.8, collation
-- utf8mb4_unicode_ci, DATETIME in UTC, DATE nel fuso della webcam dove è
-- detto, JSON come LONGTEXT con CHECK (JSON_VALID(...)), colonne generate
-- PERSISTENT per "una sola riga attiva" dentro un indice UNIQUE.
--
-- I file (loghi, video, PDF, elenchi delle impronte) stanno su disco sotto
-- storage/; nel database c'è solo il percorso relativo a storage/ e lo SHA-256.
--
-- Esecuzione: mysql -u webcam2 -p webcam2 < schema-mariadb-004.sql
-- (il portal la applica da sé come migrations/004_*.sql)
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Livello di listino e opzioni (api-dolibarr-v2.md, "Listino e servizi").
-- Dolibarr è la fonte quando webcam_service.source = 'dolibarr': i valori
-- si sovrascrivono a ogni sincronizzazione. plan_level NULL = 'base'.
-- plan_options: JSON con gli stessi nomi dell'oggetto "options" di
-- GET webcams/{id}/portal (sponsor, weather_cert, film, report).
-- weather_params / timelapse_params / overlay_rev: impostazioni del portal,
-- mai scritte da Dolibarr.
-- ---------------------------------------------------------------------------
ALTER TABLE webcam_service
	ADD COLUMN IF NOT EXISTS plan_level        ENUM('base','standard','pro') NULL COMMENT 'NULL = base' AFTER photo_metrics,
	ADD COLUMN IF NOT EXISTS plan_options      LONGTEXT NULL COMMENT 'JSON: options di GET webcams/{id}/portal' AFTER plan_level,
	ADD COLUMN IF NOT EXISTS rental_start      DATE     NULL COMMENT 'inizio noleggio (Dolibarr), per il filmato finale' AFTER plan_options,
	ADD COLUMN IF NOT EXISTS rental_end        DATE     NULL COMMENT 'fine noleggio prevista (Dolibarr), per il filmato finale' AFTER rental_start,
	ADD COLUMN IF NOT EXISTS weather_params    LONGTEXT NULL COMMENT 'JSON: soglie del maltempo diverse dai predefiniti (servizi-v1.md)' AFTER rental_end,
	ADD COLUMN IF NOT EXISTS timelapse_params  LONGTEXT NULL COMMENT 'JSON: crop_x_pct, crop_y_pct, fps... (servizi-v1.md)' AFTER weather_params,
	ADD CONSTRAINT ck_service_plan_options CHECK (plan_options IS NULL OR JSON_VALID(plan_options)),
	ADD CONSTRAINT ck_service_weather_params CHECK (weather_params IS NULL OR JSON_VALID(weather_params)),
	ADD CONSTRAINT ck_service_timelapse_params CHECK (timelapse_params IS NULL OR JSON_VALID(timelapse_params));

-- Archivio dopo il noleggio (M13): fino a questa data il cliente di
-- un'assegnazione chiusa vede ancora l'archivio (foto del suo periodo,
-- timelapse, rapporti, certificati). Copiata da "archives" di
-- GET webcams/{id}/portal; NULL = regola predefinita (servizi-v1.md).
ALTER TABLE customer_webcams
	ADD COLUMN IF NOT EXISTS archive_until DATE NULL COMMENT 'giorno locale incluso; NULL = archive.default_days dalla fine' AFTER valid_to,
	ADD COLUMN IF NOT EXISTS archive_source ENUM('dolibarr','portal') NULL AFTER archive_until,
	ADD KEY IF NOT EXISTS idx_custweb_archive (customer_id, archive_until);

-- Conteggi dei log in più per il rapporto mensile (servizi-v1.md,
-- "Conteggi del logserver"). Giorno UTC, come le colonne esistenti.
ALTER TABLE log_counters
	ADD COLUMN IF NOT EXISTS uploads_ok        SMALLINT UNSIGNED NULL COMMENT 'righe "OK foto caricata" (solo destinazione FTP)' AFTER upload_failures,
	ADD COLUMN IF NOT EXISTS ntp_issues        SMALLINT UNSIGNED NULL COMMENT 'righe "ora non sincronizzata" / "ora del router non valida"' AFTER uploads_ok,
	ADD COLUMN IF NOT EXISTS sensor_errors_log SMALLINT UNSIGNED NULL COMMENT 'righe "sensore ... non risponde"' AFTER ntp_issues,
	ADD COLUMN IF NOT EXISTS log_lines         INT UNSIGNED NULL COMMENT 'righe totali del giorno' AFTER sensor_errors_log,
	ADD COLUMN IF NOT EXISTS log_hours         TINYINT UNSIGNED NULL COMMENT 'ore UTC con almeno una riga (0-24)' AFTER log_lines;

-- ---------------------------------------------------------------------------
-- Sovrimpressione (M2). Una riga per webcam; senza riga = sovrimpressione
-- spenta. overlay_rev cresce a ogni modifica (anche di sponsor e loghi):
-- entra nel nome delle immagini in cache e nell'ETag.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS overlay_assets (
	id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
	customer_id   INT UNSIGNED NULL COMMENT 'NULL = immagine di Viewlapse',
	kind          ENUM('logo','sponsor') NOT NULL,
	label         VARCHAR(100) NULL,
	storage_path  VARCHAR(255) NOT NULL COMMENT 'storage/overlay/<id>.png, relativo a storage/',
	mime          ENUM('image/png','image/jpeg') NOT NULL,
	width         SMALLINT UNSIGNED NOT NULL,
	height        SMALLINT UNSIGNED NOT NULL,
	size_bytes    INT UNSIGNED NOT NULL,
	sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	created_by    INT UNSIGNED NULL,
	created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	deleted_at    DATETIME NULL,
	PRIMARY KEY (id),
	KEY idx_overlay_assets_customer (customer_id, kind),
	CONSTRAINT fk_overlay_assets_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_overlay_assets_user FOREIGN KEY (created_by) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_overlay_assets_sha CHECK (sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS overlay_config (
	webcam_id       INT UNSIGNED NOT NULL,
	enabled         TINYINT(1)   NOT NULL DEFAULT 0,
	overlay_rev     INT UNSIGNED NOT NULL DEFAULT 1,
	fields          SET('datetime','temperature_ext','humidity_ext','dewpoint_ext','name','location','altitude','text')
	                NOT NULL DEFAULT 'datetime,temperature_ext,humidity_ext,name',
	custom_text     VARCHAR(120) NULL COMMENT 'campo "text": testo fisso del cliente',
	bar_position    ENUM('top','bottom') NOT NULL DEFAULT 'bottom',
	text_align      ENUM('left','center','right') NOT NULL DEFAULT 'left',
	bar_opacity_pct TINYINT UNSIGNED NOT NULL DEFAULT 55 COMMENT 'sfondo nero della fascia, 0-90',
	font_scale_pct  SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT '50-200, rispetto all altezza predefinita',
	locale          ENUM('it','en','de','fr') NOT NULL DEFAULT 'it',
	logo_asset_id   INT UNSIGNED NULL,
	logo_position   ENUM('tl','tr','bl','br') NOT NULL DEFAULT 'tr',
	logo_width_pct  TINYINT UNSIGNED NOT NULL DEFAULT 12 COMMENT 'larghezza del logo in % della larghezza dell immagine, 3-30',
	logo_opacity_pct TINYINT UNSIGNED NOT NULL DEFAULT 100,
	apply_to        SET('640','1280','1920','current','social','timelapse') NOT NULL DEFAULT '640,1280,1920,current,social,timelapse',
	current_size    ENUM('1280','1920','orig') NOT NULL DEFAULT '1920'
	                COMMENT 'dimensione di current.jpg con sovrimpressione; orig = nessuna sovrimpressione su current.jpg',
	user_id         INT UNSIGNED NULL,
	updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id),
	CONSTRAINT fk_overlay_config_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_overlay_config_logo FOREIGN KEY (logo_asset_id) REFERENCES overlay_assets (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_overlay_config_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_overlay_config_ranges CHECK (bar_opacity_pct <= 90 AND font_scale_pct BETWEEN 50 AND 200
		AND logo_width_pct BETWEEN 3 AND 30 AND logo_opacity_pct BETWEEN 10 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sponsor e infopoint (M12). valid_from/valid_to: giorni locali della
-- webcam, inclusi; NULL = senza limite. Uno sponsor si vede solo se è attivo,
-- nel periodo, e se la webcam ha la funzione "sponsor" (listino pro o
-- opzione) nel periodo del pacchetto di Dolibarr.
--   sponsor:   logo (asset) e/o testo nella fascia della sovrimpressione
--              (burn = 1) e nell'API per il visualizzatore del plugin;
--   infopoint: etichetta su un punto dell'immagine (x_pct, y_pct, 0-100
--              dall'angolo in alto a sinistra), cliccabile nel plugin;
--              si stampa sull'immagine solo con burn = 1.
CREATE TABLE IF NOT EXISTS overlay_items (
	id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id     INT UNSIGNED NOT NULL,
	kind          ENUM('sponsor','infopoint') NOT NULL,
	label         VARCHAR(80)  NOT NULL COMMENT 'nome breve, es. Rifugio Alpe',
	text          VARCHAR(255) NULL COMMENT 'descrizione (infopoint) o slogan (sponsor)',
	url           VARCHAR(255) NULL COMMENT 'https://..., link nel visualizzatore',
	asset_id      INT UNSIGNED NULL COMMENT 'logo dello sponsor o icona dell infopoint',
	x_pct         DECIMAL(5,2) NULL COMMENT 'solo infopoint',
	y_pct         DECIMAL(5,2) NULL COMMENT 'solo infopoint',
	burn          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = stampato sulle immagini con sovrimpressione',
	sort_order    SMALLINT     NOT NULL DEFAULT 0,
	valid_from    DATE         NULL,
	valid_to      DATE         NULL,
	active        TINYINT(1)   NOT NULL DEFAULT 1,
	created_by    INT UNSIGNED NULL,
	created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_overlay_items_webcam (webcam_id, kind, active, valid_from),
	CONSTRAINT fk_overlay_items_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_overlay_items_asset FOREIGN KEY (asset_id) REFERENCES overlay_assets (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_overlay_items_user FOREIGN KEY (created_by) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_overlay_items_range CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from),
	CONSTRAINT ck_overlay_items_point CHECK (kind = 'sponsor'
		OR (x_pct IS NOT NULL AND y_pct IS NOT NULL AND x_pct BETWEEN 0 AND 100 AND y_pct BETWEEN 0 AND 100)),
	CONSTRAINT ck_overlay_items_url CHECK (url IS NULL OR url REGEXP '^https?://')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Punteggio delle foto (O8, servizi-v1.md "Foto buone"). Separato da
-- photo_metrics (diagnosi): scriverlo non fa scattare regole. Una riga per
-- foto valutata; good = 1 se passa tutti i criteri, reject_reason il primo
-- criterio non superato. source = metrics se ha usato photo_metrics della
-- stessa foto, own se il portal ha analizzato la foto per il punteggio,
-- inherited se ha preso le metriche della foto analizzata più vicina.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_scores (
	photo_id        BIGINT UNSIGNED NOT NULL,
	webcam_id       INT UNSIGNED NOT NULL,
	taken_at        DATETIME     NOT NULL,
	day             DATE         NOT NULL COMMENT 'giorno locale della webcam',
	good            TINYINT(1)   NOT NULL,
	reject_reason   VARCHAR(24)  CHARACTER SET ascii COLLATE ascii_general_ci NULL
	                COMMENT 'night, dark, overexposed, blurry, fog, fog_ext, dirty, moved, alert, humid, flicker, hidden',
	score           DECIMAL(5,1) NULL COMMENT '0-100, solo per le foto buone',
	brightness      DECIMAL(5,1) NULL,
	contrast        DECIMAL(5,1) NULL,
	sharpness_tiles DECIMAL(10,2) NULL,
	blur_ratio      DECIMAL(5,3) NULL,
	rh_ext          DECIMAL(4,1) NULL,
	spread_ext      DECIMAL(4,1) NULL COMMENT 't_ext - dp_ext',
	source          ENUM('metrics','own','inherited') NOT NULL,
	inherited_from  BIGINT UNSIGNED NULL COMMENT 'photo_id della foto da cui ha preso le metriche',
	params_rev      SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'versione dei criteri: cambiandoli si ricalcola',
	computed_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (photo_id),
	KEY idx_scores_webcam_day (webcam_id, day, good, score),
	CONSTRAINT fk_scores_photo FOREIGN KEY (photo_id) REFERENCES photos (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_scores_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Timelapse (M7, O8, M10). Una riga = un video. I lavori automatici (day,
-- week, month) sono unici per webcam, periodo, formato e selezione; una
-- richiesta uguale a un video esistente restituisce quello (o lo rimette in
-- coda se è failed). period_from/period_to: giorni locali, inclusi.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS timelapses (
	id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id         INT UNSIGNED NOT NULL,
	customer_id       INT UNSIGNED NULL COMMENT 'cliente dell assegnazione nel periodo (visibilità)',
	kind              ENUM('day','week','month','custom','final') NOT NULL,
	period_from       DATE         NOT NULL,
	period_to         DATE         NOT NULL,
	format            ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape',
	width             SMALLINT UNSIGNED NOT NULL COMMENT '1920x1080, 1280x720, 1080x1920',
	height            SMALLINT UNSIGNED NOT NULL,
	selection         ENUM('all','good','one_per_day') NOT NULL DEFAULT 'good',
	same_light_time   CHAR(5)      CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HH:MM locale, solo one_per_day',
	fps               TINYINT UNSIGNED NOT NULL,
	overlay           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'sovrimpressione sui fotogrammi (se attiva e in apply_to)',
	crop_x_pct        TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'centro del ritaglio orizzontale, 0-100',
	crop_y_pct        TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'centro del ritaglio verticale, 0-100',
	params            LONGTEXT     NULL COMMENT 'JSON: criteri O8 e parametri usati, per rifarlo uguale',
	status            ENUM('queued','running','done','failed','skipped','cancelled','expired') NOT NULL DEFAULT 'queued',
	status_reason     VARCHAR(64)  NULL COMMENT 'ffmpeg_missing, too_few_frames, no_photos, timeout, ffmpeg_error, memory...',
	priority          TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1 = prima (richieste a mano), 9 = ultima',
	attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
	frames_candidates INT UNSIGNED NULL,
	frames_used       INT UNSIGNED NULL,
	frames_rejected   LONGTEXT     NULL COMMENT 'JSON: conteggi per reject_reason',
	frames            LONGTEXT     NULL COMMENT 'JSON: elenco dei photo_id usati, in ordine',
	duration_s        DECIMAL(6,2) NULL,
	storage_path      VARCHAR(255) NULL COMMENT 'storage/timelapse/<webcam>/<id>.mp4',
	poster_path       VARCHAR(255) NULL COMMENT 'storage/timelapse/<webcam>/<id>.jpg',
	size_bytes        BIGINT UNSIGNED NULL,
	sha256            CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NULL,
	requested_by      ENUM('auto','operator','customer','rental_end') NOT NULL DEFAULT 'auto',
	user_id           INT UNSIGNED NULL,
	created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	started_at        DATETIME     NULL,
	finished_at       DATETIME     NULL,
	expires_at        DATETIME     NULL COMMENT 'il file si cancella dopo (retention); NULL = finché c è archivio',
	PRIMARY KEY (id),
	UNIQUE KEY uk_timelapses_period (webcam_id, kind, period_from, period_to, format, selection),
	KEY idx_timelapses_queue (status, priority, created_at),
	KEY idx_timelapses_list (webcam_id, status, kind, period_from),
	KEY idx_timelapses_expires (expires_at),
	CONSTRAINT fk_timelapses_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_timelapses_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_timelapses_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_timelapses_range CHECK (period_to >= period_from),
	CONSTRAINT ck_timelapses_crop CHECK (crop_x_pct <= 100 AND crop_y_pct <= 100),
	CONSTRAINT ck_timelapses_fps CHECK (fps BETWEEN 1 AND 60),
	CONSTRAINT ck_timelapses_light CHECK (same_light_time IS NULL OR same_light_time REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
	CONSTRAINT ck_timelapses_params CHECK (params IS NULL OR JSON_VALID(params)),
	CONSTRAINT ck_timelapses_rejected CHECK (frames_rejected IS NULL OR JSON_VALID(frames_rejected)),
	CONSTRAINT ck_timelapses_frames CHECK (frames IS NULL OR JSON_VALID(frames)),
	CONSTRAINT ck_timelapses_sha CHECK (sha256 IS NULL OR sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Foto del giorno (M11). Una per webcam e giorno locale.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_of_day (
	webcam_id         INT UNSIGNED NOT NULL,
	day               DATE         NOT NULL COMMENT 'giorno locale',
	photo_id          BIGINT UNSIGNED NOT NULL,
	score             DECIMAL(5,1) NULL,
	chosen_by         ENUM('auto','operator','customer') NOT NULL DEFAULT 'auto',
	user_id           INT UNSIGNED NULL,
	chosen_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	telegram_sent_at  DATETIME     NULL COMMENT 'mandata alle chat iscritte',
	PRIMARY KEY (webcam_id, day),
	KEY idx_pod_photo (photo_id),
	CONSTRAINT fk_pod_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_pod_photo FOREIGN KEY (photo_id) REFERENCES photos (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_pod_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Rapporti mensili (O3). Uno per webcam, cliente e mese (una webcam che
-- cambia cliente a metà mese ha due rapporti, ognuno sul suo periodo).
-- Stati: pending (da fare) -> generating -> ready (PDF pronto) ->
-- sent (allegato in Dolibarr) -> emailed (Dolibarr ha mandato l'email);
-- failed/skipped con status_reason.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monthly_reports (
	id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id           INT UNSIGNED NOT NULL,
	customer_id         INT UNSIGNED NOT NULL,
	month               CHAR(7)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'AAAA-MM, fuso della webcam',
	period_from         DATE         NOT NULL COMMENT 'primo giorno coperto (inizio assegnazione o 1 del mese)',
	period_to           DATE         NOT NULL,
	status              ENUM('pending','generating','ready','sent','emailed','failed','skipped') NOT NULL DEFAULT 'pending',
	status_reason       VARCHAR(64)  NULL COMMENT 'no_dolibarr, no_email, plan, no_photos, pdf_error, dolibarr_error...',
	attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
	data                LONGTEXT     NULL COMMENT 'JSON: tutti i numeri del rapporto (servizi-v1.md)',
	storage_path        VARCHAR(255) NULL COMMENT 'storage/reports/<webcam>/<AAAA-MM>-<customer>.pdf',
	size_bytes          INT UNSIGNED NULL,
	pages               SMALLINT UNSIGNED NULL,
	sha256              CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NULL,
	generated_at        DATETIME     NULL,
	dolibarr_file       VARCHAR(255) NULL COMMENT 'risposta "file" di POST webcams/{id}/documents',
	dolibarr_sent_at    DATETIME     NULL,
	email_requested     TINYINT(1)   NOT NULL DEFAULT 0,
	emailed_at          DATETIME     NULL,
	email_to            VARCHAR(255) NULL COMMENT 'destinatari riportati da Dolibarr',
	dolibarr_actioncomm_id INT UNSIGNED NULL,
	user_id             INT UNSIGNED NULL COMMENT 'operatore che l ha rigenerato o mandato a mano',
	created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uk_reports_month (webcam_id, customer_id, month),
	KEY idx_reports_status (status, month),
	KEY idx_reports_customer (customer_id, month),
	CONSTRAINT fk_reports_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_reports_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_reports_month CHECK (month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
	CONSTRAINT ck_reports_range CHECK (period_to >= period_from),
	CONSTRAINT ck_reports_data CHECK (data IS NULL OR JSON_VALID(data)),
	CONSTRAINT ck_reports_sha CHECK (sha256 IS NULL OR sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Registro giornaliero (O2): impronte SHA-256 delle foto del giorno e
-- classificazione meteo. Una riga per webcam e giorno locale, per tutte le
-- webcam in servizio (anche senza l'opzione maltempo: il registro è la
-- prova, il certificato è il servizio). Il giorno si "sigilla" alle 01:30
-- del giorno dopo (fuso della webcam), le foto arrivate dopo dalla coda
-- offline vanno nel registro come tardive (late_photos) e non cambiano
-- day_hash. Schema dell'elenco e dell'hash: servizi-v1.md, "Registro".
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_register (
	webcam_id          INT UNSIGNED NOT NULL,
	day                DATE         NOT NULL COMMENT 'giorno locale della webcam',
	timezone           VARCHAR(64)  NOT NULL COMMENT 'fuso usato (webcams.timezone al sigillo)',
	customer_id        INT UNSIGNED NULL COMMENT 'cliente dell assegnazione quel giorno',
	photos_total       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'foto nell elenco (time_synced = 1)',
	photos_unsynced    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'foto del giorno con time_synced = 0, escluse',
	late_photos        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ricevute dopo il sigillo, fuori da day_hash',
	first_photo_at     DATETIME     NULL,
	last_photo_at      DATETIME     NULL,
	hash_scheme        VARCHAR(16)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'vlreg1',
	manifest_path      VARCHAR(255) NULL COMMENT 'storage/register/<webcam>/AAAA/MM/AAAA-MM-GG.txt',
	day_hash           CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'sha256 del file elenco; NULL senza foto',
	prev_day_hash      CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'day_hash del giorno prima (catena)',
	-- dati meteo dai campioni (solo time_synced = 1), nelle ore di lavoro
	work_hours         CHAR(11)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'HH:MM-HH:MM usate',
	samples_expected   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	samples_ok         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'con sensore esterno valido e ora sincronizzata',
	samples_unsynced   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	hours_with_photo   TINYINT UNSIGNED NOT NULL DEFAULT 0,
	t_ext_min          DECIMAL(4,1) NULL,
	t_ext_max          DECIMAL(4,1) NULL,
	t_ext_avg          DECIMAL(4,1) NULL,
	rh_ext_avg         DECIMAL(4,1) NULL,
	rh_ext_max         DECIMAL(4,1) NULL,
	spread_ext_min     DECIMAL(4,1) NULL COMMENT 'minimo di t_ext - dp_ext',
	wet_hours          TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ore di lavoro umide (servizi-v1.md)',
	frost_hours        TINYINT UNSIGNED NOT NULL DEFAULT 0,
	dark_hours         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ore con foto molto più scure del riferimento',
	fog_hours          TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ore con foto classificate fog_ext',
	classification     ENUM('ok','bad_weather','not_certifiable','no_data') NULL COMMENT 'NULL = giorno non ancora sigillato',
	reasons            SET('wet','frost','fog','dark') NULL,
	not_cert_reason    VARCHAR(32)  NULL COMMENT 'time_unsynced, sensor_missing, few_samples, few_photos',
	criteria           LONGTEXT     NULL COMMENT 'JSON: soglie usate (predefiniti + weather_params)',
	criteria_rev       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
	sealed_at          DATETIME     NULL,
	dolibarr_synced_at DATETIME     NULL COMMENT 'POST webcams/{id}/register accettato',
	dolibarr_registered_at DATETIME NULL COMMENT 'ora di registrazione restituita da Dolibarr',
	computed_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id, day),
	KEY idx_register_day (day),
	KEY idx_register_pending (dolibarr_synced_at, sealed_at),
	KEY idx_register_class (webcam_id, classification, day),
	CONSTRAINT fk_register_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_register_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_register_hash CHECK (day_hash IS NULL OR day_hash REGEXP '^[0-9a-f]{64}$'),
	CONSTRAINT ck_register_prev CHECK (prev_day_hash IS NULL OR prev_day_hash REGEXP '^[0-9a-f]{64}$'),
	CONSTRAINT ck_register_criteria CHECK (criteria IS NULL OR JSON_VALID(criteria)),
	CONSTRAINT ck_register_sealed CHECK ((sealed_at IS NULL AND classification IS NULL) OR (sealed_at IS NOT NULL AND classification IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certificati delle giornate di maltempo (PDF). Di solito uno al mese per
-- webcam e cliente (kind = month, creato con il rapporto mensile); a
-- richiesta dell'operatore per un periodo qualsiasi (kind = custom).
CREATE TABLE IF NOT EXISTS weather_certificates (
	id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id           INT UNSIGNED NOT NULL,
	customer_id         INT UNSIGNED NOT NULL,
	kind                ENUM('month','custom') NOT NULL DEFAULT 'month',
	period_from         DATE         NOT NULL,
	period_to           DATE         NOT NULL,
	status              ENUM('pending','generating','ready','sent','emailed','failed','skipped') NOT NULL DEFAULT 'pending',
	status_reason       VARCHAR(64)  NULL,
	attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
	days_total          SMALLINT UNSIGNED NULL,
	days_bad            SMALLINT UNSIGNED NULL,
	days_not_certifiable SMALLINT UNSIGNED NULL,
	data                LONGTEXT     NULL COMMENT 'JSON: giorni, classificazioni, day_hash, soglie',
	storage_path        VARCHAR(255) NULL COMMENT 'storage/certificates/<webcam>/<id>.pdf',
	size_bytes          INT UNSIGNED NULL,
	pages               SMALLINT UNSIGNED NULL,
	sha256              CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NULL,
	generated_at        DATETIME     NULL,
	dolibarr_file       VARCHAR(255) NULL,
	dolibarr_sent_at    DATETIME     NULL,
	email_requested     TINYINT(1)   NOT NULL DEFAULT 0,
	emailed_at          DATETIME     NULL,
	email_to            VARCHAR(255) NULL,
	user_id             INT UNSIGNED NULL,
	created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uk_certificates_period (webcam_id, customer_id, kind, period_from, period_to),
	KEY idx_certificates_status (status),
	CONSTRAINT fk_certificates_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_certificates_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_certificates_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_certificates_range CHECK (period_to >= period_from),
	CONSTRAINT ck_certificates_data CHECK (data IS NULL OR JSON_VALID(data)),
	CONSTRAINT ck_certificates_sha CHECK (sha256 IS NULL OR sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Statistiche di visualizzazione (M8). Solo contatori aggregati: nessun IP,
-- nessun cookie. day = giorno locale della webcam. site_key_id = 0 senza
-- chiave (niente FOREIGN KEY: 0 non è una chiave). source:
--   site      beacon dei plugin WordPress (visualizzazione di una pagina)
--   image     richieste di /cam/{slug}/current.jpg (Windy, <img> esterni), 200 e 304
--   api       richieste dell'API dei siti per la webcam (dal server dei plugin)
--   telegram  /foto e /meteo del bot
--   portal    pagine del pannello clienti del portal
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS view_stats_daily (
	webcam_id        INT UNSIGNED NOT NULL,
	day              DATE         NOT NULL,
	source           ENUM('site','image','api','telegram','portal') NOT NULL,
	site_key_id      INT UNSIGNED NOT NULL DEFAULT 0,
	views            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'visualizzazioni (beacon view, richieste)',
	visitors         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'visitatori distinti nel giorno (stima, solo site e portal)',
	view_seconds     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'somma dei secondi di visione (beacon leave)',
	timelapse_plays  INT UNSIGNED NOT NULL DEFAULT 0,
	photo_opens      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'foto dell archivio aperte a schermo intero',
	updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id, day, source, site_key_id),
	KEY idx_viewstats_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provenienza: solo il nome dell'host della pagina (mai il percorso), per mese.
CREATE TABLE IF NOT EXISTS view_stats_hosts (
	webcam_id   INT UNSIGNED NOT NULL,
	month       CHAR(7)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	host        VARCHAR(190) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'es. www.hotel.it; "-" = sconosciuto',
	views       INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (webcam_id, month, host),
	CONSTRAINT ck_viewhosts_month CHECK (month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visitatori del giorno, solo per contare i distinti e togliere i doppi
-- entro 30 minuti. visitor = primi 16 byte di HMAC-SHA256(segreto del
-- giorno, IP troncato | User-Agent | webcam_id); il segreto del giorno si
-- cancella dopo 48 ore e con lui la possibilità di ricollegare i valori.
-- Le righe più vecchie di 2 giorni si cancellano (bin/stats.php).
CREATE TABLE IF NOT EXISTS view_visitors (
	day          DATE         NOT NULL,
	webcam_id    INT UNSIGNED NOT NULL,
	visitor      BINARY(16)   NOT NULL,
	last_view_at DATETIME     NOT NULL,
	PRIMARY KEY (day, webcam_id, visitor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Segreti giornalieri per view_visitors (mai esportati, cancellati dopo 48 h).
CREATE TABLE IF NOT EXISTS view_salts (
	day          DATE         NOT NULL,
	salt         BINARY(32)   NOT NULL,
	created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Bot Telegram (O10). Un solo bot per il portal (token in config).
-- Codici di abbinamento: creati dal pannello per un cliente (tutte le sue
-- webcam o una sola), monouso, solo lo SHA-256 nel database.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telegram_pair_codes (
	id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
	customer_id  INT UNSIGNED NOT NULL,
	webcam_id    INT UNSIGNED NULL COMMENT 'NULL = tutte le webcam attuali del cliente',
	code_hash    CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hex(sha256(codice normalizzato))',
	label        VARCHAR(100) NULL COMMENT 'per chi è, es. "geom. Bianchi"',
	created_by   INT UNSIGNED NULL,
	created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	expires_at   DATETIME     NOT NULL,
	used_at      DATETIME     NULL,
	used_chat    INT UNSIGNED NULL COMMENT 'telegram_chats.id',
	revoked_at   DATETIME     NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_tgcodes_hash (code_hash),
	KEY idx_tgcodes_customer (customer_id),
	CONSTRAINT fk_tgcodes_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_tgcodes_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_tgcodes_user FOREIGN KEY (created_by) REFERENCES users (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una riga per chat e cliente abbinati. status stopped: /stop o bot
-- bloccato dall'utente (403 di Telegram); i dati personali (nome, username)
-- si cancellano subito, la riga dopo telegram.forget_days.
CREATE TABLE IF NOT EXISTS telegram_chats (
	id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
	chat_id          BIGINT       NOT NULL COMMENT 'id della chat Telegram (negativo per i gruppi)',
	chat_type        ENUM('private','group','supergroup','channel') NOT NULL,
	customer_id      INT UNSIGNED NOT NULL,
	webcam_id        INT UNSIGNED NULL COMMENT 'NULL = tutte le webcam attuali del cliente (dal codice)',
	display_name     VARCHAR(128) NULL COMMENT 'nome o titolo del gruppo, per il pannello',
	username         VARCHAR(64)  NULL,
	language         CHAR(2)      CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'it',
	status           ENUM('active','stopped','blocked') NOT NULL DEFAULT 'active',
	active_flag      TINYINT AS (IF(status = 'active', 1, NULL)) PERSISTENT,
	pair_code_id     INT UNSIGNED NULL,
	paired_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	stopped_at       DATETIME     NULL,
	last_command_at  DATETIME     NULL,
	messages_today   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'messaggi mandati dal bot oggi (limite giornaliero)',
	messages_day     DATE         NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_tgchats_active (chat_id, customer_id, active_flag),
	KEY idx_tgchats_customer (customer_id, status),
	CONSTRAINT fk_tgchats_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_tgchats_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_tgchats_code FOREIGN KEY (pair_code_id) REFERENCES telegram_pair_codes (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE telegram_pair_codes
	ADD CONSTRAINT fk_tgcodes_chat FOREIGN KEY IF NOT EXISTS (used_chat) REFERENCES telegram_chats (id)
		ON DELETE SET NULL;

-- Avvisi scelti per chat e webcam (servizi-v1.md, "Bot Telegram").
CREATE TABLE IF NOT EXISTS telegram_subscriptions (
	chat_ref     INT UNSIGNED NOT NULL COMMENT 'telegram_chats.id',
	webcam_id    INT UNSIGNED NOT NULL,
	alerts       SET('offline','online','photo_of_day','frost','bad_weather','timelapse','report')
	             NOT NULL DEFAULT 'offline,online,photo_of_day',
	updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (chat_ref, webcam_id),
	KEY idx_tgsubs_webcam (webcam_id),
	CONSTRAINT fk_tgsubs_chat FOREIGN KEY (chat_ref) REFERENCES telegram_chats (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_tgsubs_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messaggi in uscita (coda e registro). dedupe_key evita di mandare due
-- volte lo stesso avviso alla stessa chat (es. "offline:alert:4411").
-- Righe più vecchie di 90 giorni cancellate.
CREATE TABLE IF NOT EXISTS telegram_messages (
	id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	chat_ref             INT UNSIGNED NOT NULL,
	webcam_id            INT UNSIGNED NULL,
	kind                 ENUM('reply','alert','photo_of_day','system') NOT NULL,
	alert_type           VARCHAR(16) CHARACTER SET ascii COLLATE ascii_general_ci NULL COMMENT 'come telegram_subscriptions.alerts',
	dedupe_key           VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
	text                 VARCHAR(1024) NULL,
	photo_id             BIGINT UNSIGNED NULL,
	status               ENUM('queued','sent','failed','dropped') NOT NULL DEFAULT 'queued',
	status_reason        VARCHAR(64) NULL COMMENT 'daily_limit, blocked, telegram_4xx, ...',
	attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
	telegram_message_id  BIGINT NULL,
	created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	sent_at              DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_tgmsg_dedupe (chat_ref, dedupe_key),
	KEY idx_tgmsg_queue (status, created_at),
	CONSTRAINT fk_tgmsg_chat FOREIGN KEY (chat_ref) REFERENCES telegram_chats (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_tgmsg_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_tgmsg_photo FOREIGN KEY (photo_id) REFERENCES photos (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativi di abbinamento sbagliati (limite contro chi prova i codici).
CREATE TABLE IF NOT EXISTS telegram_pair_failures (
	chat_id      BIGINT       NOT NULL,
	hour_ts      DATETIME     NOT NULL COMMENT 'inizio dell ora UTC',
	failures     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (chat_id, hour_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, note) VALUES ('4', 'contratto 1.3: servizi (sovrimpressione, timelapse, rapporti, maltempo, statistiche, Telegram)');
