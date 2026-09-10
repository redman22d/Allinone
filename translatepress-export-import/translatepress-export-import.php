<?php
/**
 * Plugin Name:       TranslatePress – Export / Import Translations
 * Plugin URI:        https://example.com/translatepress-export-import
 * Description:       Export TranslatePress strings (originals + translations) to CSV or JSON, translate them anywhere (Excel, CAT tools, AI services), then import the translations back. Per-language export, smart filters, dry-run imports and WP-CLI support.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.0
 * Author:            Allinone
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       translatepress-export-import
 * Domain Path:       /languages
 *
 * @package TranslatePress_Export_Import
 */

defined( 'ABSPATH' ) || exit;

define( 'TPEI_VERSION', '1.0.0' );
define( 'TPEI_PLUGIN_FILE', __FILE__ );
define( 'TPEI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPEI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TPEI_BASENAME', plugin_basename( __FILE__ ) );

require_once TPEI_PLUGIN_DIR . 'includes/class-tpei-core.php';
require_once TPEI_PLUGIN_DIR . 'includes/class-tpei-export.php';
require_once TPEI_PLUGIN_DIR . 'includes/class-tpei-import.php';
require_once TPEI_PLUGIN_DIR . 'includes/class-tpei-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TPEI_PLUGIN_DIR . 'includes/class-tpei-cli.php';
}

/**
 * Load translations.
 */
function tpei_load_textdomain() {
	load_plugin_textdomain( 'translatepress-export-import', false, dirname( TPEI_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'tpei_load_textdomain' );

/**
 * Register admin + handlers.
 */
function tpei_init() {
	if ( is_admin() ) {
		new TPEI_Admin();
	}
	TPEI_Export::init();
	TPEI_Import::init();
}
add_action( 'init', 'tpei_init' );

/**
 * Activation: store version, no DB changes (we only read/write TranslatePress tables).
 */
function tpei_activate() {
	add_option( 'tpei_version', TPEI_VERSION );
}
register_activation_hook( __FILE__, 'tpei_activate' );

/**
 * Add a "Settings" link on the plugins screen.
 *
 * @param array $links Existing links.
 * @return array
 */
function tpei_plugin_action_links( $links ) {
	$url           = admin_url( 'options-general.php?page=tpei-export-import' );
	$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Export / Import', 'translatepress-export-import' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . TPEI_BASENAME, 'tpei_plugin_action_links' );
