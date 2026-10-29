<?php
declare(strict_types=1);

define('BMN_ROOT', dirname(__DIR__));
require BMN_ROOT . '/app/HfsDebug.php';
HfsDebug::bootEarly();
date_default_timezone_set('Europe/Istanbul');

$configFile = BMN_ROOT . '/config/app.php';

if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "config/app.php yok. config/app.example.php dosyasını kopyalayıp veritabanı bilgilerini girin.\n";
    exit(1);
}

$GLOBALS['bmn_config'] = require $configFile;
HfsDebug::applyConfig($GLOBALS['bmn_config']);
define('BMN_BASE', $GLOBALS['bmn_config']['base'] ?? '');

require BMN_ROOT . '/app/helpers.php';
require BMN_ROOT . '/app/Database.php';
require BMN_ROOT . '/app/Seo.php';
require BMN_ROOT . '/app/Coupon.php';
require BMN_ROOT . '/app/Shipping.php';
require BMN_ROOT . '/app/Mailer.php';
require BMN_ROOT . '/app/Abandoned.php';
require BMN_ROOT . '/app/Review.php';
require BMN_ROOT . '/app/Cart.php';
require BMN_ROOT . '/app/Catalog.php';
require BMN_ROOT . '/app/Option.php';
require BMN_ROOT . '/app/Seed.php';
require BMN_ROOT . '/app/Auth.php';
require BMN_ROOT . '/app/HfsAuthPassword.php';
require BMN_ROOT . '/app/HfsCookies.php';
require BMN_ROOT . '/app/HfsLegal.php';
require BMN_ROOT . '/app/Migrate.php';
require BMN_ROOT . '/app/Admin.php';
require BMN_ROOT . '/app/AdminShop.php';
require BMN_ROOT . '/app/Front.php';
require BMN_ROOT . '/app/WpPassword.php';
require BMN_ROOT . '/app/HfsMigrate.php';
require BMN_ROOT . '/app/HfsPricing.php';
require BMN_ROOT . '/app/HfsFinance.php';
require BMN_ROOT . '/app/HfsMontaj.php';
require BMN_ROOT . '/app/HfsDiagram.php';
require BMN_ROOT . '/app/HfsDiagramSync.php';
require BMN_ROOT . '/app/HfsPay.php';
require BMN_ROOT . '/app/HfsSync.php';
require BMN_ROOT . '/app/HfsSeed.php';
require BMN_ROOT . '/app/HfsImport.php';
require BMN_ROOT . '/app/HfsLiveSync.php';
require BMN_ROOT . '/app/HfsNav.php';
require BMN_ROOT . '/app/HfsDealers.php';
require BMN_ROOT . '/app/HfsOffers.php';
require BMN_ROOT . '/app/HfsApproval.php';
require BMN_ROOT . '/app/HfsQuick.php';
require BMN_ROOT . '/app/HfsPrices.php';
require BMN_ROOT . '/app/HfsTrAddress.php';
require BMN_ROOT . '/app/HfsProfile.php';
require BMN_ROOT . '/app/HfsProductsExport.php';
require BMN_ROOT . '/app/HfsBayt.php';
require BMN_ROOT . '/app/HfsIntegrLog.php';
require BMN_ROOT . '/app/HfsMail.php';
require BMN_ROOT . '/app/HfsYurtici.php';
require BMN_ROOT . '/app/HfsMedia.php';
require BMN_ROOT . '/app/HfsMediaCutover.php';
require BMN_ROOT . '/app/HfsVoltV3.php';
require BMN_ROOT . '/app/HfsSkuChange.php';
require BMN_ROOT . '/app/Hfs.php';
if (!defined('HFS_SHOP')) {
    define('HFS_SHOP', true);
}

bmn_session_start();

$GLOBALS['bmn_user'] = null;
try {
    bmn_heal_live_urls();
} catch (Throwable $e) {
    HfsDebug::logThrowable($e, 'heal_live_urls');
}
try {
    Auth::tryRememberLogin();
} catch (Throwable $e) {
    HfsDebug::logThrowable($e, 'remember_login');
}
try {
    $GLOBALS['bmn_user'] = Auth::user();
} catch (Throwable $e) {
    HfsDebug::logThrowable($e, 'auth_user');
    $GLOBALS['bmn_user'] = null;
}
try {
    Migrate::up();
    Hfs::boot();
} catch (Throwable $e) {
    HfsDebug::logThrowable($e, 'migrate_boot');
}

if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', '1');
    }
}
