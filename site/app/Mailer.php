<?php
declare(strict_types=1);

final class Mailer
{
    /** @return array{ok:bool,error:string} */
    public static function send(string $to, string $subject, string $html, string $replyTo = ''): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Geçersiz alıcı.'];
        }
        $id = 0;
        try {
            db()->prepare('INSERT INTO mail_log (to_email,subject,body,status,created_at) VALUES (?,?,?,?,NOW())')
                ->execute([$to, mb_substr($subject, 0, 190), $html, 'queued']);
            $id = (int) db()->lastInsertId();
        } catch (Throwable) {
        }
        $fromMail = trim((string) setting('smtp_from', setting('email', 'hasfrensistemleri@gmail.com')));
        $fromName = trim((string) setting('smtp_from_name', setting('site_name', 'HFS Tarım')));
        $reply = trim($replyTo !== '' ? $replyTo : (string) setting('email', $fromMail));
        $result = ['ok' => false, 'error' => ''];
        if (self::smtpOn()) {
            $result = self::smtp($to, $subject, $html, $fromMail, $fromName, $reply);
        } else {
            $result = self::phpMail($to, $subject, $html, $fromMail, $fromName, $reply);
        }
        if ($id > 0) {
            try {
                db()->prepare('UPDATE mail_log SET status=?, err=? WHERE id=?')
                    ->execute([$result['ok'] ? 'sent' : 'fail', mb_substr($result['error'], 0, 400), $id]);
            } catch (Throwable) {
                db()->prepare('UPDATE mail_log SET status=? WHERE id=?')
                    ->execute([$result['ok'] ? 'sent' : 'queued', $id]);
            }
        }
        return $result;
    }

    public static function smtpOn(): bool
    {
        return setting('smtp_enabled', '0') === '1' && trim((string) setting('smtp_host', '')) !== '';
    }

    public static function wrap(string $title, string $body, string $href = '', string $cta = ''): string
    {
        $btn = ($href !== '' && $cta !== '')
            ? '<p style="margin:24px 0"><a href="' . h($href) . '" style="display:inline-block;background:#1b4332;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700">' . h($cta) . '</a></p>'
            : '';
        $site = (string) setting('site_name', 'HFS Tarım');
        return self::shell('<h1 style="font-size:20px;color:#1b4332;margin:0 0 12px">' . h($title) . '</h1>' . $body . $btn, $site);
    }

    public static function shell(string $inner, string $site = ''): string
    {
        $site = $site !== '' ? $site : (string) setting('site_name', 'HFS Tarım');
        $phone = (string) setting('phone', '0224 216 16 01');
        $logo = shop_logo_abs();
        $brand = '<a href="' . h(site_url()) . '" style="text-decoration:none">'
            . '<img src="' . h($logo) . '" alt="' . h($site) . '" width="200" style="max-width:200px;height:auto;display:block;margin:0 auto;border:0">'
            . '</a>';
        return '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f1ea;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px 16px">'
            . '<div style="background:#1b4332;padding:14px 20px 10px;border-radius:12px 12px 0 0">'
            . '<div style="background:#f4f7f5;border-radius:10px;padding:12px 16px">' . $brand . '</div></div>'
            . '<div style="background:#fff;padding:24px 20px;border:1px solid #e0ddd3;border-top:0;color:#1a241c;font-size:15px;line-height:1.55">' . $inner . '</div>'
            . '<p style="color:#5c675f;font-size:12px;padding:12px 4px">Bu ileti ' . h($site) . ' bayi portalından gönderildi. ' . h($phone) . '</p>'
            . '</div></body></html>';
    }

    /** @return array{ok:bool,error:string} */
    private static function phpMail(string $to, string $subject, string $html, string $fromMail, string $fromName, string $reply): array
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::mailbox($fromName, $fromMail),
            'Reply-To: ' . $reply,
        ];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
        return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'PHP mail() kapalı veya reddetti. SMTP kullanın.'];
    }

    /** @return array{ok:bool,error:string} */
    private static function smtp(string $to, string $subject, string $html, string $fromMail, string $fromName, string $reply): array
    {
        $host = trim((string) setting('smtp_host'));
        $port = (int) setting('smtp_port', '587');
        if ($port < 1) {
            $port = 587;
        }
        $secure = (string) setting('smtp_secure', 'tls');
        $user = trim((string) setting('smtp_user', ''));
        $pass = (string) setting('smtp_pass', '');
        $timeout = max(8, (int) setting('smtp_timeout', '20'));
        $verify = setting('smtp_verify', '1') === '1';
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify,
            ],
        ]);
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return ['ok' => false, 'error' => 'SMTP bağlanamadı: ' . $errstr . ' (' . $errno . ')'];
        }
        stream_set_timeout($fp, $timeout);
        $ehloHost = preg_replace('/:\d+$/', '', (string) (parse_url((string) site_url(), PHP_URL_HOST) ?: 'localhost')) ?: 'localhost';
        $expect = static function ($fp, array $codes): string {
            $last = '';
            while (!feof($fp)) {
                $line = (string) fgets($fp, 512);
                $last .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            $code = (int) substr($last, 0, 3);
            if (!in_array($code, $codes, true)) {
                return trim($last) !== '' ? trim($last) : 'SMTP yanıt yok';
            }
            return '';
        };
        $put = static function ($fp, string $cmd): void {
            fwrite($fp, $cmd . "\r\n");
        };
        $err = $expect($fp, [220]);
        if ($err !== '') {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        $put($fp, 'EHLO ' . $ehloHost);
        $err = $expect($fp, [250]);
        if ($err !== '') {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        if ($secure === 'tls') {
            $put($fp, 'STARTTLS');
            $err = $expect($fp, [220]);
            if ($err !== '') {
                fclose($fp);
                return ['ok' => false, 'error' => $err];
            }
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            $crypto = @stream_socket_enable_crypto($fp, true, $method);
            if ($crypto !== true) {
                fclose($fp);
                return ['ok' => false, 'error' => 'STARTTLS başarısız.'];
            }
            $put($fp, 'EHLO ' . $ehloHost);
            $err = $expect($fp, [250]);
            if ($err !== '') {
                fclose($fp);
                return ['ok' => false, 'error' => $err];
            }
        }
        if ($user !== '') {
            $put($fp, 'AUTH LOGIN');
            $err = $expect($fp, [334]);
            if ($err !== '') {
                fclose($fp);
                return ['ok' => false, 'error' => $err];
            }
            $put($fp, base64_encode($user));
            $err = $expect($fp, [334]);
            if ($err !== '') {
                fclose($fp);
                return ['ok' => false, 'error' => $err];
            }
            $put($fp, base64_encode($pass));
            $err = $expect($fp, [235]);
            if ($err !== '') {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP giriş reddedildi. ' . $err];
            }
        }
        $put($fp, 'MAIL FROM:<' . $fromMail . '>');
        $err = $expect($fp, [250]);
        if ($err !== '') {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        $put($fp, 'RCPT TO:<' . $to . '>');
        $err = $expect($fp, [250, 251]);
        if ($err !== '') {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        $put($fp, 'DATA');
        $err = $expect($fp, [354]);
        if ($err !== '') {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        $mime = 'From: ' . self::mailbox($fromName, $fromMail) . "\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Reply-To: ' . $reply . "\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split(base64_encode($html));
        $put($fp, str_replace("\n.", "\n..", $mime) . "\r\n.");
        $err = $expect($fp, [250]);
        $put($fp, 'QUIT');
        fclose($fp);
        if ($err !== '') {
            return ['ok' => false, 'error' => $err];
        }
        return ['ok' => true, 'error' => ''];
    }

    private static function mailbox(string $name, string $email): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));
        if ($name === '') {
            return $email;
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }
}
