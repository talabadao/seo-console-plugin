<?php
/**
 * Background work of the Speed module: PSI runs, CrUX fetches, runtime data for the tweaks, retention.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

use SEOConsole\Jobs\TimeBudget;
use SEOConsole\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One run = one row per URL × strategy, each tested by its own Action Scheduler action
 * (`seoc_speed_test_url`, one PSI request per action, one retry on timeout/quota).
 */
class SpeedRunner {

	const HOOK_RUN     = 'seoc_speed_run';
	const HOOK_TEST    = 'seoc_speed_test_url';
	const HOOK_CRUX    = 'seoc_speed_crux';
	const HOOK_HISTORY = 'seoc_speed_crux_history';
	const HOOK_PRUNE   = 'seoc_speed_prune';
	const HOOK_RETEST  = 'seoc_speed_change_retest';

	/** Hooks unscheduled when the module is switched off. */
	const HOOKS = array( self::HOOK_RUN, self::HOOK_TEST, self::HOOK_CRUX, self::HOOK_HISTORY, self::HOOK_PRUNE, self::HOOK_RETEST );

	/** Small autoloaded option read by the front-end tweaks (LCP images, preconnect origins). */
	const RUNTIME_OPTION = 'seoc_speed_runtime';

	/** Pending re-test after a change (debounce). */
	const RETEST_OPTION = 'seoc_speed_retest';

	/** Seconds to wait after a change before re-testing (several changes → one run). */
	const RETEST_DELAY = 300;

	/** Retry-able PSI error codes. */
	const RETRY_CODES = array( 'seoc_psi_quota', 'seoc_psi_network' );

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Store.
	 *
	 * @var SpeedStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Plugin          $plugin Plugin.
	 * @param SpeedStore|null $store  Store.
	 */
	public function __construct( Plugin $plugin, ?SpeedStore $store = null ) {
		$this->plugin = $plugin;
		$this->store  = $store ? $store : new SpeedStore();
	}

	/**
	 * Store.
	 *
	 * @return SpeedStore
	 */
	public function store(): SpeedStore {
		return $this->store;
	}

