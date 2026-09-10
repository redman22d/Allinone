<?php
/**
 * Uninstall cleanup.
 *
 * @package TranslatePress_Export_Import
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove our transients and version option. TranslatePress data is never touched.
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_tpei_%' OR `option_name` LIKE '_transient_timeout_tpei_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'tpei_version' );
