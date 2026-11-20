<?php
/** Rapporti mensili (O3) e certificati di maltempo (O2) di tutte le webcam. */
use WebcamPortal\Web\View;

$hidden = '<input type="hidden" name="_csrf" value="' . View::e($csrf) . '">';
$st = ['pending' => 'da fare', 'generating' => 'in corso', 'ready' => 'pronto', 'sent' => 'in Dolibarr', 'emailed' => 'mandato al cliente', 'failed' => 'fallito', 'skipped' => 'saltato'];
$row = static function (array $r, string $kind, string $period) use ($req, $hidden, $st): string {
	$dl = in_array($r['status'], ['ready', 'sent', 'emailed'], true) ? '<a href="' . View::e($req->url("/admin/reports/$kind/" . (int) $r['id'] . '/download')) . '">PDF</a> · ' : '';
	return '<tr><td>' . (int) $r['id'] . '</td><td>' . View::e($r['wname'] ?: $r['slug']) . '</td><td>' . View::e($r['cname']) . '</td><td>' . View::e($period) . '</td>'
		. '<td>' . View::e($st[$r['status']] ?? $r['status']) . ($r['status_reason'] ? ' <span class="small muted">(' . View::e($r['status_reason']) . ')</span>' : '') . '</td>'
		. '<td class="num">' . ($r['pages'] === null ? '—' : (int) $r['pages']) . '</td><td>' . View::e((string) ($r['email_to'] ?? '')) . '</td>'
		. '<td>' . $dl . '<form method="post" action="' . View::e($req->url("/admin/reports/$kind/" . (int) $r['id'] . '/regenerate')) . '" class="inline">' . $hidden
		. '<label class="small"><input type="checkbox" name="email" value="1"> rimanda l\'email</label> <button class="link" type="submit">rigenera</button></form></td></tr>';
};
?>
<h1>Rapporti e certificati</h1>
<p class="small muted"><?= $enabled ? 'Generati dal giorno ' . (int) $day . ' del mese alle ' . View::e($time) . ' (ora della webcam) con <code>php bin/reports.php</code>, allegati in Dolibarr e mandati al cliente da Dolibarr.' : 'Rapporti spenti (reports.enabled).' ?></p>

<h2>Rapporti mensili</h2>
<?php if ($reports): ?>
<div class="table-wrap"><table class="list">
	<thead><tr><th>Id</th><th>Webcam</th><th>Cliente</th><th>Mese</th><th>Stato</th><th class="num">Pagine</th><th>Email a</th><th></th></tr></thead>
	<tbody><?php foreach ($reports as $r) { echo $row($r, 'report', $r['month']); } ?></tbody>
</table></div>
<?php else: ?><p class="muted">Nessun rapporto.</p><?php endif; ?>

<h2>Certificati delle giornate di maltempo</h2>
<?php if ($certs): ?>
<div class="table-wrap"><table class="list">
	<thead><tr><th>Id</th><th>Webcam</th><th>Cliente</th><th>Periodo</th><th>Stato</th><th class="num">Pagine</th><th>Email a</th><th></th></tr></thead>
	<tbody><?php foreach ($certs as $r) { echo $row($r, 'certificate', $r['period_from'] . ' → ' . $r['period_to'] . ($r['days_bad'] !== null ? ' (' . (int) $r['days_bad'] . ' di maltempo)' : '')); } ?></tbody>
</table></div>
<?php else: ?><p class="muted">Nessun certificato.</p><?php endif; ?>
