<?php
/**
 * Test stub imitating LiteSpeed Cache (SEOC_TEST_STUB=litespeed). Purges are recorded in
 * $GLOBALS['seoc_stub_purges'].
 *
 * @package SEOConsole
 */

if ( ! defined( 'LSCWP_V' ) ) {
	define( 'LSCWP_V', '7.0-stub' );
}
$GLOBALS['seoc_stub_purges'] = array();
add_action(
	'litespeed_purge_all',
	static function () {
		$GLOBALS['seoc_stub_purges'][] = 'litespeed:all';
	}
);
add_action(
	'litespeed_purge_url',
	static function ( $url ) {
		$GLOBALS['seoc_stub_purges'][] = 'litespeed:' . $url;
	}
);
