<?php
if ( ! defined('ABSPATH') ) exit;

class Nfinite_Review_Scanner {

    public static function run( $probe_url, $nonce = '', $force_refresh = false ) {
        global $wpdb;

        $cache_key = 'nfinite_review_last';
        if ( ! $force_refresh ) {
            $cached = get_transient($cache_key);
            if ( $cached ) return $cached;
        }

        // Active plugins map (slug => name/version)
        $plugins_all  = function_exists('get_plugins') ? get_plugins() : array();
        $active_paths = (array) get_option('active_plugins', array());
        $active       = array();
        foreach ($active_paths as $p) {
            $slug = explode('/', $p)[0];
            $active[$slug] = array(
                'path'    => $p,
                'name'    => isset($plugins_all[$p]['Name']) ? $plugins_all[$p]['Name'] : $slug,
                'version' => isset($plugins_all[$p]['Version']) ? $plugins_all[$p]['Version'] : '',
            );
        }

        $theme      = wp_get_theme();
        $theme_slug = $theme->get_stylesheet();
        $parent     = $theme->parent() ? $theme->parent()->get_template() : null;

        // 1) PROBE JSON (preferred)
        $assets = self::probe_assets($probe_url);

        // 1b) FALLBACK: plain HTML scrape (handles minified/combined & inline)
        if (
            empty($assets['scripts']) &&
            empty($assets['styles'])
        ) {
            $assets = self::scrape_html_assets($probe_url);
        }

        // 2) Asset sizes (HEAD → GET fallback)
        $urls = array_merge(
            wp_list_pluck($assets['scripts'],'src'),
            wp_list_pluck($assets['styles'],'src')
        );
        $sizes = self::sizes_with_fallback($urls);

        // 3) Attribute to owner (counts + bytes; includes inline bytes)
        $by_owner = array(); // owner => ['type','scripts','styles','bytes','handles'=>[]]
        $collect = function($row) use (&$by_owner, $sizes) {
            $owner = $row['owner']['owner'] ?? 'external';
            $type  = $row['owner']['type']  ?? 'external';
            $src   = $row['src'] ?? '';
            $key   = $owner;

            if ( ! isset($by_owner[$key]) ) {
                $by_owner[$key] = array(
                    'type'    => $type,
                    'scripts' => 0,
                    'styles'  => 0,
                    'bytes'   => 0,
                    'handles' => array(),
                );
            }
            if ( ! empty($row['handle']) ) $by_owner[$key]['handles'][] = $row['handle'];

            // External URL bytes via HEAD/GET size map
            if ( $src && isset($sizes[$src]) && is_numeric($sizes[$src]) ) {
                $by_owner[$key]['bytes'] += (int) $sizes[$src];
            }
            // Inline bytes (no src)
            if ( empty($src) && isset($row['inline_bytes']) && is_numeric($row['inline_bytes']) ) {
                $by_owner[$key]['bytes'] += (int) $row['inline_bytes'];
            }

            if ( 'script' === ($row['_kind'] ?? '') ) $by_owner[$key]['scripts']++;
            if ( 'style'  === ($row['_kind'] ?? '') ) $by_owner[$key]['styles']++;
        };

        foreach ($assets['scripts'] as $r) { $r['_kind'] = 'script'; $collect($r); }
        foreach ($assets['styles']  as $r) { $r['_kind'] = 'style';  $collect($r); }

        // 4) AUTOLOAD options footprint
        $autoload = array();
        $rows = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload='yes' LIMIT 20000", ARRAY_A );
        foreach ($rows as $row) {
            $name  = $row['option_name'];
            $bytes = (int)$row['bytes'];
            $owner = self::guess_owner_from_option($name);
            if ( ! isset($autoload[$owner]) ) $autoload[$owner] = 0;
            $autoload[$owner] += $bytes;
        }

        // 5) Known meta signals
        $meta_signals = array(
            '_elementor_data'   => 'elementor',
            '_yoast_wpseo_meta' => 'wordpress-seo',
            '_fusion'           => 'avada',          // Avada/Fusion builder
            '_wpforms_settings' => 'wpforms-lite',
        );
        $meta_counts = array();
        foreach ($meta_signals as $key => $owner) {
            $c = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s", $key) );
            if ( $c > 0 ) {
                if ( ! isset($meta_counts[$owner]) ) $meta_counts[$owner] = 0;
                $meta_counts[$owner] += $c;
            }
        }

        // 6) Code size (dir bytes)
        $dir_size = array();
        foreach ($active as $slug => $info) {
            $root = WP_PLUGIN_DIR . '/' . $slug;
            $dir_size[$slug] = self::dir_bytes($root, 2500);
        }
        $dir_size[$theme_slug] = self::dir_bytes( get_stylesheet_directory(), 4000 );
        if ( $parent ) $dir_size[$parent] = self::dir_bytes( get_template_directory(), 4000 );

        // 7) Score + assemble rows
        $rows_map = array();
        $register_row = function($key, $type) use (&$rows_map, $by_owner, $autoload, $meta_counts, $dir_size, $active, $theme_slug, $parent) {
            $assets = $by_owner[$key] ?? array('scripts'=>0,'styles'=>0,'bytes'=>0);
            $al     = $autoload[$key] ?? 0;
            $mc     = $meta_counts[$key] ?? 0;
            $code   = $dir_size[$key] ?? 0;

            // Weighted score (0=best, 100=worst-ish)
            $w_assets = min( 100, ( $assets['bytes'] / (400 * 1024) ) * 60 ); // 400KB → ~60pts
            $w_auto   = min( 100, ( $al / (600 * 1024) ) * 25 );             // 600KB → ~25pts
            $w_meta   = min( 15,   $mc * 0.01 );                             // 1500 rows → 15pts
            $w_code   = min( 10,   ( $code / (6 * 1024 * 1024) ) * 10 );     // 6MB → 10pts

            $score = (int) round( $w_assets + $w_auto + $w_meta + $w_code );
            $grade = self::grade_from_score( 100 - min(100, $score) );

            $name  = $key;
            $ver   = '';
            if ( $type === 'plugin' && isset($active[$key]) ) {
                $name = $active[$key]['name'];
                $ver  = $active[$key]['version'];
            } elseif ( $type === 'theme' && $key === $theme_slug ) {
                $t = wp_get_theme();
                $name = $t ? $t->get('Name') : $key;
                $ver  = $t ? $t->get('Version') : '';
            } elseif ( $type === 'theme-parent' && $parent && $key === $parent ) {
                $t = wp_get_theme( $parent );
                $name = $t ? $t->get('Name') : $key;
                $ver  = $t ? $t->get('Version') : '';
            }

            $rows_map[$type.'::'.$key] = array(
                'key'            => $key,
                'type'           => $type,
                'name'           => $name,
                'version'        => $ver,
                'assets'         => $assets,
                'autoload_bytes' => $al,
                'meta_rows'      => $mc,
                'code_bytes'     => $code,
                'score'          => $score,
                'grade'          => $grade,
            );
        };

        // Rows for everything that owned assets
        foreach ($by_owner as $key => $bucket) {
            $register_row($key, $bucket['type']);
        }
        // Make sure active plugins + theme show up even without assets
        foreach ($active as $slug => $_) {
            if ( ! isset($rows_map['plugin::'.$slug]) ) $register_row($slug, 'plugin');
        }
        if ( ! isset($rows_map['theme::'.$theme_slug]) ) $register_row($theme_slug, 'theme');
        if ( $parent && ! isset($rows_map['theme-parent::'.$parent]) ) $register_row($parent, 'theme-parent');

        // Sort offenders
        $rows = array_values($rows_map);
        usort($rows, function($a,$b){
            if ($a['score'] === $b['score']) return strcasecmp($a['name'],$b['name']);
            return ($a['score'] < $b['score']) ? 1 : -1;
        });

        $payload = array(
            'refreshed' => current_time('mysql'),
            'source'    => 'probe+html+db',
            'items'     => $rows,
            'top'       => array_slice($rows, 0, 10),
        );

        set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
        return $payload;
    }

    /** Primary: call the JSON probe endpoint (bypasses caches, see frontend-probe.php) */
    protected static function probe_assets($probe_url) {
        // token → avoids requiring cookies, but we’ll still send cookies to skip caches
        $token = wp_generate_password(20, false, false);
        set_transient('nfa_probe_token_' . $token, 1, 2 * MINUTE_IN_SECONDS);

        $url = add_query_arg(array(
            'nfa_probe' => 1,
            'nfa_token' => $token,
            'nfa_bust'  => (string) microtime(true),
        ), $probe_url);

        $cookies = array();
        foreach ( (array) $_COOKIE as $k => $v ) {
            $cookies[] = new WP_Http_Cookie(array('name'=>$k,'value'=>$v));
        }

        $res = wp_remote_get($url, array(
            'timeout'     => 25,
            'redirection' => 3,
            'sslverify'   => apply_filters('https_local_ssl_verify', false),
            'headers'     => array(
                'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
                'User-Agent'    => 'NfiniteProbe/1.0 (+WP loopback)',
            ),
            'cookies'     => $cookies,
        ));

        delete_transient('nfa_probe_token_' . $token);

        if ( is_wp_error($res) ) return array('scripts'=>array(),'styles'=>array());

        $json = json_decode( wp_remote_retrieve_body($res), true );
        if ( ! is_array($json) || empty($json['success']) ) {
            return array('scripts'=>array(),'styles'=>array());
        }

        $data = $json['data'];
        return array(
            'scripts' => is_array($data['scripts'] ?? null) ? $data['scripts'] : array(),
            'styles'  => is_array($data['styles']  ?? null) ? $data['styles']  : array(),
        );
    }

