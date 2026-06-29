<?php
/**
 * Admin settings page, status panel, "Refresh now" action and admin notice.
 *
 * @package wp-alaveteli-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Alaveteli_Stats_Settings {

	const PAGE_SLUG     = 'alaveteli-stats';
	const SETTINGS_GROUP = 'alaveteli_stats';
	const REFRESH_ACTION = 'alaveteli_stats_refresh_now';

	/**
	 * Wire up the admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_admin_notice' ) );

		// Fetch immediately when the source URL is saved, so the admin sees the
		// result of their change straight away rather than the old error state
		// (or a blank page) until the next scheduled run.
		add_action( 'add_option_' . Alaveteli_Stats_Store::OPTION_SETTINGS, array( __CLASS__, 'refresh_after_save' ) );
		add_action( 'update_option_' . Alaveteli_Stats_Store::OPTION_SETTINGS, array( __CLASS__, 'refresh_after_save' ) );
	}

	/**
	 * Refresh the statistics after the settings are saved, provided a source
	 * URL is configured. Runs without the retry so the save request does not
	 * block on a doubled timeout.
	 */
	public static function refresh_after_save() {
		if ( '' !== Alaveteli_Stats_Store::get_source_url() ) {
			Alaveteli_Stats::refresh( false );
		}
	}

	/**
	 * Add the settings page under the Settings menu.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Alaveteli Stats', 'wp-alaveteli-stats' ),
			__( 'Alaveteli Stats', 'wp-alaveteli-stats' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the single source URL setting.
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Alaveteli_Stats_Store::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array( 'source_url' => '' ),
			)
		);

		add_settings_section(
			'alaveteli_stats_main',
			'',
			array( __CLASS__, 'section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'source_url',
			__( 'Alaveteli site URL', 'wp-alaveteli-stats' ),
			array( __CLASS__, 'field_source_url' ),
			self::PAGE_SLUG,
			'alaveteli_stats_main'
		);
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$url = ( is_array( $input ) && isset( $input['source_url'] ) ) ? trim( $input['source_url'] ) : '';

		return array( 'source_url' => esc_url_raw( $url ) );
	}

	/**
	 * Intro text for the settings section.
	 */
	public static function section_intro() {
		echo '<p>' . esc_html__( 'Enter the base URL of the Alaveteli site to pull statistics from. The plugin reads its public /version.json file.', 'wp-alaveteli-stats' ) . '</p>';
	}

	/**
	 * Render the source URL input.
	 */
	public static function field_source_url() {
		$value = Alaveteli_Stats_Store::get_source_url();
		printf(
			'<input type="url" name="%1$s[source_url]" id="alaveteli_stats_source_url" value="%2$s" class="regular-text" placeholder="https://www.righttoknow.org.au" />',
			esc_attr( Alaveteli_Stats_Store::OPTION_SETTINGS ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'For example: https://www.righttoknow.org.au', 'wp-alaveteli-stats' ) . '</p>';
	}

	/**
	 * Handle the "Refresh now" form submission, then redirect back.
	 */
	public static function handle_refresh_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-alaveteli-stats' ) );
		}

		check_admin_referer( self::REFRESH_ACTION );

		// Interactive request: skip the transient-error retry so a slow or down
		// source does not block the click on a doubled timeout.
		$result  = Alaveteli_Stats::refresh( false );
		$refreshed = is_wp_error( $result ) ? 'error' : 'ok';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'refreshed' => $refreshed,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Show a dismissible admin notice when a fetch problem has persisted.
	 */
	public static function maybe_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! Alaveteli_Stats_Store::needs_attention() ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Alaveteli Stats could not refresh its data and the figures shown may be out of date.', 'wp-alaveteli-stats' ),
			esc_url( $settings_url ),
			esc_html__( 'View details', 'wp-alaveteli-stats' )
		);
	}

	/**
	 * Render the settings page: configuration form, status panel and the
	 * table of currently cached statistics.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$refreshed = isset( $_GET['refreshed'] ) ? sanitize_key( wp_unslash( $_GET['refreshed'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Alaveteli Stats', 'wp-alaveteli-stats' ); ?></h1>

			<?php if ( 'ok' === $refreshed ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Statistics refreshed successfully.', 'wp-alaveteli-stats' ); ?></p></div>
			<?php elseif ( 'error' === $refreshed ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The refresh failed. See the status below for details.', 'wp-alaveteli-stats' ); ?></p></div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save settings', 'wp-alaveteli-stats' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Status', 'wp-alaveteli-stats' ); ?></h2>
			<?php self::render_status(); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
				<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
				<?php submit_button( __( 'Refresh now', 'wp-alaveteli-stats' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_stats_table(); ?>

			<h2><?php esc_html_e( 'Using a statistic', 'wp-alaveteli-stats' ); ?></h2>
			<p><?php esc_html_e( 'Add a statistic to any page or post with a shortcode, naming a key from the table below. For example:', 'wp-alaveteli-stats' ); ?></p>
			<p><code>[alaveteli_stat key="visible_request_count"]</code></p>
			<p><?php esc_html_e( 'Optional attributes: format="false" to show the raw number, and fallback="..." for the text shown when the statistic is unavailable.', 'wp-alaveteli-stats' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the fetch status and, when relevant, the failure diagnostics.
	 */
	private static function render_status() {
		$meta = Alaveteli_Stats_Store::get_meta();

		echo '<table class="widefat striped" style="max-width:60em"><tbody>';

		// Last successful fetch.
		if ( $meta['fetched_at'] ) {
			$when = self::format_time( $meta['fetched_at'] );
			$ago  = human_time_diff( $meta['fetched_at'], time() );
			self::status_row(
				__( 'Last updated', 'wp-alaveteli-stats' ),
				sprintf(
					/* translators: 1: formatted date/time, 2: human time difference, e.g. "3 hours". */
					esc_html__( '%1$s (%2$s ago)', 'wp-alaveteli-stats' ),
					esc_html( $when ),
					esc_html( $ago )
				)
			);
		} else {
			self::status_row(
				__( 'Last updated', 'wp-alaveteli-stats' ),
				esc_html__( 'Never. No statistics have been fetched yet.', 'wp-alaveteli-stats' )
			);
		}

		// Error diagnostics, if the most recent fetch failed.
		if ( '' !== $meta['last_error'] ) {
			self::status_row(
				__( 'Last error', 'wp-alaveteli-stats' ),
				self::format_error( $meta ),
				true
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Build the human-readable, admin-actionable error diagnostics.
	 *
	 * @param array $meta Metadata from the store.
	 * @return string Safe HTML.
	 */
	private static function format_error( $meta ) {
		$detail   = is_array( $meta['last_error_detail'] ) ? $meta['last_error_detail'] : array();
		$category = $meta['last_error_category'];

		$lines = array();

		$lines[] = esc_html( $meta['last_error'] );

		if ( ! empty( $meta['last_error_url'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: the URL that was requested. */
				esc_html__( 'URL requested: %s', 'wp-alaveteli-stats' ),
				'<code>' . esc_html( $meta['last_error_url'] ) . '</code>'
			);
		}

		if ( $meta['last_error_at'] ) {
			$lines[] = sprintf(
				/* translators: %s: formatted date/time. */
				esc_html__( 'Occurred: %s', 'wp-alaveteli-stats' ),
				esc_html( self::format_time( $meta['last_error_at'] ) )
			);
		}

		$hint = self::remediation_hint( $category );
		if ( '' !== $hint ) {
			$lines[] = '<em>' . esc_html( $hint ) . '</em>';
		}

		if ( ! empty( $detail['body_snippet'] ) ) {
			$lines[] = esc_html__( 'Response began:', 'wp-alaveteli-stats' )
				. ' <code>' . esc_html( $detail['body_snippet'] ) . '</code>';
		}

		return implode( '<br />', $lines );
	}

	/**
	 * Plain-language fix suggestion for a failure category.
	 *
	 * @param string $category One of config, transport, http, invalid.
	 * @return string
	 */
	private static function remediation_hint( $category ) {
		switch ( $category ) {
			case 'config':
				return __( 'Enter the Alaveteli site URL above and save.', 'wp-alaveteli-stats' );
			case 'transport':
				return __( 'The site could not be reached. Check the URL is correct and that this server can make outbound HTTPS requests.', 'wp-alaveteli-stats' );
			case 'http':
				return __( 'A 403 or 503 response often means a firewall or CDN is blocking server requests. Allowlist this server, or check the URL is correct.', 'wp-alaveteli-stats' );
			case 'invalid':
				return __( 'The URL did not return JSON. Make sure it is the base URL of an Alaveteli site, not a page within it.', 'wp-alaveteli-stats' );
			default:
				return '';
		}
	}

	/**
	 * Render the table of currently cached statistics so the editor can see
	 * which keys this instance exposes.
	 */
	private static function render_stats_table() {
		$stats = Alaveteli_Stats_Store::get_stats();

		echo '<h2>' . esc_html__( 'Available statistics', 'wp-alaveteli-stats' ) . '</h2>';

		if ( empty( $stats ) ) {
			echo '<p>' . esc_html__( 'No statistics are cached yet.', 'wp-alaveteli-stats' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
		echo '<th>' . esc_html__( 'Key', 'wp-alaveteli-stats' ) . '</th>';
		echo '<th>' . esc_html__( 'Value', 'wp-alaveteli-stats' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $stats as $key => $value ) {
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td></tr>',
				esc_html( $key ),
				esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A single status table row.
	 *
	 * @param string $label     Row label (plain text).
	 * @param string $value_html Row value (already-escaped HTML).
	 * @param bool   $is_error  Whether to style the row as an error.
	 */
	private static function status_row( $label, $value_html, $is_error = false ) {
		printf(
			'<tr><th scope="row" style="width:12em">%1$s</th><td%2$s>%3$s</td></tr>',
			esc_html( $label ),
			$is_error ? ' style="color:#b32d2e"' : '',
			$value_html // Callers pass escaped HTML.
		);
	}

	/**
	 * Format a timestamp using the site's date and time format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return date_i18n( $format, (int) $timestamp );
	}
}
