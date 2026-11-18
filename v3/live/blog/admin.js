/* Blog editor (admin.php): photos, videos, Google preview, search checklist and a draft kept in this browser. */
(() => {
  const $ = id => document.getElementById(id);

  // ----- dashboard: confirm deletes; forget the browser copy of a post once it is saved -----
  document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
  const dash = $('ba-dash');
  if (dash && dash.dataset.done) {
    try { localStorage.removeItem('cmkblog-draft-new'); localStorage.removeItem('cmkblog-draft-' + dash.dataset.done); } catch (e) {}
  }

  const form = $('ba-form');
  if (!form) return;
  const title = $('ba-title'), body = $('ba-body'), desc = $('ba-desc'), alt = $('ba-alt'), state = $('ba-state');
  const coverUrl = $('ba-cover-url'), coverImg = $('ba-cover-img'), file = $('ba-file');
  const csrf = form.querySelector('[name=csrf]').value;
  const BRAND = ' · Carbon Monoxide Kills';
  const MEDIA = /^(https:\/\/www\.carbonmonoxidekills\.com)?\/blog\/media\/[0-9]{6}-[0-9a-f]{10}\.jpg$/;
  const YOUTUBE = /^https?:\/\/(www\.|m\.)?(youtube\.com\/(watch\?(\S*&)?v=|shorts\/|embed\/|live\/)|youtu\.be\/)[A-Za-z0-9_-]{11}/;

  // ----- text helpers (same rules as _lib.php) -----
  const plain = t => t.split('\n').map(l => l.trim())
    .filter(l => !l.startsWith('## ') && !MEDIA.test(l) && !YOUTUBE.test(l))
    .join(' ').replace(/^[-*•] /gm, '').replace(/(^|\s)[-*•] /g, '$1').replace(/\*\*/g, '').replace(/\s+/g, ' ').trim();
  const words = t => (plain(t).match(/[A-Za-z0-9'’-]+/g) || []).length;
  const excerpt = (t, max) => { t = plain(t); if (t.length <= max) return t; t = t.slice(0, max); const sp = t.lastIndexOf(' '); return (sp > 0 ? t.slice(0, sp) : t).replace(/[ ,.;:-]+$/, '') + '…'; };
  const slugify = t => (t.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 70).replace(/-[^-]*$/, m => t.length > 70 ? '' : m)) || 'post';

  // ----- Google preview and search checklist -----
  function refresh() {
    const t = title.value.trim(), b = body.value, d = desc.value.trim(), n = words(b);
    const shown = (t + BRAND).length <= 60 ? t + BRAND : t;
    $('ba-tcount').textContent = t.length + ' characters' + (t.length > 60 ? ' · Google shows about 60, so the end may be cut off' : '');
    $('ba-dcount').textContent = d ? d.length + ' characters' + (d.length > 160 ? ' · Google shows about 155–160' : '') : 'Empty: Google gets the start of the post.';
    $('ba-words').textContent = n + ' words';
    $('ba-serp-url').textContent = 'www.carbonmonoxidekills.com › blog › ' + (form.dataset.slug || slugify(t || 'your post'));
    $('ba-serp-title').textContent = (shown.length > 60 ? shown.slice(0, 59) + '…' : shown) || 'Your title';
    $('ba-serp-desc').textContent = d || excerpt(b, 155) || 'The start of your post appears here.';
    const checks = [
      [t.length >= 30 && t.length <= 60, t.length > 60 ? `Shorten the title to about 60 characters (now ${t.length}).` : t.length < 30 ? 'Make the title a little longer and more specific (30–60 characters).' : 'Title is a good length.'],
      [/carbon monoxide|\bCO\b/i.test(t), /carbon monoxide|\bCO\b/i.test(t) ? 'Title mentions carbon monoxide.' : 'Use the words “carbon monoxide” in the title: that is what people search for.'],
      [n >= 300, n >= 300 ? `${n} words: a good length.` : `${n} words. Posts of 300 words or more usually do better in Google.`],
      [/^## /m.test(b), /^## /m.test(b) ? 'Has subheadings.' : 'Add a subheading or two (a line starting with ## ) to break up longer posts.'],
      [!!coverUrl.value && alt.value.trim().length > 3, !coverUrl.value ? 'Add a cover photo: posts with a photo get more clicks when shared.' : alt.value.trim().length > 3 ? 'Cover photo is described.' : 'Describe the cover photo in a few words.'],
      [!!state.value, state.value ? `Linked to the ${state.value} lawyer page.` : 'If the post is about a place, choose its state.'],
      [/https?:\/\//.test(b), /https?:\/\//.test(b) ? 'Links to a source.' : 'Link to your source (a news story, CDC or fire department) if there is one.'],
      [!d || (d.length >= 70 && d.length <= 160), !d ? 'Google description: the start of the post will be used.' : d.length < 70 ? 'Google description is short; aim for 70–160 characters.' : d.length > 160 ? 'Google description is long; aim for 70–160 characters.' : 'Google description is a good length.'],
    ];
    $('ba-checks').innerHTML = '';
    checks.forEach(([ok, text]) => { const li = document.createElement('li'); li.className = ok ? 'ok' : ''; li.textContent = text; $('ba-checks').append(li); });
  }

  // ----- the browser keeps a copy while Rob types, so nothing is lost if the page closes or the login expires -----
  const key = form.dataset.draft;
  const snapshot = () => ({ title: title.value, body: body.value, desc: desc.value, alt: alt.value, cover: coverUrl.value, state: state.value });
  const apply = s => { title.value = s.title || ''; body.value = s.body || ''; desc.value = s.desc || ''; alt.value = s.alt || ''; state.value = s.state || ''; setCover(s.cover || ''); };
  let timer;
  const keep = () => { clearTimeout(timer); timer = setTimeout(() => { try { localStorage.setItem(key, JSON.stringify(snapshot())); } catch (e) {} }, 400); };
  const changed = () => { refresh(); keep(); };

  // ----- photos -----
  async function shrink(f) {   // at most 2000 px, upright, as JPEG: quicker to upload, and camera/location data is dropped
    try {
      const bmp = await createImageBitmap(f, { imageOrientation: 'from-image' });
      const s = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
      const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(bmp, 0, 0, c.width, c.height);
      return (await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9))) || f;
    } catch (e) { return f; }
  }
  async function upload(f, msg) {
    msg.textContent = 'Uploading the photo…';
    const fd = new FormData();
    fd.append('do', 'upload'); fd.append('csrf', csrf); fd.append('photo', await shrink(f), 'photo.jpg');
    let j;
    try { j = await (await fetch('/blog/admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json(); }
    catch (e) { j = { error: 'The photo could not be uploaded. Please check the internet connection and try again.' }; }
    msg.textContent = j.error || '';
    return j.error ? null : j;
  }
  function setCover(url) {
    coverUrl.value = url;
    coverImg.hidden = !url;
    if (url) coverImg.src = url; else coverImg.removeAttribute('src');
    $('ba-cover-remove').hidden = !url;
    $('ba-cover-pick').textContent = url ? 'Change photo' : 'Choose a photo';
  }
  let pickFor = 'cover';
  $('ba-cover-pick').addEventListener('click', () => { pickFor = 'cover'; file.click(); });
  $('ba-add-photo').addEventListener('click', () => { pickFor = 'body'; file.click(); });
  $('ba-cover-remove').addEventListener('click', () => { setCover(''); changed(); });
  file.addEventListener('change', async () => {
    const f = file.files[0]; file.value = '';
    if (!f) return;
    const msg = pickFor === 'cover' ? $('ba-cover-msg') : $('ba-body-msg');
    const res = await upload(f, msg);
    if (!res) return;
    if (pickFor === 'cover') { setCover(res.url); if (!alt.value.trim()) alt.focus(); }
    else { insert(res.url, true); msg.textContent = 'Photo added. Type a caption on the line under it if you like.'; }
    changed();
  });

  // ----- inserting a photo or video where the cursor is (or at the end) -----
  let touched = false;
  body.addEventListener('focus', () => { touched = true; });
  // A photo or video is a block of its own. After a photo the cursor waits on the caption line under it;
  // after a video it moves to a new paragraph.
  function insert(text, caption) {
    const s = touched ? body.selectionStart : body.value.length, e = touched ? body.selectionEnd : s;
    const before = body.value.slice(0, s).replace(/\s+$/, ''), after = body.value.slice(e).replace(/^\s+/, '');
    const head = (before ? before + '\n\n' : '') + text + (caption ? '\n' : '\n\n');
    body.value = head + (caption && after ? '\n\n' : '') + after;
    body.focus(); touched = true;
    body.setSelectionRange(head.length, head.length);
  }
  $('ba-add-video').addEventListener('click', () => {
    const url = (prompt('Paste the link of the YouTube video (for example https://www.youtube.com/watch?v=...)') || '').trim();
    if (!url) return;
    if (!YOUTUBE.test(url)) { $('ba-body-msg').textContent = 'That doesn’t look like a YouTube link. Copy it from the address bar or YouTube’s Share button.'; return; }
    insert(url, false);
    $('ba-body-msg').textContent = 'Video added.';
    changed();
  });

  // ----- start -----
  try {
    const saved = JSON.parse(localStorage.getItem(key) || 'null');
    const now = snapshot();
    if (saved && JSON.stringify(saved) !== JSON.stringify(now) && (saved.title || saved.body)) {
      apply(saved);
      $('ba-restored').hidden = false;
      $('ba-discard').addEventListener('click', e => { e.preventDefault(); apply(now); $('ba-restored').hidden = true; localStorage.removeItem(key); refresh(); });
    }
  } catch (e) {}
  [title, body, desc, alt].forEach(el => el.addEventListener('input', changed));
  state.addEventListener('change', changed);
  refresh();
})();
