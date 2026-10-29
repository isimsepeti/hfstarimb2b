<?php
declare(strict_types=1);

final class HfsDeploy
{
    public const LIVE_URL = 'https://b2b.hfstarim.com';
    private const APPLY = '/hfs-yayin/';
    private const APPLY_FILE = '/hfs-apply.php';
    private const APPLY_UNPAK = '/hfs-unpak.php';

    /** Katalog — sipariş, bayi, SMTP, BulutTahsilat dokunulmaz. */
    private const CATALOG = [
        'categories', 'products', 'product_categories', 'variants',
        'option_groups', 'option_values', 'product_option_groups', 'product_option_values',
        'product_links', 'bundle_items', 'product_meta', 'dealer_offers',
        'pages', 'posts', 'coupons', 'redirects',
    ];

    private static array $pendingManifest = [];

    public static function config(): array
    {
        $file = BMN_ROOT . '/config/deploy.php';
        $cfg = is_file($file) ? (require $file) : [];
        return array_merge([
            'live_url' => self::LIVE_URL,
            'secret' => '',
            'ftp_host' => '',
            'ftp_user' => '',
            'ftp_pass' => '',
            'ftp_path' => '',
            'ftp_ssl' => true,
            'include_catalog' => false,
        ], is_array($cfg) ? $cfg : []);
    }

    public static function secret(): string
    {
        $local = self::config()['secret'] ?? '';
        if (is_string($local) && $local !== '') {
            return $local;
        }
        $sf = BMN_ROOT . '/config/deploy-secret.php';
        if (is_file($sf)) {
            $s = require $sf;
            if (is_array($s) && !empty($s['secret'])) {
                return (string) $s['secret'];
            }
        }
        return '';
    }

    public static function ready(): bool
    {
        $live = rtrim((string) self::config()['live_url'], '/');
        $secret = self::secret();
        return $live !== '' && $secret !== '' && !preg_match('#localhost|127\.0\.0\.1#i', $live);
    }

    public static function ftpReady(): bool
    {
        $c = self::config();
        return $c['ftp_host'] !== '' && $c['ftp_user'] !== '' && $c['ftp_pass'] !== '';
    }

