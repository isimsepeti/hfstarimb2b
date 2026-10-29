<?php $bareLayout = true; ?>
<div class="hfs-login-card">
    <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="220" height="80">
    <h1>Yeni şifre</h1>
    <?php if (empty($valid)): ?>
        <p class="err"><?= h((string) ($err ?? 'Geçersiz bağlantı.')) ?></p>
        <p class="hint"><a href="<?= h(site_url('sifremi-unuttum/')) ?>">Yeni sıfırlama talebi</a></p>
    <?php else: ?>
        <?php if (!empty($_GET['err'])): ?><p class="err"><?= h((string) $_GET['err']) ?></p><?php endif; ?>
        <form method="post" class="stack" action="<?= h(site_url('sifre-sifirla/')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h((string) ($token ?? '')) ?>">
            <label>Yeni şifre (en az 8 karakter) <input name="password" type="password" required autocomplete="new-password" minlength="8"></label>
            <label>Tekrar <input name="password_confirm" type="password" required autocomplete="new-password" minlength="8"></label>
            <button class="btn" type="submit">Kaydet</button>
        </form>
    <?php endif; ?>
    <p class="hint" style="margin-top:1rem"><a href="<?= h(site_url('giris/')) ?>">Giriş</a></p>
</div>
