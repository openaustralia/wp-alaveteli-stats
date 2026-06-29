<?php
/**
 * Smoke test: proves the bootstrap, plugin load and test DB all work.
 *
 * @package wp-alaveteli-stats
 */

class Test_Smoke extends WP_UnitTestCase {

	public function test_plugin_classes_are_loaded() {
		$this->assertTrue( class_exists( 'Alaveteli_Stats_Store' ) );
		$this->assertTrue( class_exists( 'Alaveteli_Stats_Render' ) );
		$this->assertTrue( class_exists( 'Alaveteli_Stats_Fetcher' ) );
	}
}
