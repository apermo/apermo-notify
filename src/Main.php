<?php

declare(strict_types=1);

namespace Apermo\Notify;

\defined( 'ABSPATH' ) || exit();

// OPT-IN: confirm-deactivate — delete this use statement if you declined the example.
use Apermo\Notify\Admin\DeactivationFlow;
use Apermo\Notify\Admin\PostMetaBox;
use Apermo\Notify\Admin\SettingsPage;
use Apermo\Notify\Admin\SubscribersPage;
use Apermo\Notify\Cron\Pruner;
use Apermo\Notify\Cron\Scheduler;
use Apermo\Notify\Dispatch\PostHooks;
use Apermo\Notify\Frontend\AutoAppend;
use Apermo\Notify\Frontend\FormHandler;
use Apermo\Notify\Frontend\ManagePage;
use Apermo\Notify\Frontend\Styles;
use Apermo\Notify\Privacy\Eraser;
use Apermo\Notify\Privacy\Exporter;
use Apermo\Notify\Subscription\OptInFlow;

/**
 * Bootstraps the plugin.
 */
class Main {

	public const VERSION = '0.1.0';

	/**
	 * Holds the main plugin file path.
	 *
	 * @var string
	 */
	private static string $file = '';

	/**
	 * Initializes the plugin.
	 *
	 * @param string $file Main plugin file path.
	 *
	 * @return void
	 */
	public static function init( string $file ): void {
		self::$file = $file;

		register_activation_hook( $file, [ self::class, 'activate' ] );
		register_deactivation_hook( $file, [ self::class, 'deactivate' ] );
		add_action( 'plugins_loaded', [ self::class, 'boot' ] );
	}

	/**
	 * Returns the main plugin file path.
	 *
	 * @return string
	 */
	public static function file(): string {
		return self::$file;
	}

	/**
	 * Activates the plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Activation::activate();
		Scheduler::schedule();
	}

	/**
	 * Deactivates the plugin.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Scheduler::unschedule();
	}

	/**
	 * Boots the plugin after all plugins are loaded.
	 *
	 * @return void
	 */
	public static function boot(): void {
		// OPT-IN: confirm-deactivate — delete the next 3 lines if you declined the example.
		if ( is_admin() ) {
			( new DeactivationFlow() )->register();
		}

		( new OptInFlow() )->register();
		( new FormHandler() )->register();
		( new ManagePage() )->register();
		( new Scheduler() )->register();
		( new Pruner() )->register();
		( new AutoAppend() )->register();
		( new Styles() )->register();
		( new PostHooks() )->register();
		( new Exporter() )->register();
		( new Eraser() )->register();

		if ( is_admin() ) {
			( new PostMetaBox() )->register();
			( new SubscribersPage() )->register();
			( new SettingsPage() )->register();
		}
	}
}
