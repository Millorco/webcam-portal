<?php
/**
 * @var \WebcamPortal\Http\Request $req @var list<array> $alerts @var array $filter @var list<array> $webcams
 * @var array<string,\WebcamPortal\Rules\Rule> $rules
 */
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Web\View;

$reasons = ['auto' => 'rientrato', 'operator' => 'operatore', 'dolibarr' => 'Dolibarr', 'camera_changed' => 'fotocamera cambiata', 'webcam_retired' => 'webcam ritirata'];
?>
<h1>Avvisi</h1>
<form method="get" action="<?= View::e($req->url('/admin/alerts')) ?>" class="filters">
	<label>Stato
		<select name="stato">
			<?php foreach (['open' => 'aperti', 'closed' => 'chiusi', 'all' => 'tutti'] as $k => $v): ?>
				<option value="<?= $k ?>" <?= $filter['stato'] === $k ? 'selected' : '' ?>><?= $v ?></option>
			<?php endforeach; ?>
		</select></label>
	<label>Webcam
		<select name="webcam">
			<option value="0">tutte</option>
			<?php foreach ($webcams as $w): ?>
				<option value="<?= (int) $w['id'] ?>" <?= $filter['webcam'] === (int) $w['id'] ? 'selected' : '' ?>><?= View::e($w['name'] ?: $w['slug']) ?></option>
			<?php endforeach; ?>
		</select></label>
	<label>Regola
		<select name="regola">
			<option value="">tutte</option>
			<?php foreach ($rules as $id => $r): ?>
				<option value="<?= View::e($id) ?>" <?= $filter['regola'] === $id ? 'selected' : '' ?>><?= View::e($id . ' · ' . $r->label()) ?></option>
			<?php endforeach; ?>
		</select></label>
	<button type="submit">Filtra</button>
</form>

<?php if (!$alerts): ?>
	<p class="muted">Nessun avviso con questi filtri.</p>
<?php else: ?>
<div class="table-wrap">
<table class="list">
	<thead><tr><th>Gravità</th><th>Webcam</th><th>Avviso</th><th>Aperto</th><th>Stato</th><th>Ticket</th><th>Scadenza</th></tr></thead>
	<tbody>
	<?php foreach ($alerts as $a): $tz = (string) $a['timezone']; ?>
		<tr>
			<td><span class="sev <?= View::e($a['severity']) ?>"><?= View::e(Alerts::SEVERITY_LABEL[$a['severity']] ?? $a['severity']) ?></span></td>
			<td><a href="<?= View::e($req->url('/admin/webcams/' . (int) $a['webcam_id'])) ?>"><?= View::e($a['name'] ?: $a['slug']) ?></a></td>
			<td><a href="<?= View::e($req->url('/admin/webcams/' . (int) $a['webcam_id'] . '/alerts/' . (int) $a['id'])) ?>"><?= View::e($a['title']) ?></a>
				<div class="small muted"><code><?= View::e($a['rule_id']) ?></code><?= $a['reopen_count'] > 0 ? ' · riaperto ' . (int) $a['reopen_count'] . ' volte' : '' ?></div></td>
			<td><?= View::e(View::local($a['opened_at'], $tz)) ?></td>
			<td><?php if ($a['status'] === 'open'): ?>aperto<?= $a['acknowledged_at'] ? ' · preso in carico' : '' ?>
				<?php else: ?>chiuso <?= View::e(View::local($a['closed_at'], $tz)) ?><div class="small muted"><?= View::e($reasons[$a['close_reason']] ?? '') ?></div><?php endif; ?></td>
			<td><?= $a['dolibarr_ticket_ref'] ? View::e($a['dolibarr_ticket_ref']) : '<span class="muted">—</span>' ?></td>
			<td><?= $a['due_at'] ? View::e(View::local($a['due_at'], $tz)) . ($a['sla_breached'] ? ' <span class="badge down">scaduta</span>' : '') : '<span class="muted">—</span>' ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php endif; ?>
