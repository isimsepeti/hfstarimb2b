<?php
declare(strict_types=1);

final class HfsOffers
{
    public static function parseGifts(string $str): array
    {
        $out = [];
        $str = trim($str);
        if ($str === '') {
            return $out;
        }
        foreach (preg_split('/\s*,\s*/', $str) ?: [] as $part) {
            if (!preg_match('/^(\d+)\s*[:xX\/]\s*(\d+)$/', trim($part), $m)) {
                continue;
            }
            $min = (int) $m[1];
            $free = (int) $m[2];
            if ($min > 0 && $free > 0) {
                $out[] = ['min' => $min, 'free' => $free];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        return $out;
    }

    public static function formatGifts(array $gifts): string
    {
        $gifts = self::normalize($gifts);
        $parts = [];
        foreach ($gifts as $g) {
            $parts[] = (int) $g['min'] . ':' . (int) $g['free'];
        }
        return implode(', ', $parts);
    }

    /** @return list<array{min:int,free:int}> */
    public static function normalize(mixed $gifts): array
    {
        if (is_string($gifts)) {
            return self::parseGifts($gifts);
        }
        if (!is_array($gifts)) {
            return [];
        }
        $out = [];
        foreach ($gifts as $g) {
            if (!is_array($g)) {
                continue;
            }
            $min = (int) ($g['min'] ?? 0);
            $free = (int) ($g['free'] ?? 0);
            if ($min > 0 && $free > 0) {
                $out[] = ['min' => $min, 'free' => $free];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        return $out;
    }

    public static function parseQtyPrices(string $str): array
    {
        $out = [];
        $str = trim($str);
        if ($str === '') {
            return $out;
        }
        foreach (preg_split('/\s*,\s*/', $str) ?: [] as $part) {
            if (!preg_match('/^(\d+)\s*[:xX=\/]\s*(\d+(?:[.,]\d+)?)$/', trim($part), $m)) {
                continue;
            }
            $min = (int) $m[1];
            $price = (float) str_replace(',', '.', $m[2]);
            if ($min > 0 && $price > 0) {
                $out[] = ['min' => $min, 'price' => round($price, 2)];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        return $out;
    }

    public static function formatQtyPrices(array $tiers): string
    {
        $tiers = self::normalizeQty($tiers);
        $parts = [];
        foreach ($tiers as $t) {
            $p = (float) $t['price'];
            $parts[] = (int) $t['min'] . ':' . (fmod($p, 1.0) === 0.0 ? (string) (int) $p : rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.'));
        }
        return implode(', ', $parts);
    }

    /** @return list<array{min:int,price:float}> */
    public static function normalizeQty(mixed $tiers): array
    {
        if (is_string($tiers)) {
            return self::parseQtyPrices($tiers);
        }
        if (!is_array($tiers)) {
            return [];
        }
        $out = [];
        foreach ($tiers as $t) {
            if (!is_array($t)) {
                continue;
            }
            $min = (int) ($t['min'] ?? 0);
            $price = (float) ($t['price'] ?? 0);
            if ($min > 0 && $price > 0) {
                $out[] = ['min' => $min, 'price' => round($price, 2)];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        return $out;
    }

    public static function offer(int $companyId, int $productId): ?array
    {
        if ($companyId < 1 || $productId < 1) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM dealer_offers WHERE company_id=? AND product_id=? LIMIT 1');
        $st->execute([$companyId, $productId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function customPrice(int $productId, ?array $u = null): ?float
    {
        $cid = HfsPricing::companyId($u);
        if ($cid < 1) {
            return null;
        }
        $row = self::offer($cid, $productId);
        if ($row && $row['price'] !== null && $row['price'] !== '' && (float) $row['price'] > 0) {
            return (float) $row['price'];
        }
        return null;
    }

    /** @return list<array{min:int,price:float}> */
    public static function qtyTiers(int $productId, ?array $u = null): array
    {
        $cid = HfsPricing::companyId($u);
        if ($cid > 0) {
            $row = self::offer($cid, $productId);
            if ($row && trim((string) ($row['qty_prices'] ?? '')) !== '') {
                return self::parseQtyPrices((string) $row['qty_prices']);
            }
        }
        $raw = HfsDiagram::meta($productId, 'default_qty_prices', '');
        if (is_array($raw)) {
            return self::normalizeQty($raw);
        }
        return self::parseQtyPrices((string) $raw);
    }

    public static function unitPrice(int $productId, int $qty, ?array $u = null): ?float
    {
        $tiers = self::qtyTiers($productId, $u);
        if ($tiers !== [] && $qty > 0) {
            usort($tiers, static fn (array $a, array $b): int => $b['min'] <=> $a['min']);
            foreach ($tiers as $tier) {
                if ($qty >= (int) $tier['min']) {
                    return (float) $tier['price'];
                }
            }
        }
        return self::customPrice($productId, $u);
    }

    public static function qtyLabel(int $productId, ?array $u = null): string
    {
        $tiers = self::qtyTiers($productId, $u);
        if ($tiers === []) {
            return '';
        }
        $parts = [];
        foreach ($tiers as $t) {
            $parts[] = (int) $t['min'] . ' adet → ' . money($t['price']);
        }
        return implode('; ', $parts);
    }

    /** @return list<array{min:int,free:int}> */
    public static function giftTiers(int $productId, ?array $u = null): array
    {
        $cid = HfsPricing::companyId($u);
        if ($cid > 0) {
            $row = self::offer($cid, $productId);
            if ($row && trim((string) ($row['gifts'] ?? '')) !== '') {
                return self::parseGifts((string) $row['gifts']);
            }
        }
        $raw = HfsDiagram::meta($productId, 'default_gifts', '');
        if (is_array($raw)) {
            return self::normalize($raw);
        }
        return self::parseGifts((string) $raw);
    }

    public static function freeQty(int $productId, int $qty, ?array $u = null): int
    {
        if ($qty < 1) {
            return 0;
        }
        $tiers = self::giftTiers($productId, $u);
        if ($tiers === []) {
            return 0;
        }
        usort($tiers, static fn (array $a, array $b): int => $b['min'] <=> $a['min']);
        foreach ($tiers as $tier) {
            if ($qty >= (int) $tier['min']) {
                return min((int) $tier['free'], $qty);
            }
        }
        return 0;
    }

    /** @return list<array<string,mixed>> */
    public static function forCompany(int $companyId): array
    {
        $st = db()->prepare('SELECT o.*, p.title, p.sku FROM dealer_offers o LEFT JOIN products p ON p.id=o.product_id WHERE o.company_id=? ORDER BY p.title');
        $st->execute([$companyId]);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function all(?int $companyId = null): array
    {
        $sql = 'SELECT o.*, p.title, p.sku, u.company, u.display_name, u.username
                FROM dealer_offers o
                LEFT JOIN products p ON p.id=o.product_id
                LEFT JOIN users u ON u.id=o.company_id';
        $args = [];
        if ($companyId) {
            $sql .= ' WHERE o.company_id=?';
            $args[] = $companyId;
        }
        $sql .= ' ORDER BY u.company, p.title';
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function forProduct(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }
        $st = db()->prepare('SELECT o.*, u.company, u.display_name, u.username
            FROM dealer_offers o LEFT JOIN users u ON u.id=o.company_id
            WHERE o.product_id=? ORDER BY u.company, u.username');
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    public static function saveRow(int $companyId, int $productId, ?float $price, string $gifts, string $qtyPrices = ''): void
    {
        if ($companyId < 1 || $productId < 1) {
            return;
        }
        $gifts = self::formatGifts(self::parseGifts($gifts));
        $qtyPrices = self::formatQtyPrices(self::parseQtyPrices($qtyPrices));
        if (($price === null || $price <= 0) && $gifts === '' && $qtyPrices === '') {
            db()->prepare('DELETE FROM dealer_offers WHERE company_id=? AND product_id=?')->execute([$companyId, $productId]);
            return;
        }
        db()->prepare('INSERT INTO dealer_offers (company_id,product_id,price,gifts,qty_prices) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE price=VALUES(price), gifts=VALUES(gifts), qty_prices=VALUES(qty_prices)')
            ->execute([$companyId, $productId, ($price !== null && $price > 0) ? $price : null, $gifts, $qtyPrices]);
    }

    public static function delete(int $companyId, int $productId): void
    {
        db()->prepare('DELETE FROM dealer_offers WHERE company_id=? AND product_id=?')->execute([$companyId, $productId]);
    }

    public static function setDefaultGifts(int $productId, string $gifts): void
    {
        $parsed = self::parseGifts($gifts);
        if ($parsed === []) {
            db()->prepare("DELETE FROM product_meta WHERE product_id=? AND meta_key='default_gifts'")->execute([$productId]);
            return;
        }
        db()->prepare("INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([$productId, 'default_gifts', json_encode($parsed, JSON_UNESCAPED_UNICODE)]);
    }

    public static function setDefaultQtyPrices(int $productId, string $str): void
    {
        $parsed = self::parseQtyPrices($str);
        if ($parsed === []) {
            db()->prepare("DELETE FROM product_meta WHERE product_id=? AND meta_key='default_qty_prices'")->execute([$productId]);
            return;
        }
        db()->prepare("INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([$productId, 'default_qty_prices', json_encode($parsed, JSON_UNESCAPED_UNICODE)]);
    }

    public static function saveFromPost(int $companyId): void
    {
        $skus = (array) ($_POST['offer_sku'] ?? []);
        $pids = (array) ($_POST['offer_pid'] ?? []);
        $prices = (array) ($_POST['offer_price'] ?? []);
        $gifts = (array) ($_POST['offer_gifts'] ?? []);
        $qtys = (array) ($_POST['offer_qty'] ?? []);
        db()->prepare('DELETE FROM dealer_offers WHERE company_id=?')->execute([$companyId]);
        foreach ($skus as $i => $sku) {
            $pid = (int) ($pids[$i] ?? 0);
            $sku = trim((string) $sku);
            if ($pid < 1 && $sku !== '') {
                $p = Catalog::productBySku($sku);
                $pid = $p ? (int) $p['id'] : 0;
            }
            if ($pid < 1) {
                continue;
            }
            $pr = trim((string) ($prices[$i] ?? ''));
            $price = $pr === '' ? null : (float) str_replace(',', '.', $pr);
            self::saveRow($companyId, $pid, $price, (string) ($gifts[$i] ?? ''), (string) ($qtys[$i] ?? ''));
        }
    }

    public static function saveFromProductPost(int $productId): void
    {
        if ($productId < 1) {
            return;
        }
        $cids = (array) ($_POST['poffer_cid'] ?? []);
        $prices = (array) ($_POST['poffer_price'] ?? []);
        $qtys = (array) ($_POST['poffer_qty'] ?? []);
        $gifts = (array) ($_POST['poffer_gifts'] ?? []);
        db()->prepare('DELETE FROM dealer_offers WHERE product_id=?')->execute([$productId]);
        foreach ($cids as $i => $cid) {
            $cid = (int) $cid;
            if ($cid < 1) {
                continue;
            }
            $pr = trim((string) ($prices[$i] ?? ''));
            $price = $pr === '' ? null : self::parseMoney($pr);
            // Alt hesap seçildiyse ana bayiye yaz.
            $dealer = HfsDealers::get($cid);
            if ($dealer) {
                $cid = HfsPricing::companyId($dealer) ?: $cid;
            }
            self::saveRow($cid, $productId, $price, (string) ($gifts[$i] ?? ''), (string) ($qtys[$i] ?? ''));
        }
    }

    private static function parseMoney(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // 20.000,00 veya 20000,00 → 20000.00
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '.', $raw);
        }
        $n = (float) $raw;
        return $n > 0 ? round($n, 2) : null;
    }
}
