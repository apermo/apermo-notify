=== apermo-notify ===
Contributors: flavor
Tags: notifications, subscriptions, email, gdpr, opt-in
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-content email notifications. Visitors subscribe to a specific post or page and receive an email when it is published or updated.

== Description ==

apermo-notify lets visitors subscribe to updates of a specific post or page,
not to a site-wide broadcast. Subscriptions are gated by double opt-in and
every notification carries a one-click unsubscribe link.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/apermo-notify/`
2. Activate the plugin through the "Plugins" screen in WordPress

== Changelog ==

= 0.1.2 =
* Security: reject malformed `post_id` input on the subscribe form
  instead of coercing it via `absint()`.
* Fix: stop showing a false "run composer install" admin notice when the
  plugin is installed via a parent project's Composer (Bedrock and
  similar).

= 0.1.1 =
* Template sync to 0.10.0 — adds a local commit-msg git hook that
  enforces the same conventional-commit rules as the CI workflow.

= 0.1.0 =
* Initial release
