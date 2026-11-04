#!/usr/bin/env python3
"""Builds the multi-page CMK concept.

content/<page>.html  = one page body, with a JSON meta block at the top
dist/                = standalone site (EC2 / cPanel): full HTML documents
art/                 = same site for the claude.ai artifact (index.html without the document shell)
live/                = production build for www.carbonmonoxidekills.com: indexable, SEO tags and
                       structured data from seo.py, sitemap + robots.txt, phone-only calls to action
"""
import json, os, re, shutil, sys, html
from urllib.parse import urlsplit
from pathlib import Path

import seo

ROOT = Path(__file__).parent
C = ROOT / 'content'
PHONE = '1-888-414-8680'
TEL = 'tel:+18884148680'
CONCEPT = 'Concept v3 · prepared for Carbon Monoxide Kills · October 2026'

# Page registry: key -> (file, menu title, one-line description)
PAGES = {
 'home': ('index.html', 'Home', ''),
 'cases': ('cases.html', 'All cases we handle', 'Who may be responsible, setting by setting.'),
 'rented-homes': ('rented-homes.html', 'Rented homes & apartments', 'Landlord duties for furnaces, boilers, flues and alarms.'),
 'hotels': ('hotels.html', 'Hotels & vacation rentals', 'Pool heaters, boilers and detector rules that vary by state.'),
 'appliances': ('appliances-generators.html', 'Defective appliances & generators', 'Heaters, boilers and generators that fail or lack warnings.'),
 'vehicles': ('vehicles-boats-rvs.html', 'Vehicles, boats & RVs', 'Exhaust leaks, generator fumes and enclosed spaces.'),
 'workplace': ('workplace.html', 'Workplace exposure', 'Workers’ comp, plus claims against third parties.'),
 'wrongful-death': ('wrongful-death.html', 'Wrongful death', 'Support for families who have lost someone.'),
 'facts': ('facts.html', 'Carbon monoxide facts', 'What it is, where it comes from, who is most at risk.'),
 'symptoms': ('symptoms.html', 'Symptoms & CO levels', 'Early signs, delayed effects, testing and treatment.'),
 'detectors': ('detectors.html', 'Detectors & placement', 'Standards, where to fit them, testing and replacing.'),
 'advice': ('advice.html', 'Safety advice', 'What to do if an alarm sounds, and how to prevent exposure.'),
 'faq': ('faq.html', 'Questions & answers', 'Plain answers about claims, health and the process.'),
 'lawyers': ('lawyers.html', 'Find a lawyer', 'Partner attorneys in all 50 states and DC.'),
 'news': ('news.html', 'News & insights', 'Incidents and research, with Rob’s expert view.'),
 'article': ('news-generator-safe-distance.html', '“Is this a safe distance?”', 'Generator safety after a power cut.'),
 'about': ('about.html', 'About Rob Aiers', 'The founder, and why this site exists.'),
 'attorneys': ('attorneys.html', 'For attorneys', 'Partner with us, or get expert help for a client.'),
 'medical': ('medical-professionals.html', 'For medical professionals', 'Recognizing CO poisoning and reaching an expert.'),
 'contact': ('contact.html', 'Contact', 'Call, email or send a message.'),
 'review': ('review.html', 'Free case review', 'Five confidential minutes.'),
 'thank-you': ('thank-you.html', 'Thank you', ''),
}
def url(k): return PAGES[k][0]

MENU = [
 {'key': 'cases', 'label': 'Cases', 'title': 'Cases we handle', 'blurb': 'Responsibility depends on where it happened. Each setting has its own duties, rules and evidence.',
  'all': ('cases', 'All cases'), 'links': ['rented-homes', 'hotels', 'appliances', 'vehicles', 'workplace', 'wrongful-death'],
  'promo': ('review.html', 'Not sure which applies?', 'Answer a few questions and we’ll tell you plainly whether you may have a claim.', 'Start your free review →', None)},
 {'key': 'learn', 'label': 'CO facts', 'title': 'Carbon monoxide, explained', 'blurb': 'Clear, sourced information on the gas, its symptoms and how to stay safe.',
  'all': ('faq', 'All questions'), 'links': ['facts', 'symptoms', 'detectors', 'advice', 'faq'],
  'promo': ('advice.html#alarm', 'Is an alarm sounding now?', 'Get everyone outside, leave the door open, then call 911 from fresh air.', 'What to do next →', None)},
 {'key': 'lawyers', 'label': 'Find a lawyer'},
 {'key': 'news', 'label': 'News'},
 {'key': 'about-menu', 'label': 'About', 'title': 'About us', 'blurb': 'Online since 2010, with medical and legal experts behind every answer.',
  'all': ('contact', 'Contact us'), 'links': ['about', 'attorneys', 'medical', 'contact'], 'one': True,
  'promo': ('about.html#video', 'Watch Rob explain the danger', 'Two minutes from the Carbon Monoxide Kills archive.', 'Play the video →', 'assets/rob-duotone.jpg')},
]
SECTION_OF = {k: 'cases' for k in ['cases', 'rented-homes', 'hotels', 'appliances', 'vehicles', 'workplace', 'wrongful-death']}
SECTION_OF.update({k: 'learn' for k in ['facts', 'symptoms', 'detectors', 'advice', 'faq']})
SECTION_OF.update({k: 'about-menu' for k in ['about', 'attorneys', 'medical', 'contact']})
SECTION_OF.update({'lawyers': 'lawyers', 'news': 'news', 'article': 'news'})

