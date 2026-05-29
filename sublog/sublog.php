<?php
/**
 * Plugin Name: Sublog
 * Plugin URI: https://example.com/sublog
 * Description: Automatically turns /blog/* URLs into a blog.{domain}/* subdomain blog using redirects and WordPress URL filters. Domain-agnostic.
 * Version: 1.0.0
 * Author: Custom
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sublog
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================
 * CONFIG
 * ============================================================
 *
 * Default behavior:
 * Main site:
 *   https://example.com
 *
 * Old blog URLs:
 *   https://example.com/blog/post-name/
 *
 * New blog URLs:
 *   https://blog.example.com/post-name/
 *
 * You can override these with constants in wp-config.php if needed:
 *
 * define('SUBLOG_BLOG_SUBDOMAIN', 'blog');
 * define('SUBLOG_BLOG_PATH_BASE', 'blog');
 * define('SUBLOG_FORCE_SCHEME', 'https');
 */

if (!defined('SUBLOG_BLOG_SUBDOMAIN')) {
    define('SUBLOG_BLOG_SUBDOMAIN', 'blog');
}

if (!defined('SUBLOG_BLOG_PATH_BASE')) {
    define('SUBLOG_BLOG_PATH_BASE', 'blog');
}

if (!defined('SUBLOG_FORCE_SCHEME')) {
    define('SUBLOG_FORCE_SCHEME', 'https');
}

/**
 * ============================================================
 * HELPERS
 * ============================================================
 */

function sublog_get_request_host(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = strtolower(trim($host));

    // Remove port if present.
    $host = preg_replace('/:\d+$/', '', $host);

    return $host ?: '';
}

function sublog_get_request_uri(): string {
    return $_SERVER['REQUEST_URI'] ?? '/';
}

function sublog_get_home_host(): string {
    $home = home_url('/');
    $parts = wp_parse_url($home);

    if (empty($parts['host'])) {
        return '';
    }

    return strtolower($parts['host']);
}

function sublog_strip_www(string $host): string {
    return preg_replace('/^www\./', '', strtolower($host));
}

function sublog_get_root_domain(): string {
    $home_host = sublog_get_home_host();

    // If WordPress home is www.example.com, normalize to example.com.
    return sublog_strip_www($home_host);
}

function sublog_get_blog_host(): string {
    return SUBLOG_BLOG_SUBDOMAIN . '.' . sublog_get_root_domain();
}

function sublog_get_main_hosts(): array {
    $root = sublog_get_root_domain();

    return array_values(array_unique([
        $root,
        'www.' . $root,
    ]));
}

function sublog_is_blog_host(?string $host = null): bool {
    $host = $host ?: sublog_get_request_host();

    return sublog_strip_www($host) === sublog_get_blog_host();
}

function sublog_is_main_host(?string $host = null): bool {
    $host = $host ?: sublog_get_request_host();

    return in_array($host, sublog_get_main_hosts(), true);
}

function sublog_get_blog_base_path(): string {
    return trim(SUBLOG_BLOG_PATH_BASE, '/');
}

function sublog_get_blog_base_regex(): string {
    return preg_quote(sublog_get_blog_base_path(), '#');
}

function sublog_url_has_blog_base(string $path): bool {
    $base = sublog_get_blog_base_regex();

    return (bool) preg_match('#^/' . $base . '(/|$)#i', $path);
}

function sublog_remove_blog_base_from_path(string $path): string {
    $base = sublog_get_blog_base_regex();

    $new_path = preg_replace('#^/' . $base . '(/|$)#i', '/', $path);
    $new_path = '/' . ltrim($new_path, '/');

    return $new_path === '' ? '/' : $new_path;
}

function sublog_add_blog_base_to_path(string $path): string {
    $base = sublog_get_blog_base_path();

    $path = '/' . ltrim($path, '/');

    if (sublog_url_has_blog_base($path)) {
        return $path;
    }

    return '/' . $base . ($path === '/' ? '/' : $path);
}

function sublog_blog_url(string $path = '/', string $query = ''): string {
    $path = '/' . ltrim($path, '/');

    $url = SUBLOG_FORCE_SCHEME . '://' . sublog_get_blog_host() . $path;

    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }

    return $url;
}

