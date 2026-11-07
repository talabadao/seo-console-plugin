<?php
/**
 * Applies, logs and reverts changes (see docs/ARCHITECTURE.md §7.2).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes;

use SEOConsole\Core\Database\Schema;
use SEOConsole\Core\HealthCheck;
use SEOConsole\Core\Logger;
use SEOConsole\Core\ObjectInfo;
use SEOConsole\Integrations\CachePlugins;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * read before → validate → write → log row. Frontend-affecting appliers also purge caches and run a health check;
 * a failed check reverts immediately.
 */
final class ChangeManager {

	const SOURCES = array( 'user', 'ai', 'rule', 'mcp', 'system' );

	const STATUSES = array( 'applied', 'reverted', 'failed' );

	/**
	 * Registered appliers keyed by type.
	 *
	 * @var ApplierInterface[]
	 */
	private $appliers = array();

	/**
	 * Cache plugins.
	 *
	 * @var CachePlugins
	 */
	private $cache;

	/**
	 * Health check.
	 *
	 * @var HealthCheck
	 */
	private $health;

	/**
	 * Logger.
	 *
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param CachePlugins $cache  Cache plugins.
	 * @param HealthCheck  $health Health check.
	 * @param Logger|null  $logger Logger.
	 */
	public function __construct( CachePlugins $cache, HealthCheck $health, ?Logger $logger = null ) {
		$this->cache  = $cache;
		$this->health = $health;
		$this->logger = $logger;
	}

	/**
	 * Registers an applier (a later registration with the same type replaces the earlier one).
	 *
	 * @param ApplierInterface $applier Applier.
	 */
	public function register_applier( ApplierInterface $applier ): void {
		$this->appliers[ $applier->type() ] = $applier;
	}

	/**
	 * Applier by type.
	 *
	 * @param string $type Applier id.
	 */
	public function applier( string $type ): ?ApplierInterface {
		return isset( $this->appliers[ $type ] ) ? $this->appliers[ $type ] : null;
	}

	/**
	 * All appliers keyed by type.
	 *
	 * @return ApplierInterface[]
	 */
	public function appliers(): array {
		return $this->appliers;
	}

