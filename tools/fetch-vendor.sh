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
# náhľad Word/Excel a editor tabuliek
get chart.js@4.4.1/dist/chart.umd.min.js                       chartjs/chart.umd.min.js
get mammoth@1.8.0/mammoth.browser.min.js                     mammoth/mammoth.browser.min.js
get xlsx@0.18.5/dist/xlsx.full.min.js                         xlsx/xlsx.full.min.js
get exceljs@4.4.0/dist/exceljs.min.js                         exceljs/exceljs.min.js
# celé balíky z npm (priečinky s obrázkami a písmami)
npmget() { local tmp; tmp=$(mktemp -d); (cd "$tmp" && npm pack "$1" --silent >/dev/null && tar xzf ./*.tgz); mkdir -p "$D/$3"; cp -r "$tmp/package/$2/." "$D/$3/"; rm -rf "$tmp"; echo "  $3/ ($1)"; }
npmget luckysheet@2.1.13 dist luckysheet
# Luckysheet: bez zdrojových máp, ukážkových dát a ESM verzie; slovenčina, závislosti grafov a písmo zo servera (nie z cudzích CDN)
rm -rf "$D/luckysheet/demoData" "$D/luckysheet/index.html" "$D/luckysheet/"*.map "$D/luckysheet/luckysheet.esm.js" "$D/luckysheet/assets/iconfont/demo"*
python3 "$(dirname "$0")/patch-luckysheet.py" "$D/luckysheet" || exit 1
get vue@2.6.11/dist/vue.min.js                                 luckysheet-deps/vue.min.js
get vuex@3.4.0/dist/vuex.min.js                                luckysheet-deps/vuex.min.js
get element-ui@2.13.2/lib/index.js                             luckysheet-deps/element-ui.js
get element-ui@2.13.2/lib/theme-chalk/index.css                luckysheet-deps/element-ui.css
get element-ui@2.13.2/lib/theme-chalk/fonts/element-icons.woff luckysheet-deps/fonts/element-icons.woff
get element-ui@2.13.2/lib/theme-chalk/fonts/element-icons.ttf  luckysheet-deps/fonts/element-icons.ttf
get echarts@4.8.0/dist/echarts.min.js                          luckysheet-deps/echarts.min.js
get jszip@3.10.1/dist/jszip.min.js                             jszip/jszip.min.js
get qrcode-generator@1.4.4/qrcode.js                          qrcode/qrcode.js
get docx@9.8.1/dist/index.iife.js                             docx/docx.js
get pdfjs-dist@3.11.174/legacy/build/pdf.min.js               pdfjs/pdf.min.js
get pdfjs-dist@3.11.174/legacy/build/pdf.worker.min.js        pdfjs/pdf.worker.min.js
# zdrojové mapy nepotrebujeme (prehliadač by ich zbytočne hľadal)
find "$D" -name '*.css' -o -name '*.js' | xargs sed -i -E 's#/\*\# sourceMappingURL=[^*]*\*/##; s#//\# sourceMappingURL=.*$##'
echo "Hotovo: $D"
