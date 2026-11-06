<?php
/**
 * Asks Claude for SEO titles, meta descriptions and image alt texts, validates the answers and turns them into
 * suggestions for the approval queue (docs/ARCHITECTURE.md §7.3). Nothing is ever written to the site here.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use SEOConsole\AI\ClientInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class MetaSuggester {

	/** Fields a caller can ask for. */
	const FIELDS = array( 'title', 'description', 'image_alt' );

	/** Default fields when an item does not name any. */
	const DEFAULT_FIELDS = array( 'title', 'description' );

	/**
	 * Claude client.
	 *
	 * @var ClientInterface
	 */
	private $client;

	/**
	 * Receives each suggestion: function ( array $suggestion, array $meta ): int|WP_Error. $meta = [ 'warnings' => [...] ].
	 *
	 * @var callable
	 */
	private $sink;

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Context builder.
	 *
	 * @var PostContext
	 */
	private $context;

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface  $client    Claude client.
	 * @param callable         $sink      Receives each suggestion array (Phase B: SuggestionService::create).
	 * @param array            $options   model (string, recorded on suggestions), brand_appended (bool), brand (string),
	 *                                    current_provider (callable( int $post_id ): array — current SEO values for batch
	 *                                    items), image_scope ('missing'|'all'), max_images (int), max_attempts (int),
	 *                                    rationale_language (string), clock (callable(): float, for tests).
	 * @param PostContext|null $context   Context builder.
	 * @param Validator|null   $validator Validator.
	 */
	public function __construct( ClientInterface $client, callable $sink, array $options = array(), ?PostContext $context = null, ?Validator $validator = null ) {
		$this->client    = $client;
		$this->sink      = $sink;
		$this->options   = wp_parse_args(
			$options,
			array(
				'model'              => '',
				'brand_appended'     => false,
				'brand'              => '',
				'current_provider'   => null,
				'image_scope'        => 'missing',
				'max_images'         => 10,
				'max_attempts'       => 3,
				'rationale_language' => '',
				'clock'              => null,
			)
		);
		$this->context   = $context ? $context : new PostContext();
		$this->validator = $validator ? $validator : new Validator();
	}

	/**
	 * Proposes new values for one post and hands each valid proposal to the sink.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $fields  Any of 'title', 'description', 'image_alt'.
	 * @param array    $current Current values as stored by the SEO plugin: title, description, focus_keyword,
	 *                          optional resolved_title, brand_appended (bool), brand. These become current_value.
	 * @return array{post_id: int, status: string, created: array, rejected: array, errors: array, skipped: array, stop: bool, retryable: bool}
	 *         status: 'ok' | 'partial' (some created, some failed) | 'failed' | 'nothing' (nothing to propose).
	 *         stop: the caller should stop the whole batch (budget, auth, AI unreachable).
	 */
	public function suggest_for_post( int $post_id, array $fields, array $current = array() ): array {
		$result = array(
			'post_id'   => $post_id,
			'status'    => 'nothing',
			'created'   => array(),
			'rejected'  => array(),
			'errors'    => array(),
			'skipped'   => array(),
			'stop'      => false,
			'retryable' => false,
		);

		$fields = array_values( array_intersect( self::FIELDS, $fields ) );
		if ( ! $fields ) {
			return $this->fail( $result, new WP_Error( 'seoc_meta_no_fields', __( 'No supported field was requested.', 'seo-console' ) ) );
		}
		if ( ! $this->client->is_available() ) {
			return $this->fail( $result, new WP_Error( 'seoc_ai_not_available', '' ) );
		}

		$seo     = array(
			'focus_keyword'  => isset( $current['focus_keyword'] ) ? (string) $current['focus_keyword'] : '',
			'title'          => isset( $current['title'] ) ? (string) $current['title'] : '',
			'resolved_title' => isset( $current['resolved_title'] ) ? (string) $current['resolved_title'] : '',
			'description'    => isset( $current['description'] ) ? (string) $current['description'] : '',
			'brand_appended' => array_key_exists( 'brand_appended', $current ) ? (bool) $current['brand_appended'] : (bool) $this->options['brand_appended'],
			'brand'          => isset( $current['brand'] ) ? (string) $current['brand'] : (string) $this->options['brand'],
		);
		$context = $this->context->build( $post_id, $seo );
		if ( is_wp_error( $context ) ) {
			return $this->fail( $result, $context );
		}

		$meta_fields = array_values( array_intersect( array( 'title', 'description' ), $fields ) );
		if ( $meta_fields ) {
			$result = $this->suggest_meta( $result, $context, $meta_fields, $current );
			if ( $result['stop'] ) {
				return $this->finish( $result );
			}
		}
		if ( in_array( 'image_alt', $fields, true ) ) {
			$result = $this->suggest_images( $result, $context );
		}
		return $this->finish( $result );
	}

	/**
	 * Processes items until the time budget is used, then returns what is left (for a background job that
	 * re-enqueues itself). At least one item is processed per call. A new item is not started when the slowest
	 * item so far would not fit in the remaining time.
	 *
	 * @param array $items               Items as in the seoc_request_ai_suggestions hook: [ 'object_type' => 'post',
	 *                                   'object_id' => int, 'fields' => string[], optional 'current' => array,
	 *                                   'attempts' => int ].
	 * @param float $time_budget_seconds Time budget.
	 * @return array{results: array, remaining: array, stopped: array|null} stopped: [ code, message ] when the batch
	 *         stopped early because of the AI (budget, auth, unreachable); remaining then holds every unfinished item.
	 */
	public function suggest_batch( array $items, float $time_budget_seconds ): array {
		$items   = array_values( $items );
		$out     = array(
			'results'   => array(),
			'remaining' => array(),
			'stopped'   => null,
		);
		$start   = $this->now();
		$slowest = 0.0;

		if ( $items && ! $this->client->is_available() ) {
			$error            = self::map_error( new WP_Error( 'seoc_ai_not_available', '' ) );
			$out['stopped']   = array(
				'code'    => $error['code'],
				'message' => $error['message'],
			);
			$out['remaining'] = $items;
			return $out;
		}

		$count = count( $items );
		for ( $i = 0; $i < $count; $i++ ) {
			$elapsed = $this->now() - $start;
			if ( $i > 0 && ( $elapsed >= $time_budget_seconds || $elapsed + $slowest > $time_budget_seconds ) ) {
				$out['remaining'] = array_merge( $out['remaining'], array_slice( $items, $i ) );
				break;
			}

			$item = $items[ $i ];
			$type = isset( $item['object_type'] ) ? (string) $item['object_type'] : 'post';
			$id   = isset( $item['object_id'] ) ? absint( $item['object_id'] ) : 0;
			if ( 'post' !== $type || ! $id ) {
				$out['results'][] = array(
					'post_id' => $id,
					'status'  => 'skipped',
					'errors'  => array(
						array(
							'code'      => 'seoc_meta_unsupported_item',
							'message'   => __( 'Only posts, pages and products are supported.', 'seo-console' ),
							'retryable' => false,
						),
					),
				);
				continue;
			}
			$fields = ! empty( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : self::DEFAULT_FIELDS;
			if ( isset( $item['current'] ) && is_array( $item['current'] ) ) {
				$current = $item['current'];
			} elseif ( is_callable( $this->options['current_provider'] ) ) {
				$current = (array) call_user_func( $this->options['current_provider'], $id );
			} else {
				$current = array();
			}

			$t0               = $this->now();
			$result           = $this->suggest_for_post( $id, $fields, $current );
			$slowest          = max( $slowest, $this->now() - $t0 );
			$out['results'][] = $result;

			if ( $result['stop'] || $result['retryable'] ) {
				$item['attempts'] = ( isset( $item['attempts'] ) ? (int) $item['attempts'] : 0 ) + 1;
				$retry            = $item['attempts'] < (int) $this->options['max_attempts'];
				if ( $result['stop'] ) {
					$last             = end( $result['errors'] );
					$out['stopped']   = array(
						'code'    => $last ? $last['code'] : 'seoc_ai_unavailable',
						'message' => $last ? $last['message'] : '',
					);
					$out['remaining'] = array_merge( $out['remaining'], $retry ? array( $item ) : array(), array_slice( $items, $i + 1 ) );
					break;
				}
				if ( $retry ) {
					$items[] = $item; // Retry at the end of this batch, or in the next one.
					++$count;
				}
			}
		}
		return $out;
	}

	/**
	 * Asks for several alternative title/description pairs for the review screen ("show me other options").
	 * Nothing is sent to the sink; the person picks one and it is saved as an edited suggestion.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $current Current values (see suggest_for_post()).
	 * @param int   $count   Number of options (2–5).
	 * @return array|WP_Error List of [ title, description, rationale, focus_keyword_used, valid: bool, warnings ].
	 */
	public function alternatives( int $post_id, array $current = array(), int $count = 3 ) {
		if ( ! $this->client->is_available() ) {
			$error = self::map_error( new WP_Error( 'seoc_ai_not_available', '' ) );
			return new WP_Error( $error['code'], $error['message'] );
		}
		$context = $this->context->build( $post_id, $current + array( 'brand_appended' => $this->options['brand_appended'] ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$count    = max( 2, min( 5, $count ) );
		$response = $this->client->structured(
			Prompts::meta_system(),
			array(
				array(
					'role'    => 'user',
					'content' => Prompts::meta_user_message(
						$context,
						array( 'title', 'description' ),
						array(
							'alternatives'       => $count,
							'rationale_language' => $this->options['rationale_language'],
						)
					),
				),
			),
			Schemas::alternatives(),
			$this->request_options( 'meta_alternatives' )
		);
		if ( is_wp_error( $response ) ) {
			$error = self::map_error( $response );
			return new WP_Error( $error['code'], $error['message'] );
		}
		if ( ! isset( $response['options'] ) || ! is_array( $response['options'] ) ) {
			return new WP_Error( 'seoc_meta_invalid', __( 'The AI answer did not have the expected format.', 'seo-console' ) );
		}

		$options = array();
		foreach ( array_slice( $response['options'], 0, $count ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$entry = array(
				'valid'              => true,
				'focus_keyword_used' => ! empty( $option['focus_keyword_used'] ),
				'rationale'          => self::clean_rationale( isset( $option['rationale'] ) ? $option['rationale'] : '' ),
				'warnings'           => array(),
			);
			foreach ( array( 'title', 'description' ) as $field ) {
				$check           = $this->validator->validate( $field, isset( $option[ $field ] ) ? $option[ $field ] : null, $this->validation_args( $context, $current, $field ) );
				$entry[ $field ] = $check['value'];
				if ( is_wp_error( $check['valid'] ) ) {
					$entry['valid']      = false;
					$entry['warnings'][] = array(
						'code'    => $check['valid']->get_error_code(),
						'message' => $check['valid']->get_error_message(),
					);
				}
				$entry['warnings'] = array_merge( $entry['warnings'], $check['warnings'] );
			}
			$options[] = $entry;
		}
		return $options;
	}

	/**
	 * Turns a client WP_Error into a user-facing message and flags for the batch.
	 *
	 * @param WP_Error $error Error from ClientInterface (or this class).
	 * @return array{code: string, message: string, retryable: bool, stop: bool}
	 */
	public static function map_error( WP_Error $error ): array {
		$code = (string) $error->get_error_code();
		switch ( $code ) {
			case 'seoc_ai_not_available':
				$message = __( 'AI is not set up or this month\'s AI budget is used up. Check the Claude API key and budget in SEO Console → Settings.', 'seo-console' );
				return self::mapped( $code, $message, false, true );
			case 'seoc_ai_no_key':
				$message = __( 'Add your Claude API key in SEO Console → Settings.', 'seo-console' );
				return self::mapped( $code, $message, false, true );
			case 'seoc_ai_budget':
				$message = __( 'This month\'s AI budget is used up. Raise it in SEO Console → Settings, or wait until next month.', 'seo-console' );
				return self::mapped( $code, $message, false, true );
			case 'seoc_ai_auth':
				$message = __( 'Claude rejected the API key. Check the key in SEO Console → Settings.', 'seo-console' );
				return self::mapped( $code, $message, false, true );
			case 'seoc_ai_unavailable':
			case 'seoc_ai_network':
			case 'seoc_ai_timeout':
				$message = __( 'Claude could not be reached right now. SEO Console will try again later.', 'seo-console' );
				return self::mapped( $code, $message, true, true );
			case 'seoc_ai_refusal':
				$message = __( 'Claude declined to write a proposal for this content. You can write it yourself.', 'seo-console' );
				return self::mapped( $code, $message, false, false );
			case 'seoc_ai_truncated':
				$message = __( 'The AI answer was cut off before it was complete. Try again; very long content can cause this.', 'seo-console' );
				return self::mapped( $code, $message, true, false );
			case 'seoc_ai_request':
				$message = __( 'Claude could not process this request. Try again later or write the text yourself.', 'seo-console' );
				return self::mapped( $code, $message, false, false );
			case 'seoc_ai_invalid_json':
			case 'seoc_ai_invalid_response':
			case 'seoc_ai_invalid':
				$message = __( 'The AI answer did not have the expected format. Try again.', 'seo-console' );
				return self::mapped( $code, $message, true, false );
		}
		$message = $error->get_error_message();
		$message = '' === $message
			? __( 'The AI request failed.', 'seo-console' )
			/* translators: %s: technical error message */
			: sprintf( __( 'The AI request failed: %s', 'seo-console' ), wp_strip_all_tags( $message ) );
		return self::mapped( '' === $code ? 'seoc_ai_error' : $code, $message, false, false );
	}

	/**
	 * Title/description request.
	 *
	 * @param array    $result  Result so far.
	 * @param array    $context Post context.
	 * @param string[] $fields  'title' and/or 'description'.
	 * @param array    $current Current values.
	 * @return array
	 */
	private function suggest_meta( array $result, array $context, array $fields, array $current ): array {
		$response = $this->client->structured(
			Prompts::meta_system(),
			array(
				array(
					'role'    => 'user',
					'content' => Prompts::meta_user_message( $context, $fields, array( 'rationale_language' => $this->options['rationale_language'] ) ),
				),
			),
			Schemas::meta(),
			$this->request_options( 'meta' )
		);
		if ( is_wp_error( $response ) ) {
			return $this->add_error( $result, $response );
		}
		if ( ! is_array( $response ) ) {
			return $this->add_error( $result, new WP_Error( 'seoc_ai_invalid', '' ) );
		}

		$rationale = self::clean_rationale( isset( $response['rationale'] ) ? $response['rationale'] : '' );
		foreach ( $fields as $field ) {
			$check = $this->validator->validate( $field, isset( $response[ $field ] ) ? $response[ $field ] : null, $this->validation_args( $context, $current, $field ) );
			if ( is_wp_error( $check['valid'] ) ) {
				$result['rejected'][] = self::rejection( $field, 'post', $context['post_id'], $check['valid'], $check['value'] );
				continue;
			}
			$result = $this->emit(
				$result,
				array(
					'module'         => 'meta',
					'type'           => 'seo_meta',
					'object_type'    => 'post',
					'object_id'      => (int) $context['post_id'],
					'field'          => $field,
					'current_value'  => isset( $current[ $field ] ) ? (string) $current[ $field ] : '',
					'proposed_value' => $check['value'],
					'rationale'      => $rationale,
					'source'         => 'ai',
					'model'          => (string) $this->options['model'],
				),
				$check['warnings']
			);
		}
		return $result;
	}

	/**
	 * Image alt-text request.
	 *
	 * @param array $result  Result so far.
	 * @param array $context Post context.
	 * @return array
	 */
	private function suggest_images( array $result, array $context ): array {
		$targets = array();
		foreach ( $context['images'] as $image ) {
			if ( ! $image['attachment_id'] ) {
				$result['skipped'][] = array(
					'field'     => 'alt',
					'object_id' => 0,
					'code'      => 'external_image',
					'message'   => __( 'Image is not in the media library, so its alt text cannot be changed from here.', 'seo-console' ),
					'src'       => $image['src'],
				);
				continue;
			}
			$image['current_alt'] = $image['inline'] ? $image['alt'] : $image['attachment_alt'];
			if ( 'all' !== $this->options['image_scope'] && ! PostContext::is_weak_alt( $image['current_alt'], $image['filename'] ) ) {
				continue;
			}
			$targets[ (int) $image['attachment_id'] ] = $image;
		}
		$targets = array_slice( $targets, 0, max( 1, (int) $this->options['max_images'] ), true );
		if ( ! $targets ) {
			return $result;
		}

		$response = $this->client->structured(
			Prompts::meta_system(),
			array(
				array(
					'role'    => 'user',
					'content' => Prompts::images_user_message( $context, array_values( $targets ), array( 'rationale_language' => $this->options['rationale_language'] ) ),
				),
			),
			Schemas::images(),
			$this->request_options( 'meta_images' )
		);
		if ( is_wp_error( $response ) ) {
			return $this->add_error( $result, $response );
		}
		if ( ! is_array( $response ) || ! isset( $response['images'] ) || ! is_array( $response['images'] ) ) {
			return $this->add_error( $result, new WP_Error( 'seoc_ai_invalid', '' ) );
		}

		$by_src = array();
		foreach ( $targets as $id => $image ) {
			$by_src[ $image['src'] ] = $id;
		}
		$done = array();
		foreach ( $response['images'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = isset( $entry['attachment_id'] ) ? absint( $entry['attachment_id'] ) : 0;
			if ( ! isset( $targets[ $id ] ) ) {
				$src = isset( $entry['src'] ) ? (string) $entry['src'] : '';
				$id  = isset( $by_src[ $src ] ) ? $by_src[ $src ] : 0;
			}
			if ( ! $id || isset( $done[ $id ] ) ) {
				continue; // Unknown or duplicate image: the model cannot add images of its own.
			}
			$done[ $id ] = true;
			$image       = $targets[ $id ];
			$alt         = isset( $entry['alt'] ) ? $entry['alt'] : null;
			if ( is_string( $alt ) && '' === Validator::normalise( $alt ) ) {
				$result['skipped'][] = array(
					'field'     => 'alt',
					'object_id' => $id,
					'code'      => 'decorative',
					'message'   => __( 'The AI considers this image decorative; no alt text proposed.', 'seo-console' ),
				);
				continue;
			}
			$check = $this->validator->validate(
				'alt',
				$alt,
				array(
					'current'  => $image['current_alt'],
					'language' => $context['language'],
				)
			);
			// Images in the content: the alt text lives in the post (applier post_image_alt). Images only used as
			// featured image or in a product gallery: the media-library alt (core applier image_alt).
			$target = $image['inline']
				? array(
					'type'        => 'post_image_alt',
					'object_type' => 'post',
					'object_id'   => (int) $context['post_id'],
					'field'       => 'image_alt:' . $id,
				)
				: array(
					'type'        => 'image_alt',
					'object_type' => 'attachment',
					'object_id'   => $id,
					'field'       => 'alt',
				);
			if ( is_wp_error( $check['valid'] ) ) {
				$result['rejected'][] = self::rejection( $target['field'], $target['object_type'], $target['object_id'], $check['valid'], $check['value'] );
				continue;
			}
			$result = $this->emit(
				$result,
				array(
					'module'         => 'meta',
					'type'           => $target['type'],
					'object_type'    => $target['object_type'],
					'object_id'      => $target['object_id'],
					'field'          => $target['field'],
					'current_value'  => $image['current_alt'],
					'proposed_value' => $check['value'],
					'rationale'      => self::clean_rationale( isset( $entry['rationale'] ) ? $entry['rationale'] : '' ),
					'source'         => 'ai',
					'model'          => (string) $this->options['model'],
				),
				$check['warnings']
			);
		}
		return $result;
	}

	/**
	 * Sends a suggestion to the sink and records the outcome.
	 *
	 * @param array $result     Result so far.
	 * @param array $suggestion Suggestion.
	 * @param array $warnings   Validator warnings.
	 * @return array
	 */
	private function emit( array $result, array $suggestion, array $warnings ): array {
		$id = call_user_func( $this->sink, $suggestion, array( 'warnings' => $warnings ) );
		if ( is_wp_error( $id ) ) {
			$result['rejected'][] = self::rejection( $suggestion['field'], $suggestion['object_type'], $suggestion['object_id'], $id, $suggestion['proposed_value'] );
			return $result;
		}
		$result['created'][] = array(
			'id'             => is_scalar( $id ) ? $id : null,
			'type'           => $suggestion['type'],
			'object_type'    => $suggestion['object_type'],
			'object_id'      => $suggestion['object_id'],
			'field'          => $suggestion['field'],
			'proposed_value' => $suggestion['proposed_value'],
			'warnings'       => $warnings,
		);
		return $result;
	}

	/**
	 * Validation arguments for one field.
	 *
	 * @param array  $context Context.
	 * @param array  $current Current values.
	 * @param string $field   Field.
	 * @return array
	 */
	private function validation_args( array $context, array $current, string $field ): array {
		return array(
			'current'        => isset( $current[ $field ] ) ? (string) $current[ $field ] : '',
			'language'       => $context['language'],
			'focus_keyword'  => $context['seo']['focus_keyword'],
			'brand'          => $context['seo']['brand'],
			'brand_appended' => $context['seo']['brand_appended'],
		);
	}

	/**
	 * Options for ClientInterface calls.
	 *
	 * @param string $usage_context Label for usage logs.
	 * @return array
	 */
	private function request_options( string $usage_context ): array {
		return array(
			'effort'       => 'low',
			'cache_system' => true,
			'context'      => $usage_context,
		);
	}

	/**
	 * Records a client error.
	 *
	 * @param array    $result Result so far.
	 * @param WP_Error $error  Error.
	 * @return array
	 */
	private function add_error( array $result, WP_Error $error ): array {
		$mapped              = self::map_error( $error );
		$result['errors'][]  = array(
			'code'      => $mapped['code'],
			'message'   => $mapped['message'],
			'retryable' => $mapped['retryable'],
		);
		$result['stop']      = $result['stop'] || $mapped['stop'];
		$result['retryable'] = $result['retryable'] || $mapped['retryable'];
		return $result;
	}

	/**
	 * Records an error and finishes.
	 *
	 * @param array    $result Result so far.
	 * @param WP_Error $error  Error.
	 * @return array
	 */
	private function fail( array $result, WP_Error $error ): array {
		return $this->finish( $this->add_error( $result, $error ) );
	}

	/**
	 * Sets the overall status.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private function finish( array $result ): array {
		$problems = $result['errors'] || $result['rejected'];
		if ( $result['created'] ) {
			$result['status'] = $problems ? 'partial' : 'ok';
		} else {
			$result['status'] = $problems ? 'failed' : 'nothing';
		}
		return $result;
	}

	/**
	 * Rejected-proposal entry.
	 *
	 * @param string   $field       Field.
	 * @param string   $object_type Object type.
	 * @param int      $object_id   Object ID.
	 * @param WP_Error $error       Error.
	 * @param string   $value       Rejected (normalised) value, for logs.
	 * @return array
	 */
	private static function rejection( string $field, string $object_type, int $object_id, WP_Error $error, string $value ): array {
		return array(
			'field'       => $field,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'code'        => $error->get_error_code(),
			'message'     => $error->get_error_message(),
			'value'       => PostContext::cut( $value, 200 ),
		);
	}

	/**
	 * Mapped error array.
	 *
	 * @param string $code      Code.
	 * @param string $message   Message.
	 * @param bool   $retryable Retry later.
	 * @param bool   $stop      Stop the batch.
	 * @return array
	 */
	private static function mapped( string $code, string $message, bool $retryable, bool $stop ): array {
		return array(
			'code'      => $code,
			'message'   => $message,
			'retryable' => $retryable,
			'stop'      => $stop,
		);
	}

	/**
	 * Rationale as short plain text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function clean_rationale( $value ): string {
		return is_string( $value ) ? PostContext::cut( Validator::normalise( $value ), 600 ) : '';
	}

	/**
	 * Current time in seconds.
	 *
	 * @return float
	 */
	private function now(): float {
		return is_callable( $this->options['clock'] ) ? (float) call_user_func( $this->options['clock'] ) : microtime( true );
	}
}
