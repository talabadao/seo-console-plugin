<?php
/**
 * UrlPicker with factory content and SEO plugin noindex meta.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\UrlPicker;
use WP_UnitTestCase;

class UrlPickerTest extends WP_UnitTestCase {

	private function post( array $args = array() ): int {
		static $day = 1;
		++$day;
		return self::factory()->post->create(
			array_merge(
				array(
					'post_status' => 'publish',
					'post_date'   => sprintf( '2026-01-%02d 10:00:00', min( 28, $day ) ),
				),
				$args
			)
		);
	}

	public function test_picks_home_post_page_category(): void {
		$cat_big   = self::factory()->category->create( array( 'name' => 'News' ) );
		$cat_small = self::factory()->category->create( array( 'name' => 'Tips' ) );
		$old_post  = $this->post( array( 'post_category' => array( $cat_big ) ) );
		$this->post( array( 'post_category' => array( $cat_big ) ) );
		$new_post = $this->post(
			array(
				'post_category' => array( $cat_small ),
				'post_title'    => 'Latest &amp; greatest',
			)
		);
		$this->post(
			array(
				'post_status' => 'draft',
			)
		);
		$this->post( array( 'post_password' => 'secret' ) );
		$front = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Front',
			)
		);
		$about = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$blog  = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Blog',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );

		$picked = ( new UrlPicker( 'none' ) )->pick();
		$by     = array_column( $picked, null, 'type' );

		$this->assertSame( array( 'home', 'post', 'page', 'category' ), array_keys( $by ) );
		$this->assertSame( home_url( '/' ), $by['home']['url'] );
		$this->assertSame( $front, $by['home']['object_id'] );
		$this->assertSame( get_permalink( $new_post ), $by['post']['url'] );
		$this->assertSame( 'Latest & greatest', $by['post']['label'] );
		$this->assertSame( $about, $by['page']['object_id'] );
		$this->assertSame( get_term_link( $cat_big ), $by['category']['url'] );
		$this->assertSame( 'News', $by['category']['label'] );
		$this->assertNotSame( get_permalink( $old_post ), $by['post']['url'] );

		$this->assertCount( 2, ( new UrlPicker( 'none' ) )->pick( 2 ) );
	}

	public function test_skips_yoast_noindex(): void {
		$cat_a = self::factory()->category->create( array( 'name' => 'Hidden' ) );
		$cat_b = self::factory()->category->create( array( 'name' => 'Visible' ) );
		$older = $this->post( array( 'post_category' => array( $cat_b ) ) );
		$this->post( array( 'post_category' => array( $cat_a ) ) );
		$this->post( array( 'post_category' => array( $cat_a ) ) );
		$newest = $this->post( array( 'post_category' => array( $cat_a ) ) );
		update_post_meta( $newest, '_yoast_wpseo_meta-robots-noindex', '1' );
		update_option( 'wpseo_taxonomy_meta', array( 'category' => array( $cat_a => array( 'wpseo_noindex' => 'noindex' ) ) ) );

		$by = array_column( ( new UrlPicker( 'yoast' ) )->pick(), null, 'type' );
		$this->assertNotSame( $newest, $by['post']['object_id'] );
		$this->assertSame( $cat_b, $by['category']['object_id'] );

		// Without Yoast active, its leftover meta is ignored.
		$by = array_column( ( new UrlPicker( 'none' ) )->pick(), null, 'type' );
		$this->assertSame( $newest, $by['post']['object_id'] );
		$this->assertNotNull( $older );
	}

	public function test_yoast_post_type_default_noindex(): void {
		$page = $this->post( array( 'post_type' => 'page' ) );
		update_option( 'wpseo_titles', array( 'noindex-page' => true ) );
		$by = array_column( ( new UrlPicker( 'yoast' ) )->pick(), null, 'type' );
		$this->assertArrayNotHasKey( 'page', $by );

		update_post_meta( $page, '_yoast_wpseo_meta-robots-noindex', '2' ); // Forced index.
		$by = array_column( ( new UrlPicker( 'yoast' ) )->pick(), null, 'type' );
		$this->assertSame( $page, $by['page']['object_id'] );
	}

	public function test_skips_rank_math_noindex(): void {
		$keep = $this->post();
		$skip = $this->post();
		update_post_meta( $skip, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		$cat = self::factory()->category->create();
		$this->post( array( 'post_category' => array( $cat ) ) );
		update_term_meta( $cat, 'rank_math_robots', array( 'noindex' ) );

		$picker = new UrlPicker( 'rankmath' );
		$by     = array_column( $picker->pick(), null, 'type' );
		$this->assertNotSame( $skip, $by['post']['object_id'] );
		$this->assertNotSame( $cat, isset( $by['category'] ) ? $by['category']['object_id'] : null );

		// Post type default.
		update_option(
			'rank-math-options-titles',
			array(
				'pt_page_custom_robots' => 'on',
				'pt_page_robots'        => array( 'noindex' ),
			)
		);
		$this->assertTrue( $picker->is_noindex_post( get_post( $this->post( array( 'post_type' => 'page' ) ) ) ) );
		$this->assertFalse( $picker->is_noindex_post( get_post( $keep ) ) );
	}

	public function test_woocommerce_product(): void {
		register_post_type( 'product', array( 'public' => true ) );
		$product = $this->post(
			array(
				'post_type'  => 'product',
				'post_title' => 'Blue mug',
			)
		);
		$this->assertArrayNotHasKey( 'product', array_column( ( new UrlPicker( 'none' ) )->pick(), null, 'type' ) );

		add_filter( 'seoc_speed_woocommerce_active', '__return_true' );
		$by = array_column( ( new UrlPicker( 'none' ) )->pick(), null, 'type' );
		$this->assertSame( $product, $by['product']['object_id'] );
		$this->assertSame( 'Blue mug', $by['product']['label'] );
		unregister_post_type( 'product' );
	}

	public function test_only_home_on_empty_site(): void {
		$picked = ( new UrlPicker( 'none' ) )->pick();
		$this->assertSame( 'home', $picked[0]['type'] );
		foreach ( $picked as $entry ) {
			$this->assertStringStartsWith( home_url(), $entry['url'] );
		}
	}

	public function test_validate(): void {
		$input  = array(
			home_url( '/about/' ),
			'/contact/#form',
			'https://www.example.org/shop/',
			'https://evil.example.com/',
			'javascript:alert(1)',
			'notaurl',
			'',
			home_url( '/about/' ),
			42,
		);
		$result = ( new UrlPicker() )->validate( $input );
		$this->assertSame(
			array( home_url( '/about/' ), home_url( '/contact/' ), 'https://www.example.org/shop/' ),
			$result['valid']
		);
		$this->assertCount( 3, $result['errors'] );
		$this->assertSame( 'https://evil.example.com/', $result['errors'][0]['url'] );
		$this->assertStringContainsString( 'this website', $result['errors'][0]['message'] );
	}

	public function test_validate_max_ten(): void {
		$input = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$input[] = home_url( '/p' . $i . '/' );
		}
		$result = ( new UrlPicker() )->validate( $input );
		$this->assertCount( 10, $result['valid'] );
		$this->assertCount( 2, $result['errors'] );
	}
}
