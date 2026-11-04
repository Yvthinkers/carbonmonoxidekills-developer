"""SEO layer for the Carbon Monoxide Kills site.

Everything search engines and social platforms read is generated here, in one place:
  head_tags()  - robots, canonical, Open Graph, Twitter Card, verification (icon_tags, analytics_tags)
  json_ld()    - schema.org structured data per page (Organization, WebSite, BreadcrumbList,
                 FAQPage, NewsArticle, MedicalWebPage, AboutPage, ContactPage, WebPage)
  sitemap()    - sitemap with <lastmod>
  robots_txt() - robots.txt (keeps the WordPress/Yoast rules that share this domain)

build.py calls these for every page; seo_audit.py checks the output.
Only facts that are visible on the site go into structured data.
"""
import datetime, html, json, re, subprocess
from pathlib import Path

SITE = 'https://www.carbonmonoxidekills.com'   # preferred host: https + www (apex and http redirect here)
NAME = 'Carbon Monoxide Kills'
PHONE = '+1-888-414-8680'
LANG = 'en-US'
LOCALE = 'en_US'
DESCRIPTION = ('Carbon monoxide poisoning and injury help in every US state: free, confidential case '
               'reviews and partner carbon monoxide lawyers in all 50 states and DC.')
OG_IMAGE = {'path': 'assets/og-image.jpg', 'width': 1200, 'height': 630,
            'alt': 'Carbon Monoxide Kills: carbon monoxide lawyer help in all 50 states, call 1-888-414-8680'}
LOGO = {'path': 'assets/icon-512.png', 'width': 512, 'height': 512}
FOUNDER = 'Rob Aiers'

# Filled in once the client grants access; nothing is output while they are empty.
GOOGLE_SITE_VERIFICATION = ''
BING_SITE_VERIFICATION = ''
GA4_MEASUREMENT_ID = ''        # e.g. 'G-XXXXXXXXXX'

# Pages whose content is medical information about carbon monoxide poisoning.
MEDICAL_PAGES = {'facts', 'symptoms'}
# The older WordPress sitemap on the same domain (state lawyer pages, posts) stays live.
WORDPRESS_SITEMAP = f'{SITE}/sitemap_index.xml'
SITEMAP_FILE = 'sitemap-new.xml'

ROOT = Path(__file__).parent


def abs_url(path=''):
    return f'{SITE}/{path}'


def text(s):
    """Plain text from a fragment of page HTML (tags dropped, entities decoded, whitespace collapsed)."""
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', s))).strip()


def iso_date(s):
    """'24 Aug 2026' -> '2026-08-24'."""
    return datetime.datetime.strptime(s, '%d %b %Y').date().isoformat()


def last_modified(path):
    """Date of the last commit touching a content file; today if it has uncommitted changes."""
    try:
        dirty = subprocess.run(['git', 'status', '--porcelain', '--', path.name], cwd=path.parent,
                               capture_output=True, text=True, check=True).stdout.strip()
        if not dirty:
            day = subprocess.run(['git', 'log', '-1', '--format=%cs', '--', path.name], cwd=path.parent,
                                 capture_output=True, text=True, check=True).stdout.strip()
            if day:
                return day
    except (OSError, subprocess.CalledProcessError):
        pass
    return datetime.date.today().isoformat()


# ---------- <head> ----------

def head_tags(page_path, title, desc, indexable, og_type='website'):
    """Meta tags for one page. indexable=False is the dev/staging build: noindex and no canonical."""
    e = lambda s: html.escape(s, quote=True)
    if not indexable:
        return '<meta name="robots" content="noindex, nofollow">'
    url = abs_url(page_path)
    img = abs_url(OG_IMAGE['path'])
    tags = [
        '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">',
        f'<link rel="canonical" href="{url}">',
        f'<meta property="og:type" content="{og_type}">',
        f'<meta property="og:site_name" content="{NAME}">',
        f'<meta property="og:locale" content="{LOCALE}">',
        f'<meta property="og:url" content="{url}">',
        f'<meta property="og:title" content="{e(title)}">',
        f'<meta property="og:description" content="{e(desc)}">',
        f'<meta property="og:image" content="{img}">',
        f'<meta property="og:image:width" content="{OG_IMAGE["width"]}">',
        f'<meta property="og:image:height" content="{OG_IMAGE["height"]}">',
        f'<meta property="og:image:alt" content="{e(OG_IMAGE["alt"])}">',
        '<meta name="twitter:card" content="summary_large_image">',
        f'<meta name="twitter:title" content="{e(title)}">',
        f'<meta name="twitter:description" content="{e(desc)}">',
        f'<meta name="twitter:image" content="{img}">',
        f'<meta name="twitter:image:alt" content="{e(OG_IMAGE["alt"])}">',
    ]
    if GOOGLE_SITE_VERIFICATION:
        tags.append(f'<meta name="google-site-verification" content="{e(GOOGLE_SITE_VERIFICATION)}">')
    if BING_SITE_VERIFICATION:
        tags.append(f'<meta name="msvalidate.01" content="{e(BING_SITE_VERIFICATION)}">')
    return '\n'.join(tags)


def analytics_tags():
    """Google Analytics 4, loaded async so it never delays the page. Empty until an ID is configured."""
    if not GA4_MEASUREMENT_ID:
        return ''
    gid = html.escape(GA4_MEASUREMENT_ID, quote=True)
    return (f'<script async src="https://www.googletagmanager.com/gtag/js?id={gid}"></script>\n'
            "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}"
            f"gtag('js',new Date());gtag('config','{gid}');</script>")


