<?php
/**
 * Dev site only (dev/docker-compose.yml mounts this folder as mu-plugins): inside the container the site URL
 * http://localhost:8080 is not reachable (Apache listens on port 80), so loopback requests — SEO Console's health
 * check, WordPress Site Health — fail. Re-route them to port 80 with the original Host header.
 *
 * @package SEOConsole
 */

if ( 'local' !== wp_get_environment_type() ) {
	return;
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$parts = wp_parse_url( $url );
		if ( false !== $pre || empty( $parts['host'] ) || 'localhost' !== $parts['host'] || empty( $parts['port'] ) || 80 === (int) $parts['port'] ) {
			return $pre;
		}
		$host                    = $parts['host'] . ':' . $parts['port'];
		$args['headers']         = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Host'] = $host;
		$internal                = preg_replace( '#^(https?://)localhost:\d+#', 'http://localhost', $url );
		return wp_remote_request( $internal, $args );
	},
	10,
	3
);
