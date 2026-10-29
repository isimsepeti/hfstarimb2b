<?php require __DIR__ . '/partials/card.php'; ?>
<header class="archive-head wrap">
    <h1><?= $q !== '' ? 'Arama: ' . h($q) : 'Arama' ?></h1>
    <p><?= (int) ($pager['total'] ?? 0) ?> sonuç. Bu sayfa indekslenmez.</p>
</header>
<?php if ($items): ?>
    <div class="pgrid wrap">
        <?php foreach ($items as $p) {
            shop_card($p);
        } ?>
    </div>
<?php else: ?>
    <p class="wrap empty">Eşleşen ürün yok. <a href="<?= h(site_url()) ?>">Dükkâna dön</a>.</p>
<?php endif; ?>
