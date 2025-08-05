<?php
/**
 * Dropbox storage handler
 */
class BMR_Dropbox_Storage implements BMR_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * Dropbox client
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'access_token' => '',
			'app_key' => '',
			'app_secret' => '',
			'folder' => '/backup-restore-migrate',
		) );
	}

	/**
	 * Get Dropbox client
	 */
	private function get_client() {
		if ( $this->client ) {
			return $this->client;
		}

		// Simple Dropbox API implementation
		$this->client = new stdClass();
		$this->client->access_token = $this->settings['access_token'];

		return $this->client;
	}

	/**
	 * Make API request
	 */
	private function api_request( $endpoint, $data = null, $type = 'rpc' ) {
		$client = $this->get_client();

		$headers = array(
			'Authorization: Bearer ' . $client->access_token,
		);

		$url = 'https://api.dropboxapi.com/2/';

		if ( $type === 'content' ) {
			$url = 'https://content.dropboxapi.com/2/';
			$headers[] = 'Dropbox-API-Arg: ' . json_encode( $data );
			$data = null;
		} else {
			$headers[] = 'Content-Type: application/json';
		}

		$args = array(
			'method' => 'POST',
			'headers' => $headers,
			'timeout' => 300,
		);

		if ( $data !== null && $type === 'rpc' ) {
			$args['body'] = json_encode( $data );
		}

		$response = wp_remote_post( $url . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$error = json_decode( $body, true );
			throw new Exception( $error['error_summary'] ?? 'Unknown error' );
		}

		return json_decode( $body, true );
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		try {
			$path = $this->get_full_path( $remote_file );

			// For files larger than 150MB, use upload sessions
			$file_size = filesize( $local_file );

			if ( $file_size > 150 * 1024 * 1024 ) {
				return $this->upload_large_file( $local_file, $path );
			}

			// Simple upload for smaller files
			$handle = fopen( $local_file, 'rb' );
			$content = fread( $handle, $file_size );
			fclose( $handle );

			$response = wp_remote_post(
				'https://content.dropboxapi.com/2/files/upload',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->settings['access_token'],
						'Dropbox-API-Arg' => json_encode( array(
							'path' => $path,
							'mode' => 'overwrite',
							'autorename' => false,
							'mute' => true,
						) ),
						'Content-Type' => 'application/octet-stream',
					),
					'body' => $content,
					'timeout' => 300,
				)
			);

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$result = json_decode( wp_remote_retrieve_body( $response ), true );
				return $result['path_display'] ?? $path;
			}
		} catch ( Exception $e ) {
			error_log( 'Dropbox upload error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Upload large file using sessions
	 */
	private function upload_large_file( $local_file, $remote_path ) {
		$chunk_size = 4 * 1024 * 1024; // 4MB chunks
		$file_size = filesize( $local_file );
		$handle = fopen( $local_file, 'rb' );

		// Start upload session
		$session_id = null;
		$offset = 0;

		while ( $offset < $file_size ) {
			$chunk = fread( $handle, $chunk_size );
			$is_last = ( $offset + strlen( $chunk ) ) >= $file_size;

			if ( $session_id === null ) {
				// Start session
				$response = wp_remote_post(
					'https://content.dropboxapi.com/2/files/upload_session/start',
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $this->settings['access_token'],
							'Dropbox-API-Arg' => json_encode( array( 'close' => false ) ),
							'Content-Type' => 'application/octet-stream',
						),
						'body' => $chunk,
						'timeout' => 300,
					)
				);

				if ( is_wp_error( $response ) ) {
					fclose( $handle );
					return false;
				}

				$result = json_decode( wp_remote_retrieve_body( $response ), true );
				$session_id = $result['session_id'];
			} elseif ( ! $is_last ) {
				// Append to session
				wp_remote_post(
					'https://content.dropboxapi.com/2/files/upload_session/append_v2',
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $this->settings['access_token'],
							'Dropbox-API-Arg' => json_encode( array(
								'cursor' => array(
									'session_id' => $session_id,
									'offset' => $offset,
								),
								'close' => false,
							) ),
							'Content-Type' => 'application/octet-stream',
						),
						'body' => $chunk,
						'timeout' => 300,
					)
				);
			} else {
				// Finish session
				$response = wp_remote_post(
					'https://content.dropboxapi.com/2/files/upload_session/finish',
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $this->settings['access_token'],
							'Dropbox-API-Arg' => json_encode( array(
								'cursor' => array(
									'session_id' => $session_id,
									'offset' => $offset,
								),
								'commit' => array(
									'path' => $remote_path,
									'mode' => 'overwrite',
									'autorename' => false,
									'mute' => true,
								),
							) ),
							'Content-Type' => 'application/octet-stream',
						),
						'body' => $chunk,
						'timeout' => 300,
					)
				);

				if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
					fclose( $handle );
					$result = json_decode( wp_remote_retrieve_body( $response ), true );
					return $result['path_display'] ?? $remote_path;
				}
			}

			$offset += strlen( $chunk );
		}

		fclose( $handle );
		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		try {
			$path = $this->get_full_path( $remote_file );

			$response = wp_remote_post(
				'https://content.dropboxapi.com/2/files/download',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->settings['access_token'],
						'Dropbox-API-Arg' => json_encode( array( 'path' => $path ) ),
					),
					'timeout' => 300,
					'stream' => true,
					'filename' => $local_file,
				)
			);

			return ! is_wp_error( $response ) && file_exists( $local_file );
		} catch ( Exception $e ) {
			error_log( 'Dropbox download error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		try {
			$path = $this->get_full_path( $remote_file );

			$this->api_request( 'files/delete_v2', array( 'path' => $path ) );

			return true;
		} catch ( Exception $e ) {
			error_log( 'Dropbox delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		try {
			$path = $this->get_full_path( $remote_file );

			$this->api_request( 'files/get_metadata', array( 'path' => $path ) );

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
			$path = $this->get_full_path( $directory );

			$response = $this->api_request( 'files/list_folder', array(
				'path' => $path === '/' ? '' : $path,
				'recursive' => false,
			) );

			if ( isset( $response['entries'] ) ) {
				foreach ( $response['entries'] as $entry ) {
					if ( $entry['.tag'] === 'file' ) {
						$files[] = array(
							'name' => $entry['name'],
							'path' => $entry['path_display'],
							'size' => $entry['size'],
							'modified' => strtotime( $entry['client_modified'] ),
						);
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'Dropbox list error: ' . $e->getMessage() );
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			$this->api_request( 'users/get_current_account' );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get full path
	 */
	private function get_full_path( $file ) {
		$folder = trim( $this->settings['folder'], '/' );
		$file = ltrim( $file, '/' );

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
			'response_type' => 'code',
			'client_id' => $this->settings['app_key'],
			'redirect_uri' => admin_url( 'admin.php?page=bmr-settings&tab=storage&type=dropbox' ),
		);

		return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query( $params );
	}

	/**
	 * Handle authorization callback
	 */
	public function handle_auth_callback( $code ) {
		$response = wp_remote_post(
			'https://api.dropboxapi.com/oauth2/token',
			array(
				'body' => array(
					'code' => $code,
					'grant_type' => 'authorization_code',
					'client_id' => $this->settings['app_key'],
					'client_secret' => $this->settings['app_secret'],
					'redirect_uri' => admin_url( 'admin.php?page=bmr-settings&tab=storage&type=dropbox' ),
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['access_token'] ) ) {
				$this->settings['access_token'] = $body['access_token'];
				update_option( 'bmr_storage_dropbox', $this->settings );
				return true;
			}
		}

		return false;
	}
}