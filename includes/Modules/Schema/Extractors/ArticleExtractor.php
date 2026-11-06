<?php
/**
 * BlogPosting for posts, from WordPress data (title, dates, author, featured image, excerpt).
 * Only useful when no SEO plugin outputs Article already (the builder checks that).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Html;

defined( 'ABSPATH' ) || exit;

final class ArticleExtractor extends AbstractExtractor {

	/** {@inheritDoc} */
	public function type(): string {
		return 'BlogPosting';
	}

	/** {@inheritDoc} */
	public function extract( \WP_Post $post ): array {
		if ( 'post' !== $post->post_type ) {
			return array();
		}
		$author = get_userdata( (int) $post->post_author );
		$image  = get_post_thumbnail_id( $post ) ? (string) wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post ), 'full' ) : '';
		$node   = array(
			'@type'         => 'BlogPosting',
			'headline'      => Html::to_text( get_the_title( $post ) ),
			'datePublished' => (string) get_post_time( DATE_W3C, true, $post ),
			'dateModified'  => (string) get_post_modified_time( DATE_W3C, true, $post ),
			'image'         => $image,
			'description'   => '' !== $post->post_excerpt ? Html::to_text( $post->post_excerpt ) : '',
		);
		if ( $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => $author->display_name,
				'url'   => (string) get_author_posts_url( $author->ID ),
			);
		}
		return array( $this->candidate( $node, __( 'Built from the post title, dates, author and featured image.', 'seo-console' ) ) );
	}
}
