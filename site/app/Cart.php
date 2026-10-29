<?php
declare(strict_types=1);

final class Cart
{
    private const COOKIE = 'bmn_cart';
    private const CPN = 'bmn_cpn';
    private const SESS = 'bmn_cart_items';

    /** @return list<array{id:int,vid:int,q:int,n:string}> */
    public static function items(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESS]) && is_array($_SESSION[self::SESS])) {
            return self::finalizeItems(self::normalize($_SESSION[self::SESS]));
        }

        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $persisted = self::loadUserCartPersisted($uid);
            if ($persisted !== null) {
                if ($persisted === []) {
                    self::purgeCookieCart();
                    return [];
                }
                self::hydrateSession($persisted);
                return self::finalizeItems($persisted);
            }
        }

        $fromCookie = self::decode((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($fromCookie !== []) {
            self::hydrateSession($fromCookie);
            if ($uid > 0) {
                self::saveUserCart($fromCookie);
            }
            return self::finalizeItems($fromCookie);
        }

        return [];
    }

    public static function count(): int
    {
        $n = 0;
        foreach (self::items() as $row) {
            $n += $row['q'];
        }
        return $n;
    }

    public static function add(int $productId, int $variantId, int $qty, string $note = ''): void
    {
        $items = self::items();
        $note = mb_substr(trim($note), 0, 80);
        $found = false;
        foreach ($items as &$row) {
            if ($row['id'] === $productId && $row['vid'] === $variantId && $row['n'] === $note) {
                $row['q'] = max(1, min(999, $row['q'] + $qty));
                $found = true;
                break;
            }
        }
        unset($row);
        if (!$found) {
            $items[] = ['id' => $productId, 'vid' => $variantId, 'q' => max(1, min(999, $qty)), 'n' => $note];
        }
        self::save($items);
    }

    public static function setQty(int $productId, int $variantId, int $qty, string $note = '', ?int $lineIx = null): void
    {
        $items = self::items();
        if ($lineIx !== null && array_key_exists($lineIx, $items)) {
            if ($productId > 0 && (int) $items[$lineIx]['id'] !== $productId) {
                // yanlış indeks — not ile düş
            } else {
                if ($qty < 1) {
                    array_splice($items, $lineIx, 1);
                } else {
                    $items[$lineIx]['q'] = min(999, $qty);
                }
                self::save($items);
                return;
            }
        }

        $out = [];
        $note = mb_substr(trim($note), 0, 80);
        foreach ($items as $row) {
            if ($row['id'] === $productId && $row['vid'] === $variantId && $row['n'] === $note) {
                if ($qty < 1) {
                    continue;
                }
                $row['q'] = min(999, $qty);
            }
            $out[] = $row;
        }
        self::save($out);
    }

    public static function clear(): void
    {
        self::save([]);
        self::setCoupon('');
        self::expireCookie(self::COOKIE);
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESS]);
        }
        self::saveUserCart([]);
    }

    public static function couponCode(): string
    {
        $raw = (string) ($_COOKIE[self::CPN] ?? '');
        if ($raw === '' || !str_contains($raw, '.')) {
            return '';
        }
        [$payload, $sig] = explode('.', $raw, 2);
        if (!hash_equals(hash_hmac('sha256', $payload, bmn_csrf_key()), $sig)) {
            return '';
        }
        return strtoupper($payload);
    }

    public static function setCoupon(string $code): void
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            self::expireCookie(self::CPN);
            return;
        }
        $val = $code . '.' . hash_hmac('sha256', $code, bmn_csrf_key());
        self::writeCookie(self::CPN, $val, 86400 * 14);
    }

    /** @param list<array{id:int,vid:int,q:int,n?:string}> $items */
    private static function save(array $items): void
    {
        $items = self::normalize($items);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESS] = $items;
        }

        if ($items === []) {
            self::expireCookie(self::COOKIE);
        } else {
            $payload = rtrim(strtr(base64_encode(json_encode($items, JSON_UNESCAPED_UNICODE) ?: '[]'), '+/', '-_'), '=');
            $sig = hash_hmac('sha256', $payload, bmn_csrf_key());
            self::writeCookie(self::COOKIE, $payload . '.' . $sig, 60 * 60 * 24 * 30);
        }

        self::saveUserCart($items);

        try {
            if ($items !== []) {
                Abandoned::touch(self::hydrated());
            }
        } catch (Throwable) {
        }
    }

    /** Yayında olmayan / silinmiş ürün satırlarını at (rozet vs sepet sayfası uyumsuzluğu). */
    private static function finalizeItems(array $items): array
    {
        $items = self::normalize($items);
        $clean = self::dropStaleLines($items);
        if (json_encode($items, JSON_UNESCAPED_UNICODE) !== json_encode($clean, JSON_UNESCAPED_UNICODE)) {
            self::save($clean);
            return $clean;
        }
        return $items;
    }

    /** @param list<array{id:int,vid:int,q:int,n:string}> $items
     *  @return list<array{id:int,vid:int,q:int,n:string}> */
    private static function dropStaleLines(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $p = Catalog::productById($id);
            if (!$p || ($p['status'] ?? '') !== 'publish') {
                continue;
            }
            $vid = (int) ($row['vid'] ?? 0);
            if ($vid > 0 && !Catalog::variant($vid, $id)) {
                continue;
            }
            $out[] = $row;
        }
        return self::normalize($out);
    }

    /** @param mixed $data
     *  @return list<array{id:int,vid:int,q:int,n:string}> */
    private static function normalize(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $merged = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $vid = (int) ($row['vid'] ?? 0);
            $q = max(1, min(999, (int) ($row['q'] ?? 1)));
            $n = mb_substr(trim((string) ($row['n'] ?? '')), 0, 80);
            $key = $id . '|' . $vid . '|' . $n;
            if (isset($merged[$key])) {
                $merged[$key]['q'] = min(999, $merged[$key]['q'] + $q);
            } else {
                $merged[$key] = ['id' => $id, 'vid' => $vid, 'q' => $q, 'n' => $n];
            }
        }
        return array_values($merged);
    }

    /** @return list<array{id:int,vid:int,q:int,n:string}> */
    private static function decode(string $raw): array
    {
        if ($raw === '' || !str_contains($raw, '.')) {
            return [];
        }
        [$payload, $sig] = explode('.', $raw, 2);
        $expect = hash_hmac('sha256', $payload, bmn_csrf_key());
        if (!hash_equals($expect, $sig)) {
            return [];
        }
        $b64 = strtr($payload, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            return [];
        }
        return self::normalize(json_decode($json, true));
    }

    /** @param list<array{id:int,vid:int,q:int,n:string}> $items */
    private static function hydrateSession(array $items): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESS] = $items;
        }
    }

    /**
     * Bayi sepeti DB: null = hiç kaydedilmemiş, [] = bilinçli boş (sipariş sonrası), dolu = ürünler.
     *
     * @return list<array{id:int,vid:int,q:int,n:string}>|null
     */
    private static function loadUserCartPersisted(int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }
        try {
            $st = db()->prepare('SELECT cart_json FROM users WHERE id=? LIMIT 1');
            $st->execute([$uid]);
            $raw = $st->fetchColumn();
            if ($raw === false) {
                return null;
            }
            if ($raw === null) {
                return null;
            }
            if (!is_string($raw) || $raw === '' || $raw === '[]') {
                return [];
            }
            return self::normalize(json_decode($raw, true));
        } catch (Throwable) {
            return null;
        }
    }

    private static function purgeCookieCart(): void
    {
        self::expireCookie(self::COOKIE);
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESS]);
        }
    }

    /** @param list<array{id:int,vid:int,q:int,n:string}> $items */
    private static function saveUserCart(array $items): void
    {
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid < 1) {
            return;
        }
        try {
            $json = $items === [] ? '[]' : json_encode($items, JSON_UNESCAPED_UNICODE);
            db()->prepare('UPDATE users SET cart_json=? WHERE id=?')->execute([$json, $uid]);
        } catch (Throwable) {
        }
    }

    private static function cookieOpts(int $ttl): array
    {
        $path = base_path() !== '' ? base_path() . '/' : '/';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return [
            'expires' => time() + $ttl,
            'path' => $path,
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function writeCookie(string $name, string $val, int $ttl): void
    {
        if (!headers_sent()) {
            $opts = self::cookieOpts($ttl);
            setcookie($name, $val, $opts);
            // Eski Secure=0 kopyasını temizle (Safari’de çift çerez kalabiliyor)
            if (!empty($opts['secure'])) {
                setcookie($name, '', [
                    'expires' => time() - 3600,
                    'path' => $opts['path'],
                    'secure' => false,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                setcookie($name, $val, $opts);
            }
        }
        $_COOKIE[$name] = $val;
    }

    private static function expireCookie(string $name): void
    {
        $path = base_path() !== '' ? base_path() . '/' : '/';
        if (!headers_sent()) {
            foreach ([true, false] as $secure) {
                setcookie($name, '', [
                    'expires' => time() - 3600,
                    'path' => $path,
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        unset($_COOKIE[$name]);
    }

    /**
     * @return array{
     *   lines:list<array<string,mixed>>,
     *   subtotal:float,discount:float,shipping:float,total:float,count:int,
     *   coupon:?array,coupon_code:string,volume:float,digital:bool
     * }
     */
    public static function hydrated(string $country = 'TR', string $city = ''): array
    {
        $lines = [];
        $sub = 0.0;
        $qty = 0;
        $digital = true;
        foreach (self::items() as $ix => $row) {
            $p = Catalog::productById($row['id']);
            if (!$p || $p['status'] !== 'publish') {
                continue;
            }
            if (($p['type'] ?? 'simple') !== 'digital') {
                $digital = false;
            }
            $v = $row['vid'] > 0 ? Catalog::variant($row['vid'], $row['id']) : null;
            $cartNote = (string) ($row['n'] ?? '');
            $price = $v && $v['price'] !== null && $v['price'] !== '' ? (float) $v['price'] : (float) ($p['list_price'] ?? $p['price']);
            $price = HfsPricing::apply($price, null, (int) $p['id'], (int) $row['q']);
            $varLabel = $v ? Option::variantDisplayName($v, $cartNote) : '';
            $title = $p['title'] . ($varLabel !== '' ? ' — ' . $varLabel : '');
            $line = $price * $row['q'];
            $free = HfsOffers::freeQty((int) $p['id'], (int) $row['q']);
            $gift = $free > 0 ? round($price * $free, 2) : 0.0;
            $qtyNote = HfsOffers::qtyLabel((int) $p['id']);
            $sub += $line;
            $qty += $row['q'];
            $lines[] = [
                'product' => $p,
                'variant' => $v,
                'qty' => $row['q'],
                'price' => $price,
                'line' => $line,
                'title' => $title,
                'sku' => $v['sku'] ?? $p['sku'],
                'note' => Option::noteForDisplay($cartNote),
                'cart_note' => $cartNote,
                'ix' => (int) $ix,
                'free_qty' => $free,
                'gift' => $gift,
                'qty_note' => $qtyNote,
            ];
        }
        if ($lines === []) {
            $digital = false;
        }
        $code = self::couponCode();
        $coupon = $code !== '' ? Coupon::find($code) : null;
        $cDisc = Coupon::discount($coupon, $sub);
        $vDisc = Coupon::volume($qty, $sub);
        $discount = $cDisc > 0 ? $cDisc : $vDisc;
        $giftDisc = 0.0;
        foreach ($lines as $ln) {
            $giftDisc += (float) ($ln['gift'] ?? 0);
        }
        $discount += $giftDisc;
        $co = HfsPricing::company();
        $tierMin = (int) ($co['tier_min_qty'] ?? 0);
        $tierPct = (float) ($co['tier_discount'] ?? 0);
        if ($tierMin > 0 && $tierPct > 0 && $qty >= $tierMin) {
            $discount += round($sub * ($tierPct / 100), 2);
        }
        $shipFree = $coupon && Coupon::usable($coupon, $sub) && $coupon['type'] === 'free_ship';
        $after = max(0, $sub - $discount);
        $ship = $shipFree ? 0.0 : Shipping::fee($after, $country, $city, $digital);
        return [
            'lines' => $lines,
            'subtotal' => $sub,
            'discount' => $discount,
            'shipping' => $ship,
            'total' => $after + $ship,
            'count' => $qty,
            'coupon' => $cDisc > 0 ? $coupon : null,
            'coupon_code' => $cDisc > 0 ? $code : '',
            'volume' => $cDisc > 0 ? 0.0 : $vDisc,
            'digital' => $digital,
        ];
    }
}
