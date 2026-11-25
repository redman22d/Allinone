<?php
/**
 * Tutor Learning Suite uninstall.
 *
 * Data is kept by default so reinstalling never loses bookings, payment
 * records or ad statistics. Tick "Delete all Tutor Learning Suite data when
 * the plugin is deleted" (option tls_delete_data_on_uninstall) to wipe it.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Keep in sync with tls_cron_hooks() in tutor-learning-suite.php (the main file
// is not loaded during uninstall, so the list is repeated here).
foreach ( array(
    'tls_age_safety_daily_sync',
    'tlms_ps_maintenance',
    'ostudy_ads_daily_orders',
    'tcsp_process_scheduled_sponsorships',
    'tioc_expire_overdue_orders',
    'tioc_send_deadline_reminders',
    'tioc_purge_expired_receipts',
) as $tls_hook ) {
    wp_clear_scheduled_hook( $tls_hook );
}

if ( ! get_option( 'tls_delete_data_on_uninstall' ) ) { return; }

global $wpdb;

// Custom tables.
foreach ( array( 'tlms_private_sessions', 'tlms_private_availability', 'tls_age_safety_audit', 'tioc_audit', 'ostudy_ads', 'ostudy_ads_stats', 'ostudy_ads_orders' ) as $tls_table ) {
    $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $tls_table ) . '`' ); // phpcs:ignore WordPress.DB
}

// Options, transients and user meta created by the suite's modules.
foreach ( array(
    'tls\_%', 'tcsp\_%', 'tioc\_%', 'tcf\_%', 'tlms\_%', 'ostudy\_ads\_%',
    // Transients and their matching *_timeout_ rows (rate-limit buckets, filter-count caches, ad-order limits).
    '\_transient\_tcsp\_%', '\_transient\_timeout\_tcsp\_%',
    '\_transient\_tioc\_%', '\_transient\_timeout\_tioc\_%',
    '\_transient\_tls\_%', '\_transient\_timeout\_tls\_%',
    '\_transient\_tlms\_%', '\_transient\_timeout\_tlms\_%',
    '\_transient\_ostudy\_%', '\_transient\_timeout\_ostudy\_%',
) as $tls_like ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $tls_like ) ); // phpcs:ignore WordPress.DB
}
foreach ( array( '\_tls\_%', 'tls\_%', '\_tcsp\_%', 'tcsp\_%', '\_tioc\_%', 'tioc\_%', '\_tlms\_%', 'tlms\_%', '\_tcf\_%' ) as $tls_like ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $tls_like ) ); // phpcs:ignore WordPress.DB
}

// Sponsorship records.
foreach ( (array) get_posts( array( 'post_type' => 'tcsp_sponsor_order', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $tls_id ) {
    wp_delete_post( (int) $tls_id, true );
}
