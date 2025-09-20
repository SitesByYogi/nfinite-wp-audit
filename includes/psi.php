<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** ---------- Helpers: sanitize key, normalize URL ---------- */
function nfinite_clean_api_key( $key ) {
    $key = trim( (string) $key );
    $key = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $key);
    $key = preg_replace('/\s+/', '', $key);
    return $key;
}
function nfinite_normalize_url( $url ) {
    $url = trim((string) $url);
    if ( ! preg_match('#^https?://#i', $url) ) $url = 'https://' . ltrim($url, '/');
    $parts = wp_parse_url($url);
    if (!empty($parts['path']) && substr($url, -1) !== '/') $url .= '/';
    return $url;
}

/** ---------- Scoring for Web Vitals / perf metrics ---------- */
function nfa_score_linear($value, $good, $poor) {
    if ($value === null) return null;
    if ($value <= $good) return 100;
    if ($value >= $poor) return 0;
    $t = ($value - $good) / ($poor - $good);
    return max(0, min(100, round(100 - 100*$t)));
}
function nfa_score_fcp_ms($ms){ return nfa_score_linear($ms, 1800, 3000); }
function nfa_score_lcp_ms($ms){ return nfa_score_linear($ms, 2500, 4000); }
function nfa_score_tbt_ms($ms){ return nfa_score_linear($ms, 200,  600 ); }
function nfa_score_si_ms($ms){  return nfa_score_linear($ms, 3400, 5800); }
function nfa_score_cls($v){     return nfa_score_linear($v , 0.10, 0.25); }
function nfa_score_inp_ms($ms){ return nfa_score_linear($ms, 200,  500 ); }

function nfa_grade($score){
    if ($score === null) return '–';
    if ($score >= 90) return 'A';
    if ($score >= 80) return 'B';
    if ($score >= 70) return 'C';
    if ($score >= 60) return 'D';
    return 'F';
}
function nfa_fmt_ms($ms){
    if ($ms === null) return '—';
    if ($ms < 1000) return intval($ms) . ' ms';
    return rtrim(rtrim(number_format($ms/1000, 2), '0'), '.') . ' s';
}
function nfa_fmt_cls($v){
    return $v === null ? '—' : rtrim(rtrim(number_format($v, 3), '0'), '.');
}

/** Build five lab metrics (FCP, LCP, TBT, CLS, SI) with 0–100 scores + grades. */
function nfa_extract_lab_metrics(array $psi_json){
    $a = $psi_json['lighthouseResult']['audits'] ?? [];

    $fcp = $a['first-contentful-paint']['numericValue'] ?? null;        // ms
    $lcp = $a['largest-contentful-paint']['numericValue'] ?? null;      // ms
    $tbt = $a['total-blocking-time']['numericValue'] ?? null;           // ms
    $cls = $a['cumulative-layout-shift']['numericValue'] ?? null;       // unitless
    $si  = $a['speed-index']['numericValue'] ?? null;                   // ms

    $rows = [
        'FCP' => ['label'=>'First Contentful Paint',   'value_raw'=>$fcp, 'value_fmt'=>nfa_fmt_ms($fcp), 'score'=>nfa_score_fcp_ms($fcp)],
        'LCP' => ['label'=>'Largest Contentful Paint', 'value_raw'=>$lcp, 'value_fmt'=>nfa_fmt_ms($lcp), 'score'=>nfa_score_lcp_ms($lcp)],
        'TBT' => ['label'=>'Total Blocking Time',      'value_raw'=>$tbt, 'value_fmt'=>nfa_fmt_ms($tbt), 'score'=>nfa_score_tbt_ms($tbt)],
        'CLS' => ['label'=>'Cumulative Layout Shift',  'value_raw'=>$cls, 'value_fmt'=>nfa_fmt_cls($cls), 'score'=>nfa_score_cls($cls)],
        'SI'  => ['label'=>'Speed Index',              'value_raw'=>$si,  'value_fmt'=>nfa_fmt_ms($si),  'score'=>nfa_score_si_ms($si)],
    ];

    $scores = [];
    foreach ($rows as &$m){
        $m['grade'] = nfa_grade($m['score']);
        if ($m['score'] !== null) $scores[] = $m['score'];
    }
    $overall = $scores ? round(array_sum($scores)/count($scores)) : null;

    return ['metrics'=>$rows, 'overall'=>$overall];
}

