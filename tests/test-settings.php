<?php
/**
 * Coverage for Alaveteli_Stats_Settings::sanitize_settings, in particular the
 * refresh triggered when an unchanged URL is re-saved (the add_option_/
 * update_option_ hooks do not fire in that case). HTTP is mocked.
 *
 * @package wp-alaveteli-stats
 */

class Test_Settings extends WP_UnitTestCase {

	private $request_count;

	public function set_up() {
		parent::set_up();
		$this->request_count = 0;
		delete_option( Alaveteli_Stats_Store::OPTION_SETTINGS );
		delete_option( Alaveteli_Stats_Store::OPTION_DATA );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
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

	public function test_sanitize_normalises_and_returns_the_url() {
		$out = Alaveteli_Stats_Settings::sanitize_settings( array( 'source_url' => '  https://example.org  ' ) );
		$this->assertSame( 'https://example.org', $out['source_url'] );
	}

	/** Regression guard (#5): re-saving the same URL triggers a fresh fetch. */
	public function test_resaving_unchanged_url_triggers_a_refresh() {
		update_option( Alaveteli_Stats_Store::OPTION_SETTINGS, array( 'source_url' => 'https://example.org' ) );

		Alaveteli_Stats_Settings::sanitize_settings( array( 'source_url' => 'https://example.org' ) );

		$this->assertSame( 1, $this->request_count, 'an unchanged-URL re-save should refresh' );
		$this->assertSame( 1, Alaveteli_Stats_Store::get_stat( 'visible_request_count' ) );
	}

	/** A changed URL is refreshed by the update_option_ hook, not by sanitize, so sanitize must not fetch. */
	public function test_changed_url_is_not_refreshed_inside_sanitize() {
		update_option( Alaveteli_Stats_Store::OPTION_SETTINGS, array( 'source_url' => 'https://old.example' ) );

		Alaveteli_Stats_Settings::sanitize_settings( array( 'source_url' => 'https://new.example' ) );

		$this->assertSame( 0, $this->request_count, 'a changed URL must not fetch from within sanitize' );
	}

	public function test_empty_url_does_not_fetch() {
		Alaveteli_Stats_Settings::sanitize_settings( array( 'source_url' => '' ) );
		$this->assertSame( 0, $this->request_count );
	}

	/**
	 * Drive the real save path (update_option -> sanitize_option_{$option}) to
	 * prove a single submit refreshes exactly once even if WordPress invokes the
	 * sanitize callback more than once for a save.
	 */
	public function test_real_save_path_refreshes_unchanged_url_exactly_once() {
		Alaveteli_Stats_Settings::register_settings();
		update_option( Alaveteli_Stats_Store::OPTION_SETTINGS, array( 'source_url' => 'https://example.org' ) );

		$this->request_count = 0;
		update_option( Alaveteli_Stats_Store::OPTION_SETTINGS, array( 'source_url' => 'https://example.org' ) );

		$this->assertSame( 1, $this->request_count, 'an unchanged-URL re-save must refresh exactly once' );
	}
}
