<?php
/** @var \WebcamPortal\Http\Request $req @var list<array> $webcams */
use WebcamPortal\Web\View;

$status = [0 => 'da installare', 1 => 'in servizio', 9 => 'ritirata'];
?>
<h1>Webcam</h1>
<?php if (!$webcams): ?>
	<p>Nessuna webcam. <a href="<?= View::e($req->url('/admin/webcams/new')) ?>">Creane una</a> o usa <code>php bin/webcam-add.php</code>.</p>
<?php else: ?>
<div class="table-wrap">
<table class="list">
	<thead><tr>
		<th>Ultima foto</th><th>Webcam</th><th>Ultimo contatto</th>
		<th class="num">T int</th><th class="num">UR int</th><th class="num">T est</th><th class="num">UR est</th>
		<th class="num">Batteria</th><th>Rete</th><th>Avvisi aperti</th>
	</tr></thead>
	<tbody>
	<?php foreach ($webcams as $w):
		$tz = (string) $w['timezone'];
		$seen = $w['last_seen_at'] ? time() - (int) strtotime($w['last_seen_at'] . ' UTC') : null;
		$state = $seen === null ? 'never' : ($seen < 2400 ? 'ok' : ($seen < 4 * 3600 ? 'late' : 'down'));
		$stateLabel = ['never' => 'mai vista', 'ok' => 'in linea', 'late' => 'in ritardo', 'down' => 'non risponde'][$state];
		$l = $w['last'];
		$href = $req->url('/admin/webcams/' . (int) $w['id']);
	?>
		<tr>
			<td class="thumb"><?php if ($w['has_current']): ?><a href="<?= View::e($href) ?>"><img loading="lazy" src="<?= View::e($req->url('/admin/webcams/' . (int) $w['id'] . '/current.jpg')) ?>" alt=""></a><?php endif; ?>
				<div class="small muted"><?= View::e(View::local($w['last_photo_at'], $tz)) ?></div></td>
			<td><a href="<?= View::e($href) ?>"><strong><?= View::e($w['name'] ?: $w['slug']) ?></strong></a>
				<div class="small muted"><?= View::e($w['slug']) ?> · <?= View::e($w['mac']) ?> · <?= View::e($status[(int) $w['status']] ?? $w['status']) ?><?= $w['is_public'] ? ' · pubblica' : '' ?></div></td>
			<td><span class="badge <?= $state ?>"><?= $stateLabel ?></span>
				<div class="small muted"><?= View::e(View::ago($w['last_seen_at'])) ?></div></td>
			<td class="num"><?= View::num($l['t_in'] ?? null, 1, '°C') ?></td>
			<td class="num"><?= View::num($l['rh_in'] ?? null, 0, '%') ?></td>
			<td class="num"><?= View::num($l['t_ext'] ?? null, 1, '°C') ?></td>
			<td class="num"><?= View::num($l['rh_ext'] ?? null, 0, '%') ?></td>
			<td class="num"><?= View::num($l['battery_v'] ?? null, 2, 'V') ?></td>
			<td><?= View::e($l['net_type'] ?? '—') ?><?= isset($l['rssi_dbm']) ? ' ' . (int) $l['rssi_dbm'] . ' dBm' : '' ?></td>
			<td><?php foreach ($w['alerts'] as $a): ?>
				<div><a class="sev <?= View::e($a['severity']) ?>" href="<?= View::e($href . '/alerts/' . (int) $a['id']) ?>"><?= View::e($a['title']) ?></a></div>
			<?php endforeach; ?><?= $w['alerts'] ? '' : '<span class="muted">—</span>' ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php endif; ?>
