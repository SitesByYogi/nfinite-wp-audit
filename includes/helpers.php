<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get the default placeholder URL for "Run Test" inputs.
 * Prefers saved option 'nfinite_test_url', else falls back to home_url('/').
 * Filterable via 'nfinite_default_test_url'.
 */
function nfinite_get_default_test_placeholder() {
    $saved = trim( (string) get_option( 'nfinite_test_url', '' ) );
    $url   = $saved !== '' ? $saved : home_url( '/' );

    /**
     * Filter: allow devs/sites to override the default placeholder.
     *
     * @param string $url The placeholder URL.
     */
    $url = apply_filters( 'nfinite_default_test_url', $url );

    // Escaping for attribute usage happens at render-time.
    return $url;
}
