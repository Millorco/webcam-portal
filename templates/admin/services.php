<?php
/**
 * Servizi di una webcam (servizi-v1.md): listino e funzioni, sovrimpressione
 * e logo, sponsor e infopoint, timelapse, foto del giorno, statistiche,
 * registro delle impronte.
 */
use WebcamPortal\Overlay\Overlay;
use WebcamPortal\Service\Features;
use WebcamPortal\Service\Ffmpeg;
use WebcamPortal\Web\View;

$base = '/admin/webcams/' . (int) $w['id'] . '/services';
$u = static fn (string $p = ''): string => View::e($req->url($base . $p));
$hidden = '<input type="hidden" name="_csrf" value="' . View::e($csrf) . '">';
$fromDolibarr = $svc['source'] === 'dolibarr' && $w['dolibarr_id'];
$ov = $overlay ?? [];
$ovFields = explode(',', (string) ($ov['fields'] ?? 'datetime,temperature_ext,humidity_ext,name'));
$ovApply = explode(',', (string) ($ov['apply_to'] ?? implode(',', Overlay::APPLY)));
$opt = $plan['options'];
$has = static fn (string $f): bool => in_array($f, $features, true);
$fieldLabels = ['datetime' => 'data e ora', 'temperature_ext' => 'temperatura', 'humidity_ext' => 'umidità', 'dewpoint_ext' => 'punto di rugiada',
	'name' => 'nome', 'location' => 'località', 'altitude' => 'quota', 'text' => 'testo fisso'];
$tlStatus = ['queued' => 'in coda', 'running' => 'in corso', 'done' => 'pronto', 'failed' => 'fallito', 'skipped' => 'saltato', 'cancelled' => 'annullato', 'expired' => 'scaduto'];
?>
<h1>Servizi: <?= View::e($w['name'] ?: $w['slug']) ?></h1>
<p class="small"><a href="<?= View::e($req->url('/admin/webcams/' . (int) $w['id'])) ?>">← scheda della webcam</a> · ore nel fuso <?= View::e($tz) ?></p>

<section class="card" id="listino">
	<h2>Listino e funzioni</h2>
	<p>Livello <strong><?= View::e($plan['level']) ?></strong><?= $plan['rental_end'] ? ' · noleggio fino al ' . View::e($plan['rental_end']) : '' ?>
		<?= $fromDolibarr ? '<span class="muted small">(da Dolibarr)</span>' : '' ?></p>
	<p class="small">Funzioni attive: <?= $features ? implode(', ', array_map(static fn ($f) => View::e(Features::LABELS[$f] ?? $f), $features)) : '—' ?></p>
	<p class="small muted">Server: timelapse <?= $server['timelapse'] ? 'sì' : 'no' ?>, Telegram <?= $server['telegram'] ? 'sì' : 'no' ?>.
		<?= !$ffmpeg['ok'] ? View::e(Ffmpeg::MISSING_MESSAGE) : 'ffmpeg ' . View::e((string) $ffmpeg['version']) ?></p>
	<?php if (!$fromDolibarr): ?>
	<form method="post" action="<?= $u('/plan') ?>" class="form narrow">
		<?= $hidden ?>
		<label>Livello <select name="plan_level"><?php foreach (Features::LEVELS as $l): ?><option<?= $plan['level'] === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<label class="check"><input type="checkbox" name="opt_weather_cert" value="1"<?= !empty($opt['weather_cert']) ? ' checked' : '' ?>> opzione certificato maltempo</label>
		<label class="check"><input type="checkbox" name="opt_sponsor" value="1"<?= !empty($opt['sponsor']['enabled']) ? ' checked' : '' ?>> opzione sponsor</label>
		<label>Spazi sponsor <input name="opt_sponsor_slots" inputmode="numeric" value="<?= (int) ($opt['sponsor']['slots'] ?? 2) ?>"></label>
		<label>Filmato finale <select name="opt_film"><?php foreach (['' => 'no', 'auto' => 'automatico', 'edited' => 'montato'] as $k => $l): ?><option value="<?= $k ?>"<?= ($opt['film']['option'] ?? '') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<label>Inizio noleggio <input name="rental_start" type="date" value="<?= View::e($plan['rental_start']) ?>"></label>
		<label>Fine noleggio <input name="rental_end" type="date" value="<?= View::e($plan['rental_end']) ?>"></label>
		<button type="submit">Salva il listino</button>
	</form>
	<?php endif; ?>
