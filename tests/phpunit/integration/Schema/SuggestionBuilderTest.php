<?php
/**
 * SuggestionBuilder: suggestion shape, skipping existing types, rules + AI.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Modules\Schema\AiGenerator;
use SEOConsole\Modules\Schema\Detector;
use SEOConsole\Modules\Schema\Store;
use SEOConsole\Modules\Schema\SuggestionBuilder;
use SEOConsole\Tests\Fixtures\Schema\FakeClient;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/schema/FakeClient.php';

class SuggestionBuilderTest extends WP_UnitTestCase {

	/**
	 * @param string $content Content.
	 * @param array  $args    Post args.
	 */
	private function post( string $content, array $args = array() ): \WP_Post {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		return get_post( self::factory()->post->create( array_merge( array( 'post_content' => wp_slash( $content ) ), $args ) ) );
	}

	public function test_rank_math_faq_and_incomplete_video(): void {
		$post   = $this->post( ExtractorsTest::fixture( 'yoast-faq.html' ) . ExtractorsTest::fixture( 'youtube-embed.html' ) );
		$result = ( new SuggestionBuilder( new Detector( 'rankmath' ) ) )->for_post( $post );

		$suggestion = $result['suggestion'];
		$this->assertSame( 'schema', $suggestion['module'] );
		$this->assertSame( 'schema', $suggestion['type'] );
		$this->assertSame( 'post', $suggestion['object_type'] );
		$this->assertSame( $post->ID, $suggestion['object_id'] );
		$this->assertSame( 'schema', $suggestion['field'] );
		$this->assertNull( $suggestion['current_value'] );
		$this->assertSame( 'rule', $suggestion['source'] );
		$this->assertSame( array( 'FAQPage' ), wp_list_pluck( $suggestion['proposed_value'], '@type' ) );
		$this->assertStringContainsString( 'Yoast FAQ block', $suggestion['rationale'] );
		$this->assertStringContainsString( 'government and health', $suggestion['rationale'] );

		$this->assertCount( 2, $result['incomplete'], 'Videos without an upload date need user input.' );
		$this->assertContains( 'uploadDate', $result['incomplete'][0]['missing'] );
		$this->assertNotEmpty( $result['incomplete'][0]['errors'] );
	}

	public function test_type_already_output_by_seo_plugin_is_skipped(): void {
		$post   = $this->post( ExtractorsTest::fixture( 'rankmath-faq.html' ) );
		$result = ( new SuggestionBuilder( new Detector( 'rankmath' ) ) )->for_post( $post );
		$this->assertNull( $result['suggestion'] );
		$this->assertSame( 'FAQPage', $result['skipped'][0]['type'] );
		$this->assertStringContainsString( 'Rank Math', $result['skipped'][0]['reason'] );
	}

	public function test_no_seo_plugin_adds_basics_and_keeps_approved_nodes(): void {
		$post     = $this->post( ExtractorsTest::fixture( 'gutenberg-details.html' ), array( 'post_title' => 'Coffee' ) );
		$approved = array(
			array(
				'@type' => 'HowTo',
				'name'  => 'How to brew',
				'step'  => array(
					array(
						'@type' => 'HowToStep',
						'text'  => 'Pour.',
					),
				),
			),
		);
		Store::set( $post->ID, $approved );

		$result     = ( new SuggestionBuilder( new Detector( 'none' ) ) )->for_post( $post );
		$suggestion = $result['suggestion'];
		$this->assertSame( $approved, $suggestion['current_value'] );
		$this->assertSame( array( 'HowTo', 'BlogPosting', 'BreadcrumbList', 'FAQPage' ), wp_list_pluck( $suggestion['proposed_value'], '@type' ) );
		$this->assertSame( $approved[0], $suggestion['proposed_value'][0] );

		// Once approved, nothing new is proposed.
		Store::set( $post->ID, $suggestion['proposed_value'] );
		$again = ( new SuggestionBuilder( new Detector( 'none' ) ) )->for_post( $post );
		$this->assertNull( $again['suggestion'] );
		$this->assertStringContainsString( 'already approved', $again['skipped'][0]['reason'] );
	}

	public function test_ai_candidates_are_merged_and_marked(): void {
		$client  = new FakeClient(
			array(
				'candidates' => array(
					array(
						'type'   => 'Service',
						'reason' => 'The page sells an audit.',
						'data'   => array(
							'name'           => 'Technical SEO audit',
							'description'    => null,
							'service_type'   => null,
							'area_served'    => 'Hanoi',
							'price'          => null,
							'price_currency' => null,
						),
					),
				),
			)
		);
		$post    = $this->post( ExtractorsTest::fixture( 'ai-service-page.html' ), array( 'post_type' => 'page' ) );
		$builder = new SuggestionBuilder( new Detector( 'yoast' ), null, null, new AiGenerator( $client ) );
		$result  = $builder->for_post( $post, array( 'ai_types' => array( 'Service', 'FAQPage' ) ) );

		$this->assertSame( 'ai', $result['suggestion']['source'] );
		$this->assertSame( array( 'Service' ), wp_list_pluck( $result['suggestion']['proposed_value'], '@type' ) );
		$this->assertStringContainsString( 'Suggested by AI', $result['suggestion']['rationale'] );
		$this->assertStringContainsString( 'Already present on the page (do not propose):', $client->calls[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'BreadcrumbList', $client->calls[0]['messages'][0]['content'] );

		$failing = new SuggestionBuilder( new Detector( 'yoast' ), null, array(), new AiGenerator( new FakeClient( new \WP_Error( 'seoc_ai_budget', 'Budget' ) ) ) );
		$result  = $failing->for_post( $post );
		$this->assertNull( $result['suggestion'] );
		$this->assertSame( 'seoc_ai_budget', $result['ai_error']->get_error_code() );

		$this->assertNull( $builder->for_post( $post, array( 'use_ai' => false ) )['suggestion'] );
	}
}
