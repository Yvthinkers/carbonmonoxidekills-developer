<?php
/*
 * Blog editor for Rob: log in with the password, press "New post", type a title and the text, publish.
 * Also: cover photo and photos in the text, YouTube videos, a state tag, a Google description, drafts
 * and previews, edit and delete. Behaviour in the browser: admin.js; styles: admin.css.
 * Password hash: cmk-private/blog-auth.php (not in git, not in deploys):
 *   <?php return ['hash' => '<password_hash() output>'];
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

const MAX_FAILS = 8;               // wrong passwords per IP address in 15 minutes
const SELF = '/blog/admin.php';
const UPLOAD_MAX = 25 * 1024 * 1024;
const PHOTO_WIDTH = 1600;          // photos are stored at most this wide, as JPEG

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$loggedIn = admin_session();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$do = $post && is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
$tokenOk = $post && hash_equals($_SESSION['csrf'], is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '');

if ($do === 'upload' && (!$tokenOk || !$loggedIn)) {
    json_out(['error' => 'You were logged out. Save your post as a draft, log in again and add the photo.'], 403);
}
if ($post && !$tokenOk) {
    login_page($do === 'save' ? 'You were logged out, so the post was not saved yet. Your text is kept in this browser: log in and open the post again.' : 'Please log in again.');
}
if ($do === 'login') {
    do_login();
}
if (!$loggedIn) {
    login_page();
}

try {
    match ($do) {
        'save' => do_save(),
        'delete' => do_delete(),
        'upload' => do_upload(),
        'logout' => do_logout(),
        default => null,
    };
} catch (Throwable $ex) {
    error_log('blog admin: ' . $ex->getMessage());
    if ($do === 'upload') {
        json_out(['error' => 'The photo could not be saved. Please try a different photo.'], 500);
    }
    dashboard('Sorry, something went wrong and nothing was changed. Please try again, or send this to your web developer: ' . $ex->getMessage());
}

if (isset($_GET['new'])) {
    editor(null);
}
if (is_string($_GET['edit'] ?? null)) {
    $p = find_post(load_posts(), 'id', $_GET['edit']);
    $p ? editor($p) : dashboard('That post was not found.');
}
dashboard();

// ---------- actions ----------

function go(string $query = ''): never
{
    header('Location: ' . SELF . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fails_file(): string
{
    return BLOG_DIR . '/login-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.txt';
}

function recent_fails(): array
{
    $f = fails_file();
    return is_file($f) ? array_filter(array_map('intval', file($f, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > time() - 900) : [];
}

function do_login(): never
{
    if (count(recent_fails()) >= MAX_FAILS) {
        login_page('Too many wrong passwords. Please wait 15 minutes and try again.');
    }
    $auth = is_file(AUTH_FILE) ? include AUTH_FILE : null;
    if (!is_array($auth) || empty($auth['hash'])) {
        login_page('The blog editor is not set up yet: the password file is missing.');
    }
    $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (password_verify($pw, $auth['hash'])) {
        session_regenerate_id(true);
        $_SESSION = ['ok' => true, 'since' => time(), 'csrf' => bin2hex(random_bytes(32))];
        @unlink(fails_file());
        go();
    }
    if (!is_dir(BLOG_DIR)) {
        @mkdir(BLOG_DIR, 0700, true);
    }
    @file_put_contents(fails_file(), implode("\n", [...recent_fails(), time()]) . "\n", LOCK_EX);
    sleep(1);
    login_page('That password is not right.');
}

function do_logout(): never
{
    $_SESSION = [];
    session_destroy();
    setcookie('cmkblog', '', ['expires' => 1, 'path' => '/blog/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    go();
}

function field(string $key): string
{
    $v = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    return trim(str_replace(["\r\n", "\r"], "\n", (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $v)));
}

function one_line(string $key): string
{
    return (string) preg_replace('/\s+/u', ' ', field($key));
}

function do_save(): never
{
    $id = field('id');
    $publish = field('status') !== 'draft';
    $state = one_line('state');
    $cover = one_line('cover');
    $d = [
        'id' => $id, 'title' => one_line('title'), 'body' => field('body'), 'status' => $publish ? 'published' : 'draft',
        'state' => isset(states()[$state]) ? $state : '', 'cover' => media($cover) ? $cover : '',
        'cover_alt' => cut(one_line('cover_alt'), 200), 'meta_desc' => cut(one_line('meta_desc'), DESC_MAX),
    ];
    if (len($d['title']) < 5) {
        editor($d, 'Please give the post a title (at least 5 characters).');
    }
    if (len($d['title']) > TITLE_MAX) {
        editor($d, 'The title is too long. Please keep it under ' . TITLE_MAX . ' characters.');
    }
    if ($publish && str_word_count(plain($d['body'])) < 20) {
        editor($d, 'The description is very short. Please write at least a few sentences (20 words or more), or save it as a draft for now.');
    }
    if (len($d['body']) > BODY_MAX) {
        editor($d, 'The description is too long for one post. Please split it into two posts.');
    }
    $savedId = $id;
    update_posts(function (array $posts) use ($d, $id, &$savedId) {
        $now = gmdate('c');
        $fields = ['title', 'body', 'status', 'state', 'cover', 'cover_alt', 'meta_desc'];
        if ($id !== '') {
            foreach ($posts as &$p) {
                if ($p['id'] !== $id) {
                    continue;
                }
                $wasPublished = is_published($p);
                foreach ($fields as $f) {
                    $p[$f] = $d[$f];
                }
                if (!$wasPublished) {
                    // Not public yet: the address still follows the title, and the date is the day it goes live.
                    $p['slug'] = unique_slug(slugify($d['title']), $posts, $id);
                    $p['created'] = $now;
                }
                $p['updated'] = $now;
                return $posts;
            }
            throw new RuntimeException('post ' . $id . ' no longer exists');
        }
        $savedId = bin2hex(random_bytes(6));
        $new = ['id' => $savedId, 'slug' => unique_slug(slugify($d['title']), $posts)];
        foreach ($fields as $f) {
            $new[$f] = $d[$f];
        }
        $posts[] = $new + ['created' => $now, 'updated' => $now];
        return $posts;
    });
    go(($publish ? 'saved=' : 'drafted=') . rawurlencode($savedId));
}

function do_delete(): never
{
    $id = field('id');
    update_posts(fn(array $posts) => array_filter($posts, fn($p) => $p['id'] !== $id));
    go('deleted=1');
}

/** A photo from the editor: checked, turned upright, resized, saved as a fresh JPEG (location and camera data dropped). */
function do_upload(): never
{
    $f = $_FILES['photo'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        json_out(['error' => 'The photo did not arrive. Please try again.'], 400);
    }
    if ($f['size'] > UPLOAD_MAX) {
        json_out(['error' => 'That photo is too big (over 25 MB).'], 400);
    }
    $info = @getimagesize($f['tmp_name']);
    $type = $info[2] ?? 0;
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        json_out(['error' => 'Please choose a JPEG, PNG or WebP photo.'], 400);
    }
    [$w, $h] = $info;
    if ($w < 200 || $h < 120 || $w * $h > 30_000_000) {
        json_out(['error' => $w < 200 ? 'That photo is too small. Please use one at least 800 pixels wide.' : 'That photo is too large. Please use a smaller one.'], 400);
    }
    @ini_set('memory_limit', '256M');
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']),
    };
    if (!$src) {
        json_out(['error' => 'That photo could not be read. Please try a different one.'], 400);
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $src = imagerotate($src, $angle, 0);
            [$w, $h] = [imagesx($src), imagesy($src)];
        }
    }
    $nw = min($w, PHOTO_WIDTH);
    $nh = (int) round($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // transparent PNGs on white
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imageinterlace($dst, true);
    if (!is_dir(MEDIA_DIR) && !mkdir(MEDIA_DIR, 0755, true) && !is_dir(MEDIA_DIR)) {
        throw new RuntimeException('cannot create ' . MEDIA_DIR);
    }
    $name = gmdate('Ym') . '-' . bin2hex(random_bytes(5)) . '.jpg';
    if (!imagejpeg($dst, MEDIA_DIR . '/' . $name, 84)) {
        throw new RuntimeException('cannot write photo');
    }
    imagedestroy($src);
    imagedestroy($dst);
    json_out(['url' => MEDIA_URL . $name, 'width' => $nw, 'height' => $nh]);
}

