<?php
declare(strict_types=1);

final class Catalog
{
    /** MySQL tarafında Türkçe harfleri ASCII’ye indirger (unicode_ci ı≠i sorunu). */
    private static function sqlTrFold(string $expr): string
    {
        $e = 'LOWER(' . $expr . ')';
        foreach (['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c'] as $from => $to) {
            $e = 'REPLACE(' . $e . ",'" . $from . "','" . $to . "')";
        }
        return $e;
    }

    public static function categories(?int $parent = null): array
    {
        if ($parent === null) {
            return HfsMontaj::filterNav(db()->query("SELECT * FROM categories WHERE status='publish' AND parent_id IS NULL ORDER BY sort, name")->fetchAll());
        }
        $st = db()->prepare("SELECT * FROM categories WHERE status='publish' AND parent_id=? ORDER BY sort, name");
        $st->execute([$parent]);
        return HfsMontaj::filterNav($st->fetchAll());
    }

    public static function allCategories(): array
    {
        return HfsMontaj::filterNav(db()->query("SELECT * FROM categories WHERE status='publish' ORDER BY path")->fetchAll());
    }

    public static function categoryByPath(string $path): ?array
    {
        $path = trim($path, '/');
        $st = db()->prepare("SELECT * FROM categories WHERE status='publish' AND (path=? OR slug=?) LIMIT 1");
        $st->execute([$path, $path]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function categoryById(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM categories WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return list<int> */
    public static function categoryIdsWithChildren(int $id): array
    {
        $ids = [$id];
        $st = db()->prepare('SELECT id FROM categories WHERE parent_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $row) {
            $ids = array_merge($ids, self::categoryIdsWithChildren((int) $row['id']));
        }
        return $ids;
    }

    /**
     * Kategori ekle / güncelle. Yol değişince alt kategorilerin path’i kaydırılır.
     *
     * @param array<string,mixed> $post
     * @return int|string id veya hata mesajı
     */
    public static function saveCategory(array $post, int $id = 0): int|string
    {
        HfsMigrate::up();
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '') {
            return 'Kategori adı gerekli.';
        }
        $slug = slugify((string) (($post['slug'] ?? '') !== '' ? $post['slug'] : $name));
        if ($slug === '') {
            return 'Slug geçersiz.';
        }
        $parent = (int) ($post['parent_id'] ?? 0);
        if ($id > 0 && $parent === $id) {
            return 'Kategori kendi üstü olamaz.';
        }
        if ($id > 0 && $parent > 0) {
            $desc = self::categoryIdsWithChildren($id);
            if (in_array($parent, $desc, true)) {
                return 'Üst kategori, kendi alt kategorisi olamaz.';
            }
        }
        $pathCat = $slug;
        if ($parent > 0) {
            $par = self::categoryById($parent);
            if (!$par) {
                return 'Üst kategori bulunamadı.';
            }
            $pathCat = trim((string) $par['path'] . '/' . $slug, '/');
        }
        $dup = db()->prepare('SELECT id FROM categories WHERE path=? AND id<>? LIMIT 1');
        $dup->execute([$pathCat, $id]);
        if ($dup->fetch()) {
            return 'Bu yol zaten kullanılıyor: ' . $pathCat;
        }
        $status = trim((string) ($post['status'] ?? 'publish'));
        if (!in_array($status, ['publish', 'draft'], true)) {
            $status = 'publish';
        }
        $desc = trim((string) ($post['description'] ?? ''));
        $seoTitle = trim((string) ($post['seo_title'] ?? ''));
        $seoDesc = trim((string) ($post['seo_description'] ?? ''));
        $sort = (int) ($post['sort'] ?? 0);
        $hideNav = !empty($post['hide_nav']) ? 1 : 0;
        $minQty = max(0, (int) ($post['min_qty'] ?? 0));
        $parentVal = $parent > 0 ? $parent : null;

        if ($id < 1) {
            db()->prepare(
                'INSERT INTO categories (parent_id,slug,path,name,description,seo_title,seo_description,sort,status,hide_nav,min_qty)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$parentVal, $slug, $pathCat, $name, $desc, $seoTitle, $seoDesc, $sort, $status, $hideNav, $minQty]);
            return (int) db()->lastInsertId();
        }

        $old = self::categoryById($id);
        if (!$old) {
            return 'Kategori yok.';
        }
        $oldPath = (string) ($old['path'] ?? '');
        db()->prepare(
            'UPDATE categories SET parent_id=?, slug=?, path=?, name=?, description=?, seo_title=?, seo_description=?, sort=?, status=?, hide_nav=?, min_qty=? WHERE id=?'
        )->execute([$parentVal, $slug, $pathCat, $name, $desc, $seoTitle, $seoDesc, $sort, $status, $hideNav, $minQty, $id]);

        if ($oldPath !== '' && $oldPath !== $pathCat) {
            $kids = db()->prepare('SELECT id, path FROM categories WHERE path LIKE ?');
            $kids->execute([$oldPath . '/%']);
            foreach ($kids->fetchAll() as $kid) {
                $newKidPath = $pathCat . substr((string) $kid['path'], strlen($oldPath));
                db()->prepare('UPDATE categories SET path=? WHERE id=?')->execute([$newKidPath, (int) $kid['id']]);
            }
        }
        return $id;
    }

    public static function productBySlug(string $slug): ?array
    {
        $st = db()->prepare("SELECT * FROM products WHERE slug=? AND status='publish' LIMIT 1");
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? HfsPricing::decorate($row) : null;
    }

    public static function productById(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM products WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? HfsPricing::decorate($row) : null;
    }

    /**
     * Yönetim: ürün + varyant kodları benzersiz mi (boş kod serbest).
     *
     * @param list<array{id:int,sku:string}> $variantRows
     */
    public static function validateProductSkus(int $productId, string $mainSku, array $variantRows): ?string
    {
        $mainSku = trim($mainSku);
        $seen = [];
        if ($mainSku !== '') {
            $seen[mb_strtolower($mainSku)] = true;
            $err = self::skuConflictMessage($mainSku, $productId, 0);
            if ($err !== null) {
                return $err;
            }
        }
        foreach ($variantRows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $vid = (int) ($row['id'] ?? 0);
            if ($sku === '') {
                continue;
            }
            $key = mb_strtolower($sku);
            if (isset($seen[$key])) {
                return 'Aynı ürün kodu bu formda iki kez kullanılamaz: «' . $sku . '».';
            }
            $seen[$key] = true;
            $err = self::skuConflictMessage($sku, $productId, $vid);
            if ($err !== null) {
                return $err;
            }
        }
        return null;
    }

    public static function skuConflictMessage(string $sku, int $excludeProductId, int $excludeVariantId): ?string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $st = db()->prepare('SELECT id, title FROM products WHERE sku=? LIMIT 1');
        $st->execute([$sku]);
        $p = $st->fetch();
        if ($p && (int) $p['id'] !== $excludeProductId) {
            return '«' . $sku . '» kodu zaten «' . (string) $p['title'] . '» ürününde (# ' . (int) $p['id'] . ').';
        }
        $st = db()->prepare('SELECT v.id, v.product_id, p.title FROM variants v INNER JOIN products p ON p.id=v.product_id WHERE v.sku=? LIMIT 1');
        $st->execute([$sku]);
        $v = $st->fetch();
        if (!$v) {
            return null;
        }
        $vp = (int) $v['product_id'];
        $vid = (int) $v['id'];
        if ($excludeVariantId > 0 && $vid === $excludeVariantId) {
            return null;
        }
        if ($excludeProductId > 0 && $vp === $excludeProductId) {
            return '«' . $sku . '» kodu bu ürünün bir varyantında zaten kayıtlı.';
        }
        return '«' . $sku . '» kodu «' . (string) $v['title'] . '» ürünün varyantında (# ' . $vp . ').';
    }

    /** @param array<string, mixed> $post */
    public static function applyVariantCodeEdits(int $productId, array $post): void
    {
        if ($productId < 1 || !array_key_exists('v_id', $post)) {
            return;
        }
        $ids = (array) ($post['v_id'] ?? []);
        $skus = (array) ($post['v_sku'] ?? []);
        $stocks = (array) ($post['v_stock'] ?? []);
        $upd = db()->prepare('UPDATE variants SET sku=?, stock=? WHERE id=? AND product_id=?');
        $sum = 0;
        foreach ($ids as $i => $rawId) {
            $vid = (int) $rawId;
            if ($vid < 1) {
                continue;
            }
            if (!self::variant($vid, $productId)) {
                continue;
            }
            $sku = trim((string) ($skus[$i] ?? ''));
            $stock = max(0, (int) ($stocks[$i] ?? 0));
            $upd->execute([$sku, $stock, $vid, $productId]);
            $sum += $stock;
        }
        if ($ids !== []) {
            db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$sum, $productId]);
        }
    }

