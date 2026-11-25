<?php
/**
 * Plugin Name:       Tutor LMS Course Format
 * Plugin URI:        https://example.com/tutor-course-format
 * Description:       Adds Course Type (Prerecorded / Direct / Hybrid), delivery format (Group / 1v1), sessions number and session length fields to the Tutor LMS course editor, shows them on the course page, gives instructors a dashboard page to manually enroll/remove students, and lets admins allow or block that feature site-wide.
 * Version:           1.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-learning-suite
 * Domain Path:       /languages
 *
 * Tested with Tutor LMS 4.0.7 (free). Works with Tutor LMS Pro 3.x/4.x as well.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

define( 'TCF_VERSION', '1.6.0' );
define( 'TCF_FILE', __FILE__ );
define( 'TCF_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCF_URL', plugin_dir_url( __FILE__ ) );

/**
 * Meta keys.
 */
define( 'TCF_META_TYPE', '_tcf_course_type' );
define( 'TCF_META_FORMAT', '_tcf_course_format' );
define( 'TCF_META_SESSIONS', '_tcf_session_count' );
define( 'TCF_META_LENGTH', '_tcf_session_length' );

/**
 * Payload / REST / form field names.
 *
 * These are the exact keys used by the Tutor LMS course builder for both
 * saving (POST) and loading (tutor_course_details_response).
 */
define( 'TCF_FIELD_TYPE', 'tcf_course_type' );
define( 'TCF_FIELD_FORMAT', 'tcf_course_format' );
define( 'TCF_FIELD_SESSIONS', 'tcf_session_count' );
define( 'TCF_FIELD_LENGTH', 'tcf_session_length' );

/**
 * Option name for the global "allow instructors to manually enroll
 * students" admin switch. Stored as the string '1' (allowed) or '0'
 * (blocked); defaults to allowed when never set.
 */
define( 'TCF_OPTION_ALLOW_MANUAL_ENROLLMENT', 'tcf_allow_manual_enrollment' );

/**
 * Option: hide the instructor-facing "Add Category" control in the Tutor
 * course builder. Stored as '1' (hidden) or '0' (visible).
 */
define( 'TCF_OPTION_HIDE_INSTRUCTOR_CATEGORY_ADD', 'tcf_hide_instructor_category_add' );

/**
 * Option: maximum file size (MB) allowed for assignment/quiz submissions.
 * A single site-wide value replaces the per-assessment instructor setting.
 */
define( 'TCF_OPTION_ASSESSMENT_FILE_SIZE_MB', 'tcf_assessment_file_size_mb' );

/**
 * Options: instructor image upload limits in megabytes.
 */
define( 'TCF_OPTION_IMAGE_FEATURED_SIZE_MB', 'tcf_image_featured_size_mb' );
define( 'TCF_OPTION_IMAGE_PROFILE_SIZE_MB', 'tcf_image_profile_size_mb' );
define( 'TCF_OPTION_IMAGE_NORMAL_SIZE_MB', 'tcf_image_normal_size_mb' );

require_once TCF_DIR . 'includes/tcf-helpers.php';
require_once TCF_DIR . 'includes/class-tcf-plugin.php';
require_once TCF_DIR . 'includes/class-tcf-course-builder.php';
require_once TCF_DIR . 'includes/class-tcf-admin-metabox.php';
require_once TCF_DIR . 'includes/class-tcf-frontend.php';
require_once TCF_DIR . 'includes/class-tcf-manual-enrollment.php';
require_once TCF_DIR . 'includes/class-tcf-settings.php';

/**
 * Boot.
 *
 * @return void
 */
function tcf_boot() {
	if ( ! tcf_is_tutor_active() ) {
		add_action( 'admin_notices', 'tcf_missing_tutor_notice' );
		return;
	}

	TCF_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'tcf_boot', 20 );

/**
 * Admin notice when Tutor LMS is missing.
 *
 * @return void
 */
function tcf_missing_tutor_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Tutor LMS Course Format requires the Tutor LMS plugin to be installed and active.', 'tutor-learning-suite' )
	);
}

/**
 * Activation / deactivation.
 */
register_activation_hook( __FILE__, 'tcf_activate' );

/**
 * On activation make sure the default exists for already published courses.
 *
 * We intentionally do NOT bulk-write meta: a missing `_tcf_course_type`
 * already resolves to "prerecorded" via tcf_get_course_type().
 *
 * @return void
 */
function tcf_activate() {
	flush_rewrite_rules();
}
