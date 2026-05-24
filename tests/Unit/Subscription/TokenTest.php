<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Subscription;

use Apermo\Notify\Subscription\Token;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Token utility for generation, verification, and email normalization.
 */
final class TokenTest extends TestCase {

	/**
	 * Confirms generate() returns a 64-char hex string.
	 *
	 * @return void
	 */
	public function test_generate_returns_64_char_hex(): void {
		$token = Token::generate();

		$this->assertSame( 64, \strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
	}

	/**
	 * Confirms two generated tokens differ.
	 *
	 * @return void
	 */
	public function test_generate_returns_unique_values(): void {
		$this->assertNotSame( Token::generate(), Token::generate() );
	}

	/**
	 * Confirms verify() matches an identical token.
	 *
	 * @return void
	 */
	public function test_verify_matches_identical_token(): void {
		$token = Token::generate();
		$this->assertTrue( Token::verify( $token, $token ) );
	}

	/**
	 * Confirms verify() rejects mismatched tokens of the same length.
	 *
	 * @return void
	 */
	public function test_verify_rejects_mismatched_tokens(): void {
		$this->assertFalse( Token::verify( Token::generate(), Token::generate() ) );
	}

	/**
	 * Confirms verify() rejects tokens of the wrong length.
	 *
	 * @return void
	 */
	public function test_verify_rejects_wrong_length(): void {
		$this->assertFalse( Token::verify( Token::generate(), 'tooshort' ) );
	}

	/**
	 * Confirms verify() rejects an empty stored token.
	 *
	 * @return void
	 */
	public function test_verify_rejects_empty_known(): void {
		$this->assertFalse( Token::verify( '', Token::generate() ) );
	}

	/**
	 * Confirms email normalization trims and lowercases.
	 *
	 * @return void
	 */
	public function test_normalize_email_trims_and_lowercases(): void {
		$this->assertSame(
			'visitor@example.tld',
			Token::normalize_email( '  Visitor@Example.TLD  ' ),
		);
	}
}
