<?php
/** UStudy Tutor LMS course archive replacement. */
defined( 'ABSPATH' ) || exit;
get_header();
echo TLS_Integration::render_course_archive(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
get_footer();
