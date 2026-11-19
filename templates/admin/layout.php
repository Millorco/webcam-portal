<?php
/** @var \WebcamPortal\Http\Request $req @var ?array $user @var string $title @var string $body @var string $csrf @var array $flash */
use WebcamPortal\Web\View;
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= View::e($title) ?> - webcam-portal</title>
<link rel="stylesheet" href="<?= View::e($req->url('/assets/style.css')) ?>">
</head>
<body>
<header class="top">
	<a class="brand" href="<?= View::e($req->url('/admin/')) ?>">webcam-portal</a>
	<?php if ($user): ?>
	<nav>
		<a href="<?= View::e($req->url('/admin/')) ?>">Webcam</a>
		<a href="<?= View::e($req->url('/admin/alerts')) ?>">Avvisi</a>
		<a href="<?= View::e($req->url('/admin/webcams/new')) ?>">Nuova webcam</a>
		<form method="post" action="<?= View::e($req->url('/admin/logout')) ?>" class="inline">
			<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
			<button class="link" type="submit">Esci (<?= View::e($user['email']) ?>)</button>
		</form>
	</nav>
	<?php endif; ?>
</header>
<main class="wrap">
<?php foreach ($flash as [$type, $msg]): ?>
	<p class="flash <?= View::e($type) ?>"><?= View::e($msg) ?></p>
<?php endforeach; ?>
<?= $body ?>
</main>
<footer class="wrap muted small">webcam-portal <?= View::e(WEBCAM_PORTAL_VERSION) ?> · ore nel fuso della webcam</footer>
</body>
</html>
