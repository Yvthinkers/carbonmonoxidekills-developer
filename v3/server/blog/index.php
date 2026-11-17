<?php
/*
 * Rob's blog, public side. Rob writes posts at /blog/admin.php.
 *   /blog/              all posts
 *   /blog/<slug>/       one post         (.htaccess: index.php?post=<slug>)
 *   /blog/sitemap.xml   posts for search engines, listed in robots.txt
 *   /blog/?feed=1       latest posts as JSON, for the News page
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

$posts = load_posts();

if (isset($_GET['feed'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Robots-Tag: noindex');
    echo json_encode(array_map(fn($p) => [
        'title' => $p['title'], 'url' => post_url($p), 'date' => nice_date($p['created']), 'excerpt' => excerpt($p['body'], 200),
    ], array_slice($posts, 0, 3)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['sitemap'])) {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    if ($posts) {
        echo '<url><loc>' . SITE . '/blog/</loc><lastmod>' . substr(max(array_column($posts, 'updated')), 0, 10) . "</lastmod></url>\n";
    }
    foreach ($posts as $p) {
        echo '<url><loc>' . e(SITE . post_url($p)) . '</loc><lastmod>' . substr($p['updated'], 0, 10) . "</lastmod></url>\n";
    }
    echo "</urlset>\n";
    exit;
}

$slug = $_GET['post'] ?? null;
if (is_string($slug)) {
    $post = find_post($posts, 'slug', $slug);
    $post ? show_post($post, $posts) : not_found();
} else {
    show_list($posts);
}

function show_list(array $posts): void
{
    $title = 'Rob Aiers’ blog on carbon monoxide · Carbon Monoxide Kills';
    $desc = 'Carbon monoxide safety notes, warnings and case insights written by Rob Aiers, founder of Carbon Monoxide Kills.';
    $cards = '';
    foreach ($posts as $i => $p) {
        $cards .= '<a class="card" href="' . e(post_url($p)) . '"><span class="meta">' . strtoupper(nice_date($p['created'])) . ' · ROB’S BLOG</span>'
            . '<h3>' . e($p['title']) . '</h3><p>' . e(excerpt($p['body'], $i === 0 ? 300 : 180)) . '</p></a>';
    }
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

function show_post(array $p, array $posts): void
{
    [$html, $toc] = render_body($p['body']);
    $url = post_url($p);
    $title = $p['title'];
    $full = len($title . ' · ' . SITE_NAME) <= 60 ? $title . ' · ' . SITE_NAME : $title;
    $desc = excerpt($p['body']);
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
    $related = '';
    if ($others) {
        $related = '<div class="related"><div class="wrap"><p class="label ember">More from Rob’s blog</p><div class="grid">'
            . implode('', array_map(fn($o) => '<a href="' . e(post_url($o)) . '"><b>' . e($o['title']) . '</b><span>' . e(excerpt($o['body'], 110)) . '</span><i aria-hidden="true">→</i></a>', array_slice($others, 0, 3)))
            . '</div></div></div>';
    }
    $ld = json_ld([
        ['@type' => 'BlogPosting', '@id' => SITE . $url . '#article', 'mainEntityOfPage' => SITE . $url, 'url' => SITE . $url,
            'headline' => cut($title, 110), 'description' => $desc, 'inLanguage' => 'en-US', 'image' => [OG_IMAGE],
            'datePublished' => $p['created'], 'dateModified' => $p['updated'], 'wordCount' => str_word_count(plain($p['body'])),
            'author' => ['@type' => 'Person', '@id' => FOUNDER_ID, 'name' => 'Rob Aiers', 'url' => SITE . '/about.html'],
            'publisher' => ['@type' => 'Organization', '@id' => ORG_ID, 'name' => SITE_NAME, 'url' => SITE . '/',
                'logo' => ['@type' => 'ImageObject', 'url' => SITE . '/assets/icon-512.png', 'width' => 512, 'height' => 512]],
            'isPartOf' => ['@id' => SITE . '/blog/#blog']],
        breadcrumbs([['Blog', '/blog/'], [$title, $url]]),
    ]);
    $extra = "\n" . '<meta property="article:published_time" content="' . e($p['created']) . '">'
        . "\n" . '<meta property="article:modified_time" content="' . e($p['updated']) . '">' . $ld;
    page(
        head_tags($full, $desc, $url, true, 'article', $extra),
        hero([['Blog', '/blog/'], [cut($title, 40) . (len($title) > 40 ? '…' : ''), null]], 'Rob’s blog · ' . $created, $title, '', $by, len($title) > 70 ? 'h-2' : 'h-1')
        . '<div class="wrap layout">' . $nav . '<article>' . $html . '</article>' . parts()['aside'] . '</div>'
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
