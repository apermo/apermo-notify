<?php

declare(strict_types=1);

namespace Apermo\Notify\Mail;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Builds and sends transactional emails for the plugin.
 */
final class Mailer {

	/**
	 * admin-post.php action name for the confirmation link.
	 */
	public const ACTION_CONFIRM = 'apermo_notify_confirm';

	/**
	 * admin-post.php action name for the unsubscribe link.
	 */
	public const ACTION_UNSUBSCRIBE = 'apermo_notify_unsubscribe';

	/**
	 * admin-post.php action name for the keep-alive link sent on staleness.
	 */
	public const ACTION_KEEP_ALIVE = 'apermo_notify_keep_alive';

	/**
	 * front-of-site action used by the manage-subscriptions page.
	 */
	public const ACTION_MANAGE = 'apermo_notify_manage';

	/**
	 * Sends the double opt-in confirmation email.
	 *
	 * @param Subscription $subscription The pending subscription.
	 * @param WP_Post      $post         Post the subscription targets.
	 *
	 * @return bool Result of wp_mail().
	 */
	public static function send_confirm( Subscription $subscription, WP_Post $post ): bool {
		$confirm_url = self::post_action_url( self::ACTION_CONFIRM, $subscription );

		$subject = self::subject_for(
			'confirm',
			$subscription,
			$post,
			'publish',
			/* translators: %s: post title */
			\sprintf( __( 'Confirm your subscription to "%s"', 'apermo-notify' ), $post->post_title ),
		);

		$body = self::body_for(
			'confirm',
			$subscription,
			$post,
			'publish',
			self::render_confirm_body( $post, $confirm_url ),
		);

		return self::send( $subscription->email, $subject, $body );
	}

	/**
	 * Sends an update notification email.
	 *
	 * @param Subscription $subscription The confirmed subscription.
	 * @param WP_Post      $post         Post that was published or updated.
	 * @param string       $event        Either 'publish' or 'update'.
	 *
	 * @return bool Result of wp_mail().
	 */
	public static function send_update( Subscription $subscription, WP_Post $post, string $event ): bool {
		$unsubscribe_url = self::post_action_url( self::ACTION_UNSUBSCRIBE, $subscription );
		$manage_url      = self::manage_url( $subscription->token );
		$permalink       = get_permalink( $post );
		if ( ! \is_string( $permalink ) ) {
			$permalink = '';
		}

		$default_subject = $event === 'publish'
			/* translators: %s: post title */
			? \sprintf( __( 'New post: "%s"', 'apermo-notify' ), $post->post_title )
			/* translators: %s: post title */
			: \sprintf( __( 'Updated: "%s"', 'apermo-notify' ), $post->post_title );

		$subject = self::subject_for( 'update', $subscription, $post, $event, $default_subject );
		$body    = self::body_for(
			'update',
			$subscription,
			$post,
			$event,
			self::render_update_body( $post, $permalink, $unsubscribe_url, $manage_url, $event ),
		);

		return self::send( $subscription->email, $subject, $body );
	}

	/**
	 * Sends a heads-up to an already-confirmed subscriber when someone (maybe
	 * them, maybe a harvester) re-submits their address on the same target.
	 *
	 * @param Subscription $subscription Existing confirmed subscription.
	 * @param WP_Post      $post         Post the resubmit targeted.
	 *
	 * @return bool
	 */
	public static function send_already_subscribed( Subscription $subscription, WP_Post $post ): bool {
		$manage_url      = self::manage_url( $subscription->token );
		$unsubscribe_url = self::post_action_url( self::ACTION_UNSUBSCRIBE, $subscription );

		$subject = self::subject_for(
			'already_subscribed',
			$subscription,
			$post,
			'publish',
			/* translators: %s: post title */
			\sprintf( __( 'You\'re already subscribed to "%s"', 'apermo-notify' ), $post->post_title ),
		);

		$body = self::body_for(
			'already_subscribed',
			$subscription,
			$post,
			'publish',
			self::render_already_subscribed_body( $post, $manage_url, $unsubscribe_url ),
		);

		return self::send( $subscription->email, $subject, $body );
	}

