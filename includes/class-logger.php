<?php
/**
 * Logger class
 */
class BRM_Logger {

	/**
	 * Backup ID
	 */
	private $backup_id;

	/**
	 * Enable debug logging
	 */
	private $debug_enabled;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->debug_enabled = get_option( 'brm_enable_debug_log', false );
	}

	/**
	 * Set backup ID
	 */
	public function set_backup_id( $backup_id ) {
		$this->backup_id = $backup_id;
	}

	/**
	 * Log info message
	 */
	public function info( $message ) {
		$this->log( 'info', $message );
	}

	/**
	 * Log warning message
	 */
	public function warning( $message ) {
		$this->log( 'warning', $message );
	}

	/**
	 * Log error message
	 */
	public function error( $message ) {
		$this->log( 'error', $message );
	}

	/**
	 * Log debug message
	 */
	public function debug( $message ) {
		if ( $this->debug_enabled ) {
			$this->log( 'debug', $message );
		}
	}

	/**
	 * Log message
	 */
	private function log( $level, $message ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'brm_logs',
			array(
				'backup_id' => $this->backup_id,
				'log_level' => $level,
				'message' => $message,
			)
		);

		// Also write to debug.log if WP_DEBUG is enabled
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[WP Backup Migration] [%s] %s', strtoupper( $level ), $message ) );
		}
	}
}