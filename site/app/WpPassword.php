<?php
declare(strict_types=1);

/** WordPress phpass / bcrypt ($P$, $H$, $wp$2y$) doğrulama. */
final class WpPassword
{
    public static function check(string $password, string $hash): bool
    {
        $hash = (string) $hash;
        if ($hash === '' || $password === '') {
            return false;
        }
        if (str_starts_with($hash, '$wp$')) {
            return password_verify($password, substr($hash, 3));
        }
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') || str_starts_with($hash, 'argon2')) {
            return password_verify($password, $hash);
        }
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, self::phpass($password, $hash));
        }
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }
        return false;
    }

    public static function needsRehash(string $hash): bool
    {
        return !str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$2a$') && !str_starts_with($hash, '$2b$') && !str_starts_with($hash, '$argon');
    }

    private static function phpass(string $password, string $stored): string
    {
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        if (strlen($stored) < 12) {
            return '';
        }
        $countLog2 = strpos($itoa64, $stored[3]);
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return '';
        }
        $count = 1 << $countLog2;
        $salt = substr($stored, 4, 8);
        $hash = md5($salt . $password, true);
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);
        return substr($stored, 0, 12) . self::encode64($hash, 16, $itoa64);
    }

    private static function encode64(string $input, int $count, string $itoa64): string
    {
        $out = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $out .= $itoa64[$value & 0x3f];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $out .= $itoa64[($value >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $out .= $itoa64[($value >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $out .= $itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);
        return $out;
    }
}