    /** Tek dosyayı canlı site köküne yükler (ör. storage/bayt-cariler.xlsx). */
    public static function uploadLiveFile(string $rel, string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException('Yerel dosya yok: ' . $localPath);
        }
        if (!self::ftpReady()) {
            throw new RuntimeException('FTP ayarı eksik.');
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..') || self::isProtected($rel)) {
            throw new RuntimeException('Yüklenemez: ' . $rel);
        }
        $cfg = self::config();
        $conn = self::ftpConn($cfg);
        try {
            $home = self::ftpFindDocroot($conn, $cfg);
            self::ftpPutRel($conn, $home, $rel, $localPath);
        } finally {
            self::ftpQuietClose($conn);
        }
    }

    public static function missing(): array
    {
        $c = self::config();
        $out = [];
        $live = rtrim((string) $c['live_url'], '/');
        if ($live === '') {
            $out[] = 'Canlı site URL';
        } elseif (preg_match('#localhost|127\.0\.0\.1#i', $live)) {
            $out[] = 'Canlı URL localhost olamaz';
        }
        if (self::secret() === '') {
            $out[] = 'Yayın anahtarı';
        }
        return $out;
    }

    public static function last(): ?array
    {
        $f = BMN_ROOT . '/storage/deploy-last.json';
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    public static function saveConfig(array $post): array
    {
        $old = self::config();
        $secret = trim((string) ($post['secret'] ?? ''));
        if ($secret === '') {
            $secret = $old['secret'] !== '' ? $old['secret'] : bin2hex(random_bytes(24));
        }
        $pass = (string) ($post['ftp_pass'] ?? '');
        if ($pass === '') {
            $pass = (string) $old['ftp_pass'];
        }
        $cfg = [
            'live_url' => rtrim(trim((string) ($post['live_url'] ?? '')), '/') ?: self::LIVE_URL,
            'secret' => $secret,
            'ftp_host' => trim((string) ($post['ftp_host'] ?? '')),
            'ftp_user' => trim((string) ($post['ftp_user'] ?? '')),
            'ftp_pass' => $pass,
            'ftp_path' => trim((string) ($post['ftp_path'] ?? ''), '/'),
            'ftp_ssl' => !empty($post['ftp_ssl']),
            'include_catalog' => !empty($post['include_catalog']),
        ];
        self::persistConfig($cfg);
        return $cfg;
    }

    /** @param array<string,mixed> $cfg */
    private static function persistConfig(array $cfg): void
    {
        $php = "<?php\n// Yerel yayın ayarı — canlıya yüklenmez.\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents(BMN_ROOT . '/config/deploy.php', $php) === false) {
            throw new RuntimeException('config/deploy.php yazılamadı.');
        }
        file_put_contents(
            BMN_ROOT . '/config/deploy-secret.php',
            "<?php\nreturn ['secret' => " . var_export((string) ($cfg['secret'] ?? ''), true) . "];\n"
        );
    }

    public static function push(bool $dry = false): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(300);
        ini_set('memory_limit', '512M');
        $cfg = self::config();
        [$zipPath, $sent, $skipped] = self::buildPackage($cfg);
        $bytes = ($zipPath !== '' && is_file($zipPath)) ? (int) filesize($zipPath) : 0;
        $log = [
            'ok' => true,
            'dry' => $dry,
            'at' => date('c'),
            'zip' => $zipPath !== '' ? basename($zipPath) : '',
            'bytes' => $bytes,
            'sent' => $sent,
            'skipped' => $skipped,
            'steps' => [
                $sent . ' dosya paketlendi, ' . $skipped . ' dosya atlandı (değişmedi).',
                !empty($cfg['include_catalog'])
                    ? 'Katalog (ürün, kategori, sayfa) pakette. Sipariş, bayi ve ödeme ayarı gitmez.'
                    : 'Yalnızca kod. Katalog işaretli değil.',
            ],
        ];
        if ($dry) {
            $log['steps'][] = 'Paket hazır (gönderilmedi).';
            self::writeLast($log);
            return $log;
        }
        if (!self::ready()) {
            throw new RuntimeException('Canlı URL ve yayın anahtarı gerekli. Adres localhost olamaz.');
        }
        if (!self::ftpReady()) {
            throw new RuntimeException(
                'FTP gerekli. Elle dosya yüklemeniz gerekmez — aşağıya sunucu, kullanıcı, şifre yazıp kaydedin, sonra Canlıya al.'
            );
        }
        if ($sent > 0 && $zipPath !== '' && is_file($zipPath)) {
            $log['steps'][] = self::ftpPushPackage($cfg, $zipPath);
            $http = self::httpApply($cfg, $zipPath, true, self::APPLY_FILE);
            $log['steps'][] = !empty($http['ok'])
                ? $http['message']
                : ('Açıcı (site kökü): ' . $http['message']);
        } else {
            $log['steps'][] = 'Değişen kod yok; FTP atlandı.';
        }
        if (!empty($cfg['include_catalog']) && $zipPath !== '' && is_file($zipPath)) {
            try {
                self::ftpUpload($cfg, $zipPath);
                $http = self::httpApply($cfg, $zipPath, false, self::APPLY_FILE);
                $log['steps'][] = !empty($http['ok'])
                    ? $http['message']
                    : ('Kod gitti. Katalog işlenemedi: ' . $http['message']);
            } catch (Throwable $e) {
                $log['steps'][] = 'Kod gitti. Katalog atlandı: ' . $e->getMessage();
            }
        }
        $log['live'] = $cfg['live_url'];
        self::saveManifest();
        self::writeLast($log);
        return $log;
    }

    public static function receive(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        try {
            $secret = self::secret();
            if ($secret === '') {
                throw new RuntimeException('Yayın anahtarı canlı sitede yok.');
            }
            self::assertMac($secret);
            $tmp = self::findPackage();
            $hash = (string) ($_SERVER['HTTP_X_HFS_HASH'] ?? '');
            if ($hash !== '' && !hash_equals($hash, (string) hash_file('sha256', $tmp))) {
                throw new RuntimeException('Paket özeti uyuşmuyor.');
            }
            $out = self::applyZip($tmp);
            if (str_ends_with($tmp, 'hfs-package.zip') || str_ends_with($tmp, 'package.zip')) {
                @unlink($tmp);
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    private static function findPackage(): string
    {
        $upload = (string) ($_FILES['package']['tmp_name'] ?? '');
        $err = (int) ($_FILES['package']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upload !== '' && is_uploaded_file($upload) && $err === UPLOAD_ERR_OK) {
            return $upload;
        }
        $candidates = [
            BMN_ROOT . '/hfs-package.zip',
            BMN_ROOT . '/storage/incoming/package.zip',
            BMN_ROOT . '/package.zip',
        ];
        foreach (glob(BMN_ROOT . '/storage/incoming/*.zip') ?: [] as $g) {
            $candidates[] = $g;
        }
        foreach (array_unique($candidates) as $p) {
            if (is_file($p) && (int) filesize($p) > 200) {
                return $p;
            }
        }
        throw new RuntimeException(
            'Paket bulunamadı (upload hata ' . $err . '). '
            . 'FTP kökünde hfs-package.zip veya storage/incoming/package.zip olmalı.'
        );
    }

    /** @return array{ok:bool,files:int,rows:int} */
    public static function applyZip(string $zipPath): array
    {
        $tmp = BMN_ROOT . '/storage/incoming/unpack-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0755, true);
        $zipOk = false;
        if (extension_loaded('zip') && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($tmp);
                $zip->close();
                $zipOk = true;
            }
        }
        if (!$zipOk) {
            $lib = BMN_ROOT . '/app/lib/HfsZip.php';
            if (is_file($lib)) {
                require_once $lib;
            }
            if (!class_exists('HfsZip')) {
                self::rmDir($tmp);
                throw new RuntimeException('ZipArchive yok.');
            }
            $map = HfsZip::extract($zipPath);
            if (!is_array($map)) {
                self::rmDir($tmp);
                throw new RuntimeException('Paket açılamadı.');
            }
            foreach ($map as $name => $data) {
                $name = str_replace('\\', '/', (string) $name);
                if ($name === '' || str_contains($name, '..')) {
                    continue;
                }
                $dest = $tmp . '/' . $name;
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($dest, $data);
            }
        }
        $filesN = 0;
        $filesRoot = $tmp . '/files';
        if (is_dir($filesRoot)) {
            foreach (self::walkFiles($filesRoot, false) as $file) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($filesRoot))), '/');
                if ($rel === '' || str_contains($rel, '..') || self::isProtected($rel)) {
                    continue;
                }
                $dest = BMN_ROOT . '/' . $rel;
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($file->getPathname(), $dest);
                $filesN++;
            }
        }
        $rowsN = 0;
        $jsonFile = $tmp . '/content.json';
        if (is_file($jsonFile)) {
            $data = json_decode((string) file_get_contents($jsonFile), true);
            if (!is_array($data) || empty($data['tables']) || !is_array($data['tables'])) {
                throw new RuntimeException('content.json bozuk.');
            }
            $rowsN = self::importTables($data['tables']);
        }
        self::rmDir($tmp);
        return ['ok' => true, 'files' => $filesN, 'rows' => $rowsN];
    }

    public static function testFtp(array $post = []): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        self::ftpSslQuietOn();
        $conn = false;
        try {
            $cfg = self::config();
            foreach (['ftp_host', 'ftp_user', 'ftp_path'] as $k) {
                $v = trim((string) ($post[$k] ?? ''));
                if ($v !== '') {
                    $cfg[$k] = $v;
                }
            }
            if (trim((string) ($post['ftp_pass'] ?? '')) !== '') {
                $cfg['ftp_pass'] = (string) $post['ftp_pass'];
            }
            if (array_key_exists('ftp_ssl', $post)) {
                $cfg['ftp_ssl'] = !empty($post['ftp_ssl']);
            }
            $host = (string) $cfg['ftp_host'];
            $user = (string) $cfg['ftp_user'];
            $pass = (string) $cfg['ftp_pass'];
            if ($host === '' || $user === '' || $pass === '') {
                throw new RuntimeException('FTP sunucu, kullanıcı ve şifre yazın. Şifreyi bu denemede kutuya tekrar girin.');
            }
            if (!function_exists('ftp_connect')) {
                throw new RuntimeException('PHP FTP eklentisi kapalı. XAMPP php.ini içinde extension=ftp açın.');
            }
            $steps = [];
            $ssl = !empty($cfg['ftp_ssl']);
            if ($ssl) {
                $conn = @ftp_ssl_connect($host, 21, 20);
                $steps[] = $conn ? 'FTP SSL bağlandı (' . $host . ':21).' : 'FTP SSL olmadı, düz FTP deneniyor.';
            }
            if (!$conn) {
                $conn = @ftp_connect($host, 21, 20);
                if ($conn) {
                    $steps[] = 'Düz FTP bağlandı (' . $host . ':21).';
                }
            }
            if (!$conn) {
                throw new RuntimeException('FTP sunucusuna bağlanılamadı: ' . $host);
            }
            if (!@ftp_login($conn, $user, $pass)) {
                throw new RuntimeException('FTP girişi reddedildi. Kullanıcı ve şifreyi kontrol edin.');
            }
            $steps[] = 'Giriş tamam: ' . $user;
            @ftp_pasv($conn, true);
            $entered = self::ftpEnterSite($conn, (string) $cfg['ftp_path']);
            $steps[] = $entered['note'];
            if ($entered['path'] !== trim((string) $cfg['ftp_path'], '/')) {
                $cfg['ftp_path'] = $entered['path'];
                self::persistConfig($cfg);
                $steps[] = 'Uzak klasör kaydı: ' . ($entered['path'] === '' ? '(boş — FTP kökü)' : $entered['path']);
            }
            $pwd = (string) (@ftp_pwd($conn) ?: '/');
            $names = self::ftpNames($conn);
            $steps[] = 'Şu an ' . $pwd . ' — ' . implode(', ', array_slice($names, 0, 16));
            if (self::ftpIsSiteRoot($conn) || in_array('hfs-apply.php', $names, true) || in_array('app', $names, true)) {
                $steps[] = 'Hesap site klasöründe. Canlıya alabilirsiniz. (Ana sayfa 500 olsa da FTP ping atlanır.)';
                return ['ok' => true, 'steps' => $steps];
            }
            $steps[] = 'Bu dizinde app / hfs-apply.php yok. cPanel FTP Directory b2b public_html olmalı.';
            return ['ok' => false, 'steps' => $steps];
        } finally {
            self::ftpQuietClose($conn);
        }
    }

    /** @return array{0:string,1:int,2:int} */
    private static function buildPackage(array $cfg): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive kapalı. XAMPP php.ini içinde extension=zip açın.');
        }
        $dir = BMN_ROOT . '/storage/incoming';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('storage/incoming oluşturulamadı.');
        }
        $stale = time() - 3600;
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'package*.zip') ?: [] as $old) {
            if ((int) @filemtime($old) < $stale) {
                @unlink($old);
            }
        }
        $zipPath = $dir . DIRECTORY_SEPARATOR . 'package-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE);
        if ($opened !== true) {
            throw new RuntimeException('ZIP açılamadı. Apache’yi XAMPP’ten durdur-başlatın.');
        }
        if (!empty($cfg['include_catalog'])) {
            $live = rtrim((string) $cfg['live_url'], '/');
            $local = rtrim((string) setting('site_url', site_url()), '/');
            $payload = [
                'live_url' => $live,
                'local_url' => $local,
                'tables' => self::dumpTables($local, $live),
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                throw new RuntimeException('Katalog paketlenemedi.');
            }
            $zip->addFromString('content.json', $json);
        }
        $secretFile = BMN_ROOT . '/config/deploy-secret.php';
        $files = self::fileList();
        if (is_file($secretFile)) {
            $files[] = 'config/deploy-secret.php';
            $files = array_values(array_unique($files));
        }
        $manifest = self::loadManifest();
        $lastTs = self::lastSuccessTs();
        $sent = 0;
        $skipped = 0;
        self::$pendingManifest = [];
        foreach ($files as $rel) {
            $abs = BMN_ROOT . '/' . $rel;
            if (!is_file($abs) || self::isProtected($rel)) {
                continue;
            }
            $fp = self::fingerprint($abs);
            if (isset($manifest[$rel]) && $manifest[$rel] === $fp) {
                self::$pendingManifest[$rel] = $fp;
                $skipped++;
                continue;
            }
            if (($manifest[$rel] ?? '') === '' && $lastTs > 0) {
                $mtime = (int) filemtime($abs);
                if ($mtime > 0 && $mtime <= $lastTs) {
                    self::$pendingManifest[$rel] = $fp;
                    $skipped++;
                    continue;
                }
            }
            $zip->addFile($abs, 'files/' . $rel);
            self::$pendingManifest[$rel] = $fp;
            $sent++;
        }
        $closed = $zip->close();
        if ($sent < 1 && empty($cfg['include_catalog'])) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            return ['', 0, $skipped];
        }
        if ($closed !== true || !is_file($zipPath)) {
            throw new RuntimeException('ZIP diske yazılamadı. storage/incoming klasörünün yazılabilir olduğundan emin olun.');
        }
        return [$zipPath, $sent, $skipped];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function dumpTables(string $local, string $live): array
    {
        $out = [];
        foreach (self::CATALOG as $table) {
            try {
                $rows = db()->query('SELECT * FROM `' . str_replace('`', '', $table) . '`')->fetchAll();
            } catch (Throwable) {
                continue;
            }
            if ($live !== '' && $local !== '' && $live !== $local) {
                $rows = self::rewriteUrls($rows, $local, $live);
            }
            $out[$table] = $rows;
        }
        return $out;
    }

    private static function rewriteUrls(array $rows, string $from, string $to): array
    {
        $froms = [$from, $from . '/'];
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if (is_string($v) && $v !== '') {
                    $row[$k] = str_replace($froms, [$to, $to . '/'], $v);
                }
            }
        }
        unset($row);
        return $rows;
    }

    public static function importTables(array $tables): int
    {
        $n = 0;
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::CATALOG as $table) {
            if (empty($tables[$table]) || !is_array($tables[$table])) {
                continue;
            }
            foreach ($tables[$table] as $row) {
                if (!is_array($row) || $row === []) {
                    continue;
                }
                $cols = array_keys($row);
                $fields = implode(',', array_map(static fn ($c) => '`' . str_replace('`', '', (string) $c) . '`', $cols));
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $upd = implode(',', array_map(
                    static fn ($c) => '`' . str_replace('`', '', (string) $c) . '`=VALUES(`' . str_replace('`', '', (string) $c) . '`)',
                    $cols
                ));
                $sql = "INSERT INTO `{$table}` ({$fields}) VALUES ({$ph}) ON DUPLICATE KEY UPDATE {$upd}";
                $pdo->prepare($sql)->execute(array_values($row));
                $n++;
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return $n;
    }

    /** @return list<string> */
    private static function fileList(): array
    {
        $out = [];
        $base = rtrim(str_replace('\\', '/', BMN_ROOT), '/');
        $baseLen = strlen($base);
        foreach (scandir(BMN_ROOT) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = BMN_ROOT . DIRECTORY_SEPARATOR . $name;
            if (is_link($abs) || is_dir($abs) || !is_file($abs)) {
                continue;
            }
            $out[] = $name;
        }
        foreach (['app', 'themes', 'yonetim', 'config', 'database'] as $dir) {
            foreach (self::walkFiles(BMN_ROOT . '/' . $dir) as $file) {
                $abs = str_replace('\\', '/', $file->getPathname());
                $rel = ltrim(substr($abs, $baseLen), '/');
                if ($rel !== '') {
                    $out[] = $rel;
                }
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    public static function isProtected(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);
        if (in_array($rel, [
            'config/app.php',
            'config/deploy.php',
            'install.php',
            'apply-live-catalog.php',
            'import-wp.php',
            'sync-live.php',
            'database/content.sql',
            'hfs-unpak.php',
            'hfs-package.zip',
            'storage/install.lock',
            'storage/deploy-last.json',
            'storage/deploy-manifest.json',
        ], true)) {
            return true;
        }
        if (str_starts_with($rel, 'themes/shop/assets/products/')) {
            return true;
        }
        if (str_starts_with($rel, 'storage/sessions/') || str_starts_with($rel, 'storage/logs/')) {
            return true;
        }
        if (str_starts_with($rel, 'storage/incoming/')) {
            return true;
        }
        if (str_starts_with($rel, 'data/')) {
            return true;
        }
        return str_starts_with($rel, '.git');
    }

    private static function isJunkDirName(string $name): bool
    {
        $n = strtolower($name);
        return in_array($n, ['.git', '.svn', '.cursor', 'node_modules', 'vendor', 'storage', 'cgi-bin'], true)
            || str_starts_with($n, 'backup_');
    }

    private static function walkFiles(string $root, bool $skipJunk = true): iterable
    {
        if (!is_dir($root)) {
            return;
        }
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::UNIX_PATHS;
        try {
            $dir = new RecursiveDirectoryIterator($root, $flags);
            $filter = new RecursiveCallbackFilterIterator($dir, static function ($current, $key, $iterator) use ($skipJunk) {
                try {
                    if ($current->isLink()) {
                        return false;
                    }
                    if ($skipJunk && $iterator->hasChildren() && self::isJunkDirName($current->getFilename())) {
                        return false;
                    }
                    return true;
                } catch (Throwable) {
                    return false;
                }
            });
            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
        } catch (Throwable) {
            return;
        }
        foreach ($it as $file) {
            try {
                if ($file->isLink() || !$file->isFile()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            yield $file;
        }
    }

    /** @return array{ok:bool,message:string} */
    private static function httpApply(array $cfg, string $zipPath, bool $attach, string $path): array
    {
        $url = rtrim((string) $cfg['live_url'], '/') . $path;
        $ts = (string) time();
        $hash = hash_file('sha256', $zipPath);
        $mac = hash_hmac('sha256', $ts . '.' . $hash, (string) $cfg['secret']);
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'cURL kapalı.'];
        }
        $ch = curl_init($url);
        $fields = [];
        if ($attach) {
            $fields['package'] = new CURLFile($zipPath, 'application/zip', 'package.zip');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields !== [] ? $fields : ['hfs' => '1'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-HFS-Ts: ' . $ts,
                'X-HFS-Mac: ' . $mac,
                'X-HFS-Hash: ' . $hash,
            ],
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code === 404 || $code === 0) {
            return ['ok' => false, 'message' => $code === 0 ? ('Bağlanılamadı' . ($err !== '' ? ': ' . $err : '')) : 'Alıcı henüz canlıda yok (404).'];
        }
        $trim = ltrim($body);
        if ($trim !== '' && (str_starts_with($trim, '<!') || str_starts_with($trim, '<html') || str_contains($trim, '<meta name="csrf'))) {
            return ['ok' => false, 'message' => 'Alıcı mağaza sayfasına düştü (HTTP ' . $code . '). FTP ile hfs-apply.php yüklenecek.'];
        }
        $j = json_decode($body, true);
        if ($code >= 200 && $code < 300 && is_array($j) && !empty($j['ok'])) {
            return ['ok' => true, 'message' => 'Canlı işledi: ' . (int) ($j['files'] ?? 0) . ' dosya, ' . (int) ($j['rows'] ?? 0) . ' satır.'];
        }
        if (is_array($j) && !empty($j['error'])) {
            return ['ok' => false, 'message' => (string) $j['error']];
        }
        if ($code >= 400 && $trim === '') {
            return ['ok' => false, 'message' => 'HTTP ' . $code . ' (boş cevap). ' . $url];
        }
        return ['ok' => false, 'message' => 'HTTP ' . $code . ' ' . mb_substr(trim(strip_tags($body)), 0, 160)];
    }

    private static function ftpConn(array $cfg)
    {
        self::ftpSslQuietOn();
        $ssl = !empty($cfg['ftp_ssl']);
        $conn = $ssl ? @ftp_ssl_connect((string) $cfg['ftp_host'], 21, 20) : false;
        if (!$conn) {
            $conn = @ftp_connect((string) $cfg['ftp_host'], 21, 20);
        }
        if (!$conn) {
            throw new RuntimeException('FTP bağlanamadı: ' . $cfg['ftp_host']);
        }
        if (!@ftp_login($conn, (string) $cfg['ftp_user'], (string) $cfg['ftp_pass'])) {
            self::ftpQuietClose($conn);
            throw new RuntimeException('FTP girişi başarısız.');
        }
        @ftp_pasv($conn, true);
        if (defined('FTP_TIMEOUT_SEC')) {
            @ftp_set_option($conn, FTP_TIMEOUT_SEC, 180);
        }
        self::ftpEnterSite($conn, (string) $cfg['ftp_path']);
        return $conn;
    }

    /** @return array{path:string,note:string} */
    private static function ftpEnterSite($conn, string $wanted): array
    {
        $wanted = trim(str_replace('\\', '/', $wanted), '/');
        if ($wanted !== '' && @ftp_chdir($conn, $wanted)) {
            if (self::ftpIsSiteRoot($conn) || self::ftpNames($conn) === []) {
                return ['path' => $wanted, 'note' => 'Klasöre girildi: ' . $wanted];
            }
        }
        @ftp_chdir($conn, '/');
        if (self::ftpIsSiteRoot($conn)) {
            return [
                'path' => '',
                'note' => 'FTP kökü HFS site klasörü (app / config / hfs-apply.php). Uzak klasör boş bırakıldı.',
            ];
        }
        foreach (self::ftpSiteCandidates($conn) as $try) {
            if ($try === '' || $try === $wanted) {
                continue;
            }
            @ftp_chdir($conn, '/');
            if (@ftp_chdir($conn, $try) && self::ftpIsSiteRoot($conn)) {
                return ['path' => $try, 'note' => 'Site klasörü bulundu: ' . $try];
            }
        }
        @ftp_chdir($conn, '/');
        $names = self::ftpNames($conn);
        if ($names === []) {
            return ['path' => '', 'note' => 'Dosya listesi alınamadı; FTP kökü kullanılacak.'];
        }
        throw new RuntimeException(
            ($wanted !== '' ? '«' . $wanted . '» yok. ' : '')
            . 'Şu an / içinde index.php yok. Görünen klasörler: '
            . implode(', ', array_slice($names, 0, 24))
            . '. Bunlardan site olanı Uzak klasör’e yazın (ör. domains/b2b.hfstarim.com/public_html).'
        );
    }

    /** @return list<string> */
    private static function ftpSiteCandidates($conn): array
    {
        $out = [
            'public_html', 'www', 'httpdocs', 'web', 'htdocs',
            'b2b.hfstarim.com', 'hfstarim.com',
            'domains/b2b.hfstarim.com/public_html',
            'domains/b2b.hfstarim.com/private_html',
            'domains/hfstarim.com/public_html',
            'public_html/b2b.hfstarim.com',
            'public_html/b2b',
            'b2b.hfstarim.com/public_html',
        ];
        $names = self::ftpNames($conn);
        foreach ($names as $n) {
            $low = strtolower($n);
            if (str_contains($low, 'hfs') || str_contains($low, 'b2b') || str_ends_with($low, '.com') || $low === 'domains' || $low === 'public_html') {
                $out[] = $n;
                $out[] = $n . '/public_html';
            }
        }
        if (in_array('domains', $names, true) && @ftp_chdir($conn, 'domains')) {
            foreach (self::ftpNames($conn) as $d) {
                $out[] = 'domains/' . $d . '/public_html';
                $out[] = 'domains/' . $d;
            }
            @ftp_chdir($conn, '/');
        }
        return array_values(array_unique($out));
    }

    private static function ftpIsSiteRoot($conn): bool
    {
        foreach (['index.php', 'hfs-apply.php', '.htaccess'] as $file) {
            $size = @ftp_size($conn, $file);
            if (is_int($size) && $size >= 0) {
                return true;
            }
            $mdtm = @ftp_mdtm($conn, $file);
            if (is_int($mdtm) && $mdtm !== -1) {
                return true;
            }
        }
        $names = array_map('strtolower', self::ftpNames($conn));
        if (in_array('hfs-apply.php', $names, true) || in_array('index.php', $names, true)) {
            return true;
        }
        $hasApp = in_array('app', $names, true);
        $hasConfig = in_array('config', $names, true);
        $hasThemes = in_array('themes', $names, true);
        return ($hasApp && $hasConfig) || $hasThemes;
    }

    /** @return list<string> */
    private static function ftpNames($conn): array
    {
        $out = [];
        foreach (['.', '-a', '*'] as $spec) {
            $list = @ftp_nlist($conn, $spec);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                $n = basename(str_replace('\\', '/', (string) $item));
                if ($n !== '' && $n !== '.' && $n !== '..') {
                    $out[$n] = true;
                }
            }
        }
        $raw = @ftp_rawlist($conn, '.');
        if (is_array($raw)) {
            foreach ($raw as $line) {
                if (preg_match('/\s(\S+)$/', (string) $line, $m) && $m[1] !== '.' && $m[1] !== '..') {
                    $out[$m[1]] = true;
                }
            }
        }
        $keys = array_keys($out);
        sort($keys);
        return $keys;
    }

    /** Zip’i yerelde açar, dosyaları FTP ile yazar. Canlı ZipArchive gerekmez. */
    private static function ftpPushPackage(array $cfg, string $zipPath): string
    {
        $n = self::ftpPushExtracted($cfg, $zipPath);
        return 'FTP ile ' . $n . ' dosya yazıldı. Canlıda zip eklentisi gerekmedi.';
    }

    /** Zip’i yerelde açıp dosyaları FTP ile yazar. */
    private static function ftpPushExtracted(array $cfg, string $zipPath): int
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Yerelde ZipArchive kapalı. XAMPP php.ini içinde extension=zip açın.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Paket açılamadı.');
        }
        $tmp = BMN_ROOT . '/storage/incoming/ftp-unpack-' . bin2hex(random_bytes(4));
        if (!mkdir($tmp, 0755, true) && !is_dir($tmp)) {
            $zip->close();
            throw new RuntimeException('Geçici klasör açılamadı.');
        }
        $zip->extractTo($tmp);
        $zip->close();
        $n = 0;
        $conn = false;
        try {
            $conn = self::ftpConn($cfg);
            try {
                $home = self::ftpFindDocroot($conn, $cfg);
            } catch (Throwable $e) {
                $home = (string) (@ftp_pwd($conn) ?: '/');
            }
            $apply = BMN_ROOT . '/hfs-apply.php';
            if (is_file($apply)) {
                self::ftpChdirHome($conn, $home);
                @ftp_put($conn, 'hfs-apply.php', $apply, FTP_BINARY);
            }
            $filesRoot = $tmp . '/files';
            if (is_dir($filesRoot)) {
                foreach (self::walkFiles($filesRoot, false) as $file) {
                    $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($filesRoot))), '/');
                    if ($rel === '' || str_contains($rel, '..') || self::isProtected($rel)) {
                        continue;
                    }
                    self::ftpPutRel($conn, $home, $rel, $file->getPathname());
                    $n++;
                    if ($n % 60 === 0) {
                        self::ftpQuietClose($conn);
                        $conn = self::ftpConn($cfg);
                        $home = (string) (@ftp_pwd($conn) ?: '/');
                    }
                }
            }
        } finally {
            self::ftpQuietClose($conn);
            self::rmDir($tmp);
        }
        if ($n < 1) {
            throw new RuntimeException('Pakette yüklenecek dosya yok.');
        }
        return $n;
    }

    /** HTTP ile doğrulanmış site kökünü bulur (.css ping + hfs-apply.php denemesi). */
    private static function ftpFindDocroot($conn, array $cfg): string
    {
        $token = 'hfs' . bin2hex(random_bytes(5));
        $tmpDir = rtrim(sys_get_temp_dir(), '\\/');
        $cssName = 'hfs-ping-' . $token . '.css';
        $tmpCss = $tmpDir . DIRECTORY_SEPARATOR . $cssName;
        $tmpPing = $tmpDir . DIRECTORY_SEPARATOR . 'hfs-apply-ping.php';
        file_put_contents($tmpCss, $token);
        file_put_contents($tmpPing, "<?php header('Content-Type: text/plain'); echo '" . $token . "';\n");
        $realApply = BMN_ROOT . '/hfs-apply.php';
        $user = (string) ($cfg['ftp_user'] ?? '');
        $acct = preg_replace('/_.*$/', '', $user) ?: $user;
        @ftp_chdir($conn, '/');
        $rootNames = self::ftpNames($conn);
        $dirs = array_merge(
            [''],
            self::ftpSiteCandidates($conn),
            $rootNames,
            [
                'public_html',
                '../public_html',
                '../../public_html',
                '/public_html',
                'home/' . $acct . '/public_html',
                '/home/' . $acct . '/public_html',
                '/home/' . $acct . '/domains/b2b.hfstarim.com/public_html',
                '/home/' . $user . '/public_html',
            ]
        );
        $pwd = (string) (@ftp_pwd($conn) ?: '/');
        for ($i = 0; $i < 4; $i++) {
            $dirs[] = ltrim(str_replace('\\', '/', $pwd), '/');
            if (!@ftp_chdir($conn, '..')) {
                break;
            }
            $pwd = (string) (@ftp_pwd($conn) ?: $pwd);
        }
        $seen = [];
        try {
            foreach ($dirs as $dir) {
                $dir = str_replace('\\', '/', (string) $dir);
                $key = $dir === '' || $dir === '/' ? '/' : $dir;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (!self::ftpChdirPath($conn, $dir === '/' ? '' : $dir)) {
                    continue;
                }
                if (self::ftpPingHere($conn, $cfg, $token, $cssName, $tmpCss, $tmpPing, $realApply)) {
                    @unlink($tmpCss);
                    @unlink($tmpPing);
                    return (string) (@ftp_pwd($conn) ?: '/');
                }
            }
        } finally {
            @unlink($tmpCss);
            @unlink($tmpPing);
        }
        throw new RuntimeException(
            'Bu FTP hesabı b2b.hfstarim.com web klasörüne yazamıyor. '
            . 'FTP kökünde görünen: ' . implode(', ', array_slice($rootNames, 0, 18))
            . '. cPanel → FTP Accounts → Add FTP Account: Directory olarak b2b.hfstarim.com public_html seçin. '
            . 'Yeni kullanıcı / şifreyi kaydedin. Elle dosya atmayın.'
        );
    }

    private static function ftpPingHere(
        $conn,
        array $cfg,
        string $token,
        string $cssName,
        string $tmpCss,
        string $tmpPing,
        string $realApply
    ): bool {
        $live = rtrim((string) $cfg['live_url'], '/');
        @ftp_delete($conn, $cssName);
        if (@ftp_put($conn, $cssName, $tmpCss, FTP_BINARY)) {
            usleep(250000);
            $body = trim(self::httpGetRaw($live . '/' . $cssName));
            @ftp_delete($conn, $cssName);
            if ($body === $token) {
                return true;
            }
        }
        if (!is_file($realApply) || !@ftp_put($conn, 'hfs-apply.php', $tmpPing, FTP_BINARY)) {
            return false;
        }
        usleep(400000);
        $body = trim(self::httpGetRaw($live . '/hfs-apply.php?n=' . rawurlencode($token)));
        @ftp_put($conn, 'hfs-apply.php', $realApply, FTP_BINARY);
        return str_contains($body, $token);
    }

    private static function ftpChdirPath($conn, string $dir): bool
    {
        $dir = str_replace('\\', '/', $dir);
        if ($dir === '' || $dir === '/') {
            return (bool) @ftp_chdir($conn, '/');
        }
        if (@ftp_chdir($conn, $dir)) {
            return true;
        }
        if ($dir[0] !== '/' && @ftp_chdir($conn, '/' . $dir)) {
            return true;
        }
        if (!@ftp_chdir($conn, '/')) {
            return false;
        }
        foreach (explode('/', trim($dir, '/')) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if (!@ftp_chdir($conn, $p)) {
                return false;
            }
        }
        return true;
    }

    private static function httpGetRaw(string $url): string
    {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return $body;
    }

    private static function ftpPutIncomingZip($conn, string $home, string $zipPath): void
    {
        self::ftpChdirHome($conn, $home);
        if (!@ftp_chdir($conn, 'storage')) {
            self::ftpChdirHome($conn, $home);
            @ftp_mkdir($conn, 'storage');
            if (!@ftp_chdir($conn, 'storage')) {
                self::ftpChdirHome($conn, $home);
                throw new RuntimeException('FTP storage klasörüne girilemedi.');
            }
        }
        @ftp_mkdir($conn, 'incoming');
        if (!@ftp_chdir($conn, 'incoming')) {
            self::ftpChdirHome($conn, $home);
            throw new RuntimeException('FTP storage/incoming açılamadı.');
        }
        if (!@ftp_put($conn, 'package.zip', $zipPath, FTP_BINARY)) {
            self::ftpChdirHome($conn, $home);
            throw new RuntimeException('FTP storage/incoming/package.zip yazılamadı.');
        }
        $got = @ftp_size($conn, 'package.zip');
        $expect = is_file($zipPath) ? (int) filesize($zipPath) : -1;
        if (is_int($got) && $got >= 0 && abs($got - $expect) > 1024) {
            self::ftpChdirHome($conn, $home);
            throw new RuntimeException('incoming paket boyutu uyuşmuyor.');
        }
        self::ftpChdirHome($conn, $home);
    }

    private static function httpExpectApply(array $cfg, string $path): void
    {
        $url = rtrim((string) $cfg['live_url'], '/') . $path;
        if (!function_exists('curl_init')) {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $trim = ltrim($body);
        if ($code === 404 || $code === 0) {
            throw new RuntimeException(
                'Açıcı canlıda yok (HTTP ' . $code . '). Elle yükleme gerekmez; FTP uzak klasörü boş olsun ve «FTP dene» ye basın.'
            );
        }
        if ($code === 500 || str_starts_with($trim, '<!') || str_contains($body, 'csrf') || str_contains($trim, '<html')) {
            throw new RuntimeException(
                'Açıcı mağazaya düştü (HTTP ' . $code . '). FTP site köküne yazmıyor. Uzak klasörü boş bırakıp «FTP dene»; elle dosya atmanıza gerek yok.'
            );
        }
        if (!str_contains($body, 'POST gerekli')) {
            throw new RuntimeException(
                'Açıcı beklenen cevap değil (HTTP ' . $code . '): ' . mb_substr(trim(strip_tags($body)), 0, 120)
            );
        }
    }

    private static function ftpPutRel($conn, string $home, string $rel, string $local): void
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $dir = trim(str_replace('\\', '/', dirname($rel)), '/');
        $base = basename(str_replace('\\', '/', $rel));
        if ($base === '' || $base === '.' || $base === '..') {
            return;
        }
        if (!self::ftpChdirHome($conn, $home)) {
            throw new RuntimeException('FTP köküne dönülemedi.');
        }
        if ($dir !== '' && $dir !== '.') {
            foreach (explode('/', $dir) as $p) {
                if ($p === '' || $p === '.') {
                    continue;
                }
                if (!@ftp_chdir($conn, $p)) {
                    @ftp_mkdir($conn, $p);
                    if (!@ftp_chdir($conn, $p)) {
                        throw new RuntimeException('FTP klasör açılamadı: ' . $dir . ' (' . $rel . ')');
                    }
                }
            }
        }
        if (!@ftp_put($conn, $base, $local, FTP_BINARY)) {
            throw new RuntimeException('FTP yazılamadı: ' . $rel);
        }
        @ftp_chmod($conn, 0644, $base);
        self::ftpChdirHome($conn, $home);
    }

    private static function ftpChdirHome($conn, string $home): bool
    {
        if ($home !== '' && @ftp_chdir($conn, $home)) {
            return true;
        }
        return @ftp_chdir($conn, '/');
    }

    private static function ftpSeedReceiver(array $cfg): void
    {
        $conn = self::ftpConn($cfg);
        $home = (string) (@ftp_pwd($conn) ?: '/');
        $map = [
            'hfs-apply.php' => 'hfs-apply.php',
            'hfs-unpak.php' => 'hfs-unpak.php',
            '.htaccess' => '.htaccess',
            'app/HfsDeploy.php' => 'app/HfsDeploy.php',
            'app/bootstrap.php' => 'app/bootstrap.php',
            'app/Front.php' => 'app/Front.php',
            'app/Hfs.php' => 'app/Hfs.php',
            'config/deploy-secret.php' => 'config/deploy-secret.php',
        ];
        foreach ($map as $rel => $remote) {
            $abs = BMN_ROOT . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            @ftp_chdir($conn, $home);
            $dir = dirname($remote);
            if ($dir !== '.' && $dir !== '') {
                self::ftpEnsureDir($conn, $dir);
                @ftp_chdir($conn, $home);
            }
            if (!@ftp_put($conn, $remote, $abs, FTP_BINARY)) {
                self::ftpQuietClose($conn);
                throw new RuntimeException('FTP alıcı yüklenemedi: ' . $rel);
            }
        }
        @ftp_chdir($conn, $home);
        self::ftpQuietClose($conn);
    }

    private static function ftpUpload(array $cfg, string $zipPath): void
    {
        $conn = self::ftpConn($cfg);
        $home = (string) (@ftp_pwd($conn) ?: '/');
        $okRoot = @ftp_put($conn, 'hfs-package.zip', $zipPath, FTP_BINARY);
        self::ftpEnsureDir($conn, 'storage/incoming');
        @ftp_chdir($conn, $home);
        $okInc = false;
        if (@ftp_chdir($conn, 'storage') && @ftp_chdir($conn, 'incoming')) {
            $okInc = @ftp_put($conn, 'package.zip', $zipPath, FTP_BINARY);
            @ftp_chdir($conn, $home);
        }
        self::ftpQuietClose($conn);
        if (!$okRoot && !$okInc) {
            throw new RuntimeException('Paket FTP ile yüklenemedi (hfs-package.zip).');
        }
    }

    /** FTPS kapanırken OpenSSL 3 unexpected eof basar; işlem yine başarılıdır. */
    private static function ftpSslQuietOn(): void
    {
        static $on = false;
        if ($on) {
            return;
        }
        $on = true;
        set_error_handler(static function (int $no, string $str): bool {
            return str_contains($str, 'SSL_read')
                || str_contains($str, 'unexpected eof')
                || str_contains(strtolower($str), 'ftp_close');
        });
    }

    private static function ftpQuietClose($conn): void
    {
        if ($conn === false || $conn === null) {
            return;
        }
        self::ftpSslQuietOn();
        @ftp_close($conn);
    }

    private static function ftpEnsureDir($conn, string $dir): void
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || $dir === '.') {
            return;
        }
        $pwd = (string) (@ftp_pwd($conn) ?: '/');
        foreach (explode('/', $dir) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if (@ftp_chdir($conn, $p)) {
                continue;
            }
            @ftp_mkdir($conn, $p);
            if (!@ftp_chdir($conn, $p)) {
                @ftp_chdir($conn, $pwd);
                throw new RuntimeException('FTP klasör oluşturulamadı: ' . $dir);
            }
        }
        @ftp_chdir($conn, $pwd);
    }

    private static function assertMac(string $secret): void
    {
        $ts = (string) ($_SERVER['HTTP_X_HFS_TS'] ?? '');
        $mac = (string) ($_SERVER['HTTP_X_HFS_MAC'] ?? '');
        $hash = (string) ($_SERVER['HTTP_X_HFS_HASH'] ?? '');
        if ($ts === '' || $mac === '' || $hash === '') {
            throw new RuntimeException('İmza eksik.');
        }
        if (abs(time() - (int) $ts) > 600) {
            throw new RuntimeException('İmza süresi doldu. Bilgisayar saatini kontrol edin.');
        }
        $expect = hash_hmac('sha256', $ts . '.' . $hash, $secret);
        if (!hash_equals($expect, $mac)) {
            throw new RuntimeException('Yayın anahtarı uyuşmuyor.');
        }
    }

    private static function loadManifest(): array
    {
        $f = BMN_ROOT . '/storage/deploy-manifest.json';
        if (!is_file($f)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($f), true);
        $files = is_array($j) ? ($j['files'] ?? []) : [];
        return is_array($files) ? $files : [];
    }

    private static function saveManifest(): void
    {
        $files = array_merge(self::loadManifest(), self::$pendingManifest);
        ksort($files);
        file_put_contents(
            BMN_ROOT . '/storage/deploy-manifest.json',
            json_encode(['files' => $files], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private static function fingerprint(string $abs): string
    {
        return (string) filesize($abs) . ':' . (string) filemtime($abs);
    }

    private static function lastSuccessTs(): int
    {
        $last = self::last();
        if (!$last || empty($last['ok']) || !empty($last['dry']) || empty($last['at'])) {
            return 0;
        }
        $t = strtotime((string) $last['at']);
        return $t !== false ? $t : 0;
    }

    private static function writeLast(array $log): void
    {
        $dir = BMN_ROOT . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/deploy-last.json', json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private static function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
