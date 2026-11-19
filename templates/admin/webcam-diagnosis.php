<?php
/**
 * Sezioni della diagnosi nella scheda webcam (incluso da webcam.php).
 *
 * @var \WebcamPortal\Http\Request $req @var string $csrf @var array $w
 * @var array<string,\WebcamPortal\Rules\Rule> $rules @var array<string,array> $states
 * @var list<array> $openAlerts @var list<array> $closedAlerts @var string $sharpChart @var list<array> $lastMetrics
 * @var list<array> $references @var list<array> $commands @var array $svc
 * @var ?array $availMonth @var list<array> $availMonths @var list<array> $daysBelow
 */
use WebcamPortal\Rules\Alerts;
use WebcamPortal\Service\Commands;
use WebcamPortal\Web\View;

$tz = (string) $w['timezone'];
$base = $req->url('/admin/webcams/' . (int) $w['id']);
$stateLabel = ['ok' => 'ok', 'pending' => 'in osservazione', 'alarm' => 'allarme', 'recovering' => 'in rientro', 'unknown' => 'dati insufficienti', 'disabled' => 'disattivata'];
$stateClass = ['ok' => 'ok', 'pending' => 'late', 'alarm' => 'down', 'recovering' => 'late', 'unknown' => 'never-muted', 'disabled' => 'never-muted'];
$cls = ['ok' => 'ok', 'blurry' => 'sfocata', 'fog' => 'vetro appannato', 'fog_ext' => 'nebbia', 'dirty' => 'vetro sporco', 'moved' => 'inquadratura spostata', 'skipped' => 'saltata'];
$cmdStatus = ['pending' => 'in coda', 'delivered' => 'consegnato', 'done' => 'eseguito', 'rejected' => 'rifiutato', 'failed' => 'fallito', 'expired' => 'scaduto', 'cancelled' => 'annullato'];
$fromDolibarr = $svc['source'] === 'dolibarr' && $w['dolibarr_id'];
$hidden = '<input type="hidden" name="_csrf" value="' . View::e($csrf) . '">';
?>
<section id="avvisi">
	<h2>Avvisi aperti</h2>
	<?php if (!$openAlerts): ?><p class="muted">Nessun avviso aperto.</p><?php else: ?>
	<ul class="alerts">
		<?php foreach ($openAlerts as $a): ?>
		<li><span class="sev <?= View::e($a['severity']) ?>"><?= View::e(Alerts::SEVERITY_LABEL[$a['severity']] ?? $a['severity']) ?></span>
			<a href="<?= View::e($base . '/alerts/' . (int) $a['id']) ?>"><?= View::e($a['title']) ?></a>
			<span class="small muted">dal <?= View::e(View::local($a['opened_at'], $tz)) ?><?= $a['dolibarr_ticket_ref'] ? ' · ticket ' . View::e($a['dolibarr_ticket_ref']) : '' ?></span></li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<?php if ($closedAlerts): ?>
	<details><summary class="small">Ultimi avvisi chiusi (<?= count($closedAlerts) ?>)</summary>
		<ul class="alerts small"><?php foreach ($closedAlerts as $a): ?>
			<li><a href="<?= View::e($base . '/alerts/' . (int) $a['id']) ?>"><?= View::e($a['title']) ?></a>
				<span class="muted"><?= View::e(View::local($a['opened_at'], $tz, 'd/m H:i')) ?> – <?= View::e(View::local($a['closed_at'], $tz, 'd/m H:i')) ?></span></li>
		<?php endforeach; ?></ul>
	</details>
	<?php endif; ?>

	<h2>Stato delle regole</h2>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Regola</th><th>Stato</th><th>Da</th><th>Ultima valutazione</th><th>Valori</th></tr></thead>
		<tbody>
		<?php foreach ($rules as $id => $r): $s = $states[$id] ?? null;
			$vals = $s ? json_decode((string) $s['last_value'], true) : null;
			$muted = $s && $s['muted_until'] && strtotime($s['muted_until'] . ' UTC') > time(); ?>
			<tr>
				<td><?= View::e($r->label()) ?><div class="small muted"><code><?= View::e($id) ?></code></div></td>
				<td><?php if ($s): ?><span class="badge <?= $stateClass[$s['state']] ?? '' ?>"><?= View::e($stateLabel[$s['state']] ?? $s['state']) ?></span><?php else: ?><span class="muted">mai valutata</span><?php endif; ?>
					<?= $muted ? '<div class="small muted">silenziata fino al ' . View::e(View::local($s['muted_until'], $tz, 'd/m')) . '</div>' : '' ?></td>
				<td class="small"><?= $s ? View::e(View::local($s['state_since'], $tz, 'd/m H:i')) : '' ?></td>
				<td class="small"><?= $s ? View::e(View::ago($s['last_eval_at'])) : '' ?></td>
				<td class="small"><?php if (is_array($vals)): foreach (array_slice($vals, 0, 5, true) as $k => $v): if (is_array($v)) { continue; } ?>
					<span class="kvp"><?= View::e($k) ?> <strong><?= View::e(is_bool($v) ? ($v ? 'sì' : 'no') : (is_float($v) ? View::num($v, 2) : $v)) ?></strong></span>
				<?php endforeach; endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</section>

