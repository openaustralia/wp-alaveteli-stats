<?php
/**
 * Coverage for Alaveteli_Stats: cron scheduling self-heal and the refresh lock
 * that serialises overlapping refreshes. HTTP is mocked via pre_http_request.
 *
 * @package wp-alaveteli-stats
 */

class Test_Controller extends WP_UnitTestCase {

	private $request_count;

	public function set_up() {
		parent::set_up();
		$this->request_count = 0;
		wp_clear_scheduled_hook( ALAVETELI_STATS_CRON_HOOK );
		delete_option( Alaveteli_Stats_Store::OPTION_SETTINGS );
		delete_option( Alaveteli_Stats_Store::OPTION_DATA );
		update_option( Alaveteli_Stats_Store::OPTION_SETTINGS, array( 'source_url' => 'https://example.org' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		wp_clear_scheduled_hook( ALAVETELI_STATS_CRON_HOOK );
		parent::tear_down();
	}

	public function mock_http( $pre, $args, $url ) {
		++$this->request_count;
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'visible_request_count' => $this->request_count ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** #8: a missing recurring event is (re)scheduled. */
	public function test_ensure_scheduled_registers_the_event() {
		$this->assertFalse( wp_next_scheduled( ALAVETELI_STATS_CRON_HOOK ) );
		Alaveteli_Stats::ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( ALAVETELI_STATS_CRON_HOOK ) );
		$this->assertSame( ALAVETELI_STATS_SCHEDULE, wp_get_schedule( ALAVETELI_STATS_CRON_HOOK ) );
	}

	/** #8: it does not pile up duplicate events. */
	public function test_ensure_scheduled_is_idempotent() {
		Alaveteli_Stats::ensure_scheduled();
		$first = wp_next_scheduled( ALAVETELI_STATS_CRON_HOOK );
		Alaveteli_Stats::ensure_scheduled();
		$this->assertSame( $first, wp_next_scheduled( ALAVETELI_STATS_CRON_HOOK ) );
	}

	public function test_refresh_fetches_and_persists() {
		$result = Alaveteli_Stats::refresh( false );
		$this->assertSame( array( 'visible_request_count' => 1 ), $result );
		$this->assertSame( 1, $this->request_count );
		$this->assertSame( 1, Alaveteli_Stats_Store::get_stat( 'visible_request_count' ) );
	}

	/** #7: while a refresh is already in flight, a second refresh does not fetch again. */
	public function test_refresh_skips_when_lock_is_held() {
		set_transient( Alaveteli_Stats::LOCK_KEY, 1, Alaveteli_Stats::LOCK_TTL );

		Alaveteli_Stats::refresh( false );

		$this->assertSame( 0, $this->request_count, 'a held lock must prevent a concurrent fetch' );
	}

	public function test_refresh_releases_lock_afterwards() {
		Alaveteli_Stats::refresh( false );
		$this->assertFalse( get_transient( Alaveteli_Stats::LOCK_KEY ), 'lock must be released after a refresh' );
	}
}
