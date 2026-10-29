<?php
declare(strict_types=1);

/**
 * Canlı bayi.hfstarim.com kategori ağacı ve ürün-kategori bağlarını
 * yerel hfs_bayi kataloğuna çeker. Yerel WP kopyası stub olduğu için
 * kategoriler boş görünüyordu.
 */
final class HfsLiveSync
{
    private const LIVE = 'https://bayi.hfstarim.com';
    private const SKIP_SLUGS = ['cari-hesap-odemesi'];

    /** @var list<string> */
    private static array $log = [];

    /** @return list<string> */
    public static function run(): array
    {
        self::$log = [];
        @set_time_limit(0);
        ini_set('memory_limit', '1024M');
        HfsMigrate::up();

        if (media_host() === live_host() || media_host() === 'b2b.hfstarim.com') {
            self::$log[] = 'Bayi WordPress senkronu kapalı — katalog b2b üzerinden yönetiliyor.';
            return self::$log;
        }

        $cats = self::fetchAll('/wp-json/wp/v2/product_cat?per_page=100&_fields=id,name,slug,parent,description,count');
        if ($cats === []) {
            self::$log[] = 'Canlı kategoriler alınamadı.';
            return self::$log;
        }

        $products = self::fetchProductsByCategory($cats);
        if ($products === []) {
            self::$log[] = 'Canlı ürün listesi alınamadı.';
            return self::$log;
        }

        self::replaceCategories($cats);
        $stats = self::syncProducts($products);
        $diag = HfsDiagram::attachHostsToCategories();
        if ($diag > 0) {
            self::$log[] = $diag . ' parça diyagramı kategorilere yerleştirildi.';
        }
        try {
            $liveFile = BMN_ROOT . '/data/live-diagrams.json';
            if (is_file($liveFile)) {
                self::$log[] = 'Canlı diyagram dump’u duruyor; OCR atlandı.';
            } else {
                foreach (HfsDiagramSync::run() as $line) {
                    self::$log[] = $line;
                }
            }
        } catch (Throwable $e) {
            self::$log[] = 'Diyagram senkronu atlandı: ' . $e->getMessage();
        }
        self::$log[] = 'Canlı senkron: ' . count($cats) . ' kategori, ' . $stats['linked'] . ' ürün bağlandı, ' . $stats['inserted'] . ' yeni ürün eklendi, ' . $stats['updated'] . ' ürün güncellendi.';
        return self::$log;
    }

