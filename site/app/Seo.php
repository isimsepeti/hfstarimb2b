<?php
declare(strict_types=1);

final class Seo
{
    public static function origin(): string
    {
        return rtrim(site_url(), '/');
    }

    public static function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::origin() . '/' . ltrim($path, '/');
    }

    public static function head(array $meta): string
    {
        $site = (string) setting('site_name', 'BMN Dükkan');
        $tagline = (string) setting('tagline', 'Trabzonspor taraftar koleksiyonu');
        $title = (string) ($meta['title'] ?? $site);
        $desc = (string) ($meta['description'] ?? setting('seo_description', $tagline));
        $canonical = (string) ($meta['canonical'] ?? site_url());
        $robots = (string) ($meta['robots'] ?? 'index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1');
        $type = (string) ($meta['og_type'] ?? 'website');
        $image = (string) ($meta['image'] ?? setting('og_image', shop_og()));
        $published = $meta['published'] ?? null;
        $modified = $meta['modified'] ?? null;
        $prev = $meta['prev'] ?? null;
        $next = $meta['next'] ?? null;
        $noindex = !empty($meta['noindex']);
        if ($noindex) {
            $robots = 'noindex,follow';
        }

        $out = [];
        $out[] = '<title>' . h($title) . '</title>';
        $out[] = '<meta name="description" content="' . h($desc) . '">';
        $out[] = '<meta name="robots" content="' . h($robots) . '">';
        $out[] = '<meta name="googlebot" content="' . h($robots) . '">';
        $out[] = '<link rel="canonical" href="' . h($canonical) . '">';
        if ($prev) {
            $out[] = '<link rel="prev" href="' . h($prev) . '">';
        }
        if ($next) {
            $out[] = '<link rel="next" href="' . h($next) . '">';
        }
        $out[] = '<link rel="alternate" type="application/rss+xml" title="' . h($site) . ' RSS" href="' . h(site_url('feed/')) . '">';
        $out[] = '<meta property="og:locale" content="tr_TR">';
        $out[] = '<meta property="og:type" content="' . h($type) . '">';
        $out[] = '<meta property="og:title" content="' . h($meta['og_title'] ?? $title) . '">';
        $out[] = '<meta property="og:description" content="' . h($desc) . '">';
        $out[] = '<meta property="og:url" content="' . h($canonical) . '">';
        $out[] = '<meta property="og:site_name" content="' . h($site) . '">';
        $out[] = '<meta property="og:image" content="' . h(self::absolute($image)) . '">';
        $out[] = '<meta property="og:image:alt" content="' . h($meta['image_alt'] ?? $title) . '">';
        $out[] = '<meta property="og:image:width" content="1200">';
        $out[] = '<meta property="og:image:height" content="675">';
        if ($published) {
            $out[] = '<meta property="article:published_time" content="' . h(date('c', strtotime((string) $published))) . '">';
        }
        if ($modified) {
            $out[] = '<meta property="article:modified_time" content="' . h(date('c', strtotime((string) $modified))) . '">';
            $out[] = '<meta property="og:updated_time" content="' . h(date('c', strtotime((string) $modified))) . '">';
        }
        $out[] = '<meta name="twitter:card" content="summary_large_image">';
        $out[] = '<meta name="twitter:title" content="' . h($title) . '">';
        $out[] = '<meta name="twitter:description" content="' . h($desc) . '">';
        $out[] = '<meta name="twitter:image" content="' . h(self::absolute($image)) . '">';
        $gsc = trim((string) setting('gsc_verification', ''));
        if ($gsc !== '') {
            $out[] = '<meta name="google-site-verification" content="' . h($gsc) . '">';
        }
        $out[] = '<meta name="theme-color" content="#640020">';
        $out[] = '<link rel="icon" href="' . h(shop_logo()) . '" type="image/png">';
        $out[] = '<link rel="apple-touch-icon" href="' . h(shop_logo()) . '">';
        $out[] = '<link rel="manifest" href="' . h(site_url('manifest.webmanifest')) . '">';
        if (!empty($meta['jsonld'])) {
            $out[] = '<script type="application/ld+json">' . json_encode($meta['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }
        return implode("\n    ", $out);
    }

    public static function graph(array $extraNodes = []): array
    {
        $origin = self::origin();
        $name = (string) setting('site_name', 'BMN Dükkan');
        $desc = (string) setting('org_description', 'BMN Dükkan, Trabzonspor taraftar birliğinin resmi olmayan koleksiyon atölyesidir. Forma, eşofman, atkı ve arma ürünleri.');
        $logo = self::absolute((string) setting('logo', shop_logo()));
        $phone = (string) setting('phone', '+90 850 346 0997');
        $email = (string) setting('email', 'dukkan@bordomavi.net');
        $org = [
            '@type' => ['Organization', 'OnlineStore'],
            '@id' => $origin . '/#organization',
            'name' => $name,
            'legalName' => 'BordoMaviNet',
            'url' => $origin . '/',
            'logo' => [
                '@type' => 'ImageObject',
                '@id' => $origin . '/#logo',
                'url' => $logo,
                'contentUrl' => $logo,
                'width' => 512,
                'height' => 512,
                'caption' => $name,
            ],
            'image' => ['@id' => $origin . '/#logo'],
            'description' => $desc,
            'foundingDate' => '1999-01-01',
            'sameAs' => [
                'https://dukkan.bordomavi.net/',
                'https://bordomavi.net/',
                'https://www.instagram.com/bordomavinet/',
                'https://x.com/bordomavinet',
            ],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Cevizli Mh. Mustafa Kemal Cd. Hukukçular Towers',
                'addressLocality' => 'İstanbul',
                'postalCode' => '34846',
                'addressCountry' => 'TR',
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => $phone,
                'email' => $email,
                'contactType' => 'customer support',
                'availableLanguage' => ['Turkish'],
            ],
        ];
        $web = [
            '@type' => 'WebSite',
            '@id' => $origin . '/#website',
            'url' => $origin . '/',
            'name' => $name,
            'alternateName' => (string) setting('tagline', 'Trabzonspor taraftar koleksiyonu'),
            'description' => (string) setting('tagline', 'Trabzonspor taraftar koleksiyonu'),
            'publisher' => ['@id' => $origin . '/#organization'],
            'inLanguage' => 'tr-TR',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => rtrim($origin, '/') . '/?s={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        return [
            '@context' => 'https://schema.org',
            '@graph' => array_merge([$org, $web], $extraNodes),
        ];
    }

    public static function breadcrumbs(array $items): array
    {
        $origin = self::origin();
        $list = [];
        $i = 1;
        foreach ($items as $idx => $item) {
            $node = ['@type' => 'ListItem', 'position' => $i, 'name' => $item['name']];
            $last = $idx === count($items) - 1;
            if (!$last && !empty($item['url'])) {
                $node['item'] = $item['url'];
            }
            $list[] = $node;
            $i++;
        }
        return [
            '@type' => 'BreadcrumbList',
            '@id' => ($items[count($items) - 1]['url'] ?? $origin) . '#breadcrumb',
            'itemListElement' => $list,
        ];
    }

    public static function productNode(array $p, array $crumbs, array $variants = [], array $reviewExtra = []): array
    {
        $url = permalink_product($p);
        $img = self::absolute(hfs_media((string) ($p['featured_media'] ?? '')));
        $avail = (int) $p['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        foreach ($variants as $v) {
            if ((int) $v['stock'] > 0) {
                $avail = 'https://schema.org/InStock';
                break;
            }
        }
        $offer = [
            '@type' => 'Offer',
            '@id' => $url . '#offer',
            'url' => $url,
            'priceCurrency' => 'TRY',
            'price' => number_format((float) $p['price'], 2, '.', ''),
            'availability' => $avail,
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@id' => self::origin() . '/#organization'],
            'shippingDetails' => [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => [
                    '@type' => 'MonetaryAmount',
                    'value' => number_format(ship_fee(), 2, '.', ''),
                    'currency' => 'TRY',
                ],
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => 'TR',
                ],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 2, 'unitCode' => 'DAY'],
                    'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 2, 'maxValue' => 5, 'unitCode' => 'DAY'],
                ],
            ],
            'hasMerchantReturnPolicy' => [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => 'TR',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => 14,
                'returnMethod' => 'https://schema.org/ReturnByMail',
                'returnFees' => 'https://schema.org/FreeReturn',
            ],
        ];
        $node = [
            '@type' => 'Product',
            '@id' => $url . '#product',
            'name' => $p['title'],
            'description' => $p['seo_description'] ?: excerpt_plain($p['excerpt'] ?: $p['content'], 180),
            'sku' => $p['sku'] ?: ('BMN-' . $p['id']),
            'brand' => [
                '@type' => 'Brand',
                'name' => $p['brand'] ?: (string) setting('site_name', 'BMN Dükkan'),
            ],
            'image' => $img,
            'url' => $url,
            'offers' => $offer,
        ];
        if (!empty($p['material'])) {
            $node['material'] = $p['material'];
        }
        if ($reviewExtra) {
            $node = array_merge($node, $reviewExtra);
        }
        $faq = self::productFaq($p);
        return self::graph([$node, $crumbs, $faq]);
    }

    public static function productFaq(array $p): array
    {
        $url = permalink_product($p);
        $q = [
            ['Kargo ne kadar sürer?', 'Siparişler 1–2 iş gününde kargoya verilir; Türkiye içi teslimat genelde 2–5 iş günüdür. ' . money(free_ship_min()) . ' ve üzeri alışverişte kargo ücretsizdir.'],
            ['İade var mı?', 'Kullanılmamış, etiketli ürünlerde 14 gün içinde ücretsiz iade. Ayrıntı kargo ve iade sayfasındadır.'],
            ['Beden seçimi nasıl?', 'Ürün sayfasındaki beden seçeneklerinden sipariş verilir. Emin değilsen iletişim formundan yaz; ölçü tablosunu iletiriz.'],
        ];
        $ents = [];
        foreach ($q as $row) {
            $ents[] = [
                '@type' => 'Question',
                'name' => $row[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $row[1]],
            ];
        }
        return [
            '@type' => 'FAQPage',
            '@id' => $url . '#faq',
            'mainEntity' => $ents,
        ];
    }

    public static function itemList(array $items, string $canonical, string $name): array
    {
        $list = [];
        $n = 1;
        foreach ($items as $p) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $n,
                'url' => permalink_product($p),
                'name' => $p['title'],
            ];
            $n++;
        }
        return [
            '@type' => 'ItemList',
            '@id' => $canonical . '#list',
            'name' => $name,
            'numberOfItems' => count($list),
            'itemListElement' => $list,
        ];
    }

    public static function xmlSitemapIndex(): string
    {
        $origin = self::origin();
        $last = date('c');
        try {
            $last = (string) (db()->query("SELECT MAX(modified_at) m FROM products WHERE status='publish'")->fetch()['m'] ?? $last);
        } catch (Throwable) {
        }
        $sitemaps = [
            ['product-sitemap.xml', $last],
            ['category-sitemap.xml', $last],
            ['page-sitemap.xml', $last],
            ['blog-sitemap.xml', $last],
        ];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($sitemaps as [$loc, $mod]) {
            $xml .= '  <sitemap><loc>' . h($origin . '/' . $loc) . '</loc><lastmod>' . date('c', strtotime((string) $mod)) . "</lastmod></sitemap>\n";
        }
        $xml .= '</sitemapindex>';
        return $xml;
    }

    public static function xmlUrlset(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n    <loc>" . h($u['loc']) . "</loc>\n";
            if (!empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . date('c', strtotime((string) $u['lastmod'])) . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . h($u['changefreq'] ?? 'weekly') . "</changefreq>\n";
            $xml .= '    <priority>' . h($u['priority'] ?? '0.6') . "</priority>\n";
            if (!empty($u['image'])) {
                $xml .= '    <image:image><image:loc>' . h(self::absolute($u['image'])) . '</image:loc>';
                if (!empty($u['image_title'])) {
                    $xml .= '<image:title>' . h($u['image_title']) . '</image:title>';
                }
                $xml .= "</image:image>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        return $xml;
    }

    public static function rss(array $products): string
    {
        $site = h((string) setting('site_name', 'BMN Dükkan'));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n";
        $xml .= '<title>' . $site . '</title><link>' . h(site_url()) . '</link>';
        $xml .= '<description>' . h((string) setting('tagline', 'Trabzonspor taraftar koleksiyonu')) . '</description>';
        $xml .= '<language>tr-TR</language><lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>';
        $xml .= '<atom:link href="' . h(site_url('feed/')) . '" rel="self" type="application/rss+xml"/>' . "\n";
        foreach ($products as $p) {
            $xml .= '<item><title>' . h($p['title']) . '</title><link>' . h(permalink_product($p)) . '</link>';
            $xml .= '<guid isPermaLink="true">' . h(permalink_product($p)) . '</guid>';
            $xml .= '<pubDate>' . date(DATE_RSS, strtotime((string) ($p['published_at'] ?: 'now'))) . '</pubDate>';
            $xml .= '<description><![CDATA[' . excerpt_plain($p['excerpt'] ?: $p['content'], 280) . ']]></description></item>' . "\n";
        }
        try {
            foreach (Catalog::posts(8)['items'] as $post) {
                $xml .= '<item><title>' . h($post['title']) . '</title><link>' . h(permalink_post($post)) . '</link>';
                $xml .= '<guid isPermaLink="true">' . h(permalink_post($post)) . '</guid>';
                $xml .= '<pubDate>' . date(DATE_RSS, strtotime((string) ($post['published_at'] ?: 'now'))) . '</pubDate>';
                $xml .= '<description><![CDATA[' . excerpt_plain($post['excerpt'] ?: $post['content'], 280) . ']]></description></item>' . "\n";
            }
        } catch (Throwable) {
        }
        $xml .= "</channel></rss>";
        return $xml;
    }
}
