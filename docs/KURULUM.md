# b2b.hfstarim.com — yeni cPanel'e kurulum

Kod GitHub'dan cPanel **Git Version Control** ile gelir; sonraki güncellemeler tek tıkla deploy
edilir. Elle yüklenen yalnızca git'te olmayanlardır: ürün görselleri, ayar dosyası ve veritabanı.

**Elle yüklenecek dosyalar** (yerelde `b2b/dist/`, `scripts/build-release.sh` üretir):

| Dosya | Nereye | Adım |
|---|---|---|
| `b2b-veritabani.sql` | phpMyAdmin → yeni veritabanı | 6 |
| `b2b-guvenlik.sql` | phpMyAdmin → aynı veritabanı, yukarıdakinden sonra | 6 |
| `b2b-uploads.zip` | `~/b2b.hfstarim.com/` içinde çıkar | 5 |
| `app.php` | `~/b2b.hfstarim.com/config/app.php` | 7 |

`KULLANICI` aşağıda yeni cPanel kullanıcı adınızdır.

---

## 1. PHP sürümü

cPanel → **Select PHP Version** (CloudLinux):

- Sürüm: **8.4**
- Eklentiler işaretli olmalı: `pdo_mysql`, `mysqlnd`, `curl`, `mbstring`, `zip`, `gd`, `intl`, `xml`, `simplexml`, `iconv`, `opcache`

## 2. Subdomain

cPanel → **Domains** → **Create A New Domain**

- Domain: `b2b.hfstarim.com`
- "Share document root" işaretini **kaldırın**
- Document Root: `b2b.hfstarim.com` (yani `/home/KULLANICI/b2b.hfstarim.com`)

> Farklı bir klasör seçerseniz depodaki `.cpanel.yml` içindeki `DEPLOYPATH` satırını ona göre değiştirin.

## 3. GitHub erişimi

**Depo private ise (önerilen):** cPanel → **Terminal**:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519 -N "" -C "hfstarim-cpanel"
cat ~/.ssh/id_ed25519.pub
```

Çıkan satırı GitHub → depo → **Settings → Deploy keys → Add deploy key** alanına yapıştırın
("Allow write access" **işaretsiz**). Sonra Terminal'de:

```bash
ssh -T git@github.com      # "Are you sure..." sorusuna yes; "successfully authenticated" görmelisiniz
```

Klon adresi: `git@github.com:isimsepeti/hfstarimb2b.git`

**Depo public ise:** anahtar gerekmez, klon adresi `https://github.com/isimsepeti/hfstarimb2b.git`.

## 4. Kodu çekip yayınlama

cPanel → **Git™ Version Control** → **Create**

- Clone a Repository: **açık**
- Clone URL: 3. adımdaki adres
- Repository Path: `repositories/hfstarimb2b`
- Repository Name: `hfstarimb2b`

Oluşunca **Manage** → **Pull or Deploy** sekmesi → **Deploy HEAD Commit**.

Kontrol: File Manager'da `b2b.hfstarim.com/` içinde `index.php`, `app/`, `themes/`, `cli/` görünmeli.

> Deploy, depodaki `.cpanel.yml`'ı çalıştırır: `site/` klasörünü `~/b2b.hfstarim.com/` altına kopyalar.
> `config/app.php`, `uploads/`, oturumlar ve loglar her deploy'da korunur.

## 5. Ürün görselleri

File Manager → `b2b.hfstarim.com/` → **Upload** → `b2b-uploads.zip` → sağ tık **Extract**
(hedef: `/b2b.hfstarim.com`). `uploads/2020`, `uploads/2026` vb. klasörler oluşmalı. Zip'i silin.

## 6. Veritabanı

cPanel → **MySQL® Database Wizard**

1. Veritabanı: `b2b` → `KULLANICI_b2b`
2. Kullanıcı: `b2b` → `KULLANICI_b2b`, güçlü bir şifre (not alın)
3. Yetki: **ALL PRIVILEGES**

cPanel → **phpMyAdmin** → soldan `KULLANICI_b2b` → **İçe Aktar**:

1. `b2b-veritabani.sql` → Git
2. Ardından `b2b-guvenlik.sql` → Git

`b2b-guvenlik.sql`: eski geliştiricinin `yenal` yönetici hesabını kilitler, yöneticilerin
"beni hatırla" oturumlarını iptal eder ve yeni yönetici hesabını (`abdullah`) ekler.

## 7. Ayar dosyası