/** Compute overall Web Vitals preferring FIELD; fallback to LAB (LCP+CLS+INP or +FCP). */
function nfinite_compute_web_vitals_score(array $psi_json) {
    $comp  = ['LCP'=>null, 'CLS'=>null, 'INP'=>null, 'FCP'=>null];

    // FIELD (CrUX)
    $field = $psi_json['loadingExperience']['metrics'] ?? null;
    if (is_array($field) && $field) {
        $lcp = $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null;     // ms
        $cls = $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? null;   // sometimes *100
        $inp = $field['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null;       // ms
        $fcp = $field['FIRST_CONTENTFUL_PAINT_MS']['percentile'] ?? null;       // ms
        if ($cls !== null && $cls > 1) $cls = $cls / 100.0;

        $sL = nfa_score_lcp_ms($lcp);
        $sC = nfa_score_cls($cls);
        $sI = nfa_score_inp_ms($inp);
        $vals = array_filter([$sL, $sC, $sI], fn($v)=>$v!==null);
        if ($vals) {
            $comp['LCP']=$sL; $comp['CLS']=$sC; $comp['INP']=$sI; $comp['FCP']=nfa_score_fcp_ms($fcp);
            return ['source'=>'field', 'overall'=>round(array_sum($vals)/count($vals)), 'components'=>$comp];
        }
    }

    // LAB
    $a = $psi_json['lighthouseResult']['audits'] ?? [];
    $lcp_ms = $a['largest-contentful-paint']['numericValue'] ?? null;
    $cls    = $a['cumulative-layout-shift']['numericValue'] ?? null;
    $inp_ms = $a['interaction-to-next-paint']['numericValue'] ?? null;
    $fcp_ms = $a['first-contentful-paint']['numericValue'] ?? null;

    $sL = nfa_score_lcp_ms($lcp_ms);
    $sC = nfa_score_cls($cls);
    $sI = nfa_score_inp_ms($inp_ms);
    $sF = nfa_score_fcp_ms($fcp_ms);

    $vals = array_filter([$sL, $sC, $sI], fn($v)=>$v!==null);
    if (!$vals) $vals = array_filter([$sL, $sC, $sF], fn($v)=>$v!==null);

    $comp['LCP']=$sL; $comp['CLS']=$sC; $comp['INP']=$sI; $comp['FCP']=$sF;

    return ['source'=>$vals?'lab':'none', 'overall'=>$vals?round(array_sum($vals)/count($vals)):null, 'components'=>$comp];
}

/** ---------- Internal runner for a single strategy ---------- */
function nfinite_fetch_psi_single($url, $api_key, $proxy_url = '', $strategy = 'mobile') {
    // Proxy path
    if ( ! empty($proxy_url) ) {
        $endpoint = add_query_arg(array('url'=>$url, 'strategy'=>$strategy), $proxy_url);
        $res = wp_remote_get($endpoint, array('timeout'=>20));
        if (is_wp_error($res)) {
            return array('ok'=>false,'error'=>$res->get_error_message(),'scores'=>array(),'web_vitals'=>null);
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code >= 200 && $code < 300) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (is_array($data) && isset($data['scores'])) {
                return array(
                    'ok'    => true,
                    'error' => '',
                    'scores'=> array(
                        'performance'    => (int)($data['scores']['performance'] ?? 0),
                        'best_practices' => (int)($data['scores']['best_practices'] ?? 0),
                        'seo'            => (int)($data['scores']['seo'] ?? 0),
                        '_estimated'     => false
                    ),
                    'web_vitals' => isset($data['web_vitals']) ? (int)$data['web_vitals'] : null,
                );
            }
        }
        return array('ok'=>false,'error'=>'Proxy failed','scores'=>array(),'web_vitals'=>null);
    }

    // Direct PSI
    $api_key = nfinite_clean_api_key($api_key);
    if ( empty($api_key) ) {
        return array('ok'=>false,'error'=>'PSI HTTP 429 — No key / rate-limited','scores'=>array(),'web_vitals'=>null);
    }

    $norm_url = nfinite_normalize_url($url);

$base = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

// Build the standard params first
$params = array(
    'url'      => $norm_url,
    'strategy' => $strategy,   // 'mobile' or 'desktop'
    'key'      => $api_key,
);

// http_build_query (RFC3986) ensures proper encoding; then append repeated category keys
$qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986)
    . '&category=performance&category=best-practices&category=seo';

