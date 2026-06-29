<?php
/**
 * Renders a single cached statistic.
 *
 * This is the shared output path: the [alaveteli_stat] shortcode uses it now,
 * and the Phase 2 Gutenberg block will reuse the same method so both produce
 * identical markup.
 *
 * @package wp-alaveteli-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alaveteli_Stats_Render {

	/**
	 * Register the shortcode.
	 */
	public static function init() {
		add_shortcode( 'alaveteli_stat', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Default display options, shared by the shortcode and the stat() entry
	 * point so the two cannot drift apart.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'format'   => 'true',
			'fallback' => '',
		);
	}

	/**
	 * Shortcode handler for [alaveteli_stat key="..." format="true" fallback="..."].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'key' => '' ) + self::defaults(),
			$atts,
			'alaveteli_stat'
		);

		return self::stat( $atts['key'], $atts );
	}

	/**
	 * Render a statistic as an escaped HTML string.
	 *
	 * Unknown keys, an empty key, or no cached data all render the fallback
	 * rather than failing, so a page never breaks because of a bad key.
	 *
	 * @param string $key  Statistic key, e.g. visible_request_count.
	 * @param array  $atts Optional. 'format' (bool-ish) and 'fallback' (string).
	 * @return string
	 */
	public static function stat( $key, $atts = array() ) {
		$atts = wp_parse_args( $atts, self::defaults() );

		// Use the key verbatim (only trimmed): stored keys come straight from
		// the source JSON, so normalising the case or stripping characters here
		// would make some advertised keys impossible to look up.
		$key      = trim( (string) $key );
		$fallback = esc_html( (string) $atts['fallback'] );

		if ( '' === $key ) {
			return $fallback;
		}

		$value = Alaveteli_Stats_Store::get_stat( $key );

		// Render the fallback for anything that is not a displayable scalar:
		// a missing key (null), a nested array/object, a boolean, or an empty
		// string. This avoids printing "Array" or an empty span.
		if ( ! is_scalar( $value ) || is_bool( $value ) || '' === $value ) {
			return $fallback;
		}

		$display = self::format_value( $value, filter_var( $atts['format'], FILTER_VALIDATE_BOOLEAN ) );

		return sprintf(
			'<span class="alaveteli-stat" data-key="%s">%s</span>',
			esc_attr( $key ),
			esc_html( $display )
		);
	}

	/**
	 * Format a value for display, applying locale-aware thousands separators
	 * to numeric values when formatting is enabled.
	 *
	 * @param mixed $value  Raw value from the source JSON.
	 * @param bool  $format Whether to apply number formatting.
	 * @return string
	 */
	private static function format_value( $value, $format ) {
		if ( ! $format || ! is_numeric( $value ) ) {
			return (string) $value;
		}

		$number = (float) $value;

		// Whole numbers: format with no decimals. Pass the float (not an int
		// cast) so very large counts are not truncated or overflowed.
		if ( $number === floor( $number ) ) {
			return number_format_i18n( $number, 0 );
		}

		// Fractional values: keep the decimals the source provided, since
		// number_format_i18n() would otherwise round to a whole number.
		$parts    = explode( '.', (string) $value );
		$decimals = isset( $parts[1] ) ? strlen( $parts[1] ) : 0;

		return number_format_i18n( $number, $decimals );
	}
}
