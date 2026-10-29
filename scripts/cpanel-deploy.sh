#!/usr/bin/env bash
# site/ klasörünü canlı document root'a kopyalar. .cpanel.yml bunu çağırır; cPanel deploy
# kuyrukta kalırsa Terminal'den elle de çalıştırılabilir:
#   cd ~/repositories/hfstarimb2b && git pull && bash scripts/cpanel-deploy.sh
# Sunucuya özel dosyalar (config/app.php, uploads, oturumlar, loglar, cPanel php ayarları) korunur.
set -euo pipefail
cd "$(dirname "$0")/.."
DEPLOYPATH="${DEPLOYPATH:-$HOME/public_html/}"
RSYNC="$(command -v rsync || echo /usr/bin/rsync)"
"$RSYNC" -rlptD --delete \
  --exclude 'config/app.php' --exclude 'uploads/20*/' \
  --exclude 'storage/sessions/*' --exclude 'storage/logs/*' --exclude 'storage/cache/*' \
  --exclude 'storage/uploads/reviews/*' --exclude 'data/cache/*' \
  --exclude '.well-known/' --exclude 'cgi-bin/' --exclude '.user.ini' --exclude 'php.ini' --exclude 'error_log' \
  site/ "$DEPLOYPATH"
echo "Deploy tamam: $(git rev-parse --short HEAD 2>/dev/null || echo '?') -> $DEPLOYPATH"
