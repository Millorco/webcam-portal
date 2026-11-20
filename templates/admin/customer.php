<?php
/** @var \WebcamPortal\Http\Request $req @var string $csrf @var array $c @var list<array> $webcams @var list<array> $history @var list<array> $keys @var ?array $newKey */
use WebcamPortal\Web\View;

$status = [0 => 'da installare', 1 => 'in servizio', 9 => 'ritirata'];
$id = (int) $c['id'];
?>
<h1><?= View::e($c['name']) ?> <span class="muted small"><?= $c['active'] ? '' : 'disattivato' ?></span></h1>
<p class="small muted"><a href="<?= View::e($req->url('/admin/customers')) ?>">Clienti</a>
	<?= $c['dolibarr_socid'] ? ' · terzo Dolibarr ' . (int) $c['dolibarr_socid'] . ($c['synced_at'] ? ', letto ' . View::e(View::ago($c['synced_at'])) : '') : ' · creato nel portal' ?></p>

<div class="grid2">
<section>
	<h2>Webcam assegnate</h2>
	<?php if ($webcams): ?>
	<table class="list">
		<thead><tr><th>Webcam</th><th>Stato</th><th>Ultima foto</th></tr></thead>
		<tbody>
		<?php foreach ($webcams as $w): ?>
			<tr>
				<td><a href="<?= View::e($req->url('/admin/webcams/' . (int) $w['id'])) ?>"><?= View::e($w['name'] ?: $w['slug']) ?></a>
					<div class="small muted"><?= View::e($w['slug']) ?><?= $w['is_public'] ? ' · pubblica' : ' · privata' ?></div></td>
				<td><?= View::e($status[(int) $w['status']] ?? '') ?></td>
				<td><?= View::e(View::local($w['last_photo_at'], (string) $w['timezone'])) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php else: ?>
	<p class="muted">Nessuna webcam. Si assegnano dalla scheda della webcam (campo Cliente) o arrivano da Dolibarr.</p>
	<?php endif; ?>
	<?php if ($history): ?>
	<h3>Storico delle assegnazioni</h3>
	<p class="small muted">Con la chiave client il cliente vede foto e misure solo di questi periodi.</p>
	<table class="list">
		<thead><tr><th>Webcam</th><th>Dal</th><th>Al</th><th>Da</th></tr></thead>
		<tbody>
		<?php foreach ($history as $h): ?>
			<tr><td><?= View::e($h['name'] ?: $h['slug']) ?></td>
				<td><?= View::e(View::local($h['valid_from'], (string) $h['timezone'])) ?></td>
				<td><?= $h['valid_to'] ? View::e(View::local($h['valid_to'], (string) $h['timezone'])) : 'in corso' ?></td>
				<td><?= View::e($h['source']) ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</section>
<section>
	<h2>Dati</h2>
	<form method="post" action="<?= View::e($req->url('/admin/customers/' . $id . '/edit')) ?>" class="form">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
		<label>Nome <input name="name" value="<?= View::e($c['name']) ?>" required></label>
		<label>Codice cliente <input name="code" value="<?= View::e($c['code']) ?>"></label>
		<label>Email <input name="email" type="email" value="<?= View::e($c['email']) ?>"></label>
		<label>Città <input name="town" value="<?= View::e($c['town']) ?>"></label>
		<label>Nota <input name="note" value="<?= View::e($c['note']) ?>"></label>
		<label class="check"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> Attivo (disattivato: le sue chiavi ricevono 403)</label>
		<button type="submit">Salva</button>
		<?php if ($c['dolibarr_socid']): ?><p class="small muted">Nome, codice, email e città si riaggiornano da Dolibarr a ogni sincronizzazione.</p><?php endif; ?>
	</form>
</section>
</div>

<?= View::render('admin/site-keys', ['req' => $req, 'csrf' => $csrf, 'keys' => $keys, 'newKey' => $newKey, 'customerId' => $id]) ?>
