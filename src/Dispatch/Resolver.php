<?php

declare(strict_types=1);

namespace Apermo\Notify\Dispatch;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Resolves the set of subscriptions that should be notified for a given post event.
 *
 * v0.1 covers the direct per-post target only. v0.2 will union in author,
 * term, and post-type stream subscriptions — keeping the entry point shape
 * stable while the underlying query grows.
 */
final class Resolver {

	/**
	 * Returns confirmed subscriptions that should receive a notification for
	 * this post.
	 *
	 * @param WP_Post $post Post that triggered dispatch.
	 *
	 * @return array<int, Subscription>
	 */
	public static function for_post( WP_Post $post ): array {
		return Repository::find_confirmed_for_target( 'post', $post->ID );
	}
}
