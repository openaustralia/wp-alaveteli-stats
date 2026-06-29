<?php
/**
 * Persistence for the fetched Alaveteli statistics.
 *
 * This is the only class that reads from or writes to the plugin's options.
 * It guarantees that the last good set of statistics is never lost when a
 * fetch fails: failures only update the error fields, leaving `stats` intact.
 *
 * @package wp-alaveteli-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alaveteli_Stats_Store {

	const OPTION_SETTINGS = 'alaveteli_stats_settings';
	const OPTION_DATA     = 'alaveteli_stats_data';

	/**
	 * Consecutive failures, or age of the last good value, beyond which a
	 * fetch problem is escalated to an admin notice.
	 */
	const FAILURE_THRESHOLD = 3;
	const STALE_AFTER       = 6 * HOUR_IN_SECONDS;

	/**
	 * Plugin settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			(array) get_option( self::OPTION_SETTINGS, array() ),
			array( 'source_url' => '' )
		);
	}

	/**
	 * The configured Alaveteli base URL (without the version.json path).
	 *
	 * @return string
	 */
	public static function get_source_url() {
		return self::get_settings()['source_url'];
	}

	/**
	 * The empty data record: the shape every stored record has, and the state
	 * a successful fetch resets the error fields to.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'stats'                => array(),
			'fetched_at'           => 0,
			'last_error'           => '',
			'last_error_at'        => null,
			'last_error_url'       => '',
			'last_error_category'  => '',
			'last_error_detail'    => array(),
			'consecutive_failures' => 0,
		);
	}

	/**
	 * The cached data record, with defaults filled in.
	 *
	 * @return array
	 */
	public static function get_data() {
		return wp_parse_args(
			(array) get_option( self::OPTION_DATA, array() ),
			self::defaults()
		);
	}

	/**
	 * All cached statistics, keyed exactly as the source JSON provides them.
	 *
	 * @return array
	 */
	public static function get_stats() {
		return self::get_data()['stats'];
	}

	/**
	 * A single statistic, or null when the key is not present.
	 *
	 * @param string $key Statistic key.
	 * @return mixed|null
	 */
	public static function get_stat( $key ) {
		$stats = self::get_stats();

		return array_key_exists( $key, $stats ) ? $stats[ $key ] : null;
	}

	/**
	 * The metadata about the last fetch (everything except the stats payload).
	 *
	 * @return array
	 */
	public static function get_meta() {
		$data = self::get_data();
		unset( $data['stats'] );

		return $data;
	}

	/**
	 * Persist the outcome of a fetch.
	 *
	 * On success the statistics are replaced and the error state is cleared.
	 * On a WP_Error the existing statistics are preserved and only the error
	 * fields and failure counter are updated.
	 *
	 * @param array|WP_Error $result Decoded statistics, or a fetch error.
	 * @return array The stored data record.
	 */
	public static function save( $result ) {
		$data = self::get_data();

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$error_data = is_array( $error_data ) ? $error_data : array();

			$data['last_error']           = $result->get_error_message();
			$data['last_error_at']        = time();
			$data['last_error_url']       = isset( $error_data['url'] ) ? $error_data['url'] : '';
			$data['last_error_category']  = isset( $error_data['category'] ) ? $error_data['category'] : $result->get_error_code();
			$data['last_error_detail']    = $error_data;
			$data['consecutive_failures'] = (int) $data['consecutive_failures'] + 1;
		} else {
			// A success resets every error field to its default and replaces the
			// stats, so start from the defaults rather than clearing each field.
			$data = array_merge(
				self::defaults(),
				array(
					'stats'      => (array) $result,
					'fetched_at' => time(),
				)
			);
		}

		update_option( self::OPTION_DATA, $data );

		return $data;
	}

	/**
	 * Whether any statistics have ever been cached.
	 *
	 * @return bool
	 */
	public static function has_data() {
		return ! empty( self::get_stats() );
	}

	/**
	 * Whether the most recent fetch failed.
	 *
	 * @return bool
	 */
	public static function has_error() {
		return '' !== self::get_data()['last_error'];
	}

	/**
	 * Whether the cached data is older than the staleness window.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$fetched_at = (int) self::get_data()['fetched_at'];

		// Staleness only applies once we have had a successful fetch. A site
		// that has never succeeded is not "stale"; escalation for it is driven
		// solely by the consecutive-failure count, so a single early failure is
		// still absorbed rather than escalated immediately.
		if ( ! $fetched_at ) {
			return false;
		}

		return ( time() - $fetched_at ) > self::STALE_AFTER;
	}

	/**
	 * Whether a fetch problem has persisted long enough to warrant an admin
	 * notice. A single, self-healing failure deliberately returns false.
	 *
	 * @return bool
	 */
	public static function needs_attention() {
		if ( ! self::has_error() ) {
			return false;
		}

		// An unconfigured site is not a failure to escalate; the settings page
		// guides the admin through setup instead of warning about stale data.
		if ( '' === self::get_source_url() ) {
			return false;
		}

		$failures = (int) self::get_data()['consecutive_failures'];

		return $failures >= self::FAILURE_THRESHOLD || self::is_stale();
	}
}
