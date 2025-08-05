<?php
/**
 * Storage factory class
 */
class BMR_Storage_Factory {

	/**
	 * Create storage instance
	 *
	 * @param string $type Storage type
	 * @return BMR_Storage_Interface|false
	 */
	public static function create( $type ) {
		$settings = get_option( 'bmr_storage_' . $type, array() );

		switch ( $type ) {
			case 'local':
				return new BMR_Local_Storage( $settings );

			case 'ftp':
				return new BMR_FTP_Storage( $settings );

			case 'sftp':
				return new BMR_SFTP_Storage( $settings );

			case 's3':
			case 'amazon_s3':
				return new BMR_S3_Storage( $settings );

			case 'google_drive':
				return new BMR_Google_Drive_Storage( $settings );

			case 'dropbox':
				return new BMR_Dropbox_Storage( $settings );

			case 'google_cloud':
				return new BMR_Google_Cloud_Storage( $settings );

			case 'backblaze':
				return new BMR_Backblaze_Storage( $settings );

			case 'custom_s3':
				return new BMR_S3_Storage( $settings, true );

			default:
				return false;
		}
	}
}