#!/usr/bin/env bash
# cPanel'e elle kurulum paketi üretir: dist/b2b-site.zip (+ varsa veritabanı)
# Kullanım: scripts/build-release.sh [canli-dump.sql]
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/stage
rsync -a site/ dist/stage/ \
  --exclude 'config/app.php' --exclude '.DS_Store' \
  --exclude 'storage/sessions/*' --exclude 'storage/logs/*' --exclude 'storage/cache/*' --exclude 'data/cache/*' \
  --include '.gitkeep'
cp site/config/app.example.php dist/stage/config/app.php
sed -i.bak "s/BURAYA_RASGELE_ANAHTAR/$(openssl rand -hex 16)/" dist/stage/config/app.php && rm dist/stage/config/app.php.bak
find dist/stage -type d -exec chmod 755 {} + && find dist/stage -type f -exec chmod 644 {} +
(cd dist/stage && zip -qr -X ../b2b-site.zip .)
rm -rf dist/stage
if [ "${1:-}" != "" ]; then
  scripts/clean-dump.sh "$1" dist/b2b-veritabani.sql >/dev/null
fi
ls -lh dist
