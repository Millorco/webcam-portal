<?php
/** @var \WebcamPortal\Http\Request $req @var array $w @var ?array $data */
use WebcamPortal\Web\View;

$name = $w['name'] ?: $w['slug'];
$img = $req->url('/cam/' . $w['slug'] . '/current.jpg');
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title><?= View::e($name) ?> - webcam</title>
<link rel="stylesheet" href="<?= View::e($req->url('/assets/style.css')) ?>">
</head>
<body class="public">
<main class="wrap">
	<h1><?= View::e($name) ?></h1>
	<?php if ($w['location']): ?><p class="muted"><?= View::e($w['location']) ?></p><?php endif; ?>
	<?php if ($data === null): ?>
		<p>Nessuna foto ancora disponibile.</p>
	<?php else: ?>
		<figure class="cam">
			<a href="<?= View::e($img) ?>"><img src="<?= View::e($img) ?>?t=<?= (int) $data['time'] ?>" alt="Ultima foto di <?= View::e($name) ?>"></a>
			<figcaption>
				Foto delle <?= View::e((new DateTimeImmutable($data['datetime']))->format('H:i')) ?>
				del <?= View::e((new DateTimeImmutable($data['datetime']))->format('d/m/Y')) ?>
				<?php if ($data['temperature_ext'] !== null): ?> · <?= View::num($data['temperature_ext'], 1, '°C') ?><?php endif; ?>
				<?php if ($data['humidity_ext'] !== null): ?> · umidità <?= View::num($data['humidity_ext'], 0, '%') ?><?php endif; ?>
			</figcaption>
		</figure>
		<p class="muted small">Immagine fissa da incorporare: <code><?= View::e($data['url']) ?></code></p>
	<?php endif; ?>
</main>
</body>
</html>
