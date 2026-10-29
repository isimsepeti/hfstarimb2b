<div class="page-head">
    <div>
        <h1>Ürünler</h1>
        <p><?= (int) ($pager['total'] ?? count($items)) ?> ürün. SKU veya başlıkla arayın; listede sayfa sayfa gezin.</p>
    </div>
    <div class="page-head__actions">
        <a class="btn ghost" href="<?= h(site_url('yonetim/urunler/dis-aktar/')) ?>">Dışa aktar</a>
        <a class="btn" href="<?= h(site_url('yonetim/urunler/yeni/')) ?>">Yeni ürün</a>
    </div>
</div>
<form class="toolbar" method="get">
    <input type="search" name="q" value="<?= h($q ?? '') ?>" placeholder="Başlık veya SKU">
    <button class="btn ghost" type="submit">Ara</button>
</form>
<div class="table-wrap">
<table>
    <tr><th>Ürün</th><th>Fiyat</th><th>Stok</th><th>Durum</th></tr>
    <?php foreach ($items as $p): ?>
        <tr>
            <td><a href="<?= h(site_url('yonetim/urunler/' . $p['id'] . '/')) ?>"><?= h($p['title']) ?></a><br><small><?= h($p['sku']) ?></small></td>
            <td><?= h(money($p['price'])) ?></td>
            <td><?= (int) $p['stock'] ?></td>
            <td><span class="badge badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
</table>
</div>
<?php
$pager = $pager ?? ['page' => 1, 'pages' => 1, 'total' => count($items)];
$page = (int) $pager['page'];
$pages = (int) $pager['pages'];
$qNow = trim((string) ($q ?? ''));
$adminPage = static function (int $n) use ($qNow): string {
    $qs = [];
    if ($qNow !== '') {
        $qs['q'] = $qNow;
    }
    if ($n > 1) {
        $qs['sayfa'] = $n;
    }
    $url = site_url('yonetim/urunler/');
    return $qs === [] ? $url : $url . '?' . http_build_query($qs);
};
if ($pages > 1):
    ?>
<nav class="pager">
    <?php if ($page > 1): ?>
        <a href="<?= h($adminPage($page - 1)) ?>">Önceki</a>
    <?php endif; ?>
    <span><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?>
        <a href="<?= h($adminPage($page + 1)) ?>">Sonraki</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
