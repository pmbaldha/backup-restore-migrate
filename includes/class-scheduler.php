<?php
/**
 * Scheduler class
 */
class BMR_Scheduler {

	/**
	 * Run scheduled backups
	 */
	public static function run_scheduled_backups() {
		global $wpdb;

		// Get active schedules due for execution
		$schedules = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wpbm_schedules 
			WHERE is_active = 1 
			AND next_run <= %s",
			current_time( 'mysql' )
		) );

		foreach ( $schedules as $schedule ) {
			// Create backup
			$options = json_decode( $schedule->settings, true );
			$options['backup_type'] = $schedule->backup_type;
			$options['storage_destinations'] = json_decode( $schedule->storage_destinations, true );
			$options['incremental'] = (bool) $schedule->incremental_enabled;

			// Find parent backup for incremental
			if ( $options['incremental'] ) {
				$parent = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}wpbm_backups 
					WHERE status = 'completed' 
					AND backup_type = %s 
					ORDER BY created_at DESC 
					LIMIT 1",
					$schedule->backup_type
				) );

				if ( $parent ) {
					$options['incremental_parent'] = $parent;
				} else {
					$options['incremental'] = false;
				}
			}

			$backup_engine = new BMR_Backup_Engine( $options );
			$result = $backup_engine->create_backup();

			// Update schedule
			$next_run = self::calculate_next_run( $schedule->frequency );
			$wpdb->update(
				$wpdb->prefix . 'wpbm_schedules',
				array(
					'last_run' => current_time( 'mysql' ),
					'next_run' => $next_run,
				),
				array( 'id' => $schedule->id )
			);

			// Clean up old backups
			self::cleanup_schedule_backups( $schedule );
		}
	}

	/**
	 * Calculate next run time
	 */
	private static function calculate_next_run( $frequency ) {
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

	/**
	 * Clean up old backups for schedule
	 */
	private static function cleanup_schedule_backups( $schedule ) {
		global $wpdb;

		// Get backups for this schedule
		$backups = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wpbm_backups 
			WHERE backup_type = %s 
			AND status = 'completed' 
			ORDER BY created_at DESC",
			$schedule->backup_type
		) );

		if ( count( $backups ) > $schedule->retention_count ) {
			// Delete old backups
			$to_delete = array_slice( $backups, $schedule->retention_count );

			foreach ( $to_delete as $backup ) {
				// Delete from storage
				$locations = json_decode( $backup->backup_location, true );

				foreach ( $locations as $type => $location ) {
					try {
						$storage = BMR_Storage_Factory::create( $type );
						if ( $storage ) {
							$storage->delete( $location );
						}
					} catch ( Exception $e ) {
						// Log error but continue
						error_log( 'Failed to delete backup from storage: ' . $e->getMessage() );
					}
				}

				// Delete database record
				$wpdb->delete(
					$wpdb->prefix . 'wpbm_backups',
					array( 'id' => $backup->id )
				);
			}
		}
	}

	/**
	 * Clean up old backups (general cleanup)
	 */
	public static function cleanup_old_backups() {
		global $wpdb;

		$retention_days = get_option( 'wpbm_retain_local_backups', 5 );
		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		// Get old backups
		$old_backups = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wpbm_backups 
			WHERE created_at < %s 
			AND status = 'completed'",
			$cutoff_date
		) );

		foreach ( $old_backups as $backup ) {
			// Only delete local backups
			$locations = json_decode( $backup->backup_location, true );

			if ( isset( $locations['local'] ) && file_exists( $locations['local'] ) ) {
				unlink( $locations['local'] );

				// Update location
				unset( $locations['local'] );

				if ( empty( $locations ) ) {
					// Delete record if no remote copies
					$wpdb->delete(
						$wpdb->prefix . 'wpbm_backups',
						array( 'id' => $backup->id )
					);
				} else {
					// Update location
					$wpdb->update(
						$wpdb->prefix . 'wpbm_backups',
						array( 'backup_location' => wp_json_encode( $locations ) ),
						array( 'id' => $backup->id )
					);
				}
			}
		}

		// Clean up old logs
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wpbm_logs WHERE created_at < %s",
			$cutoff_date
		) );
	}
}