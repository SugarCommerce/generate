<?php
/**
 * Human-readable code generation.
 *
 * @package   Sugar\Generate
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Generate;

/**
 * Class Code
 *
 * Codes a person has to read off a screen, a card, or an email and type
 * somewhere else: discount codes, licence keys, order references,
 * invitations.
 *
 * The alphabet is Crockford base32 — digits plus every letter except
 * `I`, `L`, `O` and `U`. The first three are indistinguishable from `1`
 * and `0` in most typefaces and in nearly all handwriting, and
 * {@see self::normalize()} folds them onto those digits so someone who
 * reads `O` for `0` still gets in rather than being told their code is
 * invalid. `U` is omitted so a random code cannot spell something
 * unfortunate on a customer's invoice.
 *
 * @since 1.0.0
 */
final readonly class Code {

	/**
	 * Handwriting-ambiguous letters, folded onto the digits they
	 * resemble. Crockford's rule.
	 */
	private const FOLD = array( 'O' => '0', 'I' => '1', 'L' => '1' );

	/**
	 * Crockford base32.
	 */
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Generate a code.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $groups    Hyphen-separated groups.
	 * @param int    $length    Characters per group.
	 * @param string $prefix    Optional prefix, e.g. `SALE`.
	 * @param string $separator Group separator.
	 *
	 * @return string e.g. `A3K9M-2PQ7X`.
	 *
	 * @throws \InvalidArgumentException On a degenerate shape.
	 * @throws \Random\RandomException   When the CSPRNG is unavailable.
	 */
	public static function make( int $groups = 2, int $length = 5, string $prefix = '', string $separator = '-' ): string {
		if ( $groups < 1 || $length < 1 ) {
			throw new \InvalidArgumentException( 'A code needs at least one group of at least one character.' );
		}

		$max   = strlen( self::ALPHABET ) - 1;
		$parts = array();

		for ( $g = 0; $g < $groups; $g++ ) {
			$part = '';

			for ( $c = 0; $c < $length; $c++ ) {
				$part .= self::ALPHABET[ random_int( 0, $max ) ];
			}

			$parts[] = $part;
		}

		$code = implode( $separator, $parts );

		return '' === $prefix ? $code : strtoupper( $prefix ) . $separator . $code;
	}

	/**
	 * A licence key.
	 *
	 * Four groups of five is 100 bits of entropy — far past guessable,
	 * and the shape people expect from software licensing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Optional product prefix.
	 *
	 * @return string e.g. `A3K9M-2PQ7X-BR4TC-9WZ2H`.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public static function license( string $prefix = '' ): string {
		return self::make( 4, 5, $prefix );
	}

	/**
	 * A discount code.
	 *
	 * Short enough to type from a banner, and prefixable so a campaign is
	 * recognisable in a support conversation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Campaign prefix, e.g. `SPRING`.
	 * @param int    $length Random characters after the prefix.
	 *
	 * @return string e.g. `SPRING-A3K9M2`.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public static function discount( string $prefix = '', int $length = 6 ): string {
		return self::make( 1, $length, $prefix );
	}

	/**
	 * A human-quotable reference.
	 *
	 * For order numbers and support tickets — short, unambiguous, and
	 * readable over the phone.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Optional prefix, e.g. `ORD`.
	 * @param int    $length Random characters.
	 *
	 * @return string e.g. `ORD-9KM3P2`.
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public static function reference( string $prefix = '', int $length = 6 ): string {
		return self::make( 1, $length, $prefix );
	}

	/**
	 * Normalise a code as typed by a person.
	 *
	 * Uppercases, drops separators and whitespace, and folds the
	 * handwriting-ambiguous letters onto the digits they resemble.
	 * Compare normalised forms rather than raw input, or you will reject
	 * codes that are correct.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Raw input.
	 *
	 * @return string
	 */
	public static function normalize( string $code ): string {
		$clean = strtoupper( (string) preg_replace( '/[\s\-_.]/', '', $code ) );

		return strtr( $clean, self::FOLD );
	}

	/**
	 * Whether two codes are the same once normalised.
	 *
	 * Constant-time, since this is often comparing a submitted licence
	 * key against a stored one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $a First code.
	 * @param string $b Second code.
	 *
	 * @return bool
	 */
	public static function matches( string $a, string $b ): bool {
		return hash_equals( self::normalize( $a ), self::normalize( $b ) );
	}
}
