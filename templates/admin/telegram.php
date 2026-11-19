<?php
/** Bot Telegram (O10): stato, codici di abbinamento, chat collegate. */
use WebcamPortal\Web\View;

$hidden = '<input type="hidden" name="_csrf" value="' . View::e($csrf) . '">';
?>
<h1>Bot Telegram</h1>
<?php if (!$configured): ?>
<p class="flash error">Bot spento: impostare <code>telegram.enabled</code> e <code>telegram.bot_token</code> (o la variabile TELEGRAM_BOT_TOKEN) in config.php.</p>
<?php else: ?>
<p class="small muted">Modo <?= View::e($mode) ?> (<?= $mode === 'webhook' ? 'POST /telegram/webhook, impostato con <code>php bin/telegram.php --set-webhook URL</code>' : '<code>php bin/telegram.php</code> ogni minuto' ?>) · messaggi in coda: <?= (int) $queue ?></p>
<?php endif; ?>

<?php if ($newCode !== null): ?>
<section class="card token">
	<h2>Codice di abbinamento</h2>
	<p><code class="big"><?= View::e($newCode['code']) ?></code></p>
	<p class="small">Il cliente scrive al bot <code>/iscrivi <?= View::e($newCode['code']) ?></code>. Vale una volta sola, fino al <?= View::e(View::local($newCode['expires_at'], 'Europe/Rome')) ?>. Non si mostra più.</p>
</section>
<?php endif; ?>

<form method="post" action="<?= View::e($req->url('/admin/telegram')) ?>" class="form card narrow">
	<h3>Nuovo codice</h3>
	<?= $hidden ?>
	<label>Cliente <select name="customer_id" required><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?></select></label>
	<label>Solo la webcam (id, facoltativo) <input name="webcam_id" inputmode="numeric"></label>
	<label>Per chi <input name="label" maxlength="100"></label>
	<button type="submit">Crea il codice</button>
</form>

<h2>Codici</h2>
<?php if ($codes): ?>
<div class="table-wrap"><table class="list">
	<thead><tr><th>Id</th><th>Cliente</th><th>Webcam</th><th>Per chi</th><th>Scade</th><th>Stato</th><th></th></tr></thead>
	<tbody>
	<?php foreach ($codes as $c): ?>
		<tr><td><?= (int) $c['id'] ?></td><td><?= View::e($c['cname']) ?></td><td><?= View::e($c['slug'] ?? 'tutte') ?></td><td><?= View::e($c['label'] ?? '') ?></td>
			<td><?= View::e(View::local($c['expires_at'])) ?></td>
			<td><?= $c['used_at'] ? 'usato' : ($c['revoked_at'] ? 'revocato' : 'valido') ?></td>
			<td><?php if (!$c['used_at'] && !$c['revoked_at']): ?><form method="post" action="<?= View::e($req->url('/admin/telegram/codes/' . (int) $c['id'] . '/revoke')) ?>" class="inline"><?= $hidden ?><button class="link" type="submit">revoca</button></form><?php endif; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table></div>
<?php else: ?><p class="muted">Nessun codice.</p><?php endif; ?>

<h2>Chat collegate</h2>
<?php if ($chats): ?>
<div class="table-wrap"><table class="list">
	<thead><tr><th>Id</th><th>Cliente</th><th>Chat</th><th>Tipo</th><th class="num">Iscrizioni</th><th>Stato</th><th>Ultimo comando</th><th></th></tr></thead>
	<tbody>
	<?php foreach ($chats as $c): ?>
		<tr><td><?= (int) $c['id'] ?></td><td><?= View::e($c['cname']) ?></td><td><?= View::e($c['display_name'] ?? '—') ?><?= $c['username'] ? ' <span class="small muted">@' . View::e($c['username']) . '</span>' : '' ?></td>
			<td><?= View::e($c['chat_type']) ?></td><td class="num"><?= (int) $c['subs'] ?></td><td><?= View::e($c['status']) ?></td>
			<td><?= View::e(View::ago($c['last_command_at'])) ?></td>
			<td><?php if ($c['status'] === 'active'): ?><form method="post" action="<?= View::e($req->url('/admin/telegram/chats/' . (int) $c['id'] . '/unlink')) ?>" class="inline"><?= $hidden ?><button class="link" type="submit">scollega</button></form><?php endif; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table></div>
<?php else: ?><p class="muted">Nessuna chat.</p><?php endif; ?>
