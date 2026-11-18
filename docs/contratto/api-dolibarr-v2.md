# API del modulo Dolibarr `webcams`: v2

Estensioni del modulo [dolibarr-webcams](https://github.com/Millorco/dolibarr-webcams) (oggi 1.1, endpoint `POST /api/index.php/webcams/report`). La v2 del contratto corrisponde al **modulo 1.2**: stessa classe `Webcams` in `class/api_webcams.class.php`, nuovi metodi con l'annotazione `@url` di Restler come quelli esistenti.

Versione del documento: **2.1** (contratto 1.1) = **modulo 1.3**, compatibile con la 1.2: aggiunge le soglie del contratto di noleggio sulla webcam, `GET webcams/{id}/portal`, l'aggiornamento e la chiusura dei ticket, il registro della disponibilità mensile. Vedi [Diagnosi e disponibilità (modulo 1.3)](#diagnosi-e-disponibilità-modulo-13).

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
| `webcam-portal` (nuovo) | Webcam → Leggere, **Sincronizzare dal portal** (nuovo); Agenda → creare eventi; Ticket → leggere e creare (se il modulo è attivo; leggere serve dal modulo 1.3 per lo stato con `GET /tickets/{id}`); Interventi → creare (se attivo); Soggetti terzi → leggere e **Estendi l'accesso a tutti i terzi** (senza, i terzi dei clienti non sono visibili all'utente API) | tutti gli endpoint sotto |

Nuovo permesso in `modWebcams.class.php`, dopo i tre esistenti:

