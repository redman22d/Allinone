<?php
/**
 * Tutor Learning Suite uninstall.
 *
 * Data is kept by default so reinstalling never loses bookings, payment
 * records or ad statistics. Tick "Delete all Tutor Learning Suite data when
 * the plugin is deleted" (option tls_delete_data_on_uninstall) to wipe it.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

wp_clear_scheduled_hook( 'tcsp_process_scheduled_sponsorships' );
wp_clear_scheduled_hook( 'ostudy_ads_daily_orders' );
wp_clear_scheduled_hook( 'tlms_ps_maintenance' );

if ( ! get_option( 'tls_delete_data_on_uninstall' ) ) { return; }

global $wpdb;

// Custom tables.
foreach ( array( 'tlms_private_sessions', 'tlms_private_availability', 'tls_age_safety_audit', 'tioc_audit', 'ostudy_ads', 'ostudy_ads_stats', 'ostudy_ads_orders' ) as $tls_table ) {
    $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $tls_table ) . '`' ); // phpcs:ignore WordPress.DB
}

// Options, transients and user meta created by the suite's modules.
foreach ( array( 'tls\_%', 'tcsp\_%', 'tioc\_%', 'tcf\_%', 'tlms\_%', 'ostudy\_ads\_%', '\_transient\_tcsp\_%', '\_transient\_tioc\_%', '\_transient\_tls\_%' ) as $tls_like ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $tls_like ) ); // phpcs:ignore WordPress.DB
}
foreach ( array( '\_tls\_%', 'tls\_%', '\_tcsp\_%', 'tcsp\_%', '\_tioc\_%', 'tioc\_%', '\_tlms\_%', 'tlms\_%', '\_tcf\_%' ) as $tls_like ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $tls_like ) ); // phpcs:ignore WordPress.DB
}

// Sponsorship records.
foreach ( (array) get_posts( array( 'post_type' => 'tcsp_sponsor_order', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $tls_id ) {
    wp_delete_post( (int) $tls_id, true );
}
