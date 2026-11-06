<?php
/**
 * Audit module: crawl + technical SEO audit + robots.txt + sitemap analysis (see docs/ARCHITECTURE.md §5).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

use SEOConsole\Jobs\TimeBudget;
use SEOConsole\Modules\AbstractModule;
use SEOConsole\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Module wiring: settings, tables, background jobs, REST, admin page and cross-module hooks.
 */
class AuditModule extends AbstractModule {

	const ID = 'audit';

	/** Recurring job (daily at night; skipped unless due per the `schedule` setting). */
	const START_HOOK = 'seoc_audit_start';

	/** One crawl step; args [ run_id, sequence ] (the sequence keeps each re-enqueue unique). */
	const STEP_HOOK = 'seoc_audit_step';

	/** Lock so only one step of a run executes at a time (job, REST "advance", start). */
	const LOCK_OPTION = 'seoc_audit_lock';

	/** Lock lifetime (seconds): longer than any step. */
	const LOCK_TTL = 90;

	/** A running run without progress for this long is considered dead and closed. */
	const STALE_SECONDS = 3600;

	/** Site-local time of the scheduled start. */
	const SCHEDULE_TIME = '01:40';

	/** Crawler setting keys (subset of the module settings). */
	const CRAWLER_KEYS = array( 'max_pages', 'max_depth', 'respect_robots', 'include_sitemaps', 'delay_ms', 'max_per_step', 'timeout', 'thin_content_words', 'slow_ms' );

	/** @var Plugin|null */
	private $plugin;

