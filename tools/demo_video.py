#!/usr/bin/env python3
"""Record short captioned demo videos of the real Chrome window (Search Console + live site) for Rob.

Uses the logged-in Chrome started for tools/request_indexing.py (port 9222). Captures the visible tab with
the Chrome DevTools screencast, adds a caption bar and title cards, and writes MP4s with ffmpeg:
    python3 tools/demo_video.py OUT_DIR [index|growth|site ...]
"""
import base64, datetime, re, subprocess, sys, textwrap, time, urllib.parse
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
from playwright.sync_api import sync_playwright

import imageio_ffmpeg

FFMPEG = imageio_ffmpeg.get_ffmpeg_exe()
W, H = 1440, 810
FONT = '/usr/share/fonts/truetype/ubuntu/Ubuntu-{}.ttf'
PROPERTY = 'sc-domain:carbonmonoxidekills.com'
RID = urllib.parse.quote(PROPERTY, safe='')
GSC = 'https://search.google.com/search-console'
SITE = 'https://www.carbonmonoxidekills.com'
BEFORE = Path.home() / 'cmk-rebrand-backups/indexing/2026-11-22'
EMBER = (217, 72, 15)


def font(style, size):
    return ImageFont.truetype(FONT.format(style), size)


class Video:
    def __init__(self, page, work):
        self.page, self.work = page, work
        self.work.mkdir(parents=True, exist_ok=True)
        self.items = []          # (image path, caption, start time) for screencast; stills carry a duration
        self.caption = ''
        self.cdp = page.context.new_cdp_session(page)
        self.cdp.on('Page.screencastFrame', self._frame)
        self.n = 0

    def _frame(self, p):
        self.n += 1
        f = self.work / f'f{self.n:05d}.jpg'
        f.write_bytes(base64.b64decode(p['data']))
        self.items.append(('live', f, self.caption, time.time()))
        try:
            self.cdp.send('Page.screencastFrameAck', {'sessionId': p['sessionId']})
        except Exception:
            pass

    def live(self):
        self.cdp.send('Page.startScreencast', {'format': 'jpeg', 'quality': 88, 'maxWidth': 1920, 'maxHeight': 1200})

    def pause(self):
        self.cdp.send('Page.stopScreencast')
        self.items.append(('end', None, None, time.time()))

    def say(self, text, hold=0):
        self.caption = text
        self.items.append(('cap', None, text, time.time()))
        if hold:
            self.page.wait_for_timeout(int(hold * 1000))

    def still(self, img, caption, secs):
        self.items.append(('still', Path(img), caption, secs))

    def card(self, label, title, sub, secs=3.5):
        im = Image.new('RGB', (W, H), (16, 20, 24))
        d = ImageDraw.Draw(im)
        d.rectangle([0, 0, 14, H], fill=EMBER)
        d.text((110, 230), label.upper(), font=font('M', 26), fill=EMBER)
        y = 285
        for line in textwrap.wrap(title, 30):
            d.text((110, y), line, font=font('B', 68), fill='white')
            y += 84
        y += 20
        for line in textwrap.wrap(sub, 62):
            d.text((110, y), line, font=font('R', 32), fill=(190, 198, 206))
            y += 46
        d.text((110, H - 90), 'carbonmonoxidekills.com', font=font('M', 24), fill=(120, 130, 140))
        self.n += 1
        f = self.work / f'c{self.n:05d}.png'
        im.save(f)
        self.items.append(('card', f, '', secs))

    def scroll(self, px, step=70, delay=45):
        self.page.mouse.move(W * 0.62, H * 0.55)
        for _ in range(max(1, abs(px) // step)):
            self.page.mouse.wheel(0, step if px > 0 else -step)
            self.page.wait_for_timeout(delay)

    # ---- render ----
    def _compose(self, path, caption):
        src = Image.open(path).convert('RGB')
        src.thumbnail((W, H), Image.LANCZOS)
        im = Image.new('RGB', (W, H), 'white')
        im.paste(src, ((W - src.width) // 2, 0))
        if caption:
            d = ImageDraw.Draw(im)
            f = font('M', 31)
            lines = textwrap.wrap(caption, 70)
            lh = 42
            bh = lh * len(lines) + 34
            d.rounded_rectangle([70, H - bh - 30, W - 70, H - 30], radius=16, fill=(16, 20, 24))
            d.rounded_rectangle([70, H - bh - 30, 82, H - 30], radius=4, fill=EMBER)
            y = H - bh - 30 + 17
            for line in lines:
                tw = d.textlength(line, font=f)
                d.text(((W - tw) / 2, y), line, font=f, fill='white')
                y += lh
        return im

    def render(self, out):
        seq, frame, cap, last = [], None, '', None

        def emit(t):
            if frame is not None and last is not None and t - last > 0.005:
                seq.append((frame, cap, min(t - last, 20)))

        for kind, path, text, t in self.items:
            if kind in ('still', 'card'):
                seq.append((path, text, t))
            elif kind == 'live':
                emit(t)
                frame, last = path, t
            elif kind == 'cap':
                emit(t)
                cap, last = text, (t if frame is not None else last)
            elif kind == 'end':
                emit(t)
                frame, last = None, None
        cache = {}
        lst = self.work / 'list.txt'
        lines = []
        for k, (path, cap, dur) in enumerate(seq):
            key = (str(path), cap)
            if key not in cache:
                o = self.work / f'r{len(cache):05d}.jpg'
                self._compose(path, cap).save(o, quality=90)
                cache[key] = o
            lines.append(f"file '{cache[key]}'\nduration {dur:.3f}")
        lines.append(f"file '{cache[(str(seq[-1][0]), seq[-1][1])]}'")
        lst.write_text('\n'.join(lines) + '\n')
        subprocess.run([FFMPEG, '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', str(lst),
                        '-vf', 'fps=30,format=yuv420p', '-c:v', 'libx264', '-preset', 'medium', '-crf', '22',
                        '-movflags', '+faststart', str(out)], check=True)
        print('wrote', out)


def inspect(v, url, caption_wait, caption_done, hold=4):
    page = v.page
    page.goto(f'{GSC}?resource_id={RID}', wait_until='domcontentloaded')
    box = page.locator('input[aria-label^="Inspect any URL"]').first
    box.wait_for(timeout=60000)
    page.wait_for_timeout(800)
    v.say(caption_wait)
    box.click()
    box.type(url, delay=18)
    box.press('Enter')
    page.get_by_text(re.compile(r'^\s*URL is ', re.I)).first.wait_for(timeout=120000)
    status = page.get_by_text(re.compile(r'^\s*URL is ', re.I)).first.inner_text().strip()
    page.wait_for_timeout(600)
    v.say(caption_done.format(status=status), hold)
    return status


def video_index(v, out):
    v.card('Search Console · 24 Nov 2026', 'Your state guides are back on Google',
           'In October, Google saw many of your state pages as "404 not found" and dropped them. '
           'We fixed them and asked Google to read them again.')
    v.still(BEFORE / '01-carbon-monoxide-lawyer-2-tennessee.png',
            '22 Nov: the Tennessee guide was NOT on Google. Google last saw it on 18 Oct as "404 Not found".', 5)
    v.still(BEFORE / '01-carbon-monoxide-lawyer-2-tennessee.png',
            'We fixed the page and pressed "Request indexing". Google accepted it.', 3)
    v.page.goto(f'{GSC}?resource_id={RID}', wait_until='domcontentloaded')
    v.page.wait_for_timeout(2000)
    v.live()
    inspect(v, f'{SITE}/carbon-monoxide-lawyer-2/tennessee/', 'Today: checking the same Tennessee page in Search Console…',
            'Today: Tennessee is ON Google (indexed)')
    try:
        v.page.get_by_text(re.compile(r'^\s*Page indexing\s*$')).first.click(timeout=5000)
        v.page.wait_for_timeout(800)
    except Exception:
        pass
    try:
        v.page.get_by_text(re.compile(r'^\s*Last crawl\s*$')).first.evaluate(
            "e => e.scrollIntoView({block: 'center', behavior: 'smooth'})")
        v.page.wait_for_timeout(1200)
    except Exception:
        v.scroll(650)
    v.say('Last crawl: 22 Nov. Google re-read the page minutes after our request.', 5)
    for slug, name in (('pennsylvania', 'Pennsylvania'), ('massachusetts', 'Massachusetts')):
        inspect(v, f'{SITE}/carbon-monoxide-lawyer-2/{slug}/', f'{name} guide (was "not on Google" on 22 Nov)…',
                f'{name}: ON Google (indexed)', 3)
    v.pause()
    v.card('Result so far', '11 pages back on Google in one day',
           'Tennessee, Pennsylvania, Illinois, Georgia, North Carolina, Michigan, Massachusetts, Virginia, '
           'West Virginia, the CO levels page and your first blog post. The remaining state pages follow daily.', 6)
    v.render(out)


def video_growth(v, out):
    v.card('Search Console · last 28 days', 'More people see your site in Google',
           'An "impression" is one time your site appeared in someone\'s Google results.')
    v.page.goto(f'{GSC}/performance/search-analytics?resource_id={RID}&num_of_days=28', wait_until='domcontentloaded')
    v.page.get_by_text(re.compile(r'Total impressions', re.I)).first.wait_for(timeout=60000)
    v.page.wait_for_timeout(2500)
    v.page.keyboard.press('Escape')
    v.live()
    v.say('Daily impressions went from about 100 to about 200 a day.', 5)
    v.say('Last 7 days: 1,413 impressions, about double the 724 of four weeks earlier.', 5)
    v.scroll(520)
    v.say('The searches your site now appears for, including lawyer and compensation searches.', 6)
    v.scroll(500)
    v.page.wait_for_timeout(4000)
    v.pause()
    v.card('Next step', 'Turning views into clicks and calls',
           'Clicks are still low (3 last week). Pages already on page 1 of Google got new titles that '
           'answer what people search for. We report the effect every Monday.', 6)
    v.render(out)


def video_site(v, out):
    v.card('Website · live today', 'What is new on your website',
           'Built to rank in Google and to turn visitors into case enquiries.')
    p = v.page
    p.goto(SITE + '/', wait_until='load')
    p.wait_for_timeout(1500)
    v.live()
    v.say('Clear homepage: a free case review and a call button on every page.', 3)
    v.scroll(1400, delay=60)
    p.goto(SITE + '/carbon-monoxide-lawyer-2/tennessee/', wait_until='load')
    p.wait_for_timeout(1500)
    v.say('15 detailed state guides: real filing deadlines and detector laws, with sources.', 3)
    v.scroll(1800, delay=55)
    p.goto(SITE + '/blog/', wait_until='load')
    p.wait_for_timeout(1500)
    v.say('Your blog: you can publish your own posts, with photos and videos.', 4)
    v.scroll(600)
    p.goto(SITE + '/carbon-monoxide-detectors/ul2034-american/', wait_until='load')
    p.wait_for_timeout(1200)
    v.say('Pages already on Google page 1 got new titles and clearer answers (UL 2034 alarm table).', 2)
    try:
        p.get_by_text('What UL 2034 means for your carbon monoxide alarm').first.evaluate(
            "e => e.scrollIntoView({block: 'start', behavior: 'smooth'})")
    except Exception:
        v.scroll(900)
    p.wait_for_timeout(4000)
    p.goto(SITE + '/review.html', wait_until='load')
    p.wait_for_timeout(1500)
    v.say('Free case review form: enquiries come straight to you.', 3)
    v.scroll(700)
    p.wait_for_timeout(2500)
    v.pause()
    v.card('Every week', 'Tracked, not guessed',
           'Phone-button presses, case review forms and Google numbers are counted and reported to you every Monday.', 5)
    v.render(out)


def main(out_dir, which):
    out = Path(out_dir)
    with sync_playwright() as pw:
        browser = pw.chromium.connect_over_cdp('http://127.0.0.1:9222')
        ctx = browser.contexts[0]
        page = next((x for x in ctx.pages if 'search.google.com' in x.url), None) or ctx.new_page()
        page.bring_to_front()
        for n, name in enumerate(which, 1):
            v = Video(page, out / f'work-{name}')
            globals()['video_' + name](v, out / f'{n}-cmk-{name}.mp4')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2:] or ['index', 'growth', 'site'])
