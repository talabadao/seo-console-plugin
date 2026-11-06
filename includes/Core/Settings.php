<?php
/**
 * Schema-validated settings stored in one option (see docs/ARCHITECTURE.md §6).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Option `seoc_settings` = { general: {...}, modules: { <id>: { enabled: bool, ...module keys } } }.
 */
final class Settings {

	const OPTION = 'seoc_settings';

	const GENERAL = 'general';

	/** Supported schema types. */
	const TYPES = array( 'bool', 'int', 'string', 'enum', 'url', 'string_list', 'url_list' );

	/**
	 * Module schemas: id => [ 'schema' => array, 'default_enabled' => bool ].
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $modules = array();

	/**
	 * Schema of the general section.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function general_schema(): array {
		return array(
			'ai_model'               => array(
				'type'    => 'enum',
				'default' => 'claude-opus-5-5',
				'enum'    => array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5' ),
			),
			'ai_monthly_budget_usd'  => array(
				'type'    => 'int',
				'default' => 20,
				'min'     => 0,
				'max'     => 100000,
			),
			'keep_data_on_uninstall' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'seo_plugin'             => array(
				'type'    => 'enum',
				'default' => 'auto',
				'enum'    => array( 'auto', 'rankmath', 'yoast', 'none' ),
			),
			'safe_mode'              => array(
				'type'    => 'bool',
				'default' => false,
			),
			'log_level'              => array(
				'type'    => 'enum',
				'default' => 'info',
				'enum'    => array( 'debug', 'info', 'warning', 'error' ),
			),
		);
	}

	/**
	 * Registers a module's settings schema (called by Plugin while loading modules).
	 *
	 * @param string $module          Module id.
	 * @param array  $schema          Settings schema (see ModuleInterface::settings_schema()).
	 * @param bool   $default_enabled Whether a fresh install enables the module.
	 */
	public function register_module( string $module, array $schema, bool $default_enabled = true ): void {
		$clean = array();
		foreach ( $schema as $key => $def ) {
			if ( ! is_string( $key ) || 'enabled' === $key || ! is_array( $def ) ) {
				continue;
			}
			if ( ! isset( $def['type'] ) || ! in_array( $def['type'], self::TYPES, true ) ) {
				continue;
			}
			$clean[ $key ] = $def;
		}
		$this->modules[ $module ] = array(
			'schema'          => $clean,
			'default_enabled' => $default_enabled,
		);
	}

	/**
	 * Whether a module is known to Settings.
	 *
	 * @param string $module Module id.
	 */
	public function has_module( string $module ): bool {
		return isset( $this->modules[ $module ] );
	}

	/**
	 * Registered module ids.
	 *
	 * @return string[]
	 */
	public function module_ids(): array {
		return array_keys( $this->modules );
	}

	/**
	 * Schema for a section ('general' or a module id). Modules get an implicit `enabled` bool.
	 *
	 * @param string $module Section.
	 * @return array<string, array<string, mixed>>|null Null for an unknown section.
	 */
	public function schema( string $module ): ?array {
		if ( self::GENERAL === $module ) {
			return self::general_schema();
		}
		if ( ! isset( $this->modules[ $module ] ) ) {
			return null;
		}
		return array_merge(
			array(
				'enabled' => array(
					'type'    => 'bool',
					'default' => (bool) $this->modules[ $module ]['default_enabled'],
				),
			),
			$this->modules[ $module ]['schema']
		);
	}

	/**
	 * One value (stored or default). Null for unknown section/key.
	 *
	 * @param string $module Section ('general' or module id).
	 * @param string $key    Key.
	 * @return mixed
	 */
	public function get( string $module, string $key ) {
		$schema = $this->schema( $module );
		if ( null === $schema || ! isset( $schema[ $key ] ) ) {
			return null;
		}
		$stored = $this->stored_section( $module );
		if ( array_key_exists( $key, $stored ) ) {
			$clean = $this->sanitize_value( $schema[ $key ], $stored[ $key ] );
			if ( ! is_wp_error( $clean ) ) {
				return $clean;
			}
		}
		return $this->default_value( $schema[ $key ] );
	}

	/**
	 * All values of a section, defaults filled in.
	 *
	 * @param string $module Section.
	 * @return array<string, mixed>
	 */
	public function all( string $module ): array {
		$schema = $this->schema( $module );
		if ( null === $schema ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( $schema ) as $key ) {
			$out[ $key ] = $this->get( $module, $key );
		}
		return $out;
	}

