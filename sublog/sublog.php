<?php
/**
 * Plugin Name: Sublog (MU)
 * Plugin URI: https://example.com/sublog
 * Description: Must-use plugin that automatically turns /blog/* URLs into a blog.{domain}/* subdomain blog using an early REQUEST_URI rewrite, redirects, WordPress URL filters, and an output-buffer link normalizer. Domain-agnostic. Always-on (cannot be deactivated from the admin).
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

/**
 * The canonical main-site host, exactly as WordPress is configured -- i.e.
 * WITH www when the site uses www (www.example.com), or bare when it does
 * not (example.com). Use this for links that point back to the main site so
 * we match the canonical host and avoid an extra non-www -> www redirect.
 *
 * (The blog host intentionally strips www: blog.example.com, never
 * blog.www.example.com.)
 */
function sublog_get_main_host(): string {
    $home_host = sublog_get_home_host();

    return $home_host !== '' ? $home_host : sublog_get_root_domain();
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

    $url = SUBLOG_FORCE_SCHEME . '://' . sublog_get_main_host() . $path;

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

function sublog_is_media_or_asset_url(string $url): bool {
    return (
        str_contains($url, '/wp-content/uploads/') ||
        str_contains($url, '/wp-content/themes/') ||
        str_contains($url, '/wp-content/plugins/') ||
        str_contains($url, '/wp-includes/')
    );
}

/**
 * Are we currently inside Rank Math's sitemap generation?
 *
 * Rank Math builds each sitemap entry's URL from get_permalink()/term_link
 * (which our permalink filters hook). It then DROPS any entry whose URL is
 * classified "external" -- i.e. a different host than the site, which is
 * exactly what blog.example.com is. If our permalink filters rewrite to the
 * subdomain during sitemap generation, the blog posts get silently removed
 * from the sitemap. So during this context we must leave permalinks LOCAL
 * (https://example.com/blog/...) and do the subdomain rewrite later, in the
 * rank_math/sitemap/entry filter (which runs AFTER the external check).
 */
function sublog_is_rank_math_sitemap_context(): bool {
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    if (stripos($uri, 'sitemap') !== false) {
        return true;
    }

    if (function_exists('doing_filter') && (
        doing_filter('rank_math/sitemap/entry') ||
        doing_filter('rank_math/sitemap/xml_post_url') ||
        doing_filter('rank_math/sitemap/post_type_archive_link') ||
        doing_filter('rank_math/sitemap/xml_img_src') ||
        doing_filter('rank_math/sitemap/urlimages')
    )) {
        return true;
    }

    return false;
}

/**
 * Unconditional rewrite of a main-domain /blog URL to the blog subdomain.
 * Used by the rank_math/sitemap/entry filter, which runs after Rank Math's
 * "external URL" check and therefore must always perform the rewrite.
 */
function sublog_force_replace_main_blog_url_with_subdomain(string $url): string {
    if ($url === '') {
        return $url;
    }

    if (sublog_is_media_or_asset_url($url)) {
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
 * Rewrite used by the general WordPress permalink/canonical/home_url filters.
 * It intentionally does NOTHING during Rank Math sitemap generation so the
 * sitemap keeps building local URLs (see sublog_is_rank_math_sitemap_context).
 */
function sublog_replace_main_blog_url_with_subdomain(string $url): string {
    if ($url === '') {
        return $url;
    }

    if (sublog_is_rank_math_sitemap_context()) {
        return $url;
    }

    return sublog_force_replace_main_blog_url_with_subdomain($url);
}

/**
 * ============================================================
 * OUTPUT BUFFER: LINK NORMALIZATION
 * ============================================================
 *
 * Two passes run on the final HTML:
 *
 * PASS A (every front-end page, main domain and blog subdomain):
 *   Rewrite URLs that clearly point at /blog -> the blog subdomain, so
 *   internal blog links go straight to blog.example.com without a 301 hop.
 *     href="/blog/post/"                    -> https://blog.example.com/post/
 *     href="https://example.com/blog/post/" -> https://blog.example.com/post/
 *
 * PASS B (ONLY when the current request is on the blog subdomain):
 *   The hard-coded templates emit root-relative main-site links such as
 *   <a href="/"> and <a href="/book-online">. Served on blog.example.com,
 *   the browser would resolve those against the CURRENT host, producing
 *   https://blog.example.com/book-online (wrong). This pass rewrites those
 *   remaining root-relative <a href>/<form action> links back to the main
 *   domain so they point where they should:
 *     href="/"            -> https://example.com/
 *     href="/book-online" -> https://example.com/book-online
 *
 * Both passes leave images, scripts, CSS, uploads, and WP system paths
 * alone (src is never touched; /wp-content, /wp-includes, /wp-admin,
 * /wp-json are skipped).
 */

add_action('template_redirect', function () {
    if (is_admin()) {
        return;
    }

    if (sublog_is_unsafe_redirect_context() || sublog_is_preview_request()) {
        return;
    }

    ob_start('sublog_rewrite_links_in_output');
}, 0);

function sublog_rewrite_links_in_output(string $html): string {
    if ($html === '') {
        return $html;
    }

    // Only process actual HTML responses.
    $content_type = '';

    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            $content_type = $header;
            break;
        }
    }

    if ($content_type && stripos($content_type, 'text/html') === false) {
        return $html;
    }

    /**
     * PASS A: rewrite href/action/content attributes that contain /blog
     * or /blog/ to the blog subdomain. Runs on every front-end page.
     */
    $html = preg_replace_callback(
        '/\s(href|action|content)=([\'"])([^\'"]*\/blog(?:\/[^\'"]*)?)\2/i',
        function ($matches) {
            $attr = $matches[1];
            $quote = $matches[2];
            $url = $matches[3];

            $new_url = sublog_rewrite_blog_url_to_subdomain($url);

            return ' ' . $attr . '=' . $quote . esc_url($new_url) . $quote;
        },
        $html
    );

    /**
     * PASS B: only on the blog subdomain, point remaining root-relative
     * main-site links (e.g. /, /book-online, /services/) back to the main
     * domain. Limited to <a href> and <form action> so assets are safe.
     * (Any /blog links were already made absolute by Pass A above.)
     */
    if (sublog_is_blog_host()) {
        $html = preg_replace_callback(
            '/(<a\b[^>]*\shref=|<form\b[^>]*\saction=)([\'"])(\/(?!\/)[^\'"]*)\2/i',
            function ($matches) {
                $prefix = $matches[1];
                $quote = $matches[2];
                $url = $matches[3];

                $new_url = sublog_rewrite_root_relative_to_main($url);

                return $prefix . $quote . esc_url($new_url) . $quote;
            },
            $html
        );
    }

    return $html;
}

function sublog_rewrite_root_relative_to_main(string $url): string {
    if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
        return $url;
    }

    // Leave WordPress system paths and assets on the current host.
    if (sublog_should_skip_rewrite_url($url)) {
        return $url;
    }

    $path = wp_parse_url($url, PHP_URL_PATH) ?: '/';

    // /blog links belong on the subdomain and are handled by Pass A.
    if (sublog_url_has_blog_base($path)) {
        return $url;
    }

    $query = wp_parse_url($url, PHP_URL_QUERY);
    $fragment = wp_parse_url($url, PHP_URL_FRAGMENT);

    $final = sublog_main_url($path, $query ?: '');

    if ($fragment) {
        $final .= '#' . $fragment;
    }

    return $final;
}

