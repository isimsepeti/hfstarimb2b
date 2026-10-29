SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(80) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(80) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NULL,
  `slug` VARCHAR(160) NOT NULL,
  `path` VARCHAR(320) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `description` MEDIUMTEXT NULL,
  `seo_title` VARCHAR(190) NULL,
  `seo_description` VARCHAR(320) NULL,
  `image` VARCHAR(500) NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'publish',
  PRIMARY KEY (`id`),
  UNIQUE KEY `path` (`path`),
  KEY `parent_id` (`parent_id`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(190) NOT NULL,
  `sku` VARCHAR(64) NOT NULL DEFAULT '',
  `title` VARCHAR(220) NOT NULL,
  `excerpt` TEXT NULL,
  `content` MEDIUMTEXT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `compare_price` DECIMAL(10,2) NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'publish',
  `featured` TINYINT(1) NOT NULL DEFAULT 0,
  `featured_media` VARCHAR(500) NULL,
  `featured_alt` VARCHAR(220) NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'simple',
  `personalize` TINYINT(1) NOT NULL DEFAULT 0,
  `upsell_id` INT UNSIGNED NULL,
  `brand` VARCHAR(120) NULL,
  `material` VARCHAR(190) NULL,
  `seo_title` VARCHAR(190) NULL,
  `seo_description` VARCHAR(320) NULL,
  `seo_robots` VARCHAR(80) NULL,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `published_at` DATETIME NULL,
  `modified_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `status_feat` (`status`,`featured`,`published_at`),
  KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `product_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`product_id`,`category_id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `variants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `sku` VARCHAR(160) NOT NULL DEFAULT '',
  `name` VARCHAR(190) NOT NULL,
  `price` DECIMAL(10,2) NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `sort` INT NOT NULL DEFAULT 0,
  `attrs` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `option_groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `option_values` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT UNSIGNED NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_slug` (`group_id`,`slug`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_option_groups` (
  `product_id` INT UNSIGNED NOT NULL,
  `group_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`product_id`,`group_id`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(160) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `content` MEDIUMTEXT NULL,
  `seo_title` VARCHAR(190) NULL,
  `seo_description` VARCHAR(320) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'publish',
  `modified_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(24) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'yeni',
  `name` VARCHAR(160) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(40) NOT NULL,
  `city` VARCHAR(80) NOT NULL,
  `district` VARCHAR(80) NOT NULL DEFAULT '',
  `address` TEXT NOT NULL,
  `note` TEXT NULL,
  `country` VARCHAR(2) NOT NULL DEFAULT 'TR',
  `coupon_code` VARCHAR(40) NULL,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `channel` VARCHAR(40) NOT NULL DEFAULT 'web',
  `tracking` VARCHAR(80) NULL,
  `ship_slot` VARCHAR(40) NULL,
  `payment` VARCHAR(40) NOT NULL DEFAULT 'havale',
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `shipping` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NULL,
  `title` VARCHAR(220) NOT NULL,
  `sku` VARCHAR(80) NOT NULL DEFAULT '',
  `qty` INT NOT NULL DEFAULT 1,
  `price` DECIMAL(10,2) NOT NULL,
  `note` VARCHAR(240) NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(320) NOT NULL,
  `target` VARCHAR(500) NOT NULL,
  `code` SMALLINT NOT NULL DEFAULT 301,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(190) NULL,
  `message` TEXT NOT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupons` (
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_links` (
  `product_id` INT UNSIGNED NOT NULL,
  `target_id` INT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'cross',
  PRIMARY KEY (`product_id`,`target_id`,`kind`),
  KEY `target_id` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bundle_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `child_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NULL,
  `qty` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `abandoned_carts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(64) NOT NULL,
  `email` VARCHAR(190) NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `reminders` TINYINT NOT NULL DEFAULT 0,
  `recovered` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `email` (`email`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reviews` (
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
  PRIMARY KEY (`id`),
  KEY `product_status` (`product_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `review_invites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `posts` (
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_alerts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_email` (`product_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `returns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(240) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'yeni',
  `note` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `err` VARCHAR(400) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
