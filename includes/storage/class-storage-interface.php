<?php
/**
 * Storage interface
 */
interface BRM_Storage_Interface {

	/**
	 * Upload file to storage
	 *
	 * @param string $local_file Local file path
	 * @param string $remote_file Remote file path
	 * @return string|false Remote file location or false on failure
	 */
	public function upload( $local_file, $remote_file );

	/**
	 * Download file from storage
	 *
	 * @param string $remote_file Remote file path
	 * @param string $local_file Local file path
	 * @return bool Success
	 */
	public function download( $remote_file, $local_file );

	/**
	 * Delete file from storage
	 *
	 * @param string $remote_file Remote file path
	 * @return bool Success
	 */
	public function delete( $remote_file );

	/**
	 * Check if file exists in storage
	 *
	 * @param string $remote_file Remote file path
	 * @return bool
	 */
	public function exists( $remote_file );

	/**
	 * List files in storage
	 *
	 * @param string $directory Directory path
	 * @return array List of files
	 */
	public function list_files( $directory = '' );

	/**
	 * Test connection to storage
	 *
	 * @return bool Success
	 */
	public function test_connection();
}
