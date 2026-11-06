<?php
/**
 * Speed module: PageSpeed Insights + CrUX monitoring, cache-plugin diagnosis, safe tweaks, database and environment checks.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

use SEOConsole\Changes\Change;
use SEOConsole\Modules\AbstractModule;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Module `speed`.
 */
class SpeedModule extends AbstractModule {

	/**
	 * Plugin (set in register()).
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Runner.
	 *
	 * @var SpeedRunner|null
	 */
	private $runner;

	/**
	 * Memoised cache-plugin features.
	 *
	 * @var array|null
	 */
	private $cache_features;

	/** {@inheritDoc} */
	public function id(): string {
		return 'speed';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Speed', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Measures page speed and Core Web Vitals, explains what to fix, and applies safe speed tweaks you can undo.', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function settings_schema(): array {
		$schema = array(
			'strategies'         => array(
				'type'    => 'enum',
				'enum'    => array( 'both', 'mobile', 'desktop' ),
				'default' => 'both',
				'label'   => __( 'Devices to test', 'seo-console' ),
			),
			'url_mode'           => array(
				'type'    => 'enum',
				'enum'    => array( 'auto', 'custom' ),
				'default' => 'auto',
				'label'   => __( 'Pages to test', 'seo-console' ),
			),
			'custom_urls'        => array(
				'type'    => 'url_list',
				'default' => array(),
				'max'     => UrlPicker::MAX_USER,
				'label'   => __( 'Custom pages to test', 'seo-console' ),
			),
			'psi_schedule'       => array(
				'type'    => 'enum',
				'enum'    => array( 'daily', 'weekly', 'off' ),
				'default' => 'daily',
				'label'   => __( 'Automatic speed tests', 'seo-console' ),
			),
			'crux_enabled'       => array(
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Collect real-user data (CrUX)', 'seo-console' ),
			),
			'retention_days'     => array(
				'type'    => 'int',
				'default' => 400,
				'min'     => 30,
				'max'     => 1000,
				'label'   => __( 'Keep speed history (days)', 'seo-console' ),
			),
			'revisions_to_keep'  => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
				'label'   => __( 'Revisions to keep per post', 'seo-console' ),
			),
			'heartbeat_interval' => array(
				'type'    => 'int',
				'default' => 60,
				'min'     => 15,
				'max'     => 120,
				'label'   => __( 'Admin Heartbeat interval (seconds)', 'seo-console' ),
			),
			'preconnect_origins' => array(
				'type'    => 'url_list',
				'default' => array(),
				'max'     => Tweaks::MAX_PRECONNECT,
				'label'   => __( 'Preconnect origins (empty = found by the speed test)', 'seo-console' ),
			),
		);
		foreach ( Tweaks::definitions() as $id => $def ) {
			$schema[ self::tweak_key( $id ) ] = array(
				'type'    => 'bool',
				'default' => false,
				'label'   => $def['title'],
			);
		}
		return $schema;
	}

	/** {@inheritDoc} */
	public function tables(): array {
		return SpeedStore::statements();
	}

	/**
	 * Settings key of a tweak.
	 *
	 * @param string $id Tweak id.
	 * @return string
	 */
	public static function tweak_key( string $id ): string {
		return 'tweak_' . $id;
	}

	/** {@inheritDoc} */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		$runner       = $this->runner();

		$enabled = $this->enabled_tweaks();
		if ( $enabled ) {
			( new Tweaks() )->apply( $enabled, $this->tweak_config(), 'everywhere' );
		}

		add_action( SpeedRunner::HOOK_RUN, array( $runner, 'scheduled_run' ) );
		add_action( SpeedRunner::HOOK_TEST, array( $runner, 'test_url' ), 10, 2 );
		add_action( SpeedRunner::HOOK_CRUX, array( $this, 'crux_job' ) );
		add_action( SpeedRunner::HOOK_HISTORY, array( $this, 'crux_history_job' ) );
		add_action( SpeedRunner::HOOK_PRUNE, array( $runner, 'prune' ) );
		add_action( SpeedRunner::HOOK_RETEST, array( $runner, 'run_retest' ) );
		add_action( 'seoc_ensure_jobs', array( $this, 'ensure_jobs' ) );

