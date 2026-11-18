# Dati della foto: `photo/1`

Ogni foto ha accanto un file `.json` con lo stesso nome (`raw_20261118-1530.jpg` → `raw_20261118-1530.json`). Lo scrive `capture` di luci-app-webcam e viaggia con la foto, sia verso l'FTP (come oggi) sia verso il portal (`POST /api/v1/photo`, vedi [api-portal-v1.md](api-portal-v1.md)).

- Schema: [foto-v1.schema.json](foto-v1.schema.json)
- Esempio: [esempi/foto-v1.json](esempi/foto-v1.json)

## Da dove parte: `photo/0`

luci-app-webcam 1.0-rc8 scrive già questo file (funzione `write_datafile` in `root/usr/bin/capture`), senza campo `schema`. Lo chiamiamo **`photo/0`**:

```json
{
  "time": 1795012200,
  "datetime": "2026-11-18T15:30:00+0100",
  "camera": "monte-cavo",
  "file": "raw_20261118-1530.jpg",
  "mode": "day",
  "special": null,
  "settings": { "iso": "Auto", "aperture": "8", "shutterspeed": "1/500" },
  "day_start": 1794981660,
  "day_end": 1795017120,
  "app_version": "1.0-rc8",
  "climate": { "...": "copia di /tmp/webcam/status.json, o null" }
}
```

`photo/1` **aggiunge** campi e non toglie né rinomina nulla. Un lettore di `photo/0` (script sul server FTP, pagine web che leggono `current.json`) continua a funzionare. Il portal accetta anche `photo/0` (lo salva con `schema_ver = 0`, senza SHA-256 verificato dal router: lo calcola lui).

## Campi

Obbligatori in `photo/1`: `schema`, `time`, `time_synced`, `mac`, `file`, `sha256`, `size`, `mode`.

| Campo | Tipo | Da | Significato |
|---|---|---|---|
| `schema` | `"photo/1"` | nuovo | Versione del formato. |
| `time` | intero | photo/0 | Istante dello scatto, secondi Unix UTC (lo stesso `NOW` usato per il nome del file). |
| `datetime` | stringa | photo/0 | Stesso istante nell'ora locale del router, `AAAA-MM-GGTHH:MM:SS+HHMM`. Il portal ne ricava `tz_offset_min`. |
| `time_synced` | booleano | nuovo | `true` solo se al momento dello scatto esiste `/var/run/webcam_ntp_synced` (creato da `/etc/hotplug.d/ntp/25-webcam`). **Obbligatorio**: una foto con `false` non vale per le giornate di maltempo certificate. |
| `mac` | stringa | nuovo | MAC del router, minuscolo con `:` (stessa funzione `get_mac` di `inventory_report`). |
| `hostname` | stringa | nuovo | Hostname del router. |
| `camera` | stringa | photo/0 | **Nome della webcam** (hostname, `WC_NAME`), non il modello della fotocamera. Resta per compatibilità; il modello è in `camera_info.model`. |
| `file` | stringa | photo/0 | Nome del file JPEG. |
| `sha256` | stringa | nuovo | SHA-256 del JPEG, esadecimale minuscolo (`sha256sum` di busybox). Calcolato **dopo** lo scatto e **prima** di scrivere il JSON. |
| `size` | intero | nuovo | Byte del JPEG. |
| `mode` | `day`/`night` | photo/0 | Impostazioni usate. |
| `special` | stringa/null | photo/0 | Fascia oraria speciale attiva (es. `Capodanno`). |
| `interval` | intero | nuovo | Intervallo di scatto in vigore, minuti. |
| `settings` | oggetto | photo/0 | Etichette gphoto2 richieste (`iso`, `aperture`, `shutterspeed`); `null` = lasciata quella della fotocamera. I valori **reali** li legge il portal dall'EXIF. |
| `day_start`, `day_end` | intero/null | photo/0 | Inizio e fine delle impostazioni diurne (alba e tramonto già spostati dagli scostamenti). |
| `lat`, `lon` | numero/null | nuovo | Coordinate configurate nel router. |
| `app_version` | stringa | photo/0 | Versione di luci-app-webcam. |
| `board_fw` | stringa | nuovo | Firmware della scheda. |
| `capture` | oggetto | nuovo | `attempts` (1 o 2), `camera_power_cycled` (la fotocamera è stata riavviata dal relè), `duration_s`. |
| `camera_info` | oggetto | nuovo | Facoltativo: `model`, `serial`, `firmware`, `lens`, `shutter_count`, `battery_level`, `read_at`. Va letto con `gphoto2 --get-config` **non a ogni scatto** (allunga lo scatto): basta una volta l'ora, il router ripete l'ultimo valore. |
| `climate` | oggetto/null | photo/0 | Copia di `/tmp/webcam/status.json` se più recente di 15 minuti. In `photo/1` lo stesso oggetto ha in più `abs_humidity`, `abs_humidity_ext`, `fan` (definito in [telemetria-v1.schema.json](telemetria-v1.schema.json) `#/$defs/climate`). |
| `power` | oggetto/null | nuovo | Ultimo campione Victron se più recente di 15 minuti (stesso oggetto della telemetria, `#/$defs/power`). |

Campi sconosciuti sono ammessi (`additionalProperties: true`): il portal li conserva nella colonna `photos.meta`.

## Regole per il router

1. **Unità**: temperature sempre in °C (anche con `temp_scale = f` nella LuCI), umidità in %, tensioni in V, correnti in A.
2. **Numeri come numeri**: niente stringhe per i valori misurati; un valore che manca è `null` o il campo non c'è.
3. **Scrittura**: il JSON va scritto sempre quando la destinazione è il portal (oggi solo con `upload_data = 1`). Con FTP resta l'opzione attuale.
4. **Coda offline**: il JSON resta in coda con la foto (come oggi `queue_add` fa per il `.json`) e non si riscrive al reinvio: `time` e `sha256` restano quelli dello scatto.
5. **`time_synced = false`**: la foto si invia lo stesso. Se l'ora viene sincronizzata dopo, il router **non** corregge il JSON della foto (il nome del file contiene già l'ora sbagliata): il portal la marca come non certificata.
6. Calcolo dell'umidità assoluta (g/m³), da mettere in `climate` accanto al punto di rugiada:

   ```
   AH = 216.7 × (RH/100 × 6.112 × exp(17.62·T / (243.12 + T))) / (273.15 + T)
   ```

   con le stesse costanti di Magnus già usate per `dewpoint` in `climate`.

## Uso dello SHA-256

- **Idempotenza**: il portal riconosce una foto già ricevuta da `(webcam, sha256)` e risponde `200` invece di salvarla due volte.
- **Integrità**: il portal ricalcola lo SHA-256 del file ricevuto e rifiuta con `422` se non coincide (upload troncato).
- **Certificazione**: la "giornata di maltempo certificata" si basa su foto con `time_synced = true` e SHA-256 registrato al momento della ricezione (`photos.received_at`). Il documento di certificazione elenca gli SHA-256: chiunque abbia il file può verificarlo.