$endpoint = $base . '?' . $qs;

    $res = wp_remote_get($endpoint, array('timeout'=>40, 'redirection'=>3, 'headers'=>array('Accept'=>'application/json')));
    if (is_wp_error($res)) {
        return array('ok'=>false,'error'=>$res->get_error_message(),'scores'=>array(),'web_vitals'=>null);
    }
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code < 200 || $code >= 300) {
        $msg = 'PSI HTTP ' . $code;
        $j = json_decode($body, true);
        if (is_array($j) && isset($j['error']['message'])) $msg .= ' — ' . $j['error']['message'];
        return array('ok'=>false,'error'=>$msg,'scores'=>array(),'web_vitals'=>null);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return array('ok'=>false,'error'=>'Invalid PSI JSON','scores'=>array(),'web_vitals'=>null);
    }

    // Category scores
    $categories = $json['lighthouseResult']['categories'] ?? array();
    $perf = isset($categories['performance']['score']) ? round(($categories['performance']['score'] * 100)) : 0;
    $bp   = isset($categories['best-practices']['score']) ? round(($categories['best-practices']['score'] * 100)) : 0;
    $seo  = isset($categories['seo']['score']) ? round(($categories['seo']['score'] * 100)) : 0;

    // Web Vitals (field -> lab fallback)
    $web_vitals = null;
    $vitals_source = 'none';
    if (isset($json['loadingExperience']['overall_category'])) {
        $map = array('GOOD'=>95,'NEEDS_IMPROVEMENT'=>70,'POOR'=>40);
        $cat = strtoupper($json['loadingExperience']['overall_category']);
        $web_vitals = $map[$cat] ?? null;
        $vitals_source = 'field';
    }
    $lab_metrics = nfa_extract_lab_metrics($json);
    $lab_overall = $lab_metrics['overall'];

    if ($web_vitals === null) {
        $wv = nfinite_compute_web_vitals_score($json);
        $web_vitals   = $wv['overall'];
        $vitals_source = $wv['source'];
    }

    $warnings = $json['lighthouseResult']['runWarnings'] ?? array();
    $finalUrl = $json['lighthouseResult']['finalUrl'] ?? ($json['finalUrl'] ?? $norm_url);

    return array(
        'ok'           => true,
        'error'        => '',
        'scores'       => array(
            'performance'    => (int) $perf,
            'best_practices' => (int) $bp,
            'seo'            => (int) $seo,
            '_estimated'     => false
        ),
        'web_vitals'   => is_null($web_vitals) ? null : (int) $web_vitals,

        // Helpful extras (non-breaking):
        'lab_metrics'  => $lab_metrics['metrics'],
        'lab_overall'  => $lab_overall,
        'vitals_source'=> $vitals_source,      // 'field' | 'lab' | 'none'
        'warnings'     => $warnings,
        'finalUrl'     => $finalUrl,
        'strategy'     => $strategy,
    );
}

/**
 * Public: Fetch PSI for a given URL and strategy.
 * @param string $url
 * @param string $api_key
 * @param string $proxy_url
 * @param string $strategy 'mobile' | 'desktop' | 'both'
 * @return array
 *   - For 'mobile' or 'desktop': same shape as before (ok,error,scores,web_vitals,…) plus extras.
 *   - For 'both': adds 'runs' => ['mobile'=>…, 'desktop'=>…]. Top-level mirrors the **mobile** run for backward compatibility.
 */
function nfinite_fetch_psi($url, $api_key, $proxy_url = '', $strategy = 'mobile') {
    $strategy = strtolower($strategy);
    if (!in_array($strategy, ['mobile','desktop','both'], true)) $strategy = 'mobile';

    if ($strategy !== 'both') {
        return nfinite_fetch_psi_single($url, $api_key, $proxy_url, $strategy);
    }

    // Run both and return combined (top-level mirrors mobile for back-compat)
    $mobile  = nfinite_fetch_psi_single($url, $api_key, $proxy_url, 'mobile');
    $desktop = nfinite_fetch_psi_single($url, $api_key, $proxy_url, 'desktop');

    // If mobile failed but desktop succeeded, still provide sane top-level fallbacks
    $primary = $mobile['ok'] ? $mobile : ($desktop['ok'] ? $desktop : $mobile);

    $combined = $primary; // copy top-level from primary (usually mobile)
    $combined['runs'] = [
        'mobile'  => $mobile,
        'desktop' => $desktop,
    ];
    return $combined;
}

