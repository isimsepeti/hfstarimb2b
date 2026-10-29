<?php
$p = $p ?? [];
$id = (int) ($p['id'] ?? 0);
$galleryList = $galleryList ?? [];
$diagramImage = $diagramImage ?? '';
$featPath = trim((string) ($p['featured_media'] ?? ''));
$mediaListUrl = site_url('yonetim/medya/liste/');
$mediaWarmUrl = site_url('yonetim/medya/warm/');
$mediaResetUrl = site_url('yonetim/medya/sifirla/');
$mediaUploadUrl = site_url('yonetim/medya/yukle/');
$mediaUrlApi = site_url('yonetim/medya/url/');
$js = BMN_ROOT . '/yonetim/assets/admin-media.js';
$jsUrl = site_url('yonetim/assets/admin-media.js') . (is_file($js) ? '?v=' . filemtime($js) : '');
?>
<?php $skuErr = trim((string) ($_GET['sku_err'] ?? '')); ?>
<div class="page-head page-head--product">
    <h1><?= $id ? 'Ürünü düzenle' : 'Yeni ürün' ?></h1>
    <button class="btn" type="submit" form="product-edit-form">Kaydet</button>
</div>
<?php if ($skuErr !== ''): ?>
    <p class="err"><?= h($skuErr) ?></p>
<?php endif; ?>
<form
    id="product-edit-form"
    class="editor product-editor"
    method="post"
    data-media-list="<?= h($mediaListUrl) ?>"
    data-media-warm="<?= h($mediaWarmUrl) ?>"
    data-media-reset="<?= h($mediaResetUrl) ?>"
    data-media-upload="<?= h($mediaUploadUrl) ?>"
    data-media-url="<?= h($mediaUrlApi) ?>"
