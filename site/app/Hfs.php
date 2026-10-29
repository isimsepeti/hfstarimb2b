<?php
declare(strict_types=1);

final class Hfs
{
    public static function boot(): void
    {
        if (!defined('HFS_SHOP')) {
            define('HFS_SHOP', true);
        }
        try {
            HfsMigrate::up();
            HfsDiagram::healPlacement();
            HfsMail::healDealerTemplates();
        } catch (Throwable) {
        }
        try {
            HfsSync::maybeRunForAdmin();
        } catch (Throwable) {
        }
        try {
            if (setting('hfs_bayt_backfill', '0') === '1') {
                setting_set('hfs_bayt_backfill', '0');
                HfsBayt::pushPendingOrders(12);
            }
            if (setting('hfs_bayt_waybill_backfill', '0') === '1') {
                setting_set('hfs_bayt_waybill_backfill', '0');
                HfsBayt::pushPendingOrders(8);
            }
            if (setting('hfs_bayt_order_backfill', '0') === '1') {
                setting_set('hfs_bayt_order_backfill', '0');
                HfsBayt::pushPendingOrders(8);
            }
        } catch (Throwable) {
        }
    }

    public static function dispatch(string $method, string $path): bool
    {
        if ($path === '/odeme/donus/') {
            HfsPay::handleReturn();
            return true;
        }
        if ($path === '/bulut-bildirim/') {
            HfsPay::handleNotify();
            return true;
        }
        if ($path === '/hfs-bt-watch/') {
            HfsPay::handleWatch();
            return true;
        }
        if ($path === '/hfs-sync/') {
            self::accountingSync();
            return true;
        }
        if ($path === '/hfs-media-pull/') {
            self::mediaPull();
            return true;
        }
        if ($path === '/hfs-bayt-order/') {
            self::baytOrder();
            return true;
        }
        if ($path === '/hfs-bt-sync/') {
            self::btSync();
            return true;
        }
        if ($path === '/hfs-bt-web/') {
            self::btWeb();
            return true;
        }
        if ($path === '/giris/') {
            $method === 'POST' ? self::loginPost() : self::login();
            return true;
        }
        if ($path === '/sifremi-unuttum/') {
            $method === 'POST' ? self::forgotPasswordPost() : self::forgotPassword();
            return true;
        }
        if ($path === '/sifre-sifirla/') {
            $method === 'POST' ? self::resetPasswordPost() : self::resetPassword();
            return true;
        }
        if ($path === '/sifre-belirle/') {
            $method === 'POST' ? self::forcePasswordPost() : self::forcePassword();
            return true;
        }
        if ($path === '/cikis/') {
            Auth::logout();
            redirect(site_url('giris/'));
        }
        if (self::guestBlocked($path)) {
            self::login();
            return true;
        }
        if ($path === '/hesabim/' || $path === '/cari-hesap/') {
            if ($method === 'POST') {
                HfsProfile::savePost();
            }
            self::account();
            return true;
        }
        if ($path === '/hesabim/ekstre/' || $path === '/cari-ekstre/') {
            $print = isset($_GET['yazdir']);
            $print ? self::ekstrePrint() : self::ekstre();
            return true;
        }
        if ($path === '/hesabim/fiyat-listesi/' || $path === '/fiyat-listesi/') {
            HfsPrices::exportDealer(current_user());
            return true;
        }
        if ($path === '/hesabim/kargo/' || $path === '/kargo-takip/') {
            self::kargo();
            return true;
        }
        if (preg_match('#^/siparis/([A-Za-z0-9-]+)/irsaliye/$#', $path, $m)) {
            self::dealerSlip($m[1]);
            return true;
        }
        if (preg_match('#^/siparis/([A-Za-z0-9-]+)/dekont/$#', $path, $m)) {
            HfsPay::downloadDekont($m[1], false);
            return true;
        }
        if (preg_match('#^/siparis/([A-Za-z0-9-]+)/e-irsaliye/$#', $path, $m)) {
            self::dealerEirsaliyePreview($m[1]);
            return true;
        }
        if ($path === '/cari-odeme/') {
            $method === 'POST' ? HfsPay::cariPost() : self::cariPay();
            return true;
        }
        if ($path === '/arama/oneri/') {
            self::searchSuggest();
            return true;
        }
        if ($path === '/hizli-siparis/') {
            if (HfsQuick::handleAjax()) {
                return true;
            }
            $method === 'POST' ? self::quickPost() : self::quickOrder();
            return true;
        }
        if ($path === '/siparis-onaylari/') {
            $method === 'POST' ? self::approvePost() : self::approvals();
            return true;
        }
        if ($path === '/odeme/' && $method === 'POST') {
            HfsPay::checkoutPost();
            return true;
        }
        if (preg_match('#^/siparis/([A-Za-z0-9-]+)/$#', $path, $m) && isset($_GET['tekrar'])) {
            $order = Catalog::orderByCode($m[1]);
            if ($order && Catalog::dealerCanSeeOrder($order)) {
                HfsQuick::reorder((int) $order['id']);
            }
            redirect(site_url('sepet/'));
        }
        return false;
    }

    public static function guestBlocked(string $path): bool
    {
        if (current_user()) {
            return false;
        }
        $open = [
            '/giris/', '/cikis/', '/sifremi-unuttum/', '/sifre-sifirla/', '/odeme/donus/', '/bulut-bildirim/', '/hfs-bt-watch/', '/hfs-sync/', '/hfs-media-pull/', '/hfs-bt-sync/', '/hfs-bt-web/', '/hfs-bayt-order/', '/hfs-yayin/', '/hfs-apply.php/', '/hfs-unpak.php/', '/robots.txt/', '/sitemap.xml/',
            '/sitemap_index.xml/', '/feed/', '/cron/', '/manifest.webmanifest/', '/llms.txt/',
        ];
        if (in_array($path, $open, true)) {
            return false;
        }
        if (str_starts_with($path, '/yonetim/')) {
            return false;
        }
        return true;
    }

    public static function dealer(): ?array
    {
        $u = current_user();
        return $u && HfsPricing::isDealer($u) ? $u : $u;
    }