function sublog_main_url(string $path = '/', string $query = ''): string {
    $path = '/' . ltrim($path, '/');

    $url = SUBLOG_FORCE_SCHEME . '://' . sublog_get_root_domain() . $path;

    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }

    return $url;
}

function sublog_is_unsafe_redirect_context(): bool {
    if (is_admin()) {
        return true;
    }

    if (defined('DOING_AJAX') && DOING_AJAX) {
        return true;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    if (defined('DOING_CRON') && DOING_CRON) {
        return true;
    }

    if (wp_doing_ajax()) {
        return true;
    }

    if (wp_doing_cron()) {
        return true;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return true;
    }

    $uri = sublog_get_request_uri();

    $blocked_patterns = [
        '#^/wp-admin(/|$)#i',
        '#^/wp-login\.php#i',
        '#^/wp-json(/|$)#i',
        '#^/xmlrpc\.php#i',
        '#^/wp-cron\.php#i',
        '#^/admin-ajax\.php#i',
    ];

    foreach ($blocked_patterns as $pattern) {
        if (preg_match($pattern, $uri)) {
            return true;
        }
    }

    return false;
}

function sublog_is_preview_request(): bool {
    return isset($_GET['preview']) || isset($_GET['preview_id']) || isset($_GET['preview_nonce']);
}

function sublog_replace_main_blog_url_with_subdomain(string $url): string {
    if ($url === '') {
        return $url;
    }

    $root = sublog_get_root_domain();
    $blog_host = sublog_get_blog_host();
    $base = sublog_get_blog_base_path();

    $searches = [
        'https://' . $root . '/' . $base,
        'http://' . $root . '/' . $base,
        'https://www.' . $root . '/' . $base,
        'http://www.' . $root . '/' . $base,
    ];

    $replacement = SUBLOG_FORCE_SCHEME . '://' . $blog_host;

    return str_replace($searches, $replacement, $url);
}

/**
 * ============================================================
 * 1. REDIRECT OLD /blog/* URLS TO BLOG SUBDOMAIN
 * ============================================================
 *
 * Example:
 * https://example.com/blog/post-name/
 * becomes:
 * https://blog.example.com/post-name/
 */

add_action('template_redirect', function () {
    if (sublog_is_unsafe_redirect_context() || sublog_is_preview_request()) {
        return;
    }

    $host = sublog_get_request_host();

    if (!sublog_is_main_host($host)) {
        return;
    }

    $uri = sublog_get_request_uri();
    $parts = wp_parse_url($uri);

    $path = $parts['path'] ?? '/';
    $query = $parts['query'] ?? '';

    if (!sublog_url_has_blog_base($path)) {
        return;
    }

    $new_path = sublog_remove_blog_base_from_path($path);
    $target = sublog_blog_url($new_path, $query);

    wp_safe_redirect($target, 301);
    exit;
}, 1);

/**
 * ============================================================
 * 2. INTERNALLY MAP BLOG SUBDOMAIN REQUESTS BACK TO /blog/*
 * ============================================================
 *
 * This helps when WordPress expects posts to live under /blog/%postname%/.
 *
 * Example browser URL:
 * https://blog.example.com/post-name/
 *
 * Internal WordPress request becomes:
 * /blog/post-name/
 */

add_filter('request', function ($query_vars) {
    if (!sublog_is_blog_host()) {
        return $query_vars;
    }

    if (sublog_is_unsafe_redirect_context() || sublog_is_preview_request()) {
        return $query_vars;
    }

    $uri = sublog_get_request_uri();
    $parts = wp_parse_url($uri);

    $path = $parts['path'] ?? '/';

    // Let root blog homepage pass through.
    // Depending on your site, you may want to map this to a posts page.
    if ($path === '/' || $path === '') {
        return $query_vars;
    }

    // If WordPress already resolved it, do not interfere.
    if (!empty($query_vars)) {
        return $query_vars;
    }

    $internal_path = sublog_add_blog_base_to_path($path);

    // Ask WordPress to parse the internal /blog/* path.
    $request = trim($internal_path, '/');

    if ($request !== '') {
        $query_vars['pagename'] = $request;
    }

    return $query_vars;
}, 1);

/**
 * ============================================================
 * 3. PREVENT WORDPRESS CANONICAL REDIRECTS FROM FIGHTING THIS
 * ============================================================
 *
 * WordPress canonical redirects are useful, but they may try to send
 * blog.example.com/post-name/ back to example.com/blog/post-name/.
 *
 * Returning false from redirect_canonical cancels the canonical redirect.
 */

add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
    if (sublog_is_blog_host()) {
        return false;
    }

    return $redirect_url;
}, 10, 2);

