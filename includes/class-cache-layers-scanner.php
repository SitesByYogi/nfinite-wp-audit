<?php
if ( ! defined('ABSPATH') ) exit;

class Nfinite_Cache_Layers_Scanner {
    private static $page_cache_plugins = [
        'wp-rocket/wp-rocket.php'                    => 'WP Rocket',
        'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
        'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
        'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
        'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
        'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
        'sg-cachepress/sg-cachepress.php'            => 'SG Optimizer',
        'swift-performance-lite/performance.php'     => 'Swift Performance',
        'comet-cache/comet-cache.php'                => 'Comet Cache',
        'nitropack/main.php'                         => 'NitroPack',
    ];

    private static $object_cache_plugins = [
        'redis-cache/redis-cache.php'            => 'Redis Object Cache',
        'w3-total-cache/w3-total-cache.php'      => 'W3TC (Object Cache)',
        'litespeed-cache/litespeed-cache.php'    => 'LiteSpeed (Object Cache)',
        'memcached-redux/memcached-redux.php'    => 'Memcached Redux',
        'docket-cache/docket-cache.php'          => 'Docket Cache',
        'object-cache-pro/object-cache-pro.php'  => 'Object Cache Pro',
    ];

    public static function scan() {
        $results = [
            'active_page_cache_plugins'   => self::get_active_page_cache_plugins(),
            'active_object_cache_plugins' => self::get_active_object_cache_plugins(),
            'dropins'                     => self::get_dropins(),
            'headers'                     => [],
            'cdn'                         => [],
            'server_cache'                => [],
            'risks'                       => [],
            'recommendations'             => [],
        ];

        $resp = wp_remote_get( home_url('/'), [
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => [ 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ],
        ]);

        if ( ! is_wp_error($resp) ) {
            $headers = wp_remote_retrieve_headers($resp);
            $headers = array_change_key_case((array) $headers, CASE_LOWER);
            $results['headers'] = $headers;

            $cdn = [];
            if ( isset($headers['cf-cache-status']) || (isset($headers['server']) && stripos($headers['server'], 'cloudflare') !== false) ) {
                $cdn[] = 'Cloudflare';
            }
            if ( isset($headers['x-served-by']) && stripos($headers['x-served-by'], 'fastly') !== false ) {
                $cdn[] = 'Fastly';
            }
            if ( isset($headers['x-akamai-staging']) || isset($headers['x-akamai-transformed']) ) {
                $cdn[] = 'Akamai';
            }
            if ( isset($headers['x-cache']) && stripos($headers['x-cache'], 'cloudfront') !== false ) {
                $cdn[] = 'Amazon CloudFront';
            }
            $results['cdn'] = array_values(array_unique($cdn));

            // --- Server cache detection (Varnish/NGINX/LiteSpeed/OpenResty) ---
            $server_cache = [];
            $get = function($k) use ($headers) {
                $k = strtolower($k);
                return isset($headers[$k]) ? (is_array($headers[$k]) ? implode(', ', $headers[$k]) : $headers[$k]) : '';
            };

            $x_cache         = strtolower($get('x-cache'));
            $x_fastcgi       = strtolower($get('x-fastcgi-cache'));
            $x_cache_status  = strtolower($get('x-cache-status'));
            $x_nginx_cache   = strtolower($get('x-nginx-cache'));
            $x_nginx_status  = strtolower($get('x-nginx-cache-status'));
            $x_proxy_cache   = strtolower($get('x-proxy-cache'));
            $x_accel_expires = $get('x-accel-expires');
            $src_store       = strtolower($get('x-srcache-store-status'));
            $src_fetch       = strtolower($get('x-srcache-fetch-status'));
            $age_hdr         = $get('age');

            // Varnish
            if ( isset($headers['x-varnish']) or (!empty($age_hdr) && (stripos($x_cache, 'hit') !== false)) ) {
                $server_cache[] = 'Varnish';
            }

            // NGINX FastCGI / reverse proxy variants
            $nginx_hit_vals = ['hit', 'miss', 'bypass', 'expired', 'updating', 'revalidated'];
            if ( in_array($x_fastcgi, $nginx_hit_vals, true) ) {
                $server_cache[] = 'NGINX FastCGI cache';
            }
            if ( in_array($x_cache_status, $nginx_hit_vals, true) || in_array($x_nginx_cache, $nginx_hit_vals, true) || in_array($x_nginx_status, $nginx_hit_vals, true) ) {
                $server_cache[] = 'NGINX FastCGI cache';
            }
            if ( in_array($x_proxy_cache, $nginx_hit_vals, true) ) {
                $server_cache[] = 'NGINX/Proxy cache';
            }
            if ( stripos($x_cache, 'nginx') !== false ) {
                $server_cache[] = 'NGINX cache';
            }
            if ( !empty($x_accel_expires) ) {
                $server_cache[] = 'NGINX (X-Accel-Expires)';
            }
            // OpenResty/ngx_srcache
            if ( in_array($src_store, ['store', 'bypass'], true) or in_array($src_fetch, ['hit','miss','bypass'], true) ) {
                $server_cache[] = 'OpenResty srcache (NGINX)';
            }

            // LiteSpeed
            if ( isset($headers['x-litespeed-cache']) or (isset($headers['server']) && stripos($headers['server'], 'litespeed') !== false) ) {
                $server_cache[] = 'LiteSpeed Server Cache';
            }

            $results['server_cache'] = array_values(array_unique($server_cache));
            // --- /Server cache detection ---
        }

        if ( count($results['active_page_cache_plugins']) > 1 ) {
            $results['risks'][] = 'Multiple page cache plugins are active—this often causes stale pages and hard-to-debug cache hits.';
        }
        if ( !empty($results['active_page_cache_plugins']) && (!empty($results['cdn']) || !empty($results['server_cache'])) ) {
            $results['risks'][] = 'Page cache plugin + CDN/server cache detected. Use only one layer to cache HTML; others should be pass-through or disabled.';
        }
        if ( self::is_object_cache_dropin_present() && count($results['active_object_cache_plugins']) > 1 ) {
            $results['risks'][] = 'Multiple object cache systems detected (drop-in + plugin). Use only one Redis/Memcached provider.';
        }

        $results['recommendations'] = self::build_recommendations($results);
        return $results;
    }

