<?php
$cat = $cat ?? [];
$cats = $cats ?? [];
$err = (string) ($err ?? '');
$ok = !empty($ok);
$id = (int) ($cat['id'] ?? 0);
$parentId = (int) ($cat['parent_id'] ?? 0);
?>
<div class="page-head">
    <h1><?= h((string) ($cat['name'] ?? 'Kategori')) ?></h1>
    <p><a href="<?= h(site_url('yonetim/kategoriler/')) ?>">← Kategoriler</a>
        · <a href="<?= h(permalink_category($cat)) ?>" target="_blank" rel="noopener">Sitede gör</a></p>
</div>
<?php if ($ok): ?><p class="ok">Kaydedildi.</p><?php endif; ?>
<?php if ($err !== ''): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Kategori düzenle</h2>
        <div class="row2">
            <label><span>Ad</span><input name="name" type="text" required value="<?= h((string) ($cat['name'] ?? '')) ?>"></label>
            <label><span>Slug</span><input name="slug" type="text" value="<?= h((string) ($cat['slug'] ?? '')) ?>"></label>
        </div>
        <div class="row2">
            <label>
                <span>Üst kategori</span>
                <select name="parent_id">
                    <option value="0">— Ana kategori —</option>
                    <?php foreach ($cats as $c):
                        if ((int) $c['id'] === $id) {
                            continue;
                        }
                        ?>
                        <option value="<?= (int) $c['id'] ?>"<?= $parentId === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['path']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Sıra</span><input name="sort" type="number" value="<?= (int) ($cat['sort'] ?? 0) ?>"></label>
        </div>
        <div class="row2">
            <label>
                <span>Durum</span>
                <select name="status">
                    <?php foreach (['publish' => 'Yayında', 'draft' => 'Taslak'] as $val => $label): ?>
                        <option value="<?= $val ?>"<?= (($cat['status'] ?? '') === $val) ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Min. sipariş adedi</span><input name="min_qty" type="number" min="0" value="<?= (int) ($cat['min_qty'] ?? 0) ?>"></label>
        </div>
        <label class="remember"><input type="checkbox" name="hide_nav" value="1"<?= !empty($cat['hide_nav']) ? ' checked' : '' ?>> Menüde gizle</label>
        <p class="field-hint">Yol: <code><?= h((string) ($cat['path'] ?? '')) ?></code> — slug veya üst değişince alt kategorilerin yolu da güncellenir.</p>
        <label><span>Açıklama (HTML)</span><textarea name="description" rows="5"><?= h((string) ($cat['description'] ?? '')) ?></textarea></label>
        <label><span>SEO başlık</span><input name="seo_title" type="text" value="<?= h((string) ($cat['seo_title'] ?? '')) ?>"></label>
        <label><span>SEO açıklama</span><textarea name="seo_description" rows="2"><?= h((string) ($cat['seo_description'] ?? '')) ?></textarea></label>
        <div class="toolbar">
            <button class="btn" type="submit">Kaydet</button>
            <a class="btn ghost" href="<?= h(site_url('yonetim/kategoriler/')) ?>">Listeye dön</a>
        </div>
    </div>
</form>
