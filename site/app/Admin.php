<?php
declare(strict_types=1);

final class Admin
{
    public static function dispatch(string $path, string $method): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        $path = rtrim($path, '/') . '/';
        if ($path === '/yonetim/index.php/') {
            $path = '/yonetim/';
        }

        if ($path === '/yonetim/giris/') {
            if ($method === 'POST') {
                $remember = !empty($_POST['remember']);
                if (csrf_ok() && Auth::attempt((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''), $remember)) {
                    $GLOBALS['bmn_user'] = Auth::user();
                    $cu = current_user();
                    if (in_array($cu['role'] ?? '', ['admin', 'editor'], true)) {
                        redirect(site_url('yonetim/'));
                    }
                    self::view('login', ['err' => true, 'hint' => 'Bu hesap yönetim paneline giremez. yenal ile giriş yapın.']);
                    return;
                }
                self::view('login', ['err' => true]);
                return;
            }
            $cu = current_user();
            if ($cu && in_array($cu['role'] ?? '', ['admin', 'editor'], true)) {
                redirect(site_url('yonetim/'));
            }
            self::view('login', [
                'hint' => $cu ? 'Mağaza hesabıyla yönetim açılmaz. Admin kullanıcı ile girin.' : '',
            ]);
            return;
        }
        if ($path === '/yonetim/cikis/') {
            Auth::logout();
            redirect(site_url('yonetim/giris/'));
        }

        Auth::requireRole(['admin', 'editor']);

        if ($method === 'POST' && csrf_ok()) {
            $sil = strtoupper(trim((string) ($_POST['sil_kod'] ?? '')));
            if ($sil !== '' && Catalog::deleteUnpaid($sil)) {
                $back = str_starts_with($path, '/yonetim/siparisler/') ? 'yonetim/siparisler/' : 'yonetim/';
                redirect(site_url($back));
            }
        }

