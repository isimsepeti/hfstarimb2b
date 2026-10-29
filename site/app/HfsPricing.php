<?php
declare(strict_types=1);

final class HfsPricing
{
    public static function canSeePrices(): bool
    {
        $u = current_user();
        return $u !== null;
    }

    public static function isDealer(?array $u = null): bool
    {
        $u = $u ?? current_user();
        if (!$u) {
            return false;
        }
        $role = (string) ($u['role'] ?? '');
        if (in_array($role, ['admin', 'editor', 'dealer'], true)) {
            return true;
        }
        return trim((string) ($u['b2b_role'] ?? '')) !== '' || (int) ($u['parent_user_id'] ?? 0) > 0;
    }

    public static function companyId(?array $u = null): int
    {
        $u = $u ?? current_user();
        if (!$u) {
            return 0;
        }
        $parent = (int) ($u['parent_user_id'] ?? 0);
        return $parent > 0 ? $parent : (int) $u['id'];
    }

    public static function company(?array $u = null): ?array
    {
        $cid = self::companyId($u);
        if ($cid < 1) {
            return $u;
        }
        $u = $u ?? current_user();
        if ($u && (int) $u['id'] === $cid) {
            return $u;
        }
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$cid]);
        $row = $st->fetch();
        return $row ?: $u;
    }

    public static function discountPercent(?array $u = null): float
    {
        $co = self::company($u);
        if (!$co) {
            return 0.0;
        }
        return max(0.0, min(100.0, (float) ($co['discount_percent'] ?? 0)));
    }

    public static function apply(float $price, ?array $u = null, int $productId = 0, int $qty = 0): float
    {
        if ($price <= 0) {
            return $price;
        }
        if ($productId > 0) {
            $fromOffer = HfsOffers::unitPrice($productId, $qty, $u);
            if ($fromOffer !== null) {
                return round($fromOffer, 2);
            }
        }
        $pct = self::discountPercent($u);
        if ($pct <= 0) {
            return $price;
        }
        return round($price * (1 - $pct / 100), 2);
    }

    public static function decorate(array $p): array
    {
        $raw = isset($p['list_price']) ? (float) $p['list_price'] : (float) $p['price'];
        $cmp = $p['compare_price'] !== null && $p['compare_price'] !== '' ? (float) $p['compare_price'] : 0.0;
        $p['list_price'] = $raw;
        $p['price'] = self::apply($raw, null, (int) ($p['id'] ?? 0));
        if ($cmp > 0) {
            $p['compare_price'] = $cmp;
        } elseif ($p['price'] < $raw) {
            $p['compare_price'] = $raw;
        }
        return $p;
    }

    public static function decorateMany(array $items): array
    {
        return array_map([self::class, 'decorate'], $items);
    }

    public static function html(array $p): string
    {
        if (!self::canSeePrices()) {
            return '<a class="price-login" href="' . h(site_url('giris/')) . '">Giriş yapın</a>';
        }
        $now = (float) $p['price'];
        $cmp = isset($p['compare_price']) && $p['compare_price'] !== null && $p['compare_price'] !== '' ? (float) $p['compare_price'] : 0.0;
        if ($cmp > $now && $now > 0) {
            return '<s>' . h(money($cmp)) . '</s> <strong>' . h(money($now)) . '</strong>';
        }
        return '<strong>' . h(money($now)) . '</strong>';
    }
}
