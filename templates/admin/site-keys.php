<?php
/**
 * Chiavi API dei siti di un cliente (o senza cliente), incluso da customers.php e customer.php.
 *
 * @var \WebcamPortal\Http\Request $req @var string $csrf @var list<array> $keys @var ?array $newKey @var ?int $customerId
 */
use WebcamPortal\Service\SiteKeys;
use WebcamPortal\Web\View;
?>
<section id="chiavi">
	<h2>Chiavi API dei siti</h2>
	<?php if ($newKey !== null): ?>
	<div class="card token">
		<h3>Chiave nuova (scope <?= View::e($newKey['scope']) ?>)</h3>
		<p>Copiala ora nelle impostazioni del plugin WordPress: non verrà più mostrata.<?= $newKey['scope'] === 'client' ? ' Una chiave <strong>client</strong> va solo nel server WordPress, mai nel JavaScript delle pagine.' : '' ?></p>
		<p><code class="big" id="site-key"><?= View::e($newKey['key']) ?></code></p>
	</div>
	<?php endif; ?>
	<?php if ($keys): ?>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Prefisso</th><th>Etichetta</th><th>Scope</th><th>Origini ammesse (CORS)</th><th>Ultimo uso</th><th>Stato</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($keys as $k): $kb = $req->url('/admin/keys/' . (int) $k['id']); ?>
			<tr>
				<td><code><?= View::e($k['key_prefix']) ?>…</code></td>
				<td><?= View::e($k['label'] ?? '') ?><div class="small muted">creata <?= View::e(View::local($k['created_at'])) ?></div></td>
				<td><?= View::e($k['scope']) ?><?= $k['rate_limit_per_min'] ? '<div class="small muted">' . (int) $k['rate_limit_per_min'] . '/min</div>' : '' ?></td>
				<td><?php if ($k['revoked_at'] === null): ?>
					<form method="post" action="<?= View::e($kb . '/origins') ?>" class="inline">
						<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
						<textarea name="origins" rows="2" cols="28" placeholder="vuoto = solo da server"><?= View::e(implode("\n", SiteKeys::origins($k['origins']))) ?></textarea>
						<button type="submit" class="small">Salva</button>
					</form>
					<?php else: ?><?= View::e(implode(', ', SiteKeys::origins($k['origins']))) ?><?php endif; ?></td>
				<td><?= View::e(View::local($k['last_used_at'])) ?><?= $k['last_used_origin'] ? '<div class="small muted">' . View::e($k['last_used_origin']) . '</div>' : '' ?></td>
				<td><?= $k['revoked_at'] ? 'revocata' : ($k['expires_at'] && strtotime($k['expires_at'] . ' UTC') <= time() ? 'scaduta' : 'attiva') ?></td>
				<td><?php if ($k['revoked_at'] === null): ?>
					<form method="post" action="<?= View::e($kb . '/revoke') ?>" onsubmit="return confirm('I siti che usano questa chiave smetteranno di funzionare. Continuare?')">
						<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
						<button type="submit" class="danger">Revoca</button>
					</form>
				<?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php else: ?>
	<p class="muted">Nessuna chiave.</p>
	<?php endif; ?>

	<form method="post" action="<?= View::e($req->url('/admin/keys')) ?>" class="form card narrow">
		<h3>Nuova chiave</h3>
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
		<input type="hidden" name="customer_id" value="<?= (int) ($customerId ?? 0) ?>">
		<label>Etichetta <input name="label" placeholder="sito www.hotel.it"></label>
		<label>Scope
			<select name="scope">
				<option value="public"><?= $customerId === null ? 'public: tutte le webcam pubbliche' : 'public: solo le webcam pubbliche del cliente' ?></option>
				<?php if ($customerId !== null): ?><option value="client">client: tutte le webcam del cliente, anche private (solo da server)</option><?php endif; ?>
			</select></label>
		<label>Origini ammesse, una per riga <textarea name="origins" rows="3" placeholder="https://www.hotel.it&#10;https://*.comune.it"></textarea>
			<span class="small muted">Per le richieste dal browser (CORS). Vuoto = solo dal server WordPress. <code>*</code> = tutte (solo public).</span></label>
		<label>Limite al minuto (vuoto = predefinito) <input name="rate_limit_per_min" inputmode="numeric"></label>
		<button type="submit">Crea la chiave</button>
	</form>
	<p class="small muted">Da riga di comando: <code>php bin/site-key.php --help</code>. API documentata in <code>docs/contratto/api-siti-v1.md</code>.</p>
</section>
