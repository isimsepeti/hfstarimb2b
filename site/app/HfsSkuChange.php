<?php
declare(strict_types=1);

/**
 * Excel ile SKU / isim / fiyat senkronu (B2B + Bayt eşleme).
 * ASKI-APARATI → mevcut HFS-SA-V3-67 gibi çakışmalarda hedef ürüne merge eder.
 */
final class HfsSkuChange
{
    /**
     * @return array{
     *   ok:bool,dry:bool,total:int,applied:int,merged:int,skipped:int,missing:int,bayt_ok:int,bayt_fail:int,
     *   rows:list<array<string,mixed>>,errors:list<string>
     * }
     */
    public static function apply(string $xlsxPath, bool $dry = false, bool $syncBayt = true): array
    {
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $rows = HfsXlsx::read($xlsxPath);
        if (is_wp_error($rows)) {
            return self::fail([$rows->get_error_message()]);
        }
        $plan = self::plan($rows);
        $out = [
            'ok' => true,
            'dry' => $dry,
            'total' => count($plan),
            'applied' => 0,
            'merged' => 0,
            'skipped' => 0,
            'missing' => 0,
            'bayt_ok' => 0,
            'bayt_fail' => 0,
            'rows' => [],
            'errors' => [],
        ];
        $pdo = db();
        if (!$dry) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($plan as $item) {
                $res = self::applyOne($item, $dry, $syncBayt);
                $out['rows'][] = $res;
                $action = (string) ($res['action'] ?? '');
                if ($action === 'missing') {
                    $out['missing']++;
                } elseif ($action === 'skip') {
                    $out['skipped']++;
                } elseif ($action === 'merge') {
                    $out['merged']++;
                    $out['applied']++;
                } elseif (in_array($action, ['update', 'rename'], true)) {
                    $out['applied']++;
                }
                if (!empty($res['bayt_ok'])) {
                    $out['bayt_ok']++;
                }
                if (!empty($res['bayt_err'])) {
                    $out['bayt_fail']++;
                    $out['errors'][] = $item['eski'] . ': ' . (string) $res['bayt_err'];
                }
                if (!empty($res['error'])) {
                    $out['errors'][] = (string) $res['error'];
                }
            }
            if (!$dry) {
                $pdo->commit();
                self::refreshMediaSkuCache();
            }
        } catch (Throwable $e) {
            if (!$dry && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return self::fail([$e->getMessage()]);
        }
        $out['ok'] = $out['errors'] === [];
        return $out;
    }

