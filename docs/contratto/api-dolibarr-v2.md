# API del modulo Dolibarr `webcams`: v2

Estensioni del modulo [dolibarr-webcams](https://github.com/Millorco/dolibarr-webcams) (oggi 1.1, endpoint `POST /api/index.php/webcams/report`). La v2 del contratto corrisponde al **modulo 1.2**: stessa classe `Webcams` in `class/api_webcams.class.php`, nuovi metodi con l'annotazione `@url` di Restler come quelli esistenti.

## Principi

- **`POST webcams/report` resta invariato**: stessi campi, stessa risposta `{ok, id, new}`, stessi eventi in agenda. I router con luci-app-webcam 1.0-rc8 continuano a funzionare. Può ricevere campi nuovi facoltativi (sotto), ignorati dal modulo 1.1 se non ancora aggiornato (finiscono solo in `report_json`).
- I **nuovi endpoint li chiama il portal**, non il router: il router parla con Dolibarr solo con `report` ogni 6 ore. Il portal riassume la telemetria (Victron, contatori) e la manda a Dolibarr al massimo una volta l'ora per webcam.
- Il portal **non legge mai le tabelle di Dolibarr**: usa queste API e quelle standard (`GET /thirdparties/{id}`, `GET /tickets/{id}`...).

## Autenticazione e permessi

Come le API standard di Dolibarr (classe `DolibarrApiAccess`): chiave API dell'utente nell'intestazione `DOLAPIKEY: <chiave>` (oppure `Authorization: Bearer <chiave>`, come fa già `inventory_report`). Errori come `RestException`: `401` chiave sbagliata, `403` permesso mancante, `404` webcam inesistente o di un'altra entità, `400` dati non validi.

Due utenti API distinti, non amministratori:

| Utente | Permessi | Usa |
|---|---|---|
| `webcam-router` (esiste) | Webcam → Leggere, Creare/modificare | `report` |
| `webcam-portal` (nuovo) | Webcam → Leggere, **Sincronizzare dal portal** (nuovo); Agenda → creare eventi; Ticket → creare (se il modulo è attivo); Interventi → creare (se attivo); Soggetti terzi → leggere | tutti gli endpoint sotto |

Nuovo permesso in `modWebcams.class.php`, dopo i tre esistenti:

```php
$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 4); // 51730004
$this->rights[$r][1] = 'Sync data from webcam-portal (counters, Victron, events, documents)';
$this->rights[$r][4] = 'webcam';
$this->rights[$r][5] = 'portal'; // $user->hasRight('webcams', 'webcam', 'portal')
```

Ogni endpoint nuovo controlla, come `get()`:

```php
if (!DolibarrApiAccess::$user->hasRight('webcams', 'webcam', 'portal')) throw new RestException(403);
if (!DolibarrApi::_checkAccessToResource('webcam', $id, 'webcams_webcam')) throw new RestException(403);
```

più il permesso dell'oggetto creato (`hasRight('ticket', 'write')`, `hasRight('ficheinter', 'creer')`, `hasRight('agenda', 'myactions', 'create')`) e `isModEnabled()` del modulo relativo (`501` se il modulo non è attivo).

Il generico `PUT webcams/{id}` resta, ma il portal **non** lo usa: permette di cambiare qualsiasi campo (anche cliente e note). Gli endpoint sotto toccano solo i propri campi.

## Nuovi campi di `llx_webcams_webcam`

File `sql/update_1.1-1.2.sql` (eseguito alla riattivazione del modulo, come `update_1.0-1.1.sql`) e definizioni corrispondenti in `$fields` di `class/webcam.class.php` (`noteditable` = 1, `visible` = 5 o -5, etichette in `langs/*/webcams.lang`). Nomi in inglese come le colonne esistenti.

```sql
-- contatori della reflex
ALTER TABLE llx_webcams_webcam ADD COLUMN camera_serial varchar(64) AFTER camera;
ALTER TABLE llx_webcams_webcam ADD COLUMN shutter_count integer AFTER camera_serial;      -- letto dalla fotocamera
ALTER TABLE llx_webcams_webcam ADD COLUMN shots_total integer AFTER shutter_count;        -- contato dal router
ALTER TABLE llx_webcams_webcam ADD COLUMN camera_hours double(8,1) AFTER shots_total;     -- ore di accensione
ALTER TABLE llx_webcams_webcam ADD COLUMN camera_power_cycles integer AFTER camera_hours;
ALTER TABLE llx_webcams_webcam ADD COLUMN counters_date datetime AFTER camera_power_cycles;
-- Victron, riassunto
ALTER TABLE llx_webcams_webcam ADD COLUMN battery_v double(5,2) AFTER humidity_ext;
ALTER TABLE llx_webcams_webcam ADD COLUMN battery_v_min24 double(5,2) AFTER battery_v;    -- minimo ultime 24 h
ALTER TABLE llx_webcams_webcam ADD COLUMN battery_soc double(5,1) AFTER battery_v_min24;  -- solo con BMV/SmartShunt
ALTER TABLE llx_webcams_webcam ADD COLUMN charge_state varchar(20) AFTER battery_soc;     -- bulk, absorption, float, off, fault
ALTER TABLE llx_webcams_webcam ADD COLUMN solar_wh_yesterday integer AFTER charge_state;
ALTER TABLE llx_webcams_webcam ADD COLUMN victron_error smallint AFTER solar_wh_yesterday;
ALTER TABLE llx_webcams_webcam ADD COLUMN victron_date datetime AFTER victron_error;
-- portal e pubblicazione
ALTER TABLE llx_webcams_webcam ADD COLUMN portal_slug varchar(64) AFTER public_url;
ALTER TABLE llx_webcams_webcam ADD COLUMN url_current varchar(255) AFTER portal_slug;    -- https://<portal>/cam/<slug>/current.jpg
ALTER TABLE llx_webcams_webcam ADD COLUMN windy_published smallint DEFAULT 0 AFTER url_current; -- "pubblicata su Windy"
ALTER TABLE llx_webcams_webcam ADD COLUMN windy_id varchar(64) AFTER windy_published;
ALTER TABLE llx_webcams_webcam ADD COLUMN last_telemetry datetime AFTER last_capture;
```

`public_url` (esistente, modificabile a mano) resta la pagina pubblica scelta dal cliente; `url_current` è l'immagine fissa del portal, scritta solo dal portal.

## Campi nuovi (facoltativi) di `report`

Il router può aggiungerli al JSON di `inventory_report`; `report` li salva se presenti, altrimenti lascia il valore salvato (come già fa: "A field missing from the report does not erase the saved value").

| Campo JSON | Colonna |
|---|---|
| `camera_serial` | `camera_serial` (tracciato: un cambio crea un evento in agenda, come `camera`) |
| `shutter_count`, `shots_total`, `camera_hours`, `camera_power_cycles` | omonime, `counters_date` = ora del report |
| `battery_v`, `charge_state` | omonime, `victron_date` = ora del report |

## Endpoint nuovi

Tutti sotto `/api/index.php/webcams/`. `{id}` è `llx_webcams_webcam.rowid` (il portal lo salva in `webcams.dolibarr_id`).

### `GET webcams/bymac/{mac}`

Trova la webcam dal MAC (`aa:bb:cc:dd:ee:ff`), per collegare una webcam del portal alla scheda Dolibarr. Risposta: l'oggetto come `GET webcams/{id}` (stessa `_cleanObjectDatas`). `404` se non esiste.

### `PUT webcams/{id}/portal`

Dati di pubblicazione, scritti dal portal.

```json
{ "portal_slug": "monte-cavo", "url_current": "https://portal.viewlapse.com/cam/monte-cavo/current.jpg",
  "windy_published": 1, "windy_id": "1712345678" }
```

Risposta `{ "ok": true, "id": 12 }`. Un cambio di `windy_published` crea un evento in agenda ("Pubblicata su Windy" / "Tolta da Windy").

### `POST webcams/{id}/counters`

Contatori della reflex e riassunto Victron.

```json
{
  "time": 1795012231,
  "camera": { "model": "Canon EOS 2000D", "serial": "123456789012", "shutter_count": null,
              "shots_total": 48213, "power_on_hours": 3120.5, "power_cycles": 57 },
  "power":  { "battery_v": 13.21, "battery_v_min24": 12.48, "soc_pct": null,
              "charge_state": "bulk", "yield_yesterday_wh": 212, "error": 0 }
}
```

- Aggiorna solo le colonne dei contatori e Victron, `counters_date`, `victron_date`, `last_telemetry`.
- Un contatore **più basso** del valore salvato con lo stesso `camera_serial` è rifiutato per quel campo (`ignored` nella risposta): un contatore non torna indietro senza cambio di fotocamera.
- Un `serial` diverso crea un evento in agenda "Fotocamera cambiata: <vecchio> -> <nuovo>".
- Risposta: `{ "ok": true, "id": 12, "updated": ["shots_total", "battery_v", ...], "ignored": [] }`.

### `POST webcams/{id}/events`

Evento in agenda (`ActionComm`) collegato alla webcam, come quelli che `report` crea già (`fk_element` = id della webcam, `elementtype = 'webcam@webcams'`, `socid` = cliente della webcam).

```json
{ "type": "camera.error", "severity": "error", "time": 1795011615,
  "label": "Scatto fallito dopo il riavvio della fotocamera",
  "note": "fotocamera non trovata su USB ...", "portal_event_id": 551203,
  "url": "https://portal.viewlapse.com/admin/webcams/12/events/551203" }
```

- `code` dell'evento: `AC_WEBCAMS_PORTAL` (tipo `AC_OTH_AUTO`, come `AC_WEBCAMS_REPORT`); etichetta `<ref> - <label>`.
- Il portal manda a Dolibarr **solo** eventi con `severity` ≥ `warning` e gli interventi, non tutto: l'agenda non deve riempirsi di eventi tecnici.
- Idempotenza: `portal_event_id` va in `ActionComm::$ref_ext` (`portal:<id>`); un secondo invio con lo stesso valore restituisce l'evento esistente.
- Risposta `{ "ok": true, "id": 12, "actioncomm_id": 8812, "duplicate": false }`.

### `POST webcams/{id}/tickets`

Apre un **ticket** (modulo Ticket) per un guasto da seguire, collegato alla webcam e al suo cliente.

```json
{ "subject": "Monte Cavo: nessuna foto da 3 ore", "message": "Ultima foto 15:30, telemetria ok, camera.missing alle 15:40 ...",
  "type_code": "ISSUE", "severity_code": "HIGH", "category_code": "WEBCAM", "portal_event_id": 551240 }
```

- Crea un `Ticket` con `fk_soc` del cliente della webcam, `origin_email` vuoto, `ref_ext` = `portal:<portal_event_id>` (idempotenza), e lo collega con `$ticket->add_object_linked('webcam@webcams', $id)`: compare nel riquadro "Oggetti collegati" della scheda webcam (`showLinkedObjectBlock`, già presente in `webcam_card.php`).
- `type_code`, `severity_code`, `category_code` devono esistere nei dizionari del Ticket; se mancano si usano i predefiniti del modulo Ticket.
- `501` se il modulo Ticket non è attivo. Risposta `{ "ok": true, "ticket_id": 341, "ticket_ref": "TS2611-0042", "duplicate": false }`.

### `POST webcams/{id}/interventions`

Apre un **intervento** (modulo Interventi, `Fichinter`) in bozza, per un'uscita del tecnico.

```json
{ "description": "Sostituzione cavo USB fotocamera", "date": "2026-11-20", "portal_event_id": 551241,
  "lines": [ { "description": "Diagnosi da remoto", "duration_min": 30 } ] }
```

Crea il `Fichinter` in bozza con `socid` del cliente (obbligatorio: senza cliente `400`), `ref_ext` = `portal:<id>`, collegato alla webcam come il ticket. Risposta `{ "ok": true, "fichinter_id": 77, "fichinter_ref": "FI2611-0007" }`. L'intervento si valida e si chiude in Dolibarr; il portal ne legge lo stato con `GET /interventions/{id}`.

### `POST webcams/{id}/documents`

Allega un PDF (es. certificazione delle giornate di maltempo, rapporto mensile) ai documenti della webcam.

```json
{ "filename": "maltempo-2026-11.pdf", "filecontent": "<base64>", "fileencoding": "base64", "overwrite": 0,
  "description": "Giornate di maltempo certificate, novembre 2026", "sha256": "<sha256 del PDF>" }
```

- Salva in `$conf->webcams->multidir_output[$object->entity].'/webcam/'.dol_sanitizeFileName($object->ref).'/'` (la stessa cartella di `webcam_document.php`), dopo `dol_sanitizeFileName()` e controllo che sia un PDF (`%PDF-` iniziale), e indicizza il file con `addFileIntoDatabaseIndex()` così compare nella scheda Documenti.
- Limite 10 MB dopo la decodifica (e `post_max_size` di PHP); `409` se il file esiste e `overwrite` = 0; `422` se `sha256` non corrisponde.
- Risposta `{ "ok": true, "id": 12, "file": "webcam/WC-9483C412ABCD/maltempo-2026-11.pdf" }`.
- Perché non l'API standard `POST /documents/upload`: richiede un `modulepart` che i moduli esterni non sempre supportano e non verifica i permessi del modulo `webcams`. Se in futuro funziona per `webcams`, l'endpoint dedicato può diventarne un semplice involucro.

## Riepilogo

| Endpoint | Chiamato da | Permesso |
|---|---|---|
| `POST webcams/report` | router | `webcam write` (invariato) |
| `GET webcams/bymac/{mac}` | portal | `webcam read` |
| `PUT webcams/{id}/portal` | portal | `webcam portal` |
| `POST webcams/{id}/counters` | portal | `webcam portal` |
| `POST webcams/{id}/events` | portal | `webcam portal` + agenda |
| `POST webcams/{id}/tickets` | portal | `webcam portal` + ticket |
| `POST webcams/{id}/interventions` | portal | `webcam portal` + ficheinter |
| `POST webcams/{id}/documents` | portal | `webcam portal` |

Dolibarr richiesto: 20 o successivo, come oggi (`need_dolibarr_version`).
