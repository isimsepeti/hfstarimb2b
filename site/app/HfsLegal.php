<?php
declare(strict_types=1);

final class HfsLegal
{
    public const KVKK_VERSION = '2026-1';
    public const TERMS_VERSION = '2026-1';

    public static function ensureLegalPages(): void
    {
        self::upsertPage('privacy', 'KVKK Aydınlatma Metni', self::privacyHtml(), 'KVKK Aydınlatma — HFS Tarım Bayi', 'Has Fren Sistemleri (HFS Tarım) bayi portalında kişisel verilerin işlenmesi, amaçlar ve haklarınız.');
        self::upsertPage('terms', 'Bayi sipariş ve ödeme koşulları', self::termsHtml(), 'Bayi sipariş koşulları — HFS Tarım', 'b2b.hfstarim.com üzerinden verilen bayi siparişleri, ödeme, kargo, açık hesap ve iade kuralları.');
    }

    /** POST’ta zorunlu onay kutuları; hata metni veya null. */
    public static function consentErrorFromPost(array $post): ?string
    {
        if (empty($post['consent_kvkk'])) {
            return 'Siparişi tamamlamak için KVKK aydınlatma metnini okuduğunuzu onaylamanız gerekir.';
        }
        if (empty($post['consent_terms'])) {
            return 'Siparişi tamamlamak için bayi sipariş ve ödeme koşullarını kabul etmeniz gerekir.';
        }
        return null;
    }

    /** Modal ön izleme (ödeme onay linkleri). */
    public static function contentForModal(string $slug): ?array
    {
        if ($slug === 'privacy') {
            return [
                'title' => 'KVKK Aydınlatma Metni',
                'html' => self::privacyHtml(),
                'url' => site_url('privacy/'),
            ];
        }
        if ($slug === 'terms') {
            return [
                'title' => 'Bayi sipariş ve ödeme koşulları',
                'html' => self::termsHtml(),
                'url' => site_url('terms/'),
            ];
        }
        return null;
    }

    public static function stampOrderByCode(string $code): void
    {
        HfsMigrate::up();
        try {
            db()->prepare(
                'UPDATE orders SET consent_kvkk_at=NOW(), consent_terms_at=NOW(), consent_kvkk_ver=?, consent_terms_ver=? WHERE code=? LIMIT 1'
            )->execute([self::KVKK_VERSION, self::TERMS_VERSION, $code]);
        } catch (Throwable) {
        }
    }

    private static function upsertPage(string $slug, string $title, string $html, string $seoTitle, string $seoDesc): void
    {
        try {
            $st = db()->prepare('SELECT id FROM pages WHERE slug=? LIMIT 1');
            $st->execute([$slug]);
            $id = $st->fetchColumn();
            if ($id) {
                db()->prepare('UPDATE pages SET title=?, content=?, seo_title=?, seo_description=?, modified_at=NOW() WHERE id=?')
                    ->execute([$title, $html, $seoTitle, $seoDesc, (int) $id]);
                return;
            }
            db()->prepare("INSERT INTO pages (slug,title,content,seo_title,seo_description,status,modified_at) VALUES (?,?,?,?,?, 'publish', NOW())")
                ->execute([$slug, $title, $html, $seoTitle, $seoDesc]);
        } catch (Throwable) {
        }
    }

