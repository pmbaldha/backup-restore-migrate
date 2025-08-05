<?php
/**
 * Plugin activation handler
 */
class BRM_Activator {

	/**
	 * Activate the plugin
	 */
	public static function activate() {
		// Create database tables
		self::create_tables();

		// Set default options
		self::set_default_options();

		// Create backup directory
		self::create_backup_directory();

		// Schedule cron events
		self::schedule_events();
	}

	/**
	 * Create plugin database tables
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Backups table
		$table_backups = $wpdb->prefix . 'brm_backups';
		$sql_backups = "CREATE TABLE $table_backups (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			backup_name varchar(255) NOT NULL,
			backup_type varchar(50) NOT NULL,
			backup_size bigint(20) DEFAULT 0,
			backup_location text,
			storage_type varchar(50),
			status varchar(50) DEFAULT 'pending',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			completed_at datetime DEFAULT NULL,
			incremental_parent bigint(20) DEFAULT NULL,
			metadata longtext,
			PRIMARY KEY (id),
			KEY backup_type (backup_type),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Backup schedules table
		$table_schedules = $wpdb->prefix . 'brm_schedules';
		$sql_schedules = "CREATE TABLE $table_schedules (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			schedule_name varchar(255) NOT NULL,
			schedule_type varchar(50) NOT NULL,
			backup_type varchar(50) NOT NULL,
			storage_destinations text,
			frequency varchar(50) NOT NULL,
			next_run datetime,
			last_run datetime,
			is_active tinyint(1) DEFAULT 1,
			retention_count int(11) DEFAULT 5,
			incremental_enabled tinyint(1) DEFAULT 0,
			settings longtext,
			PRIMARY KEY (id),
			KEY is_active (is_active),
			KEY next_run (next_run)
		) $charset_collate;";

		// Backup log table
		$table_logs = $wpdb->prefix . 'brm_logs';
		$sql_logs = "CREATE TABLE $table_logs (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			backup_id bigint(20),
			log_level varchar(20),
			message text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY backup_id (backup_id),
			KEY log_level (log_level),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql_backups );
		dbDelta( $sql_schedules );
		dbDelta( $sql_logs );

		update_option( 'brm_db_version', BRM_VERSION );
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options() {
		$defaults = array(
			'backup_directory' => 'backup-restore-migrate',
			'max_execution_time' => 300,
			'memory_limit' => '256M',
			'chunk_size' => 2048, // KB
			'compression_level' => 5,
			'exclude_tables' => array(),
			'exclude_files' => array(
				'wp-content/cache',
				'wp-content/uploads/backup-restore-migrate',
				'wp-content/backup',
				'wp-content/backups',
			),
			'email_notifications' => true,
			'notification_email' => get_option( 'admin_email' ),
			'retain_local_backups' => 5,
			'enable_debug_log' => false,
		);

		foreach ( $defaults as $key => $value ) {
			if ( get_option( 'brm_' . $key ) === false ) {
				update_option( 'brm_' . $key, $value );
			}
		}
	}

	/**
	 * Create backup directory
	 */
	private static function create_backup_directory() {
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/backup-restore-migrate';

		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );

			// Add .htaccess for security
			$htaccess_content = "Options -Indexes\nDeny from all";
			file_put_contents( $backup_dir . '/.htaccess', $htaccess_content );

			// Add index.php for security
			file_put_contents( $backup_dir . '/index.php', '<?php // Silence is golden' );
		}
	}

	/**
	 * Schedule cron events
	 */
	private static function schedule_events() {
		if ( ! wp_next_scheduled( 'brm_scheduled_backup' ) ) {
			wp_schedule_event( time(), 'hourly', 'brm_scheduled_backup' );
		}

		if ( ! wp_next_scheduled( 'brm_cleanup_old_backups' ) ) {
			wp_schedule_event( time(), 'daily', 'brm_cleanup_old_backups' );
		}
	}
}