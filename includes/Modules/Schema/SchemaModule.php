<?php
/**
 * Schema module: proposes structured data per post and site-wide, keeps it in an approval queue,
 * and outputs approved schema inside the Rank Math / Yoast graph (or on its own when neither is active).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

use SEOConsole\Modules\AbstractModule;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

final class SchemaModule extends AbstractModule {

	/** @var SchemaService|null */
	private $service;

	/** {@inheritDoc} */
	public function id(): string {
		return 'schema';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'Structured data', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Finds FAQs, how-tos, videos, products and your business details, and adds them as structured data inside your SEO plugin\'s schema after you approve them.', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function settings_schema(): array {
		return SettingsFields::schema();
	}

	/**
	 * The module's service (built on first use).
	 *
	 * @param Plugin|null $plugin Plugin.
	 */
	public function service( ?Plugin $plugin = null ): SchemaService {
		if ( null === $this->service ) {
			$this->service = new SchemaService( $plugin ? $plugin : Plugin::instance() );
		}
		return $this->service;
	}

	/** {@inheritDoc} */
	public function register( Plugin $plugin ): void {
		$service = $this->service( $plugin );
		$plugin->changes()->register_applier( new SchemaApplier() );

		add_action(
			'rest_api_init',
			static function () use ( $plugin, $service ) {
				( new SchemaController( $plugin, $service ) )->register_routes();
			}
		);
		add_action( SchemaService::BATCH_HOOK, array( $service, 'run_batch' ), 10, 2 );
		add_filter( 'seoc_dashboard_cards', array( $this, 'dashboard_card' ) );
	}

	/** {@inheritDoc} */
	public function register_frontend( Plugin $plugin ): void {
		$settings = $plugin->settings();
		if ( ! $settings->get( $this->id(), 'output_enabled' ) ) {
			return;
		}
		$output = new Output(
			$plugin->seo()->active(),
			null,
			static function () use ( $settings ) {
				return $settings->all( 'schema' );
			}
		);
		$output->register();
	}

	/** {@inheritDoc} */
	public function admin_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console-schema',
				'title'    => __( 'Structured data', 'seo-console' ),
				'entry'    => 'schema',
				'position' => 40,
			),
		);
	}

	/** {@inheritDoc} */
	public function on_disable(): void {
		Plugin::instance()->queue()->unschedule( SchemaService::BATCH_HOOK );
	}

	/**
	 * Dashboard card: posts with approved schema, and how many need input.
	 *
	 * @param mixed $cards Cards.
	 * @return array
	 */
	public function dashboard_card( $cards ): array {
		$cards  = is_array( $cards ) ? $cards : array();
		$counts = $this->service()->counts();
		$needs  = $counts['needs_input'];
		/* translators: %d: number of posts. */
		$needs_text = sprintf( _n( '%d post needs your input (for example a video upload date).', '%d posts need your input (for example a video upload date).', $needs, 'seo-console' ), $needs );
		$cards[]    = array(
			'id'          => 'schema',
			'title'       => __( 'Structured data', 'seo-console' ),
			'label'       => __( 'Posts with approved structured data', 'seo-console' ),
			'value'       => $counts['with_schema'],
			'status'      => $needs > 0 ? 'needs-work' : ( $counts['with_schema'] > 0 ? 'good' : 'unknown' ),
			'description' => $needs > 0 ? $needs_text : __( 'Suggest structured data for your posts on the Structured data page.', 'seo-console' ),
			'link'        => admin_url( 'admin.php?page=seo-console-schema' . ( $needs > 0 ? '&status=needs_input' : '' ) ),
			'linkLabel'   => $needs > 0 ? __( 'Review', 'seo-console' ) : __( 'Open', 'seo-console' ),
		);
		return $cards;
	}
}
