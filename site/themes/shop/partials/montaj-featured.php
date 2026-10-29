<?php
$montaj = $montaj ?? [];
$montajItems = $montaj['items'] ?? [];
if ($montajItems === []) {
    return;
}
?>
<section class="hfs-b2b-montaj-featured wrap" data-hfs-montaj="<?= h((string) ($term['slug'] ?? '')) ?>">
    <div class="hfs-b2b-montaj-featured__head">
        <span class="hfs-b2b-montaj-featured__kicker"><?= h($montaj['kicker'] ?? 'Sistem Montajları') ?></span>
        <h2 class="hfs-b2b-montaj-featured__title"><?= h($montaj['title'] ?? 'Sistem Montajları') ?></h2>
    </div>
    <div class="hfs-b2b-montaj-featured__grid">
        <?php foreach ($montajItems as $mp):
            $href = permalink_product($mp);
            $img = hfs_media($mp['featured_media'] ?? '');
            if (!empty($mp['has_diagram'])) {
                $dimg = HfsDiagram::meta((int) $mp['id'], 'diagram_image', '');
                if (is_string($dimg) && trim($dimg) !== '') {
                    $img = hfs_media($dimg);
                }
            }
            ?>
            <article class="hfs-b2b-montaj-card">
                <a class="hfs-b2b-montaj-card__media" href="<?= h($href) ?>">
                    <img src="<?= h($img) ?>" alt="<?= h($mp['title']) ?>">
                </a>
                <div class="hfs-b2b-montaj-card__body">
                    <h3 class="hfs-b2b-montaj-card__title"><a href="<?= h($href) ?>"><?= h($mp['title']) ?></a></h3>
                    <a class="button hfs-b2b-montaj-card__btn" href="<?= h($href) ?>">İncele</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
