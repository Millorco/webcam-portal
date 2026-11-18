<?php
/**
 * @var \WebcamPortal\Http\Request $req @var string $csrf @var array $w @var ?array $current @var ?array $lastPhoto
 * @var list<array> $recent @var list<string> $charts @var list<array> $events @var ?array $counters
 * @var list<array> $tokens @var int $photosToday @var ?string $newToken @var list<string> $timezones
 */
use WebcamPortal\Web\View;

$tz = (string) $w['timezone'];
$id = (int) $w['id'];
$base = $req->url('/admin/webcams/' . $id);
$status = [0 => 'da installare', 1 => 'in servizio', 9 => 'ritirata'];
$cs = [0 => 'spento', 2 => 'guasto', 3 => 'bulk', 4 => 'absorption', 5 => 'float', 7 => 'equalize', 245 => 'avvio', 252 => 'esterno'];
$publicUrl = $req->baseUrl() . '/cam/' . $w['slug'] . '/current.jpg';
?>
<h1><?= View::e($w['name'] ?: $w['slug']) ?> <span class="muted small"><?= View::e($status[(int) $w['status']] ?? '') ?></span></h1>

<?php if ($newToken !== null): ?>
<section class="card token">
	<h2>Token della webcam</h2>
	<p>Copialo ora in LuCI (<em>Server → Portal → Token</em>, opzione <code>portal_token</code>): non verrà più mostrato.</p>
	<p><code class="big" id="token"><?= View::e($newToken) ?></code></p>
</section>
<?php endif; ?>

<div class="grid2">
	<section>
		<?php if ($current): ?>
			<a href="<?= View::e($base . '/current.jpg') ?>"><img class="current" src="<?= View::e($base . '/current.jpg') ?>?t=<?= (int) $current['taken_at'] ?>" alt="Ultima foto"></a>
			<p class="small muted">Foto delle <?= View::e(View::local(gmdate('Y-m-d H:i:s', $current['taken_at']), $tz, 'd/m/Y H:i')) ?>
				<?php if ($lastPhoto): ?> · <?= $lastPhoto['size_bytes'] >= 1048576 ? View::num($lastPhoto['size_bytes'] / 1048576, 1, 'MB') : View::num($lastPhoto['size_bytes'] / 1024, 0, 'KB') ?>
				<?= $lastPhoto['width'] ? ' · ' . (int) $lastPhoto['width'] . '×' . (int) $lastPhoto['height'] : '' ?>
				<?= $lastPhoto['time_synced'] ? '' : ' · <strong>ora non sincronizzata</strong>' ?><?php endif; ?>
				· <?= $photosToday ?> foto nelle ultime 24 ore</p>
		<?php else: ?>
			<p class="muted">Nessuna foto ricevuta.</p>
		<?php endif; ?>
		<p class="small">URL pubblico: <code><?= View::e($publicUrl) ?></code>
			<?= $w['is_public'] && (int) $w['status'] === 1 ? '' : ' <span class="badge late">non pubblico</span>' ?></p>
	</section>
	<section>
		<table class="kv">
			<tr><th>MAC</th><td><?= View::e($w['mac']) ?></td></tr>
			<tr><th>Hostname</th><td><?= View::e($w['hostname'] ?? '—') ?></td></tr>
			<tr><th>Ultimo contatto</th><td><?= View::e(View::local($w['last_seen_at'], $tz)) ?> (<?= View::e(View::ago($w['last_seen_at'])) ?>)</td></tr>
			<tr><th>Ultima telemetria</th><td><?= View::e(View::local($w['last_telemetry_at'], $tz)) ?></td></tr>
			<tr><th>luci-app-webcam</th><td><?= View::e($w['app_version'] ?? '—') ?></td></tr>
			<tr><th>Firmware scheda</th><td><?= View::e($w['board_fw'] ?? '—') ?></td></tr>
			<tr><th>Fotocamera</th><td><?= View::e($w['camera_model'] ?? '—') ?><?= $counters && $counters['serial'] ? ' · n. ' . View::e($counters['serial']) : '' ?></td></tr>
			<?php if ($counters): ?>
			<tr><th>Contatori reflex</th><td>scatti <?= View::e($counters['shots_total'] ?? '—') ?>
				<?= $counters['shutter_count'] !== null ? ' (otturatore ' . View::e($counters['shutter_count']) . ')' : '' ?>
				· <?= View::num($counters['power_on_hours'], 1, 'h') ?> accesa · <?= View::e($counters['power_cycles'] ?? '—') ?> riavvii
				· <?= View::e($counters['capture_errors'] ?? '—') ?> errori</td></tr>
			<?php endif; ?>
			<tr><th>Dolibarr</th><td><?= $w['dolibarr_id'] ? View::e(($w['dolibarr_ref'] ?: '') . ' (id ' . $w['dolibarr_id'] . ')') : '—' ?></td></tr>
		</table>
	</section>
</div>

<section class="charts">
	<?= $charts[0] ?>
	<?= $charts[1] ?>
</section>

