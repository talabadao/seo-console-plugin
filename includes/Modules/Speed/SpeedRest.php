<?php
/**
 * REST routes of the Speed module (`seo-console/v1/speed/…`, permission manage_options).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Plugin;
use SEOConsole\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes: speed/runs, speed/runs/{id}, speed/overview, speed/recommendations, speed/tweaks, speed/tweaks/{id},
 * speed/cache, speed/maintenance, speed/maintenance/{action}, speed/urls, speed/crux.
 */
class SpeedRest extends Controller {

	/** Cleanup actions. */
	const CLEANUPS = array( 'expired_transients', 'action_scheduler', 'orphaned_postmeta' );

	/**
	 * Module.
	 *
	 * @var SpeedModule
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param Plugin      $plugin Plugin.
	 * @param SpeedModule $module Module.
	 */
	public function __construct( Plugin $plugin, SpeedModule $module ) {
		parent::__construct( $plugin );
		$this->module = $module;
	}

	/** {@inheritDoc} */
	public function register_routes(): void {
		$ns   = Plugin::REST_NAMESPACE;
		$perm = array( $this, 'can_manage' );

		register_rest_route(
			$ns,
			'/speed/runs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_runs' ),
					'permission_callback' => $perm,
					'args'                => $this->pagination_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_run' ),
					'permission_callback' => $perm,
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			$ns,
			'/speed/runs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
		$id_arg = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		register_rest_route(
			$ns,
			'/speed/runs/(?P<id>\d+)/next',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_next_tests' ),
				'permission_callback' => $perm,
				'args'                => $id_arg,
			)
		);
		foreach ( array(
			'advance'      => 'advance_run',
			'retry-failed' => 'retry_failed',
		) as $route => $callback ) {
			register_rest_route(
				$ns,
				'/speed/runs/(?P<id>\d+)/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $perm,
					'args'                => $id_arg,
				)
			);
		}
		$rid_arg = array(
			'rid' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		register_rest_route(
			$ns,
			// The PSI result JSON body has a top-level "id" key, so the path param is "rid" to avoid a clash.
			'/speed/results/(?P<rid>\d+)/ingest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest_result' ),
				'permission_callback' => $perm,
				'args'                => $rid_arg,
			)
		);
		register_rest_route(
			$ns,
			'/speed/results/(?P<rid>\d+)/report-failure',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'report_failure' ),
				'permission_callback' => $perm,
				'args'                => array(
					'rid'         => $rid_arg['rid'],
					'code'        => array(
						'type'    => 'string',
						'enum'    => array( 'quota', 'forbidden', 'network', 'timeout', 'server', 'unknown' ),
						'default' => 'unknown',
					),
					'http_status' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		foreach ( array(
			'overview'        => 'get_overview',
			'recommendations' => 'get_recommendations',
			'tweaks'          => 'get_tweaks',
			'cache'           => 'get_cache',
			'maintenance'     => 'get_maintenance',
		) as $route => $callback ) {
			register_rest_route(
				$ns,
				'/speed/' . $route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $perm,
					'args'                => array(),
				)
			);
		}
		register_rest_route(
			$ns,
			'/speed/tweaks/(?P<id>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_tweak' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id'      => array(
						'type' => 'string',
						'enum' => array_keys( Tweaks::definitions() ),
					),
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/speed/maintenance/(?P<action>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_cleanup' ),
				'permission_callback' => $perm,
				'args'                => array(
					'action'  => array(
						'type' => 'string',
						'enum' => self::CLEANUPS,
					),
					'confirm' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/speed/urls',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_urls' ),
					'permission_callback' => $perm,
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_urls' ),
					'permission_callback' => $perm,
					'args'                => array(
						'url_mode'     => array(
							'type' => 'string',
							'enum' => array( 'auto', 'custom' ),
						),
						'custom_urls'  => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'maxItems' => 50,
						),
						'strategies'   => array(
							'type' => 'string',
							'enum' => array( 'both', 'mobile', 'desktop' ),
						),
						'psi_schedule' => array(
							'type' => 'string',
							'enum' => array( 'daily', 'weekly', 'off' ),
						),
						'crux_enabled' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/speed/crux',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_crux' ),
					'permission_callback' => $perm,
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_crux' ),
					'permission_callback' => $perm,
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Runner.
	 *
	 * @return SpeedRunner
	 */
	private function runner(): SpeedRunner {
		return $this->module->runner();
	}

	/* ------------------------------------------------------------------ runs */

	/**
	 * POST speed/runs.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_run( WP_REST_Request $request ) {
		unset( $request );
		$run = $this->runner()->start_run( 'manual' );
		if ( is_wp_error( $run ) ) {
			return $this->respond( $run );
		}
		return $this->respond(
			array(
				'run'     => $run,
				'has_key' => null !== $this->runner()->api_key(),
			),
			201
		);
	}

	/**
	 * GET speed/runs.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_runs( WP_REST_Request $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$list     = $this->runner()->store()->runs( $page, $per_page );
		return $this->respond(
			array(
				'items'    => $list['items'],
				'total'    => $list['total'],
				'page'     => max( 1, $page ),
				'per_page' => max( 1, $per_page ),
			)
		);
	}

	/**
	 * GET speed/runs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_run( WP_REST_Request $request ) {
		$store = $this->runner()->store();
		$store->active_run(); // Closes stuck runs.
		$run = $store->get_run( (int) $request['id'] );
		if ( null === $run ) {
			return $this->respond( new WP_Error( 'seoc_not_found', __( 'Speed test not found.', 'seo-console' ), array( 'status' => 404 ) ) );
		}
		$results = array();
		foreach ( $store->results_for_run( $run['id'], true ) as $row ) {
			$results[] = self::result_card( $row );
		}
		return $this->respond(
			array(
				'run'     => $run,
				'results' => $results,
			)
		);
	}

	/**
	 * Result as sent to the UI (no raw PSI details).
	 *
	 * @param array $row Decoded result row.
	 * @return array
	 */
	public static function result_card( array $row ): array {
		$result = isset( $row['result'] ) && is_array( $row['result'] ) ? $row['result'] : array();
		$card   = $row;
		unset( $card['result'] );
		$card['lcp_element'] = null;
		if ( ! empty( $result['lcp_element'] ) ) {
			$el                  = $result['lcp_element'];
			$card['lcp_element'] = array(
				'type'        => $el['type'],
				'label'       => $el['label'],
				'selector'    => $el['selector'],
				'image_url'   => $el['image_url'],
				'lazy_loaded' => $el['lazy_loaded'],
			);
		}
		$card['field_url']    = isset( $result['field']['url'] ) ? $result['field']['url'] : null;
		$card['field_origin'] = isset( $result['field']['origin'] ) ? $result['field']['origin'] : null;
		$card['warnings']     = isset( $result['warnings'] ) ? $result['warnings'] : array();
		return $card;
	}

	/**
	 * The standard { run, results } payload for a run id.
	 *
	 * @param int $run_id Run id.
	 * @return array|WP_Error
	 */
	private function run_with_results( int $run_id ) {
		$store = $this->runner()->store();
		$run   = $store->get_run( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'seoc_not_found', __( 'Speed test not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		$results = array();
		foreach ( $store->results_for_run( $run_id, true ) as $row ) {
			$results[] = self::result_card( $row );
		}
		return array(
			'run'     => $run,
			'results' => $results,
		);
	}

	/**
	 * GET speed/runs/{id}/next — open tests for the browser runner, each with the keyless PSI URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_next_tests( WP_REST_Request $request ) {
		$next = $this->runner()->next_tests( (int) $request['id'] );
		if ( null === $next['run'] ) {
			return $this->respond( new WP_Error( 'seoc_not_found', __( 'Speed test not found.', 'seo-console' ), array( 'status' => 404 ) ) );
		}
		return $this->respond(
			array(
				'run'    => $next['run'],
				'tests'  => $next['tests'],
				'status' => $next['run']['status'],
			)
		);
	}

	/**
	 * POST speed/results/{id}/ingest — store a PSI result the browser fetched.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ingest_result( WP_REST_Request $request ) {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return $this->respond( new WP_Error( 'seoc_speed_bad_result', __( 'No result was sent.', 'seo-console' ), array( 'status' => 400 ) ) );
		}
		$result = $this->runner()->ingest_result( (int) $request['rid'], $raw );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond( $this->run_with_results( (int) $result['run_id'] ) );
	}

	/**
	 * POST speed/results/{id}/report-failure — the browser could not run a test.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function report_failure( WP_REST_Request $request ) {
		$result = $this->runner()->report_browser_failure( (int) $request['rid'], (string) $request['code'], (int) $request['http_status'] );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond(
			array(
				'result'    => self::result_card( $result ),
				'fall_back' => 'pending' === $result['status'],
				'run'       => $this->runner()->store()->get_run( (int) $result['run_id'] ),
			)
		);
	}

	/**
	 * POST speed/runs/{id}/advance — run one open test on the server (keyed fallback).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function advance_run( WP_REST_Request $request ) {
		$run_id  = (int) $request['id'];
		$result  = $this->runner()->advance_run( $run_id );
		$payload = $this->run_with_results( $run_id );
		if ( is_wp_error( $payload ) ) {
			return $this->respond( $payload );
		}
		$payload['advanced'] = $result['advanced'];
		return $this->respond( $payload );
	}

	/**
	 * POST speed/runs/{id}/retry-failed — re-queue failed tests of a run.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function retry_failed( WP_REST_Request $request ) {
		$run_id  = (int) $request['id'];
		$count   = $this->runner()->retry_failed( $run_id );
		$payload = $this->run_with_results( $run_id );
		if ( is_wp_error( $payload ) ) {
			return $this->respond( $payload );
		}
		$payload['retried'] = $count;
		return $this->respond( $payload );
	}

	/* -------------------------------------------------------------- overview */

	/**
	 * GET speed/overview.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_overview( WP_REST_Request $request ) {
		unset( $request );
		$runner   = $this->runner();
		$store    = $runner->store();
		$settings = $this->plugin->settings();
		$targets  = $runner->target_urls();
		$latest   = $store->latest_results( wp_list_pluck( $targets, 'url' ) );

		$pages = array();
		foreach ( $targets as $entry ) {
			$pages[ SpeedStore::url_hash( $entry['url'] ) ] = array(
				'url'     => $entry['url'],
				'label'   => $entry['label'],
				'type'    => $entry['type'],
				'mobile'  => null,
				'desktop' => null,
			);
		}
		$psi_origin = null;
		foreach ( $latest as $row ) {
			$key = SpeedStore::url_hash( $row['url'] );
			if ( isset( $pages[ $key ] ) ) {
				$pages[ $key ][ $row['strategy'] ] = self::result_card( $row );
			}
			if ( null === $psi_origin && 'mobile' === $row['strategy'] && ! empty( $row['result']['field']['origin'] ) ) {
				$psi_origin = $row['result']['field']['origin'];
			}
		}

		$origin  = PsiParser::origin( home_url( '/' ) );
		$phone   = $store->latest_crux( 'record', $origin, 'PHONE' );
		$desktop = $store->latest_crux( 'record', $origin, 'DESKTOP' );
		$h_phone = $store->latest_crux( 'history', $origin, 'PHONE' );
		$h_desk  = $store->latest_crux( 'history', $origin, 'DESKTOP' );

		$markers = array();
		$mobile  = $store->lab_trend( 'mobile' );
		foreach ( $mobile as $point ) {
			if ( 'change' === $point['trigger'] ) {
				$markers[] = array(
					'date'      => $point['date'],
					'change_id' => $point['change_id'],
					/* translators: %d: change id */
					'label'     => $point['change_id'] ? sprintf( __( 'Re-test after change #%d', 'seo-console' ), $point['change_id'] ) : __( 'Re-test after a change', 'seo-console' ),
				);
			}
		}
		$next = $this->plugin->queue()->next( SpeedRunner::HOOK_RUN );

		return $this->respond(
			array(
				'has_key'      => null !== $runner->api_key(),
				'schedule'     => (string) $settings->get( 'speed', 'psi_schedule' ),
				'strategies'   => $runner->strategies(),
				'last_run'     => $store->last_finished_run(),
				'active_run'   => $store->active_run(),
				'next_run'     => is_int( $next ) ? gmdate( 'Y-m-d\TH:i:s\Z', $next ) : null,
				'pages'        => array_values( $pages ),
				'field'        => array(
					'origin'     => $origin,
					'phone'      => $phone,
					'desktop'    => $desktop,
					'psi_origin' => $psi_origin,
				),
				'trend'        => array(
					'mobile'        => $mobile,
					'desktop'       => $store->lab_trend( 'desktop' ),
					'field_phone'   => $h_phone,
					'field_desktop' => $h_desk,
					'markers'       => $markers,
				),
				'thresholds'   => Thresholds::LIMITS,
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
	}

	/* ------------------------------------------------------- recommendations */

	/**
	 * Recommendations across the latest result of every tested URL × strategy, merged and ranked.
	 *
	 * @return array[] Action + 'urls' => [ [ 'url', 'label', 'strategy', 'display_value', 'savings_ms' ] ], 'group' => fixer type|'none'.
	 */
	public function merged_recommendations(): array {
		$runner  = $this->runner();
		$targets = $runner->target_urls();
		$latest  = $runner->store()->latest_results( wp_list_pluck( $targets, 'url' ) );
		$context = array(
			'cache_audit'    => $this->module->cache_audit(),
			'enabled_tweaks' => $this->module->enabled_tweaks(),
		);
		$recs    = new Recommendations();
		$merged  = array();
		foreach ( $latest as $row ) {
			if ( empty( $row['result'] ) ) {
				continue;
			}
			foreach ( $recs->for_result( $row['result'], $context ) as $action ) {
				$id  = $action['id'];
				$ref = array(
					'url'           => $row['url'],
					'label'         => $row['label'],
					'strategy'      => $row['strategy'],
					'display_value' => $action['display_value'],
					'savings_ms'    => $action['savings_ms'],
				);
				if ( ! isset( $merged[ $id ] ) ) {
					$merged[ $id ]         = $action;
					$merged[ $id ]['urls'] = array();
				} elseif ( Recommendations::IMPACT_RANK[ $action['impact'] ] > Recommendations::IMPACT_RANK[ $merged[ $id ]['impact'] ] ) {
					$urls                  = $merged[ $id ]['urls'];
					$merged[ $id ]         = $action;
					$merged[ $id ]['urls'] = $urls;
				}
				$merged[ $id ]['urls'][] = $ref;
			}
		}
		foreach ( $merged as $id => $item ) {
			$merged[ $id ]['group']      = $item['fixer'] ? $item['fixer']['type'] : 'none';
			$merged[ $id ]['max_saving'] = max( array_map( 'intval', wp_list_pluck( $item['urls'], 'savings_ms' ) ) );
		}
		$merged = array_values( $merged );
		usort(
			$merged,
			static function ( $a, $b ) {
				$ra = Recommendations::IMPACT_RANK[ $a['impact'] ];
				$rb = Recommendations::IMPACT_RANK[ $b['impact'] ];
				if ( $ra !== $rb ) {
					return $rb <=> $ra;
				}
				if ( count( $a['urls'] ) !== count( $b['urls'] ) ) {
					return count( $b['urls'] ) <=> count( $a['urls'] );
				}
				return $b['max_saving'] <=> $a['max_saving'];
			}
		);
		return $merged;
	}

	/**
	 * GET speed/recommendations.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_recommendations( WP_REST_Request $request ) {
		unset( $request );
		$items  = $this->merged_recommendations();
		$counts = array(
			'seo-console'  => 0,
			'cache-plugin' => 0,
			'developer'    => 0,
			'hosting'      => 0,
			'none'         => 0,
		);
		foreach ( $items as $item ) {
			++$counts[ $item['group'] ];
		}
		$audit = $this->module->cache_audit();
		return $this->respond(
			array(
				'items'        => $items,
				'counts'       => $counts,
				'cache_plugin' => $audit ? array(
					'id'   => $audit->plugin(),
					'name' => $audit->plugin_name(),
				) : null,
				'has_results'  => $this->runner()->store()->has_results(),
			)
		);
	}

	/* ---------------------------------------------------------------- tweaks */

	/**
	 * Tweak list with state, skip reasons and last change.
	 *
	 * @return array
	 */
	public function tweaks_state(): array {
		$settings = $this->plugin->settings();
		$features = $this->module->cache_features();
		$config   = $this->module->tweak_config();
		$audit    = $this->module->cache_audit();
		$changes  = $this->plugin->changes();
		$last     = array();
		$query    = $changes->query(
			array(
				'module'   => 'speed',
				'type'     => 'module_setting',
				'per_page' => 100,
			)
		);
		foreach ( $query['items'] as $change ) {
			if ( ! isset( $last[ $change->field ] ) ) {
				$rest                   = $changes->to_rest( $change );
				$last[ $change->field ] = array(
					'id'         => $rest['id'],
					'created_at' => $rest['created_at'],
					'status'     => $rest['status'],
					'user_name'  => $rest['user_name'],
					'after'      => $rest['after_value'],
					'can_revert' => ! empty( $rest['can_revert'] ),
				);
			}
		}

		$out = array();
		foreach ( Tweaks::definitions() as $id => $def ) {
			$key     = SpeedModule::tweak_key( $id );
			$enabled = (bool) $settings->get( 'speed', $key );
			$reason  = null;
			if ( $def['cache_feature'] && ! empty( $features[ $def['cache_feature'] ] ) ) {
				$reason = 'cache_plugin';
			} elseif ( 'lcp_boost' === $id && empty( $config['lcp_images'] ) ) {
				$reason = 'missing_config';
			} elseif ( 'preconnect' === $id && empty( Tweaks::clean_origins( (array) $config['preconnect_origins'] ) ) ) {
				$reason = 'missing_config';
			} elseif ( 'disable_xmlrpc' === $id && class_exists( 'Jetpack' ) ) {
				$reason = 'conflict';
			}
			$field = 'speed.' . $key;
			$out[] = array_merge(
				$def,
				array(
					'enabled'        => $enabled,
					'setting'        => $field,
					'active'         => $enabled && null === $reason && ! $this->plugin->safe_mode(),
					'skipped_reason' => $reason,
					'skipped_note'   => self::skip_note( $reason, $audit ? $audit->plugin_name() : '' ),
					'last_change'    => isset( $last[ $field ] ) ? $last[ $field ] : null,
				)
			);
		}
		$runtime = SpeedRunner::runtime();
		return array(
			'tweaks'       => $out,
			'cache_plugin' => $audit ? array(
				'id'   => $audit->plugin(),
				'name' => $audit->plugin_name(),
			) : null,
			'safe_mode'    => $this->plugin->safe_mode(),
			'runtime'      => array(
				'updated_at' => $runtime['updated_at'],
				'lcp_images' => $runtime['lcp_images'],
				'preconnect' => Tweaks::clean_origins( (array) $config['preconnect_origins'] ),
			),
		);
	}

	/**
	 * Plain-English skip note.
	 *
	 * @param string|null $reason Reason.
	 * @param string      $plugin Cache plugin name.
	 * @return string
	 */
	private static function skip_note( $reason, string $plugin ): string {
		switch ( $reason ) {
			case 'cache_plugin':
				/* translators: %s: cache plugin name */
				return sprintf( __( '%s already does this, so SEO Console skips it to avoid doing the same work twice.', 'seo-console' ), '' !== $plugin ? $plugin : __( 'Your cache plugin', 'seo-console' ) );
			case 'missing_config':
				return __( 'Waiting for data: run a speed test first so SEO Console knows which image or sites to use.', 'seo-console' );
			case 'conflict':
				return __( 'Skipped because Jetpack needs XML-RPC.', 'seo-console' );
		}
		return '';
	}

	/**
	 * GET speed/tweaks.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_tweaks( WP_REST_Request $request ) {
		unset( $request );
		return $this->respond( $this->tweaks_state() );
	}

	/**
	 * POST speed/tweaks/{id} { enabled } → ChangeManager (module_setting): logged, health-checked, undoable.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_tweak( WP_REST_Request $request ) {
		$id   = (string) $request['id'];
		$defs = Tweaks::definitions();
		if ( ! isset( $defs[ $id ] ) ) {
			return $this->respond( new WP_Error( 'seoc_not_found', __( 'Unknown speed tweak.', 'seo-console' ), array( 'status' => 404 ) ) );
		}
		$enabled = (bool) $request['enabled'];
		$field   = 'speed.' . SpeedModule::tweak_key( $id );
		$change  = null;
		if ( (bool) $this->plugin->settings()->get( 'speed', SpeedModule::tweak_key( $id ) ) !== $enabled ) {
			$result = $this->plugin->changes()->apply(
				new ChangeRequest(
					'module_setting',
					'site',
					null,
					$field,
					$enabled,
					array(
						'source' => 'user',
						'module' => 'speed',
						/* translators: 1: tweak title, 2: on|off */
						'note'   => sprintf( __( 'Speed tweak "%1$s" turned %2$s.', 'seo-console' ), $defs[ $id ]['title'], $enabled ? __( 'on', 'seo-console' ) : __( 'off', 'seo-console' ) ),
					)
				)
			);
			if ( is_wp_error( $result ) ) {
				return $this->respond( $result );
			}
			$change = $this->plugin->changes()->to_rest( $result );
		}
		$state = $this->tweaks_state();
		$tweak = null;
		foreach ( $state['tweaks'] as $item ) {
			if ( $item['id'] === $id ) {
				$tweak = $item;
			}
		}
		return $this->respond(
			array(
				'tweak'  => $tweak,
				'change' => $change,
			)
		);
	}

	/* ----------------------------------------------------------------- cache */

	/**
	 * GET speed/cache.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_cache( WP_REST_Request $request ) {
		unset( $request );
		$detected = $this->plugin->cache()->detect();
		$audit    = $this->module->cache_audit();
		$note     = '';
		if ( empty( $detected ) ) {
			$note = __( 'No cache plugin was detected. A page cache usually makes the biggest speed difference: use LiteSpeed Cache on LiteSpeed servers, or the plugin your host recommends.', 'seo-console' );
		} elseif ( null === $audit ) {
			$note = __( 'SEO Console can check the settings of LiteSpeed Cache, WP Rocket and SiteGround Speed Optimizer. Your cache plugin is detected, but its settings cannot be checked yet.', 'seo-console' );
		}
		return $this->respond(
			array(
				'detected' => $detected,
				'audited'  => $audit ? array(
					'id'   => $audit->plugin(),
					'name' => $audit->plugin_name(),
				) : null,
				'findings' => $audit ? $audit->audit() : array(),
				'features' => $audit ? $audit->features() : (object) array(),
				'note'     => $note,
			)
		);
	}

	/* ----------------------------------------------------------- maintenance */

	/**
	 * GET speed/maintenance.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_maintenance( WP_REST_Request $request ) {
		unset( $request );
		return $this->respond( ( new Maintenance() )->report() );
	}

	/**
	 * POST speed/maintenance/{action} { confirm: true }.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function run_cleanup( WP_REST_Request $request ) {
		$action = (string) $request['action'];
		if ( ! in_array( $action, self::CLEANUPS, true ) ) {
			return $this->respond( new WP_Error( 'seoc_not_found', __( 'Unknown cleanup.', 'seo-console' ), array( 'status' => 404 ) ) );
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->respond(
				new WP_Error(
					'seoc_confirm_required',
					__( 'This cleanup permanently deletes data and cannot be undone. Confirm to continue.', 'seo-console' ),
					array( 'status' => 400 )
				)
			);
		}
		$maintenance = new Maintenance();
		switch ( $action ) {
			case 'expired_transients':
				$result = $maintenance->cleanup_expired_transients();
				break;
			case 'action_scheduler':
				$result = $maintenance->cleanup_action_scheduler( 30 );
				break;
			default:
				$result = $maintenance->cleanup_orphaned_postmeta();
		}
		$this->plugin->logger()->info( 'Database cleanup.', $result, 'speed' );
		return $this->respond(
			array(
				'result' => $result,
				'report' => $maintenance->report(),
			)
		);
	}

	/* ------------------------------------------------------------------ urls */

	/**
	 * GET speed/urls shape.
	 *
	 * @return array
	 */
	private function urls_state(): array {
		$settings = $this->plugin->settings();
		return array(
			'url_mode'     => (string) $settings->get( 'speed', 'url_mode' ),
			'auto'         => ( new UrlPicker( $this->plugin->seo()->active() ) )->pick(),
			'custom_urls'  => (array) $settings->get( 'speed', 'custom_urls' ),
			'effective'    => $this->runner()->target_urls(),
			'strategies'   => (string) $settings->get( 'speed', 'strategies' ),
			'psi_schedule' => (string) $settings->get( 'speed', 'psi_schedule' ),
			'crux_enabled' => (bool) $settings->get( 'speed', 'crux_enabled' ),
			'max_custom'   => UrlPicker::MAX_USER,
			'has_key'      => null !== $this->runner()->api_key(),
		);
	}

	/**
	 * GET speed/urls.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_urls( WP_REST_Request $request ) {
		unset( $request );
		return $this->respond( $this->urls_state() );
	}

	/**
	 * POST speed/urls. Custom URLs must be on this site (max 10); nothing is saved when one is rejected.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_urls( WP_REST_Request $request ) {
		$values = array();
		foreach ( array( 'url_mode', 'strategies', 'psi_schedule', 'crux_enabled' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$values[ $key ] = $request->get_param( $key );
			}
		}
		if ( null !== $request->get_param( 'custom_urls' ) ) {
			$check = ( new UrlPicker( $this->plugin->seo()->active() ) )->validate( (array) $request->get_param( 'custom_urls' ) );
			if ( ! empty( $check['errors'] ) ) {
				return $this->respond(
					new WP_Error(
						'seoc_invalid_urls',
						__( 'Some addresses cannot be tested. Nothing was saved.', 'seo-console' ),
						array(
							'status' => 400,
							'errors' => $check['errors'],
						)
					)
				);
			}
			$values['custom_urls'] = $check['valid'];
		}
		if ( isset( $values['url_mode'] ) && 'custom' === $values['url_mode'] ) {
			$custom = isset( $values['custom_urls'] ) ? $values['custom_urls'] : (array) $this->plugin->settings()->get( 'speed', 'custom_urls' );
			if ( empty( $custom ) ) {
				return $this->respond( new WP_Error( 'seoc_invalid_urls', __( 'Add at least one page before switching to your own list.', 'seo-console' ), array( 'status' => 400 ) ) );
			}
		}
		if ( $values ) {
			$saved = $this->plugin->settings()->update( 'speed', $values );
			if ( is_wp_error( $saved ) ) {
				return $this->respond( $saved );
			}
			if ( isset( $values['psi_schedule'] ) || isset( $values['crux_enabled'] ) ) {
				$this->module->ensure_jobs();
			}
		}
		return $this->respond( $this->urls_state() );
	}

	/* ------------------------------------------------------------------ crux */

	/**
	 * GET speed/crux shape.
	 *
	 * @return array
	 */
	private function crux_state(): array {
		$store  = $this->runner()->store();
		$origin = PsiParser::origin( home_url( '/' ) );
		$urls   = array();
		foreach ( $this->runner()->target_urls() as $entry ) {
			$urls[] = array(
				'url'     => $entry['url'],
				'label'   => $entry['label'],
				'phone'   => $store->latest_crux( 'record', $entry['url'], 'PHONE' ),
				'desktop' => $store->latest_crux( 'record', $entry['url'], 'DESKTOP' ),
			);
		}
		return array(
			'has_key' => null !== $this->runner()->api_key(),
			'enabled' => (bool) $this->plugin->settings()->get( 'speed', 'crux_enabled' ),
			'origin'  => array(
				'id'      => $origin,
				'phone'   => $store->latest_crux( 'record', $origin, 'PHONE' ),
				'desktop' => $store->latest_crux( 'record', $origin, 'DESKTOP' ),
			),
			'urls'    => $urls,
			'history' => array(
				'phone'   => $store->latest_crux( 'history', $origin, 'PHONE' ),
				'desktop' => $store->latest_crux( 'history', $origin, 'DESKTOP' ),
			),
		);
	}

	/**
	 * GET speed/crux.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_crux( WP_REST_Request $request ) {
		unset( $request );
		return $this->respond( $this->crux_state() );
	}

	/**
	 * POST speed/crux: fetch real-user data now (history only when none is stored yet).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function refresh_crux( WP_REST_Request $request ) {
		unset( $request );
		$result = $this->runner()->fetch_crux();
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$this->runner()->fetch_crux_history();
		return $this->respond( $this->crux_state() );
	}
}
