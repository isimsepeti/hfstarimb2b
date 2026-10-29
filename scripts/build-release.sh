#!/usr/bin/env bash
# cPanel kurulumu için elle yüklenecek dosyaları üretir (kod GitHub'dan deploy edilir):
#   dist/b2b-uploads.zip       → ürün görselleri (git dışında)
#   dist/app.php               → config/app.php şablonu (yeni cron anahtarıyla)
#   dist/b2b-veritabani.sql    → verilen dump'ın her veritabanı adına yüklenebilir hali
# Kullanım: scripts/build-release.sh canli-dump.sql [cron_key]
set -euo pipefail
cd "$(dirname "$0")/.."
dump="$1"; key="${2:-$(openssl rand -hex 16)}"
mkdir -p dist && rm -f dist/b2b-uploads.zip dist/app.php dist/b2b-veritabani.sql dist/b2b-site.zip
(cd site && zip -qr -X ../dist/b2b-uploads.zip uploads -x '*.DS_Store')
sed "s/BURAYA_RASGELE_ANAHTAR/$key/" site/config/app.example.php > dist/app.php
scripts/clean-dump.sh "$dump" dist/b2b-veritabani.sql >/dev/null
ls -lh dist
