<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Comprehensive Site Health Digest
 * - Collect from REST, WP_Site_Health::get_tests(), and public get_test_* (reflect), then merge.
 * - De-duplicate by slug (prefer most severe: critical > recommended > good).
 * - Normalize payloads and avoid generic "Data" labels.
 * - Cache briefly; pass $force_refresh=true to rebuild.
 */
function nfinite_get_site_health_digest( $force_refresh = false, $include_async = true ) {
    // Capabilities (older WP versions may lack view_site_health_checks)
    if ( ! current_user_can('view_site_health_checks') && ! current_user_can('manage_options') ) {
        return array('error' => __('You do not have permission to view Site Health checks.', 'nfinite-audit'));
    }

    $suffix    = $include_async ? '_with_async' : '_direct_only';
    $cache_key = 'nfinite_site_health_digest' . $suffix;

    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( $cached ) return $cached;
    }

    // ---------------- helpers ----------------

    $map_status = function($s){
        $s = strtolower((string)$s);
        if ( in_array($s, array('critical','fail','error'), true) ) return 'critical';
        if ( in_array($s, array('recommended','warning','info'), true) ) return 'recommended';
        if ( in_array($s, array('good','pass'), true) ) return 'good';
        return 'recommended';
    };

    $status_rank = function($s){
        $s = strtolower((string)$s);
        if ( $s === 'critical' )    return 0;
        if ( $s === 'recommended' ) return 1;
        return 2; // good / anything else
    };

    $to_text = function($v) {
        if ( is_string($v) )   return $v;
        if ( is_numeric($v) )  return (string)$v;

        if ( is_array($v) ) {
            foreach ( array('raw','rendered','message','text','value','content') as $k ) {
                if ( isset($v[$k]) && is_string($v[$k]) ) return $v[$k];
            }
            $parts = array();
            $walker = function($x) use (&$parts, &$walker) {
                if ( is_array($x) ) { foreach ($x as $xi) $walker($xi); }
                elseif ( is_scalar($x) ) { $parts[] = (string)$x; }
            };
            $walker($v);
            return $parts ? implode(' ', $parts) : '';
        }

        if ( is_object($v) ) {
            if ( method_exists($v, '__toString') ) return (string)$v;
            foreach ( array('rendered','raw','message','text','value','content') as $prop ) {
                if ( isset($v->$prop) && is_string($v->$prop) ) return $v->$prop;
            }
            $json = function_exists('wp_json_encode') ? wp_json_encode($v) : @json_encode($v);
            return is_string($json) ? $json : '';
        }

        return '';
    };

    $to_html = function($v) use ($to_text) {
        $s = $to_text($v);
        return ($s === '') ? '' : wp_kses_post($s);
    };

    $normalize = function($slug_hint, $result) use ($to_text, $to_html, $map_status) {
        // Slug: prefer explicit fields; never allow literal "data"
        $slug_source = '';
        if ( !empty($result['test']) && is_string($result['test']) ) {
            $slug_source = $result['test'];
        } elseif ( !empty($result['slug']) && is_string($result['slug']) ) {
            $slug_source = $result['slug'];
        } else {
            $slug_source = is_string($slug_hint) ? $slug_hint : $to_text($slug_hint);
        }
        $slug_source = trim((string)$slug_source);
        if ($slug_source === '' || strtolower($slug_source) === 'data') {
            $payload_for_hash = function_exists('maybe_serialize') ? maybe_serialize($result) : serialize($result);
            $slug_source = 'test_' . substr( md5( $payload_for_hash ), 0, 8 );
        }
        $slug_source = str_replace('/', '_', $slug_source);
        $slug = sanitize_key($slug_source);

        // Label: prefer provided label; if empty or "data", humanize slug
        $label_raw = isset($result['label']) ? $result['label'] : '';
        $label_str = is_string($label_raw) ? trim($label_raw) : '';
        if ($label_str === '' || strtolower($label_str) === 'data') {
            $label_raw = ucfirst( trim( str_replace(array('_','-'), ' ', $slug) ) );
        }

        // Badge: support either scalar or ['label'=>..]
        $badge_val = '';
        if ( isset($result['badge']['label']) && is_string($result['badge']['label']) ) {
            $badge_val = sanitize_text_field($result['badge']['label']);
        } elseif ( isset($result['badge']) && is_string($result['badge']) ) {
            $badge_val = sanitize_text_field($result['badge']);
        }

        // Actions: array or scalar → HTML
        $actions_html = '';
        if ( isset($result['actions']) ) {
            if ( is_array($result['actions']) ) {
                $lis = array();
                foreach ($result['actions'] as $a) {
                    $ah = $to_html($a);
                    if ($ah !== '') $lis[] = '<li>'.$ah.'</li>';
                }
                if ($lis) $actions_html = '<ul class="ul-disc" style="margin-left:18px;">'.implode('', $lis).'</ul>';
            } else {
                $actions_html = $to_html($result['actions']);
            }
        }

        return array(
            'slug'        => $slug,
            'status'      => $map_status( $result['status'] ?? 'recommended' ),
            'label'       => wp_strip_all_tags( is_string($label_raw) ? $label_raw : $to_text($label_raw) ),
            'description' => $to_html( $result['description'] ?? '' ),
            'badge'       => $badge_val,
            'actions'     => $actions_html,
        );
    };

    $merge_results = function(array $base, array $incoming) use ($status_rank) {
        foreach ($incoming as $slug => $item) {
            if (!isset($base[$slug])) { $base[$slug] = $item; continue; }
            $a = $base[$slug]; $b = $item;
            $ra = $status_rank($a['status']); $rb = $status_rank($b['status']);
            if ( $rb < $ra ) { $base[$slug] = $b; continue; }
            if ( $rb === $ra ) {
                $a_has = (!empty($a['description']) || !empty($a['actions']));
                $b_has = (!empty($b['description']) || !empty($b['actions']));
                if ($b_has && !$a_has) $base[$slug] = $b;
            }
        }
        return $base;
    };

    // ---------------- collectors ----------------

    // REST (internal dispatch; no manual rest_api_init)
    $collect_from_rest = function($type) use ($normalize) {
        if ( ! function_exists('rest_get_server') || ! function_exists('rest_do_request') || ! class_exists('WP_REST_Request') ) {
            return array();
        }
        $server = rest_get_server();
        if ( ! $server || ! method_exists($server, 'get_routes') ) return array();

        // Check routes to avoid triggering plugins on rest_api_init
        $routes = $server->get_routes();
        $has_site_health = false;
        foreach ( $routes as $route => $handlers ) {
            if ( strpos($route, '/wp-site-health/v1/tests') === 0 ) { $has_site_health = true; break; }
        }
        if ( ! $has_site_health ) return array();

        try {
            $req = new WP_REST_Request('GET', '/wp-site-health/v1/tests/' . $type);
            $res = rest_do_request($req);
        } catch (Throwable $e) {
            return array();
        }

        if ( is_wp_error($res) ) return array();

        $data = null;
        if ( $res instanceof WP_REST_Response ) {
            $data = $res->get_data();
        } else {
            $data = $server->response_to_data($res, false);
        }
        if ( ! is_array($data) ) return array();

        $out = array();
        $i = 0;
        foreach ( $data as $k => $result ) {
            if ( ! is_array($result) ) continue;

            // Best-effort slug hint
            $slug_hint = '';
            if ( is_string($k) && $k !== '' && !is_numeric($k) && strtolower($k) !== 'data' ) {
                $slug_hint = $k;
            } elseif ( !empty($result['test']) && is_string($result['test']) ) {
                $slug_hint = $result['test'];
            } elseif ( !empty($result['slug']) && is_string($result['slug']) ) {
                $slug_hint = $result['slug'];
            } else {
                $slug_hint = $type . '_' . ($i++);
            }

            $norm = $normalize($slug_hint, $result);
            if (!empty($norm['slug'])) $out[$norm['slug']] = $norm;
        }
        return $out;
    };

    // LOCAL (get_tests + callables)
    $collect_from_local = function($include_async) use ($normalize) {
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
            return null; // do not call private perform_test
        };

        $out = array();
        foreach ( array('direct','async') as $type ) {
            if ($type==='async' && !$include_async) continue;
            if ( empty($tests[$type]) || !is_array($tests[$type]) ) continue;

            $i = 0;
            foreach ($tests[$type] as $k => $def) {
                try { $res = $run($def); } catch (Throwable $e) { $res = null; }
                if (!is_array($res)) continue;

                $slug_hint = '';
                if (!empty($def['test']) && is_string($def['test'])) $slug_hint = $def['test'];
                elseif (is_string($k) && $k !== '' && !is_numeric($k) && strtolower($k)!=='data') $slug_hint = $k;
                else $slug_hint = $type.'_'.($i++);

                $norm = $normalize($slug_hint, $res);
                if (!empty($norm['slug'])) $out[$norm['slug']] = $norm;
            }
        }

        return $out;
    };

    // REFLECT (public get_test_* methods)
    $collect_from_reflect = function() use ($normalize) {
        if ( ! class_exists('WP_Site_Health') ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
        $health  = new WP_Site_Health();
        $methods = get_class_methods($health);
        $out     = array();

        foreach ( (array) $methods as $m ) {
            if ( strpos($m, 'get_test_') !== 0 ) continue;
            try {
                $res = call_user_func( array($health, $m) );
            } catch (Throwable $e) {
                $res = null;
            }
            if ( is_array($res) ) {
                $slug_hint = substr($m, 9); // after "get_test_"
                if ( $slug_hint === '' || strtolower($slug_hint) === 'data' ) {
                    $payload_for_hash = function_exists('maybe_serialize') ? maybe_serialize($res) : serialize($res);
                    $slug_hint = 'reflect_' . substr( md5( $payload_for_hash ), 0, 8 );
                }
                $norm = $normalize($slug_hint, $res);
                if (!empty($norm['slug'])) $out[$norm['slug']] = $norm;
            }
        }
        return $out;
    };

    // ---------- collect from all sources and merge ----------

    $by_slug = array();
    $used    = array();

    // REST
    $direct_r = $collect_from_rest('direct');
    if ($direct_r) { $by_slug = array_merge($by_slug, $direct_r); $used['rest'] = true; }

    if ($include_async) {
        $async_r  = $collect_from_rest('async');
        if ($async_r) { $by_slug = $merge_results($by_slug, $async_r); $used['rest'] = true; }
    }

    // LOCAL
    $local = $collect_from_local($include_async);
    if ($local) { $by_slug = $merge_results($by_slug, $local); $used['local'] = true; }

    // REFLECT
    $reflect = $collect_from_reflect();
    if ($reflect) { $by_slug = $merge_results($by_slug, $reflect); $used['reflect'] = true; }

    // ---------- finish ----------

    $items = array_values($by_slug);

    if ( empty($items) ) {
        return array(
            'items'      => array(),
            'counts'     => array('critical'=>0,'recommended'=>0,'good'=>0),
            'refreshed'  => current_time('mysql'),
            'site'       => get_bloginfo('name'),
            'source'     => 'none',
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

    $src = empty($used) ? 'none' : implode('+', array_keys($used));

    $payload = array(
        'items'      => $items,
        'counts'     => $counts,
        'refreshed'  => current_time('mysql'),
        'site'       => get_bloginfo('name'),
        'source'     => $src,
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
