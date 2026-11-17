<?php
/*
 * Blog editor for Rob: log in with the password, press "New post", type a title and the text, publish.
 * Posts can be edited or deleted later. Password hash: cmk-private/blog-auth.php (not in git, not in deploys):
 *   <?php return ['hash' => '<password_hash() output>'];
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

const SESSION_HOURS = 12;
const MAX_FAILS = 8;            // wrong passwords per IP address in 15 minutes
const SELF = '/blog/admin.php';

const ADMIN_CSS = '<style>
.blog-admin{padding-block:56px 100px}.blog-admin .wrap{max-width:920px}
.ba-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:28px}
.ba-bar h2{font:400 30px/1.1 var(--serif)}
.ba-note{border-left:3px solid var(--ember);background:var(--ember-soft);padding:14px 18px;margin:0 0 26px;color:var(--text)}
.ba-note a{color:var(--ember-deep);font-weight:600}
.ba-list{list-style:none;padding:0;margin:0;border-top:1px solid var(--line)}
.ba-list li{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;padding:18px 0;border-bottom:1px solid var(--line)}
.ba-list b{display:block;font:400 21px/1.25 var(--serif);font-weight:400}
.ba-list small{color:var(--text-3);font:500 12.5px/1 var(--mono)}
.ba-acts{display:flex;gap:18px;align-items:center}
.ba-acts a,.ba-acts button{font:600 14.5px/1 var(--sans);color:var(--ember-deep);background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;text-underline-offset:3px}
.ba-acts button.del{color:var(--danger)}
.blog-admin .field{display:grid;gap:10px;margin-bottom:24px}
.blog-admin .field textarea{min-height:460px;font-size:17px;line-height:1.65}
.ba-count{color:var(--text-3);font-size:13px}
.ba-help{color:var(--text-2);font-size:14.5px;border:1px solid var(--line);padding:16px 20px;margin:0 0 24px}
.ba-help ul{margin:8px 0 0;padding-left:20px}.ba-help code{font:500 13.5px var(--mono);background:var(--ember-soft);padding:1px 5px}
.ba-empty{padding:40px 0;color:var(--text-2)}
</style>';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// Sessions in our own closed folder, so the host's cleanup doesn't log Rob out while he is writing.
$sessions = BLOG_DIR . '/sessions';
if (!is_dir($sessions)) {
    @mkdir($sessions, 0700, true);
}
if (is_dir($sessions) && is_writable($sessions)) {
    session_save_path($sessions);
}
ini_set('session.gc_maxlifetime', (string) (SESSION_HOURS * 3600));
ini_set('session.use_strict_mode', '1');
session_name('cmkblog');
session_set_cookie_params(['lifetime' => 0, 'path' => '/blog/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$loggedIn = ($_SESSION['ok'] ?? false) === true && time() - (int) ($_SESSION['since'] ?? 0) < SESSION_HOURS * 3600;
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$do = $post && is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
$tokenOk = $post && hash_equals($_SESSION['csrf'], is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '');

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
        'logout' => do_logout(),
        default => null,
    };
} catch (Throwable $ex) {
    error_log('blog admin: ' . $ex->getMessage());
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
    setcookie('cmkblog', '', ['expires' => 1, 'path' => '/blog/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    go();
}

function field(string $key): string
{
    $v = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    return trim(str_replace(["\r\n", "\r"], "\n", (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $v)));
}

function do_save(): never
{
    $id = field('id');
    $title = (string) preg_replace('/\s+/u', ' ', field('title'));
    $body = field('body');
    $draft = ['id' => $id, 'title' => $title, 'body' => $body];
    if (len($title) < 5) {
        editor($draft, 'Please give the post a title (at least 5 characters).');
    }
    if (len($title) > TITLE_MAX) {
        editor($draft, 'The title is too long. Please keep it under ' . TITLE_MAX . ' characters.');
    }
    if (str_word_count(plain($body)) < 20) {
        editor($draft, 'The description is very short. Please write at least a few sentences (20 words or more).');
    }
    if (len($body) > BODY_MAX) {
        editor($draft, 'The description is too long for one post. Please split it into two posts.');
    }
    $savedId = $id;
    update_posts(function (array $posts) use ($id, $title, $body, &$savedId) {
        $now = gmdate('c');
        if ($id !== '') {
            foreach ($posts as &$p) {
                if ($p['id'] === $id) {
                    $p['title'] = $title;
                    $p['body'] = $body;
                    $p['updated'] = $now;
                    return $posts;
                }
            }
            throw new RuntimeException('post ' . $id . ' no longer exists');
        }
        $savedId = bin2hex(random_bytes(6));
        $posts[] = ['id' => $savedId, 'slug' => unique_slug(slugify($title), $posts), 'title' => $title, 'body' => $body,
            'created' => $now, 'updated' => $now];
        return $posts;
    });
    go('saved=' . rawurlencode($savedId));
}

function do_delete(): never
{
    $id = field('id');
    update_posts(fn(array $posts) => array_filter($posts, fn($p) => $p['id'] !== $id));
    go('deleted=1');
}

// ---------- pages ----------


function admin_page(string $title, string $h1, string $content, string $script = ''): never
{
    page(
        head_tags($title . ' · Rob’s blog', 'Blog editor', '/blog/admin.php', false, 'website', "\n" . ADMIN_CSS),
        hero([['Blog', '/blog/'], ['Editor', null]], 'Blog editor', $h1)
        . '<section class="blog-admin"><div class="wrap">' . $content . '</div></section>' . $script
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
    } elseif (isset($_GET['deleted'])) {
        $msg = 'The post was deleted.';
    }
    $rows = '';
    foreach ($posts as $p) {
        $rows .= '<li><div><b>' . e($p['title']) . '</b><small>' . strtoupper(e(nice_date($p['created']))) . '</small></div><div class="ba-acts">'
            . '<a href="' . e(post_url($p)) . '" target="_blank" rel="noopener">View</a>'
            . '<a href="' . SELF . '?edit=' . e($p['id']) . '">Edit</a>'
            . '<form method="post" action="' . SELF . '" data-confirm="Delete “' . e($p['title']) . '”? This cannot be undone.">' . token()
            . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . e($p['id']) . '"><button class="del" type="submit">Delete</button></form>'
            . '</div></li>';
    }
    $saved = is_string($_GET['saved'] ?? null) ? $_GET['saved'] : '';
    admin_page('Your posts', 'Your blog posts.', note(e($error), true) . note($msg)
        . '<div class="ba-bar"><h2>' . count($posts) . ' post' . (count($posts) === 1 ? '' : 's') . '</h2>'
        . '<a class="btn btn-ember" href="' . SELF . '?new=1">+ New post</a></div>'
        . ($rows ? '<ul class="ba-list">' . $rows . '</ul>' : '<p class="ba-empty">No posts yet. Press <b>+ New post</b> to write the first one.</p>')
        . '<form method="post" action="' . SELF . '" style="margin-top:48px">' . token() . '<input type="hidden" name="do" value="logout">'
        . '<button class="btn btn-line" type="submit">Log out</button></form>',
        '<script>
document.querySelectorAll("form[data-confirm]").forEach(f=>f.addEventListener("submit",e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
' . ($saved !== '' ? 'try{localStorage.removeItem("cmkblog-draft-new");localStorage.removeItem(' . json_encode('cmkblog-draft-' . $saved) . ')}catch(e){}' : '') . '
</script>');
}

function editor(?array $p, string $error = ''): never
{
    $isNew = $p === null || ($p['id'] ?? '') === '';
    $id = $p['id'] ?? '';
    admin_page($isNew ? 'New post' : 'Edit post', $isNew ? 'Write a new post.' : 'Edit your post.', note(e($error), true)
        . '<form class="formcard" method="post" action="' . SELF . '" id="ba-form" data-draft="cmkblog-draft-' . e($isNew ? 'new' : $id) . '" style="max-width:none">' . token()
        . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<p class="ba-note" id="ba-restored" hidden>We brought back text you hadn’t saved yet. <a href="#" id="ba-discard">Discard it</a></p>'
        . '<div class="field"><label for="ba-title">Title</label><input id="ba-title" name="title" required minlength="5" maxlength="' . TITLE_MAX . '" value="' . e($p['title'] ?? '') . '" placeholder="For example: Why your furnace needs a check before winter"><span class="ba-count" id="ba-tcount"></span></div>'
        . '<div class="field"><label for="ba-body">Description</label><textarea id="ba-body" name="body" required maxlength="' . BODY_MAX . '" placeholder="Write your post here.">' . e($p['body'] ?? '') . '</textarea></div>'
        . '<div class="ba-help"><b>Formatting (optional)</b><ul>'
        . '<li>Leave an empty line between paragraphs.</li>'
        . '<li>Start a line with <code>## </code> for a subheading.</li>'
        . '<li>Start lines with <code>- </code> for a list of points.</li>'
        . '<li>Put <code>**two stars**</code> around words to make them <b>bold</b>.</li>'
        . '<li>Web addresses such as https://www.cdc.gov become links.</li></ul></div>'
        . '<div class="ba-bar" style="margin:0"><a href="' . SELF . '">Cancel</a>'
        . '<button class="btn btn-ember" type="submit">' . ($isNew ? 'Publish' : 'Save changes') . ' <span class="arr">→</span></button></div></form>',
        '<script>
(()=>{const f=document.getElementById("ba-form"),t=document.getElementById("ba-title"),b=document.getElementById("ba-body"),key=f.dataset.draft,c=document.getElementById("ba-tcount");
 const count=()=>{const n=t.value.trim().length;c.textContent=n+" characters"+(n>60?" · Google shows about 60, so the end may be cut off in search results":"")};
 t.addEventListener("input",count);count();
 try{const d=JSON.parse(localStorage.getItem(key)||"null");
  if(d&&(d.title!==t.value||d.body!==b.value)&&(d.title||d.body)){const old={title:t.value,body:b.value};t.value=d.title;b.value=d.body;count();
   const r=document.getElementById("ba-restored");r.hidden=false;
   document.getElementById("ba-discard").onclick=e=>{e.preventDefault();t.value=old.title;b.value=old.body;count();r.hidden=true;localStorage.removeItem(key)}}}catch(e){}
 let timer;const keep=()=>{clearTimeout(timer);timer=setTimeout(()=>{try{localStorage.setItem(key,JSON.stringify({title:t.value,body:b.value}))}catch(e){}},400)};
 t.addEventListener("input",keep);b.addEventListener("input",keep);
})();
</script>');
}
