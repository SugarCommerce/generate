<?php
/**
 * Secure random string generation.
 *
 * @package   SugarMill\Generate
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Generate;

/**
 * Class Token
 *
 * Opaque secrets: API keys, session identifiers, nonces, one-time
 * references.
 *
 * Everything here draws from the CSPRNG. Nothing draws from `rand()`,
 * `mt_rand()`, `uniqid()`, or a hash of the current time — all of which
 * appear in this role regularly and are all predictable enough to
 * enumerate.
 *
 * @since 1.0.0
 */
final readonly class Token {

	/**
	 * Alphanumeric, no ambiguity concerns — these are copy-pasted, not read.
	 */
	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	/**
	 * A hexadecimal token.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes Entropy in bytes. 32 is a good default.
	 *
	 * @return string Twice `$bytes` in length.
	 *
	 * @throws \InvalidArgumentException Below 16 bytes.
	 * @throws \Random\RandomException   When the CSPRNG is unavailable.
	 */
	public static function hex( int $bytes = 32 ): string {
		self::assert_entropy( $bytes );

		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * A URL-safe base64 token.
	 *
	 * Shorter than hex for the same entropy — 32 bytes is 43 characters
	 * rather than 64.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes Entropy in bytes.
	 *
	 * @return string Unpadded, `-` and `_` for `+` and `/`.
	 *
	 * @throws \InvalidArgumentException Below 16 bytes.
	 * @throws \Random\RandomException   When the CSPRNG is unavailable.
	 */
	public static function url_safe( int $bytes = 32 ): string {
		self::assert_entropy( $bytes );

		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	/**
	 * An alphanumeric token of an exact length.
	 *
	 * @since 1.0.0
	 *
	 * @param int $length Characters. Each carries ~5.95 bits.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException Below 22 characters (~128 bits).
	 * @throws \Random\RandomException   When the CSPRNG is unavailable.
	 */
	public static function alphanumeric( int $length = 40 ): string {
		if ( $length < 22 ) {
			throw new \InvalidArgumentException(
				'An alphanumeric token needs at least 22 characters for 128 bits of entropy.'
			);
		}

		$max   = strlen( self::ALPHABET ) - 1;
		$token = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$token .= self::ALPHABET[ random_int( 0, $max ) ];
		}

		return $token;
	}

	/**
	 * A prefixed API key, with the lookup prefix separated out.
	 *
	 * Returns the key to show once, a short prefix to store alongside the
	 * hash, and the hash to store. The prefix lets you find the row
	 * without a full table scan and lets a user recognise which key is
	 * which in a list — the pattern GitHub and Stripe both use.
	 *
	 * Store `hash`, never `key`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Product prefix, e.g. `sk`.
	 *
	 * @return array{key: string, prefix: string, hash: string}
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public static function api_key( string $prefix = 'sk' ): array {
		$secret = self::url_safe( 32 );
		$key    = $prefix . '_' . $secret;

		return array(
			'key'    => $key,
			'prefix' => substr( $key, 0, strlen( $prefix ) + 1 + 8 ),
			'hash'   => hash( 'sha256', $key ),
		);
	}

	/**
	 * Whether a submitted key matches a stored hash.
	 *
	 * Constant-time.
	 *
	 * A fast hash is correct here, unlike for passwords: the key has 256
	 * bits of entropy, so there is no dictionary to run and nothing for a
	 * slow hash to buy. It also keeps verification cheap enough to run on
	 * every API request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Submitted key.
	 * @param string $hash Stored hash.
	 *
	 * @return bool
	 */
	public static function matches( #[\SensitiveParameter] string $key, string $hash ): bool {
		return hash_equals( $hash, hash( 'sha256', $key ) );
	}

	/**
	 * Refuse dangerously short tokens.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes Requested entropy.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Below 16 bytes.
	 */
	private static function assert_entropy( int $bytes ): void {
		if ( $bytes < 16 ) {
			throw new \InvalidArgumentException( 'A token needs at least 16 bytes (128 bits) of entropy.' );
		}
	}
}