    /** @return array{product_id:int,variant_id:int}|null */
    public static function bySku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $st = db()->prepare("SELECT id FROM products WHERE sku=? AND status='publish' LIMIT 1");
        $st->execute([$sku]);
        $pid = (int) $st->fetchColumn();
        if ($pid > 0) {
            return ['product_id' => $pid, 'variant_id' => 0];
        }
        $st = db()->prepare('SELECT product_id, id FROM variants WHERE sku=? LIMIT 1');
        $st->execute([$sku]);
        $row = $st->fetch();
        if ($row) {
            return ['product_id' => (int) $row['product_id'], 'variant_id' => (int) $row['id']];
        }
        return null;
    }

    public static function productBySku(string $sku): ?array
    {
        $hit = self::bySku($sku);
        return $hit ? self::productById($hit['product_id']) : null;
    }

    public static function pageBySlug(string $slug): ?array
    {
        $st = db()->prepare("SELECT * FROM pages WHERE slug=? AND status='publish' LIMIT 1");
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function variants(int $productId): array
    {
        $st = db()->prepare('SELECT * FROM variants WHERE product_id=? ORDER BY sort, id');
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    public static function hasVariants(int $productId): bool
    {
        if ($productId < 1) {
            return false;
        }
        $st = db()->prepare('SELECT 1 FROM variants WHERE product_id=? LIMIT 1');
        $st->execute([$productId]);
        return (bool) $st->fetchColumn();
    }

    public static function variant(int $id, int $productId): ?array
    {
        $st = db()->prepare('SELECT * FROM variants WHERE id=? AND product_id=? LIMIT 1');
        $st->execute([$id, $productId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return list<string> Galeri yolları (ana görsel hariç) */
    public static function productGallery(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }
        $st = db()->prepare("SELECT meta_value FROM product_meta WHERE product_id=? AND meta_key='gallery' LIMIT 1");
        $st->execute([$productId]);
        $raw = $st->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $src) {
            $src = trim((string) $src);
            if ($src !== '' && !in_array($src, $out, true)) {
                $out[] = $src;
            }
        }
        return $out;
    }

    /** @param list<string> $paths */
    public static function setProductGallery(int $productId, array $paths): void
    {
        if ($productId < 1) {
            return;
        }
        $clean = [];
        foreach ($paths as $src) {
            $src = trim((string) $src);
            if ($src !== '' && !in_array($src, $clean, true)) {
                $clean[] = $src;
            }
        }
        if ($clean === []) {
            db()->prepare("DELETE FROM product_meta WHERE product_id=? AND meta_key='gallery'")->execute([$productId]);
            return;
        }
        db()->prepare("INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
            ->execute([$productId, 'gallery', json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public static function setProductMeta(int $productId, string $key, string $value): void
    {
        if ($productId < 1 || $key === '') {
            return;
        }
        $value = trim($value);
        if ($value === '') {
            db()->prepare('DELETE FROM product_meta WHERE product_id=? AND meta_key=?')->execute([$productId, $key]);
            return;
        }
        db()->prepare('INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)')
            ->execute([$productId, $key, $value]);
    }

    public static function catsForProduct(int $productId): array
    {
        $st = db()->prepare('SELECT c.* FROM categories c INNER JOIN product_categories pc ON pc.category_id=c.id WHERE pc.product_id=? ORDER BY c.path');
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    /** @param array{q?:string,category_id?:int,featured?:bool,per?:int,page?:int,exclude?:list<int>,parts_only?:bool} $opts */
    public static function products(array $opts = []): array
    {
        $per = max(1, (int) ($opts['per'] ?? 12));
        $page = max(1, (int) ($opts['page'] ?? 1));
        $where = ["p.status='publish'"];
        $args = [];
        if (!empty($opts['category_id'])) {
            $ids = self::categoryIdsWithChildren((int) $opts['category_id']);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id=p.id AND pc.category_id IN ($ph))";
            $args = array_merge($args, $ids);
        }
        if (!empty($opts['featured'])) {
            $where[] = 'p.featured=1';
        }
        if (!empty($opts['q'])) {
            $fold = self::sqlTrFold('p.title') . ' LIKE ? OR ' . self::sqlTrFold('p.excerpt') . ' LIKE ? OR ' . self::sqlTrFold('p.sku') . ' LIKE ?';
            $where[] = "($fold)";
            $like = '%' . hfs_tr_fold((string) $opts['q']) . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        if (!empty($opts['parts_only'])) {
            $where[] = 'p.has_diagram=0';
        }
        if (!empty($opts['exclude']) && is_array($opts['exclude'])) {
            $ids = array_values(array_filter(array_map('intval', $opts['exclude'])));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "p.id NOT IN ($ph)";
                $args = array_merge($args, $ids);
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $st = db()->prepare("SELECT COUNT(*) FROM products p WHERE $sqlWhere");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $pager = paginate($total, $per, $page);
        $st = db()->prepare("SELECT p.* FROM products p WHERE $sqlWhere ORDER BY p.has_diagram DESC, p.featured DESC, p.published_at DESC LIMIT {$pager['per']} OFFSET {$pager['offset']}");
        $st->execute($args);
        return ['items' => HfsPricing::decorateMany($st->fetchAll()), 'pager' => $pager];
    }

    /**
     * Header canlı arama: ürün + kategori.
     *
     * @return array{q:string,cats:list<array<string,string>>,items:list<array<string,string>>}
     */
    public static function suggest(string $q, int $limit = 8): array
    {
        $q = trim($q);
        $out = ['q' => $q, 'cats' => [], 'items' => []];
        if (mb_strlen($q) < 2) {
            return $out;
        }
        $esc = str_replace(['%', '_'], ['\\%', '\\_'], hfs_tr_fold($q));
        $like = '%' . $esc . '%';
        $pre = $esc . '%';
        $cname = self::sqlTrFold('name');
        $cst = db()->prepare("SELECT * FROM categories WHERE status='publish' AND ($cname LIKE ? OR slug LIKE ? OR path LIKE ?) ORDER BY name ASC LIMIT 4");
        $cst->execute([$like, $like, $like]);
        foreach ($cst->fetchAll() as $c) {
            if (HfsMontaj::isHidden($c)) {
                continue;
            }
            $out['cats'][] = [
                'name' => (string) $c['name'],
                'url' => permalink_category($c),
            ];
        }
        $ft = self::sqlTrFold('p.title');
        $fs = self::sqlTrFold('p.sku');
        $fe = self::sqlTrFold('p.excerpt');
        $st = db()->prepare("SELECT p.* FROM products p
            WHERE p.status='publish' AND ($ft LIKE ? OR $fs LIKE ? OR $fe LIKE ?)
            ORDER BY
              CASE
                WHEN $fs = ? THEN 0
                WHEN $fs LIKE ? THEN 1
                WHEN $ft LIKE ? THEN 2
                ELSE 3
              END,
              p.has_diagram ASC,
              p.title ASC
            LIMIT " . (int) $limit);
        $st->execute([$like, $like, $like, hfs_tr_fold($q), $pre, $pre]);
        foreach (HfsPricing::decorateMany($st->fetchAll()) as $p) {
            $out['items'][] = [
                'title' => (string) $p['title'],
                'sku' => (string) $p['sku'],
                'url' => permalink_product($p),
                'img' => hfs_media($p['featured_media'] ?? ''),
                'price' => HfsPricing::canSeePrices() ? money((float) $p['price']) : '',
            ];
        }
        return $out;
    }

    public static function featured(int $n = 8): array
    {
        $st = db()->prepare("SELECT * FROM products WHERE status='publish' AND featured=1 ORDER BY published_at DESC LIMIT " . (int) $n);
        $st->execute();
        return HfsPricing::decorateMany($st->fetchAll());
    }

    public static function latest(int $n = 8): array
    {
        $st = db()->prepare("SELECT * FROM products WHERE status='publish' ORDER BY published_at DESC LIMIT " . (int) $n);
        $st->execute();
        return HfsPricing::decorateMany($st->fetchAll());
    }

    public static function related(array $product, int $n = 4): array
    {
        $cats = self::catsForProduct((int) $product['id']);
        $catId = (int) ($cats[0]['id'] ?? 0);
        if ($catId < 1) {
            $data = self::products(['per' => $n, 'exclude' => [(int) $product['id']]]);
            return $data['items'];
        }
        $data = self::products(['category_id' => $catId, 'per' => $n, 'exclude' => [(int) $product['id']]]);
        return $data['items'];
    }

    public static function bumpViews(int $id): void
    {
        db()->prepare('UPDATE products SET view_count=view_count+1 WHERE id=?')->execute([$id]);
    }

    public static function newOrderCode(): string
    {
        do {
            $code = 'HFS-' . strtoupper(bin2hex(random_bytes(3)));
            $st = db()->prepare('SELECT id FROM orders WHERE code=?');
            $st->execute([$code]);
        } while ($st->fetch());
        return $code;
    }

    public static function placeOrder(array $cart, array $form): string
    {
        $code = self::newOrderCode();
        db()->prepare('INSERT INTO orders (code,status,name,email,phone,city,district,address,note,country,coupon_code,discount,channel,ship_slot,payment,subtotal,shipping,total,ip,created_at)
            VALUES (?,\'yeni\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([
                $code,
                $form['name'],
                $form['email'],
                $form['phone'],
                $form['city'],
                $form['district'],
                $form['address'],
                $form['note'],
                $form['country'] ?? 'TR',
                $form['coupon_code'] ?? '',
                $cart['discount'] ?? 0,
                $form['channel'] ?? 'web',
                $form['ship_slot'] ?? '',
                $form['payment'],
                $cart['subtotal'],
                $cart['shipping'],
                $cart['total'],
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        $oid = (int) db()->lastInsertId();
        $zip = preg_replace('/\D+/', '', (string) ($form['postcode'] ?? '')) ?: '';
        $cityFix = (string) ($form['city'] ?? '');
        $distFix = (string) ($form['district'] ?? '');
        if (($form['country'] ?? 'TR') === 'TR' && $cityFix !== '' && $distFix !== '') {
            $loc = HfsTrAddress::resolve($cityFix, $distFix);
            if ($loc) {
                $cityFix = (string) ($loc['city'] ?? $cityFix);
                $distFix = (string) ($loc['district'] ?? $distFix);
                if ($zip === '' || strlen($zip) !== 5) {
                    $zip = (string) ($loc['postcode'] ?? $zip);
                }
            }
        }
        try {
            db()->prepare('UPDATE orders SET city=?, district=?, postcode=? WHERE id=?')
                ->execute([$cityFix, $distFix, $zip, $oid]);
        } catch (Throwable) {
            try {
                db()->prepare('UPDATE orders SET postcode=? WHERE id=?')->execute([$zip, $oid]);
            } catch (Throwable) {
            }
        }
        $ins = db()->prepare('INSERT INTO order_items (order_id,product_id,variant_id,title,sku,qty,price,note) VALUES (?,?,?,?,?,?,?,?)');
        $stockP = db()->prepare('UPDATE products SET stock=GREATEST(0, stock-?) WHERE id=?');
        $stockV = db()->prepare('UPDATE variants SET stock=GREATEST(0, stock-?) WHERE id=?');
        foreach ($cart['lines'] as $line) {
            $vid = $line['variant']['id'] ?? null;
            $ins->execute([
                $oid,
                (int) $line['product']['id'],
                $vid,
                $line['title'],
                $line['sku'],
                $line['qty'],
                $line['price'],
                $line['note'] ?? '',
            ]);
            $pid = (int) $line['product']['id'];
            if (($line['product']['type'] ?? '') === 'bundle') {
                foreach (self::bundleItems($pid) as $b) {
                    $stockP->execute([$line['qty'] * (int) $b['qty'], (int) $b['child_id']]);
                    if (!empty($b['variant_id'])) {
                        $stockV->execute([$line['qty'] * (int) $b['qty'], (int) $b['variant_id']]);
                    }
                }
            } else {
                $stockP->execute([$line['qty'], $pid]);
                if ($vid) {
                    $stockV->execute([$line['qty'], (int) $vid]);
                }
            }
        }
        if (!empty($form['coupon_code'])) {
            Coupon::bump((string) $form['coupon_code']);
        }
        return $code;
    }

    public static function orderByCode(string $code): ?array
    {
        $st = db()->prepare('SELECT * FROM orders WHERE UPPER(code)=UPPER(?) LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([(int) $row['id']]);
        $row['items'] = $it->fetchAll();
        return $row;
    }

    public static function dealerCanSeeOrder(array $order, ?array $u = null): bool
    {
        $u = $u ?? current_user();
        if (!$u) {
            return false;
        }
        if (in_array((string) ($u['role'] ?? ''), ['admin', 'editor'], true)) {
            return true;
        }
        $cid = HfsPricing::companyId($u);
        $ids = HfsFinance::memberIds($cid);
        return in_array((int) ($order['user_id'] ?? 0), $ids, true)
            || (int) ($order['company_id'] ?? 0) === $cid
            || in_array((int) ($order['company_id'] ?? 0), $ids, true);
    }

    public static function canDelete(array $order): bool
    {
        if (($order['payment_status'] ?? '') === 'odendi') {
            return false;
        }
        return in_array((string) ($order['status'] ?? ''), ['odeme', 'iptal'], true);
    }

    /** Panel, POS veya muhasebe iptali: stok, ekstre, Bayt tahsilat/sipariş. */
    public static function cancelOrder(array $order, string $reason = '', bool $notify = false, bool $fromBayt = false, bool $fromBulut = false): bool
    {
        $id = (int) ($order['id'] ?? 0);
        if ($id < 1) {
            return false;
        }
        $fresh = self::orderByCode((string) ($order['code'] ?? ''));
        if (!$fresh) {
            $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                return false;
            }
            $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
            $it->execute([$id]);
            $row['items'] = $it->fetchAll();
            $fresh = $row;
        }
        $old = (string) ($fresh['status'] ?? '');
        $wasCancelled = $old === 'iptal';
        if (!$wasCancelled) {
            db()->prepare("UPDATE orders SET status='iptal', pending_approval=0, bayt_approval='cancelled' WHERE id=?")->execute([$id]);
            if ((int) ($fresh['is_cari'] ?? 0) !== 1) {
                self::restockCancelled($id);
            }
        } else {
            db()->prepare("UPDATE orders SET bayt_approval='cancelled' WHERE id=?")->execute([$id]);
        }
        HfsFinance::voidCariPaymentByOrder($id, $reason !== '' ? $reason : 'Sipariş iptal');
        if (($fresh['payment'] ?? '') === 'bulut') {
            HfsBayt::voidOrderBulutCollection($id);
        }
        if (!$fromBulut && ($fresh['payment'] ?? '') === 'bulut' && ($fresh['payment_status'] ?? '') === 'odendi') {
            HfsPay::requestBulutCancel($fresh, $reason);
        }
        if (($fresh['payment_status'] ?? '') === 'odendi') {
            db()->prepare("UPDATE orders SET payment_status='iptal' WHERE id=?")->execute([$id]);
        }
        if ($fromBayt) {
            db()->prepare("UPDATE orders SET bayt_status='deleted', bayt_error=NULL, bayt_approval='cancelled' WHERE id=?")->execute([$id]);
        } else {
            HfsBayt::deleteSalesOrder($id);
        }
        if ($notify && !$wasCancelled) {
            $after = self::orderByCode((string) ($fresh['code'] ?? '')) ?: $fresh;
            $after['status'] = 'iptal';
            $after['bayt_approval'] = 'cancelled';
            HfsMail::orderStatus($after, $old !== '' ? $old : 'yeni');
        }
        HfsIntegrLog::write('b2b', 'iptal', $reason !== '' ? $reason : 'Sipariş iptal', true, $id, (string) ($fresh['code'] ?? ''), [
            'from_bayt' => $fromBayt,
            'from_bulut' => $fromBulut,
            'payment' => (string) ($fresh['payment'] ?? ''),
            'payment_status' => (string) ($fresh['payment_status'] ?? ''),
        ]);
        return true;
    }

    public static function restockCancelled(int $orderId): void
    {
        $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $it->execute([$orderId]);
        $stockP = db()->prepare('UPDATE products SET stock=stock+? WHERE id=?');
        $stockV = db()->prepare('UPDATE variants SET stock=stock+? WHERE id=?');
        foreach ($it->fetchAll() as $row) {
            $q = (int) $row['qty'];
            $pid = (int) ($row['product_id'] ?? 0);
            if ($q < 1 || $pid < 1) {
                continue;
            }
            $stockP->execute([$q, $pid]);
            if (!empty($row['variant_id'])) {
                $stockV->execute([$q, (int) $row['variant_id']]);
            }
        }
    }

    public static function deleteUnpaid(string $code): bool
    {
        return self::removeOrderRecord($code, false);
    }

    /** Test / temizlik: iptal + Bayt/Bulut, ardından kayıt silme. */
    public static function purgeOrder(string $code, string $reason = 'Test öncesi temizlik'): bool
    {
        $order = self::orderByCode($code);
        if (!$order) {
            return false;
        }
        if (($order['status'] ?? '') !== 'iptal') {
            self::cancelOrder($order, $reason, false);
        }
        return self::removeOrderRecord($code, true);
    }

    /**
     * Canlı test öncesi tüm siparişleri iptal edip siler (B2B veritabanı).
     *
     * @return array{cancelled:int,deleted:int,fail:int,total:int}
     */
    public static function purgeAllOrders(string $reason = 'Test öncesi toplu temizlik'): array
    {
        bmn_session_release();
        @set_time_limit(0);
        $out = ['cancelled' => 0, 'deleted' => 0, 'fail' => 0, 'total' => 0];
        $rows = db()->query('SELECT code FROM orders ORDER BY id ASC')->fetchAll();
        $out['total'] = count($rows);
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                $out['fail']++;
                continue;
            }
            $before = self::orderByCode($code);
            if ($before && ($before['status'] ?? '') !== 'iptal') {
                self::cancelOrder($before, $reason, false);
                $out['cancelled']++;
            }
            if (self::removeOrderRecord($code, true)) {
                $out['deleted']++;
            } else {
                $out['fail']++;
            }
        }
        HfsIntegrLog::write('sistem', 'purge_orders', json_encode($out, JSON_UNESCAPED_UNICODE), $out['fail'] === 0);
        return $out;
    }

    private static function removeOrderRecord(string $code, bool $force): bool
    {
        $order = self::orderByCode($code);
        if (!$order) {
            return true;
        }
        if (!$force && !self::canDelete($order)) {
            return false;
        }
        if ($force && ($order['status'] ?? '') !== 'iptal' && ($order['payment_status'] ?? '') === 'odendi') {
            return false;
        }
        $id = (int) $order['id'];
        db()->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$id]);
        foreach (['review_invites', 'returns', 'bayt_log'] as $tbl) {
            try {
                db()->prepare('DELETE FROM `' . $tbl . '` WHERE order_id=?')->execute([$id]);
            } catch (Throwable) {
            }
        }
        try {
            db()->prepare('DELETE FROM integration_log WHERE order_id=? OR order_code=?')->execute([$id, $code]);
        } catch (Throwable) {
        }
        try {
            db()->prepare('DELETE FROM ledger WHERE order_id=?')->execute([$id]);
        } catch (Throwable) {
            try {
                db()->prepare("DELETE FROM ledger WHERE order_id=? AND kind='cari_odeme'")->execute([$id]);
            } catch (Throwable) {
            }
        }
        db()->prepare('DELETE FROM orders WHERE id=?')->execute([$id]);
        return true;
    }

    public static function links(int $productId, string $kind): array
    {
        $st = db()->prepare("SELECT p.* FROM products p INNER JOIN product_links l ON l.target_id=p.id WHERE l.product_id=? AND l.kind=? AND p.status='publish' LIMIT 8");
        $st->execute([$productId, $kind]);
        return $st->fetchAll();
    }

    public static function bundleItems(int $productId): array
    {
        $st = db()->prepare('SELECT b.*, p.title, p.slug, p.price FROM bundle_items b INNER JOIN products p ON p.id=b.child_id WHERE b.product_id=?');
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    public static function postBySlug(string $slug): ?array
    {
        $st = db()->prepare("SELECT * FROM posts WHERE slug=? AND status='publish' LIMIT 1");
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function posts(int $per = 20, int $page = 1): array
    {
        $total = (int) db()->query("SELECT COUNT(*) FROM posts WHERE status='publish'")->fetchColumn();
        $pager = paginate($total, $per, $page);
        $st = db()->prepare("SELECT * FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT {$pager['per']} OFFSET {$pager['offset']}");
        $st->execute();
        return ['items' => $st->fetchAll(), 'pager' => $pager];
    }

    public static function crossForCart(array $cart, int $n = 4): array
    {
        $ids = [];
        $out = [];
        foreach ($cart['lines'] as $line) {
            $ids[] = (int) $line['product']['id'];
            foreach (self::links((int) $line['product']['id'], 'cross') as $p) {
                $out[(int) $p['id']] = $p;
            }
        }
        foreach ($ids as $id) {
            unset($out[$id]);
        }
        return array_slice(array_values($out), 0, $n);
    }

    public static function fireStockAlerts(int $productId): void
    {
        $p = self::productById($productId);
        if (!$p || (int) $p['stock'] < 1) {
            return;
        }
        $st = db()->prepare('SELECT * FROM stock_alerts WHERE product_id=? AND sent_at IS NULL');
        $st->execute([$productId]);
        foreach ($st->fetchAll() as $row) {
            HfsMail::stockAlert($p, (string) $row['email']);
            db()->prepare('UPDATE stock_alerts SET sent_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
        }
    }
}
