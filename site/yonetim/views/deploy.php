<div class="page-head">
    <h1>Canlıya al</h1>
</div>
<p class="lead-copy">Localdeki kod <strong>https://b2b.hfstarim.com</strong> üzerine gider. Canlıdaki veritabanı şifresi, siparişler, bayiler, SMTP ve BulutTahsilat ayarları değişmez. Katalog kutusu işaretlenirse ürün / kategori / sayfa da gider.</p>
<?php if (!empty($ok)): ?><p class="ok"><?= h($ok) ?></p><?php endif; ?>
<?php if (!empty($err)): ?><p class="err"><?= h($err) ?></p><?php endif; ?>

<form method="post" class="live-go">
    <?= csrf_field() ?>
    <input type="hidden" name="intent" value="push">
    <button class="btn live" type="submit" onclick="return confirm('Kod canlıya gidecek. Sipariş, bayi ve ödeme ayarları dokunulmaz. Devam?')">Canlıya al</button>
    <?php if (empty($ready)): ?>
        <p class="hint">Aşağıya <strong>https://b2b.hfstarim.com</strong> yazın, yayın anahtarını kaydedin, FTP’yi doldurup «FTP dene».</p>
    <?php elseif (empty($ftpReady)): ?>
        <p class="hint">Elle FTP ile dosya atmayın. Aşağıya FTP sunucu / kullanıcı / şifre yazıp kaydedin, sonra Canlıya al.</p>
    <?php else: ?>
        <p class="hint">Elle bir şey yüklemeniz gerekmez. Canlıya al zip ve açıcıyı FTP ile kendisi gönderir.</p>
    <?php endif; ?>
</form>

<form method="post" class="stack card" style="margin-top:28px">
    <?= csrf_field() ?>
    <h2>Canlı bağlantı</h2>
    <label>Canlı site URL<input name="live_url" type="url" placeholder="https://b2b.hfstarim.com" value="<?= h((string) ($cfg['live_url'] ?? 'https://b2b.hfstarim.com')) ?>"></label>
    <label>Yayın anahtarı<input name="secret" value="<?= h((string) ($cfg['secret'] ?? '')) ?>" placeholder="Boş bırakırsanız üretilir" autocomplete="off"></label>
    <label>FTP sunucu<input name="ftp_host" value="<?= h((string) ($cfg['ftp_host'] ?? '')) ?>" placeholder="ftp.hfstarim.com"></label>
    <label>FTP kullanıcı<input name="ftp_user" value="<?= h((string) ($cfg['ftp_user'] ?? '')) ?>" autocomplete="off"></label>
    <label>FTP şifre<input name="ftp_pass" type="password" value="" placeholder="<?= ($cfg['ftp_pass'] ?? '') !== '' ? 'Kayıtlı şifre duruyor — denemek için tekrar yazın' : 'cPanel / DirectAdmin FTP şifresi' ?>"></label>
    <label>Uzak klasör<input name="ftp_path" value="<?= h((string) ($cfg['ftp_path'] ?? '')) ?>" placeholder="Boş bırakın (hesap zaten sitedeyse)"></label>
    <p class="hint">cPanel FTP çoğu zaman doğrudan site köküne düşer. <code>public_html</code> yazmayın; «şu an: /» görüyorsanız bu alan boş olmalı.</p>
    <label class="check"><input type="checkbox" name="ftp_ssl" value="1" <?= !empty($cfg['ftp_ssl']) ? 'checked' : '' ?>> FTP SSL dene</label>
    <label class="check"><input type="checkbox" name="include_catalog" value="1" <?= !empty($cfg['include_catalog']) ? 'checked' : '' ?>> Katalog da gitsin (ürün, kategori, sayfa — sipariş gitmez)</label>
    <?php if (!empty($missing)): ?>
        <p class="hint">Eksik: <?= h(implode(', ', $missing)) ?></p>
    <?php endif; ?>
    <div class="actions-row">
        <button class="btn" type="submit" name="intent" value="save">Ayarları kaydet</button>
        <button class="btn ghost" type="submit" name="intent" value="ftp_test">FTP dene</button>
        <button class="btn ghost" type="submit" name="intent" value="dry">Paketi yerelde hazırla</button>
    </div>
</form>

<?php if (!empty($last)): ?>
<h2>Son işlem</h2>
    <p><?= h((string) ($last['at'] ?? '')) ?><?= !empty($last['dry']) ? ' · deneme' : '' ?><?php
        if (isset($last['sent']) || isset($last['skipped'])) {
            echo ' · ' . (int) ($last['sent'] ?? 0) . ' dosya, ' . (int) ($last['skipped'] ?? 0) . ' atlandı';
        }
        if (!empty($last['bytes'])) {
            echo ' · ' . number_format((int) $last['bytes'] / 1048576, 1, ',', '.') . ' MB';
        }
    ?></p>
<?php if (!empty($last['steps']) && is_array($last['steps'])): ?>
<ul class="steps">
    <?php foreach ($last['steps'] as $s): ?><li><?= h((string) $s) ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>
