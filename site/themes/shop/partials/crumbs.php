<?php if (!empty($crumbs)): ?>
<nav class="crumbs" aria-label="Yol">
    <?php foreach ($crumbs as $i => $c): ?>
        <?php if ($i > 0): ?><span aria-hidden="true">/</span><?php endif; ?>
        <?php if ($i === count($crumbs) - 1): ?>
            <span><?= h($c['name']) ?></span>
        <?php else: ?>
            <a href="<?= h($c['url']) ?>"><?= h($c['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