>
    <?= csrf_field() ?>
    <div class="editor-grid">
        <div class="card editor-panel editor-panel--wide">
            <h2>Genel</h2>
            <label><span>Başlık</span><input class="title-input" name="title" type="text" value="<?= h($p['title'] ?? '') ?>" required></label>
            <label><span>Slug</span><input name="slug" type="text" value="<?= h($p['slug'] ?? '') ?>"></label>
            <label>
                <span>Ürün kodu (SKU)</span>
                <span class="field-hint">Benzersiz olmalı; başka ürünle aynı kod kaydedilemez.</span>
                <input name="sku" type="text" value="<?= h($p['sku'] ?? '') ?>" autocomplete="off" spellcheck="false">
            </label>
            <label><span>Kısa özet</span><textarea name="excerpt" rows="3"><?= h($p['excerpt'] ?? '') ?></textarea></label>
            <label><span>İçerik (HTML, iç link)</span><textarea class="content" name="content"><?= h($p['content'] ?? '') ?></textarea></label>
        </div>

        <div class="card editor-panel">
            <h2>Görseller</h2>
            <p class="hint">Medya kütüphanesinden seçin veya yeni görsel yükleyin. Yollar otomatik kaydedilir.</p>
            <div class="media-field">
                <span class="field-label">Ana görsel</span>
                <div class="media-field__preview" data-featured-preview>
                    <?php if ($featPath !== ''): ?>
                        <figure class="media-slot">
                            <img src="<?= h(hfs_media($featPath)) ?>" alt="" loading="lazy">
                            <figcaption><?= h($featPath) ?></figcaption>
                        </figure>
                    <?php else: ?>
                        <span class="media-slot media-slot--empty">Ana görsel seçilmedi</span>
                    <?php endif; ?>
                </div>
                <input name="featured_media" type="hidden" value="<?= h($featPath) ?>">
                <div class="media-field__actions">
                    <button type="button" class="btn btn--secondary" data-pick-media="featured">Medya seç</button>
                    <button type="button" class="btn-ghost" data-clear-media="featured">Kaldır</button>
                </div>
            </div>
            <label><span>Görsel alt metni</span><input name="featured_alt" type="text" value="<?= h($p['featured_alt'] ?? '') ?>"></label>
            <div class="media-field">
                <span class="field-label">Galeri</span>
                <div class="gallery-list" data-gallery-list></div>
                <textarea class="visually-hidden" name="gallery" rows="5" tabindex="-1" aria-hidden="true"><?= h(implode("\n", $galleryList)) ?></textarea>
                <div class="media-field__actions">
                    <button type="button" class="btn btn--secondary" data-pick-media="gallery" data-pick-multi="1">Görsel ekle</button>
                    <button type="button" class="btn-ghost" data-clear-media="gallery">Galeriyi temizle</button>
                </div>
            </div>
            <?php if (!empty($p['has_diagram'])): ?>
                <div class="media-field">
                    <span class="field-label">Diyagram</span>
                    <input name="diagram_image" type="text" value="<?= h($diagramImage) ?>" readonly>
                    <div class="media-field__actions">
                        <button type="button" class="btn btn--secondary" data-pick-media="diagram">Medya seç</button>
                        <button type="button" class="btn-ghost" data-clear-media="diagram">Kaldır</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card editor-panel">
            <h2>Fiyat &amp; stok</h2>
            <label>
                <span>Durum</span>
                <select name="status">
                    <option value="publish"<?= ($p['status'] ?? 'publish') === 'publish' ? ' selected' : '' ?>>Yayında</option>
                    <option value="draft"<?= ($p['status'] ?? '') === 'draft' ? ' selected' : '' ?>>Taslak</option>
                </select>
            </label>
            <label class="cat"><input type="checkbox" name="featured" value="1"<?= !empty($p['featured']) ? ' checked' : '' ?>> Öne çıkan</label>
            <div class="row2">
                <label><span>Fiyat</span><input name="price" type="number" step="0.01" value="<?= h((string) ($p['price'] ?? '')) ?>"></label>
                <label><span>Eski fiyat</span><input name="compare_price" type="number" step="0.01" value="<?= h((string) ($p['compare_price'] ?? '')) ?>" placeholder="Yok"></label>
            </div>
            <label><span>Stok</span><input name="stock" type="number" value="<?= (int) ($p['stock'] ?? 0) ?>"></label>
            <label>
                <span>Tür</span>
                <select name="type">
                    <?php foreach (['simple' => 'Basit', 'bundle' => 'Paket / asorti', 'digital' => 'Dijital'] as $k => $lab): ?>
                        <option value="<?= $k ?>"<?= ($p['type'] ?? 'simple') === $k ? ' selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="row2">
                <label><span>Marka</span><input name="brand" type="text" value="<?= h($p['brand'] ?? 'HFS') ?>"></label>
                <label><span>Malzeme</span><input name="material" type="text" value="<?= h($p['material'] ?? '') ?>"></label>
            </div>
            <label>
                <span>Varsayılan bedelsiz kademe</span>
                <input name="default_gifts" type="text" value="<?= h((string) ($defaultGifts ?? '')) ?>" placeholder="50:2, 100:10">
            </label>
            <label>
                <span>Varsayılan miktar fiyatı</span>
                <input name="default_qty_prices" type="text" value="<?= h((string) ($defaultQtyPrices ?? '')) ?>" placeholder="50:120, 100:99">
            </label>
        </div>

        <div class="card editor-panel">
            <h2>Kategori</h2>
            <div class="cats">
                <?php foreach ($cats as $c): ?>
                    <label class="cat"><input type="checkbox" name="cats[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $selected, true) ? ' checked' : '' ?>> <?= h($c['path']) ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        $optGroups = $optGroups ?? [];
        $optSelected = $optSelected ?? [];
        $optValueSelected = $optValueSelected ?? [];
        $variants = $variants ?? [];
        ?>
        <div class="card editor-panel editor-panel--wide">
            <h2>Varyant eksenleri</h2>
            <p class="hint">Varyant grupları yalnızca bu üründe seçili olanlarda görünür. Değer kutuları boş bırakılırsa o gruptaki tüm değerler kullanılır.</p>
            <?php foreach ($optGroups as $og): ?>
                <?php $gid = (int) $og['id']; $gOn = in_array($gid, $optSelected, true); ?>
                <fieldset class="variant-fieldset">
                    <label class="cat">
                        <input type="checkbox" name="opt_groups[]" value="<?= $gid ?>"<?= $gOn ? ' checked' : '' ?>>
                        <strong><?= h((string) $og['name']) ?></strong>
                    </label>
                    <?php if (!empty($og['values'])): ?>
                        <div class="cats cats--inline">
                            <?php foreach ($og['values'] as $ov):
                                $vid = (int) $ov['id'];
                                $vOn = $optValueSelected === [] ? $gOn : in_array($vid, $optValueSelected, true);
                                ?>
                                <label class="cat">
                                    <input type="checkbox" name="opt_values[]" value="<?= $vid ?>"<?= $vOn ? ' checked' : '' ?>>
                                    <?= h((string) $ov['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
            <?php if ($optGroups === []): ?>
                <p class="hint">Henüz varyant grubu yok. <a href="<?= h(site_url('yonetim/varyantlar/')) ?>">Varyantlar</a> sayfasından ekleyin.</p>
            <?php else: ?>
                <label class="cat"><input type="checkbox" name="regen_combos" value="1"> Tüm kombinasyonları yeniden oluştur</label>
            <?php endif; ?>
            <?php if ($variants !== []): ?>
                <details class="variant-details">
                    <summary><?= count($variants) ?> varyant satırı</summary>
                    <div class="table-wrap variant-table-wrap">
                        <table class="combo-table">
                            <tr><th>Ad</th><th>Kod</th><th>Stok</th></tr>
                            <?php foreach ($variants as $v): ?>
                                <tr>
                                    <td><small><?= h((string) $v['name']) ?></small></td>
                                    <td><small><?= h((string) ($v['sku'] ?? '')) ?></small></td>
                                    <td><?= (int) ($v['stock'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <div class="card editor-panel">
            <h2>SEO</h2>
            <label><span>SEO başlık</span><input name="seo_title" type="text" value="<?= h($p['seo_title'] ?? '') ?>" maxlength="70"></label>
            <label><span>SEO açıklama</span><textarea name="seo_description" rows="3" maxlength="160"><?= h($p['seo_description'] ?? '') ?></textarea></label>
        </div>

        <div class="card editor-panel editor-panel--wide">
            <h2>Bayiye göre miktar / fiyat</h2>
            <p class="hint">Kademe örneği: <code>50:120, 100:99</code>. Bedelsiz: <code>50:2</code>. Özel fiyat yalnız seçilen <strong>bayi giriş hesabında</strong> geçerli (yönetici hesabında liste fiyatı görünür).</p>
            <?php
            $productOffers = $productOffers ?? [];
            $productOffers[] = ['company_id' => 0, 'price' => '', 'qty_prices' => '', 'gifts' => ''];
            $productOffers[] = ['company_id' => 0, 'price' => '', 'qty_prices' => '', 'gifts' => ''];
            $dealers = $dealers ?? [];
            ?>
            <div class="table-wrap">
                <table class="combo-table combo-table--offers">
                    <tr>
                        <th>Bayi</th>
                        <th>Düz fiyat</th>
                        <th>Miktar → fiyat</th>
                        <th>Bedelsiz</th>
                    </tr>
                    <?php foreach ($productOffers as $of): ?>
                        <tr>
                            <td>
                                <select name="poffer_cid[]">
                                    <option value="0">—</option>
                                    <?php foreach ($dealers as $d): ?>
                                        <?php
                                        $dLabel = trim((string) ($d['company'] ?: $d['display_name'] ?: $d['username']));
                                        $dUser = trim((string) ($d['username'] ?? ''));
                                        if ($dUser !== '' && strcasecmp($dUser, $dLabel) !== 0) {
                                            $dLabel .= ' · ' . $dUser;
                                        }
                                        ?>
                                        <option value="<?= (int) $d['id'] ?>"<?= (int) ($of['company_id'] ?? 0) === (int) $d['id'] ? ' selected' : '' ?>>
                                            <?= h($dLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input name="poffer_price[]" type="number" step="0.01" min="0" placeholder="TL" value="<?= h((string) ($of['price'] ?? '')) ?>"></td>
                            <td><input name="poffer_qty[]" type="text" placeholder="50:120, 100:99" value="<?= h((string) ($of['qty_prices'] ?? '')) ?>"></td>
                            <td><input name="poffer_gifts[]" type="text" placeholder="50:2, 100:10" value="<?= h((string) ($of['gifts'] ?? '')) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <div class="card editor-panel editor-panel--wide">
            <h2>Çapraz / yukarı satış</h2>
            <label>
                <span>Yukarı satış ürünü</span>
                <select name="upsell_id">
                    <option value="0">—</option>
                    <?php foreach ($all ?? [] as $ap): if ((int) $ap['id'] === $id) {
                        continue;
                    } ?>
                        <option value="<?= (int) $ap['id'] ?>"<?= (int) ($p['upsell_id'] ?? 0) === (int) $ap['id'] ? ' selected' : '' ?>><?= h($ap['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <h3>Cross-sell</h3>
            <div class="cats cats--scroll">
                <?php foreach ($all ?? [] as $ap): if ((int) $ap['id'] === $id) {
                    continue;
                } ?>
                    <label class="cat"><input type="checkbox" name="cross[]" value="<?= (int) $ap['id'] ?>"<?= in_array((int) $ap['id'], $crossSel ?? [], true) ? ' checked' : '' ?>> <?= h($ap['title']) ?></label>
                <?php endforeach; ?>
            </div>
            <h3>Paket içeriği</h3>
            <?php $bundle = $bundle ?: [['child_id' => 0, 'qty' => 1], ['child_id' => 0, 'qty' => 1], ['child_id' => 0, 'qty' => 1]]; ?>
            <?php foreach ($bundle as $b): ?>
                <div class="toolbar">
                    <select name="bundle_id[]">
                        <option value="0">—</option>
                        <?php foreach ($all ?? [] as $ap): if ((int) $ap['id'] === $id) {
                            continue;
                        } ?>
                            <option value="<?= (int) $ap['id'] ?>"<?= (int) ($b['child_id'] ?? 0) === (int) $ap['id'] ? ' selected' : '' ?>><?= h($ap['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="compact" name="bundle_qty[]" type="number" min="1" value="<?= (int) ($b['qty'] ?? 1) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="editor-foot">
        <button class="btn" type="submit">Kaydet</button>
    </div>
</form>

<div id="media-picker" class="media-picker" hidden>
    <div class="media-picker__backdrop" data-media-close></div>
    <div class="media-picker__dialog" role="dialog" aria-modal="true" aria-labelledby="media-picker-title">
        <header class="media-picker__head">
            <h2 id="media-picker-title" data-media-title>Medya seç</h2>
            <button type="button" class="media-picker__close" data-media-close aria-label="Kapat">×</button>
        </header>
        <div class="media-picker__upload">
            <input type="file" accept="image/*" data-media-file>
            <button type="button" class="btn btn--secondary" data-media-upload-btn>Yükle</button>
        </div>
        <label class="media-picker__search">
            <span>Ara</span>
            <input type="search" data-media-search placeholder="Dosya adı veya yol">
        </label>
        <div class="media-picker__toolbar">
            <p class="media-picker__status" data-media-status></p>
            <button type="button" class="btn-ghost btn-ghost--sm" data-media-refresh>Listeyi yenile</button>
        </div>
        <div class="media-picker__grid" data-media-grid></div>
        <button type="button" class="btn btn--secondary media-picker__more" data-media-more hidden>Daha fazla göster</button>
    </div>
</div>
<script src="<?= h($jsUrl) ?>" defer></script>
