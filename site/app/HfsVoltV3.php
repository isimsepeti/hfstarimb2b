<?php
declare(strict_types=1);

/** Volt Elektrikli V3 / 33V diyagram + yedek parça kurulumu. */
final class HfsVoltV3
{
    public const CAT_SLUG = 'volt-elektrikli-v3-model';
    public const MONTAJ_SLUG = 'volt-model-33v-montaj';
    public const PREFIX = 'HFS-ELK -V3-';

    /**
     * @param list<array{sku:string,title:string,price:float,part:string,group:string}> $parts
     * @param array{govde:string,kol:string,placeholder:string} $images relative uploads paths
     * @return array<string,mixed>
     */
    public static function install(array $parts, array $images): array
    {
        $out = [
            'ok' => true,
            'category_id' => 0,
            'montaj_id' => 0,
            'products' => 0,
            'updated' => 0,
            'hosts' => [],
            'steps' => [],
        ];
        $yedekId = self::categoryIdBySlug('yedek-parcalar');
        if ($yedekId < 1) {
            return ['ok' => false, 'error' => 'yedek-parcalar kategorisi yok'];
        }
        $catId = self::ensureCategory(self::CAT_SLUG, 'Volt Elektrikli V3 Model', $yedekId, 'yedek-parcalar/' . self::CAT_SLUG, 0);
        $out['category_id'] = $catId;
        $out['steps'][] = 'kategori #' . $catId;

        $montajParent = self::categoryIdBySlug('sistem-montajlari');
        if ($montajParent < 1) {
            $montajParent = self::ensureCategory('sistem-montajlari', 'Sistem Montajları', null, 'sistem-montajlari', 1);
        }
        $montajId = self::ensureCategory(self::MONTAJ_SLUG, 'Volt Model 33V Montaj', $montajParent, 'sistem-montajlari/' . self::MONTAJ_SLUG, 1);
        $out['montaj_id'] = $montajId;
        $out['steps'][] = 'montaj #' . $montajId;

        $placeholder = trim((string) ($images['placeholder'] ?? '2026/10/hfs-placeholder-part.png'));
        $byPart = [];
        foreach ($parts as $row) {
            $sku = self::normalizeSku((string) ($row['sku'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $price = (float) ($row['price'] ?? 0);
            $part = (string) ($row['part'] ?? '');
            if ($sku === '' || $title === '') {
                continue;
            }
            $slug = slugify($sku);
            $id = self::upsertProduct([
                'sku' => $sku,
                'slug' => $slug,
                'title' => mb_strtoupper($title, 'UTF-8'),
                'price' => $price,
                'stock' => 100,
                'featured_media' => $placeholder,
                'has_diagram' => 0,
            ]);
            self::linkCategory($id, $catId);
            $out['products']++;
            if ($part !== '') {
                $byPart[$part] = $id;
                $byPart[ltrim($part, '0')] = $id;
            }
        }
        $out['steps'][] = 'parça ürün ' . $out['products'];

        $hosts = [
            [
                'slug' => 'volt-sistem-33v-govde-grubu',
                'title' => 'Volt Sistem 33V Gövde Grubu',
                'image' => (string) ($images['govde'] ?? ''),
                'group' => 'govde',
            ],
            [
                'slug' => 'volt-sistem-33v-kol-grubu',
                'title' => 'Volt Sistem 33V Kol Grubu',
                'image' => (string) ($images['kol'] ?? ''),
                'group' => 'kol',
            ],
        ];
        foreach ($hosts as $h) {
            $hid = self::upsertProduct([
                'sku' => '',
                'slug' => $h['slug'],
                'title' => $h['title'],
                'price' => 0,
                'stock' => 100,
                'featured_media' => $h['image'] !== '' ? $h['image'] : $placeholder,
                'has_diagram' => 1,
            ]);
            self::linkCategory($hid, $catId);
            self::linkCategory($hid, $montajId);
            if ($h['image'] !== '') {
                self::upsertMeta($hid, 'diagram_image', $h['image']);
            }
            $points = [];
            foreach ($parts as $row) {
                if (($row['group'] ?? '') !== $h['group']) {
                    continue;
                }
                $part = (string) ($row['part'] ?? '');
                $pid = (int) ($byPart[$part] ?? $byPart[ltrim($part, '0')] ?? 0);
                if ($pid < 1 || $part === '') {
                    continue;
                }
                $points[] = [
                    'product_id' => $pid,
                    'part' => $part,
                    'left' => -1,
                    'top' => -1,
                ];
            }
            self::upsertMeta($hid, 'diagram_points', json_encode(array_values($points), JSON_UNESCAPED_UNICODE));
            $out['hosts'][] = [
                'id' => $hid,
                'slug' => $h['slug'],
                'points' => count($points),
            ];
        }
        try {
            HfsDiagramSync::run(true, 'volt-sistem-33v-govde-grubu');
            HfsDiagramSync::run(true, 'volt-sistem-33v-kol-grubu');
        } catch (Throwable) {
        }
        try {
            HfsDiagram::attachHostsToCategories();
        } catch (Throwable) {
        }
        $out['steps'][] = 'diyagram host ' . count($out['hosts']);
        return $out;
    }

    /** @return list<array{sku:string,title:string,price:float,part:string,group:string}> */
    public static function parseCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $lines = preg_split('/\R/u', $raw) ?: [];
        $out = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || $i === 0) {
                continue;
            }
            $cols = str_getcsv($line, ';');
            if (count($cols) < 3) {
                $cols = str_getcsv($line, ',');
            }
            if (count($cols) < 3) {
                continue;
            }
            $sku = self::normalizeSku((string) $cols[0]);
            $title = trim((string) $cols[1]);
            $priceRaw = trim((string) $cols[2]);
            $priceRaw = str_replace(' ', '', $priceRaw);
            $priceRaw = str_replace(',', '.', $priceRaw);
            $price = (float) $priceRaw;
            $part = self::partFromSku($sku);
            $num = (int) preg_replace('/\D.*$/', '', $part);
            $group = $num > 0 && $num <= 26 ? 'govde' : 'kol';
            $out[] = [
                'sku' => $sku,
                'title' => $title,
                'price' => $price,
                'part' => $part,
                'group' => $group,
            ];
        }
        return $out;
    }

