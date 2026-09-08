<?php
/**
 * Module: Instructor Profile Extra
 *
 * Extends Tutor LMS instructor profiles with social links, private
 * tutoring, Algerian location, education, experience, teachable categories,
 * and spoken languages.
 *
 * Formerly the standalone "Tutor LMS Instructor Profile Extra" plugin
 * (v1.6.5), now merged as a module of Tutor LMS Suite.
 */

defined( 'ABSPATH' ) || exit;

require_once TLMS_IPE_PATH . 'includes/class-locations.php';
require_once TLMS_IPE_PATH . 'includes/class-plugin.php';

/**
 * Boot this module. Hooked onto `plugins_loaded` at the default priority,
 * matching this module's original standalone timing exactly.
 *
 * @return void
 */
function tls_suite_boot_profile_extra() {
	if ( ! function_exists( 'tutor' ) || ! function_exists( 'tutor_utils' ) ) {
		add_action(
			'admin_notices',
			static function () {
				tls_suite_tutor_missing_notice( __( 'Instructor Profile Extra', 'tutor-lms-ipe' ) );
			}
		);
		return;
	}

	Tlms_Ipe_Plugin::instance();
}
add_action( 'plugins_loaded', 'tls_suite_boot_profile_extra' );
