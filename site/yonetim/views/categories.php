<?php
$cats = $cats ?? [];
$err = (string) ($err ?? '');
?>
<div class="page-head">
    <h1>Kategoriler</h1>
    <p>Ürün kategorisi ekleyin veya listeden düzenleyin.</p>
</div>
<?php if ($err !== ''): ?>
    <p class="err"><?= h($err) ?></p>
<?php endif; ?>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Yeni kategori</h2>
        <div class="row2">
            <label><span>Ad</span><input name="name" type="text" required></label>
            <label><span>Slug</span><input name="slug" type="text" placeholder="otomatik"></label>
        </div>
        <div class="row2">
            <label>
                <span>Üst kategori</span>
                <select name="parent_id">
                    <option value="0">— Ana kategori —</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= h($c['path']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Sıra</span><input name="sort" type="number" value="0"></label>
        </div>
        <div class="row2">
            <label>
                <span>Durum</span>
                <select name="status">
                    <option value="publish">Yayında</option>
                    <option value="draft">Taslak</option>
                </select>
            </label>
            <label><span>Min. sipariş adedi</span><input name="min_qty" type="number" min="0" value="0"></label>
        </div>
        <label class="remember"><input type="checkbox" name="hide_nav" value="1"> Menüde gizle</label>
        <label><span>Açıklama (HTML)</span><textarea name="description" rows="3"></textarea></label>
        <label><span>SEO başlık</span><input name="seo_title" type="text"></label>
        <label><span>SEO açıklama</span><textarea name="seo_description" rows="2"></textarea></label>
        <button class="btn" type="submit">Kategori ekle</button>
    </div>
</form>
<div class="table-wrap" style="margin-top:20px">
<table>
    <tr><th>Yol</th><th>Ad</th><th>Durum</th><th></th></tr>
    <?php foreach ($cats as $c): ?>
        <tr>
            <td><a href="<?= h(permalink_category($c)) ?>" target="_blank" rel="noopener"><?= h($c['path']) ?></a></td>
            <td><?= h($c['name']) ?></td>
            <td><?= h((string) ($c['status'] ?? 'publish')) ?><?= !empty($c['hide_nav']) ? ' · menü gizli' : '' ?></td>
            <td><a class="btn ghost" href="<?= h(site_url('yonetim/kategoriler/' . (int) $c['id'] . '/')) ?>">Düzenle</a></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