		add_action( 'seoc_change_applied', array( $this, 'on_change' ) );
		add_action( 'seoc_change_reverted', array( $this, 'on_change' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_filter( 'seoc_environment_checks', array( $this, 'environment_checks' ), 10, 2 );
		add_filter( 'seoc_ai_site_context', array( $this, 'ai_site_context' ) );
		add_filter( 'seoc_dashboard_cards', array( $this, 'dashboard_cards' ) );
		add_filter( 'seoc_checklist', array( $this, 'checklist' ) );
	}

	/** {@inheritDoc} */
	public function register_frontend( Plugin $plugin ): void {
		$this->plugin = $plugin;
		$enabled      = $this->enabled_tweaks();
		if ( $enabled ) {
			( new Tweaks() )->apply( $enabled, $this->tweak_config(), 'frontend' );
		}
	}

	/** {@inheritDoc} */
	public function admin_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console-speed',
				'title'    => __( 'Speed', 'seo-console' ),
				'entry'    => 'speed',
				'position' => 20,
			),
			array(
				'slug'     => 'seo-console-environment',
				'title'    => __( 'Environment', 'seo-console' ),
				'entry'    => 'environment',
				'position' => 90,
			),
		);
	}

	/** {@inheritDoc} */
	public function on_disable(): void {
		$queue = Plugin::instance()->queue();
		foreach ( SpeedRunner::HOOKS as $hook ) {
			$queue->unschedule( $hook );
		}
	}

	/**
	 * Plugin (register() was called, else the singleton).
	 *
	 * @return Plugin
	 */
	public function plugin(): Plugin {
		return $this->plugin ? $this->plugin : Plugin::instance();
	}

	/**
	 * Runner.
	 *
	 * @return SpeedRunner
	 */
	public function runner(): SpeedRunner {
		if ( null === $this->runner ) {
			$this->runner = new SpeedRunner( $this->plugin() );
		}
		return $this->runner;
	}

	/**
	 * Enabled tweak ids (from settings).
	 *
	 * @return string[]
	 */
	public function enabled_tweaks(): array {
		$settings = $this->plugin()->settings();
		$on       = array();
		foreach ( array_keys( Tweaks::definitions() ) as $id ) {
			if ( $settings->get( 'speed', self::tweak_key( $id ) ) ) {
				$on[] = $id;
			}
		}
		return $on;
	}

	/**
	 * Config for Tweaks::apply().
	 *
	 * @return array
	 */
	public function tweak_config(): array {
		$settings = $this->plugin()->settings();
		$runtime  = SpeedRunner::runtime();
		$custom   = (array) $settings->get( 'speed', 'preconnect_origins' );
		return array(
			'cache_features'     => $this->cache_features(),
			'lcp_images'         => $runtime['lcp_images'],
			'preconnect_origins' => ! empty( $custom ) ? $custom : $runtime['preconnect'],
			'revisions_to_keep'  => (int) $settings->get( 'speed', 'revisions_to_keep' ),
			'heartbeat_interval' => (int) $settings->get( 'speed', 'heartbeat_interval' ),
		);
	}

	/**
	 * The audit of the first supported cache plugin that is active, or null.
	 *
	 * @return CacheConfigAudit|null
	 */
	public function cache_audit() {
		foreach ( $this->plugin()->cache()->detect() as $plugin ) {
			if ( isset( $plugin['id'] ) && CacheConfigAudit::supports( (string) $plugin['id'] ) ) {
				return new CacheConfigAudit( (string) $plugin['id'] );
			}
		}
		return null;
	}

	/**
	 * Features any active (supported) cache plugin performs, so tweaks can skip them.
	 *
	 * @return array feature => bool
	 */
	public function cache_features(): array {
		if ( null !== $this->cache_features ) {
			return $this->cache_features;
		}
		$features = array();
		foreach ( $this->plugin()->cache()->detect() as $plugin ) {
			if ( ! isset( $plugin['id'] ) || ! CacheConfigAudit::supports( (string) $plugin['id'] ) ) {
				continue;
			}
			foreach ( ( new CacheConfigAudit( (string) $plugin['id'] ) )->features() as $feature => $on ) {
				$features[ $feature ] = ! empty( $features[ $feature ] ) || true === $on;
			}
		}
		$this->cache_features = $features;
		return $features;
	}

	/**
	 * Drops memoised cache features (tests, after detection changes).
	 */
	public function reset_cache_features(): void {
		$this->cache_features = null;
	}

	/**
	 * `seoc_ensure_jobs`: recurring jobs at night (site time).
	 */
	public function ensure_jobs(): void {
		$queue    = $this->plugin()->queue();
		$settings = $this->plugin()->settings();
		if ( 'off' === $settings->get( 'speed', 'psi_schedule' ) ) {
			$queue->unschedule( SpeedRunner::HOOK_RUN );
		} else {
			$queue->schedule_daily( SpeedRunner::HOOK_RUN, array(), '03:40' );
		}
		if ( $settings->get( 'speed', 'crux_enabled' ) ) {
			$queue->schedule_daily( SpeedRunner::HOOK_CRUX, array(), '04:10' );
			$queue->schedule_daily( SpeedRunner::HOOK_HISTORY, array(), '04:20' );
		} else {
			$queue->unschedule( SpeedRunner::HOOK_CRUX );
			$queue->unschedule( SpeedRunner::HOOK_HISTORY );
		}
		$queue->schedule_daily( SpeedRunner::HOOK_PRUNE, array(), '04:50' );
	}

	/**
	 * Daily CrUX job (skipped quietly without a key or when switched off).
	 */
	public function crux_job(): void {
		if ( ! $this->plugin()->settings()->get( 'speed', 'crux_enabled' ) || null === $this->runner()->api_key() ) {
			return;
		}
		$result = $this->runner()->fetch_crux();
		if ( is_wp_error( $result ) ) {
			$this->plugin()->logger()->warning( 'CrUX fetch failed.', array( 'code' => $result->get_error_code() ), 'speed' );
		}
	}

	/**
	 * Daily CrUX history job (weekly gap enforced by the runner).
	 */
	public function crux_history_job(): void {
		if ( ! $this->plugin()->settings()->get( 'speed', 'crux_enabled' ) || null === $this->runner()->api_key() ) {
			return;
		}
		$result = $this->runner()->fetch_crux_history();
		if ( is_wp_error( $result ) ) {
			$this->plugin()->logger()->warning( 'CrUX history fetch failed.', array( 'code' => $result->get_error_code() ), 'speed' );
		}
	}

	/**
	 * `seoc_change_applied` / `seoc_change_reverted`: re-test after a front-end change (debounced).
	 *
	 * @param mixed $change Change.
	 */
	public function on_change( $change ): void {
		if ( ! $change instanceof Change || ! $change->id ) {
			return;
		}
		$applier = $this->plugin()->changes()->applier( (string) $change->type );
		if ( 'speed' !== $change->module && ( null === $applier || ! $applier->affects_frontend() ) ) {
			return;
		}
		$this->runner()->queue_retest( (int) $change->id );
	}

	/**
	 * REST routes.
	 */
	public function register_rest_routes(): void {
		( new SpeedRest( $this->plugin(), $this ) )->register_routes();
	}

	/**
	 * `seoc_environment_checks`: replaces core checks with the probe's richer ones (same id) and adds the rest.
	 *
	 * @param array $checks Checks.
	 * @param array $facts  Facts.
	 * @return array
	 */
	public function environment_checks( $checks, $facts = array() ): array {
		unset( $facts );
		$checks = is_array( $checks ) ? $checks : array();
		$probe  = ( new EnvironmentProbe() )->run( true );
		$by_id  = array();
		foreach ( $probe['items'] as $item ) {
			$by_id[ $item['id'] ] = array(
				'id'          => $item['id'],
				'label'       => $item['label'],
				'value'       => $item['value'],
				'status'      => $item['verdict'],
				'description' => $item['advice'],
				'source'      => 'speed',
			);
		}
		$out = array();
		foreach ( $checks as $check ) {
			$id = is_array( $check ) && isset( $check['id'] ) ? (string) $check['id'] : '';
			if ( '' !== $id && isset( $by_id[ $id ] ) ) {
				$out[] = $by_id[ $id ];
				unset( $by_id[ $id ] );
			} else {
				$out[] = $check;
			}
		}
		return array_merge( $out, array_values( $by_id ) );
	}

	/**
	 * Latest speed facts for summaries.
	 *
	 * @return array [ 'home_mobile' => result|null, 'origin_phone' => crux record|null, 'field_assessment' => pass|fail|null ]
	 */
	public function snapshot(): array {
		$store  = $this->runner()->store();
		$latest = $store->latest_results( array( home_url( '/' ) ) );
		$home   = null;
		foreach ( $latest as $row ) {
			if ( 'mobile' === $row['strategy'] ) {
				$home = $row;
			}
		}
		$crux       = $store->latest_crux( 'record', PsiParser::origin( home_url( '/' ) ), 'PHONE' );
		$assessment = null;
		if ( $crux && ! empty( $crux['data']['has_data'] ) ) {
			$assessment = isset( $crux['data']['assessment'] ) ? $crux['data']['assessment'] : null;
		} elseif ( $home && ! empty( $home['result']['field']['origin']['metrics'] ) ) {
			$assessment = SpeedRunner::assessment_from_field( $home['result']['field']['origin']['metrics'] );
		}
		return array(
			'home_mobile'      => $home,
			'origin_phone'     => $crux ? $crux['data'] : null,
			'field_assessment' => $assessment,
		);
	}

	/**
	 * `seoc_ai_site_context`: latest Core Web Vitals and the top 5 recommendations.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function ai_site_context( $sections ): array {
		$sections = is_array( $sections ) ? $sections : array();
		$snap     = $this->snapshot();
		if ( null === $snap['home_mobile'] && null === $snap['origin_phone'] ) {
			return $sections;
		}
		$lines = array( '## Page speed (SEO Console)' );
		$home  = $snap['home_mobile'];
		if ( $home ) {
			$lines[] = sprintf(
				'- Home page, mobile lab test (%s): score %s/100, LCP %s ms, CLS %s, TBT %s ms.',
				(string) $home['fetched_at'],
				null === $home['score'] ? 'n/a' : $home['score'],
				null === $home['lcp_ms'] ? 'n/a' : $home['lcp_ms'],
				null === $home['cls'] ? 'n/a' : $home['cls'],
				null === $home['tbt_ms'] ? 'n/a' : $home['tbt_ms']
			);
		}
		$crux = $snap['origin_phone'];
		if ( $crux && ! empty( $crux['has_data'] ) ) {
			$parts = array();
			foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
				if ( isset( $crux['metrics'][ $metric ] ) ) {
					$parts[] = strtoupper( $metric ) . ' p75 ' . $crux['metrics'][ $metric ]['p75'] . ' (' . $crux['metrics'][ $metric ]['rating'] . ')';
				}
			}
			$lines[] = '- Real users, phone, last 28 days (CrUX, origin): Core Web Vitals ' . ( $crux['assessment'] ? $crux['assessment'] : 'unknown' ) . '; ' . implode( ', ', $parts ) . '.';
		} elseif ( null !== $snap['field_assessment'] ) {
			$lines[] = '- Real users (origin, from PageSpeed Insights): Core Web Vitals ' . $snap['field_assessment'] . '.';
		}
		$recs = ( new SpeedRest( $this->plugin(), $this ) )->merged_recommendations();
		if ( $recs ) {
			$lines[] = '- Top speed to-dos:';
			foreach ( array_slice( $recs, 0, 5 ) as $rec ) {
				$lines[] = sprintf( '  - %s (impact %s, fix by %s, %d page(s))', $rec['title'], $rec['impact'], $rec['fixer'] ? $rec['fixer']['type'] : 'unknown', count( $rec['urls'] ) );
			}
		}
		$sections['speed'] = implode( "\n", $lines );
		return $sections;
	}

	/**
	 * `seoc_dashboard_cards`: home page mobile score + Core Web Vitals assessment.
	 *
	 * @param array $cards Cards.
	 * @return array
	 */
	public function dashboard_cards( $cards ): array {
		$cards = is_array( $cards ) ? $cards : array();
		$snap  = $this->snapshot();
		$link  = admin_url( 'admin.php?page=seo-console-speed' );
		$home  = $snap['home_mobile'];
		$score = $home ? $home['score'] : null;

		$status = 'unknown';
		if ( null !== $score ) {
			$status = $score >= 90 ? 'good' : ( $score >= 50 ? 'needs-work' : 'poor' );
		}
		$cards[] = array(
			'id'          => 'speed-mobile-score',
			'title'       => __( 'Page speed', 'seo-console' ),
			'label'       => __( 'Home page, mobile score', 'seo-console' ),
			'value'       => $score,
			'unit'        => '/ 100',
			'status'      => $status,
			'dataKind'    => null === $score ? 'none' : 'lab',
			'description' => null === $score
				? __( 'No speed test yet. Open Speed and click "Run test now".', 'seo-console' )
				: __( 'One simulated visit by PageSpeed Insights; scores vary a few points between runs.', 'seo-console' ),
			'link'        => $link,
			'linkLabel'   => __( 'Open Speed', 'seo-console' ),
		);

		$assessment = $snap['field_assessment'];
		$value      = null;
		$cwv_status = 'unknown';
		if ( 'pass' === $assessment ) {
			$value      = __( 'Passed', 'seo-console' );
			$cwv_status = 'good';
		} elseif ( 'fail' === $assessment ) {
			$value      = __( 'Failed', 'seo-console' );
			$cwv_status = 'poor';
		}
		$cards[] = array(
			'id'          => 'speed-cwv',
			'title'       => __( 'Core Web Vitals', 'seo-console' ),
			'label'       => __( 'Real users, last 28 days', 'seo-console' ),
			'value'       => $value,
			'status'      => $cwv_status,
			'dataKind'    => null === $value ? 'none' : 'field',
			'description' => null === $value
				? __( 'Google has not published real-user data for this site yet (not enough Chrome visitors), or no test has run.', 'seo-console' )
				: __( 'Google\'s assessment from real Chrome visitors (phone). This is what search ranking uses.', 'seo-console' ),
			'link'        => $link,
			'linkLabel'   => __( 'Details', 'seo-console' ),
		);
		return $cards;
	}

	/**
	 * `seoc_checklist`: speedTested once any URL was tested successfully.
	 *
	 * @param array $checklist Checklist.
	 * @return array
	 */
	public function checklist( $checklist ): array {
		$checklist                = is_array( $checklist ) ? $checklist : array();
		$checklist['speedTested'] = $this->runner()->store()->has_results();
		return $checklist;
	}
}