<section id="comandi">
	<h2>Comandi al router</h2>
	<p class="small muted">Il router non è raggiungibile dal portal: il comando parte con la prossima richiesta del router (foto, telemetria o controllo dei comandi), solo se luci-app-webcam dichiara <code>commands/1</code>.</p>
	<div class="row">
		<?php foreach (['capture' => 'Scatta ora', 'boost' => 'Riscaldamento 30 min', 'flush' => 'Invia telemetria'] as $do => $label): ?>
		<form method="post" action="<?= View::e($base . '/command') ?>" class="inline"><?= $hidden ?><input type="hidden" name="do" value="<?= $do ?>"><button type="submit"><?= $label ?></button></form>
		<?php endforeach; ?>
		<form method="post" action="<?= View::e($base . '/command') ?>" class="inline"><?= $hidden ?><input type="hidden" name="do" value="heater_cancel"><button type="submit" class="secondary">Ferma il riscaldamento forzato</button></form>
	</div>
	<?php if ($commands): ?>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Comando</th><th>Da</th><th>Creato</th><th>Stato</th><th>Risultato</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($commands as $c): $res = json_decode((string) $c['result'], true); ?>
			<tr>
				<td><code>cmd-<?= (int) $c['id'] ?></code> <?= View::e(Commands::LABELS[$c['type']] ?? $c['type']) ?>
					<?php $pp = json_decode((string) $c['params'], true); if (is_array($pp) && $pp): ?><div class="small muted"><?= View::e(json_encode($pp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></div><?php endif; ?></td>
				<td class="small"><?= $c['created_by'] === 'rule' ? 'regola' : View::e($c['email'] ?? 'operatore') ?></td>
				<td class="small"><?= View::e(View::local($c['created_at'], $tz, 'd/m H:i')) ?><div class="muted">scade <?= View::e(View::local($c['expires_at'], $tz, 'H:i')) ?></div></td>
				<td><?= View::e($cmdStatus[$c['status']] ?? $c['status']) ?><?= $c['delivery_count'] > 1 ? ' <span class="small muted">(' . (int) $c['delivery_count'] . ' consegne)</span>' : '' ?></td>
				<td class="small"><?= is_array($res) ? View::e(json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : '' ?></td>
				<td><?php if (in_array($c['status'], ['pending', 'delivered'], true)): ?>
					<form method="post" action="<?= View::e($base . '/command') ?>" class="inline"><?= $hidden ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="command_id" value="<?= (int) $c['id'] ?>"><button class="link" type="submit">annulla</button></form>
				<?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>
</section>

<section id="foto">
	<h2>Metriche foto</h2>
	<?= $sharpChart ?>
	<?php if ($lastMetrics): ?>
	<div class="table-wrap">
	<table class="list">
		<thead><tr><th>Foto</th><th class="num">Luminosità</th><th class="num">Nitidezza</th><th class="num">Rispetto al rif.</th><th class="num">Spostamento</th><th>Esito</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($lastMetrics as $m): ?>
			<tr>
				<td><a href="<?= View::e($base . '/photos/' . (int) $m['photo_id'] . '.jpg') ?>"><?= View::e(View::local($m['taken_at'], $tz, 'd/m H:i')) ?></a></td>
				<td class="num"><?= View::num($m['brightness'], 0) ?></td>
				<td class="num"><?= View::num($m['sharpness_tiles'], 0) ?></td>
				<td class="num"><?= $m['blur_ratio'] === null ? '—' : View::num($m['blur_ratio'] * 100, 0, '%') ?></td>
				<td class="num"><?= $m['shift_px'] === null ? '—' : View::num($m['shift_px'], 1, 'px') ?></td>
				<td><?= View::e($cls[$m['classification']] ?? $m['classification']) ?><?= $m['skip_reason'] ? ' <span class="small muted">(' . View::e($m['skip_reason']) . ')</span>' : '' ?></td>
				<td><?php if ($m['classification'] !== 'skipped'): ?>
					<form method="post" action="<?= View::e($base . '/reference') ?>" class="inline"><?= $hidden ?><input type="hidden" name="do" value="use"><input type="hidden" name="photo_id" value="<?= (int) $m['photo_id'] ?>"><button class="link" type="submit">usa come riferimento</button></form>
				<?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>

	<h3>Foto di riferimento</h3>
	<?php if (!$references): ?><p class="muted">Nessun riferimento: vengono scelti in automatico tra le foto nitide dei primi giorni.</p><?php else: ?>
	<div class="refs">
		<?php foreach ($references as $r): ?>
		<figure>
			<?php if ($r['photo_id']): ?><img loading="lazy" src="<?= View::e($base . '/photos/' . (int) $r['photo_id'] . '.jpg') ?>" alt=""><?php endif; ?>
			<figcaption class="small"><strong><?= sprintf('%02d:00', (int) $r['band']) ?></strong> · nitidezza <?= View::num($r['sharpness_tiles'], 0) ?>
				· <?= $r['chosen_by'] === 'operator' ? 'scelta a mano' : 'automatica' ?>
				<form method="post" action="<?= View::e($base . '/reference') ?>" class="inline"><?= $hidden ?><input type="hidden" name="do" value="band"><input type="hidden" name="band" value="<?= (int) $r['band'] ?>"><button class="link" type="submit">ricalcola</button></form>
			</figcaption>
		</figure>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<form method="post" action="<?= View::e($base . '/reference') ?>" onsubmit="return confirm('Tutti i riferimenti verranno rifatti con le prossime foto e gli avvisi camera.moved e glass.dirty chiusi. Continuare?')">
		<?= $hidden ?><input type="hidden" name="do" value="framing">
		<button type="submit" class="secondary">Conferma la nuova inquadratura</button>
	</form>
</section>

<div class="grid2" id="servizio">
<section>
	<h2>Disponibilità</h2>
	<?php if ($availMonth): ?>
	<p class="big-num"><?= $availMonth['pct'] === null ? '—' : View::num($availMonth['pct'], 2, '%') ?>
		<span class="small muted">questo mese, provvisoria<?= $availMonth['sla_enabled'] ? ' · obiettivo ' . View::num($availMonth['target_pct'], 1, '%') : '' ?></span></p>
	<p class="small muted"><?= (int) $availMonth['available_h'] ?> ore con foto su <?= (int) $availMonth['expected_h'] - (int) $availMonth['excluded_h'] ?> utili (<?= (int) $availMonth['excluded_h'] ?> escluse per manutenzione o interventi)</p>
	<?php else: ?><p class="muted">Non ancora calcolata (<code>bin/availability.php</code>).</p><?php endif; ?>
	<?php if ($daysBelow): ?>
	<p class="small">Giorni sotto l'obiettivo: <?php foreach ($daysBelow as $i => $d): ?><?= $i ? ', ' : '' ?><?= View::e((new DateTimeImmutable($d['day']))->format('d/m')) ?> (<?= View::num($d['pct'], 0, '%') ?>)<?php endforeach; ?></p>
	<?php endif; ?>
	<?php if ($availMonths): ?>
	<table class="list">
		<thead><tr><th>Mese</th><th class="num">Disponibilità</th><th>Impegno</th><th>Dolibarr</th></tr></thead>
		<tbody><?php foreach ($availMonths as $m): ?>
			<tr><td><?= View::e($m['month']) ?><?= $m['final'] ? '' : ' <span class="small muted">provvisoria</span>' ?></td>
				<td class="num"><?= View::num($m['pct'], 2, '%') ?></td>
				<td><?= $m['sla_met'] === null ? '<span class="muted">—</span>' : ($m['sla_met'] ? 'rispettato' : '<strong>non rispettato</strong>') ?></td>
				<td class="small"><?= $m['dolibarr_synced_at'] ? 'inviata' : '<span class="muted">da inviare</span>' ?></td></tr>
		<?php endforeach; ?></tbody>
	</table>
	<?php endif; ?>
</section>
<section>
	<h2>Impostazioni di servizio</h2>
	<?php if ($fromDolibarr): ?><p class="small muted">Copiate da Dolibarr (contratto di noleggio) <?= $svc['synced_at'] ? 'il ' . View::e(View::local($svc['synced_at'], $tz)) : '' ?>: si cambiano lì.</p><?php endif; ?>
	<form method="post" action="<?= View::e($base . '/service') ?>" class="form">
		<?= $hidden ?>
		<fieldset <?= $fromDolibarr ? 'disabled' : '' ?>>
		<label class="check"><input type="checkbox" name="sla_enabled" value="1" <?= $svc['sla_enabled'] ? 'checked' : '' ?>> Impegno di disponibilità attivo</label>
		<label>Ore senza foto prima del ticket <input type="number" name="sla_offline_hours" min="1" max="720" value="<?= (int) $svc['sla_offline_hours'] ?>"></label>
		<label>Giorni per l'intervento <input type="number" name="sla_response_days" min="1" max="365" value="<?= (int) $svc['sla_response_days'] ?>"></label>
		<label>Obiettivo mensile (%) <input name="sla_target_pct" value="<?= View::e(View::num($svc['sla_target_pct'], 2)) ?>" inputmode="decimal"></label>
		<label>Ore di scatto previste <input name="sla_hours" value="<?= View::e($svc['sla_hours']) ?>" placeholder="tutte (es. 07:00-19:00)"></label>
		<label>Giorni previsti <input name="sla_weekdays" value="<?= View::e($svc['sla_weekdays']) ?>" placeholder="tutti (es. 12345 = lun-ven)"></label>
		<label>Vita dell'otturatore (scatti) <input type="number" name="shutter_life" min="1000" value="<?= View::e($svc['shutter_life']) ?>" placeholder="dal modello"></label>
		<label class="check"><input type="checkbox" name="photo_metrics" value="1" <?= $svc['photo_metrics'] ? 'checked' : '' ?>> Analizza le foto (nitidezza, vetro, inquadratura)</label>
		<button type="submit">Salva</button>
		</fieldset>
	</form>
</section>
</div>
