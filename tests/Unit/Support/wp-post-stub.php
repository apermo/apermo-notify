<?php
/**
 * Provides a minimal WP_Post stand-in for unit tests that run without WordPress.
 *
 * The real `WP_Post` is loaded by wp-phpunit for integration tests; this stub
 * keeps the same public surface our code touches so Brain Monkey unit tests
 * can build post-shaped fixtures without a class_alias trick in each file.
 *
 * @package Apermo\Notify\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post', false ) ) {
	// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.VariableComment.Missing,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,Squiz.Commenting.FunctionComment.Missing
	class WP_Post { // phpcs:ignore Apermo,Generic.Classes.OpeningBraceSameLine
		public int $ID             = 0;
		public string $post_type   = '';
		public string $post_status = '';
		public string $post_title  = '';

		public function __construct( object|null $source = null ) {
			if ( $source === null ) {
				return;
			}
			foreach ( get_object_vars( $source ) as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
	// phpcs:enable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.VariableComment.Missing,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,Squiz.Commenting.FunctionComment.Missing
}
