<?php
declare(strict_types=1);

final class Auth
{
    private const REMEMBER_COOKIE = 'hfs_remember';
    /** Süresiz sayılacak uzun süre (20 yıl). */
    private const REMEMBER_TTL = 630720000;

    public static function attempt(string $username, string $password, bool $remember = false): bool
    {
        $user = self::resolveLoginUser($username);
        if (!$user) {
            return false;
        }
        $hash = (string) $user['password'];
        $ok = self::passwordMatches($password, $hash);
        if (!$ok) {
            return false;
        }
        $plainForRehash = self::passwordPlainForRehash($password);
        if ($ok && (WpPassword::needsRehash($hash) || (str_starts_with($hash, '$2') && password_needs_rehash($hash, PASSWORD_DEFAULT)))) {
            try {
                db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($plainForRehash, PASSWORD_DEFAULT), (int) $user['id']]);
            } catch (Throwable) {
            }
        }
        self::login($user);
        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        } else {
            self::clearRememberCookie();
        }
        return true;
    }

    public static function login(array $user): void
    {
        bmn_session_start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            try {
                session_regenerate_id(true);
            } catch (Throwable) {
                try {
                    session_regenerate_id(false);
                } catch (Throwable) {
                }
            }
        }
        $_SESSION['uid'] = (int) ($user['id'] ?? 0);
        $_SESSION['role'] = (string) ($user['role'] ?? '');
    }

    /** Oturum yokken kalıcı çerez ile giriş. */
    public static function tryRememberLogin(): void
    {
        if ((int) ($_SESSION['uid'] ?? 0) > 0) {
            return;
        }
        $raw = trim((string) ($_COOKIE[self::REMEMBER_COOKIE] ?? ''));
        if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return;
        }
        $hash = hash('sha256', $raw);
        try {
            $st = db()->prepare('SELECT user_id FROM auth_remember WHERE token_hash=? LIMIT 1');
            $st->execute([$hash]);
            $row = $st->fetch();
            if (!$row) {
                self::clearRememberCookie();
                return;
            }
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid < 1) {
                self::clearRememberCookie();
                return;
            }
            $u = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
            $u->execute([$uid]);
            $user = $u->fetch();
            if (!$user) {
                self::forgetRememberHash($hash);
                self::clearRememberCookie();
                return;
            }
            self::login($user);
            self::setRememberCookie($raw);
        } catch (Throwable) {
        }
    }

    public static function user(): ?array
    {
        $id = (int) ($_SESSION['uid'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        return $u ?: null;
    }

    public static function logout(): void
    {
        $uid = (int) ($_SESSION['uid'] ?? 0);
        $raw = trim((string) ($_COOKIE[self::REMEMBER_COOKIE] ?? ''));
        if ($raw !== '' && preg_match('/^[a-f0-9]{64}$/', $raw)) {
            self::forgetRememberHash(hash('sha256', $raw));
        } elseif ($uid > 0) {
            try {
                db()->prepare('DELETE FROM auth_remember WHERE user_id=?')->execute([$uid]);
            } catch (Throwable) {
            }
        }
        self::clearRememberCookie();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireRole(array $roles): void
    {
        $u = current_user() ?? self::user();
        if ($u) {
            $GLOBALS['bmn_user'] = $u;
        }
        if (!$u || !in_array((string) ($u['role'] ?? ''), $roles, true)) {
            redirect(site_url('yonetim/giris/'));
        }
    }

    private static function issueRememberToken(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        try {
            db()->prepare('DELETE FROM auth_remember WHERE user_id=?')->execute([$userId]);
        } catch (Throwable) {
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        try {
            db()->prepare('INSERT INTO auth_remember (user_id, token_hash, created_at) VALUES (?,?,NOW())')
                ->execute([$userId, $hash]);
            self::setRememberCookie($token);
        } catch (Throwable) {
            self::clearRememberCookie();
        }
    }

    private static function setRememberCookie(string $token): void
    {
        $p = self::rememberCookieParams();
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_TTL,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $token;
    }

    private static function clearRememberCookie(): void
    {
        if (empty($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }
        $p = self::rememberCookieParams();
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private static function forgetRememberHash(string $hash): void
    {
        if ($hash === '') {
            return;
        }
        try {
            db()->prepare('DELETE FROM auth_remember WHERE token_hash=?')->execute([$hash]);
        } catch (Throwable) {
        }
    }

    /** @return array{path:string,domain:string,secure:bool} */
    private static function rememberCookieParams(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return [
            'path' => bmn_session_cookie_path(),
            'domain' => '',
            'secure' => $https,
        ];
    }

    /** @return ?array<string,mixed> */
    private static function resolveLoginUser(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $user = $st->fetch();
        if ($user) {
            return $user;
        }
        $digits = preg_replace('/\D+/', '', $login) ?? '';
        if (strlen($digits) !== 10 && strlen($digits) !== 11) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $st->execute([$digits]);
        $user = $st->fetch();
        if ($user) {
            return $user;
        }
        $st = db()->prepare("SELECT * FROM users WHERE role='dealer' AND REPLACE(REPLACE(REPLACE(tax_number,' ',''),'-',''),'.','') = ? LIMIT 1");
        $st->execute([$digits]);
        $user = $st->fetch();

        return $user ?: null;
    }

    private static function passwordMatches(string $password, string $hash): bool
    {
        if (password_verify($password, $hash) || WpPassword::check($password, $hash)) {
            return true;
        }
        $digits = preg_replace('/\D+/', '', $password) ?? '';
        if ($digits === '' || $digits === $password) {
            return false;
        }

        return password_verify($digits, $hash) || WpPassword::check($digits, $hash);
    }

    private static function passwordPlainForRehash(string $password): string
    {
        $digits = preg_replace('/\D+/', '', $password) ?? '';
        if ($digits !== '' && $digits !== $password && (strlen($digits) === 10 || strlen($digits) === 11)) {
            return $digits;
        }

        return $password;
    }
}