```php
$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 4); // 51730014 nel modulo 1.2 ($o = 1)
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

Trova la webcam dal MAC, per collegare una webcam del portal alla scheda Dolibarr. Risposta: l'oggetto come `GET webcams/{id}` (stessa `_cleanObjectDatas`). `404` se non esiste.

Il WAF di Dolibarr rifiuta `:` nel percorso: il MAC va scritto **senza separatori, minuscolo** (`GET webcams/bymac/9483c412abcd`), oppure come parametro (`GET webcams/bymac?mac=94:83:c4:12:ab:cd`). webcam-portal usa la prima forma.

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

- Crea un `Ticket` con `fk_soc` del cliente della webcam e `origin_email` vuoto. Il `Ticket` di Dolibarr non ha `ref_ext`: il riferimento `portal:<portal_event_id>` (idempotenza) è salvato in `extraparams` del ticket. Il ticket si collega con `$ticket->add_object_linked('webcam@webcams', $id)`: compare nel riquadro "Oggetti collegati" della scheda webcam (`showLinkedObjectBlock`, già presente in `webcam_card.php`).
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

## Diagnosi e disponibilità (modulo 1.3)

Aggiunte per la fase 2 (regole in [diagnosi-v1.md](diagnosi-v1.md)). Il portal applica le regole sui suoi dati e usa Dolibarr per **i ticket**, per **leggere le soglie del contratto di noleggio** e per **registrare la disponibilità mensile**. Tutto con l'utente `webcam-portal` e il permesso `webcam portal` della 1.2; nessun permesso nuovo.

### Cosa c'è già nella 1.2 e resta com'è

- **Vita dell'otturatore**: `report` e `POST {id}/counters` scrivono un evento in agenda quando gli scatti usati (`shutter_count`, altrimenti `shots_total`) superano `WEBCAMS_SHUTTER_WARN_PCT` (90%) e `WEBCAMS_SHUTTER_LIFE` (100.000), una volta per soglia e per fotocamera; l'indice del modulo ha il riquadro "Otturatore a fine vita". **Dolibarr resta la sola fonte di questi avvisi**: il portal non manda eventi né ticket per l'otturatore e legge le stesse soglie con `GET {id}/portal` per mostrarle uguali.
- `POST {id}/tickets` con idempotenza su `portal_event_id` (in `extraparams.ref_ext`) e collegamento alla webcam.
- Pulsanti rttys sulla scheda (`WEBCAMS_RTTYS_URL`): gli stessi link vanno nel testo dei ticket del portal.

### Nuovi campi di `llx_webcams_webcam`

File `sql/update_1.2-1.3.sql`, campi in `$fields` modificabili dalla scheda (sono dati del contratto, li compila chi lo firma), etichette in `langs/*/webcams.lang`:

```sql
-- impegno di disponibilità del contratto di noleggio
ALTER TABLE llx_webcams_webcam ADD COLUMN sla_enabled smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_webcams_webcam ADD COLUMN sla_offline_hours integer;       -- N: ore senza foto (predefinito 3)
ALTER TABLE llx_webcams_webcam ADD COLUMN sla_response_days integer;       -- X: giorni per intervenire (predefinito 3)
ALTER TABLE llx_webcams_webcam ADD COLUMN sla_target_pct double(5,2);      -- disponibilità mensile promessa (predefinito 98)
ALTER TABLE llx_webcams_webcam ADD COLUMN sla_hours varchar(11);           -- ore di scatto previste 'HH:MM-HH:MM', vuoto = 24 ore
ALTER TABLE llx_webcams_webcam ADD COLUMN sla_weekdays varchar(7);         -- giorni previsti, cifre ISO 1-7, vuoto = tutti
-- ultima disponibilità mensile definitiva (per elenco e indice)
ALTER TABLE llx_webcams_webcam ADD COLUMN availability_pct double(5,2);
ALTER TABLE llx_webcams_webcam ADD COLUMN availability_period varchar(7);  -- AAAA-MM
```

Valori vuoti = predefiniti di `WEBCAMS_SLA_OFFLINE_HOURS`, `WEBCAMS_SLA_RESPONSE_DAYS`, `WEBCAMS_SLA_TARGET_PCT` (nuove impostazioni del modulo, 3 / 3 / 98). Il contratto di noleggio vero (modulo Contratti) può in futuro dare questi valori; per ora stanno sulla webcam perché la webcam passa da un cliente all'altro e il portal li legge da lì.

Tabella nuova del registro della disponibilità (`sql/llx_webcams_availability.sql` e `.key.sql`):

```sql
CREATE TABLE llx_webcams_availability (
  rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity integer DEFAULT 1 NOT NULL,
  fk_webcam integer NOT NULL,
  fk_soc integer,                         -- cliente della webcam in quel mese
  period varchar(7) NOT NULL,             -- AAAA-MM, fuso della webcam
  availability_pct double(5,2),           -- NULL se nessuna ora utile
  expected_hours integer NOT NULL,
  excluded_hours integer DEFAULT 0 NOT NULL,
  available_hours integer DEFAULT 0 NOT NULL,
  incidents integer DEFAULT 0 NOT NULL,
  downtime_hours double(7,1) DEFAULT 0 NOT NULL,
  response_breaches integer DEFAULT 0 NOT NULL,
  sla_enabled smallint DEFAULT 0 NOT NULL,
  target_pct double(5,2),
  sla_met smallint,                       -- NULL senza impegno
  final smallint DEFAULT 0 NOT NULL,      -- 1 = mese chiuso, definitivo
  date_creation datetime NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
ALTER TABLE llx_webcams_availability ADD UNIQUE INDEX uk_webcams_availability (fk_webcam, period);
ALTER TABLE llx_webcams_availability ADD INDEX idx_webcams_availability_soc (fk_soc, period);
```

La scheda della webcam mostra la tabella dei mesi (nuova scheda "Disponibilità" o riquadro nella scheda principale).

### `GET webcams/{id}/portal`

Quello che il portal legge da Dolibarr per una webcam, in una sola chiamata (a ogni sincronizzazione, al massimo una volta l'ora). Il metodo `PUT {id}/portal` della 1.2 resta per la scrittura.

```json
{
  "ok": true, "id": 12, "ref": "WC-9483C412ABCD", "status": 1,
  "customer": { "id": 57, "name": "Impresa Rossi Costruzioni" },
  "portal": { "portal_slug": "monte-cavo", "url_current": "https://portal.viewlapse.com/cam/monte-cavo/current.jpg",
              "windy_published": 1, "windy_id": "1712345678" },
  "sla": { "enabled": true, "offline_hours": 3, "response_days": 3, "target_pct": 98.0,
           "hours": "07:00-19:00", "weekdays": "12345" },
  "shutter": { "life": 100000, "warn_pct": 90, "used": 48213, "level": "" },
  "rttys": { "terminal": "https://rttys.viewlapse.com/api/connect/WC-9483C412ABCD",
             "luci": "https://rttys.viewlapse.com/api/web/WC-9483C412ABCD/http/127.0.0.1%3A80%2F" }
}
```

- `sla` con i predefiniti già applicati ai campi vuoti; `enabled = false` se `sla_enabled = 0`.
- `shutter.level`: `''`, `warning`, `danger` come `webcamsShutterLevel()`.
- `rttys`: `{}` se `WEBCAMS_RTTYS_URL` non è impostato (`webcamsRttysUrls()`).
- `customer`: `null` senza cliente. Permesso: `webcam portal`.

### `POST webcams/{id}/tickets` (esteso)

Campi nuovi facoltativi, oltre a quelli della 1.2:

```json
{ "subject": "Monte Cavo: riscaldamento della custodia guasto", "message": "Regola: enclosure.heater ...",
  "type_code": "ISSUE", "severity_code": "HIGH", "category_code": "WEBCAM",
  "portal_event_id": 551240, "portal_rule": "enclosure.heater", "portal_alert_id": 4411,
  "due_date": "2026-11-21" }
```

- `portal_rule` (id della regola, `^[a-z][a-z0-9_]*\.[a-z0-9_.]+$`) e `portal_alert_id` vanno in `extraparams` del ticket (`portal_rule`, `portal_alert_id`), accanto a `ref_ext`.
- **Un ticket aperto per webcam e regola**: se esiste un ticket collegato alla webcam con lo stesso `portal_rule` e stato diverso da chiuso (8) o annullato (9), il modulo **non** ne crea un altro: risponde con quello, `"duplicate": true, "reason": "open_for_rule"`, e aggiunge `message` come messaggio privato. Il portal ha già il suo vincolo (un avviso aperto per webcam e regola); questo protegge da un database del portal ripristinato o da due portal.
- `due_date` (`AAAA-MM-GG`, facoltativo): scadenza dell'intervento dell'impegno di disponibilità. Il Ticket non ha un campo per la scadenza: si salva in `extraparams.due_date` e il portal la scrive anche nel messaggio.
- Il messaggio è testo semplice con a capo: il modulo lo converte con `dol_nl2br(dol_escape_htmltag())` se non è già HTML. Il ticket non manda email al cliente (`origin_email` vuoto, nessuna notifica ai contatti esterni).
- Risposta come nella 1.2, più `"status": 0` (stato del ticket).

### `PUT webcams/{id}/tickets/{ticket_id}`

Aggiorna o chiude un ticket aperto dal portal. Solo ticket collegati alla webcam `{id}` e con `extraparams.ref_ext` che inizia con `portal:` (`403` altrimenti: il portal non tocca i ticket aperti a mano).

```json
{ "message": "Condizione rientrata alle 18:20: ciclo di riscaldamento ok (+2,4 °C).", "close": 1,
  "severity_code": "HIGH", "portal_event_id": 551301 }
```

| Campo | Effetto |
|---|---|
| `message` | messaggio **privato** nel ticket (evento `TICKET_MSG_PRIVATE`, come "Aggiungi un messaggio privato"); obbligatorio |
| `close` | `1` = chiudi il ticket (`Ticket::close()`) se il suo stato è **non letto (0) o letto (1)**: nessuno ci sta lavorando. Con stato assegnato, in corso, in attesa (2-7) aggiunge solo il messaggio e risponde `"closed": false, "close_refused": "in_progress"`: lo chiude l'operatore |
| `severity_code` | facoltativo, aggiorna la gravità (solo verso l'alto, come gli avvisi del portal) |
| `portal_event_id` | idempotenza del messaggio: lo stesso valore due volte non aggiunge un secondo messaggio (`"duplicate": true`) |

Risposta: `{ "ok": true, "ticket_id": 341, "status": 8, "closed": true, "duplicate": false }`. `409` se il ticket è già chiuso o annullato (il portal allora non riprova e segna l'avviso `ticket_closed_by_operator`).

Lo stato dei ticket aperti il portal lo legge con l'API standard `GET /tickets/{id}` (`fk_statut`), che richiede il permesso Ticket → leggere.

### `POST webcams/{id}/availability`

Registro della disponibilità mensile, calcolata dal portal ([diagnosi-v1.md](diagnosi-v1.md#impegno-di-disponibilità)).

```json
{ "period": "2026-11", "availability_pct": 99.12, "expected_hours": 252, "excluded_hours": 4,
  "available_hours": 246, "incidents": 1, "downtime_hours": 2.0, "response_breaches": 0,
  "sla_enabled": 1, "target_pct": 98.0, "sla_met": 1, "final": 1 }
```

- Inserisce o aggiorna la riga `(fk_webcam, period)`; `fk_soc` = cliente attuale della webcam. Una riga con `final = 1` non si modifica più: `409` (le correzioni si fanno a mano in Dolibarr).
- Con `final = 1` aggiorna anche `availability_pct` e `availability_period` della webcam (se il periodo è il più recente) e crea un evento in agenda "Disponibilità novembre 2026: 99,12% (impegno 98%: rispettato)". Un impegno **non** rispettato crea l'evento con il codice `AC_WEBCAMS_SLA` per poterlo filtrare.
- Il portal manda il mese provvisorio (`final = 0`) al più una volta al giorno, il definitivo il 3 del mese dopo.
- Risposta: `{ "ok": true, "id": 12, "period": "2026-11", "final": true, "updated": true }`.
- `GET webcams/{id}/availability?from=2026-01&to=2026-12`: elenco dei mesi registrati, per il rapporto mensile e per i preventivi di rinnovo.

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
| `GET webcams/{id}/portal` (1.3) | portal | `webcam portal` |
| `PUT webcams/{id}/tickets/{ticket_id}` (1.3) | portal | `webcam portal` + ticket |
| `POST webcams/{id}/availability`, `GET webcams/{id}/availability` (1.3) | portal | `webcam portal` |

Dolibarr richiesto: 20 o successivo, come oggi (`need_dolibarr_version`).
