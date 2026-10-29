<?php
declare(strict_types=1);

final class HfsDealers
{
    /** @return array<string,string> */
    public static function b2bRoles(): array
    {
        return [
            'company_admin' => 'Firma yöneticisi',
            'buyer' => 'Satın almacı',
            'approver' => 'Onaylayıcı',
            'finance_manager' => 'Finans müdürü',
        ];
    }

    public static function roleLabel(string $role, string $b2b = ''): string
    {
        if ($role === 'admin') {
            return 'Yönetici';
        }
        if ($role === 'editor') {
            return 'Mağaza yöneticisi';
        }
        $map = self::b2bRoles();
        return $map[$b2b] ?? 'Bayi';
    }

    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        return db()->query(
            "SELECT u.*,
                (SELECT COUNT(*) FROM users c WHERE c.parent_user_id=u.id) AS sub_count
             FROM users u
             ORDER BY FIELD(u.role,'admin','editor','dealer') ASC, u.company ASC, u.username ASC"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function search(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return self::list();
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st = db()->prepare(
            "SELECT u.*,
                (SELECT COUNT(*) FROM users c WHERE c.parent_user_id=u.id) AS sub_count
             FROM users u
             WHERE u.company LIKE ?
                OR u.username LIKE ?
                OR u.email LIKE ?
                OR u.display_name LIKE ?
                OR u.tax_number LIKE ?
             ORDER BY FIELD(u.role,'admin','editor','dealer') ASC, u.company ASC, u.username ASC"
        );
        $st->execute([$like, $like, $like, $like, $like]);
        return $st->fetchAll();
    }

