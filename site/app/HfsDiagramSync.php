<?php
declare(strict_types=1);

/**
 * Canlı montaj PNG’lerindeki numaralı baloncukları okuyup
 * yerel diyagram hotspot’larına çevirir.
 */
final class HfsDiagramSync
{
    private const FONT = 'C:\\Windows\\Fonts\\arialbd.ttf';

    /** @var list<string> */
    private static array $log = [];

    /** @return list<string> */
    public static function run(bool $force = false, ?string $only = null): array
    {
        self::$log = [];
        @set_time_limit(0);
        ini_set('memory_limit', '1024M');
        if (!extension_loaded('gd')) {
            self::$log[] = 'GD eklentisi yok, diyagram senkronu atlandı.';
            return self::$log;
        }
        $n = 0;
        foreach (self::hosts() as $host) {
            if ($only !== null && $only !== '' && (string) $host['slug'] !== $only && (string) $host['id'] !== $only) {
                continue;
            }
            $id = (int) $host['id'];
            $have = HfsDiagram::meta($id, 'diagram_points', []);
            if (!$force && self::hasPlacedHotspots($have)) {
                continue;
            }
            try {
                $ok = self::healHost($host);
            } catch (Throwable $e) {
                self::$log[] = $host['slug'] . ' hata: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
                continue;
            }
            if ($ok) {
                $n++;
                self::$log[] = $host['slug'] . ' → ' . $ok . ' parça noktası';
            } else {
                self::$log[] = $host['slug'] . ' diyagramı kurulamadı';
            }
        }
        if ($n > 0) {
            HfsDiagram::attachHostsToCategories();
        }
        foreach (self::repairPartSkuLinks($only) as $line) {
            self::$log[] = $line;
        }
        self::$log[] = $n . ' montaj ürününe parça diyagramı yazıldı.';
        return self::$log;
    }

    /**
     * Hotspot part no ile ürün SKU uyuşmuyorsa (ör. #3 → HFS-SA-V2-1) düzeltilir.
     *
     * @return list<string>
     */
    public static function repairPartSkuLinks(?string $only = null): array
    {
        $out = [];
        $stSku = db()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
        foreach (self::hosts() as $host) {
            $slug = (string) ($host['slug'] ?? '');
            $id = (int) ($host['id'] ?? 0);
            if ($only !== null && $only !== '' && $slug !== $only && (string) $id !== $only
                && !($only === 'yan' && str_starts_with($slug, 'yan-sistem-'))) {
                continue;
            }
            $points = HfsDiagram::meta($id, 'diagram_points', []);
            if (!is_array($points) || $points === []) {
                continue;
            }
            $prefix = self::skuPrefix($slug);
            $changed = 0;
            foreach ($points as &$pt) {
                if (!is_array($pt)) {
                    continue;
                }
                $part = trim((string) ($pt['part'] ?? ''));
                $pid = (int) ($pt['product_id'] ?? 0);
                if ($part === '' || $pid < 1) {
                    continue;
                }
                $stSku->execute([$pid]);
                $sku = strtoupper(trim((string) $stSku->fetchColumn()));
                $want = preg_match('/^(\d+)/', strtoupper($part), $m) ? ltrim($m[1], '0') : strtoupper($part);
                if ($want === '') {
                    $want = strtoupper($part);
                }
                $wrongPrefix = $prefix !== '' && $sku !== '' && !str_starts_with($sku, $prefix);
                $skuTail = ($prefix !== '' && str_starts_with($sku, $prefix))
                    ? substr($sku, strlen($prefix))
                    : $sku;
                $have = preg_match('/^(\d+)/', $skuTail, $m2) ? ltrim($m2[1], '0') : '';
                if (!$wrongPrefix && $have !== '' && $have === $want) {
                    continue;
                }
                $pids = self::productsForPart($slug, $part);
                if ($pids === [] || $pids[0] === $pid) {
                    continue;
                }
                $pt['product_id'] = $pids[0];
                $changed++;
            }
            unset($pt);
            if ($changed > 0) {
                self::upsertMeta($id, 'diagram_points', json_encode(array_values($points), JSON_UNESCAPED_UNICODE));
                $out[] = $slug . ': ' . $changed . ' hotspot SKU düzeltildi';
            }
        }
        return $out;
    }

