<?php
/**
 * SEO Meta module: AI-proposed SEO titles, meta descriptions and image alt texts that a person reviews and
 * approves in the Suggestions queue.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use SEOConsole\Jobs\Queue;
use SEOConsole\Jobs\TimeBudget;
use SEOConsole\Modules\AbstractModule;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

final class MetaModule extends AbstractModule {

	/** Background job. Two hook names alternate: Action Scheduler will not enqueue a hook that is running. */
	const JOB_HOOK      = 'seoc_meta_suggest_batch';
	const JOB_HOOK_NEXT = 'seoc_meta_suggest_batch_next';

	/** Posts read from the queue per batch (the time budget usually stops earlier). */
	const BATCH_SIZE = 25;

	/** Lock against two batches running at once. */
	const LOCK_OPTION  = 'seoc_meta_batch_lock';
	const LOCK_SECONDS = 300;

	/**
	 * Plugin.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Job store.
	 *
	 * @var JobStore|null
	 */
	private $store;

	/** {@inheritDoc} */
	public function id(): string {
		return 'meta';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'SEO Meta', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Claude proposes SEO titles, meta descriptions and image alt texts; you review and approve them.', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function settings_schema(): array {
		return array(
			'brand_appended'  => array(
				'type'        => 'enum',
				'default'     => 'auto',
				'enum'        => array( 'auto', 'yes', 'no' ),
				'label'       => __( 'Brand at the end of titles', 'seo-console' ),
				'description' => __( 'Auto follows your SEO plugin\'s title template. Yes: proposed titles keep the "separator + site name" ending. No: titles are written without it.', 'seo-console' ),
			),
			'image_scope'     => array(
				'type'        => 'enum',
				'default'     => 'missing',
				'enum'        => array( 'missing', 'all' ),
				'label'       => __( 'Images to write alt text for', 'seo-console' ),
				'description' => __( 'Missing: only images without a useful alt text. All: also propose better alt texts for the others.', 'seo-console' ),
			),
			'auto_on_publish' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Suggest when a post is published', 'seo-console' ),
				'description' => __( 'Queue AI suggestions automatically when a post is first published (uses AI budget).', 'seo-console' ),
			),
		);
	}

	/** {@inheritDoc} */
	public function tables(): array {
		return array( JobStore::table_sql() );
	}

	/** {@inheritDoc} */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		$plugin->changes()->register_applier( new PostImageAltApplier() );

		add_action(
			'rest_api_init',
			function () use ( $plugin ) {
				( new MetaController( $plugin, $this ) )->register_routes();
			}
		);
		add_action( self::JOB_HOOK, array( $this, 'run_batch' ) );
		add_action( self::JOB_HOOK_NEXT, array( $this, 'run_batch' ) );
		add_action( 'seoc_request_ai_suggestions', array( $this, 'on_request' ), 10, 2 );
		if ( $plugin->settings()->get( 'meta', 'auto_on_publish' ) ) {
			add_action( 'transition_post_status', array( $this, 'on_publish' ), 10, 3 );
		}
		add_filter( 'seoc_ai_site_context', array( $this, 'site_context' ) );
		add_filter( 'seoc_dashboard_cards', array( $this, 'dashboard_card' ) );
		add_filter( 'seoc_suggestion_approve_value', array( $this, 'restore_display_suffix' ), 10, 2 );
		foreach ( array( 'save_post', 'deleted_post', 'seoc_change_applied', 'seoc_change_reverted' ) as $hook ) {
			add_action( $hook, array( PostsReport::class, 'flush' ) );
		}
	}

	/** {@inheritDoc} */
	public function admin_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console-meta',
				'title'    => __( 'SEO Meta', 'seo-console' ),
				'entry'    => 'meta',
				'position' => 30,
			),
		);
	}

	/** {@inheritDoc} */
	public function on_disable(): void {
		$queue = $this->plugin ? $this->plugin->queue() : new Queue();
		$queue->unschedule( self::JOB_HOOK );
		$queue->unschedule( self::JOB_HOOK_NEXT );
		$this->store()->clear_queue();
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Job store.
	 *
	 * @return JobStore
	 */
	public function store(): JobStore {
		if ( null === $this->store ) {
			$this->store = new JobStore();
		}
		return $this->store;
	}

	/**
	 * Plugin (the booted instance when register() has not run, e.g. in tests).
	 *
	 * @return Plugin
	 */
	public function plugin(): Plugin {
		return $this->plugin ? $this->plugin : Plugin::instance();
	}

	/**
	 * A suggester wired to core: Claude client, suggestion queue, SEO plugin values, model and settings.
	 *
	 * @param array $options Overrides (MetaSuggester options).
	 * @return MetaSuggester
	 */
	public function suggester( array $options = array() ): MetaSuggester {
		$plugin = $this->plugin();
		return new MetaSuggester(
			$plugin->claude(),
			array( $this, 'sink' ),
			$options + array(
				'model'            => (string) $plugin->settings()->get( 'general', 'ai_model' ),
				'image_scope'      => (string) $plugin->settings()->get( 'meta', 'image_scope' ),
				'current_provider' => array( $this, 'current_values' ),
				'seo_fields'       => $this->seo_fields_available(),
			)
		);
	}

	/**
	 * Sink for MetaSuggester: SuggestionService::create(), after re-adding the SEO plugin's brand suffix to
	 * proposed titles (a custom title replaces the plugin's template, so the suffix must be part of the value).
	 *
	 * @param array $suggestion Suggestion.
	 * @param array $meta       Meta (warnings).
	 * @return int|\WP_Error
	 */
	public function sink( array $suggestion, array $meta = array() ) {
		if ( 'seo_meta' === $suggestion['type'] && 'title' === $suggestion['field'] ) {
			$suffix = $this->brand_suffix( (int) $suggestion['object_id'] );
			if ( '' !== $suffix ) {
				$suggestion['proposed_value'] .= $suffix;
				$meta['brand_suffix']          = $suffix;
				// The UI hides display_suffix from the editable text and shows brand_suffix_resolved instead.
				$meta['display_suffix']        = $suffix;
				$meta['brand_suffix_resolved'] = $this->brand_suffix_resolved( (int) $suggestion['object_id'], $suffix );
			}
		}
		return $this->plugin()->suggestions()->create( $suggestion, $meta );
	}

	/**
	 * seoc_suggestion_approve_value: an edited title arrives without the hidden template suffix; put it back.
	 *
	 * @param mixed $value Edited value.
	 * @param array $row   Suggestion row (decoded).
	 * @return mixed
	 */
	public function restore_display_suffix( $value, $row ) {
		if ( ! is_string( $value ) || ! is_array( $row ) || 'meta' !== ( isset( $row['module'] ) ? $row['module'] : '' ) ) {
			return $value;
		}
		$suffix = isset( $row['meta']['display_suffix'] ) && is_string( $row['meta']['display_suffix'] ) ? $row['meta']['display_suffix'] : '';
		if ( '' === trim( $suffix ) || '' === trim( $value ) ) {
			return $value;
		}
		$value = rtrim( $value );
		$bare  = ltrim( $suffix );
		if ( substr( $value, -strlen( $bare ) ) === $bare ) {
			return $value; // Someone typed it in themselves.
		}
		return $value . $suffix;
	}

	/**
	 * The brand suffix as visitors see it (" - Aqua Shop").
	 *
	 * @param int    $post_id Post ID.
	 * @param string $suffix  Suffix with template variables (computed when null).
	 * @return string
	 */
	public function brand_suffix_resolved( int $post_id, ?string $suffix = null ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$suffix = null === $suffix ? $this->brand_suffix( $post_id ) : $suffix;
		return TitleTemplate::resolve_suffix( $this->plugin()->seo()->active(), $suffix, $post );
	}

	/**
	 * Whether SEO titles and descriptions can be written (Rank Math or Yoast active).
	 *
	 * @return bool
	 */
	public function seo_fields_available(): bool {
		return 'none' !== $this->plugin()->seo()->active();
	}

	/**
	 * Requested fields that can actually be applied now (title/description need an SEO plugin).
	 *
	 * @param string[] $fields Fields.
	 * @return string[]
	 */
	public function applicable_fields( array $fields ): array {
		$fields = array_values( array_intersect( MetaSuggester::FIELDS, $fields ) );
		if ( ! $this->seo_fields_available() ) {
			$fields = array_values( array_diff( $fields, array( 'title', 'description' ) ) );
		}
		return $fields;
	}

	/**
	 * Current SEO values of a post, as MetaSuggester expects them.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function current_values( int $post_id ): array {
		$seo    = $this->plugin()->seo();
		$suffix = $this->brand_suffix( $post_id );
		return array(
			'title'          => TitleTemplate::strip( $seo->get( $post_id, 'title' ), $suffix ),
			'description'    => $seo->get( $post_id, 'description' ),
			'focus_keyword'  => $seo->get( $post_id, 'focus_keyword' ),
			'resolved_title' => $seo->resolved_title( $post_id ),
			'brand_appended' => '' !== $suffix,
			'brand'          => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * Brand suffix for a post's titles ('' = none).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function brand_suffix( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$plugin = $this->plugin();
		return TitleTemplate::suffix( $plugin->seo()->active(), $post, (string) $plugin->settings()->get( 'meta', 'brand_appended' ) );
	}

	/**
	 * Queues posts and makes sure the job runs.
	 *
	 * @param array  $items  [ 'object_type' => 'post', 'object_id', 'fields' ].
	 * @param string $origin Origin label.
	 * @return int Posts queued.
	 */
	public function enqueue( array $items, string $origin ): int {
		$count = $this->store()->add( $items, $origin );
		if ( $count ) {
			$this->schedule( self::JOB_HOOK );
		}
		return $count;
	}

	/**
	 * Whether a batch job is pending or running.
	 *
	 * @return bool
	 */
	public function job_scheduled(): bool {
		$queue = $this->plugin()->queue();
		return $queue->is_scheduled( self::JOB_HOOK ) || $queue->is_scheduled( self::JOB_HOOK_NEXT );
	}

	/**
	 * Background job: processes queued posts within the time budget, then schedules the next batch.
	 *
	 * @return array Summary: processed, remaining, stopped.
	 */
	public function run_batch(): array {
		$summary = array(
			'processed' => 0,
			'remaining' => 0,
			'stopped'   => null,
		);
		if ( ! $this->lock() ) {
			// Another batch is running: try again in a minute.
			$other = self::JOB_HOOK_NEXT === current_action() ? self::JOB_HOOK : self::JOB_HOOK_NEXT;
			$this->plugin()->queue()->schedule_single( $other, time() + MINUTE_IN_SECONDS );
			return $summary;
		}
		$store = $this->store();
		$store->purge();
		$items = $store->next( self::BATCH_SIZE );
		if ( ! $items ) {
			$this->unlock();
			return $summary;
		}

		$budget = new TimeBudget();
		$out    = $this->suggester()->suggest_batch( $items, $budget->remaining() );

		$results = array();
		foreach ( $out['results'] as $result ) {
			$results[ (int) $result['post_id'] ] = $result; // A retried post: the last result counts.
		}
		$remaining = array();
		foreach ( $out['remaining'] as $item ) {
			$remaining[ (int) $item['object_id'] ] = $item;
		}
		foreach ( $items as $item ) {
			$post_id = (int) $item['object_id'];
			$result  = isset( $results[ $post_id ] ) ? $results[ $post_id ] : null;
			if ( isset( $remaining[ $post_id ] ) ) {
				if ( $result ) {
					$store->retry_later( $item['job_id'], (int) $remaining[ $post_id ]['attempts'], self::result_message( $result ) );
				}
				continue;
			}
			if ( null === $result ) {
				continue;
			}
			++$summary['processed'];
			$ok = in_array( $result['status'], array( 'ok', 'partial', 'nothing' ), true );
			$store->finish( $item['job_id'], $ok ? 'done' : 'failed', self::result_message( $result ), isset( $result['created'] ) ? count( $result['created'] ) : 0 );
		}

		$summary['remaining'] = $store->queued_count();
		if ( $out['stopped'] ) {
			$summary['stopped'] = $out['stopped'];
			$store->set_stopped( (string) $out['stopped']['code'], (string) $out['stopped']['message'] );
		} elseif ( $summary['remaining'] > 0 ) {
			$current = current_action();
			$this->schedule( self::JOB_HOOK_NEXT === $current ? self::JOB_HOOK : self::JOB_HOOK_NEXT );
		}
		$this->unlock();
		PostsReport::flush();
		return $summary;
	}

	/**
	 * seoc_request_ai_suggestions listener (e.g. from the audit module).
	 *
	 * @param array  $items  Items.
	 * @param string $origin Origin.
	 */
	public function on_request( $items, $origin = '' ): void {
		if ( is_array( $items ) ) {
			$this->enqueue( $items, is_string( $origin ) && '' !== $origin ? $origin : 'request' );
		}
	}

	/**
	 * First publish of a post → queue suggestions (setting auto_on_publish).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_publish( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) ) {
			return;
		}
		if ( ! isset( PostsReport::post_types()[ $post->post_type ] ) || ! $this->plugin()->claude()->is_available() ) {
			return;
		}
		$this->enqueue(
			array(
				array(
					'object_type' => 'post',
					'object_id'   => $post->ID,
					'fields'      => array( 'title', 'description', 'image_alt' ),
				),
			),
			'publish'
		);
	}

	/**
	 * seoc_ai_site_context: meta coverage for the chat.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function site_context( $sections ): array {
		$sections = is_array( $sections ) ? $sections : array();
		$c        = $this->report()->coverage();
		if ( 'none' === $c['seo_plugin'] ) {
			$sections['meta'] = sprintf( "No SEO plugin (Rank Math or Yoast SEO) is active, so SEO titles and meta descriptions cannot be checked or set.\n- Published posts/pages with images missing alt text: %d", $c['missing_alt'] );
			return $sections;
		}
		$lines  = array(
			sprintf( 'SEO meta coverage of %d published posts/pages%s (SEO plugin: %s):', $c['posts'], $c['scan_limited'] ? ' (the ' . $c['scanned'] . ' most recently modified checked)' : '', $c['seo_plugin'] ),
			sprintf( '- Without a custom SEO title: %d', $c['missing_title'] ),
			sprintf( '- Without a meta description: %d', $c['missing_description'] ),
			sprintf( '- Title over 60 or description over 160 characters: %d', $c['too_long'] ),
			sprintf( '- With images missing alt text: %d', $c['missing_alt'] ),
		);
		$counts = $this->plugin()->suggestions()->store()->counts( array( 'module' => 'meta' ) );
		if ( ! empty( $counts['pending'] ) ) {
			$lines[] = sprintf( '- Meta suggestions waiting for review: %d', (int) $counts['pending'] );
		}
		$sections['meta'] = implode( "\n", $lines );
		return $sections;
	}

	/**
	 * seoc_dashboard_cards: posts missing a title or description.
	 *
	 * @param array $cards Cards.
	 * @return array
	 */
	public function dashboard_card( $cards ): array {
		$cards = is_array( $cards ) ? $cards : array();
		$c     = $this->report()->coverage();
		if ( 'none' === $c['seo_plugin'] ) {
			$cards[] = array(
				'id'          => 'meta-coverage',
				'title'       => __( 'SEO titles & descriptions', 'seo-console' ),
				'label'       => __( 'Posts missing a title or description', 'seo-console' ),
				'value'       => null,
				'status'      => 'unknown',
				'description' => __( 'Needs Rank Math or Yoast SEO: they store SEO titles and descriptions.', 'seo-console' ),
				'link'        => admin_url( 'admin.php?page=seo-console-meta' ),
				'linkLabel'   => __( 'Open SEO Meta', 'seo-console' ),
			);
			return $cards;
		}
		$missing = max( $c['missing_title'], $c['missing_description'] );
		$status  = 0 === $c['posts'] ? 'unknown' : ( 0 === $missing ? 'good' : ( $missing > $c['posts'] / 4 ? 'poor' : 'needs-work' ) );
		$cards[] = array(
			'id'          => 'meta-coverage',
			'title'       => __( 'SEO titles & descriptions', 'seo-console' ),
			'label'       => __( 'Posts missing a title or description', 'seo-console' ),
			'value'       => $missing,
			'status'      => $status,
			'description' => sprintf(
				/* translators: 1: posts without SEO title, 2: posts without description, 3: published posts */
				__( '%1$d without SEO title, %2$d without meta description, out of %3$d published.', 'seo-console' ),
				$c['missing_title'],
				$c['missing_description'],
				$c['posts']
			),
			'link'        => admin_url( 'admin.php?page=seo-console-meta&issue=missing_description' ),
			'linkLabel'   => __( 'Open SEO Meta', 'seo-console' ),
		);
		return $cards;
	}

	/**
	 * Report helper.
	 *
	 * @return PostsReport
	 */
	public function report(): PostsReport {
		return new PostsReport( $this->plugin()->seo() );
	}

	/**
	 * Schedules a job hook.
	 *
	 * @param string $hook Hook.
	 */
	private function schedule( string $hook ): void {
		$queue = $this->plugin()->queue();
		if ( $queue->enqueue( $hook ) ) {
			return;
		}
		// That hook is pending or running already (Action Scheduler keeps hooks unique): make sure a follow-up
		// exists under the other name, so posts queued while a batch runs are not left behind.
		$queue->enqueue( self::JOB_HOOK === $hook ? self::JOB_HOOK_NEXT : self::JOB_HOOK );
	}

	/**
	 * One-line summary of a result.
	 *
	 * @param array $result MetaSuggester result.
	 * @return string
	 */
	private static function result_message( array $result ): string {
		$parts = array();
		if ( ! empty( $result['created'] ) ) {
			/* translators: %d: number of suggestions */
			$parts[] = sprintf( _n( '%d suggestion created.', '%d suggestions created.', count( $result['created'] ), 'seo-console' ), count( $result['created'] ) );
		}
		foreach ( array( 'errors', 'rejected' ) as $key ) {
			foreach ( isset( $result[ $key ] ) ? $result[ $key ] : array() as $entry ) {
				$parts[] = (string) $entry['message'];
			}
		}
		if ( ! $parts && 'nothing' === $result['status'] ) {
			$parts[] = __( 'Nothing to propose.', 'seo-console' );
		}
		return implode( ' ', array_unique( $parts ) );
	}

	/**
	 * Takes the batch lock (expires after LOCK_SECONDS).
	 *
	 * @return bool
	 */
	private function lock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION );
		if ( $now - $held > self::LOCK_SECONDS ) {
			update_option( self::LOCK_OPTION, $now, false );
			return true;
		}
		return false;
	}

	/**
	 * Releases the batch lock.
	 */
	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
