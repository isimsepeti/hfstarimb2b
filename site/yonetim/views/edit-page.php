<div class="page-head"><h1><?= h($post['title']) ?></h1></div>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Sayfa</h2>
        <label><span>Başlık</span><input name="title" type="text" value="<?= h($post['title']) ?>"></label>
        <label><span>İçerik</span><textarea class="content" name="content"><?= h($post['content']) ?></textarea></label>
        <label><span>SEO başlık</span><input name="seo_title" type="text" value="<?= h($post['seo_title']) ?>"></label>
        <label><span>SEO açıklama</span><textarea name="seo_description" rows="3"><?= h($post['seo_description']) ?></textarea></label>
    </div>
    <button class="btn" type="submit">Kaydet</button>
</form>
