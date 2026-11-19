-- ---------------------------------------------------------------------------
-- webcam-portal: migrazione 003 (contratto 1.2, fase 3: API per i siti)
--
-- Si esegue dopo schema-mariadb-002.sql (migrazione 2). Aggiunge quello che
-- serve all'API in sola lettura per i siti WordPress (api-siti-v1.md):
--
--   - customers: campi letti dal soggetto terzo di Dolibarr (codice cliente,
--     città) e l'origine del record (portal o dolibarr). La tabella esiste
--     dalla migrazione 1 ed è già collegata al terzo con dolibarr_socid;
--   - customer_webcams: storico delle assegnazioni webcam -> cliente.
--     webcams.customer_id resta il cliente attuale; lo storico dice da
--     quando, così il cliente nuovo di una webcam a noleggio non vede le foto
--     scattate per il cliente di prima;
--   - site_keys: chiavi API dei siti (vls_..., solo lo SHA-256), per
--     cliente, con scope (public / client) e origini ammesse (CORS).
--
-- Stesse regole delle migrazioni 1 e 2: MariaDB 10.4+ e 11.8, collation
-- utf8mb4_unicode_ci, DATETIME in UTC, colonne generate PERSISTENT per
-- "una sola riga aperta" dentro un indice UNIQUE (più NULL ammessi).
--
-- Esecuzione: mysql -u webcam2 -p webcam2 < schema-mariadb-003.sql
-- (il portal la applica da sé come migrations/003_*.sql)
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Clienti: campi in più dal soggetto terzo di Dolibarr
-- ---------------------------------------------------------------------------
ALTER TABLE customers
	ADD COLUMN IF NOT EXISTS code    VARCHAR(64)  NULL COMMENT 'code_client del terzo di Dolibarr' AFTER name,
	ADD COLUMN IF NOT EXISTS town    VARCHAR(128) NULL COMMENT 'città del terzo' AFTER email,
	ADD COLUMN IF NOT EXISTS source  ENUM('portal','dolibarr') NOT NULL DEFAULT 'portal'
	                                 COMMENT 'portal = creato nel pannello, dolibarr = importato da sync-dolibarr' AFTER active,
	ADD COLUMN IF NOT EXISTS note    VARCHAR(255) NULL AFTER source;

-- ---------------------------------------------------------------------------
-- Storico delle assegnazioni delle webcam ai clienti. Una sola riga aperta
-- (valid_to NULL) per webcam, uguale a webcams.customer_id.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_webcams (
	id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
	customer_id   INT UNSIGNED NOT NULL,
	webcam_id     INT UNSIGNED NOT NULL,
	valid_from    DATETIME     NOT NULL COMMENT 'UTC: foto e misure visibili al cliente da qui',
	valid_to      DATETIME     NULL COMMENT 'UTC, NULL = assegnazione in corso',
	open_flag     TINYINT AS (IF(valid_to IS NULL, 1, NULL)) PERSISTENT,
	source        ENUM('portal','dolibarr') NOT NULL DEFAULT 'portal',
	user_id       INT UNSIGNED NULL COMMENT 'operatore che l ha cambiata dal pannello',
	created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uk_custweb_open (webcam_id, open_flag),
	KEY idx_custweb_customer (customer_id, webcam_id, valid_from),
	CONSTRAINT fk_custweb_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_custweb_webcam FOREIGN KEY (webcam_id) REFERENCES webcams (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_custweb_user FOREIGN KEY (user_id) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_custweb_range CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- webcam già assegnate prima della migrazione: visibili dalla creazione
INSERT INTO customer_webcams (customer_id, webcam_id, valid_from, source)
SELECT w.customer_id, w.id, w.created_at, 'portal' FROM webcams w
 WHERE w.customer_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM customer_webcams c WHERE c.webcam_id = w.id AND c.valid_to IS NULL);

-- ---------------------------------------------------------------------------
-- Chiavi API dei siti (api-siti-v1.md). vls_ + 32 byte casuali in base64url
-- (47 caratteri); nel database solo hex(sha256(chiave)).
--   scope public: solo le webcam pubbliche (del cliente, o tutte se
--                 customer_id è NULL), immagini fino a 1920 px;
--   scope client: tutte le webcam del cliente, anche private, e l'originale.
-- origins: origini ammesse per le richieste dal browser (CORS), una per riga,
-- es. https://www.hotel.it o https://*.comune.it; vuoto = solo da server.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_keys (
	id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
	customer_id         INT UNSIGNED NULL COMMENT 'NULL solo con scope public: tutte le webcam pubbliche',
	key_hash            CHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hex(sha256(chiave))',
	key_prefix          CHAR(12)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'primi 12 caratteri, per riconoscerla',
	label               VARCHAR(100) NULL COMMENT 'es. sito www.hotel.it',
	scope               ENUM('public','client') NOT NULL DEFAULT 'public',
	origins             TEXT         NULL COMMENT 'origini ammesse (CORS), una per riga',
	rate_limit_per_min  SMALLINT UNSIGNED NULL COMMENT 'NULL = site_api.rate_limit_per_min',
	created_by          INT UNSIGNED NULL,
	created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	expires_at          DATETIME     NULL,
	last_used_at        DATETIME     NULL,
	last_used_origin    VARCHAR(255) NULL COMMENT 'intestazione Origin dell ultima richiesta (vuoto = da server)',
	revoked_at          DATETIME     NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uk_site_keys_hash (key_hash),
	KEY idx_site_keys_customer (customer_id),
	CONSTRAINT fk_site_keys_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
		ON DELETE CASCADE,
	CONSTRAINT fk_site_keys_user FOREIGN KEY (created_by) REFERENCES users (id)
		ON DELETE SET NULL,
	CONSTRAINT ck_site_keys_scope CHECK (scope = 'public' OR customer_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, note) VALUES ('3', 'contratto 1.2: clienti, chiavi dei siti');
