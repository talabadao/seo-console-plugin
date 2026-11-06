<?php
/**
 * Page-cache plugin detection and purge (see docs/ARCHITECTURE.md §9).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Detects LiteSpeed Cache, WP Rocket, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache and WP Fastest Cache
 * by their constants/functions and purges through their public hooks/functions. Read-only otherwise: SEO Console
 * never writes another plugin's configuration.
 */
final class CachePlugins {

	/**
	 * Detected cache plugins.
	 *
	 * @return array<int, array{id: string, name: string, version: string, page_cache: bool}>
	 */
	public function detect(): array {
		$found = array();

		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			$found[] = $this->entry( 'litespeed', 'LiteSpeed Cache', defined( 'LSCWP_V' ) ? (string) LSCWP_V : '' );
		}
		if ( defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' ) ) {
			$found[] = $this->entry( 'wp-rocket', 'WP Rocket', defined( 'WP_ROCKET_VERSION' ) ? (string) WP_ROCKET_VERSION : '' );
		}
		if ( defined( 'SiteGround_Optimizer\VERSION' ) || function_exists( 'sg_cachepress_purge_cache' ) ) {
			$found[] = $this->entry( 'sg-optimizer', 'SiteGround Speed Optimizer', defined( 'SiteGround_Optimizer\VERSION' ) ? (string) constant( 'SiteGround_Optimizer\VERSION' ) : '' );
		}
		if ( defined( 'W3TC_VERSION' ) || function_exists( 'w3tc_flush_all' ) ) {
			$found[] = $this->entry( 'w3tc', 'W3 Total Cache', defined( 'W3TC_VERSION' ) ? (string) W3TC_VERSION : '' );
		}
		if ( function_exists( 'wp_cache_clear_cache' ) || defined( 'WPCACHEHOME' ) ) {
			$found[] = $this->entry( 'wp-super-cache', 'WP Super Cache', '' );
		}
		if ( class_exists( 'WpFastestCache' ) || function_exists( 'wpfc_clear_all_cache' ) || defined( 'WPFC_WP_PLUGIN_DIR' ) ) {
			$found[] = $this->entry( 'wp-fastest-cache', 'WP Fastest Cache', '' );
		}

		/**
		 * Filters the detected cache plugins.
		 *
		 * @param array $found List of { id, name, version, page_cache }.
		 */
		return (array) apply_filters( 'seoc_cache_plugins', $found );
	}

	/**
	 * Ids of the detected plugins.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_values( wp_list_pluck( $this->detect(), 'id' ) );
	}

	/**
	 * Purges every detected page cache.
	 *
	 * @return string[] Ids of the plugins that were asked to purge.
	 */
	public function purge_all(): array {
		$purged = array();
		foreach ( $this->ids() as $id ) {
			switch ( $id ) {
				case 'litespeed':
					if ( has_action( 'litespeed_purge_all' ) ) {
						do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's public hook.
						$purged[] = $id;
					}
					break;
				case 'wp-rocket':
					if ( function_exists( 'rocket_clean_domain' ) ) {
						rocket_clean_domain();
						$purged[] = $id;
					}
					break;
				case 'sg-optimizer':
					if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
						sg_cachepress_purge_everything();
						$purged[] = $id;
					} elseif ( function_exists( 'sg_cachepress_purge_cache' ) ) {
						sg_cachepress_purge_cache();
						$purged[] = $id;
					}
					break;
				case 'w3tc':
					if ( function_exists( 'w3tc_flush_all' ) ) {
						w3tc_flush_all();
						$purged[] = $id;
					}
					break;
				case 'wp-super-cache':
					if ( function_exists( 'wp_cache_clear_cache' ) ) {
						wp_cache_clear_cache();
						$purged[] = $id;
					}
					break;
				case 'wp-fastest-cache':
					if ( function_exists( 'wpfc_clear_all_cache' ) ) {
						wpfc_clear_all_cache( true );
						$purged[] = $id;
					} elseif ( has_action( 'wpfc_clear_all_cache' ) ) {
						do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's public hook.
						$purged[] = $id;
					}
					break;
			}
		}

		/**
		 * Fires after SEO Console asked the cache plugins to purge everything.
		 *
		 * @param string[] $purged Plugin ids.
		 */
		do_action( 'seoc_cache_purged', $purged, null );
		return $purged;
	}

	/**
	 * Purges one URL where the plugin supports it.
	 *
	 * @param string $url URL on this site.
	 * @return string[] Ids of the plugins that were asked to purge.
	 */
	public function purge_url( string $url ): array {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return array();
		}
		$purged = array();
		foreach ( $this->ids() as $id ) {
			switch ( $id ) {
				case 'litespeed':
					if ( has_action( 'litespeed_purge_url' ) ) {
						do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's public hook.
						$purged[] = $id;
					}
					break;
				case 'wp-rocket':
					if ( function_exists( 'rocket_clean_files' ) ) {
						rocket_clean_files( array( $url ) );
						$purged[] = $id;
					}
					break;
				case 'sg-optimizer':
					if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
						sg_cachepress_purge_cache( $url );
						$purged[] = $id;
					}
					break;
				case 'w3tc':
					if ( function_exists( 'w3tc_flush_url' ) ) {
						w3tc_flush_url( $url );
						$purged[] = $id;
					}
					break;
				case 'wp-super-cache':
					if ( function_exists( 'wpsc_delete_url_cache' ) ) {
						wpsc_delete_url_cache( $url );
						$purged[] = $id;
					}
					break;
				case 'wp-fastest-cache':
					$post_id = url_to_postid( $url );
					if ( $post_id && has_action( 'wpfc_clear_post_cache_by_id' ) ) {
						do_action( 'wpfc_clear_post_cache_by_id', false, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's public hook.
						$purged[] = $id;
					}
					break;
			}
		}

		/** This action is documented in includes/Integrations/CachePlugins.php */
		do_action( 'seoc_cache_purged', $purged, $url );
		return $purged;
	}

	/**
	 * Builds one detection entry.
	 *
	 * @param string $id      Id.
	 * @param string $name    Name.
	 * @param string $version Version.
	 * @return array{id: string, name: string, version: string, page_cache: bool}
	 */
	private function entry( string $id, string $name, string $version ): array {
		return array(
			'id'         => $id,
			'name'       => $name,
			'version'    => $version,
			'page_cache' => true,
		);
	}
}
