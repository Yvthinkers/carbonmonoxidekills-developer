<?php
/**
 * Plugin Name: CMK SEO cleanup
 * Description: Search fixes for the WordPress part of carbonmonoxidekills.com, kept in code so they don't depend on wp-admin. Delete this file to undo all of them.
 */
if (!defined('ABSPATH')) {
    exit;
}

// Yoast settings: image attachment pages (a few words each) send visitors to the image itself, and tag and
// author archives stay out of search results and sitemaps. Google was crawling 130+ near-empty pages.
add_filter('option_wpseo_titles', function ($options) {
    if (!is_array($options)) {
        return $options;
    }
    $options['disable-attachment'] = true;
    $options['noindex-attachment'] = true;
    $options['noindex-tax-post_tag'] = true;
    $options['disable-author'] = true;
    $options['noindex-author-wpseo'] = true;
    return $options;
});

// Four 2026 posts were copied from an older Minnesota story and kept its title and description.
function cmk_seo_fixed_meta()
{
    if (!is_singular()) {
        return null;
    }
    $fixed = array(
        2120 => array('How Often to Test and Replace Carbon Monoxide Detectors',
            'Rob Aiers explains how often to test, replace and maintain carbon monoxide detectors, and where to fit them so they protect you.'),
        2131 => array('Is This a Safe Distance? Generator Carbon Monoxide Warning',
            'A photo of a generator beside an open sliding door sparked warnings about carbon monoxide getting inside and an overheating extension cord.'),
        2134 => array('Couple Dies of Carbon Monoxide in Indiana Mobile Home',
            'A Portage, Indiana couple died of carbon monoxide from a portable generator after storms, and six more people were hospitalized.'),
        2138 => array('Agents Pull 2 Men From Carbon Monoxide-Filled Utah Home',
            'Two Utah probation agents pulled two men, moments from death, out of a carbon monoxide-filled home in Columbia, Carbon County.'),
        // Search Console, Aug-Nov 2026: these pages rank on page 1 for the questions below but their titles
        // didn't answer them, so they got almost no clicks.
        976 => array('Do Electric Heaters Produce Carbon Monoxide? CO Safety Advice',
            'No: electric heaters and electric water heaters don’t produce carbon monoxide. Gas, oil and wood appliances can. How to find the risks in your home.'),
        1138 => array('UL 2034 Carbon Monoxide Alarm Standard Explained',
            'What the UL 2034 standard means for your carbon monoxide alarm: how fast it must sound at 70, 150 and 400 ppm, and the levels it must ignore.'),
        1021 => array('NYC Carbon Monoxide Detector Law: Do Apartments Need One?',
            'Do New York City apartments need carbon monoxide detectors? What NYC law requires of building owners and tenants, and what to do if yours is missing.'),
    );
    $id = get_queried_object_id();
    return isset($fixed[$id]) ? $fixed[$id] : null;
}

function cmk_seo_title($title)
{
    $meta = cmk_seo_fixed_meta();
    if (!$meta) {
        return $title;
    }
    $full = $meta[0] . ' | CarbonMonoxideKills.com';
    return strlen($full) <= 60 ? $full : $meta[0];   // Google shows about 60 characters
}

// The UL 2034 page was the site's best page for clicks but has almost no text. Add a plain explanation.
add_filter('the_content', function ($content) {
    if (!is_page(1138) || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    return $content . '
<h2>What UL 2034 means for your carbon monoxide alarm</h2>
<p>UL 2034 is the US safety standard for residential carbon monoxide alarms. An alarm listed to UL 2034 must sound before carbon monoxide in the air reaches a level that would harm a healthy adult, and the higher the level, the faster it must react:</p>
<table>
<thead><tr><th>Carbon monoxide level</th><th>The alarm must sound within</th></tr></thead>
<tbody>
<tr><td>70 ppm</td><td>60 to 240 minutes</td></tr>
<tr><td>150 ppm</td><td>10 to 50 minutes</td></tr>
<tr><td>400 ppm</td><td>4 to 15 minutes</td></tr>
</tbody>
</table>
<p>To avoid false alarms, a UL 2034 alarm must not sound at 30 ppm for 30 days, or at 70 ppm for less than an hour. That means a standard alarm will stay silent at low levels that can still cause headaches and other symptoms over time, especially for babies, older people and anyone with heart or lung problems. If you feel unwell and suspect carbon monoxide, leave the building and call 911 even if the alarm is silent.</p>
<p>Look for “UL 2034” on the alarm or its packaging, replace it by the date printed on it, and fit one on every level of your home and outside each sleeping area. The full standard is sold by UL Standards &amp; Engagement.</p>
<p>Made ill by carbon monoxide even though an alarm was fitted? <a href="/review.html">Ask for a free case review</a> or call <a href="tel:+18884148680">1-888-414-8680</a>.</p>';
}, 20);

function cmk_seo_description($description)
{
    $meta = cmk_seo_fixed_meta();
    return $meta ? $meta[1] : $description;
}

add_filter('wpseo_title', 'cmk_seo_title');
add_filter('wpseo_opengraph_title', 'cmk_seo_title');
add_filter('wpseo_metadesc', 'cmk_seo_description');
add_filter('wpseo_opengraph_desc', 'cmk_seo_description');
