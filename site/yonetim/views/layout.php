<?php
$u = $user ?? current_user();
$here = request_path();
$nav = [
    '/yonetim/' => 'Özet',
    '/yonetim/urunler/' => 'Ürünler',
    '/yonetim/siparisler/' => 'Siparişler',
    '/yonetim/fiyatlar/' => 'Fiyatlar',
    '/yonetim/teklifler/' => 'Teklifler',
    '/yonetim/muhasebe/' => 'Muhasebe',
    '/yonetim/entegrasyon/' => 'Entegrasyon log',
    '/yonetim/kuponlar/' => 'Kuponlar',
    '/yonetim/yorumlar/' => 'Yorumlar',
    '/yonetim/sepetler/' => 'Terk sepet',
    '/yonetim/iadeler/' => 'İadeler',
    '/yonetim/yazilar/' => 'Yazılar',
    '/yonetim/kategoriler/' => 'Kategoriler',
    '/yonetim/sayfalar/' => 'Sayfalar',
    '/yonetim/kullanicilar/' => 'Bayiler',
    '/yonetim/mesajlar/' => 'Mesajlar',
    '/yonetim/epostalar/' => 'E-postalar',
    '/yonetim/ayarlar/' => 'Ayarlar',
];
$css = BMN_ROOT . '/yonetim/assets/admin.css';
$cssUrl = site_url('yonetim/assets/admin.css') . (is_file($css) ? '?v=' . filemtime($css) : '');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Yönetim · HFS Tarım Bayi</title>
<link rel="icon" href="<?= h(shop_logo()) ?>" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h($cssUrl) ?>">
</head>
<body class="admin">
<aside class="side">
    <a class="brand" href="<?= h(site_url('yonetim/')) ?>">
        <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="132" height="48">
        <span>BAYİ</span>
    </a>
    <nav>
        <div class="nav-group">
            <?php foreach ($nav as $url => $label):
                $on = ($url === '/yonetim/' && $here === '/yonetim/')
                    || ($url !== '/yonetim/' && str_starts_with($here, rtrim($url, '/')));
                ?>
                <a href="<?= h(site_url(ltrim($url, '/'))) ?>"<?= $on ? ' class="on"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
    <div class="side-foot">
        <a class="out" href="<?= h(site_url()) ?>">Dükkanı gör</a>
        <a class="out" href="<?= h(site_url('yonetim/cikis/')) ?>">Çıkış</a>
    </div>
</aside>
<div class="main">
    <div class="top">
        <strong><?= h($u['display_name'] ?? '') ?></strong>
        <span class="role"><?= h($u['role'] ?? '') ?></span>
    </div>
    <div class="pad">
        <?= $content ?>
    </div>
</div>
</body>
</html>