    /** @param list<mixed> $rows */
    private static function plan(array $rows): array
    {
        $header = 0;
        $bucket = [];
        foreach ($rows as $i => $row) {
            if ($i === $header || !is_array($row)) {
                continue;
            }
            $eski = trim((string) ($row[0] ?? ''));
            if ($eski === '') {
                continue;
            }
            $yeni = trim((string) ($row[3] ?? ''));
            $eskiAd = trim((string) ($row[1] ?? ''));
            $yeniAd = trim((string) ($row[4] ?? ''));
            $eskiF = self::num($row[2] ?? null);
            $yeniF = self::num($row[5] ?? null);
            $key = mb_strtoupper($eski);
            $score = ($yeni !== '' ? 4 : 0) + ($yeniAd !== '' ? 2 : 0) + ($yeniF !== null ? 1 : 0);
            if (!isset($bucket[$key]) || $score >= (int) $bucket[$key]['_score']) {
                $bucket[$key] = [
                    'line' => $i + 1,
                    'eski' => $eski,
                    'yeni' => $yeni !== '' ? $yeni : $eski,
                    'eski_ad' => $eskiAd,
                    'yeni_ad' => $yeniAd !== '' ? $yeniAd : $eskiAd,
                    'eski_fiyat' => $eskiF,
                    'yeni_fiyat' => $yeniF ?? $eskiF,
                    '_score' => $score,
                ];
            }
        }
        $out = [];
        foreach ($bucket as $item) {
            unset($item['_score']);
            $skuDiff = strcasecmp($item['eski'], $item['yeni']) !== 0;
            $nameDiff = $item['yeni_ad'] !== '' && self::norm($item['eski_ad']) !== self::norm($item['yeni_ad']);
            $priceDiff = $item['eski_fiyat'] !== null && $item['yeni_fiyat'] !== null
                && abs($item['eski_fiyat'] - $item['yeni_fiyat']) > 0.0001;
            if (!$skuDiff && !$nameDiff && !$priceDiff) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @param array<string,mixed> $item */
    private static function applyOne(array $item, bool $dry, bool $syncBayt): array
    {
        $eski = (string) $item['eski'];
        $yeni = (string) $item['yeni'];
        $title = (string) $item['yeni_ad'];
        $price = $item['yeni_fiyat'];
        $hit = self::findBySku($eski);
        $alreadyNew = false;
        if (!$hit && strcasecmp($eski, $yeni) !== 0) {
            $hit = self::findBySku($yeni);
            $alreadyNew = $hit !== null;
        }
        if (!$hit) {
            return [
                'line' => $item['line'],
                'eski' => $eski,
                'yeni' => $yeni,
                'action' => 'missing',
                'error' => 'Eski SKU bulunamadı',
            ];
        }
        $target = null;
        if (!$alreadyNew && strcasecmp($eski, $yeni) !== 0) {
            $target = self::findBySku($yeni);
        }
        $sameHit = $target
            && (int) $target['product_id'] === (int) $hit['product_id']
            && (int) ($target['variant_id'] ?? 0) === (int) ($hit['variant_id'] ?? 0);

        if ($target && !$sameHit) {
            // Merge: hedef (yeni SKU) kalsın, kaynak draft.
            $res = [
                'line' => $item['line'],
                'eski' => $eski,
                'yeni' => $yeni,
                'action' => 'merge',
                'from_product_id' => (int) $hit['product_id'],
                'to_product_id' => (int) $target['product_id'],
            ];
            if (!$dry) {
                self::writeEntity($target, $yeni, $title, $price);
                self::retireSource($hit, $eski);
            }
            if ($syncBayt && !$dry) {
                $bayt = HfsBayt::remapSku((int) $target['product_id'], $eski, $yeni, $title);
                $res['bayt_ok'] = !empty($bayt['ok']);
                if (empty($bayt['ok'])) {
                    $res['bayt_err'] = (string) ($bayt['error'] ?? 'Bayt eşleme başarısız');
                }
            }
            return $res;
        }

        $action = $alreadyNew ? 'update' : (strcasecmp($eski, $yeni) === 0 ? 'update' : 'rename');
        $res = [
            'line' => $item['line'],
            'eski' => $eski,
            'yeni' => $yeni,
            'action' => $action,
            'product_id' => (int) $hit['product_id'],
            'variant_id' => (int) ($hit['variant_id'] ?? 0),
            'already_new' => $alreadyNew,
        ];
        if (!$dry) {
            // Zaten yeni SKU’daysa yalnızca isim/fiyat; SKU’yu tekrar yaz.
            self::writeEntity($hit, $yeni, $title, $price);
        }
        if ($syncBayt && !$dry && !$alreadyNew && $action === 'rename') {
            $bayt = HfsBayt::remapSku((int) $hit['product_id'], $eski, $yeni, $title);
            $res['bayt_ok'] = !empty($bayt['ok']);
            if (empty($bayt['ok'])) {
                $res['bayt_err'] = (string) ($bayt['error'] ?? 'Bayt eşleme başarısız');
            }
        } elseif ($syncBayt && !$dry && $alreadyNew) {
            // Eski→yeni map’i yine de yaz (Bayt sipariş çözümlemesi).
            $bayt = HfsBayt::remapSku((int) $hit['product_id'], $eski, $yeni, $title);
            $res['bayt_ok'] = !empty($bayt['ok']);
            if (empty($bayt['ok'])) {
                $res['bayt_err'] = (string) ($bayt['error'] ?? 'Bayt eşleme başarısız');
            }
        }
        return $res;
    }

    /** @param array{product_id:int,variant_id:int} $hit */
    private static function writeEntity(array $hit, string $sku, string $title, ?float $price): void
    {
        $vid = (int) ($hit['variant_id'] ?? 0);
        $pid = (int) $hit['product_id'];
        if ($vid > 0) {
            if ($price !== null) {
                db()->prepare('UPDATE variants SET sku=?, name=COALESCE(NULLIF(?, ""), name), price=? WHERE id=?')
                    ->execute([$sku, $title, round($price, 2), $vid]);
            } else {
                db()->prepare('UPDATE variants SET sku=?, name=COALESCE(NULLIF(?, ""), name) WHERE id=?')
                    ->execute([$sku, $title, $vid]);
            }
            return;
        }
        if ($price !== null) {
            db()->prepare('UPDATE products SET sku=?, title=COALESCE(NULLIF(?, ""), title), price=?, modified_at=NOW() WHERE id=?')
                ->execute([$sku, $title, round($price, 2), $pid]);
        } else {
            db()->prepare('UPDATE products SET sku=?, title=COALESCE(NULLIF(?, ""), title), modified_at=NOW() WHERE id=?')
                ->execute([$sku, $title, $pid]);
        }
    }

    /** @param array{product_id:int,variant_id:int} $hit */
    private static function retireSource(array $hit, string $eskiSku): void
    {
        $vid = (int) ($hit['variant_id'] ?? 0);
        $pid = (int) $hit['product_id'];
        $retired = $eskiSku . '__OLD';
        if ($vid > 0) {
            db()->prepare('UPDATE variants SET sku=? WHERE id=?')->execute([$retired, $vid]);
            return;
        }
        // Aynı SKU çakışmasın diye eski kaydı draft + SKU rezerv.
        $n = 0;
        $candidate = $retired;
        while (self::findBySku($candidate)) {
            $n++;
            $candidate = $retired . '-' . $n;
        }
        db()->prepare("UPDATE products SET sku=?, status='draft', modified_at=NOW() WHERE id=?")
            ->execute([$candidate, $pid]);
    }

    /** @return array{product_id:int,variant_id:int}|null */
    private static function findBySku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $st = db()->prepare('SELECT id FROM products WHERE sku=? LIMIT 1');
        $st->execute([$sku]);
        $pid = (int) $st->fetchColumn();
        if ($pid > 0) {
            return ['product_id' => $pid, 'variant_id' => 0];
        }
        $st = db()->prepare('SELECT product_id, id FROM variants WHERE sku=? LIMIT 1');
        $st->execute([$sku]);
        $row = $st->fetch();
        if ($row) {
            return ['product_id' => (int) $row['product_id'], 'variant_id' => (int) $row['id']];
        }
        return null;
    }

    private static function refreshMediaSkuCache(): void
    {
        $cache = BMN_ROOT . '/data/cache/media-by-sku.json';
        if (!is_file($cache)) {
            return;
        }
        $raw = json_decode((string) file_get_contents($cache), true);
        if (!is_array($raw)) {
            return;
        }
        // Cache anahtarları SKU; ürün SKU değişince eski anahtar kalabilir — yeniden üretmek pahalı.
        // En azından ASKI-APARATI → HFS-SA-V3-67 taşı.
        if (isset($raw['ASKI-APARATI']) && !isset($raw['HFS-SA-V3-67'])) {
            $raw['HFS-SA-V3-67'] = $raw['ASKI-APARATI'];
        }
        @file_put_contents($cache, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function num(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return round((float) $raw, 2);
        }
        $s = str_replace(['₺', 'TL', ' '], '', trim((string) $raw));
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, [
            'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        ]);
    }

    /** @param list<string> $errors */
    private static function fail(array $errors): array
    {
        return [
            'ok' => false,
            'dry' => false,
            'total' => 0,
            'applied' => 0,
            'merged' => 0,
            'skipped' => 0,
            'missing' => 0,
            'bayt_ok' => 0,
            'bayt_fail' => 0,
            'rows' => [],
            'errors' => $errors,
        ];
    }
}
