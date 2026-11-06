<?php
/**
 * Tweaks: each tweak adds/removes the right output, guards and cache-plugin skips.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\Tweaks;
use WP_UnitTestCase;

class TweaksTest extends WP_UnitTestCase {

	public function tear_down(): void {
		wp_styles()->queue  = array();
		wp_scripts()->queue = array();
		parent::tear_down();
	}

	private function apply( array $ids, array $config = array() ): array {
		$result = ( new Tweaks() )->apply( $ids, $config );
		$this->go_to( home_url( '/' ) ); // Fires `wp`.
		return $result;
	}

	public function test_definitions(): void {
		$defs = Tweaks::definitions();
		$this->assertSame(
			array( 'disable_emojis', 'disable_embeds', 'disable_dashicons_guests', 'clean_head', 'disable_xmlrpc', 'heartbeat', 'lcp_boost', 'preconnect', 'limit_revisions' ),
			array_keys( $defs )
		);
		foreach ( $defs as $id => $def ) {
			$this->assertSame( $id, $def['id'] );
			$this->assertNotEmpty( $def['title'] );
			$this->assertNotEmpty( $def['description'] );
			$this->assertContains( $def['risk'], array( 'low', 'medium' ) );
			$this->assertContains( $def['scope'], array( 'frontend', 'everywhere' ) );
			$this->assertTrue( $def['undoable'] );
			$this->assertSame( (bool) $def['cache_feature'], '' !== $def['skips_when'] );
		}
	}

	public function test_disable_emojis(): void {
		$this->assertSame( 7, has_action( 'wp_head', 'print_emoji_detection_script' ) );
		$result = $this->apply( array( 'disable_emojis' ) );
		$this->assertSame( array( 'disable_emojis' ), $result['applied'] );
		$this->assertFalse( has_action( 'wp_head', 'print_emoji_detection_script' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' ) );
		$head = get_echo( 'wp_head' );
		$this->assertStringNotContainsString( '_wpemojiSettings', $head );
		$this->assertStringNotContainsString( 's.w.org', $head );
	}

	public function test_skipped_when_cache_plugin_does_it(): void {
		$result = ( new Tweaks() )->apply(
			array( 'disable_emojis', 'disable_embeds', 'heartbeat', 'clean_head', 'nope' ),
			array(
				'cache_features' => array(
					'disable_emojis' => true,
					'disable_embeds' => false,
					'heartbeat'      => null,
				),
			)
		);
		$this->assertSame( array( 'disable_embeds', 'heartbeat', 'clean_head' ), $result['applied'] );
		$this->assertSame(
			array(
				'disable_emojis' => 'cache_plugin',
				'nope'           => 'unknown',
			),
			$result['skipped']
		);
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 7, has_action( 'wp_head', 'print_emoji_detection_script' ) );

		// A plain list of features works too.
		$result = ( new Tweaks() )->apply( array( 'heartbeat' ), array( 'cache_features' => array( 'heartbeat' ) ) );
		$this->assertSame( array( 'heartbeat' => 'cache_plugin' ), $result['skipped'] );
	}

	public function test_scope_filter(): void {
		$result = ( new Tweaks() )->apply( array( 'heartbeat', 'limit_revisions', 'clean_head' ), array(), 'everywhere' );
		$this->assertSame( array( 'heartbeat', 'limit_revisions' ), $result['applied'] );
		$this->assertSame( array( 'clean_head' => 'scope' ), $result['skipped'] );
	}

	public function test_disable_embeds(): void {
		$this->assertNotFalse( has_action( 'wp_head', 'wp_oembed_add_discovery_links' ) );
		$this->apply( array( 'disable_embeds' ) );
		$this->assertFalse( has_action( 'wp_head', 'wp_oembed_add_discovery_links' ) );
		$this->assertFalse( has_action( 'wp_head', 'wp_oembed_add_host_js' ) );
		$this->assertFalse( apply_filters( 'embed_oembed_discover', true ) );

		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertStringNotContainsString( 'application/json+oembed', get_echo( 'wp_head' ) );
	}

	public function test_clean_head(): void {
		$this->apply( array( 'clean_head' ) );
		$head = get_echo( 'wp_head' );
		$this->assertStringNotContainsString( 'EditURI', $head );
		$this->assertStringNotContainsString( 'name="generator"', $head );
		$this->assertStringNotContainsString( 'rel=\'shortlink\'', $head );
		$this->assertFalse( has_action( 'template_redirect', 'wp_shortlink_header' ) );
	}

	public function test_clean_head_control_has_output(): void {
		$this->go_to( home_url( '/' ) );
		$head = get_echo( 'wp_head' );
		$this->assertStringContainsString( 'EditURI', $head );
		$this->assertStringContainsString( 'name="generator"', $head );
	}

	public function test_nothing_removed_for_feeds(): void {
		( new Tweaks() )->apply( array( 'clean_head', 'disable_emojis' ) );
		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );
		$this->assertNotFalse( has_action( 'wp_head', 'rsd_link' ) );
		$this->assertSame( 7, has_action( 'wp_head', 'print_emoji_detection_script' ) );
	}

	public function test_nothing_changed_in_admin(): void {
		set_current_screen( 'dashboard' );
		$this->assertFalse( Tweaks::is_public_request() );
		( new Tweaks() )->apply( array( 'clean_head', 'lcp_boost' ), array( 'lcp_images' => array( array( 'page_url' => home_url( '/' ), 'image_url' => 'https://example.org/a.jpg' ) ) ) );
		do_action( 'wp' );
		$this->assertNotFalse( has_action( 'wp_head', 'rsd_link' ) );
		$this->assertSame( '', get_echo( array( new Tweaks(), 'print_lcp_preload' ) ) );
		set_current_screen( 'front' );
	}

	public function test_dashicons_for_guests(): void {
		$this->apply( array( 'disable_dashicons_guests' ) );
		wp_enqueue_style( 'dashicons' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'dashicons', 'enqueued' ) );
	}

	public function test_dashicons_kept_when_another_style_needs_it(): void {
		$this->apply( array( 'disable_dashicons_guests' ) );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'seoc-test-icons', 'https://example.org/icons.css', array( 'dashicons' ), '1' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'dashicons', 'enqueued' ) );
	}

	public function test_dashicons_kept_for_logged_in_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->apply( array( 'disable_dashicons_guests' ) );
		wp_enqueue_style( 'dashicons' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'dashicons', 'enqueued' ) );
	}

	public function test_disable_xmlrpc(): void {
		$this->apply( array( 'disable_xmlrpc' ) );
		$this->assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		$this->assertSame( array(), apply_filters( 'xmlrpc_methods', array( 'pingback.ping' => 'this:pingback_ping' ) ) );
		$this->assertArrayNotHasKey( 'X-Pingback', apply_filters( 'wp_headers', array( 'X-Pingback' => 'https://example.org/xmlrpc.php' ) ) );
	}

	public function test_heartbeat(): void {
		$this->apply( array( 'heartbeat' ), array( 'heartbeat_interval' => 60 ) );
		wp_enqueue_script( 'heartbeat' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( 'heartbeat', 'enqueued' ) );
		// Frontend settings untouched.
		$this->assertArrayNotHasKey( 'interval', apply_filters( 'heartbeat_settings', array() ) );

		set_current_screen( 'post' );
		$this->assertSame( 60, apply_filters( 'heartbeat_settings', array() )['interval'] );
		set_current_screen( 'front' );
	}

	public function test_limit_revisions(): void {
		$post = self::factory()->post->create_and_get();
		$this->assertSame( -1, wp_revisions_to_keep( $post ) );
		( new Tweaks() )->apply( array( 'limit_revisions' ) );
		$this->assertSame( 10, wp_revisions_to_keep( $post ) );
		$this->assertSame( 5, apply_filters( 'wp_revisions_to_keep', 5, $post ) );
		$this->assertSame( 0, apply_filters( 'wp_revisions_to_keep', 0, $post ) );
	}

	public function test_limit_revisions_custom_value(): void {
		( new Tweaks() )->apply( array( 'limit_revisions' ), array( 'revisions_to_keep' => 3 ) );
		$this->assertSame( 3, apply_filters( 'wp_revisions_to_keep', -1, null ) );
	}

	public function test_preconnect(): void {
		$result = $this->apply(
			array( 'preconnect' ),
			array(
				'preconnect_origins' => array(
					'https://fonts.gstatic.com',
					'https://www.googletagmanager.com/gtag/js?id=1',
					'http://insecure.example.com',
					home_url( '/' ),
					'javascript:alert(1)',
					'https://a.example.net',
					'https://b.example.net',
					'https://c.example.net',
				),
			)
		);
		$this->assertSame( array( 'preconnect' ), $result['applied'] );
		ob_start();
		wp_resource_hints();
		$hints = ob_get_clean();
		$this->assertMatchesRegularExpression( "#<link href='https://fonts.gstatic.com' crossorigin='anonymous' rel='preconnect'#", $hints );
		$this->assertStringContainsString( "<link rel='preconnect' href='https://www.googletagmanager.com'", $hints );
		$this->assertStringContainsString( 'https://b.example.net', $hints );
		$this->assertStringNotContainsString( 'c.example.net', $hints );
		$this->assertStringNotContainsString( 'insecure', $hints );
		$this->assertStringNotContainsString( 'javascript', $hints );
	}

	public function test_preconnect_without_origins_is_skipped(): void {
		$result = ( new Tweaks() )->apply( array( 'preconnect', 'lcp_boost' ) );
		$this->assertSame(
			array(
				'preconnect' => 'missing_config',
				'lcp_boost'  => 'missing_config',
			),
			$result['skipped']
		);
	}

	private function lcp_config( string $page_url, array $extra = array() ): array {
		return array(
			'lcp_images' => array(
				array_merge(
					array(
						'page_url'  => $page_url,
						'image_url' => 'https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg',
					),
					$extra
				),
			),
		);
	}

	public function test_lcp_boost_preload_only_on_matching_page(): void {
		$tweaks = new Tweaks();
		$tweaks->apply(
			array( 'lcp_boost' ),
			$this->lcp_config(
				home_url( '/' ),
				array(
					'srcset' => 'https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg 768w, https://example.org/wp-content/uploads/2026/05/hero.jpg 1600w',
					'sizes'  => '(max-width: 768px) 100vw, 1600px',
				)
			)
		);
		$this->go_to( home_url( '/' ) );
		$head = get_echo( 'wp_head' );
		$this->assertStringContainsString( '<link rel="preload" as="image" href="https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg" fetchpriority="high" imagesrcset="https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg 768w, https://example.org/wp-content/uploads/2026/05/hero.jpg 1600w" imagesizes="(max-width: 768px) 100vw, 1600px" />', $head );

		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertStringNotContainsString( 'rel="preload" as="image"', get_echo( 'wp_head' ) );
	}

	public function test_lcp_boost_content_image(): void {
		$post_id = self::factory()->post->create();
		( new Tweaks() )->apply( array( 'lcp_boost' ), $this->lcp_config( get_permalink( $post_id ) ) );
		$this->go_to( get_permalink( $post_id ) );

		$html = '<p>x</p><img src="https://example.org/wp-content/uploads/2026/05/hero.jpg" srcset="https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg 768w" class="wp-image-5" loading="lazy" width="1600" height="854" alt="">'
			. '<img src="https://example.org/wp-content/uploads/2026/04/team.png" width="800" height="600" alt="">';
		$out  = wp_filter_content_tags( $html, 'the_content' );

		preg_match_all( '/<img[^>]*>/', $out, $tags );
		$this->assertStringContainsString( 'fetchpriority="high"', $tags[0][0] );
		$this->assertStringNotContainsString( 'loading="lazy"', $tags[0][0] );
		$this->assertStringContainsString( 'data-no-lazy="1"', $tags[0][0] );
		$this->assertStringContainsString( 'class="wp-image-5 skip-lazy"', $tags[0][0] );
		$this->assertStringNotContainsString( 'fetchpriority="high"', $tags[0][1] );
		$this->assertStringNotContainsString( 'skip-lazy', $tags[0][1] );
	}

	public function test_lcp_boost_attachment_image(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$src           = wp_get_attachment_image_url( $attachment_id, 'full' );
		( new Tweaks() )->apply(
			array( 'lcp_boost' ),
			array(
				'lcp_images' => array(
					array(
						'page_url'  => home_url( '/' ),
						'image_url' => $src,
					),
				),
			)
		);
		$this->go_to( home_url( '/' ) );
		$html = wp_get_attachment_image( $attachment_id, 'medium', false, array( 'loading' => 'lazy' ) );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
		$this->assertStringNotContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'skip-lazy', $html );
		$this->assertStringContainsString( 'data-no-lazy="1"', $html );

		$attrs = ( new Tweaks() )->filter_loading_attributes( array( 'loading' => 'lazy' ), 'img', array( 'src' => $src ), 'the_content' );
		$this->assertSame( array( 'loading' => 'lazy' ), $attrs, 'A Tweaks instance without config changes nothing.' );
	}

	public function test_page_key(): void {
		$this->assertSame( '/', Tweaks::page_key( 'https://example.org' ) );
		$this->assertSame( '/', Tweaks::page_key( 'https://example.org/?utm_source=x&fbclid=1' ) );
		$this->assertSame( '/about', Tweaks::page_key( 'https://example.org/About/' ) );
		$this->assertSame( '/?p=5', Tweaks::page_key( 'https://example.org/?utm_source=x&p=5' ) );
		$this->assertSame( '/?cat=2&page_id=3', Tweaks::page_key( '/?page_id=3&cat=2' ) );
	}

	public function test_image_matching(): void {
		$lcp = 'https://cdn.example.org/wp-content/uploads/2026/05/hero-768x410.jpg';
		$this->assertTrue( Tweaks::image_matches( 'https://example.org/wp-content/uploads/2026/05/hero.jpg', '', $lcp ) );
		$this->assertTrue( Tweaks::image_matches( '/wp-content/uploads/2026/05/hero-scaled.jpg', '', $lcp ) );
		$this->assertTrue( Tweaks::image_matches( 'https://example.org/wp-content/uploads/2026/05/hero-1024x546.jpg.webp', '', $lcp ) );
		$this->assertTrue( Tweaks::image_matches( 'data:image/gif;base64,R0lGOD', 'https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg 768w', $lcp ) );
		$this->assertFalse( Tweaks::image_matches( 'https://example.org/wp-content/uploads/2026/05/hero2.jpg', '', $lcp ) );
		$this->assertFalse( Tweaks::image_matches( 'https://example.org/wp-content/uploads/2026/04/hero.jpg', '', $lcp ) );
	}

	public function test_find_image_attributes(): void {
		$html  = '<img src="/logo.png"><img src="https://example.org/wp-content/uploads/2026/05/hero.jpg" srcset="https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg 768w, https://example.org/wp-content/uploads/2026/05/hero.jpg 1600w" sizes="(max-width: 1600px) 100vw, 1600px">';
		$attrs = Tweaks::find_image_attributes( $html, 'https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg' );
		$this->assertSame( '(max-width: 1600px) 100vw, 1600px', $attrs['sizes'] );
		$this->assertStringContainsString( '768w', $attrs['srcset'] );
		$this->assertNull( Tweaks::find_image_attributes( $html, 'https://example.org/other.jpg' ) );
	}

	public function test_boost_img_tag_existing_fetchpriority(): void {
		$out = Tweaks::boost_img_tag( '<img src="a.jpg" fetchpriority="low" loading=\'lazy\'>' );
		$this->assertSame( '<img class="skip-lazy" data-no-lazy="1" src="a.jpg" fetchpriority="high">', $out );
	}
}