/** ---------- Internal fallback: estimate "Web Vitals" from internal checks (no PSI) ---------- */
function nfinite_estimate_web_vitals_from_internal(array $internal){
    $checks = isset($internal['checks']) && is_array($internal['checks']) ? $internal['checks'] : array();
    $ttfb_ms = isset($checks['ttfb']['meta']['ttfb_ms']) ? (int)$checks['ttfb']['meta']['ttfb_ms'] : null;
    $assets_css = isset($checks['assets_counts']['meta']['css']) ? (int)$checks['assets_counts']['meta']['css'] : 0;
    $assets_js  = isset($checks['assets_counts']['meta']['js'])  ? (int)$checks['assets_counts']['meta']['js']  : 0;
    $blocking_css = isset($checks['render_blocking']['meta']['blocking_css']) ? (int)$checks['render_blocking']['meta']['blocking_css'] : 0;
    $blocking_js  = isset($checks['render_blocking']['meta']['blocking_js'])  ? (int)$checks['render_blocking']['meta']['blocking_js']  : 0;
    $img_total    = isset($checks['images_dims_and_size']['meta']['total']) ? (int)$checks['images_dims_and_size']['meta']['total'] : 0;
    $img_missing  = isset($checks['images_dims_and_size']['meta']['missing_dims']) ? (int)$checks['images_dims_and_size']['meta']['missing_dims'] : 0;
    $img_nextgen  = isset($checks['images_dims_and_size']['meta']['nextgen']) ? (int)$checks['images_dims_and_size']['meta']['nextgen'] : 0;

    // Heuristics
    $total_assets = $assets_css + $assets_js;
    $rb_total = $blocking_css + $blocking_js;

    // FCP ~ TTFB + render-blocking penalties + asset overhead
    $fcp = 800 + max(0, (int)$ttfb_ms);
    $fcp += min(2000, 200 * $blocking_css + 100 * $blocking_js);
    $extra_assets = max(0, $total_assets - 20);
    $fcp += min(1000, 10 * $extra_assets);
    $fcp = max(500, min(4000, $fcp));

    // LCP ~ FCP + image-related costs
    $img_pen = 0;
    if ($img_total > 0) {
        $ratio_nextgen = max(0.0, min(1.0, $img_nextgen / max(1,$img_total)));
        $img_pen += (1.0 - $ratio_nextgen) * 400; // missing next-gen => slower
        $ratio_missing = max(0.0, min(1.0, $img_missing / max(1,$img_total)));
        $img_pen += $ratio_missing * 600; // missing dims => layout shifts / late rendering
        if ($img_total > 30) $img_pen += min(800, ($img_total - 30) * 12);
    }
    $lcp = $fcp + (int)(0.6 * $img_pen) + (int)(120 * $rb_total);
    $lcp = max(800, min(5000, $lcp));

    // TBT ~ amount of render-blocking JS + many JS files
    $tbt = (int)(75 * $blocking_js + max(0, $assets_js - 20) * 15);
    $tbt = max(0, min(1000, $tbt));

    // CLS ~ fraction of images missing dimensions
    $cls = null;
    if ($img_total > 0) {
        $ratio = max(0.0, min(1.0, $img_missing / max(1,$img_total)));
        // Map ratio [0..1] to CLS [0..0.3]
        $cls = round(0.30 * $ratio, 3);
    } else {
        // No images meta — rough guess based on render blocking
        $cls = $rb_total > 0 ? 0.07 : 0.03;
    }
    $cls = max(0.0, min(0.4, $cls));

    // SI ~ assets and render blocking
    $si = 1500 + 40 * $total_assets + 250 * $rb_total + (int)(0.2 * max(0,(int)$ttfb_ms));
    $si = max(1000, min(6000, $si));

    // Build rows with scoring + grades
    $rows = array(
        'FCP' => array('label'=>'First Contentful Paint',   'value_raw'=>$fcp, 'value_fmt'=>nfa_fmt_ms($fcp), 'score'=>nfa_score_fcp_ms($fcp)),
        'LCP' => array('label'=>'Largest Contentful Paint', 'value_raw'=>$lcp, 'value_fmt'=>nfa_fmt_ms($lcp), 'score'=>nfa_score_lcp_ms($lcp)),
        'TBT' => array('label'=>'Total Blocking Time',      'value_raw'=>$tbt,'value_fmt'=>nfa_fmt_ms($tbt),'score'=>nfa_score_tbt_ms($tbt)),
        'CLS' => array('label'=>'Cumulative Layout Shift',  'value_raw'=>$cls,'value_fmt'=>nfa_fmt_cls($cls),'score'=>nfa_score_cls($cls)),
        'SI'  => array('label'=>'Speed Index',              'value_raw'=>$si, 'value_fmt'=>nfa_fmt_ms($si),  'score'=>nfa_score_si_ms($si)),
    );
    foreach ($rows as &$m){ $m['grade'] = nfa_grade($m['score']); }
    $scores = array();
    foreach ($rows as $m){ if ($m['score'] !== null) $scores[] = $m['score']; }
    $overall = $scores ? (int) round(array_sum($scores)/count($scores)) : null;

    return array('metrics'=>$rows, 'overall'=>$overall, 'source'=>'internal');
}
