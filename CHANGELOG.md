# Changelog

## 0.4.0 - 2026-11-19

Fase 3b di Webcam 2.0 (servizi), secondo il contratto dei dati 1.3 (`servizi-v1.md`, API dei siti 1.1, API Dolibarr 2.2).

- Migrazione 004 (`migrations/004_servizi.sql`, MariaDB 10.4): piano e funzioni, sovrimpressione, sponsor, punteggi, timelapse, foto del giorno, statistiche, registro, rapporti, certificati, Telegram, nuove colonne di `log_counters`.
- Funzioni per webcam dal piano Dolibarr (modulo 1.4: `plan`, `options`, `archives`), webcam archiviate fino a `archive_until`; `film_status` `delivered` impostato dall'operatore non si sovrascrive.
- M2 sovrimpressione con GD sulle riduzioni e su `current.jpg` (originale intatto, ETag con la revisione); M12 sponsor e infopoint con periodo.
- O8 punteggio delle foto buone (`bin/photo-scores.php`).
- M7 timelapse giorno/settimana/mese, periodo scelto e filmato finale, orizzontali e verticali (`bin/timelapse.php`); ffmpeg facoltativo (`ffmpeg.path`, `FFMPEG_PATH`): senza, la funzione è spenta con un messaggio chiaro e `whoami.server` lo dice. Il container Docker installa ffmpeg.
- M6 stesso momento in giorni diversi; M11 foto del giorno automatica (`photo_of_day.time`, predefinito 45 minuti dopo il tramonto) o scelta a mano fino alle 23:59 del giorno dopo (`bin/photo-of-day.php`).
- M8 statistiche: richieste di `current.jpg`, beacon `/views` e `views.gif`, visitatori unici con impronta salata del giorno (nessun IP in chiaro, sali cancellati dopo 48 ore) (`bin/stats.php`).
- O2 registro delle impronte SHA-256 a catena (`vlreg1`, foto in ritardo, deposito in Dolibarr, `bin/register.php --verifica`), giornate di maltempo e certificato PDF; O3 rapporto mensile PDF (`bin/reports.php`). Generatore PDF in PHP (`src/Pdf/Pdf.php`); invio a Dolibarr con `documents` (doppione = inviato, `send_email`); conteggi dei log dal logserver (riavvii, invii FTP falliti) con l'elenco dei dati mancanti se non risponde; la retention tiene le foto dei certificati.
- O10 bot Telegram via webhook (`POST /telegram/webhook`) o polling, codici di abbinamento, avvisi foto del giorno e gelo, ore di silenzio; token solo in `config.php` o `TELEGRAM_BOT_TOKEN` (`bin/telegram.php`).
- API dei siti 1.1: `same-moment`, `timelapses`, `photo-of-day`, `photos-of-day`, `overlay-items`, `stats`, `reports`, `weather-days`, `certificates`, campi `features`, `overlay`, `beacon`, `archive_until`, `whoami.server`, errori `feature_unavailable` e `scope_client_required`; file firmati `/media/...` (video con Range, documenti con scadenza di un giorno).
- VictoriaLogs: conteggi giornalieri del giorno UTC chiuso (con nuovi tentativi fino a 3 giorni) per i rapporti.
- Pannello: *Servizi* della webcam, *Rapporti*, *Telegram*.
- Test della fase 3b in `tests/servizi.php`; finto Dolibarr 1.4 e finto Telegram.

## 0.3.0 - 2026-11-19

Fase 3 di Webcam 2.0 (siti dei clienti in WordPress), secondo il contratto dei dati 1.2.

