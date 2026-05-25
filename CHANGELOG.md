# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Bootstrap no longer shows a false "run `composer install`" notice when the
  plugin is installed via a parent project's Composer (e.g. Bedrock). The
  guard now tests whether `Main` is reachable via any autoloader instead of
  assuming `vendor/autoload.php` must live inside the plugin directory.
  ([#13](https://github.com/apermo/apermo-notify/issues/13))

## [0.1.1] - 2026-05-24

### Added

- Local `.husky/commit-msg` hook synced from
  [apermo/template-wordpress 0.10.0](https://github.com/apermo/template-wordpress/blob/main/CHANGELOG.md#0100---2026-05-24).
  Mirrors the conventional-commit rules already enforced by
  `pr-validation.yml`, so invalid messages fail at `git commit` time
  instead of after `git push`.

### Changed

- Removed the `[apermo_notify]` shortcode. The subscribe form is now placed
  via `the_content` (default-on for the post types configured in Settings)
  with a per-post show/hide override in the editor sidebar.
- Confirmed-subscribe duplicates no longer surface a distinct `duplicate`
  flash on the form: the response is now identical to a fresh subscribe so
  the form cannot be used to enumerate which addresses are on the list.
  The legitimate owner of the address receives an "already subscribed"
  email instead, throttled by the existing per-IP rate limit.
- Notification and confirmation emails now include a "Manage all my
  subscriptions" link alongside the per-post unsubscribe URL.

### Added

- Top-level **Apermo Notify** admin menu with two screens:
  Subscribers (default) and Settings.
- Settings page: pick which public post types the plugin is available for and
  toggle the "auto-append form on enabled posts" default.
- Per-post visibility tri-state in the editor sidebar (Default / Show / Hide)
  stored in `_apermo_notify_show_form` post meta.
- Mandatory consent checkbox below the email field. Links to the site's
  Privacy Policy page when one is configured; an admin notice on the
  Settings screen prompts to set the policy when it is missing.
- Per-email "Manage your subscriptions" page reachable from every email
  via a token-bearing URL. Lists every confirmed subscription for the
  address and supports bulk unsubscribe scoped to that email.
- Configurable retention: `stale_after_months` (6 / 12 / 18 / 24) and
  `prune_mode` (hard `delete` or warn-then-`keep_alive`). In keep-alive
  mode, the stale subscriber receives a single warning with a one-click
  keep-alive link; if ignored, the row is removed after the configurable
  `stale_grace_days` window (7 / 14 / 30 / 60 / 90).
- Daily WP-Cron event (`apermo_notify_prune`) that runs the prune workflow,
  registered on activation and self-healing on every `init`.
- Database schema v2 with `consent_at`, `kept_alive_at`, and
  `stale_email_sent_at` columns. Existing v1 rows are backfilled
  (`kept_alive_at = confirmed_at`, `consent_at = created_at`) on upgrade
  so the prune cron doesn't immediately mark legacy data stale.
- WP_List_Table-based Subscribers admin screen with sortable columns,
  status filter, email search, pagination, and per-row + bulk delete.
- Cleanup hook: when a post is permanently deleted, every subscription
  pointing at it is removed automatically.
- Goodbye-notification dialog: moving a post with confirmed subscribers
  to Trash opens a modal offering to send the subscribers a final
  notification (with an optional author note) before the trash request
  fires. Available from both the posts list row-action and the
  edit-screen "Move to Trash" button.
- Block-editor snackbar: clicking Update on an already-published post
  with confirmed subscribers surfaces a "Notify N subscribers" snackbar
  after the save succeeds. The previous metabox checkbox (which had to
  be ticked *before* saving) is gone.

### Changed

- Update notifications no longer auto-fire on `post_updated`. The
  editor snackbar above is now the only path; first-publish events
  still notify automatically.
- Unsubscribe paths now **hard-delete** instead of soft-flipping the
  row to `STATUS_UNSUBSCRIBED`. Aligns with GDPR storage-limitation:
  once the relationship ends, no PII is retained. Affects the
  token-link unsubscribe, the manage-page bulk action, and the
  "Notify & Move to trash" admin flow (which now wipes every
  subscription for the post after sending the goodbye email).
- The "Manage your subscriptions" view is now hosted on a real
  published page chosen in Settings (the new `manage_page_id` option),
  rendered via a `the_content` filter so the active theme's page
  template wraps it — header, footer, sidebars, block templates all
  work. A non-dismissible admin notice and a DDEV orchestrate
  fragment mirror the privacy-policy plumbing. Email links fall back
  to the legacy `/?action=apermo_notify_manage&token=…` shape when
  the option is unset, so already-sent emails keep resolving.

## [0.1.0] - 2026-05-17

### Added

- Per-post subscription flow: visitors can subscribe to a specific post or page
  and receive an email when it is published or updated.
- Double opt-in with token-based confirm and one-click unsubscribe links.
- Custom DB tables (`*_apermo_notify_subscriptions`, `*_apermo_notify_sent_log`)
  with versioned schema management via the vendored
  [`apermo\WPTools\Custom_Tables`](https://gist.github.com/apermo/0fb0cbca1f57625ba6753ef3b7f73ffa)
  helper.
- Editor meta box surfacing the confirmed-subscriber count and an opt-in
  checkbox that triggers a notification on the next save.
- Dispatch on `transition_post_status` (publish event) and `post_updated`
  (author-flagged update event), deduplicated via the sent-log table.
- WordPress privacy hooks: personal-data exporter and eraser keyed on email.
- Public hooks: `apermo_notify_should_send`, `apermo_notify_email_subject`,
  `apermo_notify_email_body_text`, `apermo_notify_subscription_confirmed`,
  `apermo_notify_email_sent`.
- Initial project setup

