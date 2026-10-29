<?php
$diagram = $diagram ?? null;
if (!$diagram) {
    return;
}
$csrf = csrf_token();
$cartUrl = site_url('sepet/');
?>
<section class="hfs-b2b-diagram-product-shell wrap" id="hfs-b2b-parts-diagram-outer">
    <div class="hfs-b2b-parts-diagram">
        <div class="hfs-b2b-parts-diagram-main">
            <div class="hfs-b2b-parts-diagram-stage">
                <?php if (!empty($diagram['is_pdf'])): ?>
                    <div class="hfs-b2b-diagram-pdf-wrap">
                        <iframe src="<?= h($diagram['image']) ?>#toolbar=0" title="Parça diyagramı"></iframe>
                    </div>
                <?php else: ?>
                    <img src="<?= h($diagram['image']) ?>" alt="<?= h($p['title']) ?>" decoding="async">
                <?php endif; ?>
                <?php foreach ($diagram['groups'] as $group):
                    $labels = [];
                    foreach ($group['items'] as $it) {
                        if ($it['part'] !== '') {
                            $labels[] = $it['part'];
                        }
                    }
                    $label = $labels ? implode(', ', array_unique($labels)) : '•';
                    $hsW = (float) ($group['width'] ?? 0);
                    $hsH = (float) ($group['height'] ?? 0);
                    $style = 'left: ' . h((string) $group['left']) . '%; top: ' . h((string) $group['top']) . '%;';
                    if ($hsW > 0) {
                        $style .= '--hs-w: ' . h((string) $hsW) . '%; width: ' . h((string) $hsW) . '%;';
                    }
                    if ($hsH > 0) {
                        $style .= 'height: ' . h((string) $hsH) . '%;';
                    }
                    ?>
                    <button type="button" class="hfs-b2b-parts-hotspot" style="<?= $style ?>" data-hotspot-products="<?= h((string) json_encode($group['items'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS)) ?>"><?= h($label) ?></button>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($diagram['groups'])): ?>
                <p class="hfs-b2b-diagram-hint">Parça numarasına tıklayın, adet seçip sepete ekleyin.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($diagram['parts'])): ?>
    <div class="hfs-b2b-diagram-parts-list">
        <h2 class="hfs-b2b-diagram-parts-list__title">Parça listesi</h2>
        <ul class="hfs-b2b-diagram-parts-grid">
            <?php foreach ($diagram['parts'] as $row):
                $pp = $row['product'];
                $lab = implode(', ', $row['parts']);
                ?>
                <li class="hfs-b2b-diagram-parts-grid__item">
                    <div class="hfs-b2b-diagram-parts-card">
                        <div class="hfs-b2b-diagram-parts-card__image-wrap">
                            <?php if ($lab !== ''): ?><span class="hfs-b2b-diagram-parts-card__part">#<?= h($lab) ?></span><?php endif; ?>
                            <a class="hfs-b2b-diagram-parts-card__image" href="<?= h(permalink_product($pp)) ?>">
                                <img src="<?= h(hfs_product_image_url($pp)) ?>" alt="">
                            </a>
                        </div>
                        <div class="hfs-b2b-diagram-parts-card__body">
                            <?php if ($pp['sku']): ?><div class="hfs-b2b-diagram-parts-card__code"><code><?= h($pp['sku']) ?></code></div><?php endif; ?>
                            <h3 class="hfs-b2b-diagram-parts-card__title"><a href="<?= h(permalink_product($pp)) ?>"><?= h($pp['title']) ?></a></h3>
                            <div class="hfs-b2b-diagram-parts-card__price"><?= HfsPricing::html($pp) ?></div>
                            <form method="post" action="<?= h($cartUrl) ?>" class="hfs-b2b-add-to-cart-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="add">
                                <input type="hidden" name="product_id" value="<?= (int) $pp['id'] ?>">
                                <input type="hidden" name="back" value="<?= h(page_url()) ?>">
                                <div class="hfs-b2b-parts-product__actions">
                                    <span class="hfs-b2b-quantity-wrap">
                                        <button type="button" class="hfs-b2b-qty-minus">−</button>
                                        <input type="number" name="qty" value="1" min="1" max="999">
                                        <button type="button" class="hfs-b2b-qty-plus">+</button>
                                    </span>
                                    <button class="btn" type="submit">Sepete</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</section>
<div id="hfs-b2b-diagram-popup" class="hfs-b2b-diagram-popup" aria-hidden="true">
    <div class="hfs-b2b-diagram-popup__inner">
        <button type="button" class="hfs-b2b-diagram-popup__close" aria-label="Kapat">&times;</button>
        <div id="hfs-b2b-diagram-popup-content"></div>
    </div>
</div>
<script type="application/json" id="hfs-diagram-boot"><?= json_encode(['csrf' => $csrf, 'cart' => $cartUrl, 'back' => page_url()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php if (!empty($diagram['related'])): ?>
<section class="hfs-b2b-diagram-related-products wrap">
    <h2 class="hfs-b2b-diagram-related-products__title">Aynı kategorideki parça diyagramları</h2>
    <div class="pgrid">
        <?php foreach ($diagram['related'] as $rp) {
            shop_card($rp);
        } ?>
    </div>
</section>
<?php endif; ?>
