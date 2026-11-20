<?php
/**
 * @var \WebcamPortal\Http\Request $req @var string $csrf @var array $w @var array $a @var array $details
 * @var ?\WebcamPortal\Rules\Rule $rule @var ?array $state @var list<array> $events @var list<array> $commands @var array $rttys
 */
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Service\Commands;
use WebcamPortal\Web\View;

$tz = (string) $w['timezone'];
$base = $req->url('/admin/webcams/' . (int) $w['id']);
$self = $base . '/alerts/' . (int) $a['id'];
$reasons = ['auto' => 'rientrato da solo', 'operator' => 'chiuso dall\'operatore', 'dolibarr' => 'chiuso da Dolibarr', 'camera_changed' => 'fotocamera cambiata', 'webcam_retired' => 'webcam ritirata'];
$muted = $state && $state['muted_until'] && strtotime($state['muted_until'] . ' UTC') > time();
?>
<p class="small"><a href="<?= View::e($req->url('/admin/alerts')) ?>">Avvisi</a> · <a href="<?= View::e($base) ?>"><?= View::e($w['name'] ?: $w['slug']) ?></a></p>
<h1><?= View::e($a['title']) ?></h1>
<div class="grid2">
<section>
	<table class="kv">
		<tr><th>Regola</th><td><code><?= View::e($a['rule_id']) ?></code><?= $rule ? ' · ' . View::e($rule->label()) : '' ?></td></tr>
		<tr><th>Gravità</th><td><span class="sev <?= View::e($a['severity']) ?>"><?= View::e(Alerts::SEVERITY_LABEL[$a['severity']] ?? $a['severity']) ?></span> · azione <?= View::e($a['action']) ?></td></tr>
		<tr><th>Aperto</th><td><?= View::e(View::local($a['opened_at'], $tz)) ?><?= $a['reopen_count'] > 0 ? ' · riaperto ' . (int) $a['reopen_count'] . ' volte' : '' ?></td></tr>
		<tr><th>Stato</th><td><?= $a['status'] === 'open' ? 'aperto' : 'chiuso il ' . View::e(View::local($a['closed_at'], $tz)) . ' (' . View::e($reasons[$a['close_reason']] ?? $a['close_reason']) . ')' ?></td></tr>
		<?php if ($a['acknowledged_at']): ?><tr><th>Preso in carico</th><td><?= View::e($a['ack_email'] ?? '') ?> · <?= View::e(View::local($a['acknowledged_at'], $tz)) ?></td></tr><?php endif; ?>
		<?php if ($a['due_at']): ?><tr><th>Scadenza intervento</th><td><?= View::e(View::local($a['due_at'], $tz)) ?><?= $a['sla_breached'] ? ' <span class="badge down">scaduta</span>' : '' ?></td></tr><?php endif; ?>
		<tr><th>Ticket Dolibarr</th><td><?= $a['dolibarr_ticket_ref'] ? View::e($a['dolibarr_ticket_ref']) . ($a['ticket_closed_by_operator'] ? ' (chiuso in Dolibarr)' : '') : '—' ?></td></tr>
		<?php if ($rttys[0]): ?><tr><th>rttys</th><td><a href="<?= View::e($rttys[0]) ?>" target="_blank" rel="noopener">terminale</a><?= $rttys[1] ? ' · <a href="' . View::e($rttys[1]) . '" target="_blank" rel="noopener">LuCI</a>' : '' ?></td></tr><?php endif; ?>
	</table>
	<?php if (!empty($details['lines'])): ?>
	<h2>Dati</h2>
	<ul><?php foreach ((array) $details['lines'] as $l): ?><li><?= View::e($l) ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
	<?php if (!empty($details['check'])): ?><p><strong>Da controllare:</strong> <?= View::e($details['check']) ?></p><?php endif; ?>
	<?php if (!empty($details['cause'])): ?><p><strong>Causa probabile:</strong> <?= View::e($details['cause']) ?></p><?php endif; ?>
</section>
<section>
	<?php if ($a['status'] === 'open'): ?>
	<h2>Azioni</h2>
	<?php if (!$a['acknowledged_at']): ?>
	<form method="post" action="<?= View::e($self) ?>">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><input type="hidden" name="do" value="ack">
		<button type="submit">Prendi in carico</button>
	</form>
	<?php endif; ?>
	<form method="post" action="<?= View::e($self) ?>" class="form" onsubmit="return confirm('Chiudere l\'avviso? Se la condizione resta vera, la regola lo riaprirà.')">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><input type="hidden" name="do" value="close">
		<label>Nota di chiusura <input name="note" maxlength="200" placeholder="es. guarnizione sostituita"></label>
		<button type="submit">Chiudi l'avviso</button>
	</form>
	<?php endif; ?>
	<h2>Silenzia la regola</h2>
	<p class="small muted"><?= $muted ? 'Silenziata fino al ' . View::e(View::local($state['muted_until'], $tz)) . ': non apre nuovi avvisi su questa webcam.' : 'Una regola silenziata continua a valutare ma non apre nuovi avvisi.' ?></p>
	<form method="post" action="<?= View::e($self) ?>" class="row">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><input type="hidden" name="do" value="mute">
		<select name="days"><?php foreach ([1, 7, 30, 90] as $d): ?><option value="<?= $d ?>"><?= $d ?> giorni</option><?php endforeach; ?><?php if ($muted): ?><option value="0">riattiva ora</option><?php endif; ?></select>
		<button type="submit"><?= $muted ? 'Cambia' : 'Silenzia' ?></button>
	</form>
</section>
</div>

<section>
	<h2>Storia</h2>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Ora</th><th>Tipo</th><th>Gravità</th><th>Messaggio</th><th>Dolibarr</th></tr></thead>
		<tbody>
		<?php foreach ($events as $e): ?>
			<tr><td><?= View::e(View::local($e['ts'], $tz, 'd/m H:i')) ?></td><td><code><?= View::e($e['type']) ?></code></td>
				<td><span class="sev <?= View::e($e['severity']) ?>"><?= View::e($e['severity']) ?></span></td>
				<td><?= View::e($e['message'] ?? '') ?></td>
				<td class="small"><?= $e['dolibarr_synced_at'] ? 'inviato' : '<span class="muted">—</span>' ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</section>

<?php if ($commands): ?>
<section>
	<h2>Comandi mandati dalla regola</h2>
	<table class="list">
		<thead><tr><th>Comando</th><th>Creato</th><th>Stato</th></tr></thead>
		<tbody><?php foreach ($commands as $c): ?>
			<tr><td><code>cmd-<?= (int) $c['id'] ?></code> <?= View::e(Commands::LABELS[$c['type']] ?? $c['type']) ?></td>
				<td><?= View::e(View::local($c['created_at'], $tz, 'd/m H:i')) ?></td><td><?= View::e($c['status']) ?></td></tr>
		<?php endforeach; ?></tbody>
	</table>
</section>
<?php endif; ?>
