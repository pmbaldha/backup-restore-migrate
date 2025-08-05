<?php
/**
 * SFTP storage handler
 */
class BMR_SFTP_Storage implements BMR_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * SSH connection
	 */
	private $connection;

	/**
	 * SFTP resource
	 */
	private $sftp;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'host' => '',
			'port' => 22,
			'username' => '',
			'password' => '',
			'private_key' => '',
			'private_key_password' => '',
			'directory' => '/',
			'timeout' => 90,
		) );

		if ( ! function_exists( 'ssh2_connect' ) ) {
			throw new Exception( __( 'SSH2 extension is not installed', 'backup-restore-migrate' ) );
		}
	}

	/**
	 * Connect to SFTP
	 */
	private function connect() {
		if ( $this->sftp ) {
			return true;
		}

		// Create connection
		$this->connection = @ssh2_connect(
			$this->settings['host'],
			$this->settings['port'],
			array( 'timeout' => $this->settings['timeout'] )
		);

		if ( ! $this->connection ) {
			throw new Exception( __( 'Failed to connect to SFTP server', 'backup-restore-migrate' ) );
		}

		// Authenticate
		if ( ! empty( $this->settings['private_key'] ) ) {
			// Key-based authentication
			if ( ! @ssh2_auth_pubkey_file(
				$this->connection,
				$this->settings['username'],
				$this->settings['private_key'] . '.pub',
				$this->settings['private_key'],
				$this->settings['private_key_password']
			) ) {
				throw new Exception( __( 'SFTP key authentication failed', 'backup-restore-migrate' ) );
			}
		} else {
			// Password authentication
			if ( ! @ssh2_auth_password(
				$this->connection,
				$this->settings['username'],
				$this->settings['password']
			) ) {
				throw new Exception( __( 'SFTP authentication failed', 'backup-restore-migrate' ) );
			}
		}

		// Initialize SFTP
		$this->sftp = @ssh2_sftp( $this->connection );

		if ( ! $this->sftp ) {
			throw new Exception( __( 'Failed to initialize SFTP', 'backup-restore-migrate' ) );
		}

		return true;
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		$this->connect();

		$remote_path = $this->settings['directory'] . '/' . $remote_file;

		// Create directory if needed
		$dir = dirname( $remote_path );
		if ( $dir !== '.' && $dir !== '/' ) {
			$this->create_directory( $dir );
		}

		// Upload file
		$stream = @fopen( "ssh2.sftp://{$this->sftp}{$remote_path}", 'w' );

		if ( ! $stream ) {
			return false;
		}

		$local_stream = @fopen( $local_file, 'r' );

		if ( ! $local_stream ) {
			fclose( $stream );
			return false;
		}

		$result = stream_copy_to_stream( $local_stream, $stream );

		fclose( $local_stream );
		fclose( $stream );

		if ( $result !== false ) {
			return $remote_path;
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		$this->connect();

		$remote_path = $this->settings['directory'] . '/' . $remote_file;

		$stream = @fopen( "ssh2.sftp://{$this->sftp}{$remote_path}", 'r' );

		if ( ! $stream ) {
			return false;
		}

		$local_stream = @fopen( $local_file, 'w' );

		if ( ! $local_stream ) {
			fclose( $stream );
			return false;
		}

		$result = stream_copy_to_stream( $stream, $local_stream );

		fclose( $stream );
		fclose( $local_stream );

		return $result !== false;
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		$this->connect();

		$remote_path = $this->settings['directory'] . '/' . $remote_file;

		return @ssh2_sftp_unlink( $this->sftp, $remote_path );
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		$this->connect();

		$remote_path = $this->settings['directory'] . '/' . $remote_file;

		return @ssh2_sftp_stat( $this->sftp, $remote_path ) !== false;
	}

	/**
	 * List files
	 */
	public function list_files( $directory = '' ) {
		$this->connect();

		$path = $this->settings['directory'] . '/' . $directory;
		$files = array();

		$handle = @opendir( "ssh2.sftp://{$this->sftp}{$path}" );

		if ( $handle ) {
			while ( ( $file = readdir( $handle ) ) !== false ) {
				if ( $file !== '.' && $file !== '..' ) {
					$file_path = $path . '/' . $file;
					$stat = @ssh2_sftp_stat( $this->sftp, $file_path );

					if ( $stat && ( $stat['mode'] & 0100000 ) ) { // Regular file
						$files[] = array(
							'name' => $file,
							'path' => $file_path,
							'size' => $stat['size'],
							'modified' => $stat['mtime'],
						);
					}
				}
			}
			closedir( $handle );
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

			if ( ! @ssh2_sftp_stat( $this->sftp, $path ) ) {
				@ssh2_sftp_mkdir( $this->sftp, $path );
			}
		}
	}
}