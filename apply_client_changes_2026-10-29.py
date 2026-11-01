#!/usr/bin/env python3
"""Rob Aiers' change list (2026-10-29). Each edit must match exactly the expected number of times."""
from pathlib import Path

V3 = Path(__file__).parent / 'v3'
C = V3 / 'content'
fails = []

def sub(rel, old, new, count=1):
    p = V3 / rel
    s = p.read_text()
    n = s.count(old)
    if n != count:
        fails.append(f'{rel}: expected {count}, found {n}: {old[:70]!r}')
        return
    p.write_text(s.replace(old, new))

# 1. "Over $25 million awarded to victims who we have had the pleasure of helping with our local partners"
M25 = 'Over $25 million awarded to victims who we have had the pleasure of helping with our local partners.'
sub('content/home.html', '<div><b>Since 2010</b><span>Specializing in one poison</span></div>',
    '<div><b>Over $25 million</b><span>Awarded to victims we have helped with our local partners</span></div>\n      <div><b>Since 1999</b><span>Specializing in one poison</span></div>')
sub('content/home.html', '<p class="sig rv">Rob Aiers · Founder',
    f'<p class="rv" style="margin-top:22px;font-weight:600">{M25}</p>\n      <p class="sig rv">Rob Aiers · Founder')
sub('content/home.html', '<span>Acute &amp; long-term exposure cases</span>',
    '<span>Over $25 million awarded to victims</span><span>Acute &amp; long-term exposure cases</span>')
sub('content/home.html', '<span aria-hidden="true">Acute &amp; long-term exposure cases</span>',
    '<span aria-hidden="true">Over $25 million awarded to victims</span><span aria-hidden="true">Acute &amp; long-term exposure cases</span>')
sub('content/about.html', '<div><b>Since 2010</b><span>Online and helping families</span></div>',
    '<div><b>Over $25 million</b><span>Awarded to victims we have helped with our local partners</span></div>\n        <div><b>Since 1999</b><span>Helping to protect families</span></div>')
sub('content/review.html', '<div><b>Since 2010</b><span>Carbon monoxide specialists</span></div>',
    '<div><b>Over $25 million</b><span>Awarded to victims we have helped with our local partners</span></div>\n        <div><b>Since 1999</b><span>Carbon monoxide specialists</span></div>')

# 2. Generator: serviceable + safety tag if rented
GEN_OLD = '<b>Rob’s view —</b> at least 20 feet from the house, never in a garage, exhaust pointing away from doors and windows.'
GEN_NEW = '<b>Rob’s view —</b> at least 20 feet from the house, never in a garage, exhaust pointing away from doors and windows. Make sure the generator is serviceable, and if it is rented, make sure it has a safety tag.'
sub('content/home.html', GEN_OLD, GEN_NEW)
sub('content/news.html', GEN_OLD, GEN_NEW)
sub('content/article.html', 'And have a working carbon monoxide alarm inside.</p>',
    'And have a working carbon monoxide alarm inside. Make sure the generator is serviceable, and if it is rented, make sure it has a safety tag.</p>')

# 3. Author box: since 2010 -> since 1999
sub('build.py', 'Founder of Carbon Monoxide Kills and an acknowledged authority on carbon monoxide poisoning since 2010.',
    'Founder of Carbon Monoxide Kills. Since 1999 we have been helping to protect people from carbon monoxide poisoning.')

# 4. Detectors: no date -> permanent marker; battery life 5-7 years; few cents a month
DET_OLD = '<b>Rob’s view —</b> test monthly, and replace on the date printed on the alarm.'
DET_NEW = ('<b>Rob’s view —</b> test monthly, and replace on the date printed on the alarm. If there is no date printed on the alarm, '
           'write the date on it with a permanent marker. The alarm will last the life of its battery: when it starts beeping '
           '(between 5 and 7 years), it’s time to replace it. CO alarms are not expensive, but will potentially save your life '
           'for the cost of just a few cents a month.')
sub('content/home.html', DET_OLD, DET_NEW)
sub('content/news.html', DET_OLD, DET_NEW)
sub('content/detectors.html',
    '<li><span><b>Replace the whole alarm</b> by the date printed on it, typically every 5 to 10 years. Sensors wear out.</span></li>',
    '<li><span><b>Replace the whole alarm</b> by the date printed on it. If there is no date printed on the alarm, write the date on it with a permanent marker. The alarm will last the life of its battery: when it starts beeping (between 5 and 7 years), it’s time to replace it. Sensors wear out.</span></li>\n'
    '  <li><span><b>Don’t put it off.</b> CO alarms are not expensive, but will potentially save your life for the cost of just a few cents a month.</span></li>')

# 5. Invisible threat: appliances serviced
sub('content/news.html', '<b>Rob’s view —</b> the alarm is the only warning you will get.</p>',
    '<b>Rob’s view —</b> the alarm is the only warning you will get. But you can protect yourself by making sure that your appliances are serviced and in good condition.</p>')

