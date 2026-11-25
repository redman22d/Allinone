<?php
/**
 * Plugin Name: OStudy Tutor LMS Ads
 * Description: Standalone banner, popup, sidebar and focus-mode advertising manager for OStudy/Tutor LMS.
 * Version: 1.7.0
 * Author: OStudy
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: tutor-learning-suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OSTUDY_ADS_VERSION', '1.7.0' );
define( 'OSTUDY_ADS_FILE', __FILE__ );
define( 'OSTUDY_ADS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OSTUDY_ADS_URL', plugin_dir_url( __FILE__ ) );

require_once OSTUDY_ADS_DIR . 'includes/class-ostudy-ads-utils.php';
require_once OSTUDY_ADS_DIR . 'includes/class-ostudy-ads-orders.php';
require_once OSTUDY_ADS_DIR . 'includes/class-ostudy-ads-db.php';
require_once OSTUDY_ADS_DIR . 'includes/class-ostudy-ads-renderer.php';
require_once OSTUDY_ADS_DIR . 'includes/class-ostudy-ads-admin.php';
require_once OSTUDY_ADS_DIR . 'includes/class-ostudy-ads-tracking.php';

final class OStudy_Tutor_LMS_Ads {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( OSTUDY_ADS_FILE, array( 'OStudy_Ads_DB', 'activate' ) );
        register_deactivation_hook( OSTUDY_ADS_FILE, array( 'OStudy_Ads_DB', 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init() {
        OStudy_Ads_DB::maybe_upgrade();
        OStudy_Ads_Renderer::instance();
        OStudy_Ads_Tracking::instance();
        OStudy_Ads_Orders::instance();
        if ( is_admin() ) {
            OStudy_Ads_Admin::instance();
        }
    }
}

OStudy_Tutor_LMS_Ads::instance();
