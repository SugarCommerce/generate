<?php
/**
 * Generate test suite.
 *
 * @package   SugarCommerce\Generate
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Generate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Generate\Code;
use SugarCommerce\Generate\Slug;
use SugarCommerce\Generate\Token;
use SugarCommerce\Generate\Uuid;

final class GenerateTest extends TestCase {

	/* ─── UUID ──────────────────────────────────────────────────────── */

	public function test_v4_is_well_formed(): void {
		$uuid = Uuid::v4();

		$this->assertSame( 36, strlen( $uuid ) );
		$this->assertTrue( Uuid::is_valid( $uuid, 4 ) );
		$this->assertSame( 4, Uuid::version( $uuid ) );
	}

	public function test_v7_is_well_formed(): void {
		$uuid = Uuid::v7();

		$this->assertSame( 36, strlen( $uuid ) );
		$this->assertTrue( Uuid::is_valid( $uuid, 7 ) );
		$this->assertSame( 7, Uuid::version( $uuid ) );
	}

	public function test_uuids_are_unique(): void {
		$v4 = array();
		$v7 = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$v4[] = Uuid::v4();
			$v7[] = Uuid::v7();
		}

		$this->assertCount( 500, array_unique( $v4 ) );
		$this->assertCount( 500, array_unique( $v7 ) );
	}

	/**
	 * The reason to prefer v7 as a database key: values increase, so
	 * inserts append to the index instead of scattering across it.
	 */
	public function test_v7_sorts_by_creation_time(): void {
		$uuids = array();

		foreach ( range( 0, 20 ) as $offset ) {
			$uuids[] = Uuid::v7( 1700000000000 + $offset * 1000 );
		}

		$sorted = $uuids;
		sort( $sorted );

		$this->assertSame( $uuids, $sorted );
	}

	public function test_v4_does_not_sort_by_creation_time(): void {
		// Contrast with the above — this is the fragmentation problem.
		$uuids = array();

		for ( $i = 0; $i < 50; $i++ ) {
			$uuids[] = Uuid::v4();
		}

		$sorted = $uuids;
		sort( $sorted );

		$this->assertNotSame( $uuids, $sorted );
	}

	public function test_v7_timestamps_round_trip(): void {
		$this->assertSame( 1700000000123, Uuid::timestamp( Uuid::v7( 1700000000123 ) ) );
	}

	public function test_timestamps_are_only_read_from_v7(): void {
		$this->assertNull( Uuid::timestamp( Uuid::v4() ) );
		$this->assertNull( Uuid::timestamp( 'not-a-uuid' ) );
	}

	#[DataProvider( 'malformed_uuids' )]
	public function test_malformed_uuids_are_rejected( string $uuid ): void {
		$this->assertFalse( Uuid::is_valid( $uuid ) );
		$this->assertNull( Uuid::version( $uuid ) );
	}

	/** @return array<string, array{0: string}> */
	public static function malformed_uuids(): array {
		return array(
			'empty'        => array( '' ),
			'no hyphens'   => array( '0189d6d4d0f37c8a9c1e2f3a4b5c6d7e' ),
			'too short'    => array( '0189d6d4-d0f3-7c8a-9c1e-2f3a4b5c6d' ),
			'bad version'  => array( '0189d6d4-d0f3-9c8a-9c1e-2f3a4b5c6d7e' ),
			'bad variant'  => array( '0189d6d4-d0f3-7c8a-0c1e-2f3a4b5c6d7e' ),
			'not hex'      => array( 'zzzzzzzz-d0f3-7c8a-9c1e-2f3a4b5c6d7e' ),
		);
	}

	/* ─── Codes ─────────────────────────────────────────────────────── */

	public function test_licence_keys_have_the_expected_shape(): void {
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{5}(-[0-9A-HJKMNP-TV-Z]{5}){3}$/', Code::license() );
	}

	public function test_licence_keys_can_be_prefixed(): void {
		$this->assertStringStartsWith( 'PRO-', Code::license( 'pro' ) );
	}

	public function test_discount_codes_are_prefixable(): void {
		$this->assertMatchesRegularExpression( '/^SPRING-[0-9A-HJKMNP-TV-Z]{6}$/', Code::discount( 'spring' ) );
	}

	#[DataProvider( 'ambiguous_letters' )]
	public function test_ambiguous_letters_never_appear( string $letter ): void {
		$all = '';

		for ( $i = 0; $i < 200; $i++ ) {
			$all .= Code::license();
		}

		$this->assertStringNotContainsString( $letter, $all );
	}

	/** @return array<string, array{0: string}> */
	public static function ambiguous_letters(): array {
		return array( 'I' => array( 'I' ), 'L' => array( 'L' ), 'O' => array( 'O' ), 'U' => array( 'U' ) );
	}

	public function test_codes_are_unique(): void {
		$codes = array();

		for ( $i = 0; $i < 300; $i++ ) {
			$codes[] = Code::license();
		}

		$this->assertCount( 300, array_unique( $codes ) );
	}

	public function test_misread_codes_still_match(): void {
		// Someone reading O for 0 and l for 1 off a printed card.
		$this->assertTrue( Code::matches( '0ABCD-1EFGH', 'oabcd-lefgh' ) );
		$this->assertTrue( Code::matches( 'A3K9M-2PQ7X', 'a3k9m 2pq7x' ) );
	}

	public function test_genuinely_different_codes_do_not_match(): void {
		$this->assertFalse( Code::matches( 'A3K9M-2PQ7X', 'A3K9M-2PQ7Y' ) );
	}

	public function test_degenerate_code_shapes_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Code::make( 0, 5 );
	}

	/* ─── Slugs ─────────────────────────────────────────────────────── */

	#[DataProvider( 'slug_cases' )]
	public function test_slugs( string $input, string $expected ): void {
		$this->assertSame( $expected, Slug::make( $input ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function slug_cases(): array {
		return array(
			'plain'        => array( 'Hello World', 'hello-world' ),
			'punctuation'  => array( 'Hello, World!', 'hello-world' ),
			'apostrophe'   => array( "Dave's Samples", 'daves-samples' ),
			'curly quote'  => array( 'Dave’s Samples', 'daves-samples' ),
			'runs'         => array( 'a   b---c', 'a-b-c' ),
			'edges'        => array( '  --Hello--  ', 'hello' ),
			'numbers'      => array( 'Volume 2 Part 3', 'volume-2-part-3' ),
			'accents'      => array( 'Naïve Café', 'naive-cafe' ),
			'german'       => array( 'Übungsstück', 'ubungsstuck' ),
		);
	}

	#[RequiresPhpExtension( 'intl' )]
	public function test_non_latin_titles_produce_a_usable_slug(): void {
		// A naive [^a-z0-9] filter returns nothing at all for these.
		$this->assertSame( 'ri-ben-yunofairu', Slug::make( '日本語のファイル' ) );
		$this->assertSame( 'russkij-fajl', Slug::make( 'Русский файл' ) );
	}

	public function test_a_slug_is_never_empty(): void {
		$this->assertNotSame( '', Slug::make( '!!!' ) );
		$this->assertNotSame( '', Slug::make( '' ) );
		$this->assertNotSame( '', Slug::make( '🎵🎹' ) );
	}

	public function test_slugs_truncate_on_a_word_boundary(): void {
		$slug = Slug::make( 'the quick brown fox jumps over the lazy dog and keeps running onwards', '-', 30 );

		$this->assertLessThanOrEqual( 30, strlen( $slug ) );
		$this->assertStringEndsNotWith( '-', $slug );
		// Cut at a boundary, not mid-word.
		$this->assertStringNotContainsString( 'ju-', $slug );
	}

	public function test_uniqueness_appends_a_counter(): void {
		$taken = array( 'hello-world' => true, 'hello-world-2' => true );

		$this->assertSame(
			'hello-world-3',
			Slug::unique( 'Hello World', static fn( string $s ): bool => isset( $taken[ $s ] ) )
		);
	}

	public function test_an_unused_slug_is_returned_unchanged(): void {
		$this->assertSame( 'hello-world', Slug::unique( 'Hello World', static fn(): bool => false ) );
	}

	public function test_slug_validation(): void {
		$this->assertTrue( Slug::is_valid( 'hello-world' ) );
		$this->assertTrue( Slug::is_valid( 'a1' ) );
		$this->assertFalse( Slug::is_valid( 'Hello-World' ) );
		$this->assertFalse( Slug::is_valid( 'hello--world' ) );
		$this->assertFalse( Slug::is_valid( '-hello' ) );
		$this->assertFalse( Slug::is_valid( '' ) );
	}

	/* ─── Tokens ────────────────────────────────────────────────────── */

	public function test_hex_tokens_are_the_right_length(): void {
		$this->assertSame( 64, strlen( Token::hex( 32 ) ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]+$/', Token::hex() );
	}

	public function test_url_safe_tokens_survive_url_encoding(): void {
		$token = Token::url_safe( 32 );

		$this->assertSame( $token, rawurlencode( $token ) );
		$this->assertStringNotContainsString( '=', $token );
	}

	public function test_alphanumeric_tokens_are_the_requested_length(): void {
		$this->assertSame( 40, strlen( Token::alphanumeric( 40 ) ) );
	}

	public function test_tokens_are_unique(): void {
		$tokens = array();

		for ( $i = 0; $i < 300; $i++ ) {
			$tokens[] = Token::hex();
		}

		$this->assertCount( 300, array_unique( $tokens ) );
	}

	#[DataProvider( 'weak_entropy' )]
	public function test_dangerously_short_tokens_are_refused( string $method, int $size ): void {
		$this->expectException( \InvalidArgumentException::class );
		Token::$method( $size );
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function weak_entropy(): array {
		return array(
			'hex 8 bytes'        => array( 'hex', 8 ),
			'url_safe 4 bytes'   => array( 'url_safe', 4 ),
			'alphanumeric 10'    => array( 'alphanumeric', 10 ),
		);
	}

	/* ─── API keys ──────────────────────────────────────────────────── */

	public function test_api_keys_expose_a_lookup_prefix(): void {
		$key = Token::api_key( 'sk' );

		$this->assertStringStartsWith( 'sk_', $key['key'] );
		$this->assertStringStartsWith( 'sk_', $key['prefix'] );
		$this->assertSame( 11, strlen( $key['prefix'] ) );
		$this->assertStringStartsWith( $key['prefix'], $key['key'] );
	}

	public function test_the_stored_hash_is_not_the_key(): void {
		$key = Token::api_key();

		$this->assertNotSame( $key['key'], $key['hash'] );
		$this->assertSame( 64, strlen( $key['hash'] ) );
		$this->assertStringNotContainsString( $key['key'], $key['hash'] );
	}

	public function test_api_keys_verify_against_their_hash(): void {
		$key = Token::api_key();

		$this->assertTrue( Token::matches( $key['key'], $key['hash'] ) );
		$this->assertFalse( Token::matches( $key['key'] . 'x', $key['hash'] ) );
		$this->assertFalse( Token::matches( 'sk_wrong', $key['hash'] ) );
	}

	public function test_api_keys_are_unique(): void {
		$keys = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$keys[] = Token::api_key()['key'];
		}

		$this->assertCount( 200, array_unique( $keys ) );
	}
}
