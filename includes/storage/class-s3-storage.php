<?php
/**
 * S3 storage handler (Amazon S3 and S3-compatible)
 */
class BRM_S3_Storage implements BRM_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * S3 client
	 */
	private $client;

	/**
	 * Is custom S3
	 */
	private $is_custom;

	/**
	 * Constructor
	 */
	public function __construct( $settings, $is_custom = false ) {
		$this->is_custom = $is_custom;

		$defaults = array(
			'access_key' => '',
			'secret_key' => '',
			'bucket' => '',
			'region' => 'us-east-1',
			'directory' => '',
			'storage_class' => 'STANDARD',
			'server_side_encryption' => false,
		);

		if ( $is_custom ) {
			$defaults['endpoint'] = '';
			$defaults['use_path_style'] = true;
		}

		$this->settings = wp_parse_args( $settings, $defaults );

		// Load AWS SDK if available
		if ( ! class_exists( 'Aws\S3\S3Client' ) ) {
			// For this example, we'll use a simple S3 implementation
			require_once BRM_PLUGIN_DIR . 'includes/libs/simple-s3.php';
		}
	}

	/**
	 * Get S3 client
	 */
	private function get_client() {
		if ( $this->client ) {
			return $this->client;
		}

		if ( class_exists( 'Aws\S3\S3Client' ) ) {
			// Use AWS SDK
			$config = array(
				'version' => 'latest',
				'region' => $this->settings['region'],
				'credentials' => array(
					'key' => $this->settings['access_key'],
					'secret' => $this->settings['secret_key'],
				),
			);

			if ( $this->is_custom && ! empty( $this->settings['endpoint'] ) ) {
				$config['endpoint'] = $this->settings['endpoint'];
				$config['use_path_style_endpoint'] = $this->settings['use_path_style'];
			}

			$this->client = new Aws\S3\S3Client( $config );
		} else {
			// Use simple S3 implementation
			$this->client = new Simple_S3(
				$this->settings['access_key'],
				$this->settings['secret_key'],
				$this->settings['endpoint'] ?? null,
				$this->settings['region']
			);
		}

		return $this->client;
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		$client = $this->get_client();

		$key = $this->get_full_path( $remote_file );

		try {
			$args = array(
				'Bucket' => $this->settings['bucket'],
				'Key' => $key,
				'SourceFile' => $local_file,
				'StorageClass' => $this->settings['storage_class'],
			);

			if ( $this->settings['server_side_encryption'] ) {
				$args['ServerSideEncryption'] = 'AES256';
			}

			if ( class_exists( 'Aws\S3\S3Client' ) ) {
				$result = $client->putObject( $args );
			} else {
				$result = $client->put_object( $args );
			}

			if ( $result ) {
				return $key;
			}
		} catch ( Exception $e ) {
			error_log( 'S3 upload error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		$client = $this->get_client();

		$key = $this->get_full_path( $remote_file );

		try {
			$args = array(
				'Bucket' => $this->settings['bucket'],
				'Key' => $key,
				'SaveAs' => $local_file,
			);

			if ( class_exists( 'Aws\S3\S3Client' ) ) {
				$result = $client->getObject( $args );
			} else {
				$result = $client->get_object( $args );
			}

			return file_exists( $local_file );
		} catch ( Exception $e ) {
			error_log( 'S3 download error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		$client = $this->get_client();

		$key = $this->get_full_path( $remote_file );

		try {
			$args = array(
				'Bucket' => $this->settings['bucket'],
				'Key' => $key,
			);

			if ( class_exists( 'Aws\S3\S3Client' ) ) {
				$client->deleteObject( $args );
			} else {
				$client->delete_object( $args );
			}

			return true;
		} catch ( Exception $e ) {
			error_log( 'S3 delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		$client = $this->get_client();

		$key = $this->get_full_path( $remote_file );

		try {
			$args = array(
				'Bucket' => $this->settings['bucket'],
				'Key' => $key,
			);

			if ( class_exists( 'Aws\S3\S3Client' ) ) {
				return $client->doesObjectExist( $this->settings['bucket'], $key );
			} else {
				return $client->object_exists( $args );
			}
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * List files
	 */
	public function list_files( $directory = '' ) {
		$client = $this->get_client();

		$prefix = $this->get_full_path( $directory );
		$files = array();

		try {
			$args = array(
				'Bucket' => $this->settings['bucket'],
				'Prefix' => $prefix,
			);

			if ( class_exists( 'Aws\S3\S3Client' ) ) {
				$objects = $client->listObjects( $args );

				if ( isset( $objects['Contents'] ) ) {
					foreach ( $objects['Contents'] as $object ) {
						$files[] = array(
							'name' => basename( $object['Key'] ),
							'path' => $object['Key'],
							'size' => $object['Size'],
							'modified' => strtotime( $object['LastModified'] ),
						);
					}
				}
			} else {
				$objects = $client->list_objects( $args );

				foreach ( $objects as $object ) {
					$files[] = array(
						'name' => basename( $object['key'] ),
						'path' => $object['key'],
						'size' => $object['size'],
						'modified' => $object['modified'],
					);
				}
			}
		} catch ( Exception $e ) {
			error_log( 'S3 list error: ' . $e->getMessage() );
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		$client = $this->get_client();

		try {
			if ( class_exists( 'Aws\S3\S3Client' ) ) {
				$client->headBucket( array( 'Bucket' => $this->settings['bucket'] ) );
			} else {
				$client->bucket_exists( $this->settings['bucket'] );
			}

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