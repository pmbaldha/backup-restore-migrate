<?php
/**
 * Admin AJAX handlers
 */
class BRM_Admin_Ajax {

	/**
	 * Create backup
	 */
	public function create_backup() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$backup_type = isset( $_POST['backup_type'] ) ? sanitize_text_field( $_POST['backup_type'] ) : 'full';
		$backup_label = isset( $_POST['backup_label'] ) ? sanitize_text_field( $_POST['backup_label'] ) : '';
		$storage_destinations = isset( $_POST['storage_destinations'] ) ? array_map( 'sanitize_text_field', $_POST['storage_destinations'] ) : array( 'local' );

		// Create backup
		$backup_engine = new BRM_Backup_Engine( array(
			'backup_type' => $backup_type,
			'backup_label' => $backup_label,
			'storage_destinations' => $storage_destinations,
		) );

		$result = $backup_engine->create_backup();

		wp_send_json( $result );
	}

	/**
	 * Get backup progress
	 */
	public function get_backup_progress() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? intval( $_POST['backup_id'] ) : 0;

		if ( ! $backup_id ) {
			wp_send_json_error( __( 'Invalid backup ID', 'backup-restore-migrate' ) );
		}

		$progress = get_transient( 'brm_backup_progress_' . $backup_id );

		if ( $progress ) {
			wp_send_json_success( $progress );
		} else {
			wp_send_json_error( __( 'No progress data found', 'backup-restore-migrate' ) );
		}
	}

	/**
	 * Restore backup
	 */
	public function restore_backup() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? intval( $_POST['backup_id'] ) : 0;

		if ( ! $backup_id ) {
			wp_send_json_error( __( 'Invalid backup ID', 'backup-restore-migrate' ) );
		}

		$options = array(
			'create_restore_point' => isset( $_POST['create_restore_point'] ),
			'update_urls' => isset( $_POST['update_urls'] ),
		);

		// Restore backup
		$restore_engine = new BRM_Restore_Engine();
		$result = $restore_engine->restore_backup( $backup_id, $options );

		wp_send_json( $result );
	}

	/**
	 * Delete backup
	 */
	public function delete_backup() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$backup_id = isset( $_POST['backup_id'] ) ? intval( $_POST['backup_id'] ) : 0;

		if ( ! $backup_id ) {
			wp_send_json_error( __( 'Invalid backup ID', 'backup-restore-migrate' ) );
		}

		global $wpdb;

		// Get backup details
		$backup = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}brm_backups WHERE id = %d",
			$backup_id
		) );

		if ( ! $backup ) {
			wp_send_json_error( __( 'Backup not found', 'backup-restore-migrate' ) );
		}

		// Delete from storage
		$locations = json_decode( $backup->backup_location, true );

		if ( $locations ) {
			foreach ( $locations as $type => $location ) {
				try {
					$storage = BRM_Storage_Factory::create( $type );
					if ( $storage ) {
						$storage->delete( $location );
					}
				} catch ( Exception $e ) {
					// Log error but continue
				}
			}
		}

		// Delete database record
		$wpdb->delete(
			$wpdb->prefix . 'brm_backups',
			array( 'id' => $backup_id )
		);

		wp_send_json_success( __( 'Backup deleted successfully', 'backup-restore-migrate' ) );
	}

	/**
	 * Download backup
	 */
	public function download_backup() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$backup_id = isset( $_GET['backup_id'] ) ? intval( $_GET['backup_id'] ) : 0;

		if ( ! $backup_id ) {
			wp_die( __( 'Invalid backup ID', 'backup-restore-migrate' ) );
		}

		global $wpdb;

		// Get backup details
		$backup = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}brm_backups WHERE id = %d",
			$backup_id
		) );

		if ( ! $backup ) {
			wp_die( __( 'Backup not found', 'backup-restore-migrate' ) );
		}

		// Get backup file
		$locations = json_decode( $backup->backup_location, true );
		$file_path = null;

		// Check for local file first
		if ( isset( $locations['local'] ) && file_exists( $locations['local'] ) ) {
			$file_path = $locations['local'];
		} else {
			// Download from remote storage
			$upload_dir = wp_upload_dir();
			$temp_file = $upload_dir['basedir'] . '/backup-restore-migrate/temp/' . $backup->backup_name . '.zip';

			foreach ( $locations as $type => $location ) {
				if ( $type === 'local' ) {
					continue;
				}

				try {
					$storage = BRM_Storage_Factory::create( $type );
					if ( $storage && $storage->download( $location, $temp_file ) ) {
						$file_path = $temp_file;
						break;
					}
				} catch ( Exception $e ) {
					// Try next storage
				}
			}
		}

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( __( 'Backup file not found', 'backup-restore-migrate' ) );
		}

		// Send file
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $backup->backup_name ) . '.zip"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $file_path );

		// Clean up temp file
		if ( isset( $temp_file ) && file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}

		exit;
	}

	/**
	 * Save schedule
	 */
	public function save_schedule() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		global $wpdb;

		$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;
		$schedule_name = sanitize_text_field( $_POST['schedule_name'] );
		$backup_type = sanitize_text_field( $_POST['backup_type'] );
		$frequency = sanitize_text_field( $_POST['frequency'] );
		$storage_destinations = isset( $_POST['storage_destinations'] ) ? array_map( 'sanitize_text_field', $_POST['storage_destinations'] ) : array( 'local' );
		$retention_count = intval( $_POST['retention_count'] );
		$incremental_enabled = isset( $_POST['incremental_enabled'] ) ? 1 : 0;

		// Calculate next run
		$next_run = $this->calculate_next_run( $frequency );

		$data = array(
			'schedule_name' => $schedule_name,
			'backup_type' => $backup_type,
			'frequency' => $frequency,
			'storage_destinations' => wp_json_encode( $storage_destinations ),
			'retention_count' => $retention_count,
			'incremental_enabled' => $incremental_enabled,
			'next_run' => $next_run,
			'settings' => wp_json_encode( $_POST ),
		);

		if ( $schedule_id ) {
			// Update existing schedule
			$wpdb->update(
				$wpdb->prefix . 'brm_schedules',
				$data,
				array( 'id' => $schedule_id )
			);
		} else {
			// Create new schedule
			$data['schedule_type'] = 'backup';
			$data['is_active'] = 1;

			$wpdb->insert(
				$wpdb->prefix . 'brm_schedules',
				$data
			);
		}

		wp_send_json_success( __( 'Schedule saved successfully', 'backup-restore-migrate' ) );
	}

	/**
	 * Delete schedule
	 */
	public function delete_schedule() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;

		if ( ! $schedule_id ) {
			wp_send_json_error( __( 'Invalid schedule ID', 'backup-restore-migrate' ) );
		}

		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'brm_schedules',
			array( 'id' => $schedule_id )
		);

		wp_send_json_success( __( 'Schedule deleted successfully', 'backup-restore-migrate' ) );
	}

	/**
	 * Test storage connection
	 */
	public function test_storage() {
		check_ajax_referer( 'brm_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'backup-restore-migrate' ) );
		}

		$storage_type = isset( $_POST['storage_type'] ) ? sanitize_text_field( $_POST['storage_type'] ) : '';

		if ( ! $storage_type ) {
			wp_send_json_error( __( 'Invalid storage type', 'backup-restore-migrate' ) );
		}

		try {
			$storage = BRM_Storage_Factory::create( $storage_type );

			if ( $storage && $storage->test_connection() ) {
				wp_send_json_success( __( 'Connection successful!', 'backup-restore-migrate' ) );
			} else {
				wp_send_json_error( __( 'Connection failed. Please check your settings.', 'backup-restore-migrate' ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Calculate next run time
	 */
	private function calculate_next_run( $frequency ) {
		$intervals = array(
			'hourly' => '+1 hour',
			'twice_daily' => '+12 hours',
			'daily' => '+1 day',
			'weekly' => '+1 week',
			'monthly' => '+1 month',
		);

		$interval = isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : '+1 day';

		return date( 'Y-m-d H:i:s', strtotime( $interval ) );
	}
}

