<?php
/**
 * Removes all plugin-owned data on uninstall.
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit();

require_once __DIR__ . '/vendor/autoload.php';

\Apermo\Notify\Activation::drop_all();
delete_option( 'apermo_notify_settings' );