	/**
	 * Google API key (null when none).
	 *
	 * @return string|null
	 */
	public function api_key() {
		$key = $this->plugin->secrets()->get( 'google_api_key' );
		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * A module setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private function setting( string $key ) {
		return $this->plugin->settings()->get( 'speed', $key );
	}

	/**
	 * The URLs tested now: automatic picks, or the validated custom list.
	 *
	 * @return array[] [ 'url', 'type', 'object_id', 'label' ]
	 */
	public function target_urls(): array {
		if ( 'custom' === $this->setting( 'url_mode' ) ) {
			$valid = ( new UrlPicker( $this->plugin->seo()->active() ) )->validate( (array) $this->setting( 'custom_urls' ) );
			if ( ! empty( $valid['valid'] ) ) {
				$out = array();
				foreach ( $valid['valid'] as $url ) {
					$out[] = array(
						'url'       => $url,
						'type'      => 'custom',
						'object_id' => null,
						'label'     => $url,
					);
				}
				return $out;
			}
		}
		return ( new UrlPicker( $this->plugin->seo()->active() ) )->pick();
	}

	/**
	 * Strategies to test.
	 *
	 * @return string[]
	 */
	public function strategies(): array {
		$value = (string) $this->setting( 'strategies' );
		if ( 'mobile' === $value || 'desktop' === $value ) {
			return array( $value );
		}
		return array( 'mobile', 'desktop' );
	}

	/**
	 * Starts a run: one result row and one background action per URL × strategy.
	 *
	 * @param string   $trigger   manual|schedule|change.
	 * @param int|null $change_id Change that triggered a re-test.
	 * @return array|WP_Error Run.
	 */
	public function start_run( string $trigger = 'manual', ?int $change_id = null ) {
		$active = $this->store->active_run();
		if ( null !== $active ) {
			return new WP_Error(
				'seoc_speed_run_active',
				__( 'A speed test is already running. Wait for it to finish.', 'seo-console' ),
				array(
					'status' => 409,
					'run_id' => $active['id'],
				)
			);
		}
		if ( ! $this->plugin->queue()->available() ) {
			return new WP_Error(
				'seoc_speed_queue_unavailable',
				__( 'Background jobs are not available right now, so the test cannot start. Try again in a minute.', 'seo-console' ),
				array( 'status' => 503 )
			);
		}

		$urls       = $this->target_urls();
		$strategies = $this->strategies();
		$run_id     = $this->store->create_run(
			array(
				'trigger_source' => in_array( $trigger, array( 'manual', 'schedule', 'change' ), true ) ? $trigger : 'manual',
				'change_id'      => $change_id,
				'urls_total'     => count( $urls ) * count( $strategies ),
			)
		);
		if ( ! $run_id ) {
			return new WP_Error( 'seoc_speed_db', __( 'The test could not be saved in the database.', 'seo-console' ), array( 'status' => 500 ) );
		}
		foreach ( $urls as $entry ) {
			foreach ( $strategies as $strategy ) {
				$result_id = $this->store->create_result(
					array(
						'run_id'    => $run_id,
						'url'       => $entry['url'],
						'url_type'  => $entry['type'],
						'object_id' => $entry['object_id'],
						'label'     => mb_substr( (string) $entry['label'], 0, 250 ),
						'strategy'  => $strategy,
					)
				);
				$this->plugin->queue()->enqueue( self::HOOK_TEST, array( $result_id ) );
			}
		}
		return $this->store->get_run( $run_id );
	}

	/**
	 * Daily job: starts a scheduled run unless the schedule is off, or weekly and the last one is recent.
	 */
	public function scheduled_run(): void {
		$schedule = (string) $this->setting( 'psi_schedule' );
		if ( 'off' === $schedule ) {
			return;
		}
		if ( 'weekly' === $schedule ) {
			$last = $this->store->last_finished_run( 'schedule' );
			if ( $last && strtotime( (string) $last['created_at'] ) > time() - (int) ( 6.5 * DAY_IN_SECONDS ) ) {
				return;
			}
		}
		$this->start_run( 'schedule' );
	}

	/**
	 * Action `seoc_speed_test_url`: one PSI request for one result row.
	 *
	 * @param int $result_id Result id.
	 */
	public function test_url( $result_id ): void {
		$result = $this->store->get_result( (int) $result_id );
		if ( null === $result || ! in_array( $result['status'], SpeedStore::OPEN, true ) ) {
			return; // Already done (idempotent).
		}
		$run = $this->store->get_run( $result['run_id'] );
		if ( null === $run || ! in_array( $run['status'], SpeedStore::ACTIVE, true ) ) {
			$this->store->update_result(
				$result['id'],
				array(
					'status'     => 'failed',
					'error_code' => 'seoc_speed_run_closed',
					'error'      => 'Run was closed before this URL was tested.',
				)
			);
			return;
		}
		if ( 'queued' === $run['status'] ) {
			$this->store->update_run(
				$run['id'],
				array(
					'status'     => 'running',
					'started_at' => SpeedStore::now(),
				)
			);
		}
		self::extend_time_limit( 90 );

		$attempts = $result['attempts'] + 1;
		$client   = new PsiClient( $this->api_key() );
		$raw      = $client->run( $result['url'], $result['strategy'] );

		if ( is_wp_error( $raw ) ) {
			$code  = $raw->get_error_code();
			$data  = (array) $raw->get_error_data();
			$retry = in_array( $code, self::RETRY_CODES, true ) && $attempts < 2;
			$this->store->update_result(
				$result['id'],
				array(
					'status'     => $retry ? 'retry' : 'failed',
					'attempts'   => $attempts,
					'error_code' => $code,
					'error'      => $raw->get_error_message(),
				)
			);
			if ( $retry ) {
				$wait = isset( $data['retry_after'] ) && is_numeric( $data['retry_after'] ) ? (int) $data['retry_after'] : 120;
				$this->plugin->queue()->schedule_single( self::HOOK_TEST, time() + max( 60, min( 3600, $wait ) ), array( $result['id'] ) );
				return;
			}
			$this->plugin->logger()->warning(
				'PageSpeed Insights test failed.',
				array(
					'url'      => $result['url'],
					'strategy' => $result['strategy'],
					'code'     => $code,
				),
				'speed'
			);
			$this->finish_run_if_complete( $result['run_id'] );
			return;
		}

		$parsed = ( new PsiParser() )->parse( $raw, $result['strategy'] );
		if ( isset( $parsed['lcp_element']['type'] ) && 'image' === $parsed['lcp_element']['type'] && '' !== $parsed['lcp_element']['image_url'] ) {
			$attrs = $this->page_image_attributes( $result['url'], $parsed['lcp_element']['image_url'] );
			if ( null !== $attrs ) {
				$parsed['lcp_element']['page_attrs'] = $attrs;
			}
		}

		$lab   = $parsed['lab'];
		$field = null !== $parsed['field']['url'] ? $parsed['field']['url'] : null;
		$this->store->update_result(
			$result['id'],
			array(
				'status'         => 'done',
				'attempts'       => $attempts,
				'fetched_at'     => SpeedStore::now(),
				'score'          => $parsed['score'],
				'lcp_ms'         => self::lab_int( $lab, 'lcp' ),
				'fcp_ms'         => self::lab_int( $lab, 'fcp' ),
				'tbt_ms'         => self::lab_int( $lab, 'tbt' ),
				'cls'            => isset( $lab['cls']['value'] ) ? $lab['cls']['value'] : null,
				'si_ms'          => self::lab_int( $lab, 'si' ),
				'ttfb_ms'        => self::lab_int( $lab, 'ttfb' ),
				'field_lcp_ms'   => isset( $field['metrics']['lcp']['p75'] ) ? $field['metrics']['lcp']['p75'] : null,
				'field_inp_ms'   => isset( $field['metrics']['inp']['p75'] ) ? $field['metrics']['inp']['p75'] : null,
				'field_cls'      => isset( $field['metrics']['cls']['p75'] ) ? $field['metrics']['cls']['p75'] : null,
				'field_category' => isset( $field['overall'] ) ? $field['overall'] : null,
				'result'         => $parsed,
				'error_code'     => null,
				'error'          => null,
			)
		);
		$this->finish_run_if_complete( $result['run_id'] );
	}

	/**
	 * Closes a run once no result is open: status, summary, runtime data, completion hook.
	 *
	 * @param int $run_id Run id.
	 */
	public function finish_run_if_complete( int $run_id ): void {
		$counts = $this->store->result_counts( $run_id );
		$this->store->update_run( $run_id, array( 'urls_done' => $counts['done'] + $counts['failed'] ) );
		if ( $counts['pending'] + $counts['retry'] > 0 ) {
			return;
		}
		$run = $this->store->get_run( $run_id );
		if ( null === $run || ! in_array( $run['status'], SpeedStore::ACTIVE, true ) ) {
			return;
		}
		$status = 'done';
		if ( 0 === $counts['done'] ) {
			$status = 'failed';
		} elseif ( $counts['failed'] > 0 ) {
			$status = 'partial';
		}
		$summary = $this->summary( $run_id );
		$this->store->update_run(
			$run_id,
			array(
				'status'      => $status,
				'finished_at' => SpeedStore::now(),
				'summary'     => $summary,
				'error'       => 'failed' === $status ? __( 'No URL could be tested.', 'seo-console' ) : null,
			)
		);
		if ( 'failed' !== $status ) {
			$this->rebuild_runtime();
		}
		/**
		 * Fires when a speed run finished (ARCHITECTURE §11).
		 *
		 * @param int   $run_id  Run id.
		 * @param array $summary Summary.
		 */
		do_action( 'seoc_speed_run_completed', $run_id, $summary );
	}

	/**
	 * Summary of a run.
	 *
	 * @param int $run_id Run id.
	 * @return array [ 'tested', 'failed', 'score' => [ mobile, desktop ], 'lcp_ms' => [ … ], 'cls' => [ … ], 'home' => [ mobile, desktop ], 'field' => assessment|null ]
	 */
	public function summary( int $run_id ): array {
		$rows    = $this->store->results_for_run( $run_id, true );
		$summary = array(
			'tested' => 0,
			'failed' => 0,
			'score'  => array(),
			'lcp_ms' => array(),
			'cls'    => array(),
			'home'   => array(),
			'field'  => null,
		);
		$buckets = array();
		foreach ( $rows as $row ) {
			if ( 'done' !== $row['status'] ) {
				++$summary['failed'];
				continue;
			}
			++$summary['tested'];
			$s = $row['strategy'];
			foreach ( array( 'score', 'lcp_ms', 'cls' ) as $metric ) {
				if ( null !== $row[ $metric ] ) {
					$buckets[ $metric ][ $s ][] = (float) $row[ $metric ];
				}
			}
			if ( 'home' === $row['url_type'] ) {
				$summary['home'][ $s ] = $row['score'];
			}
			if ( null === $summary['field'] && ! empty( $row['result']['field']['origin']['metrics'] ) ) {
				$summary['field'] = self::assessment_from_field( $row['result']['field']['origin']['metrics'] );
			}
		}
		foreach ( $buckets as $metric => $by_strategy ) {
			foreach ( $by_strategy as $strategy => $values ) {
				$avg                             = array_sum( $values ) / count( $values );
				$summary[ $metric ][ $strategy ] = 'cls' === $metric ? round( $avg, 3 ) : (int) round( $avg );
			}
		}
		return $summary;
	}

	/**
	 * Core Web Vitals assessment from PSI field categories.
	 *
	 * @param array $metrics Field metrics.
	 * @return string|null pass|fail|null
	 */
	public static function assessment_from_field( array $metrics ) {
		$ratings = array();
		foreach ( Thresholds::CORE as $key ) {
			if ( isset( $metrics[ $key ]['category'] ) ) {
				$ratings[ $key ] = $metrics[ $key ]['category'];
			}
		}
		return Thresholds::assess( $ratings );
	}

	/**
	 * Fetches the tested page once (same host only) and reads the LCP <img> attributes.
	 *
	 * @param string $page_url  Page URL.
	 * @param string $image_url LCP image URL.
	 * @return array|null [ 'src', 'srcset', 'sizes' ]
	 */
	private function page_image_attributes( string $page_url, string $image_url ) {
		if ( ! PsiParser::same_site( PsiParser::host( $page_url ), PsiParser::host( home_url( '/' ) ) ) ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$page_url,
			array(
				'timeout'     => 10,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'user-agent'  => 'Mozilla/5.0 (Linux; Android 14) SEO-Console/' . SEOC_VERSION,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$attrs = Tweaks::find_image_attributes( (string) wp_remote_retrieve_body( $response ), $image_url );
		if ( null === $attrs ) {
			return null;
		}
		return array(
			'src'    => mb_substr( $attrs['src'], 0, 1000 ),
			'srcset' => mb_substr( $attrs['srcset'], 0, 3000 ),
			'sizes'  => mb_substr( $attrs['sizes'], 0, 300 ),
		);
	}

	/**
	 * Rebuilds the runtime option from the latest results of the current URLs.
	 *
	 * @return array Runtime data.
	 */
	public function rebuild_runtime(): array {
		$urls    = wp_list_pluck( $this->target_urls(), 'url' );
		$latest  = $this->store->latest_results( $urls );
		$lcp     = array();
		$origins = array();
		// Mobile first: it decides the preload candidate when both strategies saw an image.
		usort(
			$latest,
			static function ( $a, $b ) {
				return ( 'mobile' === $a['strategy'] ? 0 : 1 ) <=> ( 'mobile' === $b['strategy'] ? 0 : 1 );
			}
		);
		foreach ( $latest as $row ) {
			$result = is_array( $row['result'] ) ? $row['result'] : array();
			foreach ( isset( $result['preconnect_candidates'] ) ? (array) $result['preconnect_candidates'] : array() as $origin ) {
				$origins[ $origin ] = isset( $origins[ $origin ] ) ? $origins[ $origin ] + 1 : 1;
			}
			$key = SpeedStore::url_hash( $row['url'] );
			if ( isset( $lcp[ $key ] ) || empty( $result['lcp_element']['image_url'] ) || 'image' !== $result['lcp_element']['type'] ) {
				continue;
			}
			$el    = $result['lcp_element'];
			$attrs = isset( $el['page_attrs'] ) && is_array( $el['page_attrs'] ) ? $el['page_attrs'] : null;
			$href  = $attrs && '' !== $attrs['src'] && 0 !== strpos( $attrs['src'], 'data:' ) ? self::absolute( $attrs['src'], $row['url'] ) : $el['image_url'];

			$lcp[ $key ] = array(
				'page_url'  => $row['url'],
				'image_url' => esc_url_raw( $href, array( 'http', 'https' ) ),
				'srcset'    => $attrs ? $attrs['srcset'] : ( isset( $el['srcset'] ) ? (string) $el['srcset'] : '' ),
				'sizes'     => $attrs ? $attrs['sizes'] : ( isset( $el['sizes'] ) ? (string) $el['sizes'] : '' ),
			);
		}
		arsort( $origins );
		$runtime = array(
			'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'lcp_images' => array_values(
				array_filter(
					$lcp,
					static function ( $e ) {
						return '' !== $e['image_url'];
					}
				)
			),
			'preconnect' => Tweaks::clean_origins( array_keys( $origins ) ),
		);
		update_option( self::RUNTIME_OPTION, $runtime, true );
		return $runtime;
	}

	/**
	 * Runtime data for the tweaks.
	 *
	 * @return array [ 'updated_at', 'lcp_images', 'preconnect' ]
	 */
	public static function runtime(): array {
		$value = get_option( self::RUNTIME_OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		return array(
			'updated_at' => isset( $value['updated_at'] ) ? (string) $value['updated_at'] : null,
			'lcp_images' => isset( $value['lcp_images'] ) && is_array( $value['lcp_images'] ) ? $value['lcp_images'] : array(),
			'preconnect' => isset( $value['preconnect'] ) && is_array( $value['preconnect'] ) ? $value['preconnect'] : array(),
		);
	}

	/**
	 * Daily job: CrUX records for the origin and each tested URL (phone + desktop).
	 *
	 * @return array|WP_Error [ 'stored' => n, 'with_data' => n ]
	 */
	public function fetch_crux() {
		$key = $this->api_key();
		if ( null === $key ) {
			return new WP_Error( 'seoc_crux_no_key', __( 'Real-user data (CrUX) needs a Google API key. Add one in SEO Console settings.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$client  = new CruxClient( $key );
		$budget  = new TimeBudget();
		$stored  = 0;
		$with    = 0;
		$targets = array( array( home_url( '/' ), 'origin' ) );
		foreach ( $this->target_urls() as $entry ) {
			$targets[] = array( $entry['url'], 'url' );
		}
		foreach ( $targets as $target ) {
			foreach ( array( 'PHONE', 'DESKTOP' ) as $form_factor ) {
				if ( ! $budget->has_time( 5 ) ) {
					break 2;
				}
				$result = $client->query( $target[0], $target[1], $form_factor );
				if ( is_wp_error( $result ) ) {
					if ( in_array( $result->get_error_code(), array( 'seoc_crux_auth', 'seoc_crux_quota' ), true ) ) {
						return $result;
					}
					continue;
				}
				$this->store->insert_crux( 'record', $result );
				++$stored;
				$with += $result['has_data'] ? 1 : 0;
			}
		}
		return array(
			'stored'    => $stored,
			'with_data' => $with,
		);
	}

	/**
	 * Weekly CrUX history of the origin (phone + desktop). Runs from a daily job and skips when fetched < 6.5 days ago.
	 *
	 * @param bool $force Ignore the weekly gap.
	 * @return array|WP_Error
	 */
	public function fetch_crux_history( bool $force = false ) {
		$key = $this->api_key();
		if ( null === $key ) {
			return new WP_Error( 'seoc_crux_no_key', __( 'Real-user data (CrUX) needs a Google API key. Add one in SEO Console settings.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$last = $this->store->last_crux_fetch( 'history' );
		if ( ! $force && null !== $last && $last > time() - (int) ( 6.5 * DAY_IN_SECONDS ) ) {
			return array( 'skipped' => true );
		}
		$client = new CruxClient( $key );
		$stored = 0;
		foreach ( array( 'PHONE', 'DESKTOP' ) as $form_factor ) {
			$result = $client->history( home_url( '/' ), 'origin', $form_factor );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->store->insert_crux( 'history', $result );
			++$stored;
		}
		return array( 'stored' => $stored );
	}

	/**
	 * Daily job: retention.
	 *
	 * @return array
	 */
	public function prune(): array {
		return $this->store->prune( (int) $this->setting( 'retention_days' ) );
	}

	/**
	 * Queues a re-test a few minutes after a change (one run for several quick changes).
	 *
	 * @param int $change_id Change id.
	 */
	public function queue_retest( int $change_id ): void {
		update_option(
			self::RETEST_OPTION,
			array(
				'change_id' => $change_id,
				'at'        => time(),
			),
			false
		);
		if ( ! $this->plugin->queue()->is_scheduled( self::HOOK_RETEST ) ) {
			$this->plugin->queue()->schedule_single( self::HOOK_RETEST, time() + self::RETEST_DELAY, array() );
		}
	}

	/**
	 * Action `seoc_speed_change_retest`.
	 */
	public function run_retest(): void {
		$pending = get_option( self::RETEST_OPTION );
		if ( ! is_array( $pending ) || empty( $pending['change_id'] ) ) {
			return;
		}
		$run = $this->start_run( 'change', (int) $pending['change_id'] );
		if ( is_wp_error( $run ) && 'seoc_speed_run_active' === $run->get_error_code() ) {
			// Try again after the running test; keep the pending change.
			$this->plugin->queue()->schedule_single( self::HOOK_RETEST, time() + 2 * self::RETEST_DELAY, array() );
			return;
		}
		delete_option( self::RETEST_OPTION );
	}

	/**
	 * Raises the PHP time limit when the host allows it.
	 *
	 * @param int $seconds Seconds.
	 */
	public static function extend_time_limit( int $seconds ): void {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ) ) {
			$current = (int) ini_get( 'max_execution_time' );
			if ( 0 !== $current && $current < $seconds ) {
				set_time_limit( $seconds ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- one PSI request can take up to 60 s.
			}
		}
	}

	/**
	 * Lab metric as int or null.
	 *
	 * @param array  $lab Lab block.
	 * @param string $key Key.
	 * @return int|null
	 */
	private static function lab_int( array $lab, string $key ) {
		return isset( $lab[ $key ]['value'] ) ? (int) round( (float) $lab[ $key ]['value'] ) : null;
	}

	/**
	 * Resolves a possibly relative URL against a page URL.
	 *
	 * @param string $url  URL.
	 * @param string $base Page URL.
	 * @return string
	 */
	private static function absolute( string $url, string $base ): string {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$parts = wp_parse_url( $base );
		$root  = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' );
		if ( 0 === strpos( $url, '//' ) ) {
			return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . ':' . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		return $root . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $url;
	}
}
