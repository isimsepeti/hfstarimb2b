<?php
declare(strict_types=1);

final class HfsSeed
{
    public static function run(): void
    {
        $url = rtrim((string) ($GLOBALS['bmn_config']['site_url'] ?? site_url()), '/');
        $sets = [
            'site_url' => $url,
            'site_name' => 'HFS Tarım Bayi',
            'tagline' => 'Bayi satış ve yedek parça sistemi',
            'seo_title' => 'HFS Tarım Bayi — Yedek parça ve sistemler',
            'seo_description' => 'HFS Tarım bayi paneli. Ürün seçici, cari hesap, açık hesap ve kart ile tahsilat.',
            'org_description' => 'HFS Tarım bayi e-ticaret sistemi. Yedek parça diyagramı, bayi fiyatı, cari ödeme.',
            'email' => 'info@hfstarim.com',
            'phone' => '0224 216 16 01',
            'free_ship_min' => '0',
            'ship_fee' => '0',
            'iban' => '',
            'iban_name' => 'HFS Tarım',
            'logo' => shop_logo(),
            'og_image' => shop_og(),
            'media_base' => '/hfs-tarim/wp-content/uploads',
            'shop_mode' => 'hfs',
        ];
        $ins = db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        foreach ($sets as $k => $v) {
            $ins->execute([$k, $v]);
        }
        $GLOBALS['bmn_settings'] = null;
        self::pages();
    }

    public static function pagesOnly(): void
    {
        self::pages();
    }

    private static function pages(): void
    {
        $pages = [
            ['hakkimizda', 'Hakkımızda', '<p>HFS Tarım bayi satış sistemi. Ürünler, parça diyagramları ve cari hesap bu panelden yönetilir.</p>'],
            ['kargo-iade', 'Kargo ve iade', '<p>Bayi siparişleri anlaşmalı kargo ile gönderilir. İade ve RMA için hesabınızdan veya müşteri temsilcinizden ilerleyin.</p>'],
            ['iletisim', 'İletişim', '<p>Sipariş, cari ve teknik destek için formu kullanın. Mesajlar bayi yönetimine düşer.</p>'],
            ['privacy', 'Gizlilik', '<p>Bayi hesabı, sipariş ve tahsilat verileri yalnızca HFS Tarım operasyonu için işlenir.</p>'],
            ['terms', 'Şartlar', '<p>Fiyatlar giriş yapan bayilere özeldir. Açık hesap limiti firma kartınıza bağlıdır.</p>'],
        ];
        $st = db()->prepare('SELECT id FROM pages WHERE slug=?');
        $ins = db()->prepare("INSERT INTO pages (slug,title,content,seo_title,seo_description,status,modified_at) VALUES (?,?,?,?,?, 'publish', NOW())");
        foreach ($pages as [$slug, $title, $html]) {
            $st->execute([$slug]);
            if ($st->fetch()) {
                continue;
            }
            $ins->execute([$slug, $title, $html, $title . ' — HFS Tarım Bayi', excerpt_plain($html, 150)]);
        }
    }
}
