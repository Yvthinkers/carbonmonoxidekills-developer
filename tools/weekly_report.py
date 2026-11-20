#!/usr/bin/env python3
"""Weekly numbers for Rob: Google Search (this week vs the week before), phone button presses, case review forms.

    FTP_USER=... FTP_PASS=... python3 tools/weekly_report.py [end-date]

end-date defaults to 3 days ago (Search Console data is complete after about 2-3 days).
Needs the Search Console login from tools/gsc.py. FTP is only used to read the counts in cmk-private/.
"""
import datetime as dt, io, json, os, re, subprocess, sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import gsc  # noqa: E402

LEGAL = re.compile(r'lawyer|attorney|lawsuit|claim|compensation|legal|sue\b', re.I)
FTP_HOST = '107.180.118.108'


def query(start, end, dims, limit=25000):
    body = {'startDate': str(start), 'endDate': str(end), 'type': 'web', 'rowLimit': limit, 'dataState': 'all'}
    if dims:
        body['dimensions'] = dims
    return gsc.service().searchanalytics().query(siteUrl=gsc.SITE, body=body).execute().get('rows', [])


def totals(start, end):
    r = query(start, end, None)
    r = r[0] if r else {'clicks': 0, 'impressions': 0, 'ctr': 0, 'position': 0}
    return r['clicks'], r['impressions'], r['position']


def ftp_lines(path):
    user, pw = os.environ.get('FTP_USER'), os.environ.get('FTP_PASS')
    if not (user and pw):
        return None
    out = subprocess.run(['curl', '-s', '--ssl', '-k', '--ftp-pasv', '-u', f'{user}:{pw}', f'ftp://{FTP_HOST}/{path}'],
                         capture_output=True, text=True).stdout
    return [json.loads(l) for l in out.splitlines() if l.strip().startswith('{')]


def count_since(rows, key, since):
    return sum(1 for r in rows if r.get(key, '') >= since)


def main():
    end = dt.date.fromisoformat(sys.argv[1]) if len(sys.argv) > 1 else dt.date.today() - dt.timedelta(days=3)
    start = end - dt.timedelta(days=6)
    pend, pstart = start - dt.timedelta(days=1), start - dt.timedelta(days=7)
    c, i, p = totals(start, end)
    pc, pi, pp = totals(pstart, pend)
    pct = lambda a, b: f'{(a - b) / b * 100:+.0f}%' if b else 'new'
    print(f'WEEK {start:%d %b} – {end:%d %b %Y}   (compared with {pstart:%d %b} – {pend:%d %b})\n')
    print(f'Clicks from Google        {c:6.0f}   (before {pc:.0f}, {pct(c, pc)})')
    print(f'Times shown in Google     {i:6.0f}   (before {pi:.0f}, {pct(i, pi)})')
    print(f'Average position          {p:6.1f}   (before {pp:.1f}; lower is better)')

    now = {r['keys'][0]: r for r in query(start, end, ['query'])}
    before = {r['keys'][0]: r for r in query(pstart, pend, ['query'])}
    legal = sorted((r for q, r in now.items() if LEGAL.search(q)), key=lambda r: -r['impressions'])[:12]
    print('\nLawyer searches (position now, before):')
    for r in legal:
        q = r['keys'][0]
        b = f"{before[q]['position']:.0f}" if q in before else 'new'
        print(f"  {r['position']:5.1f}  ({b:>4})  {r['impressions']:4.0f} shown  {r['clicks']:2.0f} clicks  {q}")

    pages = sorted(query(start, end, ['page']), key=lambda r: -r['impressions'])[:10]
    print('\nPages shown most:')
    for r in pages:
        print(f"  {r['impressions']:5.0f} shown  {r['clicks']:2.0f} clicks  pos {r['position']:5.1f}  {r['keys'][0].replace('https://www.carbonmonoxidekills.com', '')}")

    since, until = f'{start}T00:00', f'{end}T23:59:59'
    months = sorted({start.strftime('%Y-%m'), end.strftime('%Y-%m')})
    calls = ftp_lines(f'cmk-private/calls/{months[-1]}.jsonl')
    if calls is None:
        print('\n(Set FTP_USER and FTP_PASS to add phone button presses and case review forms.)')
        return
    for m in months[:-1]:
        calls = (ftp_lines(f'cmk-private/calls/{m}.jsonl') or []) + calls
    forms = []
    for m in months:
        forms += [r for r in (ftp_lines(f'cmk-private/case-reviews/{m}.jsonl') or []) if 'TEST' not in r.get('Name', '')]
    in_week = lambda rows, key: [r for r in rows if since <= r.get(key, '').replace(' UTC', '').replace(' ', 'T') <= until]
    wc, wf = in_week(calls, 't'), in_week(forms, 'received')
    print(f'\nPhone button presses      {len(wc):6d}')
    print(f'Case review forms         {len(wf):6d}')


if __name__ == '__main__':
    main()
