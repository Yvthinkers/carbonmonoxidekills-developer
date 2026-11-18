<?php
/*
 * Shared code for Rob's blog: post storage, text formatting, photos and the page shell.
 *
 * Posts live in cmk-private/blog/posts.json (closed to the web, never part of a deploy), so a site
 * update can't overwrite them. Photos Rob uploads go to blog/media/. The page shell (header, footer,
 * styles) and the state list come from the static build: build.py writes cmk-private/blog-parts.json,
 * so the blog always matches the rest of the site.
 *
 * A post: id, slug, title, body, status (published|draft), state, cover, cover_alt, meta_desc,
 *         created (first published), updated. Fields added later are optional, so older posts keep working.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

const SITE = 'https://www.carbonmonoxidekills.com';
const SITE_NAME = 'Carbon Monoxide Kills';
const PRIVATE_DIR = __DIR__ . '/../cmk-private';
const BLOG_DIR = PRIVATE_DIR . '/blog';
const POSTS_FILE = BLOG_DIR . '/posts.json';
const PARTS_FILE = PRIVATE_DIR . '/blog-parts.json';
const AUTH_FILE = PRIVATE_DIR . '/blog-auth.php';
const MEDIA_DIR = __DIR__ . '/media';
const MEDIA_URL = '/blog/media/';
const MEDIA_RE = '~^(?:https://www\.carbonmonoxidekills\.com)?/blog/media/([0-9]{6}-[0-9a-f]{10}\.jpg)$~';
const YOUTUBE_RE = '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:\S*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&#]\S*)?$~';
const RESERVED_SLUGS = ['media', 'admin', 'sitemap', 'index'];
const OG_IMAGE = SITE . '/assets/og-image.jpg';
const OG_IMAGE_ALT = 'Carbon Monoxide Kills: carbon monoxide lawyer help in all 50 states, call 1-888-414-8680';
const FOUNDER_ID = SITE . '/about.html#rob-aiers';
const ORG_ID = SITE . '/#organization';
const TITLE_MAX = 120;
const BODY_MAX = 60000;
const DESC_MAX = 200;
const SESSION_HOURS = 12;

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

// ---------- storage ----------

function is_published(array $p): bool
{
    return ($p['status'] ?? 'published') === 'published';
}

/** All posts (drafts too), newest first. */
function load_posts(): array
{
    $raw = is_file(POSTS_FILE) ? (string) file_get_contents(POSTS_FILE) : '';
    $posts = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($posts)) {
        error_log('blog: posts.json is not valid JSON');
        return [];
    }
    usort($posts, fn($a, $b) => strcmp($b['created'], $a['created']));
    return $posts;
}

/** What visitors see: published posts only. */
function public_posts(?array $posts = null): array
{
    return array_values(array_filter($posts ?? load_posts(), 'is_published'));
}

function find_post(array $posts, string $field, string $value): ?array
{
    foreach ($posts as $p) {
        if (($p[$field] ?? null) === $value) {
            return $p;
        }
    }
    return null;
}

/**
 * Read-change-write of posts.json under an exclusive lock, so two saves never overwrite each other.
 * The previous version is kept as posts.json.bak. Refuses to write over a file it can't read.
 */
