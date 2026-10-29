<?php
declare(strict_types=1);

final class Seed
{
    public static function run(): void
    {
        self::settings();
        self::categories();
        self::products();
        self::coffee();
        self::pages();
    }

    public static function ensureCoffee(): void
    {
        self::categories();
        Option::seedCoffee();
        self::coffee();
    }

    private static function settings(): void
    {
        $sets = [
            'site_url' => rtrim((string) ($GLOBALS['bmn_config']['site_url'] ?? 'http://localhost/bmncoomerce'), '/'),
            'site_name' => 'BMN Dükkan',
            'tagline' => 'Trabzonspor taraftar koleksiyonu',
            'seo_title' => 'BMN Dükkan — Forma, eşofman, atkı | BordoMaviNet',
            'seo_description' => 'BMN Dükkan: Trabzonspor taraftar forması, eşofman, atkı ve arma. dukkan.bordomavi.net. Hızlı kargo, 14 gün iade.',
            'org_description' => 'BMN Dükkan, Trabzonsporlular Birliği’nin koleksiyon atölyesidir. Resmî kulüp mağazası değildir.',
            'email' => 'dukkan@bordomavi.net',
            'phone' => '+90 850 346 0997',
            'free_ship_min' => '1500',
            'ship_fee' => '49',
            'iban' => 'TR00 ACCT-000014 0000 00',
            'iban_name' => 'BordoMaviNet',
            'logo' => shop_logo(),
            'og_image' => shop_og(),
        ];
        $ins = db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        foreach ($sets as $k => $v) {
            $ins->execute([$k, $v]);
        }
        $GLOBALS['bmn_settings'] = null;
    }

    private static function cat(string $slug, string $name, string $desc, string $seoT, string $seoD, int $sort, ?int $parent = null): int
    {
        $path = $slug;
        if ($parent) {
            $p = Catalog::categoryById($parent);
            $path = trim(($p['path'] ?? '') . '/' . $slug, '/');
        }
        $st = db()->prepare('SELECT id FROM categories WHERE path=? LIMIT 1');
        $st->execute([$path]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id) {
            db()->prepare('UPDATE categories SET name=?, description=?, seo_title=?, seo_description=?, sort=? WHERE id=?')
                ->execute([$name, $desc, $seoT, $seoD, $sort, $id]);
            return $id;
        }
        db()->prepare('INSERT INTO categories (parent_id,slug,path,name,description,seo_title,seo_description,sort,status) VALUES (?,?,?,?,?,?,?,?,\'publish\')')
            ->execute([$parent, $slug, $path, $name, $desc, $seoT, $seoD, $sort]);
        return (int) db()->lastInsertId();
    }

    private static function categories(): void
    {
        $L = static fn (string $s, string $t): string => '<a href="' . h(site_url('kategori/' . $s . '/')) . '">' . $t . '</a>';
        self::cat('formalar', 'Formalar',
            '<p>BMN forma koleksiyonu: iç saha, deplasman ve kaleci kesimleri. Kumaş, yaka ve arma yerleşimi atölye çizimidir; resmi lisanslı forma değildir. Beden ve kargo için ' . $L('esofman', 'eşofman') . ' ve ' . $L('aksesuar', 'aksesuar') . ' sayfalarına da bak.</p><p>Forma alırken aynı sezon ' . $L('atki-bere', 'atkı ve bere') . ' ile kombinlenir. Stok bitince yeni kesim yayımlanır; eski beden için iletişim formunu kullan.</p>',
            'Trabzonspor Taraftar Forması — BMN Dükkan',
            'İç saha, deplasman ve kaleci formaları. BMN atölye kesimi, hızlı kargo, 14 gün iade.',
            10);
        self::cat('esofman', 'Eşofman',
            '<p>Antrenman ve şehir eşofmanı. Üst-alt ayrı satılır; takım fiyatı ürün sayfasında. ' . $L('formalar', 'Forma') . ' üstüne polar veya hoodie giyilir.</p>',
            'Eşofman Takımı ve Polar — BMN Dükkan',
            'Bordo-mavi eşofman, hoodie ve polar. Taraftar atölyesi, TR kargo.',
            20);
        self::cat('atki-bere', 'Atkı ve bere',
            '<p>Tribün atkısı, bere ve bandana. Kış koleksiyonunun omurgası. Forma ile aynı palet: bordo zemin, mavi şerit, altın arma izi.</p>',
            'Trabzonspor Atkı ve Bere — BMN Dükkan',
            'Tribün atkısı, bere ve bandana. BMN kış koleksiyonu.',
            30);
        self::cat('aksesuar', 'Aksesuar',
            '<p>Çanta, şapka, anahtarlık ve afiş. Küçük arma ürünleri hediye için; büyük parça ' . $L('formalar', 'formalarda') . '.</p>',
            'Taraftar Aksesuarı — BMN Dükkan',
            'Şapka, çanta, anahtarlık ve afiş. BMN arma koleksiyonu.',
            40);
        self::cat('cocuk', 'Çocuk',
            '<p>Çocuk forma ve atkı. Beden 6–14 yaş. Yetişkin kesim ' . $L('formalar', 'formalar') . ' sayfasında.</p>',
            'Çocuk Taraftar Forması — BMN Dükkan',
            'Çocuk forma ve atkı, 6–14 yaş. BMN atölye.',
            50);
        $icecek = self::cat('icecek', 'İçecek',
            '<p>BMN Dükkan içecek rafı. Kahve çekirdeği ve öğütme seçenekleri ' . $L('icecek/kahve', 'Kahve') . ' kategorisinde.</p>',
            'İçecek — BMN Dükkan',
            'Kahve ve içecek. BMN Dükkan.',
            60);
        self::cat('kahve', 'Kahve',
            '<p>Çekirdek ve öğütülmüş kahve. Gramaj, öğütme ve kavurma derecesini ürün sayfasında seçersin.</p>',
            'Kahve — BMN Dükkan',
            'Kahve: 250 / 500 / 1000 gr, öğütme ve kavurma seçimi. BMN Dükkan.',
            10,
            $icecek);
    }

