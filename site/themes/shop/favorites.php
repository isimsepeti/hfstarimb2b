<?php require __DIR__ . '/partials/card.php'; ?>
<header class="archive-head wrap">
    <h1>Favoriler</h1>
</header>
<?php if (empty($items)): ?>
    <p class="wrap empty">Favori ürün yok.</p>
<?php else: ?>
    <div class="pgrid wrap">
        <?php foreach ($items as $p) {
            shop_card($p);
        } ?>
    </div>
<?php endif; ?>