	/**
	 * Validates and normalises a value without saving it.
	 *
	 * @param string $module Section.
	 * @param string $key    Key.
	 * @param mixed  $value  Raw value.
	 * @return mixed|WP_Error Clean value.
	 */
	public function validate( string $module, string $key, $value ) {
		$schema = $this->schema( $module );
		if ( null === $schema ) {
			return new WP_Error(
				'seoc_unknown_module',
				/* translators: %s: module id */
				sprintf( __( 'Unknown settings section "%s".', 'seo-console' ), $module ),
				array( 'status' => 400 )
			);
		}
		if ( ! isset( $schema[ $key ] ) ) {
			return new WP_Error(
				'seoc_unknown_setting',
				/* translators: 1: section, 2: key */
				sprintf( __( 'Unknown setting "%1$s.%2$s".', 'seo-console' ), $module, $key ),
				array( 'status' => 400 )
			);
		}
		return $this->sanitize_value( $schema[ $key ], $value, $module . '.' . $key );
	}

	/**
	 * Saves one value after validation.
	 *
	 * @param string $module Section.
	 * @param string $key    Key.
	 * @param mixed  $value  Raw value.
	 * @return true|WP_Error
	 */
	public function set( string $module, string $key, $value ) {
		return $this->update( $module, array( $key => $value ) );
	}

	/**
	 * Saves several values of one section. All-or-nothing: nothing is saved when one value is invalid.
	 *
	 * @param string               $module Section.
	 * @param array<string, mixed> $values Key => raw value.
	 * @return true|WP_Error
	 */
	public function update( string $module, array $values ) {
		$clean = array();
		foreach ( $values as $key => $value ) {
			$result = $this->validate( $module, (string) $key, $value );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$clean[ $key ] = $result;
		}
		if ( empty( $clean ) ) {
			return true;
		}

		$before_enabled = self::GENERAL === $module ? null : $this->is_enabled( $module );

		$option = $this->stored();
		if ( self::GENERAL === $module ) {
			$option['general'] = array_merge( $this->stored_section( $module ), $clean );
		} else {
			$option['modules'][ $module ] = array_merge( $this->stored_section( $module ), $clean );
		}
		update_option( self::OPTION, $this->prune( $option ), true );

		if ( null !== $before_enabled && array_key_exists( 'enabled', $clean ) && $before_enabled !== $clean['enabled'] ) {
			/**
			 * Fires when a module is switched on or off.
			 *
			 * @param string $module  Module id.
			 * @param bool   $enabled New state.
			 */
			do_action( 'seoc_module_toggled', $module, (bool) $clean['enabled'] );
		}
		return true;
	}

	/**
	 * Whether a module is enabled (stored value, else its default).
	 *
	 * @param string $module Module id.
	 */
	public function is_enabled( string $module ): bool {
		if ( ! isset( $this->modules[ $module ] ) ) {
			return false;
		}
		return (bool) $this->get( $module, 'enabled' );
	}

	/**
	 * Switches a module on or off.
	 *
	 * @param string $module  Module id.
	 * @param bool   $enabled New state.
	 * @return true|WP_Error
	 */
	public function set_enabled( string $module, bool $enabled ) {
		return $this->set( $module, 'enabled', $enabled );
	}

	/**
	 * Writes the defaults for keys that are not stored yet (activation).
	 */
	public function install_defaults(): void {
		$option = $this->stored();
		foreach ( self::general_schema() as $key => $def ) {
			if ( ! isset( $option['general'][ $key ] ) ) {
				$option['general'][ $key ] = $this->default_value( $def );
			}
		}
		foreach ( $this->modules as $id => $module ) {
			if ( ! isset( $option['modules'][ $id ]['enabled'] ) ) {
				$option['modules'][ $id ]['enabled'] = (bool) $module['default_enabled'];
			}
		}
		update_option( self::OPTION, $option, true );
	}

	/**
	 * Default value of a schema entry.
	 *
	 * @param array $def Schema entry.
	 * @return mixed
	 */
	public function default_value( array $def ) {
		if ( array_key_exists( 'default', $def ) ) {
			return $def['default'];
		}
		switch ( $def['type'] ) {
			case 'bool':
				return false;
			case 'int':
				return isset( $def['min'] ) ? (int) $def['min'] : 0;
			case 'enum':
				return isset( $def['enum'][0] ) ? $def['enum'][0] : '';
			case 'string_list':
			case 'url_list':
				return array();
			default:
				return '';
		}
	}

