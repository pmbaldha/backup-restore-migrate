<?php
/**
 * Local storage handler
 */
class BRM_Local_Storage implements BRM_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * Base directory
	 */
	private $base_dir;

	/**
	 * Constructor
	 */
	public function __construct( $settings = array() ) {
		$this->settings = $settings;

		$upload_dir = wp_upload_dir();
		$this->base_dir = $upload_dir['basedir'] . '/backup-restore-migrate/';

		if ( ! empty( $settings['directory'] ) ) {
			$this->base_dir .= trim( $settings['directory'], '/' ) . '/';
		}

		// Ensure directory exists
		if ( ! file_exists( $this->base_dir ) ) {
			wp_mkdir_p( $this->base_dir );
		}
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		$destination = $this->base_dir . $remote_file;

		// Create directory if needed
		$dir = dirname( $destination );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( copy( $local_file, $destination ) ) {
			return $destination;
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		$source = $this->base_dir . $remote_file;

		if ( ! file_exists( $source ) ) {
			return false;
		}

		return copy( $source, $local_file );
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		$file = $this->base_dir . $remote_file;

		if ( file_exists( $file ) ) {
			return unlink( $file );
		}

		return false;
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		return file_exists( $this->base_dir . $remote_file );
	}

	/**
	 * List files
	 */
	public function list_files( $directory = '' ) {
		$path = $this->base_dir . $directory;
		$files = array();

		if ( ! is_dir( $path ) ) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[] = array(
					'name' => $file->getFilename(),
					'path' => str_replace( $this->base_dir, '', $file->getPathname() ),
					'size' => $file->getSize(),
					'modified' => $file->getMTime(),
				);
			}
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		return is_writable( $this->base_dir );
	}
}