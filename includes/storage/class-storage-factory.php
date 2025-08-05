<?php
/**
 * Storage factory class
 */
class BRM_Storage_Factory {

	/**
	 * Create storage instance
	 *
	 * @param string $type Storage type
	 * @return BRM_Storage_Interface|false
	 */
	public static function create( $type ) {
		$settings = get_option( 'brm_storage_' . $type, array() );

		switch ( $type ) {
			case 'local':
				return new BRM_Local_Storage( $settings );

			case 'ftp':
				return new BRM_FTP_Storage( $settings );

			case 'sftp':
				return new BRM_SFTP_Storage( $settings );

			case 's3':
			case 'amazon_s3':
				return new BRM_S3_Storage( $settings );

			case 'google_drive':
				return new BRM_Google_Drive_Storage( $settings );

			case 'dropbox':
				return new BRM_Dropbox_Storage( $settings );

			case 'google_cloud':
				return new BRM_Google_Cloud_Storage( $settings );

			case 'backblaze':
				return new BRM_Backblaze_Storage( $settings );

			case 'custom_s3':
				return new BRM_S3_Storage( $settings, true );

			default:
				return false;
		}
	}
}