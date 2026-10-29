<?php
declare(strict_types=1);

function h(mixed $s): string
{
    if ($s === null) {
        return '';
    }
    if (is_bool($s)) {
        return $s ? '1' : '';
    }
    if (!is_scalar($s)) {
        return '';
    }
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(string $key, mixed $default = ''): mixed
{
    if (!isset($GLOBALS['bmn_settings']) || !is_array($GLOBALS['bmn_settings'])) {
        $GLOBALS['bmn_settings'] = [];
        try {
            $rows = db()->query('SELECT k, v FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $GLOBALS['bmn_settings'][$row['k']] = $row['v'];
            }
        } catch (Throwable) {
            return $default;
        }
    }
    return $GLOBALS['bmn_settings'][$key] ?? $default;
}

function setting_set(string $key, mixed $value): void
{
    $store = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $store]);
    if (!isset($GLOBALS['bmn_settings']) || !is_array($GLOBALS['bmn_settings'])) {
        $GLOBALS['bmn_settings'] = [];
    }
    $GLOBALS['bmn_settings'][$key] = $store;
}

function bmn_request_origin(): ?string
{
    if (PHP_SAPI === 'cli') {
        return null;
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host)) {
        return null;
    }
    $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $fwd === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    return ($https ? 'https' : 'http') . '://' . $host;
}

function bmn_heal_live_urls(): void
{
    $origin = bmn_request_origin();
    if ($origin === null) {
        return;
    }
    $stored = rtrim((string) setting('site_url', ''), '/');
    $storedHost = (string) (parse_url($stored, PHP_URL_HOST) ?: '');
    if ($stored !== '' && !preg_match('/^(localhost|127\.0\.0\.1)$/i', $storedHost)) {
        return;
    }
    setting_set('site_url', $origin);
}

function base_path(): string
{
    $base = rtrim((string) (defined('BMN_BASE') ? BMN_BASE : ''), '/');
    if ($base === '') {
        return '';
    }
    $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($uri !== $base && !str_starts_with($uri, $base . '/') && !str_starts_with($script, $base . '/')) {
        return '';
    }
    return $base;
}

function site_url(string $path = ''): string
{
    $base = rtrim((string) setting('site_url', 'http://localhost/hfs-tarim/yeni-site'), '/');
    $live = bmn_request_origin();
    if ($live !== null) {
        $storedHost = (string) (parse_url($base, PHP_URL_HOST) ?: '');
        if ($storedHost === '' || preg_match('/^(localhost|127\.0\.0\.1)$/i', $storedHost)) {
            $base = $live;
        }
    }
    $path = ltrim($path, '/');
    return $path === '' ? $base . '/' : $base . '/' . $path;
}

function shop_logo(): string
{
    $png = BMN_ROOT . '/themes/shop/assets/logo.png';
    if (is_file($png)) {
        return asset('logo.png');
    }
    return hfs_media('2026/06/hfs-logo.png');
}

function shop_logo_abs(): string
{
    $url = shop_logo();
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    return Seo::absolute($url);
}

function shop_og(): string
{
    $png = BMN_ROOT . '/themes/shop/assets/logo.png';
    if (is_file($png)) {
        return asset('logo.png');
    }
    return asset('logo.svg');
}

/** Stok 0 olsa da sepete ekle / sipariş (B2B arka sipariş). */
function shop_allow_backorder(): bool
{
    return true;
}

function live_host(): string
{
    return (string) ($GLOBALS['bmn_config']['live_host'] ?? 'b2b.hfstarim.com');
}

function media_host(): string
{
    return (string) ($GLOBALS['bmn_config']['media_host'] ?? 'b2b.hfstarim.com');
}

function hfs_uploads_base_url(): string
{
    $base = trim((string) setting('media_base', ''));
    if (
        $base !== ''
        && preg_match('#^https://#i', $base)
        && !preg_match('#localhost|127\.0\.0\.1#i', $base)
        && !str_contains($base, 'bayi.hfstarim.com/wp-content')
    ) {
        return rtrim($base, '/');
    }
    return rtrim(site_url('uploads'), '/');
}

