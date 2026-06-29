<?php
/**
 * Fetches statistics from an Alaveteli instance's /version.json endpoint.
 *
 * Returns the decoded statistics on success, or a WP_Error whose code names
 * the failure category (transport, http, invalid) and whose data carries the
 * details the admin needs to fix the problem.
 *
 * @package wp-alaveteli-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alaveteli_Stats_Fetcher {

	const PATH         = 'version.json';
	const TIMEOUT      = 15;
	const SNIPPET_SIZE = 200;

	/**
	 * Fetch and decode the statistics for a given Alaveteli base URL.
	 *
	 * Transport errors and 5xx responses are retried once, since these are
	 * usually momentary and clear on a second attempt.
	 *
	 * @param string $source_url Alaveteli base URL, e.g. https://www.righttoknow.org.au.
	 * @return array|WP_Error
	 */
	public static function fetch( $source_url, $retry = true ) {
		$source_url = trim( (string) $source_url );

		if ( '' === $source_url ) {
			return new WP_Error(
				'no_source',
				__( 'No Alaveteli site URL has been configured yet.', 'wp-alaveteli-stats' ),
				array(
					'category' => 'config',
					'url'      => '',
				)
			);
		}

		$url     = trailingslashit( $source_url ) . self::PATH;
		$result  = self::request( $url );

		if ( $retry && self::is_transient_error( $result ) ) {
			$result = self::request( $url );
		}

		return $result;
	}

	/**
	 * Perform a single request and classify the outcome.
	 *
	 * @param string $url Full URL to the version.json endpoint.
	 * @return array|WP_Error
	 */
	private static function request( $url ) {
		// wp_safe_remote_get validates the URL and every redirect target with
		// wp_http_validate_url(), so a compromised or redirecting source cannot
		// be used to reach internal hosts (SSRF). Legitimate redirects to
		// public hosts (such as www/https) are still followed.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::user_agent(),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'transport',
				$response->get_error_message(),
				array(
					'category' => 'transport',
					'url'      => $url,
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The site responded with HTTP status %d.', 'wp-alaveteli-stats' ),
					$code
				),
				array(
					'category'     => 'http',
					'url'          => $url,
					'http_code'    => $code,
					'body_snippet' => self::snippet( $body ),
				)
			);
		}

		$decoded = json_decode( $body, true );

		// Reject anything that is not a non-empty object of statistics. An empty
		// {} or [] is valid JSON but carries no data, and treating it as success
		// would overwrite the last good values with nothing.
		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return new WP_Error(
				'invalid',
				__( 'The response did not contain any statistics, so this may not be an Alaveteli site.', 'wp-alaveteli-stats' ),
				array(
					'category'     => 'invalid',
					'url'          => $url,
					'http_code'    => $code,
					'body_snippet' => self::snippet( $body ),
				)
			);
		}

		return $decoded;
	}

	/**
	 * Whether an error is worth retrying once (momentary network or 5xx).
	 *
	 * @param array|WP_Error $result Result of a request.
	 * @return bool
	 */
	private static function is_transient_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		if ( 'transport' === $result->get_error_code() ) {
			return true;
		}

		$data = $result->get_error_data();
		$code = ( is_array( $data ) && isset( $data['http_code'] ) ) ? (int) $data['http_code'] : 0;

		return $code >= 500 && $code <= 599;
	}

	/**
	 * A descriptive user-agent so operators can identify this plugin in logs.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return 'WordPress/wp-alaveteli-stats; ' . home_url( '/' );
	}

	/**
	 * A short, tag-stripped excerpt of a response body for diagnostics.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	private static function snippet( $body ) {
		$body = trim( wp_strip_all_tags( (string) $body ) );

		// Truncate on a character boundary so a multibyte sequence is never cut
		// mid-character, which would leave invalid UTF-8 that esc_html() blanks.
		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $body, 0, self::SNIPPET_SIZE, '...' );
		}

		if ( strlen( $body ) > self::SNIPPET_SIZE ) {
			$body = substr( $body, 0, self::SNIPPET_SIZE ) . '...';
		}

		return $body;
	}
}
