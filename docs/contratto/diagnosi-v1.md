# Diagnosi automatica: regole v1

Assistenza automatica della fase 2 di Webcam 2.0: il portal applica regole ai dati che ha già nel suo database (telemetria, foto, eventi) e ai log di VictoriaLogs, apre e chiude da solo gli **avvisi**, i **ticket** in Dolibarr e, quando serve, manda un **comando** al router. Le idee vengono da "Funzionalità originali per Viewlapse" (1 diagnosi della custodia, 4 vita della reflex, 7 controllo vetro e inquadratura) e dall'analisi dei servizi nel mondo (punto 3, impegno di disponibilità).

- Tabelle: [schema-mariadb-002.sql](schema-mariadb-002.sql) (migrazione 002, dopo [schema-mariadb.sql](schema-mariadb.sql)).
- Comandi al router: [api-portal-v1.md](api-portal-v1.md#comandi-al-router) e [comando-v1.schema.json](comando-v1.schema.json).
- Campi nuovi della telemetria usati dalle regole: [telemetria-v1.md](telemetria-v1.md#campi-per-la-diagnosi-contratto-11).
- Ticket, disponibilità e soglie del contratto di noleggio in Dolibarr: [api-dolibarr-v2.md](api-dolibarr-v2.md#diagnosi-e-disponibilità-modulo-13).

## Concetti

| Termine | Significato |
|---|---|
| **regola** | controllo con un id stabile (`enclosure.seal`), soglie predefinite modificabili per webcam (`rule_config`) |
| **valutazione** | un giro della regola su una webcam; il risultato (`ok`, `alarm`, `unknown`) e i valori misurati vanno in `rule_state` |
| **avviso** (`alerts`) | nasce quando la condizione dura abbastanza (`for`), si chiude quando è rientrata abbastanza a lungo (`clear_for`). **Al massimo un avviso aperto per webcam e regola** (indice unico) |
| **azione** | cosa succede all'apertura: `ticket` (Dolibarr), `agenda` (evento in agenda Dolibarr), `command` (comando al router), `panel` (solo pannello del portal) |
| **gravità** | quella di `events.severity`: `notice`, `warning`, `error`, `critical` |

Ogni apertura, aggravamento e chiusura scrive anche una riga in `events` (`source = portal`): `alert.open`, `alert.update`, `alert.close`, con `data = { "rule": "...", "alert_id": ..., ... }` e `events.alert_id` valorizzato. Così la storia della webcam resta in un solo posto.

### Valutazione

| Gruppo | Quando | Script del portal (0.2) |
|---|---|---|
| custodia, camera ferma, telemetria ferma, otturatore | ogni 5 minuti (cron), solo per le webcam con dati nuovi o con avvisi aperti | `bin/diagnose.php` |
| foto (vetro, inquadratura) | dopo l'analisi delle foto nuove, ogni 5 minuti | `bin/photo-metrics.php`, poi le regole `glass.*`, `camera.moved` |
| log | ogni 15 minuti, una sola query `stats_query` per tutte le webcam | `bin/diagnose.php --logs` |
| disponibilità | ogni ora per il giorno in corso, alle 00:30 (fuso della webcam) il giorno chiuso, il 3 del mese il mese chiuso | `bin/availability.php` |

Regole comuni a tutte le valutazioni:

1. Si usano solo dati con `time_synced = 1`. Un campione senza ora vera non apre né chiude avvisi.
2. **Dati insufficienti** (sensore che manca, meno campioni del minimo, sensore esterno non installato): risultato `unknown`. `unknown` **non** apre e **non** chiude un avviso; dopo 24 ore di `unknown` un avviso aperto resta aperto con la nota "dati insufficienti".
3. **Manutenzione**: con `sys.maintenance = true` nell'ultimo campione, o `webcams.status <> 1`, le regole non aprono avvisi nuovi (tranne `telemetry.stalled` per `status = 1`); quelli aperti restano aperti.
4. **Silenzio**: un operatore può sospendere una regola per una webcam fino a una data (`rule_state.muted_until`): la regola si valuta lo stesso ma non apre avvisi né ticket.
5. **Isteresi**: ogni regola ha `for` (la condizione deve durare almeno tanto) e `clear_for` (deve essere rientrata almeno tanto), con soglie di chiusura più larghe di quelle di apertura dove ha senso.
6. **Riapertura**: se la stessa regola scatta di nuovo entro `reopen_within` (predefinito 24 ore) dalla chiusura, il portal **riapre lo stesso avviso** (stesso id, `reopen_count + 1`) invece di crearne uno nuovo; se il suo ticket è ancora aperto in Dolibarr ci aggiunge un messaggio, se è stato chiuso ne apre uno nuovo.
7. **Aggravamento**: la gravità di un avviso aperto può solo salire (`alert.update`); scende solo con una chiusura e una nuova apertura.

## Elenco delle regole

Soglie predefinite; tutte modificabili per webcam in `rule_config.params` (stessi nomi). "Ticket" vuol dire ticket del modulo Ticket di Dolibarr, uno per avviso (vedi [Ticket in Dolibarr](#ticket-in-dolibarr)).

| id | Cosa rileva | Gravità | Azione | Chiusura automatica |
|---|---|---|---|---|
| `enclosure.seal` | guarnizione che non tiene | warning | ticket | sì, dopo 7 giorni puliti |
| `enclosure.heater` | riscaldamento guasto | error | ticket | sì, al primo ciclo che scalda |
| `enclosure.overheat` | sole diretto sulla custodia o ventola ferma | warning → error | agenda; ticket se ripetuto o oltre 45 °C | sì, dopo 3 giorni puliti |
| `enclosure.fan` | ventola comandata ma ferma (serve `fan_rpm`, scheda futura) | warning | ticket | sì |
| `telemetry.stalled` | nessun dato dal router (webcam spenta o senza rete) | error | ticket | sì, quando torna |
| `camera.stalled` | router vivo ma nessuna foto nelle ore di scatto | error | ticket | sì, quando tornano le foto |
| `glass.fog` | vetro appannato (foto sfocata + umidità alta) | notice → warning | comando `heater.boost`; ticket se non basta | sì |
| `glass.dirty` | vetro sporco (foto sfocata col secco) | warning | ticket | sì, dopo la pulizia |
| `camera.moved` | inquadratura spostata (vento, urti) | warning | ticket | sì, se torna; altrimenti nuovo riferimento dell'operatore |
| `shutter.life` | otturatore al 90% e a fine vita | notice / warning | panel (l'agenda la scrive Dolibarr) | al cambio di fotocamera |
| `logs.reboots` | riavvii inattesi ripetuti | warning → error | agenda; ticket se persistono | sì, dopo 72 ore |
| `logs.camera_errors` | errori ripetuti della fotocamera | warning → error | agenda; ticket se persistono | sì, dopo 48 ore |

### `enclosure.seal`: guarnizione

In una custodia chiusa l'umidità **assoluta** interna (`ah_in`, g/m³) cambia molto lentamente; se segue quella esterna (`ah_ext`) entro poche ore, entra aria. L'umidità assoluta non dipende dalla temperatura, quindi il confronto vale anche tra giorno e notte.

- **Ingresso**: `measurements` (`ah_in`, `ah_ext`, `heater`, `fan`, `rh_in`) a finestre di `window_h` ore; serve il sensore esterno (`ext_sensor_ok = 1`).
- **Finestra valida**: riscaldamento e ventola spenti in tutti i campioni, almeno l'80% dei campioni presenti, `rh_in < 95` (niente condensa, che sposta l'acqua tra aria e pareti) e variazione esterna `|Δah_ext| ≥ min_delta_ext`.
- **Misura**: `follow = Δah_in / Δah_ext` tra primo e ultimo quarto d'ora della finestra (medie, per togliere il rumore). Custodia sana: `follow` vicino a 0; guarnizione rotta: vicino a 1.
- **Apertura**: `follow ≥ open_follow` in almeno `open_windows` finestre valide negli ultimi 7 giorni, in almeno 2 giorni diversi.
- **Chiusura**: almeno 2 finestre valide con `follow < close_follow` e nessuna sopra `open_follow` negli ultimi `clear_for`.
- **Soglie**: `window_h` 6, `min_delta_ext` 1,5 g/m³, `open_follow` 0,6, `open_windows` 3, `close_follow` 0,3, `clear_for` 7 giorni.
- **Ticket**: "Guarnizione della custodia da controllare", con le finestre e i valori di `follow`.

### `enclosure.heater`: riscaldamento guasto

- **Ingresso**: `measurements` (`heater`, `t_in`, `dp_in`, `t_ext`) e i campi nuovi `climate.heater_on_since`, `climate.dewpoint_margin`.
- **Ciclo valido**: riscaldamento acceso senza interruzioni da almeno `on_min` minuti (da `heater_on_since`, o da `on_min/5` campioni consecutivi con `heater = 1`), nessuno scatto in corso che lo spegne (`heat_reason` diverso da `capture`).
- **Guasto**, alla fine del ciclo valido, se valgono entrambe:
  1. la temperatura interna non è sopra il punto di rugiada interno più il margine: `t_in - dp_in < dewpoint_margin` (`dewpoint_margin` dal router, altrimenti 3 °C, il predefinito di `heat_dewpoint_margin`);
  2. la custodia non si è scaldata rispetto all'esterno: `(t_in - t_ext)` alla fine meno `(t_in - t_ext)` all'inizio del ciclo `< min_rise` (senza sensore esterno: `t_in` fine meno inizio `< min_rise`).

  La seconda condizione evita il falso allarme quando fuori la temperatura scende proprio mentre il riscaldamento lavora.
- **Apertura**: guasto in `open_cycles` cicli validi consecutivi (cicli diversi o finestre di `on_min` successive dello stesso ciclo lungo).
- **Chiusura**: un ciclo valido che scalda (`t_in - dp_in ≥ dewpoint_margin` oppure aumento `≥ 2 × min_rise`).
- **Soglie**: `on_min` 30, `min_rise` 1,0 °C, `open_cycles` 2.
- **Gravità** error, **ticket** "Riscaldamento della custodia guasto (resistenza o relè)". Senza riscaldamento il vetro si appanna o ghiaccia: è un guasto da uscita.

### `enclosure.overheat`: sole diretto o ventola ferma

- **Ingresso**: `measurements` (`t_in`, `t_ext`, `heater`, `fan`, `panel_w`), `fan_rpm` se c'è, alba e tramonto dalla posizione della webcam.
- **Condizione**: riscaldamento spento e `t_in - t_ext ≥ delta_c` per almeno `for` (6 campioni), oppure `t_in ≥ abs_warn_c` (anche senza sensore esterno).
- **Causa** (in `alerts.details.cause`): `sun` se di giorno (sole sopra l'orizzonte) e, con il Victron, `panel_w` sopra il 50% del massimo della giornata; `fan` se la ventola è comandata accesa (`fan = 1`) e `fan_rpm = 0`; altrimenti `unknown` (di notte è sospetto: possibile corto o resistenza bloccata accesa con `heater` che dice spento).
- **Azione**: alla prima volta `agenda` (warning). Diventa **ticket** (error) se `t_in ≥ abs_error_c` (la EOS 2000D è garantita fino a 40 °C) o se la condizione si ripete in `repeat_days` giorni su 7 (custodia da schermare o da girare).
- **Chiusura**: `clear_for` 3 giorni senza la condizione (con soglia di chiusura `delta_c - 3`).
- **Soglie**: `delta_c` 15 °C, `for` 30 min, `abs_warn_c` 40 °C, `abs_error_c` 45 °C, `repeat_days` 3.

### `enclosure.fan`: ventola ferma

Dormiente finché il router non manda `climate.fan_rpm` (serve un firmware della scheda con il tachimetro della ventola; con `fan_rpm` sempre `null` la regola resta `unknown`).

- **Condizione**: `fan = 1` e `fan_rpm < min_rpm` in `for` campioni consecutivi. **Apertura** dopo 3 campioni (15 min), **chiusura** dopo 3 campioni con `fan_rpm ≥ min_rpm` mentre è comandata. `min_rpm` 300. Ticket warning.

### `telemetry.stalled`: webcam muta

Sostituisce l'evento `webcam.silent` previsto in v1 (mai implementato).

- **Ingresso**: `webcams.last_telemetry_at`, `webcams.last_photo_at`, `webcams.last_seen_at` (ultima richiesta autenticata di qualsiasi tipo).
- **Apertura**: nessuna richiesta autenticata da `hours` ore (predefinito 2; con l'impegno di disponibilità attivo `min(hours, sla_offline_hours)`). Si valuta anche in manutenzione: un router in manutenzione continua a mandare telemetria.
- **Chiusura**: arriva un lotto di telemetria con almeno un campione più recente dell'apertura **e** passano 15 minuti con richieste regolari (evita di chiudere e riaprire con una rete che va e viene).
- **Gravità** error, **ticket** "Webcam senza collegamento da N ore", con l'ultima telemetria nota (batteria, segnale, coda), utile per capire se è la batteria scarica o la rete.
- Mentre `telemetry.stalled` è aperto, `camera.stalled` non si valuta: un solo ticket per lo stesso guasto.

### `camera.stalled`: camera ferma

- **Ingresso**: `photos.taken_at` e `received_at`, le **ore di scatto previste** (sotto), `measurements.cam_scheduled` dal router.
- **Ore di scatto previste**: le ore dentro `webcam_service.sla_hours` e `sla_weekdays` (vuoti = tutto il giorno, tutti i giorni) in cui il router non è in manutenzione e, se manda il campo, `camera.scheduled = true`. Esempio cantiere: `07:00-19:00`, `12345`.
- **Apertura**: telemetria che arriva (`telemetry.stalled` chiuso), nessuna foto **ricevuta** con `taken_at` nelle ultime `hours` ore previste (predefinito 3; con impegno di disponibilità `sla_offline_hours`). Le ore fuori orario non contano: una camera ferma alle 18:30 con orario `07:00-19:00` apre l'avviso alle 09:30 del giorno dopo.
- **Chiusura**: due foto nuove con `taken_at` successivo all'apertura.
- **Gravità** error, **ticket** "Nessuna foto da N ore di scatto", con l'ultima foto, gli ultimi eventi `camera.*` e lo stato `camera.present`/`camera.power` dell'ultimo campione.

### `glass.fog`: vetro appannato

Lavora sulle metriche delle foto (sotto, [Analisi delle foto](#analisi-delle-foto)).

- **Condizione**: due foto analizzate di fila (di giorno) con `blur_ratio < fog_ratio` **e** umidità alta dentro la custodia: `rh_in ≥ rh_in_min` oppure `t_in - dp_in < spread_max` (campione più vicino alla foto, entro 10 minuti).
- **Nebbia vera**: se anche fuori c'è saturazione (`rh_ext ≥ 97` e `t_ext - dp_ext < 1`) la foto è classificata `fog_ext` e la regola non scatta: è il meteo, non il vetro.
- **Azione** all'apertura (gravità notice): comando [`heater.boost`](api-portal-v1.md#comandi-al-router) per `boost_s` secondi, solo se il riscaldamento è abilitato (`heating_enabled`) e non già acceso o in boost. Massimo `boost_max_day` boost al giorno per webcam.
- **Dopo il boost**: se la prima foto analizzata dopo la fine del boost torna nitida (`blur_ratio ≥ clear_ratio`) l'avviso si chiude ("risolto con il riscaldamento"). Se resta sfocata dopo `boost_fail` boost in 6 ore, l'avviso sale a warning e apre un **ticket** "Vetro appannato nonostante il riscaldamento" (insieme a `enclosure.heater` o `enclosure.seal` indica la causa).
- **Chiusura**: due foto di fila con `blur_ratio ≥ clear_ratio`.
- **Soglie**: `fog_ratio` 0,5, `clear_ratio` 0,75, `rh_in_min` 85%, `spread_max` 2 °C, `boost_s` 1800, `boost_max_day` 4, `boost_fail` 2.

### `glass.dirty`: vetro sporco

- **Condizione**: foto sfocate col secco: `blur_ratio < dirty_ratio` con `rh_in < 70`, `t_in - dp_in ≥ 5` e `rh_ext < 85` (niente pioggia o nebbia), in almeno `min_photos` foto analizzate distribuite su almeno `min_hours` ore e 2 giorni diversi.
- **Apertura** dopo le condizioni sopra; **ticket** warning "Vetro della custodia da pulire" con la foto di riferimento e una foto attuale.
- **Chiusura**: quattro foto analizzate di fila con `blur_ratio ≥ clear_ratio` (dopo la pulizia), oppure l'operatore che sceglie un nuovo riferimento.
- **Soglie**: `dirty_ratio` 0,6, `clear_ratio` 0,8, `min_photos` 6, `min_hours` 24.

### `camera.moved`: inquadratura spostata

- **Condizione**: foto nitida (`blur_ratio ≥ 0,7`) ma spostata rispetto al riferimento: `shift_px ≥ shift_open` (pixel sulla versione larga 640) con `match_score ≥ 0,5`, oppure `match_score < match_min` (inquadratura molto diversa: camera girata o coperta).
- **Apertura** dopo `photos` foto di fila; **ticket** warning "Inquadratura spostata di N pixel" con le due foto.
- **Chiusura**: `photos` foto di fila con `shift_px < shift_close` (la camera è tornata a posto, raro), oppure l'operatore conferma la nuova inquadratura nel pannello: nuovi riferimenti per tutte le fasce, avviso chiuso con `close_reason = operator`.
- **Soglie**: `shift_open` 8 px (1,25% della larghezza), `shift_close` 3 px, `match_min` 0,35, `photos` 3.

### `shutter.life`: vita della reflex

**Fonte unica degli avvisi in Dolibarr è il modulo** (dalla 1.2): `report` e `POST webcams/{id}/counters` scrivono già un evento in agenda quando gli scatti superano `WEBCAMS_SHUTTER_WARN_PCT` (90%) e `WEBCAMS_SHUTTER_LIFE` (100.000), una volta per soglia e per fotocamera. Il portal **non** manda eventi né ticket per l'otturatore, per non creare doppioni.

- Il portal calcola lo stesso livello per il pannello e per il rapporto mensile, con le **stesse soglie lette da Dolibarr** (`GET webcams/{id}/portal`, campo `shutter`) e salvate in `webcam_service`; senza Dolibarr usa `cameras.shutter_rated` o 100.000 e 90%.
- Scatti usati: `shutter_count` se la fotocamera lo espone, altrimenti `shots_total` (stessa regola di `webcamsShotsUsed()` nel modulo).
- Livelli: notice al 90% (`alert.open`), warning a fine vita (`alert.update`). Gli eventi `alert.*` di questa regola hanno `data.forward = false`: la sincronizzazione verso l'agenda li salta.
- **Chiusura**: cambio di fotocamera (numero di serie diverso) o contatore sotto la soglia del 90%.

### `logs.reboots`: riavvii inattesi

- **Ingresso**: eventi `boot` del database (dal router o dal cambio di `boot_id`) e, per i riavvii che il router non ha fatto in tempo a mandare, il conteggio di VictoriaLogs (query di webcam-logserver, `docs/query.md`, "Riavvii inattesi"):

  ```logsql
  _time:24h "Linux version" | stats by (hostname) count() avvii
  ```

  Si prende il massimo tra i due conteggi (non la somma: è lo stesso riavvio visto due volte). I conteggi per giorno vanno in `log_counters`.
- **Inatteso**: un avvio **non** preceduto, nei 15 minuti prima, da `app.version_change`, `config.change`, `maintenance.on/off`, un comando del portal o un intervento dell'operatore. `board.power_cycle` (watchdog della scheda) conta come inatteso.
- **Apertura**: `open_24h` riavvii inattesi in 24 ore → agenda (warning). Diventa **ticket** (error) con `ticket_24h` in 24 ore, o almeno 2 al giorno in `ticket_days` giorni su 7 (alimentazione, batteria, watchdog: nel ticket la tensione minima della batteria delle ultime 24 ore).
- **Chiusura**: `clear_for` 72 ore senza riavvii inattesi.
- **Soglie**: `open_24h` 2, `ticket_24h` 4, `ticket_days` 3.

### `logs.camera_errors`: errori ripetuti della fotocamera

- **Ingresso**: eventi `camera.error`, `camera.missing`, `camera.power_cycle` del database e i conteggi di VictoriaLogs:

  ```logsql
  _time:24h level:error ("fotocamera non trovata" OR "non è ricomparsa su USB" OR "scatto fallito") | stats by (hostname) count() errori
  _time:24h "primo tentativo di scatto fallito" | stats by (hostname) count() tentativi
  ```

  Errori: massimo tra eventi `camera.error`/`camera.missing` e righe di log. Tentativi: massimo tra `camera.power_cycle` e righe "primo tentativo".
- **Apertura**: `open_errors` errori in 24 ore → agenda (warning). **Ticket** (error) con `ticket_errors` errori in 24 ore o almeno `open_errors` errori al giorno per 3 giorni di fila. Nota nel ticket (senza aprirlo) se i tentativi falliti superano `retry_warn` al giorno per 3 giorni: cavo USB o fotocamera che peggiorano.
- **Chiusura**: `clear_for` 48 ore sotto `open_errors`.
- **Soglie**: `open_errors` 3, `ticket_errors` 10, `retry_warn` 5.

### Log: webcam e hostname

VictoriaLogs riconosce la webcam solo dal campo `hostname`. Il portal usa i log di una webcam solo se `webcams.hostname` è **unico** tra le webcam in servizio; altrimenti la regola vale `unknown` e il pannello mostra "hostname non univoco". L'hostname predefinito dei router è lo stesso per tutti: va impostato (per esempio uguale allo slug) all'installazione. Se VictoriaLogs non risponde entro 5 secondi le regole `logs.*` usano solo il database.

## Analisi delle foto

Il portal calcola le metriche con **GD** (presente in XAMPP e nell'immagine Docker), **fuori dalla richiesta di upload**: `bin/photo-metrics.php` dal cron, con `memory_limit` di almeno 256 MB (un JPEG da 24 MP decompresso occupa circa 100 MB).

| Passo | Dettaglio |
|---|---|
| Quali foto | `mode = day`, al massimo una ogni `metrics_every_min` (30) minuti per webcam, più la prima foto dopo la fine di ogni `heater.boost` |
| Riduzione | `imagecreatefromjpeg` + `imagescale` a **640 px di larghezza**, scala di grigi (0,299 R + 0,587 G + 0,114 B) |
| `brightness` | media dei grigi (0-255) |
| `contrast` | deviazione standard dei grigi |
| `sharpness` | **varianza del laplaciano** (nucleo 3×3 `0 1 0 / 1 -4 1 / 0 1 0`) sull'immagine da 640 px; anche la mediana su una griglia 4×4 di riquadri (`sharpness_tiles`), meno sensibile a un cielo uniforme |
| `dhash` | impronta di 64 bit (immagine 9×8, differenza tra pixel vicini), per un confronto veloce |
| `shift_x`, `shift_y`, `match_score` | spostamento rispetto al riferimento: correlazione normalizzata dei gradienti, ricerca grossolana ±12 px a 160 px di larghezza, poi rifinitura ±2 px a 640; `match_score` 0-1 è la correlazione al punto migliore |
| `blur_ratio` | `sharpness_tiles / sharpness_tiles del riferimento`, solo se la luminosità è entro ±35% di quella del riferimento (altrimenti `null`: un cielo coperto contro uno di sole non si confronta) |
| Foto scartate | `brightness < 40` (crepuscolo) o `> 230` (sovraesposta, neve al sole): `classification = skipped`, `skip_reason` |

### Foto di riferimento (`reference_photos`)

- Una per **fascia oraria**: l'ora locale della webcam (0-23), solo le ore di giorno.
- Scelta automatica: la foto più nitida della fascia degli ultimi 14 giorni con `time_synced = 1`, luminosità tra 70 e 200, `rh_ext < 80` e nessun avviso `glass.*` o `camera.moved` aperto in quel momento. Si rinnova ogni 7 giorni (le stagioni cambiano luce e vegetazione), mai mentre è aperto un avviso `glass.*` o `camera.moved`.
- Si rifanno tutte da capo quando cambia la fotocamera (numero di serie) o quando l'operatore conferma una nuova inquadratura. L'operatore può anche scegliere a mano la foto di una fascia (`chosen_by = operator`, non sostituita in automatico).
- Il portal salva accanto alla foto la versione in grigi da 640 px (`storage/reference/<webcam_id>/<band>.png`), per non riaprire ogni volta il JPEG originale, che dopo 90 giorni può essere cancellato dalla retention.
- Senza riferimento per la fascia (prima settimana di servizio) `blur_ratio` e `shift_*` sono `null` e le regole valgono `unknown`.

## Impegno di disponibilità

Come OxBlue: "se la camera non invia per più di N ore interveniamo entro X giorni", con una percentuale di disponibilità mensile da mostrare al cliente e da mettere nel rapporto mensile.

### Soglie del contratto di noleggio

Stanno in **Dolibarr, sulla scheda della webcam** (colonne `sla_*` del modulo 1.3, compilate da chi fa il contratto) e il portal le legge con `GET webcams/{id}/portal` a ogni sincronizzazione, copiandole in `webcam_service`. Senza Dolibarr si impostano nel pannello del portal (`webcam_service.source = portal`).

| Campo | Significato | Predefinito |
|---|---|---|
| `sla_enabled` | impegno attivo per questa webcam | 0 |
| `sla_offline_hours` | **N**: ore senza foto (nelle ore previste) dopo le quali scatta l'impegno di intervento | 3 |
| `sla_response_days` | **X**: giorni entro cui intervenire (calendario) | 3 |
| `sla_target_pct` | disponibilità mensile promessa | 98,0 |
| `sla_hours` | ore di scatto previste, ora locale `HH:MM-HH:MM` (vuoto = 24 ore) | vuoto |
| `sla_weekdays` | giorni previsti, cifre ISO 1 = lunedì ... 7 = domenica (vuoto = tutti) | vuoto |

Con `sla_enabled = 1`:

- `camera.stalled` e `telemetry.stalled` usano `sla_offline_hours` come soglia (al massimo; `telemetry.stalled` resta a 2 ore se N è più grande);
- l'avviso e il suo ticket ricevono una **scadenza** `alerts.due_at = opened_at + sla_response_days`; il messaggio del ticket la riporta ("Intervento entro il 21/11/2026");
- a 24 ore dalla scadenza con l'avviso ancora aperto il portal aggiunge un messaggio al ticket e alza la gravità a critical; alla scadenza superata segna `alerts.sla_breached = 1` (conta nel mese come intervento in ritardo).

### Calcolo

Granularità: **l'ora** (ora locale della webcam), perché le foto sono ogni 5-15 minuti e un'ora è facile da spiegare al cliente ("ore con almeno una foto").

| Valore | Definizione |
|---|---|
| ora **prevista** | dentro `sla_hours`/`sla_weekdays` (tutte se vuoti), con `webcams.status = 1` |
| ora **esclusa** | prevista ma con manutenzione attiva (`sys.maintenance` in almeno un campione dell'ora, o eventi `maintenance.on/off`), con un intervento dell'operatore in corso, o dentro un periodo di `availability_exclusions` (forza maggiore concordata, lavori chiesti dal cliente) |
| ora **disponibile** | prevista, non esclusa, con almeno una foto con `taken_at` nell'ora e `time_synced = 1`, ricevuta dal portal (anche in ritardo dalla coda offline) |
| **disponibilità** | `disponibili / (previste - escluse) × 100`, due decimali. Con 0 ore utili: `null` |

- `availability_daily` (una riga per webcam e giorno) si ricalcola ogni ora per il giorno in corso e per i 7 giorni prima (foto che arrivano in ritardo dalla coda).
- `availability_monthly` somma i giorni; è **provvisoria** fino al 3 del mese dopo, poi **definitiva** (`final = 1`, non si ricalcola più; una correzione la fa l'operatore e resta tracciata in `events`).
- Nel mese si contano anche gli **incidenti** (`incidents`: avvisi `camera.stalled` e `telemetry.stalled` aperti nel mese), `response_breaches` (quelli con `sla_breached`) e `sla_met = pct ≥ sla_target_pct AND response_breaches = 0`.
- Il mese definitivo va a Dolibarr con `POST webcams/{id}/availability`; quello provvisorio si può mandare ogni giorno con `final = 0`.
- Il cliente vede nel suo pannello la percentuale del mese in corso (provvisoria) e quella dei mesi chiusi; il rapporto mensile (fase successiva) legge `availability_monthly`.

## Ticket in Dolibarr

Per ogni avviso con azione `ticket` il portal usa le API del modulo `webcams` 1.3 ([api-dolibarr-v2.md](api-dolibarr-v2.md#diagnosi-e-disponibilità-modulo-13)):

| Momento | Chiamata | Note |
|---|---|---|
| apertura | `POST webcams/{id}/tickets` con `portal_event_id` (l'evento `alert.open`), `portal_rule` e `portal_alert_id` | idempotente; un ticket aperto per webcam + regola anche lato Dolibarr |
| aggravamento, ripetizione, scadenza vicina | `PUT webcams/{id}/tickets/{ticket_id}` con `message` | messaggio privato nel ticket |
| chiusura dell'avviso | `PUT webcams/{id}/tickets/{ticket_id}` con `message` e `close = 1` | Dolibarr chiude solo se nessuno ci sta lavorando (stato non letto o letto); altrimenti aggiunge il messaggio e lascia la chiusura all'operatore |
| stato | `GET /tickets/{ticket_id}` (API standard), a ogni sincronizzazione per gli avvisi aperti | se l'operatore chiude il ticket con l'avviso ancora aperto, l'avviso resta aperto ma `ticket_closed_by_operator = 1`: nessun nuovo ticket finché l'avviso non si chiude e si riapre oltre `reopen_within` |

Gli eventi `alert.open`/`alert.update`/`alert.close` delle regole con ticket **non** vanno in agenda (ci va il ticket); quelli delle regole con azione `agenda` sì, come oggi gli eventi con gravità ≥ warning.

### Testo del ticket

Oggetto: `<nome della webcam>: <titolo della regola>` (es. "Monte Cavo: riscaldamento della custodia guasto"). Messaggio, testo semplice in italiano con a capo:

```
Regola: enclosure.heater (riscaldamento guasto), gravità: errore
Dal: 18/11/2026 15:40 (ora della webcam, Europe/Rome)

Cosa è stato misurato:
- riscaldamento acceso dalle 15:05 (35 minuti)
- interno: 2,1 °C, punto di rugiada 1,4 °C (margine 0,7 °C, richiesto 3,0 °C)
- differenza interno-esterno: da 0,4 a 0,6 °C (aumento 0,2 °C, richiesto 1,0 °C)
- stesso esito nel ciclo delle 13:20

Cosa controllare: resistenza, relè della scheda (comando H), cablaggio.
Intervento entro: 21/11/2026 (impegno di disponibilità, 3 giorni)

Portal: https://portal.viewlapse.com/admin/webcams/12/alerts/4411
Ultima foto: https://portal.viewlapse.com/admin/webcams/12/photos/918273
Terminale (rttys): https://rttys.viewlapse.com/api/connect/WC-9483C412ABCD
LuCI (rttys): https://rttys.viewlapse.com/api/web/WC-9483C412ABCD/http/127.0.0.1%3A80%2F
```

Gli URL di rttys vengono da `GET webcams/{id}/portal` (impostazione `WEBCAMS_RTTYS_URL` del modulo, stessi link della scheda webcam). Nessun token, password o IP pubblico nel testo.

Codici dei dizionari del Ticket: `type_code = ISSUE`, `category_code = WEBCAM` (se esiste), `severity_code` da gravità: notice → `LOW`, warning → `NORMAL`, error → `HIGH`, critical → `BLOCKING` (predefiniti di Dolibarr; se mancano, quelli del modulo Ticket).

## Comandi mandati dalle regole

| Regola | Comando | Limiti |
|---|---|---|
| `glass.fog` | `heater.boost` `{ "duration_s": 1800 }` | solo con `heating_enabled`; massimo 4 al giorno; scadenza 30 minuti (un boost che arriva tardi non serve più) |
| `camera.stalled` (facoltativo, `params.capture_now = true`) | `capture.now` | una volta per avviso, per sapere subito se la fotocamera risponde |

Tutti i comandi, anche quelli manuali dell'operatore, seguono [api-portal-v1.md](api-portal-v1.md#comandi-al-router); l'esito torna con l'evento `command.ack` e si vede nel pannello accanto all'avviso.
