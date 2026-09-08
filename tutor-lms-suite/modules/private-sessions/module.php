<?php
/**
 * Module: Private Sessions
 *
 * Private tutoring requests with subject, 1-to-1/group and location
 * choices, tutor proposals, approval, and Tutor LMS Native eCommerce
 * checkout.
 *
 * Formerly the standalone "Tutor LMS Private Sessions" plugin (v1.5.32),
 * now merged as a module of Tutor LMS Suite.
 */

defined( 'ABSPATH' ) || exit;

define( 'TLMS_PS_DB_VERSION', '22' );

require_once __DIR__ . '/class-private-sessions.php';

/*
 * Matches the module's original standalone timing exactly: instantiate
 * immediately (its constructor hooks its own `boot()` onto `plugins_loaded`
 * priority 20 and does its own Tutor-LMS-active check there), rather than
 * waiting for the suite's own plugins_loaded gate.
 */
TLMS_Private_Sessions::instance();

/**
 * Activation routine for this module (creates DB tables).
 *
 * @return void
 */
function tls_suite_activate_private_sessions() {
	TLMS_Private_Sessions::activate();
}

/**
 * Deactivation routine for this module.
 *
 * @return void
 */
function tls_suite_deactivate_private_sessions() {
	wp_clear_scheduled_hook( TLMS_Private_Sessions::CRON_HOOK );
}