function sublog_rewrite_blog_url_to_subdomain(string $url): string {
    if ($url === '') {
        return $url;
    }

    if (sublog_should_skip_rewrite_url($url)) {
        return $url;
    }

    $root = sublog_get_root_domain();
    $blog_host = sublog_get_blog_host();
    $scheme = SUBLOG_FORCE_SCHEME;

    /**
     * Root-relative (with or without trailing slash):
     * /blog            -> https://blog.example.com/
     * /blog/post-name/ -> https://blog.example.com/post-name/
     */
    if ($url === '/blog' || str_starts_with($url, '/blog/')) {
        $path = substr($url, strlen('/blog'));
        $path = '/' . ltrim($path, '/');

        return $scheme . '://' . $blog_host . $path;
    }

    /**
     * Absolute main-domain URLs (with or without trailing slash):
     * https://example.com/blog
     * https://example.com/blog/post-name/
     * https://www.example.com/blog/post-name/
     */
    $patterns = [
        '#^https?://' . preg_quote($root, '#') . '/blog(/.*)?/?$#i',
        '#^https?://www\.' . preg_quote($root, '#') . '/blog(/.*)?/?$#i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $m)) {
            $path = $m[1] ?? '/';
            $path = '/' . ltrim($path, '/');

            return $scheme . '://' . $blog_host . $path;
        }
    }

    return $url;
}