- Migrazione 003 (`migrations/003_siti.sql`, compatibile con MariaDB 10.4): campi `code`, `town`, `source`, `note` dei clienti, storico delle assegnazioni `customer_webcams`, chiavi dei siti `site_keys`.
- Clienti importati da `bin/sync-dolibarr.php` (cliente di `GET webcams/{id}/portal`, codice, email e città da `GET thirdparties/{id}`) o creati nel pannello; webcam assegnate con lo storico: il cliente nuovo di una webcam non vede le foto e le misure del cliente di prima, il pubblico vede solo l'assegnazione in corso.
- Chiavi API dei siti `vls_...` (solo SHA-256 nel database) per cliente, con scope `public`/`client`, origini ammesse (CORS, anche `https://*.dominio`), limite al minuto e scadenza; gestione da pannello (*Clienti*: crea, mostra una volta, origini, revoca) e da `bin/site-key.php`.
- API in sola lettura `GET /api/site/v1/`: `whoami`, `webcams`, `webcams/{slug}`, `photos?date=`, `days?month=`, `series?from&to&fields=` (medie orarie di temperatura, umidità e punto di rugiada esterni), `compare?a=&b=`. Webcam pubbliche anche senza chiave; nessun dato interno nelle risposte.
- Foto ridotte `/img/{id}/{size}.jpg` (320, 640, 1280, 1920; originale solo per lo scope `client`) con URL firmati HMAC e scadenza, generate con GD una alla volta e tenute in `storage/cache/img/`; ETag, 304, `Cache-Control` fino alla scadenza; `photo_metrics.memory_limit` per le foto da 24 MP (`503` se non basta); pulizia nella retention.
- Pannello: pagine *Clienti* e scheda del cliente; nella scheda della webcam cliente, latitudine, longitudine e altitudine.
- Limite di frequenza generalizzato (per token, per chiave, per IP).
- Test della fase 3 in `tests/siti.php`; il finto Dolibarr 1.3 risponde a `GET /thirdparties/57`.

## 0.2.0 - 2026-11-19

Fase 2 di Webcam 2.0 (assistenza automatica), secondo il contratto dei dati 1.1.

- Migrazione 002 (`migrations/002_diagnosi.sql`): avvisi, stato delle regole, comandi, metriche e riferimenti delle foto, contatori dei log, disponibilità, impostazioni di servizio; campi `telemetry/1` di luci-app-webcam 1.2.0 (`heater_on_since`, `heater_duty_pct`, `heat_reason`, `heater_boost_until`, `dewpoint_margin`, `camera.scheduled`, `camera.interval_min`).
- `bin/rules.php`: motore delle 12 regole di diagnosi con stato, durate minime, isteresi, chiusura automatica, riapertura entro 24 ore, gravità solo in salita, dipendenza `camera.stalled`/`telemetry.stalled`, scadenze dell'impegno di disponibilità, `--now` per ricalcolare. `bin/diagnose.php` è un alias.
- `bin/photo-metrics.php`: nitidezza (varianza del laplaciano), luminosità, confronto col riferimento della stessa ora e spostamento con GD su una copia a 640 px; riferimenti automatici; `memory_limit` configurabile (foto saltata e scritta nel log se non basta). Regole `glass.fog` (manda `heater.boost`), `glass.dirty`, `camera.moved`.
- Comandi al router `command/1`: coda, consegna nelle risposte e in `GET /api/v1/commands` solo con `X-Webcam-Features: commands/1` e scope `commands`, scadenza, `command.ack`, validazione con la lista chiusa del contratto.
- Ticket Dolibarr per avviso (modulo 1.3: `open_for_rule`, `close_refused`, 403, 409, `GET /tickets/{id}`), idempotenti; con il modulo 1.2 messaggi e chiusure restano in coda.
- Disponibilità oraria, giornaliera e mensile (definitiva dal 3 del mese dopo), inviata a Dolibarr; soglie `sla_*` da `GET webcams/{id}/portal`; `bin/availability.php`.
- Client VictoriaLogs (una `stats_query` ogni 15 minuti) e regole `logs.reboots`, `logs.camera_errors` per hostname.
- Pannello: pagina Avvisi con filtri, dettaglio (presa in carico, chiusura, silenzio), stato delle regole, comandi, grafico della nitidezza a 7 giorni, riferimenti, disponibilità, impostazioni di servizio; disponibilità facoltativa sulla pagina pubblica.
- Simulatore 1.2.0: `--dal/--al`, `--foto-ogni`, `--scenario guarnizione|riscaldamento|sole|vetro-appannato|vetro-sporco|camera-mossa|camera-ferma` con `--guasto-dal/--guasto-al`, comandi `commands/1` con `command.ack`, `shutter_count`.
- Test della fase 2 in `tests/diagnosi.php`, finto Dolibarr 1.3 e finta VictoriaLogs.
- Contratto 1.1 corretto: righe finali per `logs.camera_errors`, hostname `WC-<MAC>`, `sla_hours` fino a `24:00`, intervalli stretti per `config.set`, ack `protection`, `result` di `heater.cancel`.

## Contratto 1.1 (documentazione, prima della 0.2)

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