function live_origin(): string
{
    return 'https://' . live_host();
}

function asset(string $path): string
{
    $rel = 'themes/shop/assets/' . ltrim($path, '/');
    $url = site_url($rel);
    $file = BMN_ROOT . '/' . $rel;
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }
    return $url;
}

function permalink_product(array $p): string
{
    return site_url('urun/' . $p['slug'] . '/');
}

function permalink_category(array $c): string
{
    $path = ($c['path'] ?? '') !== '' ? $c['path'] : $c['slug'];
    return site_url('kategori/' . trim((string) $path, '/') . '/');
}

function permalink_page(array $p): string
{
    return site_url($p['slug'] . '/');
}

function permalink_post(array $p): string
{
    return site_url('yazi/' . $p['slug'] . '/');
}

function excerpt_plain(?string $html, int $len = 160): string
{
    $t = trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8'));
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    if (mb_strlen($t) <= $len) {
        return $t;
    }
    return rtrim(mb_substr($t, 0, $len - 1)) . '…';
}

function money(int|float|string|null $n): string
{
    return number_format((float) $n, 2, ',', '.') . ' ₺';
}

/** Türkçe arama için I/İ/ı katlama (ASCII başlıklar ↔ Türkçe sorgu). */
function hfs_tr_fold(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
    ]);
}

/** Pozitif borç (B), negatif alacak (A) — Bayt ekstresi ile aynı. */
function hfs_signed_balance_display(float $b): string
{
    if (abs($b) < 0.005) {
        return money(0);
    }
    return money(abs($b)) . ($b > 0 ? ' B' : ' A');
}

/** Bayt GetCustomerById Balance: pozitif borç (B), negatif alacak (A). */
function hfs_bayt_balance_display(array $baytBal): string
{
    if (empty($baytBal['ok'])) {
        return '—';
    }
    return hfs_signed_balance_display((float) ($baytBal['balance'] ?? 0));
}

function hfs_order_status(string $status): string
{
    return match ($status) {
        'pending_b2b' => 'Onay bekliyor',
        'odeme' => 'Ödeme bekleniyor',
        'bekliyor' => 'Açık hesap',
        'yeni' => 'Yeni',
        'hazirlaniyor' => 'Hazırlanıyor',
        'kargoda' => 'Kargoda',
        'tamam' => 'Tamamlandı',
        'iptal' => 'İptal',
        'muhasebe' => 'Muhasebe onayı bekliyor',
        default => $status,
    };
}

/** @param array<string,mixed> $order */
function hfs_order_status_for(array $order): string
{
    $st = (string) ($order['status'] ?? '');
    $ap = (string) ($order['bayt_approval'] ?? '');
    $bayt = (string) ($order['bayt_status'] ?? '');
    if ($st === 'iptal' || $ap === 'cancelled') {
        return 'İptal';
    }
    // Kart 331 vb. reddedildiyse status hâlâ "odeme" kalır — bekliyor gibi gösterme
    $ps = trim((string) ($order['payment_status'] ?? ''));
    if ((string) ($order['payment'] ?? '') === 'bulut' && str_starts_with($ps, 'hata')) {
        $fail = class_exists('HfsPay') ? HfsPay::paymentStatusLabel($order) : $ps;
        $fail = preg_replace('/^hata:\s*/iu', '', (string) $fail) ?? $fail;
        $fail = trim((string) $fail);
        return $fail !== '' ? ('Ödeme alınamadı · ' . mb_substr($fail, 0, 90)) : 'Ödeme alınamadı';
    }
    if ($ap === 'approved') {
        if (in_array($st, ['hazirlaniyor', 'kargoda'], true)) {
            return 'Onaylandı · ' . hfs_order_status($st);
        }
        return 'Onaylandı';
    }
    $phase = (string) ($order['bayt_phase'] ?? '');
    if ($phase === 'order_sent' && $bayt !== 'sent') {
        return 'Bayt siparişi gönderildi · sevk/irsaliye bekliyor';
    }
    if ($phase === 'waybill_created' || ($bayt === 'sent' && $phase !== 'edi_api_ok' && $phase !== 'edi_list_verified')) {
        if (in_array($phase, ['edi_manual_required', 'edi_probe'], true)) {
            return 'İrsaliye oluştu · e-İrsaliye için BYTE’de manuel adım';
        }
        if (in_array($phase, ['edi_ready', 'edi_staged', 'edi_api_ok'], true)) {
            return $phase === 'edi_ready'
                ? 'e-İrsaliye gönderme listesi adayı (BYTE’de fiş no ile doğrulayın)'
                : 'İrsaliye oluştu · e-İrsaliye alanları güncellendi';
        }
    }
    if ($bayt === 'sent' && !in_array($st, ['hazirlaniyor', 'kargoda', 'tamam'], true)) {
        return 'Muhasebe onayı bekliyor';
    }
    return hfs_order_status($st);
}

