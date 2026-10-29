<?php
declare(strict_types=1);

final class HfsApproval
{
    public static function canApprove(?array $u = null): bool
    {
        $u = $u ?? current_user();
        if (!$u) {
            return false;
        }
        $role = (string) ($u['b2b_role'] ?? '');
        return in_array($role, ['approver', 'finance_manager'], true)
            || in_array($u['role'] ?? '', ['admin', 'editor'], true);
    }

    public static function maybeHold(string $code): void
    {
        $order = Catalog::orderByCode($code);
        $u = current_user();
        if (!$order || !$u) {
            return;
        }
        if ((int) ($order['is_cari'] ?? 0) === 1) {
            return;
        }
        $role = (string) ($u['b2b_role'] ?? '');
        if ($role !== 'buyer') {
            return;
        }
        $co = HfsPricing::company($u);
        $limit = (float) ($co['approval_limit'] ?? 0);
        if ($limit <= 0) {
            return;
        }
        $total = (float) $order['total'];
        if ($total <= $limit) {
            return;
        }
        db()->prepare("UPDATE orders SET status='pending_b2b', pending_approval=1, approval_limit_used=? WHERE id=?")
            ->execute([$limit, (int) $order['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function pendingFor(?array $u = null): array
    {
        $u = $u ?? current_user();
        if (!$u) {
            return [];
        }
        $cid = HfsPricing::companyId($u);
        $ids = HfsFinance::memberIds($cid);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT * FROM orders WHERE status='pending_b2b' AND (company_id IN ($ph) OR user_id IN ($ph)) ORDER BY id DESC LIMIT 40");
        $st->execute(array_merge($ids, $ids));
        return $st->fetchAll();
    }

    public static function act(int $orderId, string $action, ?array $u = null): bool
    {
        $u = $u ?? current_user();
        if (!$u || !in_array($action, ['approve', 'reject'], true)) {
            return false;
        }
        $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order || ($order['status'] ?? '') !== 'pending_b2b') {
            return false;
        }
        $admin = in_array($u['role'] ?? '', ['admin', 'editor'], true);
        if (!$admin) {
            if (!self::canApprove($u)) {
                return false;
            }
            $cid = HfsPricing::companyId($u);
            $oids = HfsFinance::memberIds($cid);
            if (!in_array((int) $order['company_id'], $oids, true) && !in_array((int) $order['user_id'], $oids, true)) {
                return false;
            }
        }
        if ($action === 'approve') {
            $next = ($order['payment'] ?? '') === 'acik_hesap' ? 'bekliyor' : 'yeni';
            db()->prepare("UPDATE orders SET status=?, pending_approval=0 WHERE id=?")->execute([$next, $orderId]);
            $fresh = Catalog::orderByCode((string) $order['code']);
            if ($fresh) {
                HfsMail::orderApproved($fresh);
                HfsBayt::onConfirmed($fresh);
            }
        } else {
            Catalog::cancelOrder($order, 'Onay reddi');
            $order['status'] = 'iptal';
            HfsMail::orderRejected($order);
        }
        return true;
    }

    private static function restock(int $orderId): void
    {
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([$orderId]);
        $stockP = db()->prepare('UPDATE products SET stock=stock+? WHERE id=?');
        $stockV = db()->prepare('UPDATE variants SET stock=stock+? WHERE id=?');
        foreach ($it->fetchAll() as $row) {
            $q = (int) $row['qty'];
            if ($q < 1) {
                continue;
            }
            $stockP->execute([$q, (int) $row['product_id']]);
            if (!empty($row['variant_id'])) {
                $stockV->execute([$q, (int) $row['variant_id']]);
            }
        }
    }
}
