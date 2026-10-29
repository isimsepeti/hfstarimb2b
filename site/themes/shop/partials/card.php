<?php
if (!function_exists('shop_sale_pct')):
function shop_sale_pct(array $p): int
{
    $price = (float) $p['price'];
    $cmp = isset($p['compare_price']) && $p['compare_price'] !== null && $p['compare_price'] !== '' ? (float) $p['compare_price'] : 0;
    if ($cmp <= $price || $price <= 0) {
        return 0;
    }
    return max(1, (int) round((1 - ($price / $cmp)) * 100));
}

function shop_card(array $p): void
{
    $href = permalink_product($p);
    $img = hfs_media($p['featured_media'] ?? '');
    if (!empty($p['has_diagram'])) {
        $dimg = HfsDiagram::meta((int) $p['id'], 'diagram_image', '');
        if (is_string($dimg) && trim($dimg) !== '') {
            $img = hfs_media($dimg);
        }
    }
    $alt = $p['featured_alt'] ?: $p['title'];
    $pct = shop_sale_pct($p);
    $in = shop_allow_backorder() || (int) ($p['stock'] ?? 0) > 0;
    $fav = hfs_is_fav((int) $p['id']);
    $cardVariants = [];
    $digerVid = 0;
    if (empty($p['has_diagram'])) {
        $cardVariants = Catalog::variants((int) $p['id']);
        foreach ($cardVariants as $cv) {
            $slug = strtolower((string) ($cv['sku'] ?? ''));
            $name = strtolower((string) ($cv['name'] ?? ''));
            if (str_ends_with($slug, '-diger') || $name === 'diğer' || $name === 'diger') {
                $digerVid = (int) $cv['id'];
                break;
            }
        }
        if ($cardVariants !== [] && !shop_allow_backorder()) {
            $in = (bool) array_filter($cardVariants, static fn (array $v): bool => (int) ($v['stock'] ?? 0) > 0);
        }
    }
    ?>
    <article class="product<?= $cardVariants !== [] ? ' product--variants' : '' ?>">
        <div class="product-image">
            <a href="<?= h($href) ?>">
                <img src="<?= h($img) ?>" alt="<?= h($alt) ?>" width="480" height="480" loading="lazy" decoding="async">
            </a>
            <?php if (!empty($p['has_diagram'])): ?><span class="badge-diagram">Diyagram</span><?php endif; ?>
            <?php if ($pct > 0): ?><span class="onsale">-<?= $pct ?>%</span><?php endif; ?>
            <?php if (empty($p['has_diagram'])): ?>
            <form class="product-action<?= $cardVariants !== [] ? ' product-action--variants' : '' ?>" method="post" action="<?= h(site_url('sepet/')) ?>"<?= $digerVid > 0 ? ' data-diger-variant="' . $digerVid . '"' : '' ?><?= $cardVariants !== [] ? ' data-base-sku="' . h((string) ($p['sku'] ?? '')) . '"' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="add">
                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="qty" value="1">
                <input type="hidden" name="back" value="<?= h(page_url()) ?>">
                <?php if ($cardVariants !== []): ?>
                    <label class="product-action__variant">
                        <span class="visually-hidden">Varyant</span>
                        <select name="variant_id" required data-card-variant>
                            <option value="">Varyant seçin</option>
                            <?php foreach ($cardVariants as $cv):
                                $vSku = trim((string) ($cv['sku'] ?? ''));
                                if ($vSku === '' && !empty($p['sku'])) {
                                    $vSku = rtrim((string) $p['sku'], '-') . '-' . slugify((string) ($cv['name'] ?? ''));
                                }
                                ?>
                                <option value="<?= (int) $cv['id'] ?>" data-sku="<?= h($vSku) ?>"<?= (!shop_allow_backorder() && (int) ($cv['stock'] ?? 0) < 1) ? ' disabled' : '' ?>><?= h($vSku !== '' ? $vSku : (string) $cv['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($digerVid > 0): ?>
                        <input type="text" name="opt_motor_other" class="product-action__other" maxlength="80" placeholder="Motor marka/model" hidden data-diger-other>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit"<?= $in ? '' : ' disabled' ?>><?= $in ? 'Sepete ekle' : 'Tükendi' ?></button>
            </form>
            <?php else: ?>
            <a class="product-action product-action-link" href="<?= h($href) ?>">İncele</a>
            <?php endif; ?>
        </div>
        <div class="product-meta">
            <h3><a href="<?= h($href) ?>"><?= h($p['title']) ?></a></h3>
            <?php if (!empty($p['sku']) && empty($p['has_diagram'])): ?>
                <p class="sku"><code data-card-sku data-base-sku="<?= h((string) $p['sku']) ?>"><?= h((string) $p['sku']) ?></code></p>
            <?php endif; ?>
            <?php if (empty($p['has_diagram'])): ?><p class="price"><?= HfsPricing::html($p) ?></p><?php endif; ?>
            <form method="post" action="<?= h(site_url('favoriler/')) ?>" class="fav-mini">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="back" value="<?= h(page_url()) ?>">
                <button type="submit" class="textlink"><?= $fav ? 'Favoriden çıkar' : 'Favorile' ?></button>
            </form>
        </div>
    </article>
    <?php
}
endif;
