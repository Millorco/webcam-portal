<?php
/** @var \WebcamPortal\Http\Request $req @var string $csrf @var string $error @var string $email */
use WebcamPortal\Web\View;
?>
<section class="card narrow">
	<h1>Accesso operatori</h1>
	<?php if ($error): ?><p class="flash error"><?= View::e($error) ?></p><?php endif; ?>
	<form method="post" action="<?= View::e($req->url('/admin/login')) ?>" class="form">
		<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
		<label>Email <input type="email" name="email" value="<?= View::e($email) ?>" required autofocus autocomplete="username"></label>
		<label>Password <input type="password" name="password" required autocomplete="current-password"></label>
		<button type="submit">Entra</button>
	</form>
	<p class="muted small">Gli utenti si creano da riga di comando: <code>php bin/user-add.php</code>.</p>
</section>