    private static function privacyHtml(): string
    {
        return <<<'HTML'
<h2>1. Veri sorumlusu</h2>
<p><strong>Has Fren Sistemleri (HFS Tarım)</strong><br>
Altınova Mah. Askı Sok. No: 10, Osmangazi / Bursa<br>
Tel: <a href="tel:+902242161601">0224 216 16 01</a> · E-posta: <a href="mailto:info@hfstarim.com">info@hfstarim.com</a>, <a href="mailto:hasfrensistemleri@gmail.com">hasfrensistemleri@gmail.com</a><br>
Bayi portalı: <a href="https://b2b.hfstarim.com">b2b.hfstarim.com</a></p>
<h2>2. İşlenen kişisel veriler</h2>
<p>Bayi hesabınız, sipariş ve tahsilat süreçlerinde; ad soyad / unvan, vergi kimlik numarası, e-posta, telefon, teslimat ve fatura adresi, sipariş kalemleri, tutarlar, ödeme yöntemi, cari bakiye ve limit bilgileri, IP adresi, oturum ve güvenlik kayıtları ile (kart ile ödemede) işlem sonucu ve dekont referansları işlenebilir. Kart numarası HFS sunucularında saklanmaz; kart tahsilatı BulutTahsilat güvenli ödeme sayfasında alınır.</p>
<h2>3. Amaç ve hukuki sebep</h2>
<ul>
<li>Bayi sözleşmesi ve siparişin ifası (sözleşmenin kurulması veya ifası — KVKK m.5/2-c, m.5/2-e)</li>
<li>Cari hesap, açık hesap limiti, tahsilat ve muhasebe kayıtları (hukuki yükümlülük ve meşru menfaat)</li>
<li>ERP / stok entegrasyonu (Bayt vb.) ile sipariş ve sevkiyatın yürütülmesi</li>
<li>Destek, iade (RMA) ve yasal yükümlülüklerin yerine getirilmesi</li>
<li>Portal güvenliği, dolandırıcılık önleme ve log tutma</li>
</ul>
<h2>4. Aktarım</h2>
<p>Veriler; ödeme kuruluşu (BulutTahsilat), anlaşmalı kargo firması, muhasebe/ERP sağlayıcıları ve yalnızca hizmet için zorunlu teknik altyapı (barındırma, e-posta) ile sınırlı paylaşılabilir. Yurt dışına aktarım, açık rızanız veya KVKK’daki istisnai haller dışında yapılmaz.</p>
<h2>5. Saklama süresi</h2>
<p>Ticari ve vergi mevzuatı gereği sipariş ve tahsilat kayıtları genel olarak 10 yıla kadar; oturum ve güvenlik logları makul sürelerle; pazarlama amaçlı veri işlenmez (bayi portalı B2B operasyon içindir).</p>
<h2>6. Haklarınız</h2>
<p>6698 sayılı KVKK kapsamında; verilerinizin işlenip işlenmediğini öğrenme, düzeltme, silme, itiraz ve şikâyet haklarına sahipsiniz. Taleplerinizi yukarıdaki iletişim kanallarından veya <a href="/iletisim/">iletişim formu</a> üzerinden iletebilirsiniz.</p>
<p>Çerezler için <a href="/cerez-politikasi/">Çerez Politikası</a> sayfasına bakın.</p>
HTML;
    }

    private static function termsHtml(): string
    {
        return <<<'HTML'
<h2>1. Taraflar ve kapsam</h2>
<p>Bu koşullar, <strong>Has Fren Sistemleri (HFS Tarım)</strong> ile b2b.hfstarim.com bayi portalında giriş yapmış bayiler arasında verilen siparişler ve ödemeler için geçerlidir. Fiyatlar, stok ve kampanyalar yalnızca yetkili bayi hesabınıza özeldir; üçüncü kişilere devredilemez.</p>
<h2>2. Sipariş ve onay</h2>
<p>Sepete eklenen ürünler, ödeme adımında onayladığınız teslimat bilgileri ve tutarla siparişe dönüşür. HFS, stok, üretim veya entegrasyon kaynaklı nadir hatalarda siparişi kısmen/tamamen iptal edebilir veya süreyi güncelleyebilir; bu durumda bilgilendirme yapılır.</p>
<h2>3. Ödeme yöntemleri</h2>
<ul>
<li><strong>Havale / EFT:</strong> Sipariş sonrası bildirilen IBAN’a transfer. Ödeme teyidi sonrası sevkiyat süreci başlar.</li>
<li><strong>Açık hesap:</strong> Firmanıza tanımlı kredi limiti dahilinde cari bakiyeye işlenir; vade ve faturalama bayi sözleşmenize göre yürür.</li>
<li><strong>Kredi kartı:</strong> BulutTahsilat güvenli ödeme sayfasında alınır; kart bilgisi HFS’te tutulmaz.</li>
<li><strong>Cari tahsilat:</strong> Sipariş dışı kart ile cari hesaba ödeme yatırma işlemleri aynı tahsilat altyapısı ile yapılır.</li>
</ul>
<h2>4. Kargo</h2>
<p>Sevkiyat anlaşmalı kargo ile yapılır. Portalde görünen kargo bedeli <strong>alıcı ödemeli</strong> anlamına gelir; teslimatta kargo ücreti alıcıya yansıtılabilir. Teslimat adresinin doğruluğu bayinin sorumluluğundadır.</p>
<h2>5. İade ve ayıplı mal</h2>
<p>İade ve RMA talepleri portal üzerinden veya müşteri temsilciniz aracılığıyla iletilir. Ürünün kullanım kılavuzuna uygun montaj ve kullanım şartları geçerlidir. Detaylar için <a href="/kargo-iade/">Kargo ve iade</a> sayfasına bakın.</p>
<h2>6. Entegrasyon</h2>
<p>Sipariş ve stok bilgileri operasyonel amaçla ERP (ör. Bayt) ile senkronize edilebilir. Ödeme durumu ve cari hareketler muhasebe kayıtlarına yansır.</p>
<h2>7. Uyuşmazlık</h2>
<p>Türk hukuku uygulanır. İhtilaflarda Bursa mahkemeleri ve icra daireleri yetkilidir.</p>
<p>Kişisel veriler için <a href="/privacy/">KVKK aydınlatma metni</a> geçerlidir.</p>
HTML;
    }
}
