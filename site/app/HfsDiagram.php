<?php
declare(strict_types=1);

final class HfsDiagram
{
    public static function meta(int $productId, string $key, mixed $default = ''): mixed
    {
        $st = db()->prepare('SELECT meta_value FROM product_meta WHERE product_id=? AND meta_key=? LIMIT 1');
        $st->execute([$productId, $key]);
        $v = $st->fetchColumn();
        if ($v === false) {
            return $default;
        }
        $json = json_decode((string) $v, true);
        return $json !== null ? $json : $v;
    }

    public static function forProduct(int $productId): ?array
    {
        if ($productId < 1) {
            return null;
        }
        $image = trim((string) self::meta($productId, 'diagram_image', ''));
        $points = self::meta($productId, 'diagram_points', []);
        if ($image === '' || !is_array($points) || $points === []) {
            return null;
        }
        $points = self::ensureHotspotSizes($productId, $image, $points);
        $host = Catalog::productById($productId);
        $points = self::expandVariantPoints($productId, (string) ($host['slug'] ?? ''), $points);
        $groups = [];
        $parts = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $pid = (int) ($point['product_id'] ?? 0);
            $left = $point['left'] ?? null;
            $top = $point['top'] ?? null;
            if ($pid < 1) {
                continue;
            }
            $p = Catalog::productById($pid);
            if (!$p || $p['status'] !== 'publish') {
                continue;
            }
            $p = HfsPricing::decorate($p);
            $part = trim((string) ($point['part'] ?? ''));
            $item = [
                'product_id' => $pid,
                'part' => $part,
                'img' => hfs_product_image_url($p),
                'sku' => (string) $p['sku'],
                'name' => (string) $p['title'],
                'price_html' => HfsPricing::html($p),
                'url' => permalink_product($p),
                'stock' => (int) $p['stock'],
                'in_stock' => true,
            ];
            if ($left !== null && $top !== null && (float) $left >= 0 && (float) $top >= 0) {
                $key = round((float) $left, 1) . '|' . round((float) $top, 1);
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'left' => round((float) $left, 1),
                        'top' => round((float) $top, 1),
                        'width' => 0.0,
                        'height' => 0.0,
                        'items' => [],
                    ];
                }
                $w = (float) ($point['width'] ?? 0);
                $hh = (float) ($point['height'] ?? 0);
                if ($w > $groups[$key]['width']) {
                    $groups[$key]['width'] = round($w, 2);
                }
                if ($hh > $groups[$key]['height']) {
                    $groups[$key]['height'] = round($hh, 2);
                }
                $groups[$key]['items'][] = $item;
            }
            if (!isset($parts[$pid])) {
                $parts[$pid] = ['product' => $p, 'parts' => []];
            }
            if ($part !== '' && !in_array($part, $parts[$pid]['parts'], true)) {
                $parts[$pid]['parts'][] = $part;
            }
        }
        if ($parts === []) {
            return null;
        }
        $list = array_values($parts);
        usort($list, static function (array $a, array $b): int {
            return strnatcmp((string) ($a['parts'][0] ?? ''), (string) ($b['parts'][0] ?? ''));
        });
        foreach ($groups as &$g) {
            $by = [];
            foreach ($g['items'] as $it) {
                $id = (int) ($it['product_id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $sku = strtoupper(trim((string) ($it['sku'] ?? '')));
                $key = $sku !== '' ? 's:' . $sku : 'i:' . $id;
                $old = $by[$key]['part'] ?? '';
                $new = (string) ($it['part'] ?? '');
                if (!isset($by[$key]) || (preg_match('/[ABC]$/i', $new) && !preg_match('/[ABC]$/i', (string) $old))) {
                    $by[$key] = $it;
                }
            }
            $g['items'] = array_values($by);
            usort($g['items'], static function (array $a, array $b): int {
                return strnatcmp((string) ($a['part'] ?? ''), (string) ($b['part'] ?? ''));
            });
        }
        unset($g);
        return [
            'image' => hfs_media($image),
            'is_pdf' => (bool) preg_match('/\.pdf($|\?)/i', $image),
            'groups' => array_values($groups),
            'parts' => $list,
            'related' => self::related($productId),
        ];
    }

    /**
     * PNG’deki numaralı daireye hotspot merkezini ve çapını kilitler.
     *
     * @param list<array<string,mixed>> $points
     * @return list<array<string,mixed>>
     */
    public static function ensureHotspotSizes(int $productId, string $imageRel, array $points): array
    {
        $need = 0;
        foreach ($points as $pt) {
            if (!is_array($pt)) {
                continue;
            }
            if (empty($pt['fit']) && isset($pt['left'], $pt['top']) && (float) $pt['left'] >= 0 && (float) $pt['top'] >= 0) {
                $need++;
            }
        }
        if ($need < 1) {
            return $points;
        }
        if (!function_exists('imagecreatefromstring')) {
            return $points;
        }
        $im = self::diagramImage($imageRel);
        if (!$im) {
            return $points;
        }
        $iw = imagesx($im);
        $ih = imagesy($im);
        if ($iw < 40 || $ih < 40) {
            imagedestroy($im);
            return $points;
        }
        $foundR = [];
        foreach ($points as $i => $pt) {
            if (!is_array($pt) || !empty($pt['fit'])) {
                continue;
            }
            $left = $pt['left'] ?? null;
            $top = $pt['top'] ?? null;
            if ($left === null || $top === null || (float) $left < 0 || (float) $top < 0) {
                continue;
            }
            $cx = (int) round($iw * (float) $left / 100);
            $cy = (int) round($ih * (float) $top / 100);
            $fit = self::refineCallout($im, $cx, $cy);
            if ($fit['r'] < 1) {
                continue;
            }
            $diam = $fit['r'] * 2 * 1.18;
            $dw = max(3.2, min(14.0, round($diam / $iw * 100, 2)));
            $dh = max(3.2, min(18.0, round($diam / $ih * 100, 2)));
            $points[$i]['left'] = round($fit['x'] / $iw * 100, 1);
            $points[$i]['top'] = round($fit['y'] / $ih * 100, 1);
            $points[$i]['width'] = $dw;
            $points[$i]['height'] = $dh;
            $points[$i]['fit'] = 1;
            $foundR[] = $fit['r'];
        }
        imagedestroy($im);
        if ($foundR !== []) {
            sort($foundR);
            $medR = $foundR[(int) floor((count($foundR) - 1) / 2)];
            $diam = $medR * 2 * 1.18;
            $dw = max(3.2, min(14.0, round($diam / $iw * 100, 2)));
            $dh = max(3.2, min(18.0, round($diam / $ih * 100, 2)));
            foreach ($points as $i => $pt) {
                if (!is_array($pt)) {
                    continue;
                }
                if (empty($pt['fit'])) {
                    if (isset($pt['left'], $pt['top']) && (float) $pt['left'] >= 0 && (float) $pt['top'] >= 0) {
                        $points[$i]['width'] = $dw;
                        $points[$i]['height'] = $dh;
                        $points[$i]['fit'] = 1;
                    }
                    continue;
                }
                if ((float) ($pt['width'] ?? 0) > $dw * 1.65) {
                    $points[$i]['width'] = $dw;
                    $points[$i]['height'] = $dh;
                }
            }
        }
        if ($productId > 0) {
            try {
                db()->prepare("INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
                    ->execute([$productId, 'diagram_points', json_encode(array_values($points), JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable) {
            }
        }
        return $points;
    }

    /**
     * Aynı parça no’nun A/B/C ürünlerini aynı hotspot koordinatına ekler.
     *
     * @param list<array<string,mixed>> $points
     * @return list<array<string,mixed>>
     */
    public static function expandVariantPoints(int $hostId, string $slug, array $points): array
    {
        $expect = HfsDiagramSync::skuPrefix($slug);
        $have = [];
        $haveSku = [];
        $added = [];
        $stSku = db()->prepare('SELECT sku FROM products WHERE id=? AND status=? LIMIT 1');
        $stId = db()->prepare('SELECT id FROM products WHERE status=? AND sku=? ORDER BY id DESC LIMIT 1');
        foreach ($points as $pt) {
            if (!is_array($pt)) {
                continue;
            }
            $pid = (int) ($pt['product_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $have[$pid] = true;
            $stSku->execute([$pid, 'publish']);
            $sku0 = strtoupper(trim((string) $stSku->fetchColumn()));
            if ($sku0 !== '') {
                $haveSku[$sku0] = true;
            }
        }
        foreach ($points as $pt) {
            if (!is_array($pt)) {
                continue;
            }
            $pid = (int) ($pt['product_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $stSku->execute([$pid, 'publish']);
            $sku = trim((string) $stSku->fetchColumn());
            if ($sku === '') {
                continue;
            }
            $haveSku[strtoupper($sku)] = true;
            if ($expect !== '' && !str_starts_with($sku, $expect)) {
                continue;
            }
            if (!preg_match('/^(.*-)(\d+)-([ABC])$/i', $sku, $m)) {
                continue;
            }
            $pfx = $m[1];
            $num = $m[2];
            foreach (['A', 'B', 'C'] as $suf) {
                $want = $pfx . $num . '-' . $suf;
                if (isset($haveSku[strtoupper($want)])) {
                    continue;
                }
                $stId->execute(['publish', $want]);
                $sid = (int) $stId->fetchColumn();
                if ($sid < 1 || isset($have[$sid])) {
                    continue;
                }
                $np = $pt;
                $np['product_id'] = $sid;
                $np['part'] = ltrim($num, '0') . $suf;
                $added[] = $np;
                $have[$sid] = true;
                $haveSku[strtoupper($want)] = true;
            }
        }
        if ($added === []) {
            return $points;
        }
        $points = array_merge(array_values($points), $added);
        if ($hostId > 0) {
            try {
                db()->prepare("INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
                    ->execute([$hostId, 'diagram_points', json_encode($points, JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable) {
            }
        }
        return $points;
    }

    private static function diagramImage(string $rel): ?\GdImage
    {
        $rel = trim($rel);
        if ($rel === '' || preg_match('/\.pdf($|\?)/i', $rel)) {
            return null;
        }
        $rel = (string) preg_replace('#^https?://[^/]+/(?:hfs-tarim/)?(?:wp-content/uploads|uploads)/#i', '', $rel);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $local = dirname(BMN_ROOT) . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $bin = '';
        if (is_file($local) && filesize($local) > 1000) {
            $bin = (string) file_get_contents($local);
        }
        if (strlen($bin) < 1000) {
            $url = hfs_uploads_remote() . '/' . $rel;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_USERAGENT => 'HFS-Bayi/1.0',
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                $bin = is_string($resp) ? $resp : '';
            } else {
                $bin = (string) (@file_get_contents($url) ?: '');
            }
            if (strlen($bin) > 1000) {
                $dir = dirname($local);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                @file_put_contents($local, $bin);
            }
        }
        if (strlen($bin) < 1000) {
            return null;
        }
        $im = @imagecreatefromstring($bin);
        return $im instanceof \GdImage ? $im : null;
    }

    /** @return array{x:int,y:int,r:int} */
    private static function refineCallout(\GdImage $im, int $cx, int $cy): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $x = max(0, min($w - 1, $cx));
        $y = max(0, min($h - 1, $cy));
        $r = 0;
        $maxD = (int) round(min($w, $h) * 0.16);
        $maxD = max(36, min($maxD, 110));
        for ($iter = 0; $iter < 8; $iter++) {
            $hits = [];
            for ($a = 0; $a < 360; $a += 5) {
                $rad = deg2rad($a);
                $c = cos($rad);
                $s = sin($rad);
                $hit = 0;
                for ($d = 8; $d <= $maxD; $d++) {
                    $px = (int) round($x + $c * $d);
                    $py = (int) round($y + $s * $d);
                    if ($px < 1 || $py < 1 || $px >= $w - 1 || $py >= $h - 1) {
                        break;
                    }
                    if (self::luma($im, $px, $py) > 128) {
                        continue;
                    }
                    $qx = (int) round($x + $c * ($d + 5));
                    $qy = (int) round($y + $s * ($d + 5));
                    if ($qx < 0 || $qy < 0 || $qx >= $w || $qy >= $h) {
                        break;
                    }
                    if (self::luma($im, $qx, $qy) > 168) {
                        $hit = $d;
                    }
                }
                if ($hit > 0) {
                    $hits[] = [$c, $s, $hit];
                }
            }
            if (count($hits) < 16) {
                break;
            }
            $dists = array_column($hits, 2);
            sort($dists);
            $med = $dists[(int) floor(count($dists) / 2)];
            $good = [];
            foreach ($hits as $hit) {
                if (abs($hit[2] - $med) <= 7) {
                    $good[] = $hit;
                }
            }
            if (count($good) < 12) {
                break;
            }
            $dx = 0.0;
            $dy = 0.0;
            $rr = 0.0;
            foreach ($good as $hit) {
                $dx += $hit[0] * ($hit[2] - $med);
                $dy += $hit[1] * ($hit[2] - $med);
                $rr += $hit[2];
            }
            $n = count($good);
            $x = (int) round($x + $dx / $n);
            $y = (int) round($y + $dy / $n);
            $x = max(0, min($w - 1, $x));
            $y = max(0, min($h - 1, $y));
            $r = (int) round($rr / $n);
        }
        return ['x' => $x, 'y' => $y, 'r' => $r];
    }

    private static function luma(\GdImage $im, int $x, int $y): int
    {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 255;
        $g = ($rgb >> 8) & 255;
        $b = $rgb & 255;
        return (int) (($r * 30 + $g * 59 + $b * 11) / 100);
    }

    /** @return list<array<string,mixed>> */
    public static function hosts(int $limit = 50): array
    {
        $st = db()->prepare("SELECT * FROM products WHERE status='publish' AND has_diagram=1 AND slug<>'v2-sirt-model-urun-secici' ORDER BY title ASC LIMIT " . (int) $limit);
        $st->execute();
        return HfsPricing::decorateMany($st->fetchAll());
    }

    public static function healPlacement(): void
    {
        $n = (int) db()->query("SELECT COUNT(*) FROM products p
            LEFT JOIN product_categories pc ON pc.product_id=p.id
            WHERE p.has_diagram=1 AND pc.product_id IS NULL")->fetchColumn();
        $hosts = (int) db()->query("SELECT COUNT(*) FROM products WHERE has_diagram=1")->fetchColumn();
        $meta = (int) db()->query("SELECT COUNT(*) FROM product_meta WHERE meta_key='diagram_image'")->fetchColumn();
        if ($meta > $hosts || $n > 0) {
            self::attachHostsToCategories();
        }
    }

    public static function attachHostsToCategories(): int
    {
        $rows = db()->query("SELECT DISTINCT p.id, p.slug FROM products p
            LEFT JOIN product_meta m ON m.product_id=p.id AND m.meta_key='diagram_image'
            WHERE p.has_diagram=1 OR m.meta_value IS NOT NULL")->fetchAll();
        $ins = db()->prepare('INSERT IGNORE INTO product_categories (product_id,category_id) VALUES (?,?)');
        $flag = db()->prepare('UPDATE products SET has_diagram=1, featured=1 WHERE id=?');
        $updImg = db()->prepare("UPDATE product_meta SET meta_value=? WHERE product_id=? AND meta_key='diagram_image'");
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $d = self::forProduct($id);
            if (!$d) {
                continue;
            }
            $flag->execute([$id]);
            $img = trim((string) self::meta($id, 'diagram_image', ''));
            if ($img !== '' && preg_match('#^https?://#i', $img)) {
                $rel = (string) preg_replace('#^https?://[^/]+/(?:hfs-tarim/)?wp-content/uploads/#i', '', $img);
                if ($rel !== $img) {
                    $updImg->execute([ltrim($rel, '/'), $id]);
                }
            }
            $votes = [];
            $points = self::meta($id, 'diagram_points', []);
            if (is_array($points)) {
                foreach ($points as $point) {
                    if (!is_array($point)) {
                        continue;
                    }
                    foreach (Catalog::catsForProduct((int) ($point['product_id'] ?? 0)) as $c) {
                        $cid = (int) $c['id'];
                        if ($cid > 0) {
                            $votes[$cid] = ($votes[$cid] ?? 0) + 1;
                        }
                    }
                }
            }
            arsort($votes);
            $picked = array_slice(array_keys($votes), 0, 4);
            foreach (self::slugHints((string) $row['slug']) as $hint) {
                $cat = Catalog::categoryByPath($hint) ?: null;
                if (!$cat) {
                    $st = db()->prepare('SELECT * FROM categories WHERE slug=? LIMIT 1');
                    $st->execute([$hint]);
                    $cat = $st->fetch() ?: null;
                }
                if ($cat) {
                    $picked[] = (int) $cat['id'];
                }
            }
            $picked = array_values(array_unique(array_map('intval', $picked)));
            foreach ($picked as $cid) {
                if ($cid < 1) {
                    continue;
                }
                $ins->execute([$id, $cid]);
                $cat = Catalog::categoryById($cid);
                $pid = (int) ($cat['parent_id'] ?? 0);
                if ($pid > 0) {
                    $ins->execute([$id, $pid]);
                }
            }
            $n++;
        }
        return $n;
    }

    /** @return list<string> */
    private static function slugHints(string $slug): array
    {
        $slug = strtolower($slug);
        if (str_contains($slug, 'v2') && (str_contains($slug, 'sirt') || str_contains($slug, 'sırt'))) {
            return ['sirt-sistem-v2-model'];
        }
        if (str_contains($slug, 'v3') && str_contains($slug, 'sirt')) {
            return ['sirt-sistem-v3-model'];
        }
        if (str_contains($slug, 'volt') && (str_contains($slug, 'v3') || str_contains($slug, '33'))) {
            return ['volt-elektrikli-v3-model'];
        }
        if (str_contains($slug, 'volt')) {
            return ['volt-elektrikli-v1-model'];
        }
        if (str_contains($slug, 'yan') && str_contains($slug, 'v2')) {
            return ['yan-sistem-v2-model'];
        }
        if (str_contains($slug, 'yan')) {
            return ['yan-sistem-v1-model'];
        }
        if (str_contains($slug, 'motor')) {
            return ['hfs-motor-yedek-parcalari'];
        }
        return [];
    }

    public static function related(int $productId, int $limit = 8): array
    {
        $cats = Catalog::catsForProduct($productId);
        if ($cats === []) {
            return [];
        }
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $cats);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT p.* FROM products p
            INNER JOIN product_categories pc ON pc.product_id=p.id
            WHERE p.status='publish' AND p.has_diagram=1 AND p.id<>? AND pc.category_id IN ($ph)
            GROUP BY p.id ORDER BY p.title ASC LIMIT " . (int) $limit);
        $st->execute(array_merge([$productId], $ids));
        return HfsMontaj::sortParts(HfsPricing::decorateMany($st->fetchAll()));
    }
}
