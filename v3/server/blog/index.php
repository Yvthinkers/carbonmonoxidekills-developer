<?php
/*
 * Rob's blog, public side. Rob writes posts at /blog/admin.php.
 *   /blog/                   all published posts
 *   /blog/<slug>/            one post               (.htaccess: index.php?post=<slug>)
 *   /blog/?preview=<id>      a draft, for Rob only (logged in to the editor)
 *   /blog/sitemap.xml        published posts for search engines, listed in robots.txt
 *   /blog/?feed=1[&state=X]  latest posts as JSON for the home, News and state guide pages
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

$all = load_posts();
$posts = public_posts($all);

if (isset($_GET['feed'])) {
    $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    $list = $state !== '' ? array_values(array_filter($posts, fn($p) => ($p['state'] ?? '') === $state)) : $posts;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    header('X-Robots-Tag: noindex');
    echo json_encode(array_map(fn($p) => [
        'title' => $p['title'], 'url' => post_url($p), 'date' => nice_date($p['created']), 'state' => $p['state'] ?? '',
        'excerpt' => excerpt($p['body'], 200), 'image' => cover($p)[0] ?? '', 'image_alt' => $p['cover_alt'] ?? '',
    ], array_slice($list, 0, 3)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['sitemap'])) {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    if ($posts) {
        echo '<url><loc>' . SITE . '/blog/</loc><lastmod>' . substr(max(array_column($posts, 'updated')), 0, 10) . "</lastmod></url>\n";
    }
    foreach ($posts as $p) {
        $img = cover($p);
        echo '<url><loc>' . e(SITE . post_url($p)) . '</loc><lastmod>' . substr($p['updated'], 0, 10) . '</lastmod>'
            . ($img ? '<image:image><image:loc>' . e(SITE . $img[0]) . '</image:loc></image:image>' : '') . "</url>\n";
    }
    echo "</urlset>\n";
    exit;
}

if (is_string($_GET['preview'] ?? null)) {
    header('Cache-Control: no-store');
    $p = admin_session() ? find_post($all, 'id', $_GET['preview']) : null;
    $p ? show_post($p, $posts, true) : not_found();
    exit;
}

$slug = $_GET['post'] ?? null;
if (is_string($slug)) {
    $post = find_post($posts, 'slug', $slug);
    $post ? show_post($post, $posts) : not_found();
} else {
    show_list($posts);
}

function card(array $p, int $words): string
{
    $img = cover($p);
    $meta = strtoupper(nice_date($p['created'])) . ' · ' . strtoupper(($p['state'] ?? '') !== '' ? $p['state'] : 'Rob’s blog');
    return '<a class="card" href="' . e(post_url($p)) . '">'
        . ($img ? img_tag($img, $p['cover_alt'] ?? '', ' class="cimg" loading="lazy" decoding="async"') : '')
        . '<span class="meta">' . e($meta) . '</span><h3>' . e($p['title']) . '</h3><p>' . e(excerpt($p['body'], $words)) . '</p></a>';
}

function show_list(array $posts): void
{
    $title = 'Rob Aiers’ blog on carbon monoxide · Carbon Monoxide Kills';
    $desc = 'Carbon monoxide safety notes, warnings and case insights written by Rob Aiers, founder of Carbon Monoxide Kills.';
    $cards = implode('', array_map(fn($p, $i) => card($p, $i === 0 ? 300 : 180), $posts, array_keys($posts)));
    $body = $posts
        ? '<div class="newsgrid">' . $cards . '</div>'
        : '<p class="lede">Rob’s first post is on its way. Meanwhile, read the <a href="/news.html">latest carbon monoxide news</a>.</p>';
    $ld = json_ld([
        ['@type' => 'Blog', '@id' => SITE . '/blog/#blog', 'url' => SITE . '/blog/', 'name' => 'Rob Aiers’ blog', 'description' => $desc,
            'inLanguage' => 'en-US', 'author' => ['@id' => FOUNDER_ID], 'publisher' => ['@id' => ORG_ID],
            'blogPost' => array_map(fn($p) => ['@type' => 'BlogPosting', 'headline' => cut($p['title'], 110), 'url' => SITE . post_url($p), 'datePublished' => $p['created']], array_slice($posts, 0, 20))],
        breadcrumbs([['Blog', '/blog/']]),
    ]);
    page(
        head_tags($title, $desc, '/blog/', (bool) $posts, 'website', $posts ? $ld : ''),
        hero([['Blog', null]], 'Rob’s blog', 'Notes from Rob Aiers.', 'Carbon monoxide warnings, safety advice and what Rob has learned from over 25 years of cases.')
        . '<section class="insights tight"><div class="wrap"><h2 class="vh">Latest posts</h2>' . $body . '</div></section>'
        . parts()['band']
    );
}

function share_links(string $url, string $title): string
{
    $u = rawurlencode(SITE . $url);
    $t = rawurlencode($title);
    $links = [
        'Facebook' => "https://www.facebook.com/sharer/sharer.php?u=$u",
        'LinkedIn' => "https://www.linkedin.com/sharing/share-offsite/?url=$u",
        'X' => "https://twitter.com/intent/tweet?url=$u&text=$t",
        'WhatsApp' => "https://wa.me/?text=$t%20$u",
        'Email' => "mailto:?subject=$t&body=$u",
    ];
    $a = '';
    foreach ($links as $name => $href) {
        $a .= '<a href="' . e($href) . '"' . ($name === 'Email' ? '' : ' target="_blank" rel="noopener"') . '>' . $name . '</a>';
    }
    return '<div class="share"><span class="label">Share this post</span>' . $a
        . '<button type="button" data-copy="' . e(SITE . $url) . '">Copy link</button></div>';
}

function show_post(array $p, array $posts, bool $preview = false): void
{
    [$html, $toc] = render_body($p['body'], $p['title']);
    $url = post_url($p);
    $title = $p['title'];
    $full = len($title . ' · ' . SITE_NAME) <= 60 ? $title . ' · ' . SITE_NAME : $title;
    $desc = post_desc($p);
    $state = $p['state'] ?? '';
    $img = cover($p);
    $alt = trim($p['cover_alt'] ?? '') !== '' ? $p['cover_alt'] : $title;
    $created = nice_date($p['created']);
    $edited = substr($p['updated'], 0, 10) !== substr($p['created'], 0, 10);
    $by = '<div class="byline"><span>By <b>Rob Aiers</b>, founder</span>'
        . '<span>' . ($edited ? 'Updated' : 'Published') . ' <b class="mono"><time datetime="' . e(substr($edited ? $p['updated'] : $p['created'], 0, 10)) . '">'
        . e(nice_date($edited ? $p['updated'] : $p['created'])) . '</time></b></span><span>' . read_minutes($p['body']) . ' min read</span></div>';

    // Left column: the post's own subheadings, or other recent posts when it has none.
    $others = array_values(array_filter($posts, fn($o) => $o['id'] !== $p['id']));
    if ($toc) {
        $nav = '<nav class="toc" aria-label="On this page"><span class="label">On this page</span><ol>'
            . implode('', array_map(fn($h) => '<li><a href="#' . e($h[0]) . '">' . e($h[1]) . '</a></li>', $toc)) . '</ol></nav>';
    } else {
        $nav = '<nav class="toc" aria-label="More posts"><span class="label">More from Rob</span><ol>'
            . implode('', array_map(fn($o) => '<li><a href="' . e(post_url($o)) . '">' . e($o['title']) . '</a></li>', array_slice($others, 0, 5)))
            . '<li><a href="/blog/">All posts</a></li></ol></nav>';
    }
    // The state's lawyer page: a link from every post about that state helps it rank for "<state> carbon monoxide lawyer".
    $statePath = $state !== '' ? (states()[$state] ?? null) : null;
    $stateBox = $statePath
        ? '<div class="answer bstate"><span class="label ember">' . e($state) . '</span><p>Hurt by carbon monoxide in ' . e($state)
            . '? See the deadlines, alarm laws and how to get help in our guide: <a href="' . e($statePath) . '">Carbon monoxide lawyer in ' . e($state) . ' →</a></p></div>'
        : '';
    $coverHtml = $img ? '<figure class="bcover">' . img_tag($img, $alt, ' fetchpriority="high" decoding="async"') . '</figure>' : '';
    $related = '';
    if ($others) {
        $related = '<div class="related"><div class="wrap"><p class="label ember">More from Rob’s blog</p><div class="grid">'
            . implode('', array_map(fn($o) => '<a href="' . e(post_url($o)) . '"><b>' . e($o['title']) . '</b><span>' . e(excerpt($o['body'], 110)) . '</span><i aria-hidden="true">→</i></a>', array_slice($others, 0, 3)))
            . '</div></div></div>';
    }
    $ld = json_ld([
        ['@type' => 'BlogPosting', '@id' => SITE . $url . '#article', 'mainEntityOfPage' => SITE . $url, 'url' => SITE . $url,
            'headline' => cut($title, 110), 'description' => $desc, 'inLanguage' => 'en-US',
            'image' => $img ? [['@type' => 'ImageObject', 'url' => SITE . $img[0], 'width' => $img[1], 'height' => $img[2]]] : [OG_IMAGE],
            'datePublished' => $p['created'], 'dateModified' => $p['updated'], 'wordCount' => str_word_count(plain($p['body'])),
            'author' => ['@type' => 'Person', '@id' => FOUNDER_ID, 'name' => 'Rob Aiers', 'url' => SITE . '/about.html'],
            'publisher' => ['@type' => 'Organization', '@id' => ORG_ID, 'name' => SITE_NAME, 'url' => SITE . '/',
                'logo' => ['@type' => 'ImageObject', 'url' => SITE . '/assets/icon-512.png', 'width' => 512, 'height' => 512]],
            'isPartOf' => ['@id' => SITE . '/blog/#blog']]
            + ($state !== '' ? ['contentLocation' => ['@type' => 'State', 'name' => $state, 'containedInPlace' => ['@type' => 'Country', 'name' => 'United States']]] : []),
        breadcrumbs([['Blog', '/blog/'], [$title, $url]]),
    ]);
    $extra = "\n" . '<meta property="article:published_time" content="' . e($p['created']) . '">'
        . "\n" . '<meta property="article:modified_time" content="' . e($p['updated']) . '">' . $ld;
    $banner = $preview
        ? '<div class="bpreview">Preview: ' . (is_published($p) ? 'this post is published.' : 'this draft is not on the website yet.')
            . ' <a href="/blog/admin.php?edit=' . e($p['id']) . '">Back to the editor</a></div>'
        : '';
    page(
        head_tags($full, $desc, $url, !$preview, 'article', $extra, $img ? [SITE . $img[0], $img[1], $img[2], $alt] : null),
        $banner
        . hero([['Blog', '/blog/'], [cut($title, 40) . (len($title) > 40 ? '…' : ''), null]], 'Rob’s blog · ' . ($state !== '' ? $state . ' · ' : '') . $created,
            $title, '', $by, len($title) > 70 ? 'h-2' : 'h-1')
        . '<div class="wrap layout">' . $nav . '<article>' . $coverHtml . $html . $stateBox . share_links($url, $title) . '</article>' . parts()['aside'] . '</div>'
        . $related . parts()['band']
    );
}

function breadcrumbs(array $trail): array
{
    $items = [['Home', SITE . '/']];
    foreach ($trail as [$name, $path]) {
        $items[] = [$name, SITE . $path];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(
        fn($i, $it) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it[0], 'item' => $it[1]], array_keys($items), $items)];
}

function not_found(): void
{
    http_response_code(404);
    page(
        head_tags('Post not found · Carbon Monoxide Kills', 'This blog post is not here any more.', '/blog/', false),
        hero([['Blog', '/blog/'], ['Not found', null]], 'Rob’s blog', 'This post isn’t here.', 'It may have been moved or removed.')
        . '<section class="insights tight"><div class="wrap"><p><a class="btn btn-ember" href="/blog/">See all posts <span class="arr">→</span></a></p></div></section>'
    );
}