// ---------- pages ----------

function admin_page(string $title, string $h1, string $content, bool $js = false): never
{
    $v = '?v=' . @filemtime(__DIR__ . '/admin.js');
    page(
        head_tags($title . ' · Rob’s blog', 'Blog editor', '/blog/admin.php', false, 'website', "\n" . '<link rel="stylesheet" href="/blog/admin.css' . $v . '">'),
        hero([['Blog', '/blog/'], ['Editor', null]], 'Blog editor', $h1)
        . '<section class="blog-admin"><div class="wrap">' . $content . '</div></section>'
        . ($js ? '<script src="/blog/admin.js' . $v . '" defer></script>' : '')
    );
    exit;
}

function token(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function note(string $msg, bool $error = false): string
{
    return $msg === '' ? '' : '<p class="' . ($error ? 'formerr' : 'ba-note') . '" role="' . ($error ? 'alert' : 'status') . '">' . $msg . '</p>';
}

function login_page(string $error = ''): never
{
    admin_page('Log in', 'Log in to write a post.', note(e($error), true)
        . '<form class="formcard" method="post" action="' . SELF . '" style="max-width:520px;margin:0">' . token()
        . '<input type="hidden" name="do" value="login">'
        . '<div class="field"><label for="pw">Password</label><input id="pw" name="password" type="password" required autocomplete="current-password" autofocus></div>'
        . '<button class="btn btn-ember" type="submit">Log in <span class="arr">→</span></button></form>');
}

function dashboard(string $error = ''): never
{
    $posts = load_posts();
    $msg = '';
    if (is_string($_GET['saved'] ?? null) && ($p = find_post($posts, 'id', $_GET['saved']))) {
        $msg = 'Published. <a href="' . e(post_url($p)) . '" target="_blank" rel="noopener">See it on the website →</a>';
    } elseif (is_string($_GET['drafted'] ?? null) && ($p = find_post($posts, 'id', $_GET['drafted']))) {
        $msg = 'Saved as a draft. It is not on the website yet. <a href="/blog/?preview=' . e($p['id']) . '" target="_blank" rel="noopener">Preview it →</a>';
    } elseif (isset($_GET['deleted'])) {
        $msg = 'The post was deleted.';
    }
    $rows = '';
    foreach ($posts as $p) {
        $pub = is_published($p);
        $tags = ($pub ? '' : '<em class="ba-tag">Draft</em>') . (($p['state'] ?? '') !== '' ? '<em class="ba-tag st">' . e($p['state']) . '</em>' : '');
        $rows .= '<li>' . (($c = cover($p)) ? img_tag($c, '', ' class="ba-thumb" loading="lazy"') : '<span class="ba-thumb"></span>')
            . '<div class="ba-t"><b>' . e($p['title']) . '</b><small>' . ($pub ? strtoupper(e(nice_date($p['created']))) : 'LAST SAVED ' . strtoupper(e(nice_date($p['updated'])))) . $tags . '</small></div><div class="ba-acts">'
            . ($pub ? '<a href="' . e(post_url($p)) . '" target="_blank" rel="noopener">View</a>' : '<a href="/blog/?preview=' . e($p['id']) . '" target="_blank" rel="noopener">Preview</a>')
            . '<a href="' . SELF . '?edit=' . e($p['id']) . '">Edit</a>'
            . '<form method="post" action="' . SELF . '" data-confirm="Delete “' . e($p['title']) . '”? This cannot be undone.">' . token()
            . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . e($p['id']) . '"><button class="del" type="submit">Delete</button></form>'
            . '</div></li>';
    }
    $done = $_GET['saved'] ?? $_GET['drafted'] ?? '';
    $count = count(public_posts($posts));
    $drafts = count($posts) - $count;
    admin_page('Your posts', 'Your blog posts.', note(e($error), true) . note($msg) . call_stats()
        . '<div class="ba-bar"><h2>' . $count . ' published' . ($drafts ? ' · ' . $drafts . ' draft' . ($drafts === 1 ? '' : 's') : '') . '</h2>'
        . '<a class="btn btn-ember" href="' . SELF . '?new=1">+ New post</a></div>'
        . ($rows ? '<ul class="ba-list">' . $rows . '</ul>' : '<p class="ba-empty">No posts yet. Press <b>+ New post</b> to write the first one.</p>')
        . '<form method="post" action="' . SELF . '" style="margin-top:48px">' . token() . '<input type="hidden" name="do" value="logout">'
        . '<button class="btn btn-line" type="submit">Log out</button></form>'
        . '<div id="ba-dash" data-done="' . e(is_string($done) ? $done : '') . '" hidden></div>', true);
}

/** Presses on the website's phone buttons (logged by /call-click.php): last 7 and 30 days, and the top pages. */
function call_stats(): string
{
    $dir = PRIVATE_DIR . '/calls';
    $since7 = gmdate('c', time() - 7 * 86400);
    $since30 = gmdate('c', time() - 30 * 86400);
    $n7 = $n30 = 0;
    $pages = [];
    foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $m) {
        $f = "$dir/$m.jsonl";
        foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [] as $line) {
            $r = json_decode($line, true);
            if (!is_array($r) || ($r['t'] ?? '') < $since30) {
                continue;
            }
            $n30++;
            $n7 += $r['t'] >= $since7 ? 1 : 0;
            $pages[$r['page']] = ($pages[$r['page']] ?? 0) + 1;
        }
    }
    arsort($pages);
    $top = implode(', ', array_map(fn($p, $n) => e($p) . ' (' . $n . ')', array_keys(array_slice($pages, 0, 3, true)), array_slice($pages, 0, 3, true)));
    return '<p class="ba-note">Phone button presses on the website: <b>' . $n7 . '</b> in the last 7 days, <b>' . $n30 . '</b> in the last 30 days.'
        . ($top !== '' ? ' Most from: ' . $top . '.' : '') . '</p>';
}

