<?php
/**
 * Backblaze B2 storage handler
 */
class BMR_Backblaze_Storage implements BMR_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * API authorization
	 */
	private $auth;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'account_id' => '',
			'application_key' => '',
			'bucket_id' => '',
			'bucket_name' => '',
			'directory' => '',
		) );
	}

	/**
	 * Authorize API
	 */
	private function authorize() {
		if ( $this->auth && $this->auth['expires'] > time() ) {
			return $this->auth;
		}

		$response = wp_remote_get(
			'https://api.backblazeb2.com/b2api/v2/b2_authorize_account',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->settings['account_id'] . ':' . $this->settings['application_key'] ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['authorizationToken'] ) ) {
			throw new Exception( __( 'Backblaze authorization failed', 'backup-restore-migrate' ) );
		}

		$this->auth = array(
			'token' => $body['authorizationToken'],
			'api_url' => $body['apiUrl'],
			'download_url' => $body['downloadUrl'],
			'expires' => time() + 86400, // 24 hours
		);

		return $this->auth;
	}

	/**
	 * Get upload URL
	 */
	private function get_upload_url() {
		$auth = $this->authorize();

		$response = wp_remote_post(
			$auth['api_url'] . '/b2api/v2/b2_get_upload_url',
			array(
				'headers' => array(
					'Authorization' => $auth['token'],
				),
				'body' => json_encode( array(
					'bucketId' => $this->settings['bucket_id'],
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'url' => $body['uploadUrl'],
			'token' => $body['authorizationToken'],
		);
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		try {
			$upload = $this->get_upload_url();
			$file_name = $this->get_full_path( $remote_file );

			$response = wp_remote_post(
				$upload['url'],
				array(
					'headers' => array(
						'Authorization' => $upload['token'],
						'X-Bz-File-Name' => urlencode( $file_name ),
						'Content-Type' => 'application/octet-stream',
						'X-Bz-Content-Sha1' => sha1_file( $local_file ),
					),
					'body' => file_get_contents( $local_file ),
					'timeout' => 300,
				)
			);

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				return $body['fileId'];
			}
		} catch ( Exception $e ) {
			error_log( 'Backblaze upload error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		try {
			$auth = $this->authorize();
			$file_name = $this->get_full_path( $remote_file );

			// If remote_file is a file ID, use it directly
			if ( strpos( $remote_file, '/' ) === false && strlen( $remote_file ) === 41 ) {
				$url = $auth['download_url'] . '/b2api/v2/b2_download_file_by_id?fileId=' . $remote_file;
			} else {
				$url = $auth['download_url'] . '/file/' . $this->settings['bucket_name'] . '/' . $file_name;
			}

			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => $auth['token'],
					),
					'timeout' => 300,
					'stream' => true,
					'filename' => $local_file,
				)
			);

			return ! is_wp_error( $response ) && file_exists( $local_file );
		} catch ( Exception $e ) {
			error_log( 'Backblaze download error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		try {
			$auth = $this->authorize();

			// Get file info first
			$file_info = $this->get_file_info( $remote_file );

			if ( ! $file_info ) {
				return false;
			}

			$response = wp_remote_post(
				$auth['api_url'] . '/b2api/v2/b2_delete_file_version',
				array(
					'headers' => array(
						'Authorization' => $auth['token'],
					),
					'body' => json_encode( array(
						'fileId' => $file_info['fileId'],
						'fileName' => $file_info['fileName'],
					) ),
				)
			);

			return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
		} catch ( Exception $e ) {
			error_log( 'Backblaze delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		return $this->get_file_info( $remote_file ) !== false;
	}

	/**
	 * Get file info
	 */
	private function get_file_info( $remote_file ) {
		try {
			$auth = $this->authorize();
			$file_name = $this->get_full_path( $remote_file );

			$response = wp_remote_post(
				$auth['api_url'] . '/b2api/v2/b2_list_file_names',
				array(
					'headers' => array(
						'Authorization' => $auth['token'],
					),
					'body' => json_encode( array(
						'bucketId' => $this->settings['bucket_id'],
						'prefix' => $file_name,
						'maxFileCount' => 1,
					) ),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( isset( $body['files'] ) && count( $body['files'] ) > 0 ) {
					$file = $body['files'][0];
					if ( $file['fileName'] === $file_name ) {
						return $file;
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'Backblaze file info error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * List files
	 */
	public function list_files( $directory = '' ) {
		$files = array();

		try {
			$auth = $this->authorize();
			$prefix = $this->get_full_path( $directory );

			$response = wp_remote_post(
				$auth['api_url'] . '/b2api/v2/b2_list_file_names',
				array(
					'headers' => array(
						'Authorization' => $auth['token'],
					),
					'body' => json_encode( array(
						'bucketId' => $this->settings['bucket_id'],
						'prefix' => $prefix,
						'maxFileCount' => 1000,
					) ),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( isset( $body['files'] ) ) {
					foreach ( $body['files'] as $file ) {
						$files[] = array(
							'name' => basename( $file['fileName'] ),
							'path' => $file['fileName'],
							'size' => $file['size'],
							'modified' => $file['uploadTimestamp'] / 1000,
						);
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'Backblaze list error: ' . $e->getMessage() );
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			$this->authorize();
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get full path
	 */
	private function get_full_path( $file ) {
		$path = '';

		if ( ! empty( $this->settings['directory'] ) ) {
			$path = trim( $this->settings['directory'], '/' ) . '/';
		}

		return $path . ltrim( $file, '/' );
	}
}