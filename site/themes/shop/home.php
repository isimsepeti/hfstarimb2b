<?php
$catUrl = static function (string ...$slugs): string {
    foreach ($slugs as $slug) {
        $c = Catalog::categoryByPath($slug);
        if ($c) {
            return permalink_category($c);
        }
    }
    return site_url();
};
$akulu = $catUrl('akulu-zeytin-silkeleme-makinalari');
$sirt = $catUrl('sirttan-askili-zeytin-hasat-makinalari');
$yan = $catUrl('yandan-askili-zeytin-hasat-makinalari');
$yedek = $catUrl('v2-sirt-yedek-parca', 'sirt-sistem-v2-model', 'yedek-parcalar');
?>
<section class="hero-grid wrap hfs-hero">
    <div class="hero-main hfs-hero-main">
        <div class="hero-copy">
            <p class="eyebrow">Bayi paneli</p>
            <h1>Yedek parça,<br>sistem ve montaj</h1>
            <p>Diyagramdan parça seç, cari veya kart ile tahsil et. Fiyatlar hesabına özel.</p>
            <a class="btn" href="<?= h(site_url('cari-odeme/')) ?>">Cari ödeme yap</a>
            <a class="btn ghost" href="<?= h(site_url('hesabim/ekstre/')) ?>">Ekstre</a>
            <a class="btn ghost" href="<?= h(site_url('hesabim/kargo/')) ?>">Kargo takibi</a>
        </div>
    </div>
    <div class="hero-side">
        <a class="hero-card is-dark" href="<?= h(site_url('hesabim/')) ?>">
            <div>
                <p class="eyebrow">Cari</p>
                <strong>Hesap özeti</strong>
                <span>Limit ve açık bakiye</span>
            </div>
        </a>
        <a class="hero-card is-red" href="<?= h(site_url('favoriler/')) ?>">
            <div>
                <p class="eyebrow">Hızlı</p>
                <strong>Favoriler</strong>
                <span>Sık aldığın parçalar</span>
            </div>
        </a>
    </div>
</section>
<section class="hfs-home-cats" aria-label="Kategoriler">
    <div class="wrap hfs-home-cats__grid">
        <a class="hfs-cat-card" href="<?= h($akulu) ?>">
            <span class="hfs-cat-card__no">01</span>
            <span class="hfs-cat-card__title">Akülü Silkeleme</span>
            <span class="hfs-cat-card__text">Kablosuz, hafif ve uzun vardiyaya uygun akülü zeytin silkeleme makineleri.</span>
            <span class="hfs-cat-card__go">Kategoriyi aç →</span>
        </a>
        <a class="hfs-cat-card" href="<?= h($sirt) ?>">
            <span class="hfs-cat-card__no">02</span>
            <span class="hfs-cat-card__title">Sırttan Askılı</span>
            <span class="hfs-cat-card__text">Sırttan askılı hasat makineleri; dar bahçede hareket ve dengeli çalışma.</span>
            <span class="hfs-cat-card__go">Kategoriyi aç →</span>
        </a>
        <a class="hfs-cat-card" href="<?= h($yan) ?>">
            <span class="hfs-cat-card__no">03</span>
            <span class="hfs-cat-card__title">Yandan Askılı</span>
            <span class="hfs-cat-card__text">Yandan askılı sistemler; traktör ve mevcut ekipmanla uyumlu hasat.</span>
            <span class="hfs-cat-card__go">Kategoriyi aç →</span>
        </a>
        <a class="hfs-cat-card" href="<?= h($yedek) ?>">
            <span class="hfs-cat-card__no">04</span>
            <span class="hfs-cat-card__title">Yedek Parça</span>
            <span class="hfs-cat-card__text">V2 sırt model başta olmak üzere orijinal yedek parça ve sarf malzemeleri.</span>
            <span class="hfs-cat-card__go">Kategoriyi aç →</span>
        </a>
    </div>
</section>
