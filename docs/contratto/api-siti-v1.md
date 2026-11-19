# API del portal per i siti: v1

Versione del documento: **1.0** (contratto 1.2, fase 3). API **in sola lettura** con cui i siti dei clienti mostrano webcam, archivio, misure e confronti. Le foto restano sul portal: i siti le mostrano soltanto, con URL firmati.

Chi la usa:

| Plugin WordPress | Dove | Chiave |
|---|---|---|
| **solo visualizzazione** | qualsiasi sito WordPress esterno (hotel, comune...) | nessuna (webcam pubbliche) o chiave `public` del cliente; dal browser con le origini della chiave, o dal server |
| **portale clienti** | WordPress Multisite, un sottosito per cliente, con utenti e login | chiave `client` del cliente, **solo dal server** WordPress (mai nel JavaScript delle pagine) |

Il portal resta il motore e il pannello amministrativo: clienti, webcam assegnate e chiavi si gestiscono lì (pannello `/admin/customers` o `bin/site-key.php`). Gli utenti di WordPress non esistono nel portal: il plugin "portale clienti" decide chi vede cosa nel sottosito; il portal vede solo la chiave del cliente.

Base: `https://<portal>/api/site/v1` (anteprima XAMPP: `http://localhost/webcam-portal/api/site/v1`). Solo HTTPS in produzione (`403 https_required` su HTTP, salvo `APP_ENV=dev`). Tutto in `GET` (anche `HEAD`); `OPTIONS` per la richiesta preliminare CORS; ogni altro metodo `405`.

## Clienti e webcam

