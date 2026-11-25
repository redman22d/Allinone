<?php
/**
 * Plugin Name: Tutor Learning Suite
 * Description: UStudy integration for Tutor LMS: instructor profiles, course catalog, family/privacy dashboard, private tutoring and tutor-direct payment records.
 * Version: 1.3.48
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: tutor-learning-suite
 * License: GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;
define( 'TLS_VERSION', '1.3.48' );
define( 'TLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TLS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Simple per-visitor rate limit for public AJAX endpoints (fixed window, transient based).
 * Returns true when the caller has exceeded $max hits inside $window seconds.
 */
function tls_rate_limited( $bucket, $max = 60, $window = 60 ) {
    $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    $key = 'tls_rl_' . md5( $bucket . '|' . $ip . '|' . get_current_user_id() );
    $hits = (int) get_transient( $key );
    if ( $hits >= (int) $max ) { return true; }
    set_transient( $key, $hits + 1, (int) $window );
    return false;
}

// Each legacy component stays in its own namespace/path to preserve its data,
// assets, shortcodes and upgrade compatibility; it is not exposed as a separate plugin.
$tls_modules = array(
    'modules/ads/ostudy-tutor-lms-ads.php',
    'modules/course-format/tutor-course-format.php',
    'modules/course-search/tutor-course-search-pro.php',
    'modules/offline-payment/tutor-instructor-offline-payment.php',
    'modules/instructor-profile/tutor-lms-instructor-profile-extra.php',
    'modules/private-sessions/tutor-lms-private-sessions.php',
);
foreach ( $tls_modules as $tls_module ) {
    require_once TLS_DIR . $tls_module;
}
require_once TLS_DIR . 'includes/class-tls-feedback-moderation.php';
require_once TLS_DIR . 'includes/class-tls-age-safety.php';
require_once TLS_DIR . 'includes/class-tls-enhancements.php';
require_once TLS_DIR . 'includes/class-tls-integration.php';
// One translation domain for every module.
add_action( 'init', function () {
    load_plugin_textdomain( 'tutor-learning-suite', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );
add_action( 'plugins_loaded', array( 'TLS_Feedback_Moderation', 'init' ), 28 );
add_action( 'plugins_loaded', array( 'TLS_Age_Safety', 'init' ), 29 );
add_action( 'plugins_loaded', array( 'TLS_Enhancements', 'init' ), 30 );
add_action( 'plugins_loaded', array( 'TLS_Integration', 'init' ), 31 );

function tls_activate() {
    if ( class_exists( 'OStudy_Ads_DB' ) ) { OStudy_Ads_DB::activate(); }
    if ( function_exists( 'tcf_activate' ) ) { tcf_activate(); }
    if ( function_exists( 'tioc_activate' ) ) { tioc_activate(); }
    if ( class_exists( 'TLMS_Private_Sessions' ) ) { TLMS_Private_Sessions::activate(); }
    TLS_Enhancements::activate();
    TLS_Integration::activate();
    TLS_Age_Safety::activate();
}
register_activation_hook( __FILE__, 'tls_activate' );

/**
 * Deactivation: the bundled modules still call register_deactivation_hook() on
 * their own __FILE__, but WordPress only fires that hook for the active plugin's
 * main file, so those callbacks never run. Clear every scheduled event the suite
 * registers here so deactivated sites are not left with orphaned cron jobs.
 */
function tls_deactivate() {
    foreach ( tls_cron_hooks() as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
    if ( function_exists( 'tioc_deactivate' ) ) {
        tioc_deactivate(); // Also drops its pending rewrite flag and flushes rewrites.
    } else {
        flush_rewrite_rules();
    }
}
register_deactivation_hook( __FILE__, 'tls_deactivate' );

/**
 * Every cron hook scheduled by the suite or one of its modules.
 * Shared by deactivation and uninstall so the two lists cannot drift apart.
 *
 * @return string[]
 */
function tls_cron_hooks() {
    return array(
        'tls_age_safety_daily_sync',          // TLS_Age_Safety::CRON_HOOK
        'tlms_ps_maintenance',                // TLMS_Private_Sessions::CRON_HOOK
        'ostudy_ads_daily_orders',            // Ads orders daily job
        'tcsp_process_scheduled_sponsorships',// Course Search Pro sponsorships
        'tioc_expire_overdue_orders',         // Offline payment
        'tioc_send_deadline_reminders',
        'tioc_purge_expired_receipts',
    );
}

// Opt-in "delete everything on uninstall" switch, shown under Settings > General.
add_action( 'admin_init', function () {
    register_setting( 'general', 'tls_delete_data_on_uninstall', array( 'type' => 'boolean', 'sanitize_callback' => 'absint', 'default' => 0 ) );
    add_settings_field( 'tls_delete_data_on_uninstall', __( 'Tutor Learning Suite data', 'tutor-learning-suite' ), function () {
        echo '<label><input type="checkbox" name="tls_delete_data_on_uninstall" value="1" ' . checked( 1, (int) get_option( 'tls_delete_data_on_uninstall' ), false ) . '> ' . esc_html__( 'Delete all Tutor Learning Suite data (bookings, payment records, ads, settings) when the plugin is deleted', 'tutor-learning-suite' ) . '</label>';
    }, 'general' );
} );
