<?php
/**
 * Registry of AI tools contributed by modules through the `seoc_ai_tools` filter
 * (see docs/ARCHITECTURE.md §17.1).
 *
 * @package SEOConsole
 */

namespace SEOConsole\AI\Tools;

use SEOConsole\Core\Capabilities;
use SEOConsole\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** Largest JSON result handed back to the model, in bytes. */
	const MAX_RESULT_BYTES = 24000;

	/** @var Plugin */
	private $plugin;

	/** @var array<string, Tool>|null */
	private $tools;

	/**
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Tools of enabled modules, keyed by name.
	 *
	 * @return array<string, Tool>
	 */
	public function all(): array {
		if ( null !== $this->tools ) {
			return $this->tools;
		}
		/**
		 * Filters the AI tools. Each module adds its own Tool objects.
		 *
		 * @param Tool[] $tools Tools.
		 */
		$candidates  = apply_filters( 'seoc_ai_tools', array() );
		$this->tools = array();
		foreach ( is_array( $candidates ) ? $candidates : array() as $tool ) {
			if ( ! $tool instanceof Tool || ! $tool->is_valid() || isset( $this->tools[ $tool->name ] ) ) {
				continue;
			}
			if ( 'core' !== $tool->module && ! $this->plugin->module_enabled( $tool->module ) ) {
				continue;
			}
			$this->tools[ $tool->name ] = $tool;
		}
		ksort( $this->tools );
		return $this->tools;
	}

	/**
	 * One tool.
	 *
	 * @param string $name Tool name.
	 */
	public function get( string $name ): ?Tool {
		$all = $this->all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/** Forgets the cached list (tests, after enabling a module). */
	public function reset(): void {
		$this->tools = null;
	}

	/**
	 * Runs a tool for the current user. The result is always an array that fits MAX_RESULT_BYTES as JSON;
	 * larger results are cut down by the tool's own paging or replaced by an error that tells the model to
	 * ask for less.
	 *
	 * @param string $name  Tool name.
	 * @param array  $input Input (validated against the top-level schema: required keys and unknown keys).
	 * @return array|WP_Error
	 */
	public function run( string $name, array $input ) {
		if ( ! Capabilities::can_manage() ) {
			return new WP_Error( 'seoc_forbidden', __( 'You are not allowed to use AI tools on this site.', 'seo-console' ), array( 'status' => 403 ) );
		}
		$tool = $this->get( $name );
		if ( null === $tool ) {
			return new WP_Error( 'seoc_ai_tool_unknown', __( 'Unknown tool.', 'seo-console' ), array( 'status' => 404 ) );
		}
		$schema     = $tool->input_schema;
		$properties = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
		foreach ( isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array() as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				/* translators: %s: input field name */
				return new WP_Error( 'seoc_ai_tool_input', sprintf( __( 'Missing input "%s".', 'seo-console' ), $key ), array( 'status' => 400 ) );
			}
		}
		$input = array_intersect_key( $input, $properties );

		$result = call_user_func( $tool->callback, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}
		$json = wp_json_encode( $result );
		if ( false === $json ) {
			return new WP_Error( 'seoc_ai_tool_output', __( 'The tool result could not be encoded.', 'seo-console' ), array( 'status' => 500 ) );
		}
		if ( strlen( $json ) > self::MAX_RESULT_BYTES ) {
			return new WP_Error(
				'seoc_ai_tool_too_large',
				__( 'The result is too large. Ask for fewer items (smaller per_page) or a narrower filter.', 'seo-console' ),
				array( 'status' => 413 )
			);
		}
		/**
		 * Fires after an AI tool ran.
		 *
		 * @param Tool  $tool   Tool.
		 * @param array $input  Input.
		 * @param array $result Result.
		 */
		do_action( 'seoc_ai_tool_ran', $tool, $input, $result );
		return $result;
	}
}