    /** Fallback: fetch the REAL page HTML and parse external + inline assets */
    protected static function scrape_html_assets($page_url) {
        // Add a cache-buster query param; send logged-in cookies
        $url = add_query_arg('nfa_bust', (string) microtime(true), $page_url);

        $cookies = array();
        foreach ( (array) $_COOKIE as $k => $v ) {
            $cookies[] = new WP_Http_Cookie(array('name'=>$k,'value'=>$v));
        }

        $r = wp_remote_get($url, array(
            'timeout'     => 25,
            'redirection' => 3,
            'sslverify'   => apply_filters('https_local_ssl_verify', false),
            'headers'     => array('User-Agent' => 'NfiniteScrape/1.0 (+WP loopback)'),
            'cookies'     => $cookies,
        ));
        if ( is_wp_error($r) ) {
            return array('scripts'=>array(),'styles'=>array());
        }

        $html = (string) wp_remote_retrieve_body($r);
        if ( $html === '' ) {
            return array('scripts'=>array(),'styles'=>array());
        }

        $normalize = function($src){
            if ( ! $src ) return '';
            $src = trim($src);
            if ( strpos($src, '//') === 0 ) return (is_ssl()?'https:':'http:') . $src;
            if ( strpos($src, '/')  === 0 ) return site_url($src);
            return $src;
        };
        $map_src = function($src) use ($normalize) {
            $src = $normalize($src);
            if ( ! $src ) return array('type'=>'external','owner'=>'external');

            $u = wp_parse_url($src);
            $path = isset($u['path']) ? wp_normalize_path($u['path']) : '';

            // Under plugin/theme
            if ( preg_match('#/wp-content/plugins/([^/]+)/#i', $path, $m) )
                return array('type'=>'plugin','owner'=>sanitize_key($m[1]));
            if ( preg_match('#/wp-content/mu-plugins/([^/]+)/#i', $path, $m) )
                return array('type'=>'mu-plugin','owner'=>sanitize_key($m[1]));
            if ( preg_match('#/wp-content/themes/([^/]+)/#i', $path, $m) ) {
                $slug = sanitize_key($m[1]);
                $theme  = wp_get_theme();
                $parent = $theme && $theme->parent() ? $theme->parent()->get_template() : null;
                if ( $theme && $slug === $theme->get_stylesheet() ) return array('type'=>'theme','owner'=>$slug);
                if ( $parent && $slug === $parent )               return array('type'=>'theme-parent','owner'=>$slug);
                return array('type'=>'theme','owner'=>$slug);
            }

            // Uploads heuristics
            if ( strpos($path, '/wp-content/uploads/') !== false ) {
                if ( preg_match('#/(fusion|avada)-#i', $path) )    return array('type'=>'theme','owner'=>'avada');
                if ( preg_match('#/elementor/#i', $path) )         return array('type'=>'plugin','owner'=>'elementor');
                if ( preg_match('#/woocommerce#i', $path) )        return array('type'=>'plugin','owner'=>'woocommerce');
                return array('type'=>'core','owner'=>'core');
            }

            return array('type'=>'external','owner'=>'external');
        };

        $scripts = array();
        $styles  = array();

        // External <script> (src/data-src)
        if ( preg_match_all('#<script[^>]+(?:src|data-src)\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $m) ) {
            foreach ($m[1] as $src) {
                $src = $normalize($src);
                $scripts[] = array('handle'=>'','src'=>$src,'deps'=>array(),'ver'=>'','owner'=>$map_src($src),'_kind'=>'script');
            }
        }

        // <link rel=stylesheet|preload|modulepreload>
        if ( preg_match_all('#<link[^>]+rel=["\'](?:stylesheet|preload|modulepreload)["\'][^>]+href\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $m) ) {
            foreach ($m[1] as $src) {
                $src = $normalize($src);
                // If it's modulepreload/as=script treat as script
                if ( preg_match('#rel=["\']modulepreload["\']#i', $html) || preg_match('#as=["\']script["\']#i', $html) ) {
                    $scripts[] = array('handle'=>'','src'=>$src,'deps'=>array(),'ver'=>'','owner'=>$map_src($src),'_kind'=>'script');
                } else {
                    $styles[]  = array('handle'=>'','src'=>$src,'deps'=>array(),'ver'=>'','owner'=>$map_src($src),'_kind'=>'style');
                }
            }
        }

        // INLINE <style> (attribute to owner by id/class hints; count bytes)
        if ( preg_match_all('#<style[^>]*?(?:id=["\']([^"\']+)["\'])?[^>]*>(.*?)</style>#is', $html, $m, PREG_SET_ORDER) ) {
            foreach ($m as $match) {
                $id  = strtolower(trim($match[1] ?? ''));
                $css = (string) $match[2];
                $len = strlen($css);
                if ( $len < 64 ) continue; // ignore tiny bits

                $owner = array('type'=>'core','owner'=>'core');
                if ( $id ) {
                    if ( strpos($id,'avada') !== false || strpos($id,'fusion') !== false )     $owner = array('type'=>'theme','owner'=>'avada');
                    elseif ( strpos($id,'elementor') !== false )                                 $owner = array('type'=>'plugin','owner'=>'elementor');
                    elseif ( strpos($id,'woocommerce') !== false )                               $owner = array('type'=>'plugin','owner'=>'woocommerce');
                }
                $styles[] = array('handle'=>$id,'src'=>'','deps'=>array(),'ver'=>'','owner'=>$owner,'_kind'=>'style','inline_bytes'=>$len);
            }
        }

        // INLINE <script> (attribute by id; count bytes)
        if ( preg_match_all('#<script([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER) ) {
            foreach ($m as $match) {
                $attrs = strtolower($match[1] ?? '');
                if ( strpos($attrs,'src=') !== false ) continue; // external handled above
                $js  = (string) $match[2];
                $len = strlen($js);
                if ( $len < 64 ) continue; // ignore tiny shims

                $owner = array('type'=>'core','owner'=>'core');
                if ( preg_match('#id=["\']([^"\']+)["\']#i', $attrs, $mm) ) {
                    $id = $mm[1];
                    if ( strpos($id,'avada') !== false || strpos($id,'fusion') !== false )     $owner = array('type'=>'theme','owner'=>'avada');
                    elseif ( strpos($id,'elementor') !== false )                                 $owner = array('type'=>'plugin','owner'=>'elementor');
                    elseif ( strpos($id,'woocommerce') !== false )                               $owner = array('type'=>'plugin','owner'=>'woocommerce');
                }
                $scripts[] = array('handle'=>'','src'=>'','deps'=>array(),'ver'=>'','owner'=>$owner,'_kind'=>'script','inline_bytes'=>$len);
            }
        }

        return array('scripts'=>$scripts,'styles'=>$styles);
    }

    /** HEAD first; if length missing/0, GET the asset (cap ~2MB) and use strlen(body) */
    protected static function sizes_with_fallback($urls) {
        $out = array();
        $seen = array();
        foreach (array_filter(array_unique((array)$urls)) as $u) {
            if ( isset($seen[$u]) ) { $out[$u] = $out[$u] ?? 0; continue; }
            $seen[$u] = true;
            if ( ! $u || strpos($u,'http') !== 0 ) { $out[$u] = 0; continue; }

            // 1) HEAD
            $r = wp_remote_head($u, array('timeout'=>10));
            $len = 0;
            if ( ! is_wp_error($r) ) {
                $len = (int) wp_remote_retrieve_header($r, 'content-length');
            }
            // 2) GET fallback if missing/0
            if ( $len <= 0 ) {
                $rg = wp_remote_get($u, array(
                    'timeout'   => 12,
                    'headers'   => array('Range' => 'bytes=0-2097152'), // up to 2MB
                    'sslverify' => apply_filters('https_local_ssl_verify', false),
                ));
                if ( ! is_wp_error($rg) ) {
                    $body = wp_remote_retrieve_body($rg);
                    $len  = strlen($body);
                }
            }

            $out[$u] = $len > 0 ? $len : 0;
        }
        return $out;
    }

    protected static function guess_owner_from_option($name) {
        $name = (string)$name;
        $map = array(
            'elementor_'     => 'elementor',
            'wpseo_'         => 'wordpress-seo',
            'woocommerce_'   => 'woocommerce',
            'w3tc_'          => 'w3-total-cache',
            'wpforms_'       => 'wpforms-lite',
            'gravityforms_'  => 'gravityforms',
            'wp_mail_smtp_'  => 'wp-mail-smtp',
            'rank_math_'     => 'seo-by-rank-math',
            'jetpack_'       => 'jetpack',
            'fusion_'        => 'avada',
        );
        foreach ($map as $prefix => $owner) {
            if ( strpos($name, $prefix) === 0 ) return $owner;
        }
        return 'misc';
    }

    protected static function dir_bytes($root, $cap_files = 3000) {
        $root = wp_normalize_path($root);
        if ( ! is_dir($root) ) return 0;
        $total = 0; $count = 0;
        try {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $f) {
                if ( $count++ > $cap_files ) break;
                $total += (int) $f->getSize();
            }
        } catch (Throwable $e) {}
        return $total;
    }

    protected static function grade_from_score($score) {
        $score = max(0, min(100, (int)$score));
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }
}