# 6. Rented homes "In brief": landlord duty of care
sub('content/rented-homes.html', 'equipment is often repaired or replaced within days.</p></div>',
    'equipment is often repaired or replaced within days.</p><p>Your landlord has a duty of care: they are responsible for your safety and, in nearly all cases, for the installation of a carbon monoxide alarm. You should have records of all checks done at your rented home.</p></div>')

# 7. Facts pull quote replaced
sub('content/facts.html', '<p class="pull">The danger is rarely the fuel itself. <em>It is where the exhaust goes.</em></p>',
    '<p class="pull">Fuel that is burned inefficiently will produce carbon monoxide. <em>This could endanger your life, or even impair your health permanently.</em></p>')

# 8. Byline: drop "[attorney or physician] to confirm"
sub('build.py', '<span>Reviewed by <b>[attorney or physician]</b> <span class="tbc">to confirm</span></span>',
    '<span>We have over 25 years dealing with CO cases. We know what makes a case and what doesn’t.</span>')

# 9. Facts hero lede replaced
sub('content/facts.html', '"lede":"A colorless, odorless, tasteless gas that the body mistakes for oxygen. Here is what it is, where it comes from and why it is so often missed."',
    '"lede":"Your body has an affinity for carbon monoxide that is much higher than for oxygen. It will take up CO in favor of oxygen, and this prevents oxygen being transported to every cell in your body. Your body needs oxygen to survive."')

# 10. Closing band: "prove the invisible alone" -> "We will help prove a case by reviewing the evidence"
sub('build.py', "h = b.get('h', 'You shouldn’t have to prove the invisible <em class=\"ember\">alone.</em>')",
    "h = b.get('h', 'We will help prove a case by <em class=\"ember\">reviewing the evidence.</em>')")

# Consistency: since 1999 / over 25 years (Rob's own figures above)
sub('content/home.html', 'For more than fifteen years we have helped families', 'For over 25 years we have helped families')
for f in ('home.html', 'cases.html', 'review.html'):
    sub(f'content/{f}', 'reviewed against fifteen years of carbon monoxide cases', 'reviewed against over 25 years of carbon monoxide cases')
sub('content/about.html', '"h1":"One poison. Fifteen years. <em class=\\"ember\\">Every question answered.</em>"',
    '"h1":"One poison. Over 25 years. <em class=\\"ember\\">Every question answered.</em>"')
sub('content/about.html', 'Over more than fourteen years, Rob has built', 'Since 1999, Rob has built')
sub('content/about.html', '<li><b>2010</b><span>Carbon Monoxide Kills goes online.</span></li>',
    '<li><b>1999</b><span>Rob begins helping to protect people from carbon monoxide.</span></li>\n        <li><b>2010</b><span>Carbon Monoxide Kills goes online.</span></li>')

# 11. SEO: USA, every state. Carbon monoxide lawyer / poisoning / injury / state by state
sub('content/home.html',
    '{"title":"Carbon Monoxide Kills · The carbon monoxide injury specialists","desc":"Carbon monoxide injury and wrongful death specialists since 2010. Free, confidential case review and partner attorneys in all 50 states and DC.',
    '{"title":"Carbon Monoxide Lawyer, Poisoning & Injury Help in All 50 States · Carbon Monoxide Kills","desc":"Carbon monoxide lawyer help for poisoning, injury and wrongful death cases in every US state. Over $25 million awarded to victims. Free, confidential case review: 1-888-414-8680.')
sub('content/home.html', '<p class="label ember">Carbon monoxide injury &amp; wrongful death · United States</p>',
    '<p class="label ember">Carbon monoxide lawyer · poisoning &amp; injury · all 50 states</p>')
sub('content/home.html', '<h2 class="h-1 rv" style="margin-top:20px">Partner attorneys where you live.</h2>',
    '<h2 class="h-1 rv" style="margin-top:20px">A carbon monoxide lawyer in every state.</h2>')
sub('content/lawyers.html', '{"title":"Find a carbon monoxide lawyer in your state","desc":"Find an experienced carbon monoxide poisoning attorney in your state.',
    '{"title":"Carbon Monoxide Lawyer: State by State, All 50 States & DC","desc":"Find an experienced carbon monoxide lawyer in your state for poisoning and injury cases.')
sub('content/lawyers.html', '<h2 class="h-1 rv" style="margin-top:20px">Choose your state.</h2>',
    '<h2 class="h-1 rv" style="margin-top:20px">Carbon monoxide lawyers, state by state.</h2>')
sub('content/cases.html', '{"title":"Carbon monoxide cases we handle"', '{"title":"Carbon monoxide poisoning and injury cases we handle"')
sub('content/facts.html', '{"title":"Carbon monoxide facts"', '{"title":"Carbon monoxide facts: what it is and how it harms you"')

# The 1-888 number is now live: make the remaining plain-text mention a link
sub('content/faq.html', 'Call us on 1-888-414-8680 or send a message.',
    'Call us on <a href=\\"tel:+18884148680\\" style=\\"color:inherit\\">1-888-414-8680</a> or send a message.')

if fails:
    print('FAILED:'); print('\n'.join(fails)); raise SystemExit(1)
print('all edits applied')
