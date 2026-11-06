<?php
/**
 * WordPress test-suite configuration. Values can be overridden by environment
 * variables (CI). Never point this at a real site's database: the suite drops tables.
 *
 * @package SEOConsole
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'SEOC_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'SEOC_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'SEOC_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'SEOC_DB_HOST' ) ?: '127.0.0.1:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'SEOC_DB_PREFIX' ) ?: 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'AUTH_KEY', 'seoc-test-auth-key' );
define( 'SECURE_AUTH_KEY', 'seoc-test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'seoc-test-logged-in-key' );
define( 'NONCE_KEY', 'seoc-test-nonce-key' );
define( 'AUTH_SALT', 'seoc-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'seoc-test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'seoc-test-logged-in-salt' );
define( 'NONCE_SALT', 'seoc-test-nonce-salt' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'SEO Console Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