def icon_tags():
    return ('<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">\n'
            '<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">\n'
            '<link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">')


# ---------- structured data ----------

ORG_ID = f'{SITE}/#organization'
SITE_ID = f'{SITE}/#website'
FOUNDER_ID = f'{SITE}/about.html#rob-aiers'


def organization():
    # An Organization, not a LegalService: the site states it is not a law firm.
    return {
        '@type': 'Organization', '@id': ORG_ID, 'name': NAME, 'url': abs_url(),
        'logo': {'@type': 'ImageObject', 'url': abs_url(LOGO['path']), 'width': LOGO['width'], 'height': LOGO['height']},
        'image': abs_url(OG_IMAGE['path']),
        'description': DESCRIPTION,
        'telephone': PHONE,
        'areaServed': {'@type': 'Country', 'name': 'United States'},
        'founder': {'@id': FOUNDER_ID},
        'contactPoint': {'@type': 'ContactPoint', 'telephone': PHONE, 'contactType': 'customer service',
                         'areaServed': 'US', 'availableLanguage': 'English'},
    }


def founder():
    return {'@type': 'Person', '@id': FOUNDER_ID, 'name': FOUNDER, 'jobTitle': 'Founder',
            'worksFor': {'@id': ORG_ID}, 'url': abs_url('about.html')}


def breadcrumbs(crumbs, page_path, page_name):
    """Mirror the visible breadcrumb trail. Crumbs without a link are not pages, so they are left out."""
    items = [('Home', abs_url())]
    for name, href in crumbs[:-1]:
        if href:
            items.append((text(name), abs_url(href)))
    items.append((text(crumbs[-1][0]) if crumbs else page_name, abs_url(page_path)))
    return {'@type': 'BreadcrumbList', '@id': abs_url(page_path) + '#breadcrumb',
            'itemListElement': [{'@type': 'ListItem', 'position': i, 'name': n, 'item': u}
                                for i, (n, u) in enumerate(items, 1)]}


def faq_items(body):
    """Question/answer pairs from the visible <details><summary> blocks."""
    out = []
    for q, a in re.findall(r'<details[^>]*><summary>(.*?)</summary>(.*?)</details>', body, re.S):
        out.append({'@type': 'Question', 'name': text(q),
                    'acceptedAnswer': {'@type': 'Answer', 'text': text(a)}})
    return out


def json_ld(key, page_path, title, desc, meta, body, modified):
    url = abs_url(page_path)
    hero = meta.get('hero') or {}
    page = {'@id': url + '#webpage', 'url': url, 'name': title, 'description': desc, 'inLanguage': LANG,
            'isPartOf': {'@id': SITE_ID}, 'publisher': {'@id': ORG_ID}, 'dateModified': modified,
            'primaryImageOfPage': {'@type': 'ImageObject', 'url': abs_url(OG_IMAGE['path'])}}
    graph = [organization(), founder(), {'@type': 'WebSite', '@id': SITE_ID, 'url': abs_url(), 'name': NAME,
                              'description': DESCRIPTION, 'inLanguage': LANG, 'publisher': {'@id': ORG_ID}}]

    if key == 'home':
        page['@type'] = 'WebPage'
        page['about'] = {'@id': ORG_ID}
    elif key == 'about':
        page['@type'] = 'AboutPage'
        page['mainEntity'] = {'@id': FOUNDER_ID}
    elif key == 'contact':
        page['@type'] = 'ContactPage'
        page['mainEntity'] = {'@id': ORG_ID}
    elif key == 'faq':
        page['@type'] = 'FAQPage'
        page['mainEntity'] = faq_items(body)
    elif key in MEDICAL_PAGES:
        page['@type'] = 'MedicalWebPage'
        page['about'] = {'@type': 'MedicalCondition', 'name': 'Carbon monoxide poisoning',
                         'alternateName': 'CO poisoning'}
    else:
        page['@type'] = 'WebPage'

    if meta.get('crumbs') is not None:
        page['breadcrumb'] = {'@id': url + '#breadcrumb'}
        graph.append(breadcrumbs(meta['crumbs'], page_path, title))

    if key == 'article':
        published = iso_date(hero['updated'])
        graph.append({
            '@type': 'NewsArticle', '@id': url + '#article', 'mainEntityOfPage': {'@id': url + '#webpage'},
            'headline': text(hero['h1'])[:110], 'description': desc, 'inLanguage': LANG,
            'image': [abs_url(OG_IMAGE['path'])], 'datePublished': published, 'dateModified': published,
            'author': {'@id': FOUNDER_ID}, 'publisher': {'@id': ORG_ID},
        })
        page['dateModified'] = published

    graph.append(page)
    data = json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False, indent=1)
    # '<' escaped so page text can never close the script element early
    return '<script type="application/ld+json">\n' + data.replace('<', '\\u003c') + '\n</script>'


# ---------- crawl files ----------

def sitemap(entries):
    """entries: [(path, lastmod)] of canonical, indexable pages only."""
    rows = ''.join(f'<url><loc>{abs_url(p)}</loc><lastmod>{d}</lastmod></url>\n' for p, d in entries)
    return ('<?xml version="1.0" encoding="UTF-8"?>\n'
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + rows + '</urlset>\n')


def robots_txt():
    # A physical robots.txt replaces the one WordPress/Yoast generates, so its rules are carried over unchanged.
    return ('User-agent: *\n'
            'Disallow: /wp-content/uploads/wpforms/\n'
            '\n'
            f'Sitemap: {abs_url(SITEMAP_FILE)}\n'
            f'Sitemap: {WORDPRESS_SITEMAP}\n')
