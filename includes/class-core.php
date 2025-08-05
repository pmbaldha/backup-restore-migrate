<?php
/**
 * Core plugin class
 */
class BRM_Core {

	/**
	 * Plugin instance
	 */
	private static $instance = null;

	/**
	 * Get plugin instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_cron_hooks();
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		// Core classes
		require_once BRM_PLUGIN_DIR . 'includes/class-backup-engine.php';
		require_once BRM_PLUGIN_DIR . 'includes/class-restore-engine.php';
		require_once BRM_PLUGIN_DIR . 'includes/class-migration-engine.php';
		require_once BRM_PLUGIN_DIR . 'includes/class-scheduler.php';
		require_once BRM_PLUGIN_DIR . 'includes/class-logger.php';

		// Storage handlers
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-storage-factory.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-storage-interface.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-local-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-ftp-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-sftp-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-s3-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-google-drive-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-dropbox-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-google-cloud-storage.php';
		require_once BRM_PLUGIN_DIR . 'includes/storage/class-backblaze-storage.php';

		// Admin classes
		if ( is_admin() ) {
			require_once BRM_PLUGIN_DIR . 'admin/class-admin.php';
			require_once BRM_PLUGIN_DIR . 'admin/class-admin-ajax.php';
		}
	}

	/**
	 * Set plugin locale
	 */
	private function set_locale() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin textdomain
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'backup-restore-migrate',
			false,
			dirname( BRM_PLUGIN_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Define admin hooks
	 */
	private function define_admin_hooks() {
		if ( is_admin() ) {
			$admin = new BRM_Admin();

			add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );

			// AJAX handlers
			$ajax = new BRM_Admin_Ajax();

			add_action( 'wp_ajax_bmr_create_backup', array( $ajax, 'create_backup' ) );
			add_action( 'wp_ajax_bmr_restore_backup', array( $ajax, 'restore_backup' ) );
			add_action( 'wp_ajax_bmr_delete_backup', array( $ajax, 'delete_backup' ) );
			add_action( 'wp_ajax_bmr_download_backup', array( $ajax, 'download_backup' ) );
			add_action( 'wp_ajax_bmr_save_schedule', array( $ajax, 'save_schedule' ) );
			add_action( 'wp_ajax_bmr_delete_schedule', array( $ajax, 'delete_schedule' ) );
			add_action( 'wp_ajax_bmr_test_storage', array( $ajax, 'test_storage' ) );
			add_action( 'wp_ajax_bmr_get_backup_progress', array( $ajax, 'get_backup_progress' ) );
			add_action( 'wp_ajax_bmr_export_site', array( $ajax, 'export_site' ) );
			add_action( 'wp_ajax_bmr_import_site', array( $ajax, 'import_site' ) );
			add_action( 'wp_ajax_bmr_clone_site', array( $ajax, 'clone_site' ) );
		}
	}

	/**
	 * Define public hooks
	 */
	private function define_public_hooks() {
		// No public hooks needed for this plugin
	}

	/**
	 * Define cron hooks
	 */
	private function define_cron_hooks() {
		add_action( 'bmr_scheduled_backup', array( 'BRM_Scheduler', 'run_scheduled_backups' ) );
		add_action( 'bmr_cleanup_old_backups', array( 'BRM_Scheduler', 'cleanup_old_backups' ) );
	}
}