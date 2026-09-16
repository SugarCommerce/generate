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
	private const APOSTROPHES = array( "'", '’', '`', '´' );

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

		return 1 === preg_match( '/^[a-z0-9]+(?:' . $quoted . '[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Best-effort conversion to Latin script.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Source text.
	 *
	 * @return string
	 */
	private static function transliterate( string $text ): string {
		static $transliterator = null;

		if ( null === $transliterator ) {
			$transliterator = class_exists( \Transliterator::class )
				? ( \Transliterator::create( 'Any-Latin; Latin-ASCII' ) ?? false )
				: false;
		}

		if ( false !== $transliterator ) {
			$result = $transliterator->transliterate( $text );

			if ( false !== $result ) {
				return $result;
			}
		}

		$result = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );

		return false === $result ? $text : (string) preg_replace( '/[?]+/', '', $result );
	}
}
