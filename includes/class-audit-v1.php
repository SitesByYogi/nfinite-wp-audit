<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure the SEO Basics scanner is available
if ( ! class_exists( 'Nfinite_SEO_Basics' ) ) {
    require_once NFINITE_AUDIT_PATH . 'includes/class-seo-basics.php';
}

class Nfinite_Audit_V1 {

    /**
     * Fetch HTML with headers for a URL.
     */
    private static function fetch_html($url) {
        $args = array(
            'timeout'     => 15,
            'redirection' => 5,
            'headers'     => array(
                'User-Agent'       => 'NfiniteAudit/' . (defined('NFINITE_AUDIT_VER') ? NFINITE_AUDIT_VER : 'dev') . ' (+' . home_url('/') . ')',
                'Accept'           => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding'  => 'gzip, deflate, br',
            ),
        );
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) {
            return array('ok'=>false, 'error'=>$res->get_error_message(), 'html'=>'', 'headers'=>array(), 'code'=>0);
        }
        $code    = (int) wp_remote_retrieve_response_code($res);
        $html    = (string) wp_remote_retrieve_body($res);
        $headers = wp_remote_retrieve_headers($res);
        return array('ok'=>($code>=200 && $code<400), 'error'=>'', 'html'=>$html, 'headers'=>$headers, 'code'=>$code);
    }

    private static function header_get($headers, $key) {
        if (empty($headers)) return null;
        if (is_array($headers)) {
            foreach ($headers as $k=>$v) { if (strtolower($k) === strtolower($key)) return $v; }
            return null;
        }
        if (is_object($headers) && method_exists($headers, 'offsetGet')) {
            return $headers->offsetGet($key);
        }
        return null;
    }

    public static function grade_from_score($s){
        $s = (int)$s;
        if ($s >= 90) return 'A';
        if ($s >= 80) return 'B';
        if ($s >= 70) return 'C';
        if ($s >= 60) return 'D';
        return 'F';
    }

    /**
     * Standalone SEO Basics runner (ONLY used on the SEO Basics page).
     * Returns compact array: score/grade + full details from Nfinite_SEO_Basics.
     */
    public static function run_seo_basics($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $got = self::fetch_html($tgt);

        if (empty($got['ok'])) {
            $details = array(
                'score'    => 0,
                'grade'    => 'F',
                'checks'   => array(
                    'title'            => array('exists'=>false,'text'=>'','length'=>0,'score'=>0,'issues'=>array()),
                    'meta_description' => array('exists'=>false,'text'=>'','length'=>0,'score'=>0,'issues'=>array()),
                    'h1'               => array('count'=>0,'texts'=>array(),'score'=>0,'issues'=>array()),
                ),
                'messages' => array( 'Could not retrieve HTML for SEO checks.' ),
            );
            return array(
                'url'     => $tgt,
                'score'   => 0,
                'grade'   => 'F',
                'details' => $details,
            );
        }

        $html    = (string) $got['html'];
        $details = Nfinite_SEO_Basics::analyze($html, $tgt);

        return array(
            'url'     => $tgt,
            'score'   => isset($details['score']) ? (int)$details['score'] : 0,
            'grade'   => isset($details['grade']) ? $details['grade'] : self::grade_from_score( (int) ($details['score'] ?? 0) ),
            'details' => $details,
        );
    }

    /**
     * Internal (non-SEO) audit used by the Dashboard.
     * NOTE: No SEO Basics logic lives here anymore.
     */
    public static function run_internal_audit($test_url = null) : array {
        $url = $test_url ? $test_url : home_url('/');

        $checks   = array();
        $sections = array();

        $checks['cache_present'] = self::check_cache_present($url);
        $checks['compression']   = self::check_compression($url);
        $checks['client_cache']  = self::check_client_cache($url);
        $sections['caching']['score'] = self::avg_scores(array($checks['cache_present'],$checks['compression'],$checks['client_cache']));

        $checks['assets_counts']   = self::check_assets_counts($url);
        $checks['render_blocking'] = self::check_render_blocking($url);
        $sections['assets']['score'] = self::avg_scores(array($checks['assets_counts'],$checks['render_blocking']));

        $checks['images_dims_and_size'] = self::check_images_dims_and_size($url);
        $sections['images']['score'] = (int)$checks['images_dims_and_size']['score'];

        $checks['ttfb']  = self::check_ttfb($url);
        $checks['h2_h3'] = self::check_h2_h3($url);
        $sections['server']['score'] = self::avg_scores(array($checks['ttfb'],$checks['h2_h3']));

        $checks['autoload_size']  = self::check_autoload_size();
        $checks['postmeta_bloat'] = self::check_postmeta_bloat();
        $checks['transients']     = self::check_transients();
        $sections['database']['score'] = self::avg_scores(array($checks['autoload_size'],$checks['postmeta_bloat'],$checks['transients']));

        $checks['updates_core']    = self::check_updates_core();
        $checks['updates_plugins'] = self::check_updates_plugins();
        $checks['updates_themes']  = self::check_updates_themes();
        $sections['core']['score'] = self::avg_scores(array($checks['updates_core'],$checks['updates_plugins'],$checks['updates_themes']));

        $section_vals = array();
        foreach ($sections as $s) { $section_vals[] = (int)$s['score']; }
        $internal_overall = (int) round(array_sum($section_vals) / max(1,count($section_vals)));

        return array(
            'sections' => $sections,
            'checks'   => $checks,
            'overall'  => $internal_overall,
        );
    }

    private static function avg_scores($arr){
        $vals = array();
        foreach ($arr as $a) { if (isset($a['score'])) $vals[] = (int)$a['score']; }
        if (!$vals) return 0;
        return (int) round(array_sum($vals)/count($vals));
    }

    // ------------------------------
    // Individual checks (non-SEO)
    // ------------------------------

    public static function check_images_dims_and_size($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $got = self::fetch_html($tgt);
        if (empty($got['ok'])) {
            return array('score'=>50,'meta'=>array('total'=>0,'missing_dims'=>0,'nextgen'=>0,'error'=>$got['error']));
        }
        $html = (string)$got['html'];
        $total = 0; $missing = 0; $nextgen = 0;
        if (preg_match_all('/<img\s+[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $img) {
                $total++;
                $has_w = preg_match('/\swidth\s*=\s*["\']?\d+/i', $img);
                $has_h = preg_match('/\sheight\s*=\s*["\']?\d+/i', $img);
                if (!$has_w || !$has_h) $missing++;
                $src = '';
                if (preg_match('/\s(src|data-src|data-lazy-src)\s*=\s*["\']([^"\']+)/i', $img, $m)) { $src = strtolower($m[2]); }
                $srcset = '';
                if (preg_match('/\ssrcset\s*=\s*["\']([^"\']+)/i', $img, $m2)) { $srcset = strtolower($m2[1]); }
                if (preg_match('/\.(webp|avif)(\?|$)/i', $src) || preg_match('/\.(webp|avif)(\s|,|$)/i', $srcset)) {
                    $nextgen++;
                }
            }
        }
        $score = 100;
        $score -= min(40, $missing * 4);
        if ($total > 0 && $nextgen == 0) { $score -= 9; }
        $score = max(0, min(100, (int)round($score)));
        return array('score'=>$score,'meta'=>array('total'=>$total,'missing_dims'=>$missing,'nextgen'=>$nextgen));
    }

    public static function check_assets_counts($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $got = self::fetch_html($tgt);
        if (empty($got['ok'])) {
            return array('score'=>50,'meta'=>array('css'=>0,'js'=>0,'error'=>$got['error']));
        }
        $html = (string)$got['html'];
        $css = 0; $js = 0;
        if (preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $m)) $css = count($m[0]);
        if (preg_match_all('/<script[^>]+src=["\'][^"\']+["\'][^>]*><\/script>/i', $html, $m2)) $js = count($m2[0]);
        $score = 100;
        $score -= max(0, ($css - 3) * 5);
        $score -= max(0, ($js - 5) * 5);
        $score = max(0, min(100, (int)round($score)));
        return array('score'=>$score,'meta'=>array('css'=>$css,'js'=>$js));
    }

    public static function check_render_blocking($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $got = self::fetch_html($tgt);
        if (empty($got['ok'])) {
            return array('score'=>50,'meta'=>array('blocking_css'=>0,'blocking_js'=>0,'error'=>$got['error']));
        }
        $html = (string)$got['html'];
        $blocking_css = 0; $blocking_js = 0;
        if (preg_match('/<head.*?>(.*?)<\/head>/is', $html, $h)) {
            $head = $h[1];
            if (preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $head, $links)) {
                foreach ($links[0] as $lnk) {
                    if (preg_match('/media=["\']print["\']/i', $lnk)) continue;
                    $blocking_css++;
                }
            }
            if (preg_match_all('/<script[^>]+src=["\'][^"\']+["\'][^>]*><\/script>/i', $head, $ss)) {
                foreach ($ss[0] as $sc) {
                    if (preg_match('/\sdefer\b/i', $sc) || preg_match('/\sasync\b/i', $sc)) continue;
                    $blocking_js++;
                }
            }
        }
        $score = max(0, 100 - 10 * ($blocking_css + $blocking_js));
        return array('score'=>$score,'meta'=>array('blocking_css'=>$blocking_css,'blocking_js'=>$blocking_js));
    }

    public static function check_cache_present($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $got = self::fetch_html($tgt);
        $cached = false; $plugin = '';
        if (!empty($got['headers'])) {
            $hdrs = $got['headers'];
            $x_cache = self::header_get($hdrs,'x-cache') ?: self::header_get($hdrs,'x-proxy-cache') ?: self::header_get($hdrs,'x-cache-status') ?: self::header_get($hdrs,'cf-cache-status') ?: '';
            $age = self::header_get($hdrs,'age');
            if ($x_cache && preg_match('/(hit|cached|HIT|MISS-HIT)/i', (string)$x_cache)) $cached = true;
            if (!empty($age) && (int)$age > 0) $cached = true;
        }
        if (function_exists('is_plugin_active')) {
            include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            if (is_plugin_active('w3-total-cache/w3-total-cache.php')) { $plugin = 'W3 Total Cache'; $cached = true; }
            if (is_plugin_active('wp-rocket/wp-rocket.php')) { $plugin = 'WP Rocket'; $cached = true; }
            if (is_plugin_active('litespeed-cache/litespeed-cache.php')) { $plugin = 'LiteSpeed Cache'; $cached = true; }
        }
        if (defined('WP_CACHE') && WP_CACHE) $cached = true;
        $score = $cached ? 100 : 0;
        return array('score'=>$score,'meta'=>array('cached'=>$cached,'plugin'=>$plugin));
    }

    public static function check_compression($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $got = self::fetch_html($tgt);
        $encoding = '';
        if (!empty($got['headers'])) { $encoding = (string) self::header_get($got['headers'], 'content-encoding'); }
        $score = (stripos($encoding, 'gzip')!==false || stripos($encoding, 'br')!==false) ? 100 : 0;
        return array('score'=>$score,'meta'=>array('encoding'=>$encoding ? $encoding : 'n/a'));
    }

    public static function check_client_cache($url = null) : array {
        $asset = get_stylesheet_uri();
        if (!empty($url)) {
            $got = self::fetch_html($url);
            if (!empty($got['ok']) && preg_match('/<link[^>]+rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)/i', $got['html'], $m)) {
                $asset = $m[1];
            }
        }
        $res = wp_remote_head($asset, array('timeout'=>12));
        if (is_wp_error($res)) return array('score'=>50,'meta'=>array('cache_control'=>'n/a'));
        $cc = wp_remote_retrieve_header($res, 'cache-control');
        $score = 50;
        if ($cc) {
            if (preg_match('/max-age=(\d+)/i', $cc, $m2)) {
                $age = (int)$m2[1];
                $score = $age >= 31536000 ? 100 : ($age >= 86400 ? 80 : 50);
            } else {
                $score = 60;
            }
        }
        return array('score'=>$score,'meta'=>array('cache_control'=>$cc ? $cc : 'n/a'));
    }

    public static function check_ttfb($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $start = microtime(true);
        $res = wp_remote_get($tgt, array('timeout'=>15, 'redirection'=>5));
        $ttfb = (int) round((microtime(true) - $start) * 1000);
        if (is_wp_error($res)) {
            return array('score'=>50,'meta'=>array('ttfb_ms'=>$ttfb,'error'=>$res->get_error_message()));
        }
        $score = 100;
        if     ($ttfb > 800) $score = 20;
        elseif ($ttfb > 600) $score = 40;
        elseif ($ttfb > 400) $score = 60;
        elseif ($ttfb > 300) $score = 80;
        elseif ($ttfb > 200) $score = 90;
        return array('score'=>$score,'meta'=>array('ttfb_ms'=>$ttfb));
    }

    public static function check_h2_h3($url = null) : array {
        $tgt = $url ? $url : home_url('/');
        $res = wp_remote_head($tgt, array('timeout'=>12));
        $alpn = '';
        if (!is_wp_error($res)) {
            $server = wp_remote_retrieve_header($res, 'server-timing');
            if ($server) $alpn = $server;
        }
        $score = 70;
        $ver = 'h2/h3-unknown';
        return array('score'=>$score,'meta'=>array('alpn'=>$alpn ? $alpn : $ver));
    }

    public static function check_autoload_size() : array {
        global $wpdb;
        $bytes = 0;
        $rows = $wpdb->get_results("SELECT option_value FROM {$wpdb->options} WHERE autoload='yes'");
        if ($rows) { foreach ($rows as $r) { $bytes += strlen(maybe_serialize($r->option_value)); } }
        $score = 100;
        if ($bytes > 1048576) $score = 40;
        elseif ($bytes > 524288) $score = 60;
        elseif ($bytes > 131072) $score = 80;
        return array('score'=>$score,'meta'=>array('bytes'=>$bytes));
    }

    public static function check_postmeta_bloat() : array {
        global $wpdb;
        $posts = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' ORDER BY post_date_gmt DESC LIMIT 20");
        $avg = 0;
        if ($posts) {
            $total_meta = 0;
            foreach ($posts as $pid) {
                $cnt = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d", $pid) );
                $total_meta += $cnt;
            }
            $avg = $posts ? ($total_meta / count($posts)) : 0;
        }
        $score = 100;
        if ($avg > 120) $score = 40;
        elseif ($avg > 80) $score = 60;
        elseif ($avg > 40) $score = 80;
        return array('score'=>$score,'meta'=>array('avg_meta'=>round($avg,1)));
    }

    public static function check_transients() : array {
        global $wpdb;
        $expired = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < UNIX_TIMESTAMP()");
        $score = 100;
        if ($expired > 1000) $score = 40;
        elseif ($expired > 200) $score = 60;
        elseif ($expired > 20)  $score = 80;
        return array('score'=>$score,'meta'=>array('expired'=>$expired));
    }

    public static function check_updates_core() : array {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_version_check();
        $updates = get_core_updates();
        $count = 0;
        if (is_array($updates)) {
            foreach ($updates as $u) { if (!empty($u->response) && $u->response==='upgrade') { $count++; } }
        }
        $score = $count ? 60 : 100;
        return array('score'=>$score,'meta'=>array('count'=>$count));
    }

    public static function check_updates_plugins() : array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $count = isset($updates->response) ? count($updates->response) : 0;
        $score = 100;
        if ($count > 15) $score = 20;
        elseif ($count > 8) $score = 40;
        elseif ($count > 3) $score = 60;
        elseif ($count > 0) $score = 80;
        return array('score'=>$score,'meta'=>array('count'=>$count));
    }

    public static function check_updates_themes() : array {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_update_themes();
        $updates = get_site_transient('update_themes');
        $count = isset($updates->response) ? count($updates->response) : 0;
        $score = 100;
        if ($count > 10) $score = 20;
        elseif ($count > 5) $score = 40;
        elseif ($count > 2) $score = 60;
        elseif ($count > 0) $score = 80;
        return array('score'=>$score,'meta'=>array('count'=>$count));
    }
}
