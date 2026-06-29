<?php
/**
 * Coverage for Alaveteli_Stats_Render: which values render, number formatting,
 * output escaping, and the shortcode wiring.
 *
 * @package wp-alaveteli-stats
 */

class Test_Render extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Alaveteli_Stats_Store::save(
			array(
				'visible_request_count' => 1234567,
				'ratio'                 => 4.5,
				'ratio_str'             => '12.50',
				'zero'                  => 0,
				'zero_str'              => '0',
				'flag'                  => true,
				'nested'                => array( 'a' => 1 ),
				'blank'                 => '',
				'name'                  => 'hello',
				'quote"key'             => 7,
				'tiny'                  => 0.00001,
				'huge'                  => 9007199254740993,
			)
		);
	}

	public function test_empty_key_returns_fallback() {
		$this->assertSame( 'n/a', Alaveteli_Stats_Render::stat( '', array( 'fallback' => 'n/a' ) ) );
	}

	public function test_unknown_key_returns_fallback() {
		$this->assertSame( 'n/a', Alaveteli_Stats_Render::stat( 'does_not_exist', array( 'fallback' => 'n/a' ) ) );
	}

	public function test_known_scalar_renders_span() {
		$this->assertSame(
			'<span class="alaveteli-stat" data-key="name">hello</span>',
			Alaveteli_Stats_Render::stat( 'name' )
		);
	}

	/** Regression guard: a value of 0 must render, not fall back. */
	public function test_zero_integer_renders_not_fallback() {
		$out = Alaveteli_Stats_Render::stat( 'zero', array( 'fallback' => 'NONE' ) );
		$this->assertStringContainsString( '>0<', $out );
		$this->assertStringNotContainsString( 'NONE', $out );
	}

	public function test_zero_string_renders_not_fallback() {
		$out = Alaveteli_Stats_Render::stat( 'zero_str', array( 'fallback' => 'NONE' ) );
		$this->assertStringContainsString( '>0<', $out );
		$this->assertStringNotContainsString( 'NONE', $out );
	}

	public function test_boolean_value_renders_fallback() {
		$this->assertSame( 'FB', Alaveteli_Stats_Render::stat( 'flag', array( 'fallback' => 'FB' ) ) );
	}

	public function test_array_value_renders_fallback() {
		$this->assertSame( 'FB', Alaveteli_Stats_Render::stat( 'nested', array( 'fallback' => 'FB' ) ) );
	}

	public function test_empty_string_value_renders_fallback() {
		$this->assertSame( 'FB', Alaveteli_Stats_Render::stat( 'blank', array( 'fallback' => 'FB' ) ) );
	}

	public function test_number_is_formatted_with_thousands_separators() {
		$this->assertStringContainsString( '1,234,567', Alaveteli_Stats_Render::stat( 'visible_request_count' ) );
	}

	public function test_format_false_shows_raw_number() {
		$out = Alaveteli_Stats_Render::stat( 'visible_request_count', array( 'format' => 'false' ) );
		$this->assertStringContainsString( '>1234567<', $out );
		$this->assertStringNotContainsString( '1,234,567', $out );
	}

	public function test_fractional_value_preserves_source_decimals() {
		$this->assertStringContainsString( '>4.5<', Alaveteli_Stats_Render::stat( 'ratio' ) );
		$this->assertStringContainsString( '>12.50<', Alaveteli_Stats_Render::stat( 'ratio_str' ) );
	}

	/** Regression guard (#2): a small fraction must not be flattened to zero by E-notation. */
	public function test_small_fraction_is_not_rendered_as_zero() {
		$out = Alaveteli_Stats_Render::stat( 'tiny' );
		$this->assertStringContainsString( '>0.00001<', $out );
		$this->assertStringNotContainsString( '0.0000<', $out );
	}

	/** Regression guard (#6): an integer above 2^53 must keep full precision, not round via float. */
	public function test_large_integer_keeps_full_precision() {
		$out = Alaveteli_Stats_Render::stat( 'huge' );
		$this->assertStringContainsString( '9,007,199,254,740,993', $out );
		$this->assertStringNotContainsString( '9,007,199,254,740,992', $out );
	}

	/** Regression guard: the fallback is escaped so it cannot inject markup. */
	public function test_fallback_is_html_escaped() {
		$out = Alaveteli_Stats_Render::stat( '', array( 'fallback' => '<script>x</script>' ) );
		$this->assertSame( '&lt;script&gt;x&lt;/script&gt;', $out );
	}

	public function test_key_is_attribute_escaped() {
		$out = Alaveteli_Stats_Render::stat( 'quote"key' );
		$this->assertStringContainsString( 'data-key="quote&quot;key"', $out );
	}

	public function test_shortcode_renders_same_markup() {
		$this->assertSame(
			'<span class="alaveteli-stat" data-key="name">hello</span>',
			do_shortcode( '[alaveteli_stat key="name"]' )
		);
	}

	public function test_shortcode_honours_format_attribute() {
		$out = do_shortcode( '[alaveteli_stat key="visible_request_count" format="false"]' );
		$this->assertStringContainsString( '1234567', $out );
		$this->assertStringNotContainsString( '1,234,567', $out );
	}
}
