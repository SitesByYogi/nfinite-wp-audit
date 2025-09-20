<?php
/*
Plugin Name: Nfinite Site Audit
Description: Lightweight site audit plugin with optional PageSpeed Insights integration.
Version: 0.4.2
Author: Nfinite
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define('NFINITE_AUDIT_VER', '0.4.2');
define('NFINITE_AUDIT_PATH', plugin_dir_path(__FILE__));
define('NFINITE_AUDIT_URL', plugin_dir_url(__FILE__));

// Order: core, PSI, fallback, admin
require_once NFINITE_AUDIT_PATH . 'includes/class-audit-v1.php';
require_once NFINITE_AUDIT_PATH . 'includes/psi.php';
require_once NFINITE_AUDIT_PATH . 'includes/fallback-scores.php';
require_once NFINITE_AUDIT_PATH . 'includes/class-cache-layers-scanner.php';
require_once NFINITE_AUDIT_PATH . 'admin/class-admin.php';

// Boot the admin UI
if ( is_admin() && class_exists('Nfinite_Audit_Admin') ) {
    Nfinite_Audit_Admin::init();
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $links[] = '<a href="' . esc_url( admin_url('admin.php?page=nfinite-audit-settings') ) . '">Settings</a>';
    return $links;
});


// ---- Caching Layers: Admin Notice + Site Health ----
add_action('admin_notices', function () {
    if ( ! current_user_can('manage_options') ) return;
    if ( ! function_exists('get_current_screen') ) return;
    $screen = get_current_screen();
    if ( $screen && strpos($screen->id, 'nfinite-audit') === false && $screen->id !== 'dashboard' ) return;

    if ( ! class_exists('Nfinite_Cache_Layers_Scanner') ) return;
    $scan = Nfinite_Cache_Layers_Scanner::scan();
    if ( empty($scan['risks']) ) return;

    echo '<div class="notice notice-warning"><p><strong>Nfinite Site Audit:</strong> Potential caching conflicts detected.</p><ul style="margin-left:18px;">';
    foreach ($scan['risks'] as $risk) {
        echo '<li>' . esc_html($risk) . '</li>';
    }
    echo '</ul><p><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=nfinite-audit-caching')).'">View Caching Layers report</a></p></div>';
});

add_filter('site_status_tests', function ($tests) {
    $tests['direct']['nfinite_cache_layers'] = [
        'label' => __('Caching Layers Configuration', 'nfinite-audit'),
        'test'  => 'nfinite_site_audit_cache_layers_test_cb',
    ];
    return $tests;
});

function nfinite_site_audit_cache_layers_test_cb() {
    if ( ! class_exists('Nfinite_Cache_Layers_Scanner') ) {
        return [
            'label'       => __('Caching layers review', 'nfinite-audit'),
            'status'      => 'recommended',
            'badge'       => ['label' => __('Performance', 'nfinite-audit'), 'color' => 'blue'],
            'description' => wp_kses_post('<p>Scanner not available.</p>'),
            'test'        => 'nfinite_cache_layers',
        ];
    }
    $scan   = Nfinite_Cache_Layers_Scanner::scan();
    $status = empty($scan['risks']) ? 'good' : 'recommended';

    $desc  = '<p>' . esc_html__('We inspected page cache plugins, CDN and server cache headers.') . '</p>';
    if ( empty($scan['risks']) ) {
        $desc .= '<p>' . esc_html__('No conflicts detected.') . '</p>';
    } else {
        $desc .= '<p><strong>' . esc_html__('Risks:') . '</strong></p><ul>';
        foreach ($scan['risks'] as $risk) {
            $desc .= '<li>' . esc_html($risk) . '</li>';
        }
        $desc .= '</ul>';
    }

    return [
        'label'       => __('Caching layers review', 'nfinite-audit'),
        'status'      => $status,
        'badge'       => ['label' => __('Performance', 'nfinite-audit'), 'color' => 'blue'],
        'description' => wp_kses_post($desc),
        'actions'     => [
            sprintf('<a href="%s" class="button button-secondary">%s</a>', esc_url(admin_url('admin.php?page=nfinite-audit-caching')), esc_html__('View report', 'nfinite-audit'))
        ],
        'test'        => 'nfinite_cache_layers',
    ];
}
// ---- /Caching Layers ----


// ==== Nfinite Audit → Caching Layers (standalone callback, avoids Admin class dependency) ====
add_action('admin_menu', function () {
    // only register if top-level Nfinite Audit menu exists; otherwise bail silently
    if ( ! function_exists('add_submenu_page') ) return;
    // Fallback: always add; WP will show it under top-level if present
    add_submenu_page(
        'nfinite-audit',
        'Caching Layers · Nfinite Audit',
        'Caching Layers',
        'manage_options',
        'nfinite-audit-caching',
        'nfinite_render_caching_layers_page'
    );
});

function nfinite_render_caching_layers_page() {
    if ( ! current_user_can('manage_options') ) return;
    if ( ! class_exists('Nfinite_Cache_Layers_Scanner') ) {
        echo '<div class="wrap"><h1>Caching Layers</h1><p>Scanner not available.</p></div>';
        return;
    }
    $scan = Nfinite_Cache_Layers_Scanner::scan();

    $pill = function($text, $type = 'default') {
        $colors = [
            'default' => 'background:#eef2f7;color:#111;padding:3px 8px;border-radius:999px;display:inline-block;margin-right:6px;',
            'warn'    => 'background:#fff4e5;color:#8a4b00;',
            'ok'      => 'background:#e9f9ee;color:#0b6b2d;',
        ];
        $style = ($colors[$type] ?? $colors['default']) . 'font-weight:600;font-size:12px;';
        return '<span style="'.esc_attr($style).'">'.esc_html($text).'</span>';
    };

    echo '<div class="wrap">';
    echo '<h1 style="margin-bottom:12px;">Caching Layers</h1>';
    echo empty($scan['risks']) ? $pill('No conflicts detected', 'ok') : $pill('Conflicts detected', 'warn');

    echo '<div class="card" style="margin-top:16px;">';
    echo '<h2>Detections</h2>';

    echo '<h3>Active Page Cache Plugins</h3><p>';
    if ( ! empty($scan['active_page_cache_plugins']) ) {
        foreach ($scan['active_page_cache_plugins'] as $label) echo $pill($label);
    } else { echo 'None detected.'; }
    echo '</p>';

    echo '<h3>CDN</h3><p>';
    if ( ! empty($scan['cdn']) ) { foreach ($scan['cdn'] as $cdn) echo $pill($cdn); }
    else { echo 'None detected.'; }
    echo '</p>';

    echo '<h3>Server/Host Cache</h3><p>';
    if ( ! empty($scan['server_cache']) ) { foreach ($scan['server_cache'] as $srv) echo $pill($srv); }
    else { echo 'None detected.'; }
    echo '</p>';

    echo '<h3>Drop-ins</h3><p>';
    if ( ! empty($scan['dropins']) ) { foreach ($scan['dropins'] as $d) echo $pill($d); }
    else { echo 'None detected.'; }
    echo '</p>';

    if ( ! empty($scan['headers']) ) {
        echo '<h3>Response Header Highlights</h3><pre style="max-height:240px;overflow:auto;background:#f6f8fa;padding:12px;border-radius:6px;">';
        $keys = ['cf-cache-status','x-cache','x-cache-status','x-nginx-cache','x-nginx-cache-status','x-proxy-cache','x-varnish','age','x-fastcgi-cache','x-srcache-store-status','x-srcache-fetch-status','x-accel-expires','x-litespeed-cache','server'];
        foreach ($keys as $k) {
            $kl = strtolower($k);
            if ( isset($scan['headers'][$kl]) ) {
                $val = is_array($scan['headers'][$kl]) ? json_encode($scan['headers'][$kl]) : $scan['headers'][$kl];
                printf("%s: %s\n", $k, $val);
            }
        }
        echo '</pre>';
    }

    echo '<h2 style="margin-top:18px;">Risks</h2>';
    if ( empty($scan['risks']) ) { echo '<p>None detected.</p>'; }
    else { echo '<ul style="list-style:disc;margin-left:18px;">'; foreach ($scan['risks'] as $r) echo '<li>'.esc_html($r).'</li>'; echo '</ul>'; }

    echo '<h2 style="margin-top:18px;">Recommendations</h2>';
    if ( empty($scan['recommendations']) ) { echo '<p>Looks good. No changes recommended.</p>'; }
    else { echo '<ol style="margin-left:18px;">'; foreach ($scan['recommendations'] as $rec) echo '<li>'.wp_kses_post($rec).'</li>'; echo '</ol>'; }

    echo '<p style="margin-top:18px;"><em>Tip:</em> After any caching configuration changes, purge all layers in order: <strong>plugin → CDN → server</strong>. For Cloudflare, consider using <strong>Development Mode</strong> while editing, then disable and purge when finished.</p>';

    echo '</div></div>';
}
// ==== /Caching Layers ====


// ==== Nfinite Audit → Settings (submenu restored) ====
add_action('admin_menu', function () {
    if ( ! function_exists('add_submenu_page') ) return;
    add_submenu_page(
        'nfinite-audit',
        'Settings · Nfinite Audit',
        'Settings',
        'manage_options',
        'nfinite-audit-settings',
        'nfinite_render_settings_page'
    );
}, 40);

function nfinite_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    if ( class_exists('Nfinite_Audit_Admin') ) {
        foreach ( ['render_settings_page','settings_page','render_settings','settings'] as $m ) {
            if ( method_exists('Nfinite_Audit_Admin', $m) ) {
                return call_user_func(['Nfinite_Audit_Admin', $m]);
            }
        }
    }

    echo '<div class="wrap"><h1>Nfinite Audit Settings</h1>';
    if ( function_exists('settings_fields') && function_exists('do_settings_sections') ) {
        echo '<form method="post" action="options.php">';
        @settings_fields('nfinite_audit_options');
        @settings_fields('nfinite_audit');
        @do_settings_sections('nfinite-audit-settings');
        @do_settings_sections('nfinite-audit');
        submit_button();
        echo '</form>';
    } else {
        echo '<p>No settings form was found. The Settings API may not be registered.</p>';
    }
    echo '</div>';
}
// ==== /Settings ====



// ==== Nfinite Audit → submenu de-duplication (defensive) ====
add_action('admin_menu', 'nfinite_dedupe_nfinite_audit_submenus', 999);
function nfinite_dedupe_nfinite_audit_submenus() {
    global $submenu;
    if ( ! isset($submenu['nfinite-audit']) || ! is_array($submenu['nfinite-audit']) ) return;

    $preferred = [
        'nfinite-audit-settings' => true,
        'nfinite-audit-caching'  => true,
    ];
    $seen = [];
    $clean = [];
    foreach ( $submenu['nfinite-audit'] as $item ) {
        // $item = [ page_title, capability, menu_slug, menu_title ]
        $slug  = isset($item[2]) ? $item[2] : '';
        $title = isset($item[3]) ? strtolower($item[3]) : (isset($item[0]) ? strtolower($item[0]) : '');

        if ( isset($preferred[$slug]) ) {
            if ( isset($seen[$slug]) ) continue; // drop duplicates of our preferred slugs
            $seen[$slug] = true;
            $clean[] = $item;
            continue;
        }

        // If the title looks like Settings or Caching/Cache Layers but slug is not our preferred one,
        // keep only the first occurrence and drop the rest.
        if ( in_array($title, ['settings', 'caching layers', 'cache layers'], true) ) {
            // Normalize to a title-key group
            $key = $title;
            if ( isset($seen[$key]) ) continue;
            $seen[$key] = true;
            // Prefer to replace its slug with our preferred one if possible? No: just keep this single legacy item.
            $clean[] = $item;
            continue;
        }

        // Otherwise, keep untouched (e.g., Dashboard or any other custom pages)
        $clean[] = $item;
    }
    $submenu['nfinite-audit'] = $clean;
}
// ==== /submenu de-duplication ====
