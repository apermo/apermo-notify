<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $wp_tests_dir === false ) {
	$vendor_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( is_dir( $vendor_dir ) ) {
		$wp_tests_dir = $vendor_dir;
	}
}

if ( $wp_tests_dir !== false && is_dir( $wp_tests_dir ) ) {
	if ( getenv( 'WP_MULTISITE' ) ) {
		define( 'WP_TESTS_MULTISITE', true );
	}

	require_once $wp_tests_dir . '/includes/functions.php';

	tests_add_filter( 'muplugins_loaded', 'apermo_notify_tests_load_project' );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
} else {
	// Unit-test path: WP is not loaded, but src/ files guard themselves with
	// `defined( 'ABSPATH' ) || exit;`. Define a harmless constant so the guard
	// passes during autoload. The integration path leaves this to wp-phpunit's
	// bootstrap, which sets ABSPATH to the real WordPress install root.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}

	// Minimal WP_Post stub so unit tests can construct post-shaped fixtures
	// without pulling in WordPress. Integration tests get the real class via
	// wp-phpunit.
	require_once __DIR__ . '/Unit/Support/wp-post-stub.php';

	// Time constants used by src/ code; integration runs pull these from WP core.
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 60 * 60 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 24 * 60 * 60 );
	}
}

/**
 * Loads the plugin or theme under test.
 *
 * @return void
 */
function apermo_notify_tests_load_project(): void {
	$plugin_file = dirname( __DIR__ ) . '/plugin.php';
	if ( file_exists( $plugin_file ) ) {
		require $plugin_file;
	} else {
		register_theme_directory( dirname( __DIR__, 2 ) );
		switch_theme( basename( dirname( __DIR__ ) ) );
	}
}
