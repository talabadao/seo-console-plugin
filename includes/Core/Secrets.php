<?php
/**
 * Encrypted API keys (see docs/ARCHITECTURE.md §6).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores secrets in option `seoc_secrets` (autoload no), encrypted with libsodium crypto_secretbox
 * (key derived from wp_salt( 'auth' )). A constant SEOC_<NAME> (e.g. SEOC_CLAUDE_API_KEY) overrides storage.
 * Values are never logged, never sent to the browser and never part of a REST response.
 */
final class Secrets {

	const OPTION = 'seoc_secrets';

	const PREFIX = 'v1:';

	/** Secret names the settings screen knows about. */
	const KNOWN = array( 'claude_api_key', 'google_api_key' );

	/**
	 * Names that are stored but could not be decrypted during this request.
	 *
	 * @var array<string, bool>
	 */
	private $undecryptable = array();

	/**
	 * Secret value, or null when missing or undecryptable.
	 *
	 * @param string $name Secret name, e.g. 'claude_api_key'.
	 */
	public function get( string $name ): ?string {
		if ( ! self::valid_name( $name ) ) {
			return null;
		}
		$constant = $this->constant_value( $name );
		if ( null !== $constant ) {
			return $constant;
		}
		$stored = $this->stored();
		if ( ! isset( $stored[ $name ] ) || ! is_string( $stored[ $name ] ) ) {
			return null;
		}
		$plain = $this->decrypt( $stored[ $name ] );
		if ( null === $plain ) {
			$this->undecryptable[ $name ] = true;
			return null;
		}
		unset( $this->undecryptable[ $name ] );
		return $plain;
	}

	/**
	 * Encrypts and stores a value. An empty value deletes the secret.
	 *
	 * @param string $name  Secret name.
	 * @param string $value Plain value.
	 * @return true|WP_Error
	 */
	public function set( string $name, string $value ) {
		if ( ! self::valid_name( $name ) ) {
			return new WP_Error( 'seoc_invalid_secret', __( 'Unknown secret name.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return $this->delete( $name );
		}
		if ( null !== $this->constant_value( $name ) ) {
			return new WP_Error(
				'seoc_secret_constant',
				__( 'This key is set by a constant in wp-config.php and cannot be changed here.', 'seo-console' ),
				array( 'status' => 409 )
			);
		}
		if ( strlen( $value ) > 512 || preg_match( '/[\x00-\x1F\x7F\s]/', $value ) ) {
			return new WP_Error( 'seoc_invalid_secret', __( 'The key contains invalid characters.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$cipher = $this->encrypt( $value );
		if ( null === $cipher ) {
			return new WP_Error( 'seoc_crypto_unavailable', __( 'Encryption is not available on this server.', 'seo-console' ), array( 'status' => 500 ) );
		}
		$stored          = $this->stored();
		$stored[ $name ] = $cipher;
		$this->save( $stored );
		unset( $this->undecryptable[ $name ] );
		return true;
	}

	/**
	 * Deletes a stored secret (a constant cannot be deleted).
	 *
	 * @param string $name Secret name.
	 * @return true|WP_Error
	 */
	public function delete( string $name ) {
		if ( ! self::valid_name( $name ) ) {
			return new WP_Error( 'seoc_invalid_secret', __( 'Unknown secret name.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$stored = $this->stored();
		unset( $stored[ $name ] );
		$this->save( $stored );
		unset( $this->undecryptable[ $name ] );
		return true;
	}

	/**
	 * Whether a usable value exists.
	 *
	 * @param string $name Secret name.
	 */
	public function has( string $name ): bool {
		return null !== $this->get( $name );
	}

	/**
	 * Masked value for display, e.g. 'sk-ant-…3fA9'. Empty string when missing.
	 *
	 * @param string $name Secret name.
	 */
	public function masked( string $name ): string {
		$value = $this->get( $name );
		if ( null === $value || '' === $value ) {
			return '';
		}
		$length = strlen( $value );
		if ( $length < 12 ) {
			return str_repeat( '•', 4 );
		}
		return substr( $value, 0, 7 ) . '…' . substr( $value, -4 );
	}

	/**
	 * Where the value comes from: 'constant', 'stored' or 'none'.
	 *
	 * @param string $name Secret name.
	 */
	public function source( string $name ): string {
		if ( ! self::valid_name( $name ) ) {
			return 'none';
		}
		if ( null !== $this->constant_value( $name ) ) {
			return 'constant';
		}
		$stored = $this->stored();
		return isset( $stored[ $name ] ) ? 'stored' : 'none';
	}

	/**
	 * Public description of a secret: never the value itself.
	 *
	 * @param string $name Secret name.
	 * @return array{has: bool, masked: string, source: string}
	 */
	public function describe( string $name ): array {
		return array(
			'has'    => $this->has( $name ),
			'masked' => $this->masked( $name ),
			'source' => $this->source( $name ),
		);
	}

	/**
	 * Names of stored secrets that cannot be decrypted (salts changed). Checks every stored secret.
	 *
	 * @return string[]
	 */
	public function undecryptable(): array {
		foreach ( array_keys( $this->stored() ) as $name ) {
			if ( null === $this->constant_value( (string) $name ) ) {
				$this->get( (string) $name );
			}
		}
		return array_keys( $this->undecryptable );
	}

	/**
	 * Name of the overriding constant, e.g. SEOC_CLAUDE_API_KEY.
	 *
	 * @param string $name Secret name.
	 */
	public static function constant_name( string $name ): string {
		return 'SEOC_' . strtoupper( $name );
	}

	/**
	 * Whether a secret name is acceptable (lowercase letters, digits, underscores).
	 *
	 * @param string $name Secret name.
	 */
	public static function valid_name( string $name ): bool {
		return (bool) preg_match( '/^[a-z][a-z0-9_]{1,62}$/', $name );
	}

	/**
	 * Value of the overriding constant, if defined and non-empty.
	 *
	 * @param string $name Secret name.
	 */
	private function constant_value( string $name ): ?string {
		$constant = self::constant_name( $name );
		if ( defined( $constant ) ) {
			$value = constant( $constant );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return null;
	}

	/**
	 * Stored (encrypted) values.
	 *
	 * @return array<string, string>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Saves the option without autoloading it.
	 *
	 * @param array<string, string> $stored Encrypted values.
	 */
	private function save( array $stored ): void {
		if ( empty( $stored ) ) {
			delete_option( self::OPTION );
			return;
		}
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $stored, '', false );
			return;
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Encryption key derived from the site's auth salt.
	 */
	private function key(): ?string {
		if ( ! function_exists( 'sodium_crypto_generichash' ) ) {
			return null;
		}
		try {
			return sodium_crypto_generichash( 'seo-console-secrets|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Plain value.
	 */
	private function encrypt( string $plain ): ?string {
		$key = $this->key();
		if ( null === $key || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypts a stored value; null on failure (for example after the salts changed).
	 *
	 * @param string $stored Stored value.
	 */
	private function decrypt( string $stored ): ?string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$key = $this->key();
		if ( null === $key || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}
