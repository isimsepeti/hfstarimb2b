<?php
declare(strict_types=1);

final class HfsPrices
{
    public static function export(): void
    {
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $rows = [['Ürün Kodu', 'Adı', 'Fiyat']];
        $st = db()->query("SELECT sku, title, price FROM products WHERE sku<>'' ORDER BY id");
        foreach ($st->fetchAll() as $p) {
            $rows[] = [$p['sku'], $p['title'], (float) $p['price']];
        }
        $vs = db()->query("SELECT v.sku, CONCAT(p.title, ' — ', v.name) AS title, COALESCE(v.price, p.price) AS price
            FROM variants v INNER JOIN products p ON p.id=v.product_id WHERE v.sku<>'' ORDER BY v.id");
        foreach ($vs->fetchAll() as $p) {
            $rows[] = [$p['sku'], $p['title'], (float) $p['price']];
        }
        $talimat = [
            ['Talimat'],
            ['1. Yalnızca Fiyat sütununu değiştirin.'],
            ['2. Ürün Kodunu değiştirmeyin.'],
            ['3. Adı sütunu yüklemede kullanılmaz.'],
            ['4. Dosyayı .xlsx olarak kaydedin (CSV değil).'],
            ['5. Fiyatlar katalog (normal) fiyattır; bayi iskontosu ayrıca uygulanır.'],
        ];
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfs-fiyat-' . bin2hex(random_bytes(4)) . '.xlsx';
        $written = HfsXlsx::write([
            ['name' => 'Fiyatlar', 'rows' => $rows, 'col_types' => ['s', 's', 'n']],
            ['name' => 'Talimat', 'rows' => $talimat, 'col_types' => ['s']],
        ], $tmp);
        if (is_wp_error($written)) {
            http_response_code(500);
            echo $written->get_error_message();
            exit;
        }
        $name = 'hfs-urun-fiyatlari-' . gmdate('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public static function exportDealer(?array $u = null): void
    {
        $u = $u ?? current_user();
        if (!$u) {
            redirect(site_url('giris/'));
        }
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $rows = [['SKU', 'Ürün', 'Liste fiyatı', 'Bayi fiyatı', 'Stok']];
        $st = db()->query("SELECT id, sku, title, price, stock FROM products WHERE status='publish' AND sku<>'' ORDER BY title");
        foreach ($st->fetchAll() as $p) {
            $list = (float) $p['price'];
            $rows[] = [
                (string) $p['sku'],
                (string) $p['title'],
                $list,
                HfsPricing::apply($list, $u, (int) $p['id'], 1),
                (int) $p['stock'],
            ];
        }
        $vs = db()->query("SELECT v.sku, CONCAT(p.title, ' — ', v.name) AS title, COALESCE(v.price, p.price) AS price, v.stock, p.id AS product_id
            FROM variants v INNER JOIN products p ON p.id=v.product_id
            WHERE p.status='publish' AND v.sku<>'' ORDER BY p.title, v.name");
        foreach ($vs->fetchAll() as $p) {
            $list = (float) $p['price'];
            $rows[] = [
                (string) $p['sku'],
                (string) $p['title'],
                $list,
                HfsPricing::apply($list, $u, (int) $p['product_id'], 1),
                (int) $p['stock'],
            ];
        }
        $co = HfsPricing::company($u);
        $name = trim((string) ($co['company'] ?? $co['display_name'] ?? $u['username'] ?? 'bayi'));
        $talimat = [
            ['Bayi fiyat listesi'],
            ['Firma: ' . $name],
            ['Tarih: ' . date('d.m.Y H:i')],
            ['Bayi fiyatı hesabınıza özeldir (iskonto / teklif).'],
            ['Stok anlıktır; sipariş öncesi siteden kontrol edin.'],
        ];
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfs-bayi-fiyat-' . bin2hex(random_bytes(4)) . '.xlsx';
        $written = HfsXlsx::write([
            ['name' => 'Fiyatlar', 'rows' => $rows, 'col_types' => ['s', 's', 'n', 'n', 'n']],
            ['name' => 'Bilgi', 'rows' => $talimat, 'col_types' => ['s']],
        ], $tmp);
        if (is_wp_error($written)) {
            http_response_code(500);
            echo $written->get_error_message();
            exit;
        }
        $file = 'hfs-bayi-fiyat-' . gmdate('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('X-Robots-Tag: noindex');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** @return array{updated:int,unchanged:int,skipped:int,errors:list<string>,not_found:list<array{line:int,sku:string}>,skipped_rows:list<array{line:int,sku:string,reason:string}>,updated_rows:list<array{sku:string,old:float,new:float}>,price_warnings:list<array{line:int,sku:string,old:float,new:float,code:string,message:string}>} */
    public static function import(string $tmpPath): array
    {
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $rows = HfsXlsx::read($tmpPath, 'Fiyatlar');
        if (is_wp_error($rows)) {
            return self::emptyImportReport([$rows->get_error_message()]);
        }
        $map = self::detectColumns($rows);
        if ($map === null) {
            return self::emptyImportReport(['Excel’de SKU / Ürün Kodu ve Fiyat (veya Liste / Bayi fiyatı) sütunları bulunamadı.']);
        }
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $errors = [];
        $notFound = [];
        $skippedRows = [];
        $updatedRows = [];
        $priceWarnings = [];
        $queue = [];
        foreach ($rows as $i => $row) {
            if ($i === $map['header']) {
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row[$map['sku']] ?? ''));
            $raw = $row[$map['price']] ?? '';
            if ($sku === '' && ($raw === '' || $raw === null)) {
                continue;
            }
            $line = $i + 1;
            if ($sku === '') {
                $skipped++;
                $reason = 'ürün kodu boş';
                $skippedRows[] = ['line' => $line, 'sku' => '', 'reason' => $reason];
                $errors[] = 'Satır ' . $line . ': ' . $reason . '.';
                continue;
            }
            $price = self::parsePrice($raw);
            if ($price === null) {
                $skipped++;
                $reason = 'geçersiz fiyat';
                $skippedRows[] = ['line' => $line, 'sku' => $sku, 'reason' => $reason];
                $errors[] = 'Satır ' . $line . ' (' . $sku . '): ' . $reason . '.';
                continue;
            }
            $hit = Catalog::bySku($sku);
            if (!$hit) {
                $st = db()->prepare('SELECT id FROM products WHERE sku=? LIMIT 1');
                $st->execute([$sku]);
                $pid = (int) $st->fetchColumn();
                if ($pid > 0) {
                    $hit = ['product_id' => $pid, 'variant_id' => 0];
                } else {
                    $st = db()->prepare('SELECT product_id, id FROM variants WHERE sku=? LIMIT 1');
                    $st->execute([$sku]);
                    $vr = $st->fetch();
                    $hit = $vr ? ['product_id' => (int) $vr['product_id'], 'variant_id' => (int) $vr['id']] : null;
                }
            }
            if (!$hit) {
                $skipped++;
                $notFound[] = ['line' => $line, 'sku' => $sku];
                $errors[] = 'Satır ' . $line . ': ürün kodu bulunamadı (' . $sku . ').';
                continue;
            }
            $new = round($price, 2);
            $oldN = self::currentPrice($hit);
            $queue[] = ['line' => $line, 'sku' => $sku, 'new' => $new, 'old' => $oldN, 'hit' => $hit];
        }
        $familyMedians = self::familyPriceMedians($queue);
        $updP = db()->prepare('UPDATE products SET price=? WHERE id=?');
        $updV = db()->prepare('UPDATE variants SET price=? WHERE id=?');
        foreach ($queue as $item) {
            foreach (self::priceWarningsForRow($item, $familyMedians) as $w) {
                $priceWarnings[$item['sku'] . '|' . $w['code']] = $w;
            }
            $sku = $item['sku'];
            $new = $item['new'];
            $oldN = $item['old'];
            $hit = $item['hit'];
            if ($oldN === $new) {
                $unchanged++;
                continue;
            }
            if ((int) $hit['variant_id'] > 0) {
                $updV->execute([$new, (int) $hit['variant_id']]);
                self::noteUpdatedRow($updatedRows, $sku, $oldN, $new);
            } else {
                $updP->execute([$new, (int) $hit['product_id']]);
                self::noteUpdatedRow($updatedRows, $sku, $oldN, $new);
            }
            $updated++;
        }
        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'errors' => $errors,
            'not_found' => $notFound,
            'skipped_rows' => $skippedRows,
            'updated_rows' => array_values($updatedRows),
            'price_warnings' => array_values($priceWarnings),
        ];
    }

    /** @param array<string, array{sku:string,old:float,new:float}> $bucket */
    private static function noteUpdatedRow(array &$bucket, string $sku, float $old, float $new): void
    {
        if (!isset($bucket[$sku])) {
            $bucket[$sku] = ['sku' => $sku, 'old' => $old, 'new' => $new];
            return;
        }
        $bucket[$sku]['new'] = $new;
    }

    /** @param list<string> $errors */
    private static function emptyImportReport(array $errors): array
    {
        return [
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'errors' => $errors,
            'not_found' => [],
            'skipped_rows' => [],
            'updated_rows' => [],
            'price_warnings' => [],
        ];
    }

    /** @param array{product_id:int,variant_id:int} $hit */
    private static function currentPrice(array $hit): float
    {
        if ((int) $hit['variant_id'] > 0) {
            $cur = db()->prepare('SELECT price FROM variants WHERE id=?');
            $cur->execute([(int) $hit['variant_id']]);
            $old = $cur->fetchColumn();
            return $old === null || $old === '' ? 0.0 : round((float) $old, 2);
        }
        $cur = db()->prepare('SELECT price FROM products WHERE id=?');
        $cur->execute([(int) $hit['product_id']]);
        return round((float) $cur->fetchColumn(), 2);
    }

    /**
     * @param list<array{line:int,sku:string,new:float,old:float,hit:array}> $queue
     * @return array<string, float>
     */
    private static function familyPriceMedians(array $queue): array
    {
        $groups = [];
        foreach ($queue as $item) {
            $key = self::priceFamilyKey($item['sku']);
            if ($key === null) {
                continue;
            }
            $groups[$key][] = $item['new'];
        }
        $out = [];
        foreach ($groups as $key => $prices) {
            if (count($prices) < 3) {
                continue;
            }
            sort($prices);
            $n = count($prices);
            $mid = (int) floor($n / 2);
            $out[$key] = $n % 2 === 1 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2;
        }
        return $out;
    }

    private static function priceFamilyKey(string $sku): ?string
    {
        $sku = strtoupper($sku);
        if (str_contains($sku, 'MOTORSUZ') && substr_count($sku, '-') >= 5) {
            return null;
        }
        if (preg_match('/^HFS-(SA|YA)-01-[A-Z0-9-]+$/', $sku) !== 1) {
            return null;
        }
        if (strlen($sku) > 40) {
            return null;
        }
        return preg_replace('/^HFS-(SA|YA)-01-.+$/', 'HFS-$1-01', $sku) ?? null;
    }

    /**
     * @param array{line:int,sku:string,new:float,old:float,hit:array} $item
     * @param array<string, float> $familyMedians
     * @return list<array{line:int,sku:string,old:float,new:float,code:string,message:string}>
     */
    private static function priceWarningsForRow(array $item, array $familyMedians): array
    {
        $line = (int) $item['line'];
        $sku = $item['sku'];
        $old = (float) $item['old'];
        $new = (float) $item['new'];
        $out = [];
        $fmt = static fn(float $v): string => number_format($v, 2, ',', '.');

        if ($new <= 0) {
            $out[] = self::warnRow($line, $sku, $old, $new, 'zero', 'Fiyat sıfır veya negatif.');
            return $out;
        }
        if ($new > 0 && $new < 500 && self::looksLikeMainMachineSku($sku)) {
            $out[] = self::warnRow(
                $line,
                $sku,
                $old,
                $new,
                'machine_low',
                'Ana makine SKU’su için fiyat olağandışı düşük (< 500 ₺).'
            );
        }
        if ($old >= 1000 && $new <= $old * 0.2 && ($old - $new) >= 500) {
            $out[] = self::warnRow(
                $line,
                $sku,
                $old,
                $new,
                'sharp_drop',
                'Katalog fiyatına göre çok sert düşüş (≈ %' . (string) max(1, (int) round(100 - ($new / $old) * 100)) . ').'
            );
        }
        if ($old >= 100 && $new >= $old * 5 && ($new - $old) >= 5000) {
            $out[] = self::warnRow(
                $line,
                $sku,
                $old,
                $new,
                'sharp_rise',
                'Katalog fiyatına göre olağandışı artış (5× üzeri).'
            );
        }
        $family = self::priceFamilyKey($sku);
        if ($family !== null && isset($familyMedians[$family])) {
            $median = $familyMedians[$family];
            if ($median >= 3000 && $new > 0 && $new < $median * 0.08) {
                $out[] = self::warnRow(
                    $line,
                    $sku,
                    $old,
                    $new,
                    'file_outlier',
                    'Bu Excel’de aynı serideki satırların tipik fiyatı ~' . $fmt($median) . ' ₺; bu satır belirgin düşük.'
                );
            }
        }
        return $out;
    }

    private static function looksLikeMainMachineSku(string $sku): bool
    {
        return self::priceFamilyKey($sku) !== null;
    }

    /** @return array{line:int,sku:string,old:float,new:float,code:string,message:string} */
    private static function warnRow(int $line, string $sku, float $old, float $new, string $code, string $message): array
    {
        return [
            'line' => $line,
            'sku' => $sku,
            'old' => $old,
            'new' => $new,
            'code' => $code,
            'message' => $message,
        ];
    }

    private static function detectColumns(array $rows): ?array
    {
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = null;
            $price = null;
            $liste = null;
            $bayi = null;
            foreach ($row as $c => $cell) {
                $t = mb_strtolower(trim((string) $cell));
                if (in_array($t, ['ürün kodu', 'urun kodu', 'sku', 'kod'], true)) {
                    $sku = (int) $c;
                }
                if (in_array($t, ['liste fiyatı', 'liste fiyati', 'liste fiyat', 'list price'], true)) {
                    $liste = (int) $c;
                }
                if (in_array($t, ['bayi fiyatı', 'bayi fiyati', 'bayi fiyat'], true)) {
                    $bayi = (int) $c;
                }
                if (in_array($t, ['fiyat', 'price'], true)) {
                    $price = (int) $c;
                }
            }
            $priceCol = $liste ?? $price ?? $bayi;
            if ($sku !== null && $priceCol !== null) {
                return ['header' => $i, 'sku' => $sku, 'price' => $priceCol];
            }
        }
        return null;
    }

    private static function parsePrice(mixed $raw): ?float
    {
        if ($raw === '' || $raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $s = trim((string) $raw);
        $s = str_replace(['₺', 'TL', ' '], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }
}
