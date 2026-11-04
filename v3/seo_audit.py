#!/usr/bin/env python3
"""Checks the built site against the SEO requirements. Run after build.py:

  python3 seo_audit.py          # audits live/ (production) and dist/ (dev must stay noindex)

Exits 1 if any ERROR is found. WARN lines are worth a look but do not fail the audit.
"""
import html, json, re, sys
from collections import Counter
from pathlib import Path
from xml.etree import ElementTree

import seo

ROOT = Path(__file__).parent
errors, warnings = [], []
err = lambda page, msg: errors.append(f'{page}: {msg}')
warn = lambda page, msg: warnings.append(f'{page}: {msg}')


def meta(doc, attr, name):
    m = re.search(rf'<meta {attr}="{re.escape(name)}" content="([^"]*)"', doc)
    return html.unescape(m.group(1)) if m else None


def link(doc, rel):
    m = re.search(rf'<link rel="{rel}" href="([^"]*)"', doc)
    return m.group(1) if m else None


def visible(doc):
    """Text a visitor can read: no head, scripts or tags."""
    body = re.sub(r'<script.*?</script>', ' ', doc.split('<body>', 1)[-1], flags=re.S)
    return seo.text(body)


def audit_schema(page, doc, canonical):
    blocks = re.findall(r'<script type="application/ld\+json">(.*?)</script>', doc, re.S)
    if len(blocks) != 1:
        return err(page, f'expected 1 JSON-LD block, found {len(blocks)}')
    try:
        data = json.loads(blocks[0])
    except json.JSONDecodeError as e:
        return err(page, f'JSON-LD does not parse: {e}')
    if data.get('@context') != 'https://schema.org':
        err(page, 'JSON-LD @context is not https://schema.org')
    graph = data.get('@graph', [])
    nodes = {n['@id']: n for n in graph if '@id' in n}
    types = [n.get('@type') for n in graph]

    def refs(o):
        if isinstance(o, dict):
            if set(o) == {'@id'}:
                yield o['@id']
            for v in o.values():
                yield from refs(v)
        elif isinstance(o, list):
            for v in o:
                yield from refs(v)
    for r in refs(graph):
        if r not in nodes:
            err(page, f'JSON-LD reference {r} has no matching node')

    for t in ('Organization', 'WebSite'):
        if t not in types:
            err(page, f'JSON-LD missing {t}')
    webpage = next((n for n in graph if n.get('@id') == canonical + '#webpage'), None)
    if not webpage:
        return err(page, 'JSON-LD has no WebPage node for the canonical URL')
    if webpage.get('url') != canonical:
        err(page, 'JSON-LD WebPage url differs from canonical')

    org = nodes.get(seo.ORG_ID, {})
    for k in ('name', 'url', 'logo', 'telephone'):
        if not org.get(k):
            err(page, f'Organization missing {k}')

    has_crumbs = '<nav class="crumbs" aria-label="Breadcrumb">' in doc
    bc = next((n for n in graph if n.get('@type') == 'BreadcrumbList'), None)
    if has_crumbs and not bc:
        err(page, 'visible breadcrumb but no BreadcrumbList')
    if bc:
        items = bc['itemListElement']
        if [i['position'] for i in items] != list(range(1, len(items) + 1)):
            err(page, 'BreadcrumbList positions are not 1..n')
        if any(not i.get('item') for i in items):
            err(page, 'BreadcrumbList item without URL')
        if items[-1]['item'] != canonical:
            err(page, 'last breadcrumb is not the canonical URL')
        crumb_text = seo.text(re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', doc, re.S).group(1))
        for i in items:
            if i['name'] not in crumb_text and i['name'] != webpage.get('name'):
                err(page, f'breadcrumb "{i["name"]}" is not in the visible trail')

    text = visible(doc)
    t = webpage.get('@type')
    if t == 'FAQPage':
        qs = webpage.get('mainEntity', [])
        if not qs:
            err(page, 'FAQPage has no questions')
        for q in qs:
            if q['name'] not in text:
                err(page, f'FAQ question not visible on page: {q["name"][:50]}')
            if q['acceptedAnswer']['text'][:60] not in text:
                err(page, f'FAQ answer not visible on page: {q["name"][:50]}')
    if t == 'MedicalWebPage' and not webpage.get('about'):
        err(page, 'MedicalWebPage without about')
    for n in graph:
        if n.get('@type') == 'NewsArticle':
            for k in ('headline', 'image', 'datePublished', 'author', 'publisher'):
                if not n.get(k):
                    err(page, f'NewsArticle missing {k}')
            if len(n.get('headline', '')) > 110:
                err(page, 'NewsArticle headline over 110 characters')
            if n.get('datePublished') and n['datePublished'] not in doc:
                err(page, 'NewsArticle datePublished not shown on page (<time datetime>)')
    return types + [t]


def audit_live(build):
    pages = sorted(build.glob('*.html'))
    titles, descs, canons = {}, {}, {}
    report = []
    for p in pages:
        doc, name = p.read_text(), p.name
        expected = seo.abs_url('' if name == 'index.html' else name)
        if not doc.startswith('<!doctype html>'):
            err(name, 'missing <!doctype html>')
        if f'<html lang="{seo.LANG}">' not in doc:
            err(name, f'html lang is not {seo.LANG}')
        title = re.search(r'<title>(.*?)</title>', doc)
        title = html.unescape(title.group(1)) if title else None
        desc = meta(doc, 'name', 'description')
        if not title:
            err(name, 'missing <title>')
        elif len(title) > 65:
            warn(name, f'title is {len(title)} characters; Google may shorten it after ~60')
        if not desc:
            err(name, 'missing meta description')
        elif not 70 <= len(desc) <= 160:
            err(name, f'meta description is {len(desc)} characters (keep 70-160)')
        titles[name], descs[name] = title, desc
        h1 = len(re.findall(r'<h1[\s>]', doc))
        if h1 != 1:
            err(name, f'{h1} <h1> elements (need exactly 1)')
        robots = meta(doc, 'name', 'robots') or ''
        if 'noindex' in robots or not robots.startswith('index, follow'):
            err(name, f'robots meta is "{robots}"')
        canon = link(doc, 'canonical')
        if canon != expected:
            err(name, f'canonical {canon} != {expected}')
        canons[name] = canon
        og = {k: meta(doc, 'property', 'og:' + k) for k in
              ('type', 'site_name', 'locale', 'url', 'title', 'description', 'image', 'image:width', 'image:height', 'image:alt')}
        for k, v in og.items():
            if not v:
                err(name, f'missing og:{k}')
        if og['url'] != canon:
            err(name, 'og:url differs from canonical')
        if og['title'] != title or og['description'] != desc:
            err(name, 'og:title/description differ from title/description')
        for k in ('card', 'title', 'description', 'image', 'image:alt'):
            if not meta(doc, 'name', 'twitter:' + k):
                err(name, f'missing twitter:{k}')
        for img in (og['image'], seo.abs_url(seo.LOGO['path'])):
            if img and not (build / img.replace(seo.SITE + '/', '')).exists():
                err(name, f'{img} is not in the build')
        for tag in re.findall(r'<img\b[^>]*>', doc):
            src = re.search(r'src="([^"]*)"', tag).group(1)
            if 'alt="' not in tag:
                err(name, f'<img {src}> has no alt attribute')
            if not ('width="' in tag and 'height="' in tag):
                err(name, f'<img {src}> has no width/height (layout shift)')
        for href in re.findall(r'href="([^"#?:]+\.html)', doc):
            if not (build / href).exists():
                err(name, f'broken internal link {href}')
        levels = [int(x) for x in re.findall(r'<h([1-6])[\s>]', re.sub(r'<header class="site".*?</header>|<footer class="site".*?</footer>|<div class="drawer".*?</nav>', '', doc, flags=re.S))]
        for a, b in zip(levels, levels[1:]):
            if b > a + 1:
                warn(name, f'heading jumps from h{a} to h{b}')
                break
        types = audit_schema(name, doc, canon) or []
        report.append((name, len(title or ''), len(desc or ''), ', '.join(t for t in dict.fromkeys(types) if t)))

    for label, seen in (('title', titles), ('meta description', descs), ('canonical', canons)):
        for value, n in Counter(seen.values()).items():
            if value and n > 1:
                err('site', f'duplicate {label} on {n} pages: {value[:60]}')

    sm = build / seo.SITEMAP_FILE
    ns = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
    tree = ElementTree.parse(sm)
    urls = {u.find('s:loc', ns).text: u.find('s:lastmod', ns).text for u in tree.findall('s:url', ns)}
    if set(urls) != set(canons.values()):
        err('sitemap', f'URLs differ from canonicals: {sorted(set(urls) ^ set(canons.values()))}')
    for u, d in urls.items():
        if not d or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', d):
            err('sitemap', f'bad lastmod for {u}: {d}')
    robots = (build / 'robots.txt').read_text()
    if f'Sitemap: {seo.abs_url(seo.SITEMAP_FILE)}' not in robots:
        err('robots.txt', 'does not list the sitemap')
    if re.search(r'^Disallow: /\s*$', robots, re.M):
        err('robots.txt', 'blocks the whole site')
    return report


def audit_dev(build):
    for p in sorted(build.glob('*.html')):
        doc = p.read_text()
        if 'content="noindex, nofollow"' not in doc:
            err(f'dist/{p.name}', 'dev build page is indexable')
        if link(doc, 'canonical'):
            err(f'dist/{p.name}', 'dev build page has a canonical to production')


if __name__ == '__main__':
    report = audit_live(ROOT / 'live')
    audit_dev(ROOT / 'dist')
    print(f'{"page":36} {"title":>5} {"desc":>5}  structured data')
    for name, t, d, types in report:
        print(f'{name:36} {t:5} {d:5}  {types}')
    print()
    for w in warnings:
        print('WARN ', w)
    for e in errors:
        print('ERROR', e)
    print(f'\n{len(report)} pages, {len(errors)} errors, {len(warnings)} warnings')
    sys.exit(1 if errors else 0)
