<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite (wp-phpunit) against the
 * WordPress core in vendor/johnpbloch/wordpress-core and a throwaway database
 * (see bin/test-db.sh). Every test runs with WordPress loaded.
 *
 * @package SEOConsole
 */

$root = dirname( __DIR__, 2 );
require_once $root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: $root . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root ) {
		// Optional stubs that imitate Rank Math / Yoast for integration tests.
		if ( getenv( 'SEOC_TEST_STUB' ) ) {
			require_once __DIR__ . '/stubs/' . getenv( 'SEOC_TEST_STUB' ) . '.php';
		}
		require $root . '/seo-console.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
