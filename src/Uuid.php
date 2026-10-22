<?php
/**
 * UUID generation.
 *
 * @package   SugarCommerce\Generate
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Generate;

/**
 * Class Uuid
 *
 * Version 4 and version 7.
 *
 * **Prefer v7 for anything that becomes a database key.** A v4 UUID is
 * entirely random, so consecutive inserts land in random places in the
 * index — every insert dirties a different page, the index fragments,
 * and write throughput falls away as the table grows. A v7 UUID puts a
 * millisecond timestamp in the leading 48 bits, so values increase
 * monotonically and inserts append. Same uniqueness, same 128 bits, none
 * of the fragmentation. It also sorts by creation time for free, which
 * removes a `created_at` index you would otherwise need.
 *
 * Use v4 when the value is public and its creation time is sensitive —
 * a v7 leaks the millisecond it was minted, which for a share link or a
 * password-reset id is information you may not want to hand out.
 *
 * @since 1.0.0
 */
final readonly class Uuid {

	/**
	 * A random (version 4) UUID.
	 *
	 * @since 1.0.0
	 *
	 * @return string 36 characters, hyphenated.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public static function v4(): string {
		$bytes = random_bytes( 16 );

		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0F ) | 0x40 );   // version 4
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3F ) | 0x80 );   // RFC 4122 variant

		return self::format( $bytes );
	}

	/**
	 * A time-ordered (version 7) UUID.
	 *
	 * Layout: 48 bits of Unix milliseconds, 4 version bits, 12 random
	 * bits, 2 variant bits, 62 random bits.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $milliseconds Fixed timestamp, for tests. Null uses
	 *                               the clock.
	 *
	 * @return string 36 characters, hyphenated.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public static function v7( ?int $milliseconds = null ): string {
		$milliseconds ??= (int) ( microtime( true ) * 1000 );

		// 48-bit big-endian timestamp: pack as 64-bit, drop the top two
		// bytes. Good until the year 10889.
		$bytes = substr( pack( 'J', $milliseconds ), 2 ) . random_bytes( 10 );

		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0F ) | 0x70 );   // version 7
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3F ) | 0x80 );   // RFC 4122 variant

		return self::format( $bytes );
	}

	/**
	 * Whether a string is a well-formed UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $uuid    Candidate.
	 * @param int|null $version Require a specific version (1 to 8), or null
	 *                          for any.
	 *
	 * @return bool False for a version outside 1 to 8.
	 */
	public static function is_valid( string $uuid, ?int $version = null ): bool {
		if ( null !== $version && ( $version < 1 || $version > 8 ) ) {
			return false;
		}

		$pattern = null === $version
			? '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'
			: '/^[0-9a-f]{8}-[0-9a-f]{4}-' . $version . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

		return 1 === preg_match( $pattern, $uuid );
	}

	/**
	 * The version number of a UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid UUID.
	 *
	 * @return int|null Null when malformed.
	 */
	public static function version( string $uuid ): ?int {
		if ( ! self::is_valid( $uuid ) ) {
			return null;
		}

		return (int) hexdec( substr( str_replace( '-', '', $uuid ), 12, 1 ) );
	}

	/**
	 * The creation time encoded in a v7 UUID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $uuid A version 7 UUID.
	 *
	 * @return int|null Unix milliseconds, or null when not a v7.
	 */
	public static function timestamp( string $uuid ): ?int {
		if ( ! self::is_valid( $uuid, 7 ) ) {
			return null;
		}

		return (int) hexdec( substr( str_replace( '-', '', $uuid ), 0, 12 ) );
	}

	/**
	 * Hyphenate 16 raw bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes 16 raw bytes.
	 *
	 * @return string Lower-case hex in 8-4-4-4-12 groups.
	 */
	private static function format( string $bytes ): string {
		$hex = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}
}
