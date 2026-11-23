#!/usr/bin/env python3
"""Úprava Luckysheet pre Cloud (spúšťa tools/fetch-vendor.sh po stiahnutí knižnice):
 - závislosti grafov a písmo zo servera, nie z cudzích CDN,
 - slovenské texty (anglická jazyková verzia sa prepíše na slovenskú),
 - slovenské formáty čísel (€, dátum d.m.yyyy), predvolené písmo Arial.
Spustenie: python3 tools/patch-luckysheet.py server/assets/vendor/luckysheet
"""
import re, sys, json, os

D = sys.argv[1] if len(sys.argv) > 1 else 'server/assets/vendor/luckysheet'
JS = os.path.join(D, 'luckysheet.umd.js')
CSS = os.path.join(D, 'plugins/css/pluginsCss.css')
s = open(JS, encoding='utf-8').read()
def must(cond, what):
    if not cond:
        print('POZOR: Luckysheet – nenašiel som: ' + what); sys.exit(1)

# panel nástrojov Cloudu vľavo od panela Luckysheet (hľadanie, tlač, priblíženie) – tlačidlo „Viac“ musí rátať s menšou šírkou
if 'LS_TB_OFFSET' not in s:
    m = re.search(r'for\(var (\w)=(\w)\.length-1;\1>=0;\1--\)if\(\2\[\1\]<(\w+)-90\)', s)
    must(m, 'šírka panela nástrojov')
    s = s[:m.start()] + m.group(0).replace(m.group(3) + '-90)', m.group(3) + '-90-(window.LS_TB_OFFSET||0))') + s[m.end():]
    if 'cloud-sk-patched' in s: open(JS, 'w', encoding='utf-8').write(s)

if 'cloud-sk-patched' in s:
    print('  luckysheet: už upravené'); sys.exit(0)

# ---------- 1. cudzie servery ----------
for a, b in [('https://cdn.jsdelivr.net/npm/vue@2.6.11', 'assets/vendor/luckysheet-deps/vue.min.js'),
             ('https://unpkg.com/vuex@3.4.0', 'assets/vendor/luckysheet-deps/vuex.min.js'),
             ('https://cdn.bootcdn.net/ajax/libs/element-ui/2.13.2/index.js', 'assets/vendor/luckysheet-deps/element-ui.js'),
             ('https://cdn.bootcdn.net/ajax/libs/echarts/4.8.0/echarts.min.js', 'assets/vendor/luckysheet-deps/echarts.min.js'),
             ('https://cdn.bootcdn.net/ajax/libs/element-ui/2.13.2/theme-chalk/index.css', 'assets/vendor/luckysheet-deps/element-ui.css')]:
    s = s.replace(a, b)
s = s.replace('"expendPlugins/chart/', '"assets/vendor/luckysheet/expendPlugins/chart/')

# výber farby si nemá pamätať posledné farby v prehliadači (Cloud nič neukladá do zariadenia)
must('localStorageKey:"spectrum.' in s, 'výber farby')
s = s.replace('localStorageKey:"spectrum.', 'localStorageKey:!1&&"spectrum.')

# ---------- 2. formáty čísel a písmo ----------
EN_FMT = 'defaultFmt:[{text:"Automatic",value:"General",example:""},{text:"Plain text",value:"@",example:""},{text:"",value:"split",example:""},{text:"Number",value:"##0.00",example:"1000.12"},{text:"Percent",value:"#0.00%",example:"12.21%"},{text:"Scientific",value:"0.00E+00",example:"1.01E+5"},{text:"",value:"split",example:""},{text:"Accounting",value:"¥(0.00)",example:"¥(1200.09)"},{text:"Currency",value:"¥0.00",example:"¥1200.09"},{text:"",value:"split",example:""},{text:"Date",value:"yyyy-MM-dd",example:"2017-11-29"},{text:"Time",value:"hh:mm AM/PM",example:"3:00 PM"},{text:"Time 24H",value:"hh:mm",example:"15:00"},{text:"Date time",value:"yyyy-MM-dd hh:mm AM/PM",example:"2017-11-29 3:00 PM"},{text:"Date time 24 H",value:"yyyy-MM-dd hh:mm",example:"2017-11-29 15:00"},{text:"",value:"split",example:""},{text:"Custom formats",value:"fmtOtherSelf",example:"more"}]'
SK_FMT = ('defaultFmt:[{text:"Automaticky",value:"General",example:""},{text:"Obyčajný text",value:"@",example:""},{text:"",value:"split",example:""},'
          '{text:"Číslo",value:"#,##0.00",example:"1 000,12"},{text:"Celé číslo",value:"#,##0",example:"1 000"},{text:"Percentá",value:"0.00%",example:"12,21 %"},{text:"Vedecký zápis",value:"0.00E+00",example:"1,01E+05"},{text:"",value:"split",example:""},'
          '{text:"Mena (€)",value:"#,##0.00 \\u20ac",example:"1 200,09 €"},{text:"Mena bez centov",value:"#,##0 \\u20ac",example:"1 200 €"},{text:"",value:"split",example:""},'
          '{text:"Dátum",value:"d\\\\.m\\\\.yyyy",example:"29.11.2017"},{text:"Dátum (dd.mm.rrrr)",value:"dd\\\\.mm\\\\.yyyy",example:"29.11.2017"},{text:"Čas",value:"h:mm",example:"15:00"},{text:"Čas so sekundami",value:"h:mm:ss",example:"15:00:30"},'
          '{text:"Dátum a čas",value:"d\\\\.m\\\\.yyyy h:mm",example:"29.11.2017 15:00"},{text:"Trvanie",value:"[h]:mm:ss",example:"26:15:00"},{text:"",value:"split",example:""},{text:"Vlastné formáty",value:"fmtOtherSelf",example:"more"}]')
