<?php
/**
 * Module: Instructor Offline Payment
 *
 * Replaces the default Tutor LMS checkout with an offline checkout in which
 * every course author publishes their own payment details, receives payment
 * directly, and approves or rejects enrolment for their own courses.
 *
 * Formerly the standalone "Tutor LMS Instructor Offline Payment" plugin
 * (v1.6.1), now merged as a module of Tutor LMS Suite.
 *
 * This module keeps its own, more detailed Requirements check (Tutor LMS
 * version + native monetisation configuration) on top of the suite-wide
 * "is Tutor LMS active" gate, since it needs more than bare activation.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimum supported Tutor LMS version: native (Tutor) monetisation and the
 * ecommerce order tables landed in 3.0.0.
 */
define( 'TIOC_MIN_TUTOR_VERSION', '3.0.0' );

require_once TIOC_PATH . 'includes/helpers.php';
require_once TIOC_PATH . 'includes/class-requirements.php';
require_once TIOC_PATH . 'includes/class-settings.php';
// Audit support is optional during upgrades where its file may not yet be present.
$tioc_audit_file = TIOC_PATH . 'includes/class-audit.php';
if ( file_exists( $tioc_audit_file ) ) {
	require_once $tioc_audit_file;
}
unset( $tioc_audit_file );
require_once TIOC_PATH . 'includes/class-methods.php';
require_once TIOC_PATH . 'includes/class-contact.php';
require_once TIOC_PATH . 'includes/class-uploads.php';
require_once TIOC_PATH . 'includes/class-orders.php';
require_once TIOC_PATH . 'includes/class-emails.php';
require_once TIOC_PATH . 'includes/class-templates.php';
require_once TIOC_PATH . 'includes/class-checkout.php';
require_once TIOC_PATH . 'includes/class-dashboard.php';
require_once TIOC_PATH . 'includes/class-ajax.php';
require_once TIOC_PATH . 'includes/class-admin.php';
require_once TIOC_PATH . 'includes/class-plugin.php';

/**
 * Boot this module. Called by the suite bootstrap once Tutor LMS is
 * confirmed active. Internally re-checks version/config via Requirements.
 *
 * @return void
 */
function tls_suite_boot_offline_payment() {
	\TutorInstructorOfflinePayment\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'tls_suite_boot_offline_payment', 20 );

/**
 * Activation routine for this module: seeds default settings, creates the
 * protected proof directory and schedules the module's cron jobs.
 *
 * @return void
 */
function tls_suite_activate_offline_payment() {
	\TutorInstructorOfflinePayment\Settings::install_defaults();
	if ( class_exists( '\\TutorInstructorOfflinePayment\\Audit' ) ) {
		\TutorInstructorOfflinePayment\Audit::install();
	}
	\TutorInstructorOfflinePayment\Uploads::protect_directory();
	if ( ! wp_next_scheduled( 'tioc_expire_overdue_orders' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'tioc_expire_overdue_orders' );
	}
	if ( ! wp_next_scheduled( 'tioc_send_deadline_reminders' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'tioc_send_deadline_reminders' );
	}
	if ( ! wp_next_scheduled( 'tioc_purge_expired_receipts' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'tioc_purge_expired_receipts' );
	}
	update_option( 'tioc_flush_rewrite_rules', 1, false );
}

/**
 * Deactivation routine for this module.
 *
 * @return void
 */
function tls_suite_deactivate_offline_payment() {
	delete_option( 'tioc_flush_rewrite_rules' );
	wp_clear_scheduled_hook( 'tioc_expire_overdue_orders' );
	wp_clear_scheduled_hook( 'tioc_send_deadline_reminders' );
	wp_clear_scheduled_hook( 'tioc_purge_expired_receipts' );
}
