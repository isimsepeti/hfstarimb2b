<?php
declare(strict_types=1);

/** bayi.hfstarim.com (WordPress) kaldırılırken görseller b2b/uploads altına taşınır. */
final class HfsMediaCutover
{
    public const LEGACY_WP_UPLOADS = 'https://bayi.hfstarim.com/wp-content/uploads';

    public static function applySettings(): void
    {
        setting_set('media_base', self::uploadsBaseUrl());
    }

    public static function uploadsBaseUrl(): string
    {
        $stored = trim((string) setting('site_url', ''));
        if ($stored !== '' && preg_match('#^https://#i', $stored) && !preg_match('#localhost|127\.0\.0\.1#i', $stored)) {
            return rtrim($stored, '/') . '/uploads';
        }
        return rtrim(site_url('uploads'), '/');
    }

    public static function rewriteDatabaseUrls(): int
    {
        $n = 0;
        $st = db()->query("SELECT id, featured_media FROM products WHERE featured_media <> ''");
        $up = db()->prepare('UPDATE products SET featured_media=? WHERE id=?');
        foreach ($st->fetchAll() ?: [] as $row) {
            $rel = self::normalizeStored((string) $row['featured_media']);
            if ($rel === (string) $row['featured_media']) {
                continue;
            }
            $up->execute([$rel, (int) $row['id']]);
            $n++;
        }
        $meta = db()->query("SELECT product_id, meta_key, meta_value FROM product_meta WHERE meta_key IN ('gallery','diagram_image')");
        $mup = db()->prepare('UPDATE product_meta SET meta_value=? WHERE product_id=? AND meta_key=?');
        foreach ($meta->fetchAll() ?: [] as $row) {
            $key = (string) $row['meta_key'];
            $raw = (string) $row['meta_value'];
            if ($key === 'gallery') {
                $dec = json_decode($raw, true);
                if (!is_array($dec)) {
                    continue;
                }
                $out = [];
                $changed = false;
                foreach ($dec as $p) {
                    $rel = self::normalizeStored((string) $p);
                    $out[] = $rel;
                    if ($rel !== (string) $p) {
                        $changed = true;
                    }
                }
                if (!$changed) {
                    continue;
                }
                $mup->execute([json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', (int) $row['product_id'], $key]);
                $n++;
                continue;
            }
            $rel = self::normalizeStored($raw);
            if ($rel === $raw) {
                continue;
            }
            $mup->execute([$rel, (int) $row['product_id'], $key]);
            $n++;
        }
        return $n;
    }

    public static function normalizeStored(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (function_exists('hfs_media_rel')) {
            return hfs_media_rel($path);
        }
        if (preg_match('#^https?://#i', $path)) {
            $path = (string) preg_replace('#^https?://[^/]+/(?:hfs-tarim/)?(?:wp-content/uploads|uploads)/#i', '', $path);
        }
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /** @return list<string> */
    public static function collectRequiredRels(): array
    {
        $rels = [];
        $push = static function (string $rel) use (&$rels): void {
            $rel = self::normalizeStored($rel);
            if ($rel !== '' && !str_contains($rel, '..')) {
                $rels[$rel] = true;
            }
        };
        try {
            foreach (db()->query("SELECT featured_media FROM products WHERE featured_media<>''")->fetchAll() ?: [] as $row) {
                $push((string) $row['featured_media']);
            }
            foreach (db()->query("SELECT meta_value FROM product_meta WHERE meta_key IN ('gallery','diagram_image')")->fetchAll() ?: [] as $row) {
                $raw = (string) $row['meta_value'];
                if (str_starts_with($raw, '[')) {
                    $dec = json_decode($raw, true);
                    if (is_array($dec)) {
                        foreach ($dec as $p) {
                            $push((string) $p);
                        }
                    }
                    continue;
                }
                $push($raw);
            }
        } catch (Throwable) {
        }
        return array_keys($rels);
    }

    /** Eksik dosyaları bayi WP uploads’tan b2b/uploads’a indirir. */
    public static function pullBatch(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $done = 0;
        $skip = 0;
        $fail = 0;
        $errors = [];
        foreach (self::collectRequiredRels() as $rel) {
            if ($done >= $limit) {
                break;
            }
            if (self::localFileOk($rel)) {
                $skip++;
                continue;
            }
            if (self::downloadLegacy($rel)) {
                $done++;
            } else {
                $fail++;
                if (count($errors) < 8) {
                    $errors[] = $rel;
                }
            }
        }
        return ['downloaded' => $done, 'skipped' => $skip, 'failed' => $fail, 'errors' => $errors];
    }

    public static function localFileOk(string $rel): bool
    {
        $rel = self::normalizeStored($rel);
        if ($rel === '') {
            return false;
        }
        $abs = self::absPath($rel);
        return is_file($abs) && filesize($abs) > 200;
    }

    public static function absPath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', self::normalizeStored($rel)), '/');
        return BMN_ROOT . '/uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    public static function downloadLegacy(string $rel): bool
    {
        $rel = self::normalizeStored($rel);
        if ($rel === '' || str_contains($rel, '..')) {
            return false;
        }
        $dest = self::absPath($rel);
        if (is_file($dest) && filesize($dest) > 200) {
            return true;
        }
        $url = rtrim(self::LEGACY_WP_UPLOADS, '/') . '/' . str_replace('\\', '/', $rel);
        $bin = self::httpGet($url);
        if ($bin === '' || strlen($bin) < 200) {
            return false;
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        return file_put_contents($dest, $bin) !== false;
    }

    public static function invalidateCaches(): void
    {
        HfsMedia::invalidateRemoteIndex();
        @unlink(BMN_ROOT . '/data/cache/media-by-sku.json');
        $legacy = BMN_ROOT . '/data/cache/bayi-media-index.json';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }

    private static function httpGet(string $url): string
    {
        if (!function_exists('curl_init')) {
            $body = @file_get_contents($url);
            return is_string($body) ? $body : '';
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'HFS-B2B-Media-Migrate/1.0',
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) ? $body : '';
    }
}
