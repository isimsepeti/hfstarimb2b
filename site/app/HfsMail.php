<?php
declare(strict_types=1);

final class HfsMail
{
    /** @return array<string,array{group:string,title:string,help:string,subject:string,body:string}> */
    public static function catalog(): array
    {
        $site = '{{site_name}}';
        return [
            'order_new' => [
                'group' => 'Sipariş',
                'title' => 'Sipariş alındı (müşteri)',
                'help' => 'Havale / açık hesap ve panel siparişlerinde bayiye gider. Kartta ödeme onayından sonra kullanılır.',
                'subject' => $site . ' — Sipariş {{order_code}} alındı',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> numaralı siparişiniz alındı. Tutar: <strong>{{total}}</strong></p>{{items_html}}<p>Ödeme: {{payment_label}}<br>PO: {{po_number}}</p><p>Teslimat: {{address}}, {{district}} {{city}}</p>{{iban_block_html}}<p><a href="{{link}}">Siparişi görüntüle</a> · <a href="{{slip_link}}">İrsaliye / PDF</a></p>{{dealer_links_html}}',
            ],
            'order_admin' => [
                'group' => 'Sipariş',
                'title' => 'Yeni sipariş (yönetim)',
                'help' => 'Ayarlardaki mağaza e-postasına gider.',
                'subject' => 'Yeni sipariş {{order_code}} — {{total}}',
                'body' => '<p>Yeni sipariş: <strong>{{order_code}}</strong></p><p>{{name}} · {{customer_email}} · {{customer_phone}}</p><p>Durum: {{status_label}} · Ödeme: {{payment_label}} · Tutar: <strong>{{total}}</strong></p>{{items_html}}<p>{{address}}, {{district}} {{city}}</p><p>Not: {{note}}</p><p><a href="{{admin_link}}">Panelde aç</a></p>',
            ],
            'order_pending' => [
                'group' => 'Sipariş',
                'title' => 'Onay bekliyor (müşteri)',
                'help' => 'Alıcı limiti aşınca sipariş onaya düşer.',
                'subject' => $site . ' — {{order_code}} onay bekliyor',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz şirket onayına gönderildi. Tutar: <strong>{{total}}</strong></p>{{items_html}}<p>Onaylandıktan sonra işleme alınır.</p><p><a href="{{account_link}}">Hesabım</a> · <a href="{{approval_link}}">Sipariş onayları</a></p>{{dealer_links_html}}',
            ],
            'order_need_approval' => [
                'group' => 'Sipariş',
                'title' => 'Onay gerekli (yetkili)',
                'help' => 'Şirketteki onaycı ve finans yöneticilerine gider.',
                'subject' => 'Onay bekleyen sipariş {{order_code}} — {{total}}',
                'body' => '<p>{{name}} tarafından <strong>{{order_code}}</strong> siparişi onayınıza düştü.</p><p>Tutar: <strong>{{total}}</strong> (limit {{approval_limit}})</p>{{items_html}}<p><a href="{{approval_link}}">Sipariş onayları</a></p>',
            ],
            'order_approved' => [
                'group' => 'Sipariş',
                'title' => 'Sipariş onaylandı',
                'help' => 'Yetkili onaylayınca bayiye gider.',
                'subject' => $site . ' — {{order_code}} onaylandı',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz onaylandı ve işleme alındı. Tutar: <strong>{{total}}</strong></p><p>Durum: {{status_label}}</p><p><a href="{{link}}">Siparişi görüntüle</a> · <a href="{{slip_link}}">İrsaliye / PDF</a></p>{{dealer_links_html}}',
            ],
            'order_rejected' => [
                'group' => 'Sipariş',
                'title' => 'Sipariş reddedildi',
                'help' => 'Yetkili reddedince bayiye gider; stok iade edilir.',
                'subject' => $site . ' — {{order_code}} reddedildi',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz onay sürecinde reddedildi ve iptal edildi.</p><p>Sorunuz varsa {{phone}} veya {{email}} üzerinden yazın.</p>',
            ],
            'order_status' => [
                'group' => 'Sipariş',
                'title' => 'Durum değişti',
                'help' => 'Panelden sipariş durumu değişince bayiye gider.',
                'subject' => $site . ' — {{order_code}} {{status_label}}',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişinizin yeni durumu: <strong>{{status_label}}</strong></p>{{tracking_block_html}}<p><a href="{{link}}">Siparişi görüntüle</a> · <a href="{{slip_link}}">İrsaliye / PDF</a> · <a href="{{kargo_link}}">Kargo takibi</a></p>{{dealer_links_html}}',
            ],
            'order_tracking' => [
                'group' => 'Sipariş',
                'title' => 'Kargo takip no',
                'help' => 'Yönetimde takip numarası girilince / değişince bayiye gider. Yurtiçi API ile kargo oluşturmaz; yalnız takip bilgisi.',
                'subject' => $site . ' — {{order_code}} kargo takip no: {{tracking}}',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz kargoya verildi.</p><p>Takip no: <strong>{{tracking}}</strong></p><p><a href="{{track_url}}">Yurtiçi Kargo’da sorgula</a> · <a href="{{link}}">Siparişi görüntüle</a> · <a href="{{kargo_link}}">Panelde kargo takibi</a></p>{{dealer_links_html}}',
            ],
            'order_paid' => [
                'group' => 'Sipariş',
                'title' => 'Kart ödemesi alındı',
                'help' => 'BulutTahsilat başarılı dönüşünde (cari tahsilat hariç).',
                'subject' => $site . ' — {{order_code}} ödemesi alındı',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişinizin kart ödemesi alındı. Tutar: <strong>{{total}}</strong></p>{{items_html}}<p><a href="{{link}}">Siparişi görüntüle</a> · <a href="{{slip_link}}">İrsaliye / PDF</a></p>{{dealer_links_html}}',
            ],
            'return_new' => [
                'group' => 'İade',
                'title' => 'İade talebi alındı (müşteri)',
                'help' => 'İade formu gönderilince bayiye gider.',
                'subject' => $site . ' — {{order_code}} iade talebi alındı',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> için iade talebiniz alındı.</p><p>Gerekçe: {{reason}}</p><p>Sonucu e-posta ile bildiririz.</p>',
            ],
            'return_admin' => [
                'group' => 'İade',
                'title' => 'Yeni iade (yönetim)',
                'help' => 'Mağaza e-postasına gider.',
                'subject' => 'İade talebi {{order_code}}',
                'body' => '<p>Yeni iade: <strong>{{order_code}}</strong></p><p>{{name}} · {{customer_email}}</p><p>Gerekçe: {{reason}}</p><p><a href="{{admin_returns_link}}">İadeler</a></p>',
            ],
            'return_status' => [
                'group' => 'İade',
                'title' => 'İade durumu',
                'help' => 'Panelden iade durumu değişince bayiye gider.',
                'subject' => $site . ' — {{order_code}} iade: {{return_status_label}}',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> iade talebinizin durumu: <strong>{{return_status_label}}</strong></p><p>{{return_note}}</p>',
            ],
            'review_invite' => [
                'group' => 'Diğer',
                'title' => 'Değerlendirme daveti',
                'help' => 'Sipariş tamamlanınca gönderilir.',
                'subject' => $site . ' — {{order_code}} siparişinizi değerlendirin',
                'body' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz tamamlandı. Kısa bir değerlendirme sonraki teslimatlara yardımcı olur.</p><p><a href="{{review_link}}">Yorum yaz</a></p>',
            ],
            'stock_alert' => [
                'group' => 'Diğer',
                'title' => 'Stok haberi',
                'help' => 'Stok gelince bekleyen e-postalara gider.',
                'subject' => '{{product_title}} tekrar stokta',
                'body' => '<p>{{product_title}} stoğa girdi.</p><p><a href="{{product_link}}">Ürüne git</a></p>',
            ],
            'cart_remind' => [
                'group' => 'Diğer',
                'title' => 'Terk edilen sepet (1.)',
                'help' => '2 saat sonra, sepeti bırakana.',
                'subject' => $site . ' — Sepetinizde ürün kaldı',
                'body' => '<p>Sepetinizde bekleyen ürünler: {{cart_items}}</p><p><a href="{{cart_link}}">Sepete dön</a></p>',
            ],
            'cart_coupon' => [
                'group' => 'Diğer',
                'title' => 'Terk edilen sepet (2. / kupon)',
                'help' => '24 saat sonra; varsayılan kupon SEP10.',
                'subject' => $site . ' — Sepetinize {{coupon}} kuponu',
                'body' => '<p>Kupon kodunuz: <strong>{{coupon}}</strong></p><p>{{cart_items}}</p><p><a href="{{cart_link}}">Alışverişi tamamla</a></p>',
            ],
            'contact_admin' => [
                'group' => 'İletişim',
                'title' => 'İletişim formu (yönetim)',
                'help' => 'İletişim formundan mağaza e-postasına.',
                'subject' => 'İletişim: {{subject}}',
                'body' => '<p><strong>{{name}}</strong> ({{customer_email}})</p><p>{{subject}}</p><p>{{message}}</p>',
            ],
            'contact_customer' => [
                'group' => 'İletişim',
                'title' => 'İletişim formu kopyası (müşteri)',
                'help' => 'Varsayılan kapalı. Açarsanız gönderene kopya gider.',
                'subject' => $site . ' — Mesajınız alındı',
                'body' => '<p>Merhaba {{name}},</p><p>Mesajınız bize ulaştı. En kısa sürede dönüş yapacağız.</p><p>{{message}}</p>',
            ],
        ];
    }

