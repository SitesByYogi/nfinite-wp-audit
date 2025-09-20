<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Nfinite_Audit_Admin {

    /**
     * Boot
     */
    public static function init() {
        add_action('admin_menu',              array(__CLASS__, 'menu'));
        add_action('admin_init',              array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts',   array(__CLASS__, 'enqueue_admin_css'));

        // Actions
        add_action('admin_post_nfinite_run_audit_now', array(__CLASS__, 'handle_run_audit'));

        // AJAX
        add_action('wp_ajax_nfinite_test_psi', array(__CLASS__, 'ajax_test_psi'));

        // Make sure our digest helper is available
if ( ! function_exists('nfinite_get_site_health_digest') ) {
    $digest_file = dirname(__DIR__) . '/includes/site-health-digest.php';
    if ( file_exists($digest_file) ) {
        require_once $digest_file;
    }
}

    }


    /**
     * Admin Menu
     */
    public static function menu() {
        add_menu_page(
            'Nfinite Audit',
            'Nfinite Audit',
            'manage_options',
            'nfinite-audit',
            array(__CLASS__, 'render_dashboard_page'),
            'dashicons-search',
            59
        );

        add_submenu_page(
            'nfinite-audit',
            'Dashboard · Nfinite Audit',
            'Dashboard',
            'manage_options',
            'nfinite-audit',
            array(__CLASS__, 'render_dashboard_page')
        );

        // NEW: dedicated Site Health page
        add_submenu_page(
            'nfinite-audit',
            'Site Health · Nfinite Audit',
            'Site Health',
            'manage_options',
            'nfinite-audit-health',
            array(__CLASS__, 'render_health_page')
        );

        add_submenu_page(
            'nfinite-audit',
            'Settings · Nfinite Audit',
            'Settings',
            'manage_options',
            'nfinite-audit-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    /**
     * Settings
     */
    public static function register_settings() {
        register_setting('nfinite_audit_group', 'nfinite_psi_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('nfinite_audit_group', 'nfinite_proxy_url', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ));
        register_setting('nfinite_audit_group', 'nfinite_test_url', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ));

        add_settings_section(
            'nfinite_audit_section_keys',
            'API & Connection',
            function () {
                echo '<p>Provide your API key and (optionally) a proxy endpoint if you use one. The Default Test URL is used on the dashboard.</p>';
            },
            'nfinite-audit-settings'
        );

        add_settings_field(
            'nfinite_psi_api_key',
            'PageSpeed Insights API Key',
            array(__CLASS__, 'field_text'),
            'nfinite-audit-settings',
            'nfinite_audit_section_keys',
            array('option' => 'nfinite_psi_api_key', 'placeholder' => 'AIza...')
        );

        add_settings_field(
            'nfinite_proxy_url',
            'Proxy URL (optional)',
            array(__CLASS__, 'field_text'),
            'nfinite-audit-settings',
            'nfinite_audit_section_keys',
            array('option' => 'nfinite_proxy_url', 'placeholder' => 'https://your-proxy.example.com/psi')
        );

        add_settings_field(
            'nfinite_test_url',
            'Default Test URL',
            array(__CLASS__, 'field_text'),
            'nfinite-audit-settings',
            'nfinite_audit_section_keys',
            array('option' => 'nfinite_test_url', 'placeholder' => 'https://example.com')
        );
    }

    /**
     * Generic text field renderer
     */
    public static function field_text( $args ) {
        $option      = isset($args['option']) ? $args['option'] : '';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $value       = esc_attr( get_option($option, '') );
        printf(
            '<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s" placeholder="%3$s" />',
            esc_attr($option),
            $value,
            esc_attr($placeholder)
        );
    }

    /**
     * Enqueue styles/scripts on plugin screens only
     */
    public static function enqueue_admin_css( $hook ) {
        $allowed = array(
            'toplevel_page_nfinite-audit',
            'nfinite-audit_page_nfinite-audit-settings',
            'nfinite-audit_page_nfinite-audit-health' // NEW page
        );
        if ( ! in_array($hook, $allowed, true) ) return;

        wp_enqueue_style('nfinite-admin', NFINITE_AUDIT_URL . 'assets/admin.css', array(), NFINITE_AUDIT_VER);
        wp_enqueue_script('nfinite-admin', NFINITE_AUDIT_URL . 'assets/admin.js', array('jquery'), NFINITE_AUDIT_VER, true);

        // Inline CSS tokens (layout + badges + digest list)
        $css = ''
        . '.nfinite-wrap{max-width:1100px}'
        . '.nfinite-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:start}'
        . '.nfinite-cards.full{grid-template-columns:repeat(2,minmax(0,1fr));align-items:start}'
        . '.nfinite-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;align-self:start}'
        . '.nfinite-card summary{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;cursor:pointer;list-style:none}'
        . '.nfinite-card summary::-webkit-details-marker{display:none}'
        . '.nfinite-h{font-weight:700;font-size:14px;color:#111827}'
        . '.nfinite-score{font-size:28px;font-weight:800}'
        . '.nfinite-detail{padding:0 16px 14px 16px;border-top:1px solid #e5e7eb}'
        . '.nfinite-badge{display:inline-block;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px}'

        // Grade badges (A–F)
        . '.A{background:#ecfdf5;color:#065f46}.B{background:#eff6ff;color:#1e40af}.C{background:#fef3c7;color:#92400e}.D{background:#fee2e2;color:#991b1b}.F{background:#fef2f2;color:#991b1b}'

        // Section disclosure chevron
        . 'details.nfinite-card.section summary .chev{margin-left:8px;display:inline-block;transform:rotate(0deg);transition:transform .2s ease;font-size:18px;line-height:1}'
        . 'details.nfinite-card.section[open] summary .chev{transform:rotate(180deg)}'

        // Inline checks + chips
        . '.nfinite-check{margin:8px 0;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}'
        . '.nfinite-check .head{display:flex;align-items:center;justify-content:space-between;gap:10px}'
        . '.nfinite-check .label{font-weight:600;color:#111827}'
        . '.nfinite-chip{display:inline-block;min-width:36px;text-align:center;border-radius:999px;padding:3px 10px;font-weight:700;font-size:12px;background:#eef2ff;color:#3730a3}'
        . '.nfinite-hint{margin-top:6px;color:#4b5563;font-size:12px}'
        . '.nfinite-reco{margin-top:8px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb}'
        . '.nfinite-help{color:#6b7280;font-size:13px;margin-top:6px}'
        . '.nfinite-section{margin-top:18px}.nfinite-section-title{margin:16px 0 8px}'

        // Site Health Digest list
        . '.nfinite-card__header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e5e7eb}'
        . '.nfinite-list{padding:8px 16px}'
        . '.nfinite-list__item{padding:12px 0;border-bottom:1px solid #f1f5f9}'
        . '.nfinite-list__item:last-child{border-bottom:none}'
        . '.nfinite-badge-good{background:#ecfdf5;color:#065f46}'
        . '.nfinite-badge-warn{background:#fffbeb;color:#92400e}'
        . '.nfinite-badge-danger{background:#fef2f2;color:#991b1b}'
        . '.nfinite-badge-muted{background:#f3f4f6;color:#374151}'

        // Responsive
        . '@media (max-width:1200px){.nfinite-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}'
        . '@media (max-width:782px){.nfinite-cards,.nfinite-cards.full{grid-template-columns:1fr}}';
        wp_add_inline_style('nfinite-admin', $css);

        // Localize for assets/admin.js if needed
        wp_localize_script('nfinite-admin', 'NFINITE_AUDIT_VARS', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('nfinite_test_psi'),
        ));
    }

    /**
     * Convert score to A–F badge (uses your existing grading helper)
     */
    public static function grade_badge( $score ) {
        $grade = Nfinite_Audit_V1::grade_from_score( (int) $score );
        return '<span class="nfinite-badge ' . esc_attr($grade) . '">' . esc_html($grade) . '</span>';
    }

    /**
     * PSI AJAX (mobile + desktop) with internal fallback
     */
    public static function ajax_test_psi() {
        if ( ! current_user_can('manage_options') ) wp_send_json_error(array('message' => 'Unauthorized'), 403);
        check_ajax_referer('nfinite_test_psi');

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if ( empty($url) ) wp_send_json_error(array('message' => 'Missing URL'), 400);

        // Internal baseline
        $internal = Nfinite_Audit_V1::run_internal_audit($url);

        // Credentials
        $api   = function_exists('nfinite_clean_api_key') ? nfinite_clean_api_key( get_option('nfinite_psi_api_key','') ) : get_option('nfinite_psi_api_key','');
        $proxy = trim( (string) get_option('nfinite_proxy_url', '') );

        // No PSI: estimate categories
        if ( empty($api) && empty($proxy) ) {
            $cats = function_exists('nfinite_estimate_lighthouse') ? nfinite_estimate_lighthouse($internal) : array();
            $psi_scores = array(
                'performance'    => isset($cats['performance']) ? (int)$cats['performance'] : 0,
                'best_practices' => isset($cats['best_practices']) ? (int)$cats['best_practices'] : 0,
                'seo'            => isset($cats['seo']) ? (int)$cats['seo'] : 0,
                '_estimated'     => true,
            );

            $parts = array();
            $sections = isset($internal['sections']) ? $internal['sections'] : array();
            if ( is_array($sections) ) {
                foreach ( $sections as $sec ) {
                    if ( isset($sec['score']) ) $parts[] = (int) $sec['score'];
                }
            }
            foreach ( array('performance','best_practices','seo') as $k ) {
                if ( isset($psi_scores[$k]) ) $parts[] = (int) $psi_scores[$k];
            }
            $overall_est = $parts ? (int) round(array_sum($parts) / count($parts)) : 0;

            $payload = array(
                'timestamp'     => current_time('Y-m-d H:i:s'),
                'url'           => $url,
                'finalUrl'      => $url,
                'psi_ok'        => false,
                'psi_error'     => '',
                'psi_scores'    => $psi_scores,
                'web_vitals'    => null,
                'lab_metrics'   => array(),
                'lab_overall'   => null,
                'vitals_source' => 'none',
                'internal'      => $internal,
                'overall'       => $overall_est,
                'grade'         => Nfinite_Audit_V1::grade_from_score($overall_est),
            );
            update_option('nfinite_audit_last', $payload, false);

            $ui = array(
                'ok'       => true,
                'overall'  => $overall_est,
                'metrics'  => array(),
                'cats'     => array(
                    'performance'    => $psi_scores['performance'],
                    'best_practices' => $psi_scores['best_practices'],
                    'seo'            => $psi_scores['seo'],
                ),
                'warnings' => array('Lab metrics (FCP/LCP/TBT/CLS/SI) require a PageSpeed API key or proxy.'),
                'finalUrl' => $url,
            );
            wp_send_json_success(array('mobile' => $ui, 'desktop' => $ui));
        }

        // PSI (both devices)
        $mobile  = nfinite_fetch_psi($url, $api, $proxy, 'mobile');
        $desktop = nfinite_fetch_psi($url, $api, $proxy, 'desktop');
        $primary = !empty($mobile['ok']) ? $mobile : ( !empty($desktop['ok']) ? $desktop : $mobile );

        $psi_ok     = !empty($primary['ok']);
        $psi_error  = $psi_ok ? '' : ( isset($primary['error']) ? $primary['error'] : 'Unknown error' );
        $psi_scores = $psi_ok && isset($primary['scores'])
            ? $primary['scores']
            : ( function_exists('nfinite_estimate_lighthouse') ? nfinite_estimate_lighthouse($internal) : array('performance'=>0,'best_practices'=>0,'seo'=>0,'_estimated'=>true) );

        $web_vitals  = $psi_ok ? ( $primary['web_vitals']  ?? null ) : null;
        $lab_metrics = $psi_ok ? ( $primary['lab_metrics'] ?? array() ) : array();
        $lab_overall = $psi_ok ? ( $primary['lab_overall'] ?? null ) : null;
        $vitals_src  = $psi_ok ? ( $primary['vitals_source'] ?? 'none' ) : 'none';
        $finalUrl    = $psi_ok ? ( $primary['finalUrl'] ?? $url ) : $url;

        // Build overall blended score
        $parts = array();
        $sections = isset($internal['sections']) ? $internal['sections'] : array();
        if ( is_array($sections) ) {
            foreach ( $sections as $sec ) {
                if ( isset($sec['score']) ) $parts[] = (int) $sec['score'];
            }
        }
        foreach ( array('performance','best_practices','seo') as $k ) {
            if ( isset($psi_scores[$k]) ) $parts[] = (int) $psi_scores[$k];
        }
        if ( !is_null($web_vitals) && ($vitals_src === 'lab' || $vitals_src === 'field') ) {
            $parts[] = (int) $web_vitals;
        }
        $overall = $parts ? (int) round(array_sum($parts) / count($parts)) : 0;

        // Persist last payload
        $payload = array(
            'timestamp'     => current_time('Y-m-d H:i:s'),
            'url'           => $url,
            'finalUrl'      => $finalUrl,
            'psi_ok'        => $psi_ok,
            'psi_error'     => $psi_error,
            'psi_scores'    => $psi_scores,
            'web_vitals'    => $web_vitals,
            'lab_metrics'   => $lab_metrics,
            'lab_overall'   => $lab_overall,
            'vitals_source' => $vitals_src,
            'internal'      => $internal,
            'overall'       => $overall,
            'grade'         => Nfinite_Audit_V1::grade_from_score($overall),
        );
        update_option('nfinite_audit_last', $payload, false);

        // Shape minimal UI for each device panel
        $out = array();
        if ( !empty($mobile['ok']) ) {
            $out['mobile'] = array(
                'ok'       => true,
                'overall'  => $mobile['lab_overall'] ?? null,
                'metrics'  => $mobile['lab_metrics'] ?? array(),
                'warnings' => $mobile['warnings']    ?? array(),
                'finalUrl' => $mobile['finalUrl']    ?? $url,
            );
        } else {
            $out['mobile'] = array('ok' => false, 'error' => $mobile['error'] ?? 'Unknown error');
        }

        if ( !empty($desktop['ok']) ) {
            $out['desktop'] = array(
                'ok'       => true,
                'overall'  => $desktop['lab_overall'] ?? null,
                'metrics'  => $desktop['lab_metrics'] ?? array(),
                'warnings' => $desktop['warnings']    ?? array(),
                'finalUrl' => $desktop['finalUrl']    ?? $url,
            );
        } else {
            $out['desktop'] = array('ok' => false, 'error' => $desktop['error'] ?? 'Unknown error');
        }

        wp_send_json_success($out);
    }

    /**
     * Dashboard (PSI, Overall, Web Vitals, Section Scores)
     * Site Health Digest has been moved to its own page.
     */
    public static function render_dashboard_page() {
        if ( ! current_user_can('manage_options') ) return;

        $api      = get_option('nfinite_psi_api_key','');
        $proxy    = get_option('nfinite_proxy_url','');
        $test_url = get_option('nfinite_test_url', home_url('/'));
        $last     = get_option('nfinite_audit_last', null);

        ?>
        <div class="wrap nfinite-wrap">
          <h1>Nfinite Audit</h1>

          <?php
          if ( empty($api) ) {
              $current_home = ($last && !empty($last['url'])) ? $last['url'] : $test_url;
              $psi_ui = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode($current_home) . '&form_factor=mobile';
              echo '<div class="notice notice-info"><p>No PageSpeed API key set. <a href="' . esc_url($psi_ui) . '" target="_blank" rel="noopener">Open PageSpeed report</a></p></div>';
          }

          if ( isset($_GET['nfinite_done']) ) {
              echo '<div class="notice notice-success is-dismissible"><p>Audit completed for ' . esc_html(($last && !empty($last['url'])) ? $last['url'] : $test_url) . '.</p></div>';
          }

          if ( $last && isset($last['psi_ok']) && !$last['psi_ok'] && !empty($last['psi_error']) ) {
              echo '<div class="notice notice-warning"><p><strong>PageSpeed Insights failed:</strong> ' . esc_html($last['psi_error']) . '. Add an API key in Settings (or configure a proxy), then re-run.</p></div>';
          }

          if ( $last && isset($last['psi_scores']['_estimated']) && $last['psi_scores']['_estimated'] ) {
              echo '<div class="notice notice-info"><p>Lighthouse unavailable — showing <strong>estimated</strong> Performance / Best Practices / SEO based on internal checks. Add a PSI API key for official Lighthouse data.</p></div>';
          }
          ?>

          <p class="nfinite-help">
            Looking for Site Health details? Visit the
            <a href="<?php echo esc_url( admin_url('admin.php?page=nfinite-audit-health') ); ?>">Site Health page</a>.
          </p>

          <?php $nonce = wp_create_nonce('nfinite_test_psi'); ?>
          <div class="nfinite-psi-test" style="margin:14px 0 20px">
            <h2 style="margin:0 0 8px">Test PageSpeed Insights</h2>
            <p>Enter a public URL, then run mobile &amp; desktop tests.</p>
            <input type="url" id="nfinite-psi-url" value="<?php echo esc_attr($test_url); ?>" style="width:420px; max-width:100%;" />
            <button type="button" class="button button-primary" id="nfinite-psi-run">Run Test</button>
            <span class="spinner" style="float:none;"></span>
            <div id="nfinite-psi-results" style="margin-top:14px"></div>
          </div>

          <script>
          (function(){
            const ajax  = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
            const nonce = "<?php echo esc_js($nonce); ?>";
            const runBtn   = document.getElementById('nfinite-psi-run');
            const inputUrl = document.getElementById('nfinite-psi-url');
            const results  = document.getElementById('nfinite-psi-results');
            const spinner  = runBtn.nextElementSibling;

            function card(label, data){
              const wrap = document.createElement('div');
              wrap.className = 'nfinite-psi-block';
              const h = document.createElement('h4'); h.textContent = label; wrap.appendChild(h);
              if (!data.ok){
                wrap.innerHTML += '<div class="notice notice-error"><p>'+ (data.error || 'Error') +'</p></div>';
                return wrap;
              }
              wrap.innerHTML += '<div class="nfinite-score">'+(data.overall===null?'N/A':parseInt(data.overall))+'</div>';
              const hasMetrics = data.metrics && Object.keys(data.metrics).length>0;
              if (!hasMetrics){
                const est = document.createElement('div');
                est.className = 'nfinite-detail';
                const cats = data.cats || {};
                est.innerHTML = '<p><em>Lab metrics unavailable without PSI. Showing estimated category scores.</em></p>'
                  + '<p><strong>Performance:</strong> ' + (cats.performance==null?'N/A':parseInt(cats.performance)) + '</p>'
                  + '<p><strong>Best Practices:</strong> ' + (cats.best_practices==null?'N/A':parseInt(cats.best_practices)) + '</p>'
                  + '<p><strong>SEO:</strong> ' + (cats.seo==null?'N/A':parseInt(cats.seo)) + '</p>';
                wrap.appendChild(est);
                return wrap;
              }
              const grid = document.createElement('div'); grid.className='nfinite-cards full';
              ['FCP','LCP','TBT','CLS','SI'].forEach(function(id){
                const m = data.metrics[id]; if (!m) return;
                const cls = (m.score===null)?'neutral':(m.score>=90?'good':(m.score>=70?'ok':'bad'));
                const html = `
                  <div class="nfinite-card">
                    <details open>
                      <summary><span class="nfinite-h">${m.label}</span><span class="nfinite-badge ${cls}">${m.grade}</span></summary>
                      <div class="nfinite-detail">
                        <p><strong>Value:</strong> ${m.value_fmt}</p>
                        <p><strong>Score:</strong> ${m.score===null?'—':parseInt(m.score)}</p>
                      </div>
                    </details>
                  </div>`;
                grid.insertAdjacentHTML('beforeend', html);
              });
              wrap.appendChild(grid);
              if (data.warnings && data.warnings.length){
                const p = document.createElement('p'); p.className='nfinite-help'; p.textContent = 'Warnings: ' + data.warnings.join(' | '); wrap.appendChild(p);
              }
              return wrap;
            }

            runBtn.addEventListener('click', function(){
              results.innerHTML = '';
              spinner.classList.add('is-active');
              const fd = new FormData();
              fd.append('action','nfinite_test_psi');
              fd.append('_ajax_nonce', nonce);
              fd.append('url', inputUrl.value.trim());
              fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'})
                .then(r=>r.json())
                .then(j=>{
                  if (!j || !j.success) throw new Error(j && j.data && j.data.message ? j.data.message : 'Unknown error');
                  const grid = document.createElement('div');
                  grid.className = 'nfinite-psi-grid';
                  grid.style.display = 'grid';
                  grid.style.gridTemplateColumns = 'repeat(2,minmax(0,1fr))';
                  grid.style.gap = '16px';
                  grid.appendChild(card('Mobile', j.data.mobile));
                  grid.appendChild(card('Desktop', j.data.desktop));
                  results.appendChild(grid);
                  setTimeout(function(){ try{ location.reload(); }catch(e){} }, 1200);
                })
                .catch(err=>{
                  results.innerHTML = '<div class="notice notice-error"><p>'+ String(err.message||err) +'</p></div>';
                })
                .finally(()=> spinner.classList.remove('is-active'));
            });
          })();
          </script>

          <h2 class="nfinite-section-title">Overall</h2>
          <section class="nfinite-section">
            <div class="nfinite-cards" style="grid-template-columns:1fr">
              <div class="nfinite-card">
                <div class="nfinite-detail">
                  <?php $overall = isset($last['overall']) ? (int)$last['overall'] : 0; ?>
                  <div style="text-align:center;margin:10px 0 12px 0">
                    <div class="nfinite-score" style="display:block;margin-bottom:6px"><?php echo esc_html($overall); ?></div>
                    <?php echo self::grade_badge($overall); ?>
                  </div>
                  <?php
                    $perf = isset($last['psi_scores']['performance'])    ? (int)$last['psi_scores']['performance']    : 0;
                    $bp   = isset($last['psi_scores']['best_practices']) ? (int)$last['psi_scores']['best_practices'] : 0;
                    $seo  = isset($last['psi_scores']['seo'])            ? (int)$last['psi_scores']['seo']            : 0;
                  ?>
                  <div class="nfinite-h" style="margin-top:4px">Lighthouse / Category Scores</div>
                  <?php if (!empty($last['psi_scores']['_estimated'])) echo '<p>(estimated)</p>'; ?>
                  <p><strong>Performance:</strong> <?php echo esc_html($perf); ?></p>
                  <p><strong>Best Practices:</strong> <?php echo esc_html($bp); ?></p>
                  <p><strong>SEO:</strong> <?php echo esc_html($seo); ?></p>
                  <p class="nfinite-help">Overall score is the simple average of all available scores: Section Scores, Lighthouse category scores, and (when available) Web Vitals.</p>
                </div>
              </div>
            </div>
          </section>

          <h2 class="nfinite-section-title">Web Vitals</h2>
          <section class="nfinite-section">
            <div class="nfinite-cards" style="grid-template-columns:1fr">
              <details class="nfinite-card" open>
                <?php
                  $wv    = array_key_exists('web_vitals', (array)$last) ? $last['web_vitals'] : null;
                  $vsrc  = isset($last['vitals_source']) ? $last['vitals_source'] : 'none';
                  $show  = in_array($vsrc, array('lab','field'), true);
                  $badge = is_null($wv) ? '' : '<span class="nfinite-badge ' . Nfinite_Audit_V1::grade_from_score((int)$wv) . '">' . Nfinite_Audit_V1::grade_from_score((int)$wv) . '</span>';
                ?>
                <summary><div class="nfinite-h">Web Vitals Score</div><div class="nfinite-score"><?php echo is_null($wv) ? 'N/A' : esc_html((int)$wv); ?></div><?php echo $badge; ?></summary>
                <div class="nfinite-detail">
                  <?php if ( ! $show ) : ?>
                    <p>Web Vitals not available from PSI. This metric is excluded from the overall score.</p>
                  <?php else :
                      $lab = isset($last['lab_metrics']) && is_array($last['lab_metrics']) ? $last['lab_metrics'] : array();
                      if ($lab) :
                        $order = array('FCP','LCP','TBT','CLS','SI'); ?>
                        <div class="nfinite-cards full">
                          <?php foreach ($order as $id) :
                              if (!isset($lab[$id])) continue;
                              $m = $lab[$id];
                              $score = isset($m['score']) ? (int)$m['score'] : null;
                              $grade = is_null($score) ? '–' : nfa_grade($score);
                              $badge_class = is_null($score) ? 'F' : $grade;
                              $label = esc_html(isset($m['label']) ? $m['label'] : $id);
                              $value_fmt = esc_html(isset($m['value_fmt']) ? $m['value_fmt'] : '—'); ?>
                              <div class="nfinite-card">
                                <details open>
                                  <summary><span class="nfinite-h"><?php echo $label; ?></span><span class="nfinite-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($grade); ?></span></summary>
                                  <div class="nfinite-detail">
                                    <p><strong>Value:</strong> <?php echo $value_fmt; ?></p>
                                    <p><strong>Score:</strong> <?php echo is_null($score) ? '—' : (int)$score; ?></p>
                                  </div>
                                </details>
                              </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif;
                      if ( ! empty($last['vitals_source']) ) :
                          echo '<p class="nfinite-help">Source: ' . esc_html(strtoupper($last['vitals_source'])) . '</p>';
                      endif;
                    endif; ?>
                </div>
              </details>
            </div>
          </section>

          <h2 class="nfinite-section-title">Section Scores</h2>
          <section class="nfinite-section">
            <div class="nfinite-cards full">
              <?php
                $sections = array(
                    'caching'  => 'Caching',
                    'assets'   => 'Assets',
                    'images'   => 'Images',
                    'server'   => 'Server',
                    'database' => 'Database',
                    'core'     => 'Core Plugins',
                );

                $checks          = isset($last['internal']['checks'])   ? $last['internal']['checks']   : array();
                $section_scores  = isset($last['internal']['sections']) ? $last['internal']['sections'] : array();

                $label_map = array(
                    'cache_present'           => 'Page Cache Present',
                    'compression'             => 'HTTP Compression',
                    'client_cache'            => 'Browser Cache (Assets)',
                    'assets_counts'           => 'CSS/JS Requests',
                    'render_blocking'         => 'Render-Blocking Resources',
                    'images_dims_and_size'    => 'Image Dimensions / Next-Gen',
                    'ttfb'                    => 'TTFB',
                    'h2_h3'                   => 'HTTP/2 / HTTP/3',
                    'autoload_size'           => 'Autoloaded Options Size',
                    'postmeta_bloat'          => 'Postmeta Bloat',
                    'transients'              => 'Expired Transients',
                    'updates_core'            => 'Core Updates',
                    'updates_plugins'         => 'Plugin Updates',
                    'updates_themes'          => 'Theme Updates',
                );

                $section_checks = array(
                    'caching'  => array('cache_present','compression','client_cache'),
                    'assets'   => array('assets_counts','render_blocking'),
                    'images'   => array('images_dims_and_size'),
                    'server'   => array('ttfb','h2_h3'),
                    'database' => array('autoload_size','postmeta_bloat','transients'),
                    'core'     => array('updates_core','updates_plugins','updates_themes'),
                );

                foreach ($sections as $key => $title) :
                    $s = isset($section_scores[$key]['score']) ? (int)$section_scores[$key]['score'] : 0; ?>
                    <details class="nfinite-card section">
                      <summary><div class="nfinite-h"><?php echo esc_html($title); ?></div><div class="nfinite-score"><?php echo esc_html($s); ?></div><?php echo self::grade_badge($s); ?><span class="chev" aria-hidden="true">▾</span></summary>
                      <div class="nfinite-detail">
                        <?php
                          $check_slugs = isset($section_checks[$key]) ? $section_checks[$key] : array();
                          foreach ($check_slugs as $slug) :
                            $res  = isset($checks[$slug]) ? $checks[$slug] : null;
                            if (!$res) continue;
                            $cscore = isset($res['score']) ? (int)$res['score'] : 0;
                            $meta   = isset($res['meta']) ? $res['meta'] : array();
                            $label  = isset($label_map[$slug]) ? $label_map[$slug] : ucfirst(str_replace('_',' ', $slug));
                            $gradeG = Nfinite_Audit_V1::grade_from_score($cscore);

                            $hint = '';
                            if (is_array($meta)) {
                                if ($slug==='compression') { $hint = 'Encoding: '.esc_html(isset($meta['encoding'])?$meta['encoding']:'n/a'); }
                                elseif ($slug==='client_cache') { $hint = 'Cache-Control: '.esc_html(isset($meta['cache_control'])?$meta['cache_control']:'n/a'); }
                                elseif ($slug==='assets_counts') { $hint = 'CSS: '.(int)(isset($meta['css'])?$meta['css']:0).', JS: '.(int)(isset($meta['js'])?$meta['js']:0); }
                                elseif ($slug==='render_blocking') { $hint = 'Blocking CSS: '.(int)(isset($meta['blocking_css'])?$meta['blocking_css']:'0').', Blocking JS: '.(int)(isset($meta['blocking_js'])?$meta['blocking_js']:'0'); }
                                elseif ($slug==='images_dims_and_size') { $hint = 'Images: '.(int)(isset($meta['total'])?$meta['total']:'0').', Missing dims: '.(int)(isset($meta['missing_dims'])?$meta['missing_dims']:'0').', Next-gen: '.(int)(isset($meta['nextgen'])?$meta['nextgen']:'0'); }
                                elseif ($slug==='ttfb') { $hint = 'Measured TTFB: '.(int)(isset($meta['ttfb_ms'])?$meta['ttfb_ms']:'0').'ms'; }
                                elseif ($slug==='h2_h3') { $hint = 'ALPN: '.esc_html(isset($meta['alpn'])?$meta['alpn']:'n/a'); }
                                elseif ($slug==='autoload_size') { $kb = round( (int)(isset($meta['bytes'])?$meta['bytes']:'0') / 1024 ); $hint = 'Autoload size: '.$kb.' KB'; }
                                elseif ($slug==='postmeta_bloat') { $hint = 'Avg meta per post (last 20): '.esc_html(isset($meta['avg_meta'])?$meta['avg_meta']:'n/a'); }
                                elseif ($slug==='transients') { $hint = 'Expired transients: '.(int)(isset($meta['expired'])?$meta['expired']:'0'); }
                                elseif ($slug==='updates_plugins' || $slug==='updates_themes') { $hint = 'Updates available: '.(int)(isset($meta['count'])?$meta['count']:'0'); }
                                elseif ($slug==='cache_present') { $hint = 'Cache detected: '.(!empty($meta['cached'])?'yes':'no').( !empty($meta['plugin']) ? ' — '.$meta['plugin'] : '' ); }
                            }
                        ?>
                        <div class="nfinite-check">
                          <div class="head">
                            <span class="label"><?php echo esc_html($label); ?></span>
                            <span class="nfinite-chip"><?php echo (int)$cscore; ?></span>
                            <span class="nfinite-badge <?php echo esc_attr($gradeG); ?>"><?php echo esc_html($gradeG); ?></span>
                          </div>
                          <?php if ($hint) echo '<div class="nfinite-hint">'.$hint.'</div>'; ?>
                        </div>
                        <?php endforeach; ?>

                        <?php
                        if ( function_exists('nfinite_recommendations_registry') ) {
                            $recs_map = nfinite_recommendations_registry();
                            $need = array();
                            foreach ($check_slugs as $slug) {
                                $resx = isset($checks[$slug]) ? $checks[$slug] : null;
                                if (!$resx) continue;
                                $cscorex = isset($resx['score']) ? (int)$resx['score'] : 0;
                                if ($cscorex >= 100) continue;
                                if (isset($recs_map[$slug])) {
                                    $item = $recs_map[$slug];
                                    $item['slug']  = $slug;
                                    $item['score'] = $cscorex;
                                    $need[] = $item;
                                }
                            }
                            if ($need) {
                                usort($need, function($a,$b){
                                    $pri = array('high'=>0,'medium'=>1,'low'=>2);
                                    $sa = $pri[$a['severity']] ?? 3;
                                    $sb = $pri[$b['severity']] ?? 3;
                                    if ($sa === $sb) {
                                        if ($a['score'] === $b['score']) return 0;
                                        return ($a['score'] < $b['score']) ? -1 : 1;
                                    }
                                    return ($sa < $sb) ? -1 : 1;
                                });
                                $top = $need[0];
                                echo '<div class="nfinite-reco"><strong>Recommended next step:</strong> ' . esc_html($top['title']) . ' — ' . esc_html($top['message']) . ' <a href="' . esc_url($top['docs']) . '" target="_blank" rel="noopener">Guide</a></div>';
                            }
                        }
                        ?>
                      </div>
                    </details>
                <?php endforeach; ?>
            </div>
          </section>

          <?php if ( $last && isset($last['timestamp']) ) : ?>
            <p style="margin-top:14px;color:#6b7280">Last run: <?php echo esc_html($last['timestamp']); ?> • URL: <?php echo esc_html($last['url']); ?></p>
          <?php endif; ?>

        </div>
        <?php
    }

    /**
     * NEW: Site Health page
     */
    public static function render_health_page() {
    if ( ! current_user_can('manage_options') ) return;

    if ( ! function_exists('nfinite_get_site_health_digest') ) {
    $digest_file = dirname(__DIR__) . '/includes/site-health-digest.php';
    if ( file_exists($digest_file) ) require_once $digest_file;
}

    // Handle refresh via POST or GET
    $did_refresh_digest = false;
    if (
        ( isset($_POST['nfinite_health_action']) && 'refresh' === $_POST['nfinite_health_action'] && check_admin_referer('nfinite_refresh_health') )
        ||
        ( isset($_GET['nfinite_health_action'], $_GET['_wpnonce']) && 'refresh' === $_GET['nfinite_health_action'] && wp_verify_nonce($_GET['_wpnonce'], 'nfinite_refresh_health') )
    ) {
        // clear cache key(s) used by the helper
        delete_transient('nfinite_site_health_digest_with_async');
        delete_transient('nfinite_site_health_digest_direct_only');
        delete_transient('nfinite_site_health_digest');     // legacy
        delete_transient('nfinite_site_health_digest_v2');  // legacy

        $did_refresh_digest = true;
    }

    // Build/refresh digest now (force on refresh; otherwise use cache)
    $digest = function_exists('nfinite_get_site_health_digest')
    ? nfinite_get_site_health_digest( $did_refresh_digest, true ) // <-- include async
    : array('error' => __('Site Health helper not loaded.', 'nfinite-audit'));

    // Partition items by status
    $items = isset($digest['items']) && is_array($digest['items']) ? $digest['items'] : array();
    $crit  = array_values( array_filter( $items, function($it){ return isset($it['status']) && $it['status']==='critical'; }) );
    $reco  = array_values( array_filter( $items, function($it){ return isset($it['status']) && $it['status']==='recommended'; }) );
    $good  = array_values( array_filter( $items, function($it){ return isset($it['status']) && $it['status']==='good'; }) );

    // Small renderer for a group/card
    $render_group = function( $title, $count, $badge_class, $rows, $empty_msg ) {
        ?>
        <div class="nfinite-card">
          <div class="nfinite-card__header">
            <h2 class="nfinite-h" style="margin:0"><?php echo esc_html($title); ?></h2>
            <span class="nfinite-badge <?php echo esc_attr($badge_class); ?>"><?php echo (int) $count; ?></span>
          </div>
          <div class="nfinite-list">
            <?php if (empty($rows)): ?>
              <div class="nfinite-list__item">
                <div class="nfinite-detail"><em><?php echo esc_html($empty_msg); ?></em></div>
              </div>
            <?php else: foreach ($rows as $it): ?>
              <div class="nfinite-list__item">
                <span class="<?php echo esc_attr( nfinite_health_status_class($it['status']) ); ?>">
                  <?php echo esc_html( ucfirst($it['status']) ); ?>
                </span>
                <strong style="margin-left:8px;"><?php echo esc_html( $it['label'] ); ?></strong>
                <?php if ( ! empty($it['badge']) ) : ?>
                  <span class="nfinite-badge nfinite-badge-muted" style="margin-left:8px;"><?php echo esc_html( $it['badge'] ); ?></span>
                <?php endif; ?>

                <?php if ( ! empty($it['description']) ) : ?>
                  <div class="nfinite-detail"><?php echo $it['description']; ?></div>
                <?php endif; ?>

                <?php if ( ! empty($it['actions']) ) : ?>
                  <div class="nfinite-detail"><?php echo $it['actions']; ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <?php
    };
    ?>
    <div class="wrap nfinite-wrap">
      <h1>Site Health · Nfinite Audit</h1>

      <p class="nfinite-help" style="margin-top:8px">
        <a class="button" href="<?php echo esc_url( admin_url('admin.php?page=nfinite-audit') ); ?>">← Back to Dashboard</a>
        <a class="button" href="#critical">Jump to Critical</a>
        <a class="button" href="#recommended">Jump to Recommended</a>
        <a class="button" href="#passed">Jump to Passed</a>
      </p>

      <?php if ( $did_refresh_digest ) : ?>
        <div class="notice notice-success is-dismissible"><p>Site Health rechecked just now.</p></div>
      <?php endif; ?>

      <?php if ( isset($digest['error']) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($digest['error']); ?></p></div>
      <?php else : ?>
        <section class="nfinite-section">
          <div class="nfinite-cards" style="grid-template-columns:1fr">
            <!-- Controls -->
            <div class="nfinite-card">
              <div class="nfinite-card__header">
                <h2 class="nfinite-h" style="margin:0">Controls</h2>
                <div style="display:flex;align-items:center;gap:10px">
                  <!-- POST button (primary) -->
                  <form method="post" action="<?php echo esc_url( admin_url('admin.php?page=nfinite-audit-health') ); ?>" style="margin:0">
                    <?php wp_nonce_field('nfinite_refresh_health'); ?>
                    <input type="hidden" name="nfinite_health_action" value="refresh">
                    <button class="button" type="submit" name="nfinite_health_submit" value="1">Refresh</button>
                  </form>
                  <!-- GET fallback (if POST blocked by security plugins) -->
                  <?php
                    $refresh_url = wp_nonce_url(
                      add_query_arg(array('page'=>'nfinite-audit-health','nfinite_health_action'=>'refresh'), admin_url('admin.php')),
                      'nfinite_refresh_health'
                    );
                  ?>
                  <a class="button" href="<?php echo esc_url($refresh_url); ?>">Refresh (alt)</a>
                  <span class="description">Last checked: <?php echo esc_html( $digest['refreshed'] ); ?></span>
                </div>
              </div>
              <div class="nfinite-detail">
                <p class="nfinite-help" style="margin:8px 0 0">
                  We group Site Health into <strong>Critical issues</strong>, <strong>Recommended improvements</strong>, and <strong>Passed checks</strong>.
                </p>
              </div>
            </div>

            <!-- Critical -->
            <a id="critical"></a>
            <?php $render_group('Critical issues', count($crit), 'nfinite-badge-danger', $crit, 'No critical issues found. 🎉'); ?>

            <!-- Recommended -->
            <a id="recommended"></a>
            <?php $render_group('Recommended improvements', count($reco), 'nfinite-badge-warn', $reco, 'No recommended improvements at the moment.'); ?>

            <!-- Passed -->
            <a id="passed"></a>
            <?php $render_group('Passed checks', count($good), 'nfinite-badge-good', $good, 'No checks are currently marked as passed.'); ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
    <?php
}

    /**
     * Settings Page
     */
    public static function render_settings_page() {
        if ( ! current_user_can('manage_options') ) return;

        ?>
        <div class="wrap nfinite-wrap">
          <h1>Nfinite Audit · Settings</h1>

          <form method="post" action="options.php">
            <?php
            settings_fields('nfinite_audit_group');
            do_settings_sections('nfinite-audit-settings');
            submit_button('Save Settings');
            ?>
          </form>

          <p class="nfinite-help">
            Need help? Your API key can be created in the
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>.
          </p>
        </div>
        <?php
    }

    /**
     * Manual run handler (fallback when not using the on-page AJAX tester)
     */
    public static function handle_run_audit() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('nfinite_run_audit');

        $url = isset($_POST['nfinite_test_url_run']) ? esc_url_raw($_POST['nfinite_test_url_run']) : home_url('/');
        if ( empty($url) ) $url = home_url('/');

        update_option('nfinite_test_url', $url);

        $api   = get_option('nfinite_psi_api_key','');
        $proxy = get_option('nfinite_proxy_url','');

        $internal = Nfinite_Audit_V1::run_internal_audit($url);

        $psi         = nfinite_fetch_psi($url, $api, $proxy);
        $psi_ok      = isset($psi['ok']) ? $psi['ok'] : false;
        $psi_err     = isset($psi['error']) ? $psi['error'] : '';
        $psi_scores  = isset($psi['scores']) ? $psi['scores'] : array();
        $web_vitals  = isset($psi['web_vitals'])  ? $psi['web_vitals']  : null;
        $lab_metrics = isset($psi['lab_metrics']) ? $psi['lab_metrics'] : array();
        $lab_overall = isset($psi['lab_overall']) ? $psi['lab_overall'] : null;
        $vitals_src  = isset($psi['vitals_source']) ? $psi['vitals_source'] : 'none';
        $finalUrl    = isset($psi['finalUrl']) ? $psi['finalUrl'] : $url;

        if ( ! $psi_ok ) {
            $psi_scores   = nfinite_estimate_lighthouse($internal);
            $web_vitals   = null;
            $lab_metrics  = array();
            $lab_overall  = null;
            $vitals_src   = 'none';
        }

        $parts = array();
        if ( isset($internal['overall']) )               $parts[] = (int)$internal['overall'];
        if ( isset($psi_scores['performance']) )         $parts[] = (int)$psi_scores['performance'];
        if ( isset($psi_scores['best_practices']) )      $parts[] = (int)$psi_scores['best_practices'];
        if ( isset($psi_scores['seo']) )                 $parts[] = (int)$psi_scores['seo'];
        if ( !is_null($web_vitals) && ($vitals_src==='lab' || $vitals_src==='field') ) $parts[] = (int)$web_vitals;

        $overall = $parts ? (int) round(array_sum($parts) / count($parts)) : 0;
        $grade   = Nfinite_Audit_V1::grade_from_score($overall);

        $payload = array(
            'timestamp'     => current_time('Y-m-d H:i:s'),
            'url'           => $url,
            'finalUrl'      => $finalUrl,
            'psi_ok'        => $psi_ok,
            'psi_error'     => $psi_err,
            'psi_scores'    => $psi_scores,
            'web_vitals'    => $web_vitals,
            'lab_metrics'   => $lab_metrics,
            'lab_overall'   => $lab_overall,
            'vitals_source' => $vitals_src,
            'internal'      => $internal,
            'overall'       => $overall,
            'grade'         => $grade,
        );

        update_option('nfinite_audit_last', $payload, false);
        wp_redirect( add_query_arg(array('page'=>'nfinite-audit','nfinite_done'=>'1'), admin_url('admin.php')) );
        exit;
    }
}
