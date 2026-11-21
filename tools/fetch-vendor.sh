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
get jspreadsheet-ce@4.13.4/dist/index.min.js                  jspreadsheet/jspreadsheet.js
get jspreadsheet-ce@4.13.4/dist/jspreadsheet.min.css          jspreadsheet/jspreadsheet.css
get jsuites@4.17.7/dist/jsuites.min.js                        jspreadsheet/jsuites.js
get jsuites@4.17.7/dist/jsuites.min.css                       jspreadsheet/jsuites.css
get @formulajs/formulajs@4.4.9/lib/browser/formula.min.js     jspreadsheet/formula.js
# celé balíky z npm (priečinky s obrázkami a písmami)
npmget() { local tmp; tmp=$(mktemp -d); (cd "$tmp" && npm pack "$1" --silent >/dev/null && tar xzf ./*.tgz); mkdir -p "$D/$3"; cp -r "$tmp/package/$2/." "$D/$3/"; rm -rf "$tmp"; echo "  $3/ ($1)"; }
npmget luckysheet@2.1.13 dist luckysheet
npmget luckyexcel@1.0.1 dist luckyexcel
# grafy v tabuľkách (Luckysheet ich inak načítava z cudzích serverov)
get vue@2.6.11/dist/vue.min.js                                 luckysheet-deps/vue.min.js
get vuex@3.4.0/dist/vuex.min.js                                luckysheet-deps/vuex.min.js
get element-ui@2.13.2/lib/index.js                             luckysheet-deps/element-ui.js
get element-ui@2.13.2/lib/theme-chalk/index.css                luckysheet-deps/element-ui.css
get element-ui@2.13.2/lib/theme-chalk/fonts/element-icons.woff luckysheet-deps/fonts/element-icons.woff
get element-ui@2.13.2/lib/theme-chalk/fonts/element-icons.ttf  luckysheet-deps/fonts/element-icons.ttf
get echarts@4.8.0/dist/echarts.min.js                          luckysheet-deps/echarts.min.js
get jszip@3.10.1/dist/jszip.min.js                             jszip/jszip.min.js
# zdrojové mapy nepotrebujeme (prehliadač by ich zbytočne hľadal)
find "$D" -name '*.css' -o -name '*.js' | xargs sed -i -E 's#/\*\# sourceMappingURL=[^*]*\*/##; s#//\# sourceMappingURL=.*$##'
# Jspreadsheet pri prvom spustení načíta logo z bossanova.uk – Cloud nechodí na cudzie servery
sed -i 's#!sessionStorage.getItem("jexcel")#!1#' "$D/jspreadsheet/jspreadsheet.js"
grep -q 'sessionStorage&&!1' "$D/jspreadsheet/jspreadsheet.js" || { echo "POZOR: logo Jspreadsheet sa nepodarilo vypnúť"; exit 1; }
echo "Hotovo: $D"
