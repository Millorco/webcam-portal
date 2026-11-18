# API del portal per i router: v1

Base: `https://<portal>/api/v1` (in anteprima su XAMPP: `http://localhost/webcam-portal/api/v1`). Solo HTTPS in produzione: il portal rifiuta HTTP con `403` salvo `APP_ENV=dev`.

| Metodo | Percorso | Chi | Cosa |
|---|---|---|---|
| `POST` | `/api/v1/photo` | router | foto + JSON `photo/1` (multipart) |
| `POST` | `/api/v1/telemetry` | router | lotto `telemetry/1` |
| `POST` | `/api/v1/event` | router | lotto `event/1` |
| `GET` | `/api/v1/whoami` | router, LuCI | prova del token ("Prova connessione") |
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

## Risposte

Sempre JSON, `Content-Type: application/json; charset=utf-8`:

```json
{ "ok": true, "server_time": 1795012231, ... }
{ "ok": false, "error": "sha256_mismatch", "message": "Lo SHA-256 del file ricevuto non corrisponde a meta.sha256", "server_time": 1795012231 }
```

`server_time` permette al router di accorgersi di un orologio sbagliato anche senza NTP (non lo usa per impostare l'ora: solo per un avviso nel log).

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

Interventi (`intervention`, `source = operator`) e avvisi del portal (`webcam.silent`: nessuna foto o telemetria da N minuti) li crea il portal, non il router.

## `GET /api/v1/whoami`

Con il token: `200 { "ok": true, "webcam": { "id": 12, "slug": "monte-cavo", "mac": "94:83:c4:12:ab:cd", "name": "Monte Cavo" }, "scopes": ["photo","telemetry","event"], "server_time": ... }`. Serve al pulsante "Prova connessione" della LuCI.

## `GET /cam/{slug}/current.jpg` (pubblico)

- Solo se `webcams.is_public = 1` e `status = 1`; altrimenti `404` (non `403`, per non rivelare quali webcam esistono).
- `Content-Type: image/jpeg`, `ETag: "<sha256>"`, `Last-Modified` = ora dello scatto, `Cache-Control: public, max-age=60`. Con `If-None-Match` uguale → `304`.
- Redirect assente: l'URL è stabile, adatto a Windy, siti dei clienti e `<img>` incorporati.
- `GET /cam/{slug}/current.json` (pubblico, stesse condizioni): `{ "slug", "name", "time", "datetime", "mode", "temperature_ext", "humidity_ext", "url": ".../current.jpg", "sha256" }` (niente MAC, IP, token o dati tecnici).
- In seguito (non in v1): `current-1280.jpg` e simili per le miniature, archivio pubblico per giorno, time-lapse.

## Compatibilità

- Nuove versioni del formato: `photo/2`, `telemetry/2`... Il portal accetta tutte le versioni che conosce. Il router manda la più recente che sa scrivere.
- Nuovi campi facoltativi non cambiano versione. Una versione nuova serve solo se un campo cambia significato, unità o diventa obbligatorio.
- Nuove versioni dell'API: `/api/v2/...` accanto a `/api/v1/...`; v1 resta attiva finché esistono router che la usano (`webcams.app_version`).
