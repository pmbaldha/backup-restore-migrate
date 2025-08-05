<?php
/**
 * FTP storage handler
 */
class BRM_FTP_Storage implements BRM_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * FTP connection
	 */
	private $connection;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'host' => '',
			'port' => 21,
			'username' => '',
			'password' => '',
			'directory' => '/',
			'passive' => true,
			'ssl' => false,
			'timeout' => 90,
		) );
	}

	/**
	 * Connect to FTP
	 */
	private function connect() {
		if ( $this->connection ) {
			return true;
		}

		// Create connection
		if ( $this->settings['ssl'] && function_exists( 'ftp_ssl_connect' ) ) {
			$this->connection = @ftp_ssl_connect(
				$this->settings['host'],
				$this->settings['port'],
				$this->settings['timeout']
			);
		} else {
			$this->connection = @ftp_connect(
				$this->settings['host'],
				$this->settings['port'],
				$this->settings['timeout']
			);
		}

		if ( ! $this->connection ) {
			throw new Exception( __( 'Failed to connect to FTP server', 'backup-restore-migrate' ) );
		}

		// Login
		if ( ! @ftp_login( $this->connection, $this->settings['username'], $this->settings['password'] ) ) {
			throw new Exception( __( 'FTP login failed', 'backup-restore-migrate' ) );
		}

		// Set passive mode
		if ( $this->settings['passive'] ) {
			ftp_pasv( $this->connection, true );
		}

		// Change directory
		if ( ! empty( $this->settings['directory'] ) && $this->settings['directory'] !== '/' ) {
			if ( ! @ftp_chdir( $this->connection, $this->settings['directory'] ) ) {
				// Try to create directory
				$this->create_directory( $this->settings['directory'] );
				ftp_chdir( $this->connection, $this->settings['directory'] );
			}
		}

		return true;
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		$this->connect();

		// Create directory if needed
		$dir = dirname( $remote_file );
		if ( $dir !== '.' && $dir !== '/' ) {
			$this->create_directory( $dir );
		}

		// Upload file
		$result = @ftp_put( $this->connection, $remote_file, $local_file, FTP_BINARY );

		if ( $result ) {
			return $this->settings['directory'] . '/' . $remote_file;
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		$this->connect();

		return @ftp_get( $this->connection, $local_file, $remote_file, FTP_BINARY );
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		$this->connect();

		return @ftp_delete( $this->connection, $remote_file );
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		$this->connect();

		$size = @ftp_size( $this->connection, $remote_file );

		return $size !== -1;
	}

	/**
	 * List files
	 */
	public function list_files( $directory = '' ) {
		$this->connect();

		$files = array();
		$list = @ftp_nlist( $this->connection, $directory );

		if ( $list ) {
			foreach ( $list as $file ) {
				$size = @ftp_size( $this->connection, $file );
				if ( $size !== -1 ) {
					$files[] = array(
						'name' => basename( $file ),
						'path' => $file,
						'size' => $size,
						'modified' => ftp_mdtm( $this->connection, $file ),
					);
				}
			}
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			$this->connect();
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Create directory
	 */
	private function create_directory( $directory ) {
		$parts = explode( '/', trim( $directory, '/' ) );
		$path = '';

		foreach ( $parts as $part ) {
			$path .= '/' . $part;

			if ( ! @ftp_chdir( $this->connection, $path ) ) {
				@ftp_mkdir( $this->connection, $path );
			}
		}
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		if ( $this->connection ) {
			@ftp_close( $this->connection );
		}
	}
}

