<?php
declare(strict_types=1);

final class HfsProductsExport
{
    /** @return list<array<string, scalar|null>> */
    public static function productRows(string $status = '', string $q = ''): array
    {
        $where = ['1=1'];
        $params = [];
        if (in_array($status, ['publish', 'draft'], true)) {
            $where[] = 'p.status=?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(p.title LIKE ? OR p.sku LIKE ? OR EXISTS (
                SELECT 1 FROM variants vx WHERE vx.product_id=p.id AND (vx.sku LIKE ? OR vx.name LIKE ?)
            ))';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = 'SELECT p.id, p.sku, p.title, p.slug, p.status, p.price, p.compare_price, p.stock, p.type, p.brand, p.featured, p.excerpt,
            (SELECT GROUP_CONCAT(c.path ORDER BY c.path SEPARATOR \' | \') FROM product_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.product_id=p.id) AS categories
            FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.title ASC, p.id ASC';
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, scalar|null>> */
    public static function variantRows(string $status = '', string $q = ''): array
    {
        $where = ['1=1'];
        $params = [];
        if (in_array($status, ['publish', 'draft'], true)) {
            $where[] = 'p.status=?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(p.title LIKE ? OR p.sku LIKE ? OR v.sku LIKE ? OR v.name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = 'SELECT p.id AS product_id, p.sku AS product_sku, p.title AS product_title, v.id AS variant_id, v.sku, v.name, v.price, v.stock, v.sort
            FROM variants v INNER JOIN products p ON p.id=v.product_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY p.title ASC, v.sort ASC, v.id ASC';
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$v) {
            $v['sku'] = self::fullVariantSku((string) ($v['product_sku'] ?? ''), (string) ($v['sku'] ?? ''), (string) ($v['name'] ?? ''));
        }
        unset($v);
        return $rows;
    }

    /** Ana kod + varyant soneki (örn. HFS-SA-V3-58-kawasaki). */
    public static function fullVariantSku(string $productSku, string $variantSku, string $variantName = ''): string
    {
        $productSku = trim($productSku);
        $variantSku = trim($variantSku);
        if ($variantSku !== '') {
            if ($productSku === '' || str_starts_with(strtoupper($variantSku), strtoupper($productSku))) {
                return $variantSku;
            }
            return rtrim($productSku, '-') . '-' . ltrim($variantSku, '-');
        }
        $suf = $variantName !== '' && function_exists('slugify') ? slugify($variantName) : '';
        if ($productSku !== '' && $suf !== '') {
            return rtrim($productSku, '-') . '-' . $suf;
        }
        return $productSku !== '' ? $productSku : $suf;
    }

    /** @param list<array<string, scalar|null>> $variants @return array<int, list<string>> */
    private static function variantSkuMap(array $variants): array
    {
        $map = [];
        foreach ($variants as $v) {
            $pid = (int) ($v['product_id'] ?? 0);
            $sku = trim((string) ($v['sku'] ?? ''));
            if ($pid < 1 || $sku === '') {
                continue;
            }
            $map[$pid][] = $sku;
        }
        return $map;
    }

    public static function sendCsv(string $status = '', string $q = ''): void
    {
        $products = self::productRows($status, $q);
        $variants = self::variantRows($status, $q);
        $vmap = self::variantSkuMap($variants);
        $name = 'hfs-urunler-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Ürün kodu', 'Varyant kodları', 'Başlık', 'Slug', 'Durum', 'Fiyat', 'Eski fiyat', 'Stok', 'Tür', 'Marka', 'Öne çıkan', 'Kategoriler', 'Kısa özet'], ';');
        foreach ($products as $p) {
            $pid = (int) $p['id'];
            fputcsv($out, [
                $pid,
                (string) $p['sku'],
                isset($vmap[$pid]) ? implode(' | ', $vmap[$pid]) : '',
                (string) $p['title'],
                (string) $p['slug'],
                (string) $p['status'],
                (float) $p['price'],
                $p['compare_price'] !== null && $p['compare_price'] !== '' ? (float) $p['compare_price'] : '',
                (int) $p['stock'],
                (string) $p['type'],
                (string) $p['brand'],
                !empty($p['featured']) ? '1' : '0',
                (string) ($p['categories'] ?? ''),
                (string) ($p['excerpt'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    public static function sendXlsx(string $status = '', string $q = ''): void
    {
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        @set_time_limit(300);
        $products = self::productRows($status, $q);
        $variants = self::variantRows($status, $q);
        $vmap = self::variantSkuMap($variants);
        $pRows = [['ID', 'Ürün kodu', 'Varyant kodları', 'Başlık', 'Slug', 'Durum', 'Fiyat', 'Eski fiyat', 'Stok', 'Tür', 'Marka', 'Öne çıkan', 'Kategoriler', 'Kısa özet']];
        foreach ($products as $p) {
            $pid = (int) $p['id'];
            $pRows[] = [
                $pid,
                (string) $p['sku'],
                isset($vmap[$pid]) ? implode(' | ', $vmap[$pid]) : '',
                (string) $p['title'],
                (string) $p['slug'],
                (string) $p['status'],
                (float) $p['price'],
                $p['compare_price'] !== null && $p['compare_price'] !== '' ? (float) $p['compare_price'] : '',
                (int) $p['stock'],
                (string) $p['type'],
                (string) $p['brand'],
                !empty($p['featured']) ? 1 : 0,
                (string) ($p['categories'] ?? ''),
                (string) ($p['excerpt'] ?? ''),
            ];
        }
        $vRows = [['Ürün ID', 'Ana kod', 'Ürün adı', 'Varyant ID', 'Varyant kodu', 'Varyant adı', 'Fiyat', 'Stok']];
        foreach ($variants as $v) {
            $vRows[] = [
                (int) $v['product_id'],
                (string) $v['product_sku'],
                (string) $v['product_title'],
                (int) $v['variant_id'],
                (string) $v['sku'],
                (string) $v['name'],
                $v['price'] !== null && $v['price'] !== '' ? (float) $v['price'] : '',
                (int) $v['stock'],
            ];
        }
        $flat = [['Stok kodu', 'Ürün adı', 'Varyant adı', 'Ürün ID', 'Varyant ID', 'Fiyat', 'Stok', 'Durum']];
        $withVar = [];
        foreach ($variants as $v) {
            $pid = (int) $v['product_id'];
            $withVar[$pid] = true;
            $flat[] = [
                (string) $v['sku'],
                (string) $v['product_title'],
                (string) $v['name'],
                $pid,
                (int) $v['variant_id'],
                $v['price'] !== null && $v['price'] !== '' ? (float) $v['price'] : '',
                (int) $v['stock'],
                '',
            ];
        }
        foreach ($products as $p) {
            $pid = (int) $p['id'];
            if (isset($withVar[$pid])) {
                continue;
            }
            $flat[] = [
                (string) $p['sku'],
                (string) $p['title'],
                '',
                $pid,
                0,
                (float) $p['price'],
                (int) $p['stock'],
                (string) $p['status'],
            ];
        }
        $info = [
            ['Ürün dışa aktarma'],
            ['Tarih: ' . date('d.m.Y H:i')],
            ['Ürünler sayfası: ' . count($products) . ' satır'],
            ['Varyantlar sayfası: ' . count($variants) . ' satır'],
            ['Stok kodları sayfası: ' . (count($flat) - 1) . ' satır'],
            ['Filtre durum: ' . ($status !== '' ? $status : 'tümü')],
            ['Not: Varyantlı ürünlerde tam kodlar (örn. HFS-SA-V3-58-kawasaki) Ürünler / Varyantlar / Stok kodları sayfalarındadır.'],
        ];
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfs-urun-export-' . bin2hex(random_bytes(4)) . '.xlsx';
        $written = HfsXlsx::write([
            ['name' => 'Ürünler', 'rows' => $pRows, 'col_types' => ['n', 's', 's', 's', 's', 's', 'n', 'n', 'n', 's', 's', 'n', 's', 's']],
            ['name' => 'Varyantlar', 'rows' => $vRows, 'col_types' => ['n', 's', 's', 'n', 's', 's', 'n', 'n']],
            ['name' => 'Stok kodları', 'rows' => $flat, 'col_types' => ['s', 's', 's', 'n', 'n', 'n', 'n', 's']],
            ['name' => 'Bilgi', 'rows' => $info, 'col_types' => ['s']],
        ], $tmp);
        if (is_wp_error($written)) {
            http_response_code(500);
            echo $written->get_error_message();
            exit;
        }
        $name = 'hfs-urunler-' . gmdate('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
