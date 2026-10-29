<?php
$cookiePolicyUrl = site_url('cerez-politikasi/');
?>
<div class="hfs-cookie-bar" id="hfs-cookie-bar" hidden role="region" aria-label="Çerez bildirimi">
    <div class="hfs-cookie-bar__inner wrap">
        <div class="hfs-cookie-bar__text">
            <strong>İzin tercihlerinizi özelleştirelim</strong>
            <p>Bayi portalında deneyiminizi iyileştirmek için çerezler kullanılmaktadır. Ayrıntılar ve tercihleriniz için
                <a href="<?= h($cookiePolicyUrl) ?>">Çerez Politikası</a> metnini inceleyebilirsiniz.</p>
        </div>
        <div class="hfs-cookie-bar__actions">
            <button type="button" class="hfs-cookie-bar__btn hfs-cookie-bar__btn--ghost" data-cookie-settings>Çerez ayarları</button>
            <button type="button" class="hfs-cookie-bar__btn hfs-cookie-bar__btn--primary" data-cookie-bar-accept>Kabul et</button>
        </div>
    </div>
</div>
<div class="hfs-cookie" id="hfs-cookie" hidden aria-hidden="true">
    <div class="hfs-cookie__backdrop" data-cookie-close tabindex="-1"></div>
    <div class="hfs-cookie__dialog" role="dialog" aria-modal="true" aria-labelledby="hfs-cookie-title" aria-describedby="hfs-cookie-desc">
        <button type="button" class="hfs-cookie__close" data-cookie-close aria-label="Kapat">&times;</button>
        <h2 id="hfs-cookie-title" class="hfs-cookie__title">Çerez Tercihleri</h2>
        <p id="hfs-cookie-desc" class="hfs-cookie__lead">Sitemizde çerez kullanmaktayız. Lütfen aşağıdan çerez tercihlerinizi seçiniz.</p>
        <p class="hfs-cookie__policy">
            <a href="<?= h($cookiePolicyUrl) ?>" target="_blank" rel="noopener">Çerez Politikası</a> hakkında daha fazla bilgi alabilirsiniz.
        </p>
        <ul class="hfs-cookie__list">
            <li class="hfs-cookie__row">
                <div>
                    <strong>Zorunlu Çerezler</strong>
                    <span>Bayi girişi, güvenlik ve sepet için gereklidir.</span>
                </div>
                <span class="hfs-cookie__always">Her zaman açık</span>
            </li>
            <li class="hfs-cookie__row">
                <div>
                    <strong>İşlevsellik İçin Gerekli Olan Çerezler</strong>
                    <span>Tercihlerinizi ve formları hatırlamak için kullanılır.</span>
                </div>
                <label class="hfs-cookie__switch">
                    <input type="checkbox" name="fn" id="hfs-cookie-fn">
                    <span aria-hidden="true"></span>
                </label>
            </li>
            <li class="hfs-cookie__row">
                <div>
                    <strong>Performans ve Analitik İçin Gerekli Olan Çerezler</strong>
                    <span>Siteyi nasıl kullandığınızı anlamamız için kullanılır.</span>
                </div>
                <label class="hfs-cookie__switch">
                    <input type="checkbox" name="perf" id="hfs-cookie-perf">
                    <span aria-hidden="true"></span>
                </label>
            </li>
            <li class="hfs-cookie__row">
                <div>
                    <strong>Hedefleme ve Kişiselleştirme Reklam Çerezleri</strong>
                    <span>Şu an kullanılmaz; ileride onayınıza tabi olur.</span>
                </div>
                <label class="hfs-cookie__switch">
                    <input type="checkbox" name="ads" id="hfs-cookie-ads">
                    <span aria-hidden="true"></span>
                </label>
            </li>
        </ul>
        <div class="hfs-cookie__actions">
            <button type="button" class="hfs-cookie__btn hfs-cookie__btn--ghost" data-cookie-accept-all>Tümünü Kabul Et</button>
            <button type="button" class="hfs-cookie__btn hfs-cookie__btn--primary" data-cookie-save>Seçimi Kaydet</button>
        </div>
    </div>
</div>
