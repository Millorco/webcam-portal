<?php
/** @var \WebcamPortal\Http\Request $req @var string $csrf @var array $in @var string $error */
use WebcamPortal\Web\View;
?>
<h1>Nuova webcam</h1>
<?php if ($error): ?><p class="flash error"><?= View::e($error) ?></p><?php endif; ?>
<form method="post" action="<?= View::e($req->url('/admin/webcams/new')) ?>" class="form card narrow">
	<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
	<label>MAC del router <input name="mac" value="<?= View::e($in['mac'] ?? '') ?>" required placeholder="94:83:c4:12:ab:cd"></label>
	<label>Nome <input name="name" value="<?= View::e($in['name'] ?? '') ?>" required placeholder="Monte Cavo"></label>
	<label>Slug (URL pubblico, vuoto = dal nome) <input name="slug" value="<?= View::e($in['slug'] ?? '') ?>" placeholder="monte-cavo" pattern="[a-z0-9][a-z0-9-]{0,63}"></label>
	<label>Località <input name="location" value="<?= View::e($in['location'] ?? '') ?>"></label>
	<label>Fuso orario <input name="timezone" value="<?= View::e($in['timezone'] ?? 'Europe/Rome') ?>" required></label>
	<label class="check"><input type="checkbox" name="is_public" value="1" <?= !empty($in['is_public']) ? 'checked' : '' ?>> Pubblica (current.jpg visibile a tutti)</label>
	<button type="submit">Crea e genera il token</button>
</form>
