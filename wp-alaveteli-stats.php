<?php
/**
 * Plugin Name:       Alaveteli Stats
 * Plugin URI:        https://github.com/openaustralia/wp-alaveteli-stats
 * Description:        Shows live statistics from an Alaveteli site (such as Right to Know) on a WordPress page, refreshed automatically in the background.
 * Version:           0.1.0
 * Requires at least: 5.4
 * Requires PHP:      7.2
 * Author:            OpenAustralia Foundation
 * License:           MIT
 * Text Domain:       wp-alaveteli-stats
 *
 * @package wp-alaveteli-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALAVETELI_STATS_VERSION', '0.1.0' );
define( 'ALAVETELI_STATS_FILE', __FILE__ );
define( 'ALAVETELI_STATS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALAVETELI_STATS_CRON_HOOK', 'alaveteli_stats_refresh' );
define( 'ALAVETELI_STATS_SCHEDULE', 'alaveteli_stats_two_hours' );

require_once ALAVETELI_STATS_DIR . 'includes/class-alaveteli-stats-store.php';
require_once ALAVETELI_STATS_DIR . 'includes/class-alaveteli-stats-fetcher.php';
require_once ALAVETELI_STATS_DIR . 'includes/class-alaveteli-stats-render.php';
require_once ALAVETELI_STATS_DIR . 'includes/class-alaveteli-stats-settings.php';

/**
 * Plugin controller: wires the components together and owns the WP-Cron
 * lifecycle and the shared refresh orchestration.
 */
class Alaveteli_Stats {

	/**
	 * Register runtime hooks. Runs on every request.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( ALAVETELI_STATS_CRON_HOOK, array( __CLASS__, 'refresh' ) );

		Alaveteli_Stats_Render::init();

		if ( is_admin() ) {
			Alaveteli_Stats_Settings::init();
		}
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'wp-alaveteli-stats', false, dirname( plugin_basename( ALAVETELI_STATS_FILE ) ) . '/languages' );
	}

	/**
	 * Add the custom two-hour cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ ALAVETELI_STATS_SCHEDULE ] = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => __( 'Every two hours (Alaveteli Stats)', 'wp-alaveteli-stats' ),
		);

		return $schedules;
	}

	/**
	 * Fetch the latest statistics and store the outcome. This is the cron
	 * callback and is also called by the "Refresh now" button.
	 *
	 * @param bool $retry Whether to retry once on a transient error. Pass false
	 *                    on interactive requests so they do not block on a
	 *                    doubled timeout.
	 * @return array|WP_Error The fetch result.
	 */
	public static function refresh( $retry = true ) {
		$result = Alaveteli_Stats_Fetcher::fetch( Alaveteli_Stats_Store::get_source_url(), $retry );
		Alaveteli_Stats_Store::save( $result );

		return $result;
	}

	/**
	 * On activation: schedule the recurring refresh and do one immediate fetch
	 * so a page is not blank before the first scheduled run. A failed fetch
	 * must not prevent activation.
	 */
	public static function activate() {
		// Ensure the custom interval is registered before scheduling.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );

		// Start the recurring schedule one interval out: the immediate fetch
		// below covers "now", so a first run at time() would just refetch.
		if ( ! wp_next_scheduled( ALAVETELI_STATS_CRON_HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, ALAVETELI_STATS_SCHEDULE, ALAVETELI_STATS_CRON_HOOK );
		}

		try {
			self::refresh();
		} catch ( \Throwable $e ) {
			// Swallow: the scheduled event will retry.
		}
	}

	/**
	 * On deactivation: clear the scheduled refresh.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( ALAVETELI_STATS_CRON_HOOK );
	}
}

register_activation_hook( __FILE__, array( 'Alaveteli_Stats', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Alaveteli_Stats', 'deactivate' ) );

Alaveteli_Stats::boot();