    public static function syncTaxonomy(): void
    {
        self::categories();
    }

    private static function L(string $slug, string $text): string
    {
        return '<a href="' . h(site_url('urun/' . $slug . '/')) . '">' . $text . '</a>';
    }

    private static function C(string $path, string $text): string
    {
        return '<a href="' . h(site_url('kategori/' . $path . '/')) . '">' . $text . '</a>';
    }

    private static function products(): void
    {
        $catMap = [];
        foreach (db()->query('SELECT id, slug FROM categories')->fetchAll() as $c) {
            $catMap[$c['slug']] = (int) $c['id'];
        }
        $sizes = ['S', 'M', 'L', 'XL'];
        $kid = ['6-7', '8-9', '10-12', '13-14'];
        $items = [
            [
                'slug' => 'ic-saha-forma-2627',
                'sku' => 'BMN-F-IC-27',
                'title' => 'İç saha forma 26/27',
                'price' => 1890,
                'compare' => 2190,
                'stock' => 48,
                'feat' => 1,
                'img' => 'products/forma-ic.svg',
                'alt' => 'Bordo iç saha taraftar forması, mavi yaka şeridi',
                'mat' => 'Polyester file',
                'cats' => ['formalar'],
                'sizes' => $sizes,
                'seo_t' => 'İç Saha Forma 26/27 Satın Al — BMN Dükkan',
                'seo_d' => 'BMN iç saha forma 26/27: bordo zemin, mavi yaka, atölye kesimi. Beden S–XL, 14 gün iade, 1500 ₺ üzeri kargo bedava.',
                'ex' => 'Bordo zemin, göğüste altın daire, mavi yaka şeridi. Tribün ve sokak kesimi.',
                'body' => '<p>İç saha forma, BMN atölyesinin omurga parçasıdır. Resmî lisanslı forma değildir; kesim, yaka ve arma izi BordoMaviNet çizimidir. Aynı sezon ' . self::L('deplasman-forma-2627', 'deplasman forma') . ' ve ' . self::L('kaleci-forma-2627', 'kaleci forma') . ' ile palet paylaşır.</p>
<h2>Kumaş ve kesim</h2>
<p>File polyester, nemi dışarı atar. Yaka V; kol ağzında mavi şerit. Göğüste altın daire — BMN markası, kulüp arması değil. Beden S–XL; dar kalıp. Emin değilsen ' . self::C('formalar', 'formalar') . ' sayfasındaki ölçü notuna bak.</p>
<h2>Nasıl kombinlenir?</h2>
<p>Tribünde ' . self::L('tribun-atki', 'tribün atkısı') . ' ve ' . self::L('bordo-bere', 'bordo bere') . '; şehirde ' . self::L('hoodie-bordo', 'hoodie') . '. Çocuk kesimi ' . self::L('cocuk-forma-ic-saha', 'çocuk iç saha') . ' ürününde.</p>
<h2>Kargo ve iade</h2>
<p>' . money(free_ship_min()) . ' üzeri kargo ücretsiz. 14 gün, etiketli ve giyilmemiş iade. Ayrıntı ' . '<a href="' . h(site_url('kargo-iade/')) . '">kargo ve iade</a> sayfasında.</p>',
            ],
            [
                'slug' => 'deplasman-forma-2627',
                'sku' => 'BMN-F-DIS-27',
                'title' => 'Deplasman forma 26/27',
                'price' => 1890,
                'compare' => null,
                'stock' => 36,
                'feat' => 1,
                'img' => 'products/forma-dis.svg',
                'alt' => 'Gök mavisi deplasman taraftar forması',
                'mat' => 'Polyester file',
                'cats' => ['formalar'],
                'sizes' => $sizes,
                'seo_t' => 'Deplasman Forma 26/27 — BMN Dükkan',
                'seo_d' => 'Mavi deplasman forma, bordo şerit. BMN 26/27 atölye kesimi, S–XL, hızlı kargo.',
                'ex' => 'Gök mavisi zemin, bordo omuz şeridi. Deplasman geceleri için.',
                'body' => '<p>Deplasman forma, iç sahanın ters paletidir: mavi zemin, bordo şerit. ' . self::L('ic-saha-forma-2627', 'İç saha forma') . ' ile aynı kumaş ve kalıp; sadece renk döner.</p>
<h2>Neden ayrı kesim?</h2>
<p>Tribün fotoğrafında iki forma yan yana durunca palet okunur. Tek başına da sokak tişörtü gibi giyilir. ' . self::C('aksesuar', 'Şapka ve çanta') . ' ile tamamlanır.</p>',
            ],
            [
                'slug' => 'kaleci-forma-2627',
                'sku' => 'BMN-F-KL-27',
                'title' => 'Kaleci forma 26/27',
                'price' => 1690,
                'compare' => null,
                'stock' => 20,
                'feat' => 0,
                'img' => 'products/forma-kaleci.svg',
                'alt' => 'Altın-bordo kaleci forması',
                'mat' => 'Polyester file',
                'cats' => ['formalar'],
                'sizes' => $sizes,
                'seo_t' => 'Kaleci Forma 26/27 — BMN Dükkan',
                'seo_d' => 'Altın zemin kaleci forma, bordo kol. BMN atölye, sınırlı stok.',
                'ex' => 'Altın zemin, bordo kol. Kaleci gecesi ve koleksiyon.',
                'body' => '<p>Kaleci forma, 26/27 paletinin üçüncü ayağıdır. Altın zemin; bordo kol. ' . self::L('ic-saha-forma-2627', 'İç saha') . ' ve ' . self::L('deplasman-forma-2627', 'deplasman') . ' ile üçlü set olur.</p>',
            ],
            [
                'slug' => 'esofman-ust',
                'sku' => 'BMN-E-UST',
                'title' => 'Eşofman üst',
                'price' => 1290,
                'compare' => null,
                'stock' => 40,
                'feat' => 1,
                'img' => 'products/esofman.svg',
                'alt' => 'Bordo eşofman üst, mavi fermuar şeridi',
                'mat' => 'Pamuk-polyester',
                'cats' => ['esofman'],
                'sizes' => $sizes,
                'seo_t' => 'Bordo Eşofman Üst — BMN Dükkan',
                'seo_d' => 'Fermuarlı eşofman üst, bordo-mavi şerit. Forma üzerine veya tek başına.',
                'ex' => 'Fermuar, yan cep, mavi kol şeridi.',
                'body' => '<p>Eşofman üst, ' . self::L('esofman-alt', 'eşofman alt') . ' ile takım; ' . self::L('ic-saha-forma-2627', 'forma') . ' üzerine polar katmanı. ' . self::C('esofman', 'Eşofman') . ' kategorisinde hoodie de var.</p>',
            ],
            [
                'slug' => 'esofman-alt',
                'sku' => 'BMN-E-ALT',
                'title' => 'Eşofman alt',
                'price' => 990,
                'compare' => null,
                'stock' => 40,
                'feat' => 0,
                'img' => 'products/esofman-alt.svg',
                'alt' => 'Bordo eşofman alt, paça lastiği',
                'mat' => 'Pamuk-polyester',
                'cats' => ['esofman'],
                'sizes' => $sizes,
                'seo_t' => 'Eşofman Alt — BMN Dükkan',
                'seo_d' => 'Paça lastikli bordo eşofman alt. Üst ile takım fiyatı ürünlerde.',
                'ex' => 'Paça lastik, gizli cep, bordo zemin.',
                'body' => '<p>Eşofman alt, ' . self::L('esofman-ust', 'üst') . ' ile aynı kumaş. Antrenman ve yolculuk. ' . self::L('hoodie-bordo', 'Hoodie') . ' ile de giyilir.</p>',
            ],
            [
                'slug' => 'hoodie-bordo',
                'sku' => 'BMN-H-BR',
                'title' => 'Bordo hoodie',
                'price' => 1490,
                'compare' => 1690,
                'stock' => 32,
                'feat' => 1,
                'img' => 'products/hoodie.svg',
                'alt' => 'Bordo kapüşonlu sweatshirt, göğüste BMN',
                'mat' => 'Şardonlu pamuk',
                'cats' => ['esofman'],
                'sizes' => $sizes,
                'seo_t' => 'Bordo Hoodie — BMN Dükkan',
                'seo_d' => 'Şardonlu bordo hoodie, göğüste BMN yazısı. Kış ve tribün.',
                'ex' => 'Kapüşon, kanguru cep, içi şardon.',
                'body' => '<p>Hoodie, forma üzerine giyilen şehir parçasıdır. ' . self::L('bordo-bere', 'Bere') . ' ve ' . self::L('tribun-atki', 'atkı') . ' ile kış seti olur.</p>',
            ],
            [
                'slug' => 'tribun-atki',
                'sku' => 'BMN-A-TR',
                'title' => 'Tribün atkısı',
                'price' => 490,
                'compare' => null,
                'stock' => 80,
                'feat' => 1,
                'img' => 'products/atki.svg',
                'alt' => 'Bordo-mavi çizgili tribün atkısı',
                'mat' => 'Akrilik örgü',
                'cats' => ['atki-bere'],
                'sizes' => ['Tek beden'],
                'seo_t' => 'Trabzonspor Tribün Atkısı — BMN Dükkan',
                'seo_d' => 'Bordo-mavi çizgili tribün atkısı, saçak uç. BMN kış koleksiyonu.',
                'ex' => 'Çift taraflı örgü, saçak, 160 cm.',
                'body' => '<p>Tribün atkısı, kışın en çok satan parçadır. ' . self::L('ic-saha-forma-2627', 'Forma') . ' yakasına, ' . self::L('bordo-bere', 'bere') . ' ile başa. ' . self::C('atki-bere', 'Atkı ve bere') . ' kategorisinde bandana da var.</p>',
            ],
            [
                'slug' => 'bordo-bere',
                'sku' => 'BMN-B-BR',
                'title' => 'Bordo bere',
                'price' => 290,
                'compare' => null,
                'stock' => 64,
                'feat' => 0,
                'img' => 'products/bere.svg',
                'alt' => 'Bordo örgü bere, mavi kat',
                'mat' => 'Akrilik örgü',
                'cats' => ['atki-bere'],
                'sizes' => ['Tek beden'],
                'seo_t' => 'Bordo Taraftar Bere — BMN Dükkan',
                'seo_d' => 'Örgü bordo bere, mavi kat. Atkı ile takım.',
                'ex' => 'Çift kat, katlanır manşet.',
                'body' => '<p>Bere, ' . self::L('tribun-atki', 'atkı') . ' ile aynı ip. Tek başına da hoodie altına girer.</p>',
            ],
            [
                'slug' => 'snapback-mavi',
                'sku' => 'BMN-C-SN',
                'title' => 'Mavi snapback',
                'price' => 390,
                'compare' => null,
                'stock' => 44,
                'feat' => 0,
                'img' => 'products/cap.svg',
                'alt' => 'Gök mavisi snapback şapka, bordo siperlik',
                'mat' => 'Pamuk twill',
                'cats' => ['aksesuar'],
                'sizes' => ['Ayarlı'],
                'seo_t' => 'Mavi Snapback Şapka — BMN Dükkan',
                'seo_d' => 'Mavi snapback, bordo siperlik, önde BMN. Yaz aksesuarı.',
                'ex' => 'Düz siperlik, arkada snap.',
                'body' => '<p>Snapback, yazın ' . self::L('deplasman-forma-2627', 'deplasman forma') . ' ile. ' . self::C('aksesuar', 'Aksesuar') . 'da çanta ve anahtarlık.</p>',
            ],
            [
                'slug' => 'sirt-canta',
                'sku' => 'BMN-G-SR',
                'title' => 'Sırt çantası',
                'price' => 790,
                'compare' => null,
                'stock' => 22,
                'feat' => 1,
                'img' => 'products/canta.svg',
                'alt' => 'Bordo sırt çantası, mavi fermuar',
                'mat' => 'Naylon 600D',
                'cats' => ['aksesuar'],
                'sizes' => ['Tek beden'],
                'seo_t' => 'Bordo Sırt Çantası — BMN Dükkan',
                'seo_d' => 'Günlük sırt çantası, laptop bölmesi, bordo-mavi.',
                'ex' => '18 L, gizli cep, göğüs kayışı.',
                'body' => '<p>Çanta, maç günü ve şehir. Laptop kılıfı 14". ' . self::L('snapback-mavi', 'Şapka') . ' cebine sığar.</p>',
            ],
            [
                'slug' => 'anahtarlik-arma',
                'sku' => 'BMN-K-AR',
                'title' => 'Arma anahtarlık',
                'price' => 90,
                'compare' => null,
                'stock' => 120,
                'feat' => 0,
                'img' => 'products/anahtarlik.svg',
                'alt' => 'Metal BMN arma anahtarlık',
                'mat' => 'Çinko alaşım',
                'cats' => ['aksesuar'],
                'sizes' => ['Tek beden'],
                'seo_t' => 'BMN Arma Anahtarlık — BMN Dükkan',
                'seo_d' => 'Metal arma anahtarlık, emaye dolgu. Hediye ve küçük sipariş.',
                'ex' => 'Emaye, 3 cm, halka.',
                'body' => '<p>Anahtarlık, hediye ve ilk sipariş ekidir. ' . self::L('tribun-atki', 'Atkı') . ' ile paketlenir.</p>',
            ],
            [
                'slug' => 'cocuk-forma-ic-saha',
                'sku' => 'BMN-F-CC-IC',
                'title' => 'Çocuk iç saha forma',
                'price' => 990,
                'compare' => null,
                'stock' => 28,
                'feat' => 1,
                'img' => 'products/cocuk.svg',
                'alt' => 'Çocuk bordo iç saha forması',
                'mat' => 'Polyester file',
                'cats' => ['cocuk', 'formalar'],
                'sizes' => $kid,
                'seo_t' => 'Çocuk İç Saha Forma — BMN Dükkan',
                'seo_d' => 'Çocuk taraftar forması, 6–14 yaş. Yetişkin iç saha ile aynı palet.',
                'ex' => 'Yetişkin iç sahanın çocuk kalıbı.',
                'body' => '<p>Çocuk forma, ' . self::L('ic-saha-forma-2627', 'yetişkin iç saha') . ' ile aynı çizim; kalıp kısa. ' . self::C('cocuk', 'Çocuk') . ' kategorisinde başka beden yoksa iletişimden yaz.</p>',
            ],
        ];

        foreach ($items as $it) {
            self::upsertProduct($it, $catMap);
        }
    }

