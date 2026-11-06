<?php
/**
 * Glue between the Schema engine and core: post listing, per-post detail and preview, creating
 * suggestions in the approval queue, the background batch, the business preview and removal.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Core\ObjectInfo;
use SEOConsole\Jobs\TimeBudget;
use SEOConsole\Modules\Schema\Extractors\BusinessExtractor;
use SEOConsole\Plugin;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class SchemaService {

	/** Hidden post meta: candidates that need the user's input (JSON list). Internal, not output. */
	const INCOMPLETE_META = '_seoc_schema_incomplete';

	/** Background job hook. */
	const BATCH_HOOK = 'seoc_schema_suggest_batch';

	/** Progress of the background batch (option, not autoloaded). */
	const BATCH_OPTION = 'seoc_schema_batch';

	/** Max posts handled inline by the rule path. */
	const INLINE_RULES = 10;

	/** Max posts handled inline by the AI path. */
	const INLINE_AI = 1;

	/** Post statuses listed. */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Active SEO plugin. */
	public function seo_plugin(): string {
		return $this->plugin->seo()->active();
	}

	/**
	 * Module settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return array_merge( SettingsFields::defaults(), $this->plugin->settings()->all( 'schema' ) );
	}

	/** Detector for the active SEO plugin. */
	public function detector(): Detector {
		return new Detector( $this->seo_plugin() );
	}

	/** Whether AI suggestions are switched on and the client can be used now. */
	public function ai_available(): bool {
		$settings = $this->settings();
		return ! empty( $settings['ai_suggestions'] ) && $this->plugin->claude()->is_available();
	}

	/**
	 * Suggestion builder; with AI only when allowed and available.
	 *
	 * @param bool $use_ai Use AI.
	 */
	public function builder( bool $use_ai ): SuggestionBuilder {
		$ai = $use_ai && $this->ai_available() ? new AiGenerator( $this->plugin->claude() ) : null;
		return new SuggestionBuilder(
			$this->detector(),
			null,
			SuggestionBuilder::default_extractors( $this->settings(), $this->seo_plugin() ),
			$ai
		);
	}

	/**
	 * Public post types that can carry schema.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Paged posts.
	 *
	 * @param array $args post_type, status ('has_ours'|'none'|'needs_input'|''), search, page, per_page.
	 * @return array{items: WP_Post[], total: int}
	 */
	public function query_posts( array $args ): array {
		$types = self::post_types();
		$type  = isset( $args['post_type'] ) ? (string) $args['post_type'] : '';
		$query = array(
			'post_type'              => '' !== $type && in_array( $type, $types, true ) ? $type : $types,
			'post_status'            => self::STATUSES,
			'posts_per_page'         => isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20,
			'paged'                  => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( 'has_ours' === $status ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filter on our own hidden meta key.
				array(
					'key'     => Store::META_KEY,
					'compare' => 'EXISTS',
				),
			);
		} elseif ( 'none' === $status ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filter on our own hidden meta key.
				array(
					'key'     => Store::META_KEY,
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'needs_input' === $status ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filter on our own hidden meta key.
				array(
					'key'     => self::INCOMPLETE_META,
					'compare' => 'EXISTS',
				),
			);
		}
		$wp_query = new \WP_Query( $query );
		$items    = array();
		foreach ( $wp_query->posts as $item ) {
			if ( $item instanceof WP_Post ) {
				$items[] = $item;
			}
		}
		return array(
			'items' => $items,
			'total' => (int) $wp_query->found_posts,
		);
	}

	/**
	 * List row for a post.
	 *
	 * @param WP_Post    $post  Post.
	 * @param array|null $found Detector findings (computed when null).
	 * @return array<string, mixed>
	 */
	public function row( WP_Post $post, ?array $found = null ): array {
		$found   = null === $found ? $this->detector()->detect( $post->ID ) : $found;
		$type    = get_post_type_object( $post->post_type );
		$pending = $this->pending_suggestion( $post->ID );
		$info    = ObjectInfo::describe( 'post', $post->ID );
		return array(
			'id'                 => (int) $post->ID,
			'title'              => $info['object_title'],
			'post_type'          => $post->post_type,
			'post_type_label'    => $type ? (string) $type->labels->singular_name : $post->post_type,
			'status'             => $post->post_status,
			'edit_link'          => $info['edit_link'],
			'view_link'          => $info['view_link'],
			'modified'           => ObjectInfo::iso_date( $post->post_modified_gmt ),
			'detected'           => self::findings_for_rest( $found ),
			'ours'               => Store::types( Store::get( $post->ID ) ),
			'needs_input'        => $this->incomplete( $post->ID ),
			'pending_suggestion' => null === $pending ? null : array(
				'id'         => (int) $pending['id'],
				'source'     => (string) $pending['source'],
				'types'      => Store::types( is_array( $pending['proposed_value'] ) ? $pending['proposed_value'] : array() ),
				'created_at' => ObjectInfo::iso_date( $pending['created_at'] ),
			),
		);
	}

	/**
	 * Detail for one post: row, stored nodes and their validation, the JSON-LD our output would add,
	 * candidates that need input, the pending suggestion.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function detail( WP_Post $post ): array {
		$found   = $this->detector()->detect( $post->ID );
		$stored  = Store::raw( $post->ID );
		$pending = $this->pending_suggestion( $post->ID );
		return array(
			'post'               => $this->row( $post, $found ),
			'stored'             => $stored,
			'validation'         => null === $stored ? null : ( new Validator() )->validate_graph( $stored, array( 'content' => (string) $post->post_content ) ),
			'preview'            => $this->preview( $post, $found ),
			'incomplete'         => $this->incomplete( $post->ID ),
			'pending_suggestion' => null === $pending ? null : $this->plugin->suggestions()->to_rest( $pending ),
			'seo_plugin'         => $this->seo_plugin(),
		);
	}

	/**
	 * What the frontend output would add for this post (same composition and de-duplication as Output).
	 *
	 * @param WP_Post $post  Post.
	 * @param array   $found Detector findings.
	 * @return array{mode: string, pieces: array[], hidden: array[], json: string}
	 */
	public function preview( WP_Post $post, array $found ): array {
		$seo      = $this->seo_plugin();
		$url      = (string) get_permalink( $post );
		$nodes    = array_values(
			array_filter(
				Store::get( $post->ID ),
				static function ( $node ) {
					$type = isset( $node['@type'] ) ? Types::primary( $node['@type'] ) : null;
					return null !== $type && Types::get( $type )['top_level'];
				}
			)
		);
		$pieces   = ( new Composer() )->compose(
			$nodes,
			array(
				'url'             => $url,
				'webpage_id'      => Composer::webpage_id( $seo, $url ),
				'organization_id' => 'none' === $seo ? null : Composer::organization_id( $seo ),
				'date_modified'   => (string) get_post_modified_time( DATE_W3C, true, $post ),
			)
		);
		$existing = array();
		foreach ( $found as $finding ) {
			if ( Detector::SOURCE_SEOC !== $finding['source'] ) {
				$existing[] = array( '@type' => $finding['type'] );
			}
		}
		$kept   = Output::merge( $existing, $pieces );
		$ids    = wp_list_pluck( $kept, '@id' );
		$hidden = array();
		foreach ( $pieces as $piece ) {
			if ( in_array( $piece['@id'], $ids, true ) ) {
				continue;
			}
			$type     = (string) Types::primary( $piece['@type'] );
			$by       = Detector::find( $found, $type );
			$hidden[] = array(
				'type'   => $type,
				'label'  => Types::label( $type ),
				'reason' => null !== $by
					/* translators: %s: where the schema comes from, e.g. "Rank Math". */
					? sprintf( __( 'Not added: already output by %s.', 'seo-console' ), Detector::source_label( $by['source'] ) )
					: __( 'Not added: the page already has this type.', 'seo-console' ),
			);
		}
		return array(
			'mode'   => $seo,
			'pieces' => $kept,
			'hidden' => $hidden,
			'json'   => self::pretty( 'none' === $seo ? self::wrap( $kept ) : $kept ),
		);
	}

	/**
	 * Runs the builder for a post, files the suggestion in the approval queue and remembers the
	 * candidates that need input.
	 *
	 * @param WP_Post $post   Post.
	 * @param bool    $use_ai Use AI (when allowed and available).
	 * @return array{post_id: int, status: string, suggestion_id: int|null, types: string[], skipped: array[], incomplete: array[], rejected: array[], error: array|null}
	 */
	public function suggest( WP_Post $post, bool $use_ai ): array {
		$built      = $this->builder( $use_ai )->for_post( $post, array( 'use_ai' => $use_ai ) );
		$incomplete = $this->save_incomplete( $post->ID, $built['incomplete'] );
		$out        = array(
			'post_id'       => (int) $post->ID,
			'status'        => 'nothing_new',
			'suggestion_id' => null,
			'types'         => array_values( array_unique( wp_list_pluck( $built['added'], 'type' ) ) ),
			'skipped'       => $built['skipped'],
			'incomplete'    => $incomplete,
			'rejected'      => self::rejected_for_rest( $built['rejected'] ),
			'error'         => $built['ai_error'] instanceof WP_Error ? array(
				'code'    => $built['ai_error']->get_error_code(),
				'message' => $built['ai_error']->get_error_message(),
			) : null,
		);
		if ( null !== $built['suggestion'] ) {
			$warnings = array();
			foreach ( $built['added'] as $candidate ) {
				$warnings = array_merge( $warnings, isset( $candidate['warnings'] ) ? (array) $candidate['warnings'] : array() );
			}
			$data         = $built['suggestion'];
			$data['meta'] = array(
				'types'    => $out['types'],
				'warnings' => array_values( array_unique( $warnings ) ),
				'skipped'  => $built['skipped'],
			);
			if ( 'ai' === $data['source'] && method_exists( $this->plugin->claude(), 'model' ) ) {
				$data['model'] = (string) call_user_func( array( $this->plugin->claude(), 'model' ) );
			}
			$id = $this->plugin->suggestions()->create( $data );
			if ( is_wp_error( $id ) ) {
				$out['status'] = 'error';
				$out['error']  = array(
					'code'    => $id->get_error_code(),
					'message' => $id->get_error_message(),
				);
				return $out;
			}
			$out['status']        = 'suggested';
			$out['suggestion_id'] = (int) $id;
		} elseif ( array() !== $incomplete ) {
			$out['status'] = 'needs_input';
		}
		return $out;
	}

	/**
	 * Suggests for several posts: inline when small, otherwise in the background.
	 *
	 * @param int[] $post_ids Post ids.
	 * @param bool  $use_ai   Use AI.
	 * @return array|WP_Error { queued: bool, count: int, results?: array[] }
	 */
	public function suggest_many( array $post_ids, bool $use_ai ) {
		$posts = array();
		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $id ) {
			$post = get_post( $id );
			if ( $post && in_array( $post->post_type, self::post_types(), true ) ) {
				$posts[] = $post;
			}
		}
		if ( array() === $posts ) {
			return new WP_Error( 'seoc_not_found', __( 'None of these posts can have structured data.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( $use_ai && ! $this->ai_available() ) {
			return new WP_Error(
				'seoc_ai_unavailable',
				__( 'AI suggestions are off, no Claude API key is set, or this month\'s AI budget is used up. Try without AI, or check Settings.', 'seo-console' ),
				array( 'status' => 409 )
			);
		}
		$limit = $use_ai ? self::INLINE_AI : self::INLINE_RULES;
		if ( count( $posts ) > $limit ) {
			$ids = wp_list_pluck( $posts, 'ID' );
			$this->enqueue_batch( array_map( 'intval', $ids ), $use_ai );
			return array(
				'queued' => true,
				'count'  => count( $ids ),
			);
		}
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = $this->suggest( $post, $use_ai );
		}
		return array(
			'queued'  => false,
			'count'   => count( $results ),
			'results' => $results,
		);
	}

	/**
	 * Queues a background batch.
	 *
	 * @param int[] $post_ids Post ids.
	 * @param bool  $use_ai   Use AI.
	 */
	public function enqueue_batch( array $post_ids, bool $use_ai ): int {
		update_option(
			self::BATCH_OPTION,
			array(
				'total'      => count( $post_ids ),
				'done'       => 0,
				'use_ai'     => $use_ai,
				'started_at' => gmdate( 'c' ),
			),
			false
		);
		return $this->plugin->queue()->enqueue( self::BATCH_HOOK, array( array_values( $post_ids ), $use_ai ) );
	}

	/**
	 * Background job: handles posts within the time budget, then re-queues the rest.
	 *
	 * @param mixed $post_ids Post ids.
	 * @param mixed $use_ai   Use AI.
	 */
	public function run_batch( $post_ids, $use_ai = false ): void {
		$ids    = array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );
		$use_ai = (bool) $use_ai && $this->ai_available();
		$budget = new TimeBudget();
		$done   = 0;
		while ( array() !== $ids ) {
			if ( $done > 0 && ! $budget->has_time( $use_ai ? 15.0 : 2.0 ) ) {
				break;
			}
			$post = get_post( array_shift( $ids ) );
			if ( $post ) {
				$this->suggest( $post, $use_ai );
			}
			++$done;
		}
		$status         = get_option( self::BATCH_OPTION, array() );
		$status         = is_array( $status ) ? $status : array();
		$status['done'] = ( isset( $status['done'] ) ? (int) $status['done'] : 0 ) + $done;
		if ( array() === $ids ) {
			$status['finished_at'] = gmdate( 'c' );
		}
		update_option( self::BATCH_OPTION, $status, false );
		if ( array() !== $ids ) {
			$this->plugin->queue()->enqueue( self::BATCH_HOOK, array( $ids, $use_ai ) );
		}
	}

	/**
	 * Progress of the last background batch, or null.
	 *
	 * @return array|null
	 */
	public function batch_status(): ?array {
		$status = get_option( self::BATCH_OPTION, null );
		return is_array( $status ) ? $status : null;
	}

	/**
	 * Removes a post's structured data through ChangeManager (logged, undoable).
	 *
	 * @param int $post_id Post id.
	 * @return \SEOConsole\Changes\Change|WP_Error
	 */
	public function remove( int $post_id ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( null === Store::raw( $post_id ) ) {
			return new WP_Error( 'seoc_nothing_to_remove', __( 'This post has no structured data from SEO Console.', 'seo-console' ), array( 'status' => 409 ) );
		}
		return $this->plugin->changes()->apply(
			new ChangeRequest(
				SchemaApplier::TYPE,
				'post',
				$post_id,
				SchemaApplier::FIELD,
				null,
				array(
					'source' => 'user',
					'module' => 'schema',
					'note'   => __( 'Structured data removed from the Schema page.', 'seo-console' ),
				)
			)
		);
	}

	/**
	 * Business / organization node from settings, its validation and whether it will be output.
	 *
	 * @param array|null $settings Settings to preview (defaults to the saved ones).
	 * @return array<string, mixed>
	 */
	public function site( ?array $settings = null ): array {
		$settings   = null === $settings ? $this->settings() : array_merge( $this->settings(), $settings );
		$seo        = $this->seo_plugin();
		$built      = ( new BusinessExtractor() )->build( $settings );
		$node       = $built['node'];
		$validation = null === $node ? null : ( new Validator() )->validate_node( $node );
		$output     = false;
		$reason     = __( 'Choose a business type and enter a name to add your business details.', 'seo-console' );
		$pieces     = array();
		if ( null !== $node ) {
			$type = (string) Types::primary( $node['@type'] );
			$out  = $this->site_output( $type, $seo );
			if ( ! $validation['valid'] ) {
				$reason = __( 'Fix the problems below; until then nothing is added to your site.', 'seo-console' );
			} elseif ( ! $out['will'] ) {
				$reason = $out['reason'];
			} else {
				$output   = true;
				$reason   = $out['reason'];
				$parent   = 'none' === $seo ? '' : Composer::organization_id( $seo );
				$pieces[] = ( new Composer() )->compose_site( $node, array( 'parent_organization_id' => $parent ) );
			}
		}
		return array(
			'type'        => isset( $settings['business_type'] ) ? (string) $settings['business_type'] : 'none',
			'node'        => $node,
			'errors'      => array_values( array_merge( $built['errors'], null === $validation ? array() : $validation['errors'] ) ),
			'warnings'    => null === $validation ? array() : $validation['warnings'],
			'will_output' => $output,
			'reason'      => $reason,
			'seo_plugin'  => $seo,
			'json'        => array() === $pieces ? '' : self::pretty( 'none' === $seo ? self::wrap( $pieces ) : $pieces ),
			'note'        => null !== $node ? Types::note( (string) Types::primary( $node['@type'] ) ) : Types::note( 'LocalBusiness' ),
		);
	}

	/**
	 * Whether the site node will be output next to the SEO plugin's own organization.
	 *
	 * @param string $type Business type.
	 * @param string $seo  SEO plugin.
	 * @return array{will: bool, reason: string}
	 */
	private function site_output( string $type, string $seo ): array {
		if ( 'none' === $seo ) {
			return array(
				'will'   => true,
				'reason' => __( 'Added to your home page.', 'seo-console' ),
			);
		}
		$name = 'rankmath' === $seo ? 'Rank Math' : 'Yoast SEO';
		if ( ! Types::is_a( $type, 'LocalBusiness' ) ) {
			return array(
				'will'   => false,
				/* translators: %s: SEO plugin name. */
				'reason' => sprintf( __( '%s already describes your organization on every page, so SEO Console does not add a second one. Choose a local business type to add your address and opening hours.', 'seo-console' ), $name ),
			);
		}
		$modules  = get_option( 'rank_math_modules', array() );
		$rm_local = 'rankmath' === $seo && is_array( $modules ) && in_array( 'local-seo', $modules, true );
		if ( $rm_local || ( 'yoast' === $seo && defined( 'WPSEO_LOCAL_VERSION' ) ) ) {
			return array(
				'will'   => false,
				/* translators: %s: SEO plugin name. */
				'reason' => sprintf( __( '%s Local SEO already outputs your local business, so SEO Console does not add a second one.', 'seo-console' ), $name ),
			);
		}
		return array(
			'will'   => true,
			/* translators: %s: SEO plugin name. */
			'reason' => sprintf( __( 'Added to your home page inside the %s schema, linked to its organization.', 'seo-console' ), $name ),
		);
	}

	/**
	 * Counts for the dashboard card.
	 *
	 * @return array{with_schema: int, needs_input: int}
	 */
	public function counts(): array {
		$count = static function ( string $key ): int {
			$query = new \WP_Query(
				array(
					'post_type'              => self::post_types(),
					'post_status'            => self::STATUSES,
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- our own hidden meta key.
						array(
							'key'     => $key,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			return (int) $query->found_posts;
		};
		return array(
			'with_schema' => $count( Store::META_KEY ),
			'needs_input' => $count( self::INCOMPLETE_META ),
		);
	}

	/**
	 * Candidates that need the user's input for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array[]
	 */
	public function incomplete( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::INCOMPLETE_META, true );
		$raw = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
	}

	/**
	 * Stores (or clears) the candidates that need input.
	 *
	 * @param int     $post_id    Post id.
	 * @param array[] $candidates Incomplete candidates from SuggestionBuilder.
	 * @return array[] Stored entries.
	 */
	private function save_incomplete( int $post_id, array $candidates ): array {
		$entries = array();
		foreach ( $candidates as $candidate ) {
			$entries[] = array(
				'type'     => (string) $candidate['type'],
				'label'    => Types::label( (string) $candidate['type'] ),
				'missing'  => array_values( array_map( array( Types::class, 'property_label' ), isset( $candidate['missing'] ) ? (array) $candidate['missing'] : array() ) ),
				'errors'   => isset( $candidate['errors'] ) ? array_values( (array) $candidate['errors'] ) : array(),
				'evidence' => isset( $candidate['evidence'] ) ? (string) $candidate['evidence'] : '',
				'node'     => $candidate['node'],
				'found_at' => gmdate( 'c' ),
			);
		}
		if ( array() === $entries ) {
			delete_post_meta( $post_id, self::INCOMPLETE_META );
		} else {
			update_post_meta( $post_id, self::INCOMPLETE_META, wp_slash( (string) wp_json_encode( $entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		}
		return $entries;
	}

	/**
	 * Pending schema suggestion row for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	private function pending_suggestion( int $post_id ): ?array {
		return $this->plugin->suggestions()->store()->find_pending( SchemaApplier::TYPE, 'post', $post_id, SchemaApplier::FIELD );
	}

	/**
	 * Findings with readable source labels.
	 *
	 * @param array[] $found Findings.
	 * @return array[]
	 */
	public static function findings_for_rest( array $found ): array {
		$out = array();
		foreach ( $found as $finding ) {
			$out[] = array(
				'type'         => (string) $finding['type'],
				'label'        => Types::label( (string) $finding['type'] ),
				'source'       => (string) $finding['source'],
				'source_label' => Detector::source_label( (string) $finding['source'] ),
				'detail'       => (string) $finding['detail'],
			);
		}
		return $out;
	}

	/**
	 * @param array[] $rejected AI rejected candidates.
	 * @return array[]
	 */
	private static function rejected_for_rest( array $rejected ): array {
		$out = array();
		foreach ( $rejected as $item ) {
			$out[] = array(
				'type'   => (string) $item['type'],
				'errors' => array_values( (array) $item['errors'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array[] $pieces Pieces.
	 * @return array
	 */
	private static function wrap( array $pieces ): array {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $pieces,
		);
	}

	/**
	 * Pretty JSON for previews.
	 *
	 * @param array $data Data.
	 */
	private static function pretty( array $data ): string {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}
}