AC = ' aria-current="page"'
CUR = ' class="cur"'
def esc(s): return html.escape(s, quote=True)

def header(cur):
    sec = SECTION_OF.get(cur)
    lis = []
    for m in MENU:
        curcls = ' cur' if sec == m['key'] else ''
        if 'links' not in m:
            ac = AC if cur == m['key'] else ''
            lis.append(f'<li class="ni"><a class="nl{curcls}" href="{url(m["key"])}"{ac}>{m["label"]}</a></li>')
            continue
        links = ''.join(f'<a class="ml{" cur" if cur == k else ""}" href="{url(k)}"{AC if cur == k else ""}><b>{esc(PAGES[k][1])}</b><span>{esc(PAGES[k][2])}</span></a>' for k in m['links'])
        href, t, d, cta, img = m['promo']
        im = f'<img src="{img}" alt="Rob Aiers, founder of Carbon Monoxide Kills" width="360" height="360" loading="lazy" decoding="async">' if img else ''
        promo = f'<a class="promo" href="{href}">{im}<span class="label">{"Video" if img else "Free help"}</span><b>{t}</b><span>{d}</span><em>{cta}</em></a>'
        ak, at = m['all']
        lis.append(f'''<li class="ni has-mega"><button class="nl{curcls}" type="button" aria-expanded="false" aria-controls="m-{m['key']}">{m['label']} <i class="chev" aria-hidden="true"></i></button>
<div class="mega" id="m-{m['key']}"><div class="wrap"><div class="intro-col"><span class="label ember">{m['label']}</span><p class="h-3">{m['title']}</p><p>{m['blurb']}</p><a class="all" href="{url(ak)}">{at} <span aria-hidden="true">→</span></a></div><div class="links{' one' if m.get('one') else ''}">{links}</div>{promo}</div></div></li>''')
    return f'''<div class="topline"><div class="wrap">
  <span class="alert"><i aria-hidden="true"></i><span><b>Alarm sounding or feeling unwell?</b> Leave the building now and call 911.</span></span>
  <a class="tl-phone" href="{TEL}">Free &amp; confidential · <b>{PHONE}</b></a>
</div></div>
<header class="site" id="hdr"{' data-solid="1"' if cur != 'home' else ''}><div class="wrap">
  <a class="mark" href="index.html" aria-label="Carbon Monoxide Kills home"><img src="assets/logo-dark.svg" alt="Carbon Monoxide Kills" width="150" height="55"></a>
  <nav class="primary" aria-label="Primary"><ul>{''.join(lis)}</ul></nav>
  <a class="btn btn-ember hdr-cta" href="{'#review' if cur == 'home' else 'review.html'}"><span class="long">Free case review</span><span class="short">Case review</span> <span class="arr">→</span></a>
  <button class="burger" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="drawer"><span></span><span></span></button>
</div></header>
<div class="scrim" id="scrim"></div>
{drawer(cur)}'''

def drawer(cur):
    out = []
    for m in MENU:
        if 'links' not in m:
            out.append(f'<a class="dl" href="{url(m["key"])}">{m["label"]}</a>')
            continue
        sub = ''.join(f'<a href="{url(k)}"{CUR if cur == k else ""}>{esc(PAGES[k][1])}</a>' for k in ([] if m['all'][0] in m['links'] else [m['all'][0]]) + m['links'])
        out.append(f'<details{" open" if SECTION_OF.get(cur) == m["key"] else ""}><summary>{m["label"]}</summary><div class="sub">{sub}</div></details>')
    return f'''<div class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="Menu" aria-hidden="true">
  <div class="dtop"><a href="index.html"><img src="assets/logo-dark.svg" alt="Carbon Monoxide Kills" width="150" height="55"></a><button class="x" type="button" aria-label="Close menu"></button></div>
  <nav aria-label="Mobile">{''.join(out)}</nav>
  <div class="dfoot"><a class="btn btn-ember" href="review.html">Start your free case review <span class="arr">→</span></a><a class="mono" href="{TEL}" style="text-decoration:none">or call {PHONE}</a><p class="alert">Alarm sounding or feeling unwell? Leave the building now and call 911.</p></div>
</div>'''