/** @param array<string,mixed> $order */
function hfs_order_badge(array $order): string
{
    $st = (string) ($order['status'] ?? '');
    $ap = (string) ($order['bayt_approval'] ?? '');
    if ($st === 'iptal' || $ap === 'cancelled') {
        return 'iptal';
    }
    $ps = trim((string) ($order['payment_status'] ?? ''));
    if ((string) ($order['payment'] ?? '') === 'bulut' && str_starts_with($ps, 'hata')) {
        return 'iptal';
    }
    if ($ap === 'approved') {
        return 'tamam';
    }
    if (($order['bayt_status'] ?? '') === 'sent') {
        return 'bekliyor';
    }
    return $st !== '' ? $st : 'yeni';
}

/**
 * Sipariş listesi: BulutTahsilat’a gitti mi?
 *
 * @param array<string,mixed> $order
 * @return array{label:string,class:string,title:string}
 */
function hfs_order_bulut_sync(array $order): array
{
    if ((string) ($order['payment'] ?? '') !== 'bulut') {
        return ['label' => '—', 'class' => 'sync-na', 'title' => 'Kart (Bulut) değil'];
    }
    $ps = trim((string) ($order['payment_status'] ?? ''));
    $guid = trim((string) ($order['bt_guid'] ?? ''));
    if ($ps === 'odendi') {
        return ['label' => 'Gitti', 'class' => 'sync-ok', 'title' => 'Bulut ödeme alındı' . ($guid !== '' ? ' · ' . $guid : '')];
    }
    if (str_starts_with($ps, 'hata')) {
        return ['label' => 'Hata', 'class' => 'sync-err', 'title' => HfsPay::paymentStatusLabel($order) ?: $ps];
    }
    if ($guid !== '') {
        return ['label' => 'Bekliyor', 'class' => 'sync-wait', 'title' => 'Bulut sayfası açıldı · ödeme bekleniyor'];
    }
    return ['label' => 'Gitmedi', 'class' => 'sync-no', 'title' => 'Bulut’a henüz gönderilmedi'];
}

/**
 * Sipariş listesi: Bayt’a gitti mi? (sipariş/irsaliye veya cari tahsilat)
 *
 * @param array<string,mixed> $order
 * @return array{label:string,class:string,title:string}
 */