    public static function enabled(string $key): bool
    {
        if (!isset(self::catalog()[$key])) {
            return false;
        }
        $def = $key === 'contact_customer' ? '0' : '1';
        return setting('mail_tpl_' . $key . '_on', $def) !== '0';
    }

    /** @return array{subject:string,body:string} */
    public static function resolved(string $key): array
    {
        $def = self::catalog()[$key] ?? ['subject' => '', 'body' => ''];
        $sub = (string) setting('mail_tpl_' . $key . '_subject', '');
        $body = (string) setting('mail_tpl_' . $key . '_body', '');
        return [
            'subject' => $sub !== '' ? $sub : (string) $def['subject'],
            'body' => $body !== '' ? $body : (string) $def['body'],
        ];
    }

    public static function save(string $key, string $subject, string $body, bool $on): void
    {
        if (!isset(self::catalog()[$key])) {
            return;
        }
        setting_set('mail_tpl_' . $key . '_subject', $subject);
        setting_set('mail_tpl_' . $key . '_body', $body);
        setting_set('mail_tpl_' . $key . '_on', $on ? '1' : '0');
    }

    public static function restore(string $key): void
    {
        if (!isset(self::catalog()[$key])) {
            return;
        }
        setting_set('mail_tpl_' . $key . '_subject', '');
        setting_set('mail_tpl_' . $key . '_body', '');
        setting_set('mail_tpl_' . $key . '_on', '1');
    }

