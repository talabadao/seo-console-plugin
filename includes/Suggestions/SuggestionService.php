<?php
/**
 * Approval queue (see docs/ARCHITECTURE.md §7.3).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Suggestions;

use SEOConsole\Changes\Change;
use SEOConsole\Changes\ChangeManager;
use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Core\ObjectInfo;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestions are proposed values for a known applier. A person reviews, optionally edits and approves them; only
 * then ChangeManager applies them.
 */
final class SuggestionService {

	const SOURCES = array( 'ai', 'rule', 'mcp' );

	const MAX_BULK = 100;

	/**
	 * Store.
	 *
	 * @var SuggestionStore
	 */
	private $store;

	/**
	 * Change manager.
	 *
	 * @var ChangeManager
	 */
	private $changes;

	/**
	 * Constructor.
	 *
	 * @param SuggestionStore $store   Store.
	 * @param ChangeManager   $changes Change manager.
	 */
	public function __construct( SuggestionStore $store, ChangeManager $changes ) {
		$this->store   = $store;
		$this->changes = $changes;
	}

	/**
	 * The store (for modules that need raw access).
	 */
	public function store(): SuggestionStore {
		return $this->store;
	}

	/**
	 * Creates a suggestion, or updates the pending one for the same type/object_type/object_id/field.
	 *
	 * Can be passed directly as a sink callable: array( $service, 'create' ).
	 *
	 * @param array $data module, type, object_type, object_id, field, proposed_value (required); rationale, confidence
	 *                    (0–1), source ('ai'|'rule'|'mcp', default 'ai'), model, meta (array, e.g. [ 'warnings' => [...] ]).
	 * @param array $meta Extra meta merged into $data['meta'].
	 * @return int|WP_Error Suggestion id.
	 */
	public function create( array $data, array $meta = array() ) {
		foreach ( array( 'type', 'object_type', 'field' ) as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'seoc_invalid_suggestion', sprintf( __( 'Missing "%s".', 'seo-console' ), $key ), array( 'status' => 400 ) );
			}
		}
		if ( ! array_key_exists( 'proposed_value', $data ) ) {
			return new WP_Error( 'seoc_invalid_suggestion', __( 'Missing "proposed_value".', 'seo-console' ), array( 'status' => 400 ) );
		}
		$source = isset( $data['source'] ) ? (string) $data['source'] : 'ai';
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return new WP_Error( 'seoc_invalid_suggestion', __( 'Invalid suggestion source.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$confidence = null;
		if ( isset( $data['confidence'] ) ) {
			if ( ! is_numeric( $data['confidence'] ) || $data['confidence'] < 0 || $data['confidence'] > 1 ) {
				return new WP_Error( 'seoc_invalid_suggestion', __( 'Confidence must be between 0 and 1.', 'seo-console' ), array( 'status' => 400 ) );
			}
			$confidence = (float) $data['confidence'];
		}

		if ( isset( $data['meta'] ) && ! is_array( $data['meta'] ) ) {
			return new WP_Error( 'seoc_invalid_suggestion', __( '"meta" must be an object.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$meta = array_merge( isset( $data['meta'] ) ? (array) $data['meta'] : array(), $meta );
		$json = empty( $meta ) ? '' : wp_json_encode( $meta );
		if ( false === $json || strlen( (string) $json ) > 65536 ) {
			return new WP_Error( 'seoc_invalid_suggestion', __( '"meta" must be JSON data under 64 KB.', 'seo-console' ), array( 'status' => 400 ) );
		}

		$type        = $data['type'];
		$object_type = $data['object_type'];
		$object_id   = isset( $data['object_id'] ) ? (int) $data['object_id'] : null;
		$field       = $data['field'];
		$applier     = $this->changes->applier( $type );
		if ( null === $applier ) {
			/* translators: %s: applier id */
			return new WP_Error( 'seoc_unknown_applier', sprintf( __( 'Unknown change type "%s".', 'seo-console' ), $type ), array( 'status' => 400 ) );
		}
		$valid = $applier->validate( $data['proposed_value'], $object_type, $object_id, $field );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}
		$current = $applier->read( $object_type, $object_id, $field );
		if ( Change::values_equal( $current, $data['proposed_value'] ) ) {
			return new WP_Error( 'seoc_no_change', __( 'The proposed value is the same as the current value.', 'seo-console' ), array( 'status' => 400 ) );
		}

		$row = array(
			'module'         => substr( sanitize_key( isset( $data['module'] ) ? (string) $data['module'] : '' ), 0, 40 ),
			'type'           => $type,
			'object_type'    => $object_type,
			'object_id'      => $object_id,
			'field'          => $field,
			'current_value'  => $current,
			'proposed_value' => $data['proposed_value'],
			'final_value'    => null,
			'rationale'      => isset( $data['rationale'] ) ? sanitize_textarea_field( (string) $data['rationale'] ) : '',
			'confidence'     => $confidence,
			'source'         => $source,
			'model'          => isset( $data['model'] ) && '' !== $data['model'] ? substr( sanitize_text_field( (string) $data['model'] ), 0, 80 ) : null,
			'status'         => 'pending',
			'error'          => null,
			'meta'           => empty( $meta ) ? null : $meta,
		);

		$existing = $this->store->find_pending( $type, $object_type, $object_id, $field );
		if ( $existing ) {
			$this->store->update( $existing['id'], $row );
			$id = $existing['id'];
		} else {
			$row['created_by'] = get_current_user_id();
			$id                = $this->store->insert( $row );
			if ( ! $id ) {
				return new WP_Error( 'seoc_db_error', __( 'The suggestion could not be saved.', 'seo-console' ), array( 'status' => 500 ) );
			}
		}

		/**
		 * Fires after a suggestion was created or refreshed.
		 *
		 * @param int  $id      Suggestion id.
		 * @param bool $updated Whether an existing pending suggestion was updated.
		 */
		do_action( 'seoc_suggestion_created', $id, (bool) $existing );
		return $id;
	}

	/**
	 * One suggestion as a REST item, or null.
	 *
	 * @param int $id Suggestion id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->store->get( $id );
		return $row ? $this->to_rest( $row ) : null;
	}

	/**
	 * Filtered, paged list.
	 *
	 * @param array $args status, module, type, object_type, object_id, page, per_page (≤ 100).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args = array() ): array {
		$result = $this->store->query( $args );
		return array(
			'items' => array_map( array( $this, 'to_rest' ), $result['items'] ),
			'total' => $result['total'],
		);
	}

	/**
	 * Approves (applies) a suggestion, optionally with an edited value.
	 *
	 * @param int   $id           Suggestion id.
	 * @param mixed $edited_value Value to apply instead of the proposed one (null = proposed).
	 * @param bool  $force        Apply even when the live value changed since the suggestion was made.
	 * @return Change|WP_Error
	 */
	public function approve( int $id, $edited_value = null, bool $force = false ) {
		$row = $this->store->get( $id );
		if ( null === $row ) {
			return new WP_Error( 'seoc_not_found', __( 'Suggestion not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $row['status'], array( 'pending', 'stale', 'failed' ), true ) ) {
			return new WP_Error( 'seoc_invalid_status', __( 'This suggestion was already handled.', 'seo-console' ), array( 'status' => 409 ) );
		}
		$stale = $this->check_stale( $row, $force );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		if ( null !== $edited_value ) {
			/**
			 * Filters a value a person edited before it is applied (e.g. a module re-adds a template suffix that
			 * the UI hides; see suggestion meta `display_suffix`).
			 *
			 * @param mixed $edited_value Edited value.
			 * @param array $row          Suggestion row (decoded).
			 */
			$edited_value = apply_filters( 'seoc_suggestion_approve_value', $edited_value, $row );
		}
		$value  = null === $edited_value ? $row['proposed_value'] : $edited_value;
		$change = $this->changes->apply( $this->request_for( $row, $value, null !== $edited_value ) );
		return $this->record_result( $row, $value, $change );
	}

	/**
	 * Rejects a suggestion.
	 *
	 * @param int $id Suggestion id.
	 * @return true|WP_Error
	 */
	public function reject( int $id ) {
		$row = $this->store->get( $id );
		if ( null === $row ) {
			return new WP_Error( 'seoc_not_found', __( 'Suggestion not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $row['status'], array( 'pending', 'stale', 'failed' ), true ) ) {
			return new WP_Error( 'seoc_invalid_status', __( 'This suggestion was already handled.', 'seo-console' ), array( 'status' => 409 ) );
		}
		$this->store->update( $id, array( 'status' => 'rejected' ) );
		/**
		 * Fires after a suggestion was rejected.
		 *
		 * @param int $id Suggestion id.
		 */
		do_action( 'seoc_suggestion_rejected', $id );
		return true;
	}

	/**
	 * Approves or rejects several suggestions. Approvals are applied as one batch (one health check); ids that are
	 * missing, already handled or stale get their own error.
	 *
	 * @param string $action 'approve' | 'reject'.
	 * @param int[]  $ids    Suggestion ids (≤ 100).
	 * @return array<int, Change|true|WP_Error> id => result.
	 */
	public function bulk( string $action, array $ids ): array {
		$ids     = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, self::MAX_BULK );
		$results = array();
		if ( 'reject' === $action ) {
			foreach ( $ids as $id ) {
				$results[ $id ] = $this->reject( $id );
			}
			return $results;
		}
		if ( 'approve' !== $action ) {
			foreach ( $ids as $id ) {
				$results[ $id ] = new WP_Error( 'seoc_invalid_action', __( 'Unknown bulk action.', 'seo-console' ), array( 'status' => 400 ) );
			}
			return $results;
		}

		$requests = array();
		$rows     = array();
		foreach ( $ids as $id ) {
			$row = $this->store->get( $id );
			if ( null === $row ) {
				$results[ $id ] = new WP_Error( 'seoc_not_found', __( 'Suggestion not found.', 'seo-console' ), array( 'status' => 404 ) );
				continue;
			}
			if ( ! in_array( $row['status'], array( 'pending', 'stale', 'failed' ), true ) ) {
				$results[ $id ] = new WP_Error( 'seoc_invalid_status', __( 'This suggestion was already handled.', 'seo-console' ), array( 'status' => 409 ) );
				continue;
			}
			$stale = $this->check_stale( $row, false );
			if ( is_wp_error( $stale ) ) {
				$results[ $id ] = $stale;
				continue;
			}
			$request = $this->request_for( $row, $row['proposed_value'], false );
			$valid   = $this->changes->validate( $request );
			if ( is_wp_error( $valid ) ) {
				$results[ $id ] = $valid;
				continue;
			}
			$requests[ $id ] = $request;
			$rows[ $id ]     = $row;
		}

		if ( ! empty( $requests ) ) {
			$batch   = $this->changes->apply_batch( array_values( $requests ) );
			$ordered = array_keys( $requests );
			foreach ( $ordered as $index => $id ) {
				if ( $batch['ok'] && isset( $batch['changes'][ $index ] ) ) {
					$results[ $id ] = $this->record_result( $rows[ $id ], $rows[ $id ]['proposed_value'], $batch['changes'][ $index ] );
				} else {
					$error          = isset( $batch['errors'][ $index ] ) ? $batch['errors'][ $index ] : ( $batch['error'] ? $batch['error'] : new WP_Error( 'seoc_batch_failed', __( 'The batch failed.', 'seo-console' ) ) );
					$results[ $id ] = $this->record_result( $rows[ $id ], $rows[ $id ]['proposed_value'], $error );
				}
			}
		}

		$ordered_results = array();
		foreach ( $ids as $id ) {
			$ordered_results[ $id ] = $results[ $id ];
		}
		return $ordered_results;
	}

	/**
	 * Marks a pending suggestion stale when the live value no longer matches current_value.
	 *
	 * @param int $id Suggestion id.
	 * @return bool Whether the suggestion is (now) stale.
	 */
	public function refresh_stale( int $id ): bool {
		$row = $this->store->get( $id );
		if ( null === $row ) {
			return false;
		}
		return is_wp_error( $this->check_stale( $row, false ) );
	}

	/**
	 * REST item (shape documented in ARCHITECTURE "REST response shapes").
	 *
	 * @param array $row Decoded row.
	 * @return array<string, mixed>
	 */
	public function to_rest( array $row ): array {
		$applier = $this->changes->applier( (string) $row['type'] );
		$item    = array(
			'id'             => (int) $row['id'],
			'created_at'     => ObjectInfo::iso_date( $row['created_at'] ),
			'updated_at'     => ObjectInfo::iso_date( $row['updated_at'] ),
			'module'         => (string) $row['module'],
			'type'           => (string) $row['type'],
			'label'          => $applier ? $applier->label( (string) $row['field'] ) : (string) $row['field'],
			'field_label'    => $applier ? $applier->label( (string) $row['field'] ) : (string) $row['field'],
			'object_type'    => (string) $row['object_type'],
			'object_id'      => $row['object_id'],
			'field'          => (string) $row['field'],
			'current_value'  => $row['current_value'],
			'proposed_value' => $row['proposed_value'],
			'final_value'    => $row['final_value'],
			'rationale'      => (string) $row['rationale'],
			'confidence'     => $row['confidence'],
			'source'         => (string) $row['source'],
			'model'          => $row['model'],
			'status'         => (string) $row['status'],
			'change_id'      => $row['change_id'],
			'error'          => $row['error'],
			'created_by'     => (int) $row['created_by'],
			'meta'           => isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : null,
		);
		return array_merge( $item, ObjectInfo::describe( $item['object_type'], $item['object_id'] ) );
	}

	/**
	 * Returns a WP_Error (and marks the row stale) when the live value differs from current_value.
	 *
	 * @param array $row   Row.
	 * @param bool  $force Skip the check.
	 * @return true|WP_Error
	 */
	private function check_stale( array $row, bool $force ) {
		if ( $force ) {
			return true;
		}
		$applier = $this->changes->applier( (string) $row['type'] );
		if ( null === $applier ) {
			return new WP_Error( 'seoc_unknown_applier', __( 'The module that made this suggestion is not active.', 'seo-console' ), array( 'status' => 409 ) );
		}
		$live = $applier->read( $row['object_type'], $row['object_id'], $row['field'] );
		if ( Change::values_equal( $live, $row['current_value'] ) ) {
			return true;
		}
		$this->store->update(
			(int) $row['id'],
			array(
				'status' => 'stale',
				'error'  => 'seoc_stale',
			)
		);
		return new WP_Error(
			'seoc_stale',
			__( 'The value changed since this suggestion was made. Review it again, or approve anyway to overwrite.', 'seo-console' ),
			array(
				'status'        => 409,
				'current_value' => $live,
				'suggestion_id' => (int) $row['id'],
			)
		);
	}

	/**
	 * Change request for a suggestion.
	 *
	 * @param array $row    Row.
	 * @param mixed $value  Value to apply.
	 * @param bool  $edited Whether the value was edited by the reviewer.
	 */
	private function request_for( array $row, $value, bool $edited ): ChangeRequest {
		return new ChangeRequest(
			(string) $row['type'],
			(string) $row['object_type'],
			$row['object_id'],
			(string) $row['field'],
			$value,
			array(
				'source'        => (string) $row['source'],
				'module'        => (string) $row['module'],
				'suggestion_id' => (int) $row['id'],
				'note'          => $edited ? __( 'Approved with edits.', 'seo-console' ) : '',
			)
		);
	}

	/**
	 * Stores the outcome of an approval.
	 *
	 * @param array           $row    Row.
	 * @param mixed           $value  Value applied.
	 * @param Change|WP_Error $change Result.
	 * @return Change|WP_Error
	 */
	private function record_result( array $row, $value, $change ) {
		$id = (int) $row['id'];
		if ( is_wp_error( $change ) ) {
			$data = $change->get_error_data();
			// Invalid input (e.g. an edited value that fails validation) keeps the suggestion open for another try.
			$status = is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] < 500 ? $row['status'] : 'failed';
			$this->store->update(
				$id,
				array(
					'status' => $status,
					'error'  => $change->get_error_message(),
				)
			);
			return $change;
		}
		$this->store->update(
			$id,
			array(
				'status'      => 'applied',
				'final_value' => $value,
				'change_id'   => $change->id,
				'error'       => null,
			)
		);
		/**
		 * Fires after a suggestion was approved and applied.
		 *
		 * @param int    $id     Suggestion id.
		 * @param Change $change Change.
		 */
		do_action( 'seoc_suggestion_applied', $id, $change );
		return $change;
	}
}
