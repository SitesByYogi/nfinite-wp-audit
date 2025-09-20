<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Comprehensive Site Health Digest
 * - Pulls BOTH Direct and Async tests (prefer REST; fallback to running callables).
 * - Includes plugin-provided tests (e.g., WP Mail SMTP).
 * - Coerces mixed values to strings before wp_kses_post() to avoid fatals.
 * - Groups & counts by status (critical, recommended, good).
 * - Cached briefly; your Refresh clears the transient.
 */
function nfinite_get_site_health_digest( $force_refresh = false, $include_async = true ) {
    // Capability fallback for older WP
    if ( ! current_user_can('view_site_health_checks') && ! current_user_can('manage_options') ) {
        return array('error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit'));
    }

    $suffix    = $include_async ? '_with_async' : '_direct_only';
    $cache_key = 'nfinite_site_health_digest' . $suffix;

    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
    }

    // ---------- safe string helpers ----------
    $to_text = function($v) {
        if (is_string($v))   return $v;
        if (is_numeric($v))  return (string)$v;

        if (is_array($v)) {
            foreach (array('raw','rendered','message','text','value','content') as $k) {
                if (isset($v[$k]) && is_string($v[$k])) return $v[$k];
            }
            $parts = array();
            $walker = function($x) use (&$parts, &$walker) {
                if (is_array($x)) { foreach ($x as $xi) $walker($xi); }
                elseif (is_scalar($x)) { $parts[] = (string)$x; }
            };
            $walker($v);
            return $parts ? implode(' ', $parts) : '';
        }

        if (is_object($v)) {
            if (method_exists($v, '__toString')) return (string)$v;
            foreach (array('rendered','raw','message','text','value','content') as $prop) {
                if (isset($v->$prop) && is_string($v->$prop)) return $v->$prop;
            }
            $json = wp_json_encode($v);
            return is_string($json) ? $json : '';
        }

        return '';
    };

    $to_html = function($v) use ($to_text) {
        $s = $to_text($v);
        return $s === '' ? '' : wp_kses_post($s);
    };

    $normalize = function( $slug, $result ) use ($to_text, $to_html) {
        $label_raw = isset($result['label']) ? $result['label'] : ucfirst(str_replace('_',' ', (string)$slug));
        $badge_val = '';
        if ( isset($result['badge']['label']) && is_string($result['badge']['label']) ) {
            $badge_val = sanitize_text_field($result['badge']['label']);
        } elseif ( isset($result['badge']) && is_string($result['badge']) ) {
            $badge_val = sanitize_text_field($result['badge']);
        }

        $status = isset($result['status']) ? $result['status'] : 'recommended';
        // Some plugins use other words (e.g., 'fail'). Map conservatively.
        if ($status === 'fail') $status = 'critical';
        if ($status === 'good' || $status === 'recommended' || $status === 'critical') {
            // ok
        } else {
            // Unknown => bucket as recommended
            $status = 'recommended';
        }

        return array(
            'slug'        => sanitize_key( is_string($slug) ? $slug : $to_text($slug) ),
            'status'      => $status, // good|recommended|critical
            'label'       => wp_strip_all_tags( is_string($label_raw) ? $label_raw : $to_text($label_raw) ),
            'description' => $to_html( $result['description'] ?? '' ),
            'badge'       => $badge_val,
            'actions'     => $to_html( $result['actions'] ?? '' ),
        );
    };

    // ---------- attempt REST first ----------
    $items = array();
    $rest_ok = false;

    if ( function_exists('rest_url') && function_exists('wp_remote_get') ) {
        $headers = array('X-WP-Nonce' => wp_create_nonce('wp_rest'));
        $fetch   = function( $type ) use ($headers) {
            $url = rest_url('wp-site-health/v1/tests/' . $type);
            $res = wp_remote_get($url, array('headers'=>$headers, 'timeout'=>15));
            if (is_wp_error($res)) return null;
            $code = wp_remote_retrieve_response_code($res);
            if ((int)$code !== 200) return null;
            $body = json_decode( wp_remote_retrieve_body($res), true );
            return is_array($body) ? $body : null;
        };

        $direct_body = $fetch('direct');
        if ( is_array($direct_body) ) {
            foreach ($direct_body as $slug => $result) {
                if (is_array($result)) $items[] = $normalize($slug, $result);
            }
            $rest_ok = true;
        }

        if ( $include_async ) {
            $async_body = $fetch('async');
            if ( is_array($async_body) ) {
                // Merge async. If duplicate slug exists, prefer DIRECT.
                $seen = array();
                foreach ($items as $it) { $seen[$it['slug']] = true; }
                foreach ($async_body as $slug => $result) {
                    if (isset($seen[$slug])) continue;
                    if (is_array($result)) $items[] = $normalize($slug, $result);
                }
                $rest_ok = $rest_ok || !empty($async_body);
            }
        }
    }

    // ---------- fallback: run callables from WP_Site_Health ----------
    if ( ! $rest_ok ) {
        if ( ! class_exists('WP_Site_Health') ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
        $health = new WP_Site_Health();
        $tests  = $health->get_tests();

        $run = function( $def ) use ($health) {
            if ( empty($def['test']) ) return null;
            $cb = $def['test'];
            if ( is_callable($cb) ) return call_user_func($cb);
            if ( is_string($cb) && method_exists($health, $cb) && is_callable(array($health, $cb)) ) {
                return call_user_func(array($health, $cb));
            }
            return null; // never call private perform_test
        };

        $buckets = array();
        foreach (array('direct','async') as $type) {
            if ($type === 'async' && ! $include_async) continue;
            if ( isset($tests[$type]) && is_array($tests[$type]) ) {
                foreach ($tests[$type] as $slug => $def) {
                    $res = $run($def);
                    if ( is_array($res) ) $buckets[] = $normalize($slug, $res);
                }
            }
        }
        $items = $buckets;
    }

    if ( empty($items) ) {
        return array(
            'items'      => array(),
            'refreshed'  => current_time('mysql'),
            'site'       => get_bloginfo('name'),
            'counts'     => array('critical'=>0,'recommended'=>0,'good'=>0),
            'error'      => __('No Site Health results were returned. If this persists, check that REST API and loopback requests are allowed for logged-in admins.', 'nfinite-audit'),
        );
    }

    // Sort and count
    usort($items, function($a,$b){
        $order = array('critical'=>0,'recommended'=>1,'good'=>2);
        $ac = $order[$a['status']] ?? 9;
        $bc = $order[$b['status']] ?? 9;
        if ($ac === $bc) return strcasecmp($a['label'], $b['label']);
        return $ac <=> $bc;
    });

    $counts = array('critical'=>0,'recommended'=>0,'good'=>0);
    foreach ($items as $it) {
        if (isset($counts[$it['status']])) $counts[$it['status']]++;
    }

    $payload = array(
        'items'      => $items,
        'counts'     => $counts,
        'refreshed'  => current_time('mysql'),
        'site'       => get_bloginfo('name'),
        'source'     => $rest_ok ? 'rest' : 'local',
    );

    // Cache 5 minutes; your Refresh clears this
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
