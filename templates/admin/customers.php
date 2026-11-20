<?php
/** @var \WebcamPortal\Http\Request $req @var string $csrf @var list<array> $customers @var list<array> $keys @var ?array $newKey @var int $unassigned */
use WebcamPortal\Web\View;
?>
<h1>Clienti</h1>
<p class="muted small">Importati da Dolibarr con <code>php bin/sync-dolibarr.php</code> (cliente della scheda webcam) o creati qui.
	<?= $unassigned > 0 ? $unassigned . ' webcam senza cliente.' : '' ?></p>
<?php if ($customers): ?>
<div class="table-wrap">
<table class="list">
	<thead><tr><th>Cliente</th><th>Codice</th><th>Dolibarr</th><th class="num">Webcam</th><th class="num">Chiavi attive</th><th>Stato</th></tr></thead>
	<tbody>
	<?php foreach ($customers as $c): ?>
		<tr>
			<td><a href="<?= View::e($req->url('/admin/customers/' . (int) $c['id'])) ?>"><strong><?= View::e($c['name']) ?></strong></a>
				<?= $c['town'] ? '<div class="small muted">' . View::e($c['town']) . '</div>' : '' ?></td>
			<td><?= View::e($c['code'] ?? '—') ?></td>
			<td><?= $c['dolibarr_socid'] ? 'terzo ' . (int) $c['dolibarr_socid'] . ($c['synced_at'] ? '<div class="small muted">letto ' . View::e(View::ago($c['synced_at'])) . '</div>' : '') : '—' ?></td>
			<td class="num"><?= (int) $c['webcams'] ?></td>
			<td class="num"><?= (int) $c['keys_active'] ?></td>
			<td><?= $c['active'] ? 'attivo' : '<span class="badge late">disattivato</span>' ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php else: ?>
<p class="muted">Nessun cliente.</p>
<?php endif; ?>

<form method="post" action="<?= View::e($req->url('/admin/customers')) ?>" class="form card narrow">
	<h3>Nuovo cliente</h3>
	<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
	<label>Nome <input name="name" required></label>
	<label>Codice cliente <input name="code"></label>
	<label>Email <input name="email" type="email"></label>
	<label>Città <input name="town"></label>
	<label>Id del terzo in Dolibarr (facoltativo) <input name="dolibarr_socid" inputmode="numeric"></label>
	<button type="submit">Crea</button>
</form>

<?= View::render('admin/site-keys', ['req' => $req, 'csrf' => $csrf, 'keys' => $keys, 'newKey' => $newKey, 'customerId' => null]) ?>
<p class="small muted">Le chiavi qui sopra non sono di un cliente: servono ai siti che mostrano tutte le webcam pubbliche (es. viewlapse.com).</p>
