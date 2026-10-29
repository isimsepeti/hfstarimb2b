<?php $bareLayout = true; ?>
<div class="hfs-login-card">
    <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="220" height="80">
    <h1>İlk giriş — yeni şifre</h1>
    <p>Güvenlik için kayıtlı vergi / T.C. numaranızla giriş yaptınız. Devam etmek için kalıcı bir şifre belirleyin.</p>
    <?php if (!empty($err)): ?><p class="err"><?= h((string) $err) ?></p><?php endif; ?>
    <form method="post" class="stack" action="<?= h(site_url('sifre-belirle/')) ?>">
        <?= csrf_field() ?>
        <label>Yeni şifre (en az 8 karakter) <input name="password" type="password" required autocomplete="new-password" minlength="8"></label>
        <label>Tekrar <input name="password_confirm" type="password" required autocomplete="new-password" minlength="8"></label>
        <button class="btn" type="submit">Kaydet ve devam et</button>
    </form>
    <p class="hint" style="margin-top:1rem"><a href="<?= h(site_url('cikis/')) ?>">Çıkış</a></p>
</div>