	/**
	 * Sends the keep-alive warning when a subscription has gone stale.
	 *
	 * @param Subscription $subscription Stale confirmed subscription.
	 * @param WP_Post|null $post         Subscribed post if it still exists.
	 * @param int          $grace_days   Number of days until the row is deleted if ignored.
	 *
	 * @return bool
	 */
	public static function send_stale_warning( Subscription $subscription, ?WP_Post $post, int $grace_days ): bool {
		$keep_alive_url = self::post_action_url( self::ACTION_KEEP_ALIVE, $subscription );
		$manage_url     = self::manage_url( $subscription->token );
		$post_title     = $post instanceof WP_Post ? $post->post_title : '';

		$subject = self::subject_for(
			'stale_warning',
			$subscription,
			$post ?? new WP_Post( (object) [] ), // phpcs:ignore Apermo.PHP.ForbiddenObjectCast.Found -- WP_Post::__construct needs an object; stub keeps the filter signature uniform when the source post has been deleted.
			'publish',
			__( 'Do you still want notifications from this site?', 'apermo-notify' ),
		);

		$body = self::body_for(
			'stale_warning',
			$subscription,
			$post ?? new WP_Post( (object) [] ), // phpcs:ignore Apermo.PHP.ForbiddenObjectCast.Found -- WP_Post::__construct needs an object; stub keeps the filter signature uniform when the source post has been deleted.
			'publish',
			self::render_stale_warning_body( $post_title, $keep_alive_url, $manage_url, $grace_days ),
		);

		return self::send( $subscription->email, $subject, $body );
	}

	/**
	 * Sends the deletion-notification email when an admin opts in on the
	 * post-deletion dialog.
	 *
	 * @param Subscription $subscription Confirmed subscriber.
	 * @param WP_Post      $post         Post about to be deleted.
	 * @param string       $custom_note  Optional free-form author note. May be empty.
	 *
	 * @return bool
	 */
	public static function send_goodbye( Subscription $subscription, WP_Post $post, string $custom_note ): bool {
		$manage_url = self::manage_url( $subscription->token );

		$subject = self::subject_for(
			'goodbye',
			$subscription,
			$post,
			'delete',
			/* translators: %s: post title */
			\sprintf( __( 'A post you followed has been removed: "%s"', 'apermo-notify' ), $post->post_title ),
		);

		$body = self::body_for(
			'goodbye',
			$subscription,
			$post,
			'delete',
			self::render_goodbye_body( $post, $manage_url, $custom_note ),
		);

		return self::send( $subscription->email, $subject, $body );
	}

	/**
	 * Builds the absolute URL for a one-click token action.
	 *
	 * @param string $action Either ACTION_CONFIRM or ACTION_UNSUBSCRIBE.
	 * @param string $token  Token to embed.
	 *
	 * @return string
	 */
	public static function action_url( string $action, string $token ): string {
		return add_query_arg(
			[
				'action' => $action,
				'token'  => $token,
			],
			admin_url( 'admin-post.php' ),
		);
	}

	/**
	 * Builds a post-permalink-based token action URL.
	 *
	 * Lands the click on the original post (so the address bar shows a
	 * familiar URL during the brief redirect) and carries a base64-encoded
	 * copy of the subscriber email so the post's subscribe form can prefill
	 * the input when the user is sent back after the action — making re-
	 * subscribing one click + one consent tick.
	 *
	 * Falls back to {@see self::action_url()} when the subscription's target
	 * isn't a post or no permalink can be resolved.
	 *
	 * @param string       $action       One of the ACTION_* constants.
	 * @param Subscription $subscription Subscription whose token is being acted on.
	 *
	 * @return string
	 */
	public static function post_action_url( string $action, Subscription $subscription ): string {
		if ( $subscription->target_type !== 'post' || $subscription->target_id <= 0 ) {
			return self::action_url( $action, $subscription->token );
		}

		$permalink = get_permalink( $subscription->target_id );
		if ( ! \is_string( $permalink ) || $permalink === '' ) {
			return self::action_url( $action, $subscription->token );
		}

		return add_query_arg(
			[
				'apermo_notify_action' => $action,
				'token'                => $subscription->token,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe base64 wrapping of the subscriber email so the form prefill query var stays opaque-ish in browser history.
				'apermo_notify_email'  => \rtrim( \strtr( \base64_encode( $subscription->email ), '+/', '-_' ), '=' ),
			],
			$permalink,
		);
	}

