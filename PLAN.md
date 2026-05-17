# apermo-notify — Implementation Plan

> Status: planning. The repo is a fresh checkout of `apermo/template-wordpress`. `setup.sh`
> has not been run yet — all `Plugin_Name` / `plugin-name` / `plugin_name` placeholders are
> still in place.

## 1. Goal

A self-hosted WordPress plugin that lets **any visitor** subscribe to a specific piece of
content (post, page, or custom post type entry) and receive an email when that content is
**published or updated**. No SaaS dependencies — delivery goes through `wp_mail()` and a
custom DB table on the host site.

The differentiator vs. existing "new post notification" plugins (Subscribe2, BNFW,
BracketSpace Notification, Post Notif, MailPoet post-notifications): those broadcast new
posts to a site-wide list. apermo-notify is **per-content, visitor-initiated**, and fires
on updates, not just publish.

## 2. Non-goals (explicit)

- Newsletter/campaign sending. We send transactional update notifications only.
- Browser push, SMS, Slack, Discord. Email only in v1.
- Hosted/SaaS sender integrations (Mailgun, Postmark, etc.). The host's `wp_mail()` is
  the contract; admins use an existing SMTP plugin if they need deliverability.

## 3. User stories

### Visitor
- As a visitor on a single post/page, I can enter my email and subscribe to updates of
  *this specific entry*.
- I receive a confirmation email and must click a link before any subscription is active
  (double opt-in).
- Every notification email contains a one-click unsubscribe link that needs no login.
- I can subscribe to "everything by author X", "everything in category Y", or
  "everything in CPT Z" (Phase 2).

### Editor / author
- When editing a post, I see a meta box / sidebar panel with: subscriber count, and a
  checkbox **"Notify subscribers about this update"** (defaults off — opt-in per save to
  prevent spam on trivial edits). On `publish` transition, notification fires
  automatically.
- I can preview the notification email before sending.

### Site admin
- I can see all subscribers grouped by target (post / author / term / CPT).
- I can export/delete subscriber data per GDPR request.
- I can configure: from-name/from-email, email templates, per-CPT enablement, frontend
  form placement (auto-append vs. block/shortcode only).

### Developer
- Public hooks/filters cover: subscription creation, dispatch eligibility, email
  subject/body, recipient list, subscription types. Documented in `docs/hooks.md`.

## 4. Architecture

### 4.1 Data model

Custom tables, registered with `dbDelta` on activation, dropped on uninstall.

`{prefix}apermo_notify_subscriptions`
| column           | type              | notes                                                |
|------------------|-------------------|------------------------------------------------------|
| id               | BIGINT UNSIGNED   | PK auto                                              |
| target_type      | VARCHAR(32)       | `post`, `author`, `term`, `post_type`                |
| target_id        | BIGINT UNSIGNED   | post ID / user ID / term ID / 0 for post_type        |
| target_meta      | VARCHAR(64)       | post_type slug when `target_type = post_type`        |
| filter_json      | TEXT NULL         | optional: keyword / status filters (Phase 2)         |
| email            | VARCHAR(254)      | normalized lowercase                                 |
| token            | CHAR(64)          | random URL-safe, used for confirm + unsubscribe      |
| status           | TINYINT           | 0 = pending, 1 = confirmed, 2 = unsubscribed         |
| created_at       | DATETIME          |                                                      |
| confirmed_at     | DATETIME NULL     |                                                      |
| last_notified_at | DATETIME NULL     |                                                      |

Indexes: `(target_type, target_id)`, `(email)`, `(token)`, unique
`(target_type, target_id, target_meta, email)`.

`{prefix}apermo_notify_sent_log` (dedup + audit)
| column          | type            | notes                                |
|-----------------|-----------------|--------------------------------------|
| id              | BIGINT UNSIGNED | PK auto                              |
| subscription_id | BIGINT UNSIGNED | FK (logical)                         |
| post_id         | BIGINT UNSIGNED | the post the email referenced        |
| event           | VARCHAR(16)     | `publish` or `update`                |
| sent_at         | DATETIME        |                                      |

### 4.2 Module layout (PSR-4 under `src/`)

```
src/
├── Main.php                       # bootstrap; registers services on plugins_loaded
├── Activation.php                 # dbDelta, default options, capabilities
├── Subscription/
│   ├── Repository.php             # CRUD against custom tables
│   ├── Subscription.php           # value object
│   ├── Token.php                  # generate / verify URL-safe tokens
│   └── OptInFlow.php              # double opt-in: send confirm mail, handle confirm/unsub URLs
├── Dispatch/
│   ├── PostHooks.php              # listens to transition_post_status + post_updated
│   ├── Resolver.php               # given (post, event) → which subscriptions match
│   └── Dispatcher.php             # iterates matches, dedups via sent_log, calls Mailer
├── Mail/
│   ├── Mailer.php                 # wp_mail wrapper, template loader, filters
│   └── templates/                 # *.php email templates (overridable from theme)
├── Frontend/
│   ├── Block.php                  # Gutenberg block + render callback
│   ├── Shortcode.php              # [apermo_notify] fallback
│   ├── AutoAppend.php             # optional the_content filter
│   └── FormHandler.php            # admin-post.php endpoint (no REST in v1 — simpler nonce flow)
├── Admin/
│   ├── SubscribersListTable.php   # WP_List_Table per target
│   ├── PostMetaBox.php            # editor sidebar: subscriber count + "notify on update"
│   ├── SettingsPage.php           # Settings API
│   └── views/                     # admin templates
├── Privacy/
│   ├── Exporter.php               # wp_privacy_personal_data_exporters
│   └── Eraser.php                 # wp_privacy_personal_data_erasers
└── Cli/
    └── Commands.php               # `wp apermo-notify ...` (subscribers list/export/dispatch)
```

