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
            '/giris/', '/cikis/', '/sifremi-unuttum/', '/sifre-sifirla/', '/odeme/donus/', '/bulut-bildirim/', '/hfs-bt-watch/', '/hfs-sync/', '/hfs-media-pull/', '/robots.txt/', '/sitemap.xml/',
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
