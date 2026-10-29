<?php require __DIR__ . '/partials/crumbs.php'; ?>
<article class="page wrap">
    <h1><?= h($post['title']) ?></h1>
    <p class="hint"><?= h(date('d.m.Y', strtotime((string) $post['published_at']))) ?></p>
    <div class="prose"><?= $post['content'] ?></div>
</article>
