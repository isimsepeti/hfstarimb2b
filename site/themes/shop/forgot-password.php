<?php $bareLayout = true; ?>
<div class="hfs-login-card">
    <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="220" height="80">
    <h1>Şifremi unuttum</h1>
    <p>Kullanıcı adınız veya kayıtlı e-posta adresinizi girin. Size sıfırlama bağlantısı gönderilir.</p>
    <?php if (!empty($msg)): ?><p class="ok"><?= h((string) $msg) ?></p><?php endif; ?>
    <?php if (!empty($err)): ?><p class="err"><?= h((string) $err) ?></p><?php endif; ?>
    <form method="post" class="stack" action="<?= h(site_url('sifremi-unuttum/')) ?>">
        <?= csrf_field() ?>
        <label>Vergi / T.C. no, kullanıcı adı veya e-posta <input name="user" required autocomplete="username"></label>
        <button class="btn" type="submit">Bağlantı gönder</button>
    </form>
    <p class="hint" style="margin-top:1rem"><a href="<?= h(site_url('giris/')) ?>">Giriş sayfasına dön</a></p>
</div>