    public static function normalizeSku(string $sku): string
    {
        $sku = trim($sku);
        $sku = preg_replace('/\s+/', ' ', $sku) ?? $sku;
        // CSV: "HFS-ELK -V3-01" — V1 ile aynı boşluk kalıbı.
        if (preg_match('/^HFS-ELK\s*-?\s*V3-(.+)$/i', $sku, $m)) {
            return 'HFS-ELK -V3-' . strtoupper(trim($m[1]));
        }
        return $sku;
    }

    public static function partFromSku(string $sku): string
    {
        $sku = self::normalizeSku($sku);
        if (preg_match('/V3-(.+)$/i', $sku, $m)) {
            return strtoupper(trim($m[1]));
        }
        return '';
    }

    private static function categoryIdBySlug(string $slug): int
    {
        $st = db()->prepare('SELECT id FROM categories WHERE slug=? LIMIT 1');
        $st->execute([$slug]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    private static function ensureCategory(string $slug, string $name, ?int $parentId, string $path, int $hideNav): int
    {
        $id = self::categoryIdBySlug($slug);
        if ($id > 0) {
            try {
                db()->prepare('UPDATE categories SET name=?, parent_id=?, path=?, hide_nav=? WHERE id=?')
                    ->execute([$name, $parentId, $path, $hideNav, $id]);
            } catch (Throwable) {
                db()->prepare('UPDATE categories SET name=?, parent_id=?, path=? WHERE id=?')
                    ->execute([$name, $parentId, $path, $id]);
            }
            return $id;
        }
        try {
            db()->prepare('INSERT INTO categories (slug,name,parent_id,path,hide_nav) VALUES (?,?,?,?,?)')
                ->execute([$slug, $name, $parentId, $path, $hideNav]);
        } catch (Throwable) {
            db()->prepare('INSERT INTO categories (slug,name,parent_id,path) VALUES (?,?,?,?)')
                ->execute([$slug, $name, $parentId, $path]);
        }
        return (int) db()->lastInsertId();
    }

    /** @param array{sku:string,slug:string,title:string,price:float,stock:int,featured_media:string,has_diagram:int} $p */
    private static function upsertProduct(array $p): int
    {
        $slug = (string) $p['slug'];
        $st = db()->prepare('SELECT id, featured_media FROM products WHERE slug=? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch();
        if ($row) {
            $id = (int) $row['id'];
            $media = trim((string) ($row['featured_media'] ?? ''));
            $keep = $media !== '' && !str_contains($media, 'placeholder') ? $media : (string) $p['featured_media'];
            db()->prepare('UPDATE products SET sku=?, title=?, price=?, stock=?, featured_media=?, has_diagram=?, status=\'publish\', modified_at=NOW() WHERE id=?')
                ->execute([(string) $p['sku'], (string) $p['title'], (float) $p['price'], (int) $p['stock'], $keep, (int) $p['has_diagram'], $id]);
            return $id;
        }
        if ($p['sku'] !== '') {
            $st = db()->prepare('SELECT id FROM products WHERE sku=? LIMIT 1');
            $st->execute([(string) $p['sku']]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                db()->prepare('UPDATE products SET slug=?, title=?, price=?, stock=?, featured_media=IF(featured_media=\'\' OR featured_media LIKE \'%placeholder%\', ?, featured_media), has_diagram=?, status=\'publish\', modified_at=NOW() WHERE id=?')
                    ->execute([$slug, (string) $p['title'], (float) $p['price'], (int) $p['stock'], (string) $p['featured_media'], (int) $p['has_diagram'], $id]);
                return $id;
            }
        }
        db()->prepare('INSERT INTO products (slug,sku,title,excerpt,content,price,compare_price,stock,status,featured,featured_media,type,has_diagram,published_at,modified_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([
                $slug,
                (string) $p['sku'],
                (string) $p['title'],
                '',
                '',
                (float) $p['price'],
                null,
                (int) $p['stock'],
                'publish',
                0,
                (string) $p['featured_media'],
                'simple',
                (int) $p['has_diagram'],
            ]);
        return (int) db()->lastInsertId();
    }

    private static function linkCategory(int $productId, int $categoryId): void
    {
        if ($productId < 1 || $categoryId < 1) {
            return;
        }
        db()->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?,?)')
            ->execute([$productId, $categoryId]);
    }

    private static function upsertMeta(int $productId, string $key, string $value): void
    {
        db()->prepare('INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)')
            ->execute([$productId, $key, $value]);
    }
}