File Manager → `b2b.hfstarim.com/config/` → `app.php`'yi yükleyin → **Edit**:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'KULLANICI_b2b',      // 6. adımdaki veritabanı
    'user' => 'KULLANICI_b2b',      // 6. adımdaki kullanıcı
    'pass' => '...',                // 6. adımdaki şifre
],
```

`cron_key` yeni üretildi, değiştirmeyin. İzinleri **600** yapın (sağ tık → Change Permissions).

## 8. Cron

cPanel → **Cron Jobs** → Common Settings: **Once Per Five Minutes** (`*/5 * * * *`), komut:

```
/usr/local/bin/php /home/KULLANICI/b2b.hfstarim.com/cli/sync.php >/dev/null 2>&1
```

Elle denemek için Terminal: `php ~/b2b.hfstarim.com/cli/sync.php` → 5 satır `phase=...` yazmalı.

> Eski sunucudaki `curl .../hfs-sync/?k=...` cron'u **kullanılmaz**.

## 9. DNS'i değiştirmeden test

Kendi bilgisayarınızda hosts dosyasına yeni sunucunun IP'sini ekleyin
(Mac: `sudo nano /etc/hosts`):

```
YENI.SUNUCU.IP   b2b.hfstarim.com
```

Tarayıcıda https://b2b.hfstarim.com açın. SSL henüz yok, uyarıyı geçin. Kontrol listesi:

- [ ] `/yonetim/` → `abdullah` ile giriş, siparişler listeleniyor
- [ ] Ana sayfa, kategori ve ürün görselleri, footer logosu görünüyor
- [ ] `/app/bootstrap.php`, `/config/app.php` → **403**
- [ ] Bir bayi hesabıyla giriş → Hesabım, Ekstre açılıyor

Test bitince hosts satırını silin.

## 10. Geçiş günü (canlı veriyi son haliyle taşıma)

Eski site sipariş almaya devam ettiği için DNS değişmeden hemen önce:

1. Eski cPanel → phpMyAdmin → `hfst8320_b2b` → **Dışa Aktar** (Hızlı, SQL)
2. Yeni phpMyAdmin → `KULLANICI_b2b` → tüm tabloları seç → **Kaldır (Drop)**
   (döküm `DROP TABLE` içermez; tablolar varken içe aktarma hata verir)
3. Yeni dökümü içe aktar → ardından **yine** `b2b-guvenlik.sql`
4. DNS: `b2b.hfstarim.com` için **A kaydını** yeni sunucu IP'sine çevir
   (hfstarim.com DNS'i nerede yönetiliyorsa orada; ana site ve e-posta kayıtlarına dokunmayın)
5. DNS yayıldıktan sonra cPanel → **SSL/TLS Status** → **Run AutoSSL**
6. Eski sunucuda b2b cron'unu silin (iki sunucu aynı anda Bulut/Bayt senkronu yapmasın)

## 11. Sonraki güncellemeler

```
yerelde geliştir → git commit → git push
cPanel → Git Version Control → Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit
```

## 12. Geçişten sonra

- **Bayt:** partner API sunucusu (`141.98.114.2:25066`) 23 Ekim'den beri erişilemiyor. HFS BT / Bayt
  bayisi sunucuyu ayağa kaldırmalı; IP kısıtlaması varsa yeni sunucunun IP'si izinli listeye eklenmeli.
  Kod değişikliği gerekmez; cron bekleyen işleri kendisi gönderir. Durum: Yönetim → Entegrasyon.
- Eski geliştiricinin bildiği şifreler değişmeli: Bulut Tahsilat ve Bayt API kullanıcıları, eski cPanel/FTP.

## Sorun giderme

| Belirti | Çözüm |
|---|---|
| Deploy "rsync: not found" | `.cpanel.yml`'da `/bin/rsync` yerine `/usr/bin/rsync` deneyin |
| Her sayfa 500 | PHP 8.4 ve 1. adımdaki eklentiler; hata için `b2b.hfstarim.com/error_log` |
| "config/app.php yok" | 7. adım; dosya adı tam `app.php` olmalı |
| Veritabanı bağlantı hatası | `app.php`'deki ad/kullanıcıda `KULLANICI_` öneki; kullanıcıya yetki verildi mi |
| Görseller yok | 5. adım: `uploads/2026/...` doğrudan `b2b.hfstarim.com/` altında olmalı |
| PHP sürümü deploy sonrası değişiyor | cPanel `.htaccess`'e handler bloğu eklediyse o bloğu depodaki `site/.htaccess`'e de ekleyin |
