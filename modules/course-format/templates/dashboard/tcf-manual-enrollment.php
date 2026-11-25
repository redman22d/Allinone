<?php
/**
 * Instructor dashboard: "Enroll Students" page.
 *
 * Loaded by TCF_Manual_Enrollment::dashboard_template_path() via Tutor LMS's
 * `load_dashboard_template_part_from_other_location` filter.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

if ( ! empty( TCF_Plugin::instance()->manual_enrollment ) ) {
	TCF_Plugin::instance()->manual_enrollment->render_manual_enrollment();
}
