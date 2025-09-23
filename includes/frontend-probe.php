<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Front-end asset probe (cache-busting & CDN-aware)
 *
 * Call any public URL with one of:
 *   ?nfa_probe=1&nfa_token={token}         (preferred; token transient)
 *   ?nfa_probe=1&nfa_nonce={admin nonce}   (fallback; requires admin cookie)
 *
 * Returns JSON: { success, data: { scripts[], styles[], debug{} } }
 */
add_action('template_redirect', function () {
    if ( empty($_GET['nfa_probe']) ) return;

    // --- hard cache bypass (before anything else) ---
    if ( ! defined('DONOTCACHEPAGE') )   define('DONOTCACHEPAGE',   true);
    if ( ! defined('DONOTCACHEOBJECT') ) define('DONOTCACHEOBJECT', true);
    if ( ! defined('DONOTCACHEDB') )     define('DONOTCACHEDB',     true);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-NFA-Probe', 'hit');

    // --- auth: token OR (nonce+cap+cookie) ---
    $ok = false;
    $token = isset($_GET['nfa_token']) ? sanitize_text_field($_GET['nfa_token']) : '';
    if ( $token && get_transient('nfa_probe_token_' . $token) ) {
        $ok = true;
    } else {
        $nonce = isset($_GET['nfa_nonce']) ? sanitize_text_field($_GET['nfa_nonce']) : '';
        if ( is_user_logged_in() && current_user_can('manage_options') && wp_verify_nonce($nonce, 'nfa_probe_assets') ) {
            $ok = true;
        }
    }
    if ( ! $ok ) {
        status_header(403);
        wp_send_json_error(array('message' => 'Forbidden'));
    }

    // Buffer entire page so we can parse HTML (for preload/modulepreload/etc.)
    if ( ! ob_get_level() ) ob_start();

    // ---- helpers ----
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

        // Quick path-based mapping (works behind CDNs)
        if ( preg_match('#/wp-content/plugins/([^/]+)/#i', $path, $m) ) {
            return array('type'=>'plugin','owner'=>sanitize_key($m[1]));
        }
        if ( preg_match('#/wp-content/mu-plugins/([^/]+)/#i', $path, $m) ) {
            return array('type'=>'mu-plugin','owner'=>sanitize_key($m[1]));
        }
        if ( preg_match('#/wp-content/themes/([^/]+)/#i', $path, $m) ) {
            $slug = sanitize_key($m[1]);
            $theme  = wp_get_theme();
            $parent = $theme && $theme->parent() ? $theme->parent()->get_template() : null;
            if ( $theme && $slug === $theme->get_stylesheet() ) return array('type'=>'theme','owner'=>$slug);
            if ( $parent && $slug === $parent )               return array('type'=>'theme-parent','owner'=>$slug);
            return array('type'=>'theme','owner'=>$slug);
        }

        // Uploads heuristics (builder/theme generated CSS/JS)
        if ( strpos($path, '/wp-content/uploads/') !== false ) {
            if ( preg_match('#/uploads/(fusion|avada)-#i', $path) )   return array('type'=>'theme','owner'=>'avada');
            if ( preg_match('#/uploads/elementor/#i', $path) )        return array('type'=>'plugin','owner'=>'elementor');
            if ( preg_match('#/uploads/et-cache/#i', $path) )         return array('type'=>'theme','owner'=>'divi');
            if ( preg_match('#/uploads/woocommerce(_blocks)?/#i', $path) ) return array('type'=>'plugin','owner'=>'woocommerce');
            // default uploads → core bucket
            return array('type'=>'core','owner'=>'core');
        }

        // Fallback: try to resolve to filesystem to refine owner
        $resolved = '';
        $home = trailingslashit( home_url() );
        if ( ! empty($u['host']) && 0 === strpos($src, $home) ) {
            $rel = ltrim( wp_make_link_relative($src), '/' );
            $guess = wp_normalize_path( ABSPATH . $rel );
            if ( file_exists($guess) ) $resolved = $guess;
        }
        if ( $resolved ) {
            $plugins = wp_normalize_path(WP_PLUGIN_DIR);
            $mu      = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path(WPMU_PLUGIN_DIR) : null;
            $tdir    = wp_normalize_path(get_stylesheet_directory());
            $pdir    = wp_normalize_path(get_template_directory());
            if ( 0 === strpos($resolved,$plugins) ) {
                $slug = explode('/', substr($resolved, strlen($plugins)+1))[0] ?? '';
                return array('type'=>'plugin','owner'=>sanitize_key($slug),'path'=>$resolved);
            }
            if ( $mu && 0 === strpos($resolved,$mu) ) {
                $slug = explode('/', substr($resolved, strlen($mu)+1))[0] ?? '';
                return array('type'=>'mu-plugin','owner'=>sanitize_key($slug),'path'=>$resolved);
            }
            if ( 0 === strpos($resolved,$tdir) )  return array('type'=>'theme','owner'=>wp_get_theme()->get_stylesheet(),'path'=>$resolved);
            if ( 0 === strpos($resolved,$pdir) )  return array('type'=>'theme-parent','owner'=>wp_get_theme()->get_template(),'path'=>$resolved);
            return array('type'=>'core','owner'=>'core','path'=>$resolved);
        }

        return array('type'=>'external','owner'=>'external');
    };

    $collect_registry = function() use ($normalize,$map_src) {
        global $wp_scripts, $wp_styles;
        $out = array('scripts'=>array(),'styles'=>array());

        if ( $wp_scripts instanceof WP_Scripts ) {
            $handles = $wp_scripts->done ?: $wp_scripts->queue;
            foreach ( (array)$handles as $h ) {
                if ( empty($wp_scripts->registered[$h]) ) continue;
                $o = $wp_scripts->registered[$h];
                $src = $normalize($o->src ?? '');
                $out['scripts'][] = array('handle'=>$h,'src'=>$src,'deps'=>(array)($o->deps??array()),'ver'=>(string)($o->ver??''),'owner'=>$map_src($src),'_kind'=>'script');
            }
        }
        if ( $wp_styles instanceof WP_Styles ) {
            $handles = $wp_styles->done ?: $wp_styles->queue;
            foreach ( (array)$handles as $h ) {
                if ( empty($wp_styles->registered[$h]) ) continue;
                $o = $wp_styles->registered[$h];
                $src = $normalize($o->src ?? '');
                $out['styles'][] = array('handle'=>$h,'src'=>$src,'deps'=>(array)($o->deps??array()),'ver'=>(string)($o->ver??''),'owner'=>$map_src($src),'_kind'=>'style');
            }
        }
        return $out;
    };

    $collect_html = function($html) use ($normalize,$map_src){
        $out = array('scripts'=>array(),'styles'=>array());
        if ( ! $html ) return $out;

        // <script src> + data-src
        if ( preg_match_all('#<script[^>]+(?:src|data-src)\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $m) ) {
            foreach ($m[1] as $src) {
                $src = $normalize($src);
                $out['scripts'][] = array('handle'=>'','src'=>$src,'deps'=>array(),'ver'=>'','owner'=>$map_src($src),'_kind'=>'script');
            }
        }
        // stylesheet + preload → stylesheet
        if ( preg_match_all('#<link[^>]+rel=["\'](?:stylesheet|preload|modulepreload)["\'][^>]+href\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $m) ) {
            foreach ($m[1] as $src) {
                $src = $normalize($src);
                // Guess type from "as=" if present
                if ( preg_match('#as=["\']script["\']#i', $html) || preg_match('#rel=["\']modulepreload["\']#i', $html) ) {
                    $out['scripts'][] = array('handle'=>'','src'=>$src,'deps'=>array(),'ver'=>'','owner'=>$map_src($src),'_kind'=>'script');
                } else {
                    $out['styles'][]  = array('handle'=>'','src'=>$src,'deps'=>array(),'ver'=>'','owner'=>$map_src($src),'_kind'=>'style');
                }
            }
        }
        return $out;
    };

    // Snapshots around head/footer
    $head = array('scripts'=>array(),'styles'=>array());
    $foot = array('scripts'=>array(),'styles'=>array());
    add_action('wp_print_scripts', function() use (&$head, $collect_registry){ $head = $collect_registry(); }, PHP_INT_MAX);
    add_action('wp_print_footer_scripts', function() use (&$foot, $collect_registry){ $foot = $collect_registry(); }, PHP_INT_MAX);

    // Emit JSON at shutdown
    add_action('shutdown', function() use (&$head,&$foot,$collect_html) {
        $html = '';
        if ( ob_get_level() ) {
            $html = ob_get_contents();
            while ( ob_get_level() ) ob_end_clean();
        }

        $from_html = $collect_html($html);

        $merge = function($a,$b){
            $seen = array(); $out = array();
            foreach ( array_merge($a,$b) as $row ) {
                $k = $row['src'] ?: $row['handle'];
                if ( isset($seen[$k]) ) continue;
                $seen[$k] = true;
                $out[] = $row;
            }
            return $out;
        };

        $scripts = $merge( $merge($head['scripts'] ?? array(), $foot['scripts'] ?? array()), $from_html['scripts'] ?? array() );
        $styles  = $merge( $merge($head['styles']  ?? array(), $foot['styles']  ?? array()), $from_html['styles']  ?? array() );

        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo wp_json_encode(array(
            'success' => true,
            'data' => array(
                'scripts' => $scripts,
                'styles'  => $styles,
                'url'     => home_url(add_query_arg(array())),
                'ts'      => time(),
                'debug'   => array(
                    'html_len' => strlen($html),
                    'head_js'  => count($head['scripts'] ?? array()),
                    'head_css' => count($head['styles'] ?? array()),
                    'foot_js'  => count($foot['scripts'] ?? array()),
                    'foot_css' => count($foot['styles'] ?? array()),
                    'html_js'  => count($from_html['scripts'] ?? array()),
                    'html_css' => count($from_html['styles'] ?? array()),
                ),
            ),
        ));
        exit;
    }, PHP_INT_MAX);
});
