<?php
/**
 * Plugin Name: Backup Restore Migrate
 * Plugin URI: https://example.com/backup-restore-migrate
 * Description: Complete backup, restore and migration solution with cloud storage support
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: backup-restore-migrate
 * Domain Path: /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'BRM_VERSION', '1.0.0' );
define( 'BRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BRM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( $class ) {
	$prefix = 'BRM_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$class_file = str_replace( $prefix, '', $class );
	$class_file = str_replace( '_', '-', strtolower( $class_file ) );
	$file = BRM_PLUGIN_DIR . 'includes/class-' . $class_file . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Include required files
require_once BRM_PLUGIN_DIR . 'includes/class-activator.php';
require_once BRM_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once BRM_PLUGIN_DIR . 'includes/class-core.php';

// Activation hook
register_activation_hook( __FILE__, array( 'BRM_Activator', 'activate' ) );

// Deactivation hook
register_deactivation_hook( __FILE__, array( 'BRM_Deactivator', 'deactivate' ) );

// Initialize plugin
add_action( 'plugins_loaded', array( 'BRM_Core', 'get_instance' ) );
