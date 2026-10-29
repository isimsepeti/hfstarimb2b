<?php
$site = (string) setting('site_name', 'HFS Tarım Bayi');
$tagline = (string) setting('tagline', 'Bayi satış ve yedek parça sistemi');
$seo = $seo ?? [];
$pathNow = request_path();
$isHome = $pathNow === '/';
$mail = (string) setting('email', '');
$phone = (string) setting('phone', '');
$yil = (int) date('Y');
$qNow = (string) ($_GET['s'] ?? $_GET['q'] ?? '');
$u = current_user();
$bareLayout = !empty($bareLayout);
$cartCount = (int) ($cartCount ?? 0);
if (!$bareLayout && $cartCount === 0) {
    try {
        $cartCount = (int) (Cart::hydrated()['count'] ?? Cart::count());
    } catch (Throwable) {
        $cartCount = Cart::count();
    }
}
$navCats = $navCats ?? [];
$snap = null;
if (!$bareLayout && $u) {
    try {
        $snap = HfsFinance::snapshot($u);
    } catch (Throwable) {
        $snap = null;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf" content="<?= h(csrf_token()) ?>">
    <?= Seo::head($seo) ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('hfs.css')) ?>">
</head>
<body id="top" class="<?= $bareLayout ? 'is-login' : ($isHome ? 'is-home' : 'is-inner') ?><?= !empty($diagram) ? ' hfs-b2b-parts-diagram-page' : '' ?>">
<a class="skip" href="#icerik">İçeriğe geç</a>
<?php if ($bareLayout): ?>
<main id="icerik" class="login-main"><?= $content ?></main>
<?php else: ?>
<header class="site-header">
    <p class="top-notice">
        <span>HFS Tarım bayi paneli</span>
        <span>Parça diyagramı · Cari hesap · Kart / açık hesap</span>
        <span><?= $u ? h($u['display_name'] ?: $u['username']) : 'Giriş gerekli' ?></span>
    </p>
    <div class="header-main wrap">
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="ana-menu" aria-label="Menü"></button>
        <a class="logo" href="<?= h(site_url()) ?>">
            <img src="<?= h(shop_logo()) ?>" alt="HFS Tarım" width="200" height="72">
        </a>
        <form class="header-search" action="<?= h(site_url()) ?>" method="get" role="search" data-live-url="<?= h(site_url('arama/oneri/')) ?>" autocomplete="off">
            <input type="search" name="s" placeholder="Ürün, SKU veya kategori ara…" value="<?= h($qNow) ?>" required autocomplete="off" aria-autocomplete="list" aria-controls="live-search-panel" aria-expanded="false">
            <button type="submit">Ara</button>
            <div class="live-search" id="live-search-panel" hidden role="listbox"></div>
        </form>
        <div class="header-tools">
            <?php if ($u): ?>
                <a class="hfs-cari-cta" href="<?= h(site_url('cari-odeme/')) ?>">Cari Ödeme Yap</a>
                <a class="header-icon" href="<?= h(site_url('hesabim/')) ?>" aria-label="Hesabım, <?= h($u['display_name'] ?: $u['username']) ?>" title="Hesabım">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm-7 9c1.2-3.6 3.8-5.5 7-5.5s5.8 1.9 7 5.5"/></svg>
                </a>
            <?php endif; ?>
            <a class="cart-btn" href="<?= h(site_url('sepet/')) ?>" data-cart-open aria-controls="mini-cart" aria-expanded="false" aria-label="Sepet, <?= (int) $cartCount ?> ürün">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M6 7h15l-1.4 8.2H8.2L6 7zm0 0L5 4H2"/><circle cx="9" cy="20" r="1.4" fill="currentColor"/><circle cx="18" cy="20" r="1.4" fill="currentColor"/></svg>
                <?php if ($cartCount > 0): ?><span><?= (int) $cartCount ?></span><?php endif; ?>
            </a>
            <?php if ($u): ?>
                <a class="header-icon" href="<?= h(site_url('cikis/')) ?>" aria-label="Çıkış" title="Çıkış">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 16l5-4-5-4M20 12H10"/></svg>
                </a>
            <?php else: ?>
                <a class="header-icon" href="<?= h(site_url('giris/')) ?>" aria-label="Giriş" title="Giriş">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm-7 9c1.2-3.6 3.8-5.5 7-5.5s5.8 1.9 7 5.5"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <nav class="header-nav" id="ana-menu" aria-label="Kategoriler">
        <div class="wrap nav-bar">
            <a class="nav-top" href="<?= h(site_url()) ?>"<?= $isHome ? ' aria-current="page"' : '' ?>>Mağaza</a>
            <?php foreach (($navMenu ?? HfsNav::items($pathNow)) as $item):
                $kids = $item['children'] ?? [];
                if ($kids):
                    $colN = max(1, count($kids));
                    ?>
                <div class="nav-item<?= !empty($item['on']) ? ' is-on' : '' ?>">
                    <a class="nav-top" href="<?= h($item['href']) ?>"<?= !empty($item['on']) ? ' aria-current="page"' : '' ?> aria-haspopup="true" aria-expanded="false"><?= h($item['label']) ?></a>
                    <div class="nav-mega" role="group" aria-label="<?= h($item['label']) ?>">
                        <div class="nav-mega-inner wrap<?= $colN >= 6 ? ' is-dense' : '' ?>" style="--mega-cols: <?= (int) $colN ?>">
                            <div class="nav-mega-intro">
                                <p class="nav-mega-kicker">Kategoriler</p>
                                <strong><?= h($item['label']) ?></strong>
                                <a href="<?= h($item['href']) ?>">Tümünü gör</a>
                            </div>
                            <?php foreach ($kids as $ch):
                                $grand = $ch['children'] ?? [];
                                ?>
                                <div class="nav-mega-col<?= !empty($ch['on']) ? ' is-on' : '' ?>">
                                    <a class="nav-mega-title" href="<?= h($ch['href']) ?>"<?= !empty($ch['on']) ? ' aria-current="page"' : '' ?>><?= h($ch['label']) ?></a>
                                    <?php foreach ($grand as $g): ?>
                                        <a class="nav-mega-link" href="<?= h($g['href']) ?>"<?= !empty($g['on']) ? ' aria-current="page"' : '' ?>><?= h($g['label']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <a class="nav-top" href="<?= h($item['href']) ?>"<?= !empty($item['on']) ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
                <?php endif;
            endforeach; ?>
        </div>
    </nav>
</header>
<main id="icerik">
    <?= $content ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/mini-cart.php'; ?>
<script src="<?= h(asset('app.js')) ?>" defer></script>
<script src="<?= h(asset('hfs.js')) ?>" defer></script>
<script src="<?= h(asset('hfs-address.js')) ?>" defer></script>
<?php endif; ?>
<?php
try {
    HfsCookies::ensurePolicyPage();
    HfsLegal::ensureLegalPages();
} catch (Throwable) {
}
require __DIR__ . '/partials/cookie-consent.php';
require __DIR__ . '/partials/legal-modal.php';
?>
<script src="<?= h(asset('hfs-cookies.js')) ?>" defer></script>
<script src="<?= h(asset('hfs-legal.js')) ?>" defer></script>
</body>
</html>