function hfs_order_bayt_sync(array $order): array
{
    $st = (string) ($order['status'] ?? '');
    if ($st === 'iptal' || (string) ($order['bayt_approval'] ?? '') === 'cancelled') {
        if ((string) ($order['bayt_status'] ?? '') === 'deleted'
            || (string) ($order['bayt_collection_status'] ?? '') === 'deleted') {
            return ['label' => 'Silindi', 'class' => 'sync-na', 'title' => 'Bayt kaydı iptal/silindi'];
        }
    }
    if ((int) ($order['is_cari'] ?? 0) === 1) {
        $cs = trim((string) ($order['bayt_collection_status'] ?? ''));
        if ($cs === '' && trim((string) ($order['ledger_bayt_status'] ?? '')) !== '') {
            $cs = trim((string) $order['ledger_bayt_status']);
        }
        $cid = (int) ($order['bayt_collection_id'] ?? 0);
        if ($cid < 1) {
            $cid = (int) ($order['ledger_bayt_id'] ?? 0);
        }
        if ($cs === 'sent' || ($cid > 0 && $cs !== 'error')) {
            return ['label' => 'Gitti', 'class' => 'sync-ok', 'title' => 'Cari tahsilat Bayt’ta' . ($cid > 0 ? ' #' . $cid : '')];
        }
        if ($cs === 'error') {
            return ['label' => 'Hata', 'class' => 'sync-err', 'title' => trim((string) ($order['bayt_error'] ?? $order['ledger_bayt_error'] ?? 'Bayt tahsilat hatası'))];
        }
        if ($cs === 'pending' || $cs === 'reconcile') {
            return ['label' => 'Bekliyor', 'class' => 'sync-wait', 'title' => $cs === 'reconcile' ? 'Bayt doğrulama bekliyor' : 'Bayt tahsilat bekliyor'];
        }
        if (trim((string) ($order['payment_status'] ?? '')) === 'odendi') {
            return ['label' => 'Gitmedi', 'class' => 'sync-no', 'title' => 'Ödendi ama Bayt tahsilatı yok'];
        }
        return ['label' => '—', 'class' => 'sync-na', 'title' => 'Cari tahsilat henüz yok'];
    }
    $bs = trim((string) ($order['bayt_status'] ?? ''));
    $oid = (int) ($order['bayt_order_id'] ?? 0);
    $wid = (int) ($order['bayt_id'] ?? 0);
    $phase = trim((string) ($order['bayt_phase'] ?? ''));
    if ($bs === 'sent' || $oid > 0 || $wid > 0 || $phase === 'order_sent' || str_starts_with($phase, 'waybill') || str_starts_with($phase, 'edi_')) {
        $bits = [];
        if ($oid > 0) {
            $bits[] = 'sipariş #' . $oid;
        }
        if ($wid > 0) {
            $bits[] = 'irsaliye #' . $wid;
        }
        if ($phase !== '') {
            $bits[] = $phase;
        }
        return ['label' => 'Gitti', 'class' => 'sync-ok', 'title' => $bits !== [] ? implode(' · ', $bits) : 'Bayt’a gönderildi'];
    }
    if ($bs === 'error') {
        return ['label' => 'Hata', 'class' => 'sync-err', 'title' => trim((string) ($order['bayt_error'] ?? 'Bayt hatası'))];
    }
    if (in_array($st, ['odeme', 'bekliyor', 'pending_b2b'], true)
        && trim((string) ($order['payment_status'] ?? '')) !== 'odendi'
        && (string) ($order['payment'] ?? '') !== 'acik_hesap') {
        return ['label' => '—', 'class' => 'sync-na', 'title' => 'Ödeme/onay sonrası Bayt'];
    }
    if ($st === 'iptal') {
        return ['label' => '—', 'class' => 'sync-na', 'title' => 'İptal'];
    }
    if ($bs === 'pending' || $phase !== '') {
        return ['label' => 'Bekliyor', 'class' => 'sync-wait', 'title' => $phase !== '' ? $phase : 'Bayt bekliyor'];
    }
    return ['label' => 'Gitmedi', 'class' => 'sync-no', 'title' => 'Bayt’a henüz gönderilmedi'];
}

function hfs_yurtici_track_url(string $no): string
{
    $no = trim($no);
    if ($no === '') {
        return 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula';
    }
    return 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . rawurlencode($no);
}

/** @return list<array{name:string,href:string}> */
function hfs_track_carriers(string $no): array
{
    // B2B sevkiyatlar Yurtiçi (alıcı ödemeli); panel/mailde tek takip hedefi.
    return [
        ['name' => 'Yurtiçi Kargo', 'href' => hfs_yurtici_track_url($no)],
    ];
}

