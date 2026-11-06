<?php
/**
 * REST routes of the Audit module under seo-console/v1/audit/… (permission manage_options).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

use SEOConsole\Core\ObjectInfo;
use SEOConsole\Modules\Audit\Rules\Rule;
use SEOConsole\Modules\Audit\Rules\Rules;
use SEOConsole\Plugin;
use SEOConsole\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Audit REST controller.
 */
class AuditController extends Controller {

	/** Seconds of inline work when a run is started or advanced from the admin page. */
	const START_BUDGET   = 10.0;
	const ADVANCE_BUDGET = 5.0;

	/** @var AuditModule */
	private $module;

	/** @var AuditStore */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Plugin      $plugin Plugin.
	 * @param AuditModule $module Module.
	 */
	public function __construct( Plugin $plugin, AuditModule $module ) {
		parent::__construct( $plugin );
		$this->module = $module;
		$this->store  = $module->store();
	}

	/** {@inheritDoc} */
	public function register_routes(): void {
		$ns  = Plugin::REST_NAMESPACE;
		$id  = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$can = array( $this, 'can_manage' );

		register_rest_route(
			$ns,
			'/audit/runs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_runs' ),
					'permission_callback' => $can,
					'args'                => $this->pagination_args(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start_run' ),
					'permission_callback' => $can,
				),
			)
		);
		register_rest_route(
			$ns,
			'/audit/runs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$ns,
			'/audit/runs/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_run' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$ns,
			'/audit/runs/(?P<id>\d+)/advance',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'advance_run' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$ns,
			'/audit/runs/(?P<id>\d+)/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_issues' ),
				'permission_callback' => $can,
				'args'                => array_merge(
					$id,
					$this->pagination_args(),
					array(
						'rule_id'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'severity' => array(
							'type' => 'string',
							'enum' => Rule::SEVERITIES,
						),
						'category' => array(
							'type' => 'string',
							'enum' => Rule::CATEGORIES,
						),
						'group'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/audit/runs/(?P<id>\d+)/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_pages' ),
				'permission_callback' => $can,
				'args'                => array_merge(
					$id,
					$this->pagination_args(),
					array(
						'indexability' => array(
							'type' => 'string',
							'enum' => array( 'indexable', 'noindex', 'canonicalised', 'redirect', 'client_error', 'server_error', 'blocked', 'not_html', 'host_variant', 'error', 'not_ok' ),
						),
						'status'       => array(
							'type'    => 'string',
							'pattern' => '^([1-5]xx|error|\d{3})$',
						),
						'has_issue'    => array( 'type' => 'boolean' ),
						'search'       => array(
							'type'              => 'string',
							'maxLength'         => 200,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'      => array(
							'type'    => 'string',
							'enum'    => array( 'url', 'status_code', 'depth', 'inbound_internal', 'word_count', 'issues', 'response_ms' ),
							'default' => 'url',
						),
						'order'        => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'asc',
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/audit/pages/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_page' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$ns,
			'/audit/robots',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_robots' ),
				'permission_callback' => $can,
				'args'                => array(
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/audit/robots/test',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'test_robots' ),
				'permission_callback' => $can,
				'args'                => array(
					'url'        => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
					'user_agent' => array(
						'type'    => 'string',
						'enum'    => array( 'Googlebot', 'Googlebot-Image', 'Bingbot', '*' ),
						'default' => 'Googlebot',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/audit/sitemaps',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sitemaps' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$ns,
			'/audit/ai-fixes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ai_fixes' ),
				'permission_callback' => $can,
				'args'                => array(
					'run_id'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'rule_ids' => array(
						'type'    => 'array',
						'items'   => array(
							'type' => 'string',
							'enum' => array_keys( Report::AI_FIELDS ),
						),
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/audit/rules',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_rules' ),
				'permission_callback' => $can,
			)
		);
	}

	/* ------------------------------------------------------------------ Runs */

	/**
	 * GET /audit/runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_runs( WP_REST_Request $request ) {
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$items    = array_map( array( $this, 'run_item' ), $this->store->list_runs( $per_page, '', ( $page - 1 ) * $per_page ) );
		$total    = $this->store->count_runs();
		$response = $this->respond(
			array(
				'items'         => $items,
				'total'         => $total,
				'page'          => $page,
				'per_page'      => $per_page,
				'active_run_id' => $this->store->active_run_id() ? $this->store->active_run_id() : null,
			)
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * POST /audit/runs: start + first step inline.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_run() {
		$progress = $this->module->start_run( self::START_BUDGET );
		if ( is_wp_error( $progress ) ) {
			return $this->respond( $progress );
		}
		return $this->respond( $this->run_payload( (int) $progress['run_id'], false ), 201 );
	}

	/**
	 * GET /audit/runs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_run( WP_REST_Request $request ) {
		$run_id = (int) $request['id'];
		if ( ! $this->store->get_run( $run_id ) ) {
			return $this->not_found();
		}
		return $this->respond( $this->run_payload( $run_id, true ) );
	}

	/**
	 * POST /audit/runs/{id}/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_run( WP_REST_Request $request ) {
		$run_id = (int) $request['id'];
		$run    = $this->store->get_run( $run_id );
		if ( ! $run ) {
			return $this->not_found();
		}
		if ( ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return $this->respond( new WP_Error( 'seoc_audit_not_running', __( 'This audit is not running.', 'seo-console' ), array( 'status' => 409 ) ) );
		}
		$this->module->cancel_run( $run_id );
		return $this->respond( $this->run_payload( $run_id, false ) );
	}

	/**
	 * POST /audit/runs/{id}/advance: a short inline step while the admin page watches (background jobs on shared
	 * hosting may only run when someone visits the site). Does nothing while another step is running.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function advance_run( WP_REST_Request $request ) {
		$run_id = (int) $request['id'];
		if ( ! $this->store->get_run( $run_id ) ) {
			return $this->not_found();
		}
		/**
		 * Filters the seconds of inline work per "advance" request from the admin page.
		 *
		 * @param float $seconds Seconds (default 5).
		 */
		$budget   = (float) apply_filters( 'seoc_audit_advance_budget', self::ADVANCE_BUDGET );
		$progress = $this->module->step_run( $run_id, max( 0.5, $budget ) );
		$payload  = $this->run_payload( $run_id, false );

		$payload['advanced'] = null !== $progress;
		return $this->respond( $payload );
	}

	/**
	 * { run, progress, summary, diff }.
	 *
	 * @param int  $run_id    Run id.
	 * @param bool $with_diff Include the diff vs the previous finished run.
	 */
	private function run_payload( int $run_id, bool $with_diff ): array {
		$run      = $this->store->get_run( $run_id );
		$progress = $this->module->crawler()->progress( $run_id );
		$summary  = $run && $run['summary'] ? $run['summary'] : null;
		$diff     = null;
		if ( $with_diff && $run && 'done' === $run['status'] ) {
			$diff = $this->module->report()->diff( $run_id );
			if ( ! $diff['previous_run_id'] ) {
				$diff = null;
			}
		}
		return array(
			'run'      => $this->run_item( $run ),
			'progress' => array(
				'phase'         => $progress['phase'],
				'pages_found'   => $progress['pages_found'],
				'pages_crawled' => $progress['pages_crawled'],
				'max_pages'     => $progress['max_pages'],
				'checked'       => $progress['checked'],
				'to_check'      => $progress['to_check'],
				'done'          => $progress['done'],
			),
			'summary'  => $summary,
			'diff'     => $diff,
		);
	}

	/**
	 * Run item for REST.
	 *
	 * @param array|null $run Run row.
	 */
	private function run_item( $run ): ?array {
		if ( ! $run ) {
			return null;
		}
		$summary = isset( $run['summary'] ) && is_array( $run['summary'] ) ? $run['summary'] : array();
		return array(
			'id'            => (int) $run['id'],
			'status'        => (string) $run['status'],
			'phase'         => (string) $run['phase'],
			'created_at'    => ObjectInfo::iso_date( $run['created_at'] ?? null ),
			'updated_at'    => ObjectInfo::iso_date( $run['updated_at'] ?? null ),
			'started_at'    => ObjectInfo::iso_date( $run['started_at'] ?? null ),
			'finished_at'   => ObjectInfo::iso_date( $run['finished_at'] ?? null ),
			'pages_found'   => (int) $run['pages_found'],
			'pages_crawled' => (int) $run['pages_crawled'],
			'score'         => null === $run['score'] ? null : (int) $run['score'],
			'error'         => (string) $run['error'],
			'issues'        => isset( $summary['by_severity'] ) ? $summary['by_severity'] : null,
		);
	}

	/* ---------------------------------------------------------------- Issues */

	/**
	 * GET /audit/runs/{id}/issues (group=true: one row per rule).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_issues( WP_REST_Request $request ) {
		$run_id = (int) $request['id'];
		if ( ! $this->store->get_run( $run_id ) ) {
			return $this->not_found();
		}
		$rules = Rules::all();
		if ( $request['group'] ) {
			$by_rule = array();
			foreach ( $this->store->issue_counts( $run_id ) as $row ) {
				if ( ( $request['severity'] && $request['severity'] !== $row['severity'] ) || ( $request['category'] && $request['category'] !== $row['category'] ) ) {
					continue;
				}
				$meta                       = isset( $rules[ $row['rule_id'] ] ) ? $rules[ $row['rule_id'] ]->to_array() : array(
					'id'         => $row['rule_id'],
					'title'      => $row['rule_id'],
					'why'        => '',
					'how_to_fix' => '',
					'fixable_by' => null,
					'scope'      => 'page',
				);
				$by_rule[ $row['rule_id'] ] = array(
					'count'      => $row['n'],
					'severity'   => $row['severity'],
					'category'   => $row['category'],
					'title'      => $meta['title'],
					'why'        => $meta['why'],
					'how_to_fix' => $meta['how_to_fix'],
					'fixable_by' => $meta['fixable_by'],
					'scope'      => $meta['scope'],
				);
			}
			$groups = Report::top_issues( $by_rule, count( $by_rule ) );
			return $this->respond(
				array(
					'groups' => $groups,
					'total'  => count( $groups ),
				)
			);
		}

		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$result   = $this->store->issues(
			$run_id,
			array(
				'rule_id'  => (string) $request['rule_id'],
				'severity' => (string) $request['severity'],
				'category' => (string) $request['category'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$response = $this->respond(
			array(
				'items'    => array_map( array( $this, 'issue_item' ), $result['items'] ),
				'total'    => $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * Issue item for REST.
	 *
	 * @param array $issue Issue from AuditStore::issues().
	 */
	private function issue_item( array $issue ): array {
		$links = self::post_links( (int) $issue['post_id'] );
		return array(
			'id'        => $issue['id'],
			'rule_id'   => $issue['rule_id'],
			'severity'  => $issue['severity'],
			'category'  => $issue['category'],
			'page_id'   => $issue['page_id'],
			'url'       => $issue['url'],
			'post_id'   => $issue['post_id'] > 0 ? $issue['post_id'] : null,
			'edit_link' => $links['edit_link'],
			'data'      => (object) $issue['data'],
		);
	}

	/**
	 * Edit/title of a post (empty when none or not editable by the user).
	 *
	 * @param int $post_id Post id.
	 * @return array{edit_link: string, object_title: string}
	 */
	private static function post_links( int $post_id ): array {
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'edit_link'    => '',
				'object_title' => '',
			);
		}
		$info = ObjectInfo::describe( 'post', $post_id );
		return array(
			'edit_link'    => $info['edit_link'],
			'object_title' => $info['object_title'],
		);
	}

	/* ----------------------------------------------------------------- Pages */

	/**
	 * GET /audit/runs/{id}/pages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_pages( WP_REST_Request $request ) {
		$run_id = (int) $request['id'];
		if ( ! $this->store->get_run( $run_id ) ) {
			return $this->not_found();
		}
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$result   = $this->store->query_pages(
			$run_id,
			array(
				'indexability' => (string) $request['indexability'],
				'status'       => (string) $request['status'],
				'has_issue'    => null === $request['has_issue'] ? null : (bool) $request['has_issue'],
				'search'       => (string) $request['search'],
				'orderby'      => (string) $request['orderby'],
				'order'        => (string) $request['order'],
				'page'         => $page,
				'per_page'     => $per_page,
			)
		);
		$items    = array();
		foreach ( $result['items'] as $row ) {
			$links   = self::post_links( (int) $row['post_id'] );
			$items[] = array(
				'id'               => $row['id'],
				'url'              => (string) $row['url'],
				'crawl_state'      => (string) $row['crawl_state'],
				'status_code'      => $row['status_code'],
				'final_url'        => (string) $row['final_url'],
				'indexability'     => (string) $row['indexability'],
				'indexable'        => (bool) $row['indexable'],
				'title'            => null === $row['title'] ? null : (string) $row['title'],
				'word_count'       => $row['word_count'],
				'depth'            => $row['depth'],
				'inbound_internal' => $row['inbound_internal'],
				'in_sitemap'       => (bool) $row['in_sitemap'],
				'response_ms'      => $row['response_ms'],
				'issues'           => $row['issues'],
				'post_id'          => $row['post_id'] > 0 ? $row['post_id'] : null,
				'edit_link'        => $links['edit_link'],
			);
		}
		$response = $this->respond(
			array(
				'items'    => $items,
				'total'    => $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * GET /audit/pages/{id}: every fact, issues, inbound and outbound internal links.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( WP_REST_Request $request ) {
		$page = $this->store->get_page( (int) $request['id'] );
		if ( ! $page ) {
			return $this->not_found();
		}
		$run_id = (int) $page['run_id'];
		$links  = self::post_links( (int) $page['post_id'] );
		unset( $page['url_hash'] );
		$page['edit_link']    = $links['edit_link'];
		$page['object_title'] = $links['object_title'];
		$page['crawled_at']   = ObjectInfo::iso_date( $page['crawled_at'] );
		$page['extra']        = (object) $page['extra'];
		$page['og']           = (object) $page['og'];

		$issues   = $this->store->issues(
			$run_id,
			array(
				'page_id'  => (int) $page['id'],
				'per_page' => 200,
			)
		);
		$outbound = array();
		$targets  = $this->store->internal_links_from( $run_id, array( (int) $page['id'] ) );
		foreach ( array_slice( isset( $targets[ $page['id'] ] ) ? $targets[ $page['id'] ] : array(), 0, 200 ) as $link ) {
			$target     = $this->store->get_page_by_url( $run_id, (string) $link['to_url'] );
			$outbound[] = array(
				'url'         => (string) $link['to_url'],
				'anchor'      => (string) $link['anchor'],
				'nofollow'    => (bool) $link['nofollow'],
				'status_code' => $target ? $target['status_code'] : null,
				'crawl_state' => $target ? (string) $target['crawl_state'] : 'not_crawled',
				'target_id'   => $target ? (int) $target['id'] : null,
			);
		}
		return $this->respond(
			array(
				'page'     => $page,
				'issues'   => array_map( array( $this, 'issue_item' ), $issues['items'] ),
				'inbound'  => $this->store->linking_pages( $run_id, (string) $page['url'], 100 ),
				'outbound' => $outbound,
			)
		);
	}

	/* ---------------------------------------------------------------- Robots */

	/**
	 * The newest run whose state has a key (robots/sitemap).
	 *
	 * @param string $key State key.
	 */
	private function latest_with_state( string $key ): ?array {
		foreach ( $this->store->list_runs( 10 ) as $row ) {
			$run = $this->store->get_run( (int) $row['id'] );
			if ( $run && ! empty( $run['state'][ $key ] ) ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * Check item for REST.
	 *
	 * @param array $check [ 'rule', 'data' ].
	 */
	private static function check_item( array $check ): array {
		$rule = Rules::get( (string) $check['rule'] );
		return array(
			'rule_id'    => (string) $check['rule'],
			'severity'   => $rule ? $rule->severity : 'notice',
			'title'      => $rule ? $rule->title : (string) $check['rule'],
			'why'        => $rule ? $rule->why : '',
			'how_to_fix' => $rule ? $rule->how_to_fix : '',
			'fixable_by' => $rule ? $rule->fixable_by : null,
			'data'       => (object) ( isset( $check['data'] ) ? (array) $check['data'] : array() ),
		);
	}

	/**
	 * GET /audit/robots: latest analysis (from the newest run, or live when none / fresh=true).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_robots( WP_REST_Request $request ) {
		$run    = $request['fresh'] ? null : $this->latest_with_state( 'robots' );
		$checks = array();
		if ( $run ) {
			$robots = (array) $run['state']['robots'];
			foreach ( (array) ( $run['state']['checks'] ?? array() ) as $check ) {
				if ( 0 === strpos( (string) $check['rule'], 'robots_' ) ) {
					$checks[] = $check;
				}
			}
		} else {
			$robots = ( new RobotsAnalyzer() )->analyze();
			$checks = $robots['checks'];
			unset( $robots['checks'] );
		}
		$manager = RobotsAnalyzer::manager();
		return $this->respond(
			array(
				'run_id'      => $run ? (int) $run['id'] : null,
				'analysed_at' => $run ? ObjectInfo::iso_date( $run['started_at'] ? $run['started_at'] : $run['created_at'] ) : gmdate( 'Y-m-d\TH:i:s\Z' ),
				'live'        => null === $run,
				'robots'      => array(
					'url'           => ( new Url() )->root_url( '/robots.txt' ),
					'manager'       => $manager,
					'manager_label' => RobotsAnalyzer::manager_label( $manager ),
					'edit_url'      => RobotsAnalyzer::edit_url( $manager ),
					'advice'        => RobotsAnalyzer::advice( $manager ),
					'source'        => (string) ( $robots['source'] ?? '' ),
					'status'        => (int) ( $robots['status'] ?? 0 ),
					'exists'        => ! empty( $robots['exists'] ),
					'content'       => (string) ( $robots['content'] ?? '' ),
					'sitemaps'      => array_values( (array) ( $robots['sitemaps'] ?? array() ) ),
					'groups'        => array_values( (array) ( $robots['groups'] ?? array() ) ),
				),
				'checks'      => array_map( array( __CLASS__, 'check_item' ), $checks ),
			)
		);
	}

	/**
	 * GET /audit/robots/test?url=&user_agent=: whether a URL may be crawled (no request is made).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_robots( WP_REST_Request $request ) {
		$scope = new Url();
		$input = trim( (string) $request['url'] );
		$url   = '/' === substr( $input, 0, 1 ) ? $scope->root_url( $input ) : $scope->normalize( $input );
		if ( '' === $url || ! $scope->is_internal( $url ) ) {
			return $this->respond( new WP_Error( 'seoc_audit_external_url', __( 'Enter an address on this site, for example /shop/ or the full URL of a page.', 'seo-console' ), array( 'status' => 400 ) ) );
		}
		$run     = $this->latest_with_state( 'robots' );
		$content = $run ? (string) ( $run['state']['robots']['content'] ?? '' ) : (string) ( new RobotsAnalyzer() )->fetch()['content'];
		$robots  = RobotsTxt::parse( $content );
		$agent   = (string) $request['user_agent'];
		$rule    = $robots->matching_rule( $url, $agent );
		if ( $rule ) {
			unset( $rule['length'] );
		}
		return $this->respond(
			array(
				'url'        => $url,
				'path'       => Url::path_and_query( $url ),
				'user_agent' => $agent,
				'allowed'    => $robots->is_allowed( $url, $agent ),
				'rule'       => $rule,
				'source'     => $run ? 'run' : 'live',
			)
		);
	}

	/* -------------------------------------------------------------- Sitemaps */

	/**
	 * GET /audit/sitemaps: discovered files, validation checks and the crawl cross-check (finished runs).
	 *
	 * @return WP_REST_Response
	 */
	public function get_sitemaps() {
		$run = $this->latest_with_state( 'sitemap' );
		if ( ! $run ) {
			return $this->respond(
				array(
					'run_id'      => null,
					'sitemap'     => null,
					'checks'      => array(),
					'cross_check' => null,
				)
			);
		}
		$state  = (array) $run['state']['sitemap'];
		$checks = array();
		foreach ( (array) ( $run['state']['checks'] ?? array() ) as $check ) {
			if ( 0 === strpos( (string) $check['rule'], 'sitemap_' ) || 'robots_no_sitemap' === $check['rule'] ) {
				$checks[] = $check;
			}
		}
		$cross = null;
		if ( 'done' === $run['status'] ) {
			$pages = array();
			$after = 0;
			do {
				$chunk = $this->store->pages( (int) $run['id'], $after, 500, array( 'crawled', 'blocked', 'error' ) );
				$more  = 500 === count( $chunk );
				foreach ( $chunk as $page ) {
					$pages[] = $page;
					$after   = (int) $page['id'];
				}
			} while ( $more );
			$cross = array();
			foreach ( SitemapAnalyzer::cross_check( $pages, ! empty( $state['found'] ) ) as $key => $urls ) {
				$cross[ $key ] = array(
					'count' => count( $urls ),
					'urls'  => array_slice( $urls, 0, 100 ),
				);
			}
		}
		$files = array();
		foreach ( (array) ( $state['files'] ?? array() ) as $file ) {
			$files[] = array(
				'url'      => (string) $file['url'],
				'kind'     => (string) ( $file['kind'] ?? '' ),
				'type'     => (string) $file['type'],
				'status'   => (int) $file['status'],
				'count'    => (int) $file['count'],
				'problems' => (object) ( $file['problems'] ?? array() ),
			);
		}
		return $this->respond(
			array(
				'run_id'      => (int) $run['id'],
				'analysed_at' => ObjectInfo::iso_date( $run['started_at'] ? $run['started_at'] : $run['created_at'] ),
				'sitemap'     => array(
					'found'     => ! empty( $state['found'] ),
					'declared'  => ! empty( $state['declared'] ),
					'url_count' => (int) ( $state['url_count'] ?? 0 ),
					'capped'    => ! empty( $state['capped'] ),
					'files'     => $files,
					'tried'     => array_values( (array) ( $state['tried'] ?? array() ) ),
				),
				'checks'      => array_map( array( __CLASS__, 'check_item' ), $checks ),
				'cross_check' => $cross,
			)
		);
	}

	/* ------------------------------------------------------------ AI + rules */

	/**
	 * POST /audit/ai-fixes { run_id?, rule_ids? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_fixes( WP_REST_Request $request ) {
		$result = $this->module->request_ai_fixes( (int) $request['run_id'], array_map( 'strval', (array) $request['rule_ids'] ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$result['suggestions_url'] = admin_url( 'admin.php?page=seo-console-suggestions&module=meta' );
		return $this->respond( $result );
	}

	/**
	 * GET /audit/rules.
	 *
	 * @return WP_REST_Response
	 */
	public function get_rules() {
		return $this->respond(
			array(
				'rules'      => (object) Rules::describe(),
				'categories' => Rule::CATEGORIES,
				'severities' => Rule::SEVERITIES,
				'ai_fields'  => (object) Report::AI_FIELDS,
			)
		);
	}

	/** 404 error. */
	private function not_found(): WP_Error {
		return new WP_Error( 'seoc_not_found', __( 'This audit item no longer exists. Older audits are removed automatically.', 'seo-console' ), array( 'status' => 404 ) );
	}
}
