<?php
declare(strict_types=1);

final class Front
{
    public static function dispatch(): void
    {
        try {
            self::dispatchInner();
        } catch (Throwable $e) {
            self::failPage($e);
        }
    }

    private static function failPage(Throwable $e): void
    {
        error_log('HFS shop: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code(200);
        }
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Hata</title><p>Sayfa yüklenemedi.</p><pre>'
            . h($e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine())
            . '</pre>';
    }

    private static function dispatchInner(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        self::canonicalizeRequest($method);

        $path = request_path();

        if (defined('HFS_SHOP') && HFS_SHOP) {
            HfsAuthPassword::enforceMustChange($path);
        }

        if (preg_match('#^/yonetim(/.*)?$#', $path)) {
            Admin::dispatch($path, $method);
            return;
        }

        if (Hfs::dispatch($method, $path)) {
            return;
        }

        $redir = self::redirects($path);
        if ($redir) {
            redirect(site_url(ltrim((string) $redir['target'], '/')), (int) $redir['code']);
        }

        if (isset($_GET['s']) && $path === '/') {
            self::search((string) $_GET['s']);
            return;
        }

        $routes = [
            '/' => fn () => self::home(),
            '/robots.txt/' => fn () => self::robots(),
            '/sitemap.xml/' => fn () => self::sitemapIndex(),
            '/sitemap_index.xml/' => fn () => self::sitemapIndex(),
            '/product-sitemap.xml/' => fn () => self::sitemapProducts(),
            '/category-sitemap.xml/' => fn () => self::sitemapCats(),
            '/page-sitemap.xml/' => fn () => self::sitemapPages(),
            '/feed/' => fn () => self::feed(),
            '/manifest.webmanifest/' => fn () => self::manifest(),
            '/llms.txt/' => fn () => self::llms(),
            '/blog-sitemap.xml/' => fn () => self::sitemapPosts(),
            '/sepet/' => fn () => $method === 'POST' ? self::cartPost() : self::cart(),
            '/odeme/' => fn () => $method === 'POST' ? self::checkoutPost() : self::checkout(),
            '/cron/' => fn () => self::cron(),
            '/iade/' => fn () => $method === 'POST' ? self::returnPost() : self::returnForm(),
        ];
        if (isset($routes[$path])) {
            $routes[$path]();
            return;
        }

        if (preg_match('#^/siparis/([A-Za-z0-9-]+)/$#', $path, $m)) {
            self::thanks($m[1]);
            return;
        }
        if (preg_match('#^/yorum/([a-f0-9]{32})/$#', $path, $m)) {
            $method === 'POST' ? self::reviewPost($m[1]) : self::reviewForm($m[1]);
            return;
        }

        if ($method === 'POST' && $path === '/iletisim/') {
            self::contactPost();
            return;
        }
        if ($method === 'POST' && $path === '/stok-haber/') {
            self::stockAlertPost();
            return;
        }

        $page = 1;
        $bare = trim($path, '/');
        if (preg_match('#^(.*)/page/(\d+)$#', $bare, $m)) {
            $bare = $m[1];
            $page = (int) $m[2];
        }

        if ($bare === 'yazi') {
            self::blog($page);
            return;
        }
        if (preg_match('#^yazi/([^/]+)$#', $bare, $m)) {
            self::blogPost($m[1]);
            return;
        }
        if ($bare === 'diyagramlar') {
            self::diagrams($page);
            return;
        }
        if (preg_match('#^kategori/(.+)$#', $bare, $m)) {
            self::category($m[1], $page);
            return;
        }
        if (preg_match('#^urun/([^/]+)$#', $bare, $m)) {
            $p = Catalog::productBySlug($m[1]);
            if ($p) {
                self::product($p);
                return;
            }
            self::notFound();
            return;
        }
        if (preg_match('#^arama/?$#', $bare)) {
            self::search((string) ($_GET['q'] ?? $_GET['s'] ?? ''));
            return;
        }
        if ($bare !== '' && !str_contains($bare, '/')) {
            $pg = Catalog::pageBySlug($bare);
            if ($pg) {
                self::page($pg);
                return;
            }
        }

        self::notFound();
    }

