<?php
/**
 * Removes SEO Console data on uninstall unless "Keep data on uninstall" is on.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

use SEOConsole\Core\Database\Schema;
use SEOConsole\Jobs\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Drops `{prefix}seoc_*` tables, `seoc_*` options/transients, `_seoc_*` post/term meta and the plugin's Action
 * Scheduler actions. Runs per site on multisite.
 */
final class Uninstaller {

	/**
	 * Entry point from uninstall.php.
	 */
	public static function run(): void {
		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run_site();
				restore_current_blog();
			}
			return;
		}
		self::run_site();
	}

	/**
	 * Uninstalls the current site.
	 *
	 * @return bool Whether data was removed (false when kept).
	 */
	public static function run_site(): bool {
		$settings = get_option( Settings::OPTION, array() );
		$keep     = is_array( $settings ) && ! empty( $settings['general']['keep_data_on_uninstall'] );
		if ( $keep ) {
			return false;
		}
		self::delete_actions();
		Schema::drop_all();
		self::delete_options();
		self::delete_meta();
		return true;
	}

	/**
	 * Deletes all `seoc_*` options and transients.
	 */
	public static function delete_options(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall cleanup.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'seoc_' ) . '%',
				$wpdb->esc_like( '_transient_seoc_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_seoc_' ) . '%'
			)
		);
		// phpcs:enable
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Deletes `_seoc_*` post and term meta.
	 */
	public static function delete_meta(): void {
		global $wpdb;
		$like = $wpdb->esc_like( '_seoc_' ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall cleanup.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $like ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->termmeta, $like ) );
		// phpcs:enable
		wp_cache_flush();
	}

	/**
	 * Removes the plugin's Action Scheduler actions (API when loaded, else directly from AS tables).
	 */
	public static function delete_actions(): void {
		global $wpdb;
		if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			as_unschedule_all_actions( '', array(), Queue::GROUP );
		}
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed AS table names.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $groups ) ) ) !== $groups ) {
			return;
		}
		$group_id = $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, Queue::GROUP ) );
		if ( $group_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE group_id = %d AND status IN ('pending','in-progress')", $actions, (int) $group_id ) );
		}
		// phpcs:enable
	}
}
