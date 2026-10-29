<?php
declare(strict_types=1);

final class HfsMigrate
{
    public static function up(): void
    {
        $v = (int) setting('hfs_schema', '0');
        if ($v < 1) {
            self::schema();
            setting_set('hfs_schema', '1');
            $v = 1;
        }
        if ($v < 2) {
            self::schema();
            setting_set('hfs_schema', '2');
            $v = 2;
        }
        if ($v < 3) {
            self::schema3();
            setting_set('hfs_schema', '3');
            $v = 3;
        }
        if ($v < 4) {
            self::col('dealer_offers', 'qty_prices', "VARCHAR(240) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '4');
            $v = 4;
        }
        if ($v < 5) {
            self::col('mail_log', 'err', "VARCHAR(400) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '5');
            $v = 5;
        }
        if ($v < 6) {
            self::col('ledger', 'bayt_status', "VARCHAR(20) NOT NULL DEFAULT ''");
            self::col('ledger', 'bayt_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
            self::col('ledger', 'bayt_ref', "VARCHAR(80) NOT NULL DEFAULT ''");
            self::col('ledger', 'bayt_error', 'TEXT NULL');
            self::col('ledger', 'bayt_sent_at', 'DATETIME NULL');
            setting_set('hfs_schema', '6');
        }
        if ($v < 7) {
            self::col('users', 'bt_exp_code', "VARCHAR(80) NOT NULL DEFAULT ''");
            self::col('users', 'tax_office', "VARCHAR(80) NOT NULL DEFAULT ''");
            self::col('users', 'tax_number', "VARCHAR(20) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '7');
        }
        if ($v < 8) {
            self::fixAynaDisli17();
            setting_set('hfs_schema', '8');
        }
        if ($v < 9) {
            self::fixMediaBase();
            setting_set('hfs_schema', '9');
        }
        if ($v < 10) {
            self::col('ledger', 'status', "VARCHAR(20) NOT NULL DEFAULT 'ok'");
            self::col('ledger', 'voided_at', 'DATETIME NULL');
            setting_set('hfs_schema', '10');
        }
        if ($v < 11) {
            setting_set('hfs_bayt_backfill', '1');
            setting_set('hfs_schema', '11');
        }
        if ($v < 12) {
            self::col('orders', 'bayt_approval', "VARCHAR(20) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '12');
        }
        if ($v < 13) {
            setting_set('hfs_bayt_waybill_backfill', '1');
            setting_set('hfs_schema', '13');
        }
        if ($v < 14) {
            self::col('orders', 'bayt_order_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
            setting_set('hfs_bayt_order_backfill', '1');
            setting_set('hfs_schema', '14');
        }
        if ($v < 15) {
            self::schemaProductOptionValues();
            Option::seedHfsMachines();
            Option::wireMachineVariants([
                'sirttan-askili-zeytin-hasat-makinasi-motorsuz',
                'yandan-askili-zeytin-hasat-makinasi-motorsuz',
            ]);
            setting_set('hfs_schema', '15');
            $v = 15;
        }
        if ($v < 16) {
            self::col('users', 'district', "VARCHAR(80) NOT NULL DEFAULT ''");
            self::col('users', 'postcode', "VARCHAR(10) NOT NULL DEFAULT ''");
            self::col('users', 'country', "VARCHAR(2) NOT NULL DEFAULT 'TR'");
            setting_set('hfs_schema', '16');
            $v = 16;
        }
        if ($v < 17) {
            self::col('orders', 'postcode', "VARCHAR(10) NOT NULL DEFAULT ''");
            self::col('orders', 'bayt_phase', "VARCHAR(40) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '17');
            $v = 17;
        }
        if ($v < 18) {
            self::col('orders', 'bayt_dispatch_at', 'DATETIME NULL');
            setting_set('hfs_schema', '18');
            $v = 18;
        }
        if ($v < 19) {
            self::schemaAuthRemember();
            setting_set('hfs_schema', '19');
            $v = 19;
        }
        if ($v < 20) {
            self::col('orders', 'bayt_collection_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
            self::col('orders', 'bayt_collection_ref', "VARCHAR(32) NOT NULL DEFAULT ''");
            self::col('orders', 'bayt_collection_status', "VARCHAR(24) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '20');
            $v = 20;
        }
        if ($v < 21) {
            self::schemaIntegrationLog();
            setting_set('hfs_schema', '21');
            $v = 21;
        }
        if ($v < 22) {
            self::schemaAuthPassword();
            setting_set('hfs_schema', '22');
            $v = 22;
        }
        if ($v < 23) {
            self::col('orders', 'yk_job_id', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'yk_status', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'yk_error', 'TEXT NULL');
            setting_set('hfs_schema', '23');
            $v = 23;
        }
        if ($v < 24) {
            self::col('orders', 'bt_tx_id', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_payment_id', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_auth_code', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_installment', "VARCHAR(12) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_card_holder', "VARCHAR(120) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_card_mask', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_card_bank', "VARCHAR(80) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_pos_bank', "VARCHAR(80) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_merchant_id', "VARCHAR(40) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_user_name', "VARCHAR(120) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_bank_message', "VARCHAR(200) NOT NULL DEFAULT ''");
            self::col('orders', 'bt_dekont_url', "VARCHAR(500) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '24');
            $v = 24;
        }
        if ($v < 25) {
            self::col('users', 'cart_json', 'LONGTEXT NULL');
            setting_set('hfs_schema', '25');
            $v = 25;
        }
        if ($v < 26) {
            try {
                db()->exec("ALTER TABLE `orders` MODIFY `payment_status` VARCHAR(240) NOT NULL DEFAULT ''");
            } catch (Throwable) {
            }
            try {
                db()->exec("ALTER TABLE `orders` MODIFY `bt_bank_message` VARCHAR(400) NOT NULL DEFAULT ''");
            } catch (Throwable) {
            }
            setting_set('hfs_schema', '26');
            $v = 26;
        }
        if ($v < 27) {
            self::col('orders', 'consent_kvkk_at', 'DATETIME NULL');
            self::col('orders', 'consent_terms_at', 'DATETIME NULL');
            self::col('orders', 'consent_kvkk_ver', "VARCHAR(20) NOT NULL DEFAULT ''");
            self::col('orders', 'consent_terms_ver', "VARCHAR(20) NOT NULL DEFAULT ''");
            setting_set('hfs_schema', '27');
            $v = 27;
        }
        if ($v < 28) {
            require_once BMN_ROOT . '/app/HfsMediaCutover.php';
            HfsMediaCutover::applySettings();
            HfsMediaCutover::rewriteDatabaseUrls();
            HfsMediaCutover::invalidateCaches();
            setting_set('hfs_schema', '28');
        }
    }

    private static function schemaAuthPassword(): void
    {
        self::col('users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');
        db()->exec("CREATE TABLE IF NOT EXISTS `auth_password_reset` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT UNSIGNED NOT NULL,
          `token_hash` CHAR(64) NOT NULL,
          `expires_at` DATETIME NOT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_token` (`token_hash`),
          KEY `idx_user` (`user_id`),
          KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function schemaIntegrationLog(): void
    {
        db()->exec("CREATE TABLE IF NOT EXISTS `integration_log` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `source` VARCHAR(16) NOT NULL DEFAULT '',
          `event` VARCHAR(64) NOT NULL DEFAULT '',
          `ok` TINYINT(1) NOT NULL DEFAULT 1,
          `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `order_code` VARCHAR(32) NOT NULL DEFAULT '',
          `message` VARCHAR(2000) NOT NULL DEFAULT '',
          `context` TEXT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_created` (`created_at`),
          KEY `idx_source` (`source`),
          KEY `idx_order_code` (`order_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function schemaAuthRemember(): void
    {
        db()->exec("CREATE TABLE IF NOT EXISTS `auth_remember` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT UNSIGNED NOT NULL,
          `token_hash` CHAR(64) NOT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_token` (`token_hash`),
          KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function schemaProductOptionValues(): void
    {
        db()->exec("CREATE TABLE IF NOT EXISTS `product_option_values` (
          `product_id` INT UNSIGNED NOT NULL,
          `value_id` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`product_id`,`value_id`),
          KEY `value_id` (`value_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Görseller b2b kökündeki uploads/ altından servis edilir. */
    private static function fixMediaBase(): void
    {
        require_once BMN_ROOT . '/app/HfsMediaCutover.php';
        HfsMediaCutover::applySettings();
    }

    /** Katalog: 17-A ø12, 17-B ø10-12, 17-C ø10. Yan/V2 kaymaları düzeltilir. */
    private static function fixAynaDisli17(): void
    {
        $map = [
            'A' => 'AYNA DİŞLİ ø12 MM',
            'B' => 'AYNA DİŞLİ ø10-12 MM',
            'C' => 'AYNA DİŞLİ ø10 MM',
        ];
        $st = db()->prepare("UPDATE products SET title=? WHERE sku LIKE ? AND title LIKE '%AYNA%'");
        foreach ($map as $suf => $title) {
            $st->execute([$title, '%-17-' . $suf]);
        }
    }

    private static function schema(): void
    {
        self::col('users', 'phone', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('users', 'company', "VARCHAR(190) NOT NULL DEFAULT ''");
        self::col('users', 'city', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('users', 'district', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('users', 'postcode', "VARCHAR(10) NOT NULL DEFAULT ''");
        self::col('users', 'country', "VARCHAR(2) NOT NULL DEFAULT 'TR'");
        self::col('users', 'address', 'TEXT NULL');
        self::col('users', 'parent_user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('users', 'b2b_role', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('users', 'discount_percent', 'DECIMAL(6,2) NOT NULL DEFAULT 0');
        self::col('users', 'tier_min_qty', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('users', 'tier_discount', 'DECIMAL(6,2) NOT NULL DEFAULT 0');
        self::col('users', 'credit_limit', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        self::col('users', 'opening_balance', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        self::col('users', 'monthly_limit', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        self::col('users', 'cari_payments_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        self::col('users', 'wp_user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('orders', 'user_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('orders', 'company_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('orders', 'is_cari', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::col('orders', 'payment_ref', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'payment_status', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_date', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_total', "VARCHAR(24) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_currency', "VARCHAR(8) NOT NULL DEFAULT 'TRY'");
        self::col('orders', 'bt_guid', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_tx_id', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_payment_id', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_auth_code', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_installment', "VARCHAR(12) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_card_holder', "VARCHAR(120) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_card_mask', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_card_bank', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_pos_bank', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_merchant_id', "VARCHAR(40) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_user_name', "VARCHAR(120) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_bank_message', "VARCHAR(200) NOT NULL DEFAULT ''");
        self::col('orders', 'bt_dekont_url', "VARCHAR(500) NOT NULL DEFAULT ''");
        self::col('categories', 'min_qty', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('categories', 'hide_nav', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::col('products', 'has_diagram', 'TINYINT(1) NOT NULL DEFAULT 0');
        db()->exec("CREATE TABLE IF NOT EXISTS `product_meta` (
          `product_id` INT UNSIGNED NOT NULL,
          `meta_key` VARCHAR(120) NOT NULL,
          `meta_value` LONGTEXT NULL,
          PRIMARY KEY (`product_id`,`meta_key`),
          KEY `meta_key` (`meta_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS `favorites` (
          `user_id` INT UNSIGNED NOT NULL,
          `product_id` INT UNSIGNED NOT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`user_id`,`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS `ledger` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `company_id` INT UNSIGNED NOT NULL,
          `kind` VARCHAR(40) NOT NULL,
          `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
          `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `note` VARCHAR(240) NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          KEY `company_id` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try {
            db()->exec('ALTER TABLE `users` ADD KEY `wp_user_id` (`wp_user_id`)');
        } catch (Throwable) {
        }
    }

    private static function schema3(): void
    {
        self::col('users', 'approval_limit', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        self::col('users', 'order_templates', 'LONGTEXT NULL');
        self::col('users', 'bayt_customer_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('users', 'bayt_customer_code', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'po_number', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'pending_approval', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::col('orders', 'approval_limit_used', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        self::col('orders', 'bayt_status', "VARCHAR(20) NOT NULL DEFAULT ''");
        self::col('orders', 'bayt_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::col('orders', 'bayt_ref', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::col('orders', 'bayt_error', 'TEXT NULL');
        self::col('orders', 'bayt_sent_at', 'DATETIME NULL');
        self::col('orders', 'bayt_doc_type', "VARCHAR(20) NOT NULL DEFAULT ''");
        db()->exec("CREATE TABLE IF NOT EXISTS `dealer_offers` (
          `company_id` INT UNSIGNED NOT NULL,
          `product_id` INT UNSIGNED NOT NULL,
          `price` DECIMAL(12,2) NULL,
          `gifts` VARCHAR(240) NOT NULL DEFAULT '',
          `qty_prices` VARCHAR(240) NOT NULL DEFAULT '',
          PRIMARY KEY (`company_id`,`product_id`),
          KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS `bayt_log` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `ok` TINYINT(1) NOT NULL DEFAULT 0,
          `message` VARCHAR(400) NOT NULL DEFAULT '',
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function col(string $table, string $col, string $def): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $col)) {
            return;
        }
        $st = db()->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . db()->quote($col));
        if ($st && $st->fetch()) {
            return;
        }
        db()->exec('ALTER TABLE `' . $table . '` ADD `' . $col . '` ' . $def);
    }
}
