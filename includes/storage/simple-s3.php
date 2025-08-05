<?php
/**
 * Simple S3 implementation for when AWS SDK is not available
 */
class Simple_S3 {

	private $access_key;
	private $secret_key;
	private $endpoint;
	private $region;

	public function __construct( $access_key, $secret_key, $endpoint = null, $region = 'us-east-1' ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->endpoint = $endpoint ?: 'https://s3.amazonaws.com';
		$this->region = $region;
	}

	public function put_object( $args ) {
		$bucket = $args['Bucket'];
		$key = $args['Key'];
		$file = $args['SourceFile'];

		$url = $this->endpoint . '/' . $bucket . '/' . $key;
		$date = gmdate( 'D, d M Y H:i:s T' );
		$content_type = 'application/octet-stream';

		$string_to_sign = "PUT\n\n{$content_type}\n{$date}\n/{$bucket}/{$key}";
		$signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $this->secret_key, true ) );

		$response = wp_remote_request(
			$url,
			array(
				'method' => 'PUT',
				'headers' => array(
					'Authorization' => 'AWS ' . $this->access_key . ':' . $signature,
					'Content-Type' => $content_type,
					'Date' => $date,
				),
				'body' => file_get_contents( $file ),
				'timeout' => 300,
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}

	public function get_object( $args ) {
		$bucket = $args['Bucket'];
		$key = $args['Key'];
		$save_as = $args['SaveAs'];

		$url = $this->endpoint . '/' . $bucket . '/' . $key;
		$date = gmdate( 'D, d M Y H:i:s T' );

		$string_to_sign = "GET\n\n\n{$date}\n/{$bucket}/{$key}";
		$signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $this->secret_key, true ) );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'AWS ' . $this->access_key . ':' . $signature,
					'Date' => $date,
				),
				'timeout' => 300,
				'stream' => true,
				'filename' => $save_as,
			)
		);

		return ! is_wp_error( $response );
	}

	public function delete_object( $args ) {
		$bucket = $args['Bucket'];
		$key = $args['Key'];

		$url = $this->endpoint . '/' . $bucket . '/' . $key;
		$date = gmdate( 'D, d M Y H:i:s T' );

		$string_to_sign = "DELETE\n\n\n{$date}\n/{$bucket}/{$key}";
		$signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $this->secret_key, true ) );

		$response = wp_remote_request(
			$url,
			array(
				'method' => 'DELETE',
				'headers' => array(
					'Authorization' => 'AWS ' . $this->access_key . ':' . $signature,
					'Date' => $date,
				),
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 204;
	}

	public function object_exists( $args ) {
		$bucket = $args['Bucket'];
		$key = $args['Key'];

		$url = $this->endpoint . '/' . $bucket . '/' . $key;
		$date = gmdate( 'D, d M Y H:i:s T' );

		$string_to_sign = "HEAD\n\n\n{$date}\n/{$bucket}/{$key}";
		$signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $this->secret_key, true ) );

		$response = wp_remote_head(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'AWS ' . $this->access_key . ':' . $signature,
					'Date' => $date,
				),
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}

	public function list_objects( $args ) {
		$bucket = $args['Bucket'];
		$prefix = isset( $args['Prefix'] ) ? $args['Prefix'] : '';

		$url = $this->endpoint . '/' . $bucket . '/?prefix=' . urlencode( $prefix );
		$date = gmdate( 'D, d M Y H:i:s T' );

		$string_to_sign = "GET\n\n\n{$date}\n/{$bucket}/";
		$signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $this->secret_key, true ) );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'AWS ' . $this->access_key . ':' . $signature,
					'Date' => $date,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
		$objects = array();

		if ( $xml && isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $object ) {
				$objects[] = array(
					'key' => (string) $object->Key,
					'size' => (int) $object->Size,
					'modified' => strtotime( (string) $object->LastModified ),
				);
			}
		}

		return $objects;
	}

	public function bucket_exists( $bucket ) {
		$url = $this->endpoint . '/' . $bucket;
		$date = gmdate( 'D, d M Y H:i:s T' );

		$string_to_sign = "HEAD\n\n\n{$date}\n/{$bucket}/";
		$signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $this->secret_key, true ) );

		$response = wp_remote_head(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'AWS ' . $this->access_key . ':' . $signature,
					'Date' => $date,
				),
			)
		);

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}
}