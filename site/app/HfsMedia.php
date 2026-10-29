<?php
declare(strict_types=1);

final class HfsMedia
{
    private const EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    /** @var array<string, list<array{rel:string,url:string,name:string,mtime:int,source:string}>> */
    private static array $indexCache = [];

    public static function uploadsRoot(): string
    {
        $d = BMN_ROOT . '/uploads';
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        return $d;
    }

    /** @return array{items:list<array{rel:string,url:string,name:string,mtime:int,source:string}>,total:int,offset:int,limit:int,has_more:bool,remote_pending:bool,remote_total:int} */
    public static function listPage(string $q = '', int $offset = 0, int $limit = 120): array
    {
        $q = mb_strtolower(trim($q));
        $limit = max(24, min(200, $limit));
        $offset = max(0, $offset);
        $all = self::buildIndex($q);
        $total = count($all);
        $slice = array_slice($all, $offset, $limit);
        $remote = self::remoteIndexMeta();
        return [
            'items' => $slice,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $offset + count($slice) < $total,
            'remote_pending' => !($remote['done'] ?? true),
            'remote_total' => (int) ($remote['total'] ?? 0),
        ];
    }

    /** @return list<array{rel:string,url:string,name:string,mtime:int,source:string}> */
    private static function buildIndex(string $q): array
    {
        if (isset(self::$indexCache[$q])) {
            return self::$indexCache[$q];
        }

        $seen = [];
        $items = [];

        self::scanTree(self::uploadsRoot(), '', 'site', $q, $items, $seen);

        foreach (self::pathsFromDb($q) as $rel) {
            self::pushItem($items, $seen, $rel, 'db', 0);
        }

        foreach (self::cachedRemoteItems($q) as $row) {
            self::pushItem($items, $seen, $row['rel'], 'bayi', $row['mtime']);
        }

        usort($items, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['rel'], $b['rel']));
        self::$indexCache[$q] = $items;
        return $items;
    }

    /** @param list<array{rel:string,url:string,name:string,mtime:int,source:string}> $items */
    private static function pushItem(array &$items, array &$seen, string $rel, string $source, int $mtime): void
    {
        $rel = hfs_media_rel($rel);
        if ($rel === '' || str_contains($rel, '..') || isset($seen[$rel])) {
            return;
        }
        if (preg_match('#^https?://#i', $rel)) {
            return;
        }
        $seen[$rel] = true;
        $items[] = [
            'rel' => $rel,
            'url' => hfs_media($rel),
            'name' => basename($rel),
            'mtime' => $mtime,
            'source' => $source,
        ];
    }

    /** @param list<array{rel:string,url:string,name:string,mtime:int,source:string}> $items */
    private static function scanTree(string $root, string $prefix, string $source, string $q, array &$items, array &$seen): void
    {
        if (!is_dir($root)) {
            return;
        }
        $it = new DirectoryIterator($root);
        foreach ($it as $node) {
            if ($node->isDot()) {
                continue;
            }
            $name = $node->getFilename();
            if ($node->isDir()) {
                $sub = $prefix === '' ? $name : $prefix . '/' . $name;
                self::scanTree($node->getPathname(), $sub, $source, $q, $items, $seen);
                continue;
            }
            if (!$node->isFile()) {
                continue;
            }
            $ext = strtolower($node->getExtension());
            if (!in_array($ext, self::EXT, true)) {
                continue;
            }
            $rel = $prefix === '' ? $name : $prefix . '/' . $name;
            $rel = str_replace('\\', '/', $rel);
            if ($q !== '' && !str_contains(mb_strtolower($rel), $q) && !str_contains(mb_strtolower($name), $q)) {
                continue;
            }
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;
            $items[] = [
                'rel' => $rel,
                'url' => hfs_media($rel),
                'name' => $name,
                'mtime' => (int) $node->getMTime(),
                'source' => $source,
            ];
        }
    }

    /** @return list<string> */
    private static function pathsFromDb(string $q): array
    {
        $out = [];
        try {
            $st = db()->query("SELECT featured_media AS p FROM products WHERE featured_media<>'' UNION SELECT meta_value AS p FROM product_meta WHERE meta_key IN ('gallery','diagram_image')");
            foreach ($st->fetchAll() as $row) {
                $raw = (string) ($row['p'] ?? '');
                if ($raw === '') {
                    continue;
                }
                if (str_starts_with($raw, '[')) {
                    $dec = json_decode($raw, true);
                    if (is_array($dec)) {
                        foreach ($dec as $p) {
                            $rel = hfs_media_rel((string) $p);
                            if ($rel !== '' && ($q === '' || str_contains(mb_strtolower($rel), $q))) {
                                $out[] = $rel;
                            }
                        }
                    }
                    continue;
                }
                $rel = hfs_media_rel($raw);
                if ($rel !== '' && ($q === '' || str_contains(mb_strtolower($rel), $q))) {
                    $out[] = $rel;
                }
            }
        } catch (Throwable) {
        }
        return array_values(array_unique($out));
    }

    private static function cacheFile(): string
    {
        return BMN_ROOT . '/data/cache/bayi-media-index.json';
    }

    /** @return array{items:list<array{rel:string,mtime:int}>,next_page:int,done:bool,total:int} */
    private static function loadRemoteStore(): array
    {
        $empty = ['items' => [], 'next_page' => 1, 'done' => false, 'total' => 0];
        $file = self::cacheFile();
        if (!is_file($file)) {
            return $empty;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $empty;
        }
        $dec = json_decode($raw, true);
        if (!is_array($dec)) {
            return $empty;
        }
        $items = is_array($dec['items'] ?? null) ? $dec['items'] : [];
        return [
            'items' => $items,
            'next_page' => max(1, (int) ($dec['next_page'] ?? 1)),
            'done' => !empty($dec['done']),
            'total' => count($items),
        ];
    }

    /** @param array{items:list<array{rel:string,mtime:int}>,next_page:int,done:bool} $store */
    private static function saveRemoteStore(array $store): void
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode([
            'updated' => gmdate('c'),
            'next_page' => $store['next_page'],
            'done' => $store['done'],
            'items' => $store['items'],
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array{done:bool,total:int} */
    private static function remoteIndexMeta(): array
    {
        $s = self::loadRemoteStore();
        return ['done' => $s['done'], 'total' => $s['total']];
    }

    /** @return list<array{rel:string,mtime:int}> */
    private static function cachedRemoteItems(string $q): array
    {
        $rows = self::loadRemoteStore()['items'];
        if ($q === '') {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            $rel = (string) ($row['rel'] ?? '');
            if ($rel === '') {
                continue;
            }
            if (str_contains(mb_strtolower($rel), $q) || str_contains(mb_strtolower(basename($rel)), $q)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return array{ok:bool,done:bool,added:int,total:int,next_page:int} */
    public static function warmRemoteIndex(int $pagesPerCall = 3): array
    {
        $pagesPerCall = max(1, min(5, $pagesPerCall));
        $store = self::loadRemoteStore();
        if ($store['done']) {
            return ['ok' => true, 'done' => true, 'added' => 0, 'total' => $store['total'], 'next_page' => $store['next_page']];
        }

        if (!str_contains(hfs_uploads_base_url(), 'bayi.hfstarim.com')) {
            $store['done'] = true;
            self::saveRemoteStore($store);
            return ['ok' => true, 'done' => true, 'added' => 0, 'total' => $store['total'], 'next_page' => 1];
        }

        $base = hfs_uploads_remote();
        $host = (string) parse_url($base, PHP_URL_HOST);
        if ($host === '') {
            $store['done'] = true;
            self::saveRemoteStore($store);
            return ['ok' => false, 'done' => true, 'added' => 0, 'total' => 0, 'next_page' => 1];
        }

        $api = 'https://' . $host . '/wp-json/wp/v2/media?per_page=100&media_type=image&orderby=date&order=desc';
        $seen = [];
        foreach ($store['items'] as $row) {
            $seen[(string) $row['rel']] = true;
        }
        $before = count($store['items']);
        $page = $store['next_page'];
        $done = false;

        for ($i = 0; $i < $pagesPerCall; $i++) {
            $json = self::httpGet($api . '&page=' . $page);
            if ($json === '') {
                $done = true;
                break;
            }
            $chunk = json_decode($json, true);
            if (!is_array($chunk) || $chunk === []) {
                $done = true;
                break;
            }
            foreach ($chunk as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $src = (string) ($row['source_url'] ?? '');
                if ($src === '') {
                    continue;
                }
                $rel = hfs_media_rel($src);
                if ($rel === '' || isset($seen[$rel])) {
                    continue;
                }
                $seen[$rel] = true;
                $ts = strtotime((string) ($row['date_gmt'] ?? '')) ?: 0;
                $store['items'][] = ['rel' => $rel, 'mtime' => $ts];
            }
            if (count($chunk) < 100) {
                $done = true;
                $page++;
                break;
            }
            $page++;
        }

        $store['next_page'] = $page;
        $store['done'] = $done;
        self::saveRemoteStore($store);
        self::$indexCache = [];

        $added = count($store['items']) - $before;
        return [
            'ok' => true,
            'done' => $done,
            'added' => $added,
            'total' => count($store['items']),
            'next_page' => $page,
        ];
    }

    private static function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return '';
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'HFS-B2B-Media/1.0',
            ]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code >= 200 && $code < 300) ? $body : '';
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 12,
                'header' => "User-Agent: HFS-B2B-Media/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }

    public static function invalidateRemoteIndex(): void
    {
        $file = self::cacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
        self::$indexCache = [];
    }

    /** @return array{ok:bool,rel:string,url:string}|array{ok:false,message:string} */
    public static function upload(array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'Dosya gelmedi.'];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Yükleme hatası (' . $err . ').'];
        }
        $mime = self::detectImageMime($tmp, (string) ($file['name'] ?? ''));
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'];
        if ($mime === '' || !isset($map[$mime])) {
            return ['ok' => false, 'message' => 'Yalnızca görsel (JPG, PNG, WebP, GIF, SVG).'];
        }
        $ext = $map[$mime];
        $orig = (string) ($file['name'] ?? 'gorsel');
        $base = slugify(pathinfo($orig, PATHINFO_FILENAME));
        if ($base === '' || $base === 'urun') {
            $base = 'gorsel';
        }
        $ym = gmdate('Y/m');
        $dir = self::uploadsRoot() . '/' . str_replace('/', DIRECTORY_SEPARATOR, $ym);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'message' => 'uploads/ klasörü yazılamıyor.'];
        }
        $name = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'message' => 'Dosya kaydedilemedi. uploads/ yazılabilir mi?'];
        }
        $rel = $ym . '/' . $name;
        self::$indexCache = [];
        return ['ok' => true, 'rel' => $rel, 'url' => hfs_media($rel)];
    }

    /** Canlıda fileinfo (finfo) olmayabilir — getimagesize / mime_content_type / uzantı. */
    private static function detectImageMime(string $tmp, string $origName): string
    {
        if (class_exists('finfo')) {
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmp);
                if ($mime !== '' && str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            } catch (Throwable) {
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = (string) @mime_content_type($tmp);
            if ($mime !== '' && str_starts_with($mime, 'image/')) {
                return $mime;
            }
        }
        $info = @getimagesize($tmp);
        if (is_array($info) && !empty($info['mime']) && is_string($info['mime'])) {
            return $info['mime'];
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => '',
        };
    }
}