/**
 * ============================================================
 * 4. FILTER GENERATED BLOG URLS
 * ============================================================
 *
 * These filters make WordPress-generated links use the blog subdomain.
 */

add_filter('post_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('post_type_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('page_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('category_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('tag_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('author_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('term_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('the_permalink', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('post_comments_feed_link', 'sublog_replace_main_blog_url_with_subdomain', 20);
add_filter('feed_link', 'sublog_replace_main_blog_url_with_subdomain', 20);

/**
 * ============================================================
 * 5. FILTER HOME URL ONLY FOR BLOG PATHS
 * ============================================================
 *
 * This avoids globally changing the whole site URL.
 * WordPress exposes the home_url filter for modifying generated home URLs.
 */

add_filter('home_url', function ($url, $path, $orig_scheme, $blog_id) {
    $path = is_string($path) ? $path : '';

    if ($path === '') {
        return $url;
    }

    $path_with_slash = '/' . ltrim($path, '/');

    if (!sublog_url_has_blog_base($path_with_slash)) {
        return $url;
    }

    return sublog_replace_main_blog_url_with_subdomain($url);
}, 20, 4);

/**
 * ============================================================
 * 6. CANONICAL URL FILTERS FOR COMMON SEO PLUGINS
 * ============================================================
 *
 * Keep only the filters for the SEO plugin you use.
 */

/**
 * Yoast SEO canonical.
 */
add_filter('wpseo_canonical', function ($canonical) {
    return is_string($canonical) ? sublog_replace_main_blog_url_with_subdomain($canonical) : $canonical;
}, 20);

/**
 * Yoast Open Graph URL.
 */
add_filter('wpseo_opengraph_url', function ($url) {
    return is_string($url) ? sublog_replace_main_blog_url_with_subdomain($url) : $url;
}, 20);

/**
 * Rank Math canonical.
 */
add_filter('rank_math/frontend/canonical', function ($canonical) {
    return is_string($canonical) ? sublog_replace_main_blog_url_with_subdomain($canonical) : $canonical;
}, 20);

/**
 * Rank Math Open Graph URL.
 */
add_filter('rank_math/opengraph/url', function ($url) {
    return is_string($url) ? sublog_replace_main_blog_url_with_subdomain($url) : $url;
}, 20);

/**
 * ============================================================
 * 7. SITEMAP URL FILTERING
 * ============================================================
 *
 * This catches many WordPress core sitemap URLs.
 */

add_filter('wp_sitemaps_posts_entry', function ($entry, $post) {
    if (isset($entry['loc'])) {
        $entry['loc'] = sublog_replace_main_blog_url_with_subdomain($entry['loc']);
    }

    return $entry;
}, 20, 2);

add_filter('wp_sitemaps_taxonomies_entry', function ($entry, $term, $taxonomy) {
    if (isset($entry['loc'])) {
        $entry['loc'] = sublog_replace_main_blog_url_with_subdomain($entry['loc']);
    }

    return $entry;
}, 20, 3);

add_filter('wp_sitemaps_users_entry', function ($entry, $user) {
    if (isset($entry['loc'])) {
        $entry['loc'] = sublog_replace_main_blog_url_with_subdomain($entry['loc']);
    }

    return $entry;
}, 20, 2);

/**
 * ============================================================
 * 8. OPTIONAL DEBUG HEADER
 * ============================================================
 *
 * Sends a small header when WP_DEBUG is enabled.
 */

add_action('send_headers', function () {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    header('X-Sublog-Host: ' . sublog_get_request_host());
    header('X-Sublog-Blog-Host: ' . sublog_get_blog_host());
});
