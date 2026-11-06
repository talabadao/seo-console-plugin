<?php
/**
 * PostContext: what Claude gets to read about a post.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Meta;

use SEOConsole\Modules\Meta\PostContext;
use SEOConsole\Modules\Meta\Prompts;
use WP_UnitTestCase;

class PostContextTest extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/fixtures/ai/woocommerce-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/fixtures/ai/polylang-stubs.php';
	}

	public function tear_down() {
		unset( $GLOBALS['seoc_test_wc_products'], $GLOBALS['seoc_test_pll_languages'] );
		parent::tear_down();
	}

	public function test_normal_post(): void {
		$cat     = self::factory()->category->create( array( 'name' => 'Guides' ) );
		$content = '<!-- wp:paragraph --><p>Intro paragraph about <strong>water filters</strong>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Why filter water?</h2><!-- /wp:heading -->'
			. '<p>Because taps &amp; pipes.</p><script>alert("x")</script><style>.a{}</style>'
			. '[vc_row][vc_column]<ul><li>First</li><li>Second</li></ul>[/vc_column][/vc_row]'
			. '<h3>Details</h3><p>More text.</p>';
		$post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Water filter guide',
				'post_name'     => 'water-filter-guide',
				'post_content'  => $content,
				'post_excerpt'  => 'A short excerpt.',
				'post_category' => array( $cat ),
				'tags_input'    => array( 'filters', 'health' ),
			)
		);
		update_option( 'blogname', 'Aqua Shop' );
		update_option( 'blogdescription', 'Clean water for everyone' );

		$ctx = ( new PostContext() )->build(
			$post_id,
			array(
				'focus_keyword'  => 'water filter',
				'title'          => 'Old title',
				'description'    => 'Old description',
				'brand_appended' => true,
			)
		);

		$this->assertIsArray( $ctx );
		$this->assertSame( 'post', $ctx['post_type'] );
		$this->assertSame( 'Water filter guide', $ctx['title'] );
		$this->assertSame( 'water-filter-guide', $ctx['slug'] );
		$this->assertSame( get_permalink( $post_id ), $ctx['permalink'] );
		$this->assertSame( 'A short excerpt.', $ctx['excerpt'] );
		$this->assertSame( array( 'Guides' ), $ctx['categories'] );
		$this->assertEqualsCanonicalizing( array( 'filters', 'health' ), $ctx['tags'] );
		$this->assertSame( 'water filter', $ctx['seo']['focus_keyword'] );
		$this->assertSame( 'Old title', $ctx['seo']['title'] );
		$this->assertTrue( $ctx['seo']['brand_appended'] );
		$this->assertSame( 'Aqua Shop', $ctx['seo']['brand'] );
		$this->assertSame( 'Aqua Shop', $ctx['site']['name'] );
		$this->assertSame( 'Clean water for everyone', $ctx['site']['tagline'] );
		$this->assertSame( 'en', $ctx['language'] );
		$this->assertNull( $ctx['product'] );
		$this->assertFalse( $ctx['text_truncated'] );

		$text = $ctx['text'];
		$this->assertStringStartsWith( 'Intro paragraph about water filters.', $text );
		$this->assertStringContainsString( "## Why filter water?\n\nBecause taps & pipes.", $text );
		$this->assertStringContainsString( "- First\n- Second", $text );
		$this->assertStringContainsString( '### Details', $text );
		$this->assertSame( "Text\n\nmore", PostContext::html_to_text( '<p>Text</p><script>alert("x")</script><style>.a{}</style><noscript>n</noscript><p>more</p>' ) );
		$this->assertStringNotContainsString( 'wp:', $text );
		$this->assertStringNotContainsString( '[vc_', $text );
		$this->assertStringNotContainsString( '<', $text );
		$this->assertSame(
			array(
				array(
					'level' => 2,
					'text'  => 'Why filter water?',
				),
				array(
					'level' => 3,
					'text'  => 'Details',
				),
			),
			$ctx['headings']
		);
	}

	public function test_missing_post_is_an_error(): void {
		$this->assertWPError( ( new PostContext() )->build( 999999 ) );
	}

	public function test_long_post_is_shortened_but_keeps_intro_and_every_heading(): void {
		$intro   = '<p>INTRO-START ' . str_repeat( 'Opening words matter. ', 40 ) . 'INTRO-END</p>';
		$content = $intro;
		for ( $i = 1; $i <= 25; $i++ ) {
			$content .= '<h2>Section number ' . $i . '</h2><p>SECTION-' . $i . '-START ' . str_repeat( 'Lorem ipsum dolor sit amet consectetur. ', 60 ) . 'SECTION-' . $i . '-END</p>';
		}
		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );

		$ctx = ( new PostContext() )->build( $post_id );

		$this->assertTrue( $ctx['text_truncated'] );
		$this->assertGreaterThan( 50000, $ctx['text_length'] );
		$this->assertLessThanOrEqual( PostContext::MAX_TEXT_CHARS + 200, PostContext::len( $ctx['text'] ) );
		$this->assertStringContainsString( 'INTRO-START', $ctx['text'] );
		$this->assertStringContainsString( 'INTRO-END', $ctx['text'] );
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->assertStringContainsString( '## Section number ' . $i . "\n", $ctx['text'] );
			$this->assertStringContainsString( 'SECTION-' . $i . '-START', $ctx['text'] );
		}
		$this->assertStringNotContainsString( 'SECTION-1-END', $ctx['text'] );
		$this->assertStringContainsString( '[…]', $ctx['text'] );

		$message = Prompts::meta_user_message( $ctx, array( 'title' ) );
		$this->assertStringContainsString( 'The main text was shortened', $message );
	}

	public function test_shorten_without_headings_keeps_the_beginning(): void {
		$text  = str_repeat( 'word ', 5000 );
		$short = PostContext::shorten( $text, 2000 );
		$this->assertTrue( $short['truncated'] );
		$this->assertLessThanOrEqual( 2000, PostContext::len( $short['text'] ) );
		$this->assertStringStartsWith( 'word word', $short['text'] );
	}

	public function test_vietnamese_post_language_from_site_locale(): void {
		$filter = static function () {
			return 'vi';
		};
		add_filter( 'locale', $filter );
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hướng dẫn chọn máy lọc nước',
				'post_content' => '<h2>Máy lọc nước RO là gì?</h2><p>Máy lọc nước RO sử dụng màng thẩm thấu ngược.</p>',
			)
		);
		$ctx = ( new PostContext() )->build( $post_id, array( 'focus_keyword' => 'máy lọc nước' ) );
		remove_filter( 'locale', $filter );

		$this->assertSame( 'vi', $ctx['language'] );
		$this->assertSame( 'Vietnamese', $ctx['language_name'] );
		$this->assertSame( 'Hướng dẫn chọn máy lọc nước', $ctx['title'] );
		$this->assertStringContainsString( '## Máy lọc nước RO là gì?', $ctx['text'] );

		$message = Prompts::meta_user_message( $ctx, array( 'title', 'description' ) );
		$this->assertStringContainsString( 'Language: Vietnamese (vi)', $message );
		$this->assertStringContainsString( 'Focus keyword: máy lọc nước', $message );
	}

	public function test_language_from_polylang_then_wpml(): void {
		$post_id = self::factory()->post->create();

		$GLOBALS['seoc_test_pll_languages'] = array( $post_id => 'vi_VN' );
		$this->assertSame( 'vi_VN', PostContext::post_locale( get_post( $post_id ) ) );
		unset( $GLOBALS['seoc_test_pll_languages'] );

		$wpml = static function ( $details, $id ) use ( $post_id ) {
			return $id === $post_id ? array( 'locale' => 'fr_FR', 'language_code' => 'fr' ) : $details;
		};
		add_filter( 'wpml_post_language_details', $wpml, 10, 2 );
		$this->assertSame( 'fr_FR', PostContext::post_locale( get_post( $post_id ) ) );
		remove_filter( 'wpml_post_language_details', $wpml, 10 );

		$this->assertSame( get_locale(), PostContext::post_locale( get_post( $post_id ) ) );
	}

	public function test_product_data(): void {
		register_post_type( 'product', array( 'public' => true, 'labels' => array( 'singular_name' => 'Product' ) ) );
		register_taxonomy( 'product_cat', 'product' );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'product',
				'post_title'   => 'Máy lọc nước RO 9 lõi',
				'post_content' => '<p>Long description.</p>',
			)
		);
		$term = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Máy lọc nước' ) );
		wp_set_object_terms( $post_id, array( $term ), 'product_cat' );

		$GLOBALS['seoc_test_wc_products'] = array(
			$post_id => array(
				'price'             => '5990000',
				'regular_price'     => '6990000',
				'sale_price'        => '5990000',
				'sku'               => 'RO-9',
				'stock_status'      => 'instock',
				'short_description' => '<p>Lọc sạch <b>99%</b> vi khuẩn.</p>',
				'attributes'        => array(
					'Công suất' => '10 lít/giờ',
					'Số lõi'    => '9',
				),
			),
		);

		$ctx = ( new PostContext() )->build( $post_id );
		_unregister_post_type( 'product' );
		_unregister_taxonomy( 'product_cat' );

		$this->assertSame( 'Product', $ctx['post_type_label'] );
		$this->assertSame( array( 'Máy lọc nước' ), $ctx['categories'] );
		$this->assertSame( '5990000', $ctx['product']['price'] );
		$this->assertSame( '6990000', $ctx['product']['regular_price'] );
		$this->assertSame( 'RO-9', $ctx['product']['sku'] );
		$this->assertSame( 'VND', $ctx['product']['currency'] );
		$this->assertSame( 'Lọc sạch 99% vi khuẩn.', $ctx['product']['short_description'] );
		$this->assertSame(
			array(
				'Công suất' => '10 lít/giờ',
				'Số lõi'    => '9',
			),
			$ctx['product']['attributes']
		);

		$message = Prompts::meta_user_message( $ctx, array( 'title' ) );
		$this->assertStringContainsString( 'SKU: RO-9', $message );
		$this->assertStringContainsString( 'Công suất: 10 lít/giờ', $message );
	}

	public function test_images_with_and_without_alt(): void {
		$post_id = self::factory()->post->create();
		$with    = self::factory()->attachment->create_object( 'kitchen-filter.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$without = self::factory()->attachment->create_object( 'IMG_1234.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$thumb   = self::factory()->attachment->create_object( 'cover.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $with, '_wp_attachment_image_alt', 'Under-sink water filter in a kitchen' );
		set_post_thumbnail( $post_id, $thumb );

		$upload  = wp_get_upload_dir()['baseurl'];
		$content = '<h2>Installation</h2>'
			. '<figure class="wp-block-image"><img src="' . $upload . '/kitchen-filter-300x200.jpg" alt="Under-sink water filter in a kitchen" class="wp-image-' . $with . '"/><figcaption>Our filter under the sink</figcaption></figure>'
			. '<h2>Parts</h2>'
			. '[caption id="attachment_' . $without . '"]<img class="size-full wp-image-' . $without . '" src="' . $upload . '/IMG_1234.jpg" /> The nine cartridges[/caption]'
			. '<p><img src="https://cdn.example.com/banner.png" alt=""></p>'
			. '<img src="data:image/gif;base64,R0lGOD" alt="">';
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);

		$images = ( new PostContext() )->build( $post_id )['images'];

		$this->assertCount( 4, $images );
		$this->assertSame( $with, $images[0]['attachment_id'] );
		$this->assertSame( 'kitchen-filter.jpg', $images[0]['filename'] );
		$this->assertSame( 'Under-sink water filter in a kitchen', $images[0]['alt'] );
		$this->assertSame( 'Under-sink water filter in a kitchen', $images[0]['attachment_alt'] );
		$this->assertSame( 'Our filter under the sink', $images[0]['caption'] );
		$this->assertSame( 'Installation', $images[0]['heading'] );

		$this->assertSame( $without, $images[1]['attachment_id'] );
		$this->assertSame( '', $images[1]['alt'] );
		$this->assertSame( '', $images[1]['attachment_alt'] );
		$this->assertSame( 'The nine cartridges', $images[1]['caption'] );
		$this->assertSame( 'Parts', $images[1]['heading'] );

		$this->assertSame( 0, $images[2]['attachment_id'] );
		$this->assertSame( 'banner.png', $images[2]['filename'] );

		$this->assertSame( $thumb, $images[3]['attachment_id'] );
		$this->assertTrue( $images[3]['featured'] );

		$this->assertFalse( PostContext::is_weak_alt( $images[0]['attachment_alt'], $images[0]['filename'] ) );
		$this->assertTrue( PostContext::is_weak_alt( '', 'x.jpg' ) );
		$this->assertTrue( PostContext::is_weak_alt( 'IMG_1234', 'IMG_1234.jpg' ) );
		$this->assertTrue( PostContext::is_weak_alt( 'kitchen filter', 'kitchen-filter.jpg' ) );
		$this->assertTrue( PostContext::is_weak_alt( 'DSC 0042', 'a.jpg' ) );
	}
}