### 4.3 Dispatch logic

`PostHooks` listens to two hooks:

1. `transition_post_status` — when going `* → publish` for the first time, fire
   `event = publish` against all matching subscriptions (post, author, term, post_type).
2. `post_updated` — only fire `event = update` if **both** apply:
   - target post is currently `publish`, AND
   - the post-meta flag `_apermo_notify_send_on_save` was set by the editor for this save.

The meta flag is reset after dispatch so it never re-fires.

`Resolver` collects subscriptions in this order: direct post → author → terms (all
taxonomies on the post) → post_type. Deduped by `(email, post_id, event)` via
`sent_log` so a subscriber on both "post" and "author X" gets one email.

### 4.4 Public hooks (developer API)

Filters:
- `apermo_notify_should_send( bool, Subscription, WP_Post, event )` — veto.
- `apermo_notify_recipients( array, WP_Post, event )` — final recipient list.
- `apermo_notify_email_subject( string, Subscription, WP_Post, event )`
- `apermo_notify_email_body_html( string, ... )` / `..._text( string, ... )`
- `apermo_notify_subscription_types( array )` — register a custom target type.

Actions:
- `apermo_notify_subscription_confirmed( Subscription )`
- `apermo_notify_email_sent( Subscription, WP_Post, event )`

### 4.5 Compliance (GDPR, CASL, CCPA, COPPA, LGPD)

- Double opt-in is **mandatory** — there is no "skip confirmation" setting.
- Every email contains a one-click, no-login unsubscribe link (token-based).
- WP core privacy tools integration: subscriber emails are exportable + erasable per
  user request.
- Settings page ships a copy-pasteable privacy policy snippet (translatable).
- IP address is *not* stored. `created_at` + the explicit confirmation step are
  sufficient consent records.
- Unconfirmed subscriptions auto-purged after 7 days (configurable).

## 5. Roadmap

### v0.1 — MVP (per-post + per-page only)
- [ ] Run `setup.sh` (slug `apermo-notify`, namespace `Apermo\Notify`, plugin mode, no theme bits).
- [ ] DB schema + activation/uninstall.
- [ ] `Subscription/Repository`, `Token`, `OptInFlow`.
- [ ] Frontend: shortcode + Gutenberg block + form handler (admin-post.php).
- [ ] Dispatch on `publish` and on `update` (with editor checkbox).
- [ ] Confirmation, unsubscribe, and basic plain-text email templates.
- [ ] Admin: per-post subscriber count meta box, global subscriber list table, settings page.
- [ ] Privacy: exporter + eraser.
- [ ] Tests: Unit (Brain Monkey) for Resolver, Token, Repository; Integration for
      dispatch on real hook flow; Playwright E2E for the subscribe → confirm → receive →
      unsubscribe round-trip.

### v0.2 — Stream subscriptions
- [ ] Subscribe by author / term / post_type.
- [ ] UI: "Follow this author" link template tag + block variation.
- [ ] Resolver aggregation + dedup.

### v0.3 — Filters & extensibility
- [ ] Keyword filter on subject/content.
- [ ] Status filter (publish-only vs. any update).
- [ ] HTML email template with theme override path.
- [ ] WP-CLI commands.

### v0.4 — Operational
- [ ] Daily/weekly digest mode (batches multiple updates per recipient).
- [ ] Background sending via `wp_schedule_single_event` so large lists don't block the
      editor save request.
- [ ] Bounce-handling guidance docs (no in-plugin bounce parsing — defer to SMTP plugins).

## 6. Open questions

- Single-site MVP. Multisite: subscriptions per-site, no network table. Confirm before
  v1.0.
- Block-based themes: should the subscribe block be a *post-context* block only, or also
  usable in template parts (then it needs a "current post in loop" check)?
- Editor checkbox default: off (chosen above) vs. on. Off prevents accidental spam; on
  matches naive author intuition. Stay with off; surface clearly in the UI.
- Confirmation email throttling: cap per-IP per-hour to prevent abuse signing strangers
  up. Defer to v0.2 unless found exploited in v0.1.

## 7. Tech stack (inherited from template)

- PHP 8.1+, `declare(strict_types=1)` everywhere.
- PSR-4 under `src/`, namespace `Apermo\Notify`.
- PHPCS via `apermo/apermo-coding-standards` ^3.0 (third-person docblock summaries,
  no short echo tags, `exit;` after redirects).
- PHPStan via `apermo/phpstan-wordpress-rules` + `szepeviktor/phpstan-wordpress`.
- PHPUnit + Brain Monkey for unit; `wp-phpunit` for integration.
- Playwright + `@axe-core/playwright` for E2E + a11y on the subscribe form.
- DDEV (`ddev start && ddev orchestrate`) for local WP.

## 8. First steps for the implementer

1. `cd /Users/cd/repos/apermo/apermo-notify && ./setup.sh`
   — slug: `apermo-notify`, namespace: `Apermo\Notify`, mode: plugin, WP.org publishing: yes.
2. Commit the post-setup state as the first real commit (`chore: bootstrap from template`).
3. Land the schema + activation in a thin first PR — no business logic, just tables and
   uninstall cleanup — so future PRs can assume the storage layer exists.
4. Build the MVP slice (subscribe → confirm → publish dispatch → unsubscribe) end-to-end
   on the per-post target before touching authors/terms/CPTs. Resist generalizing the
   `Resolver` until v0.2 — the per-post path will reveal the right shape.
