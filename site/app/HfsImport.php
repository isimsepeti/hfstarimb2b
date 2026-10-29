<?php
declare(strict_types=1);

final class HfsImport
{
    /** @var array<int,string> */
    private static array $log = [];

    public static function wpPdo(): ?PDO
    {
        try {
            $pdo = new PDO('mysql:host=localhost;dbname=hfs-tarim;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        } catch (Throwable) {
            return null;
        }
    }

    public static function run(?PDO $wp = null): array
    {
        self::$log = [];
        @set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $wp = $wp ?? self::wpPdo();
        if (!$wp) {
            self::$log[] = 'WooCommerce veritabanına bağlanılamadı (hfs-tarim).';
            return self::$log;
        }
        HfsMigrate::up();
        $t0 = microtime(true);
        self::importSettings($wp);
        self::importCategories($wp);
        self::importProducts($wp);
        self::importUsers($wp);
        self::$log[] = 'Aktarım bitti: ' . round(microtime(true) - $t0, 1) . ' sn.';
        try {
            foreach (HfsLiveSync::run() as $line) {
                self::$log[] = $line;
            }
        } catch (Throwable $e) {
            self::$log[] = 'Canlı kategori senkronu atlandı: ' . $e->getMessage();
        }
        return self::$log;
    }

    private static function importSettings(PDO $wp): void
    {
        $st = $wp->prepare("SELECT option_value FROM wp_options WHERE option_name=? LIMIT 1");
        $st->execute(['woocommerce_buluttahsilat_settings']);
        $raw = $st->fetchColumn();
        $bt = is_string($raw) ? self::maybeUnserialize($raw) : null;
        if (is_array($bt)) {
            $map = [
                'username' => 'bt_username',
                'password' => 'bt_password',
                'firm_api_code' => 'bt_firm',
                'secret_key_code' => 'bt_secret',
                'payment_exp' => 'bt_exp',
                'enabled' => 'bt_enabled',
            ];
            foreach ($map as $from => $to) {
                if (isset($bt[$from]) && (string) $bt[$from] !== '') {
                    setting_set($to, (string) $bt[$from]);
                }
            }
            self::$log[] = 'BulutTahsilat ayarları alındı.';
        }
        $phone = self::option($wp, 'woocommerce_store_phone') ?: self::option($wp, 'blogname');
        $blog = self::option($wp, 'blogname');
        if (is_string($blog) && $blog !== '') {
            setting_set('site_name', $blog);
        }
        $mail = self::option($wp, 'admin_email');
        if (is_string($mail) && $mail !== '') {
            setting_set('email', $mail);
        }
        if (is_string($phone) && preg_match('/\d/', $phone)) {
            setting_set('phone', $phone);
        }
    }

    private static function option(PDO $wp, string $key): string
    {
        $st = $wp->prepare("SELECT option_value FROM wp_options WHERE option_name=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return is_string($v) ? $v : '';
    }

    private static function importCategories(PDO $wp): void
    {
        $rows = $wp->query("SELECT t.term_id, t.name, t.slug, tt.parent, tt.description
            FROM wp_terms t
            INNER JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id
            WHERE tt.taxonomy='product_cat'")->fetchAll();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['term_id']] = $r;
        }
        $pathOf = [];
        $build = static function (int $id) use (&$build, &$pathOf, $byId): string {
            if (isset($pathOf[$id])) {
                return $pathOf[$id];
            }
            if (!isset($byId[$id])) {
                return (string) $id;
            }
            $slug = (string) $byId[$id]['slug'];
            $parent = (int) $byId[$id]['parent'];
            $path = $parent > 0 ? trim($build($parent) . '/' . $slug, '/') : $slug;
            $pathOf[$id] = $path;
            return $path;
        };
        db()->exec('DELETE FROM product_categories');
        db()->exec('DELETE FROM categories');
        $ins = db()->prepare("INSERT INTO categories (id,parent_id,slug,path,name,description,seo_title,seo_description,sort,status,min_qty,hide_nav)
            VALUES (?,?,?,?,?,?,?,?,0,'publish',?,?)");
        $mins = [];
        try {
            foreach ($wp->query("SELECT term_id, meta_value FROM wp_termmeta WHERE meta_key='hfs_b2b_min_qty'") as $m) {
                $mins[(int) $m['term_id']] = (int) $m['meta_value'];
            }
        } catch (Throwable) {
        }
        $n = 0;
        foreach ($byId as $id => $r) {
            $parent = (int) $r['parent'];
            $path = $build($id);
            $slug = (string) $r['slug'];
            $hide = ($slug === 'sistem-montajlari' || str_ends_with($slug, '-montaj') || str_contains($path, 'sistem-montajlari')) ? 1 : 0;
            $ins->execute([
                $id,
                $parent > 0 ? $parent : null,
                $slug,
                $path,
                (string) $r['name'],
                (string) $r['description'],
                (string) $r['name'] . ' — HFS Tarım Bayi',
                excerpt_plain((string) $r['description'], 150) ?: ((string) $r['name'] . ' yedek parça ve sistemler.'),
                $mins[$id] ?? 0,
                $hide,
            ]);
            $n++;
        }
        self::$log[] = $n . ' kategori aktarıldı.';
    }

    private static function importProducts(PDO $wp): void
    {
        $posts = $wp->query("SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, post_date, post_modified, post_parent, post_type, menu_order
            FROM wp_posts WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','inherit')")->fetchAll();
        $products = [];
        $variations = [];
        foreach ($posts as $p) {
            if ($p['post_type'] === 'product') {
                $products[(int) $p['ID']] = $p;
            } else {
                $variations[] = $p;
            }
        }
        $ids = array_keys($products);
        $varIds = array_map(static fn (array $v): int => (int) $v['ID'], $variations);
        $allIds = array_values(array_unique(array_merge($ids, $varIds)));
        $meta = self::loadMeta($wp, $allIds, [
            '_sku', '_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_manage_stock',
            '_thumbnail_id', '_product_type', '_virtual', '_hfs_b2b_diagram_image_url', '_hfs_b2b_diagram_points',
            '_hfs_b2b_has_parts_diagram',
        ]);
        $attrMeta = self::loadMetaLike($wp, $varIds, 'attribute_%');
        $files = self::attachmentFiles($wp, $meta);
        $featured = self::featuredIds($wp);
        $rel = [];
        if ($ids) {
            $chunk = array_chunk($ids, 400);
            foreach ($chunk as $part) {
                $ph = implode(',', array_fill(0, count($part), '?'));
                $st = $wp->prepare("SELECT tr.object_id, tt.term_id FROM wp_term_relationships tr
                    INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                    WHERE tt.taxonomy='product_cat' AND tr.object_id IN ($ph)");
                $st->execute($part);
                foreach ($st->fetchAll() as $row) {
                    $rel[(int) $row['object_id']][] = (int) $row['term_id'];
                }
            }
        }
        db()->exec('DELETE FROM product_option_groups');
        db()->exec('DELETE FROM variants');
        db()->exec('DELETE FROM product_meta');
        db()->exec('DELETE FROM product_categories');
        db()->exec('DELETE FROM products');
        $insP = db()->prepare("INSERT INTO products (id,slug,sku,title,excerpt,content,price,compare_price,stock,status,featured,featured_media,featured_alt,type,seo_title,seo_description,has_diagram,published_at,modified_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insC = db()->prepare('INSERT IGNORE INTO product_categories (product_id,category_id) VALUES (?,?)');
        $insM = db()->prepare('INSERT INTO product_meta (product_id,meta_key,meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');
        $insV = db()->prepare('INSERT INTO variants (id,product_id,sku,name,price,stock,sort,attrs) VALUES (?,?,?,?,?,?,?,?)');
        $usedSlugs = [];
        $n = 0;
        $d = 0;
        db()->beginTransaction();
        foreach ($products as $id => $p) {
            $m = $meta[$id] ?? [];
            $status = $p['post_status'] === 'publish' || $p['post_status'] === 'private' ? 'publish' : 'draft';
            $price = self::num($m['_price'] ?? $m['_regular_price'] ?? '0');
            $regular = self::num($m['_regular_price'] ?? '0');
            $sale = self::num($m['_sale_price'] ?? '0');
            $compare = ($regular > $price && $price > 0) ? $regular : (($sale > 0 && $sale < $price) ? $price : ($regular > $price ? $regular : null));
            $stockStatus = (string) ($m['_stock_status'] ?? 'instock');
            $stock = isset($m['_stock']) && $m['_stock'] !== '' ? (int) $m['_stock'] : ($stockStatus === 'instock' ? 100 : 0);
            if ($stock < 0) {
                $stock = 0;
            }
            $thumb = (int) ($m['_thumbnail_id'] ?? 0);
            $media = $files[$thumb] ?? '';
            $slug = self::uniqueSlug((string) ($p['post_name'] ?: slugify((string) $p['post_title'])), $usedSlugs);
            $usedSlugs[$slug] = true;
            $points = self::maybeUnserialize($m['_hfs_b2b_diagram_points'] ?? '');
            $img = trim((string) ($m['_hfs_b2b_diagram_image_url'] ?? ''));
            $has = ($img !== '' && is_array($points) && $points !== []) || (($m['_hfs_b2b_has_parts_diagram'] ?? '') === '1');
            if ($has) {
                $d++;
            }
            $type = ((string) ($m['_virtual'] ?? '') === 'yes') ? 'digital' : 'simple';
            $excerpt = trim(html_entity_decode(strip_tags((string) $p['post_excerpt']), ENT_QUOTES, 'UTF-8'));
            $insP->execute([
                $id,
                $slug,
                (string) ($m['_sku'] ?? ''),
                (string) $p['post_title'],
                $excerpt !== '' ? $excerpt : excerpt_plain((string) $p['post_content'], 180),
                (string) $p['post_content'],
                $price,
                $compare,
                $stock,
                $status,
                isset($featured[$id]) ? 1 : 0,
                $media,
                (string) $p['post_title'],
                $type,
                (string) $p['post_title'] . ' — HFS Tarım Bayi',
                $excerpt !== '' ? $excerpt : excerpt_plain((string) $p['post_content'], 150),
                $has ? 1 : 0,
                $p['post_date'],
                $p['post_modified'],
            ]);
            foreach ($rel[$id] ?? [] as $cid) {
                $insC->execute([$id, $cid]);
            }
            if ($img !== '') {
                $insM->execute([$id, 'diagram_image', $img]);
            }
            if (is_array($points) && $points !== []) {
                $insM->execute([$id, 'diagram_points', json_encode(array_values($points), JSON_UNESCAPED_UNICODE)]);
            }
            $n++;
            if ($n % 200 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        $vn = 0;
        foreach ($variations as $v) {
            $pid = (int) $v['post_parent'];
            if (!isset($products[$pid])) {
                continue;
            }
            $id = (int) $v['ID'];
            $m = $meta[$id] ?? [];
            $attrs = $attrMeta[$id] ?? [];
            $labels = [];
            foreach ($attrs as $k => $val) {
                $val = trim((string) $val);
                if ($val === '') {
                    continue;
                }
                $labels[] = self::attrLabel($wp, $k, $val);
            }
            $name = $labels ? implode(' / ', $labels) : ((string) $v['post_title'] ?: 'Seçenek');
            $price = self::num($m['_price'] ?? $m['_regular_price'] ?? '');
            $stockStatus = (string) ($m['_stock_status'] ?? 'instock');
            $stock = isset($m['_stock']) && $m['_stock'] !== '' ? (int) $m['_stock'] : ($stockStatus === 'instock' ? 100 : 0);
            $insV->execute([
                $id,
                $pid,
                (string) ($m['_sku'] ?? ''),
                mb_substr($name, 0, 180),
                $price > 0 ? $price : null,
                max(0, $stock),
                (int) $v['menu_order'],
                $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : '',
            ]);
            db()->prepare("UPDATE products SET type='variable' WHERE id=? AND type='simple'")->execute([$pid]);
            $vn++;
        }
        db()->commit();
        $max = (int) db()->query('SELECT COALESCE(MAX(id),0) FROM products')->fetchColumn();
        $vmax = (int) db()->query('SELECT COALESCE(MAX(id),0) FROM variants')->fetchColumn();
        $cmax = (int) db()->query('SELECT COALESCE(MAX(id),0) FROM categories')->fetchColumn();
        if ($max > 0) {
            db()->exec('ALTER TABLE products AUTO_INCREMENT=' . ($max + 1));
        }
        if ($vmax > 0) {
            db()->exec('ALTER TABLE variants AUTO_INCREMENT=' . ($vmax + 1));
        }
        if ($cmax > 0) {
            db()->exec('ALTER TABLE categories AUTO_INCREMENT=' . ($cmax + 1));
        }
        self::$log[] = $n . ' ürün, ' . $vn . ' varyasyon, ' . $d . ' diyagram aktarıldı.';
    }

    private static function importUsers(PDO $wp): void
    {
        $users = $wp->query('SELECT ID, user_login, user_email, user_pass, display_name, user_registered FROM wp_users')->fetchAll();
        $caps = [];
        $st = $wp->query("SELECT user_id, meta_value FROM wp_usermeta WHERE meta_key='wp_capabilities'");
        foreach ($st as $row) {
            $caps[(int) $row['user_id']] = self::maybeUnserialize($row['meta_value']);
        }
        $umeta = [];
        $keys = [
            'hfs_b2b_discount_percent', 'hfs_b2b_tier_min_quantity', 'hfs_b2b_tier_discount_percent',
            'hfs_b2b_credit_limit', 'hfs_b2b_opening_balance', 'hfs_b2b_monthly_purchase_limit',
            'hfs_b2b_parent_user_id', 'hfs_b2b_role', 'hfs_b2b_cari_payments_total',
            'billing_phone', 'billing_city', 'billing_address_1', 'billing_company', 'first_name', 'last_name',
        ];
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $wp->prepare("SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE meta_key IN ($ph)");
        $st->execute($keys);
        foreach ($st as $row) {
            $umeta[(int) $row['user_id']][$row['meta_key']] = $row['meta_value'];
        }
        $ins = db()->prepare("INSERT INTO users (id,username,email,password,display_name,role,created_at,phone,company,city,address,parent_user_id,b2b_role,discount_percent,tier_min_qty,tier_discount,credit_limit,opening_balance,monthly_limit,cari_payments_total,wp_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE email=VALUES(email), password=VALUES(password), display_name=VALUES(display_name),
            phone=VALUES(phone), company=VALUES(company), city=VALUES(city), address=VALUES(address),
            parent_user_id=VALUES(parent_user_id), b2b_role=VALUES(b2b_role), discount_percent=VALUES(discount_percent),
            tier_min_qty=VALUES(tier_min_qty), tier_discount=VALUES(tier_discount), credit_limit=VALUES(credit_limit),
            opening_balance=VALUES(opening_balance), monthly_limit=VALUES(monthly_limit),
            cari_payments_total=VALUES(cari_payments_total), wp_user_id=VALUES(wp_user_id),
            role=IF(role IN ('admin','editor'), role, VALUES(role))");
        $n = 0;
        foreach ($users as $u) {
            $id = (int) $u['ID'];
            $cap = $caps[$id] ?? [];
            $isAdmin = is_array($cap) && (isset($cap['administrator']) || isset($cap['shop_manager']));
            $isCust = is_array($cap) && (isset($cap['customer']) || isset($cap['subscriber']));
            $m = $umeta[$id] ?? [];
            $b2b = trim((string) ($m['hfs_b2b_role'] ?? ''));
            $parent = (int) ($m['hfs_b2b_parent_user_id'] ?? 0);
            if (!$isAdmin && !$isCust && $b2b === '' && $parent < 1) {
                continue;
            }
            $role = $isAdmin ? 'admin' : 'dealer';
            $name = trim((string) $u['display_name']);
            if ($name === '') {
                $name = trim((string) ($m['first_name'] ?? '') . ' ' . (string) ($m['last_name'] ?? ''));
            }
            $email = (string) $u['user_email'];
            if ($email === '') {
                $email = $u['user_login'] . '@hfstarim.local';
            }
            try {
                $ins->execute([
                    $id,
                    (string) $u['user_login'],
                    $email,
                    (string) $u['user_pass'],
                    $name !== '' ? $name : (string) $u['user_login'],
                    $role,
                    $u['user_registered'] ?: date('Y-m-d H:i:s'),
                    (string) ($m['billing_phone'] ?? ''),
                    (string) ($m['billing_company'] ?? ''),
                    (string) ($m['billing_city'] ?? ''),
                    (string) ($m['billing_address_1'] ?? ''),
                    $parent,
                    $b2b,
                    (float) ($m['hfs_b2b_discount_percent'] ?? 0),
                    (int) ($m['hfs_b2b_tier_min_quantity'] ?? 0),
                    (float) ($m['hfs_b2b_tier_discount_percent'] ?? 0),
                    (float) ($m['hfs_b2b_credit_limit'] ?? 0),
                    (float) ($m['hfs_b2b_opening_balance'] ?? 0),
                    (float) ($m['hfs_b2b_monthly_purchase_limit'] ?? 0),
                    (float) ($m['hfs_b2b_cari_payments_total'] ?? 0),
                    $id,
                ]);
                $n++;
            } catch (Throwable $e) {
                self::$log[] = 'Kullanıcı atlandı ' . $u['user_login'] . ': ' . $e->getMessage();
            }
        }
        $max = (int) db()->query('SELECT COALESCE(MAX(id),0) FROM users')->fetchColumn();
        if ($max > 0) {
            db()->exec('ALTER TABLE users AUTO_INCREMENT=' . ($max + 1));
        }
        self::$log[] = $n . ' kullanıcı aktarıldı (bayi + yönetici).';
    }

    /** @param list<int> $ids @param list<string> $keys @return array<int,array<string,string>> */
    private static function loadMeta(PDO $wp, array $ids, array $keys): array
    {
        $out = [];
        if ($ids === [] || $keys === []) {
            return $out;
        }
        foreach (array_chunk($ids, 300) as $part) {
            $phI = implode(',', array_fill(0, count($part), '?'));
            $phK = implode(',', array_fill(0, count($keys), '?'));
            $st = $wp->prepare("SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN ($phI) AND meta_key IN ($phK)");
            $st->execute(array_merge($part, $keys));
            foreach ($st as $row) {
                $out[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int,array<string,string>> */
    private static function loadMetaLike(PDO $wp, array $ids, string $like): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach (array_chunk($ids, 300) as $part) {
            $ph = implode(',', array_fill(0, count($part), '?'));
            $st = $wp->prepare("SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN ($ph) AND meta_key LIKE ?");
            $st->execute(array_merge($part, [$like]));
            foreach ($st as $row) {
                $out[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
            }
        }
        return $out;
    }

    /** @param array<int,array<string,string>> $meta @return array<int,string> */
    private static function attachmentFiles(PDO $wp, array $meta): array
    {
        $ids = [];
        foreach ($meta as $row) {
            $tid = (int) ($row['_thumbnail_id'] ?? 0);
            if ($tid > 0) {
                $ids[$tid] = true;
            }
        }
        $list = array_keys($ids);
        $files = [];
        if ($list === []) {
            return $files;
        }
        foreach (array_chunk($list, 400) as $part) {
            $ph = implode(',', array_fill(0, count($part), '?'));
            $st = $wp->prepare("SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key='_wp_attached_file' AND post_id IN ($ph)");
            $st->execute($part);
            foreach ($st as $row) {
                $files[(int) $row['post_id']] = (string) $row['meta_value'];
            }
        }
        return $files;
    }

    /** @return array<int,true> */
    private static function featuredIds(PDO $wp): array
    {
        $out = [];
        try {
            $rows = $wp->query("SELECT tr.object_id FROM wp_term_relationships tr
                INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                INNER JOIN wp_terms t ON t.term_id=tt.term_id
                WHERE tt.taxonomy='product_visibility' AND t.slug='featured'")->fetchAll();
            foreach ($rows as $r) {
                $out[(int) $r['object_id']] = true;
            }
        } catch (Throwable) {
        }
        return $out;
    }

    private static function uniqueSlug(string $slug, array $used): string
    {
        $slug = $slug !== '' ? $slug : 'urun';
        $base = $slug;
        $i = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private static function num(mixed $v): float
    {
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace([' ', ','], ['', '.'], $s);
        return (float) $s;
    }

    private static function attrLabel(PDO $wp, string $key, string $val): string
    {
        $key = preg_replace('/^attribute_/', '', $key) ?? $key;
        if (str_starts_with($key, 'pa_')) {
            static $cache = [];
            $ck = $key . '|' . $val;
            if (!isset($cache[$ck])) {
                $st = $wp->prepare("SELECT t.name FROM wp_terms t INNER JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.taxonomy=? AND t.slug=? LIMIT 1");
                $st->execute([$key, $val]);
                $name = $st->fetchColumn();
                $cache[$ck] = is_string($name) && $name !== '' ? $name : $val;
            }
            return $cache[$ck];
        }
        return $val;
    }

    private static function maybeUnserialize(mixed $v): mixed
    {
        if (!is_string($v) || $v === '') {
            return $v;
        }
        if ($v[0] === '{' || $v[0] === '[') {
            $j = json_decode($v, true);
            if (is_array($j)) {
                return $j;
            }
        }
        if (!preg_match('/^(a|O|s|b|i|d):/', $v)) {
            return $v;
        }
        $u = @unserialize($v, ['allowed_classes' => false]);
        return $u === false && $v !== 'b:0;' ? $v : $u;
    }
}
