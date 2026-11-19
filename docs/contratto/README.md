# Contratto dei dati di Webcam 2.0

Formati e API comuni alle repository del progetto Viewlapse (noleggio di webcam time-lapse con router OpenWrt e reflex Canon). Ogni repository implementa la sua parte **rispettando questi file**; una modifica al contratto si fa qui prima che nel codice.

**Versione del contratto: 1.3** (fase 3b, servizi che si vendono: sovrimpressione, timelapse, foto del giorno, rapporto mensile, giornate di maltempo certificate, statistiche, bot Telegram, listino e opzioni in Dolibarr). È compatibile con la 1.2, la 1.1 e la 1.0: aggiunge file, endpoint, campi e tabelle, senza cambiare il significato di nulla di quello che esiste. I formati restano `photo/1`, `telemetry/1`, `event/1`, `command/1`. Cosa cambia: [Cambiamenti della 1.3](#cambiamenti-della-13).

| File | Contenuto |
|---|---|
| [foto-v1.md](foto-v1.md), [foto-v1.schema.json](foto-v1.schema.json), [esempi/foto-v1.json](esempi/foto-v1.json) | JSON accanto a ogni foto (`photo/1`) |
| [telemetria-v1.md](telemetria-v1.md), [telemetria-v1.schema.json](telemetria-v1.schema.json), [esempi/telemetria-v1.json](esempi/telemetria-v1.json) | Misure ogni 5 minuti, inviate a lotti (`telemetry/1`) |
| [evento-v1.schema.json](evento-v1.schema.json), [esempi/evento-v1.json](esempi/evento-v1.json) | Eventi del router (`event/1`), descritti in api-portal-v1.md; dalla 1.1 anche `command.ack` ([esempi/evento-ack-v1.json](esempi/evento-ack-v1.json)) |
| [comando-v1.schema.json](comando-v1.schema.json), [esempi/comandi-v1.json](esempi/comandi-v1.json) | 1.1: comandi del portal al router (`command/1`) nelle risposte delle API |
| [api-portal-v1.md](api-portal-v1.md) | API del portal per i router e URL pubblico `current.jpg` (documento 1.1: comandi al router, `GET /api/v1/commands`; 1.2: sovrimpressione su `current.jpg`, webhook Telegram) |
| [api-dolibarr-v2.md](api-dolibarr-v2.md) | Estensioni del modulo Dolibarr `webcams` (1.2; documento 2.1 = modulo 1.3: soglie del contratto di noleggio, ticket per regola, disponibilità; documento 2.2 = modulo 1.4: listino, opzioni, **matrice livello → funzioni**, archivio dopo il noleggio, registro delle impronte, documenti con tipo ed email al cliente) |
| [diagnosi-v1.md](diagnosi-v1.md) | 1.1: regole di diagnosi (custodia, camera ferma, vetro, inquadratura, otturatore, log), analisi delle foto, impegno di disponibilità |
| [schema-mariadb.sql](schema-mariadb.sql) | Database del portal, migrazione 1 (MariaDB 11.8 sul VPS, 10.4+ in XAMPP) |
| [schema-mariadb-002.sql](schema-mariadb-002.sql) | 1.1: migrazione 2 (avvisi, stato delle regole, comandi, metriche e riferimenti delle foto, conteggi dei log, disponibilità) |
| [api-siti-v1.md](api-siti-v1.md) | 1.2: API in sola lettura per i siti (plugin WordPress "solo visualizzazione" e "portale clienti"): `GET /api/site/v1/...`, chiavi `vls_`, scope, CORS, URL firmati `/img/...`; documento 1.1 (contratto 1.3): endpoint dei servizi, `features`, beacon delle visualizzazioni, URL firmati `/media/...` |
| [schema-mariadb-003.sql](schema-mariadb-003.sql) | 1.2: migrazione 3 (campi dei clienti, storico delle assegnazioni webcam → cliente, chiavi dei siti) |
| [servizi-v1.md](servizi-v1.md) | 1.3: regole dei servizi: sovrimpressione, foto buone (O8), stesso momento, timelapse e filmato finale, foto del giorno, rapporto mensile e generatore PDF, registro delle impronte e giornate di maltempo, statistiche senza dati personali, bot Telegram, conteggi del logserver |
| [schema-mariadb-004.sql](schema-mariadb-004.sql) | 1.3: migrazione 4 (listino e archivio, sovrimpressione e sponsor, punteggi delle foto, timelapse, foto del giorno, rapporti, registro e certificati, statistiche, Telegram, conteggi dei log) |

## Componenti

```
                    HTTPS (Bearer per webcam)
 ┌────────────────┐  photo/1, telemetry/1, event/1   ┌─────────────────────┐   PDO   ┌──────────────┐
 │ luci-app-webcam├─────────────────────────────────►│ webcam-portal       ├────────►│ MariaDB      │
 │                │◄─ command/1 nelle risposte (1.1) ┤                     │         │              │
 │ router OpenWrt │                                  │ PHP 8.2, senza      │         │ db webcam2   │
 │ + board.ino    │  FTP/FTPS/SFTP (opzione, oggi)   │ framework           │         │ (separato)   │
 │ + Victron      ├──────────► server FTP            └──┬───────────────┬──┘         └──────────────┘
 └──┬──────┬───┬──┘                                     │ API webcams v2│ GET /cam/{slug}/current.jpg
    │      │   │ report (ogni 6 h, invariato)           ▼               ▼
    │      │   └──────────────────────────────►  dolibarr-webcams    pubblico, Windy, siti clienti
    │      │ syslog TCP 5140                     (modulo Dolibarr,
    │      └──────────► webcam-logserver         stesso MariaDB, altro db)
    │ WireGuard / rtty      (VictoriaLogs)
    └──────────► webcam-vpn, webcam-rttys (accesso remoto a LuCI e SSH)
```

## Chi produce e chi consuma

| Dato | Produce | Consuma | Trasporto |
|---|---|---|---|
| `photo/1` (JPEG + JSON) | luci-app-webcam `capture` | webcam-portal (`photos`), server FTP | `POST /api/v1/photo` o FTP |
| `telemetry/1` | luci-app-webcam (nuovi `telemetry_sample`, `telemetry_send`; `climate` scrive i campi in più) | webcam-portal (`measurements`, `measurements_hourly`, `camera_counters`) | `POST /api/v1/telemetry` |
| `event/1` | luci-app-webcam (`capture`, avvio, `climate`, aggiornamenti) | webcam-portal (`events`) | `POST /api/v1/event` |
| inventario (`report`) | luci-app-webcam `inventory_report` | dolibarr-webcams | `POST webcams/report` (invariato) |
| contatori, Victron sintetico, eventi importanti, ticket, interventi, PDF | webcam-portal | dolibarr-webcams | API `webcams` v2 |
| `command/1` (1.1) | webcam-portal (regole, operatori) | luci-app-webcam | campo `commands` nelle risposte di `/photo`, `/telemetry`, `/event`, `GET /api/v1/commands`; esito con l'evento `command.ack` |
| avvisi, ticket per regola, disponibilità mensile (1.1) | webcam-portal | dolibarr-webcams 1.3 | API `webcams` (documento 2.1) |
| soglie del contratto di noleggio, soglie dell'otturatore, link rttys (1.1) | dolibarr-webcams 1.3 | webcam-portal | `GET webcams/{id}/portal` |
| conteggi di riavvii ed errori (1.1) | webcam-logserver (VictoriaLogs) | webcam-portal | API HTTP `stats_query`, sola lettura |
| clienti, stato dei ticket | dolibarr-webcams / Dolibarr | webcam-portal | API Dolibarr (mai le tabelle); dalla 1.2 il cliente della webcam (`GET webcams/{id}/portal`) e il terzo (`GET thirdparties/{id}`) finiscono in `customers` |
| webcam, archivio foto, misure esterne, confronti (1.2) | webcam-portal | plugin WordPress (repository a parte) | `GET /api/site/v1/...` con chiave `vls_` per cliente, immagini con URL firmati `/img/...` ([api-siti-v1.md](api-siti-v1.md)) |
| livello di listino, opzioni, archivio dopo il noleggio, destinatari del rapporto (1.3) | dolibarr-webcams 1.4 | webcam-portal | `GET webcams/{id}/portal` (`plan`, `options`, `archives`) |
| rapporti mensili e certificati PDF, hash giornalieri del registro, stato del filmato finale (1.3) | webcam-portal | dolibarr-webcams 1.4 (che manda l'email al cliente) | `POST webcams/{id}/documents` con `send_email`, `POST webcams/{id}/register`, `PUT webcams/{id}/portal` |
| timelapse, foto del giorno, statistiche, rapporti, giornate di maltempo (1.3) | webcam-portal | plugin WordPress 1.1 | `GET /api/site/v1/webcams/{slug}/...`, file con URL firmati `/media/...` |
| visualizzazioni (1.3) | plugin WordPress (browser del visitatore) | webcam-portal | `POST /api/site/v1/views` (beacon, nessun dato personale) |
| conteggi giornalieri dei log per il rapporto (1.3) | webcam-logserver (VictoriaLogs) | webcam-portal | `stats_query` ([servizi-v1.md](servizi-v1.md#conteggi-del-logserver-per-il-rapporto-mensile)) |
| comandi e avvisi del bot (1.3) | clienti su Telegram | webcam-portal | API dei bot di Telegram (webhook o polling) |
| comandi seriali, misure dei sensori | board.ino | luci-app-webcam (`serial`) | UART (invariato; fw 2.4 aggiunge eventuali comandi) |
| log testuali | router (syslog) | webcam-logserver | syslog TCP (invariato) |
| accesso remoto | — | operatori | webcam-vpn, webcam-rttys (invariati, nessun formato dati) |

Il log (VictoriaLogs) resta per leggere cosa è successo riga per riga; il database del portal contiene i dati strutturati. Gli eventi importanti finiscono in entrambi: il router continua a scrivere la riga di log come oggi e in più manda l'evento.

## Identità della webcam

- **MAC** della prima interfaccia fisica (`get_mac` di `inventory_report`), minuscolo con `:`. È la chiave comune: `llx_webcams_webcam.mac`, `webcams.mac` del portal, campo `mac` di ogni JSON.
- **Token per webcam** per il portal (`wct_…`, solo l'hash nel database). La chiave API Dolibarr dell'utente `webcam-router` resta per `report`.
- **Chiave per sito** (1.2): `vls_…` per cliente, solo l'hash nel database, con scope `public`/`client` e origini ammesse ([api-siti-v1.md](api-siti-v1.md)). Il cliente è il soggetto terzo di Dolibarr (`customers.dolibarr_socid`).
- **slug** per gli URL pubblici (`/cam/monte-cavo/current.jpg`), scelto nel portal.
- La **reflex** si identifica a parte con modello + numero di serie (tabella `cameras`), perché passa da una webcam all'altra tra un noleggio e l'altro.

## Convenzioni

- Ore nei JSON: secondi Unix UTC (`time`), come già fa `capture`. Nel database: `DATETIME` UTC.
- Ogni dato temporale ha il flag `time_synced` (NTP): obbligatorio, perché il router non ha orologio a batteria. Solo i dati con `time_synced = true` valgono per statistiche certificate.
- Unità SI: °C, %, g/m³, V, A, W, Wh, byte, secondi. Niente °F nei dati (solo la LuCI li mostra).
- Valori mancanti: `null` o campo assente, mai `""` o `0` fittizi.
- Nomi dei campi in inglese `snake_case`, come quelli esistenti (`temperature_ext`, `last_capture_time`); documentazione in italiano.
- Campi sconosciuti sono ammessi e conservati (`additionalProperties: true`): un router più nuovo non rompe un portal più vecchio.

## Versionamento

- Ogni JSON ha `"schema": "<tipo>/<versione>"`: `photo/1`, `telemetry/1`, `event/1`. Il JSON delle foto di luci-app-webcam 1.0-rc8, senza `schema`, è `photo/0` ed è ancora accettato.
- **Stessa versione**: si possono aggiungere campi facoltativi.
- **Versione nuova** (`photo/2`): solo se un campo cambia significato o unità, sparisce o diventa obbligatorio. Il portal accetta tutte le versioni che conosce per almeno 12 mesi; il router scrive la più recente.
- API: percorso versionato (`/api/v1/`). Il modulo Dolibarr non cambia percorso: `report` resta identico, gli endpoint nuovi si aggiungono (contratto Dolibarr v2 = modulo 1.2).
- Database: tabella `schema_migrations`; ogni modifica è un file di migrazione numerato nel portal. Il contratto ne tiene una copia: `schema-mariadb.sql` = migrazione 1, `schema-mariadb-002.sql` = migrazione 2, `schema-mariadb-003.sql` = migrazione 3, `schema-mariadb-004.sql` = migrazione 4.
- API dei siti: percorso versionato a parte (`/api/site/v1/`), indipendente da quello dei router.
- Contratto: versione `1.x` finché le aggiunte sono compatibili (1.1 = fase 2, 1.2 = fase 3, 1.3 = fase 3b). I documenti riportano in testa la loro versione quando cambia.
- Gli schemi JSON hanno `$id` sotto `https://viewlapse.com/schemas/contratto/`: è un identificatore, non serve che l'URL sia raggiungibile.

## Versioni previste

Fase 1 (contratto 1.0):

| Repository | Versione | Cosa cambia |
|---|---|---|
| luci-app-webcam | 1.1.0 | `photo/1`, telemetria, eventi, upload HTTPS al portal (FTP resta), opzioni `portal_url`/`portal_token`, contatori reflex, lettura Victron, `report` con campi nuovi |
| board.ino | fw 2.4 | se serve: comando per la causa dell'ultimo reset / ciclo di alimentazione, stato dei relè |
| dolibarr-webcams | 1.2 | colonne nuove, permesso `portal`, endpoint v2 |
| webcam-portal | 0.1 | ricezione API v1, database, `current.jpg`, pannello minimo |
| webcam-logserver, webcam-vpn, webcam-rttys | — | nessun cambio di formato; al più il portal accanto agli altri container |

Fase 2 (contratto 1.1, assistenza automatica):

| Repository | Versione | Cosa cambia |
|---|---|---|
| luci-app-webcam | 1.2.0 | campi di diagnosi in `climate` (`heater_on_since`, `heater_duty_pct`, `heat_reason`, `heater_boost_until`, `dewpoint_margin`) e in `camera` (`scheduled`, `interval_min`); esecuzione dei comandi `command/1` dalle risposte del portal, `GET /api/v1/commands` all'avvio e con `poll_after`, evento `command.ack`, intestazione `X-Webcam-Features: commands/1`, opzione `commands`; boost del riscaldamento in `climate` |
| dolibarr-webcams | 1.3 | campi `sla_*` e `availability_*` sulla webcam, tabella `llx_webcams_availability`, `GET {id}/portal`, `POST {id}/tickets` con `portal_rule` (un ticket aperto per webcam e regola), `PUT {id}/tickets/{ticket_id}`, `POST`/`GET {id}/availability` |
| webcam-portal | 0.2 | migrazione 002, regole di [diagnosi-v1.md](diagnosi-v1.md), analisi delle foto con GD, comandi al router, lettura dei log da VictoriaLogs, disponibilità giornaliera e mensile (pannello operatore e cliente), sincronizzazione di ticket e disponibilità con Dolibarr |
| board.ino | — | nessun cambio necessario; un firmware futuro con tachimetro della ventola attiverebbe `fan_rpm` e la regola `enclosure.fan` |
| webcam-logserver | — | nessun cambio: il portal usa le query di `docs/query.md` |

Fase 3 (contratto 1.2, siti dei clienti in WordPress):

| Repository | Versione | Cosa cambia |
|---|---|---|
| webcam-portal | 0.3 | migrazione 003, clienti importati da Dolibarr con lo storico delle assegnazioni, chiavi dei siti (pannello e `bin/site-key.php`), `GET /api/site/v1/...`, immagini ridotte con URL firmati e cache su disco |
| plugin WordPress (repository nuova) | 1.0 | plugin "solo visualizzazione" per siti esterni e "portale clienti" per WordPress Multisite, client di [api-siti-v1.md](api-siti-v1.md) |
| dolibarr-webcams | — | nessun cambio: il cliente arriva già da `GET webcams/{id}/portal` (1.3) |

Fase 3b (contratto 1.3, servizi che si vendono):

| Repository | Versione | Cosa cambia |
|---|---|---|
| webcam-portal | 0.4.0 | migrazione 004; funzioni dal listino (`Features`); sovrimpressione con GD su riduzioni, `current.jpg` e formati social; punteggio delle foto buone; stesso momento; timelapse con ffmpeg facoltativo; foto del giorno; rapporto mensile e certificato con il generatore PDF del portal; registro giornaliero delle impronte con deposito in Dolibarr; statistiche e beacon; bot Telegram (webhook o polling); conteggi dei log per il rapporto; endpoint 1.1 di [api-siti-v1.md](api-siti-v1.md) e `/media/...` |
| dolibarr-webcams | 1.4 | campi del listino e delle opzioni, tabelle `llx_webcams_archive` e `llx_webcams_register`, `GET {id}/portal` con `plan`/`options`/`archives`, `PUT {id}/portal` con il filmato, `POST {id}/documents` con `doc_type` ed email al cliente con i modelli di Dolibarr, `POST`/`GET {id}/register`, matrice delle funzioni sulla scheda ([api-dolibarr-v2.md](api-dolibarr-v2.md#listino-e-servizi-modulo-14)) |
| plugin WordPress | 1.1.0 | mostra solo le funzioni in `features`: stesso momento, timelapse (anche verticali), foto del giorno con i formati per i social, sponsor e infopoint cliccabili; nel portale clienti statistiche, rapporti, giornate di maltempo e certificati; beacon delle visualizzazioni senza cookie |
| webcam-logserver | — | nessun cambio di configurazione; `docs/query.md` aggiunge le query dei conteggi per il rapporto mensile e allinea la "chiamata unica" al filtro degli errori del contratto |
| luci-app-webcam, board.ino | — | nessun cambio |

## Cambiamenti della 1.3

Tutto si aggiunge; niente cambia significato.

- **Nuovi file**: [servizi-v1.md](servizi-v1.md) (regole dei servizi) e [schema-mariadb-004.sql](schema-mariadb-004.sql) (migrazione 4).
- **Listino** (M4): livello `base`/`standard`/`pro` e opzioni (sponsor M12, certificato maltempo O2, filmato finale M10, rapporto per email O3) sulla webcam in Dolibarr; archivio dopo il noleggio (M13) per cliente in `llx_webcams_archive`. **Matrice livello → funzioni** in [api-dolibarr-v2.md](api-dolibarr-v2.md#matrice-livello--funzioni), applicata solo dal portal; i plugin leggono `features`.
- **Dolibarr 2.2 = modulo 1.4**: `GET {id}/portal` con `plan`, `options`, `archives`; `PUT {id}/portal` con `film_status`/`film_url`; `POST {id}/documents` con `doc_type`, `period`, `portal_doc_id` e `send_email` (**l'email al cliente la manda Dolibarr** con i suoi modelli email); `POST`/`GET {id}/register` (deposito immutabile dell'hash giornaliero del registro).
- **API dei siti 1.1**: `same-moment`, `timelapses`, `photo-of-day`, `photos-of-day`, `overlay-items`, e solo per lo scope `client` `stats`, `reports`, `weather-days`, `certificates`; `POST views` (beacon, unica scrittura, nessun dato personale); URL firmati `/media/...` (video con `Range`, PDF, elenchi); formati social `sq1080`, `p1350`, `s1920`; webcam `archived`; errore `403 feature_unavailable`.
- **API dei router 1.2**: nessun cambio per i router; `current.jpg` con sovrimpressione facoltativa (l'originale resta intatto), webhook del bot Telegram.
- **Registro delle impronte** (O2): un elenco `vlreg1` al giorno per webcam con gli SHA-256 delle foto sincronizzate, hash del giorno a catena, depositato in Dolibarr il giorno dopo; classificazione meteo da umidità, punto di rugiada, temperatura e foto, con limiti dichiarati (nessun pluviometro).
- **Conteggi dei log** (O3): due query giornaliere in più per webcam-logserver, nuove colonne in `log_counters`. Il filtro degli errori della fotocamera è quello delle righe finali (già in diagnosi-v1.md 1.1).
- **Punti aperti** della 1.3 (pubblicazione automatica sui social, scatto su richiesta da Telegram, pluviometro, dati meteo esterni, soglie del maltempo da concordare): [servizi-v1.md](servizi-v1.md#limiti-e-punti-aperti).

## Punti da decidere

1. **Victron**: quale prodotto (solo MPPT SmartSolar, o anche BMV/SmartShunt)? Con il solo MPPT la percentuale di carica (`soc_pct`) non esiste: si mostra la tensione e la fase di carica, o si stima la percentuale dalla tensione? E come si collega al GL-MT300N-V2, che ha una sola UART libera già usata dalla scheda: cavo VE.Direct-USB sulla porta USB (che serve alla fotocamera, quindi con hub) o lettura dalla scheda (board.ino) su una seconda seriale software?
2. **Modem 4G**: è un modem USB sul router (allora `rssi`/`rsrp` si leggono con `uqmi`/`mmcli`) o un router 4G separato collegato via cavo (allora i dati radio vanno letti dalla sua API, o restano vuoti)?
3. **Contatore degli scatti**: le EOS 2000D/1500D di solito **non** espongono `shuttercounter` via gphoto2. Va bene il contatore del router (`shots_total`, riparte se si cambia fotocamera) con un valore iniziale inserito a mano letto con un programma da PC?
4. **Nomi dei campi Dolibarr**: nel contratto `windy_published`, `url_current`, `camera_hours`... in inglese come le colonne esistenti, con etichette italiane in LuCI/Dolibarr. Confermare o preferire nomi italiani (`pubblicata_su_windy`).
5. **Retention**: misure grezze 12 mesi e medie orarie per sempre, come proposto. Foto: per quanto si tengono i JPEG originali sul VPS (spazio disco: ~150 foto/giorno × 8 MB ≈ 1,2 GB/giorno per webcam)? Serve una politica (es. originali 90 giorni, poi una foto l'ora o ridotte).
6. **Dove gira il portal**: dominio (`portal.viewlapse.com`?) e se va dietro Nginx Proxy Manager come rttys.
7. **Ticket o interventi**: per i guasti si usa il modulo Ticket, gli Interventi, o entrambi (ticket per il guasto, intervento per l'uscita)? Sono attivi in Dolibarr?
8. **Destinazione delle foto**: con il portal attivo, l'FTP resta come seconda copia (doppio upload in 4G) o diventa un'alternativa (una sola destinazione per webcam)? Il contratto permette entrambe le scelte.
9. **Registrazione delle webcam**: proposto che la webcam venga creata nel portal da un operatore (o importata da Dolibarr per MAC) e che il router riceva il token a mano in LuCI. In alternativa, registrazione automatica con un codice di attivazione monouso.
10. **Dolibarr 20+**: confermare la versione installata sul VPS (il modulo chiede 20 o successiva).
