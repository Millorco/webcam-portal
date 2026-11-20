<?php
/**
 * Chiavi API dei siti (api-siti-v1.md): vls_..., per cliente, con scope e
 * origini ammesse (CORS). La chiave in chiaro si vede solo alla creazione.
 *
 *   php bin/site-key.php --elenco [--cliente ID]
 *   php bin/site-key.php --clienti
 *   php bin/site-key.php --crea --cliente ID|--socid N|--senza-cliente
 *        [--scope public|client] [--origini "https://www.hotel.it,https://*.comune.it"]
 *        [--etichetta "sito hotel"] [--limite 600]
 *   php bin/site-key.php --origini "https://..." --chiave ID   (sostituisce l'elenco; "" = solo da server)
 *   php bin/site-key.php --revoca ID
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use WebcamPortal\Cli;
use WebcamPortal\Db;
use WebcamPortal\Log;
use WebcamPortal\Service\SiteKeys;

Cli::only();
$a = Cli::args($argv);

if (isset($a['help']) || count($a) === 0) {
	Cli::out(trim((string) preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', (string) file_get_contents(__FILE__), 2)[1], 2)[0])));
	exit(0);
}

$customer = static function () use ($a): ?int {
	if (isset($a['senza-cliente'])) {
		return null;
	}
	if (is_string($a['socid'] ?? null)) {
		$id = Db::scalar('SELECT id FROM customers WHERE dolibarr_socid = ?', [(int) $a['socid']]);
		if ($id === null) {
			Cli::fail("Nessun cliente con terzo Dolibarr {$a['socid']}: eseguire prima php bin/sync-dolibarr.php");
		}
		return (int) $id;
	}
	if (is_string($a['cliente'] ?? null) && ctype_digit($a['cliente'])) {
		return (int) $a['cliente'];
	}
	Cli::fail('Indicare --cliente ID, --socid N oppure --senza-cliente');
};

try {
	if (isset($a['clienti'])) {
		foreach (Db::all('SELECT c.*, (SELECT COUNT(*) FROM webcams w WHERE w.customer_id = c.id AND w.status <> 9) AS n FROM customers c ORDER BY c.name') as $c) {
			Cli::out(sprintf('%5d  %-40s  terzo %-6s  %d webcam%s', $c['id'], mb_substr((string) $c['name'], 0, 40), $c['dolibarr_socid'] ?? '-', $c['n'], $c['active'] ? '' : '  (disattivato)'));
		}
		exit(0);
	}
	if (isset($a['elenco'])) {
		$sql = 'SELECT k.*, c.name FROM site_keys k LEFT JOIN customers c ON c.id = k.customer_id';
		$p = [];
		if (is_string($a['cliente'] ?? null)) {
			$sql .= ' WHERE k.customer_id = ?';
			$p[] = (int) $a['cliente'];
		}
		foreach (Db::all($sql . ' ORDER BY k.id', $p) as $k) {
			Cli::out(sprintf(
				'%4d  %s…  %-6s  %-30s  %-20s  %s  origini: %s',
				$k['id'], $k['key_prefix'], $k['scope'], mb_substr((string) ($k['name'] ?? '(tutte le pubbliche)'), 0, 30),
				mb_substr((string) ($k['label'] ?? ''), 0, 20), $k['revoked_at'] ? 'revocata' : 'attiva',
				implode(' ', SiteKeys::origins($k['origins'])) ?: '(solo da server)'
			));
		}
		exit(0);
	}
	if (is_string($a['revoca'] ?? null)) {
		if (!SiteKeys::revoke((int) $a['revoca'])) {
			Cli::fail("Chiave {$a['revoca']} inesistente o già revocata");
		}
		Log::info("chiave dei siti {$a['revoca']} revocata da riga di comando");
		Cli::out("Chiave {$a['revoca']} revocata.");
		exit(0);
	}
	if (isset($a['crea'])) {
		$r = SiteKeys::create(
			$customer(),
			is_string($a['scope'] ?? null) ? $a['scope'] : 'public',
			is_string($a['origini'] ?? null) ? $a['origini'] : '',
			is_string($a['etichetta'] ?? null) ? $a['etichetta'] : null,
			null,
			is_string($a['limite'] ?? null) ? (int) $a['limite'] : null
		);
		Log::info('chiave dei siti ' . substr($r['key'], 0, 12) . " (id {$r['id']}) creata da riga di comando");
		Cli::out("Chiave {$r['id']} creata. Copiala ora nel plugin WordPress, non verrà più mostrata:");
		Cli::out($r['key']);
		exit(0);
	}
	if (array_key_exists('origini', $a) && is_string($a['chiave'] ?? null)) {
		SiteKeys::setOrigins((int) $a['chiave'], is_string($a['origini']) ? $a['origini'] : '');
		Cli::out("Origini della chiave {$a['chiave']} aggiornate.");
		exit(0);
	}
	Cli::fail('Comando sconosciuto: php bin/site-key.php --help');
} catch (InvalidArgumentException $e) {
	Cli::fail($e->getMessage());
}
