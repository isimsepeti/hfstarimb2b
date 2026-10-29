<?php
$pager = $pager ?? null;
$base = $base ?? site_url();
if ($pager && ($pager['pages'] ?? 1) > 1):
    $page = (int) $pager['page'];
    $pages = (int) $pager['pages'];
    ?>
<nav class="pager" aria-label="Sayfalar">
    <?php if ($page > 1): ?>
        <a href="<?= h(pager_url($base, $page - 1)) ?>">Önceki</a>
    <?php endif; ?>
    <span><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?>
        <a href="<?= h(pager_url($base, $page + 1)) ?>">Sonraki</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