    /** @param list<array<string,mixed>> $cats */
    private static function replaceCategories(array $cats): void
    {
        $byId = [];
        foreach ($cats as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $byId[$id] = $c;
        }
        $pathOf = [];
        $build = static function (int $id) use (&$build, &$pathOf, $byId): string {
            if (isset($pathOf[$id])) {
                return $pathOf[$id];
            }
            if (!isset($byId[$id])) {
                return (string) $id;
            }
            $slug = (string) $byId[$id]['slug'];
            $parent = (int) ($byId[$id]['parent'] ?? 0);
            $path = $parent > 0 ? trim($build($parent) . '/' . $slug, '/') : $slug;
            $pathOf[$id] = $path;
            return $path;
        };

        db()->exec('DELETE FROM product_categories');
        db()->exec('DELETE FROM categories');
        $ins = db()->prepare("INSERT INTO categories (id,parent_id,slug,path,name,description,seo_title,seo_description,sort,status,min_qty,hide_nav)
            VALUES (?,?,?,?,?,?,?,?,?,'publish',0,?)");
        $n = 0;
        foreach ($byId as $id => $r) {
            $parent = (int) ($r['parent'] ?? 0);
            $slug = (string) ($r['slug'] ?? '');
            $path = $build($id);
            $name = html_entity_decode((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $desc = html_entity_decode(strip_tags((string) ($r['description'] ?? '')), ENT_QUOTES, 'UTF-8');
            $hide = self::shouldHide($slug, $path) ? 1 : 0;
            $ins->execute([
                $id,
                $parent > 0 ? $parent : null,
                $slug,
                $path,
                $name,
                $desc,
                $name . ' — HFS Tarım Bayi',
                excerpt_plain($desc, 150) ?: ($name . ' yedek parça ve sistemler.'),
                self::sortFor($slug, $parent, $name),
                $hide,
            ]);
            $n++;
        }
        self::$log[] = $n . ' canlı kategori yazıldı.';
    }

    /**
     * @param list<array<string,mixed>> $products
     * @return array{linked:int,inserted:int,updated:int}
     */
    private static function syncProducts(array $products): array
    {
        $byId = [];
        foreach (db()->query('SELECT id, slug, sku, featured_media FROM products')->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $bySlug = [];
        foreach ($byId as $row) {
            $bySlug[(string) $row['slug']] = (int) $row['id'];
        }

        $insP = db()->prepare("INSERT INTO products (id,slug,sku,title,excerpt,content,price,compare_price,stock,status,featured,featured_media,featured_alt,type,seo_title,seo_description,has_diagram,published_at,modified_at)
            VALUES (?,?,?,?,?,?,?,NULL,?,?,0,?,?, 'simple',?,?,0,?,?)");
        $insPNew = db()->prepare("INSERT INTO products (slug,sku,title,excerpt,content,price,compare_price,stock,status,featured,featured_media,featured_alt,type,seo_title,seo_description,has_diagram,published_at,modified_at)
            VALUES (?,?,?,?,?,?,NULL,?,?,0,?,?, 'simple',?,?,0,?,?)");
        $updP = db()->prepare('UPDATE products SET sku=?, title=?, excerpt=?, content=IF(?="", content, ?), featured_media=?, featured_alt=?, seo_title=?, seo_description=?, price=IF(? > 0, ?, price), stock=?, modified_at=? WHERE id=?');
        $insC = db()->prepare('INSERT IGNORE INTO product_categories (product_id,category_id) VALUES (?,?)');
        $insM = db()->prepare("INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $delM = db()->prepare("DELETE FROM product_meta WHERE product_id=? AND meta_key='gallery'");

        $catOk = [];
        foreach (db()->query('SELECT id FROM categories')->fetchAll() as $row) {
            $catOk[(int) $row['id']] = true;
        }

        $usedSlugs = $bySlug;
        $linked = 0;
        $inserted = 0;
        $updated = 0;
        $collisions = 0;
        db()->beginTransaction();
        foreach ($products as $p) {
            $liveId = (int) ($p['id'] ?? 0);
            $slug = trim((string) ($p['slug'] ?? ''));
            if ($liveId < 1 || $slug === '' || in_array($slug, self::SKIP_SLUGS, true)) {
                continue;
            }
            $title = trim((string) ($p['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $localId = (int) ($bySlug[$slug] ?? 0);
            if ($localId < 1 && isset($byId[$liveId]) && (string) $byId[$liveId]['slug'] === $slug) {
                $localId = $liveId;
            }
            $images = $p['images'] ?? [];
            if (!is_array($images)) {
                $images = [];
            }
            $img = trim((string) ($images[0] ?? ''));
            $gallery = array_values(array_filter(array_slice($images, 1), static fn ($s): bool => trim((string) $s) !== ''));
            $sku = trim((string) ($p['sku'] ?? ''));
            $content = (string) ($p['content'] ?? '');
            $excerpt = (string) ($p['excerpt'] ?? '');
            $stock = !empty($p['in_stock']) ? max(1, (int) ($p['stock'] ?? 100)) : 0;
            $price = (float) ($p['price'] ?? 0);
            $now = date('Y-m-d H:i:s');
            if ($localId < 1) {
                $uniq = $slug;
                $i = 2;
                while (isset($usedSlugs[$uniq])) {
                    $uniq = $slug . '-' . $i;
                    $i++;
                }
                $idTaken = isset($byId[$liveId]);
                try {
                    if ($idTaken) {
                        $insPNew->execute([
                            $uniq, $sku, $title, $excerpt, $content, $price, $stock, 'publish', $img, $title,
                            $title . ' — HFS Tarım Bayi',
                            $excerpt !== '' ? $excerpt : excerpt_plain($content, 150),
                            $now, $now,
                        ]);
                        $localId = (int) db()->lastInsertId();
                        $collisions++;
                    } else {
                        $insP->execute([
                            $liveId, $uniq, $sku, $title, $excerpt, $content, $price, $stock, 'publish', $img, $title,
                            $title . ' — HFS Tarım Bayi',
                            $excerpt !== '' ? $excerpt : excerpt_plain($content, 150),
                            $now, $now,
                        ]);
                        $localId = $liveId;
                    }
                    $usedSlugs[$uniq] = $localId;
                    $bySlug[$uniq] = $localId;
                    $byId[$localId] = ['id' => $localId, 'slug' => $uniq, 'sku' => $sku];
                    $inserted++;
                } catch (Throwable $e) {
                    self::$log[] = 'Ürün eklenemedi #' . $liveId . ' ' . $slug . ': ' . $e->getMessage();
                    continue;
                }
            } else {
                $keepSku = $sku !== '' ? $sku : (string) ($byId[$localId]['sku'] ?? '');
                $keepImg = $img !== '' ? $img : (string) ($byId[$localId]['featured_media'] ?? '');
                $updP->execute([
                    $keepSku,
                    $title,
                    $excerpt,
                    $content,
                    $content,
                    $keepImg,
                    $title,
                    $title . ' — HFS Tarım Bayi',
                    $excerpt !== '' ? $excerpt : excerpt_plain($content, 150),
                    $price,
                    $price,
                    $stock > 0 ? $stock : 100,
                    $now,
                    $localId,
                ]);
                $updated++;
            }
            if ($gallery) {
                $insM->execute([$localId, 'gallery', json_encode(array_values($gallery), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            } else {
                $delM->execute([$localId]);
            }
            $cats = $p['product_cat'] ?? [];
            if (!is_array($cats)) {
                $cats = [];
            }
            $any = false;
            foreach ($cats as $cid) {
                $cid = (int) $cid;
                if ($cid < 1 || !isset($catOk[$cid])) {
                    continue;
                }
                $insC->execute([$localId, $cid]);
                $any = true;
            }
            if ($any) {
                $linked++;
            }
            if (($linked + $inserted) % 200 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        db()->commit();
        if ($collisions > 0) {
            self::$log[] = $collisions . ' ürün ID çakışması slug ile çözüldü.';
        }
        return ['linked' => $linked, 'inserted' => $inserted, 'updated' => $updated];
    }

    private static function shouldHide(string $slug, string $path): bool
    {
        if ($slug === 'uncategorized' || $slug === 'uncategorised') {
            return true;
        }
        return $slug === 'sistem-montajlari'
            || str_ends_with($slug, '-montaj')
            || str_contains($path, 'sistem-montajlari');
    }

    private static function sortFor(string $slug, int $parent, string $name): int
    {
        static $top = [
            'tam-sistemler' => 10,
            'yedek-parcalar' => 20,
            'aski-aparati' => 30,
        ];
        if (isset($top[$slug])) {
            return $top[$slug];
        }
        static $child = [
            'sirttan-askili-zeytin-hasat-makinalari' => 10,
            'yandan-askili-zeytin-hasat-makinalari' => 20,
            'akulu-zeytin-silkeleme-makinalari' => 30,
            'sirt-sistem-v2-model' => 10,
            'sirt-sistem-v3-model' => 20,
            'volt-elektrikli-v1-model' => 30,
            'yan-sistem-v1-model' => 40,
            'yan-sistem-v2-model' => 50,
        ];
        if (isset($child[$slug])) {
            return $child[$slug];
        }
        if (preg_match('/^(\d+)/', $slug, $m) || preg_match('/^(\d+)/', $name, $m)) {
            return (int) $m[1];
        }
        return $parent > 0 ? 100 + ($parent % 50) : 90;
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private static function fromStore(array $p): ?array
    {
        $id = (int) ($p['id'] ?? 0);
        $slug = trim((string) ($p['slug'] ?? ''));
        if ($id < 1 || $slug === '') {
            return null;
        }
        $images = [];
        foreach ($p['images'] ?? [] as $im) {
            if (!is_array($im)) {
                continue;
            }
            $src = self::relUpload((string) ($im['src'] ?? ''));
            if ($src !== '' && !in_array($src, $images, true)) {
                $images[] = $src;
            }
        }
        $cats = [];
        foreach ($p['categories'] ?? [] as $c) {
            if (is_array($c)) {
                $cid = (int) ($c['id'] ?? 0);
            } else {
                $cid = (int) $c;
            }
            if ($cid > 0) {
                $cats[] = $cid;
            }
        }
        $desc = self::htmlField($p['description'] ?? '');
        $short = self::htmlField($p['short_description'] ?? '');
        $content = $desc !== '' ? $desc : $short;
        $price = self::storePrice($p);
        return [
            'id' => $id,
            'slug' => $slug,
            'title' => html_entity_decode(trim((string) ($p['name'] ?? '')), ENT_QUOTES, 'UTF-8'),
            'sku' => trim((string) ($p['sku'] ?? '')),
            'content' => $content,
            'excerpt' => excerpt_plain($short !== '' ? $short : $desc, 220),
            'product_cat' => $cats,
            'images' => $images,
            'in_stock' => !empty($p['is_in_stock']),
            'price' => $price,
            'stock' => !empty($p['is_in_stock']) ? 100 : 0,
        ];
    }

    /** @param array<string,mixed> $p */
    private static function storePrice(array $p): float
    {
        $prices = $p['prices'] ?? null;
        if (!is_array($prices)) {
            return (float) ($p['price'] ?? 0);
        }
        $raw = trim((string) ($prices['price'] ?? $prices['regular_price'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return (float) ($p['price'] ?? 0);
        }
        $minor = (int) ($prices['currency_minor_unit'] ?? 2);
        return round(((int) $raw) / (10 ** max(0, $minor)), 2);
    }

    /**
     * @param list<array<string,mixed>> $cats
     * @return list<array<string,mixed>>
     */
    private static function fetchProductsByCategory(array $cats): array
    {
        $byId = [];
        foreach ($cats as $c) {
            $cid = (int) ($c['id'] ?? 0);
            $count = (int) ($c['count'] ?? 0);
            if ($cid < 1 || $count < 1) {
                continue;
            }
            $raw = self::fetchAll('/wp-json/wc/store/v1/products?category=' . $cid . '&per_page=100');
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $n = self::fromStore($row);
                if (!$n) {
                    continue;
                }
                $id = (int) $n['id'];
                if (!isset($byId[$id])) {
                    $byId[$id] = $n;
                    continue;
                }
                $byId[$id]['product_cat'] = array_values(array_unique(array_merge(
                    $byId[$id]['product_cat'] ?? [],
                    $n['product_cat'] ?? []
                )));
                if (($n['price'] ?? 0) > ($byId[$id]['price'] ?? 0)) {
                    $byId[$id]['price'] = $n['price'];
                }
                if (($n['sku'] ?? '') !== '' && ($byId[$id]['sku'] ?? '') === '') {
                    $byId[$id]['sku'] = $n['sku'];
                }
                if (($n['images'][0] ?? '') !== '' && ($byId[$id]['images'][0] ?? '') === '') {
                    $byId[$id]['images'] = $n['images'];
                }
            }
        }
        return array_values($byId);
    }

    private static function htmlField(mixed $v): string
    {
        if (is_array($v) && isset($v['rendered'])) {
            return trim((string) $v['rendered']);
        }
        return is_string($v) ? trim($v) : '';
    }

    private static function relUpload(string $src): string
    {
        $src = trim($src);
        if ($src === '') {
            return '';
        }
        $src = (string) preg_replace('#^https?://[^/]+/(?:hfs-tarim/)?wp-content/uploads/#i', '', $src);
        $src = ltrim(str_replace('\\', '/', $src), '/');
        return (string) preg_replace('#^(?:hfs-tarim/)?wp-content/uploads/#', '', $src);
    }

    /** @return list<array<string,mixed>> */
    private static function fetchAll(string $path): array
    {
        $first = self::httpJson(self::LIVE . $path . (str_contains($path, '?') ? '&' : '?') . 'page=1');
        $body = $first['body'];
        if (!is_array($body)) {
            return [];
        }
        $pages = max(1, $first['pages']);
        if ($pages === 1 && count($body) >= 100) {
            $pages = 20;
        }
        $all = $body;
        for ($p = 2; $p <= $pages; $p++) {
            $sep = str_contains($path, '?') ? '&' : '?';
            $chunk = self::httpJson(self::LIVE . $path . $sep . 'page=' . $p)['body'];
            if (!is_array($chunk) || $chunk === []) {
                break;
            }
            foreach ($chunk as $row) {
                $all[] = $row;
            }
            if (count($chunk) < 100) {
                break;
            }
        }
        return $all;
    }

    /** @return array{body:?array,pages:int,total:int} */
    private static function httpJson(string $url): array
    {
        $raw = '';
        $headers = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: HFS-Bayi-Sync/1.0'],
            ]);
            $resp = curl_exec($ch);
            if (is_string($resp)) {
                $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($resp, 0, $hs);
                $raw = substr($resp, $hs);
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 60,
                    'header' => "Accept: application/json\r\nUser-Agent: HFS-Bayi-Sync/1.0\r\n",
                ],
            ]);
            $raw = (string) (@file_get_contents($url, false, $ctx) ?: '');
            $headers = implode("\n", $GLOBALS['http_response_header'] ?? []);
        }
        $pages = 1;
        $total = 0;
        if (preg_match('/X-WP-TotalPages:\s*(\d+)/i', $headers, $m)) {
            $pages = (int) $m[1];
        }
        if (preg_match('/X-WP-Total:\s*(\d+)/i', $headers, $m)) {
            $total = (int) $m[1];
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || isset($json['code']) || !array_is_list($json)) {
            $json = null;
        }
        return [
            'body' => $json,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    private static function rendered(mixed $v): string
    {
        if (is_array($v) && isset($v['rendered'])) {
            return html_entity_decode(strip_tags((string) $v['rendered']), ENT_QUOTES, 'UTF-8');
        }
        return is_string($v) ? html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8') : '';
    }

    private static function sqlDate(mixed $v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
