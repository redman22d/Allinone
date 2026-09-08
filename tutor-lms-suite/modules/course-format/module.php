<?php
/**
 * Module: Course Format
 *
 * Adds Course Type (Prerecorded / Direct / Hybrid), delivery format
 * (Group / 1v1), sessions number and session length fields to the Tutor LMS
 * course editor, and shows them on the course page.
 *
 * Formerly the standalone "Tutor LMS Course Format" plugin (v1.2.0), now
 * merged as a module of Tutor LMS Suite. The Tutor-LMS-active check and the
 * admin notice it used to show on its own are now handled once, centrally,
 * by the suite bootstrap.
 */

defined( 'ABSPATH' ) || exit;

define( 'TCF_META_TYPE', '_tcf_course_type' );
define( 'TCF_META_FORMAT', '_tcf_course_format' );
define( 'TCF_META_SESSIONS', '_tcf_session_count' );
define( 'TCF_META_LENGTH', '_tcf_session_length' );

/**
 * Payload / REST / form field names -- the exact keys used by the Tutor LMS
 * course builder for both saving (POST) and loading
 * (tutor_course_details_response).
 */
define( 'TCF_FIELD_TYPE', 'tcf_course_type' );
define( 'TCF_FIELD_FORMAT', 'tcf_course_format' );
define( 'TCF_FIELD_SESSIONS', 'tcf_session_count' );
define( 'TCF_FIELD_LENGTH', 'tcf_session_length' );

require_once TCF_DIR . 'includes/tcf-helpers.php';
require_once TCF_DIR . 'includes/class-tcf-plugin.php';
require_once TCF_DIR . 'includes/class-tcf-course-builder.php';
require_once TCF_DIR . 'includes/class-tcf-admin-metabox.php';
require_once TCF_DIR . 'includes/class-tcf-frontend.php';

/**
 * Boot this module. Hooked onto `plugins_loaded` priority 20, matching this
 * module's original standalone timing exactly.
 *
 * @return void
 */
function tls_suite_boot_course_format() {
	if ( ! tcf_is_tutor_active() ) {
		add_action(
			'admin_notices',
			static function () {
				tls_suite_tutor_missing_notice( __( 'Course Format', 'tutor-course-format' ) );
			}
		);
		return;
	}

	TCF_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'tls_suite_boot_course_format', 20 );

/**
 * Activation routine for this module (no schema or rewrite changes).
 *
 * We intentionally do NOT bulk-write meta on activation: a missing
 * `_tcf_course_type` already resolves to "prerecorded" via
 * tcf_get_course_type().
 *
 * @return void
 */
function tls_suite_activate_course_format() {
	// The suite owns rewrite flushing; course metadata needs no extra flush.
}
