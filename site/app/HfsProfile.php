<?php
declare(strict_types=1);

final class HfsProfile
{
    public static function savePost(): never
    {
        if (!csrf_ok()) {
            redirect(site_url('hesabim/') . '?hata=oturum');
        }
        $u = current_user();
        if (!$u) {
            redirect(site_url('giris/'));
        }
        $id = (int) ($u['id'] ?? 0);
        $err = self::validateAndUpdate($id, $_POST, $u);
        if ($err !== null) {
            redirect(site_url('hesabim/') . '?hata=' . rawurlencode($err));
        }
        $fresh = Auth::user();
        if ($fresh) {
            $GLOBALS['bmn_user'] = $fresh;
        }
        redirect(site_url('hesabim/') . '?ok=1');
    }

    /** @param array<string, mixed> $post */
    private static function validateAndUpdate(int $id, array $post, array $u): ?string
    {
        $email = trim((string) ($post['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Geçerli bir e-posta girin.';
        }
        $display = trim((string) ($post['display_name'] ?? ''));
        $phone = trim((string) ($post['phone'] ?? ''));
        $city = trim((string) ($post['city'] ?? ''));
        $district = trim((string) ($post['district'] ?? ''));
        $postcode = preg_replace('/\D+/', '', (string) ($post['postcode'] ?? '')) ?? '';
        $country = strtoupper(trim((string) ($post['country'] ?? 'TR')));
        if ($country !== 'TR') {
            $country = 'TR';
        }
        $address = trim((string) ($post['address'] ?? ''));

        $dup = db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
        $dup->execute([$email, $id]);
        if ($dup->fetch()) {
            return 'Bu e-posta başka bir hesapta kayıtlı.';
        }

        $parent = (int) ($u['parent_user_id'] ?? 0);
        $isMain = $parent < 1;

        $company = trim((string) ($post['company'] ?? ''));
        $taxOffice = trim((string) ($post['tax_office'] ?? ''));
        $taxNumber = preg_replace('/\D+/', '', (string) ($post['tax_number'] ?? '')) ?? '';

        if ($isMain) {
            $loc = HfsTrAddress::resolve($city, $district);
            if ($loc === null) {
                return 'Bayt için il ve ilçe seçin.';
            }
            if ($address === '') {
                return 'Adres zorunlu.';
            }
            $city = $loc['city'];
            $district = $loc['district'];
            $postcode = $loc['postcode'];
            $country = 'TR';
        } elseif ($city !== '' || $district !== '') {
            $loc = HfsTrAddress::resolve($city, $district);
            if ($loc === null) {
                return 'Geçerli il ve ilçe seçin.';
            }
            $city = $loc['city'];
            $district = $loc['district'];
            $postcode = $loc['postcode'];
            $country = 'TR';
        }

        if ($isMain) {
            if ($company === '') {
                return 'Firma unvanı zorunlu.';
            }
            if ($taxOffice === '' || $taxNumber === '') {
                return 'Vergi dairesi ve vergi numarası zorunlu.';
            }
            if (strlen($taxNumber) !== 10 && strlen($taxNumber) !== 11) {
                return 'Vergi no 10 veya T.C. 11 haneli olmalı.';
            }
        }

        $passNew = (string) ($post['password_new'] ?? '');
        $passCur = (string) ($post['password_current'] ?? '');
        $passHash = null;
        if ($passNew !== '') {
            if (strlen($passNew) < 8) {
                return 'Yeni şifre en az 8 karakter olmalı.';
            }
            if ($passNew !== (string) ($post['password_confirm'] ?? '')) {
                return 'Yeni şifre tekrarı eşleşmiyor.';
            }
            $hash = (string) ($u['password'] ?? '');
            $curOk = $passCur !== '' && (password_verify($passCur, $hash) || WpPassword::check($passCur, $hash));
            if (!$curOk) {
                return 'Mevcut şifre hatalı.';
            }
            $passHash = password_hash($passNew, PASSWORD_DEFAULT);
        }

        if ($isMain) {
            $sql = 'UPDATE users SET email=?, display_name=?, phone=?, city=?, district=?, postcode=?, country=?, address=?, company=?, tax_office=?, tax_number=?';
            $args = [
                $email,
                $display !== '' ? $display : (string) ($u['username'] ?? ''),
                $phone,
                $city,
                $district,
                $postcode,
                $country,
                $address,
                $company,
                $taxOffice,
                $taxNumber,
            ];
        } else {
            $sql = 'UPDATE users SET email=?, display_name=?, phone=?, city=?, district=?, postcode=?, country=?, address=?';
            $args = [
                $email,
                $display !== '' ? $display : (string) ($u['username'] ?? ''),
                $phone,
                $city,
                $district,
                $postcode,
                $country,
                $address,
            ];
        }
        if ($passHash !== null) {
            $sql .= ', password=?';
            $args[] = $passHash;
        }
        $sql .= ' WHERE id=?';
        $args[] = $id;
        db()->prepare($sql)->execute($args);
        if ($isMain) {
            try {
                HfsBayt::pushDealer($id);
            } catch (Throwable) {
            }
        }
        return null;
    }
}
