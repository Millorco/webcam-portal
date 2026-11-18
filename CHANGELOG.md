# Changelog

## Non rilasciato

- Contratto dei dati 1.1 (fase 2, assistenza automatica), solo documentazione: regole di diagnosi `docs/contratto/diagnosi-v1.md`, migrazione 2 `docs/contratto/schema-mariadb-002.sql`, comandi al router `command/1` (`comando-v1.schema.json`, risposte delle API e `GET /api/v1/commands`, evento `command.ack`), campi di diagnosi facoltativi in `telemetry/1`, API Dolibarr documento 2.1 (modulo 1.3: soglie del contratto di noleggio, ticket per regola, disponibilità mensile). Il codice arriva con la 0.2.

## 0.1.0 - 2026-11-18

Prima versione (fase 1 di Webcam 2.0), secondo il contratto dei dati v1.

- API v1 per i router: `POST /api/v1/photo` (multipart JPEG + `photo/1` o `photo/0`, verifica SHA-256, idempotenza, archivio per giorno, EXIF, `current.jpg`), `POST /api/v1/telemetry` (lotti `telemetry/1`, gzip, `INSERT IGNORE`, delta di traffico, medie orarie, contatori della reflex), `POST /api/v1/event`, `GET /api/v1/whoami`.
- Autenticazione Bearer con token per webcam (solo SHA-256 nel database), limite di 120 richieste/minuto, solo HTTPS in produzione, codici di risposta e limiti di dimensione del contratto.
- Eventi del portal: riavvio (boot_id cambiato), cambio di versione, batteria bassa.
- Pubblico: `/cam/{slug}/current.jpg` (ETag, Last-Modified, 304, cache 60 s), `/cam/{slug}/current.json`, pagina `/cam/{slug}/`.
- Pannello operatori con login: elenco webcam, scheda con foto, misure, grafici SVG delle ultime 24 ore, eventi, contatori, modifica, creazione webcam, rigenerazione del token.
- Script: `install.php` (migrazioni, partizioni), `webcam-add.php`, `webcam-token.php`, `user-add.php`, `aggregate-hourly.php`, `partitions.php`, `retention-photos.php`, `sync-dolibarr.php` (API webcams v2, tollerante al modulo 1.1).
- `tools/simula-router.php`: router simulato per l'anteprima locale.
- Docker (php:8.2-apache) per il VPS dietro Nginx Proxy Manager; anteprima in XAMPP.
- Test `tests/run.php` contro MariaDB e `php -S`.
- Contratto Dolibarr v2 aggiornato con il modulo 1.2: id del permesso `portal` 51730014, `bymac` con MAC senza separatori, permesso "Estendi l'accesso a tutti i terzi", riferimento del ticket in `extraparams`.
