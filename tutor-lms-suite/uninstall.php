<?php
/**
 * Uninstall routine for Tutor LMS Suite.
 *
 * Cleans up plugin-owned settings, per-instructor/per-user data, and
 * plugin-created DB tables for all five merged modules, runs on every site
 * in a multisite network, and follows one consistent rule throughout:
 *
 *   Only ever delete data this suite created for its own bookkeeping.
 *   Never delete data that represents something that really happened
 *   (an order, a payment, an enrolment, a course a student can see).
 *
 * Concretely, this means the following are always left alone:
 *
 * - Offline Payment orders and their `_tioc_*` order meta (real sales
 *   history; Tutor removes the meta itself when an order is deleted).
 * - Private Sessions' generated Tutor course posts and any Tutor cart/order
 *   data tied to them (real bookings, not this plugin's own table).
 * - Course Search Pro's `_tcsp_promotion_*` course meta (a sponsorship an
 *   instructor paid for — business data, not disposable plugin config).
 *
 * @package TutorLmsSuite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Recursively delete a directory this plugin created.
 *
 * @param string $dir Absolute path.
 *
 * @return void
 */
function tls_suite_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );

	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $entry;

		if ( is_dir( $path ) ) {
			tls_suite_uninstall_rmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir );
}

/**
 * Delete every usermeta row whose key starts with a given prefix, across
 * all users. Used instead of one delete_metadata() call per key so adding
 * a new `_tlms_ipe_*` field in the future doesn't also require touching
 * this file.
 *
 * @param string $prefix Meta key prefix, e.g. '_tlms_ipe_'.
 *
 * @return void
 */
function tls_suite_delete_usermeta_by_prefix( $prefix ) {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Instructor Offline Payment cleanup.
 * (Unchanged from this module's original standalone uninstall.php.)
 *
 * @return void
 */
function tls_suite_uninstall_offline_payment() {
	global $wpdb;

	delete_option( 'tioc_settings' );
	delete_option( 'tioc_flush_rewrite_rules' );

	// Per-instructor payment methods and notes.
	delete_metadata( 'user', 0, '_tioc_payment_methods', '', true );
	delete_metadata( 'user', 0, '_tioc_payment_note', '', true );

	// Flash messages from an interrupted checkout.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_tioc_checkout_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tioc_checkout_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Uploaded receipts, which live outside the media library.
	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['basedir'] ) ) {
		tls_suite_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'tutor-offline-payments' );
	}
}

/**
 * Course Format cleanup: its 4 course-meta keys. These are pure
 * admin-configured fields (course type/format/session count/length), not
 * sales or enrolment history, so they're safe to remove.
 *
 * @return void
 */
function tls_suite_uninstall_course_format() {
	delete_post_meta_by_key( '_tcf_course_type' );
	delete_post_meta_by_key( '_tcf_course_format' );
	delete_post_meta_by_key( '_tcf_session_count' );
	delete_post_meta_by_key( '_tcf_session_length' );
}

/**
 * Course Search Pro cleanup: its own settings option only.
 *
 * Deliberately NOT touched: `_tcsp_promoted_course` and the other
 * `_tcsp_promotion_*` course-meta keys. These represent a paid
 * sponsorship placement an instructor bought — real business data, same
 * category as an order, not disposable plugin config.
 *
 * @return void
 */
function tls_suite_uninstall_course_search_pro() {
	delete_option( 'tcsp_settings' );
}

/**
 * Instructor Profile Extra cleanup: its 2 options and every `_tlms_ipe_*`
 * usermeta key (profile fields, verification status/nonces).
 *
 * @return void
 */
function tls_suite_uninstall_profile_extra() {
	delete_option( 'tlms_ipe_verification_cta_enabled' );
	delete_option( 'tlms_ipe_verification_recipient' );
	tls_suite_delete_usermeta_by_prefix( '_tlms_ipe_' );
}

/**
 * Private Sessions cleanup: its two custom DB tables and its DB-version
 * option.
 *
 * Deliberately NOT touched: the Tutor course posts this module generates
 * for each bookable session, or any Tutor cart/order data tied to them —
 * those are real bookings/content, not this plugin's own bookkeeping.
 *
 * @return void
 */
function tls_suite_uninstall_private_sessions() {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tlms_private_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tlms_private_availability" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	delete_option( 'tlms_ps_db_version' );
}

/**
 * Clean one site.
 *
 * @return void
 */
function tls_suite_uninstall_site() {
	tls_suite_uninstall_course_format();
	tls_suite_uninstall_course_search_pro();
	tls_suite_uninstall_offline_payment();
	tls_suite_uninstall_profile_extra();
	tls_suite_uninstall_private_sessions();
}

if ( is_multisite() ) {
	$tls_suite_sites = get_sites(
		array(
			'fields'   => 'ids',
			'number'   => 0,
			'archived' => 0,
			'deleted'  => 0,
		)
	);

	foreach ( $tls_suite_sites as $tls_suite_site_id ) {
		switch_to_blog( $tls_suite_site_id );
		tls_suite_uninstall_site();
		restore_current_blog();
	}
} else {
	tls_suite_uninstall_site();
}
