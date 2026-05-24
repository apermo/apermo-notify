<?php
/**
 * Tests PostDeletionListener cleanup against a real WordPress instance.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use Apermo\Notify\Admin\PostDeletionListener;
use Apermo\Notify\Subscription\Repository;
use WP_UnitTestCase;

/**
 * Exercises the `deleted_post` cleanup against the real WP delete pipeline.
 */
final class PostDeletionListenerTest extends WP_UnitTestCase {

	/**
	 * Ensures the listener's hooks are wired so wp_delete_post triggers cleanup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();
		( new PostDeletionListener() )->register();
	}

	/**
	 * Confirms wp_delete_post wipes every subscription for the post but leaves
	 * subscriptions of other posts untouched.
	 *
	 * @return void
	 */
	public function test_deleted_post_drops_matching_subscriptions(): void {
		$doomed = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$kept   = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		Repository::create_pending( 'post', $doomed, '', 'pending@example.tld' );
		Repository::create_pending( 'post', $doomed, '', 'confirmed@example.tld' );
		Repository::create_pending( 'post', $kept, '', 'keep@example.tld' );

		$this->assertSame( 3, Repository::count_total( null, '' ) );

		wp_delete_post( $doomed, true );

		$this->assertSame( 1, Repository::count_total( null, '' ) );
		$rows = Repository::paginate( 20, 0, null, '', 'id', 'ASC' );
		$this->assertSame( 'keep@example.tld', $rows[0]->email );
	}

	/**
	 * Confirms trashing alone keeps subscriptions in place (only permanent
	 * delete should remove them).
	 *
	 * @return void
	 */
	public function test_trashing_keeps_subscriptions(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Repository::create_pending( 'post', $post, '', 'still-here@example.tld' );

		wp_trash_post( $post );

		$this->assertSame( 1, Repository::count_total( null, '' ) );
	}
}
