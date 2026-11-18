-- ---------------------------------------------------------------------------
-- webcam-portal: migrazione 002 (contratto 1.1, fase 2: assistenza automatica)
--
-- Si esegue dopo schema-mariadb.sql (migrazione 1). Aggiunge le tabelle delle
-- regole di diagnosi (diagnosi-v1.md), dei comandi al router
-- (api-portal-v1.md, "Comandi al router"), dell'analisi delle foto e della
-- disponibilità mensile. Non cambia il significato di nessuna colonna
-- esistente: aggiunge solo colonne facoltative.
--
-- Stesse regole di compatibilità della migrazione 1: MariaDB 10.4+ e 11.8,
-- collation utf8mb4_unicode_ci, DATETIME in UTC, JSON come LONGTEXT con
-- CHECK (JSON_VALID(...)). Le colonne generate usano PERSISTENT (sinonimo
-- di STORED, accettato da 10.2 in poi) per poterle indicizzare.
--
-- "Un solo avviso aperto per webcam e regola" e "una sola foto di
-- riferimento valida per fascia" si ottengono con una colonna generata che
-- vale 1 per la riga attiva e NULL per le altre, dentro un indice UNIQUE:
-- MariaDB ammette più NULL in un indice unico.
--
-- Esecuzione: mysql -u webcam2 -p webcam2 < schema-mariadb-002.sql
-- (il portal la applica da sé come migrations/002_*.sql)
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Colonne nuove su tabelle esistenti
-- ---------------------------------------------------------------------------

-- Telemetria: campi nuovi di telemetry/1 usati dalle regole (telemetria-v1.md,
-- "Campi per la diagnosi"). Su una tabella partizionata ADD COLUMN in fondo
-- è istantaneo da MariaDB 10.3.
ALTER TABLE measurements
	ADD COLUMN IF NOT EXISTS heater_on_since  DATETIME     NULL COMMENT 'climate.heater_on_since, UTC',
	ADD COLUMN IF NOT EXISTS heater_duty_pct  TINYINT UNSIGNED NULL COMMENT 'climate.heater_duty_pct, ultima ora',
	ADD COLUMN IF NOT EXISTS heat_reason      VARCHAR(12)  CHARACTER SET ascii COLLATE ascii_general_ci NULL
	                                          COMMENT 'dewpoint, min_temp, boost, capture, off',
	ADD COLUMN IF NOT EXISTS heater_boost_until DATETIME   NULL COMMENT 'fine del boost comandato dal portal',
	ADD COLUMN IF NOT EXISTS dewpoint_margin  DECIMAL(3,1) NULL COMMENT 'heat_dewpoint_margin del router',
	ADD COLUMN IF NOT EXISTS fan_rpm          SMALLINT UNSIGNED NULL COMMENT 'solo con tachimetro sulla scheda',
	ADD COLUMN IF NOT EXISTS cam_scheduled    TINYINT(1)   NULL COMMENT 'camera.scheduled: ora di scatto prevista',
	ADD COLUMN IF NOT EXISTS cam_interval_min SMALLINT UNSIGNED NULL COMMENT 'camera.interval_min in vigore';

ALTER TABLE measurements_hourly
	ADD COLUMN IF NOT EXISTS ah_in_avg        DECIMAL(5,2) NULL AFTER rh_in_max,
	ADD COLUMN IF NOT EXISTS dp_in_avg        DECIMAL(4,1) NULL AFTER ah_in_avg,
	ADD COLUMN IF NOT EXISTS maintenance_min  TINYINT UNSIGNED NULL COMMENT 'minuti in manutenzione (campioni x 5)' AFTER fan_min;

-- Eventi collegati a un avviso (alert.open, alert.update, alert.close,
-- command.ack).
ALTER TABLE events
	ADD COLUMN IF NOT EXISTS alert_id BIGINT UNSIGNED NULL AFTER data,
	ADD KEY IF NOT EXISTS idx_events_alert (alert_id);

-- Token: nuovo scope "commands" (il router riceve comandi). I token
-- esistenti lo ricevono subito; un operatore può toglierlo per una webcam.
ALTER TABLE tokens
	MODIFY scopes SET('photo','telemetry','event','read','commands') NOT NULL DEFAULT 'photo,telemetry,event,commands';
