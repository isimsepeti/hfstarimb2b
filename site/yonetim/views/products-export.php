<?php
$status = $status ?? '';
$q = $q ?? '';
$base = site_url('yonetim/urunler/dis-aktar/');
$dl = static function (string $format) use ($base, $status, $q): string {
    $qs = ['indir' => '1', 'format' => $format];
    if ($status !== '') {
        $qs['durum'] = $status;
    }
    if ($q !== '') {
        $qs['q'] = $q;
    }
    return $base . '?' . http_build_query($qs);
};
?>
<div class="page-head">
    <div>
        <h1>Ürünleri dışa aktar</h1>
        <p>Excel veya CSV olarak indirin. Varyant kodları (örn. HFS-SA-V3-58-kawasaki) Excel’de «Ürünler» sütununda, «Varyantlar» ve «Stok kodları» sayfalarında yer alır.</p>
    </div>
    <a class="btn ghost" href="<?= h(site_url('yonetim/urunler/')) ?>">← Ürün listesi</a>
</div>
<div class="card">
    <p class="hint">
        Toplam <strong><?= (int) ($total ?? 0) ?></strong> ürün
        (yayında <?= (int) ($pub ?? 0) ?>, taslak <?= (int) ($draft ?? 0) ?>),
        <strong><?= (int) ($variantN ?? 0) ?></strong> varyant satırı.
    </p>
    <form class="toolbar stack" method="get" action="<?= h($base) ?>">
        <label>
            <span>Durum</span>
            <select name="durum">
                <option value=""<?= $status === '' ? ' selected' : '' ?>>Tümü</option>
                <option value="publish"<?= $status === 'publish' ? ' selected' : '' ?>>Yayında</option>
                <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>>Taslak</option>
            </select>
        </label>
        <label>
            <span>Ara (başlık / SKU)</span>
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Opsiyonel filtre">
        </label>
        <button class="btn ghost" type="submit">Filtreyi uygula</button>
    </form>
    <div class="toolbar" style="margin-top:12px">
        <a class="btn" href="<?= h($dl('xlsx')) ?>">Excel indir (.xlsx)</a>
        <a class="btn ghost" href="<?= h($dl('csv')) ?>">CSV indir</a>
    </div>
    <p class="hint">CSV’de «Varyant kodları» sütunu vardır. Excel’de ayrıca «Varyantlar» ve düz liste «Stok kodları» sayfaları gelir.</p>
</div>