function update_posts(callable $change): void
{
    if (!is_dir(BLOG_DIR) && !mkdir(BLOG_DIR, 0700, true) && !is_dir(BLOG_DIR)) {
        throw new RuntimeException('cannot create ' . BLOG_DIR);
    }
    $fh = fopen(POSTS_FILE, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        throw new RuntimeException('cannot open ' . POSTS_FILE);
    }
    try {
        $raw = (string) stream_get_contents($fh);
        $posts = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($posts)) {
            throw new RuntimeException('posts.json is not valid JSON, not overwriting it');
        }
        $posts = array_values($change($posts));
        if ($raw !== '') {
            file_put_contents(POSTS_FILE . '.bak', $raw, LOCK_EX);
        }
        $json = json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        ftruncate($fh, 0);
        rewind($fh);
        if (fwrite($fh, $json) !== strlen($json) || !fflush($fh)) {
            throw new RuntimeException('could not write ' . POSTS_FILE);
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** URL slug from a title: lowercase words joined by hyphens, at most ~70 characters, cut at a word. */
function slugify(string $title): string
{
    $s = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    if (strlen($s) > 70) {
        $s = substr($s, 0, 71);
        $s = substr($s, 0, strrpos($s, '-') ?: 70);
    }
    return $s !== '' ? $s : 'post';
}

/** $slug, or $slug-2, -3 ... if another post (not $selfId) already has it. */
function unique_slug(string $slug, array $posts, string $selfId = ''): string
{
    $taken = array_merge(RESERVED_SLUGS, array_column(array_filter($posts, fn($p) => $p['id'] !== $selfId), 'slug'));
    $try = $slug;
    for ($n = 2; in_array($try, $taken, true); $n++) {
        $try = "$slug-$n";
    }
    return $try;
}

// ---------- states ----------

/** State name => path of its "carbon monoxide lawyer in <state>" page (a state guide or the WordPress page). */
function states(): array
{
    return parts()['states'] ?? [];
}

// ---------- photos ----------

/** [url, width, height] of an uploaded photo given as its /blog/media/ address, or null. */
function media(string $url): ?array
{
    if (!preg_match(MEDIA_RE, trim($url), $m) || !is_file(MEDIA_DIR . '/' . $m[1])) {
        return null;
    }
    $size = @getimagesize(MEDIA_DIR . '/' . $m[1]);
    return [MEDIA_URL . $m[1], (int) ($size[0] ?? 1600), (int) ($size[1] ?? 900)];
}

function img_tag(array $m, string $alt, string $extra = ''): string
{
    return '<img src="' . e($m[0]) . '" width="' . $m[1] . '" height="' . $m[2] . '" alt="' . e($alt) . '"' . $extra . '>';
}

// ---------- text ----------

/** Escaped text with **bold** and clickable web addresses. */
function inline(string $text): string
{
    $h = e($text);
    $h = (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $h);
    return (string) preg_replace_callback('~\bhttps?://[^\s<]+~u', function ($m) {
        $url = rtrim($m[0], '.,;:!?)');
        $rest = substr($m[0], strlen($url));
        $own = str_starts_with($url, SITE . '/') || $url === SITE;
        return '<a href="' . $url . '"' . ($own ? '' : ' target="_blank" rel="noopener"') . '>' . $url . '</a>' . $rest;
    }, $h);
}

/** Plain text, as Rob types it, to article HTML. Returns [html, [[id, heading], ...]].
 *  Empty line = new paragraph · "## " = subheading · "- " = bullet point · **word** = bold
 *  A photo address on its own (from "Add a photo") = the photo; lines under it = its caption
 *  A YouTube link on its own line = the video */
function render_body(string $text, string $title = ''): array
{
    $html = '';
    $toc = [];
    $first = true;
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    foreach (preg_split('/\n[ \t]*\n+/', $text) ?: [] as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== ''));
        if (!$lines) {
            continue;
        }
        if (count($lines) === 1 && str_starts_with($lines[0], '## ')) {
            $heading = trim(substr($lines[0], 3));
            $id = slugify($heading);
            $taken = array_column($toc, 0);
            for ($n = 2, $base = $id; in_array($id, $taken, true); $n++) {
                $id = "$base-$n";
            }
            $toc[] = [$id, $heading];
            $html .= '<h2 id="' . e($id) . '">' . inline($heading) . "</h2>\n";
            continue;
        }
        if ($m = media($lines[0])) {
            $caption = implode(' ', array_slice($lines, 1));
            $html .= '<figure class="bimg">' . img_tag($m, $caption !== '' ? plain($caption) : $title, ' loading="lazy" decoding="async"')
                . ($caption !== '' ? '<figcaption>' . inline($caption) . '</figcaption>' : '') . "</figure>\n";
            continue;
        }
        if (count($lines) === 1 && preg_match(YOUTUBE_RE, $lines[0], $yt)) {
            $html .= '<figure class="bvideo"><iframe src="https://www.youtube-nocookie.com/embed/' . $yt[1] . '" title="' . e($title !== '' ? $title . ' (video)' : 'YouTube video')
                . '" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></figure>' . "\n";
            continue;
        }
        $bullets = array_filter($lines, fn($l) => preg_match('/^[-*•] /u', $l));
        if (count($bullets) === count($lines)) {
            $html .= '<ul class="plain">' . implode('', array_map(fn($l) => '<li>' . inline(trim((string) preg_replace('/^[-*•] /u', '', $l))) . '</li>', $lines)) . "</ul>\n";
            continue;
        }
        $html .= '<p' . ($first ? ' class="first"' : '') . '>' . implode('<br>', array_map('inline', $lines)) . "</p>\n";
        $first = false;
    }
    return [$html, $toc];
}

/** Plain text of a post for summaries: subheadings, photos, videos and formatting marks removed. */
function plain(string $text): string
{
    $keep = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $l) {
        $l = trim($l);
        if (str_starts_with($l, '## ') || preg_match(MEDIA_RE, $l) || preg_match(YOUTUBE_RE, $l)) {
            continue;
        }
        $keep[] = $l;
    }
    $t = (string) preg_replace('/^[-*•] /mu', '', implode("\n", $keep));
    $t = str_replace('**', '', $t);
    return trim((string) preg_replace('/\s+/u', ' ', $t));
}

/** The opening words of the post, up to $max characters, cut at a word. */
function excerpt(string $body, int $max = 155): string
{
    $t = plain($body);
    if (len($t) <= $max) {
        return $t;
    }
    $t = cut($t, $max);
    $sp = strrpos($t, ' ');
    return rtrim($sp ? substr($t, 0, $sp) : $t, " ,.;:-") . '…';
}

