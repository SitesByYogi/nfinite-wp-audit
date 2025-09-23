<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'nfinite_estimate_lighthouse' ) ) {
    /**
     * Heuristic/estimated Lighthouse scores when PSI is unavailable.
     * Uses internal section scores as a proxy.
     */
    function nfinite_estimate_lighthouse( array $internal ) : array {
        $sections = isset($internal['sections']) && is_array($internal['sections']) ? $internal['sections'] : array();
        $get = function($key, $default = 0) use ($sections) {
            return isset($sections[$key]['score']) ? (int)$sections[$key]['score'] : $default;
        };

        // Very simple baselines; tweak as you wish
        $performance    = (int) round( ( $get('caching') + $get('assets') + $get('images') + $get('server') ) / 4 );
        $best_practices = (int) round( ( $get('core') + $get('database') + $get('server') ) / 3 );
        $seo            = isset($sections['seo_basics']['score']) ? (int)$sections['seo_basics']['score'] : 0;

        return array(
            'performance'    => max(0, min(100, $performance)),
            'best_practices' => max(0, min(100, $best_practices)),
            'seo'            => max(0, min(100, $seo)),
            '_estimated'     => true,
        );
    }
}

function nfinite_recommendations_registry(){
    return array(
        'cache_present' => array('title'=>'Enable full-page caching','message'=>'No page cache detected. Enable server/page caching (e.g., W3 Total Cache, WP Rocket, LiteSpeed, or host cache).','docs'=>'https://developer.wordpress.org/caching/','severity'=>'high'),
        'compression' => array('title'=>'Turn on gzip/Brotli compression','message'=>'Responses are not compressed. Enable gzip/Brotli at the server or via plugin/CDN.','docs'=>'https://wordpress.org/support/article/optimization/','severity'=>'high'),
        'client_cache' => array('title'=>'Add long-lived browser caching for assets','message'=>'CSS/JS lack Cache-Control max-age. Set far-future headers on static assets.','docs'=>'https://web.dev/http-cache/','severity'=>'medium'),
        'assets_counts' => array('title'=>'Reduce CSS/JS requests','message'=>'Too many individual files. Concatenate where possible and remove unused enqueues.','docs'=>'https://web.dev/requests/','severity'=>'medium'),
        'render_blocking' => array('title'=>'Eliminate render-blocking resources','message'=>'Add defer/async to non-critical scripts and inline critical CSS.','docs'=>'https://web.dev/render-blocking-resources/','severity'=>'high'),
        'images_dims_and_size' => array('title'=>'Serve next-gen / sized images','message'=>'Define width/height and serve WebP/AVIF where supported.','docs'=>'https://web.dev/uses-webp-images/','severity'=>'medium'),
        'ttfb' => array('title'=>'Reduce server TTFB','message'=>'Add page cache, optimize PHP/DB, and reduce slow queries.','docs'=>'https://web.dev/ttfb/','severity'=>'high'),
        'h2_h3' => array('title'=>'Enable HTTP/2 or HTTP/3','message'=>'Upgrade server/CDN to support multiplexing and HPACK/QPACK.','docs'=>'https://web.dev/http2/','severity'=>'low'),
        'autoload_size' => array('title'=>'Shrink autoloaded options','message'=>'Large autoload bloat slows every page load. Prune options and avoid marking big data autoload=yes.','docs'=>'https://wordpress.org/documentation/article/optimization/','severity'=>'medium'),
        'postmeta_bloat' => array('title'=>'Normalize postmeta','message'=>'Heavy meta per post — clean up unused keys and index frequently-queried ones.','docs'=>'https://make.wordpress.org/core/','severity'=>'low'),
        'transients' => array('title'=>'Purge expired transients','message'=>'Expired transients found — schedule cleanup.','docs'=>'https://developer.wordpress.org/apis/option/trns/','severity'=>'low'),
        'updates_core' => array('title'=>'Update WordPress core','message'=>'Keep core up to date for security and performance fixes.','docs'=>'https://wordpress.org/download/releases/','severity'=>'high'),
        'updates_plugins' => array('title'=>'Update plugins','message'=>'Outdated plugins increase risk and overhead. Remove unused ones.','docs'=>'https://wordpress.org/plugins/','severity'=>'medium'),
        'updates_themes' => array('title'=>'Update themes','message'=>'Keep your active/child themes updated.','docs'=>'https://wordpress.org/themes/','severity'=>'low'),
    );
}
