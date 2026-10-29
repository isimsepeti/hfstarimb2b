<?php
declare(strict_types=1);

final class HfsPay
{
    private const API = 'https://ws.buluttahsilat.com/Api/Vpos/PaymentCreateCommonPage';
    private const INFO = 'https://ws.buluttahsilat.com/Api/Vpos/PaymentInfo';
    private const CANCEL = 'https://ws.buluttahsilat.com/Api/Vpos/PaymentCancel';

    public static function checkoutPost(): void
    {
        if (!csrf_ok() || trim((string) ($_POST['website'] ?? '')) !== '') {
            redirect(site_url('odeme/') . '?hata=eksik');
        }
        $u = current_user();
        if (!$u) {
            redirect(site_url('giris/'));
        }
        $country = strtoupper(trim((string) ($_POST['country'] ?? 'TR')));
        if (!isset(Shipping::countries()[$country])) {
            $country = 'TR';
        }
        $city = trim((string) ($_POST['city'] ?? (string) ($u['city'] ?? '')));
        $cart = Cart::hydrated($country, $city);
        if ($cart['lines'] === []) {
            redirect(site_url('sepet/'));
        }
        $consentErr = HfsLegal::consentErrorFromPost($_POST);
        if ($consentErr !== null) {
            redirect(site_url('odeme/') . '?hata=' . rawurlencode($consentErr));
        }
        $mins = HfsFinance::minimumErrors($cart);
        if ($mins) {
            redirect(site_url('odeme/') . '?hata=' . rawurlencode($mins[0]));
        }
        $pay = (string) ($_POST['payment'] ?? 'havale');
        if (!in_array($pay, ['havale', 'acik_hesap', 'bulut'], true)) {
            $pay = 'havale';
        }
        if ($pay === 'bulut' && !self::cardEnabled()) {
            $pay = 'havale';
        }
        $gate = HfsFinance::checkoutGate((float) $cart['total'], $u);
        if (!$gate['ok']) {
            redirect(site_url('odeme/') . '?hata=' . rawurlencode($gate['msg']));
        }
        if ($pay === 'acik_hesap') {
            $ok = HfsFinance::canOpenAccount((float) $cart['total'], $u);
            if (!$ok['ok']) {
                redirect(site_url('odeme/') . '?hata=' . rawurlencode($ok['msg']));
            }
        }
        $district = trim((string) ($_POST['district'] ?? (string) ($u['district'] ?? '')));
        $postcode = preg_replace('/\D+/', '', (string) ($_POST['postcode'] ?? (string) ($u['postcode'] ?? ''))) ?: '';
        if ($country === 'TR' && $city !== '' && $district !== '') {
            $loc = HfsTrAddress::resolve($city, $district);
            if ($loc) {
                $city = (string) ($loc['city'] ?? $city);
                $district = (string) ($loc['district'] ?? $district);
                if ($postcode === '' || strlen($postcode) !== 5) {
                    $postcode = (string) ($loc['postcode'] ?? $postcode);
                }
            }
        }
        $form = [
            'name' => trim((string) ($_POST['name'] ?? $u['display_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? $u['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? $u['phone'] ?? '')),
            'city' => $city,
            'district' => $district,
            'postcode' => $postcode,
            'address' => trim((string) ($_POST['address'] ?? $u['address'] ?? '')),
            'note' => trim((string) ($_POST['note'] ?? '')),
            'country' => $country,
            'ship_slot' => trim((string) ($_POST['ship_slot'] ?? '')),
            'coupon_code' => $cart['coupon_code'],
            'channel' => 'bayi',
            'payment' => $pay,
            'po_number' => trim((string) ($_POST['po_number'] ?? '')),
        ];
        if ($form['name'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL) || $form['phone'] === '' || $form['city'] === '' || $form['address'] === '') {
            redirect(site_url('odeme/') . '?hata=eksik');
        }
        if ($country === 'TR' && !preg_match('/^[0-9]{5}$/', $postcode)) {
            redirect(site_url('odeme/') . '?hata=' . rawurlencode('Geçerli il / ilçe seçin; 5 haneli posta kodu gerekli.'));
        }
        $status = $pay === 'acik_hesap' ? 'bekliyor' : ($pay === 'bulut' ? 'odeme' : 'yeni');
        $code = Catalog::placeOrder($cart, $form);
        HfsLegal::stampOrderByCode($code);
        self::tagOrder($code, $u, $status, 0);
        $po = trim((string) ($form['po_number'] ?? ''));
        if ($po !== '') {
            db()->prepare('UPDATE orders SET po_number=? WHERE code=?')->execute([mb_substr($po, 0, 80), $code]);
        }
        HfsApproval::maybeHold($code);
        $placed = Catalog::orderByCode($code);
        if ($placed) {
            HfsMail::orderPlaced($placed, $pay === 'bulut');
            if ($pay !== 'bulut') {
                HfsBayt::onConfirmed($placed);
            }
        }
        Cart::clear();
        Abandoned::recover();
        if ($pay === 'bulut') {
            $url = self::startHosted($code);
            if ($url) {
                redirect($url, 303);
            }
            redirect(site_url('siparis/' . $code . '/') . '?hata=odeme');
        }
        redirect(site_url('siparis/' . $code . '/'));
    }

    public static function cariPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url('cari-odeme/') . '?hata=1');
        }
        $u = current_user();
        if (!$u) {
            redirect(site_url('giris/'));
        }
        if (!self::cariEnabled()) {
            redirect(site_url('cari-odeme/') . '?hata=kapali');
        }
        $consentErr = HfsLegal::consentErrorFromPost($_POST);
        if ($consentErr !== null) {
            redirect(site_url('cari-odeme/') . '?hata=onay');
        }
        $amount = self::parseAmount((string) ($_POST['amount'] ?? '0'));
        if ($amount < 1 || $amount > 10000000) {
            redirect(site_url('cari-odeme/') . '?hata=tutar');
        }
        $snap = HfsFinance::snapshot($u);
        // Aynı tutarda açık “bekliyor” cari kaydı varsa yenisini açma (çift tıklama / limit spam)
        $code = '';
        try {
            $stReuse = db()->prepare(
                "SELECT code FROM orders WHERE user_id=? AND is_cari=1 AND status='odeme'
                 AND ABS(total-?) < 0.05 AND payment_status IN ('bekliyor','')
                 AND created_at > DATE_SUB(NOW(), INTERVAL 20 MINUTE)
                 ORDER BY id DESC LIMIT 1"
            );
            $stReuse->execute([(int) ($u['id'] ?? 0), $amount]);
            $code = trim((string) $stReuse->fetchColumn());
        } catch (Throwable) {
            $code = '';
        }
        if ($code === '') {
            $code = self::placeCariOrder($u, $snap, $amount);
        }
        HfsLegal::stampOrderByCode($code);
        $url = self::startHosted($code);
        if ($url) {
            redirect($url, 303);
        }
        redirect(site_url('cari-odeme/') . '?hata=odeme');
    }

    public static function handleReturn(): void
    {
        header('Cache-Control: private, no-store');
        $payload = self::returnPayload();
        self::logBt('return', [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'keys' => array_keys($payload),
            'get' => array_keys($_GET),
            'post' => array_keys($_POST),
            'status' => self::payloadStr($payload, 'PaymentStatusCode', 'StatusCode'),
            'msg' => self::payloadStr($payload, 'PaymentStatusText', 'StatusMessage'),
            'bank' => mb_substr(self::payloadStr($payload, 'BankMessage', 'TransactionStatusMessage'), 0, 120),
            'bank_code' => self::payloadStr($payload, 'BankStatusCode'),
            'pos' => self::payloadStr($payload, 'VPosBankName', 'PosBankName'),
            'sparse' => self::payloadStr($payload, 'BankMessage', 'AuthCode', 'PaymentID') === '' ? 1 : 0,
        ]);
        $order = self::orderFromPayload($payload);
        $guid = self::payloadStr($payload, 'OrderGuidCode', 'orderGuidCode');
        if (!$order && $guid !== '') {
            $st = db()->prepare('SELECT * FROM orders WHERE bt_guid=? LIMIT 1');
            $st->execute([$guid]);
            $order = self::hydrateOrder($st->fetch() ?: null);
        }
        if (!$order) {
            $order = self::orderFromReturnQuery();
        }
        if (!$order) {
            self::flashError('Ödeme sonucu alınamadı. Cari bakiyenizi ekstreden kontrol edin veya tekrar deneyin.');
            redirect(site_url('cari-odeme/') . '?hata=donus');
        }
        $hash = self::payloadStr($payload, 'Hash');
        $skipHash = false;
        if ($guid === '') {
            $guid = trim((string) ($order['bt_guid'] ?? ''));
        }
        if ($guid !== '') {
            $info = self::paymentInfo($guid);
            if (is_array($info) && $info !== [] && self::isApiQuotaError($info)) {
                self::logBt('paymentinfo_quota', [
                    'ok' => false,
                    'order' => (int) ($order['id'] ?? 0),
                    'code' => (string) ($order['code'] ?? ''),
                    'msg' => self::payloadStr($info, 'StatusMessage', 'PaymentStatusMessage'),
                ]);
                // Kotayı dönüş payload’ının üstüne yazma — banka cevabı bozulmasın
                $info = null;
            }
            if ($info) {
                // PaymentInfo boş alanlarla dönüşteki banka metnini ezmesin
                $payload = self::mergePreferNonEmpty($payload, $info);
                $skipHash = true;
            } elseif (self::payloadStr($payload, 'StatusCode', 'PaymentStatusCode') === '') {
                self::logBt('odeme_dogrulama_yok', [
                    'ok' => false,
                    'order' => (int) ($order['id'] ?? 0),
                    'code' => (string) ($order['code'] ?? ''),
                    'msg' => 'PaymentInfo yok',
                ]);
                self::flashError('Bulut ödeme doğrulaması alınamadı (PaymentInfo).');
                redirect(site_url((int) ($order['is_cari'] ?? 0) === 1 ? 'cari-odeme/' : ('siparis/' . $order['code'] . '/')) . '?hata=odeme');
            }
        }
        $outcome = self::paymentOutcome($payload);
        $status = $outcome['code'];
        $msg = $outcome['msg'];
        if (!$skipHash && $hash !== '') {
            if (!self::returnHashOk($order, $payload, $hash)) {
                $bankOk = self::isPaymentSuccess($status, $msg, $payload)
                    && self::payloadStr($payload, 'PaymentID', 'AuthCode', 'BankTransactionID') !== '';
                $amtOk = self::verifyBulutAmount($order, $payload)['ok'] ?? false;
                if ($bankOk && $amtOk) {
                    self::logBt('odeme_imza_bypass', [
                        'ok' => true,
                        'order' => (int) ($order['id'] ?? 0),
                        'code' => (string) ($order['code'] ?? ''),
                        'status' => self::payloadStr($payload, 'StatusCode'),
                        'msg' => self::payloadStr($payload, 'StatusMessage', 'PaymentStatusMessage'),
                        'outcome_status' => $status,
                        'outcome_msg' => $msg,
                        'reason' => 'banka onayı + tutar; Hash eşleşmedi (PaymentInfo kota / boş StatusCode)',
                    ]);
                } else {
                    self::logBt('odeme_imza_hata', [
                        'ok' => false,
                        'order' => (int) ($order['id'] ?? 0),
                        'code' => (string) ($order['code'] ?? ''),
                        'status' => self::payloadStr($payload, 'StatusCode'),
                        'msg' => self::payloadStr($payload, 'StatusMessage', 'PaymentStatusMessage'),
                        'outcome_status' => $status,
                        'outcome_msg' => $msg,
                    ]);
                    self::flashError('Ödeme imzası doğrulanamadı.');
                    if ((int) ($order['is_cari'] ?? 0) === 1) {
                        redirect(site_url('cari-odeme/') . '?hata=imza');
                    }
                    redirect(site_url('siparis/' . $order['code'] . '/') . '?hata=odeme');
                }
            }
        }
        $ok = self::isPaymentSuccess($status, $msg, $payload);
        if ($ok) {
            $verify = self::verifyBulutAmount($order, $payload);
            if (!$verify['ok']) {
                $ok = false;
                $msg = $verify['msg'];
            }
        }
        $alreadyPaid = ($order['payment_status'] ?? '') === 'odendi';
        if ($ok) {
            self::applySuccessfulPayment($order, $payload, $status, $msg);
            Cart::clear();
        } elseif ($alreadyPaid) {
            if (self::looksCancelled($status, $msg) || self::payloadIndicatesCancel($payload)) {
                Catalog::cancelOrder($order, trim($status . ' ' . $msg) !== '' ? ('BulutTahsilat ' . trim($status . ' ' . $msg)) : 'BulutTahsilat iptal', true, false, true);
            } else {
                self::syncOrderFromBulut((int) $order['id']);
            }
        } elseif (!$alreadyPaid) {
            $detail = self::markPaymentFailed((int) $order['id'], $payload, $status, $msg);
            self::logBt('odeme_alinamadi', [
                'ok' => false,
                'order' => (int) ($order['id'] ?? 0),
                'code' => (string) ($order['code'] ?? ''),
                'status' => $status,
                'msg' => $msg,
                'pay_text' => $outcome['pay_text'],
                'detail' => $detail,
                'label' => $detail,
                'is_cari' => (int) ($order['is_cari'] ?? 0),
                'total' => (float) ($order['total'] ?? 0),
                'b2b_status' => (string) ($order['status'] ?? ''),
            ]);
        }
        if ((int) $order['is_cari'] === 1) {
            if (!$ok) {
                $flash = self::formatPaymentFailure($payload, $status, $msg);
                self::flashError($flash !== '' ? $flash : 'Ödeme tamamlanmadı veya iptal edildi.');
            }
            redirect(site_url('cari-odeme/') . ($ok ? '?ok=1' : '?hata=iptal'));
        }
        redirect(site_url('siparis/' . $order['code'] . '/') . ($ok ? '' : '?hata=odeme'));
    }

    public static function configured(): bool
    {
        return trim((string) setting('bt_username', '')) !== ''
            && trim((string) setting('bt_password', '')) !== ''
            && trim((string) setting('bt_firm', '')) !== ''
            && trim((string) setting('bt_secret', '')) !== '';
    }

    public static function cardEnabled(): bool
    {
        return self::configured() && self::flag('bt_enabled', true);
    }

    public static function cariEnabled(): bool
    {
        return self::cardEnabled() && self::flag('bt_cari', true);
    }

    public static function amount(float $n): string
    {
        return number_format($n, 2, '.', '');
    }

    public static function flag(string $key, bool $default = true): bool
    {
        $v = strtolower(trim((string) setting($key, $default ? '1' : '0')));
        if ($v === '') {
            return $default;
        }
        return in_array($v, ['1', 'yes', 'on', 'true'], true);
    }

    /** @param array<string,mixed> $payload */
    private static function orderFromPayload(array $payload): ?array
    {
        $ref = self::payloadStr($payload, 'OrderRefNo', 'orderRefNo', 'ref');
        $order = $ref !== '' ? Catalog::orderByCode($ref) : null;
        if (!$order) {
            $rid = (int) self::payloadStr($payload, 'ReservedField', 'oid');
            if ($rid > 0) {
                $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
                $st->execute([$rid]);
                $order = self::hydrateOrder($st->fetch() ?: null);
            }
        }
        return $order;
    }

    private static function orderFromReturnQuery(): ?array
    {
        $ref = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
        $oid = (int) ($_GET['oid'] ?? $_POST['oid'] ?? 0);
        $order = null;
        if ($ref !== '') {
            $order = Catalog::orderByCode($ref);
        }
        if (!$order && $oid > 0) {
            $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
            $st->execute([$oid]);
            $order = self::hydrateOrder($st->fetch() ?: null);
        }
        if (!$order && !empty($_SESSION['hfs_bt_pending']['code'])) {
            $order = Catalog::orderByCode((string) $_SESSION['hfs_bt_pending']['code']);
        }
        return $order;
    }

    /** @param array<string,mixed> $order */
    private static function returnToken(array $order): string
    {
        $secret = trim((string) setting('bt_secret', ''));
        if ($secret === '') {
            $secret = 'hfs';
        }
        return substr(hash_hmac('sha256', (string) ($order['code'] ?? '') . '|' . (int) ($order['id'] ?? 0), $secret), 0, 20);
    }

    /** @param array<string,mixed> $payload */
    private static function payloadStr(array $payload, string ...$keys): string
    {
        $lower = [];
        foreach ($payload as $k => $v) {
            if (is_scalar($v)) {
                $lower[strtolower((string) $k)] = trim((string) $v);
            }
        }
        foreach ($keys as $key) {
            $v = $lower[strtolower($key)] ?? '';
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /** @param array<string,mixed>|null $row */
    private static function hydrateOrder(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([(int) $row['id']]);
        $row['items'] = $it->fetchAll();
        return $row;
    }

    /** @return array<string,mixed> */
    private static function returnPayload(): array
    {
        $p = array_merge($_GET, $_POST);
        foreach (['jsonData', 'JsonData', 'JSONData', 'jsondata'] as $k) {
            if (!empty($p[$k]) && is_string($p[$k])) {
                $j = json_decode($p[$k], true);
                if (is_array($j)) {
                    $p = array_merge($p, $j);
                }
            }
        }
        $raw = (string) file_get_contents('php://input');
        if ($raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $p = array_merge($p, $j);
            } else {
                $parsed = [];
                parse_str($raw, $parsed);
                if (is_array($parsed) && $parsed !== []) {
                    $p = array_merge($p, $parsed);
                    foreach (['jsonData', 'JsonData', 'JSONData', 'jsondata'] as $k) {
                        if (!empty($parsed[$k]) && is_string($parsed[$k])) {
                            $jj = json_decode($parsed[$k], true);
                            if (is_array($jj)) {
                                $p = array_merge($p, $jj);
                            }
                        }
                    }
                }
            }
        }
        return $p;
    }

    public static function consumeError(): string
    {
        $m = trim((string) ($_SESSION['hfs_bt_err'] ?? ''));
        unset($_SESSION['hfs_bt_err']);
        return $m;
    }

    /** @return array{ok:bool,msg:string} */
    public static function connectionTest(): array
    {
        bmn_session_release();
        if (!self::configured()) {
            return ['ok' => false, 'msg' => 'Kullanıcı, şifre, FirmAPICode ve SecretKeyCode doldurulmalı.'];
        }
        $payload = [
            'UserName' => trim((string) setting('bt_username')),
            'Password' => trim((string) setting('bt_password')),
            'FirmAPICode' => trim((string) setting('bt_firm')),
            'OrderGuidCode' => '00000000-0000-0000-0000-000000000000',
        ];
        $res = self::postJson(self::INFO, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        if ($res['raw'] === '') {
            return ['ok' => false, 'msg' => 'Sunucuya bağlanılamadı: ' . ($res['err'] !== '' ? $res['err'] : 'boş yanıt')];
        }
        $data = is_array($res['data']) ? $res['data'] : [];
        $msg = trim((string) ($data['StatusMessage'] ?? $data['Message'] ?? ''));
        $code = trim((string) ($data['StatusCode'] ?? ''));
        if ($msg === '' && $code === '') {
            return ['ok' => false, 'msg' => 'Yanıt anlaşılamadı: ' . mb_substr($res['raw'], 0, 180)];
        }
        $combined = trim($code . ' ' . $msg);
        if (stripos($combined, 'GIRIS_YETKISI') !== false || str_contains($combined, '625')) {
            return ['ok' => false, 'msg' => 'Sanal POS giriş reddedildi: ' . $combined];
        }
        if (str_contains($combined, '629') || stripos($combined, 'KAYIT_BULUNAMADI') !== false) {
            try {
                setting_set('bt_last_error', '');
            } catch (Throwable) {
            }
            return [
                'ok' => true,
                'msg' => 'Sanal POS bağlantısı tamam. 629 yalnızca test GUID için “kayıt yok” demektir; gerçek kart ödemesi ayrı çalışır.',
            ];
        }
        return ['ok' => true, 'msg' => 'Sanal POS yanıtı: ' . $combined];
    }

    /** @return array{ok:bool,msg:string} */
    public static function connectionTestFull(): array
    {
        $pos = self::connectionTest();
        $web = self::pingWebApi();
        $lines = ['Sanal POS: ' . $pos['msg']];
        if ($web['ok']) {
            $n = (int) ($web['subs'] ?? 0);
            $lines[] = 'WEB API (cari kart): OK — Bulut’ta ' . $n . ' alt firma listelendi.';
            try {
                setting_set('bt_last_error', '');
            } catch (Throwable) {
            }
        } else {
            $wm = trim((string) ($web['msg'] ?? $web['error'] ?? 'bağlanamadı'));
            $lines[] = 'WEB API (cari kart): ' . ($wm !== '' ? $wm : 'Kullanıcı/şifre eksik veya hatalı.');
        }
        return [
            'ok' => !empty($pos['ok']) && !empty($web['ok']),
            'msg' => implode(' ', $lines),
        ];
    }

    /** @param array<string,mixed> $order */
    public static function inspectOrderPayment(array $order): array
    {
        $guid = trim((string) ($order['bt_guid'] ?? ''));
        if ($guid === '' || !self::configured()) {
            return ['ok' => false, 'msg' => $guid === '' ? 'bt_guid yok' : 'Bulut ayarı yok'];
        }
        bmn_session_release();
        $info = self::paymentInfo($guid);
        if (!is_array($info) || $info === []) {
            return ['ok' => false, 'msg' => 'PaymentInfo yok'];
        }
        $outcome = self::paymentOutcome($info);
        $good = self::paymentStillGood($info);
        $cancelled = self::looksCancelled($outcome['code'], $outcome['msg'])
            || self::payloadIndicatesCancel($info);
        return [
            'ok' => true,
            'still_paid' => $good === true,
            'cancelled' => $cancelled,
            'unpaid' => self::looksUnpaid($outcome['code'], $outcome['msg']),
            'status_code' => $outcome['code'],
            'status_message' => $outcome['msg'],
            'pay_text' => $outcome['pay_text'],
        ];
    }

    /** @return array<string,mixed>|null */
    /** @var array<string, array<string,mixed>|null> */
    private static array $paymentInfoCache = [];

    private static function paymentInfo(string $guid): ?array
    {
        $guid = strtoupper(trim($guid));
        if ($guid === '') {
            return null;
        }
        if (array_key_exists($guid, self::$paymentInfoCache)) {
            return self::$paymentInfoCache[$guid];
        }
        $quotaUntil = (int) setting('bt_paymentinfo_quota_until', '0');
        if ($quotaUntil > time()) {
            return self::$paymentInfoCache[$guid] = null;
        }
        $payload = [
            'UserName' => trim((string) setting('bt_username')),
            'Password' => trim((string) setting('bt_password')),
            'FirmAPICode' => trim((string) setting('bt_firm')),
            'OrderGuidCode' => $guid,
        ];
        $res = self::postJson(self::INFO, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        $data = is_array($res['data']) ? $res['data'] : null;
        if (is_array($data) && self::isApiQuotaError($data)) {
            try {
                // Günlük kota — yeni gün UTC+3’e kadar sorguyu kes
                setting_set('bt_paymentinfo_quota_until', (string) (strtotime('tomorrow 00:05') ?: (time() + 3600)));
            } catch (Throwable) {
            }
            self::logBt('paymentinfo_quota', [
                'ok' => false,
                'guid' => $guid,
                'msg' => self::payloadStr($data, 'StatusMessage', 'PaymentStatusMessage'),
            ]);
            return self::$paymentInfoCache[$guid] = null;
        }
        return self::$paymentInfoCache[$guid] = $data;
    }

    /** @param array<string,mixed> $payload */
    private static function isApiQuotaError(array $payload): bool
    {
        $t = mb_strtoupper(trim(
            self::payloadStr($payload, 'StatusMessage', 'PaymentStatusMessage')
            . ' '
            . self::payloadStr($payload, 'StatusCode', 'PaymentStatusCode')
            . ' '
            . self::payloadStr($payload, 'PaymentStatusText')
        ), 'UTF-8');
        $t = str_replace(['İ', 'İ'], 'I', $t);
        return str_contains($t, 'SORGU LIMIT')
            || str_contains($t, 'MAX ADET')
            || (str_contains($t, 'LIMIT') && str_contains($t, 'ASILDI'));
    }

    public static function reconcilePayments(int $limit = 40): int
    {
        if (!self::configured()) {
            return 0;
        }
        $lim = max(1, min(40, $limit));
        $n = self::pollVposCancellations($lim);

        // Dekont doldurma: her sync'te değil (PaymentInfo yakar) — ~30 dk'da bir, az adet
        $dekontTs = (int) setting('bt_dekont_enrich_ts', '0');
        if (time() - $dekontTs >= 1800) {
            try {
                setting_set('bt_dekont_enrich_ts', (string) time());
            } catch (Throwable) {
            }
            self::enrichMissingDekonts(min(8, $lim));
        }

        // Ödenmiş sipariş iptal kontrolü: asıl kaynak VPos listesi.
        // PaymentInfo yedek taraması seyrek + dar pencere (kota koruması).
        $paidScanTs = (int) setting('bt_pi_paid_scan_ts', '0');
        if (time() - $paidScanTs >= 21600) { // 6 saat
            try {
                setting_set('bt_pi_paid_scan_ts', (string) time());
            } catch (Throwable) {
            }
            $paidLim = min(5, $lim);
            $rows = db()->query(
                "SELECT * FROM orders WHERE payment='bulut' AND payment_status='odendi' AND status<>'iptal'
                 AND bt_guid<>'' AND created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                 ORDER BY id DESC LIMIT " . $paidLim
            )->fetchAll() ?: [];
            foreach ($rows as $row) {
                $info = self::paymentInfo((string) $row['bt_guid']);
                if (!is_array($info) || $info === []) {
                    continue;
                }
                $outcome = self::paymentOutcome($info);
                if (self::looksUnpaid($outcome['code'], $outcome['msg']) && !self::looksCancelled($outcome['code'], $outcome['msg']) && !self::payloadIndicatesCancel($info)) {
                    continue;
                }
                $good = self::paymentStillGood($info);
                if ($good !== false) {
                    continue;
                }
                Catalog::cancelOrder($row, 'BulutTahsilat / POS iptal', true, false, true);
                $n++;
            }
        }

        // Bekleyen ödemeler: az adet, yeni açılanlar (dönüş URL) atlanır
        $pendingLim = min(8, $lim);
        $pending = db()->query(
            "SELECT * FROM orders WHERE payment='bulut' AND status='odeme' AND payment_status IN ('bekliyor','')
             AND bt_guid<>'' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY id DESC LIMIT " . $pendingLim
        )->fetchAll() ?: [];
        foreach ($pending as $row) {
            $info = self::paymentInfo((string) $row['bt_guid']);
            if (!is_array($info) || $info === []) {
                continue;
            }
            $outcome = self::paymentOutcome($info);
            if ($outcome['code'] === '' && $outcome['msg'] === '') {
                continue;
            }
            // Kota / API hatası → bekliyorda bırak
            if (self::isApiQuotaError($info)) {
                continue;
            }
            // Dönüş URL imza/kota yüzünden kaçmış olabilir — bekleyen başarıyı burada tamamla.
            if (self::isPaymentSuccess($outcome['code'], $outcome['msg'], $info)) {
                $verify = self::verifyBulutAmount($row, $info);
                if (!empty($verify['ok'])) {
                    self::applySuccessfulPayment($row, $info, $outcome['code'], $outcome['msg']);
                    $n++;
                }
                continue;
            }
            if (!self::looksUnpaid($outcome['code'], $outcome['msg']) && !self::looksCancelled($outcome['code'], $outcome['msg'])) {
                continue;
            }
            $detail = self::markPaymentFailed((int) $row['id'], $info, $outcome['code'], $outcome['msg']);
            self::logBt('odeme_alinamadi', [
                'ok' => false,
                'order' => (int) $row['id'],
                'code' => (string) ($row['code'] ?? ''),
                'status' => $outcome['code'],
                'msg' => $outcome['msg'],
                'pay_text' => $outcome['pay_text'],
                'detail' => $detail,
                'label' => $detail,
                'source' => 'reconcile',
                'is_cari' => (int) ($row['is_cari'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
                'b2b_status' => (string) ($row['status'] ?? ''),
            ]);
            $n++;
        }
        return $n;
    }

    /** Bulut panel iptal/iade → B2B + Bayt (cron veya /hfs-bt-watch/). */
    public static function watchBulutCancellations(): int
    {
        return (int) (HfsSync::run()['bulut'] ?? 0);
    }

    /** Bulut sunucu bildirimi (ReturnUrl benzeri POST/GET). */
    public static function handleNotify(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        bmn_session_release();
        $payload = self::returnPayload();
        self::logBt('notify', [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'keys' => array_keys($payload),
        ]);
        $order = self::orderFromPayload($payload);
        $guid = self::payloadStr($payload, 'OrderGuidCode', 'orderGuidCode');
        if (!$order && $guid !== '') {
            $st = db()->prepare('SELECT * FROM orders WHERE bt_guid=? LIMIT 1');
            $st->execute([$guid]);
            $order = self::hydrateOrder($st->fetch() ?: null);
        }
        if ($order) {
            self::applyBulutNotifyToOrder($order, $payload);
        }
        // Kimlik doğrulamasız uç nokta: dışarıdan tekrar tekrar çağrılıp Bulut API'sini yormasın.
        if ((time() - (int) setting('bt_notify_poll_at', '0')) >= 60) {
            setting_set('bt_notify_poll_at', (string) time());
            self::pollVposCancellations(30);
        }
        http_response_code(200);
        echo 'OK';
        exit;
    }

    public static function handleWatch(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        $key = (string) ($GLOBALS['bmn_config']['cron_key'] ?? '');
        if ($key === '' || !hash_equals($key, (string) ($_GET['k'] ?? ''))) {
            http_response_code(403);
            echo 'no';
            exit;
        }
        if (!HfsSync::tryLock()) {
            echo 'busy';
            exit;
        }
        try {
            $r = HfsSync::run(true);
            echo 'ok bulut=' . $r['bulut'] . ' bayt=' . $r['bayt'];
        } finally {
            HfsSync::unlock();
        }
        exit;
    }

    /** B2B / Bayt iptali → Bulut Sanal POS iptal talebi (aynı gün void). */
    public static function requestBulutCancel(array $order, string $reason = ''): bool
    {
        if (!self::configured()) {
            return false;
        }
        if (($order['payment'] ?? '') !== 'bulut' || ($order['payment_status'] ?? '') !== 'odendi') {
            return false;
        }
        $guid = trim((string) ($order['bt_guid'] ?? ''));
        $code = trim((string) ($order['code'] ?? ''));
        if ($guid === '' && $code === '') {
            return false;
        }
        bmn_session_release();
        $payload = [
            'UserName' => trim((string) setting('bt_username')),
            'Password' => trim((string) setting('bt_password')),
            'FirmAPICode' => trim((string) setting('bt_firm')),
            'OrderGuidCode' => $guid !== '' ? $guid : '00000000-0000-0000-0000-000000000000',
            'OrderRefNo' => $code,
        ];
        $refPay = trim((string) ($order['payment_ref'] ?? ''));
        if ($refPay !== '') {
            $payload['PaymentID'] = $refPay;
            $payload['AuthCode'] = $refPay;
        }
        $res = self::postJson(self::CANCEL, json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        $data = is_array($res['data']) ? $res['data'] : [];
        $status = self::payloadStr($data, 'StatusCode', 'PaymentStatusCode');
        $msg = self::payloadStr($data, 'StatusMessage', 'PaymentStatusMessage');
        $ok = self::isSuccess($status) || self::looksCancelled($status, $msg);
        self::logBt('payment_cancel', [
            'order' => (int) ($order['id'] ?? 0),
            'code' => $code,
            'ok' => $ok,
            'status' => $status,
            'msg' => $msg,
            'reason' => mb_substr($reason, 0, 120),
        ]);
        return $ok;
    }

    /** Ödenmiş Bulut siparişini PaymentInfo ile kontrol eder; iptal ise B2B+Bayt. */
    public static function syncOrderFromBulut(int $orderId): bool
    {
        if ($orderId < 1 || !self::configured()) {
            return false;
        }
        $st = db()->prepare("SELECT * FROM orders WHERE id=? AND payment='bulut' LIMIT 1");
        $st->execute([$orderId]);
        $row = $st->fetch();
        if (!$row || ($row['payment_status'] ?? '') !== 'odendi' || ($row['status'] ?? '') === 'iptal') {
            return false;
        }
        $guid = trim((string) ($row['bt_guid'] ?? ''));
        if ($guid === '') {
            return false;
        }
        $info = self::paymentInfo($guid);
        if (self::paymentStillGood($info) !== false) {
            return false;
        }
        Catalog::cancelOrder($row, 'BulutTahsilat iptal/iade (PaymentInfo)', true, false, true);
        return true;
    }

    /**
     * Sanal POS işlem listesinden iptal/iade satırlarını okur (Bulut panel iptali).
     */
    public static function pollVposCancellations(int $limit = 40): int
    {
        if (!self::configured()) {
            return 0;
        }
        bmn_session_release();
        $lim = max(1, min(80, $limit));
        $end = time();
        $start = $end - 86400 * 4;
        $items = self::fetchVposTransactions($start, $end);
        if ($items === []) {
            return 0;
        }
        $n = 0;
        foreach ($items as $item) {
            if ($n >= $lim) {
                break;
            }
            if (!self::vposItemIsCancel($item)) {
                continue;
            }
            $ref = trim((string) ($item['OrderRefNo'] ?? ''));
            $order = null;
            if ($ref !== '') {
                $order = Catalog::orderByCode($ref);
            }
            if (!$order && trim((string) ($item['OrderGuidCode'] ?? '')) !== '') {
                $st = db()->prepare('SELECT * FROM orders WHERE bt_guid=? LIMIT 1');
                $st->execute([trim((string) $item['OrderGuidCode'])]);
                $order = self::hydrateOrder($st->fetch() ?: null);
            }
            if (!$order || ($order['payment'] ?? '') !== 'bulut' || ($order['payment_status'] ?? '') !== 'odendi') {
                continue;
            }
            if (($order['status'] ?? '') === 'iptal') {
                continue;
            }
            $reason = trim((string) ($item['TransactionStatus'] ?? 'Bulut iptal/iade'));
            if ($reason === '') {
                $reason = 'BulutTahsilat iptal/iade (VPos)';
            }
            Catalog::cancelOrder($order, $reason, true, false, true);
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $order */
    private static function applyBulutNotifyToOrder(array $order, array $payload): void
    {
        $guid = self::payloadStr($payload, 'OrderGuidCode', 'orderGuidCode');
        if ($guid !== '') {
            $info = self::paymentInfo($guid);
            if (is_array($info) && $info !== []) {
                $payload = array_merge($payload, $info);
            }
        }
        $outcome = self::paymentOutcome($payload);
        $status = $outcome['code'];
        $msg = $outcome['msg'];
        $paid = ($order['payment_status'] ?? '') === 'odendi';
        if ($paid && (self::looksCancelled($status, $msg) || self::payloadIndicatesCancel($payload))) {
            Catalog::cancelOrder($order, trim($status . ' ' . $msg) !== '' ? ('BulutTahsilat ' . trim($status . ' ' . $msg)) : 'BulutTahsilat iptal', true, false, true);
            return;
        }
        if ($paid) {
            self::syncOrderFromBulut((int) ($order['id'] ?? 0));
            try {
                self::enrichOrderDekont($order, trim((string) ($order['bt_dekont_url'] ?? '')) === '');
            } catch (Throwable) {
            }
            return;
        }
        // Ödenmemiş bildirim: log + payment_status güncelle (panelde görünsün)
        if ($status !== '' || $msg !== '') {
            $okPay = self::isPaymentSuccess($status, $msg, $payload);
            if (!$okPay) {
                $detail = self::markPaymentFailed((int) ($order['id'] ?? 0), $payload, $status, $msg);
                self::logBt('odeme_alinamadi', [
                    'ok' => false,
                    'order' => (int) ($order['id'] ?? 0),
                    'code' => (string) ($order['code'] ?? ''),
                    'status' => $status,
                    'msg' => $msg,
                    'pay_text' => $outcome['pay_text'],
                    'detail' => $detail,
                    'label' => $detail,
                    'source' => 'notify',
                    'is_cari' => (int) ($order['is_cari'] ?? 0),
                    'total' => (float) ($order['total'] ?? 0),
                    'b2b_status' => (string) ($order['status'] ?? ''),
                ]);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private static function payloadIndicatesCancel(array $payload): bool
    {
        foreach (['IsCancelled', 'Cancelled', 'IsCancel', 'PaymentCancel', 'IsVoid', 'Voided', 'IsTransactionCancel'] as $k) {
            $v = $payload[$k] ?? null;
            if ($v === true || $v === 1 || $v === '1' || (is_string($v) && in_array(strtolower($v), ['true', 'yes', 'iptal'], true))) {
                return true;
            }
        }
        $code = self::payloadStr($payload, 'PaymentStatusCode', 'StatusCode');
        $msg = self::payloadStr($payload, 'StatusMessage', 'PaymentStatusMessage');
        return self::looksCancelled($code, $msg);
    }

    /** @param array<string,string> $item */
    private static function vposItemIsCancel(array $item): bool
    {
        $ic = strtolower(trim((string) ($item['IsTransactionCancel'] ?? '')));
        if (in_array($ic, ['true', '1', 'yes'], true)) {
            return true;
        }
        $st = trim((string) ($item['TransactionStatus'] ?? ''));
        return self::looksCancelled('', $st);
    }

    /** @var array<string, list<array<string,string>>> */
    private static array $vposListCache = [];

    /** @return list<array<string,string>> */
    private static function fetchVposTransactions(int $startTs, int $endTs): array
    {
        $start = date('Y-m-d\T00:00:00', $startTs);
        $end = date('Y-m-d\T23:59:59', $endTs);
        $ck = $start . '|' . $end;
        if (isset(self::$vposListCache[$ck])) {
            return self::$vposListCache[$ck];
        }
        $inner = '<GetVPosTransactionList xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(trim((string) setting('bt_username'))) . '</userName>'
            . '<password>' . self::xml(trim((string) setting('bt_password'))) . '</password>'
            . '<firmApiCode>' . self::xml(trim((string) setting('bt_firm'))) . '</firmApiCode>'
            . '<transactionStatusID>0</transactionStatusID>'
            . '<startDate>' . self::xml($start) . '</startDate>'
            . '<endDate>' . self::xml($end) . '</endDate>'
            . '</GetVPosTransactionList>';
        $raw = self::soap('GetVPosTransactionList', $inner);
        if ($raw === '') {
            return self::$vposListCache[$ck] = [];
        }
        $code = self::xmlTag($raw, 'StatusCode');
        $msg = self::xmlTag($raw, 'StatusMessage');
        if ($code !== '' && (int) $code >= 400 && !str_contains(mb_strtolower($msg), 'kayit')) {
            self::logBt('vpos_list_fail', ['code' => $code, 'msg' => $msg]);
            return self::$vposListCache[$ck] = [];
        }
        return self::$vposListCache[$ck] = self::parseVposListXml($raw);
    }

    /** @return list<array<string,string>> */
    private static function parseVposListXml(string $raw): array
    {
        $items = [];
        if (!preg_match_all('/<(?:\w+:)?VPosTransactionListItem\b[^>]*>(.*?)<\/(?:\w+:)?VPosTransactionListItem>/si', $raw, $blocks)) {
            return [];
        }
        $tags = [
            'TransactionID', 'TotalPaymentAmount', 'CurrencyCode', 'InstallmentNumber', 'AdditionalInstallmentNumber',
            'TransactionDate', 'TransactionStatusID', 'TransactionStatus', 'PaymentExpCode', 'FirmName',
            'CreditCardHoldersFullName', 'MaskedCreditCardNumber', 'PosBankCode', 'PosBankName',
            'CardBankCode', 'CardBankName', 'AuthCode', 'UserFullName', 'Explanation',
            'IsTransactionCancel', 'VPosProductTypeName', 'BranchFirmName', 'BranchFirmTaxNumber',
            'VPosBankMerchantID', 'OrderRefNo', 'OrderGuidCode', 'OrderBankNo',
            'VPosTransactionDekontUrl', 'BankMessage', 'BusinessCard',
        ];
        foreach ($blocks[1] as $inner) {
            $row = [];
            foreach ($tags as $tag) {
                $row[$tag] = self::xmlTag($inner, $tag);
            }
            $items[] = $row;
        }
        return $items;
    }

    /** Canlı teşhis: VPos listesi + örnek PaymentInfo. */
    public static function probeVposDekont(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'bulut ayar yok'];
        }
        $end = time();
        $start = $end - 86400 * 3;
        $startIso = date('Y-m-d\T00:00:00', $start);
        $endIso = date('Y-m-d\T23:59:59', $end);
        $inner = '<GetVPosTransactionList xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(trim((string) setting('bt_username'))) . '</userName>'
            . '<password>' . self::xml(trim((string) setting('bt_password'))) . '</password>'
            . '<firmApiCode>' . self::xml(trim((string) setting('bt_firm'))) . '</firmApiCode>'
            . '<transactionStatusID>0</transactionStatusID>'
            . '<startDate>' . self::xml($startIso) . '</startDate>'
            . '<endDate>' . self::xml($endIso) . '</endDate>'
            . '</GetVPosTransactionList>';
        $raw = self::soap('GetVPosTransactionList', $inner);
        $items = $raw !== '' ? self::parseVposListXml($raw) : [];
        $sample = null;
        foreach ($items as $it) {
            if (!self::vposItemIsCancel($it) && trim((string) ($it['VPosTransactionDekontUrl'] ?? '')) !== '') {
                $sample = [
                    'OrderRefNo' => $it['OrderRefNo'] ?? '',
                    'OrderGuidCode' => $it['OrderGuidCode'] ?? '',
                    'AuthCode' => $it['AuthCode'] ?? '',
                    'Dekont' => mb_substr((string) ($it['VPosTransactionDekontUrl'] ?? ''), 0, 120),
                    'Mask' => $it['MaskedCreditCardNumber'] ?? '',
                ];
                break;
            }
        }
        $order = db()->query("SELECT id,code,bt_guid,created_at FROM orders WHERE payment='bulut' AND payment_status='odendi' AND bt_guid<>'' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        $infoKeys = [];
        if ($order) {
            $info = self::paymentInfo((string) $order['bt_guid']);
            $infoKeys = is_array($info) ? array_keys($info) : [];
        }
        return [
            'ok' => true,
            'range' => [$startIso, $endIso],
            'raw_len' => strlen($raw),
            'status' => self::xmlTag($raw, 'StatusCode'),
            'message' => self::xmlTag($raw, 'StatusMessage'),
            'items' => count($items),
            'sample' => $sample,
            'order' => $order,
            'payment_info_keys' => $infoKeys,
            'last_soap' => mb_substr((string) setting('bt_last_soap', ''), 0, 200),
        ];
    }

    /** @param array<string,mixed> $order */
    public static function hasDekont(array $order): bool
    {
        if (($order['payment'] ?? '') !== 'bulut' || ($order['payment_status'] ?? '') !== 'odendi') {
            return false;
        }
        return trim((string) ($order['bt_dekont_url'] ?? '')) !== ''
            || trim((string) ($order['bt_guid'] ?? '')) !== '';
    }

    /**
     * Ödenmiş Bulut siparişine VPos dekont alanlarını yazar.
     *
     * @param array<string,mixed>|null $order
     */
    public static function enrichOrderDekont(?array $order, bool $force = false): bool
    {
        if (!$order || !self::configured()) {
            return false;
        }
        if (($order['payment'] ?? '') !== 'bulut' || ($order['payment_status'] ?? '') !== 'odendi') {
            return false;
        }
        $id = (int) ($order['id'] ?? 0);
        if ($id < 1) {
            return false;
        }
        if (!$force && trim((string) ($order['bt_dekont_url'] ?? '')) !== '' && trim((string) ($order['bt_auth_code'] ?? '')) !== '') {
            return true;
        }
        // Önce VPos listesi (tek SOAP); PaymentInfo kota yer
        $item = self::findVposItemForOrder($order);
        if ($item !== null) {
            self::persistVposEnrichment($id, $item);
            return true;
        }
        $guid = trim((string) ($order['bt_guid'] ?? ''));
        if ($guid !== '') {
            $info = self::paymentInfo($guid);
            if (is_array($info) && $info !== []) {
                $mapped = self::mapPaymentInfoToVpos($info);
                if ($mapped !== null) {
                    self::persistVposEnrichment($id, $mapped);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * PaymentInfo JSON → VPos alan adları (persistVposEnrichment ile uyumlu).
     *
     * @param array<string,mixed> $info
     * @return array<string,string>|null
     */
    private static function mapPaymentInfoToVpos(array $info): ?array
    {
        $dekont = self::payloadStr($info, 'DekontUrl', 'VPosTransactionDekontUrl', 'ReceiptUrl');
        $auth = self::payloadStr($info, 'AuthCode');
        $payId = self::payloadStr($info, 'PaymentID', 'BankTransactionID');
        $holder = trim(self::payloadStr($info, 'CardHolderName') . ' ' . self::payloadStr($info, 'CardHolderSurname'));
        if ($holder === '') {
            $holder = self::payloadStr($info, 'CreditCardHoldersFullName', 'CardHolder');
        }
        $mask = self::payloadStr($info, 'CreditCardNumber', 'MaskedCreditCardNumber', 'CardNumber');
        $cardBank = self::payloadStr($info, 'CreditBankName', 'CardBankName', 'CreditCardBankName');
        $posBank = self::payloadStr($info, 'VPosBankName', 'PosBankName');
        $inst = self::payloadStr($info, 'Installment', 'InstallmentNumber');
        $bankMsg = self::payloadStr($info, 'BankMessage');
        $merchant = self::payloadStr($info, 'VPosBankMerchantID', 'VPosBankCode');
        if ($dekont === '' && $auth === '' && $mask === '' && $payId === '' && $posBank === '' && $merchant === '') {
            return null;
        }
        return [
            'TransactionID' => $payId,
            'AuthCode' => $auth,
            'InstallmentNumber' => $inst,
            'CreditCardHoldersFullName' => $holder,
            'MaskedCreditCardNumber' => $mask,
            'CardBankName' => $cardBank,
            'PosBankName' => $posBank,
            'VPosBankMerchantID' => $merchant,
            'UserFullName' => self::payloadStr($info, 'UserFullName'),
            'BankMessage' => $bankMsg,
            'VPosTransactionDekontUrl' => $dekont,
            'OrderGuidCode' => self::payloadStr($info, 'OrderGuidCode'),
            'OrderRefNo' => self::payloadStr($info, 'OrderRefNo'),
            'IsTransactionCancel' => 'false',
            'TransactionStatus' => self::payloadStr($info, 'PaymentStatusText', 'StatusMessage'),
        ];
    }

    /**
     * Geriye dönük: dekont URL / provizyon eksik ödenmiş siparişleri doldurur.
     *
     * @return array{ok:bool,scanned:int,filled:int,miss:int,left:int}
     */
    public static function enrichMissingDekonts(int $limit = 40): array
    {
        $out = ['ok' => false, 'scanned' => 0, 'filled' => 0, 'miss' => 0, 'left' => 0, 'error' => ''];
        if (!self::configured()) {
            $out['error'] = 'bulut ayar yok';
            return $out;
        }
        $lim = max(1, min(200, $limit));
        try {
            HfsMigrate::up();
            $cols = array_column(db()->query('SHOW COLUMNS FROM orders')->fetchAll(), 'Field');
            if (!in_array('bt_dekont_url', $cols, true) || !in_array('bt_auth_code', $cols, true)) {
                $out['error'] = 'bt kolonlari yok: ' . implode(',', array_values(array_filter($cols, static fn ($c) => str_starts_with((string) $c, 'bt_'))));
                return $out;
            }
            $paid = (int) db()->query("SELECT COUNT(*) FROM orders WHERE payment='bulut' AND payment_status='odendi' AND bt_guid<>''")->fetchColumn();
            $out['paid_bulut'] = $paid;
            $rows = db()->query(
                "SELECT * FROM orders WHERE payment='bulut' AND payment_status='odendi' AND status<>'iptal'
                 AND bt_guid<>'' AND (IFNULL(bt_dekont_url,'')='' OR IFNULL(bt_auth_code,'')='')
                 ORDER BY id DESC LIMIT " . $lim
            )->fetchAll();
            $left = (int) db()->query(
                "SELECT COUNT(*) FROM orders WHERE payment='bulut' AND payment_status='odendi' AND status<>'iptal'
                 AND bt_guid<>'' AND (IFNULL(bt_dekont_url,'')='' OR IFNULL(bt_auth_code,'')='')"
            )->fetchColumn();
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        $out['scanned'] = count($rows);
        $out['left'] = $left;
        if ($rows === []) {
            $out['ok'] = true;
            return $out;
        }
        $missSamples = [];
        foreach ($rows as $row) {
            if (self::enrichOrderDekont($row, true)) {
                $out['filled']++;
            } else {
                $out['miss']++;
                if (count($missSamples) < 3) {
                    $missSamples[] = [
                        'code' => (string) ($row['code'] ?? ''),
                        'guid' => (string) ($row['bt_guid'] ?? ''),
                        'at' => (string) ($row['created_at'] ?? ''),
                    ];
                }
            }
        }
        if ($missSamples !== []) {
            $out['miss_samples'] = $missSamples;
        }
        try {
            $out['left'] = (int) db()->query(
                "SELECT COUNT(*) FROM orders WHERE payment='bulut' AND payment_status='odendi' AND status<>'iptal'
                 AND bt_guid<>'' AND (IFNULL(bt_dekont_url,'')='' OR IFNULL(bt_auth_code,'')='')"
            )->fetchColumn();
        } catch (Throwable) {
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,string>|null
     */
    private static function findVposItemForOrder(array $order): ?array
    {
        $guid = trim((string) ($order['bt_guid'] ?? ''));
        $code = trim((string) ($order['code'] ?? ''));
        $created = strtotime((string) ($order['created_at'] ?? '')) ?: time();
        $start = $created - 86400 * 2;
        $end = $created + 86400 * 2;
        $items = self::fetchVposTransactions($start, $end);
        if ($items === []) {
            $items = self::fetchVposTransactions(time() - 86400 * 7, time());
        }
        $matches = [];
        foreach ($items as $item) {
            if (self::vposItemIsCancel($item)) {
                continue;
            }
            $ig = trim((string) ($item['OrderGuidCode'] ?? ''));
            $ir = trim((string) ($item['OrderRefNo'] ?? ''));
            $ok = ($guid !== '' && strcasecmp($ig, $guid) === 0)
                || ($code !== '' && strcasecmp($ir, $code) === 0);
            if ($ok) {
                $matches[] = $item;
            }
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, static function (array $a, array $b): int {
            return strcmp((string) ($b['TransactionDate'] ?? ''), (string) ($a['TransactionDate'] ?? ''));
        });
        return $matches[0];
    }

    /** @param array<string,string> $item */
    private static function persistVposEnrichment(int $orderId, array $item): void
    {
        $auth = trim((string) ($item['AuthCode'] ?? ''));
        $txId = trim((string) ($item['TransactionID'] ?? ''));
        $inst = trim((string) ($item['InstallmentNumber'] ?? ''));
        $holder = mb_substr(trim((string) ($item['CreditCardHoldersFullName'] ?? '')), 0, 120);
        $mask = mb_substr(trim((string) ($item['MaskedCreditCardNumber'] ?? '')), 0, 40);
        $cardBank = mb_substr(trim((string) ($item['CardBankName'] ?? '')), 0, 80);
        $posBank = mb_substr(trim((string) ($item['PosBankName'] ?? '')), 0, 80);
        $merchant = mb_substr(trim((string) ($item['VPosBankMerchantID'] ?? '')), 0, 40);
        $user = mb_substr(trim((string) ($item['UserFullName'] ?? '')), 0, 120);
        $bankMsg = mb_substr(trim((string) ($item['BankMessage'] ?? '')), 0, 200);
        $dekont = mb_substr(trim((string) ($item['VPosTransactionDekontUrl'] ?? '')), 0, 500);
        $payId = $txId !== '' ? $txId : $auth;
        try {
            db()->prepare(
                'UPDATE orders SET
                    bt_tx_id=CASE WHEN ?<>\'\' THEN ? ELSE bt_tx_id END,
                    bt_auth_code=CASE WHEN ?<>\'\' THEN ? ELSE bt_auth_code END,
                    bt_payment_id=CASE WHEN ?<>\'\' THEN ? ELSE bt_payment_id END,
                    bt_installment=CASE WHEN ?<>\'\' THEN ? ELSE bt_installment END,
                    bt_card_holder=CASE WHEN ?<>\'\' THEN ? ELSE bt_card_holder END,
                    bt_card_mask=CASE WHEN ?<>\'\' THEN ? ELSE bt_card_mask END,
                    bt_card_bank=CASE WHEN ?<>\'\' THEN ? ELSE bt_card_bank END,
                    bt_pos_bank=CASE WHEN ?<>\'\' THEN ? ELSE bt_pos_bank END,
                    bt_merchant_id=CASE WHEN ?<>\'\' THEN ? ELSE bt_merchant_id END,
                    bt_user_name=CASE WHEN ?<>\'\' THEN ? ELSE bt_user_name END,
                    bt_bank_message=CASE WHEN ?<>\'\' THEN ? ELSE bt_bank_message END,
                    bt_dekont_url=CASE WHEN ?<>\'\' THEN ? ELSE bt_dekont_url END,
                    payment_ref=CASE WHEN (payment_ref IS NULL OR payment_ref=\'\') AND ?<>\'\' THEN ? ELSE payment_ref END
                 WHERE id=?'
            )->execute([
                $txId, $txId,
                $auth, $auth,
                $payId, $payId,
                $inst, $inst,
                $holder, $holder,
                $mask, $mask,
                $cardBank, $cardBank,
                $posBank, $posBank,
                $merchant, $merchant,
                $user, $user,
                $bankMsg, $bankMsg,
                $dekont, $dekont,
                $payId, $payId,
                $orderId,
            ]);
        } catch (Throwable $e) {
            self::logBt('dekont_persist_fail', ['order' => $orderId, 'err' => $e->getMessage()]);
        }
    }

    /** Bayi veya yönetim: Bulut tahsilat dekontu PDF. */
    public static function downloadDekont(string $code, bool $asAdmin = false): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        $order = Catalog::orderByCode($code);
        if (!$order) {
            http_response_code(404);
            echo 'Sipariş yok';
            exit;
        }
        if ($asAdmin) {
            $u = current_user();
            if (!$u || !in_array((string) ($u['role'] ?? ''), ['admin', 'editor'], true)) {
                http_response_code(403);
                echo 'Yetki yok';
                exit;
            }
        } elseif (!Catalog::dealerCanSeeOrder($order)) {
            http_response_code(403);
            echo 'Yetki yok';
            exit;
        }
        if (($order['payment'] ?? '') !== 'bulut' || ($order['payment_status'] ?? '') !== 'odendi') {
            http_response_code(404);
            echo 'Kart tahsilatı yok';
            exit;
        }
        self::enrichOrderDekont($order, trim((string) ($order['bt_dekont_url'] ?? '')) === '');
        $fresh = Catalog::orderByCode($code) ?: $order;
        $url = trim((string) ($fresh['bt_dekont_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            http_response_code(404);
            echo 'Dekont henüz hazır değil. Kısa süre sonra tekrar deneyin.';
            exit;
        }
        $bin = self::fetchRemoteBinary($url);
        if ($bin !== null && $bin !== '') {
            $name = 'tahsilat-dekontu-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $code) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $name . '"');
            header('Content-Length: ' . (string) strlen($bin));
            echo $bin;
            exit;
        }
        redirect($url, 302);
    }

    private static function fetchRemoteBinary(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'HFS-B2B-Dekont/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) {
            return null;
        }
        if (str_contains($ctype, 'pdf') || str_starts_with($body, '%PDF')) {
            return $body;
        }
        return null;
    }

    /** @param array<string,mixed>|null $info */
    private static function paymentStillGood(?array $info): ?bool
    {
        if (!is_array($info) || $info === []) {
            return null;
        }
        $outcome = self::paymentOutcome($info);
        $code = $outcome['code'];
        $msg = $outcome['msg'];
        foreach (['IsCancelled', 'Cancelled', 'IsCancel', 'PaymentCancel', 'IsVoid', 'Voided'] as $k) {
            $v = $info[$k] ?? null;
            if ($v === true || $v === 1 || $v === '1' || (is_string($v) && in_array(strtolower($v), ['true', 'yes', 'iptal'], true))) {
                return false;
            }
        }
        if (self::looksCancelled($code, $msg) || self::looksUnpaid($code, $msg)) {
            return false;
        }
        if ($code === '' && $msg === '') {
            return null;
        }
        // Yalnızca açık ödeme başarısı → iyi. Belirsiz / API-601’de iptal etme.
        if (self::isPaymentSuccess($code, $msg, $info)) {
            return true;
        }
        return null;
    }

    /**
     * Bulut payload: ödeme kodu (PaymentStatusCode) + metin (PaymentStatusText).
     * StatusCode 601 = API OK; ödeme sonucu değildir.
     *
     * @param array<string,mixed> $payload
     * @return array{code:string,msg:string,pay_text:string,api_code:string}
     */
    private static function paymentOutcome(array $payload): array
    {
        $payCode = self::payloadStr($payload, 'PaymentStatusCode');
        $apiCode = self::payloadStr($payload, 'StatusCode');
        $payText = self::payloadStr($payload, 'PaymentStatusText');
        $apiMsg = self::payloadStr($payload, 'StatusMessage', 'PaymentStatusMessage');
        $code = $payCode !== '' ? $payCode : $apiCode;
        if ($code === '601' && $payCode === '') {
            $code = '';
        }
        $msg = $payText !== '' ? $payText : $apiMsg;
        // API mesajı “ISLEM_BASARILI” iken ödeme metni yoksa yanıltmasın
        if ($payText === '' && $payCode !== '' && strtoupper($apiMsg) === 'ISLEM_BASARILI' && !self::isPaymentSuccess($payCode, $apiMsg, $payload)) {
            $bank = self::payloadStr($payload, 'BankMessage');
            $msg = $bank !== '' ? $bank : ($payCode === '331' || $payCode === '613' ? 'Ödeme alınamadı' : $apiMsg);
        }
        return [
            'code' => $code,
            'msg' => $msg,
            'pay_text' => $payText,
            'api_code' => $apiCode,
        ];
    }

    /** Panel / bayi: ödeme durumunu okunaklı göster. */
    public static function paymentStatusLabel(array $order): string
    {
        $ps = trim((string) ($order['payment_status'] ?? ''));
        if ($ps === '') {
            return '';
        }
        if ($ps === 'odendi') {
            return 'ödendi';
        }
        if ($ps === 'bekliyor') {
            return 'bekliyor';
        }
        $detail = trim((string) ($order['bt_bank_message'] ?? ''));
        if (str_starts_with($ps, 'hata:')) {
            $fromStatus = trim(mb_substr($ps, 5));
            // payment_status ve bt_bank_message aynı kısa metinse tekrarlama
            if ($detail !== '' && self::foldTrUpper($detail) !== self::foldTrUpper($fromStatus)) {
                if (str_contains(self::foldTrUpper($detail), self::foldTrUpper($fromStatus))
                    || mb_strlen($detail) >= mb_strlen($fromStatus)) {
                    return 'hata: ' . $detail;
                }
                return 'hata: ' . $fromStatus . ' — ' . $detail;
            }
            if ($detail !== '') {
                return 'hata: ' . $detail;
            }
            return 'hata: ' . (self::humanizeBankStatus($fromStatus) ?: $fromStatus);
        }
        return $ps;
    }

    /**
     * Bulut reddi → sipariş + log için net Türkçe metin.
     *
     * @param array<string,mixed> $payload
     */
    public static function formatPaymentFailure(array $payload, string $code = '', string $msg = ''): string
    {
        $payText = self::payloadStr($payload, 'PaymentStatusText');
        $bankMsg = self::payloadStr($payload, 'BankMessage');
        $txMsg = self::payloadStr($payload, 'TransactionStatusMessage');
        $bankCode = self::payloadStr($payload, 'BankStatusCode');
        $explain = self::payloadStr($payload, 'Explanation', 'ReservedField');
        $pos = self::payloadStr($payload, 'VPosBankName', 'PosBankName');
        $bankMsg = trim((string) (preg_replace('/\s*violatorParam:\s*/iu', '', $bankMsg) ?? $bankMsg));
        $bankMsg = rtrim($bankMsg, " \t.,;");

        $generic = static function (string $cand): bool {
            return (bool) preg_match(
                '/^(ODEME[_\s-]?ALINAMADI|ISLEM[_\s-]?BASARILI|ODENMEDI|PAYMENT[_\s-]?FAILED|FAILED|601|613|331|330|335)$/iu',
                trim($cand)
            );
        };

        $core = '';
        foreach ([$bankMsg, $txMsg, $payText, $explain, $msg] as $cand) {
            $cand = trim((string) $cand);
            if ($cand === '' || $generic($cand)) {
                continue;
            }
            $human = self::humanizeBankStatus($cand);
            if ($human !== '') {
                $core = $human;
                break;
            }
        }
        $codeHints = [
            '331' => 'Kart reddedildi veya 3D tamamlanmadı',
            '330' => 'Ödeme doğrulanamadı / banka reddi',
            '335' => 'Ödeme başarısız (banka)',
            '613' => 'Ödeme alınamadı (Bulut)',
        ];
        if ($core === '') {
            if (self::looksCancelled($code, $msg)) {
                $core = 'Ödeme iptal edildi';
            } elseif (isset($codeHints[trim($code)])) {
                $core = $codeHints[trim($code)];
            } elseif (self::looksUnpaid($code, $msg)) {
                $core = 'Ödeme alınamadı';
            } else {
                $core = 'Ödeme tamamlanmadı';
            }
        }

        $parts = [$core];
        $seen = [self::foldTrUpper($core)];
        $addPart = static function (string $text) use (&$parts, &$seen): void {
            $text = trim($text);
            if ($text === '') {
                return;
            }
            $key = self::foldTrUpper($text);
            foreach ($seen as $s) {
                if ($s === $key || str_contains($s, mb_substr($key, 0, min(20, mb_strlen($key)))) || str_contains($key, mb_substr($s, 0, min(20, mb_strlen($s))))) {
                    return;
                }
            }
            $seen[] = $key;
            $parts[] = $text;
        };

        foreach ([$bankMsg, $txMsg, $explain] as $extra) {
            if ($extra === '' || $generic($extra)) {
                continue;
            }
            $addPart(self::humanizeBankStatus($extra));
        }
        if ($bankCode !== '' && !preg_match('/^(0|00|000)$/', $bankCode)) {
            $addPart('Banka kodu: ' . $bankCode);
        }
        // Bulut bazen 331’de BankMessage/AuthCode göndermez (3D yarıda / erken red)
        if (isset($codeHints[trim($code)]) && $bankMsg === '' && $txMsg === '') {
            $addPart('Banka sebep metni gelmedi — farklı kart veya banka ile tekrar deneyin (Bulut ' . trim($code) . ')');
        }
        if ($pos !== '') {
            $addPart('POS: ' . $pos);
        }
        return mb_substr(implode(' — ', $parts), 0, 280);
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function mergePreferNonEmpty(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_string($v) && trim($v) === '') {
                if (!array_key_exists($k, $base) || (is_string($base[$k]) && trim((string) $base[$k]) === '')) {
                    $base[$k] = $v;
                }
                continue;
            }
            if ($v === null) {
                continue;
            }
            $base[$k] = $v;
        }
        return $base;
    }

    private static function humanizeBankStatus(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^\d+\s*[-–:]\s*(.+)$/u', $s, $m)) {
            $s = trim($m[1]);
        }
        $u = self::foldTrUpper($s);
        $map = [
            'ISLEM BANKA TARAFINDAN ONAYLANMADI' => 'İşlem banka tarafından onaylanmadı',
            'TERMINALE BU ISLEM IZNI VERILMEMISTIR' => 'Terminale bu işlem izni verilmemiştir',
            'ODEME ALINAMADI' => 'Ödeme alınamadı',
            'ODEME_ALINAMADI' => 'Ödeme alınamadı',
            'ODENMEDI' => 'Ödeme alınamadı',
            'ISLEM BASARILI' => '',
            'ISLEM_BASARILI' => '',
            'KART REDDEDILDI' => 'Kart reddedildi',
            'DO NOT HONOUR' => 'Kart reddedildi (Do not honour)',
            'NOT SUFFICIENT FUNDS' => 'Yetersiz bakiye',
            'YETERSIZ LIMIT' => 'Yetersiz limit',
            'YETERSIZ BAKIYE' => 'Yetersiz bakiye',
            'EXPIRED CARD' => 'Kartın son kullanma tarihi geçmiş',
            'INVALID CARD' => 'Geçersiz kart',
            'STOLEN CARD' => 'Kart bloke / çalıntı bildirimi',
            'RESTRICTED CARD' => 'Kart kısıtlı',
            'TAKSIT YAPILAMAZ' => 'Bu karta taksit yapılamaz',
            '3D SECURE' => '3D Secure doğrulaması başarısız',
            '3D ISLEMI BASARILI SEKILDE SONLANMADI' => '3D işlemi başarılı şekilde sonlanmadı',
            'KARTINIZ E-TICARET ISLEMLERINE KAPALIDIR' => 'Kartınız e-ticaret işlemlerine kapalıdır. Bankanızı arayınız',
            'ISLEM IPTAL' => 'İşlem iptal edildi',
            'TIMEOUT' => 'Banka zaman aşımı',
            'SYSTEM ERROR' => 'Banka sistem hatası',
        ];
        foreach ($map as $needle => $label) {
            if ($needle !== '' && str_contains($u, self::foldTrUpper($needle))) {
                return $label;
            }
        }
        // Bilinen kalıp yoksa orijinal banka metnini olduğu gibi bırak (Türkçe bozma).
        return $s;
    }

    private static function foldTrUpper(string $s): string
    {
        $s = strtr($s, [
            'ı' => 'I', 'i' => 'I', 'İ' => 'I', 'I' => 'I',
            'ğ' => 'G', 'Ğ' => 'G',
            'ü' => 'U', 'Ü' => 'U',
            'ş' => 'S', 'Ş' => 'S',
            'ö' => 'O', 'Ö' => 'O',
            'ç' => 'C', 'Ç' => 'C',
        ]);
        $s = preg_replace('/\p{M}+/u', '', $s) ?? $s;
        $s = mb_strtoupper($s, 'UTF-8');
        $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function markPaymentFailed(int $orderId, array $payload, string $code, string $msg): string
    {
        $detail = self::formatPaymentFailure($payload, $code, $msg);
        if ($detail === '') {
            $detail = 'Ödeme alınamadı';
        }
        $status = 'hata:' . mb_substr($detail, 0, 220);
        try {
            db()->prepare('UPDATE orders SET payment_status=?, bt_bank_message=? WHERE id=?')
                ->execute([$status, mb_substr($detail, 0, 390), $orderId]);
        } catch (Throwable) {
            try {
                db()->prepare('UPDATE orders SET payment_status=? WHERE id=?')
                    ->execute([mb_substr($status, 0, 40), $orderId]);
            } catch (Throwable) {
            }
        }
        return $detail;
    }

    /**
     * Eski kısa hata kodlarını (613 ODEME_ALINAMADI vb.) Bulut’tan net metne çevirir.
     *
     * @return array{ok:bool,scanned:int,updated:int,skipped:int}
     */
    public static function backfillFailedPaymentMessages(int $limit = 40): array
    {
        HfsMigrate::up();
        $lim = max(1, min(80, $limit));
        $rows = db()->query(
            "SELECT id, code, bt_guid, payment_status, bt_bank_message
             FROM orders
             WHERE payment='bulut' AND payment_status LIKE 'hata:%' AND bt_guid<>''
             ORDER BY id DESC
             LIMIT {$lim}"
        )->fetchAll() ?: [];
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $guid = trim((string) ($row['bt_guid'] ?? ''));
            if ($guid === '') {
                $skipped++;
                continue;
            }
            $info = self::paymentInfo($guid);
            if (!is_array($info) || $info === [] || self::isApiQuotaError($info)) {
                $skipped++;
                continue;
            }
            $outcome = self::paymentOutcome($info);
            if (self::isPaymentSuccess($outcome['code'], $outcome['msg'], $info)) {
                $skipped++;
                continue;
            }
            $before = (string) ($row['payment_status'] ?? '');
            $detail = self::markPaymentFailed((int) $row['id'], $info, $outcome['code'], $outcome['msg']);
            if ($detail !== '' && $detail !== $before && !str_ends_with($before, $detail)) {
                $updated++;
                self::logBt('odeme_hata_backfill', [
                    'ok' => true,
                    'order' => (int) $row['id'],
                    'code' => (string) ($row['code'] ?? ''),
                    'before' => $before,
                    'detail' => $detail,
                ]);
            } else {
                $skipped++;
            }
        }
        return ['ok' => true, 'scanned' => count($rows), 'updated' => $updated, 'skipped' => $skipped];
    }

    private static function unpaidLabel(string $code, string $msg): string
    {
        if (self::looksCancelled($code, $msg)) {
            return 'Ödeme iptal edildi';
        }
        if (self::looksUnpaid($code, $msg) || $code === '331' || $code === '613') {
            return self::humanizeBankStatus($msg) ?: 'Ödeme alınamadı';
        }
        return 'Ödeme bekleniyor / tamamlanmadı';
    }

    private static function looksUnpaid(string $code, string $msg): bool
    {
        $t = mb_strtoupper(trim($code . ' ' . $msg), 'UTF-8');
        $t = str_replace(['İ', 'İ'], 'I', $t);
        // API kotası / teknik hata ≠ ödeme alınamadı
        if (str_contains($t, 'SORGU LIMIT') || str_contains($t, 'MAX ADET')) {
            return false;
        }
        foreach (['ODEME_ALINAMADI', 'ODENMEDI', 'PAYMENT_FAILED', 'FAILED', 'BASARISIZ'] as $w) {
            if ($w !== '' && str_contains($t, $w)) {
                return true;
            }
        }
        return in_array(strtoupper(trim($code)), ['331', '330', '335'], true);
    }

    /** @param array<string,mixed> $payload */
    private static function isPaymentSuccess(string $code, string $msg, array $payload = []): bool
    {
        if (self::looksUnpaid($code, $msg) || self::looksCancelled($code, $msg)) {
            return false;
        }
        $payText = self::payloadStr($payload, 'PaymentStatusText');
        if ($payText !== '' && self::looksUnpaid('', $payText)) {
            return false;
        }
        if (self::isSuccess($code)) {
            return true;
        }
        // Dönüşte StatusCode=601 / boş olabilir; PaymentStatusCode veya banka onayı asıl kanıt
        $payCode = self::payloadStr($payload, 'PaymentStatusCode');
        if ($payCode !== '' && self::isSuccess($payCode) && !self::looksUnpaid($payCode, $payText)) {
            return true;
        }
        $tx = self::payloadStr($payload, 'TransactionStatusID', 'TransactionStatusMessage');
        $bank = self::payloadStr($payload, 'BankMessage', 'BankStatusCode');
        $payId = self::payloadStr($payload, 'PaymentID', 'AuthCode');
        $txU = mb_strtoupper($tx . ' ' . $bank, 'UTF-8');
        $txU = str_replace(['İ', 'İ'], 'I', $txU);
        if ($payId !== '' && (str_contains($txU, 'APPROVED') || str_contains($txU, 'ONAY') || $tx === '1' || $tx === '2')) {
            return true;
        }
        return false;
    }

    private static function looksCancelled(string $code, string $msg): bool
    {
        $t = mb_strtoupper(trim($code . ' ' . $msg), 'UTF-8');
        $t = str_replace(['İ', 'İ'], 'I', $t);
        foreach (['IPTAL', 'CANCEL', 'VOID', 'REFUND', 'IADE', 'REVERSE'] as $w) {
            if ($w !== '' && str_contains($t, $w)) {
                return true;
            }
        }
        return in_array(strtoupper(trim($code)), ['333', '334', '99', '998', '999'], true);
    }

    private static function placeCariOrder(array $u, array $snap, float $amount): string
    {
        $code = Catalog::newOrderCode();
        $co = $snap['company'] ?? $u;
        $cid = (int) ($snap['company_id'] ?? 0);
        if ($cid < 1) {
            $cid = HfsPricing::companyId($u);
        }
        if ($cid < 1) {
            $cid = (int) ($u['id'] ?? 0);
        }
        db()->prepare("INSERT INTO orders (code,status,name,email,phone,city,district,address,note,country,coupon_code,discount,channel,ship_slot,payment,subtotal,shipping,total,ip,created_at,user_id,company_id,is_cari,payment_status)
            VALUES (?, 'odeme', ?,?,?,?, '', ?, 'Cari hesap ödemesi', 'TR', '', 0, 'cari', '', 'bulut', ?, 0, ?, ?, NOW(), ?, ?, 1, 'bekliyor')")
            ->execute([
                $code,
                $u['display_name'] ?: $u['username'],
                $u['email'],
                $u['phone'] ?? '',
                $u['city'] ?? '',
                $u['address'] ?? $co['address'] ?? '-',
                $amount,
                $amount,
                $_SERVER['REMOTE_ADDR'] ?? '',
                (int) $u['id'],
                $cid,
            ]);
        $oid = (int) db()->lastInsertId();
        db()->prepare('INSERT INTO order_items (order_id,product_id,variant_id,title,sku,qty,price,note) VALUES (?,?,NULL,?,?,1,?,?)')
            ->execute([$oid, 0, 'Cari hesap ödemesi', 'CARI', $amount, '']);
        return $code;
    }

    private static function tagOrder(string $code, array $u, string $status, int $isCari): void
    {
        $cid = HfsPricing::companyId($u);
        db()->prepare('UPDATE orders SET user_id=?, company_id=?, status=?, is_cari=? WHERE code=?')
            ->execute([(int) $u['id'], $cid, $status, $isCari, $code]);
    }

    private static function parseAmount(string $raw): float
    {
        $raw = trim(str_replace(["\xc2\xa0", ' '], '', $raw));
        if ($raw === '') {
            return 0.0;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return round((float) $raw, 2);
        }
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        return round((float) $raw, 2);
    }

    /** BulutTahsilat BillPhone / DeliveryPhone: en fazla 10 rakam (baştaki 0 / 90 yok). */
    private static function btPhone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') {
            $d = preg_replace('/\D+/', '', (string) setting('phone', '2242161601')) ?: '2242161601';
        }
        if (str_starts_with($d, '90') && strlen($d) > 10) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) > 10) {
            $d = substr($d, -10);
        }
        return strlen($d) === 10 ? $d : '2242161601';
    }

    private static function startHosted(string $code): string
    {
        if (!self::configured()) {
            self::flashError('BulutTahsilat bilgileri eksik. Yönetim → Ayarlar.');
            return '';
        }
        $order = Catalog::orderByCode($code);
        if (!$order) {
            self::flashError('Sipariş kaydı oluşturulamadı.');
            return '';
        }
        bmn_session_release();
        $isCari = (int) ($order['is_cari'] ?? 0) === 1;
        $date = date('Y-m-d H:i:s');
        $total = self::amount((float) $order['total']);
        $currency = 'TRY';
        $hash = md5(trim((string) setting('bt_secret')) . trim((string) setting('bt_username')) . trim((string) setting('bt_firm')) . $code . $date . $total . $currency);
        $lines = [];
        foreach ($order['items'] as $item) {
            $qty = max(1, (int) $item['qty']);
            $inc = round((float) $item['price'], 2);
            $ex = $isCari ? $inc : round($inc / 1.20, 2);
            $lines[] = [
                'ProductName' => (string) $item['title'],
                'ProductInfo' => (string) ($item['sku'] ?: $item['product_id']),
                'SalePrice' => $ex,
                'SalePriceWithVat' => $inc,
                'Quantity' => $qty,
                'SaleTotalPrice' => round($ex * $qty, 2),
                'SaleTotalPriceWithVat' => round($inc * $qty, 2),
            ];
        }
        if ($lines === []) {
            $inc = (float) $total;
            $ex = $isCari ? $inc : round($inc / 1.20, 2);
            $lines[] = [
                'ProductName' => ($isCari ? 'Cari hesap ödemesi ' : 'Sipariş ') . $code,
                'ProductInfo' => $code,
                'SalePrice' => $ex,
                'SalePriceWithVat' => $inc,
                'Quantity' => 1,
                'SaleTotalPrice' => $ex,
                'SaleTotalPriceWithVat' => $inc,
            ];
        }
        $totalEx = 0.0;
        foreach ($lines as $line) {
            $totalEx += (float) $line['SaleTotalPrice'];
        }
        $totalEx = $isCari ? (float) $total : round($totalEx, 2);
        $name = trim((string) $order['name']);
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts) ?: $name;
        $last = $parts ? implode(' ', $parts) : $first;
        $phone = self::btPhone((string) ($order['phone'] ?? ''));
        $addr = trim((string) $order['address']);
        if ($addr === '') {
            $addr = '-';
        }
        $city = trim((string) $order['city']);
        if ($city === '') {
            $city = 'Bursa';
        }
        $email = trim((string) $order['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = (string) setting('email', 'info@hfstarim.com');
        }
        $dealer = self::payDealer($order);
        $firmName = '';
        $exp = '';
        if ($dealer) {
            $firmName = trim((string) ($dealer['company'] ?: $dealer['display_name'] ?: ''));
            if ($firmName !== '') {
                $name = $firmName;
                $parts = preg_split('/\s+/', $name) ?: [$name];
                $first = array_shift($parts) ?: $name;
                $last = $parts ? implode(' ', $parts) : $first;
            }
            if (trim((string) ($dealer['phone'] ?? '')) !== '') {
                $phone = self::btPhone((string) $dealer['phone']);
            }
            if (trim((string) ($dealer['address'] ?? '')) !== '' && $addr === '-') {
                $addr = trim((string) $dealer['address']);
            }
            if (trim((string) ($dealer['city'] ?? '')) !== '' && $city === 'Bursa') {
                $city = trim((string) $dealer['city']);
            }
            if (trim((string) ($dealer['email'] ?? '')) !== '' && filter_var((string) $dealer['email'], FILTER_VALIDATE_EMAIL)) {
                $email = (string) $dealer['email'];
            }
            $exp = self::ensureDealerExpCode((int) $dealer['id']);
            if ($exp === '') {
                self::logBt('exp_missing', [
                    'dealer' => (int) $dealer['id'],
                    'company' => $firmName,
                    'err' => (string) setting('bt_last_error', ''),
                ]);
            }
        } elseif ($exp === '') {
            $exp = trim((string) setting('bt_exp', ''));
        }
        $lang = strtoupper(trim((string) setting('bt_language', 'TR'))) === 'EN' ? 'EN' : 'TR';
        $payload = [
            'UserName' => trim((string) setting('bt_username')),
            'Password' => trim((string) setting('bt_password')),
            'FirmAPICode' => trim((string) setting('bt_firm')),
            'OrderRefNo' => $code,
            'OrderDetailList' => $lines,
            'ShippingPrice' => $isCari ? 0.0 : (float) self::amount((float) $order['shipping']),
            'Discount' => $isCari ? 0.0 : (float) self::amount((float) $order['discount']),
            'TotalPrice' => $totalEx,
            'TotalPriceWithVAT' => (float) $total,
            'OrderDate' => $date,
            'Currency' => $currency,
            'Language' => $lang,
            'BillName' => $first,
            'BillSurname' => $last,
            'BillEmail' => $email,
            'BillCompany' => $firmName,
            'BillAddress' => $addr,
            'BillCounty' => $city,
            'BillCity' => $city,
            'BillCountry' => 'Türkiye',
            'BillPhone' => $phone,
            'DeliveryFirstName' => $first,
            'DeliveryLastName' => $last,
            'DeliveryPhone' => $phone,
            'DeliveryAddress' => $addr,
            'DeliveryCounty' => $city,
            'DeliveryCity' => $city,
            'DeliveryCountry' => 'Türkiye',
            'Hash' => $hash,
            'ReturnUrl' => self::hostedReturnUrl($order, $isCari),
            'PaymentExpCode' => $exp,
            'Installment' => '',
            'Description' => ($isCari ? 'Cari hesap ödemesi ' : 'HFS Bayi sipariş ') . $code,
            'ReservedField' => (string) $order['id'],
            'IsPreAuth' => false,
            'IsAllowGTSEqualInstallment' => true,
        ];
        db()->prepare('UPDATE orders SET bt_date=?, bt_total=?, bt_currency=? WHERE id=?')
            ->execute([$date, $total, $currency, (int) $order['id']]);
        $json = self::encode($payload);
        $res = self::postJson(self::API, $json);
        if ($res['raw'] === '') {
            self::flashError('BulutTahsilat sunucusuna bağlanılamadı. ' . ($res['err'] !== '' ? $res['err'] : 'cURL / allow_url_fopen kontrol edin.'));
            self::markPayError((int) $order['id'], $res['err'] !== '' ? $res['err'] : 'boş yanıt');
            return '';
        }
        $data = $res['data'];
        if (!is_array($data)) {
            self::flashError('BulutTahsilat yanıtı okunamadı.');
            self::markPayError((int) $order['id'], mb_substr($res['raw'], 0, 80));
            return '';
        }
        $url = trim((string) ($data['PaymentRedirectUrl'] ?? $data['RedirectUrl'] ?? $data['PaymentUrl'] ?? ''));
        $url = str_replace(["\r", "\n"], '', $url);
        $status = trim((string) ($data['StatusCode'] ?? '') . ' ' . (string) ($data['StatusMessage'] ?? $data['Message'] ?? ''));
        if ($url === '' || !preg_match('#^https://checkout\.buluttahsilat\.com/#i', $url)) {
            self::flashError($status !== '' ? $status : 'Ödeme sayfası alınamadı. Yönetim → Ayarlar → Bulut Tahsilat alanlarını kontrol edin.');
            self::markPayError((int) $order['id'], $status !== '' ? $status : 'URL yok');
            self::logBt('create_fail', ['code' => $code, 'status' => $status, 'body' => mb_substr($res['raw'], 0, 400)]);
            return '';
        }
        if (!empty($data['OrderGuidCode'])) {
            db()->prepare('UPDATE orders SET bt_guid=? WHERE id=?')->execute([(string) $data['OrderGuidCode'], (int) $order['id']]);
        }
        self::logBt('odeme_basladi', [
            'ok' => true,
            'order' => (int) $order['id'],
            'code' => $code,
            'is_cari' => $isCari ? 1 : 0,
            'total' => (float) ($order['total'] ?? 0),
            'guid' => (string) ($data['OrderGuidCode'] ?? ''),
            'label' => 'Ödeme bekleniyor (Bulut sayfası açıldı)',
        ]);
        $_SESSION['hfs_bt_pending'] = [
            'code' => $code,
            'id' => (int) $order['id'],
            'cari' => $isCari ? 1 : 0,
        ];
        return $url;
    }

    /** @param array<string,mixed> $order */
    private static function hostedReturnUrl(array $order, bool $isCari): string
    {
        $q = [
            'ref' => (string) ($order['code'] ?? ''),
            'oid' => (string) (int) ($order['id'] ?? 0),
            'k' => self::returnToken($order),
        ];
        if ($isCari) {
            $q['cari'] = '1';
        }
        return site_url('odeme/donus/') . '?' . http_build_query($q);
    }

    private static function flashError(string $msg): void
    {
        $msg = trim($msg);
        if ($msg === '') {
            return;
        }
        $_SESSION['hfs_bt_err'] = mb_substr($msg, 0, 400);
        try {
            setting_set('bt_last_error', date('Y-m-d H:i') . ' ' . mb_substr($msg, 0, 240));
        } catch (Throwable) {
        }
    }

    private static function markPayError(int $orderId, string $msg): void
    {
        self::markPaymentFailed($orderId, ['PaymentStatusText' => $msg, 'BankMessage' => $msg], '', $msg);
    }

    private static function logBt(string $event, array $ctx): void
    {
        $dir = BMN_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = date('c') . ' ' . $event . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($dir . '/bulut.log', $line, FILE_APPEND);
        $oid = (int) ($ctx['order'] ?? $ctx['order_id'] ?? 0);
        $code = (string) ($ctx['code'] ?? $ctx['order_code'] ?? '');
        $msg = $event;
        if ($ctx !== []) {
            $msg .= ' ' . mb_substr(json_encode($ctx, JSON_UNESCAPED_UNICODE) ?: '', 0, 1500);
        }
        $ok = !preg_match('/fail|error/i', $event);
        if (array_key_exists('ok', $ctx)) {
            $ok = (bool) $ctx['ok'];
        }
        HfsIntegrLog::write('bulut', $event, $msg, $ok, $oid, $code, $ctx);
    }

    /**
     * Bayiyi BulutTahsilat alt firması yapar, PaymentExpCode’u users.bt_exp_code’a yazar.
     */
    public static function ensureDealerExpCode(int $userId): string
    {
        if ($userId < 1 || !self::configured()) {
            return '';
        }
        $u = HfsDealers::get($userId);
        if (!$u) {
            return '';
        }
        $cid = HfsPricing::companyId($u);
        if ($cid > 0 && $cid !== (int) $u['id']) {
            $p = HfsDealers::get($cid);
            if ($p) {
                $u = $p;
                $userId = $cid;
            }
        }
        if (($u['role'] ?? '') === 'admin') {
            return '';
        }
        $have = trim((string) ($u['bt_exp_code'] ?? ''));
        if ($have !== '') {
            if (self::soapReady()) {
                self::updateSubFirm($u, $have);
            }
            return $have;
        }
        if (!self::soapReady()) {
            self::flashError('Cari kart için BulutTahsilat WEB API kullanıcısı gerekir. Sanal POS API kullanıcısıyla SubFirm açılamaz. destek@buluttahsilat.com adresine talep yazın; gelen kullanıcıyı Ayarlar → WEB API alanına kaydedin.');
            return '';
        }
        $denyUntil = (int) setting('bt_subfirm_deny_' . $userId, '0');
        if ($denyUntil > time()) {
            return '';
        }
        $code = self::addSubFirm($u);
        if ($code === '') {
            $code = self::findListedExp($u);
        }
        if ($code === '') {
            if (trim((string) ($_SESSION['hfs_bt_err'] ?? '')) === '') {
                self::flashError('BulutTahsilat cari kodu alınamadı. Bayi firma / vergi bilgilerini kontrol edin.');
            }
            return '';
        }
        try {
            db()->prepare('UPDATE users SET bt_exp_code=? WHERE id=?')->execute([$code, $userId]);
        } catch (Throwable) {
        }
        return $code;
    }

    /**
     * Canlı teşhis / ilk eşleme: bayi PaymentExpCode oluşturur.
     *
     * @return array<string,mixed>
     */
    public static function syncNamedDealer(string $username): array
    {
        $st = db()->prepare('SELECT id, username, display_name, company, bt_exp_code, tax_office, tax_number FROM users WHERE username=? LIMIT 1');
        $st->execute([$username]);
        $row = $st->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'kullanıcı yok'];
        }
        $before = trim((string) ($row['bt_exp_code'] ?? ''));
        $exp = self::ensureDealerExpCode((int) $row['id']);
        $after = HfsDealers::get((int) $row['id']);
        return [
            'ok' => $exp !== '',
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'company' => (string) ($row['company'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'tax_number' => (string) ($row['tax_number'] ?? ''),
            'before' => $before,
            'exp' => $exp,
            'saved' => trim((string) ($after['bt_exp_code'] ?? '')),
            'last' => (string) setting('bt_last_error', ''),
            'soap' => (string) setting('bt_last_soap', ''),
        ];
    }

    /** @param array<string,mixed> $order */
    private static function payDealer(array $order): ?array
    {
        $id = (int) ($order['company_id'] ?? 0);
        if ($id < 1) {
            $id = (int) ($order['user_id'] ?? 0);
        }
        if ($id < 1) {
            return null;
        }
        $u = HfsDealers::get($id);
        if (!$u) {
            return null;
        }
        $cid = HfsPricing::companyId($u);
        if ($cid > 0 && $cid !== (int) $u['id']) {
            $p = HfsDealers::get($cid);
            if ($p) {
                return $p;
            }
        }
        return $u;
    }

    /** @param array<string,mixed> $u */
    private static function addSubFirm(array $u): string
    {
        $id = (int) ($u['id'] ?? 0);
        $inner = self::subFirmAddNewInner($u);
        $raw = self::soap('SubFirmAddNew', $inner);
        try {
            setting_set('bt_last_soap', mb_substr($raw, 0, 1500));
        } catch (Throwable) {
        }
        $code = self::xmlTag($raw, 'PaymentExpCode') ?: self::xmlTag($raw, 'paymentExpCode');
        if ($code === '' && $raw !== '') {
            $alt = str_replace(['<firmAPICode>', '</firmAPICode>'], ['<firmApiCode>', '</firmApiCode>'], $inner);
            $raw2 = self::soap('SubFirmAddNew', $alt);
            try {
                setting_set('bt_last_soap', mb_substr($raw2 !== '' ? $raw2 : $raw, 0, 1500));
            } catch (Throwable) {
            }
            if ($raw2 !== '') {
                $raw = $raw2;
                $code = self::xmlTag($raw, 'PaymentExpCode') ?: self::xmlTag($raw, 'paymentExpCode');
            }
        }
        if ($code !== '') {
            return $code;
        }
        $msg = self::xmlTag($raw, 'StatusMessage') ?: self::xmlTag($raw, 'faultstring');
        $sc = self::xmlTag($raw, 'StatusCode');
        if ($sc === '625' || str_contains($msg, '625') || stripos($msg, 'yetki') !== false || stripos($msg, 'GIRIS_YETKISI') !== false) {
            $msg = 'Cari kart (SubFirm) için WEB API kullanıcısı gerekir. Sanal POS API kullanıcısıyla bu işlem yetkisiz (625). destek@buluttahsilat.com';
            try {
                setting_set('bt_subfirm_deny_' . $id, (string) (time() + 86400));
            } catch (Throwable) {
            }
        }
        self::flashError($msg !== '' ? $msg : 'SubFirmAddNew yanıtında PaymentExpCode yok.');
        self::logBt('subfirm_add_fail', [
            'user' => $id,
            'msg' => $msg,
            'status_code' => $sc,
            'request' => self::soapEnvelope($inner),
            'response' => $raw,
        ]);
        return '';
    }

    /** @param array<string,mixed> $u */
    private static function updateSubFirm(array $u, string $exp): void
    {
        if ($exp === '') {
            return;
        }
        $inner = '<SubFirmUpdate xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(self::soapUser()) . '</userName>'
            . '<password>' . self::xml(self::soapPass()) . '</password>'
            . '<firmAPICode>' . self::xml(self::soapFirm()) . '</firmAPICode>'
            . '<paymentExpCode>' . self::xml($exp) . '</paymentExpCode>'
            . self::subFirmXml($u)
            . '</SubFirmUpdate>';
        $raw = self::soap('SubFirmUpdate', $inner);
        $code = self::xmlTag($raw, 'StatusCode');
        $msg = self::xmlTag($raw, 'StatusMessage');
        if ((int) $code >= 400 || stripos($msg, 'hata') !== false || stripos($msg, 'fail') !== false) {
            self::logBt('subfirm_update_fail', [
                'user' => (int) ($u['id'] ?? 0),
                'code' => $code,
                'msg' => $msg,
            ]);
        }
    }

    /** SubFirmAddNew / SubFirmUpdate’in tüm subFirm alanları. */
    /** @param array<string,mixed> $u */
    private static function subFirmXml(array $u): string
    {
        $id = (int) ($u['id'] ?? 0);
        $firm = trim((string) ($u['company'] ?: $u['display_name'] ?: $u['username'] ?: ('Bayi ' . $id)));
        $person = trim((string) ($u['display_name'] ?: $u['username'] ?: $firm));
        $parts = preg_split('/\s+/', $person) ?: [$person];
        $first = (string) array_shift($parts);
        $last = $parts ? implode(' ', $parts) : $first;
        $acc = trim((string) ($u['bayt_customer_code'] ?? ''));
        if ($acc === '') {
            $acc = 'HFS' . $id;
        }
        $tax = preg_replace('/\D+/', '', (string) ($u['tax_number'] ?? '')) ?: '';
        $office = trim((string) ($u['tax_office'] ?? ''));
        $phone = self::btPhone((string) ($u['phone'] ?? ''));
        $city = trim((string) ($u['city'] ?? ''));
        return '<subFirm>'
            . '<FirmName>' . self::xml($firm) . '</FirmName>'
            . '<Address>' . self::xml(trim((string) ($u['address'] ?? '')) ?: '-') . '</Address>'
            . '<County>' . self::xml($city !== '' ? $city : 'Bursa') . '</County>'
            . '<CityID>' . self::cityId($city) . '</CityID>'
            . '<Phone>' . self::xml($phone) . '</Phone>'
            . '<TaxOffice>' . self::xml($office !== '' ? $office : ' ') . '</TaxOffice>'
            . '<TaxNumber>' . self::xml($tax) . '</TaxNumber>'
            . '<AuthPersName>' . self::xml($first) . '</AuthPersName>'
            . '<AuthPersSurname>' . self::xml($last) . '</AuthPersSurname>'
            . '<AuthPersGSM>' . self::xml($phone) . '</AuthPersGSM>'
            . '<AuthPersGenderID>1</AuthPersGenderID>'
            . '<Status>Active</Status>'
            . '<DealerCode>' . self::xml(mb_substr((string) ($u['username'] ?? ('HFS' . $id)), 0, 40)) . '</DealerCode>'
            . '<BusinessArea>Tarim</BusinessArea>'
            . '<AccountingCode>' . self::xml(mb_substr($acc, 0, 40)) . '</AccountingCode>'
            . '<ReservedField>' . self::xml((string) $id) . '</ReservedField>'
            . '</subFirm>';
    }

    /** @param array<string,mixed> $u */
    private static function findListedExp(array $u): string
    {
        $inner = '<SubFirmList xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(self::soapUser()) . '</userName>'
            . '<password>' . self::xml(self::soapPass()) . '</password>'
            . '<firmAPICode>' . self::xml(self::soapFirm()) . '</firmAPICode>'
            . '</SubFirmList>';
        $raw = self::soap('SubFirmList', $inner);
        if ($raw === '') {
            return '';
        }
        $sx = @simplexml_load_string($raw);
        if ($sx === false) {
            return '';
        }
        $uid = (string) ($u['id'] ?? '');
        $acc = trim((string) ($u['bayt_customer_code'] ?? ''));
        if ($acc === '') {
            $acc = 'HFS' . $uid;
        }
        $name = trim((string) ($u['company'] ?: $u['display_name'] ?: ''));
        $tax = preg_replace('/\D+/', '', (string) ($u['tax_number'] ?? '')) ?: '';
        $nodes = $sx->xpath('//*[local-name()="SubFirmReturn"]') ?: [];
        foreach ($nodes as $n) {
            $res = trim((string) ($n->ReservedField ?? ''));
            $ac = trim((string) ($n->AccountingCode ?? ''));
            $fn = trim((string) ($n->FirmName ?? ''));
            $exp = trim((string) ($n->PaymentExpCode ?? ''));
            $tn = preg_replace('/\D+/', '', (string) ($n->TaxNumber ?? '')) ?: '';
            if ($exp === '') {
                continue;
            }
            if ($res === $uid
                || ($acc !== '' && $ac === $acc)
                || ($name !== '' && mb_strtolower($fn) === mb_strtolower($name))
                || mb_strtolower(trim((string) ($n->DealerCode ?? ''))) === mb_strtolower((string) ($u['username'] ?? ''))
                || ($tax !== '' && $tn === $tax)
            ) {
                return $exp;
            }
        }
        return '';
    }

    /**
     * WEB API kullanıcısını kaydeder. Yanıtta şifre yok.
     *
     * @return array<string,mixed>
     */
    public static function persistWebApi(string $user, string $pass, string $firm = ''): array
    {
        $user = trim($user);
        $firm = strtoupper(trim($firm));
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'error' => 'bos'];
        }
        setting_set('bt_web_user', $user);
        setting_set('bt_web_pass', $pass);
        if ($firm !== '') {
            setting_set('bt_web_firm', $firm);
        }
        return [
            'ok' => true,
            'user' => $user,
            'pass_len' => strlen($pass),
            'firm' => $firm !== '',
            'ready' => self::soapReady(),
        ];
    }

    /**
     * SubFirmList ile WEB API oturumunu dener. Şifre dönmez.
     *
     * @return array<string,mixed>
     */
    public static function pingWebApi(): array
    {
        if (!self::soapReady()) {
            return ['ok' => false, 'error' => 'web api yok'];
        }
        $inner = '<SubFirmList xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(self::soapUser()) . '</userName>'
            . '<password>' . self::xml(self::soapPass()) . '</password>'
            . '<firmAPICode>' . self::xml(self::soapFirm()) . '</firmAPICode>'
            . '</SubFirmList>';
        $raw = self::soap('SubFirmList', $inner);
        $msg = self::xmlTag($raw, 'StatusMessage') ?: self::xmlTag($raw, 'faultstring');
        $code = self::xmlTag($raw, 'StatusCode');
        $n = 0;
        if ($raw !== '') {
            $sx = @simplexml_load_string($raw);
            if ($sx !== false) {
                $n = count($sx->xpath('//*[local-name()="SubFirmReturn"]') ?: []);
            }
        }
        $bad = $raw === '' || str_contains($msg, '625') || stripos($msg, 'hata') !== false || ((int) $code >= 400);
        if (!$bad) {
            try {
                setting_set('bt_last_error', '');
            } catch (Throwable) {
            }
        }
        return [
            'ok' => !$bad,
            'code' => $code,
            'msg' => mb_substr($msg, 0, 240),
            'subs' => $n,
            'user' => self::soapUser(),
        ];
    }

    /**
     * Kayaport / Bulut destek: SOAP request + response (SubFirmList veya SubFirmAddNew).
     *
     * @return array<string,mixed>
     */
    public static function exportSoapTrace(string $action = 'SubFirmList', int $dealerId = 0, bool $maskPassword = true): array
    {
        $url = 'https://ws.buluttahsilat.com/WebService/WSBankPaymentService.asmx';
        $action = trim($action) !== '' ? trim($action) : 'SubFirmList';
        $soapAction = 'http://tempuri.org/' . $action;
        if (!self::soapReady()) {
            return ['ok' => false, 'error' => 'WEB API kullanıcı/şifre kayıtlı değil', 'url' => $url, 'soap_action' => $soapAction];
        }
        $dealerMeta = null;
        if ($action === 'SubFirmList') {
            $inner = '<SubFirmList xmlns="http://tempuri.org/">'
                . '<userName>' . self::xml(self::soapUser()) . '</userName>'
                . '<password>' . self::xml(self::soapPass()) . '</password>'
                . '<firmAPICode>' . self::xml(self::soapFirm()) . '</firmAPICode>'
                . '</SubFirmList>';
        } elseif ($action === 'SubFirmAddNew') {
            $u = self::dealerRowForSubFirm($dealerId);
            if ($u === null) {
                return ['ok' => false, 'error' => 'Bayi bulunamadı (user id)', 'url' => $url, 'dealer_id' => $dealerId];
            }
            $dealerMeta = [
                'id' => (int) ($u['id'] ?? 0),
                'username' => (string) ($u['username'] ?? ''),
                'company' => (string) ($u['company'] ?? ''),
                'tax_number' => (string) ($u['tax_number'] ?? ''),
            ];
            $inner = self::subFirmAddNewInner($u);
        } else {
            return ['ok' => false, 'error' => 'Desteklenmeyen SOAP action', 'url' => $url, 'action' => $action];
        }
        $requestFull = self::soapEnvelope($inner);
        $raw = self::soap($action, $inner);
        $requestRetry = null;
        $responseRetry = null;
        if ($action === 'SubFirmAddNew' && $raw !== '' && self::xmlTag($raw, 'PaymentExpCode') === '') {
            $alt = str_replace(['<firmAPICode>', '</firmAPICode>'], ['<firmApiCode>', '</firmApiCode>'], $inner);
            if ($alt !== $inner) {
                $responseRetry = self::soap($action, $alt);
                $requestRetry = self::soapEnvelope($alt);
            }
        }
        $pass = self::soapPass();
        $requestOut = $requestFull;
        if ($maskPassword && $pass !== '') {
            $requestOut = str_replace(self::xml($pass), '***', $requestFull);
        }
        $msg = self::xmlTag($raw, 'StatusMessage') ?: self::xmlTag($raw, 'faultstring');
        $code = self::xmlTag($raw, 'StatusCode');
        $out = [
            'ok' => $raw !== '' && $code !== '625' && !str_contains($msg, '625'),
            'action' => $action,
            'url' => $url,
            'http_method' => 'POST',
            'soap_action' => $soapAction,
            'content_type' => 'text/xml; charset=utf-8',
            'web_api_user' => self::soapUser(),
            'firm_api_code' => self::soapFirm(),
            'password_masked' => $maskPassword,
            'request' => $requestOut,
            'response' => $raw,
            'status_code' => $code,
            'status_message' => $msg,
            'generated_at' => date('c'),
        ];
        if ($dealerMeta !== null) {
            $out['dealer'] = $dealerMeta;
        }
        if ($requestRetry !== null && $responseRetry !== null) {
            $out['request_retry_firmApiCode'] = $maskPassword && $pass !== ''
                ? str_replace(self::xml($pass), '***', $requestRetry)
                : $requestRetry;
            $out['response_retry_firmApiCode'] = $responseRetry;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function dealerRowForSubFirm(int $dealerId): ?array
    {
        if ($dealerId < 1) {
            return null;
        }
        $u = HfsDealers::get($dealerId);
        if (!$u) {
            return null;
        }
        $cid = HfsPricing::companyId($u);
        if ($cid > 0 && $cid !== (int) $u['id']) {
            $p = HfsDealers::get($cid);
            if ($p) {
                return $p;
            }
        }
        return $u;
    }

    /** @param array<string,mixed> $u */
    private static function subFirmAddNewInner(array $u): string
    {
        return '<SubFirmAddNew xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(self::soapUser()) . '</userName>'
            . '<password>' . self::xml(self::soapPass()) . '</password>'
            . '<firmAPICode>' . self::xml(self::soapFirm()) . '</firmAPICode>'
            . self::subFirmXml($u)
            . '</SubFirmAddNew>';
    }

    private static function soapEnvelope(string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>' . $inner . '</soap:Body></soap:Envelope>';
    }

    /**
     * BulutTahsilat alt firma / cari kartları.
     *
     * @return list<array<string,string>>
     */
    public static function fetchSubFirms(): array
    {
        if (!self::soapReady()) {
            return [];
        }
        $inner = '<SubFirmList xmlns="http://tempuri.org/">'
            . '<userName>' . self::xml(self::soapUser()) . '</userName>'
            . '<password>' . self::xml(self::soapPass()) . '</password>'
            . '<firmAPICode>' . self::xml(self::soapFirm()) . '</firmAPICode>'
            . '</SubFirmList>';
        $raw = self::soap('SubFirmList', $inner);
        if ($raw === '') {
            return [];
        }
        $sx = @simplexml_load_string($raw);
        if ($sx === false) {
            return [];
        }
        $out = [];
        foreach ($sx->xpath('//*[local-name()="SubFirmReturn"]') ?: [] as $n) {
            $out[] = [
                'firm_id' => trim((string) ($n->FirmID ?? '')),
                'firm_name' => trim((string) ($n->FirmName ?? '')),
                'exp' => trim((string) ($n->PaymentExpCode ?? $n->FirmCode ?? '')),
                'tax' => preg_replace('/\D+/', '', (string) ($n->TaxNumber ?? '')) ?: '',
                'office' => trim((string) ($n->TaxOffice ?? '')),
                'acc' => trim((string) ($n->AccountingCode ?? '')),
                'dealer' => trim((string) ($n->DealerCode ?? '')),
                'reserved' => trim((string) ($n->ReservedField ?? '')),
                'phone' => trim((string) ($n->Phone ?? $n->AuthPersGSM ?? '')),
                'city' => trim((string) ($n->County ?? $n->City ?? '')),
                'address' => trim((string) ($n->Address ?? '')),
            ];
        }
        return $out;
    }

    /** SubFirm SOAP: WEB API kullanıcısı (Sanal POS değil). */
    private static function soapUser(): string
    {
        return trim((string) setting('bt_web_user', ''));
    }

    private static function soapPass(): string
    {
        return (string) setting('bt_web_pass', '');
    }

    private static function soapFirm(): string
    {
        $w = trim((string) setting('bt_web_firm', ''));
        return $w !== '' ? $w : trim((string) setting('bt_firm', ''));
    }

    private static function soapReady(): bool
    {
        return self::soapUser() !== '' && trim(self::soapPass()) !== '';
    }

    private static function soap(string $action, string $inner): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>' . $inner . '</soap:Body></soap:Envelope>';
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init('https://ws.buluttahsilat.com/WebService/WSBankPaymentService.asmx');
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "http://tempuri.org/' . $action . '"',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $out = curl_exec($ch);
        }
        curl_close($ch);
        return is_string($out) ? $out : '';
    }

    private static function xmlTag(string $raw, string $tag): string
    {
        $t = preg_quote($tag, '/');
        if (preg_match('/<(?:\w+:)?' . $t . '\b[^>]*>([^<]*)<\/(?:\w+:)?' . $t . '>/i', $raw, $m)) {
            return trim(html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }
        return '';
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function cityId(string $city): int
    {
        $n = strtr(mb_strtolower(trim($city), 'UTF-8'), ['ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c']);
        $map = [
            'adana' => 1, 'ankara' => 6, 'antalya' => 7, 'aydin' => 9, 'balikesir' => 10,
            'bursa' => 16, 'denizli' => 20, 'diyarbakir' => 21, 'eskisehir' => 26,
            'gaziantep' => 27, 'hatay' => 31, 'mersin' => 33, 'istanbul' => 34, 'izmir' => 35,
            'kayseri' => 38, 'kocaeli' => 41, 'konya' => 42, 'manisa' => 45, 'mugla' => 48,
            'sakarya' => 54, 'samsun' => 55, 'tekirdag' => 59, 'trabzon' => 61,
        ];
        return $map[$n] ?? 16;
    }

    /** @return array{raw:string,err:string,data:?array} */
    private static function postJson(string $url, string $json): array
    {
        $raw = '';
        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json; charset=utf-8',
                        'Accept: application/json',
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $out = curl_exec($ch);
                if ($out === false) {
                    $err = curl_error($ch);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    $out = curl_exec($ch);
                    if ($out === false) {
                        $err = curl_error($ch);
                    } else {
                        $raw = (string) $out;
                        $err = '';
                    }
                } else {
                    $raw = (string) $out;
                }
                curl_close($ch);
            }
        }
        if ($raw === '') {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
                    'content' => $json,
                    'timeout' => 45,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $out = @file_get_contents($url, false, $ctx);
            if (is_string($out) && $out !== '') {
                $raw = $out;
                $err = '';
            } elseif ($err === '') {
                $err = 'file_get_contents boş döndü (allow_url_fopen kapalı olabilir)';
            }
        }
        $data = json_decode($raw, true);
        return ['raw' => $raw, 'err' => $err, 'data' => is_array($data) ? $data : null];
    }

    private static function hash(string $ref, string $date, string $total, string $currency, string $status, string $msg): string
    {
        return md5(trim((string) setting('bt_secret')) . trim((string) setting('bt_username')) . trim((string) setting('bt_firm')) . $ref . $date . $total . $currency . $status . $msg);
    }

    /**
     * Bulut Hash = StatusCode + StatusMessage (ham). Boş/601/PaymentStatus varyasyonlarını dene.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    private static function returnHashOk(array $order, array $payload, string $hash): bool
    {
        $ref = (string) ($order['code'] ?? '');
        $date = (string) ($order['bt_date'] ?? '');
        $total = (string) ($order['bt_total'] ?? '');
        $cur = (string) ($order['bt_currency'] ?: 'TRY');
        $status = self::payloadStr($payload, 'StatusCode');
        $payStatus = self::payloadStr($payload, 'PaymentStatusCode');
        $msg = self::payloadStr($payload, 'StatusMessage');
        if ($msg === '') {
            $msg = self::payloadStr($payload, 'PaymentStatusMessage');
        }
        $payMsg = self::payloadStr($payload, 'PaymentStatusText');
        $pairs = [
            [$status, $msg],
            [$payStatus, $payMsg !== '' ? $payMsg : $msg],
            [$payStatus, $msg],
            [$status, $payMsg],
            ['601', $msg],
            ['332', $msg !== '' ? $msg : 'ISLEM_BASARILI'],
            ['', $msg],
        ];
        $want = strtolower($hash);
        foreach ($pairs as [$st, $m]) {
            if ($st === '' && $m === '') {
                continue;
            }
            $expected = self::hash($ref, $date, $total, $cur, $st, $m);
            if (hash_equals(strtolower($expected), $want)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Kart / cari başarı: ödendi + POS + Bayt CreateCollection / sipariş.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    public static function applySuccessfulPayment(array $order, array $payload, string $status = '', string $msg = ''): void
    {
        $alreadyPaid = ($order['payment_status'] ?? '') === 'odendi';
        $cid = (int) ($order['company_id'] ?? 0);
        if ($cid < 1) {
            $cid = (int) ($order['user_id'] ?? 0);
        }
        $ref = self::payloadStr($payload, 'PaymentID', 'AuthCode', 'BankTransactionID');
        $mappedReturn = self::mapPaymentInfoToVpos($payload);
        if ($mappedReturn) {
            self::persistVposEnrichment((int) $order['id'], $mappedReturn);
        }
        $next = 'yeni';
        if ((int) ($order['is_cari'] ?? 0) === 1) {
            $next = 'tamam';
        } elseif ((int) ($order['pending_approval'] ?? 0) === 1 || ($order['status'] ?? '') === 'pending_b2b') {
            $next = 'pending_b2b';
        }
        db()->prepare("UPDATE orders SET status=?, payment_status='odendi', payment_ref=IF(?<>'', ?, IFNULL(payment_ref,'')) WHERE id=?")
            ->execute([$next, $ref, $ref, (int) $order['id']]);
        self::logBt('odeme_ok', [
            'ok' => true,
            'order' => (int) ($order['id'] ?? 0),
            'code' => (string) ($order['code'] ?? ''),
            'status' => $status,
            'msg' => $msg,
            'ref' => $ref,
            'is_cari' => (int) ($order['is_cari'] ?? 0),
            'total' => (float) ($order['total'] ?? 0),
            'pos' => (string) ($mappedReturn['PosBankName'] ?? ''),
            'merchant' => (string) ($mappedReturn['VPosBankMerchantID'] ?? ''),
        ]);
        $paidRow = Catalog::orderByCode((string) $order['code']);
        if ($paidRow) {
            try {
                self::enrichOrderDekont($paidRow, true);
            } catch (Throwable $e) {
                self::logBt('dekont_enrich_fail', ['order' => (int) $paidRow['id'], 'err' => $e->getMessage()]);
            }
        }
        if ((int) ($order['is_cari'] ?? 0) === 1 && !$alreadyPaid) {
            HfsFinance::addCariPayment($cid, (float) $order['total'], (int) $order['id'], 'BulutTahsilat cari');
        }
        if ($alreadyPaid) {
            // Tekrar dönüş: ledger/Bayt yoksa tamamla
            if ((int) ($order['is_cari'] ?? 0) === 1) {
                HfsFinance::addCariPayment($cid, (float) $order['total'], (int) $order['id'], 'BulutTahsilat cari');
            }
            return;
        }
        $paid = Catalog::orderByCode((string) $order['code']);
        if (!$paid) {
            return;
        }
        if ((int) ($paid['is_cari'] ?? 0) !== 1 && ($paid['payment'] ?? '') === 'bulut') {
            try {
                HfsBayt::postOrderBulutCollection((int) $paid['id'], false);
            } catch (Throwable $e) {
                self::logBt('bayt_collection_fail', ['order' => (int) $paid['id'], 'err' => $e->getMessage()]);
            }
        }
        if ((int) ($paid['is_cari'] ?? 0) !== 1 && $next !== 'pending_b2b') {
            HfsBayt::onConfirmed($paid);
        }
        HfsMail::orderPaid($paid);
    }

    private static function isSuccess(string $code): bool
    {
        $c = strtoupper(trim($code));
        // 601 = Bulut API genel OK; ödeme onayı değildir (PaymentStatusCode 332 kullanılır).
        return in_array($c, ['332', '00', '000', '0', 'SUCCESS', 'OK', 'APPROVED'], true);
    }

    /** Bulut PaymentInfo / dönüş tutarı ile sipariş tutarını karşılaştırır. */
    /** @param array<string,mixed> $order @param array<string,mixed> $payload */
    private static function verifyBulutAmount(array $order, array $payload): array
    {
        $want = round((float) ($order['total'] ?? 0), 2);
        if ($want <= 0) {
            return ['ok' => false, 'msg' => 'Sipariş tutarı geçersiz.'];
        }
        $got = 0.0;
        foreach (['TotalPriceWithVAT', 'TotalPriceWithVat', 'Amount', 'PaymentAmount', 'TotalAmount'] as $k) {
            $v = self::payloadStr($payload, $k);
            if ($v !== '' && is_numeric(str_replace(',', '.', $v))) {
                $got = round((float) str_replace(',', '.', $v), 2);
                break;
            }
        }
        if ($got <= 0 && (string) ($order['bt_total'] ?? '') !== '') {
            $got = round((float) $order['bt_total'], 2);
        }
        if ($got > 0 && abs($got - $want) > 0.05) {
            return ['ok' => false, 'msg' => 'Bulut tutarı (' . $got . ') sipariş (' . $want . ') ile uyuşmuyor.'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    /** @param array<string,mixed> $payload */
    private static function encode(array $payload): string
    {
        $money = ['ShippingPrice', 'Discount', 'TotalPrice', 'TotalPriceWithVAT', 'SalePrice', 'SalePriceWithVat', 'SaleTotalPrice', 'SaleTotalPriceWithVat'];
        $repl = [];
        $walk = static function ($data) use (&$walk, &$repl, $money) {
            if (!is_array($data)) {
                return $data;
            }
            $out = [];
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $out[$k] = $walk($v);
                    continue;
                }
                if (in_array((string) $k, $money, true) && is_numeric($v)) {
                    $tok = 'HFSAMT' . count($repl) . 'Z';
                    $repl['"' . $tok . '"'] = number_format((float) $v, 2, '.', '');
                    $out[$k] = $tok;
                    continue;
                }
                $out[$k] = $v;
            }
            return $out;
        };
        $json = json_encode($walk($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return str_replace(array_keys($repl), array_values($repl), (string) $json);
    }
}
