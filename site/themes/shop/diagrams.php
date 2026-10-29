<?php
require __DIR__ . '/partials/card.php';
require __DIR__ . '/partials/crumbs.php';
?>
<header class="archive-head wrap">
    <h1>Parça diyagramları</h1>
    <p class="dek">Görseldeki numaraya tıklayarak yedek parçayı sepete ekleyin.</p>
</header>
<?php if ($items): ?>
    <div class="pgrid wrap">
        <?php foreach ($items as $p) {
            shop_card($p);
        } ?>
    </div>
<?php else: ?>
    <p class="wrap empty">Henüz parça diyagramı tanımlı değil.</p>
<?php endif; ?>