    private static function get_active_page_cache_plugins() {
        $active = (array) get_option('active_plugins', []);
        $found  = [];
        foreach ( self::$page_cache_plugins as $slug => $label ) {
            if ( in_array($slug, $active, true) ) $found[$slug] = $label;
        }
        return $found;
    }

    private static function get_active_object_cache_plugins() {
        $active = (array) get_option('active_plugins', []);
        $found  = [];
        foreach ( self::$object_cache_plugins as $slug => $label ) {
            if ( in_array($slug, $active, true) ) $found[$slug] = $label;
        }
        return $found;
    }

    private static function get_dropins() {
        $dropins = [];
        if ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) $dropins[] = 'advanced-cache.php';
        if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) )   $dropins[] = 'object-cache.php';
        return $dropins;
    }

    private static function is_object_cache_dropin_present() {
        return file_exists( WP_CONTENT_DIR . '/object-cache.php' );
    }

    private static function build_recommendations($r) {
        $rec = [];

        if ( count($r['active_page_cache_plugins']) > 1 ) {
            $rec[] = 'Deactivate extra page cache plugins. Keep only **one** page cache plugin active.';
        }
        if ( !empty($r['cdn']) && !empty($r['active_page_cache_plugins']) ) {
            $rec[] = 'If using a CDN ('.implode(', ', $r['cdn']).'), set HTML caching at **either** the CDN **or** the plugin, not both. Prefer CDN for static assets only.';
        }
        if ( !empty($r['server_cache']) ) {
            $rec[] = 'Host/server cache detected ('.implode(', ', $r['server_cache']).'). Ensure it does not also cache HTML if a plugin/CDN already does.';
        }
        if ( self::is_object_cache_dropin_present() && count($r['active_object_cache_plugins']) > 1 ) {
            $rec[] = 'Use only one object cache (e.g., Redis **or** Memcached) to avoid conflicts.';
        }
        $rec[] = 'After changes, purge all layers: plugin cache → CDN cache → server cache.';
        $rec[] = 'For Cloudflare users, use **Development Mode** while editing, then disable and purge when done.';

        return $rec;
    }
}
