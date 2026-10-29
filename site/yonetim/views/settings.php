<div class="page-head"><h1>Ayarlar</h1></div>
<?php if (!empty($ok)): ?><p class="ok">Kaydedildi.</p><?php endif; ?>
<form class="stack dealer-form" method="post">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Site</h2>
        <div class="row2">
            <label><span>Site adı</span><input name="site_name" type="text" value="<?= h((string) setting('site_name')) ?>"></label>
            <label><span>Slogan</span><input name="tagline" type="text" value="<?= h((string) setting('tagline')) ?>"></label>
        </div>
        <label><span>SEO başlık</span><input name="seo_title" type="text" value="<?= h((string) setting('seo_title')) ?>"></label>
        <label><span>SEO açıklama</span><textarea name="seo_description" rows="3"><?= h((string) setting('seo_description')) ?></textarea></label>
        <div class="row2">
            <label><span>E-posta</span><input name="email" type="email" value="<?= h((string) setting('email')) ?>"></label>
            <label><span>Telefon</span><input name="phone" type="text" value="<?= h((string) setting('phone')) ?>"></label>
        </div>
    </div>
    <div class="card">
        <h2>Kargo</h2>
        <div class="row2">
            <label><span>Ücretsiz kargo eşiği</span><input name="free_ship_min" type="number" value="<?= h((string) setting('free_ship_min')) ?>"></label>
            <label><span>Kargo ücreti</span><input name="ship_fee" type="number" value="<?= h((string) setting('ship_fee')) ?>"></label>
        </div>
        <label><span>Yurt dışı kargo</span><input name="ship_intl" type="number" value="<?= h((string) setting('ship_intl', '189')) ?>"></label>
        <label><span>İl bazlı kargo</span><span class="field-hint">Örnek: İstanbul=39, her satıra bir il</span><textarea name="ship_cities" rows="4"><?= h((string) setting('ship_cities')) ?></textarea></label>
        <label><span>Saat dilimleri</span><span class="field-hint">Her satıra bir dilim</span><textarea name="ship_slots" rows="3"><?= h((string) setting('ship_slots')) ?></textarea></label>
        <div class="row2">
            <label><span>Katlı indirim % (2 adet)</span><input name="volume_2_pct" type="number" step="0.1" value="<?= h((string) setting('volume_2_pct', '0')) ?>"></label>
            <label><span>Katlı indirim % (3+ adet)</span><input name="volume_3_pct" type="number" step="0.1" value="<?= h((string) setting('volume_3_pct', '0')) ?>"></label>
        </div>
    </div>
    <div class="card">
        <h2>Yurtiçi Kargo (AÖ)</h2>
        <p class="hint">B2B sevkiyatlar alıcı ödemeli. Sipariş kodu <code>cargoKey</code> olur; takip no YK’dan çekilir. AÖ Normal API kullanıcısını girin (ör. <code>…A</code> ile biten).</p>
        <label class="cat"><input type="checkbox" name="yk_enabled" value="1"<?= setting('yk_enabled', '0') === '1' ? ' checked' : '' ?>> Yurtiçi entegrasyonu açık</label>
        <label class="cat"><input type="checkbox" name="yk_test" value="1"<?= setting('yk_test', '0') === '1' ? ' checked' : '' ?>> Test ortamı (YKTEST)</label>
        <div class="row2">
            <label><span>WS kullanıcı</span><input name="yk_user" type="text" value="<?= h((string) setting('yk_user')) ?>" autocomplete="off" placeholder="6052N…A"></label>
            <label><span>WS şifre</span><input name="yk_pass" type="password" value="" autocomplete="new-password" placeholder="<?= setting('yk_pass', '') !== '' ? 'Kayıtlı — değişmezse boş' : '' ?>"></label>
        </div>
    </div>
    <div class="card">
        <h2>Havale</h2>
        <div class="row2">
            <label><span>IBAN</span><input name="iban" type="text" value="<?= h((string) setting('iban')) ?>"></label>
            <label><span>IBAN adı</span><input name="iban_name" type="text" value="<?= h((string) setting('iban_name')) ?>"></label>
        </div>
        <label><span>Medya tabanı</span><input name="media_base" type="text" value="<?= h((string) setting('media_base', site_url('uploads'))) ?>"></label>
    </div>
    <div class="card">
        <h2>SMTP / e-posta</h2>
        <p class="hint">Sunucuda PHP <code>mail()</code> kapalıysa SMTP kullanın. Şablonlar <a href="<?= h(site_url('yonetim/epostalar/')) ?>">E-postalar</a> sayfasından düzenlenir. Gönderen boşsa yukarıdaki mağaza e-postası kullanılır.</p>
        <label class="cat"><input type="checkbox" name="smtp_enabled" value="1"<?= setting('smtp_enabled', '0') === '1' ? ' checked' : '' ?>> SMTP kullan</label>
        <div class="row2">
            <label><span>SMTP sunucu</span><input name="smtp_host" type="text" value="<?= h((string) setting('smtp_host')) ?>" placeholder="smtp.yandex.com" autocomplete="off"></label>
            <label><span>Port</span><input name="smtp_port" type="number" value="<?= h((string) setting('smtp_port', '587')) ?>"></label>
        </div>
        <div class="row2">
            <label>
                <span>Şifreleme</span>
                <select name="smtp_secure">
                    <?php $sec = (string) setting('smtp_secure', 'tls'); ?>
                    <option value="tls"<?= $sec === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option>
                    <option value="ssl"<?= $sec === 'ssl' ? ' selected' : '' ?>>SSL (465)</option>
                    <option value="none"<?= $sec === 'none' ? ' selected' : '' ?>>Yok</option>
                </select>
            </label>
            <label><span>Zaman aşımı (sn)</span><input name="smtp_timeout" type="number" value="<?= h((string) setting('smtp_timeout', '20')) ?>"></label>
        </div>
        <div class="row2">
            <label><span>SMTP kullanıcı</span><input name="smtp_user" type="text" value="<?= h((string) setting('smtp_user')) ?>" autocomplete="off"></label>
            <label><span>SMTP şifre</span><input name="smtp_pass" type="password" value="" autocomplete="new-password" placeholder="<?= setting('smtp_pass', '') !== '' ? 'Kayıtlı — değişmezse boş' : '' ?>"></label>
        </div>
        <div class="row2">
            <label><span>Gönderen e-posta</span><input name="smtp_from" type="email" value="<?= h((string) setting('smtp_from')) ?>" placeholder="<?= h((string) setting('email')) ?>"></label>
            <label><span>Gönderen adı</span><input name="smtp_from_name" type="text" value="<?= h((string) setting('smtp_from_name', (string) setting('site_name', 'HFS Tarım'))) ?>"></label>
        </div>
        <label class="cat"><input type="checkbox" name="smtp_verify" value="1"<?= setting('smtp_verify', '1') !== '0' ? ' checked' : '' ?>> SSL sertifikasını doğrula</label>
    </div>
    <div class="card">
        <h2>BulutTahsilat</h2>
        <p class="hint">Kart tahsilatı (Bulut Tahsilat). Kullanıcı, şifre, FirmAPICode ve SecretKeyCode — eski WooCommerce ayarlarından alındıysa burada güncel tutun.</p>
        <?php if (trim((string) setting('bt_last_error', '')) !== ''): ?>
            <p class="err">Son hata: <?= h((string) setting('bt_last_error')) ?></p>
        <?php endif; ?>
        <?php if (!empty($btTest)): ?>
            <p class="<?= !empty($btTest['ok']) ? 'ok' : 'err' ?>"><?= h((string) ($btTest['msg'] ?? '')) ?></p>
        <?php endif; ?>
        <label class="cat"><input type="checkbox" name="bt_enabled" value="1"<?= HfsPay::flag('bt_enabled', true) ? ' checked' : '' ?>> Kart ile ödemeyi aç</label>
        <label class="cat"><input type="checkbox" name="bt_cari" value="1"<?= HfsPay::flag('bt_cari', true) ? ' checked' : '' ?>> Cari bakiyeyi kartla tahsil et</label>
        <div class="row2">
            <label><span>Sanal POS API kullanıcısı</span><input name="bt_username" type="text" value="<?= h((string) setting('bt_username')) ?>" autocomplete="off"></label>
            <label><span>Sanal POS şifre</span><input name="bt_password" type="password" value="" autocomplete="new-password" placeholder="<?= setting('bt_password', '') !== '' ? 'Kayıtlı — değişmezse boş' : '' ?>"></label>
        </div>
        <div class="row2">
            <label><span>FirmAPICode</span><input name="bt_firm" type="text" value="<?= h((string) setting('bt_firm')) ?>" autocomplete="off"></label>
            <label><span>SecretKeyCode</span><input name="bt_secret" type="password" value="" autocomplete="new-password" placeholder="<?= setting('bt_secret', '') !== '' ? 'Kayıtlı — değişmezse boş' : '' ?>"></label>
        </div>
        <div class="row2">
            <label><span>PaymentExpCode (HFS ana firma)</span><input name="bt_exp" type="text" value="<?= h((string) setting('bt_exp')) ?>"></label>
            <label><span>Dil</span>
                <select name="bt_language">
                    <option value="TR"<?= strtoupper((string) setting('bt_language', 'TR')) !== 'EN' ? ' selected' : '' ?>>Türkçe</option>
                    <option value="EN"<?= strtoupper((string) setting('bt_language', 'TR')) === 'EN' ? ' selected' : '' ?>>English</option>
                </select>
            </label>
        </div>
        <p class="hint">Ana PaymentExpCode HFS firmasına aittir. Bayi ödemesinde bu kod kullanılmaz; her ana bayinin kendi kodu gerekir.</p>
        <div class="row2">
            <label><span>WEB API kullanıcısı</span><input name="bt_web_user" type="text" value="<?= h((string) setting('bt_web_user')) ?>" autocomplete="off" placeholder="Cari kart / SubFirm"></label>
            <label><span>WEB API şifre</span><input name="bt_web_pass" type="password" value="" autocomplete="new-password" placeholder="<?= setting('bt_web_pass', '') !== '' ? 'Kayıtlı — değişmezse boş' : 'Sanal POS şifresi değil' ?>"></label>
        </div>
        <p class="hint">Kart tahsilatı Sanal POS API kullanıcısıyla gider. Bayi cari kartı (SubFirmAddNew) için BulutTahsilat’ın açtığı ayrı <strong>WEB API kullanıcısı</strong> gerekir. Yoksa destek@buluttahsilat.com adresine talep yazın; gelince buraya yapıştırın. Sanal POS kullanıcısıyla cari açılamaz.</p>
        <?php $ck = (string) ($cronKey ?? ''); ?>
        <p class="hint"><strong>B2B ↔ Bulut ↔ Bayt iptal senkronu:</strong> Bulut bildirim <code><?= h(site_url('bulut-bildirim/')) ?></code> · tam senkron cron <code><?= h(site_url('hfs-sync/')) ?><?= $ck !== '' ? '?k=' . h($ck) : '?k=CRON_ANAHTARI' ?></code> (her 1–2 dk). Panel/Bayt iptalinde Bulut <code>PaymentCancel</code> denenir; gün sonu geçmiş işlemlerde iadeyi Bulut panelinden yapın.</p>
    </div>
    <div class="card">
        <h2>İzleme</h2>
        <label><span>Google Search Console</span><input name="gsc_verification" type="text" value="<?= h((string) setting('gsc_verification')) ?>"></label>
        <label><span>GA4 ölçüm ID</span><span class="field-hint">Boşsa yüklenmez</span><input name="ga4" type="text" value="<?= h((string) setting('ga4')) ?>" placeholder="G-XXXXXXXX"></label>
        <?php if (!empty($cron)): ?><p class="hint">Sepet hatırlatma cron: <code><?= h($cron) ?></code></p><?php endif; ?>
    </div>
    <button class="btn" type="submit">Kaydet</button>
