<?php
/**
 * Uninstall handler for Nfinite Site Audit
 *
 * Runs only when the plugin is deleted from the WordPress Plugins screen.
 * Safety first: we only purge data if the user explicitly enabled
 * the "Remove all plugin data on uninstall" setting.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Names used by the plugin. Adjust/extend as your schema grows.
 * Tip: keep this list centralized to avoid missing anything.
 */
$option_keys = array(
    // Core/meta
    'nfinite_audit_version',
    'nfinite_audit_options',                 // general settings array
    'nfinite_audit_purge_on_uninstall',      // boolean toggle

    // PSI / scores / cached results
    'nfinite_psi_api_key',
    'nfinite_audit_last_results',
    'nfinite_audit_last_psi_response',
    'nfinite_audit_web_vitals',
    'nfinite_audit_seo_results',
    'nfinite_audit_cache_layers',
    'nfinite_audit_dashboard_state',         // UI prefs if you added any
);

$cron_hooks = array(
    'nfinite_audit_run_scheduled',           // scheduled audits (pro later)
    'nfinite_audit_refresh_scores',
);

/**
 * Read the purge toggle in both single-site & network contexts.
 * If the toggle is false (or missing), we keep data.
 */
$is_multisite = is_multisite();

$should_purge = false;
if ( $is_multisite ) {
    // Prefer network setting if you saved it network-wide, otherwise fall back.
    $network_toggle = get_site_option( 'nfinite_audit_purge_on_uninstall', null );
    if ( is_null( $network_toggle ) ) {
        // Fallback to per-site option of the main site
        $should_purge = (bool) get_option( 'nfinite_audit_purge_on_uninstall', false );
    } else {
        $should_purge = (bool) $network_toggle;
    }
} else {
    $should_purge = (bool) get_option( 'nfinite_audit_purge_on_uninstall', false );
}

if ( ! $should_purge ) {
    // Remove only volatile pieces that should never persist after uninstall,
    // like cron events and transients, but keep saved settings/results.
    nfinite_audit_clear_cron_events( $cron_hooks );
    nfinite_audit_delete_transients_like( 'nfinite_%' );
    return;
}

/**
 * Full purge path
 * – Deletes options (site & network)
 * – Drops any custom tables (if present)
 * – Clears transients and cron
 * – Optionally deletes custom posts/terms if you ever add them
 */

if ( $is_multisite ) {
    // Network-wide cleanup across all blogs
    $blog_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        nfinite_audit_delete_options( $option_keys );
        nfinite_audit_clear_cron_events( $cron_hooks );
        nfinite_audit_delete_transients_like( 'nfinite_%' );
        // If you add CPTs later, call the deleter here (see helper below).
        restore_current_blog();
    }
    // Network-level options
    foreach ( $option_keys as $key ) {
        delete_site_option( $key );
    }
} else {
    // Single site cleanup
    nfinite_audit_delete_options( $option_keys );
    nfinite_audit_clear_cron_events( $cron_hooks );
    nfinite_audit_delete_transients_like( 'nfinite_%' );
}

// Drop custom tables if you add them (guarded: only if they exist)
nfinite_audit_drop_custom_tables();

/* ---------------- Helper Functions (defined inline for uninstall scope) ---------------- */

function nfinite_audit_delete_options( array $keys ) {
    foreach ( $keys as $key ) {
        delete_option( $key );
    }
}

function nfinite_audit_clear_cron_events( array $hooks ) {
    if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
        return;
    }
    foreach ( $hooks as $hook ) {
        // Clear all scheduled instances of this hook
        while ( $timestamp = wp_next_scheduled( $hook ) ) {
            wp_unschedule_event( $timestamp, $hook );
        }
        wp_clear_scheduled_hook( $hook );
    }
}

/**
 * Delete all transients that match a pattern.
 * Uses a direct query for performance & completeness.
 */
function nfinite_audit_delete_transients_like( $like ) {
    global $wpdb;

    // Standard and timeout rows
    $options_table = $wpdb->options;
    $like_escaped  = esc_sql( $like );

    // Delete _transient_* and _site_transient_* rows
    $wpdb->query( "DELETE FROM {$options_table} WHERE option_name LIKE '_transient_{$like_escaped}'" );
    $wpdb->query( "DELETE FROM {$options_table} WHERE option_name LIKE '_transient_timeout_{$like_escaped}'" );
    $wpdb->query( "DELETE FROM {$options_table} WHERE option_name LIKE '_site_transient_{$like_escaped}'" );
    $wpdb->query( "DELETE FROM {$options_table} WHERE option_name LIKE '_site_transient_timeout_{$like_escaped}'" );
}

/**
 * If/when you add custom DB tables, drop them here safely.
 * Example names shown; adjust to your actual table names.
 */
function nfinite_audit_drop_custom_tables() {
    global $wpdb;
    $tables = array(
        // "{$wpdb->prefix}nfinite_audit_history",
        // "{$wpdb->prefix}nfinite_audit_logs",
    );
    foreach ( $tables as $table ) {
        // Only drop if it exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s", $table
        ) );
        if ( $exists === $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
    }
}

/**
 * If you ever store audit reports as a CPT, you can purge them here.
 * Keep commented until you introduce the CPT to avoid accidental deletions.
 */
// function nfinite_audit_delete_cpt_content() {
//     $post_type = 'nfinite_audit_report';
//     $ids = get_posts(array(
//         'post_type'      => $post_type,
//         'fields'         => 'ids',
//         'posts_per_page' => -1,
//         'post_status'    => 'any',
//         'no_found_rows'  => true,
//     ));
//     foreach ( $ids as $id ) {
//         wp_delete_post( $id, true ); // force delete
//     }
// }
