# webcam-portal

Portale di Webcam 2.0 (Viewlapse): riceve dai router con [luci-app-webcam](https://github.com/Millorco/luci-app-webcam) le foto, la telemetria ogni 5 minuti e gli eventi, li salva in MariaDB e pubblica l'ultima foto di ogni webcam (`/cam/{slug}/current.jpg`, adatta a Windy e ai siti dei clienti). Riassume i dati verso il modulo Dolibarr [dolibarr-webcams](https://github.com/Millorco/dolibarr-webcams) tramite le sue API. Dalla 0.3 dà ai siti dei clienti in WordPress un'API in sola lettura con chiavi per cliente e foto ridotte con URL firmati: le foto restano sul portal, i siti le mostrano soltanto.

- PHP 8.2 senza framework e senza composer (autoload PSR-4 scritto a mano), accesso al database con PDO.
- MariaDB 11.8 sul VPS (database separato da quello di Dolibarr, utente dedicato); MariaDB 10.4+ di XAMPP per l'anteprima locale su Windows.
- I dati di Dolibarr si leggono solo dalle API di Dolibarr, mai dalle sue tabelle.
- Nessun CDN: il pannello è HTML semplice, i grafici sono SVG generati sul server.

## Contratto dei dati

Formati JSON, API e schema del database comuni a tutte le repository: [docs/contratto/](docs/contratto/README.md). Le API implementate sono esattamente quelle di [api-portal-v1.md](docs/contratto/api-portal-v1.md). Il portal 0.2 implementa il contratto 1.1 (fase 2: [diagnosi](docs/contratto/diagnosi-v1.md), comandi al router, disponibilità, migrazione [002](docs/contratto/schema-mariadb-002.sql)); la 0.3 il contratto 1.2 (fase 3: [API dei siti](docs/contratto/api-siti-v1.md), migrazione [003](docs/contratto/schema-mariadb-003.sql)); la 0.4 il contratto 1.3 (fase 3b: [servizi](docs/contratto/servizi-v1.md), API dei siti 1.1, API Dolibarr 2.2, migrazione [004](docs/contratto/schema-mariadb-004.sql)).

## Cosa fa (versione 0.4)

| Funzione | Dove |
|---|---|
| `POST /api/v1/photo`: JPEG + JSON `photo/1` (e `photo/0`), verifica SHA-256, idempotenza, archivio `AAAA/MM/GG`, EXIF, `current.jpg` | `src/Api/PhotoApi.php` |
| `POST /api/v1/telemetry`: lotti `telemetry/1` (anche gzip), `INSERT IGNORE`, delta di traffico, medie orarie, contatori reflex, eventi del portal (riavvio, cambio versione, batteria bassa) | `src/Api/TelemetryApi.php` |
| `POST /api/v1/event`: lotti `event/1`, idempotenza | `src/Api/EventApi.php` |
| `GET /api/v1/whoami`: prova del token ("Prova connessione" della LuCI) | `src/App.php` |
| Autenticazione Bearer (solo SHA-256 del token nel database), 120 richieste/minuto per token, solo HTTPS in produzione | `src/Api/TokenAuth.php`, `RateLimiter.php` |
| `GET /cam/{slug}/current.jpg` (ETag, Last-Modified, `Cache-Control: public, max-age=60`, 304), `current.json`, pagina `/cam/{slug}/` | `src/Web/PublicController.php` |
| Pannello operatori `/admin/`: elenco webcam, scheda con foto, misure, grafici 24 ore, eventi, contatori, modifica, creazione webcam, rigenerazione token | `src/Admin/` |
| Medie orarie, partizioni mensili, retention delle foto | `bin/aggregate-hourly.php`, `bin/partitions.php`, `bin/retention-photos.php` |
| Sincronizzazione Dolibarr (contatori, Victron, URL `current.jpg`, eventi importanti) | `bin/sync-dolibarr.php`, `src/Dolibarr/` |
| Router simulato per l'anteprima e i test, con scenari di guasto e comandi | `tools/simula-router.php` |

Fase 2 (assistenza automatica, 0.2):

| Funzione | Dove |
|---|---|
| Motore delle regole di [diagnosi-v1.md](docs/contratto/diagnosi-v1.md): stato per regola (`rule_state`), durate minime, isteresi, chiusura automatica, riapertura entro 24 ore, gravità solo in salita, `camera.stalled` sospesa mentre è aperto `telemetry.stalled`, scadenze dell'impegno di disponibilità | `bin/rules.php`, `src/Rules/` |
| 12 regole: `telemetry.stalled`, `camera.stalled`, `enclosure.seal`, `enclosure.heater`, `enclosure.overheat`, `enclosure.fan`, `glass.fog` (manda `heater.boost`), `glass.dirty`, `camera.moved`, `shutter.life`, `logs.reboots`, `logs.camera_errors` | `src/Rules/Rule/` |
| Metriche foto con GD su una copia a 640 px (nitidezza = varianza del laplaciano, mediana su 16 riquadri, luminosità, spostamento rispetto al riferimento della stessa ora), foto di riferimento scelte in automatico, `memory_limit` configurabile | `bin/photo-metrics.php`, `src/Photo/` |
| Comandi al router `command/1`: coda per webcam, consegna nelle risposte di `/photo`, `/telemetry`, `/event` e in `GET /api/v1/commands` (solo con `X-Webcam-Features: commands/1` e scope `commands`), scadenza, `command.ack`, lista chiusa di tipi e valori | `src/Service/Commands.php` |
| Ticket Dolibarr per avviso (modulo 1.3: apertura, messaggi, chiusura, stato con `GET /tickets/{id}`), testo con dati, link al portal e a rttys; con il modulo 1.2 si apre solo il ticket e il resto resta in coda | `src/Dolibarr/TicketSync.php` |
| Disponibilità per ora, giorno e mese (definitiva dal 3 del mese dopo), inviata con `POST webcams/{id}/availability`; soglie `sla_*` da `GET webcams/{id}/portal` | `bin/availability.php`, `src/Service/Availability.php` |
| Conteggi dei log da VictoriaLogs (riavvii, errori finali della fotocamera, tentativi) per hostname | `src/Logs/` |
| Pannello: pagina Avvisi (aperti/chiusi, filtri per webcam e regola), dettaglio con presa in carico, chiusura e silenzio della regola; nella scheda stato delle regole, comandi (Scatta ora, Riscaldamento 30 min, Invia telemetria), nitidezza degli ultimi 7 giorni, riferimenti per fascia, disponibilità e impostazioni di servizio | `src/Admin/DiagnosisPages.php`, `templates/admin/` |

Fase 3 (siti dei clienti in WordPress, 0.3):

| Funzione | Dove |
|---|---|
| Clienti (`customers`, collegati al terzo Dolibarr con `dolibarr_socid`) importati da `sync-dolibarr.php` (`GET webcams/{id}/portal` e `GET thirdparties/{id}`) o creati nel pannello; webcam assegnate con lo storico (`customer_webcams`): il cliente nuovo di una webcam a noleggio non vede le foto del cliente di prima | `src/Service/Customers.php`, `src/Dolibarr/Sync.php` |
| Chiavi API dei siti `vls_...` per cliente (solo SHA-256 nel database), scope `public` (webcam pubbliche) o `client` (tutte le webcam del cliente), origini ammesse per CORS, limite al minuto per chiave | `src/Service/SiteKeys.php`, pannello *Clienti*, `bin/site-key.php` |
| `GET /api/site/v1/whoami`, `webcams`, `webcams/{slug}`, `.../photos?date=`, `.../days?month=`, `.../series?from&to&fields=`, `.../compare?a=&b=`; senza chiave le sole webcam pubbliche | `src/Site/SiteApi.php` |
| Foto ridotte `/img/{id}/{320,640,1280,1920,orig}.jpg?e=&s=` con firma HMAC e scadenza, generate con GD una alla volta e tenute in `storage/cache/img/` (ETag, `Cache-Control` fino alla scadenza); originale solo per lo scope `client` | `src/Site/Images.php` |

Fase 3b (servizi a pagamento, 0.4), secondo [servizi-v1.md](docs/contratto/servizi-v1.md):

| Funzione | Dove |
|---|---|
| Funzioni per webcam dal piano Dolibarr (`base`, `standard`, `pro` + opzioni, `GET webcams/{id}/portal`), webcam archiviate fino a `archive_until` (solo archivio, timelapse, foto del giorno, rapporti) | `src/Service/Features.php`, `src/Dolibarr/Sync.php` |
| M2 sovrimpressione con GD (dati meteo, logo, sponsor e infopoint di M12) sulle riduzioni e su `current.jpg`; l'originale non si tocca; ETag `<sha>-o<rev>` | `src/Overlay/`, `src/Web/PublicController.php` |
| O8 punteggio delle foto buone (nitidezza, luminosità, meteo) | `bin/photo-scores.php`, `src/Service/PhotoScores.php` |
| M7 timelapse giorno/settimana/mese (e periodo scelto, filmato finale), orizzontali e verticali, con ffmpeg | `bin/timelapse.php`, `src/Service/Timelapse.php`, `Ffmpeg.php` |
| M6 stesso momento in giorni diversi; M11 foto del giorno (automatica dopo il tramonto o scelta a mano fino alle 23:59 del giorno dopo) | `src/Site/SiteApi.php`, `bin/photo-of-day.php` |
| M8 statistiche di visualizzazione: `current.jpg`, beacon `/views` e `views.gif` (token `vb1`), visitatori unici con impronta giornaliera salata, nessun IP in chiaro | `bin/stats.php`, `src/Service/Stats.php` |
| O2 registro delle impronte SHA-256 a catena (elenco `vlreg1`, foto in ritardo), giornate di maltempo e certificato PDF; O3 rapporto mensile PDF; PDF generati in PHP; invio a Dolibarr (`documents`, `register`) | `bin/register.php`, `bin/reports.php`, `src/Pdf/Pdf.php`, `src/Service/Register.php`, `Reports.php` |
| O10 bot Telegram (webhook o polling, codici di abbinamento, avvisi foto del giorno e gelo, ore di silenzio) | `bin/telegram.php`, `src/Telegram/`, `POST /telegram/webhook` |
| API dei siti 1.1: `same-moment`, `timelapses`, `photo-of-day`, `photos-of-day`, `overlay-items`, `stats`, `reports`, `weather-days`, `certificates`; `features`, `overlay`, `beacon`, `archive_until` nelle webcam; `whoami.server`; file firmati `/media/{tl,tlp,logo,rep,cert,reg}/...` (video con Range) | `src/Site/SiteApi.php`, `src/Site/Media.php` |
| Pannello: *Servizi* della webcam (piano, sovrimpressione, logo, sponsor, timelapse, foto del giorno, statistiche), *Rapporti* (rapporti e certificati, rigenera, invia) e *Telegram* | `src/Admin/ServicePages.php`, `templates/admin/` |

## API per i siti (WordPress)

Documentata nel contratto: [docs/contratto/api-siti-v1.md](docs/contratto/api-siti-v1.md). In breve:

1. Il cliente arriva da Dolibarr (`php bin/sync-dolibarr.php`, cliente della scheda webcam) o si crea in *Pannello → Clienti*; la webcam si assegna dalla scheda (campo *Cliente*), dove si inseriscono anche latitudine, longitudine e altitudine.
2. Nella scheda del cliente, *Nuova chiave*: scope `public` per il plugin "solo visualizzazione" (con le origini del sito, es. `https://www.hotel.it`, se il plugin chiama dal browser) o `client` per il "portale clienti" (origini vuote: si usa solo dal server WordPress). La chiave si vede una volta sola. Da riga di comando:

   ```sh
   php bin/site-key.php --clienti
   php bin/site-key.php --crea --cliente 7 --scope client --etichetta "portale clienti"
   php bin/site-key.php --crea --socid 57 --scope public --origini "https://www.hotel.it,https://*.hotel.it"
   php bin/site-key.php --elenco
   php bin/site-key.php --revoca 3
   ```

3. Prova: `curl -H "Authorization: Bearer vls_..." https://portal.viewlapse.com/api/site/v1/whoami`. Senza chiave `.../api/site/v1/webcams` elenca le webcam pubbliche.

Impostazioni in `config.php`, sezione `site_api`: limiti al minuto (600 per chiave, 120 senza chiave per IP, 1200 immagini per IP), segreto degli URL firmati (`url_secret`, vuoto = generato in `storage/keys/site-url.secret`: va conservato con lo storage), durata degli URL (7 giorni, arrotondata al giorno), qualità JPEG, giorni di cache delle riduzioni (30). Le riduzioni usano `photo_metrics.memory_limit` come le metriche: con una foto da 24 MP servono circa 120 MB, altrimenti `503` e una riga in `storage/logs/site-api.log`. Dietro Nginx Proxy Manager le risposte delle immagini si possono mettere in cache anche lì (`Cache-Control: public` per le webcam pubbliche).

## Struttura

```
public/            radice del sito: index.php (front controller), .htaccess, assets/
src/               codice (namespace WebcamPortal\ -> src/)
templates/         pagine HTML (pannello e pagina pubblica)
config/            config.example.php (da copiare in config.php, ignorato da git)
migrations/        001_schema.sql = schema del contratto, 002 diagnosi, 003 siti; tabella schema_migrations
bin/               script da riga di comando e cron
tools/             simula-router.php
storage/           foto, cache delle riduzioni, segreto degli URL, log, sessioni (fuori da public/, ignorato da git)
tests/             run.php, diagnosi.php e siti.php (test senza PHPUnit), fake-dolibarr.php, fake-victorialogs.php
docker/, Dockerfile, docker-compose.yml   per il VPS
```

## Anteprima in XAMPP (Windows)

Richiede XAMPP 8.2 (PHP 8.2, Apache, MariaDB 10.4). Gli esempi usano `C:\xampp`.

1. **Copia** la cartella del repository in `C:\xampp\htdocs\webcam-portal` (con `git clone` o scaricando lo zip). Il file `.htaccess` nella radice manda tutte le richieste in `public/`, quindi `src/`, `config/` e `storage/` non sono raggiungibili dal browser.
2. **PHP**: in `C:\xampp\php\php.ini` controlla che queste righe non abbiano il `;` davanti: `extension=gd`, `extension=pdo_mysql`, `extension=exif` (facoltativa: valori EXIF delle foto), `extension=openssl`. Per le foto vere della reflex alza i limiti:
   ```ini
   upload_max_filesize = 30M
   post_max_size = 32M
   ```
   In `C:\xampp\apache\conf\httpd.conf` deve essere attivo `LoadModule rewrite_module modules/mod_rewrite.so` e la cartella `htdocs` deve avere `AllowOverride All` (predefinito in XAMPP). Riavvia Apache dal pannello di XAMPP.
3. **Database**: il database `webcam2` e l'utente `webcam2` esistono già. Se servisse ricrearli (phpMyAdmin → SQL):
   ```sql
   CREATE DATABASE webcam2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'webcam2'@'localhost' IDENTIFIED BY 'una-password-lunga';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP,
         CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES ON webcam2.* TO 'webcam2'@'localhost';
   ```
4. **Configurazione**: copia `config\config.example.php` in `config\config.php` e cambia almeno:
   ```php
   'app_env' => 'dev',          // in locale si usa HTTP: con "prod" le API rispondono 403
   'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'webcam2', 'user' => 'webcam2', 'pass' => '...'],
   'public_base_url' => 'http://localhost/webcam-portal',
   ```
5. **Schema**: apri il Prompt dei comandi nella cartella del portal ed esegui
   ```bat
   cd C:\xampp\htdocs\webcam-portal
   C:\xampp\php\php.exe bin\install.php
   ```
   Crea le tabelle (migrazione 1 = `docs/contratto/schema-mariadb.sql`), le partizioni dei prossimi mesi e le cartelle in `storage\`. Si può rieseguire: applica solo le migrazioni nuove.
6. **Operatore** del pannello (la password la chiede da tastiera, o con `--password`):
   ```bat
   C:\xampp\php\php.exe bin\user-add.php --email tu@esempio.it --nome "Mario Rossi" --ruolo admin
   ```
7. **Webcam**: con il MAC del router (o uno inventato per il simulatore):
   ```bat
   C:\xampp\php\php.exe bin\webcam-add.php --mac 94:83:c4:12:ab:cd --nome "Monte Cavo" --pubblica
   ```
   Stampa il token `wct_...` **una sola volta**: copialo (per un router vero va in LuCI, opzioni `portal_url` = `http://<ip-del-pc>/webcam-portal` e `portal_token`). Si può creare la webcam anche dal pannello ("Nuova webcam"); un token perso si rigenera dalla scheda della webcam o con `bin\webcam-token.php --slug monte-cavo`.
8. **Simulatore**: riempie due giorni di storico (telemetria ogni 5 minuti, una foto l'ora, eventi), poi una foto e mezz'ora di misure "adesso":
   ```bat
   C:\xampp\php\php.exe tools\simula-router.php --url http://localhost/webcam-portal --token wct_... --mac 94:83:c4:12:ab:cd --giorni 2
   C:\xampp\php\php.exe tools\simula-router.php --url http://localhost/webcam-portal --token wct_... --mac 94:83:c4:12:ab:cd --una-volta
   ```
   Senza `--giorni` né `--una-volta` resta acceso e manda un campione ogni 5 minuti e una foto ogni 10 (`--ogni 60 --intervallo 2` per vederlo muoversi più in fretta; Ctrl+C per fermarlo).
9. **Guarda**:
   - pannello: <http://localhost/webcam-portal/admin/>
   - pagina pubblica: <http://localhost/webcam-portal/cam/monte-cavo/>
   - immagine fissa: <http://localhost/webcam-portal/cam/monte-cavo/current.jpg>
   - dati pubblici: <http://localhost/webcam-portal/cam/monte-cavo/current.json>

Senza Apache si può provare anche col server integrato di PHP: `C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public public\index.php` (radice `http://127.0.0.1:8080`).

## Installazione sul VPS (Docker)

Il portal gira in un container `php:8.2-apache` (con `pdo_mysql`, `gd`, `exif`) sulla rete Docker esterna `shared-network`, dietro Nginx Proxy Manager come webcam-rttys. MariaDB è quello già presente sul VPS (11.8); le foto, i log e le sessioni stanno nel volume `webcam-portal-data` (`/data` nel container).

1. Database e utente su MariaDB del VPS (l'utente deve poter entrare dal container: `'webcam2'@'%'` o, meglio, la sottorete di Docker, es. `'webcam2'@'172.%'`):
   ```sql
   CREATE DATABASE webcam2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'webcam2'@'172.%' IDENTIFIED BY '<password lunga>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP,
         CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES ON webcam2.* TO 'webcam2'@'172.%';
   ```
   Se MariaDB è installato sull'host deve ascoltare anche sull'interfaccia di Docker (`bind-address`), e `DB_HOST=host.docker.internal`; se è un container sulla rete `shared-network`, `DB_HOST` è il nome del container.
2. Codice e configurazione:
   ```sh
   git clone https://github.com/Millorco/webcam-portal.git /opt/webcam-portal
   cd /opt/webcam-portal
   cp .env.example .env && nano .env         # DB_*, PUBLIC_BASE_URL, DOLIBARR_*
   docker network create shared-network 2>/dev/null || true
   docker compose up -d --build
   docker compose exec -u www-data portal php bin/install.php
   docker compose exec -u www-data portal php bin/user-add.php --email tu@esempio.it --ruolo admin
   docker compose exec -u www-data portal php bin/webcam-add.php --mac 94:83:c4:12:ab:cd --nome "Monte Cavo" --pubblica
   ```
   Nel container le impostazioni vengono dalle variabili d'ambiente (stessi nomi indicati in `config/config.example.php`); un `config/config.php` non serve.
   Gli script vanno eseguiti come `www-data` (`-u www-data`), l'utente di Apache: altrimenti le cartelle e i log creati da root non sarebbero scrivibili dal portal.
3. Aggiornamento: `git pull && docker compose up -d --build && docker compose exec -u www-data portal php bin/install.php`.

### Nginx Proxy Manager

Nuovo *Proxy Host*:

| Campo | Valore |
|---|---|
| Domain Names | `portal.viewlapse.com` |
| Scheme / Forward Hostname / Port | `http` / `webcam-portal` / `80` |
| Block Common Exploits | sì |
| SSL | certificato Let's Encrypt, **Force SSL**, HTTP/2 |

Nella scheda *Advanced* alza il limite del corpo per le foto (25 MB + JSON) e i tempi per le reti 4G lente:

```nginx
client_max_body_size 32m;
proxy_read_timeout 180s;
proxy_send_timeout 180s;
proxy_request_buffering on;
```

NPM manda `X-Forwarded-Proto: https`: con `TRUST_PROXY=true` (predefinito nel container) il portal riconosce l'HTTPS e l'IP reale dei router. Il container non va esposto su una porta pubblica: si raggiunge solo dalla rete `shared-network`.

## Operazioni pianificate

| Script | Quando | Cosa fa |
|---|---|---|
| `bin/rules.php` | ogni 5 minuti | regole di diagnosi, comandi delle regole, VictoriaLogs (al più ogni 15 minuti), ticket Dolibarr (`--webcam`, `--regole`, `--logs`, `--now`, `--senza-dolibarr`) |
| `bin/photo-metrics.php` | ogni 5 minuti | metriche delle foto nuove (una ogni 30 minuti per webcam) e regole delle foto (`--webcam`, `--now`, `--senza-regole`) |
| `bin/availability.php` | ogni ora, al minuto 20 | disponibilità di oggi e dei 7 giorni prima, mesi provvisori e definitivi (`--webcam`, `--dal`, `--al`, `--now`) |
| `bin/aggregate-hourly.php` | ogni ora, al minuto 5 | ricalcola le medie orarie delle ultime 3 ore (`--ore N`, `--dal AAAA-MM-GG --al ...`) |
| `bin/sync-dolibarr.php` | ogni ora, al minuto 15 | invia a Dolibarr contatori, Victron, URL `current.jpg`, eventi in agenda, disponibilità; legge le soglie `sla_*` e il cliente della webcam; ticket; riprova gli endpoint mancanti |
| `bin/photo-scores.php` | ogni 5 minuti | punteggio delle foto nuove (`--webcam`, `--now`) |
| `bin/timelapse.php` | ogni 10 minuti | crea i timelapse automatici (giorno alle 01:00 locali, settimana il lunedì, mese il 2, filmato finale) ed esegue un lavoro dalla coda (`--tutti`, `--stato`, `--richiedi`) |
| `bin/photo-of-day.php` | ogni 15 minuti | foto del giorno di oggi e di ieri all'ora `photo_of_day.time` (`--scegli FOTO --webcam ID`) |
| `bin/register.php` | ogni ora, al minuto 40 | sigilla i giorni chiusi (dalle 01:30 locali), foto in ritardo, deposito in Dolibarr (`--verifica`) |
| `bin/reports.php` | ogni ora, al minuto 45 | dal giorno `reports.day` alle `reports.time`: rapporti mensili e certificati, invio a Dolibarr (`--certificato`, `--rigenera`) |
| `bin/stats.php` | ogni ora, al minuto 50 | somma le visualizzazioni, cancella impronte e sali dopo 48 ore, sponsor che entrano o escono |
| `bin/telegram.php` | ogni minuto | coda dei messaggi, avviso gelo; con `telegram.mode = poll` anche `getUpdates` |
| `bin/partitions.php` | ogni giorno alle 03:10 | crea le partizioni dei prossimi 2 mesi, cancella quelle oltre 12 mesi (`--prova`, `--elenco`) |
| `bin/retention-photos.php` | ogni giorno alle 03:30 | originali oltre 90 giorni: tiene una foto l'ora; cache delle riduzioni per i siti non usate da 30 giorni, timelapse scaduti, loghi non usati; tiene le foto dei certificati (`--giorni N`, `--cache-giorni N`, `--prova`) |

I risultati finiscono in `storage/logs/cron.log`, `rules.log`, `photo-metrics.log`, `availability.log` e `sync-dolibarr.log`. `rules.php` e `photo-metrics.php` hanno un lucchetto: se un giro è ancora in corso il successivo salta.

### Linux (VPS, crontab dell'host)

`crontab -e` (con Docker gli script girano nel container):

```cron
*/5 * * * * docker exec -u www-data webcam-portal php bin/rules.php         >/dev/null 2>&1
2-59/5 * * * * docker exec -u www-data webcam-portal php bin/photo-metrics.php >/dev/null 2>&1
20 * * * *  docker exec -u www-data webcam-portal php bin/availability.php   >/dev/null
5  * * * *  docker exec -u www-data webcam-portal php bin/aggregate-hourly.php >/dev/null
15 * * * *  docker exec -u www-data webcam-portal php bin/sync-dolibarr.php   >/dev/null 2>&1
10 3 * * *  docker exec -u www-data webcam-portal php bin/partitions.php      >/dev/null
30 3 * * *  docker exec -u www-data webcam-portal php bin/retention-photos.php >/dev/null
1-59/5 * * * * docker exec -u www-data webcam-portal php bin/photo-scores.php >/dev/null 2>&1
*/10 * * * * docker exec -u www-data webcam-portal php bin/timelapse.php     >/dev/null 2>&1
7-59/15 * * * * docker exec -u www-data webcam-portal php bin/photo-of-day.php >/dev/null 2>&1
40 * * * *  docker exec -u www-data webcam-portal php bin/register.php       >/dev/null 2>&1
45 * * * *  docker exec -u www-data webcam-portal php bin/reports.php        >/dev/null 2>&1
50 * * * *  docker exec -u www-data webcam-portal php bin/stats.php          >/dev/null
* * * * *   docker exec -u www-data webcam-portal php bin/telegram.php       >/dev/null 2>&1
```

Senza Docker: `5 * * * * cd /opt/webcam-portal && php bin/aggregate-hourly.php`, e così via.

### Windows (XAMPP, Utilità di pianificazione)

Per l'anteprima bastano le medie orarie (le ore toccate si ricalcolano già alla ricezione). Da un Prompt dei comandi come amministratore:

```bat
schtasks /Create /TN "webcam-portal medie orarie" /SC HOURLY /MO 1 /ST 00:05 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\aggregate-hourly.php"
schtasks /Create /TN "webcam-portal partizioni" /SC DAILY /ST 03:10 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\partitions.php"
schtasks /Create /TN "webcam-portal retention foto" /SC DAILY /ST 03:30 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\retention-photos.php"
schtasks /Create /TN "webcam-portal Dolibarr" /SC HOURLY /MO 1 /ST 00:15 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\sync-dolibarr.php"
schtasks /Create /TN "webcam-portal regole" /SC MINUTE /MO 5 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\rules.php"
schtasks /Create /TN "webcam-portal metriche foto" /SC MINUTE /MO 5 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\photo-metrics.php"
schtasks /Create /TN "webcam-portal disponibilita" /SC HOURLY /MO 1 /ST 00:20 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\availability.php"
schtasks /Create /TN "webcam-portal punteggi" /SC MINUTE /MO 5 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\photo-scores.php"
schtasks /Create /TN "webcam-portal timelapse" /SC MINUTE /MO 10 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\timelapse.php"
schtasks /Create /TN "webcam-portal foto del giorno" /SC MINUTE /MO 15 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\photo-of-day.php"
schtasks /Create /TN "webcam-portal registro" /SC HOURLY /MO 1 /ST 00:40 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\register.php"
schtasks /Create /TN "webcam-portal rapporti" /SC HOURLY /MO 1 /ST 00:45 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\reports.php"
schtasks /Create /TN "webcam-portal statistiche" /SC HOURLY /MO 1 /ST 00:50 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\stats.php"
schtasks /Create /TN "webcam-portal telegram" /SC MINUTE /MO 1 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\webcam-portal\bin\telegram.php"
```

Oppure dall'interfaccia: *Utilità di pianificazione → Crea attività di base*, programma `C:\xampp\php\php.exe`, argomento il percorso dello script, "Inizia da" `C:\xampp\htdocs\webcam-portal`.

## Dolibarr

`bin/sync-dolibarr.php` usa l'utente API `webcam-portal` (vedi [api-dolibarr-v2.md](docs/contratto/api-dolibarr-v2.md): permessi Webcam → Leggere e Sincronizzare dal portal, Agenda → creare, **Ticket → Leggere e Creare** (Leggere serve dal modulo 1.3 per lo stato dei ticket con `GET /tickets/{id}`), Soggetti terzi → leggere ed *Estendi l'accesso a tutti i terzi*). In `config.php`:

```php
'dolibarr' => [
	'url' => 'http://localhost/dolibarr/api/index.php',   // fino a index.php; se Dolibarr ha la cartella htdocs: .../dolibarr/htdocs/api/index.php
	'api_key' => '<chiave API dell'utente webcam-portal>',
],
```

Per ogni webcam non ritirata: la cerca per MAC (`GET webcams/bymac/9483c412abcd`, MAC senza separatori perché il WAF di Dolibarr rifiuta `:`; con il modulo 1.1 ripiega su `GET webcams?sqlfilters=...`), salva `dolibarr_id`, poi `PUT webcams/{id}/portal` (slug, `url_current`, Windy), `POST webcams/{id}/counters` (contatori reflex, tensione batteria attuale e minima 24 h, fase di carica, resa di ieri, errore Victron) e `POST webcams/{id}/events` per gli eventi con gravità ≥ warning non ancora inviati. Se il modulo è ancora alla 1.1 gli endpoint v2 non esistono: lo scrive una volta nel log e prosegue, con codice di uscita 0. Chiave sbagliata o Dolibarr spento: errore nel log, uscita 1, nessun crash.

Con il modulo 1.3, a ogni giro:

- `GET webcams/{id}/portal` copia in `webcam_service` le soglie del contratto di noleggio (`sla_*`), la vita dell'otturatore e i link rttys; finché la webcam non ne ha, le soglie si impostano nella scheda del pannello.
- Dallo stesso `GET webcams/{id}/portal` il cliente (`customer`) diventa un record di `customers` (per `dolibarr_socid`, con codice, email e città da `GET thirdparties/{id}` se l'utente può leggere i terzi) e la webcam gli viene assegnata con lo storico; `customer: null` la toglie. Dolibarr vince sul pannello a ogni giro. Il campo *Attivo* del cliente resta del pannello.
- `POST webcams/{id}/availability` manda il mese provvisorio una volta al giorno e il definitivo il 3 del mese dopo. Un `409` su un mese già definitivo vuol dire "già registrato".
- I ticket seguono gli avvisi con azione *ticket*: `POST webcams/{id}/tickets` all'apertura (con `portal_rule` e `portal_alert_id`; se Dolibarr ha già un ticket aperto per la regola risponde `open_for_rule` e il portal lo usa), `PUT webcams/{id}/tickets/{ticket_id}` per i messaggi e la chiusura (`close_refused: in_progress` se qualcuno ci sta lavorando: resta aperto; `409` se è già chiuso e `403` se non è un ticket del portal: non si riprova), `GET /tickets/{id}` per sapere se l'operatore l'ha chiuso.
- La descrizione ha i dati misurati, cosa controllare, il link all'avviso nel portal e i link rttys (`rttys_url` + `/api/connect/<ref Dolibarr>`, se Dolibarr non li manda).

`bin/rules.php` sincronizza i ticket subito dopo le regole. Con il modulo 1.2 si apre il ticket ma messaggi e chiusure restano in coda: lo scrive nel log una volta ogni 6 ore, e `sync-dolibarr.php` riprova una volta l'ora.

## Diagnosi

- Soglie: quelle di [diagnosi-v1.md](docs/contratto/diagnosi-v1.md); si cambiano per tutte le webcam in `config.php` (`rules.params`, es. `'glass.fog' => ['boost_s' => 1200]`) o per webcam nella tabella `rule_config`. `rules.disabled` spegne una regola per tutte.
- Le regole valutano solo i dati fino all'ora di `--now`: `php bin/rules.php --now "2026-11-18 15:00"` ricalcola com'era quel momento.
- Metriche foto: GD lavora su una copia in grigi a 640 px; un JPEG da 24 Mpx decompresso occupa circa 125 MB, quindi `photo_metrics.memory_limit` (predefinito `512M`, variabile `PHOTO_METRICS_MEMORY`). Se la memoria non basta la foto si salta con `skip_reason = memory` e una riga nel log. Le foto di notte e quelle troppo scure o bruciate non si analizzano.
- Riferimenti: per ogni ora del giorno la foto più nitida degli ultimi 14 giorni con il secco, rinnovata ogni 7 giorni, mai mentre è aperto un avviso del vetro o dell'inquadratura. Dal pannello: *ricalcola* una fascia, *usa come riferimento* una foto, *conferma la nuova inquadratura* (azzera tutti i riferimenti).
- VictoriaLogs ([webcam-logserver](https://github.com/Millorco/webcam-logserver), `docs/query.md`): in `config.php` `victorialogs.url` (es. `http://victorialogs:9428`), `user`, `pass`, `timeout` (5 s), o le variabili `VICTORIALOGS_URL`, `VICTORIALOGS_USER`, `VICTORIALOGS_PASS`, `VICTORIALOGS_TIMEOUT`. Vuoto = spento: le regole `logs.*` usano solo gli eventi. Una sola `stats_query` ogni 15 minuti per tutte le webcam, legate per `webcams.hostname` (`WC-<MAC>`); un hostname senza righe vale 0, uno usato da due webcam dà "dati insufficienti".
- Disponibilità pubblica: con `availability_public = true` (variabile `AVAILABILITY_PUBLIC`) la pagina `/cam/{slug}/` delle webcam pubbliche mostra la disponibilità del mese.

## Servizi (0.4)

- **Funzioni**: arrivano dal piano della scheda Dolibarr (`plan`, `options`, `archives` di `GET webcams/{id}/portal`, modulo 1.4) con `sync-dolibarr.php`; la tabella in `src/Service/Features.php`. Senza Dolibarr si impostano nel pannello (*Servizi* della webcam). Una webcam a fine noleggio resta archiviata fino a `archive_until` (`archive.default_days`, 30): i siti vedono solo l'archivio e i documenti.
- **ffmpeg** (solo timelapse): facoltativo. `ffmpeg.path` in `config.php` (o `FFMPEG_PATH`): vuoto = `ffmpeg` nel `PATH`, un percorso assoluto (su Windows es. `C:\ffmpeg\bin\ffmpeg.exe`, da https://www.gyan.dev/ffmpeg/builds/), `off` = spento. Il container Docker lo installa. Senza ffmpeg i timelapse sono spenti: il pannello lo dice, `whoami.server.timelapse` è `false` con il motivo, i lavori in coda finiscono in `failed` con `ffmpeg_missing`. Prova: `php bin/timelapse.php --stato`.
- **Sovrimpressione**: si disegna con GD sulle riduzioni e su `current.jpg` servito (mai sull'originale); font TrueType in `overlay.font` e `overlay.font_bold` (vuoto = `assets/fonts/DejaVuSans.ttf`). Ogni cambio alza la revisione e quindi l'ETag.
- **Statistiche**: l'IP non si salva mai: si usa un'impronta HMAC-SHA256 (sale casuale del giorno, IP accorciato, user agent, webcam) per contare i visitatori unici, cancellata con il sale dopo 48 ore (`bin/stats.php`).
- **Rapporti e certificati**: PDF generati dal portal (`src/Pdf/Pdf.php`, nessuna libreria esterna), allegati alla scheda Dolibarr con `POST webcams/{id}/documents` (un doppione vale come inviato) e, con `send_email`, mandati al cliente da Dolibarr. I conteggi dei log (riavvii, invii FTP falliti) vengono dal logserver: se non risponde, il rapporto scrive quali dati mancano. Rigenerare un rapporto non rimanda l'email (`--rigenera ... --email` per rimandarla).
- **Registro**: ogni giorno chiuso ha un elenco `vlreg1` delle impronte delle foto e un `day_hash` a catena col giorno prima, depositato in Dolibarr (`POST webcams/{id}/register`); `php bin/register.php --verifica` ricontrolla elenchi e catena. Le foto usate in un certificato non si cancellano con la retention.
- **Telegram**: crea il bot con @BotFather e metti il token in `telegram.bot_token` o `TELEGRAM_BOT_TOKEN` (mai nel codice o nei log). Sul VPS `telegram.mode = 'webhook'`, un `webhook_secret` casuale e `php bin/telegram.php --set-webhook https://portal.viewlapse.com/telegram/webhook`; su XAMPP `mode = 'poll'`. Il cliente si abbona con il codice creato nel pannello *Telegram* o con `php bin/telegram.php --codice --cliente ID`.

## Test

`tests/run.php` (senza PHPUnit) ricrea lo schema in un database di prova (il nome deve finire con `_test`), avvia il portal con `php -S` e un finto Dolibarr (moduli 1.1, 1.2 e 1.3), e prova autenticazione, foto (idempotenza, SHA-256, limiti, `photo/0`), `current.jpg`/`current.json`/304, telemetria (idempotenza, validazione per campione, gzip, limiti, eventi del portal), eventi, limite di frequenza, medie orarie, partizioni, retention, sincronizzazione Dolibarr, pannello e simulatore.

`tests/diagnosi.php` (incluso da `run.php`) prova la fase 2: per ogni scenario del simulatore (`guarnizione`, `riscaldamento`, `sole`, `vetro-appannato`, `vetro-sporco`, `camera-mossa`, `camera-ferma`) una webcam nuova riceve giorni di storico con il guasto e poi la riparazione; metriche foto e regole girano ora per ora con l'ora finta, e l'avviso deve aprirsi durante il guasto e chiudersi da solo dopo. Poi una webcam sana senza avvisi, comandi (consegna solo con intestazione e scope, ack dal simulatore, scadenza, validazione, pulsanti del pannello), metriche (memoria insufficiente), pagina Avvisi, disponibilità, ticket con i finti Dolibarr 1.2 e 1.3 e VictoriaLogs con un finto server. Dura qualche minuto.

`tests/siti.php` (incluso da `run.php`) prova la fase 3: clienti e storico delle assegnazioni, chiavi (formato, solo hash, scope, revoca, scadenza, cliente disattivato, limite al minuto), CORS (origini esatte e `*.`, preliminare `OPTIONS`, 403 dal browser), tutti gli endpoint con i loro errori, nessun dato interno nelle risposte, URL firmati (dimensione, foto e scadenza non modificabili), riduzioni a 320/640/1280/1920 e originale, cache su disco con ETag/304, foto da 24 MP con poca memoria (`503`), pulizia della cache, cambio di cliente, pannello, `bin/site-key.php` e import dei clienti dal finto Dolibarr 1.3.

`tests/servizi.php` (incluso da `run.php`) prova la fase 3b: funzioni dal piano e webcam archiviate, sovrimpressione (originale intatto, ETag, sponsor), punteggi, timelapse (con ffmpeg se c'è, e senza), stesso momento, foto del giorno, statistiche e beacon, registro e verifica della catena, rapporti e certificati (PDF letti con `pdftotext` se installato), invio al finto Dolibarr 1.4, bot Telegram con un finto server, tutti gli endpoint nuovi delle API dei siti, pannello e script di `bin/`.

```sh
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_NAME=webcam2_test TEST_DB_USER=webcam2 TEST_DB_PASS=... php tests/run.php
```

Su Windows: `set TEST_DB_PASS=...` e poi `C:\xampp\php\php.exe tests\run.php` (serve un database `webcam2_test` con gli stessi permessi di `webcam2`).

## Note

- `current.jpg` è una copia dell'ultima foto in `storage/photos/<id>/current.jpg`, con `current.json` accanto (id, SHA-256, ora) per `ETag` e `Last-Modified`. Una foto che arriva dalla coda offline, o con `current=0`, non sostituisce una più recente.
- `from_queue = 1` quando la foto arriva più di 15 minuti dopo lo scatto (il contratto non prevede un campo esplicito).
- Le medie orarie usano solo i campioni con `time_synced = true`; gli altri si contano in `samples_unsynced`.
- La retention delle foto cancella il file ma tiene il record in `photos` (`deleted_at`) con lo SHA-256, per le certificazioni; con il file vanno via le sue riduzioni.
- Gli URL delle immagini già dati ai siti valgono fino alla loro scadenza anche se la chiave viene revocata o la webcam diventa privata.
- Prossimi passi: miniature (`current-1280.jpg`), archivio pubblico per giorno, utenti dei clienti (pannello del cliente), interventi verso Dolibarr, pagina per le esclusioni dalla disponibilità (ora solo nella tabella `availability_exclusions`).
