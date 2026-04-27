<?php
/**
 * Plugin Name: Multi-Location Google Reviews Widget
 * Plugin URI: https://example.com
 * Description: Display and manage Google reviews across multiple business locations.
 * Version: 2.0.0
 * Author: SterlingX
 * Author URI: https://example.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: multi-location-google-reviews-widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MLGR_PLUGIN_FILE', __FILE__ );
define( 'MLGR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLGR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLGR_PLUGIN_VERSION', '2.0.0' );

$action_scheduler_file = MLGR_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $action_scheduler_file ) ) {~
	require_once $action_scheduler_file;
}

require_once MLGR_PLUGIN_PATH . 'includes/class-database-installer.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-cpt-manager.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-logger.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-serpapi-fetcher.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-review-syncer.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-admin-settings-page.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-review-shortcode.php';
require_once MLGR_PLUGIN_PATH . 'includes/class-maintenance-manager.php';

register_activation_hook( MLGR_PLUGIN_FILE, array( 'Database_Installer', 'activate' ) );
register_activation_hook( MLGR_PLUGIN_FILE, array( 'Maintenance_Manager', 'activate' ) );
register_activation_hook( MLGR_PLUGIN_FILE, array( 'Logger', 'activate' ) );
register_deactivation_hook( MLGR_PLUGIN_FILE, array( 'Maintenance_Manager', 'deactivate' ) );
register_deactivation_hook( MLGR_PLUGIN_FILE, array( 'Logger', 'deactivate' ) );

CPT_Manager::init();
add_action( 'init', array( 'Database_Installer', 'maybe_upgrade_schema' ), 20 );
Logger::init();
Review_Syncer::init();
Admin_Settings_Page::init();
Review_Shortcode::init();
Maintenance_Manager::init();
