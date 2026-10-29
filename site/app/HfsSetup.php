<?php
declare(strict_types=1);

final class HfsSetup
{
    public const LIVE_HOST = 'b2b.hfstarim.com';
    public const MEDIA_HOST = 'b2b.hfstarim.com';
    public const LIVE_URL = 'https://b2b.hfstarim.com';

    public static function isBrokenLiveConfig(?array $cfg): bool
    {
        if (!is_array($cfg)) {
            return true;
        }
        $user = strtolower((string) ($cfg['db']['user'] ?? ''));
        $pass = (string) ($cfg['db']['pass'] ?? '');
        $name = strtolower((string) ($cfg['db']['name'] ?? ''));
        return $user === 'root' || $pass === '' || $name === 'hfs_bayi' || $name === 'hfs_b2b';
    }

    /** @return list<string> */
    public static function configCopyCandidates(): array
    {
        $root = BMN_ROOT;
        return [
            $root . '/yenal/config/app.php',
            $root . '/config/app.live.php',
            $root . '/config/app.bak.php',
            $root . '/config/app.php.bak',
        ];
    }

    /** @return array<string,mixed>|null */
    public static function tryRestoreFromCopies(): ?array
    {
        foreach (self::configCopyCandidates() as $c) {
            if (!is_file($c)) {
                continue;
            }
            $data = include $c;
            if (!is_array($data) || self::isBrokenLiveConfig($data)) {
                continue;
            }
            $dest = BMN_ROOT . '/config/app.php';
            $cfg = "<?php\nreturn " . var_export($data, true) . ";\n";
            if (file_put_contents($dest, $cfg) === false) {
                continue;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($dest, true);
            }
            return $data;
        }
        return null;
    }