def footer():
    col = lambda t, ks: f'<div><h4>{t}</h4><ul>' + ''.join(f'<li><a href="{url(k)}">{esc(PAGES[k][1].replace("All cases we handle","All cases"))}</a></li>' for k in ks) + '</ul></div>'
    return f'''<footer class="site"><div class="wrap">
  <div class="top">
    <div><img class="cmk-logo" src="assets/logo-dark.svg" alt="Carbon Monoxide Kills" width="190" height="70" loading="lazy" decoding="async"><p class="big">The carbon monoxide injury specialists.</p><a class="mono" href="{TEL}" style="display:block;margin-top:22px;color:var(--on-ink)">{PHONE}</a></div>
    {col('Help', ['review', 'lawyers', 'contact', 'faq'])}
    {col('Cases', ['cases', 'rented-homes', 'hotels', 'appliances', 'vehicles', 'workplace', 'wrongful-death'])}
    {col('Learn', ['facts', 'symptoms', 'detectors', 'advice', 'news'])}
    {col('About', ['about', 'attorneys', 'medical'])}
  </div>
  <div class="legal"><p>Carbon Monoxide Kills is not a law firm and does not give legal or medical advice. Completing a case review does not create an attorney–client relationship. Attorney advertising. Prior results do not guarantee a similar outcome.</p><p>In an emergency, leave the building and call 911.<br>© 2010–2026 CarbonMonoxideKills.com · Privacy · Terms<span class="concept" style="display:block">{CONCEPT}</span></p></div>
</div></footer>'''

def phero(m):
    h = m['hero']
    crumbs = '<a href="index.html">Home</a>'
    for t, u in m.get('crumbs', []):
        crumbs += '<span>/</span>' + (f'<a href="{u}">{t}</a>' if u else f'<span style="color:var(--on-ink)">{t}</span>')
    by = ''
    rd = f'<span>{h["read"]} min read</span>' if h.get('read') else ''
    if h.get('byline'):
        by = f'<div class="byline"><span>By <b>Rob Aiers</b>, founder</span><span>We have over 25 years dealing with CO cases. We know what makes a case and what doesn’t.</span><span>Updated <b class="mono"><time datetime="{seo.iso_date(h.get("updated", "21 Oct 2026"))}">{h.get("updated", "21 Oct 2026")}</time></b></span>{rd}</div>'
    acts = f'<div class="acts">{h["acts"]}</div>' if h.get('acts') else ''
    return f'''<div class="phero dark"><div class="wrap">
  <nav class="crumbs" aria-label="Breadcrumb">{crumbs}</nav>
  <p class="label ember">{h['label']}</p>
  <h1 class="{h.get('size', 'h-1')}">{h['h1']}</h1>
  {f'<p class="lede">{h["lede"]}</p>' if h.get('lede') else ''}
  {acts}{by}
</div></div>'''

def aside(m):
    a = m.get('aside', {})
    t = a.get('title', 'Could you have a claim?')
    d = a.get('text', 'Five confidential minutes to find out where you stand.')
    extra = a.get('extra', '<div class="author"><b>Rob Aiers</b>Founder of Carbon Monoxide Kills. Since 1999 we have been helping to protect people from carbon monoxide poisoning.</div>')
    return f'<aside class="aside"><div class="cta-card"><span class="label ember">Free case review</span><h3>{t}</h3><p>{d}</p><a class="btn btn-ember" href="review.html">Start your review <span class="arr">→</span></a><a class="mono" href="{TEL}" style="text-decoration:none">or call {PHONE}</a></div>{extra}</aside>'

def related(keys, title='Keep reading'):
    if not keys: return ''
    cards = ''.join(f'<a href="{url(k)}"><b>{esc(PAGES[k][1])}</b><span>{esc(PAGES[k][2])}</span><i aria-hidden="true">→</i></a>' for k in keys)
    return f'<div class="related"><div class="wrap"><p class="label ember">{title}</p><div class="grid">{cards}</div></div></div>'

