#!/usr/bin/env bash
# cPanel yedeğindeki dump'ı herhangi bir veritabanı adına içe aktarılabilir hale getirir
# (CREATE DATABASE / USE satırlarını çıkarır).
# Kullanım: scripts/clean-dump.sh girdi.sql cikti.sql
set -euo pipefail
in="$1"; out="$2"
grep -vE '^(CREATE DATABASE .*;|USE `[^`]+`;)$' "$in" > "$out"
echo "Yazıldı: $out ($(du -h "$out" | cut -f1))"
