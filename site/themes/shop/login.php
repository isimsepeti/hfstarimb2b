<?php $bareLayout = true; ?>
<div class="hfs-login-card">
    <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="220" height="80">
    <h1>HFS Tarım Bayi</h1>
    <p>Fiyatlar ve sipariş için bayi hesabınızla girin. İlk girişte kullanıcı adı ve şifre kayıtlı vergi veya T.C. numaranızdır (10–11 hane).</p>
    <?php if (!empty($_GET['ok']) && (string) $_GET['ok'] === 'sifre'): ?><p class="ok">Şifreniz güncellendi. Giriş yapabilirsiniz.</p><?php endif; ?>
    <?php if (!empty($err)): ?><p class="err">Kullanıcı veya şifre hatalı.</p><?php endif; ?>
    <form method="post" class="stack" action="<?= h(site_url('giris/')) ?>">
        <?= csrf_field() ?>
        <label>Vergi / T.C. no, kullanıcı adı veya e-posta <input name="user" required autocomplete="username" inputmode="numeric"></label>
        <label>Şifre <input name="pass" type="password" required autocomplete="current-password"></label>
        <label class="remember"><input type="checkbox" name="remember" value="1" checked> Beni hatırla</label>
        <button class="btn" type="submit">Giriş yap</button>
    </form>
    <p class="hint" style="margin-top:1rem"><a href="<?= h(site_url('sifremi-unuttum/')) ?>">Şifremi unuttum</a> · <a href="#" data-cookie-settings>Çerez tercihleri</a></p>
</div>
