<?php
declare(strict_types=1);

final class HfsCookies
{
    public const CONSENT_VERSION = 1;

    /** Çerez politikası sayfası yoksa oluşturur / günceller. */
    public static function ensurePolicyPage(): void
    {
        $slug = 'cerez-politikasi';
        $title = 'Çerez Politikası';
        $html = <<<'HTML'
<h2>1. Veri sorumlusu</h2>
<p>HFS Tarım (Has Fren Sistemleri) — Altınova Mah. Askı Sok. No: 10, Osmangazi / Bursa. Bayi portalı: b2b.hfstarim.com</p>
<h2>2. Çerez nedir?</h2>
<p>Çerezler, ziyaret ettiğiniz internet sitesi tarafından tarayıcınıza kaydedilen küçük metin dosyalarıdır. Oturumunuzu sürdürmek, sepetinizi hatırlamak ve siteyi güvenli kullanmanızı sağlamak için kullanılır.</p>
<h2>3. Kullandığımız çerez türleri</h2>
<ul>
<li><strong>Zorunlu:</strong> Giriş oturumu, güvenlik (CSRF), sepet ve “beni hatırla” gibi portalın çalışması için şart çerezler. Kapatılamaz.</li>
<li><strong>İşlevsel:</strong> Dil/tercih, form alanlarını hatırlama, terk edilen sepet hatırlatması (e-posta verdiyseniz) gibi isteğe bağlı kolaylıklar.</li>
<li><strong>Performans / analitik:</strong> Hangi sayfaların kullanıldığını anonim istatistikle anlamak (şu an yalnızca açık onayınızla devreye alınır).</li>
<li><strong>Hedefleme / reklam:</strong> Bayi portalında üçüncü taraf reklam çerezi kullanmıyoruz; bu kategori ileride entegrasyon olursa yine onayınıza tabidir.</li>
</ul>
<h2>4. Saklama süreleri</h2>
<p>Oturum çerezleri tarayıcıyı kapatınca veya oturum bitince silinir. Sepet ve tercih çerezleri en fazla 30 gün; “beni hatırla” en fazla 20 yıl (siz çıkış yapana kadar geçerli). Çerez tercih kaydınız 12 ay saklanır.</p>
<h2>5. Haklarınız</h2>
<p>6698 sayılı KVKK kapsamında kişisel verilerinize ilişkin taleplerinizi <a href="mailto:info@hfstarim.com">info@hfstarim.com</a> veya <a href="/iletisim/">iletişim formu</a> üzerinden iletebilirsiniz. Aydınlatma metni için <a href="/privacy/">Gizlilik</a> sayfasına bakın.</p>
<h2>6. Tercihlerinizi değiştirme</h2>
<p>Sayfanın altındaki «Çerez tercihleri» bağlantısından paneli yeniden açabilirsiniz.</p>
HTML;
        $seo = 'HFS Tarım bayi portalında kullanılan çerez türleri, saklama süreleri ve tercih yönetimi.';
        try {
            $st = db()->prepare('SELECT id FROM pages WHERE slug=? LIMIT 1');
            $st->execute([$slug]);
            $id = $st->fetchColumn();
            if ($id) {
                db()->prepare('UPDATE pages SET title=?, content=?, seo_title=?, seo_description=?, modified_at=NOW() WHERE id=?')
                    ->execute([$title, $html, $title . ' — HFS Tarım Bayi', $seo, (int) $id]);
                return;
            }
            db()->prepare("INSERT INTO pages (slug,title,content,seo_title,seo_description,status,modified_at) VALUES (?,?,?,?,?, 'publish', NOW())")
                ->execute([$slug, $title, $html, $title . ' — HFS Tarım Bayi', $seo]);
        } catch (Throwable) {
        }
    }
}
