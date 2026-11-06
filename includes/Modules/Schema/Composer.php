<?php
/**
 * Turns approved nodes into graph pieces: stable @ids ({permalink}#seoc-<slug>[-n]) and links to
 * the SEO plugin's WebPage / Organization nodes (isPartOf, mainEntityOfPage, publisher, provider).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class Composer {

	/**
	 * The SEO plugin's WebPage @id for a URL, by its conventions (Rank Math "{url}#webpage";
	 * Yoast uses the canonical URL itself). Null when no SEO plugin outputs a WebPage.
	 *
	 * @param string $seo_plugin 'rankmath'|'yoast'|'none'.
	 * @param string $permalink  Page URL.
	 * @return string|null
	 */
	public static function webpage_id( string $seo_plugin, string $permalink ): ?string {
		if ( 'rankmath' === $seo_plugin ) {
			return $permalink . '#webpage';
		}
		if ( 'yoast' === $seo_plugin ) {
			return $permalink;
		}
		return null;
	}

	/**
	 * The site Organization @id (Rank Math and Yoast both use "{home}#organization"; ours otherwise).
	 *
	 * @param string $seo_plugin 'rankmath'|'yoast'|'none'.
	 */
	public static function organization_id( string $seo_plugin ): string {
		$home = home_url( '/' );
		return in_array( $seo_plugin, array( 'rankmath', 'yoast' ), true ) ? $home . '#organization' : $home . '#seoc-' . Types::id_slug( 'Organization' );
	}

	/**
	 * Composes a post's approved nodes.
	 *
	 * @param array[] $nodes Approved nodes (from Store).
	 * @param array   $ctx   url (string, required), webpage_id (?string), organization_id (?string),
	 *                       date_modified (?string ISO 8601: refreshes Article dateModified).
	 * @return array[] Graph pieces.
	 */
	public function compose( array $nodes, array $ctx ): array {
		$url     = isset( $ctx['url'] ) ? (string) $ctx['url'] : '';
		$webpage = isset( $ctx['webpage_id'] ) && is_string( $ctx['webpage_id'] ) && '' !== $ctx['webpage_id'] ? $ctx['webpage_id'] : null;
		$org     = isset( $ctx['organization_id'] ) && is_string( $ctx['organization_id'] ) && '' !== $ctx['organization_id'] ? $ctx['organization_id'] : null;
		$out     = array();
		$used    = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
				continue;
			}
			$type = Types::primary( $node['@type'] );
			if ( null === $type ) {
				continue;
			}
			unset( $node['@context'] );
			$node['@id'] = $this->unique_id( $url . '#seoc-' . Types::id_slug( $type ), $used );
			$def         = Types::get( $type );

			if ( 'isPartOf' === $def['link'] || Types::is_a( $type, 'Article' ) ) {
				if ( null !== $webpage && ! isset( $node['isPartOf'] ) ) {
					$node['isPartOf'] = array( '@id' => $webpage );
				} elseif ( null === $webpage && ! isset( $node['url'] ) && in_array( 'url', $def['allowed'], true ) && 'FAQPage' === $type ) {
					$node['url'] = $url;
				}
			}
			if ( 'mainEntityOfPage' === $def['link'] && ! isset( $node['mainEntityOfPage'] ) ) {
				$node['mainEntityOfPage'] = null !== $webpage ? array( '@id' => $webpage ) : $url;
			}
			if ( null !== $org && Types::is_a( $type, 'Article' ) && ! isset( $node['publisher'] ) ) {
				$node['publisher'] = array( '@id' => $org );
			}
			if ( null !== $org && 'Service' === $type && ! isset( $node['provider'] ) ) {
				$node['provider'] = array( '@id' => $org );
			}
			if ( Types::is_a( $type, 'Article' ) && ! empty( $ctx['date_modified'] ) ) {
				$node['dateModified'] = (string) $ctx['date_modified'];
			}
			$out[] = self::id_first( $node );
		}
		return $out;
	}

	/**
	 * Composes the site-wide Organization / LocalBusiness node for the front page.
	 *
	 * @param array $node Node from BusinessExtractor.
	 * @param array $ctx  parent_organization_id (?string): the SEO plugin's Organization @id; a
	 *                    LocalBusiness then points to it with parentOrganization.
	 * @return array
	 */
	public function compose_site( array $node, array $ctx = array() ): array {
		$type = (string) Types::primary( $node['@type'] );
		unset( $node['@context'] );
		$node['@id'] = home_url( '/' ) . '#seoc-' . Types::id_slug( $type );
		$parent      = isset( $ctx['parent_organization_id'] ) ? (string) $ctx['parent_organization_id'] : '';
		if ( '' !== $parent && Types::is_a( $type, 'LocalBusiness' ) && $parent !== $node['@id'] && ! isset( $node['parentOrganization'] ) ) {
			$node['parentOrganization'] = array( '@id' => $parent );
		}
		return self::id_first( $node );
	}

	/**
	 * @param string $id   Wanted id.
	 * @param array  $used Ids used so far.
	 */
	private function unique_id( string $id, array &$used ): string {
		$candidate = $id;
		$n         = 2;
		while ( isset( $used[ $candidate ] ) ) {
			$candidate = $id . '-' . $n;
			++$n;
		}
		$used[ $candidate ] = true;
		return $candidate;
	}

	/**
	 * Puts @type and @id first for readable output.
	 *
	 * @param array $node Node.
	 * @return array
	 */
	private static function id_first( array $node ): array {
		$head = array(
			'@type' => $node['@type'],
			'@id'   => $node['@id'],
		);
		return array_merge( $head, $node );
	}
}