	/**
	 * Validates a request without writing anything.
	 *
	 * @param ChangeRequest $request Request.
	 * @return true|WP_Error
	 */
	public function validate( ChangeRequest $request ) {
		$applier = $this->applier( (string) $request->type );
		if ( null === $applier ) {
			return new WP_Error(
				'seoc_unknown_applier',
				/* translators: %s: applier id */
				sprintf( __( 'Unknown change type "%s".', 'seo-console' ), (string) $request->type ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $request->source, self::SOURCES, true ) ) {
			return new WP_Error( 'seoc_invalid_source', __( 'Invalid change source.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$result = $applier->validate( $request->value, (string) $request->object_type, $request->object_id, (string) $request->field );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}
		return true;
	}

	/**
	 * Applies one change.
	 *
	 * @param ChangeRequest $request Request.
	 * @return Change|WP_Error
	 */
	public function apply( ChangeRequest $request ) {
		$valid = $this->validate( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$applier = $this->applier( $request->type );
		$before  = $applier->read( $request->object_type, $request->object_id, $request->field );
		$written = $applier->write( $request->object_type, $request->object_id, $request->field, $request->value );
		if ( is_wp_error( $written ) ) {
			$this->insert( $request, $before, $request->value, 'failed', $written->get_error_message(), null );
			return self::with_status( $written, 500 );
		}
		$after  = $applier->read( $request->object_type, $request->object_id, $request->field );
		$change = $this->insert( $request, $before, $after, 'applied', null, null );
		if ( is_wp_error( $change ) ) {
			$applier->write( $request->object_type, $request->object_id, $request->field, $before );
			return $change;
		}

		if ( $applier->affects_frontend() ) {
			$this->cache->purge_all();
			$result = $this->health->run( $this->urls_for( array( $request ) ) );
			if ( ! $result['ok'] ) {
				$applier->write( $request->object_type, $request->object_id, $request->field, $before );
				$this->cache->purge_all();
				$reason = self::health_reason( $result );
				$this->update_row(
					$change->id,
					array(
						'status' => 'failed',
						'error'  => $reason,
					)
				);
				$this->log(
					'warning',
					'Change reverted automatically: health check failed.',
					array(
						'change_id' => $change->id,
						'reason'    => $reason,
					)
				);
				$failed = $this->get( $change->id );
				/**
				 * Fires when a change failed and was rolled back.
				 *
				 * @param Change $change Change (status failed).
				 */
				do_action( 'seoc_change_failed', $failed );
				return new WP_Error(
					'seoc_health_failed',
					/* translators: %s: reason */
					sprintf( __( 'The change was undone because the site did not load correctly afterwards: %s', 'seo-console' ), $reason ),
					array(
						'status'    => 500,
						'change_id' => $change->id,
						'checks'    => $result['checks'],
					)
				);
			}
		}

		/**
		 * Fires after a change was applied.
		 *
		 * @param Change $change Change.
		 */
		do_action( 'seoc_change_applied', $change );
		return $change;
	}

	/**
	 * Applies several changes with one health check; all-or-nothing.
	 *
	 * @param ChangeRequest[] $requests Requests.
	 * @return array{ok: bool, batch_id: string, changes: Change[], errors: array<int, WP_Error>, error: WP_Error|null}
	 */
	public function apply_batch( array $requests ): array {
		$result   = array(
			'ok'       => false,
			'batch_id' => wp_generate_uuid4(),
			'changes'  => array(),
			'errors'   => array(),
			'error'    => null,
		);
		$requests = array_values( $requests );
		if ( empty( $requests ) ) {
			$result['ok'] = true;
			return $result;
		}

		foreach ( $requests as $index => $request ) {
			if ( ! $request instanceof ChangeRequest ) {
				$result['errors'][ $index ] = new WP_Error( 'seoc_invalid_request', __( 'Invalid change request.', 'seo-console' ), array( 'status' => 400 ) );
				continue;
			}
			$valid = $this->validate( $request );
			if ( is_wp_error( $valid ) ) {
				$result['errors'][ $index ] = $valid;
			}
		}
		if ( ! empty( $result['errors'] ) ) {
			$result['error'] = new WP_Error( 'seoc_batch_invalid', __( 'Nothing was applied because some changes are invalid.', 'seo-console' ), array( 'status' => 400 ) );
			return $result;
		}

		$applied  = array(); // index => [ request, before, change ].
		$frontend = false;
		foreach ( $requests as $index => $request ) {
			$applier = $this->applier( $request->type );
			$before  = $applier->read( $request->object_type, $request->object_id, $request->field );
			$written = $applier->write( $request->object_type, $request->object_id, $request->field, $request->value );
			if ( is_wp_error( $written ) ) {
				$this->rollback( $applied, __( 'Rolled back: another change in the batch failed.', 'seo-console' ) );
				$this->insert( $request, $before, $request->value, 'failed', $written->get_error_message(), $result['batch_id'] );
				$result['errors'][ $index ] = self::with_status( $written, 500 );
				$result['error']            = new WP_Error( 'seoc_batch_failed', __( 'Nothing was applied because one change could not be written.', 'seo-console' ), array( 'status' => 500 ) );
				return $result;
			}
			$after  = $applier->read( $request->object_type, $request->object_id, $request->field );
			$change = $this->insert( $request, $before, $after, 'applied', null, $result['batch_id'] );
			if ( is_wp_error( $change ) ) {
				$applier->write( $request->object_type, $request->object_id, $request->field, $before );
				$this->rollback( $applied, __( 'Rolled back: the change log could not be written.', 'seo-console' ) );
				$result['errors'][ $index ] = $change;
				$result['error']            = $change;
				return $result;
			}
			$applied[ $index ] = array( $request, $before, $change );
			$frontend          = $frontend || $applier->affects_frontend();
		}

		if ( $frontend ) {
			$this->cache->purge_all();
			$health = $this->health->run( $this->urls_for( $requests ) );
			if ( ! $health['ok'] ) {
				$reason = self::health_reason( $health );
				$this->rollback( $applied, $reason );
				$this->cache->purge_all();
				$this->log(
					'warning',
					'Batch reverted automatically: health check failed.',
					array(
						'batch_id' => $result['batch_id'],
						'reason'   => $reason,
					)
				);
				$result['error'] = new WP_Error(
					'seoc_health_failed',
					/* translators: %s: reason */
					sprintf( __( 'The changes were undone because the site did not load correctly afterwards: %s', 'seo-console' ), $reason ),
					array(
						'status'   => 500,
						'batch_id' => $result['batch_id'],
						'checks'   => $health['checks'],
					)
				);
				return $result;
			}
		}

		foreach ( $applied as $index => $item ) {
			$result['changes'][ $index ] = $item[2];
			/** This action is documented in includes/Changes/ChangeManager.php */
			do_action( 'seoc_change_applied', $item[2] );
		}
		$result['ok'] = true;
		return $result;
	}

	/**
	 * Reverts an applied change to its before value.
	 *
	 * @param int  $change_id Change id.
	 * @param bool $force     Revert even when the value changed since (conflict).
	 * @return true|WP_Error
	 */
	public function revert( int $change_id, bool $force = false ) {
		$change = $this->get( $change_id );
		if ( null === $change ) {
			return new WP_Error( 'seoc_not_found', __( 'Change not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( 'applied' !== $change->status ) {
			return new WP_Error( 'seoc_not_applied', __( 'Only applied changes can be undone.', 'seo-console' ), array( 'status' => 409 ) );
		}
		$applier = $this->applier( $change->type );
		if ( null === $applier ) {
			return new WP_Error( 'seoc_unknown_applier', __( 'The module that made this change is not active.', 'seo-console' ), array( 'status' => 409 ) );
		}
		$current = $applier->read( $change->object_type, $change->object_id, $change->field );
		if ( ! $force && ! Change::values_equal( $current, $change->after_value ) ) {
			return new WP_Error(
				'seoc_conflict',
				__( 'The value was changed after this change was applied. Undo anyway to overwrite it.', 'seo-console' ),
				array(
					'status'        => 409,
					'current_value' => $current,
					'after_value'   => $change->after_value,
				)
			);
		}
		$written = $applier->write( $change->object_type, $change->object_id, $change->field, $change->before_value );
		if ( is_wp_error( $written ) ) {
			return self::with_status( $written, 500 );
		}

		if ( $applier->affects_frontend() ) {
			$this->cache->purge_all();
			$health = $this->health->run( $this->urls_for( array( new ChangeRequest( $change->type, $change->object_type, $change->object_id, $change->field, null ) ) ) );
			if ( ! $health['ok'] ) {
				$applier->write( $change->object_type, $change->object_id, $change->field, $current );
				$this->cache->purge_all();
				$reason = self::health_reason( $health );
				$this->log(
					'warning',
					'Undo rolled back: health check failed.',
					array(
						'change_id' => $change->id,
						'reason'    => $reason,
					)
				);
				return new WP_Error(
					'seoc_health_failed',
					/* translators: %s: reason */
					sprintf( __( 'The undo was rolled back because the site did not load correctly afterwards: %s', 'seo-console' ), $reason ),
					array(
						'status'    => 500,
						'change_id' => $change->id,
						'checks'    => $health['checks'],
					)
				);
			}
		}

		$this->update_row(
			$change->id,
			array(
				'status'      => 'reverted',
				'reverted_at' => gmdate( 'Y-m-d H:i:s' ),
				'reverted_by' => get_current_user_id(),
			)
		);
		$reverted = $this->get( $change->id );
		/**
		 * Fires after a change was reverted.
		 *
		 * @param Change $change Change (status reverted).
		 */
		do_action( 'seoc_change_reverted', $reverted );
		return true;
	}

	/**
	 * One change.
	 *
	 * @param int $change_id Change id.
	 */
	public function get( int $change_id ): ?Change {
		global $wpdb;
		$table = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table from whitelist.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $change_id ), ARRAY_A );
		return $row ? Change::from_row( $row ) : null;
	}

	/**
	 * Paged change log, newest first.
	 *
	 * @param array $args module, status, type, object_type, object_id, suggestion_id, batch_id, page, per_page (≤ 100).
	 * @return array{items: Change[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table = Schema::table( 'changes' );
		// Every condition is a literal '%i = %s' / '%i = %d' fragment; columns and values go in as placeholders.
		$where  = array( '1=1' );
		$params = array( $table );
		foreach ( array( 'module', 'status', 'type', 'object_type', 'batch_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
				$where[]  = '%i = %s';
				$params[] = $key;
				$params[] = (string) $args[ $key ];
			}
		}
		foreach ( array( 'object_id', 'suggestion_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
				$where[]  = '%i = %d';
				$params[] = $key;
				$params[] = (int) $args[ $key ];
			}
		}
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql holds only literal placeholder fragments.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where_sql, $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = Change::from_row( $row );
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * REST/UI representation of a change (shape documented in ARCHITECTURE "REST response shapes").
	 *
	 * @param Change $change Change.
	 * @return array<string, mixed>
	 */
	public function to_rest( Change $change ): array {
		$applier = $this->applier( $change->type );
		$user    = $change->user_id ? get_userdata( $change->user_id ) : null;
		$undoer  = $change->reverted_by ? get_userdata( $change->reverted_by ) : null;
		$label   = $applier ? $applier->label( $change->field ) : $change->field;
		$data    = array(
			'id'               => $change->id,
			'created_at'       => ObjectInfo::iso_date( $change->created_at ),
			'user_id'          => $change->user_id,
			'user_name'        => $user ? $user->display_name : '',
			'source'           => $change->source,
			'module'           => $change->module,
			'type'             => $change->type,
			'label'            => $label,
			'field_label'      => $label,
			'object_type'      => $change->object_type,
			'object_id'        => $change->object_id,
			'field'            => $change->field,
			'before_value'     => $change->before_value,
			'after_value'      => $change->after_value,
			'status'           => $change->status,
			'error'            => $change->error,
			'suggestion_id'    => $change->suggestion_id,
			'batch_id'         => $change->batch_id,
			'note'             => $change->note,
			'reverted_at'      => ObjectInfo::iso_date( $change->reverted_at ),
			'reverted_by'      => $change->reverted_by,
			'reverted_by_name' => $undoer ? $undoer->display_name : '',
			'can_revert'       => 'applied' === $change->status && null !== $applier,
		);
		return array_merge( $data, ObjectInfo::describe( $change->object_type, $change->object_id ) );
	}

	/**
	 * Inserts a change row.
	 *
	 * @param ChangeRequest $request  Request.
	 * @param mixed         $before   Before value.
	 * @param mixed         $after    After value.
	 * @param string        $status   Status.
	 * @param string|null   $error    Error.
	 * @param string|null   $batch_id Batch id.
	 * @return Change|WP_Error
	 */
	private function insert( ChangeRequest $request, $before, $after, string $status, ?string $error, ?string $batch_id ) {
		global $wpdb;
		$row = array(
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'user_id'       => get_current_user_id(),
			'source'        => (string) $request->source,
			'module'        => substr( sanitize_key( (string) $request->module ), 0, 40 ),
			'type'          => (string) $request->type,
			'object_type'   => (string) $request->object_type,
			'object_id'     => null === $request->object_id ? null : (int) $request->object_id,
			'field'         => (string) $request->field,
			'before_value'  => Change::encode( $before ),
			'after_value'   => Change::encode( $after ),
			'status'        => $status,
			'error'         => $error,
			'suggestion_id' => null === $request->suggestion_id ? null : (int) $request->suggestion_id,
			'batch_id'      => $batch_id,
			'note'          => sanitize_textarea_field( (string) $request->note ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert( Schema::table( 'changes' ), $row );
		if ( false === $ok ) {
			return new WP_Error( 'seoc_db_error', __( 'The change log could not be written.', 'seo-console' ), array( 'status' => 500 ) );
		}
		$row['id']          = (int) $wpdb->insert_id;
		$row['reverted_at'] = null;
		$row['reverted_by'] = null;
		return Change::from_row( $row );
	}

	/**
	 * Updates a change row.
	 *
	 * @param int   $change_id Change id.
	 * @param array $data      Columns.
	 */
	private function update_row( int $change_id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->update( Schema::table( 'changes' ), $data, array( 'id' => $change_id ) );
	}

	/**
	 * Restores the before values of applied batch items (reverse order) and marks them failed.
	 *
	 * @param array  $applied index => [ request, before, change ].
	 * @param string $reason  Error text.
	 */
	private function rollback( array $applied, string $reason ): void {
		foreach ( array_reverse( $applied, true ) as $item ) {
			list( $request, $before, $change ) = $item;
			$this->applier( $request->type )->write( $request->object_type, $request->object_id, $request->field, $before );
			$this->update_row(
				$change->id,
				array(
					'status' => 'failed',
					'error'  => $reason,
				)
			);
		}
	}

	/**
	 * Public URLs to check for some requests (up to 3).
	 *
	 * @param ChangeRequest[] $requests Requests.
	 * @return string[]
	 */
	private function urls_for( array $requests ): array {
		$urls = array();
		foreach ( $requests as $request ) {
			$url = ObjectInfo::public_url( (string) $request->object_type, $request->object_id );
			if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
			if ( count( $urls ) >= HealthCheck::MAX_EXTRA_URLS ) {
				break;
			}
		}
		return $urls;
	}

	/**
	 * Human reason of a failed health check.
	 *
	 * @param array $result HealthCheck::run() result.
	 */
	private static function health_reason( array $result ): string {
		foreach ( $result['checks'] as $check ) {
			if ( isset( $check['status'] ) && 'fail' === $check['status'] ) {
				$url = isset( $check['url'] ) ? $check['url'] : '';
				$err = isset( $check['error'] ) && '' !== $check['error'] ? $check['error'] : __( 'unknown error', 'seo-console' );
				return trim( $url . ' — ' . $err, ' —' );
			}
		}
		return __( 'health check failed', 'seo-console' );
	}

	/**
	 * Adds an HTTP status to an error that has none.
	 *
	 * @param WP_Error $error  Error.
	 * @param int      $status Status.
	 */
	private static function with_status( WP_Error $error, int $status ): WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = $status;
			$error->add_data( $data );
		}
		return $error;
	}

	/**
	 * Logs when a logger is available.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger ) {
			$this->logger->log( $level, $message, $context, 'core' );
		}
	}
}
