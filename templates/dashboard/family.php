<?php
/** Family dashboard page. */
defined( 'ABSPATH' ) || exit;
echo TLS_Integration::render_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
TLS_Integration::dashboard_page( 'family' );
