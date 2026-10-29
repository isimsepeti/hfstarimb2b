<?php $post = $post ?? []; $id = (int) ($post['id'] ?? 0); ?>
<div class="page-head"><h1><?= $id ? 'Yazıyı düzenle' : 'Yeni yazı' ?></h1></div>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Yazı</h2>
        <label><span>Başlık</span><input name="title" type="text" value="<?= h($post['title'] ?? '') ?>" required></label>
        <label><span>Slug</span><input name="slug" type="text" value="<?= h($post['slug'] ?? '') ?>"></label>
        <label><span>Özet</span><textarea name="excerpt" rows="2"><?= h($post['excerpt'] ?? '') ?></textarea></label>
        <label><span>İçerik</span><textarea class="content" name="content" rows="14"><?= h($post['content'] ?? '') ?></textarea></label>
        <label>
            <span>Durum</span>
            <select name="status">
                <option value="publish"<?= ($post['status'] ?? '') === 'publish' ? ' selected' : '' ?>>Yayında</option>
                <option value="draft"<?= ($post['status'] ?? '') === 'draft' ? ' selected' : '' ?>>Taslak</option>
            </select>
        </label>
    </div>
    <div class="card">
        <h2>SEO</h2>
        <label><span>SEO başlık</span><input name="seo_title" type="text" value="<?= h($post['seo_title'] ?? '') ?>"></label>
        <label><span>SEO açıklama</span><textarea name="seo_description" rows="2"><?= h($post['seo_description'] ?? '') ?></textarea></label>
    </div>
    <div class="toolbar">
        <button class="btn" type="submit">Kaydet</button>
        <a class="btn ghost" href="<?= h(site_url('yonetim/yazilar/')) ?>">Listeye dön</a>
    </div>
</form>
<?php if ($id > 0): ?>
<form method="post" style="margin-top:16px" onsubmit="return confirm('Bu yazı kalıcı olarak silinsin mi?');">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="delete">
    <button class="textlink" type="submit">Yazıyı sil</button>
</form>
<?php endif; ?>