    /**
     * Excel / manuel: [slug, part, wrongSku, rightSku]
     * @param list<array{0:string,1:string,2:string,3:string}> $rows
     * @return list<string>
     */
    public static function applySkuRemap(array $rows): array
    {
        $out = [];
        $byHost = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }
            $slug = trim((string) $row[0]);
            $part = strtoupper(trim((string) $row[1]));
            $wrong = strtoupper(trim((string) $row[2]));
            $right = strtoupper(trim((string) $row[3]));
            if ($slug === '' || $part === '' || $right === '') {
                continue;
            }
            $byHost[$slug][] = [$part, $wrong, $right];
        }
        $stHost = db()->prepare("SELECT id FROM products WHERE slug=? AND status='publish' LIMIT 1");
        $stSku = db()->prepare("SELECT id FROM products WHERE status='publish' AND sku=? ORDER BY id DESC LIMIT 1");
        foreach ($byHost as $slug => $fixes) {
            $stHost->execute([$slug]);
            $hostId = (int) $stHost->fetchColumn();
            if ($hostId < 1) {
                $out[] = $slug . ': host yok';
                continue;
            }
            $points = HfsDiagram::meta($hostId, 'diagram_points', []);
            if (!is_array($points) || $points === []) {
                $out[] = $slug . ': hotspot yok';
                continue;
            }
            $changed = 0;
            $missing = [];
            foreach ($fixes as [$part, $wrong, $right]) {
                $stSku->execute([$right]);
                $rightId = (int) $stSku->fetchColumn();
                if ($rightId < 1) {
                    $missing[] = $right;
                    continue;
                }
                foreach ($points as &$pt) {
                    if (!is_array($pt)) {
                        continue;
                    }
                    $ptPart = strtoupper(trim((string) ($pt['part'] ?? '')));
                    $pid = (int) ($pt['product_id'] ?? 0);
                    if ($pid < 1) {
                        continue;
                    }
                    $stCur = db()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
                    $stCur->execute([$pid]);
                    $curSku = strtoupper(trim((string) $stCur->fetchColumn()));
                    $partMatch = $ptPart === $part
                        || ltrim($ptPart, '0') === ltrim($part, '0')
                        || (preg_match('/^(\d+)/', $ptPart, $a) && preg_match('/^(\d+)/', $part, $b) && ltrim($a[1], '0') === ltrim($b[1], '0'));
                    $skuMatch = $wrong !== '' && $curSku === $wrong;
                    if (!$partMatch && !$skuMatch) {
                        continue;
                    }
                    if ($pid === $rightId) {
                        continue;
                    }
                    $pt['product_id'] = $rightId;
                    if ($ptPart === '' || $skuMatch) {
                        $pt['part'] = preg_match('/^(\d+[A-Z]?)/', $part, $pm) ? $pm[1] : $part;
                    }
                    $changed++;
                }
                unset($pt);
            }
            if ($changed > 0) {
                self::upsertMeta($hostId, 'diagram_points', json_encode(array_values($points), JSON_UNESCAPED_UNICODE));
            }
            $msg = $slug . ': ' . $changed . ' eşleme';
            if ($missing !== []) {
                $msg .= ' (SKU yok: ' . implode(',', array_unique($missing)) . ')';
            }
            $out[] = $msg;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function hosts(): array
    {
        return db()->query("SELECT * FROM products WHERE status='publish' AND (
            slug LIKE 'sirt-sistem-v2-model-%'
            OR slug LIKE 'sirt-sistem-v3-model-%'
            OR slug LIKE 'yan-sistem-v1-model-grup-%'
            OR slug LIKE 'yan-sistem-v2-model-grup-%'
            OR slug LIKE 'volt-sistem-12v-%'
            OR slug LIKE 'volt-sistem-33v-%'
        ) ORDER BY slug")->fetchAll() ?: [];
    }

    /** @param array<string,mixed> $host */
    private static function healHost(array $host): int
    {
        $id = (int) $host['id'];
        $slug = (string) $host['slug'];
        $existing = HfsDiagram::meta($id, 'diagram_image', '');
        $rel = is_string($existing) && $existing !== '' ? $existing : self::resolveImage($host);
        if ($rel === '') {
            $rel = self::resolveImage($host);
        }
        if ($rel === '') {
            return 0;
        }
        $layout = self::knownLayout($slug);
        if ($layout !== []) {
            $points = self::hydrateLayout($slug, $layout);
        } else {
            $points = self::indexPoints($slug);
        }
        if ($points === []) {
            $points = self::fallbackPoints($host);
        }
        if ($points === []) {
            return 0;
        }
        self::upsertMeta($id, 'diagram_image', $rel);
        self::upsertMeta($id, 'diagram_points', json_encode(array_values($points), JSON_UNESCAPED_UNICODE));
        db()->prepare('UPDATE products SET has_diagram=1, featured=1 WHERE id=?')->execute([$id]);
        return count($points);
    }

    /** @param array<string,mixed> $host */
    private static function resolveImage(array $host): string
    {
        $slug = (string) $host['slug'];
        $feat = str_replace('\\', '/', (string) ($host['featured_media'] ?? ''));
        $feat = preg_replace('#^https?://[^/]+/(?:hfs-tarim/)?wp-content/uploads/#i', '', $feat) ?? $feat;
        $feat = ltrim((string) $feat, '/');
        $cands = [];
        $compact = str_replace(['on-elcik', 'ön-elcik'], 'onelcik', $slug);
        $compact = (string) preg_replace('/grup-(\d+)/', 'grup$1', $compact);
        foreach (array_unique([$compact, $slug]) as $s) {
            $cands[] = '2026/06/' . $s . '-0.png';
            $cands[] = '2026/06/' . $s . '.png';
        }
        if ($feat !== '' && preg_match('/\.(png|jpe?g|svg|pdf)$/i', $feat)) {
            $cands[] = $feat;
        }
        if (str_starts_with($slug, 'volt-sistem-12v-')) {
            $cands[] = '2026/06/Volt-Sistem-12V-govde-grubu-0.png';
            $cands[] = '2026/06/Volt-Sistem-12V-kol-grubu-0.png';
        }
        if (str_starts_with($slug, 'volt-sistem-33v-')) {
            $cands[] = '2026/10/volt-sistem-33v-govde-grubu.png';
            $cands[] = '2026/10/volt-sistem-33v-kol-grubu.png';
        }
        $cands = array_values(array_unique(array_filter($cands)));
        usort($cands, static function (string $a, string $b): int {
            $as = str_contains($a, '-0.') ? 0 : 1;
            $bs = str_contains($b, '-0.') ? 0 : 1;
            return $as <=> $bs;
        });
        foreach (array_slice($cands, 0, 6) as $rel) {
            $bin = self::download($rel);
            if (self::isImage($bin)) {
                return $rel;
            }
        }
        return '';
    }

    private static function isImage(string $bin): bool
    {
        if (strlen($bin) < 1000) {
            return false;
        }
        $sig = substr($bin, 0, 8);
        return str_starts_with($sig, "\x89PNG")
            || str_starts_with($sig, "\xFF\xD8")
            || str_starts_with($bin, '%PDF')
            || str_starts_with($bin, '<svg')
            || str_contains(substr($bin, 0, 200), '<svg');
    }

    private static function remoteOk(string $rel): bool
    {
        if (HfsMediaCutover::localFileOk($rel)) {
            return true;
        }
        $url = rtrim(HfsMediaCutover::LEGACY_WP_UPLOADS, '/') . '/' . str_replace('\\', '/', $rel);
        $code = self::httpCode($url);
        return $code === 200;
    }

    private static function httpCode(string $url): int
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'HFS-Bayi-Sync/1.0',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code;
        }
        $h = @get_headers($url, true);
        if (!is_array($h)) {
            return 0;
        }
        if (preg_match('/\s(\d{3})\s/', (string) ($h[0] ?? ''), $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private static function download(string $rel): string
    {
        $local = HfsMediaCutover::absPath($rel);
        if (is_file($local) && filesize($local) > 1000) {
            $bin = (string) file_get_contents($local);
            if (self::isImage($bin)) {
                return $bin;
            }
        }
        $url = rtrim(HfsMediaCutover::LEGACY_WP_UPLOADS, '/') . '/' . str_replace('\\', '/', $rel);
        $bin = self::httpGet($url);
        if (self::isImage($bin)) {
            $dir = dirname($local);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($local, $bin);
            return $bin;
        }
        return '';
    }

    private static function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'HFS-Bayi-Sync/1.0',
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            return is_string($resp) ? $resp : '';
        }
        return (string) (@file_get_contents($url) ?: '');
    }

    /** @return list<array{product_id:int,left?:float,top?:float,part:string}> */
    private static function pointsFromImage(\GdImage $src, string $slug): array
    {
        $ow = imagesx($src);
        $oh = imagesy($src);
        if ($ow < 20 || $oh < 20) {
            return [];
        }
        $layout = self::knownLayout($slug);
        if ($layout !== []) {
            return self::hydrateLayout($slug, $layout);
        }
        $badges = self::findBadges($src);
        $labels = self::possibleLabels($slug);
        $points = [];
        foreach ($badges as $b) {
            $part = self::readLabel($src, $b, $labels);
            if ($part === '') {
                continue;
            }
            $pids = self::productsForPart($slug, $part);
            if ($pids === []) {
                continue;
            }
            $left = round(($b['x'] / $ow) * 100, 1);
            $top = round(($b['y'] / $oh) * 100, 1);
            foreach ($pids as $pid) {
                $points[] = [
                    'product_id' => $pid,
                    'left' => $left,
                    'top' => $top,
                    'part' => $part,
                ];
            }
        }
        if ($points === []) {
            return self::indexPoints($slug);
        }
        $have = [];
        foreach ($points as $pt) {
            $have[$pt['part']] = true;
        }
        foreach (self::hydrateLayout($slug, array_map(static fn (string $lab): array => ['part' => $lab, 'left' => -1, 'top' => -1], $labels)) as $extra) {
            if (!isset($have[$extra['part']])) {
                $points[] = $extra;
            }
        }
        return $points;
    }

    /**
     * @return list<array{part:string,left:float,top:float}>
     */
    private static function knownLayout(string $slug): array
    {
        return match ($slug) {
            'sirt-sistem-v2-model-kelepce' => [
                ['part' => '54', 'left' => 14.5, 'top' => 65.9],
                ['part' => '53', 'left' => 25.7, 'top' => 67.2],
                ['part' => '52', 'left' => 33.6, 'top' => 66.7],
                ['part' => '52', 'left' => 37.6, 'top' => 67.6],
                ['part' => '52', 'left' => 41.6, 'top' => 67.6],
                ['part' => '51', 'left' => 56.8, 'top' => 67.8],
                ['part' => '49', 'left' => 86.5, 'top' => 62.7],
                ['part' => '50', 'left' => 48.3, 'top' => 36.5],
                ['part' => '50', 'left' => 69.2, 'top' => 36.5],
            ],
            'volt-sistem-33v-govde-grubu' => (static function (): array {
                // rOuter≈18 (mürekkep halkası ~16 + çizgi kalınlığı); 2-C / 25 daha geniş.
                $s = ['width' => 2.90, 'height' => 2.05];
                $l = ['width' => 4.35, 'height' => 3.08];
                $p = static fn (string $part, float $left, float $top, array $sz): array => array_merge([
                    'part' => $part,
                    'left' => $left,
                    'top' => $top,
                ], $sz);
                return [
                    $p('12', 63.87, 14.94, $s),
                    $p('13', 52.74, 16.76, $s),
                    $p('14', 45.40, 17.73, $s),
                    $p('15', 36.53, 18.99, $s),
                    $p('16', 29.44, 19.90, $s),
                    $p('17', 19.03, 33.75, $s),
                    $p('18', 18.95, 40.25, $s),
                    $p('19', 18.95, 43.50, $s),
                    $p('21', 19.19, 49.54, $s),
                    $p('22', 18.95, 53.65, $s),
                    $p('23', 18.95, 57.47, $s),
                    $p('24', 18.87, 64.14, $s),
                    $p('20', 51.53, 45.72, $s),
                    $p('11', 93.79, 42.99, $s),
                    $p('5', 93.71, 45.55, $s),
                    $p('10', 91.53, 48.97, $s),
                    $p('9', 89.27, 52.68, $s),
                    $p('8', 87.58, 55.36, $s),
                    $p('7', 85.65, 58.27, $s),
                    $p('6', 82.74, 62.83, $s),
                    $p('5', 80.08, 67.05, $s),
                    $p('4', 78.23, 69.73, $s),
                    $p('3', 76.13, 73.20, $s),
                    $p('2-C', 73.71, 77.99, $l),
                    $p('1', 65.16, 90.82, $s),
                    $p('4', 23.23, 83.98, $s),
                    $p('25-G', 18.15, 71.90, $l),
                    $p('25-E', 18.15, 75.60, $l),
                    $p('26', 70.50, 71.60, $s),
                ];
            })(),
            'volt-sistem-33v-kol-grubu' => (static function (): array {
                $s = ['width' => 3.71, 'height' => 2.62];
                $l = ['width' => 5.16, 'height' => 3.65];
                $xs = ['width' => 2.58, 'height' => 1.82];
                // diameter from measured callout radius: w%=2r/1240*100, h%=2r/1754*100
                $sz = static fn (int $r): array => [
                    'width' => round($r * 2 * 100 / 1240, 2),
                    'height' => round($r * 2 * 100 / 1754, 2),
                ];
                $p = static fn (string $part, float $left, float $top, array $size): array => array_merge([
                    'part' => $part,
                    'left' => $left,
                    'top' => $top,
                ], $size);
                return [
                    $p('51', 20.24, 20.47, $s),
                    $p('52', 30.40, 20.41, $s),
                    $p('53', 40.97, 20.41, $s),
                    $p('54', 21.05, 37.91, $s),
                    $p('55', 40.56, 31.70, $s),
                    $p('32', 51.85, 17.33, $sz(22)),
                    $p('33', 53.60, 15.14, $s),
                    $p('34', 72.26, 11.29, $s),
                    $p('5', 75.97, 11.52, $xs),
                    $p('35', 81.05, 11.29, $s),
                    $p('36-C', 88.79, 42.42, $sz(34)),
                    $p('37', 83.15, 48.92, $s),
                    $p('38', 81.05, 51.94, $s),
                    $p('39-C', 79.35, 55.47, $l),
                    $p('40', 76.69, 59.46, $s),
                    $p('41-C', 74.92, 62.66, $sz(29)),
                    $p('42', 72.66, 65.39, $s),
                    $p('43', 69.27, 70.30, $s),
                    $p('44', 64.44, 77.59, $s),
                    $p('45', 57.10, 88.60, $s),
                    $p('26-A', 18.23, 66.59, $sz(33)),
                    $p('31', 26.61, 55.36, $s),
                    $p('30', 23.87, 59.41, $s),
                    $p('29', 21.85, 62.60, $s),
                    $p('47', 10.40, 88.14, $s),
                    $p('46', 13.79, 91.51, $s),
                    $p('27', 34.27, 92.08, $sz(33)),
                    $p('4', 42.10, 91.16, $s),
                ];
            })(),
            default => [],
        };
    }

    /**
     * @param list<array{part:string,left?:float,top?:float,width?:float,height?:float}> $layout
     * @return list<array{product_id:int,left?:float,top?:float,width?:float,height?:float,fit?:int,part:string}>
     */
    private static function hydrateLayout(string $slug, array $layout): array
    {
        $points = [];
        foreach ($layout as $row) {
            $part = (string) ($row['part'] ?? '');
            if ($part === '') {
                continue;
            }
            $pids = self::productsForPart($slug, $part);
            if ($pids === []) {
                continue;
            }
            $left = $row['left'] ?? null;
            $top = $row['top'] ?? null;
            $ww = isset($row['width']) ? (float) $row['width'] : 0.0;
            $hh = isset($row['height']) ? (float) $row['height'] : 0.0;
            foreach ($pids as $pid) {
                $pt = [
                    'product_id' => $pid,
                    'part' => $part,
                ];
                if ($left !== null && $top !== null && (float) $left >= 0 && (float) $top >= 0) {
                    $pt['left'] = (float) $left;
                    $pt['top'] = (float) $top;
                    $pt['fit'] = 1;
                    if ($ww > 0) {
                        $pt['width'] = $ww;
                    }
                    if ($hh > 0) {
                        $pt['height'] = $hh;
                    }
                }
                $points[] = $pt;
            }
        }
        return $points;
    }

    /** @return list<array{product_id:int,left:float,top:float,part:string}> */
    private static function indexPoints(string $slug): array
    {
        $labels = self::possibleLabels($slug);
        if ($labels === []) {
            return [];
        }
        usort($labels, static fn (string $a, string $b): int => strnatcmp($a, $b));
        $uniq = [];
        foreach ($labels as $lab) {
            $uniq[(string) $lab] = true;
        }
        $labels = array_keys($uniq);
        $points = [];
        $used = [];
        foreach ($labels as $lab) {
            $pids = self::productsForPart($slug, (string) $lab);
            if ($pids === []) {
                continue;
            }
            foreach ($pids as $pid) {
                if (isset($used[$pid])) {
                    continue;
                }
                $used[$pid] = true;
                $points[] = [
                    'product_id' => $pid,
                    'part' => (string) $lab,
                ];
            }
        }
        return $points;
    }

    /** @return array<int,int> 0-255 */
    private static function gray(\GdImage $im): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $out = [];
        $i = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 255;
                $g = ($rgb >> 8) & 255;
                $b = $rgb & 255;
                $out[$i++] = (int) (($r * 30 + $g * 59 + $b * 11) / 100);
            }
        }
        $dil = $out;
        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                if ($out[$y * $w + $x] >= 120) {
                    continue;
                }
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $dil[($y + $dy) * $w + ($x + $dx)] = min($dil[($y + $dy) * $w + ($x + $dx)], 40);
                    }
                }
            }
        }
        return $dil;
    }

    /**
     * @return list<array{x:int,y:int,r:int}>
     */
    private static function findBadges(\GdImage $im): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $win = 52;
        $outer = 96;
        $step = 16;
        $cands = [];
        for ($y = (int) round($h * 0.08); $y < $h - $win; $y += $step) {
            for ($x = 16; $x < $w - $win; $x += $step) {
                $inner = self::darkCount($im, $x, $y, $win, $w, $h);
                if ($inner < 12 || $inner > 82) {
                    continue;
                }
                $ox = $x - (int) (($outer - $win) / 2);
                $oy = $y - (int) (($outer - $win) / 2);
                $ring = self::darkCount($im, $ox, $oy, $outer, $w, $h) - $inner;
                if ($ring > 22) {
                    continue;
                }
                $cands[] = [
                    'x' => (int) round($x + $win / 2),
                    'y' => (int) round($y + $win / 2),
                    'r' => 26,
                    's' => $inner - ($ring * 0.4),
                ];
            }
        }
        usort($cands, static fn (array $a, array $b): int => $b['s'] <=> $a['s']);
        $kept = [];
        foreach ($cands as $c) {
            foreach ($kept as $k) {
                if (abs($c['x'] - $k['x']) < 40 && abs($c['y'] - $k['y']) < 40) {
                    continue 2;
                }
            }
            $kept[] = self::refineBadge($im, (int) round($c['x']), (int) round($c['y']));
        }
        return $kept;
    }

    /** @return array{x:int,y:int,r:int} */
    private static function refineBadge(\GdImage $im, int $x, int $y): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $rad = 34;
        $minx = $w;
        $miny = $h;
        $maxx = 0;
        $maxy = 0;
        $n = 0;
        for ($yy = max(0, $y - $rad); $yy < min($h, $y + $rad); $yy++) {
            for ($xx = max(0, $x - $rad); $xx < min($w, $x + $rad); $xx++) {
                if (self::lum($im, $xx, $yy) > 85) {
                    continue;
                }
                $n++;
                $minx = min($minx, $xx);
                $miny = min($miny, $yy);
                $maxx = max($maxx, $xx);
                $maxy = max($maxy, $yy);
            }
        }
        if ($n < 8) {
            return ['x' => $x, 'y' => $y, 'r' => 26];
        }
        return [
            'x' => (int) round(($minx + $maxx) / 2),
            'y' => (int) round(($miny + $maxy) / 2),
            'r' => max(22, (int) round(max($maxx - $minx, $maxy - $miny) / 2)),
        ];
    }

    private static function darkCount(\GdImage $im, int $x0, int $y0, int $size, int $w, int $h): int
    {
        $n = 0;
        $x1 = min($w, $x0 + $size);
        $y1 = min($h, $y0 + $size);
        $x0 = max(0, $x0);
        $y0 = max(0, $y0);
        for ($y = $y0; $y < $y1; $y += 2) {
            for ($x = $x0; $x < $x1; $x += 2) {
                if (self::lum($im, $x, $y) < 90) {
                    $n++;
                }
            }
        }
        return $n;
    }

    private static function lum(\GdImage $im, int $x, int $y): int
    {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 255;
        $g = ($rgb >> 8) & 255;
        $b = $rgb & 255;
        return (int) (($r * 30 + $g * 59 + $b * 11) / 100);
    }

    /**
     * @param array{x:int,y:int,r:int} $b
     * @param list<string> $labels
     */
    private static function readLabel(\GdImage $im, array $b, array $labels): string
    {
        $size = max(56, (int) round($b['r'] * 2.6));
        $crop = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($crop, 255, 255, 255);
        imagefill($crop, 0, 0, $white);
        $sx = $b['x'] - (int) round($size / 2);
        $sy = $b['y'] - (int) round($size / 2);
        imagecopy($crop, $im, 0, 0, $sx, $sy, $size, $size);
        $keepR = (int) round($size * 0.30);
        $cx = (int) round($size / 2);
        $bg = imagecolorallocate($crop, 255, 255, 255);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x - $cx;
                $dy = $y - $cx;
                if (($dx * $dx + $dy * $dy) > $keepR * $keepR) {
                    imagesetpixel($crop, $x, $y, $bg);
                }
            }
        }
        $norm = self::normalizeGlyph($crop);
        imagedestroy($crop);
        if ($norm === null) {
            return '';
        }
        $best = '';
        $bestS = 0.12;
        foreach ($labels as $lab) {
            $lab = (string) $lab;
            $s = self::matchGlyph($norm, $lab);
            if ($s > $bestS) {
                $bestS = $s;
                $best = $lab;
            }
        }
        imagedestroy($norm);
        return $best;
    }

    private static function normalizeGlyph(\GdImage $crop): ?\GdImage
    {
        $w = imagesx($crop);
        $h = imagesy($crop);
        $minx = $w;
        $miny = $h;
        $maxx = 0;
        $maxy = 0;
        $found = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (self::lum($crop, $x, $y) > 90) {
                    continue;
                }
                $found++;
                $minx = min($minx, $x);
                $miny = min($miny, $y);
                $maxx = max($maxx, $x);
                $maxy = max($maxy, $y);
            }
        }
        if ($found < 12 || $maxx <= $minx || $maxy <= $miny) {
            return null;
        }
        $pad = 2;
        $minx = max(0, $minx - $pad);
        $miny = max(0, $miny - $pad);
        $maxx = min($w - 1, $maxx + $pad);
        $maxy = min($h - 1, $maxy + $pad);
        $out = imagecreatetruecolor(36, 36);
        $bg = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $bg);
        imagecopyresized($out, $crop, 2, 2, $minx, $miny, 32, 32, $maxx - $minx + 1, $maxy - $miny + 1);
        return $out;
    }

    private static function matchGlyph(\GdImage $norm, string $lab): float
    {
        if (!is_file(self::FONT) || $lab === '') {
            return 0;
        }
        $best = 0.0;
        foreach ([18, 22, 26] as $fs) {
            $tmpl = imagecreatetruecolor(36, 36);
            $bg = imagecolorallocate($tmpl, 255, 255, 255);
            $fg = imagecolorallocate($tmpl, 0, 0, 0);
            imagefill($tmpl, 0, 0, $bg);
            $box = imagettfbbox($fs, 0, self::FONT, $lab);
            if ($box === false) {
                imagedestroy($tmpl);
                continue;
            }
            $tw = $box[2] - $box[0];
            $th = $box[1] - $box[7];
            $x = (int) round((36 - $tw) / 2 - $box[0]);
            $y = (int) round((36 + $th) / 2 - $box[1]);
            imagettftext($tmpl, $fs, 0, $x, $y, $fg, self::FONT, $lab);
            $same = 0;
            $n = 0;
            for ($yy = 0; $yy < 36; $yy++) {
                for ($xx = 0; $xx < 36; $xx++) {
                    $ab = self::lum($norm, $xx, $yy) < 110;
                    $bb = self::lum($tmpl, $xx, $yy) < 110;
                    if ($ab || $bb) {
                        $n++;
                        if ($ab === $bb) {
                            $same++;
                        }
                    }
                }
            }
            imagedestroy($tmpl);
            $s = $n > 10 ? $same / $n : 0;
            if ($s > $best) {
                $best = $s;
            }
        }
        return $best;
    }

    /** @return list<string> */
    private static function possibleLabels(string $slug): array
    {
        $range = self::partRange($slug);
        if ($range !== []) {
            return array_map('strval', $range);
        }
        $prefix = self::skuPrefix($slug);
        if ($prefix === '') {
            return [];
        }
        $st = db()->prepare("SELECT sku FROM products WHERE status='publish' AND sku LIKE ?");
        $st->execute([$prefix . '%']);
        $labs = [];
        foreach ($st->fetchAll() as $row) {
            if (preg_match('/(\d+)(?:-[A-Za-z])?$/', (string) $row['sku'], $m)) {
                $n = ltrim($m[1], '0');
                $labs[(string) ($n === '' ? $m[1] : $n)] = true;
            }
        }
        $out = array_map('strval', array_keys($labs));
        usort($out, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strnatcmp($a, $b));
        return $out;
    }

    /** @return list<int|string> */
    private static function partRange(string $slug): array
    {
        $slug = strtolower($slug);
        if (str_starts_with($slug, 'sirt-sistem-v2-model-')) {
            $tail = substr($slug, strlen('sirt-sistem-v2-model-'));
            return match (true) {
                str_contains($tail, 'kelepce') => range(49, 54),
                str_contains($tail, 'sanziman') => array_merge(range(1, 30), ['22-A']),
                str_contains($tail, 'elcik') => range(31, 37),
                str_contains($tail, 'tahrik') => [46, 47, 48, 49],
                str_contains($tail, 'tetik') => range(31, 45),
                default => [],
            };
        }
        if (str_starts_with($slug, 'sirt-sistem-v3-model-')) {
            $tail = substr($slug, strlen('sirt-sistem-v3-model-'));
            return match (true) {
                str_contains($tail, 'kelepce') => range(44, 50),
                str_contains($tail, 'sanziman') => range(1, 25),
                str_contains($tail, 'kanca') => array_merge(range(26, 30), [60, 61]),
                str_contains($tail, 'tahrik') => [41, 42, 43],
                str_contains($tail, 'tetik') => range(31, 40),
                str_contains($tail, 'motor') => range(55, 59),
                default => [],
            };
        }
        if (preg_match('/volt-sistem-12v-govde/', $slug)) {
            return range(1, 26);
        }
        if (preg_match('/volt-sistem-12v-kol/', $slug)) {
            return range(27, 41);
        }
        if (preg_match('/volt-sistem-33v-govde/', $slug)) {
            return array_merge(range(1, 26), ['25-G', '25-E']);
        }
        if (preg_match('/volt-sistem-33v-kol/', $slug)) {
            return array_merge(range(27, 55), ['26-A', '36-C', '39-C', '41-C']);
        }
        return [];
    }

    /** @return list<int> */
    private static function productsForPart(string $slug, string $part): array
    {
        $prefix = self::skuPrefix($slug);
        if ($prefix === '') {
            return [];
        }
        $part = strtoupper(trim($part));
        $base = $part;
        $letter = '';
        if (preg_match('/^(\d+)-([A-Z]+)$/', $part, $m)) {
            $base = $m[1];
            $letter = $m[2];
        } elseif (preg_match('/^(\d+)([A-Z]+)$/', $part, $m)) {
            $base = $m[1];
            $letter = $m[2];
        }
        $n = ltrim($base, '0');
        if ($n === '') {
            $n = $base;
        }
        $padded = ctype_digit($n) ? str_pad($n, 2, '0', STR_PAD_LEFT) : $n;
        $skus = [];
        foreach (array_unique(array_filter([$part, $n, $padded, $base])) as $num) {
            $skus[] = $prefix . $num;
            foreach (['-A', '-B', '-C', '-G', '-E', 'A', 'B', 'C', 'G', 'E'] as $suf) {
                $skus[] = $prefix . $num . $suf;
            }
        }
        if ($letter !== '') {
            $skus[] = $prefix . $n . $letter;
            $skus[] = $prefix . $n . '-' . $letter;
            $skus[] = $prefix . $padded . $letter;
            $skus[] = $prefix . $padded . '-' . $letter;
        }
        return self::idsForSkus(array_unique($skus));
    }

    /** @param list<string> $skus @return list<int> */
    private static function idsForSkus(array $skus): array
    {
        $ids = [];
        $st = db()->prepare("SELECT p.id,
            (SELECT COUNT(*) FROM product_categories pc WHERE pc.product_id=p.id) cats
            FROM products p WHERE p.status='publish' AND p.sku=? ORDER BY cats DESC, p.id DESC");
        foreach ($skus as $sku) {
            $st->execute([$sku]);
            $row = $st->fetch();
            if ($row && (int) $row['id'] > 0) {
                $ids[(int) $row['id']] = true;
            }
        }
        return array_values(array_map('intval', array_keys($ids)));
    }

    public static function skuPrefix(string $slug): string
    {
        if (preg_match('/^sirt-sistem-v2-model-/', $slug)) {
            return 'HFS-SA-V2-';
        }
        if (preg_match('/^sirt-sistem-v3-model-/', $slug)) {
            return 'HFS-SA-V3-';
        }
        // Yan sistem SKU: HFS-YA-V1-{grup}-{parça} (ör. HFS-YA-V1-1-25)
        if (preg_match('/^yan-sistem-v([12])-model-grup-(\d+)/', $slug, $m)) {
            return 'HFS-YA-V' . $m[1] . '-' . $m[2] . '-';
        }
        if (preg_match('/^volt-sistem-12v-/', $slug)) {
            return 'HFS-ELK -V1-';
        }
        if (preg_match('/^volt-sistem-33v-/', $slug)) {
            return 'HFS-ELK -V3-';
        }
        return '';
    }

    /** @param array<string,mixed> $host @return list<array{product_id:int,left:float,top:float,part:string}> */
    private static function fallbackPoints(array $host): array
    {
        return [];
    }

    private static function upsertMeta(int $productId, string $key, string $value): void
    {
        db()->prepare('INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)')->execute([$productId, $key, $value]);
    }

    private static function hasPlacedHotspots(mixed $have): bool
    {
        if (!is_array($have) || $have === []) {
            return false;
        }
        foreach ($have as $pt) {
            if (!is_array($pt)) {
                continue;
            }
            $left = $pt['left'] ?? null;
            $top = $pt['top'] ?? null;
            if ($left !== null && $top !== null && (float) $left >= 0 && (float) $top >= 0) {
                return true;
            }
        }
        return false;
    }
}