<section>
	<h2>Ultime misure</h2>
	<?php if (!$recent): ?><p class="muted">Nessuna misura nelle ultime 24 ore.</p><?php else: ?>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Ora</th><th class="num">T int</th><th class="num">UR int</th><th class="num">T est</th><th class="num">UR est</th>
			<th>Risc.</th><th class="num">Batteria</th><th class="num">Corrente</th><th class="num">Pannello</th><th>Carica</th><th>Rete</th><th class="num">Coda</th></tr></thead>
		<tbody>
		<?php foreach ($recent as $r): ?>
			<tr<?= $r['time_synced'] ? '' : ' class="unsynced" title="ora non sincronizzata"' ?>>
				<td><?= View::e(View::local($r['ts'], $tz, 'd/m H:i')) ?></td>
				<td class="num"><?= View::num($r['t_in'], 1, '°C') ?></td>
				<td class="num"><?= View::num($r['rh_in'], 0, '%') ?></td>
				<td class="num"><?= View::num($r['t_ext'], 1, '°C') ?></td>
				<td class="num"><?= View::num($r['rh_ext'], 0, '%') ?></td>
				<td><?= $r['heater'] === null ? '—' : ($r['heater'] ? 'acceso' : 'spento') ?></td>
				<td class="num"><?= View::num($r['battery_v'], 2, 'V') ?></td>
				<td class="num"><?= View::num($r['battery_a'], 2, 'A') ?></td>
				<td class="num"><?= $r['panel_w'] === null ? '—' : (int) $r['panel_w'] . ' W' ?></td>
				<td><?= $r['charge_state'] === null ? '—' : View::e($cs[(int) $r['charge_state']] ?? $r['charge_state']) ?></td>
				<td><?= View::e($r['net_type'] ?? '—') ?><?= $r['rssi_dbm'] !== null ? ' ' . (int) $r['rssi_dbm'] . ' dBm' : '' ?></td>
				<td class="num"><?= View::e($r['queue_photos'] ?? '—') ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>
</section>

<section>
	<h2>Eventi</h2>
	<?php if (!$events): ?><p class="muted">Nessun evento.</p><?php else: ?>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Ora</th><th>Tipo</th><th>Gravità</th><th>Origine</th><th>Messaggio</th></tr></thead>
		<tbody>
		<?php foreach ($events as $e): ?>
			<tr id="ev-<?= (int) $e['id'] ?>">
				<td><?= View::e(View::local($e['ts'], $tz, 'd/m H:i')) ?></td>
				<td><code><?= View::e($e['type']) ?></code></td>
				<td><span class="sev <?= View::e($e['severity']) ?>"><?= View::e($e['severity']) ?></span></td>
				<td><?= View::e($e['source']) ?></td>
				<td><?= View::e($e['message'] ?? '') ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>
</section>

<div class="grid2">
<section>
	<h2>Modifica</h2>
	<form method="post" action="<?= View::e($base . '/edit') ?>" class="form">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
		<label>Nome <input name="name" value="<?= View::e($w['name']) ?>" required></label>
		<label>Slug <input name="slug" value="<?= View::e($w['slug']) ?>" required pattern="[a-z0-9][a-z0-9-]{0,63}"></label>
		<label>Località <input name="location" value="<?= View::e($w['location']) ?>"></label>
		<label>Fuso orario <input name="timezone" value="<?= View::e($tz) ?>" required></label>
		<label>Stato
			<select name="status">
				<?php foreach ($status as $k => $v): ?><option value="<?= $k ?>" <?= (int) $w['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
			</select></label>
		<label class="check"><input type="checkbox" name="is_public" value="1" <?= $w['is_public'] ? 'checked' : '' ?>> Pubblica</label>
		<label class="check"><input type="checkbox" name="windy_published" value="1" <?= $w['windy_published'] ? 'checked' : '' ?>> Pubblicata su Windy</label>
		<label>ID Windy <input name="windy_id" value="<?= View::e($w['windy_id']) ?>"></label>
		<button type="submit">Salva</button>
	</form>
</section>
<section>
	<h2>Token</h2>
	<table class="list">
		<thead><tr><th>Prefisso</th><th>Creato</th><th>Ultimo uso</th><th>Stato</th></tr></thead>
		<tbody>
		<?php foreach ($tokens as $t): ?>
			<tr>
				<td><code><?= View::e($t['token_prefix']) ?>…</code></td>
				<td><?= View::e(View::local($t['created_at'], $tz)) ?></td>
				<td><?= View::e(View::local($t['last_used_at'], $tz)) ?><?= $t['last_used_ip'] ? '<div class="small muted">' . View::e($t['last_used_ip']) . '</div>' : '' ?></td>
				<td><?= $t['revoked_at'] ? 'revocato' : 'attivo' ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<form method="post" action="<?= View::e($base . '/token') ?>" onsubmit="return confirm('Il token attuale smetterà di funzionare. Continuare?')">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
		<button type="submit" class="danger">Rigenera il token</button>
	</form>
	<p class="small muted">Il token vecchio viene revocato: il router risponderà 403 finché non si inserisce quello nuovo in LuCI.</p>
</section>
</div>