function editor(?array $p, string $error = ''): never
{
    $isNew = $p === null || ($p['id'] ?? '') === '';
    $id = $p['id'] ?? '';
    $stored = $isNew ? null : find_post(load_posts(), 'id', $id);
    $live = $stored && is_published($stored);
    $cover = media($p['cover'] ?? '');
    $opts = '<option value="">No particular state</option>';
    foreach (array_keys(states()) as $s) {
        $opts .= '<option' . (($p['state'] ?? '') === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    $slug = $stored['slug'] ?? '';
    admin_page($isNew ? 'New post' : 'Edit post', $isNew ? 'Write a new post.' : ($live ? 'Edit your post.' : 'Edit your draft.'), note(e($error), true)
        . '<form class="formcard" method="post" action="' . SELF . '" id="ba-form" data-draft="cmkblog-draft-' . e($isNew ? 'new' : $id) . '"'
        . ' data-slug="' . e($live ? $slug : '') . '" style="max-width:none">' . token()
        . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<p class="ba-note" id="ba-restored" hidden>We brought back text you hadn’t saved yet. <a href="#" id="ba-discard">Discard it</a></p>'

        . '<div class="field"><label for="ba-title">Title</label><input id="ba-title" name="title" required minlength="5" maxlength="' . TITLE_MAX . '" value="' . e($p['title'] ?? '') . '" placeholder="For example: Why your furnace needs a check before winter"><span class="ba-count" id="ba-tcount"></span></div>'

        . '<div class="field"><span class="flabel">Cover photo <i>(optional, shown at the top and when the post is shared)</i></span>'
        . '<div class="ba-cover" id="ba-cover">' . ($cover ? img_tag($cover, '', ' id="ba-cover-img"') : '<img id="ba-cover-img" alt="" hidden>')
        . '<div class="ba-cover-acts"><button type="button" class="btn btn-line" id="ba-cover-pick">' . ($cover ? 'Change photo' : 'Choose a photo') . '</button>'
        . '<button type="button" class="ba-link" id="ba-cover-remove"' . ($cover ? '' : ' hidden') . '>Remove</button><span class="ba-count" id="ba-cover-msg"></span></div></div>'
        . '<input type="hidden" name="cover" id="ba-cover-url" value="' . e($cover[0] ?? '') . '">'
        . '<input type="file" id="ba-file" accept="image/jpeg,image/png,image/webp,image/*" hidden>'
        . '<label for="ba-alt" class="ba-sub">Describe the photo in a few words (for Google and for blind readers)</label>'
        . '<input id="ba-alt" name="cover_alt" maxlength="200" value="' . e($p['cover_alt'] ?? '') . '" placeholder="For example: Firefighters outside a house with a carbon monoxide detector"></div>'

        . '<div class="field"><label for="ba-body">Description</label>'
        . '<div class="ba-tools"><button type="button" class="btn btn-line" id="ba-add-photo">+ Add a photo here</button><button type="button" class="btn btn-line" id="ba-add-video">+ Add a YouTube video</button><span class="ba-count" id="ba-body-msg"></span></div>'
        . '<textarea id="ba-body" name="body" required maxlength="' . BODY_MAX . '" placeholder="Write your post here.">' . e($p['body'] ?? '') . '</textarea>'
        . '<span class="ba-count" id="ba-words"></span></div>'
        . '<details class="ba-help"><summary>Formatting help (optional)</summary><ul>'
        . '<li>Leave an empty line between paragraphs.</li>'
        . '<li>Start a line with <code>## </code> for a subheading.</li>'
        . '<li>Start lines with <code>- </code> for a list of points.</li>'
        . '<li>Put <code>**two stars**</code> around words to make them <b>bold</b>.</li>'
        . '<li>Web addresses such as https://www.cdc.gov become links.</li>'
        . '<li><b>Add a photo here</b> puts a photo where the cursor is. Type a caption on the line under its address.</li>'
        . '<li><b>Add a YouTube video</b> asks for the video’s link and shows the video in the post.</li></ul></details>'

        . '<div class="fgrid ba-grid"><div class="field"><label for="ba-state">Which state is this about?</label><select id="ba-state" name="state">' . $opts . '</select>'
        . '<span class="ba-count">The post links to that state’s lawyer page, and shows up on it.</span></div>'
        . '<div class="field"><label for="ba-desc">Google description <i>(optional)</i></label><textarea id="ba-desc" name="meta_desc" maxlength="' . DESC_MAX . '" rows="3" placeholder="Leave empty to use the start of the post.">' . e($p['meta_desc'] ?? '') . '</textarea>'
        . '<span class="ba-count" id="ba-dcount"></span></div></div>'

        . '<div class="ba-seo"><div><span class="flabel">How it may look on Google</span><div class="ba-serp"><span class="u" id="ba-serp-url"></span><b id="ba-serp-title"></b><span id="ba-serp-desc"></span></div></div>'
        . '<div><span class="flabel">Search checklist</span><ul class="ba-checks" id="ba-checks"></ul></div></div>'

        . '<div class="ba-bar ba-foot"><a href="' . SELF . '">Cancel</a><span>'
        . ($stored ? '<a href="/blog/?preview=' . e($id) . '" target="_blank" rel="noopener">Preview' . ($live ? '' : ' last saved draft') . '</a>' : '')
        . '<button class="btn btn-line" type="submit" name="status" value="draft">' . ($live ? 'Unpublish (save as draft)' : 'Save as draft') . '</button>'
        . '<button class="btn btn-ember" type="submit" name="status" value="published">' . ($live ? 'Save changes' : 'Publish') . ' <span class="arr">→</span></button></span></div></form>', true);
}
