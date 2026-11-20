# API del portal per i siti: v1

Versione del documento: **1.1** (contratto 1.3, fase 3b), compatibile con la 1.0 (contratto 1.2). API **in sola lettura** con cui i siti dei clienti mostrano webcam, archivio, misure e confronti e, dalla 1.1, i servizi del listino: stesso momento in giorni diversi, timelapse, foto del giorno, statistiche, rapporti mensili e certificati di maltempo ([Servizi (1.1)](#servizi-11)). Le foto restano sul portal: i siti le mostrano soltanto, con URL firmati. L'unica scrittura è il conteggio delle visualizzazioni ([beacon](#visualizzazioni-beacon)).

Chi la usa:

| Plugin WordPress | Dove | Chiave |
|---|---|---|
| **solo visualizzazione** | qualsiasi sito WordPress esterno (hotel, comune...) | nessuna (webcam pubbliche) o chiave `public` del cliente; dal browser con le origini della chiave, o dal server |
| **portale clienti** | WordPress Multisite, un sottosito per cliente, con utenti e login | chiave `client` del cliente, **solo dal server** WordPress (mai nel JavaScript delle pagine) |

Il portal resta il motore e il pannello amministrativo: clienti, webcam assegnate e chiavi si gestiscono lì (pannello `/admin/customers` o `bin/site-key.php`). Gli utenti di WordPress non esistono nel portal: il plugin "portale clienti" decide chi vede cosa nel sottosito; il portal vede solo la chiave del cliente.

Base: `https://<portal>/api/site/v1` (anteprima XAMPP: `http://localhost/webcam-portal/api/site/v1`). Solo HTTPS in produzione (`403 https_required` su HTTP, salvo `APP_ENV=dev`). Tutto in `GET` (anche `HEAD`); `OPTIONS` per la richiesta preliminare CORS; ogni altro metodo `405`, tranne `POST /api/site/v1/views` (1.1, [beacon](#visualizzazioni-beacon)).

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

**Archivio dopo il noleggio** (1.1, M13): con la chiave `client` si vedono anche le webcam che **non sono più** del cliente, finché dura l'archivio della sua ultima assegnazione chiusa (`customer_webcams.archive_until`, da Dolibarr; senza, `archive.default_days` = 30 giorni da `valid_to`). Sono nell'elenco con `"state": "archived"` e `archive_until`, e rispondono solo con i dati del periodo dell'assegnazione: `photos`, `days`, `series`, `compare`, `same-moment`, `timelapses`, `photo-of-day`, `reports`, `weather-days`, `certificates`. `current_url`, `weather` e `availability` sono `null`; non si genera niente di nuovo (timelapse, rapporti) per un'assegnazione chiusa. Il giorno dopo `archive_until` la webcam torna `404` per quel cliente.

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
| `same-moment` | al massimo 400 elementi | |
| `stats` | al massimo 400 giorni | |
| `POST views` | 60 al minuto per indirizzo IP, poi ignorati (`204`) | |
| `/media/...` | come `/img/...`: 1200 al minuto per indirizzo IP | `site_api.image_rate_limit_per_min` |

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
| `403` | `feature_unavailable` | 1.1: la funzione non è nel listino della webcam; `message` dice quale. La webcam è visibile, quindi non si rivela nulla |
| `403` | `scope_client_required` | 1.1: endpoint solo per la chiave `client` (statistiche, rapporti, certificati) |
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
| `state` | `online` (contatto negli ultimi 40 minuti), `maintenance` (online e in manutenzione), `late` (fino a 4 ore), `offline` (oltre), `never` (mai vista), `not_installed` (da installare, solo scope `client`), `archived` (1.1: noleggio finito, archivio ancora visibile, solo scope `client`) |
| `last_seen` | ultimo contatto del router (secondi Unix) o `null` |
| `last_photo` | ultima foto visibile o `null` |
| `current_url` | URL stabile dell'ultima foto per le webcam pubbliche (`api-portal-v1.md`, cache 60 s), altrimenti `null` |
| `features` | 1.1: funzioni attive per la webcam ([Funzioni e listino](#funzioni-e-listino)), es. `["archive","availability","overlay","timelapse"]` |
| `overlay` | 1.1: `true` se le immagini `images` (da 640 in su) hanno la sovrimpressione del portal |
| `archive_until` | 1.1: solo con `state = archived`, ultimo giorno visibile (`AAAA-MM-GG`); altrimenti assente |
| `beacon` | 1.1: `{ "url": "https://<portal>/api/site/v1/views", "token": "..." }` per contare le visualizzazioni ([beacon](#visualizzazioni-beacon)) |

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
| `GET /api/site/v1/webcams/{slug}/same-moment` (1.1) | `time`, `from`, `to`, `step`, `tolerance`, `good`, `sizes` | stesso momento in giorni diversi (funzione `same_moment`) |
| `GET /api/site/v1/webcams/{slug}/timelapses` (1.1) | `kind`, `format`, `from`, `to`, `limit` | timelapse pronti (funzione `timelapse`) |
| `GET /api/site/v1/webcams/{slug}/photo-of-day` (1.1) | `date`, `sizes` | foto del giorno (funzione `photo_of_day`) |
| `GET /api/site/v1/webcams/{slug}/photos-of-day` (1.1) | `month`, `sizes` | foto del giorno di un mese |
| `GET /api/site/v1/webcams/{slug}/overlay-items` (1.1) | | sponsor e infopoint attivi (funzione `sponsor`) |
| `GET /api/site/v1/webcams/{slug}/stats` (1.1) | `from`, `to`, `group` | statistiche (funzione `stats`, solo `client`) |
| `GET /api/site/v1/webcams/{slug}/reports` (1.1) | `year` | rapporti mensili (funzione `monthly_report`, solo `client`) |
| `GET /api/site/v1/webcams/{slug}/weather-days` (1.1) | `month` | registro e giornate di maltempo (funzione `weather_cert`, solo `client`) |
| `GET /api/site/v1/webcams/{slug}/certificates` (1.1) | `year` | certificati di maltempo (funzione `weather_cert`, solo `client`) |
| `POST /api/site/v1/views` (1.1) | corpo `t`, `ev`, `s` | conteggio di una visualizzazione (beacon) |

### `GET whoami`

```json
{ "ok": true, "scope": "client", "customer": { "id": 7, "name": "Impresa Rossi Costruzioni" },
  "key": { "prefix": "vls_Obycxu0N", "label": "portale clienti", "origins": [], "expires_at": null },
  "sizes": ["320","640","1280","1920","orig"], "rate_limit_per_min": 600,
  "server": { "timelapse": true, "telegram": false, "reason": { "telegram": "not_configured" } },
  "server_time": 1795093375 }
```

`server` (1.1): servizi che il portal sa offrire in assoluto (`timelapse = false` con `reason.timelapse = "ffmpeg_missing"`), per spiegare all'amministratore del sito perché una funzione manca.

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

## Servizi (1.1)

Endpoint della fase 3b (contratto 1.3). Regole dei servizi in [servizi-v1.md](servizi-v1.md). Tutti in sola lettura, stesse regole di visibilità, cache e limiti degli endpoint della 1.0.

### Funzioni e listino

Ogni endpoint dei servizi dipende da una **funzione** del listino della webcam (base, standard, pro e opzioni, letti da Dolibarr: matrice in [api-dolibarr-v2.md](api-dolibarr-v2.md#matrice-livello--funzioni)). Le funzioni attive sono nel campo `features` di ogni oggetto webcam; il plugin mostra solo quello che c'è in `features` e non deve ricalcolare la matrice. Una richiesta a un endpoint di una funzione assente risponde `403 feature_unavailable`.

| Endpoint | Funzione | base | standard | pro | Scope |
|---|---|:-:|:-:|:-:|---|
| `whoami`, `webcams`, `webcams/{slug}`, `photos`, `days`, `series`, `compare` | `archive` | ✓ | ✓ | ✓ | tutti |
| `same-moment` | `same_moment` | | ✓ | ✓ | tutti |
| `timelapses` (`kind` `day`, `week`, `month`, orizzontali) | `timelapse` | | ✓ | ✓ | tutti |
| `timelapses` con `format=portrait` | `timelapse_portrait` | | | ✓ | tutti |
| `timelapses` con `kind` `custom` | `timelapse_custom` | | | ✓ | `client` |
| `timelapses` con `kind` `final` | `final_film` (opzione) | | | | `client` |
| `photo-of-day`, `photos-of-day` | `photo_of_day` | | ✓ | ✓ | tutti |
| `overlay-items` | `sponsor` | | | ✓ (o opzione) | tutti |
| `stats` | `stats` | | | ✓ | `client` |
| `reports` | `monthly_report` | | ✓ | ✓ | `client` |
| `weather-days`, `certificates` | `weather_cert` | | | ✓ (o opzione) | `client` |
| `POST views` | nessuna (si conta sempre) | ✓ | ✓ | ✓ | tutti, anche senza chiave |

Per le webcam `archived` valgono le funzioni di consultazione (`archive`, `same_moment`, e `timelapse`, `photo_of_day`, `monthly_report`, `weather_cert` per i file già prodotti), indipendentemente dal listino del noleggio nuovo.

### `GET webcams/{slug}/same-moment`

Stesso momento in giorni diversi (M6).

| Parametro | Predefinito | Significato |
|---|---|---|
| `time` | `12:00` | ora locale `HH:MM` |
| `from`, `to` | ultimi 30 giorni, fino a oggi | date `AAAA-MM-GG` |
| `step` | `day` | `day`, `week` (stesso giorno della settimana di `to`), `month` (stesso giorno del mese di `to`), `year` |
| `tolerance` | 30 | minuti 1-180: oltre, nessuna foto per quel giorno |
| `good` | `0` | `1` = preferire le foto buone ([servizi-v1.md](servizi-v1.md#foto-buone-o8)) |
| `sizes` | tutte | come gli altri endpoint |

Al più 400 elementi (`400 invalid_param` oltre), dal più vecchio.

```json
{ "ok": true, "webcam": "monte-cavo", "timezone": "Europe/Rome", "time": "12:00", "step": "day", "count": 30,
  "items": [ { "date": "2026-10-21", "requested": 1792576800, "photo": { ...oggetto foto... }, "delta_s": -240, "good": true },
             { "date": "2026-10-22", "requested": 1792663200, "photo": null, "delta_s": null, "good": null } ],
  "server_time": 1795093375 }
```

### `GET webcams/{slug}/timelapses`

Timelapse pronti (M7, M10), dal più recente.

| Parametro | Predefinito | Significato |
|---|---|---|
| `kind` | tutti quelli permessi | `day`, `week`, `month`, `custom`, `final`, anche più di uno separati da virgola |
| `format` | tutti quelli permessi | `landscape`, `portrait` |
| `from`, `to` | | date: timelapse con il periodo che tocca l'intervallo |
| `limit` | 50 | 1-200 |

```json
{ "ok": true, "webcam": "monte-cavo", "timezone": "Europe/Rome", "count": 2,
  "latest": { "day": { "landscape": 5521, "portrait": 5522 }, "week": { "landscape": 5490 }, "month": { "landscape": 5301 } },
  "timelapses": [
    { "id": 5521, "kind": "day", "period_from": "2026-11-18", "period_to": "2026-11-18", "format": "landscape",
      "width": 1920, "height": 1080, "fps": 12, "duration_s": 8.25, "frames": 99, "size_bytes": 14820311,
      "overlay": true, "created": 1795050000,
      "video_url": "https://portal.viewlapse.com/media/tl/5521.mp4?e=1795737600&s=Q2x...",
      "poster_url": "https://portal.viewlapse.com/media/tlp/5521.jpg?e=1795737600&s=Lm9..." } ],
  "server_time": 1795093375 }
```

- Solo `status = done` e non scaduti. `latest`: l'id più recente per tipo e formato (tra quelli permessi), comodo per un widget "timelapse di ieri".
- Visibilità: il periodo deve stare dentro quello visibile delle foto (assegnazione in corso per `public`/anonimo, periodi del cliente per `client`). `custom` e `final` solo con scope `client` e solo quelli del cliente della chiave.
- Senza ffmpeg sul server non si fanno video nuovi (`whoami.server.timelapse = false`), ma l'endpoint risponde con quelli già fatti.

### `GET webcams/{slug}/photo-of-day?date=AAAA-MM-GG`

Foto del giorno (M11). `date` predefinito: oggi se è già scelta, altrimenti ieri.

```json
{ "ok": true, "webcam": "monte-cavo", "date": "2026-11-18", "chosen_by": "auto", "score": 91.4,
  "photo": { ...oggetto foto...,
    "social": { "square": "https://portal.viewlapse.com/img/918273/sq1080.jpg?e=...&s=...",
                "portrait": "https://portal.viewlapse.com/img/918273/p1350.jpg?e=...&s=...",
                "story": "https://portal.viewlapse.com/img/918273/s1920.jpg?e=...&s=..." } },
  "server_time": 1795093375 }
```

`photo: null` se quel giorno non c'è (nessuna foto buona). `chosen_by`: `auto`, `operator`, `customer`.

`GET webcams/{slug}/photos-of-day?month=AAAA-MM` (predefinito il mese in corso): `{ "ok", "webcam", "month", "days": [ { "date", "chosen_by", "score", "photo": {...} } ] }`, solo i giorni con la foto, senza `social` (si chiede il giorno per averli).

### `GET webcams/{slug}/overlay-items`

Sponsor e infopoint attivi oggi (M12), per disegnarli cliccabili nel visualizzatore del plugin.

```json
{ "ok": true, "webcam": "monte-cavo", "slots": 3,
  "sponsors": [ { "id": 31, "label": "Rifugio Alpe", "text": "Polenta tutti i giorni", "url": "https://rifugioalpe.it",
                  "logo_url": "https://portal.viewlapse.com/media/logo/31.png?e=...&s=...", "burned": true } ],
  "infopoints": [ { "id": 40, "label": "Monte Cavo, 949 m", "text": "Antenne e sentiero 504", "url": null,
                    "x_pct": 61.5, "y_pct": 22.0, "icon_url": null, "burned": false } ],
  "server_time": 1795093375 }
```

`x_pct`, `y_pct`: posizione in percentuale dell'immagine intera, dall'angolo in alto a sinistra (valgono per qualsiasi dimensione, non per i ritagli `social`). `burned = true`: l'elemento è già stampato sulle immagini con sovrimpressione, il plugin aggiunge solo l'area cliccabile.

### `GET webcams/{slug}/stats` (solo `client`)

Statistiche di visualizzazione (M8): contatori aggregati, nessun dato personale.

| Parametro | Predefinito | Significato |
|---|---|---|
| `from`, `to` | ultimi 30 giorni | date (al più 400 giorni) |
| `group` | `day` | `day` o `month` |

```json
{ "ok": true, "webcam": "monte-cavo", "timezone": "Europe/Rome", "from": "2026-10-21", "to": "2026-11-19", "group": "day",
  "totals": { "views": 12840, "visitors": 4210, "view_seconds": 1520400, "avg_view_s": 118, "timelapse_plays": 932,
              "photo_opens": 2210, "image_requests": 40210, "api_requests": 5120, "telegram": 64 },
  "by_source": { "site": 12100, "portal": 740, "image": 40210, "api": 5120, "telegram": 64 },
  "series": [ { "date": "2026-10-21", "views": 402, "visitors": 150, "view_seconds": 48100, "timelapse_plays": 30, "image_requests": 1320 } ],
  "hosts": [ { "host": "www.hotel-alpi.it", "views": 9800 }, { "host": "-", "views": 310 } ],
  "server_time": 1795093375 }
```

`views`/`visitors` contano le fonti `site` e `portal`; `image_requests` le richieste di `current.jpg` (Windy, incorporamenti); `hosts` per i mesi toccati dall'intervallo, i primi 20. Il giorno in corso è parziale.

### `GET webcams/{slug}/reports?year=AAAA` (solo `client`)

Rapporti mensili del cliente della chiave (O3), dal più recente; `year` predefinito l'anno in corso.

```json
{ "ok": true, "webcam": "monte-cavo", "reports": [
  { "id": 381, "month": "2026-10", "period_from": "2026-10-01", "period_to": "2026-10-31", "generated": 1793865600,
    "pages": 6, "size_bytes": 2310455, "sha256": "9f2c...", "emailed": 1793866000,
    "summary": { "availability_pct": 99.12, "photos": 2841, "bad_weather_days": 6 },
    "pdf_url": "https://portal.viewlapse.com/media/rep/381.pdf?e=1795100000&s=..." } ], "server_time": 1795093375 }
```

Solo `status` `ready`, `sent`, `emailed`. `summary`: tre numeri di `monthly_reports.data`. `emailed`: ora dell'email (secondi Unix) o `null`.

### `GET webcams/{slug}/weather-days?month=AAAA-MM` (solo `client`)

Registro giornaliero e classificazione meteo (O2), solo i giorni dell'assegnazione al cliente e già sigillati.

```json
{ "ok": true, "webcam": "monte-cavo", "month": "2026-11", "timezone": "Europe/Rome", "work_hours": "07:00-18:00",
  "days": [ { "date": "2026-11-18", "workday": true, "classification": "bad_weather", "reasons": ["wet","dark"],
              "wet_hours": 6, "frost_hours": 0, "fog_hours": 1, "t_ext_min": 3.1, "t_ext_max": 7.4, "rh_ext_avg": 96.2,
              "photos": 96, "photos_unsynced": 0, "late_photos": 0,
              "day_hash": "3b7e...64 hex", "registered_at": 1795051800,
              "manifest_url": "https://portal.viewlapse.com/media/reg/12-20261118.txt?e=...&s=..." } ],
  "summary": { "days": 18, "bad_weather": 6, "not_certifiable": 1 }, "server_time": 1795093375 }
```

`registered_at`: ora del deposito dell'hash in Dolibarr, `null` se non ancora depositato o senza Dolibarr. `manifest_url`: l'elenco `vlreg1` del giorno (testo, [servizi-v1.md](servizi-v1.md#registro-daily_register)), da verificare con `sha256sum`.

### `GET webcams/{slug}/certificates?year=AAAA` (solo `client`)

```json
{ "ok": true, "webcam": "monte-cavo", "certificates": [
  { "id": 77, "kind": "month", "period_from": "2026-10-01", "period_to": "2026-10-31", "days_bad": 6,
    "days_not_certifiable": 1, "generated": 1793865700, "sha256": "41aa...", "size_bytes": 3120400, "emailed": 1793866100,
    "pdf_url": "https://portal.viewlapse.com/media/cert/77.pdf?e=...&s=..." } ], "server_time": 1795093375 }
```

## Visualizzazioni (beacon)

Per le statistiche (M8) il plugin conta le visualizzazioni **dal browser del visitatore**, con il token che trova nel campo `beacon` di ogni oggetto webcam. Funziona anche per le webcam private del portale clienti senza mettere la chiave nella pagina: il token è firmato dal portal e vale solo per contare.

- **Token** `beacon.token`: `<webcam_id>.<site_key_id>.<scadenza>.<firma>`, firma = primi 22 caratteri del base64url di HMAC-SHA256(`vb1|<webcam_id>|<site_key_id>|<scadenza>`, segreto del portal); scadenza a 3 giorni, arrotondata al giorno come per le immagini. Il plugin lo mette nella pagina com'è (attributo `data-vl-beacon`); non lo calcola.
- **Richiesta**: `POST /api/site/v1/views` con corpo `application/x-www-form-urlencoded` o `text/plain` (lo stesso formato), così `navigator.sendBeacon()` funziona senza richiesta preliminare CORS:

  ```
  t=<token>&ev=view&s=0
  ```

  | Campo | Valori |
  |---|---|
  | `t` | il token |
  | `ev` | `view` (pagina con la webcam visibile), `leave` (pagina chiusa o nascosta, `visibilitychange`), `play` (timelapse avviato), `open` (foto dell'archivio a schermo intero) |
  | `s` | solo con `leave`: secondi di visione (0-3600) |
  | `src` | facoltativo: `portal` per il pannello del portal; il plugin non lo manda (vale `site`) |

  In alternativa, senza JavaScript: `GET /api/site/v1/views.gif?t=...&ev=view` risponde con un GIF trasparente 1 × 1.
- **Risposta**: sempre `204` senza corpo (anche con token scaduto o sbagliato: niente da mostrare al visitatore), `Access-Control-Allow-Origin: *`, `Cache-Control: no-store`. Il plugin non legge la risposta e non riprova.
- **Nessun cookie** e nessun dato salvato nel browser. Il portal tiene solo contatori aggregati e, per contare i visitatori distinti del giorno, un'impronta che non si può ricollegare all'IP dopo 48 ore ([servizi-v1.md](servizi-v1.md#statistiche-di-visualizzazione-m8)). Il plugin può dirlo nella sua informativa: "statistiche aggregate senza cookie, nessun dato personale conservato".
- Limite: 60 beacon al minuto per indirizzo IP (gli altri si ignorano, sempre `204`).
- Le richieste dell'API dei siti e di `current.jpg` si contano già dal portal: il plugin manda il beacon solo per le visualizzazioni nel browser.

Il plugin manda `view` una volta per caricamento di pagina e per webcam, quando l'immagine entra nello schermo (`IntersectionObserver`), e `leave` con i secondi in cui la pagina è stata visibile.

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
- **Sovrimpressione** (1.1, funzione `overlay`): con la sovrimpressione attiva le dimensioni `640`, `1280`, `1920` arrivano con data, misure, logo e sponsor stampati ([servizi-v1.md](servizi-v1.md#sovrimpressione-m2-m12)); `320` e `orig` mai. L'URL non cambia; cambia l'`ETag` quando cambia la sovrimpressione. `"overlay": true` nell'oggetto webcam lo dice al plugin.
- **Formati per i social** (1.1, solo negli URL di `photo-of-day`): `size` = `sq1080` (1080 × 1080), `p1350` (1080 × 1350), `s1920` (1080 × 1920), ritagliati al centro (`crop_x_pct` della webcam), con sovrimpressione se attiva. Firma come sopra con il nome del formato al posto della dimensione.

## File dei servizi (1.1)

Video, poster, PDF, elenchi del registro e loghi degli sponsor si servono come le immagini, con URL firmati che i siti usano così come arrivano:

```
GET /media/{tipo}/{id}.{ext}?e=<scadenza>&s=<firma>
```

| `tipo` | `id` | Contenuto | Scadenza dell'URL | Cache |
|---|---|---|---|---|
| `tl` | id del timelapse | `video/mp4` | come le immagini (7 giorni, al giorno) | `public` o `private` come le immagini, `immutable` |
| `tlp` | id del timelapse | poster `image/jpeg` | come sopra | come sopra |
| `logo` | id dell'elemento (`overlay_items`) | `image/png` o `image/jpeg` | come sopra | `public` |
| `rep` | id del rapporto | `application/pdf`, `Content-Disposition: attachment; filename="rapporto-<slug>-AAAA-MM.pdf"` | 1 giorno, all'ora (`site_api.document_url_ttl`) | `private, no-store` |
| `cert` | id del certificato | `application/pdf`, `filename="maltempo-<slug>-<periodo>.pdf"` | come sopra | come sopra |
| `reg` | `<webcam_id>-<AAAAMMGG>` | `text/plain; charset=utf-8`, `filename="registro-<slug>-AAAA-MM-GG.txt"` | come sopra | come sopra |

- Firma: primi 32 caratteri del base64url di HMAC-SHA256(`med1|<tipo>|<id>|<e>`, segreto del portal); errori `403 bad_signature`, `403 url_expired`, `404` come per le immagini.
- I video rispondono alle richieste con `Range` (`206 Partial Content`, `Accept-Ranges: bytes`), necessarie al lettore video del browser per spostarsi nel filmato.
- `Access-Control-Allow-Origin: *` e `Cross-Origin-Resource-Policy: cross-origin` come le immagini.
- `rep`, `cert`, `reg` si danno solo alle chiavi `client`; la firma è l'autorizzazione, per questo scadono presto.

## Gestione delle chiavi

- Pannello: **Clienti** → scheda del cliente → "Chiavi API dei siti": crea (etichetta, scope, origini, limite), mostra la chiave una volta, modifica le origini, revoca. Le chiavi senza cliente (solo `public`) sono in fondo all'elenco dei clienti.
- Riga di comando: `php bin/site-key.php --crea --cliente 7 --scope client --etichetta "portale"`, `--crea --socid 57 --scope public --origini "https://www.hotel.it"`, `--elenco`, `--clienti`, `--origini "..." --chiave 3`, `--revoca 3`.
- Cliente disattivato nel pannello: tutte le sue chiavi ricevono `403 customer_inactive`.

## Compatibilità

- 1.1: solo aggiunte (campi `features`, `overlay`, `beacon`, `archive_until`, `server`, endpoint dei servizi, `POST views`, URL `/media/...`, dimensioni per i social). Un plugin 1.0 continua a funzionare; le sue immagini da 640 in su possono avere la sovrimpressione se il cliente l'ha attivata.

- Nuovi campi facoltativi nelle risposte non cambiano versione: il plugin ignora i campi che non conosce. Dimensioni di immagine nuove possono comparire in `images`.
- Un cambio incompatibile (campo che cambia significato, sparisce o cambia unità) → `/api/site/v2/` accanto a v1.
