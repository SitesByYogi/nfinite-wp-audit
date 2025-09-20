<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Site Health Digest (REST-based)
 * - Pulls tests from /wp-json/wp-site-health/v1/tests/{direct|async}
 * - Filters to a curated set, normalizes, caches
 */
function nfinite_get_site_health_digest( $force_refresh = false, $include_async = true ) {
    if ( ! current_user_can('view_site_health_checks') ) {
        return array('error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit'));
    }

    $cache_key = 'nfinite_site_health_digest' . ( $include_async ? '_with_async' : '' );
    if ( ! $force_refresh ) {
        $cached = get_transient($cache_key);
        if ( $cached ) return $cached;
    }

    // Curate the “important” tests (slugs in WP core registry)
    $wanted_slugs = array(
        'https_status',
        'ssl_support',
        'rest_availability',
        'loopback_requests',
        'dotorg_communication',
        'background_updates',
        'php_version',
        'sql_server',              // note: on some installs this can be 'database_server'; we map below
        'database_server',
        'plugin_version',
        'theme_version',
        'persistent_object_cache',
        'is_in_debug_mode',
        'timezone',
    );

    $nonce   = wp_create_nonce('wp_rest');
    $headers = array('X-WP-Nonce' => $nonce);
    $items   = array();

    // Helper to fetch a test set and merge
    $fetch = function( $type ) use ( $headers, $wanted_slugs ) {
        $url  = rest_url('wp-site-health/v1/tests/' . $type);
        $res  = wp_remote_get( $url, array('headers' => $headers, 'timeout' => 15) );
        if ( is_wp_error($res) ) return array();
        $code = wp_remote_retrieve_response_code($res);
        if ( 200 !== (int)$code ) return array();

        $body = json_decode( wp_remote_retrieve_body($res), true );
        if ( ! is_array($body) ) return array();

        $out = array();
        foreach ( $body as $slug => $result ) {
            // Normalize slug variants
            $norm_slug = $slug;
            if ( 'database_server' === $slug ) $norm_slug = 'sql_server';

            if ( ! in_array($norm_slug, $wanted_slugs, true) ) continue;
            if ( ! is_array($result) ) continue;

            $status = isset($result['status']) ? $result['status'] : 'recommended'; // 'good' | 'recommended' | 'critical'
            $label  = isset($result['label'])  ? $result['label']  : ucfirst(str_replace('_',' ', $norm_slug));

            $out[] = array(
                'slug'        => $norm_slug,
                'status'      => $status,
                'label'       => $label,
                'description' => isset($result['description']) ? wp_kses_post($result['description']) : '',
                'badge'       => isset($result['badge']['label']) ? sanitize_text_field($result['badge']['label']) : '',
                'actions'     => isset($result['actions']) ? wp_kses_post($result['actions']) : '',
                // keep the source type if you want to display it (optional)
                '_source'     => $type,
            );
        }
        return $out;
    };

    // Always fetch "direct"
    $items = array_merge( $items, $fetch('direct') );

    // Optionally fetch and merge "async" (some sites put important results there)
    if ( $include_async ) {
        $items = array_merge( $items, $fetch('async') );
        // Deduplicate by slug, prefer direct over async
        $seen = array();
        $items = array_values(array_filter($items, function($it) use (&$seen) {
            if ( isset($seen[$it['slug']]) ) return false;
            $seen[$it['slug']] = true;
            return true;
        }));
    }

    // Fallback note if nothing came back
    if ( empty($items) ) {
        // If REST is blocked, let the user know rather than showing an empty box
        $rest_status = function_exists('rest_url') ? rest_url() : '';
        return array(
            'items'      => array(),
            'refreshed'  => current_time('mysql'),
            'site'       => get_bloginfo('name'),
            'error'      => sprintf(__('No Site Health results were returned. If this persists, check that REST API is enabled for logged-in users (URL: %s).', 'nfinite-audit'), esc_html($rest_status))
        );
    }

    // Sort: critical → recommended → good → then by label
    usort($items, function($a,$b){
        $order = array('critical'=>0,'recommended'=>1,'good'=>2);
        $ac = $order[$a['status']] ?? 9;
        $bc = $order[$b['status']] ?? 9;
        if ($ac === $bc) return strcasecmp($a['label'], $b['label']);
        return $ac <=> $bc;
    });

    $payload = array(
        'items'      => $items,
        'refreshed'  => current_time('mysql'),
        'site'       => get_bloginfo('name'),
    );

    set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
    return $payload;
}

/** Map Site Health status to badge classes for UI */
function nfinite_health_status_class( $status ) {
    switch ($status) {
        case 'critical':    return 'nfinite-badge nfinite-badge-danger';
        case 'recommended': return 'nfinite-badge nfinite-badge-warn';
        default:            return 'nfinite-badge nfinite-badge-good';
    }
}
