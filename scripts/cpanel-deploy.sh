#!/usr/bin/env bash
# site/ klasörünü canlı document root'a kopyalar. .cpanel.yml bunu çağırır; Terminal'den elle de çalışır:
#   cd ~/repositories/hfstarimb2b && git pull && bash scripts/cpanel-deploy.sh
# CloudLinux/CageFS'te rsync olmadığı için yalnızca cp/find/rm kullanır.
# Sunucuya özel dosyalar (config/app.php, uploads, oturumlar, loglar, cPanel php ayarları) korunur;
# depodan silinen dosyalar canlıdan da silinir.
set -euo pipefail
cd "$(dirname "$0")/.."
SRC="$(pwd)/site"
DEPLOYPATH="${DEPLOYPATH:-$HOME/public_html/}"
DEPLOYPATH="${DEPLOYPATH%/}"

protected() {
  case "$1" in
    config/app.php | uploads/20* | storage/sessions/* | storage/logs/* | storage/cache/* | \
    storage/uploads/reviews/* | data/cache/* | .well-known | .well-known/* | cgi-bin | cgi-bin/* | \
    .user.ini | php.ini | error_log | */error_log) return 0 ;;
  esac
  return 1
}

mkdir -p "$DEPLOYPATH"
cp -R "$SRC/." "$DEPLOYPATH/"

cd "$DEPLOYPATH"
removed=0
while IFS= read -r -d '' f; do
  rel="${f#./}"
  if ! protected "$rel" && [ ! -e "$SRC/$rel" ]; then
    rm -f -- "$f"
    removed=$((removed + 1))
  fi
done < <(find . -type f -print0)
while IFS= read -r -d '' d; do
  rel="${d#./}"
  if ! protected "$rel" && [ ! -d "$SRC/$rel" ]; then
    rmdir -- "$d" 2>/dev/null || true
  fi
done < <(find . -mindepth 1 -depth -type d -print0)

cd "$SRC/.."
echo "Deploy tamam: $(git rev-parse --short HEAD 2>/dev/null || echo '?') -> $DEPLOYPATH/ (silinen eski dosya: $removed)"
