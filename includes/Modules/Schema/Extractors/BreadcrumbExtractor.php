<?php
/**
 * BreadcrumbList: Home → primary category (posts) or parent pages (pages) → the post.
 * Only useful when no SEO plugin outputs breadcrumbs already (the builder checks that).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Html;

defined( 'ABSPATH' ) || exit;

final class BreadcrumbExtractor extends AbstractExtractor {

	/** {@inheritDoc} */
	public function type(): string {
		return 'BreadcrumbList';
	}

	/** {@inheritDoc} */
	public function extract( \WP_Post $post ): array {
		$trail = array( array( __( 'Home', 'seo-console' ), home_url( '/' ) ) );
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
				$trail[] = array( Html::to_text( get_the_title( $ancestor ) ), (string) get_permalink( $ancestor ) );
			}
		} elseif ( 'post' === $post->post_type ) {
			$categories = get_the_category( $post->ID );
			if ( $categories ) {
				$category = $categories[0];
				foreach ( array_reverse( get_ancestors( $category->term_id, 'category', 'taxonomy' ) ) as $parent_id ) {
					$parent  = get_term( $parent_id, 'category' );
					$trail[] = $parent instanceof \WP_Term ? array( $parent->name, (string) get_term_link( $parent ) ) : null;
				}
				$trail[] = array( $category->name, (string) get_term_link( $category ) );
			}
		}
		$trail[] = array( Html::to_text( get_the_title( $post ) ), (string) get_permalink( $post ) );

		$items = array();
		foreach ( array_filter( $trail ) as $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => count( $items ) + 1,
				'name'     => html_entity_decode( $crumb[0], ENT_QUOTES, 'UTF-8' ),
				'item'     => $crumb[1],
			);
		}
		if ( count( $items ) < 2 ) {
			return array();
		}
		return array(
			$this->candidate(
				array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => $items,
				),
				__( 'Built from the site structure (home, category or parent pages, this page).', 'seo-console' )
			),
		);
	}
}