    private static function canonicalizeRequest(string $method): void
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        if ($path === '' || str_contains($path, '..')) {
            return;
        }
        $rel = $path;
        $base = base_path();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $rel = substr($path, strlen($base)) ?: '/';
        }
        if (preg_match('#^/yonetim(/|$)#', $rel)) {
            return;
        }
        $next = $path;
        if (preg_match('/[A-Z]/', $next)) {
            $next = strtolower($next);
        }
        if (str_contains($next, '//')) {
            $next = (string) preg_replace('#/{2,}#', '/', $next);
        }
        $isFile = (bool) preg_match('#\.[a-z0-9]{1,16}$#i', $next);
        if (!$isFile && $next !== '/' && !str_ends_with($next, '/')) {
            $next .= '/';
        }
        if ($next === $path) {
            return;
        }
        $qs = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        redirect($next . ($qs !== '' ? '?' . $qs : ''), 301);
    }

    private static function redirects(string $path): ?array
    {
        $src = trim($path, '/');
        if ($src === '') {
            return null;
        }
        try {
            $st = db()->prepare('SELECT source, target, code FROM redirects WHERE source IN (?, ?) LIMIT 1');
            $st->execute(['/' . $src . '/', $src]);
            $row = $st->fetch();
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function view(string $view, array $data = [], array $http = []): void
    {
        self::render($view, $data, $http);
    }

    private static function render(string $view, array $data = [], array $http = []): void
    {
        try {
            self::renderInner($view, $data, $http);
        } catch (Throwable $e) {
            self::failPage($e);
        }
    }

    private static function renderInner(string $view, array $data = [], array $http = []): void
    {
        if (!empty($http['status'])) {
            http_response_code((int) $http['status']);
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!empty($http['no_cache']) || current_user()) {
            header('Cache-Control: private, no-store, max-age=0');
        } elseif (session_status() !== PHP_SESSION_ACTIVE && in_array($method, ['GET', 'HEAD'], true)) {
            header('Cache-Control: public, max-age=120, s-maxage=300');
        }
        $canonical = $data['seo']['canonical'] ?? '';
        if (is_string($canonical) && $canonical !== '' && empty($data['seo']['noindex'])) {
            header('Link: <' . $canonical . '>; rel="canonical"', false);
        }
        $bare = !empty($data['bareLayout']) || $view === 'login';
        if ($bare) {
            $data['miniCart'] = ['lines' => [], 'count' => 0, 'total' => 0];
            $data['cartCount'] = 0;
            $data['navCats'] = [];
            $data['navMenu'] = [];
        } else {
            try {
                if (!isset($data['miniCart']) || !is_array($data['miniCart'])) {
                    $data['miniCart'] = Cart::hydrated();
                }
                $data['cartCount'] = (int) ($data['miniCart']['count'] ?? 0);
                $data['navCats'] = Catalog::categories();
                $data['navMenu'] = HfsNav::items((string) ($data['pathNow'] ?? request_path()));
            } catch (Throwable $e) {
                error_log('HFS shop nav: ' . $e->getMessage());
                $data['miniCart'] = ['lines' => [], 'count' => 0, 'total' => 0];
                $data['cartCount'] = 0;
                $data['navCats'] = [];
                $data['navMenu'] = [];
            }
        }
        extract($data, EXTR_SKIP);
        $theme = BMN_ROOT . '/themes/shop';
        $viewFile = $theme . '/' . $view . '.php';
        $layoutFile = $theme . '/layout.php';
        if (!is_file($viewFile) || !is_file($layoutFile)) {
            throw new RuntimeException('Tema dosyası yok: ' . $view);
        }
        header('Content-Language: tr');
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require $layoutFile;
    }

    private static function home(): void
    {
        $origin = Seo::origin();
        $title = (string) setting('seo_title', 'HFS Tarım Bayi — Yedek parça ve sistemler');
        $desc = (string) setting('seo_description', 'HFS Tarım bayi paneli. Ürün seçici, cari hesap, açık hesap ve kart ile tahsilat.');
        $canonical = site_url();
        $jsonld = Seo::graph([
            [
                '@type' => 'CollectionPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $title,
                'isPartOf' => ['@id' => $origin . '/#website'],
                'about' => ['@id' => $origin . '/#organization'],
                'inLanguage' => 'tr-TR',
            ],
        ]);
        self::render('home', [
            'seo' => [
                'title' => $title,
                'description' => $desc,
                'canonical' => $canonical,
                'image' => shop_og(),
                'jsonld' => $jsonld,
            ],
        ]);
    }

    private static function diagrams(int $page = 1): void
    {
        $items = HfsDiagram::hosts(48);
        $canonical = site_url('diyagramlar/');
        $crumbs = [
            ['name' => 'Dükkan', 'url' => site_url()],
            ['name' => 'Parça diyagramları', 'url' => $canonical],
        ];
        self::render('diagrams', [
            'items' => $items,
            'crumbs' => $crumbs,
            'seo' => [
                'title' => 'Parça diyagramları — HFS Tarım Bayi',
                'description' => 'Sistem montaj görsellerinden yedek parça seçin.',
                'canonical' => $canonical,
                'jsonld' => Seo::graph([
                    [
                        '@type' => 'CollectionPage',
                        '@id' => $canonical . '#webpage',
                        'url' => $canonical,
                        'name' => 'Parça diyagramları',
                        'inLanguage' => 'tr-TR',
                    ],
                    Seo::breadcrumbs($crumbs),
                ]),
            ],
        ]);
    }

    private static function product(array $p): void
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!preg_match('/Googlebot|bingbot|YandexBot|DuckDuckBot|facebookexternalhit|Twitterbot/i', $ua)) {
            Catalog::bumpViews((int) $p['id']);
            $p['view_count'] = (int) ($p['view_count'] ?? 0) + 1;
        }
        $p = HfsPricing::decorate($p);
        $variants = Catalog::variants((int) $p['id']);
        foreach ($variants as &$vv) {
            if ($vv['price'] !== null && $vv['price'] !== '') {
                $vv['list_price'] = (float) $vv['price'];
                $vv['price'] = HfsPricing::apply((float) $vv['price']);
            }
        }
        unset($vv);
        $diagram = HfsDiagram::forProduct((int) $p['id']);
        $cats = Catalog::catsForProduct((int) $p['id']);
        $optAxes = Option::axesForProduct((int) $p['id']);
        $related = Catalog::related($p, 8);
        $cross = Catalog::links((int) $p['id'], 'cross');
        $upsell = !empty($p['upsell_id']) ? Catalog::productById((int) $p['upsell_id']) : null;
        if (!$upsell) {
            $up = Catalog::links((int) $p['id'], 'up');
            $upsell = $up[0] ?? null;
        }
        $bundle = ($p['type'] ?? '') === 'bundle' ? Catalog::bundleItems((int) $p['id']) : [];
        $reviews = Review::published((int) $p['id']);
        $revStats = Review::stats((int) $p['id']);
        $reviewExtra = Review::schemaNode($p, $revStats, $reviews) ?? [];
        $canonical = permalink_product($p);
        $crumbs = [
            ['name' => 'Dükkan', 'url' => site_url()],
        ];
        $leaf = null;
        $len = -1;
        foreach ($cats as $c) {
            $n = strlen((string) ($c['path'] ?? ''));
            if ($n > $len) {
                $len = $n;
                $leaf = $c;
            }
        }
        $chain = [];
        $cur = $leaf;
        $guard = 0;
        while ($cur && $guard++ < 8) {
            array_unshift($chain, $cur);
            $cur = !empty($cur['parent_id']) ? Catalog::categoryById((int) $cur['parent_id']) : null;
        }
        foreach ($chain as $c) {
            $crumbs[] = ['name' => $c['name'], 'url' => permalink_category($c)];
        }
        $crumbs[] = ['name' => $p['title'], 'url' => $canonical];
        $title = $p['seo_title'] ?: ($p['title'] . ' — ' . (string) setting('site_name', 'HFS Tarım Bayi'));
        $desc = $p['seo_description'] ?: excerpt_plain($p['excerpt'] ?: $p['content'], 160);
        self::render('product', [
            'p' => $p,
            'cats' => $cats,
            'variants' => $variants,
            'optAxes' => $optAxes,
            'related' => $related,
            'cross' => $cross,
            'upsell' => $upsell,
            'bundle' => $bundle,
            'reviews' => $reviews,
            'revStats' => $revStats,
            'crumbs' => $crumbs,
            'diagram' => $diagram,
            'seo' => [
                'title' => $title,
                'description' => $desc,
                'canonical' => $canonical,
                'robots' => $p['seo_robots'],
                'og_type' => 'product',
                'image' => hfs_media($p['featured_media'] ?? ''),
                'image_alt' => $p['featured_alt'] ?: $p['title'],
                'published' => $p['published_at'],
                'modified' => $p['modified_at'],
                'jsonld' => Seo::productNode($p, Seo::breadcrumbs($crumbs), $variants, $reviewExtra),
            ],
        ]);
    }

    private static function category(string $path, int $page = 1): void
    {
        $term = Catalog::categoryByPath($path);
        if (!$term) {
            self::notFound();
            return;
        }
        $children = Catalog::categories((int) $term['id']);
        $montajAll = HfsMontaj::featured($term);
        $exclude = [];
        foreach ($montajAll['items'] ?? [] as $mp) {
            $exclude[] = (int) $mp['id'];
        }
        $data = Catalog::products(['category_id' => (int) $term['id'], 'per' => 24, 'page' => $page, 'exclude' => $exclude, 'parts_only' => true]);
        $base = permalink_category($term);
        $canonical = $page > 1 ? pager_url($base, $page) : $base;
        $title = $term['seo_title'] ?: ($term['name'] . ' — ' . (string) setting('site_name', 'HFS Tarım Bayi'));
        $chain = [];
        $cur = $term;
        $guard = 0;
        while ($cur && $guard++ < 8) {
            array_unshift($chain, $cur);
            $cur = !empty($cur['parent_id']) ? Catalog::categoryById((int) $cur['parent_id']) : null;
        }
        $crumbs = [['name' => 'Ana Sayfa', 'url' => site_url()]];
        foreach ($chain as $c) {
            $crumbs[] = ['name' => $c['name'], 'url' => permalink_category($c)];
        }
        $parent = !empty($term['parent_id']) ? Catalog::categoryById((int) $term['parent_id']) : null;
        self::render('category', [
            'term' => $term,
            'parent' => $parent,
            'children' => $children,
            'items' => $data['items'],
            'pager' => $data['pager'],
            'crumbs' => $crumbs,
            'montaj' => $page > 1 ? [] : $montajAll,
            'seo' => [
                'title' => $page > 1 ? $title . ' — Sayfa ' . $page : $title,
                'description' => $term['seo_description'] ?: excerpt_plain($term['description'], 160) ?: ($term['name'] . ' — HFS Tarım Bayi'),
                'canonical' => $canonical,
                'prev' => $page > 1 ? ($page === 2 ? $base : pager_url($base, $page - 1)) : null,
                'next' => $data['pager']['page'] < $data['pager']['pages'] ? pager_url($base, $page + 1) : null,
                'jsonld' => Seo::graph([
                    [
                        '@type' => 'CollectionPage',
                        '@id' => $canonical . '#webpage',
                        'url' => $canonical,
                        'name' => $term['name'],
                        'inLanguage' => 'tr-TR',
                    ],
                    Seo::breadcrumbs($crumbs),
                    Seo::itemList($data['items'], $canonical, $term['name']),
                ]),
            ],
        ]);
    }

    private static function page(array $post): void
    {
        $canonical = permalink_page($post);
        $crumbs = [
            ['name' => 'Dükkan', 'url' => site_url()],
            ['name' => $post['title'], 'url' => $canonical],
        ];
        self::render('page', [
            'post' => $post,
            'crumbs' => $crumbs,
            'isContact' => $post['slug'] === 'iletisim',
            'contactOk' => isset($_GET['ok']),
            'contactErr' => isset($_GET['hata']),
            'seo' => [
                'title' => $post['seo_title'] ?: ($post['title'] . ' — BMN Dükkan'),
                'description' => $post['seo_description'] ?: excerpt_plain($post['content'], 160),
                'canonical' => $canonical,
                'jsonld' => Seo::graph([
                    [
                        '@type' => $post['slug'] === 'iletisim' ? 'ContactPage' : 'WebPage',
                        '@id' => $canonical . '#webpage',
                        'url' => $canonical,
                        'name' => $post['title'],
                        'inLanguage' => 'tr-TR',
                        'isPartOf' => ['@id' => Seo::origin() . '/#website'],
                    ],
                    Seo::breadcrumbs($crumbs),
                ]),
            ],
        ]);
    }

    private static function search(string $q): void
    {
        $q = trim($q);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $data = $q === '' ? ['items' => [], 'pager' => paginate(0, 12, 1)] : Catalog::products(['q' => $q, 'per' => 12, 'page' => $page]);
        header('X-Robots-Tag: noindex,follow');
        self::render('search', [
            'q' => $q,
            'items' => $data['items'],
            'pager' => $data['pager'],
            'seo' => [
                'title' => ($q !== '' ? 'Arama: ' . $q : 'Arama') . ' — BMN Dükkan',
                'description' => 'Ürün arama — BMN Dükkan',
                'canonical' => rtrim(site_url(), '/') . '/?s=' . rawurlencode($q),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function cart(): void
    {
        header('X-Robots-Tag: noindex,follow');
        $cart = Cart::hydrated();
        $cross = Catalog::crossForCart($cart);
        self::render('cart', [
            'cart' => $cart,
            'miniCart' => $cart,
            'cross' => $cross,
            'savedEmail' => Abandoned::email(),
            'couponErr' => isset($_GET['kupon']),
            'seo' => [
                'title' => 'Sepet — BMN Dükkan',
                'description' => 'Alışveriş sepetin.',
                'canonical' => site_url('sepet/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function afterCart(string $fallback): never
    {
        $back = cart_return_url();
        redirect($back !== '' ? cart_open_url($back) : $fallback);
    }

    private static function cartPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url('sepet/') . '?sepet_hata=csrf');
        }
        $act = (string) ($_POST['act'] ?? 'add');
        $pid = (int) ($_POST['product_id'] ?? 0);
        $vid = (int) ($_POST['variant_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 1);
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 80);
        if ($act === 'coupon') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $c = Coupon::find($code);
            if ($c && Coupon::usable($c, Cart::hydrated()['subtotal'])) {
                Cart::setCoupon($code);
            } else {
                Cart::setCoupon('');
                redirect(site_url('sepet/') . '?kupon=1');
            }
            redirect(site_url('sepet/'));
        }
        if ($act === 'remind') {
            Abandoned::setEmail(trim((string) ($_POST['email'] ?? '')));
            Abandoned::touch(Cart::hydrated());
            redirect(site_url('sepet/') . '?hatirlat=1');
        }
        if ($act === 'add' && $pid > 0) {
            $p = Catalog::productById($pid);
            if ($p && $p['status'] === 'publish') {
                if (Catalog::hasVariants($pid)) {
                    if ($vid < 1 || !Catalog::variant($vid, $pid)) {
                        redirect(permalink_product($p) . '?hata=varyant');
                    }
                }
                $lineNote = Option::cartLineNote($pid, $vid, $note, trim((string) ($_POST['opt_motor_other'] ?? '')));
                if ($lineNote === false) {
                    redirect(permalink_product($p) . '?hata=motor');
                }
                Cart::add($pid, $vid, $qty, $lineNote);
            }
            if (!empty($_POST['go_cart'])) {
                redirect(site_url('odeme/'));
            }
            $to = $p ? permalink_product($p) . '?sepet=1' : site_url('sepet/');
            self::afterCart($to);
        }
        if ($act === 'update') {
            $lineIx = isset($_POST['line_ix']) && $_POST['line_ix'] !== '' ? (int) $_POST['line_ix'] : null;
            if ($lineIx !== null && $lineIx < 0) {
                $lineIx = null;
            }
            Cart::setQty($pid, $vid, $qty, trim((string) ($_POST['note'] ?? '')), $lineIx);
            self::afterCart(site_url('sepet/'));
        }
        if ($act === 'clear') {
            Cart::clear();
            self::afterCart(site_url('sepet/'));
        }
        redirect(site_url('sepet/'));
    }

    private static function checkout(): void
    {
        header('X-Robots-Tag: noindex,follow');
        $country = strtoupper(trim((string) ($_GET['ulke'] ?? $_POST['country'] ?? 'TR')));
        if (!isset(Shipping::countries()[$country])) {
            $country = 'TR';
        }
        $city = trim((string) ($_GET['il'] ?? $_POST['city'] ?? ''));
        $cart = Cart::hydrated($country, $city);
        if ($cart['lines'] === []) {
            redirect(site_url('sepet/'));
        }
        $cross = Catalog::crossForCart($cart);
        $u = current_user();
        $snap = HfsFinance::snapshot($u);
        $openOk = HfsFinance::canOpenAccount((float) $cart['total'], $u);
        $mins = HfsFinance::minimumErrors($cart);
        self::render('checkout', [
            'cart' => $cart,
            'cross' => $cross,
            'country' => $country,
            'slots' => Shipping::slots(),
            'countries' => Shipping::countries(),
            'savedEmail' => $u['email'] ?? Abandoned::email(),
            'dealer' => $u,
            'snap' => $snap,
            'openOk' => $openOk,
            'mins' => $mins,
            'btOn' => HfsPay::cardEnabled(),
            'gate' => HfsFinance::checkoutGate((float) $cart['total'], $u),
            'err' => (string) ($_GET['hata'] ?? ''),
            'seo' => [
                'title' => 'Ödeme — HFS Tarım Bayi',
                'description' => 'Siparişi tamamla.',
                'canonical' => site_url('odeme/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function checkoutPost(): void
    {
        if (!csrf_ok() || trim((string) ($_POST['website'] ?? '')) !== '') {
            redirect(site_url('odeme/') . '?hata=1');
        }
        $country = strtoupper(trim((string) ($_POST['country'] ?? 'TR')));
        if (!isset(Shipping::countries()[$country])) {
            $country = 'TR';
        }
        $city = trim((string) ($_POST['city'] ?? ''));
        $cart = Cart::hydrated($country, $city);
        if ($cart['lines'] === []) {
            redirect(site_url('sepet/'));
        }
        Abandoned::setEmail(trim((string) ($_POST['email'] ?? '')));
        $form = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'city' => $city,
            'district' => trim((string) ($_POST['district'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'note' => trim((string) ($_POST['note'] ?? '')),
            'country' => $country,
            'ship_slot' => trim((string) ($_POST['ship_slot'] ?? '')),
            'coupon_code' => $cart['coupon_code'],
            'channel' => 'web',
            'payment' => in_array($_POST['payment'] ?? '', ['havale', 'kapida'], true) ? (string) $_POST['payment'] : 'havale',
        ];
        if ($form['name'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL) || $form['phone'] === '' || $form['city'] === '' || $form['address'] === '') {
            redirect(site_url('odeme/') . '?hata=1');
        }
        $code = Catalog::placeOrder($cart, $form);
        $placed = Catalog::orderByCode($code);
        if ($placed) {
            HfsMail::orderPlaced($placed);
        }
        Cart::clear();
        Abandoned::recover();
        redirect(site_url('siparis/' . $code . '/'));
    }

    private static function thanks(string $code): void
    {
        header('X-Robots-Tag: noindex,follow');
        $order = Catalog::orderByCode($code);
        if (!$order || !Catalog::dealerCanSeeOrder($order)) {
            self::notFound();
            return;
        }
        try {
            if (HfsBayt::syncOne($order)) {
                $order = Catalog::orderByCode($code) ?: $order;
            }
        } catch (Throwable) {
        }
        self::render('thanks', [
            'order' => $order,
            'seo' => [
                'title' => 'Sipariş ' . $code . ' — HFS Tarım Bayi',
                'description' => 'Siparişin alındı.',
                'canonical' => site_url('siparis/' . $code . '/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function contactPost(): void
    {
        if (!csrf_ok() || trim((string) ($_POST['website'] ?? '')) !== '') {
            redirect(site_url('iletisim/') . '?hata=1');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            redirect(site_url('iletisim/') . '?hata=1');
        }
        db()->prepare('INSERT INTO contact_messages (name,email,subject,message,ip,created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$name, $email, $subject, $message, $_SERVER['REMOTE_ADDR'] ?? '']);
        HfsMail::contact($name, $email, $subject, $message);
        redirect(site_url('iletisim/') . '?ok=1');
    }

    private static function notFound(): void
    {
        self::render('404', [
            'seo' => [
                'title' => 'Sayfa yok — BMN Dükkan',
                'description' => 'Aradığın sayfa bulunamadı.',
                'canonical' => site_url(ltrim(request_path(), '/')),
                'noindex' => true,
            ],
        ], ['status' => 404, 'no_cache' => true]);
    }

    private static function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo "User-agent: *\nAllow: /\n";
        echo "Disallow: /yonetim/\nDisallow: /sepet/\nDisallow: /odeme/\nDisallow: /siparis/\nDisallow: /hizli-siparis/\nDisallow: /siparis-onaylari/\nDisallow: /hesabim/\nDisallow: /fiyat-listesi/\nDisallow: /arama\nDisallow: /cron/\nDisallow: /iade/\nDisallow: /yorum/\nDisallow: /?s=\n\n";
        echo 'Sitemap: ' . site_url('sitemap_index.xml') . "\n";
        exit;
    }

    private static function xmlFresh(?string $lastmod, int $maxAge = 600): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=' . $maxAge);
        $ts = $lastmod ? strtotime($lastmod) : false;
        if (!is_int($ts) || $ts <= 0) {
            return;
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $ts) . ' GMT');
        $ims = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ims === '') {
            return;
        }
        $since = strtotime($ims);
        if ($since !== false && $since >= $ts) {
            http_response_code(304);
            exit;
        }
    }

    private static function sitemapIndex(): void
    {
        $last = (string) (db()->query("SELECT MAX(modified_at) m FROM products WHERE status='publish'")->fetch()['m'] ?? '');
        self::xmlFresh($last);
        echo Seo::xmlSitemapIndex();
        exit;
    }

    private static function sitemapProducts(): void
    {
        $last = (string) db()->query("SELECT MAX(modified_at) FROM products WHERE status='publish'")->fetchColumn();
        self::xmlFresh($last);
        $rows = db()->query("SELECT slug, modified_at, published_at, featured_media, title FROM products WHERE status='publish' AND (seo_robots IS NULL OR seo_robots='' OR seo_robots NOT LIKE '%noindex%') ORDER BY published_at DESC")->fetchAll();
        $urls = [[
            'loc' => site_url(),
            'lastmod' => $rows[0]['modified_at'] ?? date('c'),
            'changefreq' => 'hourly',
            'priority' => '1.0',
        ]];
        foreach ($rows as $r) {
            $urls[] = [
                'loc' => site_url('urun/' . $r['slug'] . '/'),
                'lastmod' => $r['modified_at'] ?: $r['published_at'],
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'image' => ($r['featured_media'] ?? '') !== '' ? hfs_media((string) $r['featured_media']) : null,
                'image_title' => $r['title'],
            ];
        }
        echo Seo::xmlUrlset($urls);
        exit;
    }

    private static function sitemapCats(): void
    {
        $last = (string) db()->query("SELECT MAX(modified_at) FROM products WHERE status='publish'")->fetchColumn();
        self::xmlFresh($last);
        $rows = db()->query("SELECT * FROM categories WHERE status='publish' ORDER BY path")->fetchAll();
        $urls = [];
        foreach ($rows as $r) {
            $urls[] = [
                'loc' => permalink_category($r),
                'changefreq' => 'daily',
                'priority' => $r['parent_id'] ? '0.6' : '0.7',
            ];
        }
        echo Seo::xmlUrlset($urls);
        exit;
    }

    private static function sitemapPages(): void
    {
        $last = (string) db()->query("SELECT MAX(modified_at) FROM pages WHERE status='publish'")->fetchColumn();
        self::xmlFresh($last);
        $rows = db()->query("SELECT slug, modified_at, title FROM pages WHERE status='publish' ORDER BY title")->fetchAll();
        $urls = [['loc' => site_url(), 'changefreq' => 'daily', 'priority' => '1.0']];
        foreach ($rows as $r) {
            $urls[] = ['loc' => site_url($r['slug'] . '/'), 'lastmod' => $r['modified_at'], 'priority' => '0.5'];
        }
        echo Seo::xmlUrlset($urls);
        exit;
    }

    private static function sitemapPosts(): void
    {
        $last = (string) db()->query("SELECT MAX(modified_at) FROM posts WHERE status='publish'")->fetchColumn();
        self::xmlFresh($last ?: null);
        $rows = db()->query("SELECT slug, modified_at, published_at, title FROM posts WHERE status='publish' ORDER BY published_at DESC")->fetchAll();
        $urls = [['loc' => site_url('yazi/'), 'changefreq' => 'weekly', 'priority' => '0.5']];
        foreach ($rows as $r) {
            $urls[] = [
                'loc' => site_url('yazi/' . $r['slug'] . '/'),
                'lastmod' => $r['modified_at'] ?: $r['published_at'],
                'changefreq' => 'monthly',
                'priority' => '0.45',
            ];
        }
        echo Seo::xmlUrlset($urls);
        exit;
    }

    private static function cron(): void
    {
        header('X-Robots-Tag: noindex');
        $key = (string) ($GLOBALS['bmn_config']['cron_key'] ?? '');
        if ($key === '' || !hash_equals($key, (string) ($_GET['k'] ?? ''))) {
            http_response_code(403);
            echo 'no';
            exit;
        }
        $n = Abandoned::cron();
        $due = db()->query("SELECT o.* FROM orders o LEFT JOIN review_invites i ON i.order_id=o.id WHERE o.status='tamam' AND i.id IS NULL AND o.created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) LIMIT 20")->fetchAll();
        foreach ($due as $o) {
            Review::inviteForOrder($o);
        }
        $sync = ['bulut' => 0, 'bayt' => 0, 'col_fail' => 0];
        if (HfsSync::tryLock()) {
            try {
                $sync = HfsSync::run(true);
            } catch (Throwable) {
            } finally {
                HfsSync::unlock();
            }
        }
        $btIptal = (int) $sync['bulut'];
        $baytIptal = (int) $sync['bayt'];
        $baytGonder = 0;
        try {
            $baytGonder = HfsBayt::pushPendingOrders(5);
        } catch (Throwable) {
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok sepet=' . $n . ' yorum=' . count($due) . ' iptal_bt=' . $btIptal . ' bayt_senk=' . $baytIptal . ' bayt_gonder=' . $baytGonder;
        exit;
    }

    private static function blog(int $page = 1): void
    {
        $data = Catalog::posts(12, $page);
        $canonical = site_url('yazi/');
        self::render('blog', [
            'items' => $data['items'],
            'pager' => $data['pager'],
            'seo' => [
                'title' => 'Yazılar — BMN Dükkan',
                'description' => 'Beden, bakım ve koleksiyon notları. BMN atölye.',
                'canonical' => $canonical,
                'jsonld' => Seo::graph([[
                    '@type' => 'Blog',
                    '@id' => $canonical . '#blog',
                    'url' => $canonical,
                    'name' => 'BMN Yazılar',
                    'inLanguage' => 'tr-TR',
                ]]),
            ],
        ]);
    }

    private static function blogPost(string $slug): void
    {
        $post = Catalog::postBySlug($slug);
        if (!$post) {
            self::notFound();
            return;
        }
        $canonical = permalink_post($post);
        $crumbs = [
            ['name' => 'Dükkan', 'url' => site_url()],
            ['name' => 'Yazılar', 'url' => site_url('yazi/')],
            ['name' => $post['title'], 'url' => $canonical],
        ];
        self::render('blog-post', [
            'post' => $post,
            'crumbs' => $crumbs,
            'seo' => [
                'title' => $post['seo_title'] ?: ($post['title'] . ' — BMN Dükkan'),
                'description' => $post['seo_description'] ?: excerpt_plain($post['excerpt'] ?: $post['content'], 160),
                'canonical' => $canonical,
                'og_type' => 'article',
                'published' => $post['published_at'],
                'modified' => $post['modified_at'],
                'jsonld' => Seo::graph([
                    [
                        '@type' => 'BlogPosting',
                        '@id' => $canonical . '#article',
                        'headline' => $post['title'],
                        'description' => $post['seo_description'] ?: excerpt_plain($post['content'], 160),
                        'datePublished' => date('c', strtotime((string) $post['published_at'])),
                        'dateModified' => date('c', strtotime((string) ($post['modified_at'] ?: $post['published_at']))),
                        'author' => ['@id' => Seo::origin() . '/#organization'],
                        'publisher' => ['@id' => Seo::origin() . '/#organization'],
                        'mainEntityOfPage' => $canonical,
                        'inLanguage' => 'tr-TR',
                    ],
                    Seo::breadcrumbs($crumbs),
                ]),
            ],
        ]);
    }

    private static function reviewForm(string $token): void
    {
        $inv = Review::byToken($token);
        if (!$inv) {
            self::notFound();
            return;
        }
        $order = Catalog::orderByCode((string) $inv['code']);
        header('X-Robots-Tag: noindex');
        self::render('review', [
            'inv' => $inv,
            'order' => $order,
            'ok' => isset($_GET['ok']),
            'seo' => [
                'title' => 'Yorum — BMN Dükkan',
                'description' => 'Sipariş yorumu',
                'canonical' => site_url('yorum/' . $token . '/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function reviewPost(string $token): void
    {
        if (!csrf_ok()) {
            redirect(site_url('yorum/' . $token . '/'));
        }
        $inv = Review::byToken($token);
        if (!$inv) {
            self::notFound();
            return;
        }
        $pid = (int) ($_POST['product_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));
        $rating = (int) ($_POST['rating'] ?? 5);
        if ($pid < 1 || $body === '') {
            redirect(site_url('yorum/' . $token . '/'));
        }
        $photo = null;
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $photo = self::storeReviewPhoto((int) $_FILES['photo']['error'], (string) $_FILES['photo']['tmp_name'], (string) ($_FILES['photo']['name'] ?? ''));
        }
        Review::add($pid, (int) $inv['oid'], (string) $inv['name'], (string) $inv['email'], $rating, $body, $photo);
        redirect(site_url('yorum/' . $token . '/') . '?ok=1');
    }

    private static function storeReviewPhoto(int $err, string $tmp, string $name): ?string
    {
        if ($err !== UPLOAD_ERR_OK || !is_file($tmp)) {
            return null;
        }
        if (filesize($tmp) > 1024 * 1024) {
            return null;
        }
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($tmp) ?: '';
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($map[$mime])) {
            return null;
        }
        $dir = BMN_ROOT . '/storage/uploads/reviews';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fn = bin2hex(random_bytes(8)) . '.' . $map[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $fn)) {
            return null;
        }
        return site_url('storage/uploads/reviews/' . $fn);
    }

    private static function returnForm(): void
    {
        header('X-Robots-Tag: noindex');
        self::render('return', [
            'ok' => isset($_GET['ok']),
            'err' => isset($_GET['hata']),
            'seo' => [
                'title' => 'İade talebi — BMN Dükkan',
                'description' => 'Sipariş iade formu',
                'canonical' => site_url('iade/'),
                'noindex' => true,
            ],
        ], ['no_cache' => true]);
    }

    private static function returnPost(): void
    {
        if (!csrf_ok() || trim((string) ($_POST['website'] ?? '')) !== '') {
            redirect(site_url('iade/') . '?hata=1');
        }
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $email = trim((string) ($_POST['email'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $order = Catalog::orderByCode($code);
        if (!$order || strcasecmp((string) $order['email'], $email) !== 0 || $reason === '') {
            redirect(site_url('iade/') . '?hata=1');
        }
        db()->prepare('INSERT INTO returns (order_id,reason,status,created_at) VALUES (?, ?,\'yeni\',NOW())')
            ->execute([(int) $order['id'], $reason]);
        HfsMail::returnCreated($order, $reason);
        redirect(site_url('iade/') . '?ok=1');
    }

    private static function stockAlertPost(): void
    {
        if (!csrf_ok()) {
            redirect(site_url());
        }
        $pid = (int) ($_POST['product_id'] ?? 0);
        $email = trim((string) ($_POST['email'] ?? ''));
        $p = $pid ? Catalog::productById($pid) : null;
        if ($p && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            db()->prepare('INSERT IGNORE INTO stock_alerts (product_id,email,created_at) VALUES (?,?,NOW())')
                ->execute([$pid, $email]);
            redirect(permalink_product($p) . '?stok=1');
        }
        redirect($p ? permalink_product($p) : site_url());
    }

    private static function feed(): void
    {
        $last = (string) db()->query("SELECT MAX(modified_at) FROM products WHERE status='publish'")->fetchColumn();
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        $ts = $last !== '' ? strtotime($last) : false;
        if (is_int($ts) && $ts > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $ts) . ' GMT');
            $ims = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
            $since = $ims !== '' ? strtotime($ims) : false;
            if ($since !== false && $since >= $ts) {
                http_response_code(304);
                exit;
            }
        }
        echo Seo::rss(Catalog::latest(20));
        exit;
    }

    private static function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name' => setting('site_name', 'BMN Dükkan'),
            'short_name' => 'BMN',
            'start_url' => site_url(),
            'id' => site_url(),
            'display' => 'standalone',
            'background_color' => '#640020',
            'theme_color' => '#89CFF0',
            'lang' => 'tr',
            'icons' => [
                ['src' => shop_logo(), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function llms(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "BMN Dükkan — Trabzonspor taraftar koleksiyonu\n";
        echo "Site: " . site_url() . "\n";
        echo "Canlı: " . live_origin() . "/\n";
        echo "Ana site: https://bordomavi.net/\n";
        echo "İletişim: " . setting('email', 'dukkan@bordomavi.net') . "\n";
        echo "Sitemap: " . site_url('sitemap_index.xml') . "\n";
        echo "Kargo: " . money(free_ship_min()) . " üzeri ücretsiz. 14 gün iade.\n";
        exit;
    }
}
