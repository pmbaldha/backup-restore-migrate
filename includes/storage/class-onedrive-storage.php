<?php
/**
 * OneDrive storage handler
 */
class BRM_OneDrive_Storage implements BRM_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * Access token
	 */
	private $access_token;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'client_id' => '',
			'client_secret' => '',
			'refresh_token' => '',
			'access_token' => '',
			'folder_path' => '/Apps/WordPress-Backup',
		) );

		// Get access token
		if ( ! empty( $this->settings['refresh_token'] ) ) {
			$this->refresh_access_token();
		} else {
			$this->access_token = $this->settings['access_token'];
		}
	}

	/**
	 * Refresh access token
	 */
	private function refresh_access_token() {
		if ( empty( $this->settings['refresh_token'] ) || empty( $this->settings['client_id'] ) || empty( $this->settings['client_secret'] ) ) {
			return false;
		}

		$response = wp_remote_post( 'https://login.microsoftonline.com/common/oauth2/v2.0/token', array(
			'body' => array(
				'client_id' => $this->settings['client_id'],
				'client_secret' => $this->settings['client_secret'],
				'refresh_token' => $this->settings['refresh_token'],
				'grant_type' => 'refresh_token',
				'scope' => 'files.readwrite.all offline_access',
			),
		) );

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['access_token'] ) ) {
				$this->access_token = $body['access_token'];
				$this->settings['access_token'] = $body['access_token'];
				
				// Update stored settings with new access token
				update_option( 'brm_storage_onedrive', $this->settings );
				
				return true;
			}
		}

		return false;
	}

	/**
	 * Make API request
	 */
	private function api_request( $endpoint, $method = 'GET', $data = null, $headers = array() ) {
		$url = 'https://graph.microsoft.com/v1.0' . $endpoint;

		$default_headers = array(
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type' => 'application/json',
		);

		$headers = array_merge( $default_headers, $headers );

		$args = array(
			'method' => $method,
			'headers' => $headers,
			'timeout' => 300,
		);

		if ( $data !== null && $method !== 'GET' ) {
			$args['body'] = is_array( $data ) ? json_encode( $data ) : $data;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			$error = json_decode( $body, true );
			throw new Exception( isset( $error['error']['message'] ) ? $error['error']['message'] : 'API request failed' );
		}

		return json_decode( $body, true );
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		try {
			$file_size = filesize( $local_file );
			$file_path = $this->get_full_path( $remote_file );

			// For files larger than 4MB, use upload session
			if ( $file_size > 4 * 1024 * 1024 ) {
				return $this->upload_large_file( $local_file, $file_path );
			}

			// Simple upload for smaller files
			$file_content = file_get_contents( $local_file );

			$response = wp_remote_request(
				'https://graph.microsoft.com/v1.0/me/drive/root:' . $file_path . ':/content',
				array(
					'method' => 'PUT',
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->access_token,
						'Content-Type' => 'application/octet-stream',
					),
					'body' => $file_content,
					'timeout' => 300,
				)
			);

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 201 ) {
				$result = json_decode( wp_remote_retrieve_body( $response ), true );
				return $result['id'];
			}

			return false;
		} catch ( Exception $e ) {
			error_log( 'OneDrive upload error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Upload large file using upload session
	 */
	private function upload_large_file( $local_file, $remote_path ) {
		// Create upload session
		$session_response = $this->api_request(
			'/me/drive/root:' . $remote_path . ':/createUploadSession',
			'POST',
			array(
				'item' => array(
					'@microsoft.graph.conflictBehavior' => 'replace',
				),
			)
		);

		if ( ! isset( $session_response['uploadUrl'] ) ) {
			return false;
		}

		$upload_url = $session_response['uploadUrl'];
		$file_size = filesize( $local_file );
		$chunk_size = 10 * 1024 * 1024; // 10MB chunks
		$handle = fopen( $local_file, 'rb' );

		$offset = 0;
		while ( $offset < $file_size ) {
			$chunk_end = min( $offset + $chunk_size - 1, $file_size - 1 );
			$chunk_length = $chunk_end - $offset + 1;

			fseek( $handle, $offset );
			$chunk = fread( $handle, $chunk_length );

			$response = wp_remote_request( $upload_url, array(
				'method' => 'PUT',
				'headers' => array(
					'Content-Length' => $chunk_length,
					'Content-Range' => 'bytes ' . $offset . '-' . $chunk_end . '/' . $file_size,
				),
				'body' => $chunk,
				'timeout' => 300,
			) );

			if ( is_wp_error( $response ) ) {
				fclose( $handle );
				return false;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code !== 202 && $code !== 201 && $code !== 200 ) {
				fclose( $handle );
				return false;
			}

			// If upload is complete (201 or 200), return the file ID
			if ( $code === 201 || $code === 200 ) {
				$result = json_decode( wp_remote_retrieve_body( $response ), true );
				fclose( $handle );
				return isset( $result['id'] ) ? $result['id'] : true;
			}

			$offset = $chunk_end + 1;
		}

		fclose( $handle );
		return true;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		try {
			$file_path = $this->get_full_path( $remote_file );

			// Get download URL
			$response = $this->api_request( '/me/drive/root:' . $file_path );

			if ( isset( $response['@microsoft.graph.downloadUrl'] ) ) {
				$download_url = $response['@microsoft.graph.downloadUrl'];

				// Download file
				$file_response = wp_remote_get( $download_url, array(
					'timeout' => 300,
					'stream' => true,
					'filename' => $local_file,
				) );

				return ! is_wp_error( $file_response ) && file_exists( $local_file );
			}

			return false;
		} catch ( Exception $e ) {
			error_log( 'OneDrive download error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		try {
			$file_path = $this->get_full_path( $remote_file );
			$this->api_request( '/me/drive/root:' . $file_path, 'DELETE' );
			return true;
		} catch ( Exception $e ) {
			error_log( 'OneDrive delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		try {
			$file_path = $this->get_full_path( $remote_file );
			$this->api_request( '/me/drive/root:' . $file_path );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * List files
	 */
	public function list_files( $directory = '' ) {
		$files = array();

		try {
			$folder_path = $this->get_full_path( $directory );
			
			// Get folder contents
			$response = $this->api_request( '/me/drive/root:' . $folder_path . ':/children' );

			if ( isset( $response['value'] ) ) {
				foreach ( $response['value'] as $item ) {
					if ( ! isset( $item['folder'] ) ) { // Only files, not folders
						$files[] = array(
							'name' => $item['name'],
							'path' => $item['id'],
							'size' => isset( $item['size'] ) ? $item['size'] : 0,
							'modified' => strtotime( $item['lastModifiedDateTime'] ),
						);
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'OneDrive list error: ' . $e->getMessage() );
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			// Try to get user info
			$response = $this->api_request( '/me' );
			return isset( $response['id'] );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get full path
	 */
	private function get_full_path( $file ) {
		$folder = trim( $this->settings['folder_path'], '/' );
		$file = trim( $file, '/' );

		if ( empty( $folder ) ) {
			return '/' . $file;
		}

		return '/' . $folder . '/' . $file;
	}

	/**
	 * Get authorization URL
	 */
	public function get_auth_url() {
		$params = array(
			'client_id' => $this->settings['client_id'],
			'response_type' => 'code',
			'redirect_uri' => admin_url( 'admin.php?page=brm-settings&tab=storage&type=onedrive' ),
			'scope' => 'files.readwrite.all offline_access',
			'response_mode' => 'query',
		);

		return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query( $params );
	}

	/**
	 * Handle authorization callback
	 */
	public function handle_auth_callback( $code ) {
		$response = wp_remote_post( 'https://login.microsoftonline.com/common/oauth2/v2.0/token', array(
			'body' => array(
				'client_id' => $this->settings['client_id'],
				'client_secret' => $this->settings['client_secret'],
				'code' => $code,
				'redirect_uri' => admin_url( 'admin.php?page=brm-settings&tab=storage&type=onedrive' ),
				'grant_type' => 'authorization_code',
			),
		) );

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['access_token'] ) && isset( $body['refresh_token'] ) ) {
				$this->settings['access_token'] = $body['access_token'];
				$this->settings['refresh_token'] = $body['refresh_token'];
				update_option( 'brm_storage_onedrive', $this->settings );
				return true;
			}
		}

		return false;
	}
}