<?php
/**
 * Plugin Name:       Tutor LMS Suite
 * Plugin URI:        https://example.com/tutor-lms-suite
 * Description:       All-in-one Tutor LMS add-on: course delivery format fields, advanced course search with sponsorships, per-instructor offline payment/checkout, extended instructor profiles, and private 1-to-1 tutoring sessions. Merges what used to be five separate plugins into one.
 * Version:           1.0.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  tutor
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-lms-suite
 * Domain Path:       /languages
 *
 * @package TutorLmsSuite
 *
 * ---------------------------------------------------------------------
 * This plugin merges five previously-separate add-ons into one codebase
 * so they ship, update, and load as a single plugin:
 *
 *   - Tutor LMS Course Format          (was v1.2.0)  -> modules/course-format
 *   - Tutor Course Search Pro          (was v3.8.3)  -> modules/course-search-pro
 *   - Tutor Instructor Offline Payment (was v1.6.1)  -> modules/instructor-offline-payment
 *   - Tutor LMS Instructor Profile Extra (was v1.6.5) -> modules/instructor-profile-extra
 *   - Tutor LMS Private Sessions       (was v1.5.32) -> modules/private-sessions
 *
 * Each module keeps its original class/function prefixes (TCF_, TCSP_,
 * TutorInstructorOfflinePayment\, Tlms_Ipe_, TLMS_PS_ / TLMS_Private_
 * Sessions) so there is no risk of collisions between them, and each
 * module's business logic is unmodified from its standalone version.
 *
 * What changed in the merge (see README.md for the full list):
 *   - One plugin header, one version, one activation/deactivation/uninstall
 *     flow instead of five.
 *   - The three near-identical "Tutor LMS must be active" admin notices
 *     (Course Format, Instructor Profile Extra, Private Sessions) now share
 *     one rendering function, tls_suite_tutor_missing_notice().
 *   - Developer-only files (the Course Format test suite, an empty .tmp
 *     leftover in Private Sessions, five duplicate readme.txt/README.md
 *     files) were dropped; production code is untouched.
 * ---------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

define( 'TLS_SUITE_VERSION', '1.0.5' );
define( 'TLS_SUITE_FILE', __FILE__ );
define( 'TLS_SUITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TLS_SUITE_URL', plugin_dir_url( __FILE__ ) );
define( 'TLS_SUITE_MODULES_DIR', TLS_SUITE_DIR . 'modules/' );
define( 'TLS_SUITE_MODULES_URL', TLS_SUITE_URL . 'modules/' );

/*
 * ---------------------------------------------------------------------
 * Per-module path/URL constants.
 *
 * Every module's own files still reference these exact constant names
 * (e.g. TCF_DIR . 'includes/...'), so keeping the names identical and only
 * repointing their values means none of the merged business-logic files
 * needed to be edited.
 *
 * The *_FILE / *_BASENAME constants point at this single bootstrap file
 * (rather than a per-module file that no longer exists) so that
 * plugin_basename()/dirname() calls and the "Settings" action link on the
 * Plugins screen resolve against the real, single plugin file.
 * ---------------------------------------------------------------------
 */

// Course Format.
define( 'TCF_VERSION', TLS_SUITE_VERSION );
define( 'TCF_FILE', TLS_SUITE_FILE );
define( 'TCF_DIR', TLS_SUITE_MODULES_DIR . 'course-format/' );
define( 'TCF_URL', TLS_SUITE_MODULES_URL . 'course-format/' );

// Course Search Pro.
define( 'TCSP_VERSION', TLS_SUITE_VERSION );
define( 'TCSP_FILE', TLS_SUITE_FILE );
define( 'TCSP_DIR', TLS_SUITE_MODULES_DIR . 'course-search-pro/' );
define( 'TCSP_URL', TLS_SUITE_MODULES_URL . 'course-search-pro/' );

// Instructor Offline Payment.
define( 'TIOC_VERSION', TLS_SUITE_VERSION );
define( 'TIOC_FILE', TLS_SUITE_FILE );
define( 'TIOC_PATH', TLS_SUITE_MODULES_DIR . 'instructor-offline-payment/' );
define( 'TIOC_URL', TLS_SUITE_MODULES_URL . 'instructor-offline-payment/' );
define( 'TIOC_BASENAME', plugin_basename( TLS_SUITE_FILE ) );

// Instructor Profile Extra.
define( 'TLMS_IPE_VERSION', TLS_SUITE_VERSION );
define( 'TLMS_IPE_FILE', TLS_SUITE_FILE );
define( 'TLMS_IPE_PATH', TLS_SUITE_MODULES_DIR . 'instructor-profile-extra/' );
define( 'TLMS_IPE_URL', TLS_SUITE_MODULES_URL . 'instructor-profile-extra/' );

// Private Sessions.
define( 'TLMS_PS_VERSION', TLS_SUITE_VERSION );
define( 'TLMS_PS_FILE', TLS_SUITE_FILE );
define( 'TLMS_PS_PATH', TLS_SUITE_MODULES_DIR . 'private-sessions/' );
define( 'TLMS_PS_URL', TLS_SUITE_MODULES_URL . 'private-sessions/' );

/**
 * Shared "Tutor LMS is required" admin notice.
 *
 * Replaces three separate, near-identical inline notices that used to live
 * in Course Format, Instructor Profile Extra and Private Sessions.
 *
 * @param string $module_label Human-readable module name for the message.
 *
 * @return void
 */
function tls_suite_tutor_missing_notice( $module_label ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: module name, e.g. "Course Format". */
				__( 'Tutor LMS Suite - %s requires the Tutor LMS plugin to be installed and active.', 'tutor-lms-suite' ),
				$module_label
			)
		)
	);
}

require_once TLS_SUITE_DIR . 'includes/class-suite-hub.php';

/*
 * ---------------------------------------------------------------------
 * Load every module. Each module.php keeps that module's own original
 * hook timing (immediate / plugins_loaded default / plugins_loaded@20),
 * exactly as it ran when standalone, so behaviour is unchanged.
 * ---------------------------------------------------------------------
 */
require_once TLS_SUITE_MODULES_DIR . 'course-format/module.php';
require_once TLS_SUITE_MODULES_DIR . 'course-search-pro/module.php';
require_once TLS_SUITE_MODULES_DIR . 'instructor-offline-payment/module.php';
require_once TLS_SUITE_MODULES_DIR . 'instructor-profile-extra/module.php';
require_once TLS_SUITE_MODULES_DIR . 'private-sessions/module.php';

/**
 * Combined activation routine: runs every module's own activation logic,
 * then flushes rewrite rules once (each module used to do this separately).
 *
 * @return void
 */
function tls_suite_activate() {
	tls_suite_activate_course_format();
	tls_suite_activate_offline_payment();
	tls_suite_activate_private_sessions();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'tls_suite_activate' );

/**
 * Combined deactivation routine.
 *
 * @return void
 */
function tls_suite_deactivate() {
	tls_suite_deactivate_offline_payment();
	tls_suite_deactivate_private_sessions();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tls_suite_deactivate' );

/**
 * Load the suite's translations. Individual modules previously each
 * called load_plugin_textdomain() for their own text domain; that is left
 * untouched inside each module so existing .mo files for those domains
 * keep working, and this adds one more for the suite's own strings
 * (e.g. tls_suite_tutor_missing_notice()).
 *
 * @return void
 */
function tls_suite_load_textdomain() {
	load_plugin_textdomain( 'tutor-lms-suite', false, dirname( plugin_basename( TLS_SUITE_FILE ) ) . '/languages' );
}
add_action( 'init', 'tls_suite_load_textdomain' );
