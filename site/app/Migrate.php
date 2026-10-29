<?php
declare(strict_types=1);

final class Migrate
{
    public static function up(): void
    {
        $v = (int) setting('schema_version', '1');
        if ($v < 2) {
            self::columns();
            self::tables();
            self::seed();
            setting_set('schema_version', '2');
            $v = 2;
        }
        if ($v < 3) {
            self::branding();
            setting_set('schema_version', '3');
            $v = 3;
        }
        if ($v < 4) {
            self::options();
            Option::seedCoffee();
            Seed::syncTaxonomy();
            setting_set('schema_version', '4');
        }
    }

    private static function columns(): void
    {
        self::col('products', 'type', "VARCHAR(20) NOT NULL DEFAULT 'simple'");
        self::col('products', 'personalize', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::col('products', 'upsell_id', 'INT UNSIGNED NULL');
        self::col('orders', 'country', "VARCHAR(2) NOT NULL DEFAULT 'TR'");
        self::col('orders', 'coupon_code', 'VARCHAR(40) NULL');
        self::col('orders', 'discount', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        self::col('orders', 'channel', "VARCHAR(40) NOT NULL DEFAULT 'web'");
        self::col('orders', 'tracking', 'VARCHAR(80) NULL');
        self::col('orders', 'ship_slot', 'VARCHAR(40) NULL');
        self::col('order_items', 'note', 'VARCHAR(240) NULL');
    }

    private static function tables(): void
    {
        $stmts = [
            "CREATE TABLE IF NOT EXISTS `coupons` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `code` VARCHAR(40) NOT NULL,
              `type` VARCHAR(20) NOT NULL DEFAULT 'percent',
              `value` DECIMAL(10,2) NOT NULL DEFAULT 0,
              `min_subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0,
              `max_uses` INT NOT NULL DEFAULT 0,
              `used` INT NOT NULL DEFAULT 0,
              `starts_at` DATETIME NULL,
              `ends_at` DATETIME NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'publish',
              PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `product_links` (
              `product_id` INT UNSIGNED NOT NULL,
              `target_id` INT UNSIGNED NOT NULL,
              `kind` VARCHAR(20) NOT NULL DEFAULT 'cross',
              PRIMARY KEY (`product_id`,`target_id`,`kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `bundle_items` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `product_id` INT UNSIGNED NOT NULL,
              `child_id` INT UNSIGNED NOT NULL,
              `variant_id` INT UNSIGNED NULL,
              `qty` INT NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`), KEY `product_id` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `abandoned_carts` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `token` VARCHAR(64) NOT NULL,
              `email` VARCHAR(190) NULL,
              `payload` MEDIUMTEXT NOT NULL,
              `reminders` TINYINT NOT NULL DEFAULT 0,
              `recovered` TINYINT(1) NOT NULL DEFAULT 0,
              `updated_at` DATETIME NOT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`), UNIQUE KEY `token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `reviews` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `product_id` INT UNSIGNED NOT NULL,
              `order_id` INT UNSIGNED NULL,
              `name` VARCHAR(120) NOT NULL,
              `email` VARCHAR(190) NOT NULL,
              `rating` TINYINT NOT NULL DEFAULT 5,
              `body` TEXT NOT NULL,
              `photo` VARCHAR(500) NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
              `reply` TEXT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`), KEY `product_status` (`product_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `review_invites` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_id` INT UNSIGNED NOT NULL,
              `token` VARCHAR(64) NOT NULL,
              `sent_at` DATETIME NULL,
              PRIMARY KEY (`id`), UNIQUE KEY `token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `posts` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `slug` VARCHAR(160) NOT NULL,
              `title` VARCHAR(190) NOT NULL,
              `excerpt` TEXT NULL,
              `content` MEDIUMTEXT NULL,
              `seo_title` VARCHAR(190) NULL,
              `seo_description` VARCHAR(320) NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'publish',
              `published_at` DATETIME NULL,
              `modified_at` DATETIME NULL,
              PRIMARY KEY (`id`), UNIQUE KEY `slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `stock_alerts` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `product_id` INT UNSIGNED NOT NULL,
              `email` VARCHAR(190) NOT NULL,
              `sent_at` DATETIME NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`), UNIQUE KEY `prod_email` (`product_id`,`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `returns` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_id` INT UNSIGNED NOT NULL,
              `reason` VARCHAR(240) NOT NULL,
              `status` VARCHAR(24) NOT NULL DEFAULT 'yeni',
              `note` TEXT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `mail_log` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `to_email` VARCHAR(190) NOT NULL,
              `subject` VARCHAR(190) NOT NULL,
              `body` MEDIUMTEXT NOT NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
              `err` VARCHAR(400) NOT NULL DEFAULT '',
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($stmts as $sql) {
            db()->exec($sql);
        }
    }

    private static function seed(): void
    {
        $n = (int) db()->query('SELECT COUNT(*) FROM coupons')->fetchColumn();
        if ($n < 1) {
            $ins = db()->prepare('INSERT INTO coupons (code,type,value,min_subtotal,max_uses,used,status) VALUES (?,?,?,?,0,0,\'publish\')');
            $ins->execute(['BMN10', 'percent', 10, 500]);
            $ins->execute(['KARGO', 'free_ship', 0, 0]);
            $ins->execute(['SEP10', 'percent', 10, 0]);
        }
        if ((string) setting('volume_2_pct', '') === '') {
            setting_set('volume_2_pct', '0');
            setting_set('volume_3_pct', '0');
        }
        if ((string) setting('ship_intl', '') === '') {
            setting_set('ship_intl', '189');
        }
        if ((string) setting('ship_slots', '') === '') {
            setting_set('ship_slots', "09:00-12:00\n12:00-18:00\n18:00-21:00");
        }
        $st = db()->prepare('SELECT id FROM posts WHERE slug=?');
        $st->execute(['beden-ve-bakim']);
        if (!$st->fetch()) {
            db()->prepare('INSERT INTO posts (slug,title,excerpt,content,seo_title,seo_description,status,published_at,modified_at) VALUES (?,?,?,?,?,?,\'publish\',NOW(),NOW())')
                ->execute([
                    'beden-ve-bakim',
                    'Forma bedeni ve bakım',
                    'S–XL kalıp notu, yıkama ve iade. BMN atölye kesimi.',
                    '<p>BMN formalar dar kalıptır. İki beden arasında kaldıysan bir üstü al. Yıkama 30 derece, ters çevir, ütü yok.</p><p>İade 14 gün, etiketli. Ayrıntı <a href="' . h(site_url('kargo-iade/')) . '">kargo ve iade</a> sayfasında.</p>',
                    'Forma Bedeni ve Bakım — BMN Dükkan',
                    'BMN forma kalıbı, yıkama ve 14 gün iade. Atölye kesimi beden notu.',
                ]);
        }
        self::seedLinks();
        $dir = BMN_ROOT . '/storage/uploads/reviews';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $deny = BMN_ROOT . '/storage/uploads/.htaccess';
        if (!is_file($deny)) {
            file_put_contents($deny, "php_flag engine off\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n");
        }
    }

    private static function seedLinks(): void
    {
        $ids = [];
        foreach (['ic-saha-forma-2627', 'hoodie-bordo', 'tribun-atki', 'bordo-bere'] as $slug) {
            $st = db()->prepare('SELECT id FROM products WHERE slug=?');
            $st->execute([$slug]);
            $ids[$slug] = (int) $st->fetchColumn();
        }
        $ins = db()->prepare('INSERT IGNORE INTO product_links (product_id,target_id,kind) VALUES (?,?,?)');
        if ($ids['ic-saha-forma-2627'] && $ids['tribun-atki']) {
            $ins->execute([$ids['ic-saha-forma-2627'], $ids['tribun-atki'], 'cross']);
            $ins->execute([$ids['ic-saha-forma-2627'], $ids['hoodie-bordo'], 'up']);
        }
        if ($ids['hoodie-bordo'] && $ids['bordo-bere']) {
            $ins->execute([$ids['hoodie-bordo'], $ids['bordo-bere'], 'cross']);
        }
        $forma = $ids['ic-saha-forma-2627'];
        $atki = $ids['tribun-atki'];
        $bere = $ids['bordo-bere'];
        if ($forma && $atki && $bere) {
            $st = db()->prepare('SELECT id FROM products WHERE slug=?');
            $st->execute(['tribun-seti']);
            $bid = (int) $st->fetchColumn();
            if ($bid < 1) {
                db()->prepare("INSERT INTO products (slug,sku,title,excerpt,content,price,compare_price,stock,status,featured,featured_media,featured_alt,brand,material,seo_title,seo_description,type,published_at,modified_at) VALUES ('tribun-seti','BMN-SET-TR','Tribün seti','Forma + atkı + bere. Paket fiyat.','<p>İç saha forma, tribün atkısı ve bere tek pakette. Ayrı alıma göre indirimli.</p>',2490,2670,20,'publish',1,?,?, 'BMN','Karışık','Tribün Seti Forma Atkı Bere — BMN','İç saha forma, atkı ve bere paket fiyatı. BMN atölye.','bundle',NOW(),NOW())")
                    ->execute([asset('products/forma-ic.svg'), 'Tribün seti: forma, atkı, bere']);
                $bid = (int) db()->lastInsertId();
                $cat = db()->query("SELECT id FROM categories WHERE slug='formalar' LIMIT 1")->fetchColumn();
                if ($cat) {
                    db()->prepare('INSERT IGNORE INTO product_categories (product_id,category_id) VALUES (?,?)')->execute([$bid, (int) $cat]);
                }
            }
            $b = db()->prepare('INSERT IGNORE INTO bundle_items (product_id,child_id,qty) VALUES (?,?,1)');
            $b->execute([$bid, $forma]);
            $b->execute([$bid, $atki]);
            $b->execute([$bid, $bere]);
            db()->prepare('UPDATE products SET personalize=1 WHERE slug=?')->execute(['ic-saha-forma-2627']);
        }
    }

    private static function branding(): void
    {
        $sets = [
            'site_name' => 'BMN Dükkan',
            'tagline' => 'Trabzonspor taraftar koleksiyonu',
            'seo_title' => 'BMN Dükkan — Forma, eşofman, atkı | BordoMaviNet',
            'seo_description' => 'BMN Dükkan: Trabzonspor taraftar forması, eşofman, atkı ve arma. dukkan.bordomavi.net. Hızlı kargo, 14 gün iade.',
            'org_description' => 'BMN Dükkan, Trabzonsporlular Birliği’nin koleksiyon atölyesidir. Resmî kulüp mağazası değildir. Canlı: dukkan.bordomavi.net',
            'email' => 'dukkan@bordomavi.net',
            'logo' => shop_logo(),
            'og_image' => shop_og(),
        ];
        foreach ($sets as $k => $v) {
            setting_set($k, $v);
        }
        foreach (['products' => ['seo_title', 'seo_description', 'content', 'excerpt'], 'pages' => ['title', 'content', 'seo_title', 'seo_description'], 'posts' => ['title', 'excerpt', 'content', 'seo_title', 'seo_description'], 'categories' => ['description', 'seo_title', 'seo_description']] as $table => $cols) {
            foreach ($cols as $col) {
                db()->exec("UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, 'BMN Mağaza', 'BMN Dükkan')");
                db()->exec("UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, 'magaza@bordomavi.net', 'dukkan@bordomavi.net')");
            }
        }
        $GLOBALS['bmn_settings'] = null;
    }

    private static function col(string $table, string $col, string $def): void
    {
        $ok = ['products', 'orders', 'order_items'];
        if (!in_array($table, $ok, true) || !preg_match('/^[a-z_]+$/', $col)) {
            return;
        }
        $st = db()->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . db()->quote($col));
        if ($st && $st->fetch()) {
            return;
        }
        db()->exec('ALTER TABLE `' . $table . '` ADD `' . $col . '` ' . $def);
    }

    private static function options(): void
    {
        db()->exec("CREATE TABLE IF NOT EXISTS `option_groups` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `slug` VARCHAR(80) NOT NULL,
          `name` VARCHAR(120) NOT NULL,
          `sort` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS `option_values` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `group_id` INT UNSIGNED NOT NULL,
          `slug` VARCHAR(80) NOT NULL,
          `name` VARCHAR(120) NOT NULL,
          `sort` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `group_slug` (`group_id`,`slug`),
          KEY `group_id` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS `product_option_groups` (
          `product_id` INT UNSIGNED NOT NULL,
          `group_id` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`product_id`,`group_id`),
          KEY `group_id` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $st = db()->query("SHOW COLUMNS FROM `variants` LIKE 'attrs'");
        if (!$st || !$st->fetch()) {
            db()->exec('ALTER TABLE `variants` ADD `attrs` VARCHAR(255) NOT NULL DEFAULT \'\'');
        }
        db()->exec('ALTER TABLE `variants` MODIFY `name` VARCHAR(190) NOT NULL');
        db()->exec('ALTER TABLE `variants` MODIFY `sku` VARCHAR(160) NOT NULL DEFAULT \'\'');
    }
}
