<?php
declare(strict_types=1);

final class HfsAuthPassword
{
    private const RESET_TTL = 3600;

    public static function enforceMustChange(string $path): void
    {
        $u = current_user();
        if (!$u || (string) ($u['role'] ?? '') !== 'dealer') {
            return;
        }
        if ((int) ($u['must_change_password'] ?? 0) !== 1) {
            return;
        }
        $allow = [
            '/sifre-belirle/',
            '/cikis/',
        ];
        if (in_array($path, $allow, true)) {
            return;
        }
        if (str_starts_with($path, '/yonetim/')) {
            return;
        }
        redirect(site_url('sifre-belirle/'));
    }

    /** @return array{ok:bool,message:string} */
    public static function requestReset(string $login): array
    {
        $login = trim($login);
        if ($login === '') {
            return ['ok' => true, 'message' => 'Talebiniz alındı. Kayıtlı bir hesap varsa e-posta gönderildi.'];
        }
        $user = self::findDealerForLogin($login);
        if (!$user) {
            return ['ok' => true, 'message' => 'Talebiniz alındı. Kayıtlı bir hesap varsa e-posta gönderildi.'];
        }
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Bu hesapta geçerli e-posta yok. Lütfen HFS ile iletişime geçin.'];
        }
        self::purgeExpiredTokens();
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $uid = (int) ($user['id'] ?? 0);
        try {
            db()->prepare('DELETE FROM auth_password_reset WHERE user_id=?')->execute([$uid]);
            db()->prepare('INSERT INTO auth_password_reset (user_id, token_hash, expires_at, created_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? SECOND),NOW())')
                ->execute([$uid, $hash, self::RESET_TTL]);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'İşlem yapılamadı. Lütfen tekrar deneyin.'];
        }
        $link = site_url('sifre-sifirla/?token=' . rawurlencode($token));
        $name = trim((string) ($user['display_name'] ?? $user['company'] ?? $user['username'] ?? ''));
        $html = Mailer::wrap(
            'Şifre sıfırlama',
            '<p>Merhaba ' . h($name !== '' ? $name : 'Bayi') . ',</p>'
            . '<p>Bayi portalı şifrenizi sıfırlamak için aşağıdaki bağlantıyı kullanın. Bağlantı '
            . (int) (self::RESET_TTL / 60) . ' dakika geçerlidir.</p>'
            . '<p>Talebi siz yapmadıysanız bu iletiyi yok sayın.</p>',
            $link,
            'Yeni şifre belirle'
        );
        $sent = Mailer::send($email, (string) setting('site_name', 'HFS Tarım') . ' — Şifre sıfırlama', $html);
        if (!$sent['ok']) {
            return ['ok' => false, 'message' => 'E-posta gönderilemedi. SMTP ayarlarını kontrol edin veya daha sonra deneyin.'];
        }
        return ['ok' => true, 'message' => 'Talebiniz alındı. Kayıtlı bir hesap varsa e-posta gönderildi.'];
    }

    /** @return array{ok:bool,user:?array,error:string} */
    public static function userForToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'user' => null, 'error' => 'Geçersiz veya süresi dolmuş bağlantı.'];
        }
        self::purgeExpiredTokens();
        $hash = hash('sha256', $token);
        $st = db()->prepare('SELECT user_id FROM auth_password_reset WHERE token_hash=? AND expires_at>NOW() LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row) {
            return ['ok' => false, 'user' => null, 'error' => 'Geçersiz veya süresi dolmuş bağlantı.'];
        }
        $u = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $u->execute([(int) ($row['user_id'] ?? 0)]);
        $user = $u->fetch();
        if (!$user || (string) ($user['role'] ?? '') !== 'dealer') {
            return ['ok' => false, 'user' => null, 'error' => 'Geçersiz veya süresi dolmuş bağlantı.'];
        }
        return ['ok' => true, 'user' => $user, 'error' => ''];
    }

    /** @return ?string hata metni */
    public static function completeReset(string $token, string $pass, string $confirm): ?string
    {
        if (strlen($pass) < 8) {
            return 'Şifre en az 8 karakter olmalı.';
        }
        if ($pass !== $confirm) {
            return 'Şifre tekrarı eşleşmiyor.';
        }
        $check = self::userForToken($token);
        if (!$check['ok'] || !$check['user']) {
            return $check['error'] !== '' ? $check['error'] : 'Geçersiz bağlantı.';
        }
        $uid = (int) ($check['user']['id'] ?? 0);
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password=?, must_change_password=0 WHERE id=?')->execute([$hash, $uid]);
        db()->prepare('DELETE FROM auth_password_reset WHERE user_id=?')->execute([$uid]);
        try {
            db()->prepare('DELETE FROM auth_remember WHERE user_id=?')->execute([$uid]);
        } catch (Throwable) {
        }
        return null;
    }

    /** Zorunlu ilk şifre değişimi (oturum açık). @return ?string */
    public static function forceChange(int $userId, string $pass, string $confirm): ?string
    {
        if (strlen($pass) < 8) {
            return 'Şifre en az 8 karakter olmalı.';
        }
        if ($pass !== $confirm) {
            return 'Şifre tekrarı eşleşmiyor.';
        }
        $u = current_user();
        if (!$u || (int) ($u['id'] ?? 0) !== $userId) {
            return 'Oturum geçersiz.';
        }
        if ((int) ($u['must_change_password'] ?? 0) !== 1) {
            return 'Bu adım gerekli değil.';
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password=?, must_change_password=0 WHERE id=?')->execute([$hash, $userId]);
        $fresh = Auth::user();
        if ($fresh) {
            $GLOBALS['bmn_user'] = $fresh;
        }
        return null;
    }

    public static function applySmtpFromPayload(array $data): array
    {
        $host = trim((string) ($data['host'] ?? 'mail.hfstarim.com'));
        $port = (int) ($data['port'] ?? 587);
        $secure = (string) ($data['secure'] ?? 'tls');
        if (!in_array($secure, ['tls', 'ssl', ''], true)) {
            $secure = 'tls';
        }
        $user = trim((string) ($data['user'] ?? ''));
        $pass = (string) ($data['pass'] ?? '');
        $from = trim((string) ($data['from'] ?? $user));
        $fromName = trim((string) ($data['from_name'] ?? setting('site_name', 'HFS Tarım')));
        $verify = !empty($data['verify']) ? '1' : '0';
        if ($host === '' || $user === '' || $pass === '') {
            return ['ok' => false, 'error' => 'host, user, pass gerekli'];
        }
        setting_set('smtp_enabled', '1');
        setting_set('smtp_host', $host);
        setting_set('smtp_port', (string) ($port > 0 ? $port : 587));
        setting_set('smtp_secure', $secure !== '' ? $secure : 'tls');
        setting_set('smtp_user', $user);
        setting_set('smtp_pass', $pass);
        setting_set('smtp_from', $from !== '' ? $from : $user);
        setting_set('smtp_from_name', $fromName);
        setting_set('smtp_verify', $verify);
        setting_set('smtp_timeout', '25');
        return ['ok' => true, 'smtp_on' => Mailer::smtpOn()];
    }

    private static function purgeExpiredTokens(): void
    {
        try {
            db()->exec('DELETE FROM auth_password_reset WHERE expires_at < NOW()');
        } catch (Throwable) {
        }
    }

    /** @return ?array<string,mixed> */
    private static function findDealerForLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        $st = db()->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND role='dealer' LIMIT 1");
        $st->execute([$login, $login]);
        $user = $st->fetch();
        if ($user) {
            return $user;
        }
        $digits = preg_replace('/\D+/', '', $login) ?? '';
        if (strlen($digits) !== 10 && strlen($digits) !== 11) {
            return null;
        }
        $st = db()->prepare("SELECT * FROM users WHERE role='dealer' AND username=? LIMIT 1");
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
}
