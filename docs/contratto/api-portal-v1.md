# API del portal per i router: v1

Versione del documento: **1.1** (contratto 1.1, compatibile con 1.0: si aggiungono il canale dei [comandi al router](#comandi-al-router), l'endpoint `GET /api/v1/commands`, l'evento `command.ack` e gli eventi degli avvisi del portal). Il percorso resta `/api/v1/`.

Base: `https://<portal>/api/v1` (in anteprima su XAMPP: `http://localhost/webcam-portal/api/v1`). Solo HTTPS in produzione: il portal rifiuta HTTP con `403` salvo `APP_ENV=dev`.

| Metodo | Percorso | Chi | Cosa |
|---|---|---|---|
| `POST` | `/api/v1/photo` | router | foto + JSON `photo/1` (multipart) |
| `POST` | `/api/v1/telemetry` | router | lotto `telemetry/1` |
| `POST` | `/api/v1/event` | router | lotto `event/1` |
| `GET` | `/api/v1/whoami` | router, LuCI | prova del token ("Prova connessione") |
| `GET` | `/api/v1/commands` | router | comandi in attesa (1.1, [Comandi al router](#comandi-al-router)) |
| `GET` | `/cam/{slug}/current.jpg` | pubblico | ultima foto |
| `GET` | `/cam/{slug}/current.json` | pubblico | dati pubblici dell'ultima foto |

## Autenticazione

```
Authorization: Bearer wct_Obycxu0Nk92mqIyjOZupDmsKcwweRudI3Pg85IZVi0g
```

- Un token **per webcam**, generato dal portal (32 byte casuali in base64url, prefisso `wct_`: 47 caratteri). Il portal salva solo `sha256(token)` (tabella `tokens`) e mostra il token una volta sola.
- Sul router: nuove opzioni UCI `webcam.@server[0].portal_url` e `portal_token` (stesso stile di `inventory_url`/`inventory_token`).
- Il token identifica la webcam. Il `mac` nel corpo **deve** essere quello della webcam del token, altrimenti `409`. Una webcam con `status = 9` (ritirata) o un token revocato/scaduto → `403`.
- La prima volta: l'operatore crea la webcam nel portal (o il portal la importa da Dolibarr per MAC) e genera il token. Il router non si registra da solo con il token: evita che un token rubato crei webcam.
- `User-Agent: luci-app-webcam/<versione>` (oggi `inventory_report` manda `luci-app-webcam`).
- `X-Webcam-Features: commands/1` (1.1): il router sa eseguire i comandi `command/1`. Il portal mette `commands` nelle risposte **solo** alle richieste con questa intestazione e solo se il token ha lo scope `commands`; un router 1.1.0 non la manda e non riceve comandi. Più funzioni si separano con la virgola.

## Risposte

Sempre JSON, `Content-Type: application/json; charset=utf-8`:

```json
{ "ok": true, "server_time": 1795012231, ... }
{ "ok": false, "error": "sha256_mismatch", "message": "Lo SHA-256 del file ricevuto non corrisponde a meta.sha256", "server_time": 1795012231 }
```

`server_time` permette al router di accorgersi di un orologio sbagliato anche senza NTP (non lo usa per impostare l'ora: solo per un avviso nel log e per la scadenza dei comandi).

Dal 1.1 le risposte di successo (`200`/`201`) di `/photo`, `/telemetry`, `/event` e `/commands` possono contenere `commands` (comandi in attesa) e `poll_after` (vedi [Comandi al router](#comandi-al-router)). Un router che non li conosce li ignora.

| Codice | Significato | Cosa fa il router |
|---|---|---|
| `200` | già ricevuto (duplicato), o lotto accettato | toglie dalla coda |
| `201` | creato | toglie dalla coda |
| `400` | JSON o multipart malformato, campo obbligatorio mancante | **non** riprova: sposta in `rejected/` (foto) o scarta (campioni), log `ERRORE` |
| `401` | token mancante o sconosciuto | tiene in coda, log `ERRORE` (configurazione) |
| `403` | token revocato/scaduto, webcam ritirata, HTTP senza TLS | tiene in coda, log `ERRORE` |
| `409` | `mac` diverso da quello del token | tiene in coda, log `ERRORE` |
| `413` | oltre i limiti di dimensione | non riprova (foto in `rejected/`); per i lotti dimezza e riprova |
| `415` | tipo di contenuto sbagliato | non riprova, `rejected/` |
| `422` | valori non validi (SHA-256 diverso, `schema` non supportato, ora fuori finestra) | foto: un solo nuovo tentativo, poi `rejected/` |
| `429` | troppe richieste; c'è `Retry-After` (secondi) | riprova dopo `Retry-After` |
| `500`, `502`, `503`, `504`, timeout | problema del server o della rete | riprova al giro dopo |

`rejected/` è una sottocartella della coda, limitata a 20 file (si cancellano i più vecchi), da guardare via LuCI o SSH.

## Limiti

| Cosa | Limite | Note |
|---|---|---|
| JPEG | 25 MB | configurabile (`PHOTO_MAX_BYTES`); un JPEG L/fine di una EOS 2000D sta sotto i 12 MB. In XAMPP alzare `upload_max_filesize` e `post_max_size` in `php.ini`. |
| JSON della foto (`meta`) | 64 KB | |
| Corpo di `/telemetry` | 1 MB decompresso, 288 campioni | 288 = 24 ore a 5 minuti |
| Corpo di `/event` | 256 KB, 100 eventi | |
| `Content-Encoding: gzip` | accettato su `/telemetry` e `/event` | facoltativo (`gzip -c` di busybox); il limite vale dopo la decompressione |
| Frequenza | 120 richieste/minuto per token | oltre: `429` |
| Ora dei dati | da `now - 400 giorni` a `now + 10 minuti` | fuori finestra: la foto `422`, il campione finisce in `rejected` della risposta. I dati più vecchi della retention di `measurements` non si possono salvare (partizione cancellata). |

## `POST /api/v1/photo`

`multipart/form-data` con due parti:

| Parte | Tipo | Obbl. | Contenuto |
|---|---|---|---|
| `photo` | `image/jpeg` | sì | il JPEG; il nome del file è ignorato (conta `meta.file`) |
| `meta` | `application/json` | sì | il JSON `photo/1` (o `photo/0`) scritto da `capture` |
| `current` | testo `0`/`1` | no | `1` (predefinito) = aggiorna `current.jpg` se la foto è più recente dell'attuale. Una foto che arriva dalla coda offline non sostituisce una più recente. |

Esempio dal router:

```sh
curl -sS -m 120 --retry 2 \
  -H "Authorization: Bearer $TOKEN" -H "User-Agent: luci-app-webcam/$VER" \
  -F "photo=@raw_20261118-1530.jpg;type=image/jpeg" \
  -F "meta=@raw_20261118-1530.json;type=application/json" \
  -w '\n%{http_code}' "$PORTAL_URL/api/v1/photo"
```

Il portal:

1. verifica token, `mac`, dimensioni e tipo (i primi byte devono essere `FF D8 FF`);
2. valida `meta` con [foto-v1.schema.json](foto-v1.schema.json) (o lo legge come `photo/0`);
3. calcola lo SHA-256 del file; con `photo/1` deve essere uguale a `meta.sha256` (`422 sha256_mismatch`);
4. **idempotenza**: se `(webcam, sha256)` esiste già → `200` con `"duplicate": true` e lo stesso `photo_id`;
5. salva in `storage/photos/<webcam_id>/AAAA/MM/GG/<file>` (data di `time` nel fuso della webcam), legge l'EXIF, inserisce in `photos`, aggiorna `webcams.last_photo_*` e `current.jpg`.

```json
{ "ok": true, "photo_id": 918273, "duplicate": false, "sha256": "75b70e70...", "current": true, "server_time": 1795012231 }
```

`201` alla prima ricezione, `200` per un duplicato.

## `POST /api/v1/telemetry`

Corpo: lotto [telemetry/1](telemetria-v1.md), `Content-Type: application/json`.

Il portal valida il lotto, poi **ogni campione** per conto suo: un campione non valido non fa rifiutare gli altri. Inserisce con `INSERT IGNORE` su chiave `(webcam_id, ts, sample_id)`: un campione già ricevuto conta come `duplicates`.

```json
{
  "ok": true,
  "accepted": 5,
  "duplicates": 1,
  "rejected": [ { "id": "3f2a9c1e-1503", "error": "time_out_of_window" } ],
  "server_time": 1795012231
}
```

- Risposta `200` anche con campioni rifiutati: il router **toglie dalla coda tutti gli `id` del lotto** (accettati, duplicati e rifiutati: un rifiutato non migliora riprovando).
- `400` solo se il lotto intero è illeggibile (JSON rotto, `schema` diverso, `samples` mancante).
- Dopo l'inserimento il portal ricalcola `measurements_hourly` per le ore toccate, calcola `rx_delta`/`tx_delta`, salva i contatori in `camera_counters` se sono cambiati e aggiorna `webcams.last_telemetry_at`.
- Eventi generati dal portal (non dal router): `boot_id` cambiato → `boot` se il router non l'ha già mandato; `app_version`/`board_fw` cambiati → `app.version_change`/`board.version_change`; batteria sotto soglia → `power.low_battery`.

## `POST /api/v1/event`

Corpo: lotto `event/1` ([evento-v1.schema.json](evento-v1.schema.json), esempio [esempi/evento-v1.json](esempi/evento-v1.json)). Stessa logica di `/telemetry`: idempotenza su `(webcam_id, event_uid)`, risposta con `accepted`, `duplicates`, `rejected`.

Gli eventi si mandano **subito** (non aspettano i 30 minuti) e vanno in coda se manca la rete. Tipi definiti in v1 (un tipo sconosciuto è accettato e salvato così com'è):

| `type` | `severity` | Quando | `data` |
|---|---|---|---|
| `boot` | notice | avvio del router | `board_reset_cause`, `previous_uptime` |
| `board.power_cycle` | warning | la scheda ha tolto corrente per mancanza di heartbeat (dal log della scheda) | `reason` |
| `camera.power_cycle` | warning | `capture` ha riavviato la fotocamera dal relè | `file`, `gphoto2` |
| `camera.error` | error | scatto fallito anche dopo il riavvio | `file`, `reason`, `gphoto2` |
| `camera.missing` | error | fotocamera non vista su USB | |
| `camera.change` | notice | modello o numero di serie diverso | `from`, `to` |
| `upload.error` | warning | upload FTP fallito (solo con destinazione FTP) | `reason`, `queued` |
| `queue.dropped` | error | foto cancellate dalla coda piena | `count` |
| `telemetry.dropped` | warning | campioni scartati dalla coda piena | `count` |
| `sensor.error` | error | SHT31 interno o esterno non risponde | `sensor`: `int`/`ext` |
| `power.error` | error | Victron: `ERR` ≠ 0 o nessuna risposta | `error`, `charge_state` |
| `app.version_change`, `board.version_change` | info | aggiornamento | `from`, `to` |
| `config.change` | info | configurazione UCI cambiata dalla LuCI | `section`, `keys` (mai password né token) |
| `maintenance.on`, `maintenance.off` | notice | modalità manutenzione | |
| `ntp.synced` | debug | prima sincronizzazione dopo l'avvio | `offset_s` |

Tipi aggiunti nella versione 1.1 del documento (i primi quattro il router li manda già dalla 1.1.0 di luci-app-webcam):

| `type` | `severity` | Quando | `data` |
|---|---|---|---|
| `heating.on`, `heating.off` | info | `climate` accende o spegne il riscaldamento | `temperature`, `humidity`, `dewpoint`, `temperature_ext` |
| `sensor.ok` | notice | un sensore SHT31 torna a rispondere | `sensor` |
| `service.start` | info | `capture_service` parte | |
| `command.ack` | info; warning se non `done` | esito di un comando del portal | `command_id`, `type`, `status`, `reason`, `result` ([comando-v1.schema.json](comando-v1.schema.json), `$defs/ack`; esempio [esempi/evento-ack-v1.json](esempi/evento-ack-v1.json)) |

Eventi creati dal portal, non dal router (`source = portal` o `operator`): interventi (`intervention`) e gli avvisi delle regole di diagnosi, `alert.open`, `alert.update`, `alert.close` con `data.rule` e `data.alert_id` ([diagnosi-v1.md](diagnosi-v1.md)). L'avviso `webcam.silent` previsto nella prima versione è sostituito dalle regole `telemetry.stalled` e `camera.stalled`.

## `GET /api/v1/whoami`

Con il token: `200 { "ok": true, "webcam": { "id": 12, "slug": "monte-cavo", "mac": "94:83:c4:12:ab:cd", "name": "Monte Cavo" }, "scopes": ["photo","telemetry","event","commands"], "server_time": ... }`. Serve al pulsante "Prova connessione" della LuCI. Dal 1.1 `scopes` può contenere `commands`.

## Comandi al router

Il router sta dietro il 4G (CGNAT) e non è raggiungibile dal portal: **è sempre il router a chiedere**. I comandi viaggiano quindi nelle risposte alle richieste che il router fa già, senza connessioni in più nel caso normale.

| Dove | Quando arriva | Ritardo tipico |
|---|---|---|
| risposta di `POST /api/v1/photo` | a ogni foto | intervallo di scatto (5-15 min) |
| risposta di `POST /api/v1/telemetry` e `/event` | ogni 30 minuti, e subito per gli eventi | fino a 30 min |
| `GET /api/v1/commands` | all'avvio del router, dopo `poll_after` secondi se la risposta precedente lo chiede, dal pulsante della LuCI | — |

Di notte e in manutenzione le foto possono mancare: restano telemetria ed eventi. Il router **non** interroga `/commands` di continuo (ogni richiesta TLS in 4G costa qualche KB); lo fa solo quando il portal lo chiede con `poll_after` (es. 60 s mentre un boost è in corso, per avere subito la conferma).

### Formato

Ogni comando è un oggetto `command/1` ([comando-v1.schema.json](comando-v1.schema.json), esempio [esempi/comandi-v1.json](esempi/comandi-v1.json)):

```json
{ "ok": true, "accepted": 6, "duplicates": 0, "rejected": [], "server_time": 1795012231,
  "commands": [
    { "schema": "command/1", "id": "cmd-4410", "type": "heater.boost",
      "created_at": 1795012200, "expires_at": 1795014000,
      "params": { "duration_s": 1800 }, "reason": "glass.fog" }
  ],
  "poll_after": 300 }
```

| Campo | Significato |
|---|---|
| `id` | `cmd-<numero>`: chiave di idempotenza |
| `type` | uno dei tipi della lista chiusa sotto |
| `created_at`, `expires_at` | ora del portal; scadenza al massimo 24 ore dopo |
| `params` | parametri del tipo, validati da entrambi i lati |
| `reason` | regola o nota dell'operatore, per il log del router |

### Tipi (lista chiusa)

| `type` | `params` | Cosa fa il router | Rifiuta con `reason` |
|---|---|---|---|
| `heater.boost` | `duration_s` 300-3600 | scrive `/tmp/webcam/heater_boost_until`; `climate` tiene acceso il riscaldamento (e la ventola se `heat_fan = 1`) fino a quell'ora, poi torna alla logica normale. Lo scatto lo spegne come sempre per il tempo della foto | `heating_disabled` se `heating = 0` (forse non c'è la resistenza); `protection` se lo impediscono le protezioni del riscaldamento del router |
| `heater.cancel` | nessuno | toglie il boost; `result` = `{ "was_active": true/false }` | |
| `capture.now` | `upload` (booleano, predefinito `true`) | come il pulsante "Scatta ora" (`capture_now`), anche in manutenzione | `busy` se c'è uno scatto in corso; `rate_limited` se l'ultimo `capture.now` è di meno di 10 minuti fa |
| `telemetry.flush` | nessuno | `telemetry_sample` e `telemetry_send` subito | `busy` se un invio è già in corso |
| `config.set` | `key`, `value` (stringa) | `uci set webcam.@<sezione>[0].<opzione>=<valore>`, `uci commit`, evento `config.change` come dalla LuCI | `not_allowed` per una chiave fuori lista, `invalid_params` per un valore fuori intervallo |

Chiavi ammesse da `config.set` (lista scritta nel codice del router, non presa dal portal):

| `key` | Valori |
|---|---|
| `general.heating`, `general.heat_fan`, `general.maintenance_mode` | `0`, `1` |
| `general.heat_dewpoint_margin` | 1-10 (°C, un decimale) |
| `general.heat_hysteresis` | 0-5 (°C, un decimale) |
| `general.heat_min_temp` | da -10 a 15 (°C, un decimale) |
| `shooting.photo_time_lapse` | 1-60 (minuti) |

Fuori dalla lista, sempre: credenziali, token, URL (`portal_url`, `upload_server`, `update_url`...), porte seriali, `telemetry` (spegnerla taglierebbe il canale dei comandi), comandi di shell, riavvii, aggiornamenti. Per tutto il resto c'è l'accesso remoto con rttys.

### Esecuzione e conferma

1. Il router esegue i comandi nell'ordine ricevuto, **uno alla volta**, in background (la richiesta che li ha portati è già chiusa).
2. Scadenza: un comando con `expires_at` passato (ora del router se sincronizzata, altrimenti `server_time` della risposta) **non** si esegue: `command.ack` con `status = expired`.
3. Idempotenza: il router tiene gli `id` eseguiti negli ultimi 2 giorni (`/tmp/webcam/commands_done`, con l'esito). Un comando ricevuto di nuovo non si riesegue: si rimanda lo stesso `command.ack`. Dopo un riavvio la lista è vuota: rieseguire un `heater.boost`, `capture.now`, `config.set` o `telemetry.flush` non fa danni.
4. Conferma: evento `command.ack` (`POST /api/v1/event`, subito, in coda se manca la rete) con `data.command_id`, `type`, `status` (`done`, `rejected`, `failed`, `expired`), `reason`, `result` (es. `{ "until": 1795014090 }` per il boost, `{ "file": "raw_20261118-1530.jpg" }` per lo scatto, `{ "key", "old", "new" }` per `config.set`, `{ "was_active": true }` per `heater.cancel`). Anche la riga di log come per tutte le azioni.
5. Il portal rimanda un comando in ogni risposta finché non riceve il `command.ack` o il comando scade (`commands.status`: `pending` → `delivered` → `done`/`rejected`/`failed`/`expired`). Al massimo 5 comandi per risposta, dal più vecchio.

### Sicurezza

- I comandi arrivano solo in risposte HTTPS autenticate con il token della webcam; nessuna porta aperta sul router.
- **Lista chiusa** di tipi e di chiavi, con intervalli, controllata dal router: un portal compromesso può al più accendere il riscaldamento, scattare una foto o cambiare queste poche opzioni.
- Scope `commands` del token: senza, il portal non manda comandi a quella webcam. Il router può spegnerli con l'opzione UCI `webcam.@server[0].commands = 0` (nessuna intestazione `X-Webcam-Features`).
- Chi crea i comandi: le regole del portal ([diagnosi-v1.md](diagnosi-v1.md#comandi-mandati-dalle-regole)) e gli operatori/amministratori dal pannello (`commands.user_id`); mai gli utenti dei clienti.
- Limiti del portal: al massimo 10 comandi creati per webcam all'ora, 4 `heater.boost` al giorno dalle regole.

### `GET /api/v1/commands`

Con il token e `X-Webcam-Features: commands/1`: `200` con `ok`, `server_time`, `commands` (anche vuoto) e `poll_after` facoltativo. Senza l'intestazione o senza scope `commands`: `200` con `commands: []`. Conta nel limite di 120 richieste al minuto.

## `GET /cam/{slug}/current.jpg` (pubblico)

- Solo se `webcams.is_public = 1` e `status = 1`; altrimenti `404` (non `403`, per non rivelare quali webcam esistono).
- `Content-Type: image/jpeg`, `ETag: "<sha256>"`, `Last-Modified` = ora dello scatto, `Cache-Control: public, max-age=60`. Con `If-None-Match` uguale → `304`.
- Redirect assente: l'URL è stabile, adatto a Windy, siti dei clienti e `<img>` incorporati.
- `GET /cam/{slug}/current.json` (pubblico, stesse condizioni): `{ "slug", "name", "time", "datetime", "mode", "temperature_ext", "humidity_ext", "url": ".../current.jpg", "sha256" }` (niente MAC, IP, token o dati tecnici).
- Miniature e archivio per giorno: dal contratto 1.2 nell'API dei siti ([api-siti-v1.md](api-siti-v1.md), `/api/site/v1/...` e `/img/...`). Time-lapse: in seguito.

## Compatibilità

- Nuove versioni del formato: `photo/2`, `telemetry/2`... Il portal accetta tutte le versioni che conosce. Il router manda la più recente che sa scrivere.
- Nuovi campi facoltativi non cambiano versione. Una versione nuova serve solo se un campo cambia significato, unità o diventa obbligatorio.
- Versione 1.1 del documento: tutto quello che aggiunge è facoltativo. Un router 1.1.0 (senza `X-Webcam-Features`) e un portal 0.1 (senza `commands` nelle risposte) continuano a funzionare insieme alle versioni nuove in tutte le combinazioni.
- Nuove versioni dell'API: `/api/v2/...` accanto a `/api/v1/...`; v1 resta attiva finché esistono router che la usano (`webcams.app_version`).
