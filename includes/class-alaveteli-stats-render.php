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

		// Work from the source's own digits rather than via number_format_i18n,
		// which takes a float: a float cast would silently lose precision on a
		// very large integer and render a small fraction in scientific notation
		// (e.g. 1.0E-5), which then formats as 0. Grouping the digit string
		// directly avoids both, while still applying the locale's separators.
		$string   = self::decimal_string( $value );
		$negative = '' !== $string && '-' === $string[0];
		if ( $negative ) {
			$string = substr( $string, 1 );
		}

		$dot       = strpos( $string, '.' );
		$int_part  = ( false === $dot ) ? $string : substr( $string, 0, $dot );
		$frac_part = ( false === $dot ) ? '' : substr( $string, $dot + 1 );

		global $wp_locale;
		$thousands_sep = isset( $wp_locale ) ? $wp_locale->number_format['thousands_sep'] : ',';
		$decimal_point = isset( $wp_locale ) ? $wp_locale->number_format['decimal_point'] : '.';

		$out = ( $negative ? '-' : '' ) . self::group_thousands( $int_part, $thousands_sep );
		if ( '' !== $frac_part ) {
			$out .= $decimal_point . $frac_part;
		}

		return $out;
	}

	/**
	 * Render a numeric value as a plain decimal digit string, preserving the
	 * source's exact decimals and never falling back to scientific notation.
	 *
	 * @param int|float|string $value A value already known to be numeric.
	 * @return string
	 */
	private static function decimal_string( $value ) {
		// A plain decimal string is used verbatim so trailing zeros the source
		// chose (e.g. "12.50") survive.
		if ( is_string( $value ) && preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return (string) $value;
		}

		// A float, or a numeric string in another form (e.g. "1e3", ".5"):
		// expand to plain decimal notation, then drop the padding zeros.
		$string = sprintf( '%.14F', (float) $value );
		if ( false !== strpos( $string, '.' ) ) {
			$string = rtrim( rtrim( $string, '0' ), '.' );
		}

		return $string;
	}

	/**
	 * Insert a thousands separator into a string of digits.
	 *
	 * @param string $digits Digits only (no sign or decimal point).
	 * @param string $sep    Separator to insert.
	 * @return string
	 */
	private static function group_thousands( $digits, $sep ) {
		if ( '' === $sep || strlen( $digits ) <= 3 ) {
			return $digits;
		}

		return strrev( implode( $sep, str_split( strrev( $digits ), 3 ) ) );
	}
}