        if ($path === '/yonetim/medya/url/') {
            header('Content-Type: application/json; charset=utf-8');
            $rel = trim((string) ($_GET['rel'] ?? ''));
            echo json_encode(['url' => hfs_media($rel)], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($path === '/yonetim/medya/warm/') {
            @set_time_limit(45);
            header('Content-Type: application/json; charset=utf-8');
            $pages = max(1, min(5, (int) ($_GET['pages'] ?? 3)));
            echo json_encode(HfsMedia::warmRemoteIndex($pages), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($path === '/yonetim/medya/liste/') {
            @set_time_limit(30);
            header('Content-Type: application/json; charset=utf-8');
            $q = trim((string) ($_GET['q'] ?? ''));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $limit = max(24, min(200, (int) ($_GET['limit'] ?? 120)));
            echo json_encode(HfsMedia::listPage($q, $offset, $limit), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($path === '/yonetim/medya/sifirla/' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            if (!csrf_ok()) {
                http_response_code(403);
                echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
                return;
            }
            HfsMedia::invalidateRemoteIndex();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($path === '/yonetim/medya/yukle/' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            if (!csrf_ok()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Oturum doğrulanamadı.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                echo json_encode(['ok' => false, 'message' => 'Dosya seçilmedi.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            try {
                echo json_encode(HfsMedia::upload($file), JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'message' => 'Yükleme hatası: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        if ($path === '/yonetim/') {
            $stats = [
                'products' => (int) db()->query("SELECT COUNT(*) FROM products WHERE status='publish'")->fetchColumn(),
                'orders' => (int) db()->query("SELECT COUNT(*) FROM orders WHERE status='yeni'")->fetchColumn(),
                'messages' => (int) db()->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn(),
                'revenue' => (float) db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('yeni','hazirlaniyor','kargoda','tamam')")->fetchColumn(),
                'carts' => (int) db()->query("SELECT COUNT(*) FROM abandoned_carts WHERE recovered=0")->fetchColumn(),
                'reviews' => (int) db()->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn(),
                'dealers' => (int) db()->query("SELECT COUNT(*) FROM users WHERE role='dealer'")->fetchColumn(),
                'pending' => (int) db()->query("SELECT COUNT(*) FROM orders WHERE status='pending_b2b'")->fetchColumn(),
            ];
            $orders = db()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 8')->fetchAll();
            self::view('dashboard', compact('stats', 'orders'));
            return;
        }

        if ($path === '/yonetim/urunler/dis-aktar/') {
            $status = trim((string) ($_GET['durum'] ?? ''));
            $q = trim((string) ($_GET['q'] ?? ''));
            if (!empty($_GET['indir'])) {
                $fmt = (string) ($_GET['format'] ?? 'xlsx');
                if ($fmt === 'csv') {
                    HfsProductsExport::sendCsv($status, $q);
                } else {
                    HfsProductsExport::sendXlsx($status, $q);
                }
                return;
            }
            $total = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
            $pub = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='publish'")->fetchColumn();
            $draft = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='draft'")->fetchColumn();
            $variantN = (int) db()->query('SELECT COUNT(*) FROM variants')->fetchColumn();
            self::view('products-export', compact('total', 'pub', 'draft', 'variantN', 'status', 'q'));
            return;
        }

        if ($path === '/yonetim/urunler/') {
            $q = trim((string) ($_GET['q'] ?? ''));
            $page = max(1, (int) ($_GET['sayfa'] ?? 1));
            $per = 60;
            if ($q !== '') {
                $like = '%' . $q . '%';
                $cst = db()->prepare('SELECT COUNT(*) FROM products WHERE title LIKE ? OR sku LIKE ?');
                $cst->execute([$like, $like]);
                $total = (int) $cst->fetchColumn();
                $pager = paginate($total, $per, $page);
                $st = db()->prepare('SELECT * FROM products WHERE title LIKE ? OR sku LIKE ? ORDER BY title ASC, id DESC LIMIT ' . (int) $pager['per'] . ' OFFSET ' . (int) $pager['offset']);
                $st->execute([$like, $like]);
                $items = $st->fetchAll();
            } else {
                $total = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
                $pager = paginate($total, $per, $page);
                $items = db()->query('SELECT * FROM products ORDER BY title ASC, id DESC LIMIT ' . (int) $pager['per'] . ' OFFSET ' . (int) $pager['offset'])->fetchAll();
            }
            self::view('products', compact('items', 'q', 'pager'));
            return;
        }

        if (preg_match('#^/yonetim/urunler/(\d+)/$#', $path, $m) || $path === '/yonetim/urunler/yeni/') {
            $id = isset($m[1]) ? (int) $m[1] : 0;
            if ($method === 'POST') {
                self::saveProduct($id);
                return;
            }
            $p = $id ? Catalog::productById($id) : null;
            if ($id && !$p) {
                redirect(site_url('yonetim/urunler/'));
            }
            $variants = $id ? Catalog::variants($id) : [];
            $optGroups = Option::groupsWithValues();
            $optSelected = $id ? Option::groupIdsForProduct($id) : [];
            $cats = db()->query('SELECT * FROM categories ORDER BY path')->fetchAll();
            $selected = $id ? array_map('intval', array_column(Catalog::catsForProduct($id), 'id')) : [];
            $all = db()->query("SELECT id,title FROM products WHERE status='publish' ORDER BY title LIMIT 300")->fetchAll();
            $crossSel = $id ? array_map('intval', array_column(Catalog::links($id, 'cross'), 'id')) : [];
            $upSel = $id ? array_map('intval', array_column(Catalog::links($id, 'up'), 'id')) : [];
            $bundle = $id ? Catalog::bundleItems($id) : [];
            $defaultGifts = '';
            $defaultQtyPrices = '';
            $productOffers = [];
            $dealers = HfsDealers::mains();
            if ($id) {
                $raw = HfsDiagram::meta($id, 'default_gifts', '');
                $defaultGifts = HfsOffers::formatGifts(HfsOffers::normalize($raw));
                $qraw = HfsDiagram::meta($id, 'default_qty_prices', '');
                $defaultQtyPrices = HfsOffers::formatQtyPrices(HfsOffers::normalizeQty($qraw));
                $productOffers = HfsOffers::forProduct($id);
            }
            $optValueSelected = $id ? Option::valueIdsForProduct($id) : [];
            $galleryList = $id ? Catalog::productGallery($id) : [];
            $diagramImage = $id ? trim((string) HfsDiagram::meta($id, 'diagram_image', '')) : '';
            self::view('edit-product', compact('p', 'variants', 'optGroups', 'optSelected', 'optValueSelected', 'galleryList', 'diagramImage', 'cats', 'selected', 'all', 'crossSel', 'upSel', 'bundle', 'defaultGifts', 'defaultQtyPrices', 'productOffers', 'dealers'));
            return;
        }

        if ($path === '/yonetim/siparisler/') {
            $items = db()->query(
                "SELECT o.*,
                    (SELECT l.bayt_status FROM ledger l WHERE l.order_id=o.id AND l.kind='cari_odeme' ORDER BY l.id DESC LIMIT 1) AS ledger_bayt_status,
                    (SELECT l.bayt_id FROM ledger l WHERE l.order_id=o.id AND l.kind='cari_odeme' ORDER BY l.id DESC LIMIT 1) AS ledger_bayt_id,
                    (SELECT l.bayt_error FROM ledger l WHERE l.order_id=o.id AND l.kind='cari_odeme' ORDER BY l.id DESC LIMIT 1) AS ledger_bayt_error
                 FROM orders o
                 ORDER BY o.id DESC
                 LIMIT 200"
            )->fetchAll();
            self::view('orders', compact('items'));
            return;
        }
        if (preg_match('#^/yonetim/siparisler/([A-Z0-9-]+)/irsaliye/$#', $path, $m)) {
            $order = Catalog::orderByCode($m[1]);
            if (!$order) {
                redirect(site_url('yonetim/siparisler/'));
            }
            self::view('packing-slip', compact('order'));
            return;
        }
        if (preg_match('#^/yonetim/siparisler/([A-Z0-9-]+)/dekont/$#', $path, $m)) {
            HfsPay::downloadDekont($m[1], true);
            return;
        }
        if (preg_match('#^/yonetim/siparisler/([A-Z0-9-]+)/e-irsaliye/$#', $path, $m)) {
            $order = Catalog::orderByCode($m[1]);
            if (!$order) {
                redirect(site_url('yonetim/siparisler/'));
            }
            $preview = HfsBayt::eirsaliyePreview($order);
            $back = site_url('yonetim/siparisler/' . $order['code'] . '/');
            self::view('eirsaliye-preview', compact('order', 'preview', 'back'));
            return;
        }
        if (preg_match('#^/yonetim/siparisler/([A-Z0-9-]+)/$#', $path, $m)) {
            $order = Catalog::orderByCode($m[1]);
            if (!$order) {
                redirect(site_url('yonetim/siparisler/'));
            }
            if ($method !== 'POST') {
                try {
                    if (HfsBayt::syncOne($order)) {
                        $order = Catalog::orderByCode($m[1]) ?: $order;
                    }
                } catch (Throwable) {
                }
            }
            if ($method === 'POST' && csrf_ok()) {
                $act = (string) ($_POST['b2b_act'] ?? '');
                if (!empty($_POST['sil_siparis']) && Catalog::deleteUnpaid($order['code'])) {
                    redirect(site_url('yonetim/siparisler/'));
                }
                if (in_array($act, ['approve', 'reject'], true)) {
                    HfsApproval::act((int) $order['id'], $act);
                    redirect(site_url('yonetim/siparisler/' . $order['code'] . '/'));
                }
                if (!empty($_POST['bayt_send'])) {
                    HfsBayt::send((int) $order['id'], true);
                    redirect(site_url('yonetim/siparisler/' . $order['code'] . '/') . '?bayt=1');
                }
                if (!empty($_POST['bayt_send_order'])) {
                    HfsBayt::sendOrderOnly((int) $order['id'], true);
                    redirect(site_url('yonetim/siparisler/' . $order['code'] . '/') . '?bayt=1');
                }
                if (!empty($_POST['bayt_send_waybill'])) {
                    $disp = trim((string) ($_POST['dispatch_at'] ?? ''));
                    if ($disp !== '') {
                        $dispSql = str_replace('T', ' ', $disp) . ':00';
                        try {
                            db()->prepare('UPDATE orders SET bayt_dispatch_at=? WHERE id=?')->execute([$dispSql, (int) $order['id']]);
                        } catch (Throwable) {
                        }
                    }
                    HfsBayt::sendWaybillOnly((int) $order['id'], true);
                    redirect(site_url('yonetim/siparisler/' . $order['code'] . '/') . '?bayt=1');
                }
                if (!empty($_POST['yk_create']) || !empty($_POST['yk_track'])) {
                    HfsMigrate::up();
                    $res = !empty($_POST['yk_create'])
                        ? HfsYurtici::createAndTrack($order)
                        : HfsYurtici::queryAndApply($order);
                    $_SESSION['yk_flash'] = $res;
                    redirect(site_url('yonetim/siparisler/' . $order['code'] . '/') . '?yk=1');
                }
                if (!empty($_POST['bayt_probe_edi']) || !empty($_POST['bayt_stage_edi'])) {
                    $disp = trim((string) ($_POST['dispatch_at'] ?? ''));
                    if ($disp !== '') {
                        $dispSql = str_replace('T', ' ', $disp);
                        if (strlen($dispSql) === 16) {
                            $dispSql .= ':00';
                        }
                        try {
                            db()->prepare('UPDATE orders SET bayt_dispatch_at=? WHERE id=?')->execute([$dispSql, (int) $order['id']]);
                            $order = Catalog::orderByCode($order['code']) ?: $order;
                        } catch (Throwable) {
                        }
                    }
                    $probe = HfsBayt::repairEdiStageForOrder((int) $order['id']);
                    $_SESSION['bayt_probe'] = $probe;
                    redirect(site_url('yonetim/siparisler/' . $order['code'] . '/') . '?baytprobe=1');
                }
                $stt = (string) ($_POST['status'] ?? '');
                $old = (string) $order['status'];
                $oldTrack = trim((string) ($order['tracking'] ?? ''));
                $newTrack = trim((string) ($_POST['tracking'] ?? ($order['tracking'] ?? '')));
                if ($stt === 'iptal' && $old !== 'iptal') {
                    Catalog::cancelOrder($order, 'Panel iptal');
                } elseif (in_array($stt, ['odeme', 'bekliyor', 'yeni', 'hazirlaniyor', 'kargoda', 'tamam', 'iptal', 'pending_b2b'], true)) {
                    db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$stt, $order['id']]);
                }
                // Takip no gelince otomatik "kargoda" (iptal/tamam/ödeme bekleyen hariç)
                if ($newTrack !== '' && $oldTrack === '' && in_array($stt !== '' ? $stt : $old, ['yeni', 'bekliyor', 'hazirlaniyor'], true)) {
                    $stt = 'kargoda';
                    db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute(['kargoda', $order['id']]);
                }
                db()->prepare('UPDATE orders SET name=?, email=?, phone=?, city=?, district=?, address=?, note=?, tracking=?, ship_slot=?, country=? WHERE id=?')
                    ->execute([
                        trim((string) ($_POST['name'] ?? $order['name'])),
                        trim((string) ($_POST['email'] ?? $order['email'])),
                        trim((string) ($_POST['phone'] ?? $order['phone'])),
                        trim((string) ($_POST['city'] ?? $order['city'])),
                        trim((string) ($_POST['district'] ?? $order['district'])),
                        trim((string) ($_POST['address'] ?? $order['address'])),
                        trim((string) ($_POST['note'] ?? $order['note'])),
                        $newTrack,
                        trim((string) ($_POST['ship_slot'] ?? ($order['ship_slot'] ?? ''))),
                        strtoupper(trim((string) ($_POST['country'] ?? ($order['country'] ?? 'TR')))),
                        $order['id'],
                    ]);
                $qtys = (array) ($_POST['item_qty'] ?? []);
                $stockP = db()->prepare('UPDATE products SET stock=GREATEST(0, stock-?) WHERE id=?');
                $stockBack = db()->prepare('UPDATE products SET stock=stock+? WHERE id=?');
                foreach ($order['items'] as $it) {
                    $nid = (int) $it['id'];
                    if (!isset($qtys[$nid])) {
                        continue;
                    }
                    $nq = max(0, (int) $qtys[$nid]);
                    $oldq = (int) $it['qty'];
                    $diff = $nq - $oldq;
                    if ($nq < 1) {
                        db()->prepare('DELETE FROM order_items WHERE id=?')->execute([$nid]);
                        $stockBack->execute([$oldq, (int) $it['product_id']]);
                    } elseif ($diff !== 0) {
                        db()->prepare('UPDATE order_items SET qty=? WHERE id=?')->execute([$nq, $nid]);
                        if ($diff > 0) {
                            $stockP->execute([$diff, (int) $it['product_id']]);
                        } else {
                            $stockBack->execute([-$diff, (int) $it['product_id']]);
                        }
                    }
                }
                $sum = db()->prepare('SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id=?');
                $sum->execute([$order['id']]);
                $sub = (float) $sum->fetchColumn();
                $fresh = Catalog::orderByCode($order['code']);
                $ship = (float) ($fresh['shipping'] ?? 0);
                $disc = (float) ($fresh['discount'] ?? 0);
                $total = max(0, $sub - $disc) + $ship;
                db()->prepare('UPDATE orders SET subtotal=?, total=? WHERE id=?')->execute([$sub, $total, $order['id']]);
                $fresh = Catalog::orderByCode($order['code']);
                if ($fresh && in_array((string) ($fresh['status'] ?? ''), ['odeme', 'bekliyor', 'yeni', 'hazirlaniyor', 'kargoda', 'tamam', 'iptal', 'pending_b2b'], true)) {
                    if (($fresh['status'] ?? '') === 'tamam' && $old !== 'tamam') {
                        Review::inviteForOrder($fresh);
                    }
                    if (HfsBayt::isReadyToSend($fresh)) {
                        HfsBayt::onConfirmed($fresh);
                    }
                }
                $trackChanged = $fresh && $newTrack !== '' && $newTrack !== $oldTrack;
                if ($trackChanged) {
                    HfsMail::orderTracking($fresh);
                } elseif ($fresh) {
                    HfsMail::orderStatus($fresh, $old);
                }
                redirect(site_url('yonetim/siparisler/' . $order['code'] . '/'));
            }
            self::view('order', compact('order'));
            return;
        }

        if ($path === '/yonetim/varyantlar/') {
            if ($method === 'POST' && csrf_ok()) {
                Option::adminPost();
                redirect(site_url('yonetim/varyantlar/'));
            }
            $groups = Option::groupsWithValues();
            self::view('options', compact('groups'));
            return;
        }

        if ($path === '/yonetim/kategoriler/') {
            $catErr = '';
            if ($method === 'POST' && csrf_ok()) {
                $saved = Catalog::saveCategory($_POST, 0);
                if (is_int($saved) && $saved > 0) {
                    redirect(site_url('yonetim/kategoriler/' . $saved . '/'));
                }
                $catErr = is_string($saved) ? $saved : 'Kategori eklenemedi.';
            }
            $cats = db()->query('SELECT * FROM categories ORDER BY path')->fetchAll();
            self::view('categories', ['cats' => $cats, 'err' => $catErr]);
            return;
        }
        if (preg_match('#^/yonetim/kategoriler/(\d+)/$#', $path, $m)) {
            $id = (int) $m[1];
            $cat = Catalog::categoryById($id);
            if (!$cat) {
                redirect(site_url('yonetim/kategoriler/'));
            }
            $catErr = '';
            if ($method === 'POST' && csrf_ok()) {
                $saved = Catalog::saveCategory($_POST, $id);
                if (is_int($saved) && $saved > 0) {
                    redirect(site_url('yonetim/kategoriler/' . $id . '/?ok=1'));
                }
                $catErr = is_string($saved) ? $saved : 'Kayıt başarısız.';
                $cat = Catalog::categoryById($id) ?: $cat;
            }
            $cats = db()->query('SELECT * FROM categories ORDER BY path')->fetchAll();
            self::view('edit-category', [
                'cat' => $cat,
                'cats' => $cats,
                'err' => $catErr,
                'ok' => !empty($_GET['ok']),
            ]);
            return;
        }

        if ($path === '/yonetim/sayfalar/') {
            $items = db()->query('SELECT * FROM pages ORDER BY title')->fetchAll();
            self::view('pages', compact('items'));
            return;
        }
        if (preg_match('#^/yonetim/sayfalar/(\d+)/$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'POST' && csrf_ok()) {
                db()->prepare('UPDATE pages SET title=?, content=?, seo_title=?, seo_description=?, modified_at=NOW() WHERE id=?')
                    ->execute([
                        trim((string) $_POST['title']),
                        (string) $_POST['content'],
                        trim((string) $_POST['seo_title']),
                        trim((string) $_POST['seo_description']),
                        $id,
                    ]);
                redirect(site_url('yonetim/sayfalar/' . $id . '/'));
            }
            $st = db()->prepare('SELECT * FROM pages WHERE id=?');
            $st->execute([$id]);
            $post = $st->fetch();
            if (!$post) {
                redirect(site_url('yonetim/sayfalar/'));
            }
            self::view('edit-page', compact('post'));
            return;
        }

        if ($path === '/yonetim/mesajlar/') {
            $items = db()->query('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 100')->fetchAll();
            self::view('messages', compact('items'));
            return;
        }

        if ($path === '/yonetim/ayarlar/') {
            if ($method === 'POST' && csrf_ok()) {
                if (($_POST['bt_act'] ?? '') === 'test') {
                    $btTest = HfsPay::connectionTestFull();
                    self::view('settings', [
                        'ok' => false,
                        'cron' => site_url('cron/') . '?k=' . urlencode((string) ($GLOBALS['bmn_config']['cron_key'] ?? '')),
                        'btTest' => $btTest,
                    ]);
                    return;
                }
                if (($_POST['smtp_act'] ?? '') === 'test') {
                    $to = trim((string) ($_POST['smtp_test_to'] ?? ''));
                    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        $to = (string) (current_user()['email'] ?? setting('email', ''));
                    }
                    $html = Mailer::wrap('SMTP test', '<p>Bu bir test iletisidir. Gönderim yolu: ' . h(Mailer::smtpOn() ? 'SMTP' : 'PHP mail()') . '</p>');
                    $smtpTest = Mailer::send($to, (string) setting('site_name', 'HFS Tarım') . ' — SMTP test', $html);
                    self::view('settings', [
                        'ok' => false,
                        'cron' => site_url('cron/') . '?k=' . urlencode((string) ($GLOBALS['bmn_config']['cron_key'] ?? '')),
                        'smtpTest' => $smtpTest,
                        'smtpTestTo' => $to,
                    ]);
                    return;
                }
                foreach (['site_name', 'tagline', 'seo_title', 'seo_description', 'email', 'phone', 'free_ship_min', 'ship_fee', 'ship_intl', 'ship_cities', 'ship_slots', 'volume_2_pct', 'volume_3_pct', 'gsc_verification', 'ga4', 'iban', 'iban_name', 'media_base', 'bt_username', 'bt_web_user', 'bt_firm', 'bt_exp', 'bt_language', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_from', 'smtp_from_name', 'smtp_timeout', 'yk_user'] as $k) {
                    if (isset($_POST[$k])) {
                        setting_set($k, trim((string) $_POST[$k]));
                    }
                }
                foreach (['bt_password', 'bt_web_pass', 'bt_secret', 'smtp_pass', 'yk_pass'] as $secret) {
                    $val = (string) ($_POST[$secret] ?? '');
                    if ($val !== '') {
                        setting_set($secret, $val);
                    }
                }
                setting_set('smtp_enabled', empty($_POST['smtp_enabled']) ? '0' : '1');
                setting_set('smtp_verify', empty($_POST['smtp_verify']) ? '0' : '1');
                setting_set('bt_enabled', empty($_POST['bt_enabled']) ? '0' : '1');
                setting_set('bt_cari', empty($_POST['bt_cari']) ? '0' : '1');
                setting_set('bt_debug', empty($_POST['bt_debug']) ? '0' : '1');
                setting_set('yk_enabled', empty($_POST['yk_enabled']) ? '0' : '1');
                setting_set('yk_test', empty($_POST['yk_test']) ? '0' : '1');
                redirect(site_url('yonetim/ayarlar/') . '?ok=1');
            }
            $ck = (string) ($GLOBALS['bmn_config']['cron_key'] ?? '');
            self::view('settings', ['ok' => isset($_GET['ok']), 'cron' => site_url('cron/') . '?k=' . urlencode($ck), 'cronKey' => $ck]);
            return;
        }

        if ($path === '/yonetim/yayin/') {
            self::deploy($method);
            return;
        }

        if (AdminShop::dispatch($path, $method)) {
            return;
        }

        http_response_code(404);
        echo 'Yok';
    }

    private static function saveProduct(int $id): void
    {
        if (!csrf_ok()) {
            redirect(site_url('yonetim/urunler/'));
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = slugify((string) ($_POST['slug'] ?: $title));
        $mainSku = trim((string) ($_POST['sku'] ?? ''));
        $skuChanged = true;
        if ($id > 0) {
            $prev = Catalog::productById($id);
            if ($prev) {
                $skuChanged = strcasecmp(trim((string) ($prev['sku'] ?? '')), $mainSku) !== 0;
            }
        }
        if ($skuChanged) {
            $skuErr = Catalog::validateProductSkus($id, $mainSku, []);
            if ($skuErr !== null) {
                $back = $id > 0 ? 'yonetim/urunler/' . $id . '/' : 'yonetim/urunler/yeni/';
                redirect(site_url($back . '?sku_err=' . rawurlencode($skuErr)));
            }
        }
        $fields = [
            $slug,
            $mainSku,
            $title,
            trim((string) ($_POST['excerpt'] ?? '')),
            (string) ($_POST['content'] ?? ''),
            (float) ($_POST['price'] ?? 0),
            ($_POST['compare_price'] ?? '') === '' ? null : (float) $_POST['compare_price'],
            (int) ($_POST['stock'] ?? 0),
            in_array($_POST['status'] ?? '', ['publish', 'draft'], true) ? $_POST['status'] : 'publish',
            empty($_POST['featured']) ? 0 : 1,
            trim((string) ($_POST['featured_media'] ?? '')),
            trim((string) ($_POST['featured_alt'] ?? $title)),
            trim((string) ($_POST['brand'] ?? 'BMN')),
            trim((string) ($_POST['material'] ?? '')),
            trim((string) ($_POST['seo_title'] ?? '')),
            trim((string) ($_POST['seo_description'] ?? '')),
            in_array($_POST['type'] ?? '', ['simple', 'bundle', 'digital'], true) ? $_POST['type'] : 'simple',
            empty($_POST['personalize']) ? 0 : 1,
            (int) ($_POST['upsell_id'] ?? 0) ?: null,
        ];
        $oldStock = 0;
        if ($id > 0) {
            $old = Catalog::productById($id);
            $oldStock = (int) ($old['stock'] ?? 0);
            db()->prepare('UPDATE products SET slug=?,sku=?,title=?,excerpt=?,content=?,price=?,compare_price=?,stock=?,status=?,featured=?,featured_media=?,featured_alt=?,brand=?,material=?,seo_title=?,seo_description=?,type=?,personalize=?,upsell_id=?,modified_at=NOW() WHERE id=?')
                ->execute([...$fields, $id]);
        } else {
            db()->prepare('INSERT INTO products (slug,sku,title,excerpt,content,price,compare_price,stock,status,featured,featured_media,featured_alt,brand,material,seo_title,seo_description,type,personalize,upsell_id,published_at,modified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute($fields);
            $id = (int) db()->lastInsertId();
        }
        db()->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$id]);
        $ins = db()->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?,?)');
        foreach ((array) ($_POST['cats'] ?? []) as $cid) {
            $ins->execute([$id, (int) $cid]);
        }
        $galleryLines = preg_split('/\r\n|\r|\n/', (string) ($_POST['gallery'] ?? '')) ?: [];
        Catalog::setProductGallery($id, $galleryLines);
        if (array_key_exists('diagram_image', $_POST)) {
            Catalog::setProductMeta($id, 'diagram_image', trim((string) $_POST['diagram_image']));
        }
        $touchVariants = array_key_exists('opt_groups', $_POST) || !empty($_POST['regen_combos']);
        $sumStock = 0;
        if ($touchVariants) {
            $groupIds = array_values(array_filter(array_map('intval', (array) ($_POST['opt_groups'] ?? []))));
            Option::setProductGroups($id, $groupIds);
            $valueIds = array_values(array_filter(array_map('intval', (array) ($_POST['opt_values'] ?? []))));
            Option::setProductValues($id, $valueIds);
            $names = (array) ($_POST['v_name'] ?? []);
            $vsku = (array) ($_POST['v_sku'] ?? []);
            $vstock = (array) ($_POST['v_stock'] ?? []);
            $vprice = (array) ($_POST['v_price'] ?? []);
            $vattrs = (array) ($_POST['v_attrs'] ?? []);
            $manualRows = [];
            foreach ($names as $i => $nm) {
                $nm = trim((string) $nm);
                if ($nm === '') {
                    continue;
                }
                $pr = $vprice[$i] ?? '';
                $manualRows[] = [
                    'name' => $nm,
                    'sku' => trim((string) ($vsku[$i] ?? '')),
                    'stock' => (int) ($vstock[$i] ?? 0),
                    'price' => ($pr === '' ? null : (float) $pr),
                    'attrs' => trim((string) ($vattrs[$i] ?? '')),
                ];
            }
            $skuBase = trim((string) ($_POST['sku'] ?? ''));
            $forceCombos = !empty($_POST['regen_combos']);
            if ($manualRows !== [] && !$forceCombos) {
                $sumStock = Option::regenProductVariants($id, $skuBase, false, $manualRows);
            } else {
                $sumStock = Option::regenProductVariants($id, $skuBase, $forceCombos);
            }
            if ($sumStock > 0) {
                db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$sumStock, $id]);
            }
        }
        db()->prepare('DELETE FROM product_links WHERE product_id=?')->execute([$id]);
        $lins = db()->prepare('INSERT IGNORE INTO product_links (product_id,target_id,kind) VALUES (?,?,?)');
        foreach ((array) ($_POST['cross'] ?? []) as $tid) {
            $tid = (int) $tid;
            if ($tid > 0 && $tid !== $id) {
                $lins->execute([$id, $tid, 'cross']);
            }
        }
        foreach ((array) ($_POST['ups'] ?? []) as $tid) {
            $tid = (int) $tid;
            if ($tid > 0 && $tid !== $id) {
                $lins->execute([$id, $tid, 'up']);
            }
        }
        db()->prepare('DELETE FROM bundle_items WHERE product_id=?')->execute([$id]);
        $bins = db()->prepare('INSERT INTO bundle_items (product_id,child_id,qty) VALUES (?,?,?)');
        foreach ((array) ($_POST['bundle_id'] ?? []) as $i => $cid) {
            $cid = (int) $cid;
            $bq = max(1, (int) (($_POST['bundle_qty'][$i] ?? 1)));
            if ($cid > 0 && $cid !== $id) {
                $bins->execute([$id, $cid, $bq]);
            }
        }
        if ($oldStock < 1 && ($sumStock > 0 || (int) ($_POST['stock'] ?? 0) > 0)) {
            Catalog::fireStockAlerts($id);
        }
        HfsOffers::setDefaultGifts($id, (string) ($_POST['default_gifts'] ?? ''));
        HfsOffers::setDefaultQtyPrices($id, (string) ($_POST['default_qty_prices'] ?? ''));
        if (isset($_POST['poffer_cid'])) {
            HfsOffers::saveFromProductPost($id);
        }
        if ($skuChanged && $id > 0 && !empty($prev) && is_array($prev)) {
            $oldSku = trim((string) ($prev['sku'] ?? ''));
            if ($oldSku !== '' && strcasecmp($oldSku, $mainSku) !== 0) {
                HfsBayt::remapSku($id, $oldSku, $mainSku, $title);
            }
        }
        redirect(site_url('yonetim/urunler/' . $id . '/'));
    }

    private static function deploy(string $method): void
    {
        if ((current_user()['role'] ?? '') !== 'admin') {
            redirect(site_url('yonetim/'));
        }
        $err = '';
        $ok = '';
        $log = null;
        if ($method === 'POST' && csrf_ok()) {
            $intent = (string) ($_POST['intent'] ?? 'save');
            try {
                if ($intent === 'save') {
                    HfsDeploy::saveConfig($_POST);
                    redirect(site_url('yonetim/yayin/?ok=1'));
                }
                if ($intent === 'ftp_test') {
                    $t = HfsDeploy::testFtp($_POST);
                    $msg = implode(' ', $t['steps'] ?? []);
                    if (!empty($t['ok'])) {
                        $ok = $msg;
                    } else {
                        $err = $msg !== '' ? $msg : 'FTP doğrulanamadı.';
                    }
                } else {
                    $log = HfsDeploy::push($intent === 'dry');
                    $ok = $intent === 'dry' ? 'Paket hazırlandı, canlıya gönderilmedi.' : 'Canlıya alındı.';
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
                $log = HfsDeploy::last();
            }
        }
        if ($ok === '' && $err === '') {
            $log = $log ?? HfsDeploy::last();
        }
        self::view('deploy', [
            'cfg' => HfsDeploy::config(),
            'ready' => HfsDeploy::ready(),
            'ftpReady' => HfsDeploy::ftpReady(),
            'missing' => HfsDeploy::missing(),
            'last' => $log ?? HfsDeploy::last(),
            'err' => $err,
            'ok' => $ok !== '' ? $ok : (!empty($_GET['ok']) ? 'Kaydedildi.' : ''),
        ]);
    }

    private static function view(string $view, array $data = []): void
    {
        $data['user'] = current_user();
        extract($data, EXTR_SKIP);
        $theme = BMN_ROOT . '/yonetim/views';
        ob_start();
        require $theme . '/' . $view . '.php';
        $content = ob_get_clean();
        if ($view === 'login' || $view === 'packing-slip' || $view === 'eirsaliye-preview') {
            echo $content;
            return;
        }
        require $theme . '/layout.php';
    }
}
