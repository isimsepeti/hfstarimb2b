<?php require __DIR__ . '/partials/crumbs.php'; ?>
<header class="archive-head wrap">
    <h1>Yazılar</h1>
    <p>Beden, bakım, koleksiyon. Kısa notlar.</p>
</header>
<?php if ($items): ?>
    <div class="wrap">
        <?php foreach ($items as $p): ?>
            <article class="note">
                <h2><a href="<?= h(permalink_post($p)) ?>"><?= h($p['title']) ?></a></h2>
                <p><?= h($p['excerpt'] ?: excerpt_plain($p['content'], 180)) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
    <?php $base = site_url('yazi/'); require __DIR__ . '/partials/pager.php'; ?>
<?php else: ?>
    <p class="wrap empty">Henüz yazı yok.</p>
<?php endif; ?>
