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
	 * Sends the double opt-in confirmation email.
	 *
	 * @param Subscription $subscription The pending subscription.
	 * @param WP_Post      $post         Post the subscription targets.
	 *
	 * @return bool Result of wp_mail().
	 */
	public static function send_confirm( Subscription $subscription, WP_Post $post ): bool {
		$confirm_url = self::action_url( self::ACTION_CONFIRM, $subscription->token );

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
		$unsubscribe_url = self::action_url( self::ACTION_UNSUBSCRIBE, $subscription->token );
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
			self::render_update_body( $post, $permalink, $unsubscribe_url, $event ),
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
	 * Renders the default plain-text body for the update email.
	 *
	 * @param WP_Post $post            Updated post.
	 * @param string  $permalink       Public URL for the post.
	 * @param string  $unsubscribe_url One-click unsubscribe URL.
	 * @param string  $event           'publish' or 'update'.
	 *
	 * @return string
	 */
	private static function render_update_body(
		WP_Post $post,
		string $permalink,
		string $unsubscribe_url,
		string $event,
	): string {
		$intro = $event === 'publish'
			/* translators: %s: post title */
			? \sprintf( __( 'A new post has been published: %s', 'apermo-notify' ), $post->post_title )
			/* translators: %s: post title */
			: \sprintf( __( 'A post you follow was updated: %s', 'apermo-notify' ), $post->post_title );

		return \sprintf(
			/* translators: 1: intro line, 2: permalink, 3: unsubscribe URL */
			__(
				"%1\$s\n\nRead it here:\n%2\$s\n\nTo stop receiving these updates, open this link:\n%3\$s",
				'apermo-notify',
			),
			$intro,
			$permalink,
			$unsubscribe_url,
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
