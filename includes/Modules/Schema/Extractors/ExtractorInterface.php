<?php
/**
 * A deterministic (no AI) source of schema candidates for a post.
 *
 * A candidate is: [
 *   'type'     => 'FAQPage',          // schema type of the node
 *   'node'     => array,              // the node, without @context/@id
 *   'source'   => 'rule',
 *   'evidence' => string,             // plain English: what was found and where
 *   'missing'  => string[],           // properties the extractor could not fill (user must add them)
 * ]
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

defined( 'ABSPATH' ) || exit;

interface ExtractorInterface {

	/** Schema type this extractor produces (used to skip it when the type already exists). */
	public function type(): string;

	/**
	 * Candidates found in the post ([] when none).
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	public function extract( \WP_Post $post ): array;
}
