<?php
declare(strict_types=1);

/**
 * Yurtiçi Kargo SOAP — B2B AÖ (alıcı ödemeli) sevkiyat.
 * cargoKey / invoiceKey = sipariş kodu; takip no queryShipment ile alınır.
 */
final class HfsYurtici
{
    private const PROD = 'https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices';
    private const PROD_ALT = 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices';
    private const TEST = 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices';

    public static function configured(): bool
    {
        return setting('yk_enabled', '0') === '1'
            && trim((string) setting('yk_user', '')) !== ''
            && trim((string) setting('yk_pass', '')) !== '';
    }

    /** @return array{ok:bool,msg:string,tracking?:string,job_id?:string,raw?:mixed} */
    public static function createAndTrack(array $order): array
    {
        $created = self::createShipment($order);
        if (!$created['ok'] && empty($created['already'])) {
            return $created;
        }
        $q = self::queryAndApply($order);
        if ($q['ok']) {
            return $q;
        }
        // Oluştu ama henüz docId yok — jobId kaydet
        if (!empty($created['job_id'])) {
            self::saveMeta((int) $order['id'], (string) $created['job_id'], 'NOP', '');
        }
        return [
            'ok' => true,
            'msg' => ($created['msg'] ?? 'YK kaydı oluştu') . '; takip no henüz yok, biraz sonra “Takip çek” deneyin.',
            'job_id' => (string) ($created['job_id'] ?? ''),
        ];
    }

