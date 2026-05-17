<?php

declare(strict_types=1);

namespace Apermo\Notify\Subscription;

\defined( 'ABSPATH' ) || exit();

/**
 * Generates and verifies URL-safe subscription tokens.
 */
final class Token {

	/**
	 * Token length in characters (hex encoding of 32 random bytes).
	 */
	public const LENGTH = 64;

	/**
	 * Generates a fresh random token.
	 *
	 * @return string 64-character hex string suitable for use in URLs.
	 */
	public static function generate(): string {
		return \bin2hex( \random_bytes( 32 ) );
	}

	/**
	 * Compares two tokens in constant time.
	 *
	 * @param string $known     Stored token.
	 * @param string $candidate Token supplied by the request.
	 *
	 * @return bool Whether the tokens match.
	 */
	public static function verify( string $known, string $candidate ): bool {
		if ( $known === '' || \strlen( $candidate ) !== self::LENGTH ) {
			return false;
		}

		return \hash_equals( $known, $candidate );
	}

	/**
	 * Normalizes a subscriber email: trimmed and lowercased.
	 *
	 * @param string $email Raw input.
	 *
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		return \strtolower( \trim( $email ) );
	}
}