def band(m):
    if m.get('band') is False: return ''
    b = m.get('band') or {}
    h = b.get('h', 'We will help prove a case by <em class="ember">reviewing the evidence.</em>')
    return f'<section class="band" style="padding:0"><div class="wrap"><h2 class="h-1">{h}</h2><div class="acts"><a class="btn btn-ember" href="review.html">Start your free case review <span class="arr">→</span></a><a class="mono" href="{TEL}" style="text-decoration:none">or call {PHONE}</a></div></div></section>'

def guide(m, body):
    toc = ''.join(f'<li><a href="#{i}">{re.sub("<[^>]+>", "", t)}</a></li>' for i, t in re.findall(r'<h2 id="([^"]+)">(.*?)</h2>', body))
    pre = m.get('preband', '')
    if pre == 'PPM_INSTRUMENT':  # reuse the homepage gauge as a full-width band
        home = (C / 'home.html').read_text()
        a = home.index('<section class="instrument dark" id="levels">'); b = home.index('</section>', a) + len('</section>')
        pre = re.sub(r'\s*<a class="btn btn-line rv" href="symptoms.html".*?</a>', '', home[a:b], flags=re.S).replace('id="levels"', 'id="gauge"', 1)
    return f'''{phero(m)}{pre}
<div class="wrap layout">
  <nav class="toc" aria-label="On this page"><span class="label">On this page</span><ol>{toc}</ol></nav>
  <article>{body}</article>
  {aside(m)}
</div>'''

def state_links(mode):
    # Every state links to its own "Carbon monoxide lawyer in <state>" page on the live domain (state_pages.json)
    pages = json.loads((ROOT / 'state_pages.json').read_text())
    base = '' if mode == 'live' else seo.SITE
    return ''.join(f'<a href="{base}{urlsplit(u).path}" title="Carbon monoxide lawyer in {esc(s)}">{esc(s)}</a>' for s, u in pages.items())

def parse(p):
    s = p.read_text()
    mt = re.match(r'<!--meta\s*(\{.*?\})\s*-->\s*', s, re.S)
    meta = json.loads(mt.group(1)); body = s[mt.end():]
    return meta, body

PHONE_PANEL = f'''<div class="panel" id="call-us"><p class="label ember">Free case review</p><h3>Call us for a free, confidential review.</h3><p class="hint">Tell Rob’s team what happened and we will tell you plainly whether you may have a claim, and what to do next. No obligation.</p><p style="margin-top:22px"><a class="btn btn-ember" href="{TEL}">Call {PHONE} <span class="arr">→</span></a></p><p style="margin-top:14px"><a href="review.html#case-form" style="color:var(--ember-deep);font-weight:600">Or fill in the case review form →</a></p><p class="hint" style="margin-top:18px"><small>Alarm sounding or feeling unwell? Leave the building now and call 911.</small></p></div>'''
PHONE_CARD = f'''<div class="formcard rv"><h2>Call us</h2><p class="hint">The quickest way to reach Rob Aiers’ team is by phone. Free and confidential.</p><p style="margin-top:22px"><a class="btn btn-ember" href="{TEL}">Call {PHONE} <span class="arr">→</span></a></p></div>'''

def live_copy(page):
    """Production wording: no preview forms or concept labels, phone-only calls to action."""
    page = re.sub(r'<span class="concept"[^>]*>.*?</span>', '', page, flags=re.S)
    page = re.sub(r'<form class="panel" id="eval"[^>]*></form>', PHONE_PANEL, page)
    page = re.sub(r'<form class="formcard enquiry rv" novalidate>.*?</form>', PHONE_CARD, page, flags=re.S)
    for a, b in (
        ('Answer a few questions and we’ll tell you plainly whether you may have a claim.', 'Call us and we’ll tell you plainly whether you may have a claim.'),
        ('Answer a few questions and Rob’s team will tell you plainly', 'Call Rob’s team and we will tell you plainly'),
        ('Five minutes. Confidential. No obligation.', 'One call. Confidential. No obligation.'),
        ('Five confidential minutes.', 'One confidential call.'),
        ('Five confidential minutes to find out if you may have a claim.', 'One confidential call to find out if you may have a claim.'),
        ('Call, email or send a message.', 'Call us, free and confidential.'),
        ('toll free on 1-888-414-8680 or send a message.', 'toll free on 1-888-414-8680.'),
        ('Call toll free, or send a message. If we are busy', 'Call toll free. If we are busy'),
        ('</a> or send a message. We usually reply the same day.', '</a>. We usually reply the same day.'),
        ('Your answers are reviewed against', 'Your case is reviewed against'),
        ('Tell us what happened</h3><p>', 'Tell us what happened</h3><p>'),
    ):
        page = page.replace(a, b)
    return page

BRAND = ' · Carbon Monoxide Kills'