must(EN_FMT in s, 'zoznam formátov')
s = s.replace(EN_FMT, SK_FMT, 1)
EN_DATES = 'dateFmtList:[{name:"1930-08-05",value:"yyyy-MM-dd"},{name:"1930/8/5",value:"yyyy/MM/dd"},{name:"08-05",value:"MM-dd"},{name:"8-5",value:"M-d"},{name:"13:30:30",value:"h:mm:ss"},{name:"13:30",value:"h:mm"},{name:"PM 01:30",value:"AM/PM hh:mm"},{name:"PM 1:30",value:"AM/PM h:mm"},{name:"PM 1:30:30",value:"AM/PM h:mm:ss"},{name:"08-05 PM 01:30",value:"MM-dd AM/PM hh:mm"}]'
SK_DATES = 'dateFmtList:[{name:"5.8.1930",value:"d\\\\.m\\\\.yyyy"},{name:"05.08.1930",value:"dd\\\\.mm\\\\.yyyy"},{name:"5. 8. 1930",value:"d\\\\. m\\\\. yyyy"},{name:"5.8.",value:"d\\\\.m\\\\."},{name:"1930-08-05",value:"yyyy-MM-dd"},{name:"13:30:30",value:"h:mm:ss"},{name:"13:30",value:"h:mm"},{name:"5.8.1930 13:30",value:"d\\\\.m\\\\.yyyy h:mm"}]'
if EN_DATES in s: s = s.replace(EN_DATES, SK_DATES, 1)
must('"ct","¥ #.00"' in s, 'tlačidlo meny')
s = s.replace('"ct","¥ #.00"', '"ct","#,##0.00 \\u20ac"')
s = s.replace('"ct","#0.00%"', '"ct","0.00%"')
EN_FONT = 'fontarray:["Times New Roman","Arial","Tahoma","Verdana"],fontjson:{"times new roman":0,arial:1,tahoma:2,verdana:3}'
must(EN_FONT in s, 'zoznam písiem')
s = s.replace(EN_FONT, 'fontarray:["Arial","Times New Roman","Tahoma","Verdana","Calibri","Courier New","Georgia"],fontjson:{arial:0,"times new roman":1,tahoma:2,verdana:3,calibri:4,"courier new":5,georgia:6}', 1)
s = s.replace('defualtFont:["Times New Roman","Arial",', 'defualtFont:["Arial","Times New Roman",', 1)

# ---------- 3. slovenské texty ----------
SK = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'luckysheet-sk.json'), encoding='utf-8'))
i0 = s.find('{en:{functionlist:[')
must(i0 > 0, 'anglická jazyková verzia')
ui0 = s.find('}],toolbar:{undo:"Undo"', i0)
end = s.find('},zh:{functionlist:[', ui0)
must(ui0 > 0 and end > 0, 'texty rozhrania')
seg = s[ui0:end]
cnt = 0
def rep(m):
    global cnt
    t = m.group(1)
    if t in SK['ui']:
        cnt += 1
        return '"' + SK['ui'][t].replace('\\', '\\\\').replace('"', '\\"') + '"'
    return m.group(0)
seg = re.sub(r'"((?:[^"\\]|\\.)*)"', rep, seg)
# popisy funkcií (nápoveda pri písaní vzorca)
fl = s[i0:ui0]
fcnt = 0
for name, d in SK['functions'].items():
    m = re.search(r'\{n:"' + re.escape(name) + r'",t:[^,]+,d:"((?:[^"\\]|\\.)*)",a:"((?:[^"\\]|\\.)*)"', fl)
    if not m: continue
    q = d.replace('"', '\\"')
    fl = fl[:m.start(1)] + q + fl[m.end(1):m.start(2)] + q + fl[m.end(2):]
    fcnt += 1
s = s[:i0] + fl + seg + s[end:]
# texty mimo jazykových verzií (napr. orezanie obrázka je po čínsky)
for a, b in SK['global'].items():
    s = s.replace(a, b)
s = '/*cloud-sk-patched*/' + s
open(JS, 'w', encoding='utf-8').write(s)

c = open(CSS, encoding='utf-8').read()
c = re.sub(r"url\((['\"]?)(https?:)?//at\.alicdn\.com/[^)]*\)", 'url(data:,)', c)
open(CSS, 'w', encoding='utf-8').write(c)
if re.search(r'cdn\.jsdelivr|unpkg\.com|bootcdn|alicdn', s + c):
    print('POZOR: Luckysheet stále odkazuje na cudzí server'); sys.exit(1)
CM = os.path.join(D, 'expendPlugins/chart/chartmix.umd.min.js')
if os.path.exists(CM):
    m = open(CM, encoding='utf-8').read()
    m = m.replace('"默认标题"', '"Graf"').replace('"默认副标题"', '""')
    open(CM, 'w', encoding='utf-8').write(m)
print('  luckysheet: %d textov a %d funkcií po slovensky' % (cnt, fcnt))