    /** Yalnızca config/app.php yazar; katalog ve kullanıcıları yeniden kurmaz. */
    public static function repairConfig(array $in): array
    {
        $host = trim((string) ($in['db_host'] ?? 'localhost'));
        $name = trim((string) ($in['db_name'] ?? ''));
        $user = trim((string) ($in['db_user'] ?? ''));
        $pass = (string) ($in['db_pass'] ?? '');
        if ($host === '' || $name === '' || $user === '' || $pass === '' || strtolower($user) === 'root') {
            throw new RuntimeException('cPanel MySQL adı, kullanıcısı ve şifresi gerekli. root / boş şifre olmaz.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new RuntimeException('Veritabanı adı geçersiz.');
        }
        $pdo = new PDO(
            'mysql:host=' . $host . ';dbname=' . str_replace('`', '', $name) . ';charset=utf8mb4',
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->query('SELECT 1');
        $old = is_file(BMN_ROOT . '/config/app.php') ? (include BMN_ROOT . '/config/app.php') : [];
        $cronKey = is_array($old) && !empty($old['cron_key']) ? (string) $old['cron_key'] : bin2hex(random_bytes(16));
        $siteUrl = rtrim((string) ($old['site_url'] ?? self::LIVE_URL), '/');
        if ($siteUrl === '' || str_contains($siteUrl, 'localhost')) {
            $siteUrl = self::LIVE_URL;
        }
        return self::writeConfig('', $siteUrl, $cronKey, $host, $name, $user, $pass);
    }

    public static function dumpPath(): string
    {
        return BMN_ROOT . '/database/content.sql';
    }

    public static function locked(): bool
    {
        return is_file(self::lockPath());
    }

    public static function dumpExists(): bool
    {
        return is_file(self::dumpPath()) && filesize(self::dumpPath()) > 1000;
    }

    public static function detectBase(): string
    {
        $sn = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
        $base = rtrim(dirname($sn), '/');
        if ($base === '/' || $base === '\\' || $base === '.') {
            return '';
        }
        return $base;
    }

    public static function isLiveHost(): bool
    {
        $h = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        return $h === self::LIVE_HOST || str_ends_with($h, '.' . self::LIVE_HOST);
    }

    public static function detectUrl(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === self::LIVE_HOST || str_ends_with($host, '.' . self::LIVE_HOST)) {
            return self::LIVE_URL;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $h = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . $h . self::detectBase();
    }

    /** @param array<string,mixed> $in @return list<string> */
    public static function run(array $in): array
    {
        @set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $log = [];
        $host = trim((string) ($in['db_host'] ?? 'localhost'));
        $name = trim((string) ($in['db_name'] ?? ''));
        $user = trim((string) ($in['db_user'] ?? ''));
        $pass = (string) ($in['db_pass'] ?? '');
        $adminUser = trim((string) ($in['admin_user'] ?? ''));
        $adminPass = (string) ($in['admin_pass'] ?? '');
        $siteUrl = rtrim(trim((string) ($in['site_url'] ?? self::detectUrl())), '/');
        $base = trim((string) ($in['base'] ?? self::detectBase()));
        $live = self::isLiveHost();
        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('Veritabanı host, adı ve kullanıcısı gerekli. cPanel’deki tam adı yazın (ör. hfst8320_b2b).');
        }
        if ($live && (strtolower($user) === 'root' || $pass === '')) {
            throw new RuntimeException(
                'Canlı sunucuda XAMPP bilgisi (root / boş şifre) çalışmaz. '
                . 'cPanel → MySQL Veritabanları’ndaki kullanıcı ve şifreyi yazın (ör. hfst8320_b2b).'
            );
        }
        if ($adminUser === '' || strlen($adminPass) < 8) {
            throw new RuntimeException('Admin kullanıcı ve en az 8 karakter şifre gerekli.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new RuntimeException('Veritabanı adı geçersiz.');
        }
        if ($siteUrl === '' || !preg_match('#^https?://#i', $siteUrl)) {
            $siteUrl = self::LIVE_URL;
        }

        $log[] = 'Veritabanına bağlanılıyor…';
        $safeName = str_replace('`', '', $name);
        try {
            $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $safeName . ';charset=utf8mb4', $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1045')) {
                throw new RuntimeException(
                    'MySQL kullanıcısı/şifre reddedildi. Formdaki DB kullanıcı ve şifre, cPanel’de oluşturduğunuz hesap olmalı; '
                    . 'root veya boş şifre canlıda geçersizdir. Ham hata: ' . $e->getMessage()
                );
            }
            if (!str_contains($e->getMessage(), '1044') && !str_contains($e->getMessage(), '1049')) {
                throw $e;
            }
            try {
                $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec('USE `' . $safeName . '`');
            } catch (PDOException $e2) {
                throw new RuntimeException(
                    'Bu MySQL kullanıcısının «' . $name . '» veritabanına yetkisi yok. '
                    . 'cPanel → MySQL Veritabanları: veritabanını oluşturun, kullanıcıyı o veritabanına ALL PRIVILEGES ile ekleyin. '
                    . 'DB adı tam olmalı (ör. hfst8320_b2b), yalnızca b2b yazmayın. '
                    . 'Ham hata: ' . $e2->getMessage()
                );
            }
        }
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach (['/config', '/storage/sessions', '/storage/cache', '/storage/logs'] as $d) {
            $p = BMN_ROOT . $d;
            if (!is_dir($p)) {
                mkdir($p, 0755, true);
            }
        }

        $cronKey = bin2hex(random_bytes(16));
        $cfg = self::writeConfig($base, $siteUrl, $cronKey, $host, $name, $user, $pass);
        $GLOBALS['bmn_config'] = $cfg;
        db(true);

        if (self::dumpExists()) {
            $log[] = 'Katalog ve bayi içeriği aktarılıyor…';
            $n = self::importFile(self::dumpPath(), $pdo);
            $log[] = $n . ' SQL komutu işlendi.';
        } else {
            $log[] = 'content.sql yok; boş şema kuruluyor.';
            $schema = (string) file_get_contents(BMN_ROOT . '/database/schema.sql');
            self::importSql($schema, $pdo);
        }

        $schema = (string) file_get_contents(BMN_ROOT . '/database/schema.sql');
        self::importSql($schema, $pdo);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $GLOBALS['bmn_settings'] = null;
        HfsMigrate::up();
        try {
            Migrate::up();
        } catch (Throwable $e) {
            $log[] = 'Şema tamamlayıcı: ' . $e->getMessage();
        }
        HfsSeed::pagesOnly();
        self::applyLive($siteUrl);
        self::ensureAdmin($adminUser, $adminPass);

        $prod = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $usr = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $log[] = 'Ürün: ' . $prod . ' · kullanıcı: ' . $usr;
        file_put_contents(self::lockPath(), date('c') . ' ' . $siteUrl . "\n");
        $log[] = 'Kurulum kilitlendi (storage/install.lock).';
        return $log;
    }

    /** @return array<string,mixed> */
    public static function writeConfig(string $base, string $siteUrl, string $cronKey, string $host, string $name, string $user, string $pass): array
    {
        $data = [
            'base' => $base,
            'site_url' => $siteUrl,
            'live_host' => self::LIVE_HOST,
            'media_host' => self::MEDIA_HOST,
            'cron_key' => $cronKey,
            'db' => [
                'host' => $host,
                'name' => $name,
                'user' => $user,
                'pass' => $pass,
            ],
        ];
        $cfg = "<?php\nreturn " . var_export($data, true) . ";\n";
        $path = BMN_ROOT . '/config/app.php';
        if (file_put_contents($path, $cfg) === false) {
            throw new RuntimeException('config/app.php yazılamadı. Klasör yazılabilir olmalı (chmod 755).');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        file_put_contents(BMN_ROOT . '/config/.htaccess', "Require all denied\n");
        return $data;
    }

    public static function applyLive(string $siteUrl): void
    {
        setting_set('site_url', $siteUrl);
        setting_set('site_name', 'HFS Tarım Bayi');
        setting_set('tagline', 'Bayi satış ve yedek parça sistemi');
        setting_set('seo_title', 'HFS Tarım Bayi — Yedek parça ve sistemler');
        setting_set('seo_description', 'HFS Tarım bayi paneli. Ürün seçici, cari hesap, açık hesap ve kart ile tahsilat.');
        setting_set('org_description', 'HFS Tarım bayi e-ticaret sistemi. Yedek parça diyagramı, bayi fiyatı, cari ödeme.');
        setting_set('iban_name', 'HFS Tarım');
        setting_set('shop_mode', 'hfs');
        setting_set('media_base', rtrim($siteUrl, '/') . '/uploads');
        $phone = (string) setting('phone', '');
        if ($phone === '' || str_contains($phone, '322 000')) {
            setting_set('phone', '0224 216 16 01');
        }
        setting_set('logo', shop_logo());
        setting_set('og_image', shop_og());
        $GLOBALS['bmn_settings'] = null;
    }

    public static function ensureAdmin(string $username, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $email = $username . '@hfstarim.com';
        $st = db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $st->execute([$username]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            db()->prepare("UPDATE users SET password=?, role='admin', email=? WHERE id=?")->execute([$hash, $email, $id]);
            return;
        }
        db()->prepare("INSERT INTO users (username,email,password,display_name,role,created_at) VALUES (?,?,?,?,'admin',NOW())")
            ->execute([$username, $email, $hash, $username]);
    }

    public static function importFile(string $path, PDO $pdo): int
    {
        $sql = (string) file_get_contents($path);
        return self::importSql($sql, $pdo);
    }

    public static function importSql(string $sql, PDO $pdo): int
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }
        $sql = ltrim($sql);
        $n = 0;
        foreach (self::split($sql) as $stmt) {
            $trim = trim($stmt);
            if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                continue;
            }
            if (preg_match('/^(LOCK TABLES|UNLOCK TABLES)/i', $trim)) {
                continue;
            }
            $pdo->exec($stmt);
            $n++;
        }
        return $n;
    }

    /** @return list<string> */
    private static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== '') {
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($ch === ';') {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }
        return $out;
    }
}
