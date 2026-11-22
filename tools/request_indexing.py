#!/usr/bin/env python3
"""Press "Request indexing" in Search Console for a list of URLs, in a Chrome window someone has logged in to.

Google offers this only as a button (not in its API), so this drives a normal Chrome window:
    google-chrome --remote-debugging-port=9222 --remote-debugging-address=127.0.0.1 \
        --user-data-dir=$HOME/.config/cmk-chrome https://search.google.com/search-console
    (log in by hand with the Search Console account), then:
    python3 tools/request_indexing.py URL [URL ...]

Google allows about 10-12 requests a day per site; the script stops at "Quota exceeded".
Screenshots of each result go to ~/cmk-rebrand-backups/indexing/<date>/.
"""
import datetime, re, sys, urllib.parse
from pathlib import Path
from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout

PROPERTY = 'sc-domain:carbonmonoxidekills.com'
OUT = Path.home() / 'cmk-rebrand-backups/indexing' / datetime.date.today().isoformat()
DONE = re.compile(r'Indexing requested|Quota exceeded|Indexing request rejected|Something went wrong', re.I)


HOME = 'https://search.google.com/search-console?resource_id=' + urllib.parse.quote(PROPERTY, safe='')


def main(urls):
    OUT.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.connect_over_cdp('http://127.0.0.1:9222')
        ctx = browser.contexts[0]
        # Use the visible Search Console tab: Chrome does not render background tabs.
        page = next((x for x in ctx.pages if 'search.google.com' in x.url), None) or ctx.new_page()
        page.bring_to_front()
        for n, u in enumerate(urls, 1):
            name = re.sub(r'[^a-z0-9]+', '-', u.split('.com', 1)[1].lower()).strip('-') or 'home'
            page.goto(HOME, wait_until='domcontentloaded')
            box = page.locator('input[aria-label^="Inspect any URL"]').first
            box.wait_for(timeout=60000)
            box.click()
            box.fill(u)
            box.press('Enter')
            try:
                page.get_by_text(re.compile(r'^\s*URL is ', re.I)).first.wait_for(timeout=90000)
            except PWTimeout:
                page.screenshot(path=str(OUT / f'{n:02d}-{name}-no-result.png'))
                print(f'{u} | inspection did not load')
                continue
            status = page.get_by_text(re.compile(r'^\s*URL is ', re.I)).first.inner_text().strip()
            button = page.get_by_text(re.compile(r'^\s*request indexing\s*$', re.I)).first
            try:
                button.click(timeout=20000)
            except PWTimeout:
                page.screenshot(path=str(OUT / f'{n:02d}-{name}-no-button.png'))
                print(f'{u} | {status} | no Request indexing button')
                continue
            # Google first runs a live test (1-2 minutes), then shows the outcome in a dialog. Read the visible
            # page text rather than waiting on one element: hidden copies of these messages exist in the page.
            text = 'no answer within 4 minutes'
            for _ in range(80):
                page.wait_for_timeout(3000)
                m = DONE.search(page.inner_text('body'))
                if m:
                    text = m.group(0)
                    break
            page.screenshot(path=str(OUT / f'{n:02d}-{name}.png'))
            print(f'{u} | was: {status} | {text}', flush=True)
            for label in ('Got it', 'Dismiss', 'Close'):
                b = page.get_by_role('button', name=re.compile(label, re.I))
                if b.count():
                    b.first.click()
                    break
            if 'quota' in text.lower():
                print('Daily quota reached; continue tomorrow.')
                break



if __name__ == '__main__':
    main(sys.argv[1:])
