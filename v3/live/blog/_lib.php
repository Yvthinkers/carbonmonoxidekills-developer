<?php
/*
 * Shared code for Rob's blog: post storage, text formatting and the page shell.
 *
 * Posts live in cmk-private/blog/posts.json (closed to the web, never part of a deploy), so a site
 * update can't overwrite them. The page shell (header, footer, styles) comes from the static build:
 * build.py writes it to cmk-private/blog-parts.json, so the blog always matches the rest of the site.
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
const OG_IMAGE = SITE . '/assets/og-image.jpg';
const OG_IMAGE_ALT = 'Carbon Monoxide Kills: carbon monoxide lawyer help in all 50 states, call 1-888-414-8680';
const FOUNDER_ID = SITE . '/about.html#rob-aiers';
const ORG_ID = SITE . '/#organization';
const TITLE_MAX = 120;
const BODY_MAX = 60000;

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

/** All posts, newest first. */
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

function unique_slug(string $slug, array $posts): string
{
    $taken = array_column($posts, 'slug');
    $try = $slug;
    for ($n = 2; in_array($try, $taken, true); $n++) {
        $try = "$slug-$n";
    }
    return $try;
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
 *  Empty line = new paragraph · "## " = subheading · "- " = bullet point · **word** = bold */
function render_body(string $text): array
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

/** Plain text of a post for summaries: formatting marks removed. */
function plain(string $text): string
{
    $t = (string) preg_replace('/^(## |[-*•] )/mu', '', $text);
    $t = str_replace('**', '', $t);
    return trim((string) preg_replace('/\s+/u', ' ', $t));
}

/** Search-result description: the opening words of the post, up to $max characters, cut at a word. */
function excerpt(string $body, int $max = 155): string
{
    $t = plain((string) preg_replace('/^## .*$/mu', '', $body));   // subheadings don't read well in a summary
    if (len($t) <= $max) {
        return $t;
    }
    $t = cut($t, $max);
    $sp = strrpos($t, ' ');
    return rtrim($sp ? substr($t, 0, $sp) : $t, " ,.;:-") . '…';
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

// ---------- page ----------

function parts(): array
{
    static $parts = null;
    if ($parts === null) {
        $parts = json_decode((string) @file_get_contents(PARTS_FILE), true);
        if (!is_array($parts) || !isset($parts['layout'])) {
            error_log('blog: ' . PARTS_FILE . ' missing, run build.py and deploy it');
            $parts = ['layout' => '<!doctype html><html lang="en-US"><head><meta charset="utf-8">{{HEAD}}</head><body><main>{{MAIN}}</main></body></html>', 'aside' => '', 'band' => ''];
        }
    }
    return $parts;
}

/** <title>, description, robots, canonical, Open Graph and Twitter tags (same set as seo.py head_tags). */
function head_tags(string $title, string $desc, string $path, bool $indexable, string $ogType = 'website', string $extra = ''): string
{
    $t = '<title>' . e($title) . "</title>\n<meta name=\"description\" content=\"" . e($desc) . "\">\n";
    if (!$indexable) {
        return $t . '<meta name="robots" content="noindex, nofollow">' . $extra;
    }
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
        '<meta property="og:image" content="' . OG_IMAGE . '">',
        '<meta property="og:image:width" content="1200">',
        '<meta property="og:image:height" content="630">',
        '<meta property="og:image:alt" content="' . e(OG_IMAGE_ALT) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . e($title) . '">',
        '<meta name="twitter:description" content="' . e($desc) . '">',
        '<meta name="twitter:image" content="' . OG_IMAGE . '">',
        '<meta name="twitter:image:alt" content="' . e(OG_IMAGE_ALT) . '">',
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
