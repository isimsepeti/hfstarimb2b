<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Giriş · HFS Tarım Bayi</title>
<link rel="icon" href="<?= h(shop_logo()) ?>" type="image/png">
<link rel="stylesheet" href="<?= h(site_url('yonetim/assets/admin.css')) ?>?v=<?= is_file(BMN_ROOT . '/yonetim/assets/admin.css') ? filemtime(BMN_ROOT . '/yonetim/assets/admin.css') : time() ?>">
</head>
<body class="login-wrap">
<div class="login-card">
    <p class="login-logo"><img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="220" height="80"></p>
    <h1>HFS Tarım Bayi</h1>
    <p>Yönetim paneli</p>
    <?php if (!empty($hint)): ?><p class="err"><?= h((string) $hint) ?></p><?php endif; ?>
    <?php if (!empty($err) && empty($hint)): ?><p class="err">Kullanıcı veya şifre hatalı.</p><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label><span>Kullanıcı</span> <input name="user" type="text" required autofocus></label>
        <label><span>Şifre</span> <input name="pass" type="password" required autocomplete="current-password"></label>
        <label class="remember"><input type="checkbox" name="remember" value="1" checked> Beni hatırla</label>
        <button class="btn" type="submit">Giriş</button>
    </form>
</div>
</body>
</html>
