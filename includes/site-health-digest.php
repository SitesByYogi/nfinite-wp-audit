<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Comprehensive Site Health Digest (internal REST)
 * - Runs BOTH Direct and Async tests by dispatching WP's REST routes internally (rest_do_request),
 *   so we get the same results as Tools → Site Health (including plugin tests) without HTTP/nonces.
 * - Falls back to calling public callables if REST is unavailable.
 * - Dedupes by slug, keeping the most severe status (critical > recommended > good).
 * - Coerces mixed values (arrays/objects) to safe HTML strings before wp_kses_post().
 * - Caches briefly; your "Refresh" button clears the cache.
 */
function nfinite_get_site_health_digest( $force_refresh = false, $include_async = true ) {
    if ( ! current_user_can('view_site_health_checks') && ! current_user_can('manage_options') ) {
        return array('error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit'));
    }

    $suffix    = $include_async ? '_with_async' : '_direct_only';
    $cache_key = 'nfinite_site_health_digest' . $suffix;

    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
    }

    // ---------- helpers ----------
    $status_rank = function($s){
        $s = strtolower((string)$s);
        if (in_array($s, array('critical','fail','error'), true)) return 0;
        if (in_array($s, array('recommended','warning','info'), true)) return 1;
        return 2; // good / pass / anything else
    };
    $map_status = function($s){
        $s = strtolower((string)$s);
        if (in_array($s, array('critical','fail','error'), true)) return 'critical';
        if (in_array($s, array('recommended','warning','info'), true)) return 'recommended';
        if (in_array($s, array('good','pass'), true)) return 'good';
        return 'recommended';
    };

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
            $json = function_exists('wp_json_encode') ? wp_json_encode($v) : json_encode($v);
            return is_string($json) ? $json : '';
        }
        return '';
    };
    $to_html = function($v) use ($to_text) {
        $s = $to_text($v);
        return $s === '' ? '' : wp_kses_post($s);
    };

    $normalize = function($slug, $result) use ($to_text, $to_html, $map_status) {
        $label_raw = isset($result['label']) ? $result['label'] : ucfirst(str_replace('_',' ', (string)$slug));
        $badge_val = '';
        if ( isset($result['badge']['label']) && is_string($result['badge']['label']) ) {
            $badge_val = sanitize_text_field($result['badge']['label']);
        } elseif ( isset($result['badge']) && is_string($result['badge']) ) {
            $badge_val = sanitize_text_field($result['badge']);
        }
        return array(
            'slug'        => sanitize_key( is_string($slug) ? $slug : $to_text($slug) ),
            'status'      => $map_status( $result['status'] ?? 'recommended' ), // critical|recommended|good
            'label'       => wp_strip_all_tags( is_string($label_raw) ? $label_raw : $to_text($label_raw) ),
            'description' => $to_html( $result['description'] ?? '' ),
            'badge'       => $badge_val,
            'actions'     => $to_html( $result['actions'] ?? '' ),
        );
    };

    $merge_results = function(array $base, array $incoming) use ($status_rank) {
        // Keep the most severe per slug; prefer items with any description/actions when ranks tie.
        foreach ($incoming as $slug => $item) {
            if (!isset($base[$slug])) { $base[$slug] = $item; continue; }
            $a = $base[$slug]; $b = $item;
            $ra = $status_rank($a['status']); $rb = $status_rank($b['status']);
            if ( $rb < $ra ) { $base[$slug] = $b; continue; }
            if ( $rb === $ra ) {
                $a_has = (!empty($a['description']) || !empty($a['actions']));
                $b_has = (!empty($b['description']) || !empty($b['actions']));
                if ( $b_has && ! $a_has ) $base[$slug] = $b;
            }
        }
        return $base;
    };

    // ---------- preferred: dispatch REST internally ----------
    $items = array();
    $by_slug = array();
    $used_rest = false;

    if ( function_exists('rest_do_request') && class_exists('WP_REST_Request') ) {
        $dispatch = function($type){
            $req = new WP_REST_Request('GET', '/wp-site-health/v1/tests/' . $type);
            $res = rest_do_request($req);
            if ( is_wp_error($res) ) return null;
            if ( $res instanceof WP_REST_Response ) {
                $data = $res->get_data();
            } else {
                $server = rest_get_server();
                $data = $server ? $server->response_to_data($res, false) : null;
            }
            return is_array($data) ? $data : null;
        };

        // DIRECT tests
        $direct = $dispatch('direct');
        if ( is_array($direct) ) {
            $tmp = array();
            foreach ($direct as $slug => $result) {
                if (is_array($result)) $tmp[$slug] = $normalize($slug, $result);
            }
            $by_slug = $merge_results($by_slug, $tmp);
            $used_rest = true;
        }

        // ASYNC tests
        if ( $include_async ) {
            $async = $dispatch('async');
            if ( is_array($async) ) {
                $tmp = array();
                foreach ($async as $slug => $result) {
                    if (is_array($result)) $tmp[$slug] = $normalize($slug, $result);
                }
                $by_slug = $merge_results($by_slug, $tmp);
                $used_rest = true;
            }
        }
    }

    // ---------- fallback: call public callables ----------
    if ( ! $used_rest ) {
        if ( ! class_exists('WP_Site_Health') ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
        $health = new WP_Site_Health();
        $tests  = $health->get_tests();

        $run = function($def) use ($health) {
            if ( empty($def['test']) ) return null;
            $cb = $def['test'];
            if ( is_callable($cb) ) return call_user_func($cb);
            if ( is_string($cb) && method_exists($health, $cb) && is_callable(array($health, $cb)) ) {
                return call_user_func(array($health, $cb));
            }
            return null; // never call private perform_test
        };

        foreach ( array('direct','async') as $type ) {
            if ($type === 'async' && ! $include_async) continue;
            if ( isset($tests[$type]) && is_array($tests[$type]) ) {
                $tmp = array();
                foreach ($tests[$type] as $slug => $def) {
                    $res = $run($def);
                    if ( is_array($res) ) $tmp[$slug] = $normalize($slug, $res);
                }
                $by_slug = $merge_results($by_slug, $tmp);
            }
        }
    }

    // ---------- finish ----------
    $items = array_values($by_slug);
    if ( empty($items) ) {
        return array(
            'items'      => array(),
            'counts'     => array('critical'=>0,'recommended'=>0,'good'=>0),
            'refreshed'  => current_time('mysql'),
            'site'       => get_bloginfo('name'),
            'source'     => $used_rest ? 'rest-internal' : 'local',
            'error'      => __('No Site Health results were returned. If this persists, check that Site Health routes are registered and loopback requests aren’t blocked.', 'nfinite-audit'),
        );
    }

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
        'source'     => $used_rest ? 'rest-internal' : 'local',
    );

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