function sublog_should_skip_rewrite_url(string $url): bool {
    if (
        str_starts_with($url, '#') ||
        str_starts_with($url, 'mailto:') ||
        str_starts_with($url, 'tel:') ||
        str_starts_with($url, 'javascript:') ||
        str_starts_with($url, 'data:') ||
        str_starts_with($url, '//')
    ) {
        return true;
    }

    if (
        str_contains($url, '/wp-content/') ||
        str_contains($url, '/wp-includes/') ||
        str_contains($url, '/wp-admin/') ||
        str_contains($url, '/wp-json/')
    ) {
        return true;
    }

    return false;
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

/**
 * Allow wp_safe_redirect() to send users to the blog subdomain.
 *
 * wp_safe_redirect() validates the target host against an allow-list and,
 * when the host is not allowed, falls back to wp-admin/. Because
 * blog.example.com is a DIFFERENT host than example.com, the redirect below
 * would otherwise bounce visitors to /wp-admin. Whitelisting the blog host
 * lets the redirect proceed while keeping wp_safe_redirect's protections.
 */
add_filter('allowed_redirect_hosts', function ($hosts) {
    if (!is_array($hosts)) {
        $hosts = [];
    }

    $hosts[] = sublog_get_blog_host();

    return array_values(array_unique(array_filter($hosts)));
});

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
 * Browser URL:
 *   https://blog.example.com/example-post/
 *
 * Internal WordPress request:
 *   /blog/example-post/
 *
 * This happens early by rewriting REQUEST_URI before WordPress parses
 * the request and rewrite rules, which is more reliable than adjusting
 * $query_vars later via the `request` filter.
 *
 * NOTE: Rather than hooking `muplugins_loaded` (whose timing relative to
 * this file's own load is ambiguous when the logic lives inside the
 * MU-plugin being loaded), we run the mapping immediately as the file
 * loads. By this point all required helpers and WordPress option/URL
 * functions are available, and this happens well before
 * WP::parse_request() reads $_SERVER['REQUEST_URI'].
 */

sublog_maybe_map_blog_subdomain_request();

function sublog_maybe_map_blog_subdomain_request(): void {
    if (!sublog_is_blog_host()) {
        return;
    }

    if (sublog_is_unsafe_redirect_context() || sublog_is_preview_request()) {
        return;
    }

    $uri = sublog_get_request_uri();
    $parts = wp_parse_url($uri);

    $path = $parts['path'] ?? '/';
    $query = $parts['query'] ?? '';

    // Blog homepage: map blog.example.com/ to the internal /blog/ index.
    if ($path === '/' || $path === '') {
        $_SERVER['REQUEST_URI'] = '/' . sublog_get_blog_base_path() . '/';

        if ($query !== '') {
            $_SERVER['REQUEST_URI'] .= '?' . $query;
        }

        return;
    }

    // Already prefixed with /blog/ for some reason; leave it.
    if (sublog_url_has_blog_base($path)) {
        return;
    }

    // Do not remap admin/system/asset paths.
    if (sublog_should_skip_internal_mapping_path($path)) {
        return;
    }

    $internal_path = sublog_add_blog_base_to_path($path);

    $_SERVER['REQUEST_URI'] = $internal_path;

    if ($query !== '') {
        $_SERVER['REQUEST_URI'] .= '?' . $query;
    }
}

function sublog_should_skip_internal_mapping_path(string $path): bool {
    $path = strtolower($path);

    $skip_prefixes = [
        '/wp-admin/',
        '/wp-content/',
        '/wp-includes/',
        '/wp-json/',
    ];

    foreach ($skip_prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    $skip_files = [
        '/wp-login.php',
        '/xmlrpc.php',
        '/wp-cron.php',
        '/robots.txt',
        '/favicon.ico',
    ];

    if (in_array($path, $skip_files, true)) {
        return true;
    }

    return false;
}

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
 */

/**
 * Rank Math sitemap entries (the primary path on these sites).
 *
 * Rank Math replaces WordPress core sitemaps with its own
 * (/sitemap_index.xml), so the wp_sitemaps_* filters below never run when
 * Rank Math is active. Rank Math runs EVERY entry -- posts, terms,
 * authors, archives, the home/posts page, and the HTML sitemap -- through
 * `rank_math/sitemap/entry` as [ 'loc' => ..., 'images' => [...] ].
 *
 * Most post/term locs are already rewritten because Rank Math builds them
 * from get_permalink()/get_term_link() (which our section 4 filters
 * cover). This filter is the safety net that also catches entries built
 * from raw URLs or overridden by a stored canonical, so /blog URLs in the
 * sitemap consistently point at the blog subdomain.
 *
 * Image locs are passed through the same helper, which leaves
 * /wp-content/uploads/ (and other asset) URLs untouched.
 */
add_filter('rank_math/sitemap/entry', function ($entry, $type, $object) {
    if (!is_array($entry)) {
        return $entry;
    }

    if (isset($entry['loc']) && is_string($entry['loc'])) {
        $before = $entry['loc'];
        $entry['loc'] = sublog_force_replace_main_blog_url_with_subdomain($entry['loc']);

        // Proof that this filter ran during (re)generation. Enable with
        // define('WP_DEBUG', true); then check wp-content/debug.log.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[sublog] sitemap entry (%s): %s -> %s',
                is_string($type) ? $type : 'unknown',
                $before,
                $entry['loc']
            ));
        }
    }

    if (!empty($entry['images']) && is_array($entry['images'])) {
        foreach ($entry['images'] as $i => $image) {
            if (isset($image['src']) && is_string($image['src'])) {
                $entry['images'][$i]['src'] = sublog_force_replace_main_blog_url_with_subdomain($image['src']);
            }
        }
    }

    return $entry;
}, 20, 3);

