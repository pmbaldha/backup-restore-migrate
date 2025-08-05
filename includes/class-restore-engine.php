<?php
// --- includes/class-restore-engine.php ---
/**
 * Restore engine class
 */
class BRM_Restore_Engine {

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Progress data
	 */
	private $progress = array(
		'status' => 'initializing',
		'percentage' => 0,
		'message' => '',
	);

	/**
	 * Restore ID for progress tracking
	 */
	private $restore_id;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger = new BRM_Logger();
		$this->restore_id = uniqid( 'restore_' );
	}

	/**
	 * Restore backup
	 */
	public function restore_backup( $backup_id, $options = array() ) {
		global $wpdb;

		// Set execution limits
		$this->set_execution_limits();

		// Get backup details
		$backup = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bmr_backups WHERE id = %d",
			$backup_id
		) );

		if ( ! $backup ) {
			throw new Exception( __( 'Backup not found', 'backup-restore-migrate' ) );
		}

		$this->logger->set_backup_id( $backup_id );

		// Update progress
		$this->update_progress( 'downloading', 5, __( 'Downloading backup...', 'backup-restore-migrate' ) );

		try {
			// Download backup if not local
			$archive_path = $this->download_backup( $backup );

			// Extract archive
			$this->update_progress( 'extracting', 20, __( 'Extracting backup...', 'backup-restore-migrate' ) );
			$temp_dir = $this->extract_archive( $archive_path );

			// Validate backup
			$this->update_progress( 'validating', 30, __( 'Validating backup...', 'backup-restore-migrate' ) );
			$backup_info = $this->validate_backup( $temp_dir );

			// Create restore point
			if ( ! empty( $options['create_restore_point'] ) ) {
				$this->update_progress( 'restore_point', 40, __( 'Creating restore point...', 'backup-restore-migrate' ) );
				$this->create_restore_point();
			}

			// Restore database
			if ( $backup->backup_type === 'full' || $backup->backup_type === 'database' ) {
				$this->update_progress( 'database', 50, __( 'Restoring database...', 'backup-restore-migrate' ) );
				$this->restore_database( $temp_dir . '/database.sql', $backup_info );
			}

			// Restore files
			if ( $backup->backup_type === 'full' || $backup->backup_type === 'files' ) {
				$this->update_progress( 'files', 70, __( 'Restoring files...', 'backup-restore-migrate' ) );
				$this->restore_files( $temp_dir . '/files' );
			}

			// Update URLs if needed
			if ( ! empty( $options['update_urls'] ) ) {
				$this->update_progress( 'urls', 85, __( 'Updating URLs...', 'backup-restore-migrate' ) );
				$this->update_urls( $backup_info['site_url'], get_site_url() );
			}

			// Clean up
			$this->cleanup_temp_files( $temp_dir );

			// Clear caches
			$this->clear_caches();

			// Update progress
			$this->update_progress( 'completed', 100, __( 'Restore completed successfully!', 'backup-restore-migrate' ) );

			$this->logger->info( 'Restore completed successfully' );

			return array(
				'success' => true,
				'message' => __( 'Restore completed successfully!', 'backup-restore-migrate' ),
			);

		} catch ( Exception $e ) {
			$this->logger->error( 'Restore failed: ' . $e->getMessage() );

			// Clean up
			if ( isset( $temp_dir ) ) {
				$this->cleanup_temp_files( $temp_dir );
			}

			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Set execution limits
	 */
	private function set_execution_limits() {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );
	}

	/**
	 * Download backup
	 */
	private function download_backup( $backup ) {
		$locations = json_decode( $backup->backup_location, true );

		// Check if local copy exists
		if ( isset( $locations['local'] ) && file_exists( $locations['local'] ) ) {
			return $locations['local'];
		}

		// Download from remote storage
		$upload_dir = wp_upload_dir();
		$local_path = $upload_dir['basedir'] . '/backup-restore-migrate/temp/' . basename( $backup->backup_name ) . '.zip';

		foreach ( $locations as $type => $location ) {
			if ( $type === 'local' ) {
				continue;
			}

			try {
				$storage = BRM_Storage_Factory::create( $type );
				if ( $storage && $storage->download( $location, $local_path ) ) {
					return $local_path;
				}
			} catch ( Exception $e ) {
				$this->logger->warning( "Failed to download from $type: " . $e->getMessage() );
			}
		}

		throw new Exception( __( 'Failed to download backup from storage', 'backup-restore-migrate' ) );
	}

	/**
	 * Extract archive
	 */
	private function extract_archive( $archive_path ) {
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/backup-restore-migrate/temp/restore_' . uniqid();

		wp_mkdir_p( $temp_dir );

		$zip = new ZipArchive();
		$result = $zip->open( $archive_path );

		if ( $result !== true ) {
			throw new Exception( __( 'Failed to open archive', 'backup-restore-migrate' ) );
		}

		$zip->extractTo( $temp_dir );
		$zip->close();

		return $temp_dir;
	}

	/**
	 * Validate backup
	 */
	private function validate_backup( $temp_dir ) {
		$info_file = $temp_dir . '/backup-info.json';

		if ( ! file_exists( $info_file ) ) {
			throw new Exception( __( 'Invalid backup: missing backup info', 'backup-restore-migrate' ) );
		}

		$backup_info = json_decode( file_get_contents( $info_file ), true );

		// Check compatibility
		if ( version_compare( $backup_info['wordpress_version'], get_bloginfo( 'version' ), '>' ) ) {
			$this->logger->warning( 'Backup from newer WordPress version' );
		}

		if ( version_compare( $backup_info['php_version'], PHP_VERSION, '>' ) ) {
			$this->logger->warning( 'Backup from newer PHP version' );
		}

		return $backup_info;
	}

	/**
	 * Create restore point
	 */
	private function create_restore_point() {
		$backup_engine = new BRM_Backup_Engine( array(
			'backup_type' => 'full',
			'storage_destinations' => array( 'local' ),
		) );

		$result = $backup_engine->create_backup();

		if ( ! $result['success'] ) {
			$this->logger->warning( 'Failed to create restore point: ' . $result['message'] );
		}
	}

	/**
	 * Restore database
	 */
	private function restore_database( $sql_file, $backup_info ) {
		global $wpdb;

		if ( ! file_exists( $sql_file ) ) {
			throw new Exception( __( 'Database backup file not found', 'backup-restore-migrate' ) );
		}

		// Read SQL file
		$sql_content = file_get_contents( $sql_file );

		// Replace table prefix if different
		if ( $backup_info['table_prefix'] !== $wpdb->prefix ) {
			$sql_content = str_replace(
				'`' . $backup_info['table_prefix'],
				'`' . $wpdb->prefix,
				$sql_content
			);
		}

		// Split into queries
		$queries = $this->split_sql_file( $sql_content );
		$total_queries = count( $queries );
		$current_query = 0;

		// Execute queries
		foreach ( $queries as $query ) {
			$current_query++;

			if ( trim( $query ) === '' ) {
				continue;
			}

			$result = $wpdb->query( $query );

			if ( $result === false ) {
				$this->logger->error( 'Database query failed: ' . $wpdb->last_error );
				throw new Exception( sprintf( __( 'Database restore failed at query %d', 'backup-restore-migrate' ), $current_query ) );
			}

			// Update progress
			if ( $current_query % 100 === 0 ) {
				$percentage = 50 + ( ( $current_query / $total_queries ) * 20 );
				$this->update_progress( 'database', $percentage, sprintf( __( 'Executing queries: %d/%d', 'backup-restore-migrate' ), $current_query, $total_queries ) );
			}
		}

		$this->logger->info( "Database restored. Total queries: $total_queries" );
	}

	/**
	 * Split SQL file into queries
	 */
	private function split_sql_file( $sql ) {
		$queries = array();
		$current_query = '';
		$in_string = false;
		$string_char = '';

		for ( $i = 0; $i < strlen( $sql ); $i++ ) {
			$char = $sql[ $i ];
			$next_char = isset( $sql[ $i + 1 ] ) ? $sql[ $i + 1 ] : '';

			// Handle strings
			if ( $in_string ) {
				$current_query .= $char;

				if ( $char === $string_char && $sql[ $i - 1 ] !== '\\' ) {
					$in_string = false;
				}
			} else {
				if ( $char === '"' || $char === "'" ) {
					$in_string = true;
					$string_char = $char;
				}

				$current_query .= $char;

				// Check for query end
				if ( $char === ';' && ! $in_string ) {
					$queries[] = trim( $current_query );
					$current_query = '';
				}
			}
		}

		if ( trim( $current_query ) !== '' ) {
			$queries[] = trim( $current_query );
		}

		return $queries;
	}

	/**
	 * Restore files
	 */
	private function restore_files( $files_dir ) {
		if ( ! file_exists( $files_dir ) || ! is_dir( $files_dir ) ) {
			$this->logger->warning( 'Files directory not found in backup' );
			return;
		}

		$wp_root = ABSPATH;
		$this->copy_directory( $files_dir, $wp_root );

		$this->logger->info( 'Files restored successfully' );
	}

	/**
	 * Copy directory recursively
	 */
	private function copy_directory( $source, $destination ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$source_path = $file->getPathname();
			$relative_path = str_replace( $source, '', $source_path );
			$dest_path = $destination . $relative_path;

			if ( $file->isDir() ) {
				wp_mkdir_p( $dest_path );
			} else {
				// Create directory if needed
				$dest_dir = dirname( $dest_path );
				if ( ! file_exists( $dest_dir ) ) {
					wp_mkdir_p( $dest_dir );
				}

				// Copy file
				if ( ! copy( $source_path, $dest_path ) ) {
					$this->logger->warning( "Failed to copy file: $source_path" );
				}
			}
		}
	}

	/**
	 * Update URLs
	 */
	private function update_urls( $old_url, $new_url ) {
		if ( $old_url === $new_url ) {
			return;
		}

		global $wpdb;

		// Update options
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s",
			$old_url,
			$new_url,
			'%' . $wpdb->esc_like( $old_url ) . '%'
		) );

		// Update posts
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)",
			$old_url,
			$new_url
		) );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s)",
			$old_url,
			$new_url
		) );

		// Update postmeta
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
			$old_url,
			$new_url,
			'%' . $wpdb->esc_like( $old_url ) . '%'
		) );

		$this->logger->info( "URLs updated from $old_url to $new_url" );
	}

	/**
	 * Clean up temporary files
	 */
	private function cleanup_temp_files( $temp_dir ) {
		if ( file_exists( $temp_dir ) ) {
			$this->delete_directory( $temp_dir );
		}
	}

	/**
	 * Delete directory recursively
	 */
	private function delete_directory( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;

			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Clear caches
	 */
	private function clear_caches() {
		// Clear WordPress cache
		wp_cache_flush();

		// Clear opcache if available
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}

		// Clear object cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Trigger action for third-party cache plugins
		do_action( 'bmr_clear_caches' );
	}

	/**
	 * Update progress
	 */
	private function update_progress( $status, $percentage, $message ) {
		$this->progress['status'] = $status;
		$this->progress['percentage'] = $percentage;
		$this->progress['message'] = $message;

		// Save to transient for AJAX polling
		set_transient( 'bmr_restore_progress_' . $this->restore_id, $this->progress, 300 );
	}

	/**
	 * Get progress
	 */
	public function get_progress() {
		return get_transient( 'bmr_restore_progress_' . $this->restore_id );
	}
}
