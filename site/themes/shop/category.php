<?php
require __DIR__ . '/partials/card.php';
require __DIR__ . '/partials/crumbs.php';
$base = permalink_category($term);
$pager = $pager ?? ['total' => count($items), 'page' => 1, 'per' => 24, 'pages' => 1];
$total = (int) ($pager['total'] ?? count($items));
$pageNow = (int) ($pager['page'] ?? 1);
$per = (int) ($pager['per'] ?? 24);
$from = $total > 0 ? (($pageNow - 1) * $per) + 1 : 0;
$to = min($total, $pageNow * $per);
?>
<header class="archive-head wrap">
    <h1><?= h($term['name']) ?></h1>
    <?php if (!empty($term['description'])): ?>
        <div class="prose intro"><?= $term['description'] ?></div>
    <?php endif; ?>
    <?php if ($children): ?>
        <p class="chips">
            <?php foreach ($children as $ch): ?>
                <a href="<?= h(permalink_category($ch)) ?>"><?= h($ch['name']) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</header>
<?php require __DIR__ . '/partials/montaj-featured.php'; ?>
<?php if ($items): ?>
    <div class="archive-products wrap">
        <p class="archive-toolbar"><?= h($from) ?>–<?= h((string) $to) ?> / <?= h((string) $total) ?> ürün</p>
        <div class="pgrid">
            <?php foreach ($items as $p) {
                shop_card($p);
            } ?>
        </div>
        <?php require __DIR__ . '/partials/pager.php'; ?>
    </div>
<?php elseif (empty($montaj['items'])): ?>
    <p class="wrap empty">Bu kategoride henüz ürün yok.</p>
<?php endif; ?>
