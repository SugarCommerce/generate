# Generate

Identifier generation: UUID v4 and v7, human-readable licence and discount codes, transliterating slugs, and CSPRNG-backed API keys. Zero dependencies.

## Why

Every application ends up writing these four, and usually writes at least one of them badly. The recurring mistakes:

- **A token from `uniqid()` or `mt_rand()`.** Both are predictable. `uniqid()` is the current time in hex — an attacker who knows roughly when an account was created has narrowed the search to a few thousand values.
- **A licence key with `I`, `O` and `U` in it.** Somebody reads it off a screen, types `O` for zero, and you get a support ticket — or the random generator spells something unfortunate on an invoice.
- **A slug that drops non-Latin text entirely.** `日本語のタイトル` becomes `-`, and every such post collides.
- **API keys stored in plaintext.** A database leak becomes an account takeover.

## Features

- 🔐 **CSPRNG only** — `random_bytes` and `random_int`; nothing here is seeded or time-derived.
- 🆔 **UUID v4 and v7** — v7 sorts by creation time, which matters for a primary key.
- 🎫 **Unambiguous codes** — Crockford base32, and reading `O` for `0` still validates.
- 🌏 **Transliterating slugs** — Japanese, Cyrillic and Greek become readable Latin rather than nothing.
- 🔑 **Hashed API keys** — the raw key is returned once; only its hash is meant to be stored.
- ⏱️ **Constant-time comparison** — key and code verification go through `hash_equals`.

## Requirements

PHP 8.5+ (`ext-intl` optional, for better transliteration)

## Installation

```bash
composer require sugarcommerce/generate
```

## UUIDs

```php
use SugarCommerce\Generate\Uuid;

Uuid::v4();                       // random
Uuid::v7();                       // time-ordered
Uuid::is_valid( $value );
Uuid::is_valid( $value, 7 );      // and of that version
Uuid::version( $value );          // 4, 7, or null
Uuid::timestamp( $value );        // milliseconds, v7 only
```

Prefer **v7** for anything you will store. A v4 primary key is random, so every insert lands in a different page of the index and the B-tree fragments; v7 embeds a millisecond timestamp in its high bits, so inserts append and the index stays dense. It also sorts chronologically for free.

Use **v4** where the value is exposed and the creation time should not be — a password-reset identifier, a public share link.

## Codes

```php
use SugarCommerce\Generate\Code;

Code::license();                    // 'WZPRV-R06ZZ-4F2CR-T73HR'
Code::license( 'ACME' );            // 'ACME-AS835-Z0ZGE-BVQE5-B7H4W'
Code::discount();                   // '9TQ9C4'
Code::discount( 'SALE' );           // 'SALE-9TQ9C4'
Code::reference( 'INV' );           // 'INV-5796J9'
Code::make( groups: 3, length: 4 ); // 'A3K9-M2PQ-7XB4'

Code::normalize( 'wzprv-r06zz' );   // 'WZPRVR06ZZ' — case and separators folded
Code::matches( $typed, $stored );   // constant time, normalising both sides
```

The alphabet is **Crockford base32** — `0-9` and `A-Z` without `I`, `L`, `O` or `U`. The first three are dropped because they are unreadable next to `1` and `0`; `U` is dropped so a random code cannot spell something unfortunate on a customer's invoice.

`normalize()` folds the other direction too: a customer who typed `O` for zero or `I` for one still gets in. Compare with `matches()` rather than `===`, so lowercase and misplaced dashes also validate — in constant time.

## Slugs

```php
use SugarCommerce\Generate\Slug;

Slug::make( 'Héllo Wörld!' );        // 'hello-world'
Slug::make( '日本語のタイトル' );      // 'ri-ben-yunotaitoru'
Slug::make( 'Post', '_' );           // 'post'

Slug::unique( $title, fn( string $s ): bool => $repository->exists( $s ) );
Slug::is_valid( $slug );
```

`unique()` takes a callback that answers "is this taken?" and appends `-2`, `-3` until it isn't. Whether that check is a database query, a filesystem look-up or an array is yours to decide.

Where nothing transliterable survives — an emoji-only title — you get a short random slug rather than an empty string, because empty slugs collide with each other.

## Tokens and API keys

```php
use SugarCommerce\Generate\Token;

Token::hex( 32 );            // 64 hex characters
Token::url_safe( 32 );       // base64url, no padding
Token::alphanumeric( 40 );

$key = Token::api_key( 'sk' );

$key['key'];      // 'sk_65CcAKIT5cAG…' — show once, never store
$key['prefix'];   // 'sk_65CcAKIT'      — store, to identify the key in a list
$key['hash'];     // sha256 of the key  — store this

Token::matches( $presented, $key['hash'] );   // constant time
Token::digest( $presented );                  // the stored form, for lookup
```

`matches()` is for checking a key against one you already found. `digest()` is
for finding it in the first place — a `WHERE key_hash = ?` needs the digest,
not a comparison. Use it rather than writing `hash( 'sha256', $key )` in your
own code, or the two definitions of "the stored form" eventually disagree.

Store the hash, not the key. A leaked database of hashes is an inconvenience; a leaked database of keys is every customer's account.

Show the raw key exactly once, at creation, and say so in the interface — a "reveal" button that works later means it was stored in a recoverable form.

## Testing

```bash
composer install
composer test
```

50 tests — UUID version and variant bits asserted against RFC 9562, v7 monotonicity across a millisecond boundary, alphabet exclusions, transliteration across four scripts, and constant-time comparison behaviour.

## License

GPL-2.0-or-later
