<?php
/** Replaces Tutor's loop/course template with the suite's course card. */
defined( 'ABSPATH' ) || exit;
echo TLS_Integration::course_card( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
