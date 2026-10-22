<?php
/**
 * Secure random string generation.
 *
 * @package   SugarCommerce\Generate
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Generate;

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
	private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

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
	 * @since 1.2.0 `$shown`, for a store whose prefix column was sized for
	 *              a different number of secret characters.
	 *
	 * @param string $prefix Product prefix, e.g. `sk`.
	 * @param int    $shown  Characters of the secret kept in the stored
	 *                       prefix. Each one shown is one fewer to guess,
	 *                       so keep it short.
	 *
	 * @return array{key: string, prefix: string, hash: string}
	 *
	 * @throws \InvalidArgumentException When `$shown` is negative or above 12.
	 * @throws \Random\RandomException   When the CSPRNG is unavailable.
	 */
	public static function api_key( string $prefix = 'sk', int $shown = 8 ): array {
		if ( $shown < 0 || $shown > 12 ) {
			throw new \InvalidArgumentException( 'An API key prefix may show between 0 and 12 characters of the secret.' );
		}

		$secret = self::url_safe( 32 );
		$key    = $prefix . '_' . $secret;

		return array(
			'key'    => $key,
			'prefix' => substr( $key, 0, strlen( $prefix ) + 1 + $shown ),
			'hash'   => self::digest( $key ),
		);
	}

	/**
	 * A short random identifier, optionally prefixed: `evt_9f2c41d0a7b3e655`.
	 *
	 * For naming a thing — a delivery, an event, a request — so a log line
	 * and a support ticket can point at the same one. Not a secret: 64 bits
	 * by default is plenty to never collide in practice and too few to be
	 * an authenticator, so never use one as a token. {@see self::hex()} is
	 * for that, and refuses anything under 128 bits.
	 *
	 * The prefix says what kind of thing it is, the way Stripe's `evt_` and
	 * `cus_` do, so an id pasted into the wrong field is recognisably wrong.
	 *
	 * @since 1.2.0
	 *
	 * @param string $prefix Kind, without the underscore. '' for none.
	 * @param int    $bytes  Randomness in bytes; the id has twice as many
	 *                       hex characters.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException Below 4 bytes.
	 * @throws \Random\RandomException   When the CSPRNG is unavailable.
	 */
	public static function id( string $prefix = '', int $bytes = 8 ): string {
		if ( $bytes < 4 ) {
			throw new \InvalidArgumentException( 'An identifier needs at least 4 bytes of randomness.' );
		}

		return ( '' === $prefix ? '' : $prefix . '_' ) . bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * The stored form of a key.
	 *
	 * Split out because three callers need it and two of them are in this
	 * class: a key is hashed when it is minted, when it is verified, and —
	 * in any store that looks a key up rather than iterating — when it is
	 * found. That third caller lives in the application, so the digest has
	 * to be reachable rather than private, or the application writes its own
	 * and the two drift.
	 *
	 * Unkeyed on purpose. The lookup happens by hash, so it must be
	 * derivable from the key alone; there is no secret a database that has
	 * been read could be missing. The key's own entropy is what makes that
	 * safe — see matches() for why a fast hash is right here.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key The key, as issued.
	 *
	 * @return string 64-character hex digest.
	 */
	public static function digest( #[\SensitiveParameter] string $key ): string {
		return hash( 'sha256', $key );
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
		return hash_equals( $hash, self::digest( $key ) );
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
	 * @phpstan-assert int<16, max> $bytes
	 *
	 * @throws \InvalidArgumentException Below 16 bytes.
	 */
	private static function assert_entropy( int $bytes ): void {
		if ( $bytes < 16 ) {
			throw new \InvalidArgumentException( 'A token needs at least 16 bytes (128 bits) of entropy.' );
		}
	}
}