</form>
<form class="stack dealer-form" method="post" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="bt_act" value="test">
    <div class="card">
        <h2>BulutTahsilat bağlantı testi</h2>
        <p class="hint">Sanal POS + WEB API dener; tahsilat almaz. 629 yalnızca test siparişi olmadığı için görülebilir — hata değildir. Önce bilgileri kaydedin.</p>
        <button class="btn ghost" type="submit">Bağlantıyı dene</button>
    </div>
</form>
<form class="stack dealer-form" method="post" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="smtp_act" value="test">
    <div class="card">
        <h2>Test iletisi</h2>
        <?php if (!empty($smtpTest)): ?>
            <p class="<?= !empty($smtpTest['ok']) ? 'ok' : 'err' ?>">
                <?= !empty($smtpTest['ok']) ? 'Gönderildi: ' . h((string) ($smtpTestTo ?? '')) : 'Gönderilemedi: ' . h((string) ($smtpTest['error'] ?? '')) ?>
            </p>
        <?php endif; ?>
        <p class="hint">Önce SMTP ayarlarını kaydedin, sonra test edin. Şu an: <?= Mailer::smtpOn() ? 'SMTP' : 'PHP mail()' ?>.</p>
        <label><span>Alıcı</span><input name="smtp_test_to" type="email" value="<?= h((string) ($smtpTestTo ?? setting('email'))) ?>" required></label>
        <button class="btn ghost" type="submit">Test e-postası gönder</button>
    </div>
</form>
