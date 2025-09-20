<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Site Health Digest
 * - Executes a curated subset of Site Health "direct" tests
 * - Returns normalized items (status, label, description, actions)
 * - Cached with a transient to keep the admin snappy
 */
function nfinite_get_site_health_digest( $force_refresh = false ) {
    if ( ! current_user_can('view_site_health_checks') ) {
        return array(
            'error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit')
        );
    }

    $cache_key = 'nfinite_site_health_digest';
    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
    }

    if ( ! class_exists('WP_Site_Health') ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }

    $health = new WP_Site_Health();
    $tests  = $health->get_tests();

    // Curate the “important” tests you want to surface (slugs from the registry)
    $wanted_slugs = array(
        'https_status',
        'ssl_support',
        'rest_availability',
        'loopback_requests',
        'dotorg_communication',
        'background_updates',
        'php_version',
        'sql_server',
        'plugin_version',
        'theme_version',
        'persistent_object_cache',
        'is_in_debug_mode',
        'timezone',
    );

    $items = array();

    // Only run "direct" tests (fast, synchronous).
    $direct = isset($tests['direct']) && is_array($tests['direct']) ? $tests['direct'] : array();

    foreach ( $direct as $slug => $test ) {
        if ( ! in_array( $slug, $wanted_slugs, true ) ) continue;

        $cb = $test['test'] ?? null;
        $result = null;

        // Safely execute only when callable or public method on the instance.
        try {
            if ( is_callable( $cb ) ) {
                // e.g. closure or [$health, 'get_test_xyz']
                $result = call_user_func( $cb );
            } elseif ( is_string( $cb ) && method_exists( $health, $cb ) ) {
                // If the registry stored a string method name on the instance
                $ref = new ReflectionMethod( $health, $cb );
                if ( $ref->isPublic() ) {
                    $result = $health->{$cb}();
                }
            } else {
                // Not safely invokable in this context; skip (prevents calling private perform_test()).
                continue;
            }
        } catch (Throwable $e) {
            // If a test throws, skip it instead of breaking the dashboard.
            continue;
        }

        if ( ! is_array( $result ) ) continue;

        // Normalize fields we’ll display
        $items[] = array(
            'slug'        => $slug,
            'status'      => $result['status']      ?? 'recommended', // 'good' | 'recommended' | 'critical'
            'label'       => $result['label']       ?? ucfirst(str_replace('_',' ',$slug)),
            'description' => isset($result['description']) ? wp_kses_post($result['description']) : '',
            'badge'       => isset($result['badge']['label']) ? sanitize_text_field($result['badge']['label']) : '',
            'actions'     => isset($result['actions']) ? wp_kses_post($result['actions']) : '',
        );
    }

    // Sort: critical → recommended → good, then by label
    usort( $items, function($a, $b) {
        $order = array('critical' => 0, 'recommended' => 1, 'good' => 2);
        $ac = $order[$a['status']] ?? 9;
        $bc = $order[$b['status']] ?? 9;
        if ( $ac === $bc ) return strcasecmp($a['label'], $b['label']);
        return $ac <=> $bc;
    });

    $payload = array(
        'items'      => $items,
        'refreshed'  => current_time('mysql'),
        'site'       => get_bloginfo('name'),
    );

    // Cache for 15 minutes to avoid running checks too often
    set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );

    return $payload;
}

/**
 * Map Site Health status to your badge classes.
 */
function nfinite_health_status_class( $status ) {
    switch ($status) {
        case 'critical':    return 'nfinite-badge nfinite-badge-danger';
        case 'recommended': return 'nfinite-badge nfinite-badge-warn';
        default:            return 'nfinite-badge nfinite-badge-good';
    }
}
