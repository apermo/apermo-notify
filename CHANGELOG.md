# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Goodbye-notification dialog: clicking "Delete Permanently" on a post
  with confirmed subscribers opens a modal offering to send the
  subscribers a final notification (with an optional author note)
  before deletion.

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

