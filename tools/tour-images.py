#!/usr/bin/env python3
"""Výrezy obrazoviek pre rýchly sprievodcu (assets/help/tour-N.jpg) zo snímok príručky (vetva screens → help/*.png).
Použitie: python3 tools/tour-images.py <priečinok so snímkami .png>"""
import sys
from PIL import Image, ImageDraw
src = sys.argv[1].rstrip('/') + '/'
out = __file__.rsplit('/tools/', 1)[0] + '/server/assets/help/'
R = 2.65  # pomer strán obrázka v sprievodcovi
crops = {0: ('u-disk', 250, 60, 1030), 1: ('u-novy', 0, 40, 760), 2: ('u-disk-zoznam', 250, 60, 1030), 3: ('u-kontext', 330, 60, 900),
         4: ('u-zdielanie', 230, 150, 830), 5: ('u-hladanie-vysledky', 250, 0, 1030), 6: ('u-fotky', 256, 80, 1000),
         7: ('u-verzie', 300, 180, 700), 8: ('u-zamknuta', 330, 230, 650), 10: ('u-ucty', 560, 0, 720)}
for n, (name, x, y, w) in crops.items():
    im = Image.open(src + name + '.png').convert('RGB')
    h = round(w / R)
    im.crop((x, y, x + w, y + h)).save(f'{out}tour-{n}.jpg', quality=85, optimize=True, progressive=True)
# krok 9: dva telefóny vedľa seba (horná časť obrazovky)
W, H = 760, 287
canvas = Image.new('RGB', (W, H), (232, 240, 254))
for i, name in enumerate(['m-disk', 'm-fotky']):
    ph = Image.open(src + name + '.png').convert('RGB')
    pw = 250; ph = ph.resize((pw, round(ph.height * pw / ph.width)), Image.LANCZOS).crop((0, 0, pw, H - 22))
    fx = W // 2 - pw - 20 + i * (pw + 40)
    frame = Image.new('RGB', (pw + 16, H - 14), (32, 33, 36))
    canvas.paste(frame, (fx - 8, 14)); canvas.paste(ph, (fx, 22))
canvas.save(f'{out}tour-9.jpg', quality=85, optimize=True, progressive=True)
print('hotovo')
