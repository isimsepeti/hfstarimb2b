<footer class="hfs-ft" id="hfs-footer">
    <div class="hfs-ft__inner">
        <div class="hfs-ft__ribbon">
            <span>Bayi Portalı</span>
            <span>Yerli üretim</span>
            <span>TSE belgesi</span>
            <span>TÜBİTAK ve Uludağ Üniversitesi testleri</span>
        </div>
        <div class="hfs-ft__grid">
            <div class="hfs-ft__brand">
                <a class="hfs-ft__brand-link" href="<?= h(site_url()) ?>">
                    <img class="hfs-ft__logo" src="<?= h(shop_logo()) ?>" alt="HFS TARIM" width="200" height="62">
                </a>
                <p class="hfs-ft__lead">Bayileriniz için özel sipariş, cari hesap ve yedek parça portalı. Hasat makinalarında yerli üretim, sahada kanıtlanmış performans.</p>
                <div class="hfs-ft__cta">
                    <a class="hfs-ft__btn hfs-ft__btn--wa" href="https://wa.me/905400101601" target="_blank" rel="noopener">WhatsApp</a>
                    <a class="hfs-ft__btn hfs-ft__btn--call" href="tel:+902242161601">Hemen ara</a>
                </div>
            </div>
            <nav class="hfs-ft__col" aria-label="Bayi işlemleri">
                <h2>Bayi işlemleri</h2>
                <ul>
                    <li><a href="<?= h(site_url()) ?>">Mağaza</a></li>
                    <li><a href="<?= h(site_url('hizli-siparis/')) ?>">Hızlı sipariş</a></li>
                    <li><a href="<?= h(site_url('hesabim/')) ?>">Hesabım</a></li>
                    <li><a href="<?= h(site_url('hesabim/kargo/')) ?>">Kargo takibi</a></li>
                    <li><a href="<?= h(site_url('hesabim/')) ?>#siparisler">Siparişlerim</a></li>
                    <li><a href="<?= h(site_url('hesabim/')) ?>">Cari hesabım</a></li>
                    <li><a href="<?= h(site_url('iade/')) ?>">İade talepleri</a></li>
                    <li><a href="<?= h(site_url('favoriler/')) ?>">Favoriler</a></li>
                    <li><a href="<?= h(site_url('sepet/')) ?>">Sepet</a></li>
                </ul>
            </nav>
            <nav class="hfs-ft__col" aria-label="Destek">
                <h2>Destek</h2>
                <ul>
                    <li><a href="tel:+905380101601">Teknik servis</a></li>
                    <li><a href="https://www.hfstarim.com/kullanim-kilavuzlari/" target="_blank" rel="noopener">Kullanım kılavuzları</a></li>
                    <li><a href="https://www.hfstarim.com/urun-videolari/" target="_blank" rel="noopener">Ürün videoları</a></li>
                    <li><a href="https://www.hfstarim.com/hakkimizda/" target="_blank" rel="noopener">Kurumsal</a></li>
                    <li><a href="https://www.hfstarim.com/" target="_blank" rel="noopener">Perakende mağaza</a></li>
                </ul>
                <a class="hfs-ft__etbis" href="https://etbis.ticaret.gov.tr/tr/SiteSorgulamaSonuc?siteId=efb4f14c-b4a8-46df-ac37-1060e8685251" target="_blank" rel="noopener noreferrer">
                    <img src="<?= h(asset('etbis-qr.png')) ?>" alt="ETBİS Karekod Doğrulama" width="120" height="148" loading="lazy">
                </a>
            </nav>
            <div class="hfs-ft__col hfs-ft__contact">
                <h2>İletişim</h2>
                <ul class="hfs-ft__info">
                    <li><span>Adres</span>Altınova Mah. Askı Sok. No: 10<br>Osmangazi / Bursa</li>
                    <li><span>Santral</span><a href="tel:+902242161601">0224 216 16 01</a></li>
                    <li><span>Müşteri hizmetleri</span><a href="tel:+905400101601">0540 010 16 01</a></li>
                    <li><span>Teknik servis</span><a href="tel:+905380101601">0538 010 16 01</a></li>
                    <li><span>E-posta</span><a href="mailto:hasfrensistemleri@gmail.com">hasfrensistemleri@gmail.com</a></li>
                </ul>
            </div>
        </div>
        <div class="hfs-ft__bottom">
            <p>© <span class="hfs-ft__year"><?= (int) $yil ?></span> HFS TARIM · Has Fren Sistemleri · Tüm hakları saklıdır.
                · <a href="<?= h(site_url('cerez-politikasi/')) ?>">Çerez politikası</a>
                · <a href="<?= h(site_url('privacy/')) ?>">KVKK / Gizlilik</a>
                · <a href="<?= h(site_url('terms/')) ?>">Bayi koşulları</a>
            </p>
            <p class="hfs-ft__credit">Powered by <a href="https://larus.web.tr">Larus Web Atölyesi</a></p>
            <a class="hfs-ft__top" href="#top">Yukarı çık</a>
        </div>
    </div>
</footer>
