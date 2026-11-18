# webcam-portal

Portale di Webcam 2.0 (Viewlapse): riceve dai router con [luci-app-webcam](https://github.com/Millorco/luci-app-webcam) le foto, la telemetria ogni 5 minuti e gli eventi, li salva in MariaDB e pubblica l'ultima foto di ogni webcam (`/cam/{slug}/current.jpg`). Riassume i dati verso il modulo Dolibarr [dolibarr-webcams](https://github.com/Millorco/dolibarr-webcams) tramite le sue API.

- PHP 8.2 senza framework pesanti, accesso al database con PDO.
- MariaDB 11.8 sul VPS (database separato da quello di Dolibarr, utente dedicato); MariaDB 10.4+ di XAMPP per l'anteprima locale su Windows.
- I dati di Dolibarr si leggono solo dalle API di Dolibarr, mai dalle sue tabelle.

## Contratto dei dati

Formati JSON, API e schema del database comuni a tutte le repository: [docs/contratto/](docs/contratto/README.md).

## Stato

In sviluppo: per ora c'è solo il contratto.
