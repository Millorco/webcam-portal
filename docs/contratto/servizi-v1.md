# Servizi del portal: v1

Versione del documento: **1.0** (contratto 1.3, fase 3b: servizi che si vendono). Regole dei servizi che il portal produce dai dati che ha già (foto, telemetria, metriche delle foto, eventi, log): sovrimpressione, foto buone, timelapse, foto del giorno, rapporto mensile, registro delle impronte e giornate di maltempo, statistiche di visualizzazione, bot Telegram, conteggi dei log.

- Tabelle: [schema-mariadb-004.sql](schema-mariadb-004.sql) (migrazione 004).
- Come i siti leggono i servizi: [api-siti-v1.md](api-siti-v1.md) (documento 1.1).
- Listino, opzioni, archivio, documenti ed email in Dolibarr, **matrice livello → funzioni**: [api-dolibarr-v2.md](api-dolibarr-v2.md#listino-e-servizi-modulo-14) (documento 2.2 = modulo 1.4).
- Metriche delle foto e regole già esistenti: [diagnosi-v1.md](diagnosi-v1.md).

Fonti delle idee: "Servizi webcam nel mondo" (M2 sovrimpressione, M4 listino, M6 stesso momento, M7 timelapse, M8 statistiche, M10 filmato finale, M11 foto del giorno, M12 sponsor, M13 archivio) e "Funzionalità originali" (O2 maltempo certificato, O3 rapporto mensile, O8 timelapse pulito, O10 bot Telegram).

## Principi

1. **L'originale non si tocca mai.** Il JPEG ricevuto dal router resta byte per byte com'è su disco (`photos.storage_path`) e il suo SHA-256 resta quello di `photos.sha256`: è quello che il registro e i certificati provano. Sovrimpressioni, ritagli, riduzioni e video sono **file derivati**, in cartelle a parte, rigenerabili.
2. **Niente librerie esterne**: GD per le immagini (anche il testo, con FreeType), PDF con un generatore PHP del portal, `ffmpeg` solo per i video e solo se c'è. Niente ImageMagick, niente composer.
3. **Fuori dalle richieste**: tutti i lavori pesanti girano dal cron (`bin/*.php`), uno alla volta per tipo (file di blocco in `storage/locks/`), mai durante l'upload di una foto o una richiesta dell'API dei siti (tranne le riduzioni al volo che già esistono).
4. **Solo dati con l'ora vera** per tutto ciò che si certifica o si conta (`time_synced = 1`), come in [diagnosi-v1.md](diagnosi-v1.md#valutazione). Per le cose solo belle da vedere (timelapse, foto del giorno) le foto con l'ora non sincronizzata si usano lo stesso, nella posizione della loro ora.
5. **Funzione spenta = niente lavoro**: una webcam senza la funzione nel suo listino non genera timelapse, rapporti, certificati né statistiche. Il **registro delle impronte** invece si fa per tutte le webcam in servizio: costa poco ed è la prova che serve se il cliente compra il certificato dopo.
6. **Giorni e mesi nel fuso della webcam** (`webcams.timezone`), come nel resto del contratto.

### Funzioni, listino e server

Ogni servizio corrisponde a una **funzione** con un nome stabile (`overlay`, `timelapse`, ...). Le funzioni attive per una webcam si ricavano dal livello di listino e dalle opzioni letti da Dolibarr (matrice in [api-dolibarr-v2.md](api-dolibarr-v2.md#matrice-livello--funzioni)). Il calcolo lo fa **solo il portal** (`Features::forWebcam()`), Dolibarr manda livello e opzioni; i plugin leggono il risultato nel campo `features` dell'API dei siti. Le funzioni dicono cosa il cliente **ha comprato**; quello che il server sa **produrre** è a parte (`server` di `whoami`): senza `ffmpeg` non si fanno timelapse nuovi ma quelli già fatti restano visibili, senza bot configurato `telegram` non lavora.

## Configurazione del portal

Nuove sezioni di `config/config.php` (tutti i valori sono i predefiniti; ognuna si può spegnere con `enabled => false`):

```php
'ffmpeg' => [
	'path' => '',          // FFMPEG_PATH: '' = cerca "ffmpeg" nel PATH; percorso assoluto (anche ffmpeg.exe); 'off' = spento
	'timeout' => 1800,     // secondi per un video
	'threads' => 1,
	'nice' => 10,          // solo Linux
],
'overlay' => [
	'enabled' => true,
	'font' => '',          // TTF; '' = assets/fonts/DejaVuSans.ttf (licenza libera, nel repository)
	'font_bold' => '',     // '' = assets/fonts/DejaVuSans-Bold.ttf
	'jpeg_quality' => 85,
],
'scores' => [
	'every_min' => 15,     // al più una foto analizzata per il punteggio ogni N minuti per webcam
	'max_per_run' => 400,
],
'timelapse' => [
	'enabled' => true,
	'temp_dir' => '',      // '' = storage/tmp/timelapse
	'keep_days' => ['day' => 30, 'week' => 180, 'month' => 0, 'custom' => 90, 'final' => 0], // 0 = finché c'è archivio
],
'photo_of_day' => [ 'enabled' => true, 'time' => 'sunset+45' ], // HH:MM locale o sunset+<minuti>
'reports' => [ 'enabled' => true, 'day' => 3, 'time' => '06:00', 'link_url' => '' ], // link_url: pagina del cliente nel PDF
'weather' => [ 'enabled' => true ],     // soglie: sezione "Giornate di maltempo"
'archive' => [ 'default_days' => 30 ],  // accesso all'archivio dopo la fine dell'assegnazione senza opzione
'stats' => [ 'enabled' => true, 'dedupe_min' => 30 ],
'telegram' => [
	'enabled' => false,
	'bot_token' => '',            // TELEGRAM_BOT_TOKEN (mai nei log)
	'mode' => 'poll',             // 'webhook' (VPS) o 'poll' (XAMPP, senza URL pubblico)
	'webhook_secret' => '',       // TELEGRAM_WEBHOOK_SECRET, 32+ caratteri casuali
	'code_ttl_days' => 7,
	'quiet_hours' => '22:00-07:00',
	'daily_limit' => 20,
	'forget_days' => 30,
],
```

### Script e orari (cron)

| Script | Quando | Cosa fa |
|---|---|---|
| `bin/photo-scores.php` | ogni 5 minuti | punteggio delle foto nuove ([Foto buone](#foto-buone-o8)) |
| `bin/register.php` | ogni ora; il sigillo alle 01:30 locali del giorno dopo | elenco delle impronte, classificazione meteo, invio a Dolibarr |
| `bin/timelapse.php` | ogni 10 minuti (prende un lavoro dalla coda); crea i lavori automatici alle 01:00 locali | timelapse |
| `bin/photo-of-day.php` | ogni 15 minuti | sceglie la foto del giorno all'ora configurata |
| `bin/reports.php` | ogni ora (lavora dal giorno `reports.day` alle `reports.time`) | rapporto mensile e certificato del mese, invio a Dolibarr |
| `bin/stats.php` | ogni ora | pulizia di `view_visitors` e `view_salts`, contatori delle immagini dal file di appoggio |
| `bin/telegram.php` | ogni minuto (`--poll` lavora 55 s; `--send` svuota la coda) | bot |
| `bin/diagnose.php --logs` | già esistente; in più alle 00:20 UTC il giorno prima completo | conteggi dei log anche per il rapporto ([Conteggi del logserver](#conteggi-del-logserver-per-il-rapporto-mensile)) |

## Sovrimpressione (M2, M12)

### Su quali immagini

| Immagine | Sovrimpressione |
|---|---|
| originale (`/img/{id}/orig.jpg`, file su disco, FTP) | **mai** |
| `320` | mai (testo illeggibile) |
| `640`, `1280`, `1920` dell'API dei siti | sì, se nella colonna `overlay_config.apply_to` |
| `current.jpg` pubblico | sì se `current` è in `apply_to`: si serve la versione `current_size` (predefinito 1920) con la sovrimpressione; `current_size = orig` lascia l'originale senza sovrimpressione come nella 1.0 |
| formati per i social (`square`, `portrait`, `story`) | sì, se `social` è in `apply_to` |
| fotogrammi dei timelapse | sì, se `timelapse` è in `apply_to` e il lavoro ha `overlay = 1` |
| analisi delle foto (diagnosi, punteggio) | mai: si analizza sempre l'originale |

Condizioni: `overlay.enabled`, riga `overlay_config` con `enabled = 1` e funzione `overlay` attiva per la webcam. Altrimenti si servono le riduzioni senza sovrimpressione di oggi.

Cache: le immagini con sovrimpressione stanno in `storage/cache/img/<photo_id>/<size>-o<overlay_rev>.jpg`, accanto a quelle senza. `overlay_rev` cresce a ogni modifica della configurazione, del logo o degli sponsor attivi (anche quando uno sponsor entra o esce dal suo periodo: il controllo lo fa `bin/stats.php` ogni ora); l'`ETag` contiene `overlay_rev`, così browser e Windy prendono la versione nuova. Gli URL firmati non cambiano.

### Dati

Valori **della foto**, non dell'ultimo campione: quelli salvati con la foto (`photos.t_ext`, `photos.rh_ext`, `taken_at`), così la scritta è vera anche nell'archivio. Un valore che manca non si scrive (mai `0` o `--`).

| Campo (`fields`) | Formato (`locale = it`) | Note |
|---|---|---|
| `datetime` | `19/11/2026 10:10` | ora locale della webcam; con `time_synced = 0` si aggiunge ` (ora non verificata)` |
| `temperature_ext` | `6,5 °C` | un decimale |
| `humidity_ext` | `80 %` | intero |
| `dewpoint_ext` | `rugiada 3,1 °C` | calcolato da `t_ext` e `rh_ext` con Magnus ([foto-v1.md](foto-v1.md#regole-per-il-router)) |
| `name` | `Monte Cavo` | `webcams.name` |
| `location` | `Rocca di Papa (RM)` | `webcams.location` |
| `altitude` | `949 m` | `webcams.altitude_m` |
| `text` | testo libero | `overlay_config.custom_text`, al più 120 caratteri |

I campi si uniscono con ` · ` nell'ordine della tabella. Con `locale` `en`/`de`/`fr` cambiano solo il formato di data e numeri (`11/19/2026 10:10 AM`, punto decimale) e la parola "rugiada" (`dew point`, `Taupunkt`, `point de rosée`). Testo in UTF-8, con il font TTF (gli accenti si vedono).

### Disegno

Misure in proporzione all'**immagine derivata** su cui si disegna (`H` = altezza, `W` = larghezza):

- **Fascia** in alto o in basso (`bar_position`), alta `max(18 px, round(H × 0,032 × font_scale_pct / 100))`, larga tutta l'immagine, nera con opacità `bar_opacity_pct`; testo bianco, corpo = 62% dell'altezza della fascia, margine laterale = metà dell'altezza della fascia, allineamento `text_align`. Se il testo non sta nella larghezza si tolgono i campi da destra (prima `text`, poi `location`, `altitude`, `dewpoint_ext`), mai la data.
- **Logo** (`logo_asset_id`): PNG con trasparenza o JPEG, ridotto a larghezza `W × logo_width_pct / 100` (proporzioni mantenute, mai ingrandito oltre l'originale), nell'angolo `logo_position` con margine `W × 0,02`; se l'angolo è sul lato della fascia sta sopra la fascia, senza coprirla. Opacità `logo_opacity_pct`.
- **Sponsor** (`overlay_items.kind = sponsor`, `burn = 1`, attivi e nel periodo, funzione `sponsor`): nella fascia, dal lato opposto al testo, in ordine `sort_order`; al più `sponsor.slots` del pacchetto Dolibarr. Ognuno è il suo logo alto quanto il corpo del testo, o il suo `label` se non ha logo, separati da ` · `. Se non c'è spazio si tolgono dalla fine. Gli sponsor non coprono mai la data.
- **Infopoint** (`kind = infopoint`) con `burn = 1`: un punto bianco con bordo nero (diametro `H × 0,012`) in (`x_pct`, `y_pct`) e il `label` accanto, su fondo nero al 55%. Con `burn = 0` (predefinito per gli infopoint consigliato) non si disegnano: li disegna il plugin, cliccabili, dai dati dell'API.
- Formati verticali e quadrati (social, timelapse verticale): stesse regole sull'immagine già ritagliata.

Le strisce in alto e in basso escluse dal controllo "camera mossa" (`photo_metrics.mask_top_pct`) restano utili per le scritte che il router o la fotocamera stampano già sull'originale: la sovrimpressione del portal non entra nell'analisi.

### Loghi e immagini degli sponsor

Caricati dal pannello (operatore; il cliente dal suo pannello solo per le sue webcam): PNG o JPEG, al più 2 MB e 2000 × 2000 px, controllati con `getimagesize()` e riscritti con GD (si toglie qualsiasi metadato). File in `storage/overlay/<id>.png|jpg`, riga in `overlay_assets`.

## Foto buone (O8)

Una foto è **buona** se è adatta a essere mostrata in un timelapse "pulito", come foto del giorno o nel rapporto: di giorno, nitida, senza pioggia, nebbia o vetro appannato. Il giudizio usa le **metriche che il portal calcola già** per la diagnosi (`photo_metrics`, [diagnosi-v1.md](diagnosi-v1.md#analisi-delle-foto)) e i dati salvati con la foto, e va in `photo_scores`.

### Quali foto si valutano

- `bin/photo-scores.php` valuta tutte le foto di giorno (`mode = day`) ricevute: per una foto ogni `scores.every_min` (15) minuti per webcam usa le metriche della foto stessa (`source = metrics` se `photo_metrics` ce l'ha, altrimenti le calcola con lo stesso algoritmo a 640 px, `source = own`, senza scriverle in `photo_metrics`); le altre foto **ereditano** le metriche della foto analizzata più vicina entro `every_min` minuti (`source = inherited`, `inherited_from`) e aggiungono i loro controlli propri (modalità, dati della foto, avvisi).
- Le foto di notte hanno `good = 0`, `reject_reason = night` senza analisi.

### Criteri (tutti devono valere; `reject_reason` = il primo che non vale)

| # | Criterio | Dati | `reject_reason` |
|---|---|---|---|
| 1 | foto visibile: `is_public = 1`, `deleted_at` nullo | `photos` | `hidden` |
| 2 | di giorno: `mode = day` | `photos.mode` | `night` |
| 3 | luminosità tra `bright_min` 60 e `bright_max` 220 | `brightness` | `dark` / `overexposed` |
| 4 | classificazione della diagnosi non `fog`, `fog_ext`, `blurry`, `dirty`, `moved` | `photo_metrics.classification` | lo stesso nome |
| 5 | nitida: `blur_ratio ≥ blur_min` 0,7; senza riferimento `sharpness_tiles ≥ 0,5 ×` il 90° percentile della webcam negli ultimi 14 giorni | `blur_ratio`, `sharpness_tiles` | `blurry` |
| 6 | niente avviso aperto in quel momento su `glass.fog`, `glass.dirty`, `camera.moved` | `alerts` (`opened_at` ≤ foto < `closed_at`) | `alert` |
| 7 | aria non satura (niente pioggia o nebbia): `rh_ext < rh_max` 95 **e** `t_ext - dp_ext ≥ spread_min` 1,0 °C; senza sensore esterno il criterio non si applica | `photos.rh_ext`, `photos.t_ext` | `humid` |
| 8 | niente sbalzo di luce: luminosità entro ±`flicker_pct` 35% della mediana delle 4 foto buone vicine (2 prima, 2 dopo) | `brightness` | `flicker` |

Le soglie si cambiano per webcam in `webcam_service.timelapse_params.good` (stessi nomi); cambiarle fa crescere `params_rev` e ricalcolare i punteggi degli ultimi 90 giorni.

### Punteggio

Per le foto buone, da 0 a 100 (`photo_scores.score`):

```
nitidezza  = min(1, blur_ratio)                 (senza riferimento: min(1, sharpness_tiles / p90))
luce       = 1 - |brightness - 125| / 125
contrasto  = min(1, contrast / 50)
score      = 100 × nitidezza × sqrt(luce) × contrasto
```

Con 10% in più (al massimo 100) nell'ultima ora prima di `day_end` della foto (luce del tramonto). Serve per scegliere: foto del giorno, foto migliori del rapporto, il fotogramma di ogni intervallo nei timelapse.

## Stesso momento in giorni diversi (M6)

Servizio di sola lettura dell'API dei siti ([api-siti-v1.md](api-siti-v1.md#get-webcamsslugsame-moment)): per ogni giorno di un intervallo, la foto visibile più vicina a un'ora locale fissa (`12:00`), entro una tolleranza (predefinito ±30 minuti). Con `good=1` si sceglie la foto buona più vicina nella tolleranza e, se non ce n'è, la più vicina comunque (`good: false` nella risposta). Passo `day`, `week` (stesso giorno della settimana), `month` (stesso giorno del mese; i mesi senza quel giorno si saltano) o `year`. Nessun file nuovo: usa le riduzioni già esistenti.

## Timelapse (M7, O8, M10)

### Tipi e scelta dei fotogrammi

| `kind` | Periodo | Quando | Fotogrammi (`selection`) | fps |
|---|---|---|---|---|
| `day` | un giorno | alle 01:00 del giorno dopo | `all`: tutte le foto di giorno visibili, tranne quelle con `reject_reason` `hidden`, `night`, `dark`, `overexposed` (il giorno com'è stato, anche se nebbioso) | 12 |
| `week` | settimana ISO, lunedì-domenica | lunedì alle 02:00 | `good`, una ogni 30 minuti | 15 |
| `month` | mese | il 2 alle 03:00 | `good`, una ogni 60 minuti | 25 |
| `custom` | da `period_from` a `period_to` | a richiesta dal pannello (operatore; cliente con `timelapse_custom`) | scelta di chi lo chiede; predefinito come `month` fino a 62 giorni, poi `one_per_day` | 25 |
| `final` | tutto il noleggio (`rental_start` - `rental_end` di Dolibarr, o l'assegnazione) | alla fine del noleggio (`rental_end` passato o assegnazione chiusa) se l'opzione `film` di Dolibarr è `auto` o `edited`; o a richiesta dell'operatore | `good`; `one_per_day` sopra 62 giorni | 25 |

- **Una ogni N minuti** (`good`): il periodo si divide in fasce di N minuti di ora locale; per ogni fascia si prende la foto buona con il punteggio più alto; una fascia senza foto buone si salta.
- **`one_per_day`** ("una al giorno alla stessa luce"): per ogni giorno la foto buona più vicina a `same_light_time` (predefinito `12:00`, cambiabile in `timelapse_params`) entro ±60 minuti; un giorno senza si salta.
- Sotto **24 fotogrammi** il lavoro finisce `skipped` con `status_reason = too_few_frames` (inverno, giorno di nebbia per `good`).
- **Durata**: al più 60 secondi (90 per `final`). Oltre, si tolgono fotogrammi a intervalli regolari fino a starci. Sotto 4 secondi gli fps scendono fino a 8.
- Foto non sincronizzate (`time_synced = 0`): ammesse, ordinate per `taken_at`.
- Dopo i 90 giorni della retention restano gli originali di una foto l'ora: i timelapse di periodi vecchi hanno al più un fotogramma l'ora, e i `day` vecchi non si rifanno.

### Formati

| `format` | Dimensioni | Da dove | Ritaglio |
|---|---|---|---|
| `landscape` | 1920 × 1080 (anche 1280 × 720 se chiesto) | la riduzione `1920` in cache se c'è, altrimenti l'originale | da 3:2 a 16:9: si taglia in altezza, centro verticale a `crop_y_pct` (predefinito 50) |
| `portrait` (funzione `timelapse_portrait`) | 1080 × 1920 | **sempre l'originale** (dalla 1920 servirebbe ingrandire) | finestra 9:16 alta quanto l'immagine (da 6000 × 4000: 2250 × 4000), centro orizzontale a `crop_x_pct` (predefinito 50, 0 = tutto a sinistra, 100 = tutto a destra), poi ridotta |

`crop_x_pct` e `crop_y_pct` si impostano per webcam in `webcam_service.timelapse_params` (anteprima del ritaglio nel pannello) e si copiano nel lavoro. Ogni fotogramma si prepara con GD (riduzione con `imagescale` bicubica, ritaglio, sovrimpressione) e si salva come JPEG qualità 90 in `temp_dir/<id>/f000001.jpg`...; memoria come per l'analisi (`photo_metrics.memory_limit`), una foto alla volta.

### Video

```sh
ffmpeg -hide_banner -loglevel error -y -framerate <fps> -i <temp>/f%06d.jpg \
  -vf "deflicker=mode=pm:size=5" -c:v libx264 -preset medium -crf 23 \
  -pix_fmt yuv420p -profile:v high -level 4.1 -movflags +faststart -an <storage>/timelapse/<webcam>/<id>.mp4
```

- H.264 in MP4, senza audio, `+faststart` (si vede nel browser prima di scaricarlo tutto). `deflicker` solo per `week`, `month`, `custom`, `final` (per `day` la luce che cambia è il contenuto).
- **Poster**: il fotogramma di mezzo, JPEG qualità 85 (`<id>.jpg`).
- Alla fine: `size_bytes`, `sha256`, `duration_s = fotogrammi / fps`, `frames` (elenco dei `photo_id`), `frames_rejected` (conteggi per `reject_reason`), cartella temporanea cancellata sempre (anche in errore).
- Un lavoro alla volta per server (`storage/locks/timelapse.lock`), con `ffmpeg.timeout`; errore → `failed` con `status_reason` (`ffmpeg_error` e le ultime righe di errore nel log `timelapse`), al più 3 tentativi.

### Senza ffmpeg

`ffmpeg.path` vuoto: il portal prova `ffmpeg -version` (una volta l'ora, esito in `storage/state/ffmpeg.json`). Se non c'è o è `off`:

- i lavori automatici **non si creano**; quelli già in coda passano a `failed` con `status_reason = ffmpeg_missing` (si rimettono in coda dal pannello quando ffmpeg c'è);
- il pannello mostra "Timelapse disattivati: ffmpeg non trovato. Installarlo e impostare ffmpeg.path in config.php" al posto dei pulsanti;
- `features` dell'API dei siti non cambia (dipende dal listino): l'elenco dei timelapse mostra quelli già fatti e `whoami` dice `server.timelapse = false`, `reason.timelapse = "ffmpeg_missing"`;
- tutto il resto (sovrimpressione, rapporti, registro, statistiche, Telegram) funziona.

Sul VPS ffmpeg si installa nell'immagine Docker (`apk add ffmpeg` o `apt-get install ffmpeg`).

### Filmato finale (M10)

Opzione `film` di Dolibarr ([api-dolibarr-v2.md](api-dolibarr-v2.md#campi-del-listino-e-delle-opzioni)):

| `film.option` | Cosa fa il portal |
|---|---|
| vuoto | niente |
| `auto` | alla fine del noleggio crea il lavoro `final` nei formati `film.format` (`landscape`, `portrait`, `both`), durata obiettivo `film.duration_s` (predefinito 60), e scrive a Dolibarr `film_status = ready` con il link del pannello (`PUT webcams/{id}/portal`) |
| `edited` | come `auto` (materiale grezzo), più le 50 foto con il punteggio più alto del noleggio in uno ZIP nel pannello per il montaggio a mano (musica, titoli); `film_status = ready` quando i file sono pronti; `delivered` lo mette l'operatore in Dolibarr |

### Conservazione

`expires_at = finished_at + timelapse.keep_days[kind]` (0 = nessuna scadenza: si tiene finché il cliente ha l'archivio, poi lo decide l'operatore). `bin/retention-photos.php` cancella i file scaduti e mette `status = expired`.

## Foto del giorno (M11)

- All'ora `photo_of_day.time` (predefinito 45 minuti dopo il tramonto, `day_end` dell'ultima foto del giorno; oppure `HH:MM` locale), per ogni webcam con la funzione `photo_of_day`: la foto buona con il punteggio più alto del giorno → `photo_of_day` (`chosen_by = auto`). Nessuna foto buona: nessuna foto del giorno.
- L'operatore, e l'utente del cliente dal suo pannello, possono scegliere un'altra foto dello stesso giorno (`chosen_by = operator`/`customer`, non più sostituita in automatico) fino alle 23:59 del giorno dopo.
- Formati per i social, generati al volo come le riduzioni (con sovrimpressione se `social` è in `apply_to`):

  | Nome (nell'URL) | Dimensioni | Ritaglio |
  |---|---|---|
  | `square` (`sq1080`) | 1080 × 1080 | quadrato alto quanto l'immagine, centro a `crop_x_pct` |
  | `portrait` (`p1350`) | 1080 × 1350 (4:5, Instagram) | come sopra, 4:5 |
  | `story` (`s1920`) | 1080 × 1920 (9:16) | come il timelapse verticale |

- Pubblicazione: API dei siti (il plugin la mostra o la offre da scaricare), Telegram (avviso `photo_of_day`). La pubblicazione automatica su Facebook/Instagram del cliente **non** è in questa versione (vedi [Limiti e punti aperti](#limiti-e-punti-aperti)).

## Rapporto mensile (O3)

### Chi e quando

- Per ogni assegnazione webcam → cliente con almeno un giorno nel mese e la funzione `monthly_report`: un rapporto (`monthly_reports`, unico per webcam, cliente e mese), periodo = parte del mese in cui la webcam era del cliente.
- Il giorno `reports.day` (3, dopo la disponibilità definitiva del mese, [diagnosi-v1.md](diagnosi-v1.md#calcolo)) alle `reports.time` (06:00 locali): `pending` → `generating` → `ready`; poi `POST webcams/{id}/documents` con `doc_type = monthly_report` e, se `options.report.enabled` di Dolibarr, la richiesta di email (`send_email`): `sent`, e `emailed` quando Dolibarr conferma l'invio. **L'email la manda Dolibarr** con il suo modello ([api-dolibarr-v2.md](api-dolibarr-v2.md#post-webcamsiddocuments-esteso)): il portal non manda email ai clienti.
- Senza Dolibarr: il PDF resta `ready` nel pannello e nell'API dei siti; `status_reason = no_dolibarr`.
- Errori: fino a 5 tentativi, uno l'ora; poi `failed` e un evento `report.failed` (warning) nel pannello.
- L'operatore può rigenerare un rapporto (`overwrite = 1` in Dolibarr; nessuna seconda email se non lo chiede).

### Contenuto (PDF A4 verticale, italiano)

| Sezione | Contenuto | Dati |
|---|---|---|
| Copertina | logo Viewlapse, logo del cliente (`overlay_config.logo_asset_id`, se c'è), nome e luogo della webcam, cliente, mese, periodo coperto | `webcams`, `customers` |
| Sintesi | disponibilità del mese e impegno (`98%: rispettato`), foto ricevute, giorni con foto, ore disponibili / previste, incidenti, interventi | `availability_monthly` (`final = 1`), `photos`, `alerts`, `events` |
| Foto migliori | 6 foto (miniature 640 px con sovrimpressione se attiva), la migliore di ogni settimana poi le altre per punteggio, al più una per giorno, con data e ora | `photo_scores`, `photo_of_day` |
| Meteo | grafico giornaliero della temperatura esterna (minima, media, massima) e dell'umidità media; minima e massima del mese con giorno; giornate di gelo (minima ≤ 0 °C); con la funzione `weather_cert` anche il numero di giornate di maltempo e il rinvio al certificato | `measurements_hourly` (`samples_unsynced` escluse), `daily_register` |
| Energia | solo con il Victron: energia solare per giorno (Wh, somma di `panel_wh`), tensione minima della batteria per giorno | `measurements_hourly` |
| Funzionamento | grafico della disponibilità per giorno; riavvii, errori della fotocamera, upload falliti, ore con log; scatti dell'otturatore usati e % della vita prevista | `availability_daily`, `log_counters` ([sotto](#conteggi-del-logserver-per-il-rapporto-mensile)), `camera_counters`, `webcam_service.shutter_*` |
| Eventi e interventi | elenco in parole semplici: guasti (titolo della regola, dal ... al ..., durata, "risolto"), interventi del tecnico, manutenzioni programmate, cambio di fotocamera | `alerts` (regole con azione `ticket` o `agenda`), `events` (`intervention`, `maintenance.*`, `camera.change`) |
| Timelapse del mese | poster del timelapse `month` e il link della pagina del cliente (`reports.link_url`, es. il sottosito del portale clienti); niente URL firmati nel PDF (scadono) | `timelapses` |
| Piè di pagina | "Rapporto generato il ... da Viewlapse", pagina N di M | |

Mai nel rapporto: MAC, IP, token, numeri di ticket, testo tecnico degli avvisi, temperatura interna della custodia.

`monthly_reports.data` contiene tutti i numeri stampati (stessi nomi della tabella sotto), per i controlli e per l'API:

```json
{ "availability_pct": 99.12, "sla_target_pct": 98.0, "sla_met": true, "photos": 2841, "days_with_photos": 30,
  "hours_available": 246, "hours_expected": 252, "incidents": 1, "interventions": 1,
  "t_ext_min": -3.2, "t_ext_min_day": "2026-11-27", "t_ext_max": 14.8, "t_ext_max_day": "2026-11-03", "frost_days": 4,
  "bad_weather_days": 6, "solar_wh": 6120, "battery_v_min": 12.31,
  "boots": 3, "camera_errors": 2, "upload_failures": 0, "log_hours": 718,
  "shutter_used": 48213, "shutter_life": 100000, "best_photos": [918273, 918811] }
```

### Generatore PDF

Scritto nel portal, senza librerie: PDF 1.4, A4 (595 × 842 pt), font standard `Helvetica` e `Helvetica-Bold` con `WinAnsiEncoding` (testo convertito da UTF-8 a Windows-1252; i caratteri che non ci sono, come `≥`, si scrivono in parole), immagini JPEG baseline incluse così come sono (`/DCTDecode`, nessuna ricompressione), grafici come linee e rettangoli vettoriali, flussi compressi con `/FlateDecode` (`gzcompress`). Metadati `Title`, `Author` = "Viewlapse", `CreationDate`. Il PDF deve stare sotto i **10 MB** del modulo Dolibarr: se li supera si rifanno le miniature con qualità più bassa (fino a 60), poi più piccole.

## Registro delle impronte e giornate di maltempo (O2)

Per i cantieri: un **registro giornaliero** che prova quali foto esistevano, a che ora, senza modifiche, e una **classificazione meteo** del giorno dai sensori. Il certificato PDF mette insieme le due cose per la direzione lavori.

### Registro (`daily_register`)

Per ogni webcam in servizio e giorno locale, sigillato alle **01:30 del giorno dopo** (fuso della webcam):

1. **Elenco** (file `storage/register/<webcam_id>/AAAA/MM/AAAA-MM-GG.txt`, schema `vlreg1`): testo UTF-8, righe chiuse da `\n` (anche l'ultima), nessuno spazio in più:

   ```
   vlreg1|monte-cavo|2026-11-18|Europe/Rome|<day_hash del giorno prima, o 64 zeri>
   75b70e70...64 hex|2026-11-18T06:40:00Z|2026-11-18T06:40:31Z|raw_20261118-0740.jpg|8123456
   ...
   ```

   Prima riga: schema, slug della webcam, giorno, fuso, hash del giorno prima (catena). Poi una riga per foto: `sha256|taken_at|received_at|file_name|size_bytes`, ore UTC in ISO 8601 con `Z`, ordinate per `taken_at` e poi per `photos.id`. Ci vanno **tutte** le foto ricevute con `taken_at` in quel giorno locale e `time_synced = 1`, anche quelle nascoste (`is_public = 0`: l'impronta non mostra il contenuto) e quelle che la retention cancellerà dopo. Le foto con `time_synced = 0` non ci vanno (contate in `photos_unsynced`).
2. **`day_hash`** = SHA-256 esadecimale minuscolo dei byte del file. Chiunque abbia il file lo verifica con `sha256sum`; chi ha una foto ne verifica l'impronta nell'elenco. Senza foto: file con la sola prima riga, `day_hash` calcolato lo stesso (la catena continua).
3. **Deposito in Dolibarr**: subito dopo il sigillo `POST webcams/{id}/register` ([api-dolibarr-v2.md](api-dolibarr-v2.md#post-webcamsidregister)) con `day_hash` e i conteggi. Dolibarr li salva una volta sola, con la sua ora di registrazione, e non li cambia più: è la prova, tenuta da un sistema diverso dal portal, che l'elenco esisteva quel giorno. Senza Dolibarr il registro vale solo come dato del portal (il certificato lo dice).
4. **Foto tardive** (dalla coda offline, ricevute dopo il sigillo): non cambiano l'elenco né `day_hash`; vanno in `AAAA-MM-GG.late.txt` (stesso formato, senza prima riga di catena) e in `late_photos`.
5. Il registro non si ricalcola mai. Se un elenco su disco non corrisponde più al suo `day_hash`, il pannello lo segnala in rosso (controllo settimanale di `bin/register.php --verify`).

### Classificazione meteo del giorno

**Il router non misura la pioggia** (né vento o neve): la giornata si classifica da indizi, con soglie configurabili per webcam (`webcam_service.weather_params`, stessi nomi), salvate nella riga (`criteria`, `criteria_rev`).

**Ore di lavoro**: `sla_hours` e `sla_weekdays` della webcam se impostati, altrimenti `work_hours` = `07:00-18:00` tutti i giorni (i giorni fuori da `sla_weekdays` si classificano lo stesso e il certificato li segna come non lavorativi). Si usano i campioni con `time_synced = 1` e sensore esterno valido (`ext_sensor_ok = 1`).

Per ogni **ora di lavoro** (da `measurements_hourly`, `photo_metrics`, `photo_scores`):

| Ora | Condizione | Soglie (predefinite) |
|---|---|---|
| **umida** (`wet`) | `rh_ext_avg ≥ rh_wet` **oppure** `t_ext_avg - dp_ext_avg ≤ spread_wet`, con almeno `min_samples_hour` campioni validi | `rh_wet` 95%, `spread_wet` 1,0 °C, `min_samples_hour` 9 (su 12) |
| **gelo** (`frost`) | `t_ext_min ≤ frost_c` | `frost_c` 0,0 °C |
| **nebbia** (`fog`) | almeno una foto dell'ora classificata `fog_ext` dalla diagnosi | |
| **scura** (`dark`) | luminosità mediana delle foto dell'ora `< dark_ratio ×` luminosità della foto di riferimento della fascia | `dark_ratio` 0,6 |

Giornata:

| `classification` | Quando |
|---|---|
| `no_data` | nessun campione e nessuna foto nelle ore di lavoro |
| `not_certifiable` | campioni validi < `min_coverage` (80%) dei previsti (`sensor_missing` se il sensore esterno manca, altrimenti `few_samples`), o ore con almeno una foto < 80% delle ore di lavoro (`few_photos`), o campioni non sincronizzati > 20% (`time_unsynced`) |
| `bad_weather` | `wet_hours ≥ min_wet_hours` (4), oppure `frost_hours ≥ min_frost_hours` (4), oppure `fog_hours ≥ min_fog_hours` (4) |
| `ok` | negli altri casi |

`reasons` dice quali condizioni hanno contato (`wet`, `frost`, `fog`) più `dark` se le ore scure sono almeno 2 (solo come conferma: le ore scure da sole non fanno una giornata di maltempo).

### Certificato (`weather_certificates`)

- Funzione `weather_cert`. Uno al mese per webcam e cliente, generato con il rapporto mensile (stesso giorno e ora), inviato a Dolibarr con `doc_type = weather_certificate` e la sua email (modello `weather`). L'operatore può chiederne uno per un periodo qualsiasi (`kind = custom`, es. il periodo di una contestazione).
- Contenuto (PDF A4, italiano, stesso generatore del rapporto):
  1. intestazione: webcam, luogo con coordinate e quota, cliente, periodo, data di emissione;
  2. **metodo e soglie** usate (tabella sopra con i valori della webcam) e **limiti** (sotto), sempre;
  3. tabella di tutti i giorni: data, lavorativo sì/no, classificazione, motivi, ore umide/gelo/nebbia, temperatura minima e massima, umidità media, foto, `day_hash` (primi 16 caratteri) e ora di registrazione in Dolibarr;
  4. per ogni giornata di maltempo una pagina: grafico orario di temperatura, umidità e punto di rugiada nelle ore di lavoro e fino a 6 foto (una ogni due ore di lavoro, miniature da 400 px) con ora e primi 16 caratteri dello SHA-256 dell'**originale**;
  5. **verifica**: come controllare un elenco (`sha256sum AAAA-MM-GG.txt`) e una foto (`sha256sum raw_....jpg`, da cercare nell'elenco), dove scaricare gli elenchi (pannello del cliente, API dei siti), che gli hash sono depositati in Dolibarr il giorno dopo.
- Le miniature sono derivate (ridotte): la prova vale per gli originali, che il cliente può chiedere (scope `client`, `/img/{id}/orig.jpg`) finché ci sono (90 giorni, poi una foto l'ora: le foto delle giornate di maltempo dei certificati emessi **non** si cancellano con la retention: `bin/retention-photos.php` salta le foto elencate nei certificati).

### Limiti dichiarati (anche nel certificato)

- **Nessuna misura di precipitazione, vento o neve**: le ore umide sono aria satura o quasi, compatibile con pioggia, nebbia o rugiada; dopo la pioggia l'umidità resta alta per qualche ora; una pioggia breve con aria non satura può non comparire. Con un pluviometro o un anemometro sulla scheda (sviluppo futuro) i criteri si estenderanno.
- La neve non si distingue dalla pioggia, se non per la temperatura (ore di gelo).
- Le misure sono puntuali, nel luogo della webcam, con un sensore SHT31 (±2% di umidità, ±0,3 °C, schermato dal sole come installato).
- Il registro prova **integrità e ora delle foto e dei dati** (ora sincronizzata con NTP, impronte depositate in Dolibarr il giorno dopo); la classificazione meteo è un **indizio** secondo soglie concordate nel contratto, non sostituisce i dati ufficiali (ARPA, stazioni meteo) né la valutazione della direzione lavori.

## Statistiche di visualizzazione (M8)

Solo contatori aggregati per webcam, giorno locale, fonte e chiave del sito (`view_stats_daily`), e host delle pagine per mese (`view_stats_hosts`). **Nessun IP in chiaro, nessun cookie, nessun identificativo nel browser.**

| Fonte | Da dove | `views` | `visitors` |
|---|---|---|---|
| `site` | beacon dei plugin ([api-siti-v1.md](api-siti-v1.md#visualizzazioni-beacon)) | una per evento `view`, tolti i doppi dello stesso visitatore entro `stats.dedupe_min` (30) minuti | distinti nel giorno |
| `image` | `GET /cam/{slug}/current.jpg` (anche `304`) | una per richiesta | — |
| `api` | richieste di `/api/site/v1/webcams/{slug}...` con risposta `200` | una per richiesta | — |
| `telegram` | `/foto` e `/meteo` | una per comando | — |
| `portal` | pagine della webcam nel pannello del cliente | come `site` | come `site` |

- **Visitatore**: `HMAC-SHA256(segreto del giorno, IP troncato | User-Agent | webcam_id)`, primi 16 byte (`view_visitors.visitor`). IP troncato: IPv4 a /24 (`203.0.113.0`), IPv6 a /48. Il segreto del giorno (`view_salts`, 32 byte casuali) si crea alla prima visita del giorno e si cancella dopo 48 ore insieme alle righe di `view_visitors`: dopo non si può più ricollegare nulla a nessuno.
- Bot esclusi: User-Agent vuoto o che contiene `bot`, `crawl`, `spider`, `slurp`, `preview`, `monitor`, `curl`, `wget`, `python`, `headless` (senza distinzione di maiuscole). Le richieste di `current.jpg` contano anche dai bot (Windy è un bot: è la visibilità che il cliente compra).
- `view_seconds`: somma delle durate dei beacon `leave` (al più 3600 s per evento); tempo medio = `view_seconds / views`.
- **Host**: solo il nome dell'host della pagina (dall'intestazione `Origin`, altrimenti da `Referer`), senza percorso; `-` se manca. Al più 200 host per webcam e mese (gli altri in `altri`).
- Le richieste di `current.jpg` si contano senza toccare il database a ogni richiesta: una riga per richiesta in `storage/state/image-hits.log`, sommata ogni ora da `bin/stats.php`.
- Funzione `stats` (listino pro): i contatori `site`, `api`, `image` si raccolgono **sempre** (costano poco e servono a vendere il pro), ma si mostrano al cliente solo con la funzione.

## Bot Telegram (O10)

Un solo bot per il portal (creato con BotFather, token in `telegram.bot_token`). Funzione `telegram` (listino standard).

### Collegamento

- **Webhook** (VPS): `POST https://<portal>/telegram/webhook`, impostato con `php bin/telegram.php --set-webhook` (`setWebhook` con `secret_token`). Il portal accetta la richiesta solo se l'intestazione `X-Telegram-Bot-Api-Secret-Token` è uguale a `telegram.webhook_secret` (confronto a tempo costante), altrimenti `403`. Risponde sempre `200` a Telegram in meno di 2 secondi: le risposte lente (foto) vanno nella coda `telegram_messages`.
- **Polling** (XAMPP, nessun URL pubblico): `php bin/telegram.php --poll` dal cron ogni minuto, `getUpdates` con `timeout=25`, lavora al più 55 secondi; l'ultimo `update_id` in `storage/state/telegram-offset`.
- Invio: `bin/telegram.php --send` (anche dopo ogni poll): `sendMessage`, `sendPhoto` con il **file caricato** (multipart, la versione `1280` con sovrimpressione, nessun URL pubblico necessario), rispettando `retry_after` dei `429` e al più un messaggio al secondo per chat. Un `403` di Telegram (bot bloccato dall'utente) mette la chat in `blocked`.

### Abbinamento (sicurezza)

1. Nel pannello (operatore, o utente del cliente) **Clienti → Telegram → Nuovo codice**: per tutte le webcam del cliente o una sola, con un'etichetta. Il codice è di 10 caratteri dell'alfabeto Crockford base32 (senza `I`, `L`, `O`, `U`), mostrato una volta come `K7QM2-X9TRB`, valido `telegram.code_ttl_days` (7) giorni, **monouso**; nel database solo lo SHA-256 del codice normalizzato (maiuscolo, senza spazi e trattini).
2. Il cliente scrive al bot `/iscrivi K7QM2-X9TRB`. Il portal controlla: codice esistente, non usato, non revocato, non scaduto, cliente attivo, almeno una webcam del codice con la funzione `telegram`. Crea la chat (`telegram_chats`), le iscrizioni per le webcam (avvisi predefiniti `offline`, `online`, `photo_of_day`) e segna il codice usato.
3. Più di 5 tentativi sbagliati da una chat in un'ora (`telegram_pair_failures`): "Troppi tentativi, riprova tra un'ora", senza controllare il codice.
4. La chat vede **solo** le webcam attuali del cliente (o quella del codice) con le regole dello scope `client` di [api-siti-v1.md](api-siti-v1.md#visibilità): quando una webcam passa a un altro cliente le sue iscrizioni si cancellano. Una chat non abbinata riceve solo "Questa chat non è collegata: chiedi un codice a Viewlapse e scrivi /iscrivi CODICE".
5. Gruppi: ammessi (un cantiere con più persone); chi è nel gruppo vede tutto quello che vede la chat. Il pannello lo dice quando si crea il codice.
6. Revoca: dal pannello (chat → Scollega) o con `/stop`.
7. Il bot **non manda mai comandi al router**: `/foto` manda l'ultima foto ricevuta, non fa scattare la fotocamera (la regola "mai gli utenti dei clienti" dei [comandi al router](api-portal-v1.md#sicurezza) resta).

### Comandi

| Comando | Alias | Risposta |
|---|---|---|
| `/start` | | saluto e come collegarsi |
| `/iscrivi CODICE` | `/subscribe` | abbinamento (sopra), poi l'elenco delle webcam |
| `/foto [webcam]` | `/photo` | ultima foto visibile (1280 px, con sovrimpressione se attiva) con didascalia `Monte Cavo · 19/11/2026 10:10 · 6,5 °C · 80 %`; se l'ultima foto è più vecchia di due intervalli di scatto più 30 minuti, in più "La webcam non manda foto dalle 10:10" |
| `/meteo [webcam]` | `/weather` | ultimo dato esterno (ora, temperatura, umidità, punto di rugiada), minima e massima di oggi, differenza di temperatura nelle ultime 3 ore; "sensore esterno non installato" se manca |
| `/webcam` | | elenco delle webcam della chat |
| `/avvisi` | `/alerts` | avvisi attivi per webcam, con pulsanti per accenderli e spegnerli |
| `/stop` | | chat scollegata: iscrizioni cancellate, nome e username tolti; "Non riceverai più messaggi. Per ricollegarti serve un nuovo codice" |
| `/aiuto` | `/help` | elenco dei comandi |

- Con più webcam e nessun argomento: pulsanti (inline keyboard) con i nomi. `callback_data` brevi (`f:<webcam_id>`, `m:<id>`, `a:<id>:<avviso>`), sempre ricontrollati contro le webcam della chat.
- Argomento: nome o slug della webcam, senza distinzione di maiuscole, anche parziale se univoco.
- Limiti: un `/foto` al minuto e 30 al giorno per chat; altri comandi 20 al minuto.

### Avvisi

| Avviso | Quando | Testo (esempio) | Predefinito |
|---|---|---|---|
| `offline` | apertura di un avviso `camera.stalled` o `telemetry.stalled` ([diagnosi-v1.md](diagnosi-v1.md)) | "Monte Cavo non manda foto dalle 15:30. Il servizio tecnico è già avvisato." | sì |
| `online` | chiusura dello stesso avviso | "Monte Cavo è di nuovo attiva (ultima foto 18:20)." | sì |
| `photo_of_day` | foto del giorno scelta | la foto con "Foto del giorno, 19/11/2026" | sì |
| `frost` | primo campione del giorno con `t_ext ≤ 0 °C` | "Monte Cavo: gelo, -1,2 °C alle 06:40." | no |
| `bad_weather` | sigillo di un giorno `bad_weather` (funzione `weather_cert`) | "Ieri giornata di maltempo registrata: 6 ore umide." | no |
| `timelapse` | timelapse `week` o `month` pronto | poster e link della pagina del cliente | no |
| `report` | rapporto mensile mandato per email | "Il rapporto di novembre è stato inviato a ..." | no |

- Niente dettagli tecnici negli avvisi (batteria, regole, ticket).
- Idempotenza: `dedupe_key` (es. `offline:4411:0` = avviso e `reopen_count`), unico per chat.
- **Ore di silenzio** `telegram.quiet_hours` (ora locale della webcam): gli avvisi restano in coda fino alla fine; `online` che arriva in silenzio per un `offline` non ancora mandato li cancella entrambi (`dropped`).
- Al più `telegram.daily_limit` (20) avvisi per chat al giorno; oltre: `dropped` con `daily_limit`.

### Dati personali

`chat_id`, nome e username servono solo a mandare i messaggi. Con `/stop`, `blocked` o la revoca nome e username si cancellano subito; la riga dopo `forget_days` (30). `telegram_messages` si cancella dopo 90 giorni. Il token del bot non si scrive mai nei log (gli URL di `api.telegram.org` contengono il token: il log riporta solo il metodo).

## Conteggi del logserver per il rapporto mensile

Il rapporto usa `log_counters` (giorno **UTC**, una riga per webcam), che la migrazione 004 estende. `bin/diagnose.php --logs` già aggiorna il giorno in corso ogni 15 minuti; in più alle **00:20 UTC** rifà il giorno prima completo con due query `stats_query` (stessa connessione e stesse regole di [diagnosi-v1.md](diagnosi-v1.md#log-webcam-e-hostname): hostname unico, timeout 5 s, un hostname assente vale 0, `log_ok = 0` se VictoriaLogs non risponde e il giorno si ritenta alla esecuzione dopo, al più per 3 giorni).

**Query 1** (conteggi, inizio incluso e fine esclusa):

```logsql
_time:[2026-11-17T00:00:00Z, 2026-11-18T00:00:00Z)
| stats by (hostname)
    count() righe,
    count() if ("Linux version") avvii,
    count() if (level:error ("anche dopo il riavvio dalla scheda" OR "scatto fallito dopo il riavvio")) errori,
    count() if ("primo tentativo di scatto fallito") tentativi,
    count() if ("upload fallito") upload_falliti,
    count() if ("OK foto caricata") upload_ok,
    count() if ("ora non sincronizzata" OR "ora del router non valida") ora_non_sincronizzata,
    count() if ("sensore" "non risponde") sensori
```

**Query 2** (ore con almeno una riga):

```logsql
_time:[2026-11-17T00:00:00Z, 2026-11-18T00:00:00Z)
| stats by (hostname, _time:1h) count() righe
| stats by (hostname) count() ore
```

Risposta come le altre `stats_query` (`data.result[]` con `metric.__name__`, `metric.hostname`, `value[1]` come stringa). Corrispondenza con le colonne:

| Nome nella query | Colonna di `log_counters` |
|---|---|
| `righe` | `log_lines` |
| `ore` | `log_hours` |
| `avvii` | `boots_log` |
| `errori` | `camera_errors_log` |
| `tentativi` | `capture_retries` |
| `upload_falliti` | `upload_failures` |
| `upload_ok` | `uploads_ok` |
| `ora_non_sincronizzata` | `ntp_issues` |
| `sensori` | `sensor_errors_log` |

- Il filtro degli **errori** è quello delle sole righe finali di uno scatto fallito, già scelto in [diagnosi-v1.md](diagnosi-v1.md#logscamera_errors-errori-ripetuti-della-fotocamera) (contratto 1.1): la "chiamata unica" di `docs/query.md` di webcam-logserver va allineata a questo filtro (oggi usa quello largo).
- Nel rapporto: riavvii = somma per giorno del massimo tra `boots_log` e `boots_events`, errori = massimo tra `camera_errors_log` e `camera_errors_events`, come nelle regole. I giorni UTC non coincidono con quelli locali: il mese del rapporto somma i giorni UTC del mese (scarto di una o due ore ai bordi, scritto in nota).
- Verifica a mano di un mese, per una webcam:

  ```logsql
  _time:[2026-11-01T00:00:00Z, 2026-12-01T00:00:00Z) hostname:WC-9483C412ABCD
  | stats count() if ("Linux version") avvii, count() if ("upload fallito") upload_falliti
  ```

webcam-logserver aggiunge queste query in `docs/query.md` (sezione "Conteggi per il rapporto mensile"); nessun cambio di configurazione di VictoriaLogs.

## Retention dei file dei servizi

| File | Dove | Quanto |
|---|---|---|
| immagini con sovrimpressione e formati social | `storage/cache/img/` | come le riduzioni: `site_api.cache_days` dall'ultimo uso |
| timelapse e poster | `storage/timelapse/` | `timelapse.keep_days` |
| rapporti e certificati | `storage/reports/`, `storage/certificates/` | per sempre (pochi MB; una copia è anche in Dolibarr) |
| elenchi del registro | `storage/register/` | per sempre (pochi KB al giorno) |
| loghi e sponsor | `storage/overlay/` | finché usati; cancellati 30 giorni dopo `deleted_at` |
| originali delle foto nei certificati emessi | `storage/photos/` | non cancellati dalla retention |

## Limiti e punti aperti

1. **Social**: la pubblicazione automatica sui profili Facebook/Instagram del cliente richiede un'app Meta approvata e un account business del cliente: non è in questa versione. La foto del giorno si scarica dal plugin o arriva su Telegram.
2. **Scatto su richiesta da Telegram** ("/foto per uno scatto subito" dell'idea originale): escluso, perché i clienti non creano comandi al router. Si può aggiungere con un tipo di comando limitato (es. un `capture.now` ogni 30 minuti per webcam, solo se l'ultima foto è più vecchia di due intervalli) cambiando [api-portal-v1.md](api-portal-v1.md#sicurezza).
3. **Pluviometro e anemometro**: con un sensore sulla scheda (fw futuro) il maltempo diventerebbe una misura; i nomi `rain_mm`, `wind_ms` sono riservati in `telemetry/1`.
4. **Dati meteo esterni** (Open-Meteo, ARPA) come conferma nel certificato: non in questa versione.
5. Le soglie del maltempo vanno **concordate nel contratto** di noleggio con il cliente: i predefiniti sono una proposta.
