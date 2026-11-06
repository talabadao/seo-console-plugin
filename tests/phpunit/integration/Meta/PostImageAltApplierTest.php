<?php
/**
 * post_image_alt applier: read/validate/write and the approve → revert round trip.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Meta;

use SEOConsole\Modules\Meta\PostImageAltApplier;
use SEOConsole\Plugin;
use SEOConsole\Tests\Fixtures\AI\ModuleTestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/ai/ModuleTestCase.php';

class PostImageAltApplierTest extends ModuleTestCase {

	/** @var int */
	private $health_calls = 0;

	public function set_up(): void {
		parent::set_up();
		$this->health_calls = 0;
		add_filter( 'seoc_health_check_pre', array( $this, 'healthy' ) );
	}

	public function tear_down(): void {
		remove_filter( 'seoc_health_check_pre', array( $this, 'healthy' ) );
		parent::tear_down();
	}

	public function healthy() {
		++$this->health_calls;
		return array(
			'ok'     => true,
			'checks' => array(),
		);
	}

	/**
	 * A post with the attachment twice (core/image block + classic HTML) and another image.
	 *
	 * @return int[] [ post id, attachment id, other attachment id ]
	 */
	private function make_post(): array {
		$post_id = self::factory()->post->create();
		$att     = self::factory()->attachment->create_object( 'filter.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$other   = self::factory()->attachment->create_object( 'other.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$content = '<!-- wp:image {"id":' . $att . ',"sizeSlug":"large"} -->' . "\n"
			. '<figure class="wp-block-image size-large"><img src="http://example.org/wp-content/uploads/filter-1024x768.jpg" alt="" class="wp-image-' . $att . '"/></figure>' . "\n"
			. '<!-- /wp:image -->' . "\n"
			. '<p>Text <iframe src="https://www.youtube.com/embed/x"></iframe></p>' . "\n"
			. '<p><img class="alignnone size-medium wp-image-' . $att . '" src="http://example.org/wp-content/uploads/filter-300x200.jpg" width="300" height="200" /></p>' . "\n"
			. '<img class="wp-image-' . $other . '" src="http://example.org/wp-content/uploads/other.jpg" alt="Other image">';
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) ); // Raw content, no kses.
		clean_post_cache( $post_id );
		return array( $post_id, $att, $other );
	}

	public function test_read_validate_and_replace(): void {
		list( $post_id, $att, $other ) = $this->make_post();
		$applier                       = new PostImageAltApplier();

		$this->assertSame( '', $applier->read( 'post', $post_id, 'image_alt:' . $att ) );
		$this->assertSame( 'Other image', $applier->read( 'post', $post_id, 'image_alt:' . $other ) );
		$this->assertNull( $applier->read( 'post', $post_id, 'image_alt:999999' ) );
		$this->assertNull( $applier->read( 'post', $post_id, 'alt' ) );

		$this->assertTrue( $applier->validate( 'Water filter', 'post', $post_id, 'image_alt:' . $att ) );
		$this->assertWPError( $applier->validate( 'x', 'attachment', $att, 'image_alt:' . $att ) );
		$this->assertWPError( $applier->validate( 'x', 'post', $post_id, 'image_alt:999999' ) );
		$this->assertWPError( $applier->validate( '<b>x</b>', 'post', $post_id, 'image_alt:' . $att ) );
		$this->assertWPError( $applier->validate( str_repeat( 'x', 251 ), 'post', $post_id, 'image_alt:' . $att ) );

		$html = PostImageAltApplier::replace_alt( '<img data-id="7" src="a.jpg"><img class="wp-image-77" alt="keep">', 7, 'Say "hi" & $1' );
		$this->assertSame( '<img alt="Say &quot;hi&quot; &amp; $1" data-id="7" src="a.jpg"><img class="wp-image-77" alt="keep">', $html );
	}

	public function test_approve_and_revert_round_trip(): void {
		list( $post_id, $att, $other ) = $this->make_post();
		$this->login( 'author' ); // No unfiltered_html: kses must not strip the iframe.
		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		$suggestions = Plugin::instance()->suggestions();
		$id          = $suggestions->create(
			array(
				'module'         => 'meta',
				'type'           => 'post_image_alt',
				'object_type'    => 'post',
				'object_id'      => $post_id,
				'field'          => 'image_alt:' . $att,
				'proposed_value' => 'Under-sink water filter with three cartridges',
				'source'         => 'ai',
			)
		);
		$this->assertIsInt( $id );
		$this->assertSame( '', $suggestions->get( $id )['current_value'] );
		$this->assertSame( 'Image alt text (in post)', $suggestions->get( $id )['field_label'] );

		$change = $suggestions->approve( $id );
		$this->assertNotWPError( $change );
		$this->assertSame( 1, $this->health_calls );

		$content = get_post( $post_id )->post_content;
		$this->assertSame( 2, substr_count( $content, 'alt="Under-sink water filter with three cartridges"' ) );
		$this->assertStringContainsString( 'alt="Other image"', $content );
		$this->assertStringContainsString( '<iframe src="https://www.youtube.com/embed/x"></iframe>', $content, 'Content is not filtered by kses.' );
		$this->assertStringContainsString( '<!-- wp:image {"id":' . $att, $content );
		$this->assertSame( 'Under-sink water filter with three cartridges', get_post_meta( $att, '_wp_attachment_image_alt', true ), 'Empty library alt is filled.' );
		$this->assertGreaterThan( $revisions_before, count( wp_get_post_revisions( $post_id ) ) );

		$reverted = Plugin::instance()->changes()->revert( $change->id );
		$this->assertTrue( $reverted );
		$content = get_post( $post_id )->post_content;
		$this->assertSame( 0, substr_count( $content, 'Under-sink water filter' ) );
		$this->assertSame( '', ( new PostImageAltApplier() )->read( 'post', $post_id, 'image_alt:' . $att ) );
		$this->assertSame( 'Under-sink water filter with three cartridges', get_post_meta( $att, '_wp_attachment_image_alt', true ), 'Revert restores only the inline alt.' );
	}

	public function test_existing_library_alt_is_not_overwritten(): void {
		list( $post_id, $att ) = $this->make_post();
		update_post_meta( $att, '_wp_attachment_image_alt', 'Library alt' );
		( new PostImageAltApplier() )->write( 'post', $post_id, 'image_alt:' . $att, 'Inline alt' );
		$this->assertSame( 'Library alt', get_post_meta( $att, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Inline alt', ( new PostImageAltApplier() )->read( 'post', $post_id, 'image_alt:' . $att ) );
	}
}
