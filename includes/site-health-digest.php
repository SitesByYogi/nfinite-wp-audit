<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Site Health Digest (safe runner)
 * - Executes a curated subset of Site Health "direct" tests.
 * - No calls to private WP_Site_Health::perform_test().
 * - Falls back to running all direct tests if curated slugs don't exist.
 * - Cached briefly with a transient.
 */
function nfinite_get_site_health_digest( $force_refresh = false ) {
    // Allow either capability; older WP sites may not map 'view_site_health_checks'
    if ( ! current_user_can('view_site_health_checks') && ! current_user_can('manage_options') ) {
        return array(
            'error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit')
        );
    }

    $cache_key = 'nfinite_site_health_digest_v2';
    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
    }

    if ( ! class_exists('WP_Site_Health') ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }

    $health = new WP_Site_Health();
    $tests  = $health->get_tests();

    // Curate the “important” core tests. Some slugs vary across WP versions,
    // so we add a few aliases and also have a fallback below.
    $wanted_slugs = array(
        'https_status',
        'ssl_support',
        'rest_availability',
        'loopback_requests',
        'dotorg_communication',
        'background_updates',
        'php_version',
        'sql_server',           // aka mysql_server in older versions
        'mysql_server',
        'plugin_version',
        'theme_version',
        'persistent_object_cache',
        'is_in_debug_mode',
        'timezone',
        'utf8mb4_support',      // present on many installs
        'theme_supports_php',   // appears on newer installs
    );

    $normalize = function( $slug, $result ) {
        return array(
            'slug'        => sanitize_key( $slug ),
            'status'      => isset($result['status']) ? $result['status'] : 'recommended', // good | recommended | critical
            'label'       => isset($result['label']) ? wp_strip_all_tags($result['label']) : ucfirst(str_replace('_',' ', $slug)),
            'description' => isset($result['description']) ? wp_kses_post($result['description']) : '',
            'badge'       => isset($result['badge']['label']) ? sanitize_text_field($result['badge']['label']) : '',
            'actions'     => isset($result['actions']) ? wp_kses_post($result['actions']) : '',
        );
    };

    // Safe runner that never calls private perform_test()
    $run_test = function( $test_def ) use ( $health ) {
        if ( empty($test_def['test']) ) return null;
        $cb = $test_def['test'];

        // If a global callable/closure
        if ( is_callable( $cb ) ) {
            return call_user_func( $cb );
        }

        // If it's a method name on WP_Site_Health, make sure it's callable (not private)
        if ( is_string( $cb ) && method_exists( $health, $cb ) ) {
            // is_callable will be false for private/protected methods — perfect.
            if ( is_callable( array( $health, $cb ) ) ) {
                return call_user_func( array( $health, $cb ) );
            }
        }

        return null; // Unsupported test shape
    };

    $items = array();
    $direct = is_array( $tests ) && isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();

    // 1) Try curated slugs first
    foreach ( $direct as $slug => $def ) {
        if ( ! in_array( $slug, $wanted_slugs, true ) ) continue;
        $result = $run_test( $def );
        if ( is_array( $result ) ) $items[] = $normalize( $slug, $result );
    }

    // 2) Fallback: if nothing matched (version mismatch), run a subset of *all* direct tests
    if ( empty( $items ) && $direct ) {
        $count = 0;
        foreach ( $direct as $slug => $def ) {
            $result = $run_test( $def );
            if ( is_array( $result ) ) {
                $items[] = $normalize( $slug, $result );
                if ( ++$count >= 12 ) break; // keep it snappy
            }
        }
    }

    if ( empty( $items ) ) {
        // Return a helpful error rather than an empty set
        return array(
            'error' => __(
                'No Site Health results were returned. This can happen if a security plugin blocks loopback/REST requests or if tests are unavailable on this WordPress version. Open Tools → Site Health in another tab to confirm tests run, and ensure REST is reachable for logged-in users.',
                'nfinite-audit'
            )
        );
    }

    // Sort: critical → recommended → good, then by label
    usort( $items, function( $a, $b ) {
        $order = array('critical'=>0, 'recommended'=>1, 'good'=>2);
        $ac = $order[ $a['status'] ] ?? 9;
        $bc = $order[ $b['status'] ] ?? 9;
        if ( $ac === $bc ) return strcasecmp( $a['label'], $b['label'] );
        return $ac <=> $bc;
    });

    $payload = array(
        'items'     => $items,
        'refreshed' => current_time('mysql'),
        'site'      => get_bloginfo('name'),
    );

    // Cache briefly (5 minutes). Your UI can pass $force_refresh=true to bypass.
    set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

    return $payload;
}

/**
 * Optional: tiny helper for status → CSS class
 */
function nfinite_health_status_class( $status ) {
    switch ( $status ) {
        case 'critical':    return 'nfinite-badge nfinite-badge-danger';
        case 'recommended': return 'nfinite-badge nfinite-badge-warn';
        default:            return 'nfinite-badge nfinite-badge-good';
    }
}