def case_form():
    """The case review form (content/_case_form.html), posted to server/case-review.php."""
    states = json.loads((ROOT / 'state_pages.json').read_text())
    return (C / '_case_form.html').read_text().replace('{{STATE_OPTIONS}}', ''.join(f'<option>{esc(s)}</option>' for s in states))

def with_case_form(key, main):
    """Production only: the review page gets the form below the intro, the contact page instead of the concept form."""
    if key == 'review':
        return main.replace(related_marker(), f'<section class="caseform-sec"><div class="wrap">{case_form()}</div></section>\n' + related_marker(), 1)
    if key == 'contact':
        return re.sub(r'<form class="formcard enquiry rv" novalidate>.*?</form>', lambda m: case_form(), main, count=1, flags=re.S)
    return main

def related_marker():
    return '<div class="related">'

def render(key, mode):
    meta, body = parse(C / f'{key}.html')
    live = mode == 'live'
    path = '' if key == 'home' else url(key)
    title = meta['title']
    if key != 'home' and len(title + BRAND) <= 60:
        title += BRAND
    desc = live_copy(meta['desc']) if live else meta['desc']
    if meta.get('layout') == 'guide':
        main = guide(meta, body)
    else:
        main = (phero(meta) if meta.get('hero') else '') + body
    main += related(meta.get('related', [])) + band(meta)
    main = main.replace('<div class="slist" id="slist"></div>', f'<div class="slist" id="slist">{state_links(mode)}</div>')
    if live:
        main = live_copy(with_case_form(key, main))
    # dev/preview builds stay noindex; in production only utility pages (thank-you) are
    indexable = live and url(key) not in seo.NOINDEX_FILES
    seo_tags = seo.head_tags(path, title, desc, indexable=indexable, og_type='article' if key == 'article' else 'website')
    if indexable:
        seo_tags += '\n' + seo.json_ld(key, path, title, desc, meta, main, seo.last_modified(C / f'{key}.html'))
        seo_tags += ('\n' + seo.analytics_tags()) if seo.GA4_MEASUREMENT_ID else ''
    head = f'''<title>{esc(title)}</title>
<meta name="description" content="{esc(desc)}">
{seo_tags}
<meta name="theme-color" content="#0f1b2b">
{seo.icon_tags()}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Hanken+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/site.css">'''
    pre_body = (C / 'intro.html').read_text() if key == 'home' else ''
    scripts = '<script src="assets/site.js" defer></script>'
    if key == 'home':
        scripts = '<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" defer></script>\n' + scripts + '\n<script src="assets/home.js" defer></script>'
    page = f'''{pre_body}{header(key)}
<main id="top">
{main}
</main>
{footer()}
{scripts}'''
    if mode == 'art' and key == 'home':
        return head + '\n' + page
    doc = f'''<!doctype html>
<html lang="{seo.LANG}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
{head}
</head>
<body>
{page}
</body>
</html>
'''
    return live_copy(doc) if live else doc

ASSETS = ['site.js', 'home.js', 'logo-dark.svg', 'logo.svg', 'favicon.svg', 'favicon-32.png', 'apple-touch-icon.png',
          'icon-512.png', 'og-image.jpg', 'rob-duotone.jpg']

def build():
    css = (ROOT / 'assets/_base.css').read_text()
    css = '\n'.join(l for l in css.split('\n') if not l.startswith('nav.primary'))
    css = css.replace('@media (max-width:1180px){nav.primary{display:none}header.site .btn{margin-left:auto}}', '')
    css += (ROOT / 'assets/_nav.css').read_text()
    keys = [k for k in PAGES if (C / f'{k}.html').exists()]
    for k in PAGES:
        if k not in keys:
            print('MISSING content for', k, file=sys.stderr)
    for mode in ('dist', 'art', 'live'):
        out = ROOT / mode
        if out.exists(): shutil.rmtree(out)
        (out / 'assets').mkdir(parents=True)
        (out / 'assets/site.css').write_text(css)
        for f in ASSETS:
            shutil.copy(ROOT / 'assets' / f, out / 'assets' / f)
        for key in keys:
            (out / url(key)).write_text(render(key, mode))
    live = ROOT / 'live'
    entries = [('' if k == 'home' else url(k), seo.last_modified(C / f'{k}.html')) for k in keys if url(k) not in seo.NOINDEX_FILES]
    (live / seo.SITEMAP_FILE).write_text(seo.sitemap(entries))
    (live / 'robots.txt').write_text(seo.robots_txt())
    shutil.copytree(ROOT / 'server', live, dirs_exist_ok=True)   # form handler + closed storage folder
    print('built', len(keys), 'pages')

if __name__ == '__main__':
    build()
