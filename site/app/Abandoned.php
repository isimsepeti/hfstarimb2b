<?php
declare(strict_types=1);

final class Abandoned
{
    public static function token(): string
    {
        $t = (string) ($_COOKIE['bmn_aid'] ?? '');
        if ($t === '' || !preg_match('/^[a-f0-9]{32}$/', $t)) {
            $t = bin2hex(random_bytes(16));
            self::cookie('bmn_aid', $t, 60 * 60 * 24 * 30);
        }
        return $t;
    }

    public static function email(): string
    {
        $e = (string) ($_COOKIE['bmn_em'] ?? '');
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    public static function setEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::cookie('bmn_em', $email, 60 * 60 * 24 * 90);
        }
    }

    public static function touch(array $cart): void
    {
        if ($cart['lines'] === []) {
            return;
        }
        $token = self::token();
        $email = self::email();
        $payload = json_encode([
            'count' => $cart['count'],
            'total' => $cart['total'],
            'titles' => array_column($cart['lines'], 'title'),
        ], JSON_UNESCAPED_UNICODE);
        $st = db()->prepare('SELECT id, recovered FROM abandoned_carts WHERE token=?');
        $st->execute([$token]);
        $row = $st->fetch();
        if ($row && (int) $row['recovered'] === 1) {
            return;
        }
        if ($row) {
            db()->prepare('UPDATE abandoned_carts SET email=?, payload=?, updated_at=NOW() WHERE token=?')
                ->execute([$email !== '' ? $email : null, $payload, $token]);
            return;
        }
        db()->prepare('INSERT INTO abandoned_carts (token,email,payload,reminders,recovered,updated_at,created_at) VALUES (?,?,?,0,0,NOW(),NOW())')
            ->execute([$token, $email !== '' ? $email : null, $payload]);
    }

    public static function recover(): void
    {
        $token = (string) ($_COOKIE['bmn_aid'] ?? '');
        if ($token === '') {
            return;
        }
        db()->prepare('UPDATE abandoned_carts SET recovered=1, updated_at=NOW() WHERE token=?')->execute([$token]);
    }

    public static function cron(): int
    {
        $sent = 0;
        $rows = db()->query("SELECT * FROM abandoned_carts WHERE recovered=0 AND email IS NOT NULL AND email!='' AND reminders<2 ORDER BY updated_at ASC LIMIT 40")->fetchAll();
        foreach ($rows as $row) {
            $age = time() - strtotime((string) $row['updated_at']);
            $n = (int) $row['reminders'];
            $need = $n === 0 ? 2 * 3600 : 24 * 3600;
            if ($age < $need) {
                continue;
            }
            $data = json_decode((string) $row['payload'], true) ?: [];
            $titles = implode(', ', (array) ($data['titles'] ?? ['ürün']));
            if ($n === 0) {
                HfsMail::cartRemind((string) $row['email'], $titles);
            } else {
                HfsMail::cartCoupon((string) $row['email'], $titles);
            }
            db()->prepare('UPDATE abandoned_carts SET reminders=reminders+1 WHERE id=?')->execute([(int) $row['id']]);
            $sent++;
        }
        return $sent;
    }

    private static function cookie(string $n, string $v, int $ttl): void
    {
        $path = base_path() !== '' ? base_path() . '/' : '/';
        setcookie($n, $v, ['expires' => time() + $ttl, 'path' => $path, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[$n] = $v;
    }
}
