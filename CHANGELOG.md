# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Removed the `[apermo_notify]` shortcode. The subscribe form is now placed
  via `the_content` (default-on for the post types configured in Settings)
  with a per-post show/hide override in the editor sidebar.

### Added

- Top-level **Apermo Notify** admin menu with two screens:
  Subscribers (default) and Settings.
- Settings page: pick which public post types the plugin is available for and
  toggle the "auto-append form on enabled posts" default.
- Per-post visibility tri-state in the editor sidebar (Default / Show / Hide)
  stored in `_apermo_notify_show_form` post meta.

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