/**
 * Catch-all for sitemap entries that bypass rank_math/sitemap/entry.
 *
 * Rank Math's get_first_links() prepends the home page, the "posts page"
 * (e.g. /blog), and post-type archives WITHOUT running them through the
 * entry filter above, so those locs never get rewritten there (this is why
 * the /blog posts-page entry stayed on the main domain).
 *
 * rank_math/sitemap/url filters the final <url> XML for EVERY entry, so we
 * rewrite the main <loc> here. We only touch <loc> (never <image:loc>), and
 * the rewrite is idempotent: entries already moved to the subdomain by the
 * entry filter contain no main-domain /blog URL, so they pass through
 * unchanged.
 */
add_filter('rank_math/sitemap/url', function ($output, $url) {
    if (!is_string($output)) {
        return $output;
    }

    return preg_replace_callback(
        '#<loc>(.*?)</loc>#s',
        function ($m) {
            $decoded = html_entity_decode($m[1], ENT_QUOTES);
            $rewritten = sublog_force_replace_main_blog_url_with_subdomain($decoded);

            return '<loc>' . htmlspecialchars($rewritten, ENT_QUOTES) . '</loc>';
        },
        $output,
        1
    );
}, 20, 2);

/**
 * Rank Math caches generated sitemaps to disk and only re-runs the entry
 * filter above when it regenerates them (e.g. when a post is saved). So a
 * freshly deployed change does NOT retroactively rewrite an already-cached
 * sitemap -- you must regenerate/clear the cache once.
 *
 * For testing, you can force Rank Math to rebuild the sitemap on every
 * request (so rewrites always apply immediately) by adding this to
 * wp-config.php:
 *
 *   define('SUBLOG_DISABLE_SITEMAP_CACHE', true);
 *
 * Leave it OFF in production: regenerating on every request is slower.
 * In production, just clear the Rank Math sitemap cache once after deploy.
 */
if (defined('SUBLOG_DISABLE_SITEMAP_CACHE') && SUBLOG_DISABLE_SITEMAP_CACHE) {
    add_filter('rank_math/sitemap/enable_caching', '__return_false');
}

/**
 * WordPress core sitemap entries (fallback for when Rank Math sitemaps are
 * disabled). These are inert while Rank Math is active.
 */
add_filter('wp_sitemaps_posts_entry', function ($entry, $post) {
    if (isset($entry['loc'])) {
        $entry['loc'] = sublog_force_replace_main_blog_url_with_subdomain($entry['loc']);
    }

    return $entry;
}, 20, 2);

add_filter('wp_sitemaps_taxonomies_entry', function ($entry, $term, $taxonomy) {
    if (isset($entry['loc'])) {
        $entry['loc'] = sublog_force_replace_main_blog_url_with_subdomain($entry['loc']);
    }

    return $entry;
}, 20, 3);

add_filter('wp_sitemaps_users_entry', function ($entry, $user) {
    if (isset($entry['loc'])) {
        $entry['loc'] = sublog_force_replace_main_blog_url_with_subdomain($entry['loc']);
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
    header('X-Sublog-Root: ' . sublog_get_root_domain());
    header('X-Sublog-Blog-Host: ' . sublog_get_blog_host());
    header('X-Sublog-Is-Blog-Host: ' . (sublog_is_blog_host() ? 'yes' : 'no'));
});