function hfs_track_note(array $order): string
{
    $st = (string) ($order['status'] ?? '');
    $ap = (string) ($order['bayt_approval'] ?? '');
    if ($st === 'iptal' || $ap === 'cancelled') {
        return 'Bu sipariş iptal.';
    }
    if ($ap !== 'approved' && ($order['bayt_status'] ?? '') === 'sent' && !in_array($st, ['kargoda', 'hazirlaniyor', 'tamam'], true)) {
        return 'Muhasebe onayı bekleniyor. Onaylanınca hazırlanır; takip no yüklendiğinde burada görünür.';
    }
    return match ($st) {
        'kargoda' => 'Sipariş kargoda; takip numarası henüz işlenmedi.',
        'tamam' => 'Teslim edildi. Takip numarası girilmemiş.',
        'hazirlaniyor', 'yeni' => 'Sipariş hazırlanıyor. Takip no yüklendiğinde burada görünür.',
        'bekliyor' => 'Açık hesap siparişi. Sevk edilince takip numarası düşer.',
        'pending_b2b' => 'Onay bekliyor; henüz kargoya çıkmaz.',
        'odeme' => 'Ödeme tamamlanınca hazırlanır.',
        default => 'Henüz kargoya verilmedi.',
    };
}

function slugify(string $s): string
{
    $map = ['ş'=>'s','Ş'=>'s','ı'=>'i','I'=>'i','İ'=>'i','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c'];
    $s = strtr($s, $map);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return trim($s, '-') ?: 'urun';
}

function bmn_csrf_key(): string
{
    $cfg = $GLOBALS['bmn_config'] ?? [];
    $key = (string) ($cfg['cron_key'] ?? $cfg['csrf_key'] ?? '');
    if ($key === '') {
        $key = (string) ($cfg['db']['name'] ?? 'bmn') . '|' . (string) ($cfg['db']['pass'] ?? '');
    }
    return $key;
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['_csrf'];
    }
    return hash_hmac('sha256', 'bmn-csrf|' . gmdate('Y-m-d'), bmn_csrf_key());
}

function csrf_day_token(int $ago = 0): string
{
    $day = gmdate('Y-m-d', time() - ($ago * 86400));
    return hash_hmac('sha256', 'bmn-csrf|' . $day, bmn_csrf_key());
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="_csrf_day" value="' . h(csrf_day_token()) . '">';
}

function csrf_ok(): bool
{
    $candidates = [
        $_POST['_csrf'] ?? null,
        $_POST['_csrf_day'] ?? null,
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
    ];
    $key = bmn_csrf_key();
    foreach ($candidates as $t) {
        if (!is_string($t) || $t === '') {
            continue;
        }
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], $t)) {
            return true;
        }
        foreach ([0, 1] as $ago) {
            if (hash_equals(hash_hmac('sha256', 'bmn-csrf|' . gmdate('Y-m-d', time() - ($ago * 86400)), $key), $t)) {
                return true;
            }
        }
    }
    return false;
}

function bmn_session_needed(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    return true;
}

function bmn_session_cookie_path(): string
{
    $base = rtrim((string) (defined('BMN_BASE') ? BMN_BASE : ''), '/');
    if ($base === '') {
        $base = base_path();
    }
    return $base !== '' ? $base . '/' : '/';
}

function bmn_session_release(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function bmn_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || !bmn_session_needed()) {
        return;
    }
    $sessPath = BMN_ROOT . '/storage/sessions';
    if (!is_dir($sessPath)) {
        @mkdir($sessPath, 0755, true);
    }
    if (is_dir($sessPath) && is_writable($sessPath)) {
        session_save_path($sessPath);
    }
    $cookiePath = bmn_session_cookie_path();
    session_name('hfs_bayi');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

function redirect(string $to, int $code = 302): never
{
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!headers_sent()) {
        header('Location: ' . $to, true, $code);
        header('Cache-Control: no-store');
    }
    $safe = htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $js = json_encode($to, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Yönlendirme</title>';
    echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
    echo '<p><a href="' . $safe . '">Devam etmek için tıklayın</a></p>';
    if (is_string($js)) {
        echo '<script>location.replace(' . $js . ');</script>';
    }
    exit;
}

function current_user(): ?array
{
    $u = $GLOBALS['bmn_user'] ?? null;
    if (is_array($u) && (int) ($u['id'] ?? 0) > 0) {
        return $u;
    }
    if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['uid'] ?? 0) > 0) {
        try {
            $u = Auth::user();
            $GLOBALS['bmn_user'] = $u;
            return $u;
        } catch (Throwable) {
            return null;
        }
    }
    return null;
}

