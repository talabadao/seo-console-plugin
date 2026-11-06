<?php
/**
 * Capability names used by SEO Console (see docs/ARCHITECTURE.md §2).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for capability checks.
 */
final class Capabilities {

	/** Everything in SEO Console unless stated otherwise. */
	const MANAGE = 'manage_options';

	/** GET /status (HelperCompat, used by the SEO Console web app). */
	const STATUS = 'edit_posts';

	/**
	 * Whether the current user may manage SEO Console.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::MANAGE );
	}

	/**
	 * Whether the current user may read the helper status route.
	 */
	public static function can_read_status(): bool {
		return current_user_can( self::STATUS );
	}
}
