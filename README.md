# HFS Tarım B2B (b2b.hfstarim.com)

Bayi sipariş sistemi. Saf PHP 8.4 + MySQL, framework yok. cPanel paylaşımlı hostingde
Apache + `.htaccess` ile çalışır; tek giriş noktası `site/index.php`.

```
site/            → sunucudaki public_html klasörünün içeriği (document root)
  app/           → uygulama sınıfları (web'den kapalı)
  cli/sync.php   → cron: Bulut/Bayt senkronu (web'den kapalı)
  config/        → app.php sunucuya özel, git'e girmez (örnek: app.example.php)
  themes/shop/   → bayi arayüzü,  yonetim/ → yönetim paneli
  uploads/       → ürün görselleri (git dışında)
docker/          → yerel geliştirme ortamı
scripts/         → içe aktarma / paketleme betikleri
```

## Yerel geliştirme

Gereken: Docker (Colima veya Docker Desktop).

```bash
docker compose up -d --build
scripts/dev-import.sh /yol/canli-dump.sql      # canlı veriyi yükler, entegrasyonları kapatır
open http://localhost:8088
```

- `site/config/app.php` yerel ayardır (`db` host: `db`, kullanıcı/şifre: `hfs`/`hfs`).
- `docker/dev-safe.sql` içe aktarmadan sonra Bayt, Bulut ödeme, SMTP ve Yurtiçi
  entegrasyonlarını kapatır: yerelde gerçek siparişe/ödemeye/e-postaya dokunulmaz.
- Görseller için canlıdaki `uploads/` klasörünü `site/uploads/` altına kopyalayın.
- Hata ayıklama: `touch site/storage/debug.on` → `site/storage/logs/php-errors.log`
- Cron'u elle çalıştırmak: `docker compose exec web php cli/sync.php`

## Canlıya alma (GitHub → cPanel)

İlk kurulum adım adım: [docs/KURULUM.md](docs/KURULUM.md)

1. cPanel > **Git Version Control** > Create: depo URL'si, yol `~/repositories/b2b`.
2. Değişiklikleri `main`'e push edin, cPanel'de **Update from Remote** → **Deploy HEAD Commit**.
   `.cpanel.yml` yalnızca `site/` klasörünü `~/public_html/` altına kopyalar;
   `config/app.php`, `uploads/`, oturumlar ve loglar korunur.
3. cPanel PHP sürüm seçicisi `.htaccess`'e kendi bloğunu eklerse o bloğu
   `site/.htaccess`'e de ekleyin, yoksa deploy bunu siler.

## Sunucu gereksinimleri

- PHP 8.4 (en az 8.2). Eklentiler: pdo_mysql, curl, mbstring, zip, gd, intl, xml (simplexml), iconv
- MySQL 8 / MariaDB 10.6+
- Cron (5 dk): `/usr/local/bin/php $HOME/public_html/cli/sync.php >/dev/null 2>&1`

## Entegrasyonlar

| Sistem | Ne için | Ayar |
|---|---|---|
| Bayt (ERP) | cari bakiye, sipariş/irsaliye/tahsilat aktarımı | Yönetim > Entegrasyon (`settings.bayt_json`) |
| BulutTahsilat | kartla ödeme, cari ödeme | `settings.bt_*` |
| SMTP | bildirim e-postaları | Yönetim > Ayarlar |

Bayt'a bağlanılamazsa (`HfsBayt::httpJson`) 2 dakika boyunca çağrılar beklemeden atlanır ve
sayfalar son bilinen bakiyeyle açılır; cron kuyruğu Bayt geri geldiğinde kaldığı yerden devam eder.