    /** @param array<string,mixed> $vars */
    public static function send(string $key, string $to, array $vars = [], string $replyTo = '', bool $force = false): array
    {
        $fail = ['ok' => false, 'error' => ''];
        if (!$force && !self::enabled($key)) {
            return ['ok' => false, 'error' => 'Şablon kapalı.'];
        }
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Geçersiz alıcı.'];
        }
        $vars = array_merge(self::baseVars(), $vars);
        $tpl = self::resolved($key);
        $subject = html_entity_decode(self::fill($tpl['subject'], $vars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = self::fill($tpl['body'], $vars);
        if (!str_contains(strtolower($body), '<html')) {
            $body = Mailer::shell($body);
        }
        try {
            return Mailer::send($to, $subject, $body, $replyTo);
        } catch (Throwable $e) {
            $fail['error'] = $e->getMessage();
            return $fail;
        }
    }

    public static function fire(string $key, string $to, array $vars = [], string $replyTo = ''): void
    {
        try {
            self::send($key, $to, $vars, $replyTo);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $user */
    public static function dealerPortalLoginNotice(array $user, string $loginId): array
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Geçersiz e-posta'];
        }
        $name = trim((string) ($user['display_name'] ?? $user['company'] ?? ''));
        $loginUrl = site_url('giris/');
        $html = Mailer::wrap(
            'Bayi portalı giriş bilgileri',
            '<p>Merhaba ' . h($name !== '' ? $name : 'Bayi') . ',</p>'
            . '<p>HFS Tarım bayi portalına giriş yapabilirsiniz.</p>'
            . '<p><strong>Kullanıcı adı:</strong> ' . h($loginId) . '<br>'
            . '<strong>İlk şifre:</strong> ' . h($loginId) . ' (kayıtlı vergi / T.C. numaranız)</p>'
            . '<p>Güvenlik için <strong>ilk girişte</strong> yeni bir şifre belirlemeniz zorunludur.</p>'
            . '<p>Şifrenizi unutursanız giriş sayfasındaki «Şifremi unuttum» bağlantısını kullanın.</p>',
            $loginUrl,
            'Bayi portalına giriş'
        );
        return Mailer::send(
            $email,
            (string) setting('site_name', 'HFS Tarım') . ' — Bayi portalı girişi',
            $html
        );
    }

    public static function inbox(): string
    {
        $e = trim((string) setting('email', ''));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    /** @param array<string,mixed> $order */
    public static function orderPlaced(array $order, bool $waitPayment = false): void
    {
        $order = self::hydrateOrder($order);
        if (!$order || (int) ($order['is_cari'] ?? 0) === 1) {
            return;
        }
        if ($waitPayment || ($order['status'] ?? '') === 'odeme') {
            return;
        }
        $vars = self::orderVars($order);
        if (($order['status'] ?? '') === 'pending_b2b') {
            self::fire('order_pending', (string) $order['email'], $vars);
            self::notifyApprovers($order, $vars);
        } else {
            self::fire('order_new', (string) $order['email'], $vars);
        }
        $inbox = self::inbox();
        if ($inbox !== '') {
            self::fire('order_admin', $inbox, $vars);
        }
    }

    /** @param array<string,mixed> $order */
    public static function orderPaid(array $order): void
    {
        $order = self::hydrateOrder($order);
        if (!$order || (int) ($order['is_cari'] ?? 0) === 1) {
            return;
        }
        $vars = self::orderVars($order);
        if (($order['status'] ?? '') === 'pending_b2b') {
            self::fire('order_pending', (string) $order['email'], $vars);
            self::notifyApprovers($order, $vars);
        } else {
            self::fire('order_paid', (string) $order['email'], $vars);
        }
        $inbox = self::inbox();
        if ($inbox !== '') {
            self::fire('order_admin', $inbox, $vars);
        }
    }

    /** @param array<string,mixed> $order */
    public static function orderApproved(array $order): void
    {
        $order = self::hydrateOrder($order);
        if (!$order) {
            return;
        }
        self::fire('order_approved', (string) $order['email'], self::orderVars($order));
    }

    /** @param array<string,mixed> $order */
    public static function orderRejected(array $order): void
    {
        $order = self::hydrateOrder($order);
        if (!$order) {
            return;
        }
        self::fire('order_rejected', (string) $order['email'], self::orderVars($order));
    }

    /** @param array<string,mixed> $order */
    public static function orderStatus(array $order, string $oldStatus): void
    {
        $order = self::hydrateOrder($order);
        if (!$order) {
            return;
        }
        $now = (string) ($order['status'] ?? '');
        if ($now === '' || $now === $oldStatus) {
            return;
        }
        if (in_array($now, ['odeme', 'pending_b2b'], true) && in_array($oldStatus, ['odeme', 'pending_b2b', ''], true)) {
            return;
        }
        self::fire('order_status', (string) $order['email'], self::orderVars($order));
    }

    /** Takip no ilk kez girilince veya değişince; YK’da kargo oluşturmaz. */
    public static function orderTracking(array $order): void
    {
        $order = self::hydrateOrder($order);
        if (!$order) {
            return;
        }
        $track = trim((string) ($order['tracking'] ?? ''));
        if ($track === '') {
            return;
        }
        self::fire('order_tracking', (string) $order['email'], self::orderVars($order));
    }

    /** @param array<string,mixed> $order */
    public static function returnCreated(array $order, string $reason): void
    {
        $order = self::hydrateOrder($order);
        if (!$order) {
            return;
        }
        $vars = self::orderVars($order);
        $vars['reason'] = $reason;
        $vars['return_status'] = 'yeni';
        $vars['return_status_label'] = self::returnLabel('yeni');
        $vars['return_note'] = '';
        self::fire('return_new', (string) $order['email'], $vars);
        $inbox = self::inbox();
        if ($inbox !== '') {
            self::fire('return_admin', $inbox, $vars);
        }
    }

    /** @param array<string,mixed> $row */
    public static function returnStatus(array $row): void
    {
        $order = Catalog::orderByCode((string) ($row['code'] ?? ''));
        if (!$order && !empty($row['order_id'])) {
            $st = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
            $st->execute([(int) $row['order_id']]);
            $order = $st->fetch() ?: null;
            if ($order) {
                $order = self::hydrateOrder($order);
            }
        }
        if (!$order) {
            return;
        }
        $vars = self::orderVars($order);
        $stt = (string) ($row['status'] ?? '');
        $vars['reason'] = (string) ($row['reason'] ?? '');
        $vars['return_status'] = $stt;
        $vars['return_status_label'] = self::returnLabel($stt);
        $vars['return_note'] = (string) ($row['note'] ?? '');
        self::fire('return_status', (string) $order['email'], $vars);
    }

    public static function contact(string $name, string $email, string $subject, string $message): void
    {
        $vars = [
            'name' => $name,
            'customer_email' => $email,
            'subject' => $subject !== '' ? $subject : 'İletişim formu',
            'message' => $message,
        ];
        $inbox = self::inbox();
        if ($inbox !== '') {
            self::fire('contact_admin', $inbox, $vars, $email);
        }
        self::fire('contact_customer', $email, $vars);
    }

    /** @param array<string,mixed> $order */
    public static function reviewInvite(array $order, string $token): void
    {
        $order = self::hydrateOrder($order);
        if (!$order) {
            return;
        }
        $vars = self::orderVars($order);
        $vars['review_link'] = site_url('yorum/' . $token . '/');
        $vars['link'] = $vars['review_link'];
        self::fire('review_invite', (string) $order['email'], $vars);
    }

    /** @param array<string,mixed> $product */
    public static function stockAlert(array $product, string $email): void
    {
        self::fire('stock_alert', $email, [
            'product_title' => (string) ($product['title'] ?? ''),
            'product_link' => permalink_product($product),
            'link' => permalink_product($product),
        ]);
    }

    public static function cartRemind(string $email, string $items): void
    {
        self::fire('cart_remind', $email, [
            'cart_items' => $items,
            'cart_link' => site_url('sepet/'),
            'link' => site_url('sepet/'),
        ]);
    }

    public static function cartCoupon(string $email, string $items, string $coupon = 'SEP10'): void
    {
        self::fire('cart_coupon', $email, [
            'cart_items' => $items,
            'coupon' => $coupon,
            'cart_link' => site_url('sepet/'),
            'link' => site_url('sepet/'),
        ]);
    }

    /** @return array<string,string> */
    public static function dummyVars(string $key = 'order_new'): array
    {
        $vars = array_merge(self::baseVars(), [
            'name' => 'Örnek Bayi',
            'customer_email' => 'bayi@example.com',
            'customer_phone' => '0224 000 00 00',
            'order_code' => 'HFS-TEST01',
            'total' => money(12500),
            'subtotal' => money(12500),
            'shipping' => money(0),
            'discount' => money(0),
            'items_html' => '<table width="100%" cellpadding="6" cellspacing="0"><tr><td>Örnek ürün × 2</td><td style="text-align:right">' . h(money(12500)) . '</td></tr></table>',
            'items_text' => 'Örnek ürün × 2',
            'status' => 'yeni',
            'status_label' => hfs_order_status('yeni'),
            'payment' => 'havale',
            'payment_label' => 'Havale / EFT',
            'po_number' => 'PO-100',
            'tracking' => '1234567890',
            'track_url' => hfs_yurtici_track_url('1234567890'),
            'tracking_block_html' => '<p>Kargo takip no: <strong>1234567890</strong></p>',
            'kargo_link' => site_url('hesabim/kargo/'),
            'slip_link' => site_url('siparis/HFS-TEST01/irsaliye/'),
            'ekstre_link' => site_url('hesabim/ekstre/'),
            'fiyat_link' => site_url('hesabim/fiyat-listesi/'),
            'quick_link' => site_url('hizli-siparis/'),
            'dealer_links_html' => '<p><a href="' . h(site_url('hesabim/')) . '">Hesabım</a> · <a href="' . h(site_url('hesabim/ekstre/')) . '">Cari ekstre</a> · <a href="' . h(site_url('hesabim/kargo/')) . '">Kargo takibi</a> · <a href="' . h(site_url('hesabim/fiyat-listesi/')) . '">Fiyat listesi</a></p>',
            'iban_block_html' => '<p>IBAN: <strong>' . h((string) setting('iban')) . '</strong><br>' . h((string) setting('iban_name')) . '</p>',
            'address' => 'Örnek Mah. 1. Sokak No:1',
            'city' => 'Bursa',
            'district' => 'Nilüfer',
            'note' => 'Test notu',
            'link' => site_url('siparis/HFS-TEST01/'),
            'admin_link' => site_url('yonetim/siparisler/'),
            'admin_returns_link' => site_url('yonetim/iadeler/'),
            'account_link' => site_url('hesabim/'),
            'approval_link' => site_url('siparis-onaylari/'),
            'approval_limit' => money(10000),
            'reason' => 'Yanlış ürün',
            'return_status' => 'yeni',
            'return_status_label' => 'Yeni talep',
            'return_note' => '',
            'review_link' => site_url('hesabim/'),
            'product_title' => 'Örnek ürün',
            'product_link' => site_url(),
            'cart_items' => 'Örnek ürün',
            'cart_link' => site_url('sepet/'),
            'coupon' => 'SEP10',
            'subject' => 'Fiyat teklifi',
            'message' => 'Bu bir test mesajıdır.',
        ]);
        return $vars;
    }

    /** @return list<array<string,mixed>> */
    public static function recentLog(int $n = 40): array
    {
        try {
            $n = max(1, min(100, $n));
            return db()->query('SELECT id,to_email,subject,status,err,created_at FROM mail_log ORDER BY id DESC LIMIT ' . $n)->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,string> */
    public static function placeholders(): array
    {
        return [
            '{{site_name}}' => 'Site adı',
            '{{site_url}}' => 'Site adresi',
            '{{email}}' => 'Mağaza e-posta',
            '{{phone}}' => 'Mağaza telefon',
            '{{iban}}' => 'IBAN',
            '{{iban_name}}' => 'IBAN unvan',
            '{{iban_block_html}}' => 'Havale bloğu (HTML)',
            '{{logo_url}}' => 'Logo adresi',
            '{{logo_html}}' => 'Logo görseli (HTML)',
            '{{name}}' => 'Alıcı adı',
            '{{customer_email}}' => 'Alıcı e-posta',
            '{{customer_phone}}' => 'Alıcı telefon',
            '{{order_code}}' => 'Sipariş kodu',
            '{{total}}' => 'Genel toplam',
            '{{subtotal}}' => 'Ara toplam',
            '{{shipping}}' => 'Kargo',
            '{{discount}}' => 'İndirim',
            '{{items_html}}' => 'Kalem tablosu (HTML)',
            '{{items_text}}' => 'Kalem listesi (düz)',
            '{{status}}' => 'Durum kodu',
            '{{status_label}}' => 'Durum metni',
            '{{payment}}' => 'Ödeme kodu',
            '{{payment_label}}' => 'Ödeme metni',
            '{{po_number}}' => 'PO numarası',
            '{{tracking}}' => 'Kargo takip',
            '{{track_url}}' => 'Yurtiçi takip linki',
            '{{tracking_block_html}}' => 'Takip bloğu (HTML)',
            '{{kargo_link}}' => 'Kargo takibi sayfası',
            '{{slip_link}}' => 'İrsaliye PDF',
            '{{ekstre_link}}' => 'Cari ekstre',
            '{{fiyat_link}}' => 'Fiyat listesi Excel',
            '{{quick_link}}' => 'Hızlı sipariş',
            '{{dealer_links_html}}' => 'Bayi kısayolları (HTML)',
            '{{address}}' => 'Adres',
            '{{city}}' => 'İl',
            '{{district}}' => 'İlçe',
            '{{note}}' => 'Sipariş notu',
            '{{link}}' => 'Sipariş bağlantısı',
            '{{admin_link}}' => 'Panel sipariş',
            '{{account_link}}' => 'Hesabım',
            '{{approval_link}}' => 'Onay sayfası',
            '{{approval_limit}}' => 'Onay limiti',
            '{{reason}}' => 'İade gerekçesi',
            '{{return_status_label}}' => 'İade durumu',
            '{{return_note}}' => 'İade notu',
            '{{review_link}}' => 'Yorum bağlantısı',
            '{{product_title}}' => 'Ürün adı',
            '{{product_link}}' => 'Ürün bağlantısı',
            '{{cart_items}}' => 'Sepet ürünleri',
            '{{cart_link}}' => 'Sepet bağlantısı',
            '{{coupon}}' => 'Kupon kodu',
            '{{subject}}' => 'İletişim konusu',
            '{{message}}' => 'İletişim mesajı',
        ];
    }

    /** @return array<string,string> */
    private static function baseVars(): array
    {
        $iban = trim((string) setting('iban', ''));
        $ibanName = trim((string) setting('iban_name', ''));
        $ibanHtml = '';
        if ($iban !== '') {
            $ibanHtml = '<p>Havale: <strong>' . h($iban) . '</strong>';
            if ($ibanName !== '') {
                $ibanHtml .= '<br>' . h($ibanName);
            }
            $ibanHtml .= '</p>';
        }
        $logo = shop_logo_abs();
        $logoHtml = '<img src="' . h($logo) . '" alt="' . h((string) setting('site_name', 'HFS Tarım')) . '" width="180" style="max-width:180px;height:auto;border:0">';
        return [
            'site_name' => (string) setting('site_name', 'HFS Tarım'),
            'site_url' => site_url(),
            'email' => (string) setting('email', ''),
            'phone' => (string) setting('phone', ''),
            'iban' => $iban,
            'iban_name' => $ibanName,
            'iban_block_html' => $ibanHtml,
            'logo_url' => $logo,
            'logo_html' => $logoHtml,
        ];
    }

    /** @param array<string,mixed> $vars */
    private static function fill(string $tpl, array $vars): string
    {
        $rep = [];
        foreach ($vars as $k => $v) {
            $val = (string) $v;
            if (!str_ends_with((string) $k, '_html')) {
                $val = h($val);
            }
            $rep['{{' . $k . '}}'] = $val;
        }
        return strtr($tpl, $rep);
    }

    /** @param array<string,mixed> $order */
    private static function hydrateOrder(array $order): ?array
    {
        if ($order === []) {
            return null;
        }
        if (empty($order['items']) && !empty($order['code'])) {
            $full = Catalog::orderByCode((string) $order['code']);
            if ($full) {
                return $full;
            }
        }
        if (empty($order['items']) && !empty($order['id'])) {
            $it = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
            $it->execute([(int) $order['id']]);
            $order['items'] = $it->fetchAll();
        }
        $order['items'] = $order['items'] ?? [];
        return $order;
    }

    public static function healDealerTemplates(): void
    {
        $old = [
            'order_new' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> numaralı siparişiniz alındı. Tutar: <strong>{{total}}</strong></p>{{items_html}}<p>Ödeme: {{payment_label}}<br>PO: {{po_number}}</p><p>Teslimat: {{address}}, {{district}} {{city}}</p>{{iban_block_html}}<p><a href="{{link}}">Siparişi görüntüle</a></p>',
            'order_pending' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz şirket onayına gönderildi. Tutar: <strong>{{total}}</strong></p>{{items_html}}<p>Onaylandıktan sonra işleme alınır.</p><p><a href="{{account_link}}">Hesabım</a></p>',
            'order_approved' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişiniz onaylandı ve işleme alındı. Tutar: <strong>{{total}}</strong></p><p>Durum: {{status_label}}</p><p><a href="{{link}}">Siparişi görüntüle</a></p>',
            'order_status' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişinizin yeni durumu: <strong>{{status_label}}</strong></p><p>{{tracking_block_html}}</p><p><a href="{{link}}">Siparişi görüntüle</a></p>',
            'order_paid' => '<p>Merhaba {{name}},</p><p><strong>{{order_code}}</strong> siparişinizin kart ödemesi alındı. Tutar: <strong>{{total}}</strong></p>{{items_html}}<p><a href="{{link}}">Siparişi görüntüle</a></p>',
        ];
        foreach ($old as $key => $body) {
            $saved = (string) setting('mail_tpl_' . $key . '_body', '');
            if ($saved === $body) {
                setting_set('mail_tpl_' . $key . '_body', '');
            }
        }
    }

    private static function trackingBlockHtml(string $track, string $kargoUrl, string $slipUrl): string
    {
        if ($track === '') {
            return '<p>Kargo takip numarası henüz yok. <a href="' . h($kargoUrl) . '">Kargo takibi</a></p>';
        }
        $yk = hfs_yurtici_track_url($track);
        return '<p>Kargo takip no: <strong>' . h($track) . '</strong><br>'
            . '<a href="' . h($yk) . '">Yurtiçi Kargo’da sorgula</a>'
            . '<br><a href="' . h($kargoUrl) . '">Panelde kargo takibi</a> · <a href="' . h($slipUrl) . '">İrsaliye / PDF</a></p>';
    }

    /** @param array<string,mixed> $order @return array<string,string> */
    private static function orderVars(array $order): array
    {
        $items = $order['items'] ?? [];
        $html = '<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;border:1px solid #e0ddd3">';
        $text = [];
        foreach ($items as $it) {
            $qty = (int) ($it['qty'] ?? 0);
            $line = (float) ($it['price'] ?? 0) * $qty;
            $title = (string) ($it['title'] ?? '');
            $html .= '<tr><td style="border-bottom:1px solid #eeeae1">' . h($title) . ' × ' . $qty . '</td>'
                . '<td style="border-bottom:1px solid #eeeae1;text-align:right">' . h(money($line)) . '</td></tr>';
            $text[] = $title . ' × ' . $qty;
        }
        $html .= '</table>';
        $pay = (string) ($order['payment'] ?? '');
        $status = (string) ($order['status'] ?? '');
        $track = trim((string) ($order['tracking'] ?? ''));
        $code = (string) ($order['code'] ?? '');
        $orderUrl = $code !== '' ? site_url('siparis/' . $code . '/') : site_url('hesabim/');
        $slipUrl = $code !== '' ? site_url('siparis/' . $code . '/irsaliye/') : site_url('hesabim/kargo/');
        $kargoUrl = site_url('hesabim/kargo/');
        $ekstreUrl = site_url('hesabim/ekstre/');
        $fiyatUrl = site_url('hesabim/fiyat-listesi/');
        $quickUrl = site_url('hizli-siparis/');
        $trackHtml = self::trackingBlockHtml($track, $kargoUrl, $slipUrl);
        $dealerLinks = '<p style="margin:16px 0 0;font-size:13px;line-height:1.6">'
            . '<a href="' . h(site_url('hesabim/')) . '">Hesabım</a> · '
            . '<a href="' . h($ekstreUrl) . '">Cari ekstre</a> · '
            . '<a href="' . h($kargoUrl) . '">Kargo takibi</a> · '
            . '<a href="' . h($fiyatUrl) . '">Fiyat listesi (Excel)</a> · '
            . '<a href="' . h($quickUrl) . '">Hızlı sipariş</a></p>';
        return array_merge(self::baseVars(), [
            'name' => (string) ($order['name'] ?? ''),
            'customer_email' => (string) ($order['email'] ?? ''),
            'customer_phone' => (string) ($order['phone'] ?? ''),
            'order_code' => $code,
            'total' => money($order['total'] ?? 0),
            'subtotal' => money($order['subtotal'] ?? 0),
            'shipping' => money($order['shipping'] ?? 0),
            'discount' => money($order['discount'] ?? 0),
            'items_html' => $html,
            'items_text' => implode(', ', $text),
            'status' => $status,
            'status_label' => hfs_order_status_for($order),
            'payment' => $pay,
            'payment_label' => self::payLabel($pay),
            'po_number' => (string) ($order['po_number'] ?? ''),
            'tracking' => $track,
            'track_url' => hfs_yurtici_track_url($track),
            'tracking_block_html' => $trackHtml,
            'kargo_link' => $kargoUrl,
            'slip_link' => $slipUrl,
            'ekstre_link' => $ekstreUrl,
            'fiyat_link' => $fiyatUrl,
            'quick_link' => $quickUrl,
            'dealer_links_html' => $dealerLinks,
            'address' => (string) ($order['address'] ?? ''),
            'city' => (string) ($order['city'] ?? ''),
            'district' => (string) ($order['district'] ?? ''),
            'note' => (string) ($order['note'] ?? ''),
            'link' => $orderUrl,
            'admin_link' => site_url('yonetim/siparisler/' . $code . '/'),
            'admin_returns_link' => site_url('yonetim/iadeler/'),
            'account_link' => site_url('hesabim/'),
            'approval_link' => site_url('siparis-onaylari/'),
            'approval_limit' => money($order['approval_limit_used'] ?? 0),
            'reason' => '',
            'return_status' => '',
            'return_status_label' => '',
            'return_note' => '',
        ]);
    }

    /** @param array<string,mixed> $order @param array<string,string> $vars */
    private static function notifyApprovers(array $order, array $vars): void
    {
        $cid = (int) ($order['company_id'] ?? 0);
        if ($cid < 1) {
            $cid = (int) ($order['user_id'] ?? 0);
        }
        if ($cid < 1) {
            return;
        }
        $ids = HfsFinance::memberIds($cid);
        if ($ids === []) {
            return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT email FROM users WHERE id IN ($ph) AND b2b_role IN ('approver','finance_manager')");
        $st->execute($ids);
        $seen = [];
        foreach ($st->fetchAll() as $row) {
            $e = trim((string) ($row['email'] ?? ''));
            if ($e === '' || isset($seen[$e]) || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$e] = true;
            self::fire('order_need_approval', $e, $vars);
        }
    }

    private static function payLabel(string $pay): string
    {
        return match ($pay) {
            'havale' => 'Havale / EFT',
            'acik_hesap' => 'Açık hesap',
            'bulut' => 'Kart',
            'kapida' => 'Kapıda ödeme',
            default => $pay !== '' ? $pay : '—',
        };
    }

    private static function returnLabel(string $st): string
    {
        return match ($st) {
            'yeni' => 'Yeni talep',
            'onay' => 'Onaylandı',
            'red' => 'Reddedildi',
            'tamam' => 'Tamamlandı',
            default => $st,
        };
    }
}
