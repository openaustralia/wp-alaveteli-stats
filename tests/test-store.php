<?php
/**
 * Coverage for Alaveteli_Stats_Store: persistence, failure preservation and
 * the escalation logic that drives the admin notice.
 *
 * @package wp-alaveteli-stats
 */

class Test_Store extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Alaveteli_Stats_Store::OPTION_SETTINGS );
		delete_option( Alaveteli_Stats_Store::OPTION_DATA );
	}

	/** A representative fetch error, as the fetcher would produce. */
	private function http_error( $code = 500 ) {
		return new WP_Error(
			'http',
			sprintf( 'The site responded with HTTP status %d.', $code ),
			array(
				'category'  => 'http',
				'url'       => 'https://example.org/version.json',
				'http_code' => $code,
			)
		);
	}

	/** Backdate the stored last-success time to force staleness. */
	private function age_fetched_at( $seconds ) {
		$data               = Alaveteli_Stats_Store::get_data();
		$data['fetched_at'] = time() - $seconds;
		update_option( Alaveteli_Stats_Store::OPTION_DATA, $data );
	}

	private function set_source( $url ) {
		update_option( Alaveteli_Stats_Store::OPTION_SETTINGS, array( 'source_url' => $url ) );
	}

	public function test_get_settings_returns_default_source_url_when_unset() {
		$this->assertSame( array( 'source_url' => '' ), Alaveteli_Stats_Store::get_settings() );
		$this->assertSame( '', Alaveteli_Stats_Store::get_source_url() );
	}

	public function test_get_source_url_returns_configured_value() {
		$this->set_source( 'https://example.org' );
		$this->assertSame( 'https://example.org', Alaveteli_Stats_Store::get_source_url() );
	}

	public function test_save_success_stores_stats_and_clears_error_state() {
		// Seed an error first so we can prove success clears it.
		Alaveteli_Stats_Store::save( $this->http_error() );

		Alaveteli_Stats_Store::save( array( 'a' => 1, 'b' => 2 ) );

		$this->assertSame( array( 'a' => 1, 'b' => 2 ), Alaveteli_Stats_Store::get_stats() );
		$this->assertTrue( Alaveteli_Stats_Store::has_data() );
		$this->assertFalse( Alaveteli_Stats_Store::has_error() );

		$meta = Alaveteli_Stats_Store::get_meta();
		$this->assertGreaterThan( 0, $meta['fetched_at'] );
		$this->assertSame( '', $meta['last_error'] );
		$this->assertNull( $meta['last_error_at'] );
		$this->assertSame( 0, $meta['consecutive_failures'] );
	}

	public function test_save_error_preserves_stats_and_increments_failures() {
		Alaveteli_Stats_Store::save( array( 'x' => 5 ) );

		Alaveteli_Stats_Store::save( $this->http_error( 503 ) );
		$this->assertSame( array( 'x' => 5 ), Alaveteli_Stats_Store::get_stats(), 'last good stats survive a failure' );
		$this->assertTrue( Alaveteli_Stats_Store::has_error() );

		$meta = Alaveteli_Stats_Store::get_meta();
		$this->assertSame( 'http', $meta['last_error_category'] );
		$this->assertSame( 'https://example.org/version.json', $meta['last_error_url'] );
		$this->assertSame( 1, $meta['consecutive_failures'] );

		Alaveteli_Stats_Store::save( $this->http_error( 503 ) );
		$this->assertSame( 2, Alaveteli_Stats_Store::get_meta()['consecutive_failures'] );
	}

	public function test_save_error_category_falls_back_to_error_code() {
		// A WP_Error whose data carries no 'category' key.
		Alaveteli_Stats_Store::save( new WP_Error( 'transport', 'Down', array( 'url' => 'u' ) ) );
		$this->assertSame( 'transport', Alaveteli_Stats_Store::get_meta()['last_error_category'] );
	}

	public function test_get_stat_returns_value_or_null() {
		Alaveteli_Stats_Store::save( array( 'k' => 42 ) );
		$this->assertSame( 42, Alaveteli_Stats_Store::get_stat( 'k' ) );
		$this->assertNull( Alaveteli_Stats_Store::get_stat( 'missing' ) );
	}

	public function test_get_meta_excludes_stats_payload() {
		Alaveteli_Stats_Store::save( array( 'k' => 1 ) );
		$meta = Alaveteli_Stats_Store::get_meta();
		$this->assertArrayNotHasKey( 'stats', $meta );
		$this->assertArrayHasKey( 'fetched_at', $meta );
	}

	public function test_is_stale_false_when_never_fetched() {
		$this->assertFalse( Alaveteli_Stats_Store::is_stale() );
	}

	public function test_is_stale_false_when_recent_true_when_old() {
		Alaveteli_Stats_Store::save( array( 'k' => 1 ) );
		$this->assertFalse( Alaveteli_Stats_Store::is_stale() );

		$this->age_fetched_at( 7 * HOUR_IN_SECONDS );
		$this->assertTrue( Alaveteli_Stats_Store::is_stale() );
	}

	public function test_needs_attention_false_without_error() {
		$this->set_source( 'https://example.org' );
		Alaveteli_Stats_Store::save( array( 'k' => 1 ) );
		$this->assertFalse( Alaveteli_Stats_Store::needs_attention() );
	}

	public function test_needs_attention_false_when_unconfigured() {
		// No source URL: failing repeatedly should still not escalate.
		Alaveteli_Stats_Store::save( $this->http_error() );
		Alaveteli_Stats_Store::save( $this->http_error() );
		Alaveteli_Stats_Store::save( $this->http_error() );
		$this->assertFalse( Alaveteli_Stats_Store::needs_attention() );
	}

	public function test_needs_attention_false_below_threshold_and_fresh() {
		$this->set_source( 'https://example.org' );
		Alaveteli_Stats_Store::save( array( 'k' => 1 ) );
		Alaveteli_Stats_Store::save( $this->http_error() );
		$this->assertFalse( Alaveteli_Stats_Store::needs_attention() );
	}

	public function test_needs_attention_escalates_after_failure_threshold() {
		$this->set_source( 'https://example.org' );
		Alaveteli_Stats_Store::save( $this->http_error() );
		Alaveteli_Stats_Store::save( $this->http_error() );
		Alaveteli_Stats_Store::save( $this->http_error() );
		$this->assertSame( 3, Alaveteli_Stats_Store::get_meta()['consecutive_failures'] );
		$this->assertTrue( Alaveteli_Stats_Store::needs_attention() );
	}

	public function test_needs_attention_escalates_when_stale_even_below_threshold() {
		$this->set_source( 'https://example.org' );
		Alaveteli_Stats_Store::save( array( 'k' => 1 ) );
		Alaveteli_Stats_Store::save( $this->http_error() ); // one failure, below threshold
		$this->age_fetched_at( 7 * HOUR_IN_SECONDS );       // but data is now stale
		$this->assertTrue( Alaveteli_Stats_Store::needs_attention() );
	}
}