/** Search-result description: Rob's own Google description, or the opening words. */
function post_desc(array $p): string
{
    $d = trim($p['meta_desc'] ?? '');
    return $d !== '' ? $d : excerpt($p['body']);
}

function read_minutes(string $body): int
{
    return max(1, (int) round(str_word_count(plain($body)) / 220));
}

function nice_date(string $iso): string
{
    return gmdate('j M Y', strtotime($iso));
}

function post_url(array $p): string
{
    return '/blog/' . $p['slug'] . '/';
}

/** Cover photo [url, width, height] or null. */
function cover(array $p): ?array
{
    return ($p['cover'] ?? '') !== '' ? media($p['cover']) : null;
}

// ---------- admin session (editor, and previews of drafts) ----------

function admin_session(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $dir = BLOG_DIR . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);   // our own folder, so the host's cleanup doesn't log Rob out mid-post
        }
        ini_set('session.gc_maxlifetime', (string) (SESSION_HOURS * 3600));
        ini_set('session.use_strict_mode', '1');
        session_name('cmkblog');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/blog/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
    return ($_SESSION['ok'] ?? false) === true && time() - (int) ($_SESSION['since'] ?? 0) < SESSION_HOURS * 3600;
}

// ---------- page ----------

function parts(): array
{
    static $parts = null;
    if ($parts === null) {
        $parts = json_decode((string) @file_get_contents(PARTS_FILE), true);
        if (!is_array($parts) || !isset($parts['layout'])) {
            error_log('blog: ' . PARTS_FILE . ' missing, run build.py and deploy it');
            $parts = ['layout' => '<!doctype html><html lang="en-US"><head><meta charset="utf-8">{{HEAD}}</head><body><main>{{MAIN}}</main></body></html>', 'aside' => '', 'band' => '', 'states' => []];
        }
    }
    return $parts;
}

/** <title>, description, robots, canonical, Open Graph and Twitter tags (same set as seo.py head_tags).
 *  $image: [absolute url, width, height, alt] or null for the site's share image. */
function head_tags(string $title, string $desc, string $path, bool $indexable, string $ogType = 'website', string $extra = '', ?array $image = null): string
{
    $t = '<title>' . e($title) . "</title>\n<meta name=\"description\" content=\"" . e($desc) . "\">\n";
    if (!$indexable) {
        return $t . '<meta name="robots" content="noindex, nofollow">' . $extra;
    }
    [$img, $w, $h, $alt] = $image ?? [OG_IMAGE, 1200, 630, OG_IMAGE_ALT];
    $url = e(SITE . $path);
    $tags = [
        '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">',
        "<link rel=\"canonical\" href=\"$url\">",
        '<meta property="og:type" content="' . $ogType . '">',
        '<meta property="og:site_name" content="' . SITE_NAME . '">',
        '<meta property="og:locale" content="en_US">',
        "<meta property=\"og:url\" content=\"$url\">",
        '<meta property="og:title" content="' . e($title) . '">',
        '<meta property="og:description" content="' . e($desc) . '">',
        '<meta property="og:image" content="' . e($img) . '">',
        '<meta property="og:image:width" content="' . $w . '">',
        '<meta property="og:image:height" content="' . $h . '">',
        '<meta property="og:image:alt" content="' . e($alt) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . e($title) . '">',
        '<meta name="twitter:description" content="' . e($desc) . '">',
        '<meta name="twitter:image" content="' . e($img) . '">',
        '<meta name="twitter:image:alt" content="' . e($alt) . '">',
    ];
    return $t . implode("\n", $tags) . $extra;
}

function json_ld(array $graph): string
{
    $data = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return "\n<script type=\"application/ld+json\">" . str_replace('<', '<', (string) $data) . '</script>';
}

/** Dark page header with breadcrumbs, as on every other page. $crumbs: [[label, url|null], ...] */
function hero(array $crumbs, string $label, string $h1, string $lede = '', string $extra = '', string $size = 'h-1'): string
{
    $c = '<a href="/">Home</a>';
    foreach ($crumbs as [$t, $u]) {
        $c .= '<span>/</span>' . ($u ? '<a href="' . e($u) . '">' . e($t) . '</a>' : '<span style="color:var(--on-ink)">' . e($t) . '</span>');
    }
    return '<div class="phero dark"><div class="wrap"><nav class="crumbs" aria-label="Breadcrumb">' . $c . '</nav>'
        . '<p class="label ember">' . e($label) . '</p><h1 class="' . $size . '">' . e($h1) . '</h1>'
        . ($lede !== '' ? '<p class="lede">' . e($lede) . '</p>' : '') . $extra . '</div></div>';
}

function page(string $head, string $main): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo strtr(parts()['layout'], ['{{HEAD}}' => $head, '{{MAIN}}' => $main]);
}