	/** @var AuditStore */
	private $store;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->store = new AuditStore();
	}

	/** {@inheritDoc} */
	public function id(): string {
		return self::ID;
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Site audit', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Crawls your site to find broken links, indexing mistakes, missing titles and robots.txt or sitemap problems.', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function settings_schema(): array {
		return array(
			'schedule'            => array(
				'type'    => 'enum',
				'default' => 'weekly',
				'enum'    => array( 'daily', 'weekly', 'off' ),
			),
			'max_pages'           => array(
				'type'    => 'int',
				'default' => 500,
				'min'     => 10,
				'max'     => 10000,
			),
			'max_depth'           => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 1,
				'max'     => 50,
			),
			'respect_robots'      => array(
				'type'    => 'bool',
				'default' => true,
			),
			'include_sitemaps'    => array(
				'type'    => 'bool',
				'default' => true,
			),
			'delay_ms'            => array(
				'type'    => 'int',
				'default' => 250,
				'min'     => 0,
				'max'     => 5000,
			),
			'max_per_step'        => array(
				'type'    => 'int',
				'default' => 50,
				'min'     => 1,
				'max'     => 500,
			),
			'timeout'             => array(
				'type'    => 'int',
				'default' => 15,
				'min'     => 3,
				'max'     => 30,
			),
			'thin_content_words'  => array(
				'type'    => 'int',
				'default' => 200,
				'min'     => 0,
				'max'     => 5000,
			),
			'slow_ms'             => array(
				'type'    => 'int',
				'default' => 2000,
				'min'     => 200,
				'max'     => 30000,
			),
			'keep_runs'           => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 50,
			),
			'auto_ai_suggestions' => array(
				'type'    => 'bool',
				'default' => false,
			),
		);
	}

	/** {@inheritDoc} */
	public function tables(): array {
		return AuditStore::schema();
	}

	/** {@inheritDoc} */
	public function admin_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console-audit',
				'title'    => __( 'Site audit', 'seo-console' ),
				'entry'    => 'audit',
				'position' => 25,
			),
		);
	}

	/** {@inheritDoc} */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_action( 'seoc_ensure_jobs', array( $this, 'ensure_jobs' ) );
		add_action( self::START_HOOK, array( $this, 'scheduled_start' ) );
		add_action( self::STEP_HOOK, array( $this, 'job_step' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_filter( 'seoc_ai_site_context', array( $this, 'site_context' ) );
		add_filter( 'seoc_dashboard_cards', array( $this, 'dashboard_card' ) );
		add_filter( 'seoc_checklist', array( $this, 'checklist' ) );
	}

	/** {@inheritDoc} */
	public function on_disable(): void {
		$queue = $this->plugin()->queue();
		$queue->unschedule( self::START_HOOK );
		$queue->unschedule( self::STEP_HOOK );
		$active = $this->store->active_run_id();
		if ( $active ) {
			$this->crawler()->cancel( $active );
		}
		delete_option( self::LOCK_OPTION );
	}

	/* -------------------------------------------------------------- Services */

	/** Plugin (set in register(); falls back to the instance). */
	public function plugin(): Plugin {
		return $this->plugin ? $this->plugin : Plugin::instance();
	}

	/** Store. */
	public function store(): AuditStore {
		return $this->store;
	}

	/** Report. */
	public function report(): Report {
		return new Report( $this->store );
	}

	/** Crawler for the site. */
	public function crawler(): Crawler {
		return new Crawler( $this->store );
	}

	/**
	 * A module setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function setting( string $key ) {
		return $this->plugin()->settings()->get( self::ID, $key );
	}

	/**
	 * Crawler settings from the module settings.
	 */
	public function crawler_settings(): array {
		$all = (array) $this->plugin()->settings()->all( self::ID );
		return Crawler::sanitize_settings( array_intersect_key( $all, array_flip( self::CRAWLER_KEYS ) ) );
	}

	/* ------------------------------------------------------------------ Runs */

	/**
	 * Starts a run unless one is active, does a first step inline, then hands over to background steps.
	 *
	 * @param float $inline_budget Seconds for the inline first step.
	 * @return array|WP_Error Progress (Crawler::progress()) or seoc_audit_running (409, data.run_id).
	 */
	public function start_run( float $inline_budget = 10.0 ) {
		$this->close_stale_run();
		$active = $this->store->active_run_id();
		if ( $active ) {
			return new WP_Error(
				'seoc_audit_running',
				__( 'An audit is already running. Wait for it to finish or cancel it first.', 'seo-console' ),
				array(
					'status' => 409,
					'run_id' => $active,
				)
			);
		}
		if ( ! $this->acquire_lock() ) {
			return new WP_Error(
				'seoc_audit_running',
				__( 'An audit is just starting. Try again in a minute.', 'seo-console' ),
				array( 'status' => 409 )
			);
		}
		try {
			$run_id = $this->crawler()->start( $this->crawler_settings(), max( 0.5, $inline_budget ) );
		} finally {
			$this->release_lock();
		}
		if ( ! $run_id ) {
			return new WP_Error( 'seoc_audit_db', __( 'The audit could not be saved. The database tables may be missing; deactivate and reactivate SEO Console.', 'seo-console' ), array( 'status' => 500 ) );
		}
		$progress = $this->crawler()->progress( $run_id );
		if ( $progress['done'] ) {
			$this->after_done( $run_id );
		} else {
			$this->schedule_step( $run_id, 1 );
		}
		return $progress;
	}

	/**
	 * Runs one step of a run under the lock.
	 *
	 * @param int   $run_id Run id.
	 * @param float $budget Seconds.
	 * @return array|null Progress, or null when another step holds the lock.
	 */
	public function step_run( int $run_id, float $budget ): ?array {
		$crawler = $this->crawler();
		$before  = $crawler->progress( $run_id );
		if ( $before['done'] ) {
			return $before;
		}
		if ( ! $this->acquire_lock() ) {
			return null;
		}
		try {
			$progress = $crawler->step( $run_id, $budget );
		} finally {
			$this->release_lock();
		}
		if ( $progress['done'] ) {
			$this->after_done( $run_id );
		}
		return $progress;
	}

	/**
	 * Background step (STEP_HOOK). Re-enqueues itself until the run is done.
	 *
	 * @param int $run_id   Run id.
	 * @param int $sequence Step number.
	 */
	public function job_step( $run_id = 0, $sequence = 0 ): void {
		$run_id = (int) $run_id;
		if ( $run_id <= 0 ) {
			return;
		}
		$budget   = new TimeBudget();
		$progress = $this->step_run( $run_id, max( 1.0, $budget->remaining() ) );
		if ( null === $progress ) {
			// Another step is running (e.g. the admin page is advancing it): try again shortly.
			$this->plugin()->queue()->schedule_single( self::STEP_HOOK, time() + 30, array( $run_id, (int) $sequence + 1 ) );
			return;
		}
		if ( ! $progress['done'] ) {
			$this->schedule_step( $run_id, (int) $sequence + 1 );
		}
	}

	/**
	 * Queues the next background step.
	 *
	 * @param int $run_id   Run id.
	 * @param int $sequence Step number.
	 */
	public function schedule_step( int $run_id, int $sequence ): void {
		$this->plugin()->queue()->enqueue( self::STEP_HOOK, array( $run_id, $sequence ) );
	}

	/**
	 * Cancels a run.
	 *
	 * @param int $run_id Run id.
	 */
	public function cancel_run( int $run_id ): void {
		$this->crawler()->cancel( $run_id );
		$this->plugin()->queue()->unschedule( self::STEP_HOOK );
	}

	/**
	 * After a run finished: keep the newest runs, optionally queue AI suggestions.
	 *
	 * @param int $run_id Run id.
	 */
	public function after_done( int $run_id ): void {
		$this->store->prune( (int) $this->setting( 'keep_runs' ) );
		$run = $this->store->get_run( $run_id );
		if ( $run && 'done' === $run['status'] && $this->setting( 'auto_ai_suggestions' ) && $this->ai_available() ) {
			$items = $this->report()->ai_items( $run_id );
			if ( $items ) {
				/** This action is documented in includes/Modules/Audit/AuditModule.php (request_ai_fixes). */
				do_action( 'seoc_request_ai_suggestions', $items, 'audit' );
			}
		}
	}

	/**
	 * A running run that has not moved for an hour (jobs lost, PHP killed) is closed as failed.
	 */
	public function close_stale_run(): void {
		$active = $this->store->active_run_id();
		if ( ! $active ) {
			return;
		}
		$run     = $this->store->get_run( $active );
		$updated = $run ? strtotime( (string) ( $run['updated_at'] ? $run['updated_at'] : $run['created_at'] ) . ' UTC' ) : 0;
		if ( $run && $updated && time() - $updated > self::STALE_SECONDS ) {
			$this->store->close_frontier( $active );
			$this->store->update_run(
				$active,
				array(
					'status'      => 'failed',
					'phase'       => 'done',
					'error'       => 'stalled',
					'finished_at' => AuditStore::now(),
				)
			);
		}
	}

	/**
	 * Lock (atomic add_option; an expired lock is taken over).
	 */
	private function acquire_lock(): bool {
		$expires = time() + self::LOCK_TTL;
		if ( add_option( self::LOCK_OPTION, $expires, '', false ) ) {
			return true;
		}
		$current = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $current < time() ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, $expires, '', false );
		}
		return false;
	}

	/** Releases the lock. */
	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/* ------------------------------------------------------------------ Jobs */

	/**
	 * seoc_ensure_jobs: schedules the nightly start unless the schedule is off.
	 */
	public function ensure_jobs(): void {
		$queue = $this->plugin()->queue();
		if ( 'off' === $this->setting( 'schedule' ) ) {
			$queue->unschedule( self::START_HOOK );
			return;
		}
		$queue->schedule_daily( self::START_HOOK, array(), self::SCHEDULE_TIME );
	}

	/**
	 * START_HOOK: starts a run when one is due (daily, or weekly = 6.5 days after the last one).
	 */
	public function scheduled_start(): void {
		$schedule = (string) $this->setting( 'schedule' );
		if ( 'off' === $schedule ) {
			return;
		}
		if ( 'weekly' === $schedule ) {
			$last = $this->store->list_runs( 1 );
			if ( $last && time() - (int) strtotime( $last[0]['created_at'] . ' UTC' ) < 6.5 * DAY_IN_SECONDS ) {
				return;
			}
		}
		$budget = new TimeBudget();
		$this->start_run( max( 1.0, $budget->remaining() ) );
	}

	/* ---------------------------------------------------------------- AI fix */

	/** Whether the Meta module can take AI requests. */
	public function ai_available(): bool {
		return $this->plugin()->module_enabled( 'meta' ) && $this->plugin()->claude()->is_available();
	}

	/**
	 * Sends AI-fixable issues of a run to the Meta module (seoc_request_ai_suggestions).
	 *
	 * @param int      $run_id   Run id (0 = latest finished run).
	 * @param string[] $rule_ids Limit to these rules.
	 * @return array|WP_Error { run_id, queued, fields: { title, description, image_alt } }.
	 */
	public function request_ai_fixes( int $run_id = 0, array $rule_ids = array() ) {
		if ( ! $this->plugin()->module_enabled( 'meta' ) ) {
			return new WP_Error( 'seoc_meta_disabled', __( 'AI fixes are made by the "AI meta" module, which is turned off. Turn it on in SEO Console → Settings → Modules.', 'seo-console' ), array( 'status' => 409 ) );
		}
		if ( ! $this->plugin()->claude()->is_available() ) {
			return new WP_Error( 'seoc_ai_not_configured', __( 'AI is not set up yet: add your Claude API key (and a monthly budget above $0) in SEO Console → Settings.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$run_id = $run_id > 0 ? $run_id : $this->store->latest_run_id( array( 'done' ) );
		$run    = $run_id ? $this->store->get_run( $run_id ) : null;
		if ( ! $run || 'done' !== $run['status'] ) {
			return new WP_Error( 'seoc_audit_no_run', __( 'There is no finished audit yet. Run an audit first.', 'seo-console' ), array( 'status' => 404 ) );
		}
		$items  = $this->report()->ai_items( $run_id, 500, $rule_ids );
		$fields = array(
			'title'       => 0,
			'description' => 0,
			'image_alt'   => 0,
		);
		foreach ( $items as $item ) {
			foreach ( $item['fields'] as $field ) {
				++$fields[ $field ];
			}
		}
		if ( $items ) {
			/**
			 * Asks the Meta module to create AI suggestions (ARCHITECTURE §11).
			 *
			 * @param array  $items  [ [ 'object_type' => 'post', 'object_id' => int, 'fields' => string[] ] ].
			 * @param string $origin 'audit'.
			 */
			do_action( 'seoc_request_ai_suggestions', $items, 'audit' );
		}
		return array(
			'run_id' => $run_id,
			'queued' => count( $items ),
			'fields' => $fields,
		);
	}

	/* ---------------------------------------------------------- Integrations */

	/** REST routes. */
	public function register_rest(): void {
		( new AuditController( $this->plugin(), $this ) )->register_routes();
	}

	/**
	 * seoc_ai_site_context: latest audit score + top issues.
	 *
	 * @param array $sections key => markdown.
	 */
	public function site_context( $sections ): array {
		$sections = (array) $sections;
		$run_id   = $this->store->latest_run_id( array( 'done' ) );
		$run      = $run_id ? $this->store->get_run( $run_id ) : null;
		if ( ! $run ) {
			return $sections;
		}
		$summary  = (array) $run['summary'];
		$severity = (array) ( $summary['by_severity'] ?? array() );
		$lines    = array(
			sprintf(
				/* translators: 1: date, 2: score, 3: pages crawled */
				__( 'Latest site audit (%1$s UTC): health score %2$d/100, %3$d pages crawled.', 'seo-console' ),
				(string) $run['finished_at'],
				(int) $run['score'],
				(int) $run['pages_crawled']
			),
			sprintf(
				/* translators: 1: errors, 2: warnings, 3: notices */
				__( 'Issues: %1$d errors, %2$d warnings, %3$d notices.', 'seo-console' ),
				(int) ( $severity['error'] ?? 0 ),
				(int) ( $severity['warning'] ?? 0 ),
				(int) ( $severity['notice'] ?? 0 )
			),
		);
		$top      = array_slice( (array) ( $summary['top_issues'] ?? array() ), 0, 10 );
		if ( $top ) {
			$lines[] = __( 'Top issues:', 'seo-console' );
			foreach ( $top as $issue ) {
				$rule    = Rules\Rules::get( (string) $issue['rule_id'] );
				$lines[] = sprintf( '- %s (%s, %d)', $rule ? $rule->title : $issue['rule_id'], $issue['severity'], (int) $issue['count'] );
			}
		}
		$sections['audit'] = implode( "\n", $lines );
		return $sections;
	}

	/**
	 * seoc_dashboard_cards: health score card.
	 *
	 * @param array $cards Cards.
	 */
	public function dashboard_card( $cards ): array {
		$cards  = (array) $cards;
		$run_id = $this->store->latest_run_id( array( 'done' ) );
		$run    = $run_id ? $this->store->get_run( $run_id ) : null;
		$card   = array(
			'id'        => 'audit',
			'title'     => __( 'Site audit', 'seo-console' ),
			'label'     => __( 'Health score', 'seo-console' ),
			'value'     => null,
			'unit'      => '/100',
			'status'    => 'unknown',
			'link'      => admin_url( 'admin.php?page=seo-console-audit' ),
			'linkLabel' => __( 'Open site audit', 'seo-console' ),
		);
		if ( $run ) {
			$errors              = (int) ( $run['summary']['by_severity']['error'] ?? 0 );
			$card['value']       = $run['score'];
			$card['status']      = self::score_status( (int) $run['score'] );
			$card['errors']      = $errors;
			$card['finished_at'] = \SEOConsole\Core\ObjectInfo::iso_date( $run['finished_at'] );
			$card['description'] = sprintf(
				/* translators: 1: number of errors, 2: time ago */
				_n( '%1$d error found. Last audit %2$s ago.', '%1$d errors found. Last audit %2$s ago.', $errors, 'seo-console' ),
				$errors,
				human_time_diff( (int) strtotime( (string) $run['finished_at'] . ' UTC' ) )
			);
		} else {
			$card['description'] = __( 'No audit yet. Run one to get your health score.', 'seo-console' );
		}
		$cards[] = $card;
		return $cards;
	}

	/**
	 * seoc_checklist: auditRun after the first finished run.
	 *
	 * @param array $checklist Flags.
	 */
	public function checklist( $checklist ): array {
		$checklist             = (array) $checklist;
		$checklist['auditRun'] = $this->store->latest_run_id( array( 'done' ) ) > 0;
		return $checklist;
	}

	/**
	 * Badge status for a score.
	 *
	 * @param int $score Score 0–100.
	 */
	public static function score_status( int $score ): string {
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'needs-work' : 'poor';
	}
}