	/**
	 * Sanitises one value against its schema entry.
	 *
	 * @param array  $def   Schema entry.
	 * @param mixed  $value Raw value.
	 * @param string $name  Name for error messages.
	 * @return mixed|WP_Error
	 */
	private function sanitize_value( array $def, $value, string $name = '' ) {
		$invalid = static function ( string $message ) use ( $name ) {
			return new WP_Error(
				'seoc_invalid_setting',
				$name ? $name . ': ' . $message : $message,
				array(
					'status' => 400,
					'key'    => $name,
				)
			);
		};

		switch ( $def['type'] ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( in_array( $value, array( 1, '1', 'true', 'yes', 'on' ), true ) ) {
					return true;
				}
				if ( in_array( $value, array( 0, '0', 'false', 'no', 'off', '', null ), true ) ) {
					return false;
				}
				return $invalid( __( 'expected true or false.', 'seo-console' ) );

			case 'int':
				if ( is_bool( $value ) || ! is_numeric( $value ) || (float) (int) $value !== (float) $value ) {
					return $invalid( __( 'expected a whole number.', 'seo-console' ) );
				}
				$value = (int) $value;
				if ( isset( $def['min'] ) && $value < $def['min'] ) {
					/* translators: %d: minimum */
					return $invalid( sprintf( __( 'must be at least %d.', 'seo-console' ), $def['min'] ) );
				}
				if ( isset( $def['max'] ) && $value > $def['max'] ) {
					/* translators: %d: maximum */
					return $invalid( sprintf( __( 'must be at most %d.', 'seo-console' ), $def['max'] ) );
				}
				return $value;

			case 'enum':
				$allowed = isset( $def['enum'] ) ? (array) $def['enum'] : array();
				if ( ! is_scalar( $value ) || ! in_array( (string) $value, array_map( 'strval', $allowed ), true ) ) {
					/* translators: %s: allowed values */
					return $invalid( sprintf( __( 'must be one of: %s.', 'seo-console' ), implode( ', ', $allowed ) ) );
				}
				foreach ( $allowed as $option ) {
					if ( (string) $option === (string) $value ) {
						return $option;
					}
				}
				return $value;

			case 'string':
				if ( ! is_scalar( $value ) && null !== $value ) {
					return $invalid( __( 'expected text.', 'seo-console' ) );
				}
				$value = ! empty( $def['multiline'] ) ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
				if ( isset( $def['max'] ) && mb_strlen( $value ) > (int) $def['max'] ) {
					/* translators: %d: maximum length */
					return $invalid( sprintf( __( 'must be at most %d characters.', 'seo-console' ), $def['max'] ) );
				}
				return $value;

			case 'url':
				if ( null === $value || '' === $value ) {
					return '';
				}
				if ( ! is_string( $value ) ) {
					return $invalid( __( 'expected a URL.', 'seo-console' ) );
				}
				$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
				if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					return $invalid( __( 'expected a valid http(s) URL.', 'seo-console' ) );
				}
				return $url;

			case 'string_list':
			case 'url_list':
				if ( is_string( $value ) ) {
					$value = preg_split( '/[\r\n]+/', $value );
				}
				if ( null === $value ) {
					$value = array();
				}
				if ( ! is_array( $value ) ) {
					return $invalid( __( 'expected a list.', 'seo-console' ) );
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) ) {
						return $invalid( __( 'expected a list of text values.', 'seo-console' ) );
					}
					$item = trim( (string) $item );
					if ( '' === $item ) {
						continue;
					}
					if ( 'url_list' === $def['type'] ) {
						$url = esc_url_raw( $item, array( 'http', 'https' ) );
						if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
							/* translators: %s: rejected value */
							return $invalid( sprintf( __( '"%s" is not a valid http(s) URL.', 'seo-console' ), sanitize_text_field( $item ) ) );
						}
						$out[] = $url;
					} else {
						$out[] = sanitize_text_field( $item );
					}
				}
				$out = array_values( array_unique( $out ) );
				if ( isset( $def['max'] ) && count( $out ) > (int) $def['max'] ) {
					/* translators: %d: maximum number of items */
					return $invalid( sprintf( __( 'at most %d items.', 'seo-console' ), $def['max'] ) );
				}
				return $out;
		}
		return $invalid( __( 'unsupported setting type.', 'seo-console' ) );
	}

	/**
	 * Raw option, normalised to the expected shape.
	 *
	 * @return array{general: array, modules: array}
	 */
	private function stored(): array {
		$option = get_option( self::OPTION, array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}
		if ( ! isset( $option['general'] ) || ! is_array( $option['general'] ) ) {
			$option['general'] = array();
		}
		if ( ! isset( $option['modules'] ) || ! is_array( $option['modules'] ) ) {
			$option['modules'] = array();
		}
		return $option;
	}

	/**
	 * Stored values of one section.
	 *
	 * @param string $module Section.
	 * @return array<string, mixed>
	 */
	private function stored_section( string $module ): array {
		$option = $this->stored();
		if ( self::GENERAL === $module ) {
			return $option['general'];
		}
		return isset( $option['modules'][ $module ] ) && is_array( $option['modules'][ $module ] ) ? $option['modules'][ $module ] : array();
	}

	/**
	 * Drops unknown keys of registered sections (keeps sections of modules that are not loaded right now).
	 *
	 * @param array $option Option value.
	 * @return array
	 */
	private function prune( array $option ): array {
		$option['general'] = array_intersect_key( $option['general'], self::general_schema() );
		foreach ( $option['modules'] as $id => $values ) {
			$schema = $this->schema( (string) $id );
			if ( null !== $schema && is_array( $values ) ) {
				$option['modules'][ $id ] = array_intersect_key( $values, $schema );
			}
		}
		return $option;
	}
}