	/**
	 * Builds the front-of-site URL that opens the per-email manage page.
	 *
	 * Lives on `home_url()` rather than `admin-post.php` because the manage
	 * page is rendered for anonymous visitors via `template_redirect`.
	 *
	 * @param string $token Subscription token used to identify the email.
	 *
	 * @return string
	 */
	public static function manage_url( string $token ): string {
		return add_query_arg(
			[
				'action' => self::ACTION_MANAGE,
				'token'  => $token,
			],
			home_url( '/' ),
		);
	}

	/**
	 * Filters the email subject through `apermo_notify_email_subject`.
	 *
	 * @param string       $kind         Template kind: 'confirm' or 'update'.
	 * @param Subscription $subscription The subscription.
	 * @param WP_Post      $post         Related post.
	 * @param string       $event        Event slug.
	 * @param string       $default_value Default subject.
	 *
	 * @return string
	 */
	private static function subject_for(
		string $kind,
		Subscription $subscription,
		WP_Post $post,
		string $event,
		string $default_value,
	): string {
		/**
		 * Filters the subject of a notification email.
		 *
		 * @param string       $subject      Default subject.
		 * @param string       $kind         Template kind: 'confirm' or 'update'.
		 * @param Subscription $subscription Subscription receiving the email.
		 * @param WP_Post      $post         Related post.
		 * @param string       $event        Event slug.
		 *
		 * @return string Filtered subject.
		 */
		$filtered = apply_filters(
			'apermo_notify_email_subject',
			$default_value,
			$kind,
			$subscription,
			$post,
			$event,
		);

		return \is_string( $filtered ) ? $filtered : $default_value;
	}

	/**
	 * Filters the email body through `apermo_notify_email_body_text`.
	 *
	 * @param string       $kind         Template kind.
	 * @param Subscription $subscription The subscription.
	 * @param WP_Post      $post         Related post.
	 * @param string       $event        Event slug.
	 * @param string       $default_value Default plain-text body.
	 *
	 * @return string
	 */
	private static function body_for(
		string $kind,
		Subscription $subscription,
		WP_Post $post,
		string $event,
		string $default_value,
	): string {
		/**
		 * Filters the plain-text body of a notification email.
		 *
		 * @param string       $body         Default body text.
		 * @param string       $kind         Template kind: 'confirm' or 'update'.
		 * @param Subscription $subscription Subscription receiving the email.
		 * @param WP_Post      $post         Related post.
		 * @param string       $event        Event slug.
		 *
		 * @return string Filtered body.
		 */
		$filtered = apply_filters(
			'apermo_notify_email_body_text',
			$default_value,
			$kind,
			$subscription,
			$post,
			$event,
		);

		return \is_string( $filtered ) ? $filtered : $default_value;
	}

	/**
	 * Renders the default plain-text body for the confirm email.
	 *
	 * @param WP_Post $post        Subscribed post.
	 * @param string  $confirm_url One-click confirmation URL.
	 *
	 * @return string
	 */
	private static function render_confirm_body( WP_Post $post, string $confirm_url ): string {
		return \sprintf(
			/* translators: 1: post title, 2: confirmation URL */
			__(
				"Someone (hopefully you) asked to be notified about updates to:\n\n%1\$s\n\nTo confirm your subscription, open this link:\n\n%2\$s\n\nIf you did not request this, you can ignore this message — no subscription has been activated.",
				'apermo-notify',
			),
			$post->post_title,
			$confirm_url,
		);
	}

	/**
	 * Renders the body for the already-subscribed reassurance email.
	 *
	 * @param WP_Post $post            Post the resubmit targeted.
	 * @param string  $manage_url      URL to the manage page.
	 * @param string  $unsubscribe_url URL to one-click unsubscribe from this post.
	 *
	 * @return string
	 */
	private static function render_already_subscribed_body( WP_Post $post, string $manage_url, string $unsubscribe_url ): string {
		return \sprintf(
			/* translators: 1: post title, 2: manage URL, 3: unsubscribe URL */
			__(
				"Someone tried to subscribe this address to:\n\n%1\$s\n\nYou're already on the list, so nothing changed. If it was you, no action needed — you'll keep getting update notifications.\n\nTo manage every subscription you have on this site:\n%2\$s\n\nTo unsubscribe from this post only:\n%3\$s",
				'apermo-notify',
			),
			$post->post_title,
			$manage_url,
			$unsubscribe_url,
		);
	}

