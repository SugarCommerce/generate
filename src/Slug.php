<?php
/**
 * URL slug generation.
 *
 * @package   SugarCommerce\Generate
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Generate;

/**
 * Class Slug
 *
 * Turns a title into something safe for a URL.
 *
 * Transliterates rather than stripping, so `Naïve Café` becomes
 * `naive-cafe` and `日本語のファイル` becomes `ri-ben-yunofairu` — not
 * an empty string, which is what a naive `[^a-z0-9]` filter produces for
 * most of the world's titles. Uses ext-intl when present and falls back
 * to iconv.
 *
 * @since 1.0.0
 */
final readonly class Slug {

	/**
	 * Apostrophe forms that join words rather than separating them, so
	 * "Dave's" becomes "daves" and not "dave-s".
	 */
	private const array APOSTROPHES = array( "'", '’', '`', '´' );

	/**
	 * Slugify a string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text      Source text.
	 * @param string $separator Word separator.
	 * @param int    $max_length Truncation limit, on a word boundary.
	 *
	 * @return string Never empty — falls back to `n` plus a short random
	 *                suffix when nothing survives.
	 */
	public static function make( string $text, string $separator = '-', int $max_length = 96 ): string {
		$text = self::transliterate( trim( $text ) );
		$text = strtolower( $text );

		// Apostrophes join words rather than separating them: "dave's"
		// should be "daves", not "dave-s".
		$text = str_replace( self::APOSTROPHES, '', $text );

		$text = (string) preg_replace( '/[^a-z0-9]+/', $separator, $text );
		$text = trim( $text, $separator );

		if ( $max_length > 0 && strlen( $text ) > $max_length ) {
			$text = substr( $text, 0, $max_length );

			// Prefer cutting at a word boundary, unless that leaves
			// almost nothing.
			$last = strrpos( $text, $separator );

			if ( false !== $last && $last > $max_length / 2 ) {
				$text = substr( $text, 0, $last );
			}

			$text = trim( $text, $separator );
		}

		return '' === $text ? 'n' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) : $text;
	}

	/**
	 * Make a slug unique against slugs already taken.
	 *
	 * Appends `-2`, `-3`, and so on. Pass a callable that answers "is
	 * this taken?" so the check can hit whatever storage you use, and
	 * exclude the row being edited so saving a record without renaming it
	 * does not bump its own slug.
	 *
	 * Note this is advisory, not a guarantee — two concurrent requests
	 * can both be told a slug is free. Put a unique index on the column
	 * and treat a violation as the real answer.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $text      Source text or an existing slug.
	 * @param callable(string): bool $is_taken  Returns true when in use.
	 * @param string                 $separator Word separator.
	 *
	 * @return string
	 */
	public static function unique( string $text, callable $is_taken, string $separator = '-' ): string {
		$base = self::make( $text, $separator );

		if ( ! $is_taken( $base ) ) {
			return $base;
		}

		// Bounded so a broken callback cannot spin forever.
		for ( $suffix = 2; $suffix < 1000; $suffix++ ) {
			$candidate = $base . $separator . $suffix;

			if ( ! $is_taken( $candidate ) ) {
				return $candidate;
			}
		}

		return $base . $separator . bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Whether a string is already a clean slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug      Candidate.
	 * @param string $separator Word separator.
	 *
	 * @return bool
	 */
	public static function is_valid( string $slug, string $separator = '-' ): bool {
		$quoted = preg_quote( $separator, '/' );

		return 1 === preg_match( '/^[a-z0-9]+(?:' . $quoted . '[a-z0-9]+)*$/D', $slug );
	}

	/**
	 * A slug somebody typed, accepted as it is or not at all.
	 *
	 * The counterpart to make(), for a name a person chose — a shop's
	 * handle, a username in a URL — where quietly rewriting what they typed
	 * would give them an address they did not pick. So nothing is
	 * transliterated or replaced: the value is trimmed and lower-cased (a
	 * URL that is case-sensitive is one people get wrong), and then it is
	 * either a slug or ''.
	 *
	 * Letters and digits, with hyphens inside and nowhere else: a leading
	 * one reads as a command-line flag, a trailing one is invisible when the
	 * address is read aloud. Names on the reserved list are refused, because
	 * a slug that shares a path with real routes — `/s/admin` beside
	 * `/admin` — is a collision the day somebody adds one.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed    $value    Submitted value. Anything not scalar is ''.
	 * @param int      $min      Shortest allowed. Two-character names are
	 *                           the ones people squat, so three is common.
	 * @param int      $max      Longest allowed. 0 for no limit.
	 * @param string[] $reserved Names nobody may take. Compared without
	 *                           regard to case, so `Admin` here still
	 *                           refuses `admin`.
	 *
	 * @return string The slug, or '' when it is not usable.
	 */
	public static function clean( mixed $value, int $min = 1, int $max = 0, array $reserved = array() ): string {
		$value  = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		$length = strlen( $value );

		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $value ) ) {
			return '';
		}

		if ( $length < $min || ( $max > 0 && $length > $max ) ) {
			return '';
		}

		return in_array( $value, array_map( strtolower( ... ), $reserved ), true ) ? '' : $value;
	}

	/**
	 * Best-effort conversion to Latin script.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Source text.
	 *
	 * @return string ASCII where a conversion was found; the input as it
	 *                was when neither ext-intl nor iconv could help.
	 */
	private static function transliterate( string $text ): string {
		static $transliterator = null;

		// Built once per process: false when ext-intl is missing or the
		// rule will not compile, so the check is not repeated either.
		if ( null === $transliterator ) {
			$transliterator = class_exists( \Transliterator::class )
				? ( \Transliterator::create( 'Any-Latin; Latin-ASCII' ) ?? false )
				: false;
		}

		if ( $transliterator instanceof \Transliterator ) {
			$result = $transliterator->transliterate( $text );

			if ( false !== $result ) {
				return $result;
			}
		}

		$result = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );

		return false === $result ? $text : (string) preg_replace( '/[?]+/', '', $result );
	}
}