UPDATE tokens SET scopes = CONCAT(scopes, ',commands')
	WHERE FIND_IN_SET('commands', scopes) = 0 AND FIND_IN_SET('photo', scopes) > 0;

-- ---------------------------------------------------------------------------
-- Impostazioni di servizio per webcam: soglie del contratto di noleggio
-- (copiate da Dolibarr, GET webcams/{id}/portal) e soglie della reflex.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webcam_service (
	webcam_id           INT UNSIGNED NOT NULL,
	sla_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
	sla_offline_hours   SMALLINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'N: ore senza foto previste',
	sla_response_days   SMALLINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'X: giorni per intervenire',
	sla_target_pct      DECIMAL(5,2) NOT NULL DEFAULT 98.00,
	sla_hours           CHAR(11)     CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HH:MM-HH:MM ora locale, NULL = 24 ore',
	sla_weekdays        VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'cifre ISO 1-7, NULL = tutti',
	shutter_life        INT UNSIGNED NULL COMMENT 'WEBCAMS_SHUTTER_LIFE di Dolibarr',
	shutter_warn_pct    TINYINT UNSIGNED NULL COMMENT 'WEBCAMS_SHUTTER_WARN_PCT di Dolibarr',
	rttys_terminal_url  VARCHAR(255) NULL,
	rttys_luci_url      VARCHAR(255) NULL,
	photo_metrics       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = niente analisi delle foto',
	source              ENUM('dolibarr','portal') NOT NULL DEFAULT 'portal' COMMENT 'dolibarr = sovrascritto a ogni sincronizzazione',
	synced_at           DATETIME     NULL,
	updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id),
	CONSTRAINT fk_service_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT ck_service_hours CHECK (sla_hours IS NULL OR sla_hours REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-4]):[0-5][0-9]$'),
	CONSTRAINT ck_service_weekdays CHECK (sla_weekdays IS NULL OR sla_weekdays REGEXP '^[1-7]{1,7}$'),
	CONSTRAINT ck_service_target CHECK (sla_target_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Soglie delle regole diverse dai predefiniti, per webcam. I predefiniti
-- stanno nel codice (diagnosi-v1.md) e in config.php ("rules").
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rule_config (
	webcam_id    INT UNSIGNED NOT NULL,
	rule_id      VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	enabled      TINYINT(1)   NOT NULL DEFAULT 1,
	params       LONGTEXT     NULL COMMENT 'JSON: solo le soglie cambiate, stessi nomi di diagnosi-v1.md',
	user_id      INT UNSIGNED NULL,
	updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id, rule_id),
	CONSTRAINT fk_ruleconf_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_ruleconf_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_ruleconf_params CHECK (params IS NULL OR JSON_VALID(params)),
	CONSTRAINT ck_ruleconf_rule CHECK (rule_id REGEXP '^[a-z][a-z0-9_]*\\.[a-z0-9_.]+$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Avvisi: uno per guasto. Al massimo uno aperto per webcam e regola
-- (uk_alerts_open). Un avviso chiuso si riapre (stesso id) se la regola
-- scatta di nuovo entro reopen_within.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
	id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id             INT UNSIGNED NOT NULL,
	rule_id               VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	status                ENUM('open','closed') NOT NULL DEFAULT 'open',
	open_flag             TINYINT AS (IF(status = 'open', 1, NULL)) PERSISTENT,
	severity              ENUM('notice','warning','error','critical') NOT NULL,
	action                ENUM('ticket','agenda','command','panel') NOT NULL,
	title                 VARCHAR(255) NOT NULL,
	details               LONGTEXT     NULL COMMENT 'JSON: valori misurati, soglie, causa',
	opened_at             DATETIME     NOT NULL,
	updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	closed_at             DATETIME     NULL,
	close_reason          ENUM('auto','operator','dolibarr','camera_changed','webcam_retired') NULL,
	reopen_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	open_event_id         BIGINT UNSIGNED NULL COMMENT 'events.id di alert.open: portal_event_id del ticket',
	close_event_id        BIGINT UNSIGNED NULL,
	due_at                DATETIME     NULL COMMENT 'scadenza dell intervento (impegno di disponibilità)',
	sla_breached          TINYINT(1)   NOT NULL DEFAULT 0,
	acknowledged_by       INT UNSIGNED NULL COMMENT 'operatore che ha preso in carico',
	acknowledged_at       DATETIME     NULL,
	dolibarr_ticket_id    INT UNSIGNED NULL,
	dolibarr_ticket_ref   VARCHAR(32)  NULL,
	ticket_status         TINYINT UNSIGNED NULL COMMENT 'fk_statut del Ticket: 0 non letto ... 8 chiuso, 9 annullato',
	ticket_closed_by_operator TINYINT(1) NOT NULL DEFAULT 0,
	dolibarr_synced_at    DATETIME     NULL COMMENT 'NULL = da mandare (apertura, messaggio o chiusura)',
	PRIMARY KEY (id),
	UNIQUE KEY uk_alerts_open (webcam_id, rule_id, open_flag),
	KEY idx_alerts_webcam_time (webcam_id, opened_at),
	KEY idx_alerts_status (status, rule_id),
	KEY idx_alerts_due (due_at),
	KEY idx_alerts_pending (dolibarr_synced_at),
	CONSTRAINT fk_alerts_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_alerts_ack_user FOREIGN KEY (acknowledged_by) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_alerts_details CHECK (details IS NULL OR JSON_VALID(details)),
	CONSTRAINT ck_alerts_closed CHECK ((status = 'open' AND closed_at IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
	ADD CONSTRAINT fk_events_alert FOREIGN KEY IF NOT EXISTS (alert_id) REFERENCES alerts (id)
		ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Stato di ogni regola per webcam (ultima valutazione, contatori per
-- l'isteresi, silenzio dell'operatore).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rule_state (
	webcam_id       INT UNSIGNED NOT NULL,
	rule_id         VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	state           ENUM('ok','pending','alarm','recovering','unknown','disabled') NOT NULL DEFAULT 'unknown'
	                COMMENT 'pending = condizione vera ma non ancora per "for"; recovering = rientrata ma non ancora per "clear_for"',
	state_since     DATETIME     NOT NULL,
	streak          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'valutazioni/finestre/foto consecutive nello stesso esito',
	last_eval_at    DATETIME     NULL,
	last_data_at    DATETIME     NULL COMMENT 'ora del dato più recente usato',
	last_value      LONGTEXT     NULL COMMENT 'JSON: valori dell ultima valutazione',
	alert_id        BIGINT UNSIGNED NULL COMMENT 'avviso aperto o ultimo chiuso (per la riapertura)',
	muted_until     DATETIME     NULL,
	muted_by        INT UNSIGNED NULL,
	PRIMARY KEY (webcam_id, rule_id),
	KEY idx_rulestate_state (rule_id, state),
	CONSTRAINT fk_rulestate_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_rulestate_alert FOREIGN KEY (alert_id) REFERENCES alerts (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_rulestate_user FOREIGN KEY (muted_by) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_rulestate_value CHECK (last_value IS NULL OR JSON_VALID(last_value))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Comandi al router (command/1). Viaggiano nelle risposte di /photo,
-- /telemetry, /event e di GET /api/v1/commands finché il router non manda
-- command.ack o il comando scade.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commands (
	id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'nel JSON: "cmd-<id>"',
	webcam_id       INT UNSIGNED NOT NULL,
	type            VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
	                COMMENT 'heater.boost, heater.cancel, capture.now, telemetry.flush, config.set',
	params          LONGTEXT     NULL COMMENT 'JSON',
	reason          VARCHAR(255) NULL,
	alert_id        BIGINT UNSIGNED NULL,
	created_by      ENUM('rule','operator') NOT NULL,
	user_id         INT UNSIGNED NULL,
	created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	expires_at      DATETIME     NOT NULL,
	status          ENUM('pending','delivered','done','rejected','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
	delivered_at    DATETIME     NULL COMMENT 'prima consegna in una risposta',
	delivery_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	ack_at          DATETIME     NULL,
	ack_event_id    BIGINT UNSIGNED NULL,
	result          LONGTEXT     NULL COMMENT 'JSON: data.result di command.ack',
	PRIMARY KEY (id),
	KEY idx_commands_pending (webcam_id, status, expires_at),
	KEY idx_commands_alert (alert_id),
	CONSTRAINT fk_commands_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_commands_alert FOREIGN KEY (alert_id) REFERENCES alerts (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_commands_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_commands_ack FOREIGN KEY (ack_event_id) REFERENCES events (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_commands_type CHECK (type IN ('heater.boost','heater.cancel','capture.now','telemetry.flush','config.set')),
	CONSTRAINT ck_commands_params CHECK (params IS NULL OR JSON_VALID(params)),
	CONSTRAINT ck_commands_result CHECK (result IS NULL OR JSON_VALID(result))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Foto di riferimento: una valida per webcam e fascia oraria (ora locale).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reference_photos (
	id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id       INT UNSIGNED NOT NULL,
	band            TINYINT UNSIGNED NOT NULL COMMENT 'ora locale della webcam, 0-23',
	photo_id        BIGINT UNSIGNED NULL COMMENT 'NULL se la foto è stata cancellata dalla retention',
	camera_serial   VARCHAR(64)  NULL,
	brightness      DECIMAL(5,1) NOT NULL,
	sharpness       DECIMAL(10,2) NOT NULL COMMENT 'varianza del laplaciano a 640 px',
	sharpness_tiles DECIMAL(10,2) NOT NULL COMMENT 'mediana sui 16 riquadri',
	dhash           BIGINT UNSIGNED NULL,
	gray_path       VARCHAR(255) NOT NULL COMMENT 'PNG in grigi da 640 px, relativo a storage/',
	chosen_by       ENUM('auto','operator') NOT NULL DEFAULT 'auto',
	user_id         INT UNSIGNED NULL,
	valid_from      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	valid_to        DATETIME     NULL COMMENT 'NULL = riferimento in uso',
	current_flag    TINYINT AS (IF(valid_to IS NULL, 1, NULL)) PERSISTENT,
	PRIMARY KEY (id),
	UNIQUE KEY uk_reference_current (webcam_id, band, current_flag),
	KEY idx_reference_photo (photo_id),
	CONSTRAINT fk_reference_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_reference_photo FOREIGN KEY (photo_id) REFERENCES photos (id)
		ON DELETE SET NULL,
	CONSTRAINT fk_reference_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_reference_band CHECK (band <= 23)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Metriche delle foto analizzate (nitidezza, luminosità, spostamento).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_metrics (
	photo_id        BIGINT UNSIGNED NOT NULL,
	webcam_id       INT UNSIGNED NOT NULL,
	taken_at        DATETIME     NOT NULL,
	band            TINYINT UNSIGNED NOT NULL COMMENT 'ora locale, 0-23',
	computed_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	analyzed_width  SMALLINT UNSIGNED NOT NULL DEFAULT 640,
	brightness      DECIMAL(5,1) NULL COMMENT 'media dei grigi 0-255',
	contrast        DECIMAL(5,1) NULL COMMENT 'deviazione standard dei grigi',
	sharpness       DECIMAL(10,2) NULL COMMENT 'varianza del laplaciano',
	sharpness_tiles DECIMAL(10,2) NULL COMMENT 'mediana sui 16 riquadri',
	dhash           BIGINT UNSIGNED NULL,
	reference_id    INT UNSIGNED NULL,
	blur_ratio      DECIMAL(5,3) NULL COMMENT 'sharpness_tiles / riferimento',
	shift_x         SMALLINT     NULL COMMENT 'pixel a 640 px, + = verso destra',
	shift_y         SMALLINT     NULL,
	shift_px        DECIMAL(5,1) AS (IF(shift_x IS NULL OR shift_y IS NULL, NULL, SQRT(shift_x * shift_x + shift_y * shift_y))) PERSISTENT,
	match_score     DECIMAL(4,3) NULL COMMENT 'correlazione normalizzata 0-1',
	rh_in           DECIMAL(4,1) NULL COMMENT 'dal campione più vicino (entro 10 min)',
	spread_in       DECIMAL(4,1) NULL COMMENT 't_in - dp_in',
	rh_ext          DECIMAL(4,1) NULL,
	classification  ENUM('ok','blurry','fog','fog_ext','dirty','moved','skipped') NOT NULL DEFAULT 'ok',
	skip_reason     VARCHAR(32)  NULL COMMENT 'dark, overexposed, night, no_reference, decode_error',
	PRIMARY KEY (photo_id),
	KEY idx_metrics_webcam_time (webcam_id, taken_at),
	CONSTRAINT fk_metrics_photo FOREIGN KEY (photo_id) REFERENCES photos (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_metrics_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_metrics_reference FOREIGN KEY (reference_id) REFERENCES reference_photos (id)
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Conteggi giornalieri dai log (VictoriaLogs) e dagli eventi, per le regole
-- logs.* e per il pannello. day = giorno UTC.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_counters (
	webcam_id          INT UNSIGNED NOT NULL,
	day                DATE         NOT NULL,
	boots_log          SMALLINT UNSIGNED NULL COMMENT 'righe "Linux version"',
	boots_events       SMALLINT UNSIGNED NULL COMMENT 'eventi boot',
	boots_unexpected   SMALLINT UNSIGNED NULL,
	camera_errors_log  SMALLINT UNSIGNED NULL,
	camera_errors_events SMALLINT UNSIGNED NULL,
	capture_retries    SMALLINT UNSIGNED NULL COMMENT '"primo tentativo di scatto fallito" o camera.power_cycle',
	upload_failures    SMALLINT UNSIGNED NULL,
	log_ok             TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = VictoriaLogs non ha risposto',
	updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id, day),
	CONSTRAINT fk_logcount_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Disponibilità (impegno di disponibilità, diagnosi-v1.md).
-- day e month sono nel fuso della webcam (webcams.timezone).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS availability_exclusions (
	id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
	webcam_id    INT UNSIGNED NOT NULL,
	from_at      DATETIME     NOT NULL,
	to_at        DATETIME     NOT NULL,
	reason       VARCHAR(255) NOT NULL,
	user_id      INT UNSIGNED NULL,
	created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_exclusions_webcam (webcam_id, from_at),
	CONSTRAINT fk_exclusions_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_exclusions_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_exclusions_range CHECK (to_at > from_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS availability_daily (
	webcam_id        INT UNSIGNED NOT NULL,
	day              DATE         NOT NULL COMMENT 'giorno locale della webcam',
	expected_h       TINYINT UNSIGNED NOT NULL COMMENT 'ore previste (24 o meno; 23/25 al cambio dell ora legale)',
	excluded_h       TINYINT UNSIGNED NOT NULL DEFAULT 0,
	available_h      TINYINT UNSIGNED NOT NULL DEFAULT 0,
	photos           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'foto con taken_at nelle ore previste',
	pct              DECIMAL(5,2) NULL COMMENT 'available_h / (expected_h - excluded_h) x 100; NULL se 0 ore utili',
	computed_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (webcam_id, day),
	KEY idx_availday_day (day),
	CONSTRAINT fk_availday_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT ck_availday_hours CHECK (excluded_h <= expected_h AND available_h <= expected_h - excluded_h)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS availability_monthly (
	webcam_id          INT UNSIGNED NOT NULL,
	month              CHAR(7)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'AAAA-MM',
	expected_h         SMALLINT UNSIGNED NOT NULL,
	excluded_h         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	available_h        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	pct                DECIMAL(5,2) NULL,
	sla_enabled        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'impegno attivo nel mese',
	target_pct         DECIMAL(5,2) NULL,
	incidents          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'avvisi camera.stalled e telemetry.stalled del mese',
	downtime_h         DECIMAL(7,1) NOT NULL DEFAULT 0 COMMENT 'ore previste non disponibili',
	response_breaches  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'interventi oltre sla_response_days',
	sla_met            TINYINT(1)   NULL COMMENT 'NULL senza impegno',
	final              TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = definitivo (dal 3 del mese dopo)',
	computed_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	dolibarr_synced_at DATETIME     NULL,
	PRIMARY KEY (webcam_id, month),
	CONSTRAINT fk_availmonth_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT ck_availmonth_month CHECK (month REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, note) VALUES ('2', 'contratto 1.1: diagnosi, comandi, disponibilita');
