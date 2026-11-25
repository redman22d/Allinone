<?php
/**
 * Plugin Name: Tutor LMS Instructor Profile Extra
 * Plugin URI: https://tutorlms.com
 * Description: Extends Tutor LMS instructor profiles with social links, private tutoring, Algerian location, education, experience, teachable categories, and spoken languages.
 * Version: 1.7.4
 * Author: Custom
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Requires Plugins: tutor
 * Text Domain: tutor-learning-suite
 * License: GPLv2 or later
 *
 * @package TutorLmsInstructorProfileExtra
 */

defined( 'ABSPATH' ) || exit;

define( 'TLMS_IPE_VERSION', '1.7.4' );
define( 'TLMS_IPE_FILE', __FILE__ );
define( 'TLMS_IPE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TLMS_IPE_URL', plugin_dir_url( __FILE__ ) );

require_once TLMS_IPE_PATH . 'includes/class-locations.php';
require_once TLMS_IPE_PATH . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'tutor' ) || ! function_exists( 'tutor_utils' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Tutor LMS Instructor Profile Extra requires the Tutor LMS plugin to be installed and active.', 'tutor-learning-suite' );
					echo '</p></div>';
				}
			);
			return;
		}

		Tlms_Ipe_Plugin::instance();
	}
);