	/**
	 * Renders the body for the deletion-notice email.
	 *
	 * @param WP_Post $post        Post being deleted.
	 * @param string  $manage_url  Manage-all URL for the subscriber.
	 * @param string  $custom_note Optional author note from the admin dialog.
	 *
	 * @return string
	 */
	private static function render_goodbye_body( WP_Post $post, string $manage_url, string $custom_note ): string {
		$intro = \sprintf(
			/* translators: %s: post title */
			__( 'A post you subscribed to — "%s" — has been removed by the author.', 'apermo-notify' ),
			$post->post_title,
		);

		$note_block = '';
		if ( \trim( $custom_note ) !== '' ) {
			$note_block = "\n\n" . __( 'A note from the author:', 'apermo-notify' ) . "\n\n" . $custom_note;
		}

		return \sprintf(
			/* translators: 1: intro, 2: optional author note (already prefixed), 3: manage URL */
			__(
				"%1\$s%2\$s\n\nThis subscription has been removed; you don't need to do anything.\n\nTo review every subscription you have on this site:\n%3\$s",
				'apermo-notify',
			),
			$intro,
			$note_block,
			$manage_url,
		);
	}

	/**
	 * Renders the body for the stale-warning email.
	 *
	 * @param string $post_title     Subscribed post title, possibly empty.
	 * @param string $keep_alive_url URL that resets the keep-alive timestamp.
	 * @param string $manage_url     URL to the manage page.
	 * @param int    $grace_days     Days until the row is deleted if ignored.
	 *
	 * @return string
	 */
	private static function render_stale_warning_body( string $post_title, string $keep_alive_url, string $manage_url, int $grace_days ): string {
		$intro = $post_title !== ''
			/* translators: %s: post title */
			? \sprintf( __( 'You\'re subscribed to updates of "%s".', 'apermo-notify' ), $post_title )
			: __( 'You\'re subscribed to update notifications on this site.', 'apermo-notify' );

		return \sprintf(
			/* translators: 1: intro line, 2: grace-period days, 3: keep-alive URL, 4: manage URL */
			_n(
				"%1\$s\n\nIt's been a while since you last interacted with it. If you'd still like to receive notifications, click this link within %2\$d day:\n\n%3\$s\n\nOtherwise the subscription will be removed automatically.\n\nTo manage all your subscriptions on this site:\n%4\$s",
				"%1\$s\n\nIt's been a while since you last interacted with it. If you'd still like to receive notifications, click this link within %2\$d days:\n\n%3\$s\n\nOtherwise the subscription will be removed automatically.\n\nTo manage all your subscriptions on this site:\n%4\$s",
				$grace_days,
				'apermo-notify',
			),
			$intro,
			$grace_days,
			$keep_alive_url,
			$manage_url,
		);
	}

	/**
	 * Renders the default plain-text body for the update email.
	 *
	 * @param WP_Post $post            Updated post.
	 * @param string  $permalink       Public URL for the post.
	 * @param string  $unsubscribe_url One-click unsubscribe URL.
	 * @param string  $manage_url      Manage-all URL.
	 * @param string  $event           'publish' or 'update'.
	 *
	 * @return string
	 */
	private static function render_update_body(
		WP_Post $post,
		string $permalink,
		string $unsubscribe_url,
		string $manage_url,
		string $event,
	): string {
		$intro = $event === 'publish'
			/* translators: %s: post title */
			? \sprintf( __( 'A new post has been published: %s', 'apermo-notify' ), $post->post_title )
			/* translators: %s: post title */
			: \sprintf( __( 'A post you follow was updated: %s', 'apermo-notify' ), $post->post_title );

		return \sprintf(
			/* translators: 1: intro line, 2: permalink, 3: unsubscribe URL, 4: manage URL */
			__(
				"%1\$s\n\nRead it here:\n%2\$s\n\nTo stop receiving updates for this post:\n%3\$s\n\nTo manage every subscription you have on this site:\n%4\$s",
				'apermo-notify',
			),
			$intro,
			$permalink,
			$unsubscribe_url,
			$manage_url,
		);
	}

	/**
	 * Sends an email through wp_mail() with plain-text content type.
	 *
	 * @param string $recipient Recipient email.
	 * @param string $subject   Subject line.
	 * @param string $body      Plain-text body.
	 *
	 * @return bool
	 */
	private static function send( string $recipient, string $subject, string $body ): bool {
		return wp_mail( $recipient, $subject, $body );
	}
}