    private static function searchSuggest(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $q = trim((string) ($_GET['q'] ?? $_GET['s'] ?? ''));
        echo json_encode(Catalog::suggest($q), JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function accountingSync(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $key = (string) ($GLOBALS['bmn_config']['cron_key'] ?? '');
        if ($key === '' || !hash_equals($key, (string) ($_GET['k'] ?? ''))) {
            http_response_code(403);
            echo 'no';
            exit;
        }
        if (!HfsSync::tryLock()) {
            echo 'busy';
            exit;
        }
        try {
            $r = HfsSync::run(true);
            echo 'ok phase=' . (int) setting('hfs_sync_phase', '0') . ' bulut=' . $r['bulut'] . ' bayt=' . $r['bayt'] . ' col_fail=' . $r['col_fail'];
        } finally {
            HfsSync::unlock();
        }
        exit;
    }

    private static function mediaPull(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $key = (string) ($GLOBALS['bmn_config']['cron_key'] ?? '');
        if ($key === '' || !hash_equals($key, (string) ($_GET['k'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        @set_time_limit(120);
        $limit = max(1, min(150, (int) ($_GET['limit'] ?? 80)));
        $out = HfsMediaCutover::pullBatch($limit);
        echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function btSync(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $secret = '';
        $sf = BMN_ROOT . '/config/deploy-secret.php';
        if (is_file($sf)) {
            $s = require $sf;
            if (is_array($s) && !empty($s['secret'])) {
                $secret = (string) $s['secret'];
            }
        }
        $ts = (string) ($_SERVER['HTTP_X_HFS_TS'] ?? '');
        $mac = (string) ($_SERVER['HTTP_X_HFS_MAC'] ?? '');
        $user = trim((string) ($_GET['user'] ?? 'bayi1'));
        if ($secret === '' || $ts === '' || $mac === '' || abs(time() - (int) $ts) > 600) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'imza']);
            exit;
        }
        $expect = hash_hmac('sha256', $ts . '.btsync.' . $user, $secret);
        if (!hash_equals($expect, $mac)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'mac']);
            exit;
        }
        echo json_encode(HfsPay::syncNamedDealer($user), JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function btWeb(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $secret = '';
        $sf = BMN_ROOT . '/config/deploy-secret.php';
        if (is_file($sf)) {
            $s = require $sf;
            if (is_array($s) && !empty($s['secret'])) {
                $secret = (string) $s['secret'];
            }
        }
        $ts = (string) ($_SERVER['HTTP_X_HFS_TS'] ?? '');
        $mac = (string) ($_SERVER['HTTP_X_HFS_MAC'] ?? '');
        $act = strtolower(trim((string) ($_GET['act'] ?? 'save')));
        if ($secret === '' || $ts === '' || $mac === '' || abs(time() - (int) $ts) > 600) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'imza']);
            exit;
        }
        $expect = hash_hmac('sha256', $ts . '.btweb.' . $act, $secret);
        if (!hash_equals($expect, $mac)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'mac']);
            exit;
        }
        if ($act === 'ping') {
            echo json_encode(HfsPay::pingWebApi(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'trace') {
            $op = trim((string) ($_GET['op'] ?? 'SubFirmList'));
            $uid = (int) ($_GET['user'] ?? 1202);
            $full = (string) ($_GET['full'] ?? '') === '1';
            echo json_encode(HfsPay::exportSoapTrace($op, $uid, !$full), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['pass'] ?? '');
        $firm = trim((string) ($_POST['firm'] ?? ''));
        echo json_encode(HfsPay::persistWebApi($user, $pass, $firm), JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function baytOrder(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $secret = '';
        $sf = BMN_ROOT . '/config/deploy-secret.php';
        if (is_file($sf)) {
            $s = require $sf;
            if (is_array($s) && !empty($s['secret'])) {
                $secret = (string) $s['secret'];
            }
        }
        $ts = (string) ($_SERVER['HTTP_X_HFS_TS'] ?? '');
        $mac = (string) ($_SERVER['HTTP_X_HFS_MAC'] ?? '');
        $code = strtolower(trim((string) ($_GET['code'] ?? '')));
        $orderMac = strtolower(trim((string) ($_GET['order'] ?? '')));
        if ($secret === '' || $ts === '' || $mac === '' || $code === '' || abs(time() - (int) $ts) > 600) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'imza']);
            exit;
        }
        $confirm = trim((string) ($_GET['confirm'] ?? ''));
        if ($code === 'purgeorders') {
            $macKey = $ts . '.baytorder.purgeorders.' . $confirm;
        } elseif ($code === 'dealercredentials') {
            $macKey = $ts . '.baytorder.dealercredentials.' . $confirm;
        } elseif ($code === 'smtpconfigure') {
            $macKey = $ts . '.baytorder.smtpconfigure';
        } else {
            $macKey = $ts . '.baytorder.' . $code . (in_array($code, ['eirsaliyepreview', 'probedi', 'stageedi', 'orderfix', 'orderprobe', 'orderbulutcancel', 'ordersync', 'setskuimage', 'skuinfo', 'uploadasset', 'voltv3import', 'skuchange', 'dekontenrich', 'dekontprobe', 'payfailbackfill'], true) && $orderMac !== '' ? '.' . $orderMac : '');
        }
        $expect = hash_hmac('sha256', $macKey, $secret);
        if (!hash_equals($expect, $mac)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'mac']);
            exit;
        }
        if ($code === 'diag') {
            echo json_encode(HfsBayt::inspect((string) ($_GET['sku'] ?? 'HFS-SA-V2-74')), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'catprobe') {
            $rows = db()->query("SELECT id,slug,name,path,parent_id FROM categories WHERE slug LIKE '%volt%' OR path LIKE '%volt%' OR slug LIKE '%montaj%' OR name LIKE '%Volt%' ORDER BY path, id")->fetchAll();
            $hosts = db()->query("SELECT id,slug,title,sku,has_diagram FROM products WHERE slug LIKE '%volt%' OR title LIKE '%Volt Sistem%' ORDER BY id")->fetchAll();
            echo json_encode(['ok' => true, 'cats' => $rows, 'hosts' => $hosts], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'schemaprobe') {
            $cols = db()->query('SHOW COLUMNS FROM categories')->fetchAll(PDO::FETCH_COLUMN);
            $pcols = db()->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['ok' => true, 'categories' => $cols, 'products' => $pcols], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'catprobe3') {
            $rows = db()->query("SELECT id,slug,name,path,parent_id,IFNULL(hide_nav,0) hide_nav FROM categories WHERE slug LIKE '%montaj%' OR name LIKE '%Montaj%' OR name LIKE '%montaj%' ORDER BY path")->fetchAll();
            $meta = db()->prepare("SELECT meta_key, LEFT(meta_value,120) v FROM product_meta WHERE product_id IN (7352,7355)");
            $meta->execute();
            echo json_encode(['ok' => true, 'montaj_cats' => $rows, 'host_meta' => $meta->fetchAll()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'diagramfix') {
            @set_time_limit(120);
            bmn_session_release();
            $only = trim((string) ($_GET['slug'] ?? 'sirt-sistem-v2-model-sanziman'));
            $force = (string) ($_GET['force'] ?? '') === '1';
            if ($force) {
                $lines = HfsDiagramSync::run(true, $only !== '' ? $only : null);
            } else {
                $lines = HfsDiagramSync::repairPartSkuLinks($only !== '' ? $only : null);
            }
            echo json_encode(['ok' => true, 'slug' => $only, 'force' => $force, 'fixed' => $lines], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'skuinfo') {
            $sku = strtoupper(trim((string) ($_GET['order'] ?? '')));
            $st = db()->prepare('SELECT id,sku,title,type,stock,featured_media FROM products WHERE sku=? LIMIT 1');
            $st->execute([$sku]);
            $p = $st->fetch();
            if (!$p) {
                $st = db()->prepare("SELECT id,sku,title,type,stock FROM products WHERE title LIKE ? AND status='publish' ORDER BY id DESC LIMIT 8");
                $st->execute(['%' . $sku . '%']);
                echo json_encode(['ok' => true, 'mode' => 'search', 'rows' => $st->fetchAll()], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pid = (int) $p['id'];
            $vars = Catalog::variants($pid);
            $axes = Option::axesForProduct($pid);
            echo json_encode([
                'ok' => true,
                'product' => $p,
                'variants' => array_map(static fn ($v) => [
                    'id' => (int) $v['id'],
                    'sku' => (string) ($v['sku'] ?? ''),
                    'name' => (string) ($v['name'] ?? ''),
                    'stock' => (int) ($v['stock'] ?? 0),
                ], $vars),
                'axes' => array_map(static fn ($a) => [
                    'id' => (int) $a['id'],
                    'name' => (string) $a['name'],
                    'values' => array_map(static fn ($v) => [
                        'id' => (int) $v['id'],
                        'name' => (string) $v['name'],
                    ], $a['values'] ?? []),
                ], $axes),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'setskuimage') {
            $sku = strtoupper(trim((string) ($_GET['order'] ?? '')));
            $path = ltrim(str_replace('\\', '/', trim((string) ($_GET['path'] ?? ''))), '/');
            if ($sku === '' || $path === '' || str_contains($path, '..') || !preg_match('#^\d{4}/\d{2}/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$#i', $path)) {
                echo json_encode(['ok' => false, 'error' => 'sku/path']);
                exit;
            }
            $st = db()->prepare('SELECT id, featured_media FROM products WHERE sku=? LIMIT 1');
            $st->execute([$sku]);
            $row = $st->fetch();
            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'urun yok']);
                exit;
            }
            $raw = (string) file_get_contents('php://input');
            $wrote = 0;
            if (strlen($raw) > 1000) {
                $dest = BMN_ROOT . '/uploads/' . $path;
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    echo json_encode(['ok' => false, 'error' => 'uploads klasoru']);
                    exit;
                }
                $wrote = (int) file_put_contents($dest, $raw);
                if ($wrote < 1000) {
                    echo json_encode(['ok' => false, 'error' => 'yazilamadi']);
                    exit;
                }
            }
            db()->prepare('UPDATE products SET featured_media=?, modified_at=NOW() WHERE id=?')->execute([$path, (int) $row['id']]);
            $cache = BMN_ROOT . '/data/cache/media-by-sku.json';
            if (is_file($cache)) {
                $j = json_decode((string) file_get_contents($cache), true);
                if (!is_array($j)) {
                    $j = [];
                }
                $j[$sku] = $path;
                @file_put_contents($cache, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                @mkdir(dirname($cache), 0755, true);
                @file_put_contents($cache, json_encode([$sku => $path], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            echo json_encode([
                'ok' => true,
                'sku' => $sku,
                'id' => (int) $row['id'],
                'was' => (string) ($row['featured_media'] ?? ''),
                'now' => $path,
                'wrote' => $wrote,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'uploadasset') {
            $path = ltrim(str_replace('\\', '/', trim((string) ($_GET['order'] ?? $_GET['path'] ?? ''))), '/');
            if ($path === '' || str_contains($path, '..') || !preg_match('#^\d{4}/\d{2}/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$#i', $path)) {
                echo json_encode(['ok' => false, 'error' => 'path']);
                exit;
            }
            $raw = (string) file_get_contents('php://input');
            if (strlen($raw) < 500) {
                echo json_encode(['ok' => false, 'error' => 'body']);
                exit;
            }
            $dest = BMN_ROOT . '/uploads/' . $path;
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                echo json_encode(['ok' => false, 'error' => 'uploads klasoru']);
                exit;
            }
            $wrote = (int) file_put_contents($dest, $raw);
            echo json_encode(['ok' => $wrote > 500, 'path' => $path, 'wrote' => $wrote], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'voltv3import') {
            @set_time_limit(180);
            bmn_session_release();
            $raw = (string) file_get_contents('php://input');
            if (strlen($raw) < 50) {
                echo json_encode(['ok' => false, 'error' => 'csv body']);
                exit;
            }
            $tmp = BMN_ROOT . '/storage/incoming/volt33-import-' . date('YmdHis') . '.csv';
            @mkdir(dirname($tmp), 0755, true);
            file_put_contents($tmp, $raw);
            $parts = HfsVoltV3::parseCsv($tmp);
            @unlink($tmp);
            if ($parts === []) {
                echo json_encode(['ok' => false, 'error' => 'csv bos', 'bytes' => strlen($raw)], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $images = [
                'govde' => '2026/10/volt-sistem-33v-govde-grubu.png',
                'kol' => '2026/10/volt-sistem-33v-kol-grubu.png',
                'placeholder' => '2026/10/hfs-placeholder-part.png',
            ];
            $out = HfsVoltV3::install($parts, $images);
            $out['parsed'] = count($parts);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'skuchange') {
            @set_time_limit(600);
            @ini_set('memory_limit', '512M');
            bmn_session_release();
            $dry = ($orderMac === 'dry' || (string) ($_GET['dry'] ?? '') === '1');
            $raw = (string) file_get_contents('php://input');
            if (strlen($raw) < 100) {
                echo json_encode(['ok' => false, 'error' => 'xlsx body'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $tmp = BMN_ROOT . '/storage/incoming/sku-change-' . date('YmdHis') . '.xlsx';
            @mkdir(dirname($tmp), 0755, true);
            file_put_contents($tmp, $raw);
            $out = HfsSkuChange::apply($tmp, $dry, true);
            $out['bytes'] = strlen($raw);
            $out['tmp'] = basename($tmp);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'dekontenrich') {
            @set_time_limit(600);
            bmn_session_release();
            HfsMigrate::up();
            $lim = (int) ($orderMac !== '' ? $orderMac : ($_GET['limit'] ?? 100));
            echo json_encode(HfsPay::enrichMissingDekonts($lim > 0 ? $lim : 100), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'dekontprobe') {
            bmn_session_release();
            echo json_encode(HfsPay::probeVposDekont(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'payfailbackfill') {
            @set_time_limit(300);
            bmn_session_release();
            $lim = (int) ($_GET['limit'] ?? 40);
            echo json_encode(HfsPay::backfillFailedPaymentMessages($lim > 0 ? $lim : 40), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'pullcari') {
            echo json_encode(HfsDealers::pullFromAccounting(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'carilist') {
            echo json_encode(HfsBayt::inspectCustomers(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'migratecheck') {
            HfsMigrate::up();
            $uCols = array_column(db()->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
            $need = ['district', 'postcode', 'country', 'bayt_customer_id'];
            $missing = array_values(array_filter($need, static fn ($c) => !in_array($c, $uCols, true)));
            $dealers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='dealer' AND bayt_customer_id>0")->fetchColumn();
            $dealersAll = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='dealer'")->fetchColumn();
            $mustChange = 0;
            if (in_array('must_change_password', $uCols, true)) {
                $mustChange = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='dealer' AND must_change_password=1")->fetchColumn();
            }
            echo json_encode([
                'ok' => $missing === [],
                'hfs_schema' => (int) setting('hfs_schema', '0'),
                'missing_columns' => $missing,
                'dealers_bayt' => $dealers,
                'dealers_all' => $dealersAll,
                'dealers_must_change' => $mustChange,
                'smtp_on' => Mailer::smtpOn(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'importbaytxlsx') {
            @set_time_limit(900);
            @ini_set('memory_limit', '512M');
            bmn_session_release();
            HfsMigrate::up();
            $path = '';
            foreach ([
                BMN_ROOT . '/storage/bayt-cariler.xlsx',
                BMN_ROOT . '/database/bayt-cariler.xlsx',
            ] as $try) {
                if (is_file($try)) {
                    $path = $try;
                    break;
                }
            }
            if ($path === '') {
                echo json_encode(['ok' => false, 'message' => 'bayt-cariler.xlsx yok (storage veya database)'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(HfsDealers::importFile($path, 'bayt-cariler.xlsx'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'bayttest') {
            echo json_encode(HfsBayt::test(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'livetest') {
            @set_time_limit(180);
            bmn_session_release();
            $user = trim((string) ($_GET['user'] ?? 'bayi1'));
            echo json_encode(HfsBayt::createTestOrderForBayt($user), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'ordersync') {
            $oc = strtoupper(trim((string) ($_GET['order'] ?? '')));
            $order = $oc !== '' ? Catalog::orderByCode($oc) : null;
            if (!$order) {
                echo json_encode(['ok' => false, 'error' => 'siparis yok']);
                exit;
            }
            $changed = HfsBayt::syncOne($order);
            $fresh = Catalog::orderByCode($oc) ?: $order;
            echo json_encode([
                'ok' => true,
                'code' => $oc,
                'changed' => $changed,
                'bayt_approval' => (string) ($fresh['bayt_approval'] ?? ''),
                'status_label' => hfs_order_status_for($fresh),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'orderprobe') {
            $oc = strtoupper(trim((string) ($_GET['order'] ?? '')));
            $order = $oc !== '' ? Catalog::orderByCode($oc) : null;
            if (!$order) {
                echo json_encode(['ok' => false, 'error' => 'siparis yok']);
                exit;
            }
            $bulut = HfsPay::inspectOrderPayment($order);
            $baytLive = HfsBayt::inspectOrderRemote($order);
            $logs = [];
            try {
                HfsIntegrLog::ensureSchema();
                $stLog = db()->prepare('SELECT id,source,event,ok,message,context,created_at FROM integration_log WHERE order_code=? OR order_id=? ORDER BY id DESC LIMIT 40');
                $stLog->execute([$oc, (int) ($order['id'] ?? 0)]);
                $logs = $stLog->fetchAll() ?: [];
            } catch (Throwable) {
            }
            echo json_encode([
                'ok' => true,
                'code' => $oc,
                'id' => (int) ($order['id'] ?? 0),
                'status' => (string) ($order['status'] ?? ''),
                'pending_approval' => (int) ($order['pending_approval'] ?? 0),
                'bayt_approval' => (string) ($order['bayt_approval'] ?? ''),
                'payment' => (string) ($order['payment'] ?? ''),
                'payment_status' => (string) ($order['payment_status'] ?? ''),
                'payment_ref' => (string) ($order['payment_ref'] ?? ''),
                'bt_guid' => (string) ($order['bt_guid'] ?? ''),
                'bt_auth_code' => (string) ($order['bt_auth_code'] ?? ''),
                'user_id' => (int) ($order['user_id'] ?? 0),
                'company_id' => (int) ($order['company_id'] ?? 0),
                'total' => (float) ($order['total'] ?? 0),
                'note' => (string) ($order['note'] ?? ''),
                'created_at' => (string) ($order['created_at'] ?? ''),
                'bayt_order_id' => (int) ($order['bayt_order_id'] ?? 0),
                'bayt_id' => (int) ($order['bayt_id'] ?? 0),
                'bayt_status' => (string) ($order['bayt_status'] ?? ''),
                'bayt_phase' => (string) ($order['bayt_phase'] ?? ''),
                'bayt_error' => (string) ($order['bayt_error'] ?? ''),
                'bayt_collection_id' => (int) ($order['bayt_collection_id'] ?? 0),
                'bayt_collection_status' => (string) ($order['bayt_collection_status'] ?? ''),
                'auto_waybill' => HfsBayt::settings()['auto_waybill'] ?? '0',
                'bulut' => $bulut,
                'bayt_live' => $baytLive,
                'logs' => $logs,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'purgeorders') {
            if ($confirm !== 'TEMIZLE') {
                echo json_encode(['ok' => false, 'error' => 'confirm=TEMIZLE gerekli']);
                exit;
            }
            @set_time_limit(600);
            bmn_session_release();
            HfsMigrate::up();
            $stats = Catalog::purgeAllOrders();
            echo json_encode(['ok' => $stats['fail'] === 0, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'smtpconfigure') {
            @set_time_limit(120);
            bmn_session_release();
            $raw = (string) file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                echo json_encode(['ok' => false, 'error' => 'json body']);
                exit;
            }
            HfsMigrate::up();
            $applied = HfsAuthPassword::applySmtpFromPayload($data);
            if (empty($applied['ok'])) {
                echo json_encode($applied, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $to = trim((string) ($data['test_to'] ?? setting('email', '')));
            $mail = ['ok' => false, 'error' => 'test_to yok'];
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $html = Mailer::wrap('SMTP test', '<p>Bayi portalı SMTP yapılandırması başarılı.</p>');
                $mail = Mailer::send($to, (string) setting('site_name', 'HFS Tarım') . ' — SMTP test', $html);
            }
            echo json_encode(['ok' => !empty($mail['ok']), 'applied' => $applied, 'mail' => $mail], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'dealercredentials') {
            if ($confirm !== 'BASLAT') {
                echo json_encode(['ok' => false, 'error' => 'confirm=BASLAT gerekli']);
                exit;
            }
            // Toplu bayi bilgilendirme maili kapalı (yalnızca panel / şifremi-unuttum tek tek).
            $send = false;
            @set_time_limit(600);
            bmn_session_release();
            HfsMigrate::up();
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $limit = max(0, (int) ($_GET['limit'] ?? 0));
            $stats = HfsDealers::rolloutTaxCredentials($send, $offset, $limit);
            echo json_encode([
                'ok' => true,
                'sendmail' => $send,
                'stats' => $stats,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'orderbulutcancel') {
            $oc = strtoupper(trim((string) ($_GET['order'] ?? '')));
            $order = $oc !== '' ? Catalog::orderByCode($oc) : null;
            if (!$order) {
                echo json_encode(['ok' => false, 'error' => 'siparis yok']);
                exit;
            }
            bmn_session_release();
            $ok = HfsPay::requestBulutCancel($order, 'Manuel Bulut iptal talebi');
            echo json_encode(['ok' => $ok, 'bulut' => HfsPay::inspectOrderPayment($order)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'orderfix') {
            @set_time_limit(180);
            bmn_session_release();
            HfsMigrate::up();
            $oc = strtoupper(trim((string) ($_GET['order'] ?? '')));
            $order = $oc !== '' ? Catalog::orderByCode($oc) : null;
            if (!$order) {
                echo json_encode(['ok' => false, 'error' => 'siparis yok']);
                exit;
            }
            $oid = (int) $order['id'];
            $col = HfsBayt::postOrderBulutCollection($oid, true);
            $pipe = HfsBayt::ensureWaybillPipeline($oid, true);
            $edi = HfsBayt::repairEdiStageForOrder($oid);
            $fresh = Catalog::orderByCode($oc) ?: $order;
            echo json_encode([
                'ok' => $pipe === true,
                'collection' => $col,
                'waybill' => $pipe,
                'edi' => $edi,
                'bayt_order_id' => (int) ($fresh['bayt_order_id'] ?? 0),
                'bayt_id' => (int) ($fresh['bayt_id'] ?? 0),
                'bayt_phase' => (string) ($fresh['bayt_phase'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'probedi' || $code === 'stageedi') {
            @set_time_limit(120);
            bmn_session_release();
            $oc = strtoupper(trim((string) ($_GET['order'] ?? '')));
            if ($oc === '') {
                echo json_encode(['ok' => false, 'error' => 'order param']);
                exit;
            }
            $order = Catalog::orderByCode($oc);
            if (!$order) {
                echo json_encode(['ok' => false, 'error' => 'siparis yok']);
                exit;
            }
            $disp = trim((string) ($_GET['dispatch'] ?? ''));
            if ($disp !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $disp)) {
                try {
                    db()->prepare('UPDATE orders SET bayt_dispatch_at=? WHERE id=?')
                        ->execute([str_replace('T', ' ', $disp), (int) $order['id']]);
                    $order = Catalog::orderByCode($oc) ?: $order;
                } catch (Throwable) {
                }
            }
            echo json_encode(HfsBayt::repairEdiStageForOrder((int) $order['id']), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($code === 'eirsaliyepreview') {
            $oc = strtoupper(trim((string) ($_GET['order'] ?? '')));
            if ($oc === '') {
                echo json_encode(['ok' => false, 'error' => 'order param']);
                exit;
            }
            $order = Catalog::orderByCode($oc);
            if (!$order) {
                echo json_encode(['ok' => false, 'error' => 'siparis yok']);
                exit;
            }
            self::renderEirsaliyePreview($order, site_url('yonetim/siparisler/' . $order['code'] . '/'));
            exit;
        }
        if ($code === 'fixcari') {
            echo json_encode(HfsBayt::repairDealerDocs((string) ($_GET['user'] ?? 'bayi1')), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $order = Catalog::orderByCode($code);
        if (!$order) {
            echo json_encode(['ok' => false, 'error' => 'siparis yok', 'code' => $code], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $drop = (int) ($_GET['drop'] ?? 0);
        $dropped = null;
        if ($drop > 0) {
            $dropped = HfsBayt::deleteWaybillById($drop);
            db()->prepare("UPDATE orders SET bayt_status='pending', bayt_id=0, bayt_doc_type='', bayt_error=NULL WHERE id=?")
                ->execute([(int) $order['id']]);
            $order = Catalog::orderByCode($code) ?: $order;
        }
        $before = [
            'status' => (string) ($order['status'] ?? ''),
            'payment' => (string) ($order['payment'] ?? ''),
            'payment_status' => (string) ($order['payment_status'] ?? ''),
            'bayt_status' => (string) ($order['bayt_status'] ?? ''),
            'bayt_doc_type' => (string) ($order['bayt_doc_type'] ?? ''),
            'bayt_id' => (int) ($order['bayt_id'] ?? 0),
            'bayt_error' => (string) ($order['bayt_error'] ?? ''),
        ];
        $send = HfsBayt::send((int) $order['id'], true);
        $fresh = Catalog::orderByCode($code) ?: $order;
        echo json_encode([
            'ok' => $send === true,
            'send' => $send === true ? 'ok' : (string) $send,
            'dropped' => $dropped === true ? 'ok' : $dropped,
            'before' => $before,
            'after' => [
                'status' => (string) ($fresh['status'] ?? ''),
                'bayt_status' => (string) ($fresh['bayt_status'] ?? ''),
                'bayt_doc_type' => (string) ($fresh['bayt_doc_type'] ?? ''),
                'bayt_id' => (int) ($fresh['bayt_id'] ?? 0),
                'bayt_error' => (string) ($fresh['bayt_error'] ?? ''),
                'bayt_ref' => (string) ($fresh['bayt_ref'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function login(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        $u = current_user();
        if ($u) {
            redirect(site_url());
        }
        Front::view('login', [
            'bareLayout' => true,
            'err' => isset($_GET['hata']),
            'seo' => [
                'title' => 'Bayi girişi — HFS Tarım',
                'description' => 'HFS Tarım bayi paneli girişi.',
                'canonical' => site_url('giris/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function forgotPassword(): void
    {
        Front::view('forgot-password', [
            'bareLayout' => true,
            'msg' => trim((string) ($_GET['msg'] ?? '')),
            'err' => trim((string) ($_GET['err'] ?? '')),
            'seo' => [
                'title' => 'Şifremi unuttum — HFS Tarım',
                'noindex' => true,
                'canonical' => site_url('sifremi-unuttum/'),
            ],
        ], ['no_cache' => true]);
    }

    private static function forgotPasswordPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url('sifremi-unuttum/') . '?err=' . rawurlencode('Oturum doğrulanamadı.'));
        }
        $res = HfsAuthPassword::requestReset(trim((string) ($_POST['user'] ?? '')));
        $q = $res['ok'] ? 'msg' : 'err';
        redirect(site_url('sifremi-unuttum/') . '?' . $q . '=' . rawurlencode($res['message']));
    }

    private static function resetPassword(): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $check = HfsAuthPassword::userForToken($token);
        Front::view('reset-password', [
            'bareLayout' => true,
            'token' => $token,
            'valid' => $check['ok'],
            'err' => $check['error'],
            'seo' => ['title' => 'Yeni şifre — HFS Tarım', 'noindex' => true],
        ], ['no_cache' => true]);
    }

    private static function resetPasswordPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url('giris/') . '?hata=1');
        }
        $token = trim((string) ($_POST['token'] ?? ''));
        $err = HfsAuthPassword::completeReset(
            $token,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? '')
        );
        if ($err !== null) {
            redirect(site_url('sifre-sifirla/') . '?token=' . rawurlencode($token) . '&err=' . rawurlencode($err));
        }
        redirect(site_url('giris/') . '?ok=sifre');
    }

    private static function forcePassword(): void
    {
        $u = current_user();
        if (!$u) {
            redirect(site_url('giris/'));
        }
        if ((int) ($u['must_change_password'] ?? 0) !== 1) {
            redirect(site_url());
        }
        Front::view('force-password', [
            'bareLayout' => true,
            'err' => trim((string) ($_GET['err'] ?? '')),
            'seo' => ['title' => 'Yeni şifre belirleyin — HFS Tarım', 'noindex' => true],
        ], ['no_cache' => true]);
    }

    private static function forcePasswordPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url('sifre-belirle/') . '?err=' . rawurlencode('Oturum doğrulanamadı.'));
        }
        $u = current_user();
        if (!$u) {
            redirect(site_url('giris/'));
        }
        $err = HfsAuthPassword::forceChange(
            (int) ($u['id'] ?? 0),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? '')
        );
        if ($err !== null) {
            redirect(site_url('sifre-belirle/') . '?err=' . rawurlencode($err));
        }
        redirect(site_url());
    }

    private static function loginPost(): void
    {
        try {
            if (!csrf_ok()) {
                redirect(site_url('giris/') . '?hata=1');
            }
            $remember = !empty($_POST['remember']);
            $ok = Auth::attempt(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['pass'] ?? ''), $remember);
            if (!$ok) {
                redirect(site_url('giris/') . '?hata=1');
            }
            $GLOBALS['bmn_user'] = Auth::user();
            $u = current_user();
            if ($u && in_array((string) ($u['role'] ?? ''), ['admin', 'editor'], true) && !empty($_POST['yonetim'])) {
                redirect(site_url('yonetim/'));
            }
            if ($u && (string) ($u['role'] ?? '') === 'dealer' && (int) ($u['must_change_password'] ?? 0) === 1) {
                redirect(site_url('sifre-belirle/'));
            }
            redirect(site_url());
        } catch (Throwable $e) {
            error_log('HFS login: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
            }
            echo '<!DOCTYPE html><meta charset="utf-8"><title>Hata</title><p>Giriş tamamlanamadı.</p><pre>'
                . h($e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine())
                . '</pre>';
            exit;
        }
    }

    private static function account(): void
    {
        if (!empty($_GET['tekrar'])) {
            HfsQuick::reorder((int) $_GET['tekrar']);
            redirect(site_url('sepet/'));
        }
        $u = current_user();
        $snap = HfsFinance::snapshot($u);
        $baytBal = HfsBayt::customerBalanceForUser($u);
        Front::view('account', [
            'u' => $u,
            'snap' => $snap,
            'baytBal' => $baytBal,
            'canApprove' => HfsApproval::canApprove($u),
            'pendingN' => HfsApproval::canApprove($u) ? count(HfsApproval::pendingFor($u)) : 0,
            'seo' => [
                'title' => 'Hesabım — HFS Tarım Bayi',
                'canonical' => site_url('hesabim/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function kargo(): void
    {
        $u = current_user();
        $snap = HfsFinance::snapshot($u);
        $cid = (int) ($snap['company_id'] ?? 0);
        $raw = $cid > 0 ? HfsFinance::recentOrders($cid, 50) : [];
        $orders = [];
        foreach ($raw as $o) {
            if ((int) ($o['is_cari'] ?? 0) === 1 || ($o['status'] ?? '') === 'odeme') {
                continue;
            }
            $orders[] = $o;
        }
        Front::view('kargo', [
            'orders' => $orders,
            'seo' => [
                'title' => 'Kargo takibi — HFS Tarım Bayi',
                'canonical' => site_url('hesabim/kargo/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function ekstre(): void
    {
        try {
            HfsBayt::syncPendingApprovalsForUser(current_user());
        } catch (Throwable) {
        }
        [$from, $to] = HfsFinance::statementDates();
        $u = current_user();
        $baytBal = HfsBayt::customerBalanceForUser($u);
        $st = HfsFinance::statement($u, $from, $to, $baytBal);
        Front::view('ekstre', [
            'st' => $st,
            'baytBal' => $baytBal,
            'seo' => [
                'title' => 'Cari ekstre — HFS Tarım Bayi',
                'canonical' => site_url('hesabim/ekstre/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function ekstrePrint(): void
    {
        try {
            HfsBayt::syncPendingApprovalsForUser(current_user());
        } catch (Throwable) {
        }
        [$from, $to] = HfsFinance::statementDates();
        $u = current_user();
        $baytBal = HfsBayt::customerBalanceForUser($u);
        $st = HfsFinance::statement($u, $from, $to, $baytBal);
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        $back = site_url('hesabim/ekstre/') . '?bas=' . rawurlencode($from) . '&bit=' . rawurlencode($to);
        require BMN_ROOT . '/themes/shop/ekstre-print.php';
        exit;
    }

    private static function dealerSlip(string $code): void
    {
        $order = Catalog::orderByCode($code);
        if (!$order || !Catalog::dealerCanSeeOrder($order)) {
            Front::view('404', [
                'seo' => ['title' => 'Sipariş yok', 'noindex' => true],
            ]);
            return;
        }
        header('X-Robots-Tag: noindex, nofollow');
        $back = site_url('siparis/' . $order['code'] . '/');
        require BMN_ROOT . '/yonetim/views/packing-slip.php';
        exit;
    }

    private static function dealerEirsaliyePreview(string $code): void
    {
        $order = Catalog::orderByCode($code);
        if (!$order || !Catalog::dealerCanSeeOrder($order)) {
            Front::view('404', [
                'seo' => ['title' => 'Sipariş yok', 'noindex' => true],
            ]);
            return;
        }
        self::renderEirsaliyePreview($order, site_url('siparis/' . $order['code'] . '/'));
    }

    /** @param array<string,mixed> $order */
    private static function renderEirsaliyePreview(array $order, string $back): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        $preview = HfsBayt::eirsaliyePreview($order);
        require BMN_ROOT . '/yonetim/views/eirsaliye-preview.php';
        exit;
    }

    private static function cariPay(): void
    {
        $u = current_user();
        $snap = HfsFinance::snapshot($u);
        $baytBal = HfsBayt::customerBalanceForUser($u);
        $lastCariPay = null;
        if (!empty($_GET['ok'])) {
            $cid = (int) ($snap['company_id'] ?? $u['id'] ?? 0);
            $uid = (int) ($u['id'] ?? 0);
            $st = db()->prepare("SELECT * FROM orders WHERE payment='bulut' AND payment_status='odendi' AND is_cari=1 AND (company_id=? OR user_id=?) ORDER BY id DESC LIMIT 1");
            $st->execute([$cid > 0 ? $cid : $uid, $uid]);
            $lastCariPay = $st->fetch() ?: null;
            if ($lastCariPay) {
                try {
                    HfsPay::enrichOrderDekont($lastCariPay, trim((string) ($lastCariPay['bt_dekont_url'] ?? '')) === '');
                    $lastCariPay = Catalog::orderByCode((string) $lastCariPay['code']) ?: $lastCariPay;
                } catch (Throwable) {
                }
            }
        }
        Front::view('cari', [
            'snap' => $snap,
            'baytBal' => $baytBal,
            'err' => (string) ($_GET['hata'] ?? ''),
            'lastCariPay' => $lastCariPay,
            'seo' => [
                'title' => 'Cari ödeme — HFS Tarım Bayi',
                'canonical' => site_url('cari-odeme/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function favorites(): void
    {
        $u = current_user();
        $st = db()->prepare("SELECT p.* FROM products p INNER JOIN favorites f ON f.product_id=p.id WHERE f.user_id=? AND p.status='publish' ORDER BY f.created_at DESC");
        $st->execute([(int) $u['id']]);
        Front::view('favorites', [
            'items' => HfsPricing::decorateMany($st->fetchAll()),
            'seo' => [
                'title' => 'Favoriler — HFS Tarım Bayi',
                'canonical' => site_url('favoriler/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function favPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url('favoriler/'));
        }
        $u = current_user();
        $pid = (int) ($_POST['product_id'] ?? 0);
        if ($pid > 0) {
            $st = db()->prepare('SELECT product_id FROM favorites WHERE user_id=? AND product_id=?');
            $st->execute([(int) $u['id'], $pid]);
            if ($st->fetch()) {
                db()->prepare('DELETE FROM favorites WHERE user_id=? AND product_id=?')->execute([(int) $u['id'], $pid]);
            } else {
                db()->prepare('INSERT IGNORE INTO favorites (user_id,product_id,created_at) VALUES (?,?,NOW())')->execute([(int) $u['id'], $pid]);
            }
        }
        $back = cart_return_url();
        redirect($back !== '' ? $back : site_url('favoriler/'));
    }

    private static function quickOrder(): void
    {
        Front::view('quick-order', [
            'templates' => HfsQuick::templates(),
            'seo' => [
                'title' => 'Hızlı sipariş — HFS Tarım Bayi',
                'canonical' => site_url('hizli-siparis/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function quickPost(): void
    {
        if (HfsQuick::handleAjax()) {
            return;
        }
        redirect(site_url('hizli-siparis/'));
    }

    private static function approvals(): void
    {
        Front::view('approvals', [
            'can' => HfsApproval::canApprove(),
            'items' => HfsApproval::canApprove() ? HfsApproval::pendingFor() : [],
            'seo' => [
                'title' => 'Sipariş onayları — HFS Tarım Bayi',
                'canonical' => site_url('siparis-onaylari/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function approvePost(): void
    {
        if (csrf_ok()) {
            HfsApproval::act((int) ($_POST['order_id'] ?? 0), (string) ($_POST['act'] ?? ''));
        }
        redirect(site_url('siparis-onaylari/'));
    }
}

function hfs_uploads_remote(): string
{
    return hfs_uploads_base_url();
}

function hfs_media_rel(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        $stripped = (string) preg_replace('#^https?://[^/]+/(?:hfs-tarim/)?(?:wp-content/uploads|uploads)/#i', '', $path);
        if (preg_match('#^https?://#i', $stripped)) {
            return $path;
        }
        $path = $stripped;
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $path = (string) preg_replace('#^(?:hfs-tarim/)?(?:wp-content/uploads|uploads)/#', '', $path);
    return $path;
}

function hfs_media_encode(string $rel): string
{
    $out = [];
    foreach (explode('/', str_replace('\\', '/', $rel)) as $p) {
        if ($p === '') {
            continue;
        }
        $out[] = str_contains($p, '%') ? $p : rawurlencode($p);
    }
    return implode('/', $out);
}

function hfs_media_find_upload(string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return '';
    }
    $abs = BMN_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($abs)) {
        return $abs;
    }
    $dir = dirname($abs);
    if (!is_dir($dir)) {
        return '';
    }
    $want = strtolower(basename($rel));
    $alts = [$want];
    if (preg_match('/\.jpe?g$/i', $want)) {
        $alts[] = (string) preg_replace('/\.jpe?g$/i', '.jpg', $want);
        $alts[] = (string) preg_replace('/\.jpe?g$/i', '.jpeg', $want);
        $alts[] = (string) preg_replace('/\.jpe?g$/i', '.png', $want);
    }
    $alts = array_unique($alts);
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (in_array(strtolower($f), $alts, true)) {
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_file($p)) {
                return $p;
            }
        }
    }
    return '';
}

function hfs_media(?string $path): string
{
    $rel = hfs_media_rel($path);
    if ($rel === '') {
        return shop_og();
    }
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $hit = hfs_media_find_upload($rel);
    if ($hit !== '') {
        $from = BMN_ROOT . DIRECTORY_SEPARATOR;
        $urlRel = ltrim(str_replace('\\', '/', substr($hit, strlen($from))), '/');
        return site_url($urlRel);
    }
    $enc = hfs_media_encode($rel);
    return hfs_uploads_base_url() . '/' . $enc;
}

function hfs_media_path_matches_sku(string $rel, string $sku): bool
{
    $sku = strtoupper(trim($sku));
    $rel = trim($rel);
    if ($sku === '' || $rel === '') {
        return false;
    }
    $base = strtoupper(pathinfo(str_replace('\\', '/', $rel), PATHINFO_FILENAME));
    if (str_contains($base, $sku)) {
        return true;
    }
    if (preg_match('/-(\d+)-([ABC])$/i', $sku, $m)) {
        $frag = $m[1] . '-' . strtoupper($m[2]);
        if (str_contains($base, $frag) || str_contains($base, $m[1] . strtoupper($m[2]))) {
            return (bool) preg_match('/HFS[-A-Z0-9]*-' . preg_quote($m[1], '/') . '[-_]?[' . preg_quote($m[2], '/') . ']/i', $base);
        }
    }
    return false;
}

/** @return array<string, string> uppercase SKU => uploads rel */
function hfs_media_sku_index(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $file = BMN_ROOT . '/data/cache/media-by-sku.json';
    if (is_file($file) && filemtime($file) > time() - 86400) {
        $dec = json_decode((string) file_get_contents($file), true);
        $cache = is_array($dec) ? $dec : [];
        return $cache;
    }
    $index = [];
    try {
        $rows = db()->query("SELECT sku, featured_media FROM products WHERE sku<>'' AND featured_media<>''")->fetchAll();
        foreach ($rows as $r) {
            $sku = strtoupper(trim((string) $r['sku']));
            $rel = hfs_media_rel((string) $r['featured_media']);
            if ($sku !== '' && $rel !== '' && hfs_media_path_matches_sku($rel, $sku)) {
                $index[$sku] = $rel;
            }
        }
        $st = db()->query("SELECT p.sku, pm.meta_value FROM products p INNER JOIN product_meta pm ON pm.product_id=p.id AND pm.meta_key='gallery' WHERE p.sku<>''");
        foreach ($st->fetchAll() as $r) {
            $sku = strtoupper(trim((string) $r['sku']));
            $g = json_decode((string) $r['meta_value'], true);
            if (!is_array($g)) {
                continue;
            }
            foreach ($g as $path) {
                $rel = hfs_media_rel((string) $path);
                if ($sku !== '' && $rel !== '' && hfs_media_path_matches_sku($rel, $sku)) {
                    $index[$sku] = $rel;
                }
            }
        }
    } catch (Throwable) {
    }
    foreach ([dirname(BMN_ROOT) . '/wp-content/uploads', BMN_ROOT . '/uploads'] as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        foreach (glob($root . '/20*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [] as $abs) {
                if (!is_file($abs)) {
                    continue;
                }
                $base = pathinfo($abs, PATHINFO_FILENAME);
                if (!preg_match('/^(HFS-[A-Z0-9-]+-\d+-[ABC])/i', $base, $m)) {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr(str_replace('\\', '/', $abs), strlen($root) + 1)), '/');
                $index[strtoupper($m[1])] = $rel;
            }
        }
    }
    $cache = $index;
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
    }
    @file_put_contents($file, json_encode($index, JSON_UNESCAPED_UNICODE));
    return $cache;
}

function hfs_media_rel_discover_by_sku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    if ($sku === '') {
        return '';
    }
    $index = hfs_media_sku_index();
    if (isset($index[$sku])) {
        return $index[$sku];
    }
    return '';
}

function hfs_product_image_rel(array $p): string
{
    $sku = trim((string) ($p['sku'] ?? ''));
    $paths = hfs_product_images($p);
    $best = '';
    $bestScore = -1;
    foreach ($paths as $rel) {
        $rel = trim((string) $rel);
        if ($rel === '') {
            continue;
        }
        if ($sku === '') {
            return $rel;
        }
        $score = 0;
        $base = strtoupper(pathinfo(str_replace('\\', '/', $rel), PATHINFO_FILENAME));
        $skuU = strtoupper($sku);
        if ($base === $skuU || str_starts_with($base, $skuU . '-') || str_starts_with($base, $skuU . '.')) {
            $score = 1000;
        } elseif (str_contains($base, $skuU)) {
            $score = 500;
        } elseif (hfs_media_path_matches_sku($rel, $sku)) {
            $score = 200;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $rel;
        }
    }
    if ($bestScore >= 200) {
        return $best;
    }
    $disc = hfs_media_rel_discover_by_sku($sku);
    if ($disc !== '') {
        return $disc;
    }
    return $best !== '' ? $best : trim((string) ($p['featured_media'] ?? ''));
}

function hfs_product_image_url(array $p): string
{
    return hfs_media(hfs_product_image_rel($p));
}

function hfs_product_thumb(array $p): string
{
    $rel = hfs_product_image_rel($p);
    if ($rel !== '') {
        return hfs_media($rel);
    }
    if (!empty($p['has_diagram']) && class_exists('HfsDiagram')) {
        $dimg = HfsDiagram::meta((int) ($p['id'] ?? 0), 'diagram_image', '');
        if (is_string($dimg) && trim($dimg) !== '') {
            return hfs_media($dimg);
        }
    }
    foreach (hfs_product_images($p) as $src) {
        $src = trim((string) $src);
        if ($src !== '') {
            return hfs_media($src);
        }
    }
    return shop_og();
}

/** @return list<string> */
function hfs_product_images(array $p): array
{
    $out = [];
    $feat = trim((string) ($p['featured_media'] ?? ''));
    if ($feat !== '') {
        $out[] = $feat;
    }
    try {
        $st = db()->prepare("SELECT meta_value FROM product_meta WHERE product_id=? AND meta_key='gallery' LIMIT 1");
        $st->execute([(int) ($p['id'] ?? 0)]);
        $raw = $st->fetchColumn();
        $g = is_string($raw) ? json_decode($raw, true) : [];
        if (is_array($g)) {
            foreach ($g as $src) {
                $src = trim((string) $src);
                if ($src !== '' && !in_array($src, $out, true)) {
                    $out[] = $src;
                }
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

function hfs_is_fav(int $productId): bool
{
    $u = current_user();
    if (!$u || $productId < 1) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM favorites WHERE user_id=? AND product_id=? LIMIT 1');
    $st->execute([(int) $u['id'], $productId]);
    return (bool) $st->fetchColumn();
}
