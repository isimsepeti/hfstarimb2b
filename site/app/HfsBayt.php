<?php
declare(strict_types=1);

final class HfsBayt
{
    private static array $session = [];
    private static ?string $portOverride = null;
    private static ?string $protocolOverride = null;

    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'document_type' => 'waybill',
            'auto_create_customer' => '1',
            'protocol' => 'http',
            'api_host' => '141.98.114.2',
            'api_port' => '25066',
            'company_id' => '5',
            'partner_user' => '',
            'partner_pass' => '',
            'connect_user' => 'BYTE',
            'connect_pass' => '',
            'token_url' => 'https://www.bay-t.com.tr/baytapi/baytapiservice/GetToken',
            'store_id' => '1',
            'currency_id' => '1',
            'unit_id' => '3',
            'sales_rep_id' => '1',
            'vat_included' => '1',
            'customer_code_prefix' => 'HFS',
            'cash_id' => '1',
            'bank_id' => '-1',
            'bank_id_ziraat' => '12',
            'bank_id_kuveyt' => '9',
            'pos_merchant_ziraat' => '00922',
            'pos_merchant_kuveyt' => '00205',
            'auto_waybill' => '0',
            'bayt_edi_stage' => '1',
        ];
    }

    public static function settings(): array
    {
        $raw = setting('bayt_json', '');
        $saved = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($saved)) {
            $saved = [];
        }
        $out = array_merge(self::defaults(), $saved);
        $out['document_type'] = 'waybill';
        return $out;
    }

    public static function save(array $post): void
    {
        $cur = self::settings();
        $out = $cur;
        foreach (self::defaults() as $k => $def) {
            if (in_array($k, ['enabled', 'auto_create_customer', 'vat_included', 'auto_waybill', 'bayt_edi_stage'], true)) {
                $out[$k] = empty($post[$k]) ? '0' : '1';
                continue;
            }
            if (!isset($post[$k])) {
                continue;
            }
            $val = (string) $post[$k];
            if (in_array($k, ['partner_pass', 'connect_pass'], true) && $val === '') {
                continue;
            }
            $out[$k] = $val;
        }
        if (!empty($post['clear_connect_pass'])) {
            $out['connect_pass'] = '';
        }
        $out['document_type'] = 'waybill';
        setting_set('bayt_json', json_encode($out, JSON_UNESCAPED_UNICODE));
        self::$session = [];
    }

    public static function enabled(): bool
    {
        $s = self::settings();
        return ($s['enabled'] ?? '0') === '1';
    }

    /**
     * Bayt’ta olmayan bir cari kodunu oluşturup okur, test kaydını siler.
     *
     * @return array{ok:bool,message:string,steps:list<string>}
     */
    public static function testMissingCustomer(): array
    {
        $steps = [];
        $s = self::settings();
        $steps[] = 'Bayt otomatik gönderim: ' . ((($s['enabled'] ?? '0') === '1') ? 'açık' : 'kapalı');
        $steps[] = 'Cari yoksa oluştur: ' . ((($s['auto_create_customer'] ?? '1') === '1') ? 'evet' : 'hayır');
        $auth = self::ensureSession();
        if (is_string($auth)) {
            $steps[] = 'Oturum: ' . $auth;
            return ['ok' => false, 'message' => $auth, 'steps' => $steps];
        }
        $steps[] = 'Oturum: OK';
        if (($s['auto_create_customer'] ?? '1') !== '1') {
            $steps[] = 'Bayt’ta cari yoksa tahsilat/sipariş “Bayt carisi bulunamadı” der, yeni cari açılmaz.';
            return ['ok' => false, 'message' => 'Otomatik cari oluşturma kapalı.', 'steps' => $steps];
        }

        $code = 'HT' . date('His');
        $steps[] = 'Test cari kodu: ' . $code;
        $lookup = self::api('PartnerApiCustomerOperations/GetCustomerByCode', ['Code' => $code]);
        $exists = !is_string($lookup) && !empty($lookup['Success']) && (int) ($lookup['Data']['Id'] ?? 0) > 0;
        $steps[] = $exists ? ($code . ' beklenmedik şekilde vardı.') : ($code . ' Bayt’ta yok (doğru).');

        $acc = self::api('PartnerApiCustomerOperations/GetAccountingCodesList', ['SkipCount' => 0, 'TakeCount' => 20]);
        $buyId = 1;
        $sellId = 2;
        if (!is_string($acc) && !empty($acc['Success'])) {
            $list = $acc['Data'] ?? [];
            if (isset($list['Items']) && is_array($list['Items'])) {
                $list = $list['Items'];
            }
            if (is_array($list)) {
                foreach ($list as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $aid = (int) ($row['Id'] ?? 0);
                    $an = mb_strtolower((string) ($row['Name'] ?? $row['Code'] ?? ''));
                    if ($aid > 0 && $buyId === 1 && (str_contains($an, 'al') || str_contains($an, '120'))) {
                        $buyId = $aid;
                    }
                    if ($aid > 0 && $sellId === 2 && (str_contains($an, 'sat') || str_contains($an, '320'))) {
                        $sellId = $aid;
                    }
                }
                if ($buyId === 1 || $sellId === 2) {
                    foreach ($list as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $aid = (int) ($row['Id'] ?? 0);
                        if ($aid > 0 && $buyId === 1) {
                            $buyId = $aid;
                        } elseif ($aid > 0 && $aid !== $buyId && $sellId === 2) {
                            $sellId = $aid;
                            break;
                        }
                    }
                }
            }
            $steps[] = 'Muhasebe kodları: alım=' . $buyId . ' satış=' . $sellId;
        } else {
            $steps[] = 'Muhasebe kod listesi alınamadı, 1/2 denenecek.';
        }

        $created = self::api('PartnerApiCustomerOperations/CreateCustomer', self::customerPayload($code, 'HFS test', [
            'Address1' => 'Test',
            'CitySubdivision' => 'Nilufer',
            'City' => 'Bursa',
            'Country' => 'TURKIYE',
            'PostCode' => '16200',
            'Telephone1' => '2242161601',
            'PurchasedAccountingId' => $buyId,
            'SellerAccountingId' => $sellId,
        ]));
        if (is_string($created) || empty($created['Success'])) {
            $msg = is_string($created) ? $created : self::apiMessage($created, 'CreateCustomer başarısız.');
            $steps[] = 'CreateCustomer: ' . $msg;
            return ['ok' => false, 'message' => $msg, 'steps' => $steps];
        }
        $newId = (int) ($created['Data']['Id'] ?? $created['Id'] ?? 0);
        $steps[] = 'CreateCustomer: OK' . ($newId > 0 ? (' id=' . $newId) : '');

        $again = self::api('PartnerApiCustomerOperations/GetCustomerByCode', ['Code' => $code]);
        $againId = (!is_string($again) && !empty($again['Success'])) ? (int) ($again['Data']['Id'] ?? 0) : 0;
        if ($againId < 1) {
            $steps[] = 'GetCustomerByCode: oluşturulan cari okunamadı.';
            return ['ok' => false, 'message' => 'Cari yazıldı ama tekrar okunamadı.', 'steps' => $steps];
        }
        $steps[] = 'GetCustomerByCode: bulundu id=' . $againId;

        $del = self::api('PartnerApiCustomerOperations/DeleteCustomer', ['Id' => $againId]);
        if (is_string($del) || empty($del['Success'])) {
            $steps[] = 'DeleteCustomer: ' . (is_string($del) ? $del : self::apiMessage($del, 'silinemedi'));
            $steps[] = 'Uyarı: test cari Bayt’ta kalmış olabilir (' . $code . ').';
        } else {
            $steps[] = 'DeleteCustomer: test cari silindi.';
        }

        $dealer = db()->query("SELECT id, username, display_name, bayt_customer_id, bayt_customer_code FROM users WHERE username='bayi1' LIMIT 1")->fetch();
        if ($dealer) {
            $mapped = (int) ($dealer['bayt_customer_id'] ?? 0);
            $dcode = trim((string) ($dealer['bayt_customer_code'] ?? ''));
            if ($dcode === '') {
                $dcode = ($s['customer_code_prefix'] ?? 'HFS') . (int) $dealer['id'];
            }
            $steps[] = 'bayi1 eşleme: id=' . $mapped . ' kod=' . $dcode;
            if ($mapped > 0) {
                $steps[] = 'bayi1 zaten Bayt carisine bağlı; yeniden oluşturulmaz.';
            } else {
                $got = self::api('PartnerApiCustomerOperations/GetCustomerByCode', ['Code' => $dcode]);
                $gid = (!is_string($got) && !empty($got['Success'])) ? (int) ($got['Data']['Id'] ?? 0) : 0;
                if ($gid > 0) {
                    self::saveCustomer((int) $dealer['id'], $gid, $dcode);
                    $steps[] = 'bayi1 kodu Bayt’ta vardı, siteye işlendi id=' . $gid;
                } else {
                    $real = self::customerForCompany((int) $dealer['id']);
                    if (is_string($real)) {
                        $steps[] = 'bayi1 cari açma: ' . $real;
                    } else {
                        $steps[] = 'bayi1 için Bayt carisi açıldı kod=' . $real['code'] . ' id=' . $real['id'];
                    }
                }
            }
        }

        return ['ok' => true, 'message' => 'Bayt’ta olmayan cari oluşturulabiliyor.', 'steps' => $steps];
    }

    /**
     * Ana bayiyi sipariş olmadan Bayt carisine bağlar / oluşturur.
     *
     * @return array{ok:bool,skip:bool,message:string,created?:bool}
     */
    public static function pushDealer(int $userId): array
    {
        if ($userId < 1 || !self::enabled()) {
            return ['ok' => false, 'skip' => true, 'message' => ''];
        }
        if ((self::settings()['auto_create_customer'] ?? '1') !== '1') {
            return ['ok' => false, 'skip' => true, 'message' => ''];
        }
        $u = HfsDealers::get($userId);
        if (!$u || ($u['role'] ?? '') !== 'dealer') {
            return ['ok' => false, 'skip' => true, 'message' => ''];
        }
        if ((int) ($u['parent_user_id'] ?? 0) > 0) {
            return ['ok' => false, 'skip' => true, 'message' => ''];
        }
        if ((int) ($u['bayt_customer_id'] ?? 0) > 0) {
            self::updateDealerCustomer($u);
            return ['ok' => true, 'skip' => true, 'message' => ''];
        }
        $res = self::ensureUserCustomer($userId);
        if (is_string($res)) {
            self::log(0, false, 'Bayi cari: ' . $res);
            return ['ok' => false, 'skip' => false, 'message' => $res];
        }
        self::log(0, true, 'Bayi cari açıldı ' . $res['code'] . ' id=' . $res['id']);
        return ['ok' => true, 'skip' => false, 'created' => true, 'message' => 'Bayt carisi açıldı (' . $res['code'] . ').'];
    }

    /**
     * Bayt ID’si olmayan ana bayileri carileştirir.
     *
     * @return array{ok:bool,message:string,steps:list<string>}
     */
    public static function pushPendingDealers(): array
    {
        $steps = [];
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'Bayt kapalı. Muhasebe ayarlarından açın.', 'steps' => ['Bayt kapalı']];
        }
        if ((self::settings()['auto_create_customer'] ?? '1') !== '1') {
            return ['ok' => false, 'message' => 'Cari oluşturma kapalı.', 'steps' => ['Cari yoksa oluştur işareti yok']];
        }
        $rows = db()->query(
            "SELECT id, username, display_name, company FROM users
             WHERE role='dealer' AND (parent_user_id IS NULL OR parent_user_id=0)
               AND (bayt_customer_id IS NULL OR bayt_customer_id=0)
             ORDER BY id ASC LIMIT 80"
        )->fetchAll();
        if ($rows === []) {
            return ['ok' => true, 'message' => 'Gönderilmemiş ana bayi yok.', 'steps' => ['Tüm ana bayilerin Bayt cari ID’si dolu.']];
        }
        $okN = 0;
        $failN = 0;
        foreach ($rows as $row) {
            $label = trim((string) ($row['company'] ?: $row['display_name'] ?: $row['username']));
            $res = self::pushDealer((int) $row['id']);
            if (!empty($res['ok']) && !empty($res['created'])) {
                $okN++;
                $steps[] = $label . ': ' . $res['message'];
            } elseif (!empty($res['skip'])) {
                $steps[] = $label . ': atlandı';
            } else {
                $failN++;
                $steps[] = $label . ': ' . ($res['message'] !== '' ? $res['message'] : 'hata');
            }
        }
        $msg = $okN . ' cari açıldı';
        if ($failN > 0) {
            $msg .= ', ' . $failN . ' hata';
        }
        return ['ok' => $failN === 0, 'message' => $msg . '.', 'steps' => $steps];
    }

    public static function onCompleted(array $order): void
    {
        self::onConfirmed($order);
    }

    public static function onConfirmed(array $order): void
    {
        if (!self::enabled()) {
            return;
        }
        if (!self::isReadyToSend($order)) {
            return;
        }
        $paidCard = ($order['payment'] ?? '') === 'bulut' && ($order['payment_status'] ?? '') === 'odendi';
        if (self::settings()['auto_waybill'] === '1' || $paidCard) {
            self::ensureWaybillPipeline((int) $order['id'], false);
        } else {
            self::sendOrderOnly((int) $order['id'], false);
        }
    }

    /** Satış siparişi varsa irsaliye + e-İrsaliye hazırlığı; yoksa tam send. */
    public static function ensureWaybillPipeline(int $orderId, bool $force = false): true|string
    {
        $order = self::loadOrder($orderId);
        if (!$order) {
            return 'Sipariş yok';
        }
        if ((int) ($order['bayt_order_id'] ?? 0) < 1) {
            $r = self::sendOrderOnly($orderId, $force);
            if ($r !== true) {
                return $r;
            }
            $order = self::loadOrder($orderId) ?: $order;
        }
        $hasWaybill = (int) ($order['bayt_id'] ?? 0) > 0 && (string) ($order['bayt_status'] ?? '') === 'sent';
        if ($hasWaybill && !$force) {
            self::repairEdiStageForOrder($orderId);
            return true;
        }
        return self::sendWaybillOnly($orderId, $force);
    }

    /** @param array<string,mixed> $order */
    public static function isReadyToSend(array $order): bool
    {
        if ((int) ($order['is_cari'] ?? 0) === 1) {
            return false;
        }
        $st = (string) ($order['status'] ?? '');
        if (in_array($st, ['iptal', 'odeme', 'pending_b2b'], true)) {
            return false;
        }
        if (!in_array($st, ['yeni', 'bekliyor', 'hazirlaniyor', 'kargoda', 'tamam'], true)) {
            return false;
        }
        if (($order['payment'] ?? '') === 'bulut' && ($order['payment_status'] ?? '') !== 'odendi') {
            return false;
        }
        $bs = (string) ($order['bayt_status'] ?? '');
        if ($bs === 'deleted') {
            return false;
        }
        if ($bs === 'sent' && (string) ($order['bayt_doc_type'] ?? '') === 'waybill' && (int) ($order['bayt_order_id'] ?? 0) > 0) {
            return false;
        }
        return true;
    }

    public static function pushPendingOrders(int $limit = 20): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (self::pendingCompleted() as $row) {
            if ($n >= $limit) {
                break;
            }
            if (!self::isReadyToSend($row)) {
                continue;
            }
            $r = self::send((int) $row['id'], false);
            if ($r === true) {
                $n++;
            }
        }
        return $n;
    }

    /** Cari tahsilatı Bayt CreateCollection ile gönderir (Bulut kart → ctCreditCard). */
    public static function sendCollection(int $ledgerId, bool $force = false): true|string
    {
        if (!self::enabled()) {
            return $force ? 'Bayt kapalı. Muhasebe ayarlarından açın.' : true;
        }
        $st = db()->prepare('SELECT * FROM ledger WHERE id=? AND kind=? LIMIT 1');
        $st->execute([$ledgerId, 'cari_odeme']);
        $row = $st->fetch();
        if (!$row) {
            return 'Tahsilat kaydı bulunamadı.';
        }
        if (!$force && ($row['bayt_status'] ?? '') === 'sent' && (int) ($row['bayt_id'] ?? 0) > 0) {
            return true;
        }
        if (($row['status'] ?? 'ok') === 'iptal') {
            return 'Tahsilat iptal edilmiş.';
        }
        $amount = round((float) ($row['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return self::failCollection($row, 'Tutar geçersiz.');
        }
        $ref = trim((string) ($row['bayt_ref'] ?? ''));
        if ($ref === '') {
            $ref = self::collectionRefCari((int) $row['id']);
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return self::failCollection($row, $auth);
        }
        $customer = self::customerForCompany((int) $row['company_id']);
        if (is_string($customer)) {
            return self::failCollection($row, $customer);
        }
        $cid = (int) $customer['id'];
        $existing = self::findCollectionVerified($ref, $cid, $amount);
        if ($existing > 0) {
            db()->prepare("UPDATE ledger SET bayt_status='sent', bayt_ref=?, bayt_id=?, bayt_sent_at=NOW(), bayt_error=NULL WHERE id=?")
                ->execute([$ref, $existing, (int) $row['id']]);
            self::log((int) ($row['order_id'] ?? 0), true, 'Tahsilat #' . (int) $row['id'] . ' Bayt’ta zaten vardı (' . $ref . ' #' . $existing . ').');
            return true;
        }
        $note = (string) ($row['note'] ?? '');
        if (!str_contains(mb_strtolower($note), 'bulut')) {
            $note = trim($note . ' BulutTahsilat kart');
        }
        $type = self::collectionType($note);
        $receipt = self::collectionReceiptDate((string) ($row['created_at'] ?? ''));
        $forcedBank = null;
        if ($type === 'ctCreditCard') {
            $posBank = '';
            $merchant = '';
            $oid = (int) ($row['order_id'] ?? 0);
            if ($oid > 0) {
                $ost = db()->prepare('SELECT bt_pos_bank, bt_merchant_id FROM orders WHERE id=? LIMIT 1');
                $ost->execute([$oid]);
                $ord = $ost->fetch() ?: null;
                if ($ord) {
                    $posBank = (string) ($ord['bt_pos_bank'] ?? '');
                    $merchant = (string) ($ord['bt_merchant_id'] ?? '');
                }
            }
            $resolved = self::resolveCreditCardBankId($posBank, $merchant);
            if (is_string($resolved)) {
                // POS henüz yazılmadıysa kalıcı hata değil — sync tekrar dener.
                if (trim($posBank) === '' || trim($merchant) === '') {
                    db()->prepare("UPDATE ledger SET bayt_status='pending', bayt_error=? WHERE id=?")
                        ->execute([$resolved, (int) $row['id']]);
                    self::log((int) ($row['order_id'] ?? 0), false, 'Tahsilat #' . (int) $row['id'] . ': ' . $resolved);
                    return $resolved;
                }
                return self::failCollection($row, $resolved);
            }
            $forcedBank = $resolved;
        }
        $payload = self::buildCollectionPayload($cid, $amount, $ref, $receipt, $type, $forcedBank);
        if ($payload === null) {
            return self::failCollection($row, 'Tahsilat payload oluşturulamadı (banka/kasa ID).');
        }
        $res = self::api('PartnerApiCollectionOperations/CreateCollection', $payload);
        if (is_string($res)) {
            return self::failCollection($row, $res);
        }
        if (empty($res['Success'])) {
            $again = self::findCollectionVerified($ref, $cid, $amount);
            if ($again > 0) {
                db()->prepare("UPDATE ledger SET bayt_status='sent', bayt_ref=?, bayt_id=?, bayt_sent_at=NOW(), bayt_error=NULL WHERE id=?")
                    ->execute([$ref, $again, (int) $row['id']]);
                return true;
            }
            $msg = self::apiMessage($res, 'Tahsilat muhasebeye işlenemedi.');
            $msgU = mb_strtolower($msg, 'UTF-8');
            $dup = str_contains($msgU, 'aynı fiş') || str_contains($msgU, 'zaten var') || str_contains($msgU, 'already');
            if ($dup) {
                $dupId = (int) ($res['Data']['Id'] ?? $res['Id'] ?? 0);
                if ($dupId < 1) {
                    $dupId = (int) ($row['bayt_id'] ?? 0);
                }
                if ($dupId < 1) {
                    $dupId = self::findCollectionId($ref);
                }
                if ($dupId > 0) {
                    db()->prepare("UPDATE ledger SET bayt_status='sent', bayt_ref=?, bayt_id=?, bayt_sent_at=NOW(), bayt_error=NULL WHERE id=?")
                        ->execute([$ref, $dupId, (int) $row['id']]);
                    self::log((int) ($row['order_id'] ?? 0), true, 'Tahsilat #' . (int) $row['id'] . ' Bayt’ta zaten vardı (' . $ref . ' #' . $dupId . ').');
                    return true;
                }
            }
            return self::failCollection($row, $msg);
        }
        $newId = (int) ($res['Data']['Id'] ?? $res['Id'] ?? 0);
        $verified = $newId > 0 ? $newId : self::findCollectionVerified($ref, $cid, $amount);
        if ($verified < 1) {
            db()->prepare("UPDATE ledger SET bayt_status='reconcile', bayt_ref=?, bayt_error=? WHERE id=?")
                ->execute([$ref, 'CreateCollection sonrası GetCollectionList ile doğrulanamadı.', (int) $row['id']]);
            return self::failCollection($row, 'Bayt tahsilatı doğrulanamadı; tekrar CREATE yapmayın, muhasebe kontrol edin.');
        }
        db()->prepare("UPDATE ledger SET bayt_status='sent', bayt_ref=?, bayt_id=?, bayt_sent_at=NOW(), bayt_error=NULL WHERE id=?")
            ->execute([$ref, $verified, (int) $row['id']]);
        self::log((int) ($row['order_id'] ?? 0), true, 'Tahsilat #' . (int) $row['id'] . ' gönderildi (' . $ref . ' #' . $verified . ').');
        return true;
    }

    /** Bulut onaylı sipariş kart ödemesi → Bayt CreateCollection (instant checkout). */
    public static function postOrderBulutCollection(int $orderId, bool $force = false): true|string
    {
        if (!self::enabled()) {
            return $force ? 'Bayt kapalı.' : true;
        }
        $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            return 'Sipariş yok.';
        }
        if ((int) ($order['is_cari'] ?? 0) === 1) {
            return true;
        }
        if (($order['payment'] ?? '') !== 'bulut' || ($order['payment_status'] ?? '') !== 'odendi') {
            return 'Kart ödemesi onaylı değil.';
        }
        $stCol = (string) ($order['bayt_collection_status'] ?? '');
        if (!$force && $stCol === 'sent' && (int) ($order['bayt_collection_id'] ?? 0) > 0) {
            return true;
        }
        $amount = round((float) ($order['total'] ?? 0), 2);
        if ($amount <= 0) {
            return 'Tutar geçersiz.';
        }
        $ref = trim((string) ($order['bayt_collection_ref'] ?? ''));
        if ($ref === '') {
            $ref = self::collectionRefOrder((string) ($order['code'] ?? ''));
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return $auth;
        }
        $uid = (int) ($order['company_id'] ?? 0);
        if ($uid < 1) {
            $uid = (int) ($order['user_id'] ?? 0);
        }
        $customer = self::customerForCompany($uid);
        if (is_string($customer)) {
            self::markOrderCollection($orderId, $ref, 0, 'error', $customer);
            return $customer;
        }
        $cid = (int) $customer['id'];
        $existing = self::findCollectionVerified($ref, $cid, $amount);
        if ($existing > 0) {
            self::markOrderCollection($orderId, $ref, $existing, 'sent', '');
            self::log($orderId, true, 'Kart tahsilat Bayt’ta eşleşti ' . $ref . ' #' . $existing);
            return true;
        }
        $receipt = self::collectionReceiptDate((string) ($order['bt_date'] ?? $order['created_at'] ?? ''));
        $resolved = self::resolveCreditCardBankId(
            (string) ($order['bt_pos_bank'] ?? ''),
            (string) ($order['bt_merchant_id'] ?? '')
        );
        if (is_string($resolved)) {
            self::markOrderCollection($orderId, $ref, 0, 'error', $resolved);
            return $resolved;
        }
        $payload = self::buildCollectionPayload($cid, $amount, $ref, $receipt, 'ctCreditCard', $resolved);
        if ($payload === null) {
            self::markOrderCollection($orderId, $ref, 0, 'error', 'Banka ID tanımlı değil (Muhasebe → Bayt).');
            return 'Banka ID tanımlı değil.';
        }
        $res = self::api('PartnerApiCollectionOperations/CreateCollection', $payload);
        if (is_string($res)) {
            self::markOrderCollection($orderId, $ref, 0, 'error', $res);
            return $res;
        }
        if (empty($res['Success'])) {
            $again = self::findCollectionVerified($ref, $cid, $amount);
            if ($again > 0) {
                self::markOrderCollection($orderId, $ref, $again, 'sent', '');
                return true;
            }
            $msg = self::apiMessage($res, 'CreateCollection başarısız.');
            self::markOrderCollection($orderId, $ref, 0, 'error', $msg);
            return $msg;
        }
        $newId = (int) ($res['Data']['Id'] ?? $res['Id'] ?? 0);
        $verified = $newId > 0 ? $newId : self::findCollectionVerified($ref, $cid, $amount);
        if ($verified < 1) {
            self::markOrderCollection($orderId, $ref, 0, 'reconcile', 'Doğrulanamadı');
            return 'Bayt tahsilat doğrulanamadı.';
        }
        self::markOrderCollection($orderId, $ref, $verified, 'sent', '');
        self::log($orderId, true, 'Bulut kart tahsilat Bayt #' . $verified . ' (' . $ref . ', BankId=' . $resolved . ').');
        return true;
    }

    public static function collectionRefCari(int $ledgerId): string
    {
        return 'CP' . str_pad((string) max(1, $ledgerId), 8, '0', STR_PAD_LEFT) . 'O';
    }

    public static function collectionRefOrder(string $orderCode): string
    {
        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($orderCode))) ?? '');
        if ($slug === '') {
            $slug = 'HFS';
        }
        return 'IP' . substr(str_pad($slug, 8, '0'), 0, 8) . 'O';
    }

    private static function collectionReceiptDate(string $raw): string
    {
        $ts = strtotime($raw) ?: time();
        return date('Y-m-d', $ts) . 'T00:00:00.000';
    }

    /**
     * Bulut POS → Bayt banka hesabı (ZIRAATPAY→12, KUVEYTTÜRK→9).
     * Bilinmeyen / çelişen POS+merchant için otomatik varsayılan yok.
     *
     * @return int|string banka ID veya hata metni
     */
    public static function resolveCreditCardBankId(string $posBank, string $merchantId): int|string
    {
        $s = self::settings();
        $pos = self::normalizePosBankKey($posBank);
        $merchant = trim($merchantId);
        $ziraatMerchant = trim((string) ($s['pos_merchant_ziraat'] ?? '00922'));
        $kuveytMerchant = trim((string) ($s['pos_merchant_kuveyt'] ?? '00205'));
        $ziraatId = (int) ($s['bank_id_ziraat'] ?? 12);
        $kuveytId = (int) ($s['bank_id_kuveyt'] ?? 9);
        if ($pos === 'ziraat' && $merchant !== '' && $merchant === $ziraatMerchant && $ziraatId > 0) {
            return $ziraatId;
        }
        if ($pos === 'kuveyt' && $merchant !== '' && $merchant === $kuveytMerchant && $kuveytId > 0) {
            return $kuveytId;
        }
        return 'POS/banka eşlemesi belirsiz (pos=' . trim($posBank) . ', merchant=' . $merchant
            . '). Manuel kontrol; BankId=5/varsayılan kullanılmaz.';
    }

    private static function normalizePosBankKey(string $posBank): string
    {
        $p = mb_strtoupper(trim($posBank), 'UTF-8');
        $p = str_replace(['İ', 'İ', 'ı'], ['I', 'I', 'I'], $p);
        $p = preg_replace('/[^A-Z0-9]/', '', $p) ?? '';
        if ($p !== '' && str_contains($p, 'ZIRAAT')) {
            return 'ziraat';
        }
        if ($p !== '' && str_contains($p, 'KUVEYT')) {
            return 'kuveyt';
        }
        return '';
    }

    /** @return array<string,mixed>|null */
    private static function buildCollectionPayload(
        int $customerId,
        float $amount,
        string $ref,
        string $receiptIso,
        string $collectionType,
        ?int $forcedBankId = null
    ): ?array {
        $s = self::settings();
        $type = $collectionType;
        $cashId = (int) ($s['cash_id'] ?? 1);
        $bankId = $forcedBankId !== null ? $forcedBankId : (int) ($s['bank_id'] ?? -1);
        if ($type === 'ctCreditCard') {
            if ($bankId < 1) {
                return null;
            }
        } elseif ($type !== 'ctCash' && $bankId < 1) {
            $type = 'ctCash';
        }
        if ($type === 'ctCash' && $cashId < 1) {
            return null;
        }
        return [
            'ReferenceNo' => $ref,
            'ReceiptDate' => $receiptIso,
            'CustomerId' => $customerId,
            'CurrencyId' => (int) $s['currency_id'],
            'LocalExchangeRate' => 1,
            'ReportExchangeRate' => 1,
            'Items' => [[
                'CashId' => $type === 'ctCash' ? $cashId : -1,
                'BankId' => $type === 'ctCash' ? -1 : $bankId,
                'Amount' => round($amount, 2),
                'CurrencyId' => (int) $s['currency_id'],
                'CollectionType' => $type,
                'LocalExchangeRate' => 1,
            ]],
        ];
    }

    private static function findCollectionVerified(string $ref, int $customerId, float $amount): int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return 0;
        }
        $res = self::api('PartnerApiCollectionOperations/GetCollectionList', ['SkipCount' => 0, 'TakeCount' => 120], 90);
        $items = is_array($res) ? self::listRows($res) : [];
        $want = round($amount, 2);
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $r = trim((string) ($row['ReferenceNo'] ?? $row['RefNo'] ?? ''));
            if (strcasecmp($r, $ref) !== 0) {
                continue;
            }
            $cust = (int) ($row['CustomerId'] ?? 0);
            if ($customerId > 0 && $cust > 0 && $cust !== $customerId) {
                continue;
            }
            $amt = round((float) ($row['Amount'] ?? $row['Total'] ?? 0), 2);
            if (abs($amt - $want) > 0.02 && $amt > 0) {
                continue;
            }
            return (int) ($row['Id'] ?? 0);
        }
        return self::findCollectionId($ref);
    }

    private static function markOrderCollection(int $orderId, string $ref, int $baytId, string $status, string $error): void
    {
        try {
            db()->prepare('UPDATE orders SET bayt_collection_ref=?, bayt_collection_id=?, bayt_collection_status=? WHERE id=?')
                ->execute([$ref, max(0, $baytId), $status, $orderId]);
            if ($error !== '') {
                self::log($orderId, false, 'Kart tahsilat Bayt: ' . $error);
            }
        } catch (Throwable) {
        }
    }

    private static function collectionType(string $note): string
    {
        $n = mb_strtolower($note);
        if (str_contains($n, 'bulut') || str_contains($n, 'kart') || str_contains($n, 'kredi')) {
            return 'ctCreditCard';
        }
        if (str_contains($n, 'havale') || str_contains($n, 'eft') || str_contains($n, 'wire')) {
            return 'ctWireTransfer';
        }
        return 'ctCash';
    }

    /** @return array{id:int,code:string}|string */
    private static function customerForCompany(int $companyId): array|string
    {
        if ($companyId < 1) {
            return 'Cari firma yok.';
        }
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$companyId]);
        $u = $st->fetch();
        if (!$u) {
            return 'Bayi bulunamadı.';
        }
        $name = trim((string) ($u['display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($u['company'] ?? ''));
        }
        if ($name === '') {
            $name = (string) ($u['username'] ?? '');
        }
        return self::resolveCustomer([
            'id' => 0,
            'company_id' => $companyId,
            'user_id' => $companyId,
            'name' => $name,
            'email' => (string) ($u['email'] ?? ''),
            'phone' => (string) ($u['phone'] ?? ''),
            'city' => (string) ($u['city'] ?? ''),
            'address' => (string) ($u['address'] ?? ''),
            'district' => (string) ($u['district'] ?? ''),
            'postcode' => (string) ($u['postcode'] ?? ''),
            'country' => (string) ($u['country'] ?? 'TR'),
        ]);
    }

    /** @param array<string,mixed> $row */
    private static function failCollection(array $row, string $message): string
    {
        db()->prepare("UPDATE ledger SET bayt_status='error', bayt_error=? WHERE id=?")
            ->execute([$message, (int) $row['id']]);
        self::log((int) ($row['order_id'] ?? 0), false, 'Tahsilat #' . (int) $row['id'] . ': ' . $message);
        return $message;
    }

    /** @return list<array<string,mixed>> */
    public static function pendingCollections(): array
    {
        return db()->query("SELECT l.*, u.display_name, u.username
            FROM ledger l
            LEFT JOIN users u ON u.id=l.company_id
            WHERE l.kind='cari_odeme' AND IFNULL(l.bayt_status,'')<>'sent' AND IFNULL(l.status,'ok')<>'iptal'
            ORDER BY l.id DESC LIMIT 30")->fetchAll();
    }

    /** Gönderilmemiş / hata / pending cari tahsilatlarını Bayt’a tekrar dener. */
    public static function pushPendingCollections(int $limit = 20): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $lim = max(1, min(40, $limit));
        $rows = db()->query(
            "SELECT id FROM ledger
             WHERE kind='cari_odeme' AND IFNULL(status,'ok')<>'iptal'
               AND IFNULL(bayt_status,'') NOT IN ('sent','deleted')
             ORDER BY id ASC LIMIT {$lim}"
        )->fetchAll();
        $n = 0;
        foreach ($rows as $r) {
            $res = self::sendCollection((int) $r['id'], false);
            if ($res === true) {
                $n++;
            }
        }
        return $n;
    }

    /** @return array<string,mixed>|null */
    private static function loadOrder(int $orderId): ?array
    {
        $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            return null;
        }
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([$orderId]);
        $order['items'] = $it->fetchAll();
        return $order;
    }

    /** @return true|string error */
    public static function sendOrderOnly(int $orderId, bool $force = false): true|string
    {
        bmn_session_release();
        $order = self::loadOrder($orderId);
        if (!$order) {
            return 'Sipariş bulunamadı.';
        }
        if (($order['status'] ?? '') === 'iptal') {
            return 'İptal sipariş muhasebeye gönderilmez.';
        }
        if (($order['bayt_status'] ?? '') === 'deleted' && !$force) {
            return true;
        }
        if ((int) ($order['bayt_order_id'] ?? 0) > 0) {
            self::setPhase($orderId, 'order_sent');
            return true;
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return self::fail($order, $auth);
        }
        $ref = self::orderRef((string) $order['code']);
        $sales = self::sendSalesOrder($order, $ref);
        if ($sales !== true) {
            return self::fail($order, is_string($sales) ? $sales : 'Satış siparişi gönderilemedi.');
        }
        self::setPhase($orderId, 'order_sent');
        return true;
    }

    /** @return true|string error */
    public static function sendWaybillOnly(int $orderId, bool $force = false): true|string
    {
        bmn_session_release();
        $order = self::loadOrder($orderId);
        if (!$order) {
            return 'Sipariş bulunamadı.';
        }
        if (($order['status'] ?? '') === 'iptal') {
            return 'İptal sipariş muhasebeye gönderilmez.';
        }
        if ((int) ($order['bayt_order_id'] ?? 0) < 1) {
            return 'Önce Bayt satış siparişi oluşturulmalı.';
        }
        $hasWaybill = ($order['bayt_status'] ?? '') === 'sent'
            && (int) ($order['bayt_id'] ?? 0) > 0;
        if ($hasWaybill && !$force) {
            return true;
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return self::fail($order, $auth);
        }
        $ref = self::orderRef((string) $order['code']);
        $lockKey = 'hfs_bayt_wb_' . $orderId;
        $locked = (int) db()->query('SELECT GET_LOCK(' . db()->quote($lockKey) . ', 30)')->fetchColumn();
        if ($locked !== 1) {
            return 'İrsaliye işlemi devam ediyor; tekrar deneyin.';
        }
        try {
            $order = self::loadOrder($orderId) ?: $order;
            $existing = self::findExistingWaybillForOrder($order, $ref);
            if (is_array($existing) && !empty($existing['id'])) {
                $wid = (int) $existing['id'];
                $docRef = trim((string) ($existing['ref'] ?? ''));
                if ($docRef === '') {
                    $docRef = trim((string) ($order['bayt_ref'] ?? ''));
                }
                if ($docRef === '') {
                    $docRef = $ref;
                }
                self::markSent($order, $docRef, $wid, 'Bayt: mevcut irsaliye eşleşti (mükerrer oluşturulmadı).');
                self::setPhase($orderId, 'waybill_created');
                self::afterWaybillCreated($orderId, $order, $wid);
                return true;
            }
            $payload = self::buildPayload($order, $ref, 'waybill');
            if (is_string($payload)) {
                return self::fail($order, $payload);
            }
            $res = self::api('PartnerApiWaybillOperations/CreateWaybill', $payload);
            if (is_string($res)) {
                return self::fail($order, $res);
            }
            if (empty($res['Success'])) {
                return self::fail($order, self::apiMessage($res, 'İrsaliye oluşturulamadı.'));
            }
            $newId = (int) ($res['Data']['Id'] ?? $res['Id'] ?? 0);
            $baytRef = self::resolveWaybillDocumentRef($res, $newId, $ref);
            self::markSent($order, $baytRef, $newId, 'Bayt: satış irsaliyesi oluşturuldu (gönderilecek irsaliye; e-İrsaliye/GİB ayrı).');
            self::setPhase($orderId, 'waybill_created');
            self::afterWaybillCreated($orderId, $order, $newId);
            return true;
        } finally {
            try {
                db()->query('SELECT RELEASE_LOCK(' . db()->quote($lockKey) . ')');
            } catch (Throwable) {
            }
        }
    }

    /** İrsaliye sonrası STK_FIS: EIRSALIYE=1 + fiili sevk (BYTE e-İrsaliye listesi koşulu). */
    public static function stageWaybillForEdi(int $waybillId, ?DateTimeInterface $dispatchAt = null, string $postcode = ''): array
    {
        $out = ['ok' => false, 'list_candidate' => false, 'before' => [], 'after' => [], 'steps' => []];
        $wid = (int) $waybillId;
        if ($wid < 1) {
            $out['steps'][] = 'İrsaliye ID yok';
            return $out;
        }
        if (self::settings()['bayt_edi_stage'] !== '1') {
            $out['steps'][] = 'bayt_edi_stage kapalı';
            return $out;
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            $out['steps'][] = $auth;
            return $out;
        }
        $out['before'] = self::readStkFisRow($wid);
        if ($out['before'] === []) {
            $out['steps'][] = 'STK_FIS okunamadı';
            return $out;
        }
        $tz = new DateTimeZone('Europe/Istanbul');
        [$sevkTar, $sevkSaat] = self::computeFiiliSevkFields($wid, $dispatchAt, $tz, $out['before']);
        $eBefore = (string) ($out['before']['EIRSALIYE'] ?? '0');
        $needEirs = !in_array($eBefore, ['1', '2'], true);
        $curTar = trim((string) ($out['before']['SEVKTAR'] ?? ''));
        $curSaat = trim((string) ($out['before']['SEVKSAAT'] ?? ''));
        $cmpTar = trim((string) ($out['before']['FISTAR'] ?? ''));
        if ($cmpTar === '') {
            $cmpTar = $sevkTar;
        }
        if (!$needEirs && self::sevkFieldsMatch($curTar, $curSaat, $cmpTar, $sevkSaat)) {
            $out['after'] = $out['before'];
            $out['list_candidate'] = self::isEdiListCandidate($out['after']);
            $out['ok'] = true;
            $out['steps'][] = 'Fiili sevk ve EIRSALIYE zaten doğru';
            return $out;
        }
        $pc = preg_replace('/\D+/', '', $postcode) ?: '';
        $sets = [];
        if ($needEirs) {
            $sets[] = 'EIRSALIYE=1';
        }
        $sets[] = "SEVKTAR='" . str_replace("'", "''", $sevkTar) . "'";
        $sets[] = "SEVKSAAT='" . str_replace("'", "''", $sevkSaat) . "'";
        if (preg_match('/^[0-9]{5}$/', $pc)) {
            $sets[] = "POSTAKODU='{$pc}'";
        }
        $sql = 'UPDATE STK_FIS SET ' . implode(', ', $sets) . " WHERE ID={$wid} AND IPTAL=0; COMMIT;";
        $write = self::api('PartnerApiHelperOperations/ExecuteQuery', ['SqlScript' => $sql], 45);
        $apiMsg = is_array($write) ? self::apiMessage($write, '') : (string) $write;
        $out['steps'][] = 'UPDATE: ' . ($apiMsg !== '' ? $apiMsg : 'çağrıldı (yanıt Success=false olsa bile okuma esas)');
        $out['after'] = self::readStkFisRow($wid);
        $e = (string) ($out['after']['EIRSALIYE'] ?? '');
        $out['ok'] = $e === '1' || $e === '2';
        $out['list_candidate'] = self::isEdiListCandidate($out['after']);
        if ($out['ok']) {
            $out['steps'][] = 'Doğrulama: EIRSALIYE=' . $e . ', SEVKSAAT=' . (string) ($out['after']['SEVKSAAT'] ?? '');
        } else {
            $out['steps'][] = 'Doğrulama başarısız; BYTE’de manuel senaryo/sevk gerekebilir.';
        }
        return $out;
    }

    /** @param array<string,mixed> $order */
    private static function afterWaybillCreated(int $orderId, array $order, int $waybillId): void
    {
        if ($waybillId < 1 || self::settings()['bayt_edi_stage'] !== '1') {
            return;
        }
        $dispatch = null;
        $raw = (string) ($order['bayt_dispatch_at'] ?? '');
        if ($raw !== '' && !str_starts_with($raw, '0001')) {
            try {
                $dispatch = new DateTimeImmutable($raw, new DateTimeZone('Europe/Istanbul'));
            } catch (Throwable) {
            }
        }
        $contact = self::orderContactForPayload($order);
        $pc = (string) ($contact['postcode_raw'] ?? '');
        $stage = self::stageWaybillForEdi($waybillId, $dispatch, $pc);
        if ($stage['ok'] && !empty($stage['list_candidate'])) {
            self::setPhase($orderId, 'edi_ready');
            self::log($orderId, true, 'e-İrsaliye listesi adayı: STK_FIS #' . $waybillId . ' EIRSALIYE=1.');
        } elseif ($stage['ok']) {
            self::setPhase($orderId, 'edi_staged');
            self::log($orderId, true, 'STK_FIS #' . $waybillId . ' e-İrsaliye alanları güncellendi; liste SQL kontrol edin.');
        } else {
            self::setPhase($orderId, 'edi_manual_required');
            self::log($orderId, false, 'e-İrsaliye hazırlık: ' . implode(' | ', $stage['steps']));
        }
    }

    /**
     * Fiili sevk: irsaliye tarihi ile aynı gün (j.n.Y), saat varsayılan irsaliye +1 saat.
     *
     * @return array{0:string,1:string} [SEVKTAR, SEVKSAAT]
     */
    /** @param array<string,string> $stkRow @return array{0:string,1:string} ISO SQL [SEVKTAR, SEVKSAAT] */
    private static function computeFiiliSevkFields(int $waybillId, ?DateTimeInterface $dispatchOverride, DateTimeZone $tz, array $stkRow = []): array
    {
        $receipt = self::waybillReceiptAt($waybillId, $tz);
        if ($receipt === null) {
            $receipt = new DateTimeImmutable('now', $tz);
        }
        $isoDay = $receipt->format('Y-m-d');
        if ($dispatchOverride instanceof DateTimeInterface) {
            $ov = DateTimeImmutable::createFromInterface($dispatchOverride)->setTimezone($tz);
            return [$ov->format('Y-m-d'), $ov->format('Y-m-d H:i:s')];
        }
        $plus1 = $receipt->modify('+1 hour');
        return [$isoDay, $isoDay . ' ' . $plus1->format('H:i:s')];
    }

    private static function sevkFieldsMatch(string $curTar, string $curSaat, string $expectTar, string $expectSaatIso): bool
    {
        $curTar = trim($curTar);
        $curSaat = trim($curSaat);
        if ($curTar === '' || $curSaat === '') {
            return false;
        }
        if ($curTar !== $expectTar && !self::sameCalendarDayTr($curTar, $expectSaatIso)) {
            return false;
        }
        try {
            $want = new DateTimeImmutable($expectSaatIso, new DateTimeZone('Europe/Istanbul'));
            $have = new DateTimeImmutable(self::normalizeBaytSevkSaat($curSaat), new DateTimeZone('Europe/Istanbul'));
            return abs($want->getTimestamp() - $have->getTimestamp()) < 2;
        } catch (Throwable) {
            return $curSaat === $expectSaatIso;
        }
    }

    private static function normalizeBaytSevkSaat(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})\s+(\d{1,2}:\d{2}(?::\d{2})?)$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d %s:00', (int) $m[3], (int) $m[2], (int) $m[1], $m[4]);
        }
        return $raw;
    }

    private static function sameCalendarDayTr(string $curTar, string $expectSaatIso): bool
    {
        try {
            $want = new DateTimeImmutable(substr($expectSaatIso, 0, 10), new DateTimeZone('Europe/Istanbul'));
            $have = new DateTimeImmutable(self::normalizeBaytSevkSaat($curTar . ' 12:00:00'), new DateTimeZone('Europe/Istanbul'));
            return $want->format('Y-m-d') === $have->format('Y-m-d');
        } catch (Throwable) {
            return false;
        }
    }

    private static function waybillReceiptAt(int $waybillId, DateTimeZone $tz): ?DateTimeImmutable
    {
        $res = self::api('PartnerApiWaybillOperations/GetWaybillById', ['Id' => $waybillId, 'WaybillType' => 1], 30);
        if (!is_array($res) || empty($res['Success'])) {
            return null;
        }
        $data = is_array($res['Data'] ?? null) ? $res['Data'] : $res;
        foreach (['ReceiptTime', 'ReceiptDate'] as $key) {
            $raw = trim((string) ($data[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $norm = preg_replace('/\.\d{3}$/', '', str_replace('T', ' ', $raw)) ?? $raw;
            $norm = substr($norm, 0, 19);
            try {
                $dt = new DateTimeImmutable($norm, $tz);
                if ($key === 'ReceiptTime' || !str_contains($norm, '00:00:00')) {
                    return $dt;
                }
            } catch (Throwable) {
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private static function readStkFisRow(int $waybillId): array
    {
        $wid = (int) $waybillId;
        $res = self::api('PartnerApiHelperOperations/ExecuteQuery', [
            'SqlScript' => "SELECT ID, FISNO, FISTAR, BOLUMID, TIP, EIRSALIYE, EIRSALIYE_ID, IPTAL, SEVKTAR, SEVKSAAT, POSTAKODU, EIRSALIYE_UUID FROM STK_FIS WHERE ID={$wid};",
        ], 30);
        return self::parseExecuteQueryRow($res);
    }

    /** @return array<string,string> */
    private static function parseExecuteQueryRow(mixed $res): array
    {
        if (!is_array($res) || empty($res['Success'])) {
            return [];
        }
        $items = $res['Items'] ?? [];
        if (!is_array($items) || $items === []) {
            return [];
        }
        $block = $items[0]['Items'] ?? $items[0] ?? null;
        if (!is_array($block)) {
            return [];
        }
        $out = [];
        foreach ($block as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $k = (string) ($cell['FieldName'] ?? '');
            if ($k === '') {
                continue;
            }
            $out[$k] = trim((string) ($cell['FieldValue'] ?? ''));
        }
        return $out;
    }

    /** @param array<string,string> $row */
    private static function isEdiListCandidate(array $row): bool
    {
        if ($row === []) {
            return false;
        }
        $e = (string) ($row['EIRSALIYE'] ?? '');
        if (!in_array($e, ['1', '2'], true)) {
            return false;
        }
        $iptal = strtolower(trim((string) ($row['IPTAL'] ?? '0')));
        if (!in_array($iptal, ['0', 'false', ''], true)) {
            return false;
        }
        $eid = (string) ($row['EIRSALIYE_ID'] ?? '');
        if ($eid !== '' && $eid !== '-1') {
            return false;
        }
        $tip = (int) ($row['TIP'] ?? 0);
        if (!in_array($tip, [11, 20, 30, 41, 51], true)) {
            return false;
        }
        return true;
    }

    /** Mevcut irsaliyeye e-İrsaliye alan yaması (7273 gibi). */
    public static function repairEdiStageForOrder(int $orderId): array
    {
        $order = self::loadOrder($orderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'Sipariş yok'];
        }
        $wid = (int) ($order['bayt_id'] ?? 0);
        if ($wid < 1) {
            return ['ok' => false, 'message' => 'İrsaliye ID yok'];
        }
        $dispatch = null;
        $raw = (string) ($order['bayt_dispatch_at'] ?? '');
        if ($raw !== '') {
            try {
                $dispatch = new DateTimeImmutable($raw, new DateTimeZone('Europe/Istanbul'));
            } catch (Throwable) {
            }
        }
        $contact = self::orderContactForPayload($order);
        $stage = self::stageWaybillForEdi($wid, $dispatch, (string) ($contact['postcode_raw'] ?? ''));
        if ($stage['ok'] && !empty($stage['list_candidate'])) {
            self::setPhase($orderId, 'edi_ready');
        } elseif ($stage['ok']) {
            self::setPhase($orderId, 'edi_staged');
        } else {
            self::setPhase($orderId, 'edi_manual_required');
        }
        self::log($orderId, !empty($stage['ok']), 'e-İrsaliye yama #' . $wid . ': ' . implode(' | ', $stage['steps']));
        return array_merge(['ok' => !empty($stage['ok']), 'waybill_id' => $wid], $stage);
    }

    /** Sipariş + (ayar açıksa) irsaliye. */
    public static function send(int $orderId, bool $force = false): true|string
    {
        $r = self::sendOrderOnly($orderId, $force);
        if ($r !== true) {
            return $r;
        }
        if (self::settings()['auto_waybill'] !== '1') {
            return true;
        }
        return self::sendWaybillOnly($orderId, $force);
    }

    /**
     * Bayt API’de e-İrsaliye alanları için deneme çağrıları (üretim SQL yok).
     *
     * @return array{ok:bool,phase:string,steps:list<string>,details:list<array<string,mixed>>}
     */
    public static function probeEdiForOrder(int $orderId): array
    {
        return self::repairEdiStageForOrder($orderId);
    }

    private static function setPhase(int $orderId, string $phase): void
    {
        if ($orderId < 1 || $phase === '') {
            return;
        }
        try {
            db()->prepare('UPDATE orders SET bayt_phase=? WHERE id=?')->execute([$phase, $orderId]);
        } catch (Throwable) {
        }
    }

    public static function deleteSalesOrder(int $orderId): true|string
    {
        $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            return 'Sipariş bulunamadı.';
        }
        if (($order['bayt_status'] ?? '') === 'deleted') {
            return true;
        }
        $baytId = (int) ($order['bayt_id'] ?? 0);
        if ($baytId < 1 && ($order['bayt_status'] ?? '') !== 'sent') {
            return true;
        }
        if (!self::enabled()) {
            db()->prepare("UPDATE orders SET bayt_status='deleted' WHERE id=?")->execute([$orderId]);
            return true;
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return $auth;
        }
        if ($baytId < 1) {
            $ref = trim((string) ($order['bayt_ref'] ?? ''));
            if ($ref === '') {
                $ref = self::orderRef((string) $order['code']);
            }
            $found = self::findExisting($ref);
            $baytId = (int) ($found['id'] ?? 0);
        }
        if ($baytId > 0) {
            $res = self::api('PartnerApiWaybillOperations/DeleteWaybill', ['Id' => $baytId, 'WaybillType' => 1]);
            if (is_string($res)) {
                return self::fail($order, 'İptal: ' . $res);
            }
            if (empty($res['Success']) && !self::looksMissing(self::apiMessage($res, ''))) {
                return self::fail($order, 'İptal: ' . self::apiMessage($res, 'İrsaliye muhasebeden silinemedi.'));
            }
        }
        $oid = (int) ($order['bayt_order_id'] ?? 0);
        if ($oid < 1) {
            $foundOrd = self::findExistingOrder(self::orderRef((string) ($order['code'] ?? '')));
            $oid = (int) ($foundOrd['id'] ?? 0);
        }
        if ($oid > 0) {
            $delO = self::api('PartnerApiOrderOperations/DeleteOrder', ['Id' => $oid, 'OrderType' => 1]);
            if (is_array($delO) && empty($delO['Success']) && !self::looksMissing(self::apiMessage($delO, ''))) {
                self::log($orderId, false, 'Sipariş silinemedi: ' . self::apiMessage($delO, ''));
            }
        }
        db()->prepare("UPDATE orders SET bayt_status='deleted', bayt_error=NULL, bayt_approval='cancelled' WHERE id=?")->execute([$orderId]);
        self::log($orderId, true, 'İrsaliye muhasebeden silindi.');
        return true;
    }

    public static function deleteWaybillById(int $id): true|string
    {
        if ($id < 1) {
            return 'İrsaliye ID yok.';
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return $auth;
        }
        $res = self::api('PartnerApiWaybillOperations/DeleteWaybill', ['Id' => $id, 'WaybillType' => 1]);
        if (is_string($res)) {
            return $res;
        }
        if (empty($res['Success']) && !self::looksMissing(self::apiMessage($res, ''))) {
            return self::apiMessage($res, 'İrsaliye silinemedi.');
        }
        return true;
    }

    /** Siparişe bağlı Bulut kart tahsilatını (CreateCollection IP…) Bayt’tan siler. */
    public static function voidOrderBulutCollection(int $orderId): true|string
    {
        if ($orderId < 1) {
            return true;
        }
        $st = db()->prepare('SELECT id, code, company_id, user_id, bayt_collection_id, bayt_collection_ref, bayt_collection_status FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            return true;
        }
        $status = (string) ($order['bayt_collection_status'] ?? '');
        if ($status === 'deleted') {
            return true;
        }
        $baytId = (int) ($order['bayt_collection_id'] ?? 0);
        $ref = trim((string) ($order['bayt_collection_ref'] ?? ''));
        if ($baytId < 1 && $ref === '' && !in_array($status, ['sent', 'error'], true)) {
            return true;
        }
        if ($ref === '') {
            $ref = self::collectionRefOrder((string) ($order['code'] ?? ''));
        }
        if (!self::enabled()) {
            self::markOrderCollection($orderId, $ref, $baytId, 'deleted', '');
            return true;
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            self::markOrderCollection($orderId, $ref, $baytId, 'error', 'İptal: ' . $auth);
            return $auth;
        }
        if ($baytId < 1) {
            $baytId = self::findCollectionId($ref);
        }
        if ($baytId > 0) {
            $res = self::api('PartnerApiCollectionOperations/DeleteCollection', ['Id' => $baytId], 90);
            if (is_string($res)) {
                self::markOrderCollection($orderId, $ref, $baytId, 'error', 'İptal: ' . $res);
                return $res;
            }
            if (empty($res['Success']) && !self::looksMissing(self::apiMessage($res, ''))) {
                $msg = self::apiMessage($res, 'Tahsilat muhasebeden silinemedi.');
                self::markOrderCollection($orderId, $ref, $baytId, 'error', 'İptal: ' . $msg);
                return $msg;
            }
        }
        self::markOrderCollection($orderId, $ref, $baytId, 'deleted', '');
        self::log($orderId, true, 'Bulut kart tahsilatı muhasebeden silindi (' . $ref . ').');
        return true;
    }

    public static function deleteCollection(int $ledgerId): true|string
    {
        $st = db()->prepare('SELECT * FROM ledger WHERE id=? AND kind=? LIMIT 1');
        $st->execute([$ledgerId, 'cari_odeme']);
        $row = $st->fetch();
        if (!$row) {
            return 'Tahsilat kaydı bulunamadı.';
        }
        if (($row['bayt_status'] ?? '') === 'deleted') {
            return true;
        }
        $baytId = (int) ($row['bayt_id'] ?? 0);
        $ref = trim((string) ($row['bayt_ref'] ?? ''));
        if ($ref === '') {
            $ref = 'HFS-T-' . $ledgerId;
        }
        if ($baytId < 1 && ($row['bayt_status'] ?? '') !== 'sent') {
            db()->prepare("UPDATE ledger SET bayt_status='deleted' WHERE id=?")->execute([$ledgerId]);
            return true;
        }
        if (!self::enabled()) {
            db()->prepare("UPDATE ledger SET bayt_status='deleted' WHERE id=?")->execute([$ledgerId]);
            return true;
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return $auth;
        }
        if ($baytId < 1) {
            $baytId = self::findCollectionId($ref);
            if ($baytId > 0) {
                db()->prepare('UPDATE ledger SET bayt_id=? WHERE id=?')->execute([$baytId, $ledgerId]);
            }
        }
        if ($baytId > 0) {
            $res = self::api('PartnerApiCollectionOperations/DeleteCollection', ['Id' => $baytId], 90);
            if (is_string($res)) {
                return self::failCollection($row, 'İptal: ' . $res);
            }
            if (empty($res['Success']) && !self::looksMissing(self::apiMessage($res, ''))) {
                return self::failCollection($row, 'İptal: ' . self::apiMessage($res, 'Tahsilat muhasebeden silinemedi.'));
            }
        }
        db()->prepare("UPDATE ledger SET bayt_status='deleted', bayt_error=NULL WHERE id=?")->execute([$ledgerId]);
        self::log((int) ($row['order_id'] ?? 0), true, 'Tahsilat #' . $ledgerId . ' muhasebeden silindi.');
        return true;
    }

    public static function retryVoidedCollections(int $limit = 20): array
    {
        $out = ['ok' => 0, 'fail' => 0, 'steps' => []];
        if (!self::enabled()) {
            return $out;
        }
        $rows = db()->query("SELECT * FROM ledger WHERE kind='cari_odeme' AND IFNULL(status,'ok')='iptal' AND IFNULL(bayt_status,'')<>'deleted' AND (IFNULL(bayt_status,'') IN ('sent','error') OR IFNULL(bayt_id,0)>0) ORDER BY id DESC LIMIT " . max(1, min(40, $limit)))->fetchAll();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $r = self::deleteCollection($id);
            if ($r === true) {
                $out['ok']++;
                $out['steps'][] = 'HFS-T-' . $id . ' muhasebeden silindi';
            } else {
                $out['fail']++;
                $out['steps'][] = 'HFS-T-' . $id . ': ' . $r;
            }
        }
        return $out;
    }

    private static function findCollectionId(string $ref): int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return 0;
        }
        $res = self::api('PartnerApiCollectionOperations/GetCollectionList', ['SkipCount' => 0, 'TakeCount' => 80], 90);
        $items = is_array($res) ? self::listRows($res) : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $r = trim((string) ($row['ReferenceNo'] ?? $row['RefNo'] ?? $row['DocumentNo'] ?? ''));
            $desc = (string) ($row['Description'] ?? $row['Note'] ?? '');
            if (strcasecmp($r, $ref) === 0 || str_contains($desc, $ref)) {
                return (int) ($row['Id'] ?? 0);
            }
        }
        return 0;
    }

    public static function pullCancellations(int $limit = 20): int
    {
        return self::syncFromBayt($limit);
    }

    /** @param array<string,mixed> $order */
    public static function inspectOrderRemote(array $order): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => 'Bayt kapalı'];
        }
        bmn_session_release();
        $code = (string) ($order['code'] ?? '');
        $ref = trim((string) ($order['bayt_ref'] ?? ''));
        if ($ref === '') {
            $ref = self::orderRef($code);
        }
        $oid = (int) ($order['bayt_order_id'] ?? 0);
        $wid = (int) ($order['bayt_id'] ?? 0);
        $out = ['ok' => true, 'ref' => $ref, 'order' => null, 'waybill' => null];
        if ($oid < 1) {
            $found = self::findExistingOrder($ref);
            $oid = (int) ($found['id'] ?? 0);
        }
        if ($wid < 1) {
            $foundW = self::findExisting($ref);
            $wid = (int) ($foundW['id'] ?? 0);
        }
        if ($oid > 0) {
            $res = self::api('PartnerApiOrderOperations/GetOrderById', ['Id' => $oid, 'OrderType' => 1]);
            if (is_array($res) && !empty($res['Success'])) {
                $data = is_array($res['Data'] ?? null) ? $res['Data'] : $res;
                $out['order'] = ['id' => $oid, 'state' => self::classifyBaytOrder($data, $oid)];
            } else {
                $out['order'] = ['id' => $oid, 'state' => 'missing', 'msg' => is_string($res) ? $res : self::apiMessage($res, '')];
            }
        }
        if ($wid > 0) {
            $res = self::api('PartnerApiWaybillOperations/GetWaybillById', ['Id' => $wid, 'WaybillType' => 1]);
            if (is_array($res) && !empty($res['Success'])) {
                $data = is_array($res['Data'] ?? null) ? $res['Data'] : $res;
                $out['waybill'] = ['id' => $wid, 'state' => self::classifyBaytOrder($data, $wid)];
            } else {
                $out['waybill'] = ['id' => $wid, 'state' => 'missing', 'msg' => is_string($res) ? $res : self::apiMessage($res, '')];
            }
        }
        if ($oid < 1 && $wid < 1) {
            $out['msg'] = 'Bayt’ta bu referansla kayıt yok (veya silinmiş).';
        }
        return $out;
    }

    public static function syncFromBayt(int $limit = 25): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $lim = max(1, min(40, $limit));
        // Onaylılar dahil: Bayt’ta silinen irsaliye/sipariş B2B’de iptal edilir.
        $rows = db()->query(
            "SELECT * FROM orders WHERE status NOT IN ('iptal')
             AND IFNULL(bayt_status,'')<>'deleted'
             AND IFNULL(bayt_approval,'')<>'cancelled'
             AND (bayt_order_id>0 OR (bayt_status='sent' AND bayt_id>0))
             ORDER BY id DESC LIMIT " . $lim
        )->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            if (self::syncOne($row)) {
                $n++;
            }
        }
        return $n;
    }

    /** Bayi ekstre / hesap: kendi bekleyen muhasebe onaylarını Bayt’tan çek. */
    public static function syncPendingApprovalsForUser(?array $user, int $limit = 20): void
    {
        if (!self::enabled() || !$user) {
            return;
        }
        $cid = HfsPricing::companyId($user);
        if ($cid < 1) {
            return;
        }
        // Ekstre her açılışta 20 siparişi tek tek Bayt'a sormasın: firma başına 10 dk'da bir.
        $tryKey = 'bayt_appr_sync_' . $cid;
        if ((time() - (int) setting($tryKey, '0')) < 600) {
            return;
        }
        setting_set($tryKey, (string) time());
        $ids = HfsFinance::memberIds($cid);
        if ($ids === []) {
            return;
        }
        $lim = max(1, min(30, $limit));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare(
            "SELECT * FROM orders WHERE (company_id IN ($ph) OR user_id IN ($ph))
             AND (bayt_id>0 OR bayt_order_id>0)
             AND IFNULL(bayt_status,'')<>'deleted'
             AND status NOT IN ('iptal') AND IFNULL(bayt_approval,'')<>'cancelled'
             ORDER BY id DESC LIMIT " . $lim
        );
        $st->execute(array_merge($ids, $ids));
        foreach ($st->fetchAll() as $row) {
            try {
                self::syncOne($row);
            } catch (Throwable) {
            }
        }
    }

    /** @param array<string,mixed> $order */
    public static function syncOne(array $order): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $waybillId = (int) ($order['bayt_id'] ?? 0);
        $orderBaytId = (int) ($order['bayt_order_id'] ?? 0);
        if ((string) ($order['status'] ?? '') === 'iptal') {
            return false;
        }
        if ($waybillId < 1 && $orderBaytId < 1) {
            return false;
        }
        $bs = (string) ($order['bayt_status'] ?? '');
        if ($bs === 'deleted') {
            return false;
        }
        if ($bs !== 'sent' && $orderBaytId < 1) {
            return false;
        }
        $res = null;
        if ($waybillId > 0) {
            $wb = self::api('PartnerApiWaybillOperations/GetWaybillById', ['Id' => $waybillId, 'WaybillType' => 1]);
            if (is_string($wb)) {
                return false;
            }
            if (empty($wb['Success'])) {
                // İrsaliye Bayt’ta yoksa satış siparişi kalsa bile B2B iptal (ekstre / açık bakiye).
                if (self::looksMissing(self::apiMessage($wb, ''))) {
                    $full = Catalog::orderByCode((string) ($order['code'] ?? ''));
                    if ($full) {
                        Catalog::cancelOrder($full, 'Muhasebede irsaliye silindi', true, true);
                        self::forgetBalanceCacheForOrder($full);
                        return true;
                    }
                    return false;
                }
                // Belirsiz hata: satış siparişine düş.
            } else {
                $res = $wb;
            }
        }
        if ($res === null || empty($res['Success'])) {
            if ($orderBaytId > 0) {
                $asOrder = self::api('PartnerApiOrderOperations/GetOrderById', ['Id' => $orderBaytId, 'OrderType' => 1]);
                if (!is_string($asOrder) && !empty($asOrder['Success'])) {
                    $res = $asOrder;
                } elseif (!is_string($asOrder) && self::looksMissing(self::apiMessage(is_array($asOrder) ? $asOrder : [], ''))) {
                    $full = Catalog::orderByCode((string) ($order['code'] ?? ''));
                    if ($full) {
                        Catalog::cancelOrder($full, 'Muhasebede silindi', true, true);
                        self::forgetBalanceCacheForOrder($full);
                        return true;
                    }
                    return false;
                }
            }
            if (!is_array($res) || empty($res['Success'])) {
                if (!self::looksMissing(self::apiMessage(is_array($res) ? $res : [], ''))) {
                    return false;
                }
                $full = Catalog::orderByCode((string) ($order['code'] ?? ''));
                if ($full) {
                    Catalog::cancelOrder($full, 'Muhasebede silindi', true, true);
                    self::forgetBalanceCacheForOrder($full);
                    return true;
                }
                return false;
            }
        }
        $data = is_array($res['Data'] ?? null) ? $res['Data'] : $res;
        $salesId = $orderBaytId > 0 ? $orderBaytId : $waybillId;
        $kind = self::classifyBaytOrder($data, $salesId);
        if ($kind === 'pending' && $orderBaytId > 0) {
            $asOrder = self::api('PartnerApiOrderOperations/GetOrderById', ['Id' => $orderBaytId, 'OrderType' => 1]);
            if (!is_string($asOrder) && !empty($asOrder['Success'])) {
                $orderData = is_array($asOrder['Data'] ?? null) ? $asOrder['Data'] : $asOrder;
                $kind = self::classifyBaytOrder($orderData, $orderBaytId);
            }
        }
        if ($kind === 'cancelled') {
            $full = Catalog::orderByCode((string) ($order['code'] ?? ''));
            if ($full) {
                Catalog::cancelOrder($full, 'Muhasebede iptal', true, true);
                self::forgetBalanceCacheForOrder($full);
                return true;
            }
            return false;
        }
        if ($kind === 'approved') {
            return self::applyApproved($order);
        }
        return false;
    }

    /** @param array<string,mixed> $data */
    private static function classifyBaytOrder(array $data, int $baytId): string
    {
        $flat = self::flattenScalars($data);
        if (self::truthyFlag($flat, ['Cancelled', 'IsCancelled', 'IsDeleted', 'Deleted', 'IsCancel', 'Rejected', 'IsRejected', 'IsVoid', 'Voided'])) {
            return 'cancelled';
        }
        $raw = self::pickScalar($flat, ['Status', 'OrderStatus', 'DocumentStatus', 'ApprovalStatus', 'State', 'OrderState', 'ApproveStatus', 'ProcessStatus']);
        $st = self::normStatus((string) $raw);
        if ($st !== '' && preg_match('/iptal|cancel|reject|redded|silindi|void|deleted/', $st)) {
            return 'cancelled';
        }
        if (is_numeric($st) && in_array((int) $st, [3, 4, 9, 99], true)) {
            return 'cancelled';
        }
        if (self::truthyFlag($flat, ['IsApproved', 'Approved', 'Transferred', 'IsTransferred', 'Invoiced', 'IsInvoiced', 'Imported', 'IsImported', 'Accepted', 'IsAccepted'])) {
            return 'approved';
        }
        if ($st !== '' && preg_match('/onay|approv|fatura|invoice|transfer|kabul|completed|tamam|imported|accepted|onayland/', $st)) {
            return 'approved';
        }
        $doc = self::pickScalar($flat, ['InvoiceId', 'InvoiceNo', 'InvoiceNumber', 'WaybillId', 'IrsaliyeId']);
        if (is_numeric((string) $doc) && (int) $doc > 0) {
            return 'approved';
        }
        if (is_string($doc) && trim($doc) !== '' && $doc !== '0') {
            return 'approved';
        }
        foreach (['ApprovalDate', 'ApprovedDate', 'TransferDate', 'InvoiceDate'] as $dk) {
            $dv = trim((string) ($flat[$dk] ?? ''));
            if ($dv !== '' && !str_starts_with($dv, '0001') && $dv !== '0') {
                return 'approved';
            }
        }
        if (self::hasInvoicedItems($baytId)) {
            return 'approved';
        }
        return 'pending';
    }

    private static function hasInvoicedItems(int $baytId): bool
    {
        if ($baytId < 1) {
            return false;
        }
        $inv = self::api('PartnerApiOrderOperations/GetInvoicedItemsByOrderId', ['Id' => $baytId, 'OrderType' => 1]);
        if (is_string($inv) || empty($inv['Success'])) {
            return false;
        }
        $data = $inv['Data'] ?? [];
        if (!is_array($data)) {
            return false;
        }
        if (isset($data['Items']) && is_array($data['Items'])) {
            $data = $data['Items'];
        }
        if ($data === []) {
            return false;
        }
        if (isset($data[0]) || isset($data['Id']) || isset($data['ProductId']) || isset($data['InvoiceId'])) {
            return true;
        }
        return count($data) > 0;
    }

    /** @param array<string,mixed> $order */
    private static function applyApproved(array $order): bool
    {
        if ((string) ($order['bayt_approval'] ?? '') === 'approved') {
            return false;
        }
        $id = (int) ($order['id'] ?? 0);
        if ($id < 1) {
            return false;
        }
        db()->prepare("UPDATE orders SET bayt_approval='approved', bayt_error=NULL WHERE id=?")->execute([$id]);
        self::log($id, true, 'Muhasebe onayladı.');
        $fresh = Catalog::orderByCode((string) ($order['code'] ?? ''));
        if ($fresh) {
            HfsMail::orderStatus($fresh, (string) ($order['status'] ?? 'yeni'));
            if ((int) ($fresh['bayt_id'] ?? 0) < 1 && (int) ($fresh['bayt_order_id'] ?? 0) > 0) {
                try {
                    self::ensureWaybillPipeline($id, false);
                } catch (Throwable $e) {
                    self::log($id, false, 'Onay sonrası irsaliye: ' . $e->getMessage());
                }
            }
        }
        return true;
    }

    /** Muhasebe onaylı ama irsaliyesi oluşmamış siparişler. */
    /**
     * Bayt cari ekstresindeki güncel net bakiye (GetCustomerById Balance).
     *
     * @return array{ok:bool,balance:?float,debit:?float,credit:?float,code:string,name:string,fetched_at:int,error?:string}
     */
    public static function customerBalanceForUser(?array $user): array
    {
        $empty = [
            'ok' => false,
            'balance' => null,
            'debit' => null,
            'credit' => null,
            'code' => '',
            'name' => '',
            'fetched_at' => 0,
        ];
        if (!self::enabled() || !$user) {
            return $empty;
        }
        $co = HfsPricing::company($user);
        if (!is_array($co)) {
            return $empty;
        }
        $baytId = (int) ($co['bayt_customer_id'] ?? 0);
        $code = trim((string) ($co['bayt_customer_code'] ?? ''));
        if ($baytId < 1) {
            return array_merge($empty, ['error' => 'Bayt cari eşlemesi yok']);
        }
        $cacheKey = 'bayt_bal_' . $baytId;
        $raw = setting($cacheKey, '');
        $cached = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($cached) && (time() - (int) ($cached['fetched_at'] ?? 0)) < 300) {
            return $cached;
        }
        // Bu sayfa düzeninde her istekte çağrılır: Bayt yavaş/kapalıyken her sayfa
        // 45 sn beklemesin diye 120 sn'de en fazla bir deneme; arada eski değer döner.
        $tryKey = 'bayt_bal_try_' . $baytId;
        $stale = static fn (string $err): array => is_array($cached) && !empty($cached['ok'])
            ? $cached + ['stale' => true]
            : array_merge($empty, ['error' => $err]);
        if ((time() - (int) setting($tryKey, '0')) < 120) {
            return $stale('Bayt bakiyesi geçici olarak okunamadı');
        }
        try {
            setting_set($tryKey, (string) time());
        } catch (Throwable) {
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return $stale($auth);
        }
        $res = self::api('PartnerApiCustomerOperations/GetCustomerById', ['Id' => $baytId], 20);
        if (is_string($res) || empty($res['Success'])) {
            $msg = is_string($res) ? $res : self::apiMessage($res, 'Bayt bakiyesi okunamadı');
            return $stale($msg);
        }
        $d = is_array($res['Data'] ?? null) ? $res['Data'] : [];
        $out = [
            'ok' => true,
            'balance' => (float) ($d['Balance'] ?? 0),
            'debit' => (float) ($d['DebitBalance'] ?? 0),
            'credit' => (float) ($d['CreditBalance'] ?? 0),
            'code' => trim((string) ($d['Code'] ?? $code)),
            'name' => trim((string) ($d['Name'] ?? '')),
            'fetched_at' => time(),
        ];
        try {
            setting_set($cacheKey, json_encode($out, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
        }
        return $out;
    }

    public static function pushApprovedWaybills(int $limit = 15): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $lim = max(1, min(25, $limit));
        $rows = db()->query(
            "SELECT * FROM orders WHERE is_cari=0 AND status NOT IN ('iptal','odeme','pending_b2b')
             AND bayt_order_id>0 AND IFNULL(bayt_id,0)=0 AND bayt_approval='approved'
             ORDER BY id DESC LIMIT " . $lim
        )->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            try {
                $r = self::ensureWaybillPipeline((int) $row['id'], false);
                if ($r === true) {
                    $n++;
                }
            } catch (Throwable) {
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $row */
    private static function flattenScalars(array $row): array
    {
        $out = [];
        $walk = static function ($node) use (&$walk, &$out): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    $walk($v);
                    continue;
                }
                if (is_string($k) && (is_scalar($v) || $v === null)) {
                    $out[$k] = $v;
                }
            }
        };
        $walk($row);
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function truthyFlag(array $row, array $keys): bool
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if ($v === true || $v === 1 || $v === '1') {
                return true;
            }
            if (is_string($v) && in_array(self::normStatus($v), ['true', 'yes', 'onay', 'onaylandi', 'approved'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $row */
    private static function pickScalar(array $row, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && (is_scalar($row[$k]) || $row[$k] === null)) {
                return $row[$k];
            }
        }
        return null;
    }

    private static function normStatus(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return str_replace(['ı', 'İ', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'], ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'], $s);
    }

    public static function looksMissing(string $msg): bool
    {
        $t = mb_strtolower($msg);
        foreach (['bulunamad', 'not found', 'yok', 'silindi', 'deleted', 'kayıt yok', 'kayit yok', 'does not exist'] as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $order */
    private static function forgetBalanceCacheForOrder(array $order): void
    {
        $cid = (int) ($order['company_id'] ?? 0);
        if ($cid < 1) {
            $cid = (int) ($order['user_id'] ?? 0);
        }
        if ($cid < 1) {
            return;
        }
        try {
            $st = db()->prepare('SELECT bayt_customer_id FROM users WHERE id=? LIMIT 1');
            $st->execute([$cid]);
            $baytId = (int) ($st->fetchColumn() ?: 0);
            if ($baytId < 1) {
                $st = db()->prepare('SELECT bayt_customer_id FROM users WHERE id=(SELECT parent_user_id FROM users WHERE id=? LIMIT 1) LIMIT 1');
                $st->execute([$cid]);
                $baytId = (int) ($st->fetchColumn() ?: 0);
            }
            if ($baytId > 0) {
                setting_set('bayt_bal_' . $baytId, '');
            }
        } catch (Throwable) {
        }
    }

    /** @return array{ok:bool,message:string,steps:list<string>} */
    public static function test(): array
    {
        $steps = [];
        $s = self::settings();
        $token = self::getToken(true);
        if (is_string($token) && !str_starts_with($token, 'ey') && strlen($token) < 20 && str_contains($token, ' ')) {
            // error message
        }
        if (is_string($token) && (str_contains($token, ' ') || str_starts_with($token, 'GetToken') || str_contains($token, 'başar'))) {
            $steps[] = 'GetToken: ' . $token;
            return ['ok' => false, 'message' => $token, 'steps' => $steps];
        }
        if (!is_string($token) || $token === '') {
            $steps[] = 'GetToken başarısız.';
            return ['ok' => false, 'message' => 'GetToken başarısız.', 'steps' => $steps];
        }
        $steps[] = 'GetToken: OK';
        $steps[] = 'Hedef: ' . $s['protocol'] . '://' . $s['api_host'] . ':' . $s['api_port'];
        $ping = self::serverGet('PartnerApiServerOperations/Ping');
        if (is_string($ping)) {
            $steps[] = 'Ping :' . $s['api_port'] . ' başarısız (' . $ping . ')';
            foreach (['25066', '3000', '8080', '90'] as $tryPort) {
                if ($tryPort === (string) $s['api_port']) {
                    continue;
                }
                self::$portOverride = $tryPort;
                $try = self::serverGet('PartnerApiServerOperations/Ping');
                if (!is_string($try)) {
                    $s['api_port'] = $tryPort;
                    setting_set('bayt_json', json_encode($s, JSON_UNESCAPED_UNICODE));
                    $ping = $try;
                    $steps[] = 'Ping :' . $tryPort . ' OK';
                    break;
                }
                $steps[] = 'Ping :' . $tryPort . ' başarısız';
            }
            self::$portOverride = null;
            if (is_string($ping)) {
                return ['ok' => false, 'message' => 'GetToken oldu; CapitalAPI (HTTP) kapalı. Dış API portunu girin.', 'steps' => $steps];
            }
        } else {
            $steps[] = 'Ping: OK';
        }
        $session = self::connect($token, true);
        if (is_string($session) && (str_contains($session, ' ') || str_contains($session, 'başar') || str_contains($session, 'Connect'))) {
            $steps[] = 'Connect: ' . $session;
            return ['ok' => false, 'message' => $session, 'steps' => $steps];
        }
        $steps[] = 'Connect: OK';
        $cust = self::api('PartnerApiCustomerOperations/GetCustomerList', ['SkipCount' => 0, 'TakeCount' => 5]);
        $steps[] = is_string($cust) ? ('GetCustomerList: ' . $cust) : 'GetCustomerList: OK';
        $prod = self::api('PartnerApiProductOperations/GetProductList', ['ProductType' => 0, 'SkipCount' => 0, 'TakeCount' => 5]);
        $steps[] = is_string($prod) ? ('GetProductList: ' . $prod) : 'GetProductList: OK';
        self::log(0, true, 'Bağlantı testi başarılı');
        return ['ok' => true, 'message' => 'Bağlantı başarılı.', 'steps' => $steps];
    }

    private static function ensureSession(): true|string
    {
        if (!empty(self::$session['session'])) {
            return true;
        }
        $token = self::getToken(false);
        if (is_string($token) && (str_contains($token, ' ') || strlen($token) < 8)) {
            return $token;
        }
        if (!is_string($token) || $token === '') {
            return 'GetToken başarısız.';
        }
        $session = self::connect($token, false);
        if (!is_string($session) || $session === '' || str_contains($session, ' ')) {
            return is_string($session) ? $session : 'Connect başarısız.';
        }
        return true;
    }

    private static function getToken(bool $fresh): string
    {
        if (!$fresh && !empty(self::$session['partner'])) {
            return (string) self::$session['partner'];
        }
        $s = self::settings();
        $res = self::httpJson($s['token_url'], 'POST', [
            'Username' => $s['partner_user'],
            'Password' => $s['partner_pass'],
        ], [], true, 60);
        if (is_string($res)) {
            return $res;
        }
        if (empty($res['Success']) || empty($res['Token'])) {
            return self::apiMessage($res, 'GetToken başarısız.');
        }
        self::$session['partner'] = (string) $res['Token'];
        return self::$session['partner'];
    }

    private static function connect(string $partnerToken, bool $fresh): string
    {
        if (!$fresh && !empty(self::$session['session'])) {
            return (string) self::$session['session'];
        }
        $s = self::settings();
        $res = self::apiRaw('PartnerApiCompanyOperations/Connect', [
            'Username' => $s['connect_user'],
            'Password' => $s['connect_pass'],
            'PartnerToken' => $partnerToken,
        ], true, '', 60);
        if (is_string($res)) {
            return $res;
        }
        if (empty($res['Success']) || empty($res['Token'])) {
            return self::apiMessage($res, 'Connect başarısız.');
        }
        self::$session['session'] = (string) $res['Token'];
        self::$session['partner'] = $partnerToken;
        return self::$session['session'];
    }

    /** @return array<string,mixed>|string */
    private static function api(string $path, array $body, int $timeout = 30): array|string
    {
        $session = (string) (self::$session['session'] ?? '');
        if ($session === '') {
            $ok = self::ensureSession();
            if (is_string($ok)) {
                return $ok;
            }
            $session = (string) (self::$session['session'] ?? '');
        }
        return self::apiRaw($path, $body, true, $session, $timeout);
    }

    /** @return array<string,mixed>|string */
    private static function apiRaw(string $path, array $body, bool $withCompany, string $session, int $timeout = 30): array|string
    {
        $s = self::settings();
        $base = self::baseUrl();
        if ($withCompany) {
            $cid = trim((string) $s['company_id']);
            if ($cid === '') {
                return 'CompanyId boş. Önce bağlantı testi yapın.';
            }
            $url = $base . '/' . rawurlencode($cid) . '/' . ltrim($path, '/');
        } else {
            $url = $base . '/' . ltrim($path, '/');
        }
        $headers = [];
        if ($session !== '') {
            $headers['Authorization'] = 'Bearer ' . $session;
        }
        return self::httpJson($url, 'POST', $body, $headers, false, $timeout);
    }

    /** @return array<string,mixed>|string */
    private static function serverGet(string $path): array|string
    {
        return self::httpJson(self::baseUrl() . '/' . ltrim($path, '/'), 'GET', null, [], false);
    }

    private static function baseUrl(): string
    {
        $s = self::settings();
        $prot = self::$protocolOverride ?: (($s['protocol'] ?? 'http') === 'https' ? 'https' : 'http');
        $host = trim((string) $s['api_host']);
        $port = self::$portOverride !== null ? self::$portOverride : trim((string) $s['api_port']);
        $auth = $prot . '://' . $host;
        if ($port !== '' && !in_array($port, ['80', '443'], true)) {
            $auth .= ':' . $port;
        }
        return $auth . '/bayt/capital/partnerapi';
    }

    /** @param array<string,string> $headers @return array<string,mixed>|string */
    private static function httpJson(string $url, string $method, ?array $body, array $headers, bool $ssl, int $timeout = 30): array|string
    {
        // Devre kesici: sunucuya bağlanılamadıysa 2 dk boyunca her istek 10 sn beklemesin.
        $downKey = 'bayt_down_' . substr(md5(parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT)), 0, 12);
        $downUntil = (int) setting($downKey, '0');
        if ($downUntil > time()) {
            return 'Bayt sunucusuna bağlanılamıyor (' . date('H:i', $downUntil) . ' sonrası tekrar denenecek).';
        }
        $hdr = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return 'cURL yok.';
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_TIMEOUT => max(15, $timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $ssl,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $connected = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        if ($raw === false) {
            if (!$connected && in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_OPERATION_TIMEDOUT], true)) {
                try {
                    setting_set($downKey, (string) (time() + 120));
                } catch (Throwable) {
                }
            }
            return $err !== '' ? $err : 'HTTP hatası';
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            return 'HTTP ' . $code . ': yanıt JSON değil.';
        }
        return $json;
    }

    /** @param array<string,mixed> $order */
    private static function sendSalesOrder(array $order, string $ref): true|string
    {
        if ((int) ($order['bayt_order_id'] ?? 0) > 0) {
            return true;
        }
        $found = self::findExistingOrder($ref);
        if (is_array($found) && !empty($found['id'])) {
            self::saveOrderId((int) $order['id'], (int) $found['id']);
            self::log((int) $order['id'], true, 'Satış siparişi zaten vardı #' . (int) $found['id']);
            return true;
        }
        $payload = self::buildPayload($order, $ref, 'order');
        if (is_string($payload)) {
            self::log((int) $order['id'], false, 'Sipariş: ' . $payload);
            return $payload;
        }
        $res = self::api('PartnerApiOrderOperations/CreateOrder', $payload);
        if (is_string($res)) {
            self::log((int) $order['id'], false, 'Sipariş: ' . $res);
            return $res;
        }
        if (empty($res['Success'])) {
            $msg = self::apiMessage($res, 'Satış siparişi oluşturulamadı.');
            self::log((int) $order['id'], false, 'Sipariş: ' . $msg);
            return $msg;
        }
        $id = (int) ($res['Data']['Id'] ?? $res['Id'] ?? 0);
        if ($id < 1) {
            $again = self::findExistingOrder($ref);
            $id = (int) ($again['id'] ?? 0);
        }
        if ($id > 0) {
            self::saveOrderId((int) $order['id'], $id);
        }
        self::log((int) $order['id'], true, 'Satış siparişi gönderildi #' . $id);
        return true;
    }

    private static function saveOrderId(int $orderId, int $baytOrderId): void
    {
        if ($orderId < 1 || $baytOrderId < 1) {
            return;
        }
        try {
            db()->prepare('UPDATE orders SET bayt_order_id=? WHERE id=?')->execute([$baytOrderId, $orderId]);
        } catch (Throwable) {
        }
    }

    /** @return array{id:int}|null */
    private static function findExistingOrder(string $ref): ?array
    {
        $res = self::api('PartnerApiOrderOperations/GetOrderByReferenceNo', ['ReferenceNo' => $ref, 'OrderType' => 1]);
        if (is_string($res) || empty($res['Success'])) {
            return null;
        }
        $data = $res['Data'] ?? $res;
        $id = (int) ($data['Id'] ?? 0);
        return $id > 0 ? ['id' => $id] : null;
    }

    /** @return array{id:int}|null */
    private static function findExisting(string $ref): ?array
    {
        $res = self::api('PartnerApiWaybillOperations/GetWaybillByReferenceNo', ['ReferenceNo' => $ref, 'WaybillType' => 1]);
        if (is_string($res) || empty($res['Success'])) {
            return null;
        }
        $data = $res['Data'] ?? $res;
        $id = (int) ($data['Id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $docRef = trim((string) ($data['ReferenceNo'] ?? $ref));
        return ['id' => $id, 'ref' => $docRef !== '' ? $docRef : $ref];
    }

    /** @param array<string,mixed> $order @return array{id:int,ref?:string}|null */
    private static function findExistingWaybillForOrder(array $order, string $b2bRef): ?array
    {
        $wid = (int) ($order['bayt_id'] ?? 0);
        if ($wid > 0) {
            $res = self::api('PartnerApiWaybillOperations/GetWaybillById', ['Id' => $wid, 'WaybillType' => 1], 25);
            if (is_array($res) && !empty($res['Success'])) {
                $d = is_array($res['Data'] ?? null) ? $res['Data'] : $res;
                if (!self::truthyFlag(self::flattenScalars($d), ['Cancelled', 'IsCancelled', 'IsDeleted', 'Deleted', 'IPTAL'])) {
                    $docRef = trim((string) ($d['ReferenceNo'] ?? ($order['bayt_ref'] ?? '')));
                    return ['id' => $wid, 'ref' => $docRef];
                }
            }
        }
        $byB2b = self::findExisting($b2bRef);
        if ($byB2b) {
            return $byB2b;
        }
        $storedRef = trim((string) ($order['bayt_ref'] ?? ''));
        if ($storedRef !== '' && preg_match('/^IRS/i', $storedRef)) {
            $byIrs = self::findExisting($storedRef);
            if ($byIrs) {
                return $byIrs;
            }
        }
        $code = strtoupper(trim((string) ($order['code'] ?? '')));
        if ($code === '') {
            return null;
        }
        $needle = str_replace("'", "''", 'B2B ' . $code);
        foreach (['ACIKLAMA', 'ACIKLAMA1', 'ACIKLAMA2', 'NOTLAR', 'NOTES'] as $col) {
            $sql = "SELECT TOP 1 ID, FISNO FROM STK_FIS WHERE TIP=20 AND IPTAL=0 AND CAST({$col} AS NVARCHAR(400)) LIKE '%{$needle}%' ORDER BY ID ASC";
            $q = self::api('PartnerApiHelperOperations/ExecuteQuery', ['SqlScript' => $sql], 25);
            $row = self::parseExecuteQueryRow($q);
            $foundId = (int) ($row['ID'] ?? 0);
            if ($foundId > 0) {
                $fis = trim((string) ($row['FISNO'] ?? ''));
                return ['id' => $foundId, 'ref' => $fis !== '' ? $fis : $storedRef];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $order @return array<string,mixed>|string */
    private static function buildPayload(array $order, string $ref, string $kind = 'waybill'): array|string
    {
        $s = self::settings();
        $customer = self::resolveCustomer($order);
        if (is_string($customer)) {
            return $customer;
        }
        if ($kind !== 'order') {
            self::enableCariEIrsaliye((int) $customer['id']);
        }
        $items = [];
        foreach ($order['items'] as $item) {
            if ((string) ($item['sku'] ?? '') === 'CARI') {
                continue;
            }
            $line = self::buildLine($item, $s, $kind === 'order');
            if (is_string($line)) {
                return $line;
            }
            $items[] = $line;
        }
        if ($items === []) {
            return 'Gönderilecek ürün satırı yok.';
        }
        $created = $order['created_at'] ?? date('Y-m-d H:i:s');
        $ts = strtotime((string) $created) ?: time();
        $date = date('Y-m-d', $ts) . 'T00:00:00.000';
        $time = date('Y-m-d\TH:i:s', $ts) . '.000';
        $taxOffice = '';
        $taxNumber = '';
        $uid = (int) ($order['company_id'] ?? 0);
        if ($uid < 1) {
            $uid = (int) ($order['user_id'] ?? 0);
        }
        if ($uid > 0) {
            $du = HfsDealers::get($uid);
            if ($du) {
                $cid = HfsPricing::companyId($du);
                if ($cid > 0 && $cid !== $uid) {
                    $du = HfsDealers::get($cid) ?: $du;
                }
                $taxOffice = trim((string) ($du['tax_office'] ?? ''));
                $taxNumber = preg_replace('/\D+/', '', (string) ($du['tax_number'] ?? '')) ?: '';
            }
        }
        $contact = self::orderContactForPayload($order);
        $pc = (string) ($contact['postcode_raw'] ?? '');
        $country = strtoupper((string) ($contact['country'] ?? 'TR'));
        if (in_array($country, ['TR', 'TUR', 'TURKIYE', 'TÜRKİYE', ''], true)) {
            if (!preg_match('/^[0-9]{5}$/', $pc)) {
                return 'Teslimat adresinin 5 haneli posta kodu eksik veya geçersiz (TR).';
            }
        }
        $note = trim((string) ($order['note'] ?? ''));
        $po = trim((string) ($order['po_number'] ?? ''));
        $notes = $note;
        if ($po !== '') {
            $notes = $notes === '' ? ('PO: ' . $po) : ($notes . ' | PO: ' . $po);
        }
        $code = strtoupper(trim((string) ($order['code'] ?? '')));
        if ($code !== '') {
            $b2b = 'B2B ' . $code;
            $notes = $notes === '' ? $b2b : ($b2b . ' | ' . $notes);
        } elseif ($notes === '') {
            $notes = $ref;
        }
        $payload = [
            'ReferenceNo' => self::referenceNoForPayload($kind, $ref),
            'ReceiptDate' => $date,
            'ReceiptTime' => $time,
            'CustomerId' => (int) $customer['id'],
            'StoredId' => (int) $s['store_id'],
            'CurrencyId' => (int) $s['currency_id'],
            'ExchangeRate' => 1,
            'ReportExchangeRate' => 1,
            'Notes' => self::clipField($notes, 250, false),
            'VatIncluded' => $s['vat_included'] === '1',
            'SalesRepresentativeId' => (int) $s['sales_rep_id'],
            'TaxOffice' => self::clipField($taxOffice, 20, false),
            'TaxNumber' => self::clipField($taxNumber, 11, false),
            'SpecialArea1' => self::clipField($po, 40, true),
            'SpecialArea2' => '',
            'SpecialArea3' => '',
            'SpecialArea4' => '',
            'SpecialArea5' => '',
            'SpecialGroupId1' => -1,
            'SpecialGroupId2' => -1,
            'SpecialGroupId3' => -1,
            'SpecialGroupId4' => -1,
            'SpecialGroupId5' => -1,
            'ContactInformation' => [
                'AddressCode' => '',
                'Address1' => self::clipField((string) ($contact['address1'] ?? ''), 50, false),
                'Address2' => '',
                'Region' => '',
                'CitySubdivision' => self::clipField((string) ($contact['district'] ?? ''), 20, false),
                'City' => self::clipField((string) ($contact['city'] ?? ''), 20, false),
                'Country' => self::clipField((string) ($contact['country_label'] ?? ''), 20, false),
                'PostCode' => self::clipField((string) ($contact['postcode'] ?? ''), 10, false),
                'Telephone1' => self::clipField((string) ($contact['phone'] ?? ''), 15, false),
                'Telephone2' => '',
                'Telefax' => '',
                'Gsm' => self::clipField((string) ($contact['phone'] ?? ''), 15, false),
                'Mail' => self::clipField((string) ($contact['email'] ?? ''), 40, false),
                'Url' => '',
            ],
            'Items' => $items,
        ];
        if ($kind === 'order') {
            $payload['OrderType'] = 1;
        } else {
            $payload['WaybillType'] = 1;
        }
        return $payload;
    }

    /** Bayt Partner’da resmi alan yok; yalnızca irsaliye öncesi CARI bayrak (e-İrsaliye/GİB değil). */
    private static function enableCariEIrsaliye(int $customerId): void
    {
        if ($customerId < 1) {
            return;
        }
        $id = (int) $customerId;
        self::api('PartnerApiHelperOperations/ExecuteQuery', [
            'SqlScript' => "UPDATE CARI SET EIRSALIYEKULLAN=1 WHERE ID={$id} AND ISNULL(CONVERT(int, EIRSALIYEKULLAN),0)=0; COMMIT;",
        ], 30);
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|string */
    private static function buildLine(array $item, array $s, bool $reserve = false): array|string
    {
        $sku = trim((string) ($item['sku'] ?? ''));
        $productId = (int) ($item['product_id'] ?? 0);
        $variantId = (int) ($item['variant_id'] ?? 0);
        if ($productId > 0 && $variantId < 1) {
            try {
                $st = db()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
                $st->execute([$productId]);
                $liveSku = trim((string) ($st->fetchColumn() ?: ''));
                if ($liveSku !== '') {
                    $sku = $liveSku;
                }
            } catch (Throwable) {
            }
        }
        $title = trim((string) ($item['title'] ?? ''));
        $note = trim((string) ($item['note'] ?? ''));
        $qty = (float) ($item['qty'] ?? 1);
        if ($qty <= 0) {
            $qty = 1;
        }
        $custom = Option::motorOtherFromNote($note);
        if ($custom === '' && $sku !== '' && preg_match('/-diger$/i', $sku) && preg_match('/[—–-]\s*(.+)$/u', $title, $tm)) {
            $tail = trim((string) ($tm[1] ?? ''));
            if ($tail !== '' && !preg_match('/^di[gğ]er$/iu', $tail)) {
                $custom = $tail;
            }
        }
        if ($custom !== '') {
            $sku = self::skuForMotorOther($sku, (int) ($item['product_id'] ?? 0), $custom);
            if ($title === '' || preg_match('/di[gğ]er/iu', $title)) {
                $baseName = trim((string) preg_replace('/\s*[—–-]\s*.*$/u', '', $title));
                if ($baseName === '') {
                    $baseName = 'Ürün';
                }
                $title = $baseName . ' — ' . $custom;
            } elseif (!str_contains($title, $custom)) {
                $title = $title . ' — ' . $custom;
            }
        }
        $mapped = self::resolveProduct((int) ($item['product_id'] ?? 0), $sku, $title);
        if (is_string($mapped)) {
            return $mapped;
        }
        $price = (float) ($item['price'] ?? 0);
        // B2B fiyatları KDV dahil; oran Bayt stok kartından (VatIncluded=true).
        $vat = array_key_exists('vat_rate', $mapped)
            ? self::normalizeVatRate($mapped['vat_rate'])
            : 20.0;
        $line = [
            'ProductId' => (int) $mapped['id'],
            'BarcodeId' => -1,
            'Quantity' => $qty,
            'UnitId' => (int) $mapped['unit_id'],
            'Price' => round($price, 4),
            'CurrencyId' => (int) $s['currency_id'],
            'LocalExchangeRate' => 1,
            'LineType' => 'ltStock',
            'Description' => self::clipField($title, 100, false),
            'VatRate' => $vat,
            'StoredId' => (int) $s['store_id'],
            'Discount1' => 0,
            'Discount2' => 0,
            'Discount3' => 0,
            'Discount4' => 0,
            'Discount5' => 0,
            'SpecialArea1' => '',
            'SpecialArea2' => '',
            'SpecialArea3' => '',
            'SpecialArea4' => '',
            'SpecialArea5' => '',
            'SpecialGroupId1' => -1,
            'SpecialGroupId2' => -1,
            'SpecialGroupId3' => -1,
            'SpecialGroupId4' => -1,
            'SpecialGroupId5' => -1,
        ];
        if ($reserve) {
            $line['Reserved'] = true;
        }
        return $line;
    }

    /** “Diğer” serbest metin → benzersiz Bayt SKU (örn. HFS-SA-V2-66-aaaa). */
    private static function skuForMotorOther(string $variantSku, int $productId, string $custom): string
    {
        $variantSku = trim($variantSku);
        $base = (string) preg_replace('/-diger$/i', '', $variantSku);
        $base = rtrim($base, '-');
        if ($base === '' || strcasecmp($base, $variantSku) === 0) {
            if ($productId > 0) {
                try {
                    $st = db()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
                    $st->execute([$productId]);
                    $base = rtrim(trim((string) ($st->fetchColumn() ?: '')), '-');
                } catch (Throwable) {
                    $base = '';
                }
            }
        }
        if ($base === '') {
            $base = $variantSku !== '' ? $variantSku : 'HFS';
            $base = (string) preg_replace('/-diger$/i', '', $base);
            $base = rtrim($base, '-');
        }
        $suf = self::motorOtherCodeSlug($custom);
        return $base . '-' . $suf;
    }

    private static function motorOtherCodeSlug(string $custom): string
    {
        $custom = trim($custom);
        if ($custom === '') {
            return 'x';
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $custom)) {
            return strtolower($custom);
        }
        $slug = function_exists('slugify') ? slugify($custom) : '';
        $slug = preg_replace('/[^a-z0-9]+/i', '', (string) $slug) ?: '';
        if ($slug === '') {
            $slug = strtolower(substr(hash('crc32b', $custom), 0, 6));
        }
        return $slug;
    }

    /** @return array{id:int,code:string}|string */
    private static function resolveCustomer(array $order): array|string
    {
        $userId = (int) ($order['company_id'] ?? 0);
        if ($userId < 1) {
            $userId = (int) ($order['user_id'] ?? 0);
        }
        $contact = self::orderContactForPayload($order);
        return self::ensureUserCustomer($userId, [
            'order_id' => (int) ($order['id'] ?? 0),
            'name' => (string) ($order['name'] ?? ''),
            'address' => $contact['address1'],
            'district' => $contact['district'],
            'city' => $contact['city'],
            'country' => $contact['country'],
            'postcode' => preg_replace('/\D+/', '', $contact['postcode_raw']) ?: '',
            'phone' => $contact['phone'],
            'email' => $contact['email'],
        ]);
    }

    /**
     * Sipariş anlık adresi + ana bayi kartı (ilçe, posta kodu, ülke).
     *
     * @param array<string,mixed> $order
     * @return array{address1:string,district:string,city:string,country:string,country_label:string,postcode:string,postcode_raw:string,phone:string,email:string}
     */
    private static function orderContactForPayload(array $order): array
    {
        $uid = (int) ($order['company_id'] ?? 0);
        if ($uid < 1) {
            $uid = (int) ($order['user_id'] ?? 0);
        }
        $du = null;
        if ($uid > 0) {
            $du = HfsDealers::get($uid);
            if ($du) {
                $cid = HfsPricing::companyId($du);
                if ($cid > 0 && $cid !== $uid) {
                    $du = HfsDealers::get($cid) ?: $du;
                }
            }
        }
        $pick = static function (array $order, ?array $du, string $oKey, string $dKey = ''): string {
            $v = trim((string) ($order[$oKey] ?? ''));
            if ($v !== '') {
                return $v;
            }
            if ($du !== null && $dKey !== '') {
                return trim((string) ($du[$dKey] ?? ''));
            }
            if ($du !== null) {
                return trim((string) ($du[$oKey] ?? ''));
            }
            return '';
        };
        $address1 = $pick($order, $du, 'address');
        $district = $pick($order, $du, 'district');
        $city = $pick($order, $du, 'city');
        $country = strtoupper($pick($order, $du, 'country') ?: 'TR');
        $postcodeRaw = $pick($order, $du, 'postcode');
        if ($postcodeRaw === '' && $du !== null) {
            $postcodeRaw = trim((string) ($du['postcode'] ?? ''));
        }
        if ($country === 'TR' && $city !== '' && $district !== '') {
            $loc = HfsTrAddress::resolve($city, $district);
            if ($loc) {
                $city = (string) ($loc['city'] ?? $city);
                $district = (string) ($loc['district'] ?? $district);
                if ($postcodeRaw === '' || !preg_match('/^[0-9]{5}$/', preg_replace('/\D+/', '', $postcodeRaw) ?: '')) {
                    $postcodeRaw = (string) ($loc['postcode'] ?? $postcodeRaw);
                }
            }
        }
        $postcodeRaw = preg_replace('/\D+/', '', $postcodeRaw) ?: '';
        $countryLabel = in_array($country, ['TR', 'TUR', 'TURKIYE', 'TÜRKİYE'], true) ? 'TÜRKİYE' : $country;
        return [
            'address1' => self::clipField($address1, 250, false),
            'district' => self::clipField($district, 80, false),
            'city' => self::clipField($city, 80, false),
            'country' => $country,
            'country_label' => self::clipField($countryLabel, 20, false),
            'postcode' => self::clipField($postcodeRaw, 10, false),
            'postcode_raw' => $postcodeRaw,
            'phone' => self::clipField($pick($order, $du, 'phone'), 40, false),
            'email' => self::clipField($pick($order, $du, 'email'), 80, false),
        ];
    }

    /** @param array<string,mixed> $fallback @return array{id:int,code:string}|string */
    private static function ensureUserCustomer(int $userId, array $fallback = []): array|string
    {
        $s = self::settings();
        $row = null;
        $code = '';
        if ($userId > 0) {
            $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
            $st->execute([$userId]);
            $row = $st->fetch() ?: null;
            if ($row && (int) ($row['bayt_customer_id'] ?? 0) > 0) {
                return ['id' => (int) $row['bayt_customer_id'], 'code' => (string) ($row['bayt_customer_code'] ?? '')];
            }
            $code = trim((string) ($row['bayt_customer_code'] ?? ''));
        }
        if ($code === '') {
            $code = $s['customer_code_prefix'] . ($userId ?: ('G' . (int) ($fallback['order_id'] ?? 0)));
        }
        $code = self::clipField($code, 15, false);
        $found = self::api('PartnerApiCustomerOperations/GetCustomerByCode', ['Code' => $code]);
        if (!is_string($found) && !empty($found['Success'])) {
            $data = $found['Data'] ?? $found;
            $id = (int) ($data['Id'] ?? 0);
            if ($id > 0) {
                self::saveCustomer($userId, $id, $code);
                if (is_array($row)) {
                    $row['bayt_customer_id'] = $id;
                    $row['bayt_customer_code'] = $code;
                    self::updateDealerCustomer($row, $fallback);
                }
                return ['id' => $id, 'code' => $code];
            }
        }
        if ($s['auto_create_customer'] !== '1') {
            return 'Bayt carisi bulunamadı: ' . $code;
        }
        $name = trim((string) ($row['company'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['display_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string) ($fallback['name'] ?? ''));
        }
        if ($name === '') {
            $name = $code;
        }
        $created = self::api('PartnerApiCustomerOperations/CreateCustomer', self::customerPayload($code, $name, self::customerExtra($row, $fallback)));
        if (is_string($created)) {
            return $created;
        }
        if (empty($created['Success'])) {
            return self::apiMessage($created, 'Cari oluşturulamadı.');
        }
        $data = $created['Data'] ?? $created;
        $id = (int) ($data['Id'] ?? 0);
        if ($id <= 0) {
            $again = self::api('PartnerApiCustomerOperations/GetCustomerByCode', ['Code' => $code]);
            if (!is_string($again)) {
                $id = (int) ($again['Data']['Id'] ?? 0);
            }
        }
        if ($id <= 0) {
            return 'Cari oluştu ama ID alınamadı.';
        }
        self::saveCustomer($userId, $id, $code);
        self::enableCariEIrsaliye($id);
        return ['id' => $id, 'code' => $code];
    }

    /** @param array<string,mixed>|null $row @param array<string,mixed> $fallback @return array<string,mixed> */
    private static function customerExtra(?array $row, array $fallback = []): array
    {
        $row = $row ?: [];
        return [
            'TaxOffice' => (string) ($row['tax_office'] ?? ''),
            'TaxNumber' => preg_replace('/\D+/', '', (string) ($row['tax_number'] ?? '')) ?: '',
            'Address1' => (string) ($row['address'] ?? $fallback['address'] ?? ''),
            'Address2' => (string) ($fallback['address2'] ?? ''),
            'CitySubdivision' => (string) ($row['district'] ?? $fallback['district'] ?? ''),
            'City' => (string) ($row['city'] ?? $fallback['city'] ?? ''),
            'Country' => (string) ($row['country'] ?? $fallback['country'] ?? 'TR'),
            'PostCode' => (string) ($row['postcode'] ?? $fallback['postcode'] ?? $fallback['zip'] ?? ''),
            'Telephone1' => (string) ($row['phone'] ?? $fallback['phone'] ?? ''),
            'Gsm' => (string) ($row['phone'] ?? $fallback['phone'] ?? ''),
            'Mail' => (string) ($row['email'] ?? $fallback['email'] ?? ''),
        ];
    }

    /** Kayıtlı Bayt carisine vergi / adres günceller. */
    /** @param array<string,mixed> $row */
    private static function updateDealerCustomer(array $row, array $fallback = []): void
    {
        $id = (int) ($row['bayt_customer_id'] ?? 0);
        $code = self::clipField((string) ($row['bayt_customer_code'] ?? ''), 15, false);
        if ($id < 1 || $code === '') {
            return;
        }
        $name = trim((string) ($row['company'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['display_name'] ?? ''));
        }
        if ($name === '') {
            $name = $code;
        }
        $payload = self::customerPayload($code, $name, self::customerExtra($row, $fallback));
        $payload['Id'] = $id;
        $res = self::api('PartnerApiCustomerOperations/UpdateCustomer', $payload);
        if (is_string($res) || empty($res['Success'])) {
            self::log(0, false, 'Cari güncelleme: ' . (is_string($res) ? $res : self::apiMessage($res, 'başarısız')));
            return;
        }
        self::enableCariEIrsaliye($id);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private static function customerPayload(string $code, string $name, array $extra = []): array
    {
        $country = strtoupper(trim((string) ($extra['Country'] ?? 'TR')));
        if ($country === 'TR' || $country === 'TÜRKİYE' || $country === 'TURKIYE') {
            $country = 'TURKIYE';
        }
        return [
            'Code' => self::clipField($code, 15, false),
            'Name' => self::clipField($name !== '' ? $name : $code, 80, false),
            'CustomerType' => 'Alıcı + Satıcı',
            'TaxOffice' => self::clipField((string) ($extra['TaxOffice'] ?? ''), 20),
            'TaxNumber' => self::clipField((string) ($extra['TaxNumber'] ?? ''), 11),
            'Address1' => self::clipField((string) ($extra['Address1'] ?? ''), 50),
            'Address2' => self::clipField((string) ($extra['Address2'] ?? ''), 50),
            'Region' => ' ',
            'CitySubdivision' => self::clipField((string) ($extra['CitySubdivision'] ?? ''), 20),
            'City' => self::clipField((string) ($extra['City'] ?? ''), 20),
            'Country' => self::clipField($country, 20),
            'PostCode' => self::clipField((string) ($extra['PostCode'] ?? ''), 10),
            'Telephone1' => self::clipField((string) ($extra['Telephone1'] ?? ''), 15),
            'Telephone2' => ' ',
            'Telefax' => ' ',
            'Gsm' => self::clipField((string) ($extra['Gsm'] ?? ''), 15),
            'Mail' => self::clipField((string) ($extra['Mail'] ?? ''), 40),
            'Url' => ' ',
            'PurchasedAccountingId' => (int) ($extra['PurchasedAccountingId'] ?? 1),
            'SellerAccountingId' => (int) ($extra['SellerAccountingId'] ?? 2),
            'EInvoiceCustomer' => false,
        ];
    }

    private static function clipField(string $s, int $max, bool $spaceIfEmpty = true): string
    {
        $s = trim($s);
        if ($s === '') {
            return $spaceIfEmpty ? ' ' : '';
        }
        if (function_exists('mb_strcut')) {
            $cut = rtrim((string) mb_strcut($s, 0, $max, 'UTF-8'));
            return $cut !== '' ? $cut : ($spaceIfEmpty ? ' ' : '');
        }
        return strlen($s) <= $max ? $s : (string) substr($s, 0, $max);
    }

    private static function saveCustomer(int $userId, int $id, string $code): void
    {
        if ($userId < 1) {
            return;
        }
        db()->prepare('UPDATE users SET bayt_customer_id=?, bayt_customer_code=? WHERE id=?')->execute([$id, $code, $userId]);
    }

    /**
     * Bayt cari kartlarını sayfalı çeker.
     *
     * @return list<array<string,mixed>>|string
     */
    public static function fetchCustomerRows(int $max = 800): array|string
    {
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return $auth;
        }
        $out = [];
        $skip = 0;
        $take = 30;
        while (count($out) < $max) {
            $res = self::api('PartnerApiCustomerOperations/GetCustomerList', [
                'SkipCount' => $skip,
                'TakeCount' => $take,
            ], 90);
            if (is_string($res)) {
                return $out !== [] ? $out : $res;
            }
            if (empty($res['Success'])) {
                return $out !== [] ? $out : self::apiMessage($res, 'Cari listesi alınamadı.');
            }
            $items = self::listRows($res);
            if ($items === []) {
                break;
            }
            foreach ($items as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
                if (count($out) >= $max) {
                    break 2;
                }
            }
            if (count($items) < $take) {
                break;
            }
            $skip += $take;
        }
        return $out;
    }

    /** @param array<string,mixed> $res @return list<mixed> */
    private static function listRows(array $res): array
    {
        $data = $res['Data'] ?? [];
        if (isset($data['Items']) && is_array($data['Items'])) {
            return $data['Items'];
        }
        if (is_array($data) && $data !== [] && array_is_list($data)) {
            return $data;
        }
        return [];
    }

    /**
     * Bekleyen siparişleri gönderir, iptal tahsilatları Bayt’tan siler.
     *
     * @return array<string,mixed>
     */
    public static function repairDealerDocs(string $username = 'bayi1'): array
    {
        $st = db()->prepare('SELECT id, username, bayt_customer_id, bayt_customer_code FROM users WHERE username=? LIMIT 1');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u) {
            return ['ok' => false, 'error' => 'kullanıcı yok'];
        }
        $cid = (int) $u['id'];
        $orders = db()->prepare("SELECT id, code, status, payment, payment_status, total, bayt_status, bayt_doc_type, bayt_id, bayt_order_id, bayt_error, created_at FROM orders WHERE is_cari=0 AND (company_id=? OR user_id=?) ORDER BY id DESC LIMIT 8");
        $orders->execute([$cid, $cid]);
        $orderRows = $orders->fetchAll();
        $ledgers = db()->prepare("SELECT id, amount, note, status, bayt_status, bayt_id, bayt_ref, bayt_error, created_at FROM ledger WHERE company_id=? AND kind='cari_odeme' ORDER BY id DESC LIMIT 12");
        $ledgers->execute([$cid]);
        $ledgerRows = $ledgers->fetchAll();
        $voidId = (int) ($_GET['voidt'] ?? 0);
        $voided = null;
        if ($voidId > 0) {
            $voided = HfsFinance::voidCariPayment($voidId, 'Bayi iptal');
        }
        $sent = [];
        $only = strtolower(trim((string) ($_GET['order'] ?? '')));
        foreach ($orderRows as $o) {
            if ($only !== '' && strtolower((string) $o['code']) !== $only) {
                continue;
            }
            if ($only === '' && !self::isReadyToSend($o)) {
                continue;
            }
            if ($only === '') {
                continue;
            }
            $r = self::send((int) $o['id'], true);
            $sent[] = [
                'code' => (string) $o['code'],
                'ok' => $r === true,
                'msg' => $r === true ? 'ok' : (string) $r,
            ];
        }
        $voids = $voidId > 0 ? ['ok' => 0, 'fail' => 0, 'steps' => ['voidt ayrı gönderildi']] : self::retryVoidedCollections(20);
        return [
            'ok' => true,
            'user' => $username,
            'customer' => ['id' => (int) $u['bayt_customer_id'], 'code' => (string) $u['bayt_customer_code']],
            'orders' => $orderRows,
            'ledger' => $ledgerRows,
            'voided' => $voided,
            'sent' => $sent,
            'voids' => $voids,
        ];
    }

    /** GetCustomerList teşhis — şifre yok. */
    public static function inspectCustomers(): array
    {
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return ['ok' => false, 'auth' => $auth];
        }
        $ping = self::serverGet('PartnerApiServerOperations/Ping');
        $res = self::api('PartnerApiCustomerOperations/GetCustomerList', ['SkipCount' => 0, 'TakeCount' => 8], 90);
        $items = is_array($res) ? self::listRows($res) : [];
        $sample = [];
        $firstKeys = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($firstKeys === []) {
                $firstKeys = array_slice(array_keys($row), 0, 40);
            }
            $sample[] = [
                'Id' => $row['Id'] ?? $row['id'] ?? null,
                'Code' => $row['Code'] ?? $row['CustomerCode'] ?? null,
                'Name' => $row['Name'] ?? $row['CustomerName'] ?? null,
                'TaxNumber' => $row['TaxNumber'] ?? null,
                'CustomerType' => $row['CustomerType'] ?? null,
            ];
            if (count($sample) >= 8) {
                break;
            }
        }
        return [
            'ok' => is_array($res) && !empty($res['Success']),
            'ping' => is_string($ping) ? $ping : 'ok',
            'err' => is_string($res) ? $res : '',
            'success' => is_array($res) ? !empty($res['Success']) : false,
            'msg' => is_array($res) ? self::apiMessage($res, '') : '',
            'data_keys' => is_array($res) ? array_keys($res) : [],
            'inner_keys' => is_array($res) && is_array($res['Data'] ?? null) ? array_keys($res['Data']) : [],
            'count' => count($items),
            'row_keys' => $firstKeys,
            'sample' => $sample,
        ];
    }

    public static function inspect(string $sku = 'HFS-SA-V2-74'): array
    {
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return ['ok' => false, 'auth' => $auth];
        }
        $orders = db()->query("SELECT id, code, status, total, payment, bayt_status, bayt_doc_type, bayt_id, bayt_order_id, bayt_error, created_at FROM orders WHERE is_cari=0 ORDER BY id DESC LIMIT 8")->fetchAll();
        $byRef = self::api('PartnerApiWaybillOperations/GetWaybillById', ['Id' => 6247, 'WaybillType' => 1]);
        return [
            'ok' => true,
            'orders' => $orders,
            'sku' => trim($sku),
            'by_ref_ok' => is_array($byRef) && !empty($byRef['Success']),
            'by_ref_id' => is_array($byRef) ? (int) ($byRef['Data']['Id'] ?? 0) : 0,
            'by_ref_no' => is_array($byRef) ? ($byRef['Data']['ReferenceNo'] ?? null) : null,
            'by_ref_msg' => is_string($byRef) ? $byRef : self::apiMessage(is_array($byRef) ? $byRef : [], ''),
        ];
    }

    /** Mevcut B2B SKU’yu Bayt stok kartı koduna yazar (panelden kod değişmeden sonra). */
    public static function alignProductStockCode(int $productId, string $name = ''): array
    {
        if ($productId < 1) {
            return ['ok' => false, 'error' => 'ürün yok'];
        }
        $st = db()->prepare('SELECT sku, title FROM products WHERE id=? LIMIT 1');
        $st->execute([$productId]);
        $row = $st->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'ürün bulunamadı'];
        }
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku === '') {
            return ['ok' => false, 'error' => 'SKU boş'];
        }
        $title = $name !== '' ? $name : trim((string) ($row['title'] ?? ''));
        return self::remapSku($productId, $sku, $sku, $title);
    }

    /**
     * SKU değişiminde Bayt eşlemesini yeni koda taşır (eski map → yeni; yoksa stok açar).
     * @return array{ok:bool,error?:string,bayt_id?:int,created?:bool}
     */
    public static function remapSku(int $productId, string $oldSku, string $newSku, string $name = ''): array
    {
        $oldSku = trim($oldSku);
        $newSku = trim($newSku);
        if ($newSku === '') {
            return ['ok' => false, 'error' => 'yeni sku boş'];
        }
        if (!self::enabled()) {
            return ['ok' => true, 'skipped' => true];
        }
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return ['ok' => false, 'error' => $auth];
        }
        $unitFb = 1;
        $st = db()->prepare("SELECT meta_value FROM product_meta WHERE product_id=? AND meta_key='bayt_unit_id' LIMIT 1");
        $st->execute([$productId]);
        $u = (int) $st->fetchColumn();
        if ($u > 0) {
            $unitFb = $u;
        }
        $mapped = $oldSku !== '' ? self::loadBaytSkuMap($productId, $oldSku, $unitFb) : null;
        if (!$mapped) {
            $st = db()->prepare("SELECT meta_value FROM product_meta WHERE product_id=? AND meta_key='bayt_product_id' LIMIT 1");
            $st->execute([$productId]);
            $bid = (int) $st->fetchColumn();
            if ($bid > 0) {
                $mapped = ['id' => $bid, 'unit_id' => $unitFb];
            }
        }
        $created = false;
        if (!$mapped) {
            foreach (self::productCodeCandidates($newSku) as $code) {
                $hit = self::productByCode($code, $unitFb);
                if (is_array($hit)) {
                    $mapped = $hit;
                    break;
                }
            }
        }
        if (!$mapped) {
            foreach (self::productCodeCandidates($oldSku) as $code) {
                $hit = self::productByCode($code, $unitFb);
                if (is_array($hit)) {
                    $mapped = $hit;
                    break;
                }
            }
        }
        if (!$mapped) {
            $made = self::createProduct($newSku, $name !== '' ? $name : $newSku, $unitFb);
            if (is_string($made)) {
                return ['ok' => false, 'error' => $made];
            }
            $mapped = $made;
            $created = true;
        }
        if (!$created) {
            $newCode = self::baytStockCode($newSku);
            if ($newCode !== '') {
                $existing = self::productByCode($newCode, $unitFb);
                if (is_array($existing) && (int) $existing['id'] !== (int) $mapped['id']) {
                    $mapped = $existing;
                } else {
                    $ren = self::syncBaytProductCode((int) $mapped['id'], $newSku, $name);
                    if (is_string($ren)) {
                        return ['ok' => false, 'error' => $ren];
                    }
                }
            }
        }
        $baseSku = '';
        if ($productId > 0) {
            $st = db()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
            $st->execute([$productId]);
            $baseSku = trim((string) $st->fetchColumn());
        }
        self::persistBaytProductMap($productId, $newSku, $baseSku, (int) $mapped['id'], (int) $mapped['unit_id']);
        if ($oldSku !== '' && strcasecmp($oldSku, $newSku) !== 0) {
            // Eski anahtarı da aynı Bayt ID’ye bağla (geçmiş sipariş / diyagram).
            self::saveBaytSkuMap($productId, $oldSku, (int) $mapped['id'], (int) $mapped['unit_id']);
        }
        self::log(0, true, 'SKU remap: ' . $oldSku . ' → ' . $newSku . ' (Bayt #' . (int) $mapped['id'] . ')');
        return ['ok' => true, 'bayt_id' => (int) $mapped['id'], 'created' => $created];
    }

    /** @return array{id:int,unit_id:int,vat_rate:float}|string */
    private static function resolveProduct(int $productId, string $sku, string $name = ''): array|string
    {
        $s = self::settings();
        $unitFb = (int) $s['unit_id'];
        $sku = trim($sku);
        $baseSku = '';
        if ($productId > 0) {
            try {
                $st = db()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
                $st->execute([$productId]);
                $baseSku = trim((string) ($st->fetchColumn() ?: ''));
            } catch (Throwable) {
                $baseSku = '';
            }
        }
        $isVariantSku = $sku !== '' && $baseSku !== '' && strcasecmp($sku, $baseSku) !== 0;

        // Güncel katalog SKU’su (Bayt’ta yeni stok kodu); varyant / diyagram satırları hariç.
        if (!$isVariantSku && $baseSku !== '') {
            foreach (self::productCodeCandidates($baseSku) as $code) {
                $hit = self::productByCode($code, $unitFb);
                if (is_array($hit)) {
                    self::persistBaytProductMap($productId, $baseSku, $baseSku, $hit['id'], $hit['unit_id']);
                    if ($sku !== '' && strcasecmp($sku, $baseSku) !== 0) {
                        self::saveBaytSkuMap($productId, $sku, $hit['id'], $hit['unit_id']);
                    }
                    return self::withProductVat($hit);
                }
            }
            $byBase = self::loadBaytSkuMap($productId, $baseSku, $unitFb);
            if ($byBase !== null) {
                return self::withProductVat($byBase);
            }
        }

        // Varyant satırları: SKU bazlı harita (ana ürün bayt_product_id paylaşılmaz).
        if ($sku !== '') {
            $bySku = self::loadBaytSkuMap($productId, $sku, $unitFb);
            if ($bySku !== null) {
                return self::withProductVat($bySku);
            }
        }

        // Ana ürün eşlemesi yalnız parent SKU ile aynı satırlarda.
        if ($productId > 0 && !$isVariantSku) {
            $saved = (int) HfsDiagram::meta($productId, 'bayt_product_id', 0);
            if ($saved > 0) {
                $unit = (int) HfsDiagram::meta($productId, 'bayt_unit_id', $unitFb);
                return self::withProductVat([
                    'id' => $saved,
                    'unit_id' => $unit > 0 ? $unit : $unitFb,
                ]);
            }
        }
        if ($sku === '') {
            return 'SKU boş.';
        }
        $lastErr = 'Bayt stoğu bulunamadı (SKU ' . $sku . ').';
        foreach (self::productCodeCandidates($sku) as $code) {
            $hit = self::productByCode($code, $unitFb);
            if (is_array($hit)) {
                self::persistBaytProductMap($productId, $sku, $baseSku, $hit['id'], $hit['unit_id']);
                return self::withProductVat($hit);
            }
            if (is_string($hit) && $hit !== '') {
                $lastErr = $hit;
            }
        }
        $created = self::createProduct($sku, $name !== '' ? $name : $sku, $unitFb);
        if (is_array($created)) {
            self::persistBaytProductMap($productId, $sku, $baseSku, $created['id'], $created['unit_id']);
            self::log(0, true, 'Bayt stoğu açıldı: ' . $sku);
            return self::withProductVat($created);
        }
        return is_string($created) ? $created : $lastErr;
    }

    /**
     * @param array{id:int,unit_id:int,vat_rate?:float} $mapped
     * @return array{id:int,unit_id:int,vat_rate:float}
     */
    private static function withProductVat(array $mapped): array
    {
        $id = (int) ($mapped['id'] ?? 0);
        $unit = (int) ($mapped['unit_id'] ?? 0);
        if (array_key_exists('vat_rate', $mapped) && $mapped['vat_rate'] !== null && $mapped['vat_rate'] !== '') {
            return [
                'id' => $id,
                'unit_id' => $unit,
                'vat_rate' => self::normalizeVatRate($mapped['vat_rate']),
            ];
        }
        $vat = self::productVatById($id);
        return [
            'id' => $id,
            'unit_id' => $unit,
            'vat_rate' => $vat ?? 20.0,
        ];
    }

    private static function normalizeVatRate(mixed $v): float
    {
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        $n = (float) $v;
        if (!is_finite($n) || $n < 0) {
            return 0.0;
        }
        if ($n > 100) {
            return 100.0;
        }
        return round($n, 2);
    }

    /** @var array<int, float|null> */
    private static array $vatByProductId = [];

    private static function productVatById(int $baytProductId): ?float
    {
        if ($baytProductId < 1) {
            return null;
        }
        if (array_key_exists($baytProductId, self::$vatByProductId)) {
            return self::$vatByProductId[$baytProductId];
        }
        $res = self::api('PartnerApiProductOperations/GetProductById', ['Id' => $baytProductId]);
        if (is_string($res) || empty($res['Success']) || !is_array($res['Data'] ?? null)) {
            return self::$vatByProductId[$baytProductId] = null;
        }
        $data = $res['Data'];
        if (!array_key_exists('VatRate', $data) && !array_key_exists('RetailVatRate', $data)) {
            return self::$vatByProductId[$baytProductId] = null;
        }
        $raw = $data['VatRate'] ?? $data['RetailVatRate'];
        return self::$vatByProductId[$baytProductId] = self::normalizeVatRate($raw);
    }

    /** @return array{id:int,unit_id:int,vat_rate?:float}|null */
    private static function loadBaytSkuMap(int $productId, string $sku, int $unitFb): ?array
    {
        $key = self::baytSkuMetaKey($sku);
        if ($productId > 0) {
            $saved = (int) HfsDiagram::meta($productId, $key, 0);
            if ($saved > 0) {
                $unit = (int) HfsDiagram::meta($productId, $key . '_u', $unitFb);
                return ['id' => $saved, 'unit_id' => $unit > 0 ? $unit : $unitFb];
            }
        }
        // Global SKU haritası (ürün ID’siz tekrar kullanım).
        try {
            $raw = (string) setting('bayt_sku_' . substr(hash('sha256', strtoupper($sku)), 0, 20), '');
            if ($raw !== '') {
                $j = json_decode($raw, true);
                if (is_array($j) && (int) ($j['id'] ?? 0) > 0) {
                    $out = [
                        'id' => (int) $j['id'],
                        'unit_id' => (int) ($j['unit_id'] ?? $unitFb) ?: $unitFb,
                    ];
                    if (array_key_exists('vat_rate', $j)) {
                        $out['vat_rate'] = self::normalizeVatRate($j['vat_rate']);
                    }
                    return $out;
                }
            }
        } catch (Throwable) {
        }
        return null;
    }

    private static function baytSkuMetaKey(string $sku): string
    {
        return 'bayt_sku_' . substr(hash('sha256', strtoupper(trim($sku))), 0, 20);
    }

    private static function persistBaytProductMap(int $productId, string $sku, string $baseSku, int $id, int $unit): void
    {
        $sku = trim($sku);
        $isVariantSku = $sku !== '' && $baseSku !== '' && strcasecmp($sku, $baseSku) !== 0;
        if ($sku !== '') {
            self::saveBaytSkuMap($productId, $sku, $id, $unit);
        }
        if ($productId > 0 && !$isVariantSku) {
            self::saveProductMap($productId, $id, $unit);
        }
    }

    private static function saveBaytSkuMap(int $productId, string $sku, int $id, int $unit): void
    {
        $key = self::baytSkuMetaKey($sku);
        if ($productId > 0) {
            try {
                db()->prepare("INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
                    ->execute([$productId, $key, (string) $id]);
                db()->prepare("INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
                    ->execute([$productId, $key . '_u', (string) $unit]);
            } catch (Throwable) {
            }
        }
        try {
            setting_set('bayt_sku_' . substr(hash('sha256', strtoupper(trim($sku))), 0, 20), json_encode([
                'id' => $id,
                'unit_id' => $unit,
                'sku' => trim($sku),
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
        }
    }

    /** @return list<string> */
    private static function productCodeCandidates(string $sku): array
    {
        $sku = trim($sku);
        $out = [];
        $add = static function (string $c) use (&$out): void {
            $c = trim($c);
            if ($c === '') {
                return;
            }
            $c = self::clipField($c, 15, false);
            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        };
        // Önce ayırt edici kod (varyant soneki korunur).
        $add(self::baytStockCode($sku));
        $add($sku);
        if (str_starts_with(strtoupper($sku), 'HFS-')) {
            $add(substr($sku, 4));
        }
        $parts = explode('-', $sku);
        $n = count($parts);
        if ($n >= 2) {
            $add($parts[$n - 2] . '-' . $parts[$n - 1]);
        }
        return $out;
    }

    /** Bayt stok kodu max 15: ürün kodu + varyant (örn. HFS-SA-V3-58-ka). */
    private static function baytStockCode(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return '';
        }
        // Tam SKU (HFS-SA-V3-58-kawasaki) — Bayt 15 karaktere kadar, baştan kes.
        return self::clipField($sku, 15, false);
    }

    /** @return array{id:int,unit_id:int,vat_rate:float}|string|null */
    private static function productByCode(string $code, int $unitFb): array|string|null
    {
        $code = self::clipField($code, 15, false);
        if ($code === '') {
            return null;
        }
        $res = self::api('PartnerApiProductOperations/GetProductByCode', ['Code' => $code]);
        if (is_string($res)) {
            return $res;
        }
        return self::productFromResponse($res, $unitFb);
    }

    /** @param array<string,mixed> $res @return array{id:int,unit_id:int,vat_rate:float}|null */
    private static function productFromResponse(array $res, int $unitFb): ?array
    {
        $nodes = [];
        $data = $res['Data'] ?? null;
        if (is_array($data)) {
            $nodes[] = $data;
            foreach ($data as $row) {
                if (is_array($row)) {
                    $nodes[] = $row;
                }
            }
            foreach (['Items', 'Products', 'List', 'Data'] as $k) {
                if (isset($data[$k]) && is_array($data[$k])) {
                    foreach ($data[$k] as $row) {
                        if (is_array($row)) {
                            $nodes[] = $row;
                        }
                    }
                }
            }
        }
        foreach ($nodes as $node) {
            $id = (int) ($node['Id'] ?? $node['ProductId'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $unit = $unitFb;
            if (!empty($node['UnitId'])) {
                $unit = (int) $node['UnitId'];
            } elseif (!empty($node['Units'][0]['Id'])) {
                $unit = (int) $node['Units'][0]['Id'];
            }
            $vat = 20.0;
            if (array_key_exists('VatRate', $node) || array_key_exists('RetailVatRate', $node)) {
                $vat = self::normalizeVatRate($node['VatRate'] ?? $node['RetailVatRate']);
            }
            self::$vatByProductId[$id] = $vat;
            return [
                'id' => $id,
                'unit_id' => $unit > 0 ? $unit : $unitFb,
                'vat_rate' => $vat,
            ];
        }
        return null;
    }

    /** Bayt stok kartı kodunu B2B SKU ile hizalar (UpdateProduct). */
    private static function syncBaytProductCode(int $baytId, string $sku, string $name = ''): ?string
    {
        if ($baytId < 1) {
            return 'Bayt stok ID yok.';
        }
        $newCode = self::baytStockCode($sku);
        if ($newCode === '') {
            return null;
        }
        $res = self::api('PartnerApiProductOperations/GetProductById', ['Id' => $baytId]);
        if (is_string($res)) {
            return $res;
        }
        if (empty($res['Success']) || !is_array($res['Data'] ?? null)) {
            return self::apiMessage(is_array($res) ? $res : [], 'Bayt stok okunamadı.');
        }
        $data = $res['Data'];
        $cur = self::clipField((string) ($data['Code'] ?? ''), 15, false);
        if (strcasecmp($cur, $newCode) === 0) {
            return null;
        }
        $vat = self::normalizeVatRate($data['VatRate'] ?? $data['RetailVatRate'] ?? 20);
        $payload = [
            'Id' => $baytId,
            'Code' => $newCode,
            'Name' => self::clipField($name !== '' ? $name : (string) ($data['Name'] ?? $newCode), 80, false),
            'VatRate' => $vat,
            'RetailVatRate' => $vat,
            'CardType' => (string) ($data['CardType'] ?? 'Ticari Mal'),
            'Unit1' => (string) ($data['Unit1'] ?? 'Adet'),
        ];
        $up = self::api('PartnerApiProductOperations/UpdateProduct', $payload);
        if (is_string($up)) {
            return $up;
        }
        if (empty($up['Success'])) {
            return self::apiMessage($up, 'Bayt stok kodu güncellenemedi (' . $newCode . ').');
        }
        self::log(0, true, 'Bayt stok kodu: ' . ($cur !== '' ? $cur : '?') . ' → ' . $newCode . ' (#' . $baytId . ')');
        return null;
    }

    /** @return array{id:int,unit_id:int,vat_rate:float}|string */
    private static function createProduct(string $sku, string $name, int $unitFb): array|string
    {
        $code = self::baytStockCode($sku);
        if ($code === '') {
            $code = self::productCodeCandidates($sku)[0] ?? $sku;
        }
        $payload = [
            'Code' => self::clipField($code, 15, false),
            'Name' => self::clipField($name !== '' ? $name : $code, 80, false),
            'VatRate' => 20,
            'RetailVatRate' => 20,
            'CardType' => 'Ticari Mal',
            'Unit1' => 'Adet',
        ];
        $res = self::api('PartnerApiProductOperations/CreateProduct', $payload);
        if (is_string($res)) {
            return $res;
        }
        if (empty($res['Success'])) {
            return self::apiMessage($res, 'Bayt stoğu oluşturulamadı (SKU ' . $sku . ').');
        }
        $id = (int) ($res['Data']['Id'] ?? $res['Id'] ?? 0);
        if ($id < 1) {
            $again = self::productByCode($code, $unitFb);
            if (is_array($again)) {
                return $again;
            }
            return 'Bayt stoğu oluştu ama ID alınamadı (SKU ' . $sku . ').';
        }
        $unit = $unitFb;
        if (!empty($res['Data']['UnitId'])) {
            $unit = (int) $res['Data']['UnitId'];
        } elseif (!empty($res['Data']['Units'][0]['Id'])) {
            $unit = (int) $res['Data']['Units'][0]['Id'];
        }
        self::$vatByProductId[$id] = 20.0;
        return ['id' => $id, 'unit_id' => $unit > 0 ? $unit : $unitFb, 'vat_rate' => 20.0];
    }

    private static function saveProductMap(int $productId, int $id, int $unit): void
    {
        if ($productId < 1 || $id < 1) {
            return;
        }
        db()->prepare("INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([$productId, 'bayt_product_id', (string) $id]);
        db()->prepare("INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([$productId, 'bayt_unit_id', (string) $unit]);
    }

    private static function orderRef(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return 'HFS';
        }
        return str_starts_with($code, 'HFS-') ? $code : ('HFS-' . $code);
    }

    /** @param array<string,mixed> $order */
    private static function waybillDisplayNo(array $order, string $code): string
    {
        $ref = trim((string) ($order['bayt_ref'] ?? ''));
        if ($ref !== '' && preg_match('/^IRS/i', $ref)) {
            return $ref;
        }
        return self::orderRef($code);
    }

    /** Satış siparişi: B2B kodu; irsaliye: Bayt IRS… serisi (STK_FIS sıradaki no). */
    private static function referenceNoForPayload(string $kind, string $b2bRef): string
    {
        if ($kind === 'waybill') {
            $irs = self::allocateNextIrsFisNo();
            return $irs !== '' ? $irs : $b2bRef;
        }
        return $b2bRef;
    }

    /** Partner CreateWaybill ReferenceNo = fiş no; HFS kodu Notes’ta kalır. */
    private static function allocateNextIrsFisNo(): string
    {
        $auth = self::ensureSession();
        if (is_string($auth)) {
            return '';
        }
        $res = self::api('PartnerApiHelperOperations/ExecuteQuery', [
            'SqlScript' => "SELECT TOP 1 FISNO FROM STK_FIS WHERE FISNO LIKE 'IRS%' ORDER BY ID DESC;",
        ], 30);
        $row = self::parseExecuteQueryRow($res);
        $last = trim((string) ($row['FISNO'] ?? ''));
        $n = 0;
        if (preg_match('/^IRS(\d+)$/i', $last, $m)) {
            $n = (int) $m[1];
        }
        $next = $n + 1;
        return 'IRS' . str_pad((string) $next, 13, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $createResponse */
    private static function resolveWaybillDocumentRef(array $createResponse, int $waybillId, string $fallback): string
    {
        $data = is_array($createResponse['Data'] ?? null) ? $createResponse['Data'] : $createResponse;
        $ref = trim((string) ($data['ReferenceNo'] ?? ''));
        if ($ref !== '') {
            return $ref;
        }
        if ($waybillId > 0) {
            $res = self::api('PartnerApiWaybillOperations/GetWaybillById', ['Id' => $waybillId, 'WaybillType' => 1], 30);
            if (is_array($res) && !empty($res['Success'])) {
                $d = is_array($res['Data'] ?? null) ? $res['Data'] : $res;
                $ref = trim((string) ($d['ReferenceNo'] ?? ''));
                if ($ref !== '') {
                    return $ref;
                }
            }
            $row = self::readStkFisRow($waybillId);
            $fis = trim((string) ($row['FISNO'] ?? ''));
            if ($fis !== '') {
                return $fis;
            }
        }
        return $fallback;
    }

    private static function markSent(array $order, string $ref, int $id, string $message): true
    {
        db()->prepare("UPDATE orders SET bayt_status='sent', bayt_ref=?, bayt_id=?, bayt_doc_type=?, bayt_sent_at=NOW(), bayt_error=NULL, bayt_approval='pending' WHERE id=?")
            ->execute([$ref, $id, 'waybill', (int) $order['id']]);
        self::log((int) $order['id'], true, $message);
        return true;
    }

    private static function fail(array $order, string $message): string
    {
        db()->prepare("UPDATE orders SET bayt_status='error', bayt_error=? WHERE id=?")->execute([$message, (int) $order['id']]);
        self::log((int) $order['id'], false, $message);
        return $message;
    }

    private static function log(int $orderId, bool $ok, string $message): void
    {
        db()->prepare('INSERT INTO bayt_log (order_id,ok,message,created_at) VALUES (?,?,?,NOW())')
            ->execute([$orderId, $ok ? 1 : 0, mb_substr($message, 0, 400)]);
        $code = '';
        if ($orderId > 0) {
            try {
                $st = db()->prepare('SELECT code FROM orders WHERE id=? LIMIT 1');
                $st->execute([$orderId]);
                $code = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable) {
            }
        }
        HfsIntegrLog::write('bayt', $ok ? 'ok' : 'hata', $message, $ok, $orderId, $code);
    }

    /** @param array<string,mixed> $res */
    private static function apiMessage(array $res, string $fallback): string
    {
        foreach (['Message', 'message', 'ErrorMessage', 'ExceptionMessage', 'StatusMessage', 'Error', 'error', 'Detail', 'Exception'] as $k) {
            if (!empty($res[$k]) && is_scalar($res[$k])) {
                $msg = (string) $res[$k];
                if (stripos($msg, 'license') !== false) {
                    return 'Bayt Partner API bu modül için lisanslı değil (cari oluşturma). Cariyi Bayt’ta açıp bayi kartına kodu yazın, veya NLC’den cari modülünü açtırın. (' . $msg . ')';
                }
                return $msg;
            }
        }
        if (isset($res['error']) && is_array($res['error']) && !empty($res['error']['message']) && is_scalar($res['error']['message'])) {
            return (string) $res['error']['message'];
        }
        $bits = [];
        foreach (['Success', 'Data'] as $k) {
            if (array_key_exists($k, $res)) {
                $bits[] = $k . '=' . (is_scalar($res[$k]) ? (string) $res[$k] : json_encode($res[$k], JSON_UNESCAPED_UNICODE));
            }
        }
        if ($bits !== []) {
            return $fallback . ' (' . mb_substr(implode('; ', $bits), 0, 220) . ')';
        }
        return $fallback;
    }

    /** @return list<array<string,mixed>> */
    public static function logRows(int $n = 40): array
    {
        return db()->query('SELECT * FROM bayt_log ORDER BY id DESC LIMIT ' . (int) $n)->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function pendingCompleted(): array
    {
        return db()->query("SELECT * FROM orders WHERE is_cari=0 AND status IN ('yeni','bekliyor','hazirlaniyor','kargoda','tamam') AND (IFNULL(bayt_status,'') NOT IN ('sent','deleted') OR (bayt_status='sent' AND IFNULL(bayt_doc_type,'')<>'waybill') OR (bayt_status='sent' AND IFNULL(bayt_order_id,0)<1)) ORDER BY id DESC LIMIT 40")->fetchAll();
    }

    /**
     * Canlı/yerel Bayt denemesi: sipariş oluşturur ve gönderir.
     *
     * @return array<string,mixed>
     */
    public static function createTestOrderForBayt(string $username = 'bayi1'): array
    {
        $st = db()->prepare("SELECT * FROM users WHERE username=? AND role='dealer' LIMIT 1");
        $st->execute([$username]);
        $dealer = $st->fetch();
        if (!$dealer) {
            $dealer = db()->query(
                "SELECT * FROM users WHERE role='dealer' AND bayt_customer_id>0 ORDER BY id ASC LIMIT 1"
            )->fetch();
        }
        if (!$dealer || (int) ($dealer['bayt_customer_id'] ?? 0) < 1) {
            return ['ok' => false, 'message' => 'Bayi veya Bayt cari ID yok.'];
        }
        $product = db()->query(
            "SELECT p.* FROM products p
             INNER JOIN product_meta m ON m.product_id=p.id AND m.meta_key='bayt_product_id'
             WHERE p.sku<>'' LIMIT 1"
        )->fetch();
        if (!$product) {
            $product = db()->query("SELECT * FROM products WHERE sku<>'' AND price>0 ORDER BY id ASC LIMIT 1")->fetch();
        }
        if (!$product) {
            return ['ok' => false, 'message' => 'Ürün bulunamadı.'];
        }
        $qty = 1;
        $line = (float) $product['price'] * $qty;
        $cart = [
            'lines' => [[
                'product' => $product,
                'variant' => null,
                'qty' => $qty,
                'price' => (float) $product['price'],
                'line' => $line,
                'title' => (string) $product['title'],
                'sku' => (string) $product['sku'],
                'note' => '',
            ]],
            'subtotal' => $line,
            'discount' => 0,
            'shipping' => 0,
            'total' => $line,
        ];
        $name = trim((string) ($dealer['company'] ?: $dealer['display_name'] ?: $dealer['username']));
        $form = [
            'name' => $name,
            'email' => (string) ($dealer['email'] ?: 'siparis@hfstarim.com'),
            'phone' => (string) ($dealer['phone'] ?: '05000000000'),
            'city' => (string) ($dealer['city'] ?: 'Bursa'),
            'district' => (string) ($dealer['district'] ?: 'Nilüfer'),
            'address' => (string) ($dealer['address'] ?: 'Test adresi'),
            'note' => 'Canlı Bayt test ' . date('Y-m-d H:i'),
            'country' => (string) ($dealer['country'] ?: 'TR'),
            'ship_slot' => '',
            'coupon_code' => '',
            'channel' => 'panel',
            'payment' => 'havale',
        ];
        $code = Catalog::placeOrder($cart, $form);
        $order = Catalog::orderByCode($code);
        if (!$order) {
            return ['ok' => false, 'message' => 'Sipariş oluşturulamadı.'];
        }
        $oid = (int) $order['id'];
        db()->prepare('UPDATE orders SET user_id=?, company_id=?, status=? WHERE id=?')
            ->execute([(int) $dealer['id'], (int) $dealer['id'], 'bekliyor', $oid]);
        $o1 = self::sendOrderOnly($oid, true);
        if ($o1 !== true) {
            return ['ok' => false, 'message' => (string) $o1];
        }
        $w1 = self::sendWaybillOnly($oid, true);
        $probe = self::probeEdiForOrder($oid);
        $fresh = Catalog::orderByCode($code) ?: $order;
        $preview = self::eirsaliyePreview($fresh);
        return [
            'ok' => $w1 === true,
            'message' => $w1 === true ? 'Sipariş + irsaliye; e-İrsaliye API denendi.' : (string) $w1,
            'edi_probe' => $probe,
            'code' => $code,
            'dealer' => $username,
            'bayt_order_id' => (int) ($fresh['bayt_order_id'] ?? 0),
            'bayt_id' => (int) ($fresh['bayt_id'] ?? 0),
            'bayt_status' => (string) ($fresh['bayt_status'] ?? ''),
            'urls' => [
                'slip' => site_url('yonetim/siparisler/' . $code . '/irsaliye/'),
                'eirsaliye' => site_url('yonetim/siparisler/' . $code . '/e-irsaliye/'),
                'admin_order' => site_url('yonetim/siparisler/' . $code . '/'),
            ],
            'eirsaliye_preview' => $preview,
        ];
    }

    /**
     * e-İrsaliye alan özeti (GİB UBL değil; B2B ön izleme).
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public static function eirsaliyePreview(array $order): array
    {
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([(int) ($order['id'] ?? 0)]);
        $order['items'] = $it->fetchAll();
        $contact = self::orderContactForPayload($order);
        $uid = (int) ($order['company_id'] ?? 0);
        if ($uid < 1) {
            $uid = (int) ($order['user_id'] ?? 0);
        }
        $du = $uid > 0 ? (HfsDealers::get($uid) ?: null) : null;
        if ($du) {
            $cid = HfsPricing::companyId($du);
            if ($cid > 0 && $cid !== $uid) {
                $du = HfsDealers::get($cid) ?: $du;
            }
        }
        $lines = [];
        foreach ($order['items'] as $row) {
            if ((string) ($row['sku'] ?? '') === 'CARI') {
                continue;
            }
            $lines[] = [
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['title'] ?? ''),
                'qty' => (float) ($row['qty'] ?? 0),
                'unit' => 'Adet',
            ];
        }
        $code = (string) ($order['code'] ?? '');
        $sent = !empty($order['bayt_sent_at']) ? date('d.m.Y H:i', strtotime((string) $order['bayt_sent_at'])) : '';
        $created = !empty($order['created_at']) ? date('d.m.Y', strtotime((string) $order['created_at'])) : date('d.m.Y');
        $gibReady = (int) ($order['bayt_id'] ?? 0) > 0 && (string) ($order['bayt_status'] ?? '') === 'sent';
        return [
            'disclaimer' => 'Bu ekran yalnızca ön izlemedir. Resmi e-İrsaliye (GİB UBL-TR) Bayt BYTE üzerinden kesilir; B2B XML üretmez.',
            'gib_document_exists' => false,
            'bayt_waybill_ready' => $gibReady,
            'uuid_preview' => strtoupper(substr(hash('sha256', 'eirsaliye-' . $code), 0, 8) . '-' . substr(hash('sha256', $code), 8, 4) . '-4' . substr(hash('sha256', $code), 13, 3) . '-8' . substr(hash('sha256', $code), 16, 3) . '-' . substr(hash('sha256', $code), 19, 12)),
            'despatch_number' => self::waybillDisplayNo($order, $code),
            'despatch_date' => $created,
            'supplier' => [
                'name' => (string) setting('site_name', 'HFS Ziraat Makinaları'),
                'address' => 'Altınova Mah. Askı Sok. No: 10, Osmangazi / Bursa',
                'vkn' => (string) setting('company_tax_number', ''),
                'tax_office' => (string) setting('company_tax_office', 'Osmangazi'),
            ],
            'buyer' => [
                'name' => trim((string) ($du['company'] ?? $order['name'] ?? '')),
                'vkn_tckn' => preg_replace('/\D+/', '', (string) ($du['tax_number'] ?? '')) ?: '',
                'tax_office' => (string) ($du['tax_office'] ?? ''),
                'bayt_code' => (string) ($du['bayt_customer_code'] ?? ''),
            ],
            'delivery' => [
                'address' => $contact['address1'],
                'district' => $contact['district'],
                'city' => $contact['city'],
                'postcode' => $contact['postcode'],
                'country' => $contact['country_label'],
            ],
            'lines' => $lines,
            'bayt' => [
                'ref' => (string) ($order['bayt_ref'] ?? self::orderRef($code)),
                'waybill_id' => (int) ($order['bayt_id'] ?? 0),
                'order_id' => (int) ($order['bayt_order_id'] ?? 0),
                'status' => (string) ($order['bayt_status'] ?? ''),
                'approval' => (string) ($order['bayt_approval'] ?? ''),
                'sent_at' => $sent,
            ],
        ];
    }
}
