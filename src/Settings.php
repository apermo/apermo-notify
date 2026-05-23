<?php

declare(strict_types=1);

namespace Apermo\Notify;

\defined( 'ABSPATH' ) || exit();

/**
 * Reads and writes the plugin's settings option.
 *
 * The settings live in a single `apermo_notify_settings` option as an
 * associative array. Defaults are applied lazily on read so legacy installs
 * with a partial option get sensible behaviour without a migration.
 */
final class Settings {

	/**
	 * Option key in wp_options.
	 */
	public const OPTION = 'apermo_notify_settings';

	/**
	 * Returns the default settings array applied to fresh installs.
	 *
	 * @return array{enabled_post_types: array<int, string>, auto_append_default: bool}
	 */
	public static function defaults(): array {
		return [
			'enabled_post_types'  => [ 'post' ],
			'auto_append_default' => true,
		];
	}

	/**
	 * Returns the full settings array with defaults applied.
	 *
	 * @return array{enabled_post_types: array<int, string>, auto_append_default: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! \is_array( $stored ) ) {
			$stored = [];
		}

		$merged = \array_merge( self::defaults(), $stored );

		// Normalize types.
		$merged['enabled_post_types']  = \array_values(
			\array_filter(
				(array) $merged['enabled_post_types'],
				static fn ( $value ): bool => \is_string( $value ) && $value !== '',
			),
		);
		$merged['auto_append_default'] = (bool) $merged['auto_append_default'];

		return $merged;
	}

	/**
	 * Returns the configured enabled post types.
	 *
	 * @return array<int, string>
	 */
	public static function enabled_post_types(): array {
		return self::all()['enabled_post_types'];
	}

	/**
	 * Reports whether the auto-append default is on.
	 *
	 * @return bool
	 */
	public static function auto_append_default(): bool {
		return self::all()['auto_append_default'];
	}

	/**
	 * Persists a sanitized settings array.
	 *
	 * @param array<string, mixed> $input Raw input (typically from $_POST).
	 *
	 * @return void
	 */
	public static function save( array $input ): void {
		$enabled = [];
		if ( isset( $input['enabled_post_types'] ) && \is_array( $input['enabled_post_types'] ) ) {
			foreach ( $input['enabled_post_types'] as $slug ) {
				if ( \is_string( $slug ) && $slug !== '' ) {
					$enabled[] = sanitize_key( $slug );
				}
			}
		}

		$value = [
			'enabled_post_types'  => \array_values( \array_unique( $enabled ) ),
			'auto_append_default' => isset( $input['auto_append_default'] ) && (bool) $input['auto_append_default'],
		];

		update_option( self::OPTION, $value, false );
	}
}