    public static function get(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function mains(?int $except = null): array
    {
        $sql = "SELECT id, username, display_name, company FROM users
                WHERE role='dealer' AND (parent_user_id IS NULL OR parent_user_id=0)";
        $args = [];
        if ($except) {
            $sql .= ' AND id<>?';
            $args[] = $except;
        }
        $sql .= ' ORDER BY company, username';
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function save(int $id, array $post): int
    {
        $username = trim((string) ($post['username'] ?? ''));
        $email = trim((string) ($post['email'] ?? ''));
        $pass = (string) ($post['password'] ?? '');
        $display = trim((string) ($post['display_name'] ?? ''));
        $company = trim((string) ($post['company'] ?? ''));
        $phone = trim((string) ($post['phone'] ?? ''));
        $city = trim((string) ($post['city'] ?? ''));
        $district = trim((string) ($post['district'] ?? ''));
        $postcode = preg_replace('/\D+/', '', (string) ($post['postcode'] ?? '')) ?? '';
        $country = strtoupper(trim((string) ($post['country'] ?? 'TR')));
        if ($country !== 'TR') {
            $country = 'TR';
        }
        $address = trim((string) ($post['address'] ?? ''));
        $role = (string) ($post['role'] ?? 'dealer');
        if ($role !== 'admin') {
            $role = 'dealer';
        }
        if ((current_user()['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Bayi ekleme yetkisi yok.');
        }
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Kullanıcı adı ve geçerli e-posta gerekli.');
        }
        if ($id < 1 && strlen($pass) < 8) {
            throw new RuntimeException('Yeni bayi için en az 8 karakter şifre girin.');
        }
        if ($id > 0 && $pass !== '' && strlen($pass) < 8) {
            throw new RuntimeException('Şifre en az 8 karakter olmalı.');
        }
        $parent = (int) ($post['parent_user_id'] ?? 0);
        if ($parent === $id) {
            $parent = 0;
        }
        if ($role === 'dealer' && $parent < 1) {
            $office = trim((string) ($post['tax_office'] ?? ''));
            $vkn = preg_replace('/\D+/', '', (string) ($post['tax_number'] ?? '')) ?? '';
            $len = strlen($vkn);
            if ($office === '' || ($len !== 10 && $len !== 11)) {
                throw new RuntimeException('Ana bayi için vergi dairesi ve 10 haneli vergi no veya 11 haneli T.C. no gerekli (BulutTahsilat / Bayt).');
            }
            $loc = HfsTrAddress::resolve($city, $district);
            if ($loc === null) {
                throw new RuntimeException('Bayt için il ve ilçe seçin.');
            }
            if ($address === '') {
                throw new RuntimeException('Adres zorunlu (Bayt cari).');
            }
            $city = $loc['city'];
            $district = $loc['district'];
            $postcode = $loc['postcode'];
            $country = 'TR';
            $post['tax_office'] = $office;
            $post['tax_number'] = $vkn;
        } elseif ($city !== '' || $district !== '') {
            $loc = HfsTrAddress::resolve($city, $district);
            if ($loc === null) {
                throw new RuntimeException('Geçerli il ve ilçe seçin.');
            }
            $city = $loc['city'];
            $district = $loc['district'];
            $postcode = $loc['postcode'];
            $country = 'TR';
        } else {
            $post['tax_number'] = preg_replace('/\D+/', '', (string) ($post['tax_number'] ?? '')) ?? '';
        }
        $b2b = (string) ($post['b2b_role'] ?? 'company_admin');
        if (!isset(self::b2bRoles()[$b2b])) {
            $b2b = 'company_admin';
        }
        if ($parent > 0 && $role === 'dealer') {
            // sub-account
        } elseif ($role === 'dealer') {
            $parent = 0;
        }
        $dup = db()->prepare('SELECT id FROM users WHERE (username=? OR email=?) AND id<>? LIMIT 1');
        $dup->execute([$username, $email, $id]);
        if ($dup->fetch()) {
            throw new RuntimeException('Bu kullanıcı adı veya e-posta zaten kayıtlı.');
        }
        $fields = [
            $username,
            $email,
            $display !== '' ? $display : $username,
            $role,
            $phone,
            $company,
            $city,
            $district,
            $postcode,
            $country,
            $address,
            trim((string) ($post['tax_office'] ?? '')),
            trim((string) ($post['tax_number'] ?? '')),
            $parent,
            $b2b,
            max(0, min(100, (float) str_replace(',', '.', (string) ($post['discount_percent'] ?? 0)))),
            max(0, (int) ($post['tier_min_qty'] ?? 0)),
            max(0, min(100, (float) str_replace(',', '.', (string) ($post['tier_discount'] ?? 0)))),
            max(0, (float) str_replace(',', '.', (string) ($post['credit_limit'] ?? 0))),
            (float) str_replace(',', '.', (string) ($post['opening_balance'] ?? 0)),
            max(0, (float) str_replace(',', '.', (string) ($post['monthly_limit'] ?? 0))),
            max(0, (float) str_replace(',', '.', (string) ($post['approval_limit'] ?? 0))),
            trim((string) ($post['bayt_customer_code'] ?? '')),
            max(0, (int) ($post['bayt_customer_id'] ?? 0)),
            mb_substr(trim((string) ($post['bt_exp_code'] ?? '')), 0, 80),
        ];
        if ($id > 0) {
            $sql = 'UPDATE users SET username=?,email=?,display_name=?,role=?,phone=?,company=?,city=?,district=?,postcode=?,country=?,address=?,tax_office=?,tax_number=?,parent_user_id=?,b2b_role=?,discount_percent=?,tier_min_qty=?,tier_discount=?,credit_limit=?,opening_balance=?,monthly_limit=?,approval_limit=?,bayt_customer_code=?,bayt_customer_id=?,bt_exp_code=?';
            $args = $fields;
            if ($pass !== '') {
                $sql .= ', password=?';
                $args[] = password_hash($pass, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id=?';
            $args[] = $id;
            db()->prepare($sql)->execute($args);
            self::saveOffers($id, $parent, $post);
            return $id;
        }
        db()->prepare('INSERT INTO users (username,email,display_name,role,phone,company,city,district,postcode,country,address,tax_office,tax_number,parent_user_id,b2b_role,discount_percent,tier_min_qty,tier_discount,credit_limit,opening_balance,monthly_limit,approval_limit,bayt_customer_code,bayt_customer_id,bt_exp_code,password,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...$fields, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        $newId = (int) db()->lastInsertId();
        self::saveOffers($newId, $parent, $post);
        return $newId;
    }

    private static function saveOffers(int $id, int $parent, array $post): void
    {
        if (!isset($post['offer_sku'])) {
            return;
        }
        HfsOffers::saveFromPost($parent > 0 ? $parent : $id);
    }

    /**
     * Bayt carileri + BulutTahsilat alt firmalarını bayi kartına çeker.
     *
     * @return array{ok:bool,message:string,created:int,updated:int,skipped:int,steps:list<string>}
     */
    public static function pullFromAccounting(): array
    {
        bmn_session_release();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $steps = [];

        $bayt = HfsBayt::fetchCustomerRows();
        if (is_string($bayt)) {
            $steps[] = 'Bayt: ' . $bayt;
        } else {
            $steps[] = 'Bayt cari sayısı: ' . count($bayt);
            foreach ($bayt as $row) {
                try {
                    $c = self::fromBaytRow($row);
                    if ($c === null) {
                        $skipped++;
                        continue;
                    }
                    $res = self::upsertFromCari($c);
                    $steps[] = ($res['action'] === 'created' ? 'Yeni' : ($res['action'] === 'updated' ? 'Eşleşti' : 'Atlandı'))
                        . ': ' . $res['label'] . ' (#' . $res['id'] . ')';
                    if ($res['action'] === 'created') {
                        $created++;
                    } elseif ($res['action'] === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $e) {
                    $skipped++;
                    $steps[] = 'Hata: ' . mb_substr($e->getMessage(), 0, 160);
                }
            }
        }

        $subs = HfsPay::fetchSubFirms();
        $steps[] = 'BulutTahsilat cari sayısı: ' . count($subs);
        foreach ($subs as $sub) {
            try {
                $c = self::fromSubFirm($sub);
                if ($c === null) {
                    $skipped++;
                    continue;
                }
                $res = self::upsertFromCari($c);
                $steps[] = ($res['action'] === 'created' ? 'Yeni (Bulut)' : ($res['action'] === 'updated' ? 'Eşleşti (Bulut)' : 'Atlandı (Bulut)'))
                    . ': ' . $res['label'] . ' (#' . $res['id'] . ')';
                if ($res['action'] === 'created') {
                    $created++;
                } elseif ($res['action'] === 'updated') {
                    $updated++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $steps[] = 'Bulut hata: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }

        $msg = $created . ' yeni bayi, ' . $updated . ' güncellendi';
        if ($skipped > 0) {
            $msg .= ', ' . $skipped . ' atlandı';
        }
        $msg .= '.';
        if (is_string($bayt)) {
            $msg .= ' Bayt listesi alınamadı.';
        }
        if (count($steps) > 60) {
            $steps = array_merge(array_slice($steps, 0, 8), ['…'], array_slice($steps, -40));
        }
        return [
            'ok' => !is_string($bayt) || $subs !== [],
            'message' => $msg,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'steps' => $steps,
        ];
    }

    /**
     * BYTE / BulutTahsilat Excel veya CSV içe aktarır.
     *
     * @return array{ok:bool,message:string,created:int,updated:int,skipped:int,steps:list<string>}
     */
    public static function importFile(string $tmpPath, string $origName): array
    {
        $empty = ['ok' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'steps' => []];
        if ($tmpPath === '' || !is_readable($tmpPath)) {
            return $empty + ['message' => 'Dosya yüklenmedi.'];
        }
        $bayt = self::tryImportBaytWorkbook($tmpPath, $origName);
        if ($bayt !== null) {
            return $bayt;
        }
        $rows = self::readTable($tmpPath, $origName);
        if (is_string($rows)) {
            return $empty + ['message' => $rows, 'steps' => [$rows]];
        }
        $map = self::detectCariColumns($rows);
        if ($map === null) {
            $hint = self::headerHint($rows);
            return $empty + [
                'message' => 'Sütunlar tanınmadı. Ünvan veya cari kodu sütunu gerekli.',
                'steps' => $hint !== '' ? ['İlk satırlar: ' . $hint] : [],
            ];
        }
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $steps = ['Kaynak: ' . $origName, 'Başlık satırı: ' . ($map['header'] + 1)];
        $max = min(count($rows), $map['header'] + 2500);
        for ($i = $map['header'] + 1; $i < $max; $i++) {
            $row = $rows[$i] ?? null;
            if (!is_array($row)) {
                continue;
            }
            try {
                $c = self::fromImportRow($row, $map);
                if ($c === null) {
                    $skipped++;
                    continue;
                }
                $res = self::upsertFromCari($c);
                $steps[] = ($res['action'] === 'created' ? 'Yeni' : ($res['action'] === 'updated' ? 'Eşleşti' : 'Atlandı'))
                    . ': ' . $res['label'] . ' (#' . $res['id'] . ')';
                if ($res['action'] === 'created') {
                    $created++;
                } elseif ($res['action'] === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $steps[] = 'Satır ' . ($i + 1) . ': ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        $msg = $created . ' yeni bayi, ' . $updated . ' güncellendi';
        if ($skipped > 0) {
            $msg .= ', ' . $skipped . ' atlandı';
        }
        if (count($steps) > 60) {
            $steps = array_merge(array_slice($steps, 0, 8), ['…'], array_slice($steps, -40));
        }
        return [
            'ok' => $created + $updated > 0,
            'message' => $msg . '.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'steps' => $steps,
        ];
    }

    /**
     * Bayt GetCustomerList dışa aktarımı (Cariler + Adresler sayfaları).
     *
     * @return array{ok:bool,message:string,created:int,updated:int,skipped:int,steps:list<string>}|null
     */
    private static function tryImportBaytWorkbook(string $path, string $origName): ?array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        if ($ext !== 'xlsx') {
            return null;
        }
        if (!self::baytWorkbookHasSheets($path, ['Cariler', 'Adresler'])) {
            return null;
        }
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $cariler = HfsXlsx::read($path, 'Cariler');
        $adresler = HfsXlsx::read($path, 'Adresler');
        if (is_wp_error($cariler) || is_wp_error($adresler) || !is_array($cariler) || !is_array($adresler)) {
            return [
                'ok' => false,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'message' => 'Bayt cari Excel okunamadı.',
                'steps' => ['Cariler/Adresler sayfaları açılamadı.'],
            ];
        }
        $addrByCustomer = self::baytAddressIndex($adresler);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $steps = ['Kaynak: Bayt cari dışa aktarım (' . $origName . ')', 'Cariler: ' . max(0, count($cariler) - 1) . ' satır'];
        $header = array_shift($cariler);
        if (!is_array($header)) {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'message' => 'Cariler başlığı yok.', 'steps' => $steps];
        }
        $col = self::baytHeaderMap($header);
        if (!isset($col['id'], $col['code'], $col['name'])) {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'message' => 'Cariler sütunları tanınmadı.', 'steps' => $steps];
        }
        foreach ($cariler as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $c = self::fromBaytExportRow($row, $col, $addrByCustomer);
                if ($c === null) {
                    $skipped++;
                    continue;
                }
                $res = self::upsertFromBaytCari($c);
                if ($res['action'] === 'created') {
                    $created++;
                } elseif ($res['action'] === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $skipped++;
                if (count($steps) < 12) {
                    $steps[] = 'Satır ' . ($i + 2) . ': ' . mb_substr($e->getMessage(), 0, 120);
                }
            }
        }
        $msg = $created . ' yeni bayi, ' . $updated . ' güncellendi (Bayt alanları)';
        if ($skipped > 0) {
            $msg .= ', ' . $skipped . ' atlandı';
        }
        return [
            'ok' => $created + $updated > 0,
            'message' => $msg . '.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'steps' => $steps,
        ];
    }

    /** @param list<string> $need */
    private static function baytWorkbookHasSheets(string $path, array $need): bool
    {
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        $zip = HfsZip::extract($path);
        if (is_wp_error($zip)) {
            return false;
        }
        $wb = $zip['xl/workbook.xml'] ?? '';
        if ($wb === '') {
            return false;
        }
        foreach ($need as $name) {
            if (!str_contains($wb, 'name="' . $name . '"')) {
                return false;
            }
        }
        return true;
    }

    /** @param list<list<mixed>> $adresler @return array<int,list<mixed>> */
    private static function baytAddressIndex(array $adresler): array
    {
        if ($adresler === []) {
            return [];
        }
        array_shift($adresler);
        $out = [];
        foreach ($adresler as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = (int) ($row[0] ?? 0);
            if ($cid < 1) {
                continue;
            }
            if (!isset($out[$cid]) || self::baytAddressScore($row) > self::baytAddressScore($out[$cid])) {
                $out[$cid] = $row;
            }
        }
        return $out;
    }

    /** @param list<mixed> $row */
    private static function baytAddressScore(array $row): int
    {
        $tax = preg_replace('/\D+/', '', (string) ($row[5] ?? '')) ?: '';
        $score = strlen($tax) >= 10 ? 20 : 0;
        if (trim((string) ($row[17] ?? '')) !== '') {
            $score += 5;
        }
        if (trim((string) ($row[12] ?? '')) !== '') {
            $score += 3;
        }
        if (trim((string) ($row[6] ?? '')) !== '') {
            $score += 1;
        }
        return $score;
    }

    /** @param list<mixed> $header @return array<string,int> */
    private static function baytHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $cell) {
            $h = self::normHeader((string) $cell);
            if ($h === 'id') {
                $map['id'] = (int) $i;
            } elseif ($h === 'code') {
                $map['code'] = (int) $i;
            } elseif ($h === 'name') {
                $map['name'] = (int) $i;
            } elseif ($h === 'customertype') {
                $map['type'] = (int) $i;
            } elseif ($h === 'b2bkullaniciadi') {
                $map['b2b_user'] = (int) $i;
            }
        }
        return $map;
    }

    /**
     * @param list<mixed> $row
     * @param array<string,int> $col
     * @param array<int,list<mixed>> $addrByCustomer
     * @return array<string,mixed>|null
     */
    private static function fromBaytExportRow(array $row, array $col, array $addrByCustomer): ?array
    {
        $id = (int) ($row[$col['id']] ?? 0);
        $code = trim((string) ($row[$col['code']] ?? ''));
        $name = trim((string) ($row[$col['name']] ?? ''));
        if ($id < 1 && $code === '' && $name === '') {
            return null;
        }
        $type = isset($col['type']) ? mb_strtolower(trim((string) ($row[$col['type']] ?? ''))) : '';
        if ($type !== ''
            && (str_contains($type, 'satıcı') || str_contains($type, 'satici'))
            && !str_contains($type, 'alıcı') && !str_contains($type, 'alici')) {
            return null;
        }
        if (self::skipCari($code, $name)) {
            return null;
        }
        $b2bUser = isset($col['b2b_user']) ? trim((string) ($row[$col['b2b_user']] ?? '')) : '';
        $addr = $addrByCustomer[$id] ?? null;
        $taxOffice = '';
        $tax = '';
        $address = '';
        $district = '';
        $city = '';
        $postcode = '';
        $country = 'TR';
        $phone = '';
        $email = '';
        if (is_array($addr)) {
            $taxOffice = trim((string) ($addr[4] ?? ''));
            $tax = preg_replace('/\D+/', '', (string) ($addr[5] ?? '')) ?: '';
            $a1 = trim((string) ($addr[6] ?? ''));
            $a2 = trim((string) ($addr[7] ?? ''));
            $address = trim($a1 . ($a2 !== '' ? ' ' . $a2 : ''));
            $district = trim((string) ($addr[9] ?? ''));
            $city = self::parseBaytJsonCell((string) ($addr[10] ?? ''));
            if ($city === '') {
                $city = trim((string) ($addr[9] ?? ''));
            }
            $countryRaw = self::parseBaytJsonCell((string) ($addr[11] ?? ''));
            $country = self::normalizeCountry($countryRaw);
            $postcode = preg_replace('/\D+/', '', (string) ($addr[12] ?? '')) ?: '';
            $phone = trim((string) ($addr[16] ?? ''));
            if ($phone === '' || $phone === '0') {
                $phone = trim((string) ($addr[13] ?? ''));
            }
            $phone = self::cleanPhone($phone);
            $email = trim((string) ($addr[17] ?? ''));
        }
        $user = $b2bUser !== '' ? $b2bUser : ($code !== '' ? $code : ('bayt' . $id));
        return [
            'username' => self::usernameFromCode($user),
            'email' => $email,
            'display_name' => $name !== '' ? $name : $code,
            'company' => $name !== '' ? $name : $code,
            'phone' => $phone,
            'city' => $city,
            'district' => $district,
            'postcode' => $postcode,
            'country' => $country,
            'address' => $address,
            'tax_office' => $taxOffice,
            'tax_number' => $tax,
            'bayt_customer_code' => $code,
            'bayt_customer_id' => $id,
            'bt_exp_code' => '',
            'match_user_id' => 0,
        ];
    }

    private static function parseBaytJsonCell(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if ($s[0] === '{') {
            $j = json_decode($s, true);
            if (is_array($j)) {
                $d = trim((string) ($j['Description'] ?? $j['Code'] ?? ''));
                if ($d !== '') {
                    return $d;
                }
            }
        }
        return $s;
    }

    private static function normalizeCountry(string $raw): string
    {
        $u = mb_strtoupper(trim($raw));
        if ($u === '' || str_contains($u, 'TÜRK') || $u === 'TR' || $u === 'TURKIYE') {
            return 'TR';
        }
        return mb_substr($u, 0, 2);
    }

    private static function cleanPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '' || $phone === '0') {
            return '';
        }
        return preg_replace('/\s+/', ' ', $phone) ?? $phone;
    }

    /** @param array<string,mixed> $c @return array{action:string,id:int,label:string} */
    private static function upsertFromBaytCari(array $c): array
    {
        $label = trim((string) ($c['company'] ?: $c['display_name'] ?: $c['username']));
        $row = self::matchCari($c);
        if ($row && ($row['role'] ?? '') === 'admin') {
            return ['action' => 'skipped', 'id' => (int) $row['id'], 'label' => $label];
        }
        if ($row) {
            self::fillDealerBayt($row, $c);
            return ['action' => 'updated', 'id' => (int) $row['id'], 'label' => $label];
        }
        $id = self::insertDealer($c);
        return ['action' => 'created', 'id' => $id, 'label' => $label];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $c */
    private static function fillDealerBayt(array $row, array $c): void
    {
        $id = (int) $row['id'];
        $sets = [];
        $args = [];
        $map = [
            'company' => 'company',
            'display_name' => 'display_name',
            'phone' => 'phone',
            'city' => 'city',
            'district' => 'district',
            'postcode' => 'postcode',
            'country' => 'country',
            'address' => 'address',
            'tax_office' => 'tax_office',
            'tax_number' => 'tax_number',
        ];
        foreach ($map as $col => $src) {
            $incoming = trim((string) ($c[$src] ?? ''));
            if ($incoming === '') {
                continue;
            }
            $sets[] = $col . '=?';
            $args[] = $incoming;
        }
        $bid = (int) ($c['bayt_customer_id'] ?? 0);
        if ($bid > 0) {
            $sets[] = 'bayt_customer_id=?';
            $args[] = $bid;
        }
        $code = trim((string) ($c['bayt_customer_code'] ?? ''));
        if ($code !== '') {
            $sets[] = 'bayt_customer_code=?';
            $args[] = $code;
        }
        $email = trim((string) ($c['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $dup = db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
            $dup->execute([$email, $id]);
            if (!$dup->fetch()) {
                $sets[] = 'email=?';
                $args[] = $email;
            }
        }
        if ($sets === []) {
            return;
        }
        $args[] = $id;
        db()->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=?')->execute($args);
    }

    public static function exportTemplate(): void
    {
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $rows = [
            ['Cari kodu', 'Ünvan', 'Vergi dairesi', 'Vergi no', 'Telefon', 'Adres', 'İl', 'E-posta', 'PaymentExpCode', 'Bayt ID'],
            ['120.01.001', 'Örnek Ticaret Ltd. Şti.', 'Yıldırım', '1234567890', '2240000000', 'Örnek cad. no 1', 'Bursa', 'ornek@firma.com', '', ''],
        ];
        $talimat = [
            ['BYTE: Cari hesap listesini Excel’e aktarın. .xls ise Excel’de xlsx veya CSV kaydedin.'],
            ['BulutTahsilat: Cari / alt firma listesini Excel dışa aktarın.'],
            ['Sütun adları birebir olmak zorunda değil; Kodu, Ünvan, Vergi No, PN otomatik tanınır.'],
            ['Mevcut bayiler silinmez; vergi no / kod / ünvan ile eşlenir.'],
            ['Yeni hesapların şifresini bayi kartından verin.'],
        ];
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfs-cari-' . bin2hex(random_bytes(4)) . '.xlsx';
        $written = HfsXlsx::write([
            ['name' => 'Cariler', 'rows' => $rows, 'col_types' => ['s', 's', 's', 's', 's', 's', 's', 's', 's', 's']],
            ['name' => 'Talimat', 'rows' => $talimat, 'col_types' => ['s']],
        ], $tmp);
        if (is_wp_error($written)) {
            http_response_code(500);
            echo $written->get_error_message();
            exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="hfs-cari-sablon.xlsx"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('X-Robots-Tag: noindex');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** @return list<list<mixed>>|string */
    private static function readTable(string $path, string $origName): array|string
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        if (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            return self::readCsv($path);
        }
        if ($ext === 'xls') {
            return 'Bu eski .xls. Excel’de Farklı Kaydet → Excel Çalışma Kitabı (.xlsx) veya CSV yapıp tekrar yükleyin.';
        }
        if ($ext !== 'xlsx' && $ext !== '') {
            return 'xlsx veya csv yükleyin.';
        }
        require_once BMN_ROOT . '/app/lib/HfsZip.php';
        require_once BMN_ROOT . '/app/lib/HfsXlsx.php';
        $rows = HfsXlsx::read($path);
        if (is_wp_error($rows)) {
            return $rows->get_error_message();
        }
        return is_array($rows) ? $rows : 'Excel okunamadı.';
    }

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $conv = @iconv('WINDOWS-1254', 'UTF-8//IGNORE', $raw);
            if (is_string($conv) && $conv !== '') {
                $raw = $conv;
            }
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $delim = ';';
        $semi = substr_count($raw, ';');
        $comma = substr_count($raw, ',');
        $tab = substr_count($raw, "\t");
        if ($tab > $semi && $tab > $comma) {
            $delim = "\t";
        } elseif ($comma > $semi && $tab <= $comma) {
            $delim = ',';
        }
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = str_getcsv($line, $delim);
        }
        return $out;
    }

    /** @param list<list<mixed>> $rows @return array<string,int>|null */
    private static function detectCariColumns(array $rows): ?array
    {
        $aliases = [
            'code' => ['kod', 'kodu', 'carikodu', 'carikod', 'hesapkodu', 'muhasebekodu', 'code', 'customercode', 'accountingcode', 'dealercode'],
            'name' => ['unvan', 'unvani', 'adi', 'ad', 'cariadi', 'cariunvan', 'firma', 'firmadi', 'firmname', 'title', 'name', 'unvanunvan'],
            'tax_office' => ['vergidairesi', 'vergidairesiadi', 'taxoffice'],
            'tax' => ['vergino', 'verginumarasi', 'verginumara', 'vkn', 'tckn', 'tc', 'taxnumber', 'taxno'],
            'phone' => ['telefon', 'tel', 'gsm', 'cep', 'phone', 'telefon1'],
            'address' => ['adres', 'adres1', 'address', 'address1'],
            'city' => ['il', 'iladi', 'sehir', 'city'],
            'email' => ['eposta', 'email', 'mail', 'e-posta'],
            'exp' => ['paymentexpcode', 'paymentexp', 'pn', 'pnkodu', 'bulutkodu', 'carikartkodu', 'firmcode', 'firmakodu'],
            'bayt_id' => ['baytid', 'cariid'],
            'username' => ['kullanici', 'kullaniciadi', 'username', 'dealer'],
        ];
        $best = null;
        $bestScore = 0;
        $scan = min(count($rows), 20);
        for ($i = 0; $i < $scan; $i++) {
            $row = $rows[$i] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $found = ['header' => $i];
            $score = 0;
            foreach ($row as $c => $cell) {
                $h = self::normHeader((string) $cell);
                if ($h === '' || strlen($h) > 40) {
                    continue;
                }
                foreach ($aliases as $field => $list) {
                    if (isset($found[$field])) {
                        continue;
                    }
                    if (in_array($h, $list, true) || ($field === 'name' && str_contains($h, 'unvan')) || ($field === 'code' && str_contains($h, 'kod') && !str_contains($h, 'vergi') && !str_contains($h, 'pn'))) {
                        $found[$field] = (int) $c;
                        $score += $field === 'name' || $field === 'code' || $field === 'tax' || $field === 'exp' ? 3 : 1;
                    }
                }
            }
            if (($found['name'] ?? $found['code'] ?? $found['tax'] ?? $found['exp'] ?? null) === null) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $found;
            }
        }
        return $best;
    }

    /** @param list<list<mixed>> $rows */
    private static function headerHint(array $rows): string
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $t = trim((string) $cell);
                if ($t !== '') {
                    $cells[] = $t;
                }
                if (count($cells) >= 8) {
                    break;
                }
            }
            if (count($cells) >= 2) {
                return implode(' | ', $cells);
            }
        }
        return '';
    }

    private static function normHeader(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u',
            'ş' => 's', 'Ş' => 's', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        ]);
        return (string) preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /**
     * @param array<int|string,mixed> $row
     * @param array<string,int> $map
     * @return array<string,mixed>|null
     */
    private static function fromImportRow(array $row, array $map): ?array
    {
        $cell = static function (string $key) use ($row, $map): string {
            if (!isset($map[$key])) {
                return '';
            }
            return trim((string) ($row[$map[$key]] ?? ''));
        };
        $code = $cell('code');
        $name = $cell('name');
        $exp = $cell('exp');
        if ($exp === '' && preg_match('/^PN[A-Z0-9]+$/i', $code)) {
            $exp = $code;
        }
        $user = $cell('username');
        if (self::skipCari($code, $name) && $exp === '') {
            return null;
        }
        if ($name === '' && $code === '' && $exp === '') {
            return null;
        }
        $tax = preg_replace('/\D+/', '', $cell('tax')) ?: '';
        $id = (int) preg_replace('/\D+/', '', $cell('bayt_id'));
        $label = $name !== '' ? $name : ($code !== '' ? $code : $exp);
        return [
            'username' => self::usernameFromCode($user !== '' ? $user : ($code !== '' ? $code : ($exp !== '' ? $exp : 'bayi'))),
            'email' => $cell('email'),
            'display_name' => $label,
            'company' => $name !== '' ? $name : $label,
            'phone' => $cell('phone'),
            'city' => $cell('city'),
            'address' => $cell('address'),
            'tax_office' => $cell('tax_office'),
            'tax_number' => $tax,
            'bayt_customer_code' => $code,
            'bayt_customer_id' => $id,
            'bt_exp_code' => $exp,
            'match_user_id' => 0,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function fromBaytRow(array $row): ?array
    {
        $id = (int) ($row['Id'] ?? $row['id'] ?? 0);
        $code = trim((string) ($row['Code'] ?? $row['CustomerCode'] ?? ''));
        $name = trim((string) ($row['Name'] ?? $row['CustomerName'] ?? $row['Title'] ?? ''));
        if (self::skipCari($code, $name)) {
            return null;
        }
        $type = mb_strtolower((string) ($row['CustomerType'] ?? ''));
        if ($type !== '' && !str_contains($type, 'alıcı') && !str_contains($type, 'alici') && str_contains($type, 'satıcı')) {
            return null;
        }
        $mail = trim((string) ($row['Mail'] ?? $row['Email'] ?? $row['EMail'] ?? ''));
        $phone = trim((string) ($row['Telephone1'] ?? $row['Gsm'] ?? $row['Phone'] ?? ''));
        $tax = preg_replace('/\D+/', '', (string) ($row['TaxNumber'] ?? $row['TaxNo'] ?? '')) ?: '';
        return [
            'username' => self::usernameFromCode($code !== '' ? $code : ('bayt' . $id)),
            'email' => $mail,
            'display_name' => $name !== '' ? $name : $code,
            'company' => $name,
            'phone' => $phone,
            'city' => trim((string) ($row['City'] ?? '')),
            'address' => trim((string) ($row['Address1'] ?? $row['Address'] ?? '')),
            'tax_office' => trim((string) ($row['TaxOffice'] ?? '')),
            'tax_number' => $tax,
            'bayt_customer_code' => $code,
            'bayt_customer_id' => $id,
            'bt_exp_code' => '',
            'match_user_id' => 0,
        ];
    }

    /** @param array<string,string> $sub */
    private static function fromSubFirm(array $sub): ?array
    {
        $name = trim((string) ($sub['firm_name'] ?? ''));
        $exp = trim((string) ($sub['exp'] ?? ''));
        $acc = trim((string) ($sub['acc'] ?? ''));
        $dealer = trim((string) ($sub['dealer'] ?? ''));
        if (self::skipCari($acc !== '' ? $acc : $dealer, $name)) {
            return null;
        }
        $code = $acc !== '' ? $acc : ($dealer !== '' ? $dealer : $exp);
        $uid = (int) ($sub['reserved'] ?? 0);
        return [
            'username' => self::usernameFromCode($dealer !== '' ? $dealer : ($code !== '' ? $code : 'bayi')),
            'email' => '',
            'display_name' => $name !== '' ? $name : $code,
            'company' => $name,
            'phone' => (string) ($sub['phone'] ?? ''),
            'city' => (string) ($sub['city'] ?? ''),
            'address' => (string) ($sub['address'] ?? ''),
            'tax_office' => (string) ($sub['office'] ?? ''),
            'tax_number' => (string) ($sub['tax'] ?? ''),
            'bayt_customer_code' => $acc,
            'bayt_customer_id' => 0,
            'bt_exp_code' => $exp,
            'match_user_id' => $uid,
        ];
    }

    private static function skipCari(string $code, string $name): bool
    {
        $n = mb_strtolower(trim($name));
        $c = mb_strtolower(trim($code));
        if ($n === '' && $c === '') {
            return true;
        }
        foreach (['hfs test', 'test cari'] as $bad) {
            if (str_contains($n, $bad)) {
                return true;
            }
        }
        if (preg_match('/^(kasa|banka|pos)\b/u', $n) || preg_match('/^(kasa|banka)/i', $c)) {
            return true;
        }
        return false;
    }

    private static function usernameFromCode(string $code): string
    {
        $s = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $code));
        if ($s === '') {
            $s = 'bayi';
        }
        if (preg_match('/^[0-9]/', $s)) {
            $s = 'c' . $s;
        }
        return mb_substr($s, 0, 40);
    }

    /**
     * @param array<string,mixed> $c
     * @return array{action:string,id:int,label:string}
     */
    private static function upsertFromCari(array $c): array
    {
        $label = trim((string) ($c['company'] ?: $c['display_name'] ?: $c['username']));
        $row = self::matchCari($c);
        if ($row && ($row['role'] ?? '') === 'admin') {
            return ['action' => 'skipped', 'id' => (int) $row['id'], 'label' => $label];
        }
        if ($row) {
            self::fillDealer($row, $c);
            return ['action' => 'updated', 'id' => (int) $row['id'], 'label' => $label];
        }
        $id = self::insertDealer($c);
        return ['action' => 'created', 'id' => $id, 'label' => $label];
    }

    /** @param array<string,mixed> $c */
    private static function matchCari(array $c): ?array
    {
        $uid = (int) ($c['match_user_id'] ?? 0);
        if ($uid > 0) {
            $got = self::get($uid);
            if ($got && ($got['role'] ?? '') !== 'admin') {
                return $got;
            }
        }
        $bid = (int) ($c['bayt_customer_id'] ?? 0);
        if ($bid > 0) {
            $st = db()->prepare('SELECT * FROM users WHERE bayt_customer_id=? LIMIT 1');
            $st->execute([$bid]);
            $got = $st->fetch();
            if ($got) {
                return $got;
            }
        }
        $exp = trim((string) ($c['bt_exp_code'] ?? ''));
        if ($exp !== '') {
            $st = db()->prepare('SELECT * FROM users WHERE bt_exp_code=? LIMIT 1');
            $st->execute([$exp]);
            $got = $st->fetch();
            if ($got) {
                return $got;
            }
        }
        $code = trim((string) ($c['bayt_customer_code'] ?? ''));
        if ($code !== '') {
            $st = db()->prepare('SELECT * FROM users WHERE bayt_customer_code=? LIMIT 1');
            $st->execute([$code]);
            $got = $st->fetch();
            if ($got) {
                return $got;
            }
        }
        $tax = preg_replace('/\D+/', '', (string) ($c['tax_number'] ?? '')) ?: '';
        if (strlen($tax) >= 10) {
            $st = db()->prepare("SELECT * FROM users WHERE REPLACE(REPLACE(tax_number,' ',''),'-','')=? AND role='dealer' LIMIT 1");
            $st->execute([$tax]);
            $got = $st->fetch();
            if ($got) {
                return $got;
            }
        }
        $user = trim((string) ($c['username'] ?? ''));
        if ($user !== '') {
            $st = db()->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
            $st->execute([$user]);
            $got = $st->fetch();
            if ($got) {
                return $got;
            }
        }
        $company = trim((string) ($c['company'] ?? ''));
        if ($company !== '') {
            $st = db()->prepare("SELECT * FROM users WHERE role='dealer' AND (parent_user_id IS NULL OR parent_user_id=0) AND LOWER(company)=LOWER(?) LIMIT 1");
            $st->execute([$company]);
            $got = $st->fetch();
            if ($got) {
                return $got;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $c */
    private static function fillDealer(array $row, array $c): void
    {
        $id = (int) $row['id'];
        $sets = [];
        $args = [];
        $map = [
            'company' => 'company',
            'display_name' => 'display_name',
            'phone' => 'phone',
            'city' => 'city',
            'address' => 'address',
            'tax_office' => 'tax_office',
            'tax_number' => 'tax_number',
        ];
        foreach ($map as $col => $src) {
            $incoming = trim((string) ($c[$src] ?? ''));
            $have = trim((string) ($row[$col] ?? ''));
            if ($incoming !== '' && $have === '') {
                $sets[] = $col . '=?';
                $args[] = $incoming;
            }
        }
        $bid = (int) ($c['bayt_customer_id'] ?? 0);
        if ($bid > 0 && (int) ($row['bayt_customer_id'] ?? 0) < 1) {
            $sets[] = 'bayt_customer_id=?';
            $args[] = $bid;
        }
        $code = trim((string) ($c['bayt_customer_code'] ?? ''));
        if ($code !== '' && trim((string) ($row['bayt_customer_code'] ?? '')) === '') {
            $sets[] = 'bayt_customer_code=?';
            $args[] = $code;
        }
        $exp = trim((string) ($c['bt_exp_code'] ?? ''));
        if ($exp !== '' && trim((string) ($row['bt_exp_code'] ?? '')) === '') {
            $sets[] = 'bt_exp_code=?';
            $args[] = $exp;
        }
        if ($sets === []) {
            return;
        }
        $args[] = $id;
        db()->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=?')->execute($args);
    }

    /** @param array<string,mixed> $c */
    private static function insertDealer(array $c): int
    {
        $base = self::usernameFromCode((string) ($c['username'] ?? 'bayi'));
        $username = $base;
        $n = 2;
        while (true) {
            $st = db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
            $st->execute([$username]);
            if (!$st->fetch()) {
                break;
            }
            $username = mb_substr($base, 0, 36) . $n;
            $n++;
        }
        $email = trim((string) ($c['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $username . '@cari.hfstarim.com';
        }
        $st = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) {
            $email = $username . '.' . bin2hex(random_bytes(2)) . '@cari.hfstarim.com';
        }
        $company = trim((string) ($c['company'] ?? ''));
        $display = trim((string) ($c['display_name'] ?? ''));
        if ($display === '') {
            $display = $company !== '' ? $company : $username;
        }
        $pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        db()->prepare('INSERT INTO users (username,email,display_name,role,phone,company,city,district,postcode,country,address,tax_office,tax_number,parent_user_id,b2b_role,discount_percent,tier_min_qty,tier_discount,credit_limit,opening_balance,monthly_limit,approval_limit,bayt_customer_code,bayt_customer_id,bt_exp_code,password,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,\'company_admin\',0,0,0,0,0,0,0,?,?,?,?,NOW())')
            ->execute([
                $username,
                $email,
                $display,
                'dealer',
                trim((string) ($c['phone'] ?? '')),
                $company,
                trim((string) ($c['city'] ?? '')),
                trim((string) ($c['district'] ?? '')),
                trim((string) ($c['postcode'] ?? '')),
                strtoupper(trim((string) ($c['country'] ?? 'TR'))) ?: 'TR',
                trim((string) ($c['address'] ?? '')),
                trim((string) ($c['tax_office'] ?? '')),
                trim((string) ($c['tax_number'] ?? '')),
                trim((string) ($c['bayt_customer_code'] ?? '')),
                max(0, (int) ($c['bayt_customer_id'] ?? 0)),
                mb_substr(trim((string) ($c['bt_exp_code'] ?? '')), 0, 80),
                $pass,
            ]);
        $newId = (int) db()->lastInsertId();
        self::applyTaxPortalCredentials($newId);

        return $newId;
    }

    /**
     * Tek bayi: kullanıcı adı + ilk şifre = vergi/TC, ilk girişte şifre değişimi zorunlu.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function applyTaxPortalCredentials(int $userId): array
    {
        if ($userId < 1) {
            return ['ok' => false, 'reason' => 'id'];
        }
        HfsMigrate::up();
        $st = db()->prepare("SELECT id, username, email, company, display_name, tax_number, role FROM users WHERE id=? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row || (string) ($row['role'] ?? '') !== 'dealer') {
            return ['ok' => false, 'reason' => 'bayi değil'];
        }
        $vkn = preg_replace('/\D+/', '', (string) ($row['tax_number'] ?? '')) ?? '';
        $len = strlen($vkn);
        if ($len !== 10 && $len !== 11) {
            return ['ok' => false, 'reason' => 'geçersiz vergi/tc'];
        }
        $dup = db()->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
        $dup->execute([$vkn, $userId]);
        if ($dup->fetch()) {
            return ['ok' => false, 'reason' => 'username çakışması'];
        }
        $hash = password_hash($vkn, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET username=?, password=?, must_change_password=1 WHERE id=?')
            ->execute([$vkn, $hash, $userId]);
        try {
            db()->prepare('DELETE FROM auth_remember WHERE user_id=?')->execute([$userId]);
        } catch (Throwable) {
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Bayi girişi: kullanıcı adı ve ilk şifre = vergi/TC (rakamlar).
     *
     * @return array{updated:int,skipped:int,conflicts:array<int,string>,mail_ok:int,mail_fail:int,errors:array<int,string>}
     */
    public static function rolloutTaxCredentials(bool $sendMail = false, int $offset = 0, int $limit = 0): array
    {
        HfsMigrate::up();
        $stats = ['updated' => 0, 'skipped' => 0, 'conflicts' => [], 'mail_ok' => 0, 'mail_fail' => 0, 'errors' => [], 'offset' => $offset, 'limit' => $limit];
        $sql = "SELECT id, username, email, company, display_name, tax_number FROM users WHERE role='dealer' ORDER BY id ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . max(0, $offset) . ',' . min(500, $limit);
        }
        $st = db()->query($sql);
        $rows = $st ? $st->fetchAll() : [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $res = self::applyTaxPortalCredentials($id);
            if (!$res['ok']) {
                $stats['skipped']++;
                if ($res['reason'] === 'username çakışması') {
                    $vkn = preg_replace('/\D+/', '', (string) ($row['tax_number'] ?? '')) ?? '';
                    $stats['conflicts'][] = 'id=' . $id . ' username çakışması: ' . $vkn;
                } else {
                    $stats['errors'][] = 'id=' . $id . ' ' . $res['reason'];
                }
                continue;
            }
            $stats['updated']++;
            if (!$sendMail) {
                continue;
            }
            $vkn = preg_replace('/\D+/', '', (string) ($row['tax_number'] ?? '')) ?? '';
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['mail_fail']++;
                continue;
            }
            $sent = HfsMail::dealerPortalLoginNotice($row, $vkn);
            if ($sent['ok']) {
                $stats['mail_ok']++;
            } else {
                $stats['mail_fail']++;
            }
        }
        return $stats;
    }
}