</section>

<section class="card" id="sovrimpressione">
	<h2>Sovrimpressione</h2>
	<p class="small"><?= $overlayActive ? '<span class="badge ok">attiva</span>' : '<span class="badge never-muted">spenta</span>' ?>
		<?= !$has('overlay') ? 'La funzione non è nel listino (standard o pro).' : '' ?>
		<?= $ov ? 'versione ' . (int) $ov['overlay_rev'] : '' ?></p>
	<form method="post" action="<?= $u('/overlay') ?>" class="form narrow">
		<?= $hidden ?>
		<label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($ov['enabled']) ? ' checked' : '' ?>> sovrimpressione attiva</label>
		<fieldset><legend>Dati</legend>
		<?php foreach (Overlay::FIELDS as $f): ?>
			<label class="check"><input type="checkbox" name="fields[]" value="<?= $f ?>"<?= in_array($f, $ovFields, true) ? ' checked' : '' ?>> <?= View::e($fieldLabels[$f] ?? $f) ?></label>
		<?php endforeach; ?>
		</fieldset>
		<label>Testo fisso <input name="custom_text" maxlength="120" value="<?= View::e($ov['custom_text'] ?? '') ?>"></label>
		<label>Fascia <select name="bar_position"><?php foreach (['bottom' => 'in basso', 'top' => 'in alto'] as $k => $l): ?><option value="<?= $k ?>"<?= ($ov['bar_position'] ?? 'bottom') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<label>Allineamento <select name="text_align"><?php foreach (['left' => 'a sinistra', 'center' => 'al centro', 'right' => 'a destra'] as $k => $l): ?><option value="<?= $k ?>"<?= ($ov['text_align'] ?? 'left') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<label>Opacità della fascia (%) <input name="bar_opacity_pct" inputmode="numeric" value="<?= (int) ($ov['bar_opacity_pct'] ?? 55) ?>"></label>
		<label>Dimensione del testo (%) <input name="font_scale_pct" inputmode="numeric" value="<?= (int) ($ov['font_scale_pct'] ?? 100) ?>"></label>
		<label>Lingua <select name="locale"><?php foreach (['it', 'en', 'de', 'fr'] as $l): ?><option<?= ($ov['locale'] ?? 'it') === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<label>Logo <select name="logo_asset_id"><option value="0">nessuno</option><?php foreach ($assets as $a): if ($a['kind'] !== 'logo') { continue; } ?><option value="<?= (int) $a['id'] ?>"<?= (int) ($ov['logo_asset_id'] ?? 0) === (int) $a['id'] ? ' selected' : '' ?>><?= View::e(($a['label'] ?: 'logo') . ' (' . $a['width'] . '×' . $a['height'] . ')') ?></option><?php endforeach; ?></select></label>
		<label>Angolo del logo <select name="logo_position"><?php foreach (['tl' => 'alto a sinistra', 'tr' => 'alto a destra', 'bl' => 'basso a sinistra', 'br' => 'basso a destra'] as $k => $l): ?><option value="<?= $k ?>"<?= ($ov['logo_position'] ?? 'tr') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<label>Larghezza del logo (% dell'immagine) <input name="logo_width_pct" inputmode="numeric" value="<?= (int) ($ov['logo_width_pct'] ?? 12) ?>"></label>
		<label>Opacità del logo (%) <input name="logo_opacity_pct" inputmode="numeric" value="<?= (int) ($ov['logo_opacity_pct'] ?? 100) ?>"></label>
		<fieldset><legend>Su quali immagini</legend>
		<?php foreach (Overlay::APPLY as $a): ?>
			<label class="check"><input type="checkbox" name="apply_to[]" value="<?= $a ?>"<?= in_array($a, $ovApply, true) ? ' checked' : '' ?>> <?= View::e($a) ?></label>
		<?php endforeach; ?>
		</fieldset>
		<label>current.jpg <select name="current_size"><?php foreach (['1920' => '1920 px con sovrimpressione', '1280' => '1280 px con sovrimpressione', 'orig' => 'originale senza sovrimpressione'] as $k => $l): ?><option value="<?= $k ?>"<?= (string) ($ov['current_size'] ?? '1920') === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
		<button type="submit">Salva la sovrimpressione</button>
	</form>
	<form method="post" action="<?= $u('/logo') ?>" enctype="multipart/form-data" class="form narrow">
		<h3>Carica un logo o l'immagine di uno sponsor</h3>
		<?= $hidden ?>
		<label>File PNG o JPEG (al più 2 MB, 2000 px) <input type="file" name="logo" accept="image/png,image/jpeg" required></label>
		<label>Nome <input name="label" maxlength="100"></label>
		<label>Tipo <select name="kind"><option value="logo">logo (diventa il logo della sovrimpressione)</option><option value="sponsor">sponsor</option></select></label>
		<button type="submit">Carica</button>
	</form>
	<?php if (!empty($w['is_public'])): ?>
	<p class="small"><a href="<?= View::e($req->url('/cam/' . $w['slug'] . '/current.jpg')) ?>">current.jpg pubblico</a></p>
	<?php endif; ?>
</section>

<section class="card" id="sponsor">
	<h2>Sponsor e infopoint</h2>
	<p class="small muted"><?= $has('sponsor') ? 'Spazi sponsor: ' . (int) $slots . '.' : 'La funzione sponsor non è attiva (listino pro o opzione): gli elementi si salvano ma non si mostrano.' ?></p>
	<?php if ($items): ?>
	<div class="table-wrap"><table class="list">
		<thead><tr><th>Tipo</th><th>Nome</th><th>Periodo</th><th>Posizione</th><th>Stampato</th><th>Attivo</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($items as $i): ?>
			<tr><td><?= View::e($i['kind']) ?></td><td><?= View::e($i['label']) ?><?= $i['text'] ? '<div class="small muted">' . View::e($i['text']) . '</div>' : '' ?></td>
				<td><?= View::e(($i['valid_from'] ?? '…') . ' → ' . ($i['valid_to'] ?? '…')) ?></td>
				<td><?= $i['kind'] === 'infopoint' ? View::e($i['x_pct'] . '%, ' . $i['y_pct'] . '%') : '—' ?></td>
				<td><?= $i['burn'] ? 'sì' : 'no' ?></td><td><?= $i['active'] ? 'sì' : 'no' ?></td>
				<td><form method="post" action="<?= $u('/items/' . (int) $i['id'] . '/delete') ?>" class="inline"><?= $hidden ?><button class="link" type="submit">togli</button></form></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php endif; ?>
	<form method="post" action="<?= $u('/items') ?>" class="form narrow">
		<h3>Nuovo elemento</h3>
		<?= $hidden ?>
		<label>Tipo <select name="kind"><option value="sponsor">sponsor</option><option value="infopoint">infopoint</option></select></label>
		<label>Nome <input name="label" maxlength="80" required></label>
		<label>Testo <input name="text" maxlength="255"></label>
		<label>Link (https://...) <input name="url" maxlength="255"></label>
		<label>Immagine <select name="asset_id"><option value="0">nessuna</option><?php foreach ($assets as $a): ?><option value="<?= (int) $a['id'] ?>"><?= View::e(($a['label'] ?: $a['kind']) . ' (' . $a['kind'] . ')') ?></option><?php endforeach; ?></select></label>
		<label>Infopoint: x (%) <input name="x_pct" inputmode="decimal"></label>
		<label>Infopoint: y (%) <input name="y_pct" inputmode="decimal"></label>
		<label>Dal <input name="valid_from" type="date"></label>
		<label>Al <input name="valid_to" type="date"></label>
		<label class="check"><input type="checkbox" name="burn" value="1" checked> stampato sulle immagini con sovrimpressione</label>
		<button type="submit">Aggiungi</button>
	</form>
</section>

<section class="card" id="timelapse">
	<h2>Timelapse</h2>
	<?php if (!$ffmpeg['ok']): ?><p class="flash error"><?= View::e(Ffmpeg::MISSING_MESSAGE) ?></p><?php endif; ?>
	<p class="small muted">Foto valutate negli ultimi 30 giorni: <?= (int) ($scores['n'] ?? 0) ?>, buone <?= (int) ($scores['good'] ?? 0) ?>.</p>
	<?php if ($timelapses): ?>
	<div class="table-wrap"><table class="list">
		<thead><tr><th>Id</th><th>Tipo</th><th>Periodo</th><th>Formato</th><th>Stato</th><th class="num">Fotogrammi</th><th class="num">Durata</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($timelapses as $t): ?>
			<tr><td><?= (int) $t['id'] ?></td><td><?= View::e($t['kind']) ?></td><td><?= View::e($t['period_from'] . ($t['period_to'] !== $t['period_from'] ? ' → ' . $t['period_to'] : '')) ?></td>
				<td><?= View::e($t['format'] . ' ' . $t['width'] . '×' . $t['height']) ?></td>
				<td><?= View::e($tlStatus[$t['status']] ?? $t['status']) ?><?= $t['status_reason'] ? ' <span class="small muted">(' . View::e($t['status_reason']) . ')</span>' : '' ?></td>
				<td class="num"><?= $t['frames_used'] === null ? '—' : (int) $t['frames_used'] ?></td>
				<td class="num"><?= $t['duration_s'] === null ? '—' : View::num($t['duration_s'], 1, ' s') ?></td>
				<td><?php if ($t['status'] === 'done'): ?><a href="<?= $u('/timelapse/' . (int) $t['id'] . '/download') ?>">video</a> · <a href="<?= $u('/timelapse/' . (int) $t['id'] . '/poster') ?>">poster</a>
					<?php elseif (in_array($t['status'], ['failed', 'cancelled', 'skipped', 'expired'], true)): ?>
					<form method="post" action="<?= $u('/timelapse/' . (int) $t['id'] . '/requeue') ?>" class="inline"><?= $hidden ?><button class="link" type="submit">rimetti in coda</button></form><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php else: ?><p class="muted">Nessun timelapse.</p><?php endif; ?>
	<div class="grid2">
	<form method="post" action="<?= $u('/timelapse/request') ?>" class="form">
		<h3>Timelapse di un periodo</h3>
		<?= $hidden ?>
		<label>Tipo <select name="kind"><?php foreach (['custom', 'day', 'week', 'month'] as $k): ?><option><?= $k ?></option><?php endforeach; ?></select></label>
		<label>Dal <input name="from" type="date" required></label>
		<label>Al <input name="to" type="date" required></label>
		<label>Formato <select name="format"><option value="landscape">orizzontale</option><option value="portrait">verticale</option></select></label>
		<label>Fotogrammi <select name="selection"><option value="">predefinito</option><option value="good">solo foto buone</option><option value="all">tutte</option><option value="one_per_day">una al giorno</option></select></label>
		<button type="submit">Metti in coda</button>
	</form>
	<div>
	<form method="post" action="<?= $u('/timelapse') ?>" class="form">
		<h3>Ritaglio e luce</h3>
		<?= $hidden ?>
		<label>Centro del ritaglio orizzontale (%) <input name="crop_x_pct" inputmode="numeric" value="<?= (int) $tlParams['crop_x_pct'] ?>"></label>
		<label>Centro del ritaglio verticale (%) <input name="crop_y_pct" inputmode="numeric" value="<?= (int) $tlParams['crop_y_pct'] ?>"></label>
		<label>Ora della stessa luce (una al giorno) <input name="same_light_time" value="<?= View::e($tlParams['same_light_time']) ?>" pattern="\d{2}:\d{2}"></label>
		<button type="submit">Salva</button>
	</form>
	<form method="post" action="<?= $u('/good') ?>" class="form">
		<h3>Soglie delle foto buone</h3>
		<?= $hidden ?>
		<?php foreach ($good as $k => $v): ?>
			<label><?= View::e($k) ?> <input name="good[<?= View::e($k) ?>]" inputmode="decimal" value="<?= View::e($v) ?>"></label>
		<?php endforeach; ?>
		<button type="submit">Salva le soglie</button>
	</form>
	</div>
	</div>
</section>

<section class="card" id="foto-del-giorno">
	<h2>Foto del giorno</h2>
	<?= !$has('photo_of_day') ? '<p class="small muted">La funzione non è nel listino.</p>' : '' ?>
	<?php if ($pods): ?>
	<div class="table-wrap"><table class="list">
		<thead><tr><th>Giorno</th><th>Foto</th><th class="num">Punteggio</th><th>Scelta da</th><th>Telegram</th></tr></thead>
		<tbody>
		<?php foreach ($pods as $d): ?>
			<tr><td><?= View::e($d['day']) ?></td>
				<td><a href="<?= View::e($req->url('/admin/webcams/' . (int) $w['id'] . '/photos/' . (int) $d['photo_id'] . '.jpg')) ?>"><?= View::e(View::local($d['taken_at'], $tz, 'H:i')) ?></a></td>
				<td class="num"><?= View::num($d['score'], 1) ?></td><td><?= View::e($d['chosen_by']) ?></td><td><?= $d['telegram_sent_at'] ? 'mandata' : '—' ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php endif; ?>
	<?php if ($best): ?>
	<form method="post" action="<?= $u('/pod') ?>" class="form narrow">
		<h3>Scegli un'altra foto (fino alle 23:59 del giorno dopo)</h3>
		<?= $hidden ?>
		<label>Foto buone di ieri e di oggi <select name="photo_id">
			<?php foreach ($best as $b): ?><option value="<?= (int) $b['photo_id'] ?>"><?= View::e(View::local($b['taken_at'], $tz, 'd/m H:i') . ' · punteggio ' . $b['score']) ?></option><?php endforeach; ?>
		</select></label>
		<button type="submit">Scegli</button>
	</form>
	<?php endif; ?>
</section>

<section class="card" id="statistiche">
	<h2>Statistiche (ultimi 30 giorni)</h2>
	<?= !$has('stats') ? '<p class="small muted">Si raccolgono sempre; il cliente le vede solo con il listino pro.</p>' : '' ?>
	<p>Visualizzazioni <?= (int) $stats['totals']['views'] ?> · visitatori <?= (int) $stats['totals']['visitors'] ?> · tempo medio <?= (int) $stats['totals']['avg_view_s'] ?> s ·
		current.jpg <?= (int) $stats['totals']['image_requests'] ?> · API <?= (int) $stats['totals']['api_requests'] ?> · Telegram <?= (int) $stats['totals']['telegram'] ?></p>
	<?php if ($stats['hosts']): ?>
	<p class="small">Siti: <?= implode(', ', array_map(static fn ($h) => View::e($h['host']) . ' (' . (int) $h['views'] . ')', array_slice($stats['hosts'], 0, 10))) ?></p>
	<?php endif; ?>
</section>

<section class="card" id="registro">
	<h2>Registro delle impronte</h2>
	<?php if ($register): ?>
	<div class="table-wrap"><table class="list">
		<thead><tr><th>Giorno</th><th class="num">Foto</th><th>Meteo</th><th>Hash del giorno</th><th>Dolibarr</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($register as $r): ?>
			<tr><td><?= View::e($r['day']) ?></td><td class="num"><?= (int) $r['photos_total'] ?><?= $r['late_photos'] ? ' <span class="small muted">+' . (int) $r['late_photos'] . ' tardive</span>' : '' ?></td>
				<td><?= View::e((string) $r['classification']) ?><?= $r['reasons'] ? ' <span class="small muted">' . View::e($r['reasons']) . '</span>' : '' ?></td>
				<td><code class="small"><?= View::e(substr((string) $r['day_hash'], 0, 16)) ?>…</code></td>
				<td><?= $r['dolibarr_registered_at'] ? View::e(View::local($r['dolibarr_registered_at'], $tz)) : '—' ?></td>
				<td><?php if ($r['sealed_at']): ?><a href="<?= $u('/register/' . $r['day']) ?>">elenco</a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php else: ?><p class="muted">Nessun giorno sigillato (bin/register.php).</p><?php endif; ?>
	<?php if ($w['customer_id'] !== null): ?>
	<form method="post" action="<?= $u('/certificate') ?>" class="form narrow">
		<h3>Certificato di maltempo per un periodo</h3>
		<?= $hidden ?>
		<label>Dal <input name="from" type="date" required></label>
		<label>Al <input name="to" type="date" required></label>
		<button type="submit">Genera</button>
	</form>
	<?php endif; ?>
</section>
