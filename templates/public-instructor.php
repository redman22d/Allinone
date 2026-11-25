<?php
/** UStudy instructor public profile. */
defined( 'ABSPATH' ) || exit;
get_header();
$user_id = isset( $GLOBALS['tls_public_instructor_id'] ) ? absint( $GLOBALS['tls_public_instructor_id'] ) : 0;
echo TLS_Integration::render_public_instructor( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
get_footer();
