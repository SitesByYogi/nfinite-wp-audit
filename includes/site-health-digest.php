<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Comprehensive Site Health Digest
 *
 * What this does:
 * - Collects Site Health tests from internal REST (if routes are registered) and from local callables.
 * - Filters out REST "group meta" rows that caused placeholders like "Recommended Data".
 * - Ensures key core tests are present by directly calling their public methods as a final pass.
 * - Normalizes, de-duplicates by slug (most severe wins), and safely coerces values for output.
 * - Caches results briefly in a transient.
 *
 * @param bool $force_refresh  If true, bypass the transient cache.
 * @param bool $include_async  If true, include async tests where available.
 * @return array {
 *   @type array  items      Normalized list of test items
 *   @type array  counts     ['critical'=>int,'recommended'=>int,'good'=>int]
 *   @type string refreshed  MySQL datetime of generation
 *   @type string site       Site name
 *   @type string source     'rest+local'|'rest-internal'|'local'|'reflect'|'none'
 *   @type string error      Optional message if nothing collected
 * }
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

    // ----- helpers -----
    $map_status = function($s){
        $s = strtolower((string)$s);
        if ( in_array($s, array('critical','fail','error'), true) ) return 'critical';
        if ( in_array($s, array('recommended','warning','info'), true) ) return 'recommended';
        if ( in_array($s, array('good','pass'), true) ) return 'good';
        return 'recommended';
    };

    $status_rank = function($s){
        $s = strtolower((string)$s);
        if ($s === 'critical')    return 0;
        if ($s === 'recommended') return 1;
        return 2;
    };

    $to_text = function($v){
        if ( is_string($v) )  return $v;
        if ( is_numeric($v) ) return (string)$v;

        if ( is_array($v) ) {
            foreach ( array('raw','rendered','message','text','value','content') as $k ) {
                if ( isset($v[$k]) && is_string($v[$k]) ) return $v[$k];
            }
            $parts = array();
            $walker = function($x) use (&$parts, &$walker){
                if ( is_array($x) ) { foreach ($x as $y) $walker($y); }
                elseif ( is_scalar($x) ) { $parts[] = (string)$x; }
            };
            $walker($v);
            return implode(' ', $parts);
        }

        if ( is_object($v) ) {
            if ( method_exists($v,'__toString') ) return (string)$v;
            foreach ( array('rendered','raw','message','text','value','content') as $prop ) {
                if ( isset($v->$prop) && is_string($v->$prop) ) return $v->$prop;
            }
            $json = function_exists('wp_json_encode') ? wp_json_encode($v) : @json_encode($v);
            return is_string($json) ? $json : '';
        }

        return '';
    };

    $to_html = function($v) use ($to_text){
        $s = $to_text($v);
        return $s === '' ? '' : wp_kses_post($s);
    };

    $normalize = function($slug, $result) use ($to_text, $to_html, $map_status){
        // Some third-party tests may push their own slugs with spaces — normalize anyway.
        $safe_slug = sanitize_key( is_string($slug) ? $slug : $to_text($slug) );
        if ( $safe_slug === '' ) {
            // Fallback to a deterministic slug from label
            $safe_slug = sanitize_key( substr( md5( $to_text( $result['label'] ?? '' ) ), 0, 10 ) );
        }

        $label_raw = isset($result['label']) ? $result['label'] : ucfirst( str_replace('_',' ', (string)$safe_slug) );

        $badge_val = '';
        if ( isset($result['badge']['label']) && is_string($result['badge']['label']) ) {
            $badge_val = sanitize_text_field($result['badge']['label']);
        } elseif ( isset($result['badge']) && is_string($result['badge']) ) {
            $badge_val = sanitize_text_field($result['badge']);
        }

        return array(
            'slug'        => $safe_slug,
            'status'      => $map_status( $result['status'] ?? 'recommended' ),
            'label'       => wp_strip_all_tags( is_string($label_raw) ? $label_raw : $to_text($label_raw) ),
            'description' => $to_html( $result['description'] ?? '' ),
            'badge'       => $badge_val,
            'actions'     => $to_html( $result['actions'] ?? '' ),
        );
    };

    $merge_results = function(array $base, array $incoming) use ($status_rank){
        foreach ($incoming as $slug => $item) {
            if ( ! isset($item['status']) || ! isset($item['label']) ) continue;
            if ( ! isset($base[$slug]) ) { $base[$slug] = $item; continue; }
            $a = $base[$slug]; $b = $item;
            $ra = $status_rank($a['status']); $rb = $status_rank($b['status']);
            if ( $rb < $ra ) { $base[$slug] = $b; continue; }
            if ( $rb === $ra ) {
                $a_has = ( ! empty($a['description']) || ! empty($a['actions']) );
                $b_has = ( ! empty($b['description']) || ! empty($b['actions']) );
                if ( $b_has && ! $a_has ) $base[$slug] = $b;
            }
        }
        return $base;
    };

    $by_slug      = array();
    $used_rest    = false;
    $used_local   = false;
    $used_reflect = false;

    // ----- REST collection (no manual do_action('rest_api_init')) -----
    $routes_have_tests = false;
    if ( function_exists('rest_get_server') ) {
        $server = rest_get_server();
        if ( $server && method_exists($server, 'get_routes') ) {
            foreach ( $server->get_routes() as $route => $handlers ) {
                if ( strpos($route, '/wp-site-health/v1/tests') === 0 ) { $routes_have_tests = true; break; }
            }
        }
    }

    if ( $routes_have_tests && function_exists('rest_do_request') && class_exists('WP_REST_Request') ) {
        $dispatch = function($type){
            try {
                $req = new WP_REST_Request('GET', '/wp-site-health/v1/tests/' . $type);
                $res = rest_do_request($req);
                if ( is_wp_error($res) ) return null;
                if ( $res instanceof WP_REST_Response ) return $res->get_data();
                $server = rest_get_server();
                return $server ? $server->response_to_data($res, false) : null;
            } catch (Throwable $e) {
                return null;
            }
        };

        // Accept both shapes:
        // (A) flat: [ { test, status, label, ... }, ... ]
        // (B) grouped:
        //     {
        //       critical:    { label: "...", tests: [ ... ] },
        //       recommended: { label: "...", tests: [ ... ] },
        //       good:        { label: "...", tests: [ ... ] }
        //     }
        $collect = function($payload) use ($normalize){
            $tmp = array();
            if ( ! is_array($payload) ) return $tmp;

            $looks_grouped =
                ( isset($payload['critical'])    && is_array($payload['critical'])    && isset($payload['critical']['tests']) )
             || ( isset($payload['recommended']) && is_array($payload['recommended']) && isset($payload['recommended']['tests']) )
             || ( isset($payload['good'])        && is_array($payload['good'])        && isset($payload['good']['tests']) );

            if ( $looks_grouped ) {
                foreach ( array('critical','recommended','good') as $grp ) {
                    if ( empty($payload[$grp]['tests']) || ! is_array($payload[$grp]['tests']) ) continue;

                    $group_label = isset($payload[$grp]['label']) ? (string)$payload[$grp]['label'] : '';
                    $i = 0;
                    foreach ( $payload[$grp]['tests'] as $result ) {
                        if ( ! is_array($result) ) continue;

                        // Ignore accidental "group meta" rows (some environments leak these through).
                        if ( empty($result['test']) && empty($result['slug']) ) {
                            // If this row "label" equals the group label or is a short generic word like "Data", skip it.
                            $lbl = isset($result['label']) ? (string)$result['label'] : '';
                            if ( $lbl === $group_label || in_array( strtolower($lbl), array('data','summary','meta'), true ) ) {
                                continue;
                            }
                        }

                        if ( empty($result['status']) ) $result['status'] = $grp;

                        $slug = '';
                        if ( ! empty($result['test']) && is_string($result['test']) ) {
                            $slug = $result['test'];
                        } elseif ( ! empty($result['slug']) && is_string($result['slug']) ) {
                            $slug = $result['slug'];
                        } else {
                            // Build a readable slug from label to aid de-duping.
                            $lbl = isset($result['label']) ? (string)$result['label'] : ($grp . '_' . $i);
                            $slug = sanitize_key( $lbl !== '' ? $lbl : ($grp . '_' . $i) );
                        }

                        $tmp[$slug] = $normalize($slug, $result);
                        $i++;
                    }
                }
                return $tmp;
            }

            // Flat list fallback
            $i = 0;
            foreach ( $payload as $k => $result ) {
                if ( ! is_array($result) ) continue;

                // Skip obvious meta rows
                if ( empty($result['test']) && empty($result['slug']) ) {
                    $lbl = isset($result['label']) ? (string)$result['label'] : '';
                    if ( in_array(strtolower($lbl), array('data','summary','meta','results'), true) ) continue;
                }

                $slug = ( is_string($k) && $k !== '' && ! is_numeric($k) )
                    ? $k
                    : ( ! empty($result['test']) && is_string($result['test']) ? $result['test']
                        : ( ! empty($result['slug']) && is_string($result['slug']) ? $result['slug'] : 'test_' . $i++ ) );

                $tmp[$slug] = $normalize($slug, $result);
            }
            return $tmp;
        };

        $direct = $dispatch('direct');
        if ( is_array($direct) ) { $by_slug = $merge_results($by_slug, $collect($direct)); $used_rest = true; }

        if ( $include_async ) {
            $async = $dispatch('async');
            if ( is_array($async) ) { $by_slug = $merge_results($by_slug, $collect($async)); $used_rest = true; }
        }
    }

    // ----- Local callables (always try; they often include core + plugins) -----
    if ( ! class_exists('WP_Site_Health') ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }
    $health = new WP_Site_Health();
    $tests  = $health->get_tests();

    $run_callable = function($def) use ($health){
        if ( empty($def['test']) ) return null;
        $cb = $def['test'];
        if ( is_callable($cb) ) {
            try { return call_user_func($cb); } catch (Throwable $e) { return null; }
        }
        if ( is_string($cb) && method_exists($health, $cb) ) {
            try { return call_user_func(array($health, $cb)); } catch (Throwable $e) { return null; }
        }
        return null;
    };

    $tmp_local = array();
    foreach ( array('direct','async') as $type ) {
        if ( $type === 'async' && ! $include_async ) continue;
        if ( ! empty($tests[$type]) && is_array($tests[$type]) ) {
            $i = 0;
            foreach ( $tests[$type] as $k => $def ) {
                $res = $run_callable($def);
                if ( is_array($res) ) {
                    $slug = ( is_string($k) && $k !== '' && ! is_numeric($k) )
                        ? $k
                        : ( ! empty($def['test']) && is_string($def['test']) ? $def['test'] : ('local_' . $i++) );
                    $tmp_local[$slug] = $normalize($slug, $res);
                    $i++;
                }
            }
        }
    }
    if ( $tmp_local ) { $by_slug = $merge_results($by_slug, $tmp_local); $used_local = true; }

    // ----- Must-have core tests (final safety net) -----
    // WordPress versions vary in method names; try a list until we hit.
    $must_have_methods = array(
        // Display errors / debug
        'get_test_php_display_errors', 'get_test_is_in_debug_mode', 'get_test_debug_enabled',
        // PHP version
        'get_test_php_version', 'get_test_php_version_is_secure',
        // Object cache
        'get_test_persistent_object_cache', 'get_test_object_cache',
        // Inactive plugins
        'get_test_inactive_plugins',
    );

    $tmp_core = array();
    foreach ( $must_have_methods as $m ) {
        if ( method_exists($health, $m) && is_callable(array($health, $m)) ) {
            try {
                $res = call_user_func(array($health, $m));
                if ( is_array($res) ) {
                    $slug = preg_match('~^get_test_(.+)$~', $m, $mm) ? $mm[1] : $m;
                    $tmp_core[$slug] = $normalize($slug, $res);
                }
            } catch (Throwable $e) {}
        }
    }
    if ( $tmp_core ) { $by_slug = $merge_results($by_slug, $tmp_core); $used_reflect = true; }

    // ----- Finish -----
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
        if ( $ac === $bc ) return strcasecmp($a['label'], $b['label']);
        return $ac <=> $bc;
    });

    $counts = array('critical'=>0,'recommended'=>0,'good'=>0);
    foreach ($items as $it) {
        if ( isset($counts[$it['status']]) ) $counts[$it['status']]++;
    }

    $source = ($used_rest && $used_local) ? 'rest+local'
            : ($used_rest ? 'rest-internal'
            : ($used_local ? 'local'
            : ($used_reflect ? 'reflect' : 'none')));

    $payload = array(
        'items'      => $items,
        'counts'     => $counts,
        'refreshed'  => current_time('mysql'),
        'site'       => get_bloginfo('name'),
        'source'     => $source,
    );

    set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );
    return $payload;
}

/**
 * Map digest status to badge classes for the admin UI.
 */
function nfinite_health_status_class( $status ) {
    switch ( $status ) {
        case 'critical':    return 'nfinite-badge nfinite-badge-danger';
        case 'recommended': return 'nfinite-badge nfinite-badge-warn';
        default:            return 'nfinite-badge nfinite-badge-good';
    }
}
