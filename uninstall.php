<?php
/**
 * Removes the plugin's stored options on uninstall.
 *
 * @package wp-alaveteli-stats
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the store so the option names stay tied to their single source of
// truth (the class constants) rather than being duplicated as literals here.
require_once __DIR__ . '/includes/class-alaveteli-stats-store.php';

delete_option( Alaveteli_Stats_Store::OPTION_SETTINGS );
delete_option( Alaveteli_Stats_Store::OPTION_DATA );
