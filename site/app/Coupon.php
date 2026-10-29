<?php
declare(strict_types=1);

final class Coupon
{
    public static function find(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $st = db()->prepare("SELECT * FROM coupons WHERE code=? AND status='publish' LIMIT 1");
        $st->execute([$code]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function usable(?array $c, float $subtotal): bool
    {
        if (!$c) {
            return false;
        }
        $now = time();
        if (!empty($c['starts_at']) && strtotime((string) $c['starts_at']) > $now) {
            return false;
        }
        if (!empty($c['ends_at']) && strtotime((string) $c['ends_at']) < $now) {
            return false;
        }
        if ((int) $c['max_uses'] > 0 && (int) $c['used'] >= (int) $c['max_uses']) {
            return false;
        }
        return $subtotal >= (float) $c['min_subtotal'];
    }

    public static function discount(?array $c, float $subtotal): float
    {
        if (!self::usable($c, $subtotal)) {
            return 0;
        }
        if ($c['type'] === 'fixed') {
            return min($subtotal, (float) $c['value']);
        }
        if ($c['type'] === 'percent') {
            return round($subtotal * ((float) $c['value'] / 100), 2);
        }
        return 0;
    }

    public static function volume(int $qty, float $subtotal): float
    {
        $p3 = (float) setting('volume_3_pct', '0');
        $p2 = (float) setting('volume_2_pct', '0');
        if ($qty >= 3 && $p3 > 0) {
            return round($subtotal * ($p3 / 100), 2);
        }
        if ($qty >= 2 && $p2 > 0) {
            return round($subtotal * ($p2 / 100), 2);
        }
        return 0;
    }

    public static function bump(string $code): void
    {
        db()->prepare('UPDATE coupons SET used=used+1 WHERE code=?')->execute([strtoupper($code)]);
    }
}
