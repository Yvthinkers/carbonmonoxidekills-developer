#!/usr/bin/env python3
"""Renders the social share image and PNG icons into v3/assets/ (needs Playwright + Chromium and Pillow).

  og-image.jpg          1200x630  Open Graph / Twitter Card image
  icon-512.png          512x512   logo for Organization structured data
  apple-touch-icon.png  180x180   iOS home screen icon
  favicon-32.png        32x32     PNG favicon for browsers without SVG favicon support
"""
import base64, io
from pathlib import Path
from PIL import Image
from playwright.sync_api import sync_playwright

A = Path(__file__).resolve().parent.parent / 'assets'
# inline data URIs: a page made with set_content() may not load file:// images
uri = lambda f: 'data:image/svg+xml;base64,' + base64.b64encode((A / f).read_bytes()).decode()

OG = f'''<!doctype html><html><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Hanken+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
*{{margin:0;box-sizing:border-box}}
body{{width:1200px;height:630px;background:#0f1b2b;color:#eef2f5;font-family:'Hanken Grotesk',sans-serif;
 padding:70px 80px;display:flex;flex-direction:column;justify-content:space-between;
 background-image:radial-gradient(circle at 85% 20%,rgba(200,22,29,.28),transparent 45%)}}
.label{{color:#f0453c;font-weight:700;letter-spacing:.18em;text-transform:uppercase;font-size:22px}}
h1{{font-family:'Instrument Serif',serif;font-weight:400;font-size:82px;line-height:1.02;margin-top:22px;max-width:960px}}
h1 em{{color:#f0453c}}
.foot{{display:flex;align-items:center;justify-content:space-between}}
.foot img{{height:92px}}
.phone{{font-family:'JetBrains Mono',monospace;font-size:34px;text-align:right}}
.phone small{{display:block;font-family:'Hanken Grotesk',sans-serif;font-size:18px;color:#9aa7b4;letter-spacing:.14em;text-transform:uppercase;margin-bottom:8px}}
</style></head><body>
<div><p class="label">Carbon monoxide lawyer · all 50 states</p>
<h1>Carbon monoxide poisoning and injury help. <em>Free, confidential review.</em></h1></div>
<div class="foot"><img src="{uri('logo-dark.svg')}" alt=""><p class="phone"><small>Call toll free</small>1-888-414-8680</p></div>
</body></html>'''

ICON = '''<!doctype html><html><head><style>*{{margin:0}}body{{width:{s}px;height:{s}px;background:{bg};display:grid;place-items:center}}
img{{width:{i}px;height:{i}px}}</style></head><body><img src="{src}" alt=""></body></html>'''


def shot(page, markup, w, h):
    page.set_viewport_size({'width': w, 'height': h})
    page.set_content(markup, wait_until='networkidle')
    page.evaluate('document.fonts.ready')
    return Image.open(io.BytesIO(page.screenshot(type='png')))


with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_page(device_scale_factor=1)
    shot(page, OG, 1200, 630).convert('RGB').save(A / 'og-image.jpg', quality=88, optimize=True, progressive=True)
    # white tile: iOS and search results show icons on light backgrounds; the mark is dark red
    for name, size, inner in (('icon-512.png', 512, 400), ('apple-touch-icon.png', 180, 140), ('favicon-32.png', 32, 30)):
        shot(page, ICON.format(s=size, i=inner, bg='#ffffff', src=uri('favicon.svg')), size, size).save(A / name, optimize=True)
    b.close()
print('images written to', A)
