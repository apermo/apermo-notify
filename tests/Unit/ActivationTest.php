<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit;

use Apermo\Notify\Activation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Activation class constants and schema metadata.
 */
class ActivationTest extends TestCase {

	/**
	 * Confirms the plugin's schema-version option key is stable.
	 *
	 * @return void
	 */
	public function test_version_option_key_is_stable(): void {
		$this->assertSame( 'apermo_notify_db_version', Activation::VERSION_OPTION );
	}

	/**
	 * Confirms the unprefixed table names match the spec in PLAN.md.
	 *
	 * @return void
	 */
	public function test_table_names(): void {
		$this->assertSame( 'apermo_notify_subscriptions', Activation::SUBSCRIPTIONS_TABLE );
		$this->assertSame( 'apermo_notify_sent_log', Activation::SENT_LOG_TABLE );
	}

	/**
	 * Confirms the schema version is an integer >= 1 (Custom_Tables uses ints).
	 *
	 * @return void
	 */
	public function test_schema_version(): void {
		$this->assertGreaterThanOrEqual( 1, Activation::SCHEMA_VERSION );
	}
}
