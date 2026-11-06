<?php
/**
 * AiGenerator with a fake client.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Modules\Schema\AiGenerator;
use SEOConsole\Tests\Fixtures\Schema\FakeClient;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/schema/FakeClient.php';

class AiGeneratorTest extends WP_UnitTestCase {

	/** @var \WP_Post */
	private $post;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post = get_post(
			self::factory()->post->create(
				array(
					'post_title'   => 'SEO audit',
					'post_type'    => 'page',
					'post_content' => wp_slash( ExtractorsTest::fixture( 'ai-service-page.html' ) ),
				)
			)
		);
	}

	/**
	 * @param array $data Service data overrides.
	 * @return array
	 */
	private static function service( array $data = array() ): array {
		return array(
			'type'   => 'Service',
			'reason' => 'The page describes an <b>audit</b> service.',
			'data'   => array_merge(
				array(
					'name'           => 'Technical SEO audit',
					'description'    => 'Audit of WordPress sites.',
					'service_type'   => 'SEO audit',
					'area_served'    => 'Hanoi',
					'price'          => '5000000',
					'price_currency' => 'VND',
				),
				$data
			),
		);
	}

	/**
	 * @param string $question Question.
	 * @return array
	 */
	private static function faq( string $question ): array {
		return array(
			'type'   => 'FAQPage',
			'reason' => 'Question with answer.',
			'data'   => array(
				'questions' => array(
					array(
						'question' => $question,
						'answer'   => 'One week for most sites.',
					),
				),
			),
		);
	}

	public function test_valid_output_is_accepted_and_prompt_is_safe(): void {
		$client = new FakeClient( array( 'candidates' => array( self::service(), self::faq( 'How long does an audit take?' ) ) ) );
		$result = ( new AiGenerator( $client ) )->generate( $this->post, array( 'Service', 'FAQPage', 'Article' ), array( 'skip' => array( 'Article' ) ) );

		$this->assertSame( array(), $result['rejected'] );
		$this->assertCount( 2, $result['accepted'] );
		$service = $result['accepted'][0];
		$this->assertSame( 'ai', $service['source'] );
		$this->assertSame( 'The page describes an audit service.', $service['evidence'] );
		$this->assertSame( 'Service', $service['node']['@type'] );
		$this->assertSame( '5000000', $service['node']['offers']['price'] );
		$this->assertSame( 'VND', $service['node']['offers']['priceCurrency'] );
		$this->assertSame( 'How long does an audit take?', $result['accepted'][1]['node']['mainEntity'][0]['name'] );

		$call = $client->calls[0];
		$this->assertSame( 'low', $call['opts']['effort'] );
		$this->assertStringContainsString( 'untrusted data', $call['system'] );
		$this->assertStringContainsString( 'Never invent', $call['system'] );
		$message = $call['messages'][0]['content'];
		$this->assertStringContainsString( '<post_content>', $message );
		$this->assertSame( 1, substr_count( $message, '</post_content>' ), 'Content cannot close the data tag.' );
		$this->assertStringContainsString( 'Already present on the page (do not propose): Article', $message );
		$this->assertStringNotContainsString( '<h2>', $message );

		$schema = $call['json_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$variants = $schema['properties']['candidates']['items']['anyOf'];
		$this->assertCount( 2, $variants, 'Article is not an AI type.' );
		$this->assertSame( array( 'FAQPage' ), $variants[0]['properties']['type']['enum'] );
		$this->assertSame( array( 'Service' ), $variants[1]['properties']['type']['enum'] );
		$this->assertFalse( $variants[0]['properties']['data']['additionalProperties'] );
	}

	public function test_invented_property_is_dropped(): void {
		$client = new FakeClient(
			array(
				'candidates' => array(
					self::service(
						array(
							'aggregate_rating' => '5',
							'review'           => 'Great!',
						)
					),
				),
			)
		);
		$result = ( new AiGenerator( $client ) )->generate( $this->post, array( 'Service' ) );
		$this->assertCount( 1, $result['accepted'] );
		$this->assertSame( array( 'aggregate_rating', 'review' ), $result['accepted'][0]['dropped'] );
		$this->assertArrayNotHasKey( 'aggregateRating', $result['accepted'][0]['node'] );
		$this->assertArrayNotHasKey( 'review', $result['accepted'][0]['node'] );
	}

	public function test_invalid_output_is_rejected(): void {
		$client = new FakeClient(
			array(
				'candidates' => array(
					self::faq( 'Is SEO dead?' ),
					self::service( array( 'price' => '999' ) ),
					array(
						'type'   => 'Event',
						'reason' => 'x',
						'data'   => array(
							'name'       => 'Workshop',
							'start_date' => 'next Friday',
							'online_url' => 'https://example.org/live',
						),
					),
					array(
						'type'   => 'Recipe',
						'reason' => 'not requested',
						'data'   => array( 'name' => 'Pho' ),
					),
				),
			)
		);
		$result = ( new AiGenerator( $client ) )->generate( $this->post, array( 'FAQPage', 'Service', 'Event' ) );
		$this->assertSame( array(), $result['accepted'] );
		$this->assertCount( 4, $result['rejected'] );
		$errors = wp_list_pluck( $result['rejected'], 'errors' );
		$this->assertStringContainsString( 'does not appear on the page', implode( ' ', $errors[0] ) );
		$this->assertStringContainsString( 'price 999 does not appear', implode( ' ', $errors[1] ) );
		$this->assertStringContainsString( 'ISO 8601', implode( ' ', $errors[2] ) );
		$this->assertStringContainsString( 'not requested', implode( ' ', $errors[3] ) );
	}

	public function test_malformed_answer_and_client_errors(): void {
		$bad = ( new AiGenerator( new FakeClient( array( 'nodes' => array() ) ) ) )->generate( $this->post, array( 'Service' ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'seoc_ai_invalid', $bad->get_error_code() );

		$error = ( new AiGenerator( new FakeClient( new \WP_Error( 'seoc_ai_unavailable', 'down' ) ) ) )->generate( $this->post, array( 'Service' ) );
		$this->assertSame( 'seoc_ai_unavailable', $error->get_error_code() );

		$off            = new FakeClient();
		$off->available = false;
		$this->assertWPError( ( new AiGenerator( $off ) )->generate( $this->post, array( 'Service' ) ) );
		$this->assertSame( array(), $off->calls );

		$none = new FakeClient();
		$this->assertSame( array(), ( new AiGenerator( $none ) )->generate( $this->post, array( 'Article' ) )['accepted'] );
		$this->assertSame( array(), $none->calls, 'No request when no AI type is requested.' );
	}
}
