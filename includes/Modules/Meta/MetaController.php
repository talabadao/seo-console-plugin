<?php
/**
 * REST routes of the SEO Meta module (seo-console/v1/meta/...). All require manage_options.
 *
 * AI work inside a web request is limited to one post at effort "low" (shared hosting has ~30 s per request);
 * more posts always go to the background job.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use SEOConsole\Plugin;
use SEOConsole\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class MetaController extends Controller {

	/** Maximum posts per POST meta/suggest. */
	const MAX_POSTS = 500;

	/**
	 * Module.
	 *
	 * @var MetaModule
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param Plugin     $plugin Plugin.
	 * @param MetaModule $module Module.
	 */
	public function __construct( Plugin $plugin, MetaModule $module ) {
		parent::__construct( $plugin );
		$this->module = $module;
	}

	/** {@inheritDoc} */
	public function register_routes(): void {
		$ns         = Plugin::REST_NAMESPACE;
		$permission = array( $this, 'can_manage' );

		register_rest_route(
			$ns,
			'/meta/suggest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => $permission,
				'args'                => array(
					'post_ids' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => self::MAX_POSTS,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'fields'   => array(
						'type'    => 'array',
						'default' => MetaSuggester::DEFAULT_FIELDS,
						'items'   => array(
							'type' => 'string',
							'enum' => MetaSuggester::FIELDS,
						),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/meta/alternatives',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'alternatives' ),
				'permission_callback' => $permission,
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'count'   => array(
						'type'    => 'integer',
						'default' => 3,
						'minimum' => 2,
						'maximum' => 5,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/meta/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			$ns,
			'/meta/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'posts' ),
				'permission_callback' => $permission,
				'args'                => $this->pagination_args() + array(
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-z0-9_-]{0,20}$',
					),
					'issue'     => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), PostsReport::ISSUES ),
					),
					'search'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * POST meta/suggest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function suggest( WP_REST_Request $request ) {
		$ids    = array_values( array_unique( array_map( 'absint', (array) $request['post_ids'] ) ) );
		$fields = JobStore::clean_fields( (array) $request['fields'] );
		$types  = PostsReport::post_types();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! isset( $types[ $post->post_type ] ) ) {
				/* translators: %d: post ID */
				return $this->respond( new WP_Error( 'seoc_not_found', sprintf( __( 'Post %d not found.', 'seo-console' ), $id ), array( 'status' => 404 ) ) );
			}
		}
		$unavailable = $this->unavailable_error();
		if ( $unavailable ) {
			return $this->respond( $unavailable );
		}

		if ( count( $ids ) > 1 ) {
			$items = array();
			foreach ( $ids as $id ) {
				$items[] = array(
					'object_type' => 'post',
					'object_id'   => $id,
					'fields'      => $fields,
				);
			}
			$queued = $this->module->enqueue( $items, 'rest' );
			return $this->respond(
				array(
					'mode'   => 'queued',
					'queued' => $queued,
					'status' => $this->status_data(),
				),
				202
			);
		}

		$result      = $this->module->suggester()->suggest_for_post( $ids[0], $fields, $this->module->current_values( $ids[0] ) );
		$suggestions = array();
		foreach ( $result['created'] as $created ) {
			$item = is_numeric( $created['id'] ) ? $this->plugin->suggestions()->get( (int) $created['id'] ) : null;
			if ( $item ) {
				$suggestions[] = $item;
			}
		}
		PostsReport::flush();
		return $this->respond(
			array(
				'mode'        => 'inline',
				'post_id'     => $ids[0],
				'status'      => $result['status'],
				'created'     => count( $result['created'] ),
				'suggestions' => $suggestions,
				'rejected'    => $result['rejected'],
				'skipped'     => $result['skipped'],
				'errors'      => $result['errors'],
				'message'     => self::message( $result ),
			)
		);
	}

	/**
	 * POST meta/alternatives.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function alternatives( WP_REST_Request $request ) {
		$id   = (int) $request['post_id'];
		$post = get_post( $id );
		if ( ! $post || ! isset( PostsReport::post_types()[ $post->post_type ] ) ) {
			return $this->respond( new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) ) );
		}
		$unavailable = $this->unavailable_error();
		if ( $unavailable ) {
			return $this->respond( $unavailable );
		}
		$options = $this->module->suggester()->alternatives( $id, $this->module->current_values( $id ), (int) $request['count'] );
		if ( is_wp_error( $options ) ) {
			$options->add_data( array( 'status' => 502 ) );
			return $this->respond( $options );
		}
		$suffix = $this->module->brand_suffix( $id );
		foreach ( $options as $i => $option ) {
			// The value to approve (with the SEO plugin's brand suffix) next to the text a person reads.
			$options[ $i ]['title_value'] = '' !== $option['title'] ? $option['title'] . $suffix : '';
		}
		return $this->respond(
			array(
				'post_id'      => $id,
				'brand_suffix' => $suffix,
				'options'      => $options,
			)
		);
	}

	/**
	 * GET meta/status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return $this->respond( $this->status_data() );
	}

	/**
	 * GET meta/posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function posts( WP_REST_Request $request ) {
		$data  = $this->module->report()->query(
			array(
				'post_type' => (string) $request['post_type'],
				'issue'     => (string) $request['issue'],
				'search'    => (string) $request['search'],
				'page'      => (int) $request['page'],
				'per_page'  => (int) $request['per_page'],
			)
		);
		$types = array();
		foreach ( PostsReport::post_types() as $name => $label ) {
			$types[] = array(
				'value' => $name,
				'label' => $label,
			);
		}
		$data['seo_plugin']    = $this->plugin->seo()->active();
		$data['post_types']    = $types;
		$data['ai_configured'] = $this->plugin->claude()->is_available();
		$data['ranges']        = array(
			'title'       => PostsReport::TITLE_RANGE,
			'description' => PostsReport::DESCRIPTION_RANGE,
		);
		$response              = $this->respond( $data );
		$response->header( 'X-WP-Total', (string) $data['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $data['total'] / max( 1, $data['per_page'] ) ) ) );
		return $response;
	}

	/**
	 * Queue status.
	 *
	 * @return array
	 */
	private function status_data(): array {
		$data                  = $this->module->store()->summary();
		$data['job_scheduled'] = $this->module->job_scheduled();
		$data['ai_configured'] = $this->plugin->claude()->is_available();
		return $data;
	}

	/**
	 * Why AI cannot run right now, or null.
	 *
	 * @return WP_Error|null
	 */
	private function unavailable_error(): ?WP_Error {
		if ( $this->plugin->claude()->is_available() ) {
			return null;
		}
		$code   = $this->plugin->secrets()->has( 'claude_api_key' ) ? 'seoc_ai_budget' : 'seoc_ai_no_key';
		$mapped = MetaSuggester::map_error( new WP_Error( $code, '' ) );
		return new WP_Error( $mapped['code'], $mapped['message'], array( 'status' => 'seoc_ai_budget' === $code ? 402 : 400 ) );
	}

	/**
	 * Summary sentence of an inline result.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	private static function message( array $result ): string {
		$created = count( $result['created'] );
		if ( $created ) {
			/* translators: %d: number of suggestions */
			$message = sprintf( _n( '%d suggestion is ready for review.', '%d suggestions are ready for review.', $created, 'seo-console' ), $created );
		} elseif ( 'nothing' === $result['status'] ) {
			$message = __( 'Nothing to propose: everything requested already looks fine.', 'seo-console' );
		} else {
			$message = __( 'No suggestion could be created.', 'seo-console' );
		}
		foreach ( array_merge( $result['errors'], $result['rejected'] ) as $entry ) {
			$message .= ' ' . $entry['message'];
		}
		return trim( $message );
	}
}
