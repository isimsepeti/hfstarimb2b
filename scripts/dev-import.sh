#!/usr/bin/env bash
# Canlı dump'ı yerel Docker veritabanına aktarır ve entegrasyonları kapatır.
# Kullanım: scripts/dev-import.sh ../full-backup/mysql/hfst8320_b2b.sql
set -euo pipefail
cd "$(dirname "$0")/.."
dump="$1"
tmp="$(mktemp)"
scripts/clean-dump.sh "$dump" "$tmp" >/dev/null
docker compose exec -T db mysql -uroot -proot -e 'DROP DATABASE IF EXISTS hfs_b2b; CREATE DATABASE hfs_b2b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON hfs_b2b.* TO "hfs"@"%";'
docker compose exec -T db mysql -uroot -proot hfs_b2b < "$tmp"
docker compose exec -T db mysql -uroot -proot hfs_b2b < docker/dev-safe.sql
rm -f "$tmp"
echo "İçe aktarıldı. Entegrasyonlar yerelde kapalı."
