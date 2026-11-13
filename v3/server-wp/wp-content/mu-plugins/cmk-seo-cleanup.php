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
    );
    $id = get_queried_object_id();
    return isset($fixed[$id]) ? $fixed[$id] : null;
}

function cmk_seo_title($title)
{
    $meta = cmk_seo_fixed_meta();
    return $meta ? $meta[0] . ' | CarbonMonoxideKills.com' : $title;
}

function cmk_seo_description($description)
{
    $meta = cmk_seo_fixed_meta();
    return $meta ? $meta[1] : $description;
}

add_filter('wpseo_title', 'cmk_seo_title');
add_filter('wpseo_opengraph_title', 'cmk_seo_title');
add_filter('wpseo_metadesc', 'cmk_seo_description');
add_filter('wpseo_opengraph_desc', 'cmk_seo_description');
