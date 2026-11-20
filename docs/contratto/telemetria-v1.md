# Telemetria: `telemetry/1`

Versione del documento: 1.1 (contratto 1.1: [campi per la diagnosi](#campi-per-la-diagnosi-contratto-11), facoltativi; il formato resta `telemetry/1`).

Misure del router **ogni 5 minuti, anche senza foto** (anche di notte e in manutenzione), raccolte in coda e inviate a gruppi a `POST /api/v1/telemetry` (vedi [api-portal-v1.md](api-portal-v1.md)).

- Schema: [telemetria-v1.schema.json](telemetria-v1.schema.json)
- Esempio (lotto di 6 campioni, mezz'ora): [esempi/telemetria-v1.json](esempi/telemetria-v1.json)

## Ciclo sul router

```
ogni 5 min (cron, dopo climate)        ogni 30 min (cron, ritardo casuale 0-120 s)
┌───────────────────────────┐          ┌──────────────────────────────────────┐
│ telemetry_sample          │          │ telemetry_send                       │
│  legge status.json,       │  append  │  prende fino a 288 campioni          │
│  Victron, rete, sistema   ├────────► │  dalla coda, li avvolge nel lotto,   │
│  → 1 riga JSON in coda    │  coda    │  POST; su 200 toglie i campioni      │
└───────────────────────────┘          │  confermati, sugli errori di rete    │
                                       │  riprova al giro dopo                │
                                       └──────────────────────────────────────┘
```

- **Coda**: un file per campione (o un file per ora con un campione per riga) in `/tmp/webcam/telemetry/`, o nella cartella `queue_dir` su chiavetta USB se impostata. Un campione è ~1 KB: 24 ore sono ~290 KB. Limite consigliato: 2016 campioni (7 giorni), poi si scartano i più vecchi e si genera l'evento `telemetry.dropped`.
- **Persistenza**: la coda in RAM si perde al riavvio. Accettato: dopo un riavvio si perdono al massimo i campioni non inviati (≤ 30 minuti con la rete funzionante).
- **Ritmo**: 6 campioni per lotto in condizioni normali. Dopo un'interruzione di rete il router svuota la coda con più lotti di fila (al massimo 288 campioni l'uno), dal più vecchio.
- **Comportamento del portal sulla risposta**: vedi [api-portal-v1.md](api-portal-v1.md#post-apiv1telemetry).

## Lotto

| Campo | Obbl. | Significato |
|---|---|---|
| `schema` | sì | `"telemetry/1"` |
| `mac` | sì | MAC del router (come `inventory_report`). Deve corrispondere al token. |
| `sent_at` | sì | Ora del router all'invio (secondi Unix UTC). Il portal la confronta con la sua ora e registra lo scarto. |
| `time_synced` | sì | Ora sincronizzata con NTP al momento dell'invio. |
| `boot_id` | no | 8 caratteri esadecimali di `/proc/sys/kernel/random/boot_id`. Cambia a ogni avvio: il portal ne ricava i riavvii anche senza evento. |
| `hostname`, `app_version`, `board_fw` | no | Come in `inventory_report`. Un cambio genera un evento lato portal. |
| `camera` | no | Contatori della reflex (vedi sotto). |
| `samples` | sì | Da 1 a 288 campioni, dal più vecchio. |

## Campione

| Campo | Obbl. | Significato |
|---|---|---|
| `id` | sì | `<boot_id>-<seq>`, es. `3f2a9c1e-1501`. `seq` parte da 1 a ogni avvio e cresce di 1 a campione (contatore in `/tmp`). È la chiave di idempotenza. |
| `time` | sì | Istante del campione, secondi Unix UTC. |
| `time_synced` | sì | Ora affidabile. |
| `time_corrected` | no | `true` se `time` è stato ricalcolato (vedi "Ora non sincronizzata"). |
| `uptime` | no | Secondi dall'avvio. |
| `climate` | no | Sensori e riscaldamento. |
| `power` | no | Victron VE.Direct; `null` se non collegato o senza risposta. |
| `net` | no | Connessione. |
| `sys` | no | Router. |
| `camera` | no | `present` (vista su USB), `power` (relè acceso). Non si interroga gphoto2 ogni 5 minuti: `present` viene dall'ultimo scatto o da `lsusb`. |

### `climate`

È **lo stesso oggetto** di `/tmp/webcam/status.json` scritto oggi da `climate` (letto dalla LuCI, copiato nel JSON delle foto e in parte da `inventory_report`), con tre campi in più che `climate` deve iniziare a scrivere:

| Campo | Esiste già | Note |
|---|---|---|
| `time`, `sensor_ok`, `temperature`, `humidity`, `dewpoint` | sì | sensore interno SHT31 0x44 |
| `ext_sensor`, `ext_sensor_ok`, `temperature_ext`, `humidity_ext`, `dewpoint_ext` | sì | sensore esterno SHT31 0x45 (facoltativo) |
| `heating_enabled`, `heater`, `board_fw` | sì | |
| `abs_humidity`, `abs_humidity_ext` | **nuovi** | g/m³, formula in [foto-v1.md](foto-v1.md#regole-per-il-router) |
| `fan` | **nuovo** | stato voluto della ventola (oggi segue `heater` se `heat_fan = 1`) |

Così il campione si costruisce copiando `status.json` così com'è, senza rinominare nulla. Dal contratto 1.1 `climate` scrive anche i campi per la diagnosi (sotto).

### `power` (Victron VE.Direct)

Letto dal protocollo testo VE.Direct (19200 baud, un blocco al secondo) su una porta seriale o un adattatore USB VE.Direct. I campi del blocco sono convertiti in unità SI:

| Campo | Da VE.Direct | Unità |
|---|---|---|
| `product_id` | `PID` | es. `0xA053` (SmartSolar 75/15) |
| `serial`, `firmware` | `SER#`, `FW` | |
| `battery_v` | `V` / 1000 | V |
| `battery_a` | `I` / 1000 | A, positiva in carica |
| `panel_v` | `VPV` / 1000 | V |
| `panel_w` | `PPV` | W |
| `load_a` | `IL` / 1000 | A (uscita LOAD dell'MPPT) |
| `load_on` | `LOAD` = `ON` | |
| `charge_state` | `CS` | 0 spento, 2 guasto, 3 bulk, 4 absorption, 5 float, 7 equalize, 245 avvio, 252 controllo esterno |
| `mppt_state` | `MPPT` | 0 spento, 1 limitato, 2 attivo |
| `off_reason` | `OR` | |
| `error` | `ERR` | 0 nessun errore |
| `soc_pct` | `SOC` / 10 | % — **solo** con BMV o SmartShunt; un regolatore MPPT da solo non lo fornisce |
| `yield_today_wh` | `H20` × 10 | Wh |
| `yield_total_wh` | `H19` × 10 | Wh |
| `max_power_today_w` | `H21` | W |

"Stato di carica" nel senso Victron è `charge_state` (fase di carica); la percentuale della batteria è `soc_pct`. Vedi i punti aperti nel [README](README.md#punti-da-decidere).

### `net`

| Campo | Note |
|---|---|
| `iface` | interfaccia della route predefinita (es. `wwan`, `wan`) |
| `type` | `lte`, `nr`, `umts`, `gsm`, `wan` (cavo verso un router 4G esterno), `wifi` |
| `operator`, `rssi_dbm`, `rsrp_dbm`, `rsrq_db`, `sinr_db` | solo se il modem li espone (modem USB con `uqmi`/`mmcli`, o API del router 4G); altrimenti assenti |
| `rx_bytes`, `tx_bytes` | contatori dell'interfaccia (`/sys/class/net/<iface>/statistics/`): si azzerano al riavvio, il portal calcola i delta (`rx_delta`, `tx_delta`) e riconosce l'azzeramento dal calo del contatore o dal cambio di `boot_id` |
| `wan_ip` | come `inventory_report` |
| `vpn_up` | handshake WireGuard recente (`wg show wglog latest-handshakes` < 180 s) |

### `sys`

| Campo | Fonte |
|---|---|
| `load1` | `/proc/loadavg` |
| `mem_free_kb` | `MemAvailable` (come `inventory_report`) |
| `tmp_free_kb` | `df -Pk /tmp` |
| `disk_free_kb` | `df -k /overlay` (come `inventory_report`) |
| `queue_photos`, `queue_kb` | coda delle foto di `capture` |
| `queue_samples` | campioni di telemetria ancora in coda |
| `maintenance` | `maintenance_mode` |

### `camera` del lotto: contatori della reflex

| Campo | Fonte |
|---|---|
| `model`, `serial` | `gphoto2 --get-config cameramodel` / `eosserialnumber` (o `serialnumber`), letti dopo uno scatto al massimo una volta l'ora |
| `shutter_count` | `shuttercounter` se la fotocamera lo espone (molte EOS entry level **no**); altrimenti `null` |
| `shots_total` | contatore del router: scatti riusciti con questa fotocamera |
| `power_on_hours` | ore con il relè della fotocamera acceso |
| `power_cycles` | spegnimenti/riaccensioni dal relè (`camera_power_cycle` in `capture`) |
| `capture_errors` | scatti falliti |
| `battery_level` | `batterylevel` di gphoto2 (con alimentatore fisso di solito `100%`) |

`shots_total`, `power_on_hours`, `power_cycles`, `capture_errors` stanno in `/etc/webcam/counters` (flash: scritto al massimo una volta l'ora per non consumarla) e ripartono da 0 quando cambia `serial`. Il portal salva una riga in `camera_counters` solo quando un valore cambia.

## Campi per la diagnosi (contratto 1.1)

Campi **facoltativi** aggiunti con il contratto 1.1 (luci-app-webcam 1.2.0), sempre in `telemetry/1`: un portal 0.1 li salva in `extra`, un router 1.1.0 non li manda e le regole che ne hanno bisogno valgono `unknown` o usano i campioni (vedi [diagnosi-v1.md](diagnosi-v1.md)).

### In `climate` (scritti da `climate` in `status.json`)

| Campo | Tipo | Significato | Regola |
|---|---|---|---|
| `heater_on_since` | intero/null | ora Unix UTC in cui il riscaldamento si è acceso l'ultima volta; `null` se spento. Si ricava dall'ora di modifica del file di stato `heater` quando passa a 1 | `enclosure.heater` |
| `heater_duty_pct` | 0-100/null | cicli di `climate` con riscaldamento acceso negli ultimi 60 minuti (12 cicli, file in `/tmp`) | pannello, `enclosure.seal` |
| `heat_reason` | `dewpoint`, `min_temp`, `boost`, `capture`, `off` | perché il riscaldamento è nello stato attuale: vicino al punto di rugiada, sotto `heat_min_temp`, boost comandato dal portal, spento per lo scatto, nessuna condizione | `enclosure.heater`, `glass.fog` |
| `heater_boost_until` | intero/null | fine del boost di `heater.boost` (ora Unix UTC) | `glass.fog` |
| `dewpoint_margin` | °C/null | `heat_dewpoint_margin` configurato | `enclosure.heater` |
| `fan_rpm` | intero/null | giri della ventola; solo con una scheda dotata di tachimetro (non previsto in fw 2.4: campo assente) | `enclosure.fan`, `enclosure.overheat` |

`fan` resta lo stato **voluto** della ventola: solo `fan_rpm` dice se gira davvero.

### In `camera` del campione

| Campo | Tipo | Significato | Regola |
|---|---|---|---|
| `scheduled` | booleano/null | in questo momento `capture_service` deve scattare: `false` in manutenzione o fuori dalle ore di scatto | `camera.stalled`, disponibilità |
| `interval_min` | intero/null | intervallo di scatto in vigore (minuti), fasce speciali comprese (`schedule`) | `camera.stalled` |

### Nel database

Nuove colonne di `measurements` ([schema-mariadb-002.sql](schema-mariadb-002.sql)): `heater_on_since`, `heater_duty_pct`, `heat_reason`, `heater_boost_until`, `dewpoint_margin`, `fan_rpm`, `cam_scheduled` (`camera.scheduled`), `cam_interval_min` (`camera.interval_min`). Le ore Unix diventano `DATETIME` UTC come `ts`.

## Ora non sincronizzata

Il GL-MT300N-V2 non ha orologio a batteria. Dopo un riavvio, finché NTP non sincronizza:

1. il campione si prende comunque, con `time_synced = false` e `uptime`;
2. quando compare `/var/run/webcam_ntp_synced`, `telemetry_send` corregge i campioni dello **stesso** `boot_id`: `time = ora_sync - (uptime_sync - uptime)`, `time_synced = true`, `time_corrected = true`;
3. i campioni di un boot precedente rimasti senza ora non si possono correggere: partono con `time_synced = false`. Il portal li conserva ma non li usa per le medie orarie né per le certificazioni.

## Dal campione alle tabelle

| JSON | `measurements` |
|---|---|
| `id` | `sample_id` |
| `time` | `ts` (DATETIME UTC) |
| `climate.temperature` / `humidity` / `dewpoint` / `abs_humidity` | `t_in` / `rh_in` / `dp_in` / `ah_in` |
| `climate.temperature_ext` / `humidity_ext` / `dewpoint_ext` / `abs_humidity_ext` | `t_ext` / `rh_ext` / `dp_ext` / `ah_ext` |
| `climate.heater`, `fan`, `heating_enabled`, `sensor_ok`, `ext_sensor_ok` | colonne omonime |
| `climate.heater_on_since`, `heater_duty_pct`, `heat_reason`, `heater_boost_until`, `dewpoint_margin`, `fan_rpm` (1.1) | colonne omonime (migrazione 002) |
| `camera.scheduled`, `camera.interval_min` (1.1) | `cam_scheduled`, `cam_interval_min` (migrazione 002) |
| `power.*` | `battery_v`, `battery_a`, `panel_v`, `panel_w`, `load_a`, `load_on`, `charge_state`, `mppt_state`, `victron_error` (`error`), `soc_pct`, `yield_today_wh`, `yield_total_wh` |
| `net.type` | `net_type` |
| `net.*`, `sys.*` | colonne omonime (`queue_samples`, `tmp_free_kb`...) |
| `camera.present`, `camera.power` | `camera_present`, `camera_power` |
| tutto il resto | `extra` (JSON) |

Retention: `measurements` 12 mesi (partizioni mensili), `measurements_hourly` per sempre. Vedi [schema-mariadb.sql](schema-mariadb.sql).
