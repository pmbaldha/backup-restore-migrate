<?php
/**
 * Backup engine class
 */
class BRM_Backup_Engine {

	/**
	 * Backup ID
	 */
	private $backup_id;

	/**
	 * Backup options
	 */
	private $options;

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
		'current_file' => '',
		'files_processed' => 0,
		'total_files' => 0,
	);

	/**
	 * Constructor
	 */
	public function __construct( $options = array() ) {
		$this->options = wp_parse_args( $options, array(
			'backup_type' => 'full', // full, database, files
			'incremental' => false,
			'incremental_parent' => null,
			'compression' => true,
			'compression_level' => 5,
			'exclude_tables' => array(),
			'exclude_files' => array(),
			'storage_destinations' => array( 'local' ),
			'chunk_size' => 2048, // KB
		) );

		$this->logger = new BRM_Logger();
	}

	/**
	 * Create backup
	 */
	public function create_backup() {
		global $wpdb;

		// Set execution limits
		$this->set_execution_limits();

		// Create backup record
		$backup_name = 'backup_' . substr( md5( time() . wp_rand() ), 0, 8 ) . '_' . date( 'Y-m-d_H-i-s' );
		$wpdb->insert(
			$wpdb->prefix . 'brm_backups',
			array(
				'backup_name' => $backup_name,
				'backup_type' => $this->options['backup_type'],
				'status' => 'in_progress',
				'incremental_parent' => $this->options['incremental_parent'],
				'metadata' => wp_json_encode( $this->options ),
			)
		);

		$this->backup_id = $wpdb->insert_id;
		$this->logger->set_backup_id( $this->backup_id );

		// Update progress
		$this->update_progress( 'preparing', 5, __( 'Preparing backup...', 'backup-restore-migrate' ) );

		// Create temporary directory
		$temp_dir = $this->create_temp_directory( $backup_name );

		try {
			// Backup database
			if ( in_array( $this->options['backup_type'], array( 'full', 'database' ) ) ) {
				$this->update_progress( 'database', 10, __( 'Backing up database...', 'backup-restore-migrate' ) );
				$this->backup_database( $temp_dir );
			}

			// Backup files
			if ( in_array( $this->options['backup_type'], array( 'full', 'files' ) ) ) {
				$this->update_progress( 'files', 30, __( 'Backing up files...', 'backup-restore-migrate' ) );
				$this->backup_files( $temp_dir );
			}

			// Create archive
			$this->update_progress( 'compressing', 70, __( 'Creating archive...', 'backup-restore-migrate' ) );
			$archive_path = $this->create_archive( $temp_dir, $backup_name );

			// Upload to storage destinations
			$this->update_progress( 'uploading', 85, __( 'Uploading to storage...', 'backup-restore-migrate' ) );
			$storage_locations = $this->upload_to_storage( $archive_path );

			// Update backup record
			$wpdb->update(
				$wpdb->prefix . 'brm_backups',
				array(
					'status' => 'completed',
					'backup_size' => filesize( $archive_path ),
					'backup_location' => wp_json_encode( $storage_locations ),
					'completed_at' => current_time( 'mysql' ),
				),
				array( 'id' => $this->backup_id )
			);

			// Clean up temporary files
			$this->cleanup_temp_files( $temp_dir );

			// Update progress
			$this->update_progress( 'completed', 100, __( 'Backup completed successfully!', 'backup-restore-migrate' ) );

			// Send notification
			$this->send_notification( 'success' );

			return array(
				'success' => true,
				'backup_id' => $this->backup_id,
				'message' => __( 'Backup completed successfully!', 'backup-restore-migrate' ),
			);

		} catch ( Exception $e ) {
			// Log error
			$this->logger->error( 'Backup failed: ' . $e->getMessage() );

			// Update backup record
			$wpdb->update(
				$wpdb->prefix . 'brm_backups',
				array(
					'status' => 'failed',
					'completed_at' => current_time( 'mysql' ),
				),
				array( 'id' => $this->backup_id )
			);

			// Clean up
			$this->cleanup_temp_files( $temp_dir );
			if ( isset( $archive_path ) && file_exists( $archive_path ) ) {
				unlink( $archive_path );
			}

			// Send notification
			$this->send_notification( 'failed', $e->getMessage() );

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
		@set_time_limit( get_option( 'brm_max_execution_time', 300 ) );
		@ini_set( 'memory_limit', get_option( 'brm_memory_limit', '256M' ) );
	}

	/**
	 * Create temporary directory
	 */
	private function create_temp_directory( $backup_name ) {
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/backup-restore-migrate/temp/' . $backup_name;

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			throw new Exception( __( 'Failed to create temporary directory', 'backup-restore-migrate' ) );
		}

		return $temp_dir;
	}

	/**
	 * Backup database
	 */
	private function backup_database( $temp_dir ) {
		global $wpdb;

		$db_file = $temp_dir . '/database.sql';
		$handle = fopen( $db_file, 'w' );

		if ( ! $handle ) {
			throw new Exception( __( 'Failed to create database backup file', 'backup-restore-migrate' ) );
		}

		// Write header
		fwrite( $handle, "-- WordPress Database Backup\n" );
		fwrite( $handle, "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n" );
		fwrite( $handle, "-- Host: " . DB_HOST . "\n" );
		fwrite( $handle, "-- Database: " . DB_NAME . "\n\n" );

		// Get all tables
		$tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
		$total_tables = count( $tables );
		$current_table = 0;

		foreach ( $tables as $table ) {
			$table_name = $table[0];
			$current_table++;

			// Skip excluded tables
			if ( in_array( $table_name, $this->options['exclude_tables'] ) ) {
				continue;
			}

			// Update progress
			$percentage = 10 + ( ( $current_table / $total_tables ) * 20 );
			$this->update_progress( 'database', $percentage, sprintf( __( 'Backing up table: %s', 'backup-restore-migrate' ), $table_name ) );

			// Drop table statement
			fwrite( $handle, "DROP TABLE IF EXISTS `$table_name`;\n" );

			// Create table statement
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_N );
			fwrite( $handle, $create_table[1] . ";\n\n" );

			// Insert data
			$row_count = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );

			if ( $row_count > 0 ) {
				$offset = 0;
				$chunk_size = 1000;

				while ( $offset < $row_count ) {
					$rows = $wpdb->get_results( "SELECT * FROM `$table_name` LIMIT $offset, $chunk_size", ARRAY_A );

					foreach ( $rows as $row ) {
						$values = array();
						foreach ( $row as $value ) {
							if ( is_null( $value ) ) {
								$values[] = 'NULL';
							} else {
								$values[] = "'" . $wpdb->_real_escape( $value ) . "'";
							}
						}

						fwrite( $handle, "INSERT INTO `$table_name` VALUES (" . implode( ',', $values ) . ");\n" );
					}

					$offset += $chunk_size;
				}

				fwrite( $handle, "\n" );
			}
		}

		fclose( $handle );

		$this->logger->info( 'Database backup completed' );
	}

	/**
	 * Backup files
	 */
	private function backup_files( $temp_dir ) {
		$files_dir = $temp_dir . '/files';
		wp_mkdir_p( $files_dir );

		// Get WordPress root directory
		$wp_root = ABSPATH;

		// Get list of files to backup
		$files_to_backup = $this->get_files_to_backup( $wp_root );
		$total_files = count( $files_to_backup );
		$this->progress['total_files'] = $total_files;

		// Copy files
		$current_file = 0;
		foreach ( $files_to_backup as $file ) {
			$current_file++;
			$this->progress['files_processed'] = $current_file;

			// Calculate relative path
			$relative_path = str_replace( $wp_root, '', $file );
			$destination = $files_dir . '/' . $relative_path;

			// Create directory if needed
			$dest_dir = dirname( $destination );
			if ( ! file_exists( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			// Copy file
			if ( ! copy( $file, $destination ) ) {
				$this->logger->warning( "Failed to copy file: $file" );
			}

			// Update progress
			if ( $current_file % 100 === 0 ) {
				$percentage = 30 + ( ( $current_file / $total_files ) * 40 );
				$this->update_progress( 'files', $percentage, sprintf( __( 'Backing up files: %d/%d', 'backup-restore-migrate' ), $current_file, $total_files ) );
			}
		}

		// Create backup info file
		$this->create_backup_info( $temp_dir );

		$this->logger->info( "Files backup completed. Total files: $total_files" );
	}

	/**
	 * Get files to backup
	 */
	private function get_files_to_backup( $directory ) {
		$files = array();
		$exclude_patterns = $this->get_exclude_patterns();

		// If incremental backup, get changed files only
		if ( $this->options['incremental'] && $this->options['incremental_parent'] ) {
			return $this->get_incremental_files( $directory );
		}

		// Get all files
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$file_path = $file->getPathname();

				// Check exclusions
				$exclude = false;
				foreach ( $exclude_patterns as $pattern ) {
					if ( strpos( $file_path, $pattern ) !== false ) {
						$exclude = true;
						break;
					}
				}

				if ( ! $exclude ) {
					$files[] = $file_path;
				}
			}
		}

		return $files;
	}

	/**
	 * Get incremental files
	 */
	private function get_incremental_files( $directory ) {
		global $wpdb;

		// Get parent backup date
		$parent_backup = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}brm_backups WHERE id = %d",
			$this->options['incremental_parent']
		) );

		if ( ! $parent_backup ) {
			throw new Exception( __( 'Parent backup not found', 'backup-restore-migrate' ) );
		}

		$parent_date = strtotime( $parent_backup->created_at );
		$files = array();
		$exclude_patterns = $this->get_exclude_patterns();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getMTime() > $parent_date ) {
				$file_path = $file->getPathname();

				// Check exclusions
				$exclude = false;
				foreach ( $exclude_patterns as $pattern ) {
					if ( strpos( $file_path, $pattern ) !== false ) {
						$exclude = true;
						break;
					}
				}

				if ( ! $exclude ) {
					$files[] = $file_path;
				}
			}
		}

		return $files;
	}

	/**
	 * Get exclude patterns
	 */
	private function get_exclude_patterns() {
		$default_excludes = array(
			'wp-content/cache',
			'wp-content/uploads/backup-restore-migrate',
			'.git',
			'.svn',
			'node_modules',
			'.DS_Store',
			'Thumbs.db',
		);

		$user_excludes = get_option( 'brm_exclude_files', array() );

		return array_merge( $default_excludes, $user_excludes, $this->options['exclude_files'] );
	}

	/**
	 * Create backup info file
	 */
	private function create_backup_info( $temp_dir ) {
		$info = array(
			'plugin_version' => BRM_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'mysql_version' => $this->get_mysql_version(),
			'site_url' => get_site_url(),
			'home_url' => get_home_url(),
			'backup_date' => current_time( 'mysql' ),
			'backup_type' => $this->options['backup_type'],
			'incremental' => $this->options['incremental'],
			'incremental_parent' => $this->options['incremental_parent'],
			'table_prefix' => $GLOBALS['wpdb']->prefix,
			'charset' => get_option( 'blog_charset' ),
			'multisite' => is_multisite(),
		);

		file_put_contents(
			$temp_dir . '/backup-info.json',
			wp_json_encode( $info, JSON_PRETTY_PRINT )
		);
	}

	/**
	 * Get MySQL version
	 */
	private function get_mysql_version() {
		global $wpdb;
		return $wpdb->get_var( "SELECT VERSION()" );
	}

	/**
	 * Create archive
	 */
	private function create_archive( $temp_dir, $backup_name ) {
		$upload_dir = wp_upload_dir();
		$archive_path = $upload_dir['basedir'] . '/backup-restore-migrate/' . $backup_name . '.zip';

		// Create ZIP archive
		$zip = new ZipArchive();
		$result = $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( $result !== true ) {
			throw new Exception( __( 'Failed to create archive', 'backup-restore-migrate' ) );
		}

		// Add files to archive
		$this->add_files_to_zip( $zip, $temp_dir, '' );

		// Set compression level
		$zip->setCompressionIndex( 0, $this->options['compression_level'] );

		$zip->close();

		return $archive_path;
	}

	/**
	 * Add files to ZIP recursively
	 */
	private function add_files_to_zip( $zip, $source, $prefix = '' ) {
		$source = rtrim( $source, '/' );

		if ( is_dir( $source ) ) {
			$files = scandir( $source );

			foreach ( $files as $file ) {
				if ( $file === '.' || $file === '..' ) {
					continue;
				}

				$file_path = $source . '/' . $file;
				$zip_path = $prefix ? $prefix . '/' . $file : $file;

				if ( is_dir( $file_path ) ) {
					$zip->addEmptyDir( $zip_path );
					$this->add_files_to_zip( $zip, $file_path, $zip_path );
				} else {
					$zip->addFile( $file_path, $zip_path );
				}
			}
		}
	}

	/**
	 * Upload to storage destinations
	 */
	private function upload_to_storage( $archive_path ) {
		$storage_locations = array();

		foreach ( $this->options['storage_destinations'] as $destination ) {
			try {
				$storage = BRM_Storage_Factory::create( $destination );

				if ( $storage ) {
					$result = $storage->upload( $archive_path, basename( $archive_path ) );

					if ( $result ) {
						$storage_locations[ $destination ] = $result;
						$this->logger->info( "Uploaded to $destination successfully" );
					}
				}
			} catch ( Exception $e ) {
				$this->logger->error( "Failed to upload to $destination: " . $e->getMessage() );
			}
		}

		// Keep local copy if not in destinations
		if ( ! in_array( 'local', $this->options['storage_destinations'] ) ) {
			unlink( $archive_path );
		} else {
			$storage_locations['local'] = $archive_path;
		}

		return $storage_locations;
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
	 * Update progress
	 */
	private function update_progress( $status, $percentage, $message, $current_file = '' ) {
		$this->progress['status'] = $status;
		$this->progress['percentage'] = $percentage;
		$this->progress['message'] = $message;
		$this->progress['current_file'] = $current_file;

		// Save to transient for AJAX polling
		set_transient( 'brm_backup_progress_' . $this->backup_id, $this->progress, 300 );
	}

	/**
	 * Send notification
	 */
	private function send_notification( $status, $error_message = '' ) {
		if ( ! get_option( 'brm_email_notifications', true ) ) {
			return;
		}

		$to = get_option( 'brm_notification_email', get_option( 'admin_email' ) );
		$subject = sprintf(
			'[%s] %s',
			get_bloginfo( 'name' ),
			$status === 'success'
				? __( 'Backup completed successfully', 'backup-restore-migrate' )
				: __( 'Backup failed', 'backup-restore-migrate' )
		);

		$message = $status === 'success'
			? __( 'Your WordPress backup has been completed successfully.', 'backup-restore-migrate' )
			: sprintf( __( 'Your WordPress backup has failed with the following error: %s', 'backup-restore-migrate' ), $error_message );

		wp_mail( $to, $subject, $message );
	}
}
