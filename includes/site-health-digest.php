<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Nfinite: Comprehensive Site Health Digest
 *
 * - Uses internal REST if Site Health routes are already registered.
 * - Falls back to calling public callables from WP_Site_Health::get_tests().
 * - As a last resort, reflectively calls public get_test_* methods.
 * - De-duplicates by slug and prefers most severe status (critical > recommended > good).
 * - Coerces values to safe strings before wp_kses_post() to avoid fatals.
 * - Cached via transient; pass $force_refresh=true to bypass.
 *
 * @param bool $force_refresh  Bypass cache and rebuild now.
 * @param bool $include_async  Include async tests in collection.
 * @return array {
 *   @type array  items      Normalized items.
 *   @type array  counts     ['critical'=>int,'recommended'=>int,'good'=>int]
 *   @type string refreshed  MySQL datetime of generation.
 *   @type string site       Site name.
 *   @type string source     'rest-internal' | 'local' | 'reflect'
 *   @type string error      Optional error message when empty.
 * }
 */
function nfinite_get_site_health_digest( $force_refresh = false, $include_async = true ) {
    // Capability fallback for older WP versions.
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

    // Map arbitrary statuses into canonical buckets.
    $map_status = function($s){
        $s = strtolower( (string) $s );
        if ( in_array($s, array('critical','fail','error'), true) ) return 'critical';
        if ( in_array($s, array('recommended','warning','info'), true) ) return 'recommended';
        if ( in_array($s, array('good','pass'), true) ) return 'good';
        return 'recommended';
    };

    // Ranking for tie-breaking when merging duplicates.
    $status_rank = function($s){
        $s = strtolower( (string) $s );
        if ( $s === 'critical' )    return 0;
        if ( $s === 'recommended' ) return 1;
        return 2; // good/other
    };

    // Safe coercion to string for arbitrary values.
    $to_text = function($v) {
        if ( is_string($v) )   return $v;
        if ( is_numeric($v) )  return (string) $v;

        if ( is_array($v) ) {
            // Prefer common keys first.
            foreach ( array('raw','rendered','message','text','value','content') as $k ) {
                if ( isset($v[$k]) && is_string($v[$k]) ) return $v[$k];
            }
            // Flatten scalars from nested arrays.
            $parts = array();
            $walker = function($x) use (&$parts, &$walker) {
                if ( is_array($x) ) {
                    foreach ($x as $xi) $walker($xi);
                } elseif ( is_scalar($x) ) {
                    $parts[] = (string) $x;
                }
            };
            $walker($v);
            return $parts ? implode(' ', $parts) : '';
        }

        if ( is_object($v) ) {
            if ( method_exists($v, '__toString') ) return (string) $v;
            foreach ( array('rendered','raw','message','text','value','content') as $prop ) {
                if ( isset($v->$prop) && is_string($v->$prop) ) return $v->$prop;
            }
            $json = function_exists('wp_json_encode') ? wp_json_encode($v) : @json_encode($v);
            return is_string($json) ? $json : '';
        }

        return '';
    };

    // Safe HTML after coercion.
    $to_html = function($v) use ($to_text) {
        $s = $to_text($v);
        return ($s === '') ? '' : wp_kses_post($s);
    };

    // Normalize a raw test result into our schema.
    $normalize = function($slug, $result) use ($to_text, $to_html, $map_status) {
        $label_raw = isset($result['label']) ? $result['label'] : ucfirst( str_replace('_', ' ', (string) $slug) );

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

    // Merge two maps of slug => item preferring the most severe.
    $merge_results = function(array $base, array $incoming) use ($status_rank) {
        foreach ($incoming as $slug => $item) {
            if ( ! isset($base[$slug]) ) { $base[$slug] = $item; continue; }
            $a = $base[$slug]; $b = $item;
            $ra = $status_rank($a['status']); $rb = $status_rank($b['status']);
            if ( $rb < $ra ) { $base[$slug] = $b; continue; }
            if ( $rb === $ra ) {
                // Prefer the one that has details/actions.
                $a_has = ( ! empty($a['description']) || ! empty($a['actions']) );
                $b_has = ( ! empty($b['description']) || ! empty($b['actions']) );
                if ( $b_has && ! $a_has ) $base[$slug] = $b;
            }
        }
        return $base;
    };

    // ---------------- collect via internal REST (when routes exist) ----------------
    $by_slug   = array();
    $used_rest = false;

    // (A) Try to detect if Site Health routes are actually registered.
    $routes_have_tests = false;
    if ( function_exists('rest_get_server') ) {
        $server = rest_get_server(); // boots server if not already
        if ( $server && method_exists($server, 'get_routes') ) {
            $routes = $server->get_routes();
            // Core route looks like '/wp-site-health/v1/tests/(?P<type>...)'
            foreach ( $routes as $route => $handlers ) {
                if ( strpos($route, '/wp-site-health/v1/tests') === 0 ) {
                    $routes_have_tests = true;
                    break;
                }
            }
        }
    }

    // (B) If available, dispatch internal REST without firing rest_api_init ourselves.
    if ( $routes_have_tests && function_exists('rest_do_request') && class_exists('WP_REST_Request') ) {
        $dispatch = function($type){
            try {
                $req = new WP_REST_Request('GET', '/wp-site-health/v1/tests/' . $type);
                $res = rest_do_request($req);
                if ( is_wp_error($res) ) return null;
                if ( $res instanceof WP_REST_Response ) {
                    $data = $res->get_data();
                } else {
                    $server = rest_get_server();
                    $data   = $server ? $server->response_to_data($res, false) : null;
                }
                return is_array($data) ? $data : null;
            } catch (Throwable $e) {
                return null;
            }
        };

        $collect = function($payload) use ($normalize) {
            $tmp = array();
            if ( ! is_array($payload) ) return $tmp;

            $i = 0;
            foreach ( $payload as $k => $result ) {
                // Accept both keyed and numeric arrays; derive slug if needed.
                $slug = null;
                if ( is_string($k) && $k !== '' && ! is_numeric($k) ) {
                    $slug = $k;
                } elseif ( is_array($result) ) {
                    if ( ! empty($result['test']) && is_string($result['test']) ) {
                        $slug = $result['test'];
                    } elseif ( ! empty($result['slug']) && is_string($result['slug']) ) {
                        $slug = $result['slug'];
                    }
                }
                if ( ! $slug ) $slug = 'test_' . $i++;

                if ( is_array($result) ) {
                    $tmp[$slug] = $normalize($slug, $result);
                }
            }
            return $tmp;
        };

        $direct = $dispatch('direct');
        if ( is_array($direct) ) {
            $by_slug   = $merge_results($by_slug, $collect($direct));
            $used_rest = true;
        }

        if ( $include_async ) {
            $async = $dispatch('async');
            if ( is_array($async) ) {
                $by_slug   = $merge_results($by_slug, $collect($async));
                $used_rest = true;
            }
        }
    }

    // ---------------- fallback: callables from WP_Site_Health ----------------
    $used_local = false;
    if ( empty($by_slug) ) {
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

        $tmp = array();
        foreach ( array('direct','async') as $type ) {
            if ( $type === 'async' && ! $include_async ) continue;
            if ( isset($tests[$type]) && is_array($tests[$type]) ) {
                $i = 0;
                foreach ($tests[$type] as $k => $def) {
                    try {
                        $res  = $run($def);
                    } catch (Throwable $e) {
                        $res = null;
                    }
                    if ( is_array($res) ) {
                        $slug = (is_string($k) && $k !== '' && ! is_numeric($k))
                            ? $k
                            : ( (!empty($def['test']) && is_string($def['test'])) ? $def['test'] : ('local_' . $i++) );
                        $tmp[$slug] = $normalize($slug, $res);
                    }
                }
            }
        }

        if ( $tmp ) {
            $by_slug   = $merge_results($by_slug, $tmp);
            $used_local = true;
        }
    }

    // ---------------- last resort: reflectively call public get_test_* ----------------
    $used_reflect = false;
    if ( empty($by_slug) ) {
        if ( ! class_exists('WP_Site_Health') ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
        $health  = new WP_Site_Health();
        $methods = get_class_methods($health);
        $tmp     = array();
        $i       = 0;

        foreach ( (array) $methods as $m ) {
            if ( strpos($m, 'get_test_') !== 0 ) continue;
            // Limit to a reasonable number, just in case.
            if ( $i > 200 ) break;

            try {
                $res = call_user_func( array($health, $m) );
            } catch (Throwable $e) {
                $res = null;
            }

            if ( is_array($res) ) {
                $slug       = 'reflect_' . substr($m, 9); // after "get_test_"
                $tmp[$slug] = $normalize($slug, $res);
            }
            $i++;
        }

        if ( $tmp ) {
            $by_slug     = $merge_results($by_slug, $tmp);
            $used_reflect = true;
        }
    }

    // ---------------- finish ----------------
    $items = array_values($by_slug);

    if ( empty($items) ) {
        return array(
            'items'      => array(),
            'counts'     => array('critical'=>0,'recommended'=>0,'good'=>0),
            'refreshed'  => current_time('mysql'),
            'site'       => get_bloginfo('name'),
            'source'     => $used_rest ? 'rest-internal' : ( $used_local ? 'local' : 'reflect' ),
            'error'      => __('No Site Health results were returned. If this persists, check that Site Health routes are registered and loopback requests aren’t blocked.', 'nfinite-audit'),
        );
    }

    // Sort by severity (critical -> recommended -> good), then label.
    usort($items, function($a, $b){
        $order = array('critical'=>0,'recommended'=>1,'good'=>2);
        $ac = $order[$a['status']] ?? 9;
        $bc = $order[$b['status']] ?? 9;
        if ( $ac === $bc ) return strcasecmp($a['label'], $b['label']);
        return $ac <=> $bc;
    });

    // Counts
    $counts = array('critical'=>0,'recommended'=>0,'good'=>0);
    foreach ( $items as $it ) {
        if ( isset($counts[$it['status']]) ) $counts[$it['status']]++;
    }

    $payload = array(
        'items'      => $items,
        'counts'     => $counts,
        'refreshed'  => current_time('mysql'),
        'site'       => get_bloginfo('name'),
        'source'     => $used_rest ? 'rest-internal' : ( $used_local ? 'local' : 'reflect' ),
    );

    // Cache for 5 minutes
    set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

    return $payload;
}

/**
 * Map digest status to badge classes for the admin UI.
 *
 * @param string $status critical|recommended|good
 * @return string CSS classes
 */
function nfinite_health_status_class( $status ) {
    switch ( $status ) {
        case 'critical':    return 'nfinite-badge nfinite-badge-danger';
        case 'recommended': return 'nfinite-badge nfinite-badge-warn';
        default:            return 'nfinite-badge nfinite-badge-good';
    }
}