- **Cliente** = tabella `customers` del portal, copia minima del soggetto terzo di Dolibarr (`dolibarr_socid`). `bin/sync-dolibarr.php` (ogni ora) legge il cliente della webcam da `GET webcams/{id}/portal` (`customer`, modulo 1.3) e codice, email e città da `GET thirdparties/{id}` (API standard, se l'utente `webcam-portal` può leggere i terzi); crea o aggiorna il cliente e assegna la webcam. Dolibarr è la fonte: `customer: null` toglie il cliente. Senza Dolibarr i clienti si creano nel pannello.
- **Storico delle assegnazioni** (`customer_webcams`): la webcam passa da un cliente all'altro tra un noleggio e l'altro. Ogni assegnazione ha `valid_from`/`valid_to` (UTC).

### Visibilità

| Accesso | Webcam | Foto e misure |
|---|---|---|
| senza chiave | `is_public = 1` e `status = 1`, tutte | dall'inizio dell'assegnazione in corso (tutte se la webcam non ha cliente) |
| chiave `public` senza cliente | come sopra | come sopra |
| chiave `public` di un cliente | le webcam **pubbliche** del cliente, `status = 1` | come sopra |
| chiave `client` | **tutte** le webcam attualmente del cliente (anche private), `status` ≠ 9 | solo nei periodi in cui la webcam era del cliente |

Sempre escluse le foto nascoste (`photos.is_public = 0`) e quelle già tolte dalla retention (`deleted_at`). Una webcam non visibile risponde `404` (mai `403`, per non rivelare quali esistono).

## Autenticazione

```
Authorization: Bearer vls_Obycxu0Nk92mqIyjOZupDmsKcwweRudI3Pg85IZVi0g
```

- Chiave **per cliente** (o senza cliente, solo `public`): `vls_` + 32 byte casuali in base64url = 47 caratteri. Il portal salva solo `sha256(chiave)` (tabella `site_keys`) e la mostra una volta sola, alla creazione. Un cliente può avere più chiavi (una per sito); si revocano una per una.
- **Scope**: `public` (solo webcam pubbliche, immagini fino a 1920 px) o `client` (tutte le webcam del cliente, anche l'originale).
- **Origini ammesse** (`origins`), per le richieste dal browser: elenco di `https://www.hotel.it`, `http://localhost:8080`, `https://*.comune.it` (solo i sottodomini, non `comune.it`), oppure `*` (tutte, solo scope `public`). Vuoto = la chiave si usa solo da server. Una richiesta con intestazione `Origin` non ammessa riceve `403 origin_denied`; senza `Origin` (chiamata da server) l'origine non conta.
- Senza `Authorization`: accesso anonimo alle sole webcam pubbliche. Con una `Authorization` presente ma non valida: `401` (non si ripiega sull'accesso anonimo, per far vedere subito una chiave sbagliata).
- La chiave nel JavaScript delle pagine è leggibile da chiunque: va bene solo per una chiave `public` limitata alle origini del sito. L'origine si può falsificare da un server: è una protezione contro l'uso della chiave in altri siti dal browser, non un'autenticazione.

## CORS

| Richiesta | Risposta |
|---|---|
| `OPTIONS` con `Origin` | `204`, `Access-Control-Allow-Origin: <origine>`, `Access-Control-Allow-Methods: GET, HEAD, OPTIONS`, `Access-Control-Allow-Headers: Authorization`, `Access-Control-Max-Age: 600` (la chiave non c'è nella richiesta preliminare: l'origine si controlla sulla richiesta vera) |
| senza chiave, con `Origin` | `Access-Control-Allow-Origin: *` |
| con chiave e `Origin` ammessa | `Access-Control-Allow-Origin: <origine>` |
| con chiave e `Origin` non ammessa | `403 origin_denied`, senza `Access-Control-Allow-Origin` |

Sempre `Vary: Origin, Authorization`. Le immagini (`/img/...`) hanno `Access-Control-Allow-Origin: *` e `Cross-Origin-Resource-Policy: cross-origin`: si possono mettere in `<img>` e in `<canvas>` di qualsiasi sito, perché l'autorizzazione è nella firma dell'URL.

## Limiti

| Cosa | Limite | Configurazione |
|---|---|---|
| richieste con chiave | 600 al minuto per chiave (o il valore della chiave) | `site_api.rate_limit_per_min`, `site_keys.rate_limit_per_min` |
| richieste senza chiave | 120 al minuto per indirizzo IP | `site_api.anon_rate_limit_per_min` |
| immagini `/img/...` | 1200 al minuto per indirizzo IP | `site_api.image_rate_limit_per_min` |
| `series` | al massimo 400 giorni per richiesta | `site_api.series_max_days` |
| `photos` | al massimo 2000 foto per giorno | |

Oltre il limite: `429 rate_limited` con `Retry-After` (secondi). Il plugin deve tenere in cache le risposte (es. transient di WordPress per 60 s): il portal manda `Cache-Control: public, max-age=60` senza chiave e `private, max-age=60` con chiave (`whoami`: `no-store`).

## Risposte

Sempre JSON (`Content-Type: application/json; charset=utf-8`), con `ok` e `server_time` (secondi Unix):

```json
{ "ok": false, "error": "not_found", "message": "Webcam inesistente o non visibile con questa chiave", "server_time": 1795093375 }
```

| Codice | `error` | Quando |
|---|---|---|
| `400` | `invalid_param` | parametro non valido (data, mese, campi, intervallo) |
| `401` | `key_unknown` | `Authorization` presente ma chiave malformata o sconosciuta |
| `403` | `key_revoked`, `key_expired`, `customer_inactive`, `origin_denied`, `https_required` | |
| `404` | `not_found` | webcam inesistente o non visibile, endpoint inesistente |
| `405` | `method_not_allowed` | metodo diverso da `GET`/`HEAD`/`OPTIONS` |
| `429` | `rate_limited` | con `Retry-After` |
| `500`, `503` | `server_error` | riprovare più tardi |

Convenzioni: ore in secondi Unix UTC (`time`) e in più `datetime` ISO 8601 nel fuso della webcam (`2026-11-19T14:30:00+01:00`); date `AAAA-MM-GG` e mesi `AAAA-MM` nel **fuso della webcam** (`timezone`); valori mancanti `null`. Mai nelle risposte: MAC, hostname, IP, token dei router, id Dolibarr, avvisi, ticket, dati tecnici (batteria, rete, temperatura interna).

### Oggetto foto

```json
{ "id": 918273, "time": 1795091400, "datetime": "2026-11-19T10:10:00+01:00", "mode": "day",
  "temperature_ext": 6.5, "humidity_ext": 80.0, "width": 6000, "height": 4000,
  "images": { "320": "https://portal.viewlapse.com/img/918273/320.jpg?e=1795737600&s=Xb0...",
              "640": "...", "1280": "...", "1920": "...", "orig": "..." } }
```

`images` ha una chiave per dimensione ammessa (`"320"`, `"640"`, `"1280"`, `"1920"`, e `"orig"` solo con scope `client`); `?sizes=320,1280` su qualsiasi endpoint limita le chiavi a quelle chieste. `temperature_ext`/`humidity_ext` sono quelle registrate con la foto. `mode`: `day`, `night` o `null`.

### Oggetto webcam (elenco)

```json
{ "slug": "monte-cavo", "name": "Monte Cavo", "location": "Rocca di Papa (RM)",
  "latitude": 41.751, "longitude": 12.708, "altitude_m": 949, "timezone": "Europe/Rome",
  "public": true, "state": "online", "last_seen": 1795093200,
  "last_photo": { ...oggetto foto... },
  "current_url": "https://portal.viewlapse.com/cam/monte-cavo/current.jpg" }
```

| Campo | Significato |
|---|---|
| `public` | `true` se visibile a tutti (`/cam/{slug}/current.jpg` funziona) |
| `state` | `online` (contatto negli ultimi 40 minuti), `maintenance` (online e in manutenzione), `late` (fino a 4 ore), `offline` (oltre), `never` (mai vista), `not_installed` (da installare, solo scope `client`) |
| `last_seen` | ultimo contatto del router (secondi Unix) o `null` |
| `last_photo` | ultima foto visibile o `null` |
| `current_url` | URL stabile dell'ultima foto per le webcam pubbliche (`api-portal-v1.md`, cache 60 s), altrimenti `null` |

## Endpoint

| Percorso | Parametri | Risposta |
|---|---|---|
| `GET /api/site/v1/whoami` | | scope e cliente della chiave ("Prova connessione" del plugin) |
| `GET /api/site/v1/webcams` | `sizes` | elenco delle webcam visibili |
| `GET /api/site/v1/webcams/{slug}` | `sizes` | dettaglio |
| `GET /api/site/v1/webcams/{slug}/photos` | `date`, `every`, `order`, `sizes` | archivio di un giorno |
| `GET /api/site/v1/webcams/{slug}/days` | `month` | giorni con foto di un mese |
| `GET /api/site/v1/webcams/{slug}/series` | `from`, `to`, `fields` | medie orarie delle misure esterne |
| `GET /api/site/v1/webcams/{slug}/compare` | `a`, `b`, `sizes` | due foto per prima/dopo |

### `GET whoami`

```json
{ "ok": true, "scope": "client", "customer": { "id": 7, "name": "Impresa Rossi Costruzioni" },
  "key": { "prefix": "vls_Obycxu0N", "label": "portale clienti", "origins": [], "expires_at": null },
  "sizes": ["320","640","1280","1920","orig"], "rate_limit_per_min": 600, "server_time": 1795093375 }
```

Senza chiave: `"scope": "anon"`, `"customer": null`, `"key": null`.

### `GET webcams`

```json
{ "ok": true, "scope": "public", "customer": { "id": 7, "name": "Hotel Alpi" }, "count": 1,
  "webcams": [ { ...oggetto webcam... } ], "server_time": 1795093375 }
```

Ordinate per nome. `customer` è `null` senza chiave o con una chiave senza cliente.

### `GET webcams/{slug}`

```json
{ "ok": true, "webcam": { ...oggetto webcam...,
    "interval_min": 10,
    "weather": { "time": 1795093100, "temperature_ext": 5.5, "humidity_ext": 72.5, "dewpoint_ext": 0.9 },
    "availability": { "month": "2026-11", "pct": 99.2, "final": false },
    "month_days": { "month": "2026-11", "days": ["2026-11-01", "2026-11-02", "..."] },
    "archive": { "first_day": "2026-08-21", "last_day": "2026-11-19" },
    "sizes": ["320","640","1280","1920"] }, "server_time": 1795093375 }
```

- `weather`: ultimo campione di telemetria con misure esterne delle ultime 3 ore, altrimenti `null`.
- `availability`: disponibilità del servizio del mese in corso ([diagnosi-v1.md](diagnosi-v1.md)), provvisoria finché `final` è `false`. Sempre con scope `client`; per le altre solo se il portal ha `availability_public = true`; altrimenti `null` (anche se non è ancora calcolata).
- `month_days`: giorni del mese in corso con almeno una foto (per il calendario).
- `archive`: primo e ultimo giorno con foto visibili (`null` se nessuna).

### `GET webcams/{slug}/photos?date=AAAA-MM-GG`

| Parametro | Predefinito | Significato |
|---|---|---|
| `date` | oggi (fuso della webcam) | giorno locale |
| `every` | tutte | minuti 1-1440: una foto (la prima) per ogni intervallo, per i time-lapse leggeri |
| `order` | `asc` | `asc` o `desc` |

```json
{ "ok": true, "webcam": "monte-cavo", "date": "2026-11-19", "timezone": "Europe/Rome", "count": 54,
  "photos": [ { ...oggetto foto... } ], "server_time": 1795093375 }
```

### `GET webcams/{slug}/days?month=AAAA-MM`

`month` predefinito: il mese in corso.

```json
{ "ok": true, "webcam": "monte-cavo", "month": "2026-11", "timezone": "Europe/Rome",
  "days": [ { "date": "2026-11-01", "photos": 96, "first": 1793518200, "last": 1793556600 } ], "server_time": 1795093375 }
```

`days` contiene solo i giorni con foto, in ordine; `first`/`last` = ora (secondi Unix) della prima e dell'ultima foto del giorno.

### `GET webcams/{slug}/series?from=&to=&fields=`

| Parametro | Predefinito | Formato |
|---|---|---|
| `from` | 48 ore fa | secondi Unix, `AAAA-MM-GG` (mezzanotte locale) o `AAAA-MM-GGTHH:MM` (ora locale) |
| `to` | adesso | come `from`; una data `AAAA-MM-GG` comprende tutto quel giorno |
| `fields` | `temperature_ext,humidity_ext` | tra `temperature_ext`, `humidity_ext`, `dewpoint_ext` (nient'altro: i dati tecnici non escono) |

```json
{ "ok": true, "webcam": "monte-cavo", "from": 1794920575, "to": 1795093375, "timezone": "Europe/Rome",
  "interval": 3600, "fields": ["temperature_ext","humidity_ext"],
  "points": [ { "time": 1794920400, "temperature_ext": 4.2, "temperature_ext_min": 3.9, "temperature_ext_max": 4.6,
                "humidity_ext": 81.0, "humidity_ext_min": 79.5, "humidity_ext_max": 83.0 } ], "server_time": 1795093375 }
```

Medie orarie (`measurements_hourly`, `time` = inizio dell'ora, UTC). Solo le ore con almeno un campione e almeno un valore; `dewpoint_ext` ha solo la media. L'ora in corso c'è, parziale.

### `GET webcams/{slug}/compare?a=&b=`

`a` e `b` obbligatori, nello stesso formato di `from`. Per ognuno la foto visibile più vicina all'istante, prima o dopo:

```json
{ "ok": true, "webcam": "monte-cavo", "timezone": "Europe/Rome",
  "a": { "requested": 1793520000, "photo": { ...oggetto foto... }, "delta_s": -120 },
  "b": { "requested": 1795093200, "photo": { ...oggetto foto... }, "delta_s": 300 }, "server_time": 1795093375 }
```

`delta_s` = ora della foto − ora chiesta (negativo = foto prima). `photo` e `delta_s` sono `null` se non c'è nessuna foto visibile.

## Immagini

```
GET /img/{photo_id}/{size}.jpg?e=<scadenza>&s=<firma>
```

- `size`: `320`, `640`, `1280`, `1920` (lato lungo in pixel) o `orig` (il JPEG ricevuto dal router, solo negli URL dati alle chiavi `client`). Una foto più piccola della dimensione chiesta si serve com'è, mai ingrandita.
- `e`: scadenza (secondi Unix); `s`: firma = i primi 32 caratteri del base64url di HMAC-SHA256(`img1|<photo_id>|<size>|<e>`, segreto del portal). Il segreto non esce mai dal portal: i siti non generano URL, li usano come arrivano dalle risposte. Cambiare foto, dimensione o scadenza invalida la firma (`403 bad_signature`); dopo la scadenza `403 url_expired`: il plugin richiede l'elenco e riceve URL nuovi.
- Scadenza: almeno 7 giorni (`site_api.image_url_ttl`), arrotondata al giorno (`site_api.image_url_step`), così lo stesso URL resta uguale per un giorno e browser e proxy lo tengono in cache. Il plugin può tenere in cache le risposte per qualche ora senza che le immagini scadano.
- Risposta: `image/jpeg`, `ETag` (`If-None-Match` → `304`), `Last-Modified` = ora dello scatto, `Cache-Control: public, max-age=<fino alla scadenza>, immutable` (`private` per le webcam non pubbliche). Errori in JSON come sopra; `404` per foto inesistenti, nascoste o tolte dalla retention.
- Le riduzioni si fanno al volo con GD (una alla volta per server, dalla 1920 già pronta quando c'è) e restano in `storage/cache/img/` per `site_api.cache_days` giorni dall'ultimo uso (pulizia in `bin/retention-photos.php`, che toglie anche quelle delle foto cancellate). Un JPEG da 24 MP decompresso occupa circa 100 MB: il portal alza `memory_limit` a `photo_metrics.memory_limit` e, se non basta, risponde `503 image_too_large` con `Retry-After` e lo scrive nel log `site-api`.
- La revoca di una chiave o il passaggio di una webcam a privata non invalidano gli URL già dati: valgono fino alla loro scadenza.

## Gestione delle chiavi

- Pannello: **Clienti** → scheda del cliente → "Chiavi API dei siti": crea (etichetta, scope, origini, limite), mostra la chiave una volta, modifica le origini, revoca. Le chiavi senza cliente (solo `public`) sono in fondo all'elenco dei clienti.
- Riga di comando: `php bin/site-key.php --crea --cliente 7 --scope client --etichetta "portale"`, `--crea --socid 57 --scope public --origini "https://www.hotel.it"`, `--elenco`, `--clienti`, `--origini "..." --chiave 3`, `--revoca 3`.
- Cliente disattivato nel pannello: tutte le sue chiavi ricevono `403 customer_inactive`.

## Compatibilità

- Nuovi campi facoltativi nelle risposte non cambiano versione: il plugin ignora i campi che non conosce. Dimensioni di immagine nuove possono comparire in `images`.
- Un cambio incompatibile (campo che cambia significato, sparisce o cambia unità) → `/api/site/v2/` accanto a v1.