    private static function upsertProduct(array $it, array $catMap): void
    {
        $media = asset($it['img']);
        $st = db()->prepare('SELECT id FROM products WHERE slug=? LIMIT 1');
        $st->execute([$it['slug']]);
        $id = (int) ($st->fetchColumn() ?: 0);
        $fields = [
            $it['slug'], $it['sku'], $it['title'], $it['ex'], $it['body'],
            $it['price'], $it['compare'], $it['stock'], $it['feat'],
            $media, $it['alt'], 'BMN', $it['mat'], $it['seo_t'], $it['seo_d'],
        ];
        if ($id) {
            db()->prepare('UPDATE products SET slug=?,sku=?,title=?,excerpt=?,content=?,price=?,compare_price=?,stock=?,featured=?,featured_media=?,featured_alt=?,brand=?,material=?,seo_title=?,seo_description=?,status=\'publish\',modified_at=NOW() WHERE id=?')
                ->execute([...$fields, $id]);
        } else {
            db()->prepare('INSERT INTO products (slug,sku,title,excerpt,content,price,compare_price,stock,featured,featured_media,featured_alt,brand,material,seo_title,seo_description,status,published_at,modified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'publish\',NOW(),NOW())')
                ->execute($fields);
            $id = (int) db()->lastInsertId();
        }
        db()->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$id]);
        $ins = db()->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?,?)');
        foreach ($it['cats'] as $slug) {
            if (!empty($catMap[$slug])) {
                $ins->execute([$id, $catMap[$slug]]);
            }
        }
        db()->prepare('DELETE FROM variants WHERE product_id=?')->execute([$id]);
        $v = db()->prepare('INSERT INTO variants (product_id,sku,name,stock,sort) VALUES (?,?,?,?,?)');
        $n = count($it['sizes']);
        $each = (int) max(1, floor($it['stock'] / max(1, $n)));
        foreach ($it['sizes'] as $i => $name) {
            $v->execute([$id, $it['sku'] . '-' . slugify($name), $name, $each, $i]);
        }
    }

    private static function coffee(): void
    {
        $catMap = [];
        foreach (db()->query('SELECT id, slug FROM categories')->fetchAll() as $c) {
            $catMap[$c['slug']] = (int) $c['id'];
        }
        $gids = [];
        $stG = db()->prepare('SELECT id FROM option_groups WHERE slug=? LIMIT 1');
        foreach (['gramaj', 'ogutme', 'kavurma'] as $slug) {
            $stG->execute([$slug]);
            $gid = (int) ($stG->fetchColumn() ?: 0);
            if ($gid < 1) {
                return;
            }
            $gids[] = $gid;
        }
        $how = '<h2>Nasıl seçilir?</h2>
<p>Gramaj, öğütme ve kavurma derecesini ürün sayfasında seç. Çekirdek alırsan evde öğüt; V60, metal filtre, French Press, Aeropress veya Moka Pot için hazır öğütme vardır.</p>
<h2>Kargo</h2>
<p>' . money(free_ship_min()) . ' üzeri kargo ücretsiz. 14 gün iade, etiketli ve açılmamış paket.</p>';
        $bags = [
            [
                'slug' => 'kolombiya-kahve', 'sku' => 'BMN-KHV-51', 'no' => '51', 'title' => 'Kolombiya Kahve',
                'ex' => 'Kolombiya Huila yükseklerinde yetişen Arabica. Kakao, karamel ve fındık; yuvarlak gövde, dengeli asidite.',
                'notes' => 'Kakao, karamel, fındık',
                'place' => 'Huila yüksekleri',
                'feat' => 1,
                'price' => 390, 'p500' => 740, 'p1000' => 1350,
            ],
            [
                'slug' => 'kosta-rika-kahve', 'sku' => 'BMN-KHV-52', 'no' => '52', 'title' => 'Kosta Rika Kahve',
                'ex' => 'Tarrazú volkan yamaçlarından. Bal, narenciye ve şeftali; temiz, berrak filtre fincanı.',
                'notes' => 'Bal, narenciye, şeftali',
                'place' => 'Tarrazú',
                'feat' => 0,
                'price' => 390, 'p500' => 740, 'p1000' => 1350,
            ],
            [
                'slug' => 'kuba-kahve', 'sku' => 'BMN-KHV-53', 'no' => '53', 'title' => 'Küba Kahve',
                'ex' => 'Sierra Maestra eteklerinden. Kakao, baharat ve melas; sıcak, dolgun içim.',
                'notes' => 'Kakao, baharat, melas',
                'place' => 'Sierra Maestra',
                'feat' => 0,
                'price' => 390, 'p500' => 740, 'p1000' => 1350,
            ],
            [
                'slug' => 'etiyopya-kahve', 'sku' => 'BMN-KHV-54', 'no' => '54', 'title' => 'Etiyopya Kahve',
                'ex' => 'Yirgacheffe bahçelerinden. Yasemin, bergamot ve siyah çay; çiçeksi ve canlı.',
                'notes' => 'Yasemin, bergamot, çay',
                'place' => 'Yirgacheffe',
                'feat' => 1,
                'price' => 420, 'p500' => 780, 'p1000' => 1450,
            ],
            [
                'slug' => 'guatemala-kahve', 'sku' => 'BMN-KHV-55', 'no' => '55', 'title' => 'Guatemala Kahve',
                'ex' => 'Antigua vadisi, volkanik toprak. Kakao, elma ve baharat; orta gövde, net bitiş.',
                'notes' => 'Kakao, elma, baharat',
                'place' => 'Antigua',
                'feat' => 0,
                'price' => 390, 'p500' => 740, 'p1000' => 1350,
            ],
            [
                'slug' => 'kenya-kahve', 'sku' => 'BMN-KHV-56', 'no' => '56', 'title' => 'Kenya Kahve',
                'ex' => 'Nyeri yüksekleri. Frenk üzümü, domates ve çiçek; parlak asidite, uzun bitiş.',
                'notes' => 'Frenk üzümü, domates, çiçek',
                'place' => 'Nyeri',
                'feat' => 1,
                'price' => 420, 'p500' => 780, 'p1000' => 1450,
            ],
            [
                'slug' => 'peru-kahve', 'sku' => 'BMN-KHV-57', 'no' => '57', 'title' => 'Peru Kahve',
                'ex' => 'Cajamarca And yamaçları. Karamel, badem ve kakao; yumuşak, günlük filtre.',
                'notes' => 'Karamel, badem, kakao',
                'place' => 'Cajamarca',
                'feat' => 0,
                'price' => 390, 'p500' => 740, 'p1000' => 1350,
            ],
            [
                'slug' => 'ruanda-kahve', 'sku' => 'BMN-KHV-61', 'no' => '61', 'title' => 'Ruanda Kahve',
                'ex' => 'Ruanda’nın yüksek rakımlı bölgelerinde, volkanik topraklarda yetişen seçkin Arabica çekirdekler. Canlı, dengeli ve berrak bir içim deneyimi sunar.',
                'notes' => 'Narenciye, çiçeksi, bal',
                'place' => 'volkanik yüksekler',
                'feat' => 1,
                'price' => 390, 'p500' => 740, 'p1000' => 1350,
            ],
            [
                'slug' => 'santos-kahve', 'sku' => 'BMN-KHV-58', 'no' => '58', 'title' => 'Santos Kahve',
                'ex' => 'Brezilya Minas Gerais, Santos seçimi. Çikolata, fındık ve şeker kamışı; düşük asidite, rahat içim.',
                'notes' => 'Çikolata, fındık, şeker kamışı',
                'place' => 'Minas Gerais',
                'feat' => 0,
                'price' => 360, 'p500' => 690, 'p1000' => 1260,
            ],
            [
                'slug' => 'yemen-kahve', 'sku' => 'BMN-KHV-60', 'no' => '60', 'title' => 'Yemen Kahve',
                'ex' => 'Haraz teraslarından. Kuru meyve, baharat ve kakao; yoğun, eski dünya fincanı.',
                'notes' => 'Kuru meyve, baharat, kakao',
                'place' => 'Haraz',
                'feat' => 1,
                'price' => 450, 'p500' => 860, 'p1000' => 1580,
            ],
        ];
        foreach ($bags as $bag) {
            self::upsertCoffeeBag($bag, $catMap, $gids, $how);
        }
    }

    /** @param array<string,mixed> $bag */
    private static function upsertCoffeeBag(array $bag, array $catMap, array $gids, string $how): void
    {
        $gramajId = $gids[0];
        $ogutmeId = $gids[1];
        $img = 'products/' . $bag['slug'] . '.jpg';
        $media = is_file(BMN_ROOT . '/themes/shop/assets/' . $img) ? asset($img) : asset('og.svg');
        $body = '<p>' . h($bag['title']) . ', BMN Dükkan filtre kahve serisinin ' . h((string) $bag['no']) . '. numarasıdır. Tek köken, özenle seçilmiş Arabica. ' . h((string) $bag['place']) . '.</p>
<p>' . h((string) $bag['ex']) . ' Tadım notları: ' . h((string) $bag['notes']) . '. Kavrum profili orta.</p>
' . $how;
        $it = [
            'slug' => $bag['slug'],
            'sku' => $bag['sku'],
            'title' => $bag['title'],
            'ex' => $bag['ex'],
            'body' => $body,
            'price' => $bag['price'],
            'compare' => null,
            'stock' => 0,
            'feat' => (int) $bag['feat'],
            'alt' => 'BMN Dükkan ' . $bag['title'] . ' etiket: tek köken filtre kahve, No. ' . $bag['no'],
            'mat' => 'Arabica, tek köken',
            'seo_t' => $bag['title'] . ' Filtre Kahve — BMN Dükkan',
            'seo_d' => $bag['title'] . ' tek köken filtre kahve, No. ' . $bag['no'] . '. ' . $bag['notes'] . '. 250 / 500 / 1000 gr, öğütme ve kavurma seçimi.',
        ];
        $st = db()->prepare('SELECT id FROM products WHERE slug=? LIMIT 1');
        $st->execute([$it['slug']]);
        $id = (int) ($st->fetchColumn() ?: 0);
        $fields = [
            $it['slug'], $it['sku'], $it['title'], $it['ex'], $it['body'],
            $it['price'], $it['compare'], $it['stock'], $it['feat'],
            $media, $it['alt'], 'BMN', $it['mat'], $it['seo_t'], $it['seo_d'],
        ];
        if ($id) {
            db()->prepare('UPDATE products SET slug=?,sku=?,title=?,excerpt=?,content=?,price=?,compare_price=?,stock=?,featured=?,featured_media=?,featured_alt=?,brand=?,material=?,seo_title=?,seo_description=?,status=\'publish\',modified_at=NOW() WHERE id=?')
                ->execute([...$fields, $id]);
        } else {
            db()->prepare('INSERT INTO products (slug,sku,title,excerpt,content,price,compare_price,stock,featured,featured_media,featured_alt,brand,material,seo_title,seo_description,status,published_at,modified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'publish\',NOW(),NOW())')
                ->execute($fields);
            $id = (int) db()->lastInsertId();
        }
        db()->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$id]);
        $ins = db()->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?,?)');
        foreach (['kahve', 'icecek'] as $slug) {
            if (!empty($catMap[$slug])) {
                $ins->execute([$id, $catMap[$slug]]);
            }
        }
        Option::setProductGroups($id, $gids);
        $prices = ['250 gr' => (float) $bag['price'], '500 gr' => (float) $bag['p500'], '1000 gr' => (float) $bag['p1000']];
        $stocks = ['250 gr' => 12, '500 gr' => 8, '1000 gr' => 4];
        $roastRank = ['Orta' => 0, 'Açık' => 1, 'Koyu' => 2];
        $rows = [];
        foreach (Option::cartesian($gids) as $combo) {
            $gramaj = '250 gr';
            $gSort = 0;
            $oSort = 0;
            $rSort = 9;
            foreach ($combo as $item) {
                if ((int) $item['group_id'] === $gramajId) {
                    $gramaj = (string) $item['name'];
                    $gSort = (int) $item['sort'];
                }
                if ((int) $item['group_id'] === $ogutmeId) {
                    $oSort = (int) $item['sort'];
                }
                if (isset($roastRank[(string) $item['name']])) {
                    $rSort = $roastRank[(string) $item['name']];
                }
            }
            $slugs = array_map(static fn (array $i): string => slugify((string) $i['name']), $combo);
            $rows[] = [
                'name' => Option::comboName($combo),
                'sku' => $it['sku'] . '-' . implode('-', $slugs),
                'stock' => $stocks[$gramaj] ?? 8,
                'price' => $prices[$gramaj] ?? (float) $bag['price'],
                'attrs' => Option::attrsKey($combo),
                'ord' => $gSort * 1000 + $oSort * 10 + $rSort,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['ord'] <=> $b['ord']);
        db()->prepare('DELETE FROM variants WHERE product_id=?')->execute([$id]);
        $vins = db()->prepare('INSERT INTO variants (product_id,sku,name,price,stock,sort,attrs) VALUES (?,?,?,?,?,?,?)');
        $sum = 0;
        foreach ($rows as $i => $row) {
            $vins->execute([$id, $row['sku'], $row['name'], $row['price'], $row['stock'], $i, $row['attrs']]);
            $sum += $row['stock'];
        }
        db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$sum, $id]);
    }

    private static function pages(): void
    {
        $privacy = '<p>BMN Dükkan, BordoMaviNet çatısı altında sipariş alır. Bu metin 6698 sayılı KVKK kapsamındadır. Resmî Trabzonspor kulübünün mağazası değildir.</p>
<h2>Hangi veriler?</h2>
<p>Sipariş: ad, e-posta, telefon, adres. İletişim formu: ad, e-posta, mesaj. Sepet çerezi: ürün ve adet; oturum açmadan çalışır.</p>
<h2>Amaç</h2>
<p>Siparişi hazırlamak, kargolamak, soruna cevap vermek. Veri satılmaz. Ödeme bilgisi kart olarak tutulmaz; havale/EFT veya kapıda ödeme.</p>
<h2>Hakların</h2>
<p>KVKK md. 11: öğrenme, düzeltme, silme. Talebini iletişim formundan ilet.</p>';
        $terms = '<p>Mağazayı kullanarak bu şartları kabul edersin. Ürünler taraftar atölyesi kesimidir; lisanslı kulüp ürünü iddiası yoktur.</p>
<h2>Sipariş</h2>
<p>Stok, sipariş anındaki kayıttır. Havale 48 saat içinde gelmezse sipariş iptal edilebilir.</p>
<h2>İade</h2>
<p>14 gün, etiketli ve giyilmemiş. Hijyen (bere içi, atkı) için ambalaj açılmamış olmalı.</p>';
        $contact = '<p>BMN Dükkan | BordoMaviNet</p><p>E-posta: dukkan@bordomavi.net<br>WhatsApp: +90 850 346 09 97</p><p>Cevizli Mh. Mustafa Kemal Cd. Hukukçular Towers A Blok, Kartal / İstanbul</p>';
        $ship = '<p>' . money(free_ship_min()) . ' ve üzeri siparişlerde kargo ücretsizdir. Altında ' . money(ship_fee()) . '.</p>
<h2>Süre</h2>
<p>1–2 iş gününde kargoya verilir; teslimat 2–5 iş günü (ilçe ve kargo yoğunluğuna göre).</p>
<h2>İade</h2>
<p>Teslimden itibaren 14 gün. Ürün giyilmemiş, etiketli. İade kargo ücreti BMN’ye aittir. Değişim için iletişim formu.</p>';
        $about = '<p>BMN Dükkan, 1999’dan beri yayında olan BordoMaviNet’in koleksiyon atölyesidir. Forma, eşofman ve arma ürünleri taraftar çizimidir.</p>
<p>Haber ve birlik için <a href="https://bordomavi.net/">bordomavi.net</a>; alışveriş buradadır.</p>';

        $pages = [
            ['privacy', 'Gizlilik', 'Gizlilik — BMN Dükkan', 'Sipariş, çerez ve KVKK. BMN Dükkan veri satmaz.', $privacy],
            ['terms', 'Kullanım şartları', 'Kullanım Şartları — BMN Dükkan', 'Sipariş, stok, iade ve lisans notu.', $terms],
            ['iletisim', 'İletişim', 'İletişim — BMN Dükkan', 'Sipariş ve beden sorusu: form, e-posta, WhatsApp.', $contact],
            ['kargo-iade', 'Kargo ve iade', 'Kargo ve İade — BMN Dükkan', 'Ücretsiz kargo eşiği, teslimat süresi, 14 gün iade.', $ship],
            ['hakkimizda', 'Hakkımızda', 'BMN Dükkan Hakkında', 'BordoMaviNet koleksiyon atölyesi. Taraftar kesimi, lisanslı kulüp mağazası değil.', $about],
        ];
        foreach ($pages as [$slug, $title, $st, $sd, $html]) {
            $ex = db()->prepare('SELECT id FROM pages WHERE slug=?');
            $ex->execute([$slug]);
            $id = (int) ($ex->fetchColumn() ?: 0);
            if ($id) {
                db()->prepare('UPDATE pages SET title=?, content=?, seo_title=?, seo_description=?, modified_at=NOW() WHERE id=?')
                    ->execute([$title, $html, $st, $sd, $id]);
            } else {
                db()->prepare('INSERT INTO pages (slug,title,content,seo_title,seo_description,status,modified_at) VALUES (?,?,?,?,?,\'publish\',NOW())')
                    ->execute([$slug, $title, $html, $st, $sd]);
            }
        }
    }
}
