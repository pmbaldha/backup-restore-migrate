<?php
/**
 * Google Cloud Storage handler
 */
class BMR_Google_Cloud_Storage implements BMR_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * GCS client
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'project_id' => '',
			'key_file' => '',
			'bucket' => '',
			'directory' => '',
			'storage_class' => 'STANDARD',
		) );
	}

	/**
	 * Get GCS client
	 */
	private function get_client() {
		if ( $this->client ) {
			return $this->client;
		}

		// For this example, we'll use REST API
		$this->client = new stdClass();
		$this->client->access_token = $this->get_access_token();

		return $this->client;
	}

	/**
	 * Get access token using service account
	 */
	private function get_access_token() {
		if ( empty( $this->settings['key_file'] ) ) {
			throw new Exception( __( 'Google Cloud Storage key file not configured', 'backup-restore-migrate' ) );
		}

		$key_data = json_decode( $this->settings['key_file'], true );

		if ( ! $key_data ) {
			throw new Exception( __( 'Invalid Google Cloud Storage key file', 'backup-restore-migrate' ) );
		}

		// Create JWT
		$now = time();
		$jwt_header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$jwt_claim = array(
			'iss' => $key_data['client_email'],
			'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
			'aud' => 'https://oauth2.googleapis.com/token',
			'exp' => $now + 3600,
			'iat' => $now,
		);

		$jwt = $this->base64url_encode( json_encode( $jwt_header ) ) . '.' .
			$this->base64url_encode( json_encode( $jwt_claim ) );

		// Sign JWT
		$key = openssl_pkey_get_private( $key_data['private_key'] );
		openssl_sign( $jwt, $signature, $key, OPENSSL_ALGO_SHA256 );

		$jwt .= '.' . $this->base64url_encode( $signature );

		// Exchange JWT for access token
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion' => $jwt,
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['access_token'] ) ) {
				return $body['access_token'];
			}
		}

		throw new Exception( __( 'Failed to get Google Cloud Storage access token', 'backup-restore-migrate' ) );
	}

	/**
	 * Base64 URL encode
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		try {
			$client = $this->get_client();
			$object_name = $this->get_full_path( $remote_file );

			$response = wp_remote_post(
				"https://storage.googleapis.com/upload/storage/v1/b/{$this->settings['bucket']}/o?uploadType=media&name=" . urlencode( $object_name ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $client->access_token,
						'Content-Type' => 'application/octet-stream',
					),
					'body' => file_get_contents( $local_file ),
					'timeout' => 300,
				)
			);

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				return $object_name;
			}
		} catch ( Exception $e ) {
			error_log( 'Google Cloud Storage upload error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		try {
			$client = $this->get_client();
			$object_name = $this->get_full_path( $remote_file );

			$response = wp_remote_get(
				"https://storage.googleapis.com/storage/v1/b/{$this->settings['bucket']}/o/" . urlencode( $object_name ) . "?alt=media",
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $client->access_token,
					),
					'timeout' => 300,
					'stream' => true,
					'filename' => $local_file,
				)
			);

			return ! is_wp_error( $response ) && file_exists( $local_file );
		} catch ( Exception $e ) {
			error_log( 'Google Cloud Storage download error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		try {
			$client = $this->get_client();
			$object_name = $this->get_full_path( $remote_file );

			$response = wp_remote_request(
				"https://storage.googleapis.com/storage/v1/b/{$this->settings['bucket']}/o/" . urlencode( $object_name ),
				array(
					'method' => 'DELETE',
					'headers' => array(
						'Authorization' => 'Bearer ' . $client->access_token,
					),
				)
			);

			return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 204;
		} catch ( Exception $e ) {
			error_log( 'Google Cloud Storage delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		try {
			$client = $this->get_client();
			$object_name = $this->get_full_path( $remote_file );

			$response = wp_remote_get(
				"https://storage.googleapis.com/storage/v1/b/{$this->settings['bucket']}/o/" . urlencode( $object_name ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $client->access_token,
					),
				)
			);

			return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
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
			$client = $this->get_client();
			$prefix = $this->get_full_path( $directory );

			$response = wp_remote_get(
				"https://storage.googleapis.com/storage/v1/b/{$this->settings['bucket']}/o?" . http_build_query( array(
					'prefix' => $prefix,
				) ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $client->access_token,
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( isset( $body['items'] ) ) {
					foreach ( $body['items'] as $item ) {
						$files[] = array(
							'name' => basename( $item['name'] ),
							'path' => $item['name'],
							'size' => $item['size'],
							'modified' => strtotime( $item['updated'] ),
						);
					}
				}
			}
		} catch ( Exception $e ) {
			error_log( 'Google Cloud Storage list error: ' . $e->getMessage() );
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			$client = $this->get_client();

			$response = wp_remote_get(
				"https://storage.googleapis.com/storage/v1/b/{$this->settings['bucket']}",
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $client->access_token,
					),
				)
			);

			return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
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