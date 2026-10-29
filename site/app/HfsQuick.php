<?php
declare(strict_types=1);

final class HfsQuick
{
    public static function templates(?array $u = null): array
    {
        $u = $u ?? current_user();
        if (!$u) {
            return [];
        }
        $raw = $u['order_templates'] ?? '';
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($data) ? $data : [];
    }

    public static function saveTemplates(array $tpls, ?array $u = null): void
    {
        $u = $u ?? current_user();
        if (!$u) {
            return;
        }
        db()->prepare('UPDATE users SET order_templates=? WHERE id=?')
            ->execute([json_encode($tpls, JSON_UNESCAPED_UNICODE), (int) $u['id']]);
        $GLOBALS['bmn_user']['order_templates'] = json_encode($tpls, JSON_UNESCAPED_UNICODE);
    }

    /** @return array{ok:bool,added:int,errors:list<string>} */
    public static function addSkus(array $skus, array $qtys): array
    {
        $added = 0;
        $errors = [];
        $n = max(count($skus), count($qtys));
        for ($i = 0; $i < $n; $i++) {
            $sku = trim((string) ($skus[$i] ?? ''));
            $qty = max(1, (int) ($qtys[$i] ?? 1));
            if ($sku === '') {
                continue;
            }
            $hit = Catalog::bySku($sku);
            if (!$hit) {
                $errors[] = 'SKU bulunamadı: ' . $sku;
                continue;
            }
            Cart::add((int) $hit['product_id'], (int) $hit['variant_id'], $qty);
            $added++;
        }
        return ['ok' => true, 'added' => $added, 'errors' => $errors];
    }

    /** @return array{ok:bool,added:int,skipped:int} */
    public static function addCsv(string $content): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $content)))));
        $added = 0;
        $skipped = 0;
        if ($lines === []) {
            return ['ok' => true, 'added' => 0, 'skipped' => 0];
        }
        $sep = str_contains($lines[0], ';') ? ';' : ',';
        foreach ($lines as $idx => $line) {
            $cols = str_getcsv($line, $sep);
            $sku = trim((string) ($cols[0] ?? ''));
            $qty = max(1, (int) ($cols[1] ?? 1));
            if ($sku === '' || ($idx === 0 && preg_match('/sku|kod|ürün|urun/i', $sku))) {
                $skipped++;
                continue;
            }
            $hit = Catalog::bySku($sku);
            if (!$hit) {
                $skipped++;
                continue;
            }
            Cart::add((int) $hit['product_id'], (int) $hit['variant_id'], $qty);
            $added++;
        }
        return ['ok' => true, 'added' => $added, 'skipped' => $skipped];
    }

    public static function saveCartAsTemplate(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Şablon adı girin.'];
        }
        $items = [];
        foreach (Cart::items() as $row) {
            $items[] = ['id' => (int) $row['id'], 'vid' => (int) ($row['vid'] ?? 0), 'qty' => (int) $row['q']];
        }
        if ($items === []) {
            return ['ok' => false, 'message' => 'Sepet boş. Önce ürün ekleyin.'];
        }
        $tpls = self::templates();
        $tid = uniqid('t', true);
        $tpls[$tid] = ['name' => $name, 'items' => $items];
        self::saveTemplates($tpls);
        return ['ok' => true, 'id' => $tid, 'message' => 'Şablon kaydedildi.'];
    }

    public static function loadTemplate(string $tid): array
    {
        $tpls = self::templates();
        if (!isset($tpls[$tid])) {
            return ['ok' => false, 'message' => 'Şablon bulunamadı.'];
        }
        $added = 0;
        foreach ($tpls[$tid]['items'] as $row) {
            $pid = (int) ($row['id'] ?? 0);
            $vid = (int) ($row['vid'] ?? 0);
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($pid < 1) {
                continue;
            }
            Cart::add($pid, $vid, $qty);
            $added++;
        }
        return ['ok' => true, 'added' => $added, 'message' => $added . ' ürün sepete eklendi.'];
    }

    public static function deleteTemplate(string $tid): array
    {
        $tpls = self::templates();
        unset($tpls[$tid]);
        self::saveTemplates($tpls);
        return ['ok' => true, 'message' => 'Şablon silindi.'];
    }

    public static function reorder(int $orderId): bool
    {
        $u = current_user();
        if (!$u) {
            return false;
        }
        $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            return false;
        }
        $cid = HfsPricing::companyId($u);
        $members = HfsFinance::memberIds($cid);
        if (!in_array((int) $order['user_id'], $members, true) && (int) $order['company_id'] !== $cid) {
            return false;
        }
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([$orderId]);
        $n = 0;
        foreach ($it->fetchAll() as $row) {
            if ((string) ($row['sku'] ?? '') === 'CARI') {
                continue;
            }
            Cart::add((int) $row['product_id'], (int) ($row['variant_id'] ?? 0), max(1, (int) $row['qty']));
            $n++;
        }
        return $n > 0;
    }

    public static function handleAjax(): bool
    {
        $act = (string) ($_POST['act'] ?? $_GET['act'] ?? '');
        if (!str_starts_with($act, 'quick_')) {
            return false;
        }
        header('Content-Type: application/json; charset=utf-8');
        if (!current_user() || !csrf_ok()) {
            echo json_encode(['ok' => false, 'message' => 'Oturum gerekli.']);
            exit;
        }
        if ($act === 'quick_add') {
            $r = self::addSkus((array) ($_POST['sku'] ?? []), (array) ($_POST['qty'] ?? []));
            echo json_encode($r);
            exit;
        }
        if ($act === 'quick_csv') {
            $tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                echo json_encode(['ok' => false, 'message' => 'Dosya yüklenmedi.']);
                exit;
            }
            echo json_encode(self::addCsv((string) file_get_contents($tmp)));
            exit;
        }
        if ($act === 'quick_save_tpl') {
            echo json_encode(self::saveCartAsTemplate((string) ($_POST['name'] ?? '')));
            exit;
        }
        if ($act === 'quick_load_tpl') {
            echo json_encode(self::loadTemplate((string) ($_POST['template_id'] ?? '')));
            exit;
        }
        if ($act === 'quick_del_tpl') {
            echo json_encode(self::deleteTemplate((string) ($_POST['template_id'] ?? '')));
            exit;
        }
        echo json_encode(['ok' => false, 'message' => 'Bilinmeyen işlem.']);
        exit;
    }
}