    /** @return array{ok:bool,msg:string,tracking?:string,job_id?:string,already?:bool,raw?:mixed} */
    public static function createShipment(array $order): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'msg' => 'Yurtiçi ayarları eksik (Ayarlar → Yurtiçi Kargo).'];
        }
        $code = trim((string) ($order['code'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'msg' => 'Sipariş kodu yok.'];
        }
        $name = self::receiverName($order);
        $phone = self::phone10((string) ($order['phone'] ?? ''));
        $addr = trim((string) ($order['address'] ?? ''));
        $city = trim((string) ($order['city'] ?? ''));
        $town = trim((string) ($order['district'] ?? ''));
        if (mb_strlen($name) < 5) {
            return ['ok' => false, 'msg' => 'Alıcı adı en az 5 karakter olmalı.'];
        }
        if (mb_strlen($addr) < 5) {
            return ['ok' => false, 'msg' => 'Adres en az 5 karakter olmalı.'];
        }
        if (strlen($phone) !== 10) {
            return ['ok' => false, 'msg' => 'Telefon 10 haneli olmalı (örn. 5xxxxxxxxx).'];
        }
        if ($city === '' || $town === '') {
            return ['ok' => false, 'msg' => 'İl ve ilçe zorunlu.'];
        }

        $vo = [
            'cargoKey' => $code,
            'invoiceKey' => $code,
            'receiverCustName' => $name,
            'receiverAddress' => mb_substr($addr, 0, 200),
            'cityName' => $city,
            'townName' => $town,
            'receiverPhone1' => $phone,
            'emailAddress' => trim((string) ($order['email'] ?? '')),
            'cargoCount' => '1',
            'desi' => '1',
            'kg' => '1',
        ];
        $uid = (int) ($order['user_id'] ?? 0);
        if ($uid > 0) {
            $vo['orgReceiverCustId'] = (string) $uid;
        }

        try {
            $raw = self::call('createShipment', $vo);
        } catch (Throwable $e) {
            HfsIntegrLog::write('yurtici', 'create', $e->getMessage(), false, (int) ($order['id'] ?? 0), $code);
            return ['ok' => false, 'msg' => 'YK bağlantı hatası: ' . $e->getMessage()];
        }

        $result = self::dig($raw, ['ShippingOrderResultVO', 'ns1_createShipmentResponse', 'ShippingOrderResultVO']) ?? $raw;
        $flag = (string) ($result['outFlag'] ?? '');
        $msg = (string) ($result['outResult'] ?? 'YK yanıtı');
        $job = (string) ($result['jobId'] ?? '');
        $detail = $result['shippingOrderDetailVO'] ?? null;
        if (is_array($detail) && isset($detail[0])) {
            $detail = $detail[0];
        }
        $errText = is_array($detail) ? (string) ($detail['errMessage'] ?? $detail['errCode'] ?? '') : '';

        $already = stripos($msg, 'mevcut') !== false
            || stripos($msg, 'daha önce') !== false
            || stripos($errText, 'mevcut') !== false
            || $flag === '1' && stripos($msg . $errText, 'key') !== false;

        if ($flag === '0' || $flag === '0.0') {
            self::saveMeta((int) $order['id'], $job, 'CREATED', '');
            HfsIntegrLog::write('yurtici', 'create', $msg, true, (int) ($order['id'] ?? 0), $code, ['job' => $job]);
            return ['ok' => true, 'msg' => $msg !== '' ? $msg : 'Kargo oluşturuldu', 'job_id' => $job, 'raw' => $result];
        }

        if ($already || stripos($msg . $errText, 'zaten') !== false) {
            HfsIntegrLog::write('yurtici', 'create_exists', $msg . ' ' . $errText, true, (int) ($order['id'] ?? 0), $code);
            return ['ok' => true, 'already' => true, 'msg' => 'YK’da zaten kayıtlı; takip sorgulanacak.', 'job_id' => $job, 'raw' => $result];
        }

        self::saveMeta((int) $order['id'], $job, 'ERR', mb_substr($msg . ' ' . $errText, 0, 500));
        HfsIntegrLog::write('yurtici', 'create', $msg . ' ' . $errText, false, (int) ($order['id'] ?? 0), $code, ['raw' => $result]);
        return ['ok' => false, 'msg' => trim($msg . ' ' . $errText) ?: 'YK kargo oluşturulamadı', 'raw' => $result];
    }

    /** @return array{ok:bool,msg:string,tracking?:string,job_id?:string,raw?:mixed} */
    public static function queryAndApply(array $order): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'msg' => 'Yurtiçi ayarları eksik.'];
        }
        $code = trim((string) ($order['code'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'msg' => 'Sipariş kodu yok.'];
        }
        try {
            $raw = self::call('queryShipment', [
                'keys' => $code,
                'keyType' => '0',
                'addHistoricalData' => 'true',
                'onlyTracking' => 'false',
            ]);
        } catch (Throwable $e) {
            HfsIntegrLog::write('yurtici', 'query', $e->getMessage(), false, (int) ($order['id'] ?? 0), $code);
            return ['ok' => false, 'msg' => 'YK sorgu hatası: ' . $e->getMessage()];
        }

        $vo = self::dig($raw, ['ShippingDeliveryVO', 'ns1_queryShipmentResponse', 'ShippingDeliveryVO']) ?? $raw;
        $flag = (string) ($vo['outFlag'] ?? '');
        $msg = (string) ($vo['outResult'] ?? '');
        $detail = $vo['shippingDeliveryDetailVO'] ?? null;
        if (is_array($detail) && isset($detail[0]) && is_array($detail[0])) {
            $detail = $detail[0];
        }
        if (!is_array($detail)) {
            $detail = [];
        }

        $docId = trim((string) ($detail['docId'] ?? $detail['documentId'] ?? ''));
        if ($docId === '' || $docId === '0') {
            $docId = trim((string) ($detail['trackingUrl'] ?? ''));
            if (preg_match('/[?&]code=([^&]+)/', $docId, $m)) {
                $docId = rawurldecode($m[1]);
            } elseif (str_contains($docId, 'http')) {
                $docId = '';
            }
        }
        $job = (string) ($detail['jobId'] ?? $vo['jobId'] ?? '');
        $opMsg = (string) ($detail['operationMessage'] ?? '');
        $opSt = (string) ($detail['operationStatus'] ?? '');

        if ($docId !== '' && $docId !== '0') {
            $applied = self::applyTracking($order, $docId, $job, $opSt !== '' ? $opSt : 'TRACK');
            HfsIntegrLog::write('yurtici', 'query', 'Takip: ' . $docId, true, (int) ($order['id'] ?? 0), $code, ['status' => $opSt]);
            return [
                'ok' => true,
                'msg' => $applied['mailed']
                    ? 'Takip no kaydedildi ve bayiye mail gitti: ' . $docId
                    : 'Takip no kaydedildi: ' . $docId . ($opMsg !== '' ? ' (' . $opMsg . ')' : ''),
                'tracking' => $docId,
                'job_id' => $job,
                'raw' => $vo,
            ];
        }

        self::saveMeta((int) $order['id'], $job, $opSt !== '' ? $opSt : 'NOP', $opMsg);
        $okFlag = ($flag === '0' || $flag === '0.0');
        HfsIntegrLog::write('yurtici', 'query', $msg . ' ' . $opMsg, $okFlag, (int) ($order['id'] ?? 0), $code, ['status' => $opSt]);
        return [
            'ok' => $okFlag,
            'msg' => trim(($msg !== '' ? $msg . ' — ' : '') . ($opMsg !== '' ? $opMsg : 'Takip numarası henüz üretilmemiş.')),
            'job_id' => $job,
            'raw' => $vo,
        ];
    }

    /**
     * @return array{mailed:bool}
     */
    public static function applyTracking(array $order, string $tracking, string $jobId = '', string $ykStatus = ''): array
    {
        $id = (int) ($order['id'] ?? 0);
        $oldTrack = trim((string) ($order['tracking'] ?? ''));
        $tracking = trim($tracking);
        if ($id < 1 || $tracking === '') {
            return ['mailed' => false];
        }
        $st = (string) ($order['status'] ?? '');
        if (in_array($st, ['yeni', 'bekliyor', 'hazirlaniyor'], true)) {
            db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute(['kargoda', $id]);
        }
        db()->prepare('UPDATE orders SET tracking=? WHERE id=?')->execute([$tracking, $id]);
        self::saveMeta($id, $jobId, $ykStatus !== '' ? $ykStatus : 'TRACK', '');
        $fresh = Catalog::orderByCode((string) $order['code']);
        $mailed = false;
        if ($fresh && $tracking !== $oldTrack) {
            HfsMail::orderTracking($fresh);
            $mailed = true;
        }
        return ['mailed' => $mailed];
    }

    private static function saveMeta(int $orderId, string $jobId, string $status, string $err): void
    {
        if ($orderId < 1) {
            return;
        }
        try {
            db()->prepare('UPDATE orders SET yk_job_id=?, yk_status=?, yk_error=? WHERE id=?')
                ->execute([
                    mb_substr($jobId, 0, 40),
                    mb_substr($status, 0, 40),
                    $err === '' ? null : mb_substr($err, 0, 2000),
                    $orderId,
                ]);
        } catch (Throwable) {
        }
    }

    private static function receiverName(array $order): string
    {
        $n = trim((string) ($order['name'] ?? ''));
        if (mb_strlen($n) >= 5) {
            return mb_substr($n, 0, 100);
        }
        return mb_substr($n . ' Bayi', 0, 100);
    }

    private static function phone10(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '90') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '0') && strlen($d) === 11) {
            $d = substr($d, 1);
        }
        return strlen($d) === 10 ? $d : $d;
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private static function call(string $method, array $fields): array
    {
        $user = trim((string) setting('yk_user', ''));
        $pass = (string) setting('yk_pass', '');
        $lang = 'TR';
        $test = setting('yk_test', '0') === '1';
        if ($test) {
            $user = 'YKTEST';
            $pass = 'YK';
        }
        $authCreate = '<wsUserName>' . self::xml($user) . '</wsUserName>'
            . '<wsPassword>' . self::xml($pass) . '</wsPassword>'
            . '<userLanguage>' . self::xml($lang) . '</userLanguage>';
        $authQuery = '<wsUserName>' . self::xml($user) . '</wsUserName>'
            . '<wsPassword>' . self::xml($pass) . '</wsPassword>'
            . '<wsLanguage>' . self::xml($lang) . '</wsLanguage>';

        if ($method === 'createShipment') {
            $inner = $authCreate . '<ShippingOrderVO>';
            foreach ($fields as $k => $v) {
                if ($v === '') {
                    continue;
                }
                $inner .= '<' . $k . '>' . self::xml((string) $v) . '</' . $k . '>';
            }
            $inner .= '</ShippingOrderVO>';
            $body = '<ship:createShipment>' . $inner . '</ship:createShipment>';
        } elseif ($method === 'queryShipment') {
            $body = '<ship:queryShipment>' . $authQuery
                . '<keys>' . self::xml($fields['keys'] ?? '') . '</keys>'
                . '<keyType>' . self::xml($fields['keyType'] ?? '0') . '</keyType>'
                . '<addHistoricalData>' . self::xml($fields['addHistoricalData'] ?? 'true') . '</addHistoricalData>'
                . '<onlyTracking>' . self::xml($fields['onlyTracking'] ?? 'false') . '</onlyTracking>'
                . '</ship:queryShipment>';
        } else {
            throw new RuntimeException('Bilinmeyen YK metodu');
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices">'
            . '<soapenv:Header/><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';

        $urls = $test ? [self::TEST] : [self::PROD, self::PROD_ALT];
        $lastErr = '';
        foreach ($urls as $base) {
            try {
                return self::postXml($base, $xml);
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        throw new RuntimeException($lastErr !== '' ? $lastErr : 'YK yanıt vermedi');
    }

    /** @return array<string,mixed> */
    private static function postXml(string $url, string $xml): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $body === '') {
            throw new RuntimeException($err !== '' ? $err : 'Boş yanıt HTTP ' . $code);
        }
        return self::parseSoap($body);
    }

    /** @return array<string,mixed> */
    private static function parseSoap(string $xml): array
    {
        $xml = trim($xml);
        $obj = @simplexml_load_string($xml);
        if ($obj === false) {
            throw new RuntimeException('YK XML parse hatası');
        }
        $nss = $obj->getNamespaces(true) ?: [];
        foreach (array_keys($nss) as $ns) {
            $xml = preg_replace('#(</?)' . preg_quote((string) $ns, '#') . ':#', '$1' . $ns . '_', $xml) ?? $xml;
        }
        $obj2 = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($obj2 === false) {
            throw new RuntimeException('YK XML parse hatası (ns)');
        }
        $arr = json_decode(json_encode($obj2, JSON_UNESCAPED_UNICODE) ?: '{}', true);
        if (!is_array($arr)) {
            return [];
        }
        return $arr['env_Body'] ?? $arr['soapenv_Body'] ?? $arr['Body'] ?? $arr;
    }

    /** @param list<string> $path */
    private static function dig(mixed $data, array $path): mixed
    {
        if (!is_array($data)) {
            return null;
        }
        foreach ($path as $p) {
            if (isset($data[$p])) {
                return $data[$p];
            }
        }
        // flat search one level
        foreach ($data as $v) {
            if (!is_array($v)) {
                continue;
            }
            foreach ($path as $p) {
                if (isset($v[$p])) {
                    return $v[$p];
                }
            }
        }
        return null;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
