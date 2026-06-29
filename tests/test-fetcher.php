<?php
/**
 * Coverage for Alaveteli_Stats_Fetcher: URL construction, response
 * classification, the success criterion that protects the cache, and the
 * retry-once-on-transient-error policy. HTTP is mocked via pre_http_request.
 *
 * @package wp-alaveteli-stats
 */

class Test_Fetcher extends WP_UnitTestCase {

	/** @var string[] URLs requested during a test, in order. */
	private $requests;

	/** @var array Queue of canned HTTP responses; the last one sticks. */
	private $responses;

	public function set_up() {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		parent::tear_down();
	}

	/** Short-circuit wp_safe_remote_get, recording the URL and returning a canned response. */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = $url;
		if ( count( $this->responses ) > 1 ) {
			return array_shift( $this->responses );
		}
		return isset( $this->responses[0] ) ? $this->responses[0] : $pre;
	}

	private function http_response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_empty_source_returns_config_error_without_requesting() {
		$result = Alaveteli_Stats_Fetcher::fetch( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'no_source', $result->get_error_code() );
		$this->assertSame( 'config', $result->get_error_data()['category'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_builds_version_json_url_from_base() {
		$this->responses = array( $this->http_response( 200, wp_json_encode( array( 'visible_request_count' => 5 ) ) ) );
		Alaveteli_Stats_Fetcher::fetch( 'https://example.org' );
		$this->assertSame( 'https://example.org/version.json', $this->requests[0] );
	}

	public function test_trailing_slash_in_base_does_not_double_up() {
		$this->responses = array( $this->http_response( 200, wp_json_encode( array( 'visible_request_count' => 5 ) ) ) );
		Alaveteli_Stats_Fetcher::fetch( 'https://example.org/' );
		$this->assertSame( 'https://example.org/version.json', $this->requests[0] );
	}

	public function test_valid_stats_object_succeeds() {
		$stats           = array( 'visible_request_count' => 42, 'confirmed_user_count' => 7 );
		$this->responses = array( $this->http_response( 200, wp_json_encode( $stats ) ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org' );
		$this->assertSame( $stats, $result );
	}

	public function test_empty_object_is_invalid() {
		$this->responses = array( $this->http_response( 200, '{}' ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
	}

	/** Regression guard (#1): a JSON list is not statistics and must not be stored. */
	public function test_json_list_is_invalid() {
		$this->responses = array( $this->http_response( 200, '[1,2,3]' ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
	}

	/** Regression guard (#1): a non-stats JSON object (e.g. a proxy error) must not be stored. */
	public function test_object_without_any_numeric_value_is_invalid() {
		$this->responses = array( $this->http_response( 200, wp_json_encode( array( 'error' => 'rate limited' ) ) ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
	}

	public function test_non_json_body_is_invalid() {
		$this->responses = array( $this->http_response( 200, '<html>not json</html>' ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
	}

	public function test_4xx_is_not_retried() {
		$this->responses = array( $this->http_response( 404, 'nope' ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org', true );
		$this->assertWPError( $result );
		$this->assertSame( 'http', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['http_code'] );
		$this->assertCount( 1, $this->requests, '4xx is not transient and must not be retried' );
	}

	public function test_5xx_is_retried_once_then_succeeds() {
		$this->responses = array(
			$this->http_response( 503, 'busy' ),
			$this->http_response( 200, wp_json_encode( array( 'visible_request_count' => 9 ) ) ),
		);
		$result = Alaveteli_Stats_Fetcher::fetch( 'https://example.org', true );
		$this->assertSame( array( 'visible_request_count' => 9 ), $result );
		$this->assertCount( 2, $this->requests, '5xx should be retried once' );
	}

	public function test_retry_disabled_does_not_retry() {
		$this->responses = array(
			$this->http_response( 503, 'busy' ),
			$this->http_response( 503, 'busy' ),
		);
		$result = Alaveteli_Stats_Fetcher::fetch( 'https://example.org', false );
		$this->assertWPError( $result );
		$this->assertCount( 1, $this->requests, 'retry=false must make a single request' );
	}

	/** Characterisation guard (#9): the diagnostic snippet strips tags and truncates. */
	public function test_error_snippet_strips_tags_and_truncates() {
		$body            = '<p>' . str_repeat( 'x', 500 ) . '</p>';
		$this->responses = array( $this->http_response( 404, $body ) );
		$result          = Alaveteli_Stats_Fetcher::fetch( 'https://example.org', false );

		$snippet = $result->get_error_data()['body_snippet'];
		$this->assertStringNotContainsString( '<p>', $snippet );
		$this->assertStringEndsWith( '...', $snippet );
		$this->assertLessThanOrEqual( 203, strlen( $snippet ) );
	}
}
