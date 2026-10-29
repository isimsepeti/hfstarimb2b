<?php
declare(strict_types=1);

final class HfsFinance
{
    private const OPEN = ['yeni', 'hazirlaniyor', 'bekliyor', 'kargoda', 'on-hold', 'pending_b2b'];

    /** Sipariş checkout’ta kartla (Bulut) tahsil edildiyse cari borç sayılmaz. */
    public static function isPrepaidOrder(array $order): bool
    {
        if ((int) ($order['is_cari'] ?? 0) === 1) {
            return false;
        }
        return ($order['payment'] ?? '') === 'bulut' && ($order['payment_status'] ?? '') === 'odendi';
    }

    public static function snapshot(?array $u = null): array
    {
        try {
            return self::snapshotInner($u);
        } catch (Throwable $e) {
            error_log('HFS finance: ' . $e->getMessage());
            $u = $u ?? current_user() ?? [];
            return [
                'company' => is_array($u) ? $u : [],
                'company_id' => (int) ($u['id'] ?? 0),
                'opening' => 0.0,
                'opening_raw' => 0.0,
                'cari_paid' => 0.0,
                'open_orders' => 0.0,
                'all_orders' => 0.0,
                'monthly' => 0.0,
                'credit_limit' => 0.0,
                'monthly_limit' => 0.0,
                'exposure' => 0.0,
                'bayt_debt' => null,
                'remaining_limit' => 0.0,
                'total_open' => 0.0,
                'orders' => [],
                'payments' => [],
            ];
        }
    }

    private static function snapshotInner(?array $u = null): array
    {
        $u = $u ?? current_user();
        $co = HfsPricing::company($u);
        if (!is_array($co)) {
            $co = is_array($u) ? $u : [];
        }
        $cid = (int) ($co['id'] ?? 0);
        $openingRaw = (float) ($co['opening_balance'] ?? 0);
        $paid = (float) ($co['cari_payments_total'] ?? 0);
        $opening = $openingRaw - $paid;
        $openOrders = $cid ? self::openOrdersTotal($cid) : 0.0;
        $allOrders = $cid ? self::ordersTotal($cid) : 0.0;
        $monthly = $cid ? self::monthlyOrdersTotal($cid) : 0.0;
        $limit = (float) ($co['credit_limit'] ?? 0);
        $monthlyLimit = (float) ($co['monthly_limit'] ?? 0);
        $portalExposure = max(0, $opening) + $openOrders;
        $baytDebt = null;
        try {
            $baytBal = HfsBayt::customerBalanceForUser(is_array($u) ? $u : $co);
            if (!empty($baytBal['ok'])) {
                // Bayt Balance > 0 = borç (B); kredi limitinden düşülür.
                $baytDebt = max(0.0, (float) ($baytBal['balance'] ?? 0));
            }
        } catch (Throwable) {
            $baytDebt = null;
        }
        // Bayt varsa: muhasebe borcu + henüz Bayt’a düşmemiş açık portal siparişleri.
        $exposure = $baytDebt !== null ? ($baytDebt + $openOrders) : $portalExposure;
        $remaining = $limit > 0 ? max(0, $limit - $exposure) : 0.0;
        $totalOpen = $portalExposure;
        return [
            'company' => $co,
            'company_id' => $cid,
            'opening' => $opening,
            'opening_raw' => $openingRaw,
            'cari_paid' => $paid,
            'open_orders' => $openOrders,
            'all_orders' => $allOrders,
            'monthly' => $monthly,
            'credit_limit' => $limit,
            'monthly_limit' => $monthlyLimit,
            'exposure' => $exposure,
            'bayt_debt' => $baytDebt,
            'remaining_limit' => $remaining,
            'total_open' => $totalOpen,
            'orders' => $cid ? self::recentOrders($cid) : [],
            'payments' => $cid ? self::payments($cid) : [],
        ];
    }

    public static function memberIds(int $companyId): array
    {
        $ids = [$companyId];
        $st = db()->prepare('SELECT id FROM users WHERE parent_user_id=?');
        $st->execute([$companyId]);
        foreach ($st->fetchAll() as $row) {
            $ids[] = (int) $row['id'];
        }
        return array_values(array_unique($ids));
    }

    public static function openOrdersTotal(int $companyId): float
    {
        return self::sumOrders($companyId, self::OPEN, false);
    }

    public static function ordersTotal(int $companyId): float
    {
        return self::sumOrders($companyId, ['yeni', 'hazirlaniyor', 'bekliyor', 'kargoda', 'tamam', 'on-hold', 'pending_b2b'], false);
    }

    public static function monthlyOrdersTotal(int $companyId): float
    {
        $ids = self::memberIds($companyId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE company_id IN ($ph) AND is_cari=0 AND status IN ('yeni','hazirlaniyor','bekliyor','kargoda','tamam') AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $st->execute($ids);
        return (float) $st->fetchColumn();
    }

    public static function recentOrders(int $companyId, int $n = 20): array
    {
        $ids = self::memberIds($companyId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT * FROM orders WHERE (company_id IN ($ph) OR user_id IN ($ph)) ORDER BY id DESC LIMIT " . (int) $n);
        $st->execute(array_merge($ids, $ids));
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function payments(int $companyId, int $n = 20): array
    {
        $st = db()->prepare('SELECT * FROM ledger WHERE company_id=? AND kind=? ORDER BY id DESC LIMIT ' . (int) $n);
        $st->execute([$companyId, 'cari_odeme']);
        return $st->fetchAll();
    }

    /** Tüm ödeme türlerinde kredi + aylık limit (WP checkout doğrulaması). */
    public static function checkoutGate(float $cartTotal, ?array $u = null): array
    {
        $s = self::snapshot($u);
        if ($s['credit_limit'] > 0) {
            $after = $s['exposure'] + $cartTotal;
            if ($after > $s['credit_limit']) {
                return [
                    'ok' => false,
                    'msg' => 'Kredi limitiniz ' . money($s['credit_limit']) . '. Bayt/açık bakiye + bu sipariş ' . money($after) . ' olduğu için sipariş verilemez.',
                ];
            }
        }
        if ($s['monthly_limit'] > 0 && ($s['monthly'] + $cartTotal) > $s['monthly_limit']) {
            return ['ok' => false, 'msg' => 'Aylık satınalma limiti aşıldı. Limit ' . money($s['monthly_limit']) . '.'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    public static function canOpenAccount(float $cartTotal, ?array $u = null): array
    {
        $s = self::snapshot($u);
        if ($s['credit_limit'] <= 0) {
            return ['ok' => false, 'msg' => 'Açık hesap limiti tanımlı değil.'];
        }
        $after = $s['exposure'] + $cartTotal;
        if ($after > $s['credit_limit']) {
            return ['ok' => false, 'msg' => 'Kredi limiti aşıldı. Limit ' . money($s['credit_limit']) . ', kullanım (Bayt + açık) + sipariş ' . money($after) . '.'];
        }
        if ($s['monthly_limit'] > 0 && ($s['monthly'] + $cartTotal) > $s['monthly_limit']) {
            return ['ok' => false, 'msg' => 'Aylık satınalma limiti aşıldı.'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    public static function addCariPayment(int $companyId, float $amount, int $orderId, string $note = ''): int
    {
        if ($amount <= 0 || $companyId < 1) {
            return 0;
        }
        if ($orderId > 0) {
            $st = db()->prepare('SELECT id FROM ledger WHERE order_id=? AND kind=? LIMIT 1');
            $st->execute([$orderId, 'cari_odeme']);
            $exist = $st->fetch();
            if ($exist) {
                $lid = (int) $exist['id'];
                self::pushBaytCollection($lid);
                return $lid;
            }
        }
        db()->prepare('UPDATE users SET cari_payments_total = cari_payments_total + ? WHERE id=?')->execute([$amount, $companyId]);
        db()->prepare('INSERT INTO ledger (company_id,kind,amount,order_id,note,created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$companyId, 'cari_odeme', $amount, $orderId, $note]);
        $lid = (int) db()->lastInsertId();
        $GLOBALS['bmn_settings'] = null;
        self::pushBaytCollection($lid);
        return $lid;
    }

    public static function voidCariPaymentByOrder(int $orderId, string $reason = ''): int
    {
        if ($orderId < 1) {
            return 0;
        }
        $st = db()->prepare("SELECT id FROM ledger WHERE order_id=? AND kind=? AND IFNULL(status,'ok')<>'iptal'");
        $st->execute([$orderId, 'cari_odeme']);
        $n = 0;
        foreach ($st->fetchAll() as $row) {
            if (self::voidCariPayment((int) $row['id'], $reason)) {
                $n++;
            }
        }
        return $n;
    }

    public static function voidCariPayment(int $ledgerId, string $reason = ''): bool
    {
        if ($ledgerId < 1) {
            return false;
        }
        $st = db()->prepare('SELECT * FROM ledger WHERE id=? AND kind=? LIMIT 1');
        $st->execute([$ledgerId, 'cari_odeme']);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        if (($row['status'] ?? 'ok') === 'iptal') {
            $bst = (string) ($row['bayt_status'] ?? '');
            if ($bst === 'deleted') {
                return true;
            }
            if ($bst === 'sent' || $bst === 'error' || (int) ($row['bayt_id'] ?? 0) > 0) {
                $del = HfsBayt::deleteCollection($ledgerId);
                return $del === true;
            }
            return true;
        }
        $amount = round((float) ($row['amount'] ?? 0), 2);
        $cid = (int) ($row['company_id'] ?? 0);
        db()->prepare("UPDATE ledger SET status='iptal', voided_at=NOW() WHERE id=?")->execute([$ledgerId]);
        if ($amount > 0 && $cid > 0) {
            db()->prepare('UPDATE users SET cari_payments_total = GREATEST(0, cari_payments_total - ?) WHERE id=?')
                ->execute([$amount, $cid]);
        }
        $GLOBALS['bmn_settings'] = null;
        if ((string) ($row['bayt_status'] ?? '') === 'sent' || (int) ($row['bayt_id'] ?? 0) > 0) {
            $del = HfsBayt::deleteCollection($ledgerId);
            if ($del !== true) {
                db()->prepare("UPDATE ledger SET bayt_error=? WHERE id=?")->execute(['İptal: ' . $del, $ledgerId]);
            }
        }
        return true;
    }

    private static function pushBaytCollection(int $ledgerId): void
    {
        if ($ledgerId < 1) {
            return;
        }
        try {
            HfsBayt::sendCollection($ledgerId, false);
        } catch (Throwable $e) {
            error_log('HFS Bayt tahsilat: ' . $e->getMessage());
        }
    }

    public static function minimumErrors(array $cart): array
    {
        $u = current_user();
        if (!$u) {
            return [];
        }
        $byCat = [];
        foreach ($cart['lines'] as $line) {
            if (!empty($line['is_cari'])) {
                continue;
            }
            $pid = (int) $line['product']['id'];
            $cats = Catalog::catsForProduct($pid);
            foreach ($cats as $c) {
                $min = (int) ($c['min_qty'] ?? 0);
                $cur = $c;
                $g = 0;
                while ($min < 1 && $cur && $g++ < 8) {
                    $min = (int) ($cur['min_qty'] ?? 0);
                    $cur = !empty($cur['parent_id']) ? Catalog::categoryById((int) $cur['parent_id']) : null;
                }
                if ($min < 1) {
                    continue;
                }
                $cid = (int) $c['id'];
                if (!isset($byCat[$cid])) {
                    $byCat[$cid] = ['name' => $c['name'], 'min' => $min, 'qty' => 0];
                }
                $byCat[$cid]['qty'] += (int) $line['qty'];
                $byCat[$cid]['min'] = max($byCat[$cid]['min'], $min);
            }
        }
        $err = [];
        foreach ($byCat as $row) {
            if ($row['qty'] < $row['min']) {
                $err[] = $row['name'] . ' için en az ' . $row['min'] . ' adet (sepette ' . $row['qty'] . ').';
            }
        }
        return $err;
    }

    /** @return array{0:string,1:string} */
    public static function statementDates(): array
    {
        $from = trim((string) ($_GET['bas'] ?? ''));
        $to = trim((string) ($_GET['bit'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-90 days') ?: time());
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    /**
     * @return array{
     *   from:string,to:string,snap:array<string,mixed>,start:float,end:float,debit:float,credit:float,
     *   lines:list<array{at:string,kind:string,code:string,note:string,debit:float,credit:float,balance:float}>
     * }
     */
    public static function statement(?array $u = null, ?string $from = null, ?string $to = null, ?array $baytBal = null): array
    {
        $u = $u ?? current_user();
        $snap = self::snapshot($u);
        if ($from === null || $to === null) {
            [$from, $to] = self::statementDates();
        }
        $baytAnchored = is_array($baytBal) && !empty($baytBal['ok']);
        $cid = (int) ($snap['company_id'] ?? 0);
        $openingRaw = (float) ($snap['opening_raw'] ?? 0);
        $events = [];
        if ($cid > 0) {
            $ids = self::memberIds($cid);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $ost = db()->prepare("SELECT code, total, created_at, status, payment, payment_status, payment_ref,
                IFNULL(bayt_status,'') AS bayt_status, IFNULL(bayt_approval,'') AS bayt_approval FROM orders
                WHERE (company_id IN ($ph) OR user_id IN ($ph)) AND is_cari=0 AND status NOT IN ('odeme')
                ORDER BY created_at ASC, id ASC");
            $ost->execute(array_merge($ids, $ids));
            foreach ($ost->fetchAll() as $o) {
                $prepaid = self::isPrepaidOrder($o);
                $events[] = [
                    'at' => (string) $o['created_at'],
                    'sort' => 0,
                    'kind' => 'siparis',
                    'code' => (string) $o['code'],
                    'note' => 'Sipariş · ' . hfs_order_status_for($o) . ($prepaid ? ' · Kartla ödendi' : ''),
                    'debit' => (float) $o['total'],
                    'credit' => 0.0,
                ];
                if ($prepaid) {
                    $ref = trim((string) ($o['payment_ref'] ?? ''));
                    $events[] = [
                        'at' => (string) $o['created_at'],
                        'sort' => 1,
                        'kind' => 'tahsilat',
                        'code' => (string) $o['code'],
                        'note' => 'Kart tahsilatı' . ($ref !== '' ? ' · ' . $ref : ''),
                        'debit' => 0.0,
                        'credit' => (float) $o['total'],
                    ];
                }
                if ((string) ($o['status'] ?? '') === 'iptal') {
                    $events[] = [
                        'at' => (string) $o['created_at'],
                        'sort' => 2,
                        'kind' => 'iptal',
                        'code' => (string) $o['code'],
                        'note' => 'Sipariş iptal',
                        'debit' => 0.0,
                        'credit' => (float) $o['total'],
                    ];
                    if ($prepaid) {
                        $events[] = [
                            'at' => (string) $o['created_at'],
                            'sort' => 3,
                            'kind' => 'iptal',
                            'code' => (string) $o['code'],
                            'note' => 'Kart tahsilatı iptal',
                            'debit' => (float) $o['total'],
                            'credit' => 0.0,
                        ];
                    }
                }
            }
            $pst = db()->prepare("SELECT id, amount, note, created_at, order_id, IFNULL(status,'ok') AS status, voided_at FROM ledger WHERE company_id=? AND kind=? ORDER BY created_at ASC, id ASC");
            $pst->execute([$cid, 'cari_odeme']);
            foreach ($pst->fetchAll() as $p) {
                $note = trim((string) ($p['note'] ?? '')) !== '' ? (string) $p['note'] : 'Cari tahsilat';
                $code = 'HFS-T-' . (int) $p['id'];
                $events[] = [
                    'at' => (string) $p['created_at'],
                    'sort' => 0,
                    'kind' => 'tahsilat',
                    'code' => $code,
                    'note' => $note,
                    'debit' => 0.0,
                    'credit' => (float) $p['amount'],
                ];
                if (($p['status'] ?? '') === 'iptal') {
                    $events[] = [
                        'at' => (string) (($p['voided_at'] ?? '') !== '' && ($p['voided_at'] ?? null) !== null ? $p['voided_at'] : $p['created_at']),
                        'sort' => 1,
                        'kind' => 'iptal',
                        'code' => $code,
                        'note' => 'Tahsilat iptal · ' . $note,
                        'debit' => (float) $p['amount'],
                        'credit' => 0.0,
                    ];
                }
            }
        }
        usort($events, static function (array $a, array $b): int {
            $c = strcmp($a['at'], $b['at']);
            if ($c !== 0) {
                return $c;
            }
            return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        });
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';
        $bal = $baytAnchored ? (float) ($baytBal['balance'] ?? 0) : $openingRaw;
        $inRange = [];
        foreach ($events as $ev) {
            if (!$baytAnchored && $ev['at'] < $fromTs) {
                $bal += $ev['debit'] - $ev['credit'];
                continue;
            }
            if ($ev['at'] <= $toTs) {
                if (!$baytAnchored && $ev['at'] < $fromTs) {
                    continue;
                }
                if ($ev['at'] >= $fromTs) {
                    $inRange[] = $ev;
                }
            }
        }
        if (!$baytAnchored) {
            foreach ($events as $ev) {
                if ($ev['at'] < $fromTs) {
                    continue;
                }
                if ($ev['at'] <= $toTs) {
                    $inRange[] = $ev;
                }
            }
        }
        $start = $bal;
        $debit = 0.0;
        $credit = 0.0;
        $openNote = $baytAnchored ? 'Dönem başı bakiyesi (Bayt)' : 'Dönem başı bakiyesi';
        $lines = [[
            'at' => $fromTs,
            'kind' => 'acilis',
            'code' => '',
            'note' => $openNote,
            'debit' => 0.0,
            'credit' => 0.0,
            'balance' => $start,
        ]];
        foreach ($inRange as $ev) {
            $bal += $ev['debit'] - $ev['credit'];
            $debit += $ev['debit'];
            $credit += $ev['credit'];
            $ev['balance'] = $bal;
            $lines[] = $ev;
        }
        return [
            'from' => $from,
            'to' => $to,
            'snap' => $snap,
            'start' => $start,
            'end' => $bal,
            'debit' => $debit,
            'credit' => $credit,
            'lines' => $lines,
        ];
    }

    /** @param list<string> $statuses */
    private static function sumOrders(int $companyId, array $statuses, bool $includeCari): float
    {
        $ids = self::memberIds($companyId);
        $phU = implode(',', array_fill(0, count($ids), '?'));
        $phS = implode(',', array_fill(0, count($statuses), '?'));
        $cari = $includeCari ? '' : ' AND is_cari=0';
        $prepaid = " AND NOT (IFNULL(payment,'')='bulut' AND IFNULL(payment_status,'')='odendi')";
        $sql = "SELECT COALESCE(SUM(total),0) FROM orders WHERE (company_id IN ($phU) OR user_id IN ($phU)) AND status IN ($phS)$cari$prepaid";
        $st = db()->prepare($sql);
        $st->execute(array_merge($ids, $ids, $statuses));
        return (float) $st->fetchColumn();
    }
}
