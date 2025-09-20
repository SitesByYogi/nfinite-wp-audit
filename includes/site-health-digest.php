<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Site Health Digest (defensive runner)
 * - Runs a curated subset of Site Health "direct" tests.
 * - Never calls private perform_test().
 * - Coerces mixed values (arrays/objects) to strings safely before wp_kses_post().
 * - Cached briefly with a transient (cleared by your Refresh button).
 */
function nfinite_get_site_health_digest( $force_refresh = false ) {
    // Capability fallback for older WP
    if ( ! current_user_can('view_site_health_checks') && ! current_user_can('manage_options') ) {
        return array('error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit'));
    }

    $cache_key = 'nfinite_site_health_digest'; // keep in sync with your refresh handler
    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
    }

    if ( ! class_exists('WP_Site_Health') ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }

    $health = new WP_Site_Health();
    $tests  = $health->get_tests();

    // Curated slugs (add a few aliases to handle version variance)
    $wanted_slugs = array(
        'https_status',
        'ssl_support',
        'rest_availability',
        'loopback_requests',
        'dotorg_communication',
        'background_updates',
        'php_version',
        'sql_server',           // aka mysql_server / database_server
        'mysql_server',
        'database_server',
        'plugin_version',
        'theme_version',
        'persistent_object_cache',
        'is_in_debug_mode',
        'timezone',
        'utf8mb4_support',
        'theme_supports_php',
    );

    // ---- helpers -----------------------------------------------------------
    $to_text = function($v) {
        // Return a plain string from mixed input
        if (is_string($v)) return $v;
        if (is_numeric($v)) return (string) $v;

        if (is_array($v)) {
            // Prefer common keys if present
            foreach (array('raw','rendered','message','text','value','content') as $k) {
                if (isset($v[$k]) && is_string($v[$k])) return $v[$k];
            }
            // Flatten any scalar leaves
            $parts = array();
            $walker = function($x) use (&$parts, &$walker) {
                if (is_array($x)) {
                    foreach ($x as $xi) $walker($xi);
                } elseif (is_scalar($x)) {
                    $parts[] = (string) $x;
                }
            };
            $walker($v);
            return $parts ? implode(' ', $parts) : '';
        }

        if (is_object($v)) {
            if (method_exists($v, '__toString')) return (string) $v;
            // Common WP objects sometimes expose ->rendered or ->raw
            foreach (array('rendered','raw','message','text','value','content') as $prop) {
                if (isset($v->$prop) && is_string($v->$prop)) return $v->$prop;
            }
            // As last resort, JSON
            $json = wp_json_encode($v);
            return is_string($json) ? $json : '';
        }

        return '';
    };

    $to_html = function($v) use ($to_text) {
        // Always pass a *string* to KSES
        $s = $to_text($v);
        return $s === '' ? '' : wp_kses_post($s);
    };

    $normalize = function( $slug, $result ) use ( $to_html, $to_text ) {
        $label_raw = isset($result['label']) ? $result['label'] : ucfirst(str_replace('_',' ', $slug));
        $badge_val = '';
        if ( isset($result['badge']['label']) && is_string($result['badge']['label']) ) {
            $badge_val = sanitize_text_field($result['badge']['label']);
        } elseif ( isset($result['badge']) && is_string($result['badge']) ) {
            $badge_val = sanitize_text_field($result['badge']);
        }

        return array(
            'slug'        => sanitize_key( is_string($slug) ? $slug : $to_text($slug) ),
            'status'      => isset($result['status']) ? $result['status'] : 'recommended', // good|recommended|critical
            'label'       => wp_strip_all_tags( is_string($label_raw) ? $label_raw : $to_text($label_raw) ),
            'description' => $to_html( isset($result['description']) ? $result['description'] : '' ),
            'badge'       => $badge_val,
            'actions'     => $to_html( isset($result['actions']) ? $result['actions'] : '' ),
        );
    };

    $run_test = function( $test_def ) use ( $health ) {
        if ( empty($test_def['test']) ) return null;
        $cb = $test_def['test'];

        if ( is_callable( $cb ) ) {
            return call_user_func( $cb );
        }
        if ( is_string( $cb ) && method_exists( $health, $cb ) && is_callable( array($health, $cb) ) ) {
            return call_user_func( array($health, $cb) );
        }
        return null; // unsupported shape (avoids private perform_test)
    };
    // ------------------------------------------------------------------------

    $items  = array();
    $direct = ( is_array($tests) && isset($tests['direct']) && is_array($tests['direct']) ) ? $tests['direct'] : array();

    // 1) Run curated slugs first
    foreach ( $direct as $slug => $def ) {
        if ( ! in_array( $slug, $wanted_slugs, true ) ) continue;
        $result = $run_test( $def );
        if ( is_array( $result ) ) $items[] = $normalize( $slug, $result );
    }

    // 2) Fallback: if nothing matched (version mismatch), sample the first dozen direct tests
    if ( empty($items) && $direct ) {
        $count = 0;
        foreach ( $direct as $slug => $def ) {
            $result = $run_test( $def );
            if ( is_array( $result ) ) {
                $items[] = $normalize( $slug, $result );
                if ( ++$count >= 12 ) break;
            }
        }
    }

    if ( empty($items) ) {
        return array(
            'error' => __(
                'No Site Health results were returned. This can happen if a security plugin blocks loopback/REST requests, or if tests are unavailable in this WordPress version. Open Tools → Site Health in another tab to confirm tests run.',
                'nfinite-audit'
            ),
        );
    }

    // Sort: critical → recommended → good → label
    usort($items, function($a,$b){
        $order = array('critical'=>0,'recommended'=>1,'good'=>2);
        $ac = $order[$a['status']] ?? 9;
        $bc = $order[$b['status']] ?? 9;
        if ($ac === $bc) return strcasecmp($a['label'], $b['label']);
        return $ac <=> $bc;
    });

    $payload = array(
        'items'     => $items,
        'refreshed' => current_time('mysql'),
        'site'      => get_bloginfo('name'),
    );

    // Cache for 5 minutes. Your Refresh button deletes this transient.
    set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

    return $payload;
}

/** Status → CSS class for your UI */
function nfinite_health_status_class( $status ) {
    switch ($status) {
        case 'critical':    return 'nfinite-badge nfinite-badge-danger';
        case 'recommended': return 'nfinite-badge nfinite-badge-warn';
        default:            return 'nfinite-badge nfinite-badge-good';
    }
}
