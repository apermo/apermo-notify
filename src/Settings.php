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
	 * Accepted values for `stale_after_months` — kept small so the settings
	 * UI stays a tidy dropdown rather than a free-form input.
	 *
	 * @var array<int, int>
	 */
	public const STALE_AFTER_MONTHS_CHOICES = [ 6, 12, 18, 24 ];

	/**
	 * Accepted values for `prune_mode`.
	 */
	public const PRUNE_MODE_DELETE     = 'delete';
	public const PRUNE_MODE_KEEP_ALIVE = 'keep_alive';

	/**
	 * Accepted values for `stale_grace_days`.
	 *
	 * @var array<int, int>
	 */
	public const STALE_GRACE_DAYS_CHOICES = [ 7, 14, 30, 60, 90 ];

	/**
	 * Returns the default settings array applied to fresh installs.
	 *
	 * @return array{enabled_post_types: array<int, string>, auto_append_default: bool, subscription_text: string, stale_after_months: int, prune_mode: string, stale_grace_days: int}
	 */
	public static function defaults(): array {
		return [
			'enabled_post_types'  => [ 'post' ],
			'auto_append_default' => true,
			'subscription_text'   => __(
				'Want updates on this post? Enter your email and we\'ll let you know whenever it changes.',
				'apermo-notify',
			),
			'stale_after_months'  => 6,
			'prune_mode'          => self::PRUNE_MODE_KEEP_ALIVE,
			'stale_grace_days'    => 7,
			'manage_page_id'      => 0,
		];
	}

	/**
	 * Returns the full settings array with defaults applied.
	 *
	 * @return array{enabled_post_types: array<int, string>, auto_append_default: bool, subscription_text: string, stale_after_months: int, prune_mode: string, stale_grace_days: int, manage_page_id: int}
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
		$merged['subscription_text']   = (string) $merged['subscription_text'];
		$merged['stale_after_months']  = self::clamp_choice(
			(int) $merged['stale_after_months'],
			self::STALE_AFTER_MONTHS_CHOICES,
			6,
		);
		$merged['stale_grace_days']    = self::clamp_choice(
			(int) $merged['stale_grace_days'],
			self::STALE_GRACE_DAYS_CHOICES,
			7,
		);

		$mode = (string) $merged['prune_mode'];
		if ( $mode !== self::PRUNE_MODE_DELETE && $mode !== self::PRUNE_MODE_KEEP_ALIVE ) {
			$mode = self::PRUNE_MODE_KEEP_ALIVE;
		}
		$merged['prune_mode']     = $mode;
		$merged['manage_page_id'] = \max( 0, (int) $merged['manage_page_id'] );

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
	 * Returns the configured subscription intro text shown above the form.
	 *
	 * @return string
	 */
	public static function subscription_text(): string {
		return self::all()['subscription_text'];
	}

	/**
	 * Returns the number of months after which a confirmed subscription is
	 * considered stale.
	 *
	 * @return int
	 */
	public static function stale_after_months(): int {
		return self::all()['stale_after_months'];
	}

	/**
	 * Returns the configured prune mode: `delete` (hard) or `keep_alive`
	 * (warning email then delete after the grace window).
	 *
	 * @return string
	 */
	public static function prune_mode(): string {
		return self::all()['prune_mode'];
	}

	/**
	 * Returns the keep-alive grace window in days (only consulted when
	 * `prune_mode === keep_alive`).
	 *
	 * @return int
	 */
	public static function stale_grace_days(): int {
		return self::all()['stale_grace_days'];
	}

	/**
	 * Returns the configured ID of the page that hosts the subscription-
	 * management UI (zero when unset).
	 *
	 * @return int
	 */
	public static function manage_page_id(): int {
		return self::all()['manage_page_id'];
	}

	/**
	 * Persists a sanitized settings array.
	 *
	 * @param array<string, mixed> $input Raw input (typically from $_POST).
	 *
	 * @return void
	 */
	public static function save( array $input ): void {
		$value = [
			'enabled_post_types'  => self::sanitize_post_types( $input['enabled_post_types'] ?? null ),
			'auto_append_default' => isset( $input['auto_append_default'] ) && (bool) $input['auto_append_default'],
			'subscription_text'   => self::sanitize_subscription_text( $input['subscription_text'] ?? null ),
			'stale_after_months'  => self::clamp_choice(
				(int) ( $input['stale_after_months'] ?? 6 ),
				self::STALE_AFTER_MONTHS_CHOICES,
				6,
			),
			'prune_mode'          => self::sanitize_prune_mode( $input['prune_mode'] ?? null ),
			'stale_grace_days'    => self::clamp_choice(
				(int) ( $input['stale_grace_days'] ?? 7 ),
				self::STALE_GRACE_DAYS_CHOICES,
				7,
			),
			'manage_page_id'      => \max( 0, (int) ( $input['manage_page_id'] ?? 0 ) ),
		];

		update_option( self::OPTION, $value, false );
	}

	/**
	 * Sanitizes the enabled-post-types input list into unique, slug-safe values.
	 *
	 * @param mixed $raw Raw value from $_POST.
	 *
	 * @return array<int, string>
	 */
	private static function sanitize_post_types( mixed $raw ): array {
		if ( ! \is_array( $raw ) ) {
			return [];
		}

		$slugs = [];
		foreach ( $raw as $slug ) {
			if ( \is_string( $slug ) && $slug !== '' ) {
				$slugs[] = sanitize_key( $slug );
			}
		}

		return \array_values( \array_unique( $slugs ) );
	}

	/**
	 * Sanitizes the subscription-text rich input.
	 *
	 * @param mixed $raw Raw value from $_POST.
	 *
	 * @return string
	 */
	private static function sanitize_subscription_text( mixed $raw ): string {
		return \is_string( $raw ) ? wp_kses_post( $raw ) : '';
	}

	/**
	 * Sanitizes the prune-mode value into one of the allowed slugs.
	 *
	 * @param mixed $raw Raw value from $_POST.
	 *
	 * @return string
	 */
	private static function sanitize_prune_mode( mixed $raw ): string {
		$mode = \is_string( $raw ) ? sanitize_key( $raw ) : self::PRUNE_MODE_KEEP_ALIVE;

		return ( $mode === self::PRUNE_MODE_DELETE || $mode === self::PRUNE_MODE_KEEP_ALIVE )
			? $mode
			: self::PRUNE_MODE_KEEP_ALIVE;
	}

	/**
	 * Clamps an integer to one of the allowed choices, falling back to the
	 * supplied default when the value is out of set.
	 *
	 * @param int             $value    Raw integer.
	 * @param array<int, int> $choices  Allowed integers.
	 * @param int             $fallback Value to return when `$value` is not in `$choices`.
	 *
	 * @return int
	 */
	private static function clamp_choice( int $value, array $choices, int $fallback ): int {
		return \in_array( $value, $choices, true ) ? $value : $fallback;
	}
}