function paginate(int $total, int $per, int $page): array
{
    $pages = max(1, (int) ceil($total / max(1, $per)));
    $page = max(1, min($page, $pages));
    return [
        'total' => $total,
        'per' => $per,
        'page' => $page,
        'pages' => $pages,
        'offset' => ($page - 1) * $per,
    ];
}

function request_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    $uri = '/' . trim($uri, '/');
    if ($uri !== '/') {
        $uri .= '/';
    }
    return $uri;
}

function page_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $origin = bmn_request_origin() ?? ($scheme . '://' . $host);
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $query = [];
    parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?: ''), $query);
    unset($query['sepet'], $query['kupon'], $query['hatirlat']);
    $q = http_build_query($query);
    return $origin . $path . ($q !== '' ? '?' . $q : '');
}

function cart_return_url(): string
{
    $raw = trim((string) ($_POST['back'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $parts = parse_url($raw);
    if ($parts === false) {
        return '';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!empty($parts['host']) && $host !== '' && strcasecmp((string) $parts['host'], $host) !== 0) {
        return '';
    }
    if (empty($parts['host']) && !str_starts_with($raw, '/')) {
        return '';
    }
    $path = $parts['path'] ?? '/';
    $base = base_path();
    if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
        return '';
    }
    if (preg_match('#/yonetim(/|$)#', $path) || str_contains($path, '..')) {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = bmn_request_origin() ?? ($scheme . '://' . $host);
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    unset($query['sepet'], $query['kupon'], $query['hatirlat']);
    $q = http_build_query($query);
    return $origin . $path . ($q !== '' ? '?' . $q : '');
}

function cart_open_url(string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'sepet=1';
}

function pager_url(string $base, int $page): string
{
    $base = rtrim($base, '/') . '/';
    if ($page <= 1) {
        return $base;
    }
    return $base . 'page/' . $page . '/';
}

function free_ship_min(): float
{
    return (float) setting('free_ship_min', '1500');
}

function ship_fee(): float
{
    return (float) setting('ship_fee', '49');
}

function shipping_for(float $subtotal): float
{
    if ($subtotal <= 0) {
        return 0;
    }
    return $subtotal >= free_ship_min() ? 0.0 : ship_fee();
}

/** Sepet / ödeme: kargo tutarı 0 iken gösterilecek metin (ücret tahsil edilmez, alıcı öder). */
function shipping_display_label(float $shippingAmount): string
{
    if ($shippingAmount > 0) {
        return money($shippingAmount);
    }

    return 'Alıcı ödemeli';
}

if (!extension_loaded('zip') && !class_exists('ZipArchive', false)) {
    final class ZipArchive
    {
        public const CREATE = 1;
        public int $numFiles = 0;
        /** @var array<string,string> */
        private array $files = [];

        public function open(string $filename, int $flags = 0): bool|int
        {
            $this->files = [];
            $this->numFiles = 0;
            if ($flags & self::CREATE) {
                return true;
            }
            $lib = __DIR__ . '/lib/HfsZip.php';
            if (is_file($lib)) {
                require_once $lib;
            }
            if (!class_exists('HfsZip')) {
                return false;
            }
            $map = HfsZip::extract($filename);
            if (!is_array($map)) {
                return false;
            }
            $this->files = $map;
            $this->numFiles = count($map);
            return true;
        }

        public function extractTo(string $pathto, array|string|null $files = null): bool
        {
            $root = rtrim(str_replace('\\', '/', $pathto), '/');
            foreach ($this->files as $name => $data) {
                $name = ltrim(str_replace('\\', '/', (string) $name), '/');
                if ($name === '' || str_contains($name, '..')) {
                    continue;
                }
                $dest = $root . '/' . $name;
                $dir = dirname($dest);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return false;
                }
                if (file_put_contents($dest, (string) $data) === false) {
                    return false;
                }
            }
            return true;
        }

        public function close(): bool
        {
            $this->files = [];
            $this->numFiles = 0;
            return true;
        }
    }
}
