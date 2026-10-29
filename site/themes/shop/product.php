<?php
require __DIR__ . '/partials/card.php';
require __DIR__ . '/partials/crumbs.php';
$optAxes = $optAxes ?? [];
$variants = $variants ?? [];
$comboMap = [];
foreach ($variants as $v) {
    if (($v['attrs'] ?? '') === '') {
        continue;
    }
    $comboMap[] = [
        'id' => (int) $v['id'],
        'sku' => (string) ($v['sku'] ?? ''),
        'stock' => (int) $v['stock'],
        'price' => ($v['price'] !== null && $v['price'] !== '') ? (float) $v['price'] : (float) $p['price'],
        'attrs' => Option::decodeAttrs($v['attrs'] ?? ''),
        'name' => $v['name'],
    ];
}
$useAxes = $optAxes !== [] && $comboMap !== [];
$motorGroupId = 0;
$motorOtherValueId = 0;
foreach ($optAxes as $axis) {
    if (($axis['slug'] ?? '') === 'motor-tipi') {
        $motorGroupId = (int) $axis['id'];
        foreach ($axis['values'] as $val) {
            if (($val['slug'] ?? '') === 'diger') {
                $motorOtherValueId = (int) $val['id'];
            }
        }
        break;
    }
}
$in = shop_allow_backorder() || (
    $useAxes
        ? (bool) array_filter($comboMap, static fn (array $c): bool => $c['stock'] > 0)
        : ($variants
            ? (bool) array_filter($variants, static fn (array $v): bool => (int) $v['stock'] > 0)
            : (int) $p['stock'] > 0)
);
$picked = [];
$pickedId = 0;
$pickedPrice = (float) $p['price'];
$kickerCat = $cats[0] ?? null;
if ($cats) {
    $best = $cats[0];
    foreach ($cats as $c) {
        if (strlen((string) ($c['path'] ?? '')) > strlen((string) ($best['path'] ?? ''))) {
            $best = $c;
        }
    }
    $kickerCat = $best;
}
$pct = shop_sale_pct($p);
$sale = $pct > 0;
$stars = (int) ($revStats['count'] ?? 0);
$avg = (float) ($revStats['avg'] ?? 0);
$cross = $cross ?? [];
$bundle = $bundle ?? [];
$reviews = $reviews ?? [];
$gallery = hfs_product_images($p);
$hero = $gallery[0] ?? ($p['featured_media'] ?? '');
if (!empty($p['has_diagram'])) {
    $dimg = HfsDiagram::meta((int) $p['id'], 'diagram_image', '');
    if (is_string($dimg) && trim($dimg) !== '') {
        $hero = $dimg;
    }
}
?>
<?php if (!empty($diagram)): ?>
<?php require __DIR__ . '/partials/diagram.php'; ?>
<?php endif; ?>
<article class="pdp<?= !empty($diagram) ? ' pdp-has-diagram' : '' ?>">
    <div class="pdp-gallery">
        <div class="pdp-media">
            <img id="pdp-hero" src="<?= h(hfs_media($hero)) ?>" alt="<?= h($p['featured_alt'] ?: $p['title']) ?>" width="800" height="800" fetchpriority="high" decoding="async">
            <?php if ($pct > 0): ?><span class="onsale">-<?= $pct ?>%</span><?php endif; ?>
        </div>
        <?php if (count($gallery) > 1): ?>
            <div class="pdp-thumbs">
                <?php foreach ($gallery as $gi => $gsrc): ?>
                    <button type="button" data-src="<?= h(hfs_media($gsrc)) ?>"<?= $gi === 0 ? ' class="is-on"' : '' ?>>
                        <img src="<?= h(hfs_media($gsrc)) ?>" alt="" width="80" height="80" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="pdp-buy">
        <?php if ($kickerCat): ?>
            <p class="kicker"><a href="<?= h(permalink_category($kickerCat)) ?>"><?= h($kickerCat['name']) ?></a></p>
        <?php endif; ?>
        <h1><?= h($p['title']) ?></h1>
        <?php if (empty($diagram)): ?>
        <?php if ($stars > 0): ?>
            <p class="stars"><?= str_repeat('★', (int) round($avg)) ?><?= str_repeat('☆', 5 - (int) round($avg)) ?> <small><?= h(number_format($avg, 1)) ?> (<?= $stars ?>)</small></p>
        <?php endif; ?>
        <p class="price lg" data-price-box>
            <?php if ($sale): ?><s><?= h(money($p['compare_price'])) ?></s><?php endif; ?>
            <strong data-price-now><?= h(money($useAxes ? $pickedPrice : $p['price'])) ?></strong>
        </p>
        <?php if (!empty($p['sku'])): ?><p class="sku"><code data-pdp-sku data-base-sku="<?= h((string) $p['sku']) ?>"><?= h((string) $p['sku']) ?></code></p><?php endif; ?>
        <?php
        $giftTiers = HfsOffers::giftTiers((int) $p['id']);
        $qtyLabel = HfsOffers::qtyLabel((int) $p['id']);
        if ($qtyLabel): ?>
            <p class="hfs-gift-note">Miktar fiyatı: <?= h($qtyLabel) ?></p>
        <?php endif; ?>
        <?php if ($giftTiers): ?>
            <p class="hfs-gift-note">Kampanya:
                <?php foreach ($giftTiers as $g): ?>
                    <?= (int) $g['min'] ?> adette <?= (int) $g['free'] ?> bedelsiz<?= $g !== end($giftTiers) ? '; ' : '' ?>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($p['excerpt']) && trim(strip_tags((string) ($p['content'] ?? ''))) === ''): ?>
            <p class="dek"><?= h($p['excerpt']) ?></p>
        <?php endif; ?>
        <?php if ($bundle): ?>
            <ul class="facts">
                <?php foreach ($bundle as $b): ?>
                    <li><?= (int) $b['qty'] ?>× <a href="<?= h(site_url('urun/' . $b['slug'] . '/')) ?>"><?= h($b['title']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (isset($_GET['sepet'])): ?>
            <p class="ok">Sepete eklendi. <a href="<?= h(site_url('sepet/')) ?>">Sepeti gör</a></p>
        <?php endif; ?>
        <?php if (isset($_GET['stok'])): ?>
            <p class="ok">Stok gelince yazarız.</p>
        <?php endif; ?>
        <?php if (!empty($upsell) && (float) $upsell['price'] > (float) $p['price']): ?>
            <p class="upsell">Daha iyisi: <a href="<?= h(permalink_product($upsell)) ?>"><?= h($upsell['title']) ?></a> · <?= h(money($upsell['price'])) ?></p>
        <?php endif; ?>
        <?php if (isset($_GET['hata']) && $_GET['hata'] === 'motor'): ?>
            <p class="err">Motor tipi “Diğer” seçildiğinde marka/model yazın.</p>
        <?php endif; ?>
        <?php if (isset($_GET['hata']) && $_GET['hata'] === 'varyant'): ?>
            <p class="err">Sepete eklemek için önce varyant seçin.</p>
        <?php endif; ?>
        <form class="buy-form" method="post" action="<?= h(site_url('sepet/')) ?>"<?= $useAxes ? ' data-opt-form data-combos="' . h((string) json_encode($comboMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS)) . '"' : '' ?><?= ($variants && !$useAxes) ? ' data-variant-required' : '' ?><?= $motorOtherValueId > 0 ? ' data-motor-group="' . $motorGroupId . '" data-motor-other="' . $motorOtherValueId . '"' : '' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="add">
            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="back" value="<?= h(page_url()) ?>">
            <?php if ($useAxes): ?>
                <input type="hidden" name="variant_id" value="0" data-variant-id>
                <?php foreach ($optAxes as $axis): ?>
                    <fieldset class="sizes" data-opt-group="<?= (int) $axis['id'] ?>">
                        <legend><?= h($axis['name']) ?> <small>(zorunlu)</small></legend>
                        <?php foreach ($axis['values'] as $i => $val):
                            $optSku = '';
                            foreach ($comboMap as $cm) {
                                $attrs = $cm['attrs'] ?? [];
                                if ((string) ($attrs[(string) $axis['id']] ?? $attrs[$axis['id']] ?? '') === (string) $val['id']
                                    && count($attrs) === 1) {
                                    $optSku = (string) ($cm['sku'] ?? '');
                                    break;
                                }
                            }
                            if ($optSku === '' && !empty($p['sku'])) {
                                $optSku = rtrim((string) $p['sku'], '-') . '-' . slugify((string) $val['name']);
                            }
                            ?>
                            <label>
                                <input type="radio" name="opt_<?= (int) $axis['id'] ?>" value="<?= (int) $val['id'] ?>"<?= $i === 0 ? ' required' : '' ?> data-sku="<?= h($optSku) ?>">
                                <span><?= h($optSku !== '' ? $optSku : (string) $val['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
                <?php if ($motorOtherValueId > 0): ?>
                    <div class="opt-motor-other" data-opt-motor-other-wrap hidden>
                        <label>Motor markası / modeli (Diğer)
                            <input type="text" name="opt_motor_other" maxlength="80" placeholder="Örn. Stihl MS 180" autocomplete="off">
                        </label>
                    </div>
                <?php endif; ?>
            <?php elseif ($variants && ($p['type'] ?? '') !== 'bundle'): ?>
                <fieldset class="sizes">
                    <legend>Seçenek <small>(zorunlu)</small></legend>
                    <?php foreach ($variants as $i => $v):
                        $vSku = trim((string) ($v['sku'] ?? ''));
                        if ($vSku === '' && !empty($p['sku'])) {
                            $vSku = rtrim((string) $p['sku'], '-') . '-' . slugify((string) ($v['name'] ?? ''));
                        }
                        ?>
                        <label>
                            <input type="radio" name="variant_id" value="<?= (int) $v['id'] ?>"<?= $i === 0 ? ' required' : '' ?> data-sku="<?= h($vSku) ?>">
                            <span><?= h($vSku !== '' ? $vSku : (string) $v['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <?php if (!empty($p['personalize'])): ?>
                <label>İsim / sırt no <input name="note" maxlength="80" placeholder="Örn. Yenal 61"></label>
            <?php endif; ?>
            <div class="qty-row">
                <label>Adet <input type="number" name="qty" value="1" min="1" max="999"></label>
                <?php
                $needPick = $useAxes || ($variants && ($p['type'] ?? '') !== 'bundle');
                $btnOff = !$in || $needPick;
                ?>
                <button class="btn" type="submit" data-add-btn<?= $btnOff ? ' disabled' : '' ?>><?= $in ? 'Sepete ekle' : 'Tükendi' ?></button>
                <button class="btn ghost" type="submit" name="go_cart" value="1" data-buy-btn<?= $btnOff ? ' disabled' : '' ?>><?= $in ? 'Hemen al' : 'Tükendi' ?></button>
            </div>
        </form>
        <?php if (!$in && !shop_allow_backorder()): ?>
            <form class="stack" method="post" action="<?= h(site_url('stok-haber/')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                <label>Gelince haber ver <input type="email" name="email" required placeholder="e-posta"></label>
                <button class="btn ghost" type="submit">Kaydet</button>
            </form>
        <?php endif; ?>
        <ul class="facts">
            <li>SKU <span data-pdp-sku-fact data-base-sku="<?= h((string) $p['sku']) ?>"><?= h((string) $p['sku']) ?></span></li>
            <li>Bayi fiyatı · kargo anlaşmanıza göre</li>
        </ul>
        <form method="post" action="<?= h(site_url('favoriler/')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="back" value="<?= h(page_url()) ?>">
            <button class="btn ghost" type="submit"><?= hfs_is_fav((int) $p['id']) ? 'Favoriden çıkar' : 'Favorilere ekle' ?></button>
        </form>
        <?php endif; ?>
    </div>
</article>

<?php if (!empty($p['content']) && empty($diagram)): ?>
<div class="prose wrap">
    <?= $p['content'] ?>
</div>
<?php endif; ?>

<?php if ($reviews): ?>
<section class="shelf wrap reviews">
    <header class="sec-head"><h2>Yorumlar</h2></header>
    <?php foreach ($reviews as $r): ?>
        <article class="review">
            <p class="stars"><?= str_repeat('★', (int) $r['rating']) ?></p>
            <p><strong><?= h($r['name']) ?></strong></p>
            <p><?= h($r['body']) ?></p>
            <?php if ($r['photo']): ?><p><img src="<?= h($r['photo']) ?>" alt="" width="220" loading="lazy"></p><?php endif; ?>
            <?php if ($r['reply']): ?><p class="hint">BMN: <?= h($r['reply']) ?></p><?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($cross): ?>
<section class="shelf wrap">
    <header class="sec-head"><h2>Birlikte al</h2></header>
    <div class="pgrid">
        <?php foreach ($cross as $rp) { shop_card($rp); } ?>
    </div>
</section>
<?php endif; ?>

<?php if ($related): ?>
<section class="shelf wrap">
    <header class="sec-head"><h2>Önerilen ürünler</h2></header>
    <div class="pgrid">
        <?php foreach ($related as $rp) { shop_card($rp); } ?>
    </div>
</section>
<?php endif; ?>
