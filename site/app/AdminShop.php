<?php
declare(strict_types=1);

final class AdminShop
{
    public static function dispatch(string $path, string $method): bool
    {
        if ($path === '/yonetim/kuponlar/') {
            if ($method === 'POST' && csrf_ok()) {
                $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                if ($code !== '') {
                    db()->prepare('INSERT INTO coupons (code,type,value,min_subtotal,max_uses,used,status) VALUES (?,?,?,?,?,0,\'publish\')')
                        ->execute([
                            $code,
                            in_array($_POST['type'] ?? '', ['percent', 'fixed', 'free_ship'], true) ? $_POST['type'] : 'percent',
                            (float) ($_POST['value'] ?? 0),
                            (float) ($_POST['min_subtotal'] ?? 0),
                            (int) ($_POST['max_uses'] ?? 0),
                        ]);
                }
                redirect(site_url('yonetim/kuponlar/'));
            }
            $items = db()->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll();
            self::view('coupons', compact('items'));
            return true;
        }
        if (preg_match('#^/yonetim/kuponlar/(\d+)/sil/$#', $path, $m) && $method === 'POST' && csrf_ok()) {
            db()->prepare('DELETE FROM coupons WHERE id=?')->execute([(int) $m[1]]);
            redirect(site_url('yonetim/kuponlar/'));
        }
        if ($path === '/yonetim/yorumlar/') {
            if ($method === 'POST' && csrf_ok()) {
                $id = (int) ($_POST['id'] ?? 0);
                if (!empty($_POST['reply'])) {
                    db()->prepare('UPDATE reviews SET reply=? WHERE id=?')->execute([trim((string) $_POST['reply']), $id]);
                }
                if (!empty($_POST['status']) && in_array($_POST['status'], ['publish', 'pending', 'spam'], true)) {
                    db()->prepare('UPDATE reviews SET status=? WHERE id=?')->execute([$_POST['status'], $id]);
                }
                redirect(site_url('yonetim/yorumlar/'));
            }
            $items = db()->query('SELECT r.*, p.title ptitle FROM reviews r LEFT JOIN products p ON p.id=r.product_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
            self::view('reviews', compact('items'));
            return true;
        }
        if ($path === '/yonetim/sepetler/') {
            $items = db()->query('SELECT * FROM abandoned_carts ORDER BY updated_at DESC LIMIT 100')->fetchAll();
            self::view('carts', compact('items'));
            return true;
        }
        if ($path === '/yonetim/yazilar/') {
            if ($method === 'POST' && csrf_ok() && ($_POST['act'] ?? '') === 'delete') {
                $delId = (int) ($_POST['id'] ?? 0);
                if ($delId > 0) {
                    db()->prepare('DELETE FROM posts WHERE id=?')->execute([$delId]);
                }
                redirect(site_url('yonetim/yazilar/') . '?silindi=1');
            }
            $items = db()->query('SELECT * FROM posts ORDER BY id DESC')->fetchAll();
            self::view('posts', ['items' => $items, 'ok' => !empty($_GET['silindi'])]);
            return true;
        }
        if (preg_match('#^/yonetim/yazilar/(\d+)/$#', $path, $m) || $path === '/yonetim/yazilar/yeni/') {
            $id = isset($m[1]) ? (int) $m[1] : 0;
            if ($method === 'POST' && csrf_ok()) {
                if ($id > 0 && ($_POST['act'] ?? '') === 'delete') {
                    db()->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
                    redirect(site_url('yonetim/yazilar/') . '?silindi=1');
                }
                $title = trim((string) ($_POST['title'] ?? ''));
                $slug = slugify((string) ($_POST['slug'] ?: $title));
                $fields = [
                    $slug, $title, trim((string) ($_POST['excerpt'] ?? '')), (string) ($_POST['content'] ?? ''),
                    trim((string) ($_POST['seo_title'] ?? '')), trim((string) ($_POST['seo_description'] ?? '')),
                    in_array($_POST['status'] ?? '', ['publish', 'draft'], true) ? $_POST['status'] : 'publish',
                ];
                if ($id) {
                    db()->prepare('UPDATE posts SET slug=?,title=?,excerpt=?,content=?,seo_title=?,seo_description=?,status=?,modified_at=NOW() WHERE id=?')
                        ->execute([...$fields, $id]);
                } else {
                    db()->prepare('INSERT INTO posts (slug,title,excerpt,content,seo_title,seo_description,status,published_at,modified_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
                        ->execute($fields);
                    $id = (int) db()->lastInsertId();
                }
                redirect(site_url('yonetim/yazilar/' . $id . '/'));
            }
            $post = null;
            if ($id) {
                $st = db()->prepare('SELECT * FROM posts WHERE id=?');
                $st->execute([$id]);
                $post = $st->fetch() ?: null;
            }
            self::view('edit-post', compact('post'));
            return true;
        }
        if ($path === '/yonetim/iadeler/') {
            if ($method === 'POST' && csrf_ok()) {
                $stt = (string) ($_POST['status'] ?? '');
                $id = (int) ($_POST['id'] ?? 0);
                $note = trim((string) ($_POST['note'] ?? ''));
                if ($id > 0 && in_array($stt, ['yeni', 'onay', 'red', 'tamam'], true)) {
                    $st = db()->prepare('SELECT r.*, o.code, o.name, o.email FROM returns r INNER JOIN orders o ON o.id=r.order_id WHERE r.id=?');
                    $st->execute([$id]);
                    $row = $st->fetch() ?: null;
                    db()->prepare('UPDATE returns SET status=?, note=? WHERE id=?')->execute([$stt, $note, $id]);
                    if ($row && ((string) ($row['status'] ?? '') !== $stt || (string) ($row['note'] ?? '') !== $note)) {
                        $row['status'] = $stt;
                        $row['note'] = $note;
                        HfsMail::returnStatus($row);
                    }
                }
                redirect(site_url('yonetim/iadeler/'));
            }
            $items = db()->query('SELECT r.*, o.code, o.name, o.email FROM returns r INNER JOIN orders o ON o.id=r.order_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
            self::view('returns', compact('items'));
            return true;
        }
        if ($path === '/yonetim/kullanicilar/' || $path === '/yonetim/bayiler/') {
            $pull = null;
            if ($method === 'POST' && csrf_ok() && !empty($_POST['pull_cari'])) {
                $pull = HfsDealers::pullFromAccounting();
            }
            $q = trim((string) ($_GET['q'] ?? ''));
            $items = $q !== '' ? HfsDealers::search($q) : HfsDealers::list();
            self::view('users', compact('items', 'q', 'pull'));
            return true;
        }
        if ($path === '/yonetim/kullanicilar/yeni/' || preg_match('#^/yonetim/kullanicilar/(\d+)/$#', $path, $m)) {
            $id = isset($m[1]) ? (int) $m[1] : 0;
            if ($method === 'POST' && csrf_ok()) {
                if ((current_user()['role'] ?? '') !== 'admin') {
                    redirect(site_url('yonetim/kullanicilar/'));
                }
                if (!empty($_POST['manual_cari'])) {
                    $amt = round((float) str_replace(['.', ','], ['', '.'], (string) ($_POST['cari_amount'] ?? '0')), 2);
                    $note = trim((string) ($_POST['cari_note'] ?? 'Manuel tahsilat'));
                    $q = '?ok=cari';
                    if ($id > 0 && $amt > 0) {
                        $u = HfsDealers::get($id);
                        $cid = $u ? HfsPricing::companyId($u) : $id;
                        $lid = HfsFinance::addCariPayment($cid, $amt, 0, $note !== '' ? $note : 'Manuel tahsilat');
                        $q .= self::cariBaytQuery($lid);
                    }
                    redirect(site_url('yonetim/kullanicilar/' . $id . '/') . $q);
                }
                if (!empty($_POST['bayt_cari_send'])) {
                    $lid = (int) ($_POST['ledger_id'] ?? 0);
                    $u = $id > 0 ? HfsDealers::get($id) : null;
                    $cid = $u ? HfsPricing::companyId($u) : $id;
                    $st = db()->prepare('SELECT id FROM ledger WHERE id=? AND company_id=? AND kind=? LIMIT 1');
                    $st->execute([$lid, $cid, 'cari_odeme']);
                    if ($st->fetch()) {
                        HfsBayt::sendCollection($lid, true);
                    }
                    redirect(site_url('yonetim/kullanicilar/' . $id . '/') . '?ok=cari' . self::cariBaytQuery($lid));
                }
                if (!empty($_POST['cari_void'])) {
                    $lid = (int) ($_POST['ledger_id'] ?? 0);
                    $u = $id > 0 ? HfsDealers::get($id) : null;
                    $cid = $u ? HfsPricing::companyId($u) : $id;
                    $st = db()->prepare('SELECT * FROM ledger WHERE id=? AND company_id=? AND kind=? LIMIT 1');
                    $st->execute([$lid, $cid, 'cari_odeme']);
                    $row = $st->fetch();
                    if ($row) {
                        $oid = (int) ($row['order_id'] ?? 0);
                        if ($oid > 0) {
                            $ordSt = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
                            $ordSt->execute([$oid]);
                            $ord = $ordSt->fetch();
                            if ($ord) {
                                Catalog::cancelOrder($ord, 'Panel tahsilat iptal', false);
                            } else {
                                HfsFinance::voidCariPayment($lid, 'Panel tahsilat iptal');
                            }
                        } else {
                            HfsFinance::voidCariPayment($lid, 'Panel tahsilat iptal');
                        }
                    }
                    redirect(site_url('yonetim/kullanicilar/' . $id . '/') . '?ok=cariiptal');
                }
                try {
                    $id = HfsDealers::save($id, $_POST);
                } catch (Throwable $e) {
                    $row = $id ? HfsDealers::get($id) : null;
                    self::view('user-edit', [
                        'row' => $row,
                        'mains' => HfsDealers::mains($id ?: null),
                        'snap' => $row ? HfsFinance::snapshot($row) : null,
                        'offers' => $row ? HfsOffers::forCompany(HfsPricing::companyId($row)) : [],
                        'err' => $e->getMessage(),
                    ]);
                    return true;
                }
                $q = '?ok=1';
                try {
                    $sync = HfsBayt::pushDealer($id);
                    if (!empty($sync['created'])) {
                        $q .= '&baytcari=1';
                    } elseif (empty($sync['skip']) && empty($sync['ok']) && ($sync['message'] ?? '') !== '') {
                        $q .= '&baythata=' . rawurlencode(mb_substr((string) $sync['message'], 0, 160));
                    }
                } catch (Throwable $e) {
                    $q .= '&baythata=' . rawurlencode(mb_substr($e->getMessage(), 0, 160));
                }
                try {
                    $exp = HfsPay::ensureDealerExpCode($id);
                    if ($exp !== '') {
                        $q .= '&btexp=1';
                    }
                } catch (Throwable) {
                }
                redirect(site_url('yonetim/kullanicilar/' . $id . '/') . $q);
            }
            $row = $id ? HfsDealers::get($id) : null;
            if ($id && !$row) {
                redirect(site_url('yonetim/kullanicilar/'));
            }
            self::view('user-edit', [
                'row' => $row,
                'mains' => HfsDealers::mains($id ?: null),
                'snap' => $row ? HfsFinance::snapshot($row) : null,
                'offers' => $row ? HfsOffers::forCompany(HfsPricing::companyId($row)) : [],
                'ok' => isset($_GET['ok']),
            ]);
            return true;
        }
        if ($path === '/yonetim/siparisler/yeni/') {
            if ($method === 'POST' && csrf_ok()) {
                self::createOrder();
                return true;
            }
            $products = db()->query("SELECT id,title,sku,price,stock FROM products WHERE status='publish' ORDER BY title LIMIT 200")->fetchAll();
            self::view('new-order', compact('products'));
            return true;
        }
        if ($path === '/yonetim/fiyatlar/') {
            if (!empty($_GET['indir'])) {
                HfsPrices::export();
            }
            $import = null;
            if ($method === 'POST' && csrf_ok()) {
                $tmp = (string) ($_FILES['xlsx']['tmp_name'] ?? '');
                if ($tmp !== '' && is_uploaded_file($tmp)) {
                    $import = HfsPrices::import($tmp);
                } else {
                    $import = [
                        'updated' => 0,
                        'unchanged' => 0,
                        'skipped' => 0,
                        'errors' => ['Dosya yüklenmedi.'],
                        'not_found' => [],
                        'skipped_rows' => [],
                        'updated_rows' => [],
                        'price_warnings' => [],
                    ];
                }
            }
            self::view('prices', compact('import'));
            return true;
        }
        if ($path === '/yonetim/entegrasyon/') {
            HfsMigrate::up();
            $src = trim((string) ($_GET['kaynak'] ?? ''));
            self::view('integrasyon-log', [
                'rows' => HfsIntegrLog::rows(250, $src),
                'source' => $src,
            ]);
            return true;
        }
        if ($path === '/yonetim/muhasebe/') {
            $test = null;
            $sendMsg = null;
            if ($method === 'POST' && csrf_ok()) {
                $act = (string) ($_POST['bayt_act'] ?? 'save');
                if ($act === 'save') {
                    HfsBayt::save($_POST);
                    redirect(site_url('yonetim/muhasebe/') . '?ok=1');
                }
                if ($act === 'test') {
                    $test = HfsBayt::test();
                }
                if ($act === 'test_customer' || $act === 'push_dealers') {
                    $test = HfsBayt::pushPendingDealers();
                }
                if ($act === 'send') {
                    $oid = (int) ($_POST['order_id'] ?? 0);
                    $r = HfsBayt::send($oid, true);
                    $sendMsg = $r === true ? 'Gönderildi.' : $r;
                }
                if ($act === 'send_collection') {
                    $lid = (int) ($_POST['ledger_id'] ?? 0);
                    $r = HfsBayt::sendCollection($lid, true);
                    $sendMsg = $r === true ? 'Tahsilat muhasebeye gönderildi.' : $r;
                }
            }
            self::view('bayt', [
                's' => HfsBayt::settings(),
                'ok' => isset($_GET['ok']),
                'test' => $test,
                'sendMsg' => $sendMsg,
                'pending' => HfsBayt::pendingCompleted(),
                'pendingCari' => HfsBayt::pendingCollections(),
                'log' => HfsBayt::logRows(30),
            ]);
            return true;
        }
        if ($path === '/yonetim/teklifler/') {
            if ($method === 'POST' && csrf_ok()) {
                $cid = (int) ($_POST['company_id'] ?? 0);
                $pid = (int) ($_POST['product_id'] ?? 0);
                $sku = trim((string) ($_POST['sku'] ?? ''));
                if ($pid < 1 && $sku !== '') {
                    $p = Catalog::productBySku($sku);
                    $pid = $p ? (int) $p['id'] : 0;
                }
                if (!empty($_POST['del_cid']) && !empty($_POST['del_pid'])) {
                    HfsOffers::delete((int) $_POST['del_cid'], (int) $_POST['del_pid']);
                } elseif ($cid > 0 && $pid > 0) {
                    $pr = trim((string) ($_POST['price'] ?? ''));
                    $price = $pr === '' ? null : (float) str_replace(',', '.', $pr);
                    HfsOffers::saveRow($cid, $pid, $price, (string) ($_POST['gifts'] ?? ''), (string) ($_POST['qty_prices'] ?? ''));
                }
                redirect(site_url('yonetim/teklifler/'));
            }
            $items = HfsOffers::all();
            $dealers = HfsDealers::mains();
            self::view('offers', compact('items', 'dealers'));
            return true;
        }
        if ($path === '/yonetim/epostalar/') {
            self::view('emails', [
                'catalog' => HfsMail::catalog(),
                'log' => HfsMail::recentLog(40),
            ]);
            return true;
        }
        if (preg_match('#^/yonetim/epostalar/([a-z0-9_]+)/$#', $path, $m)) {
            $key = $m[1];
            $catalog = HfsMail::catalog();
            if (!isset($catalog[$key])) {
                redirect(site_url('yonetim/epostalar/'));
            }
            $test = null;
            $testTo = '';
            if ($method === 'POST' && csrf_ok()) {
                $act = (string) ($_POST['mail_act'] ?? 'save');
                if ($act === 'restore') {
                    HfsMail::restore($key);
                    redirect(site_url('yonetim/epostalar/' . $key . '/') . '?ok=1');
                }
                if ($act === 'test') {
                    $testTo = trim((string) ($_POST['test_to'] ?? ''));
                    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                        $testTo = (string) (current_user()['email'] ?? setting('email', ''));
                    }
                    HfsMail::save(
                        $key,
                        (string) ($_POST['subject'] ?? ''),
                        (string) ($_POST['body'] ?? ''),
                        !empty($_POST['on'])
                    );
                    $test = HfsMail::send($key, $testTo, HfsMail::dummyVars($key), '', true);
                } else {
                    HfsMail::save(
                        $key,
                        (string) ($_POST['subject'] ?? ''),
                        (string) ($_POST['body'] ?? ''),
                        !empty($_POST['on'])
                    );
                    redirect(site_url('yonetim/epostalar/' . $key . '/') . '?ok=1');
                }
            }
            $tpl = HfsMail::resolved($key);
            self::view('email-edit', [
                'key' => $key,
                'meta' => $catalog[$key],
                'tpl' => $tpl,
                'on' => HfsMail::enabled($key),
                'ok' => isset($_GET['ok']),
                'test' => $test,
                'testTo' => $testTo,
                'placeholders' => HfsMail::placeholders(),
            ]);
            return true;
        }
        return false;
    }

    private static function createOrder(): void
    {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $qty = max(1, (int) ($_POST['qty'] ?? 1));
        $p = Catalog::productById($pid);
        if (!$p) {
            redirect(site_url('yonetim/siparisler/yeni/'));
        }
        $line = (float) $p['price'] * $qty;
        $ship = Shipping::fee($line, 'TR', trim((string) ($_POST['city'] ?? '')));
        $cart = [
            'lines' => [[
                'product' => $p,
                'variant' => null,
                'qty' => $qty,
                'price' => (float) $p['price'],
                'line' => $line,
                'title' => $p['title'],
                'sku' => $p['sku'],
                'note' => '',
            ]],
            'subtotal' => $line,
            'discount' => 0,
            'shipping' => $ship,
            'total' => $line + $ship,
        ];
        $form = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'district' => '',
            'address' => trim((string) ($_POST['address'] ?? '')),
            'note' => 'Panel siparişi',
            'country' => 'TR',
            'ship_slot' => '',
            'coupon_code' => '',
            'channel' => in_array($_POST['channel'] ?? '', ['panel', 'trendyol', 'hepsiburada', 'n11', 'amazon', 'etsy'], true) ? $_POST['channel'] : 'panel',
            'payment' => 'havale',
        ];
        if ($form['name'] === '' || $form['email'] === '' || $form['address'] === '') {
            redirect(site_url('yonetim/siparisler/yeni/'));
        }
        $code = Catalog::placeOrder($cart, $form);
        $placed = Catalog::orderByCode($code);
        if ($placed) {
            HfsMail::orderPlaced($placed);
            HfsBayt::onConfirmed($placed);
        }
        redirect(site_url('yonetim/siparisler/' . $code . '/'));
    }

    private static function cariBaytQuery(int $ledgerId): string
    {
        if ($ledgerId < 1) {
            return '';
        }
        $st = db()->prepare('SELECT bayt_status, bayt_error FROM ledger WHERE id=? LIMIT 1');
        $st->execute([$ledgerId]);
        $row = $st->fetch();
        if (!$row) {
            return '';
        }
        if (($row['bayt_status'] ?? '') === 'sent') {
            return '&bayt=1';
        }
        $err = trim((string) ($row['bayt_error'] ?? ''));
        if ($err !== '') {
            return '&baythata=' . rawurlencode(mb_substr($err, 0, 160));
        }
        return '';
    }

    private static function view(string $view, array $data = []): void
    {
        $data['user'] = current_user();
        extract($data, EXTR_SKIP);
        $theme = BMN_ROOT . '/yonetim/views';
        ob_start();
        require $theme . '/' . $view . '.php';
        $content = ob_get_clean();
        require $theme . '/layout.php';
    }
}
