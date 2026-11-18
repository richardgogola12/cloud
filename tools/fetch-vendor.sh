#!/usr/bin/env bash
# Stiahne knižnice (Bootstrap, ikony, jQuery, Select2) do server/assets/vendor,
# aby sa stránka nenačítavala z cudzích serverov (rýchlejšie, funguje aj bez internetu).
set -euo pipefail
D="$(cd "$(dirname "$0")/.." && pwd)/server/assets/vendor"
CDN="https://cdn.jsdelivr.net/npm"
get() { mkdir -p "$D/$(dirname "$2")"; curl -fsSL --retry 3 "$CDN/$1" -o "$D/$2"; echo "  $2"; }
rm -rf "$D"; mkdir -p "$D"
get bootstrap@5.3.3/dist/css/bootstrap.min.css               bootstrap/bootstrap.min.css
get bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js          bootstrap/bootstrap.bundle.min.js
get bootstrap-icons@1.11.3/font/bootstrap-icons.min.css      bootstrap-icons/bootstrap-icons.min.css
get bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2  bootstrap-icons/fonts/bootstrap-icons.woff2
get bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff   bootstrap-icons/fonts/bootstrap-icons.woff
get jquery@3.7.1/dist/jquery.min.js                          jquery/jquery.min.js
get select2@4.1.0-rc.0/dist/css/select2.min.css              select2/select2.min.css
get select2@4.1.0-rc.0/dist/js/select2.min.js                select2/select2.min.js
get select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css select2/select2-bootstrap-5-theme.min.css
# zdrojové mapy nepotrebujeme (prehliadač by ich zbytočne hľadal)
find "$D" -name '*.css' -o -name '*.js' | xargs sed -i -E 's#/\*\# sourceMappingURL=[^*]*\*/##; s#//\# sourceMappingURL=.*$##'
echo "Hotovo: $D"
