#!/usr/bin/env python3
"""Google Search Console for carbonmonoxidekills.com, from the command line.

One-time login (opens the browser; sign in with the Google account that has Search Console access):
    python3 tools/gsc.py login ~/Downloads/cmk-gsc-client.json
Then:
    python3 tools/gsc.py sites                          properties this account can see
    python3 tools/gsc.py sitemaps                       sitemaps and their status
    python3 tools/gsc.py submit sitemap-new.xml         submit a sitemap (needs Full or Owner)
    python3 tools/gsc.py perf 2026-08-01 2026-11-18 query,page out.csv
                                                        search performance to CSV (any dimensions:
                                                        query, page, country, device, date)
    python3 tools/gsc.py inspect URL [URL ...]          index status, last crawl, canonical, problems

The token is kept in ~/.config/cmk-gsc/token.json (readable only by you), never in this repository.
"Request indexing" is not offered by Google's API; it stays a button in Search Console.
"""
import csv, json, os, sys
from pathlib import Path

SITE = 'sc-domain:carbonmonoxidekills.com'
SCOPES = ['https://www.googleapis.com/auth/webmasters']
HOME = Path.home() / '.config/cmk-gsc'
TOKEN = HOME / 'token.json'


def login(client_file):
    from google_auth_oauthlib.flow import InstalledAppFlow
    flow = InstalledAppFlow.from_client_secrets_file(os.path.expanduser(client_file), SCOPES)
    creds = flow.run_local_server(port=0, prompt='consent', open_browser=True)
    HOME.mkdir(parents=True, exist_ok=True)
    os.chmod(HOME, 0o700)
    TOKEN.write_text(creds.to_json())
    os.chmod(TOKEN, 0o600)
    print('Logged in. Token saved to', TOKEN)


def service():
    from google.oauth2.credentials import Credentials
    from google.auth.transport.requests import Request
    from googleapiclient.discovery import build
    if not TOKEN.exists():
        sys.exit('Not logged in yet: python3 tools/gsc.py login <client.json>')
    creds = Credentials.from_authorized_user_file(str(TOKEN), SCOPES)
    if not creds.valid:
        creds.refresh(Request())
        TOKEN.write_text(creds.to_json())
    return build('searchconsole', 'v1', credentials=creds, cache_discovery=False)


def perf(start, end, dims, out):
    """All rows for the date range (pages through the 25,000-row API limit), web search."""
    gsc, rows, start_row = service(), [], 0
    dims = dims.split(',')
    while True:
        r = gsc.searchanalytics().query(siteUrl=SITE, body={
            'startDate': start, 'endDate': end, 'dimensions': dims, 'type': 'web',
            'rowLimit': 25000, 'startRow': start_row, 'dataState': 'all'}).execute().get('rows', [])
        rows += r
        if len(r) < 25000:
            break
        start_row += 25000
    with open(out, 'w', newline='') as f:
        w = csv.writer(f)
        w.writerow(dims + ['clicks', 'impressions', 'ctr', 'position'])
        for r in rows:
            w.writerow(r['keys'] + [r['clicks'], r['impressions'], round(r['ctr'], 4), round(r['position'], 1)])
    print(f'{len(rows)} rows -> {out}')


def inspect(urls):
    gsc = service()
    for u in urls:
        res = None
        for attempt in range(3):   # the inspection API sometimes answers slowly
            try:
                res = gsc.urlInspection().index().inspect(body={'inspectionUrl': u, 'siteUrl': SITE}).execute(num_retries=2)
                break
            except (TimeoutError, OSError) as ex:
                err = ex
        if res is None:
            print(json.dumps({'url': u, 'error': str(err)}))
            continue
        ix = res['inspectionResult'].get('indexStatusResult', {})
        print(json.dumps({'url': u, 'verdict': ix.get('verdict'), 'coverage': ix.get('coverageState'),
                          'lastCrawl': ix.get('lastCrawlTime'), 'fetch': ix.get('pageFetchState'),
                          'robots': ix.get('robotsTxtState'), 'indexing': ix.get('indexingState'),
                          'googleCanonical': ix.get('googleCanonical'), 'userCanonical': ix.get('userCanonical')}))


def main(a):
    if not a:
        sys.exit(__doc__)
    cmd = a[0]
    if cmd == 'login':
        login(a[1])
    elif cmd == 'sites':
        for s in service().sites().list().execute().get('siteEntry', []):
            print(s['siteUrl'], s['permissionLevel'])
    elif cmd == 'sitemaps':
        for s in service().sitemaps().list(siteUrl=SITE).execute().get('sitemap', []):
            print(s['path'], s.get('lastSubmitted'), s.get('lastDownloaded'), 'errors', s.get('errors'),
                  'warnings', s.get('warnings'), [(c.get('type'), c.get('submitted')) for c in s.get('contents', [])])
    elif cmd == 'submit':
        url = a[1] if a[1].startswith('http') else 'https://www.carbonmonoxidekills.com/' + a[1].lstrip('/')
        service().sitemaps().submit(siteUrl=SITE, feedpath=url).execute()
        print('submitted', url)
    elif cmd == 'perf':
        perf(a[1], a[2], a[3], a[4])
    elif cmd == 'inspect':
        inspect(a[1:])
    else:
        sys.exit(__doc__)


if __name__ == '__main__':
    main(sys.argv[1:])
