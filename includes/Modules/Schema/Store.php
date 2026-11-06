<?php
/**
 * Approved schema per post, stored as a JSON list of nodes in hidden post meta `_seoc_schema`.
 * Nodes are stored without @context/@id; Composer adds stable ids when the page is rendered.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class Store {

	const META_KEY = '_seoc_schema';

	/**
	 * Approved nodes for a post ([] when none or unreadable).
	 *
	 * @param int $post_id Post id.
	 * @return array[]
	 */
	public static function get( int $post_id ): array {
		$nodes = self::raw( $post_id );
		return null === $nodes ? array() : $nodes;
	}

	/**
	 * Approved nodes, or null when nothing is stored (used as a suggestion's current_value).
	 *
	 * @param int $post_id Post id.
	 * @return array[]|null
	 */
	public static function raw( int $post_id ): ?array {
		$value = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_string( $value ) && '' !== $value ) {
			$value = json_decode( $value, true );
		}
		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}
		if ( ! Validator::is_list( $value ) ) {
			$value = array( $value );
		}
		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Saves nodes (an empty list deletes the meta).
	 *
	 * @param int     $post_id Post id.
	 * @param array[] $nodes   Nodes.
	 */
	public static function set( int $post_id, array $nodes ): bool {
		$nodes = array_values( array_filter( $nodes, 'is_array' ) );
		if ( array() === $nodes ) {
			return self::delete( $post_id );
		}
		$json = wp_json_encode( $nodes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}
		if ( get_post_meta( $post_id, self::META_KEY, true ) === $json ) {
			return true;
		}
		return (bool) update_post_meta( $post_id, self::META_KEY, wp_slash( $json ) );
	}

	/**
	 * @param int $post_id Post id.
	 */
	public static function delete( int $post_id ): bool {
		delete_post_meta( $post_id, self::META_KEY );
		return true;
	}

	/**
	 * Top-level @types in a list of nodes.
	 *
	 * @param array[] $nodes Nodes.
	 * @return string[]
	 */
	public static function types( array $nodes ): array {
		$types = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $type ) {
					if ( is_string( $type ) ) {
						$types[] = $type;
					}
				}
			}
		}
		return array_values( array_unique( $types ) );
	}
}
