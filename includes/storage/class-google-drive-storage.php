<?php
/**
 * Google Drive storage handler
 */
class BRM_Google_Drive_Storage implements BRM_Storage_Interface {

	/**
	 * Storage settings
	 */
	private $settings;

	/**
	 * Google client
	 */
	private $client;

	/**
	 * Drive service
	 */
	private $service;

	/**
	 * Constructor
	 */
	public function __construct( $settings ) {
		$this->settings = wp_parse_args( $settings, array(
			'client_id' => '',
			'client_secret' => '',
			'refresh_token' => '',
			'folder_id' => 'root',
		) );

		if ( ! class_exists( 'Google_Client' ) ) {
			require_once BRM_PLUGIN_DIR . 'includes/libs/google-api-php-client/autoload.php';
		}
	}

	/**
	 * Get Google client
	 */
	private function get_client() {
		if ( $this->client ) {
			return $this->client;
		}

		$this->client = new Google_Client();
		$this->client->setClientId( $this->settings['client_id'] );
		$this->client->setClientSecret( $this->settings['client_secret'] );
		$this->client->setRedirectUri( admin_url( 'admin.php?page=bmr-settings&tab=storage&type=google_drive' ) );
		$this->client->addScope( Google_Service_Drive::DRIVE_FILE );
		$this->client->setAccessType( 'offline' );
		$this->client->setApprovalPrompt( 'force' );

		if ( ! empty( $this->settings['refresh_token'] ) ) {
			$this->client->refreshToken( $this->settings['refresh_token'] );
		}

		return $this->client;
	}

	/**
	 * Get Drive service
	 */
	private function get_service() {
		if ( $this->service ) {
			return $this->service;
		}

		$client = $this->get_client();
		$this->service = new Google_Service_Drive( $client );

		return $this->service;
	}

	/**
	 * Upload file
	 */
	public function upload( $local_file, $remote_file ) {
		try {
			$service = $this->get_service();

			$file = new Google_Service_Drive_DriveFile();
			$file->setName( basename( $remote_file ) );

			// Set parent folder
			if ( $this->settings['folder_id'] !== 'root' ) {
				$file->setParents( array( $this->settings['folder_id'] ) );
			}

			// Upload file
			$result = $service->files->create(
				$file,
				array(
					'data' => file_get_contents( $local_file ),
					'mimeType' => 'application/octet-stream',
					'uploadType' => 'multipart',
				)
			);

			if ( $result && $result->getId() ) {
				return $result->getId();
			}
		} catch ( Exception $e ) {
			error_log( 'Google Drive upload error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Download file
	 */
	public function download( $remote_file, $local_file ) {
		try {
			$service = $this->get_service();

			// Get file content
			$response = $service->files->get( $remote_file, array( 'alt' => 'media' ) );
			$content = $response->getBody()->getContents();

			// Save to local file
			if ( file_put_contents( $local_file, $content ) !== false ) {
				return true;
			}
		} catch ( Exception $e ) {
			error_log( 'Google Drive download error: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Delete file
	 */
	public function delete( $remote_file ) {
		try {
			$service = $this->get_service();
			$service->files->delete( $remote_file );
			return true;
		} catch ( Exception $e ) {
			error_log( 'Google Drive delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if file exists
	 */
	public function exists( $remote_file ) {
		try {
			$service = $this->get_service();
			$file = $service->files->get( $remote_file );
			return $file !== null;
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
			$service = $this->get_service();

			$query = "mimeType != 'application/vnd.google-apps.folder' and trashed = false";

			if ( $this->settings['folder_id'] !== 'root' ) {
				$query .= " and '{$this->settings['folder_id']}' in parents";
			}

			$results = $service->files->listFiles( array(
				'q' => $query,
				'fields' => 'files(id, name, size, modifiedTime)',
			) );

			foreach ( $results->getFiles() as $file ) {
				$files[] = array(
					'name' => $file->getName(),
					'path' => $file->getId(),
					'size' => $file->getSize(),
					'modified' => strtotime( $file->getModifiedTime() ),
				);
			}
		} catch ( Exception $e ) {
			error_log( 'Google Drive list error: ' . $e->getMessage() );
		}

		return $files;
	}

	/**
	 * Test connection
	 */
	public function test_connection() {
		try {
			$service = $this->get_service();
			$about = $service->about->get( array( 'fields' => 'user' ) );
			return $about !== null;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get authorization URL
	 */
	public function get_auth_url() {
		$client = $this->get_client();
		return $client->createAuthUrl();
	}

	/**
	 * Handle authorization callback
	 */
	public function handle_auth_callback( $code ) {
		$client = $this->get_client();
		$token = $client->fetchAccessTokenWithAuthCode( $code );

		if ( isset( $token['refresh_token'] ) ) {
			$this->settings['refresh_token'] = $token['refresh_token'];
			update_option( 'bmr_storage_google_drive', $this->settings );
			return true;
		}

		return false;
	}
}