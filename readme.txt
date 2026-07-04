=== Reslab Activity Log ===
Contributors: reslab
Tags: activity log, audit log, security, user tracking, woocommerce
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.4.1
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, privacy-aware audit log that tracks every meaningful change on your WordPress site — from post edits to WooCommerce orders.

== Description ==

Reslab Activity Log records who did what and when on your WordPress site. It is built for performance, stores all data in a dedicated database table (not `wp_posts`, `wp_options`, or post meta — see "Why a custom database table?" in the FAQ), and ships with a clean admin interface to browse, filter, and export the history.

**Core tracking**

* **Authentication** — successful logins, logouts, and failed login attempts (brute-force detection included).
* **Content** — post creation, publishing, trashing, and deletion; slug and title changes tracked with before/after diff.
* **Users** — registration, profile changes (email, display name, password, role changes), deletions.
* **Plugins & themes** — activation, deactivation, installation, updates, and theme switches.
* **Settings** — changes to key WordPress options (site title, URL, email, permalink structure, etc.).
* **Navigation menus** — menu updates.

**WooCommerce integration** *(requires WooCommerce)*

* Order status transitions — fully compatible with High-Performance Order Storage (HPOS).
* Product price and stock changes — logged with old and new values.
* Coupon applications and removals.
* Refund creation — records amount, reason, and who performed it.

**Polylang integration** *(requires Polylang)*

* Language assignments to posts are logged when a post is saved via Polylang.

**Security alerts** *(opt-in, disabled by default)*

Two independent hourly WP-Cron checks, each with its own threshold/window:

* **Brute-force** — too many failed login attempts from the same IP.
* **Mass deletion** — one user logging an unusually large number of "deleted" events in a short window (a compromised or malicious account deleting content/orders/users in bulk).

Alerts are emailed to the site administrator; optionally also POSTed as JSON to a webhook URL (Slack/Discord incoming webhooks, Zapier, Make, n8n, or any custom endpoint). Duplicate alerts within the same window are suppressed automatically.

**Privacy & GDPR**

* IP addresses are anonymised by default (last IPv4 octet masked); can be turned off in Settings if your use case needs full IPs.
* User email addresses are never stored in plain text — only a SHA-256 hash is kept.
* Log retention period is configurable (default: 30 days); entries are deleted automatically every night. Optionally archive entries to a gzip CSV before they're purged.
* Custom capabilities (`reslab_al_view_log`, `reslab_al_clear_log`) allow granular access control per role; the `reslab_al_viewable_object_types` filter can further restrict *which* event types a role sees.
* A full `uninstall.php` removes the database table, all options, archive files, and capabilities when the plugin is deleted.

**Admin interface**

* Located under **Tools → Activity Log** — not cluttering the main admin menu.
* Filter by action, object type, user, IP address, date range, or free-text search across event details.
* Events fired within the same request (e.g. a post save that also triggers a language assignment) are grouped into a single row instead of flooding the log with near-duplicates.
* Before/after diff shown inline for every changed field, collapsed by default (`<details>`/`<summary>`).
* Export filtered results to CSV, or pull them programmatically via a read-only REST API (`/wp-json/reslab-al/v1/events`) for external monitoring/SIEM tools.
* **Tools → Activity Log Settings** for retention (with optional pre-purge archiving), IP anonymisation, both alert types, webhook notifications, and "last ran" status for every background job.

== Installation ==

1. Upload the `reslab-activity-log` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Tools → Activity Log** to start browsing events.
4. Optionally configure retention and privacy settings at **Tools → Activity Log Settings**.

**Trusted proxy configuration**

If your site runs behind Cloudflare or a reverse proxy and you want the real visitor IP to be logged, add the following to `wp-config.php`:

`define( 'RESLAB_AL_TRUSTED_PROXIES', '10.0.0.1' );`

Replace `10.0.0.1` with the IP address of your proxy. You can also use the `reslab_al_trusted_proxies` filter for programmatic configuration.

== Frequently Asked Questions ==

= Why a custom database table? =

An audit log is append-heavy, filtered by date range and rarely edited — the opposite access pattern of `wp_posts`. Storing entries as a custom post type would mean one `wp_posts` + one `wp_postmeta` row per field for every login, save, and setting change, multiplying table size and slowing down the post-editing tables every other plugin also queries. A dedicated table with purpose-built indexes (`created_at`, `user_id`, `action`, `object_type`, and a composite `action + created_at` index for brute-force detection) keeps writes cheap and filtered reads fast even with a large history, without adding load to `wp_posts`/`wp_postmeta`. The table is namespaced (`{prefix}reslab_activity_log`) to avoid clashing with other logging plugins, is created via `dbDelta()` on activation/upgrade, and is fully removed by `uninstall.php`.

= Does this plugin slow down my site? =

No meaningful impact on front-end performance. The tracker hooks fire only when specific admin or server-side actions occur. Database writes use a single `$wpdb->insert()` call with no additional queries.

= What happens to the log when I delete the plugin? =

`uninstall.php` drops the `{prefix}reslab_activity_log` table, removes all plugin options, deletes the brute-force alert transient, and strips the custom capabilities from all roles. Nothing is left behind.

= Can I let editors or shop managers view the log? =

Yes, two ways. Either grant the `reslab_al_view_log` capability to any role using a role-editor plugin, or add roles to the `reslab_al_default_roles` filter (`array $roles`, defaults to `[ 'administrator' ]`) so the plugin grants it itself on activation and on every schema upgrade — no role-editor plugin required. Either way they'll see the full log but won't have access to the Clear log button (which requires `reslab_al_clear_log`). By default they'll see every event type; add a callback on the `reslab_al_viewable_object_types` filter (`array $types, int $user_id`) to limit a role to, say, only `order`/`coupon` events — see README.md for an example.

= I am running WooCommerce with HPOS. Will order status changes still be logged? =

Yes. The WooCommerce tracker uses the `woocommerce_order_status_changed` action hook, which fires reliably regardless of whether legacy post-based orders or HPOS is enabled.

= How is the brute-force detection triggered? =

Off by default — enable "Enable brute-force alerts" on the Settings page first. Once enabled, an hourly WP-Cron event counts `login_failed` entries per IP address within a configurable window (default: 10 attempts / 1 hour). When the threshold is exceeded a single email is sent to the admin email address. A transient prevents duplicate alerts for the same IP within the same window.

= What is "Mass Deletion Alerts"? =

Also off by default. It's a second, independent hourly check that counts `deleted` events per user within a configurable window (default: 5 deletions / 1 hour) — the pattern a compromised account or malicious insider leaves behind (bulk order/content/user deletion), which brute-force detection doesn't catch on its own.

= Can I get alerts in Slack/Discord/Telegram instead of email? =

Set a "Webhook URL" on the Settings page (under Notifications). Every brute-force and mass-deletion alert is emailed *and* POSTed as JSON to that URL, so it works directly with Slack/Discord incoming webhooks, or through a no-code tool (Zapier, Make, n8n) for Telegram or anything else. There's also a `reslab_al_alert_{$type}` action hook (`bruteforce` or `mass_deletion`) for custom PHP integrations.

= Can I pull the log into an external monitoring/SIEM tool? =

Yes — `GET /wp-json/reslab-al/v1/events` returns paginated JSON, authenticated via WordPress Application Passwords (Settings → your profile → Application Passwords) and gated by the `reslab_al_view_log` capability. It accepts the same `filter_*` query parameters as the admin screen (action, object_type, user, date range, search) and respects `reslab_al_viewable_object_types`.

= My site is behind a reverse proxy. Is IP logging safe? =

By default the plugin only reads `REMOTE_ADDR`, which is the direct connection IP. Forwarded headers (`X-Forwarded-For`, `CF-Connecting-IP`) are only trusted when the connecting IP is listed in `RESLAB_AL_TRUSTED_PROXIES` or the `reslab_al_trusted_proxies` filter, preventing IP spoofing.

= Does the plugin store any data externally? =

Not by default. All data stays in your WordPress database, and the plugin makes no outbound requests unless you explicitly configure a webhook URL for alerts (see above) — in which case only the alert payload (not the full log) is sent to that URL.

= Is the plugin multisite compatible? =

The plugin is not explicitly built for multisite in this release. It can be network-activated but will share a single log table for all sites on the network. Per-site tables are planned for a future release.

== Screenshots ==

1. The Activity Log table — filter by action, object type, user, date range, or free-text search; date, user with avatar, IP address, and action badge for every event.
2. Inline before/after diff, expanded — shown collapsed by default inside a `<details>` element (here: a WooCommerce order status change from `pending` to `processing`).
3. Settings — data retention with optional pre-purge archiving, GDPR IP anonymisation, and brute-force alert configuration, each with a "last ran" status.
4. Settings continued — Mass Deletion Alerts and the optional webhook URL for Slack/Discord/Zapier/Make/n8n notifications.

== Changelog ==

= 1.4.1 =
* Fixed: successful logins and logouts were logged with the acting user recorded as "Guest" — WordPress fires `wp_login`/`wp_logout` before it considers that user "current" for the request, so the log now records the user WordPress itself passes to those hooks instead of relying on `get_current_user_id()`.
* Fixed: user-deletion events always logged an empty username, because `deleted_user` fires after the user row is already gone from the database; the log now uses the `WP_User` object core passes directly to that hook.
* Fixed: the REST API endpoint (`/wp-json/reslab-al/v1/events`) triggered a `_doing_it_wrong()` notice when called with no filters active.
* Fixed: inconsistent internal whitespace in the log table's schema definition made `dbDelta()` reissue a full set of `ALTER TABLE` statements on every version-gated upgrade check, even when nothing had actually changed — harmless but unnecessary, and avoidable table-lock overhead on a large log.
* Added: `reslab_al_default_roles` filter — controls which roles are granted the plugin's capabilities on activation/upgrade (previously administrator-only, hardcoded).
* Changed: the internal schema-upgrade lock now uses an atomic `add_option()` instead of a `get_transient()`/`set_transient()` pair, closing a race window under concurrent requests.

= 1.4.0 =
* Added: Mass Deletion Alerts — a second, independent anomaly check (off by default) that catches one user deleting an unusual number of objects in a short window.
* Added: optional webhook URL — brute-force and mass-deletion alerts are POSTed as JSON alongside the existing email, for Slack/Discord/Zapier/Make/n8n integrations. New `reslab_al_alert_{$type}` action hook for custom integrations.
* Added: "Archive before purge" — optionally save a gzip CSV snapshot of entries before the nightly purge deletes them, downloadable from Settings (nonce + capability gated, not a direct/public URL).
* Added: read-only REST API (`GET /wp-json/reslab-al/v1/events`), authenticated via WordPress Application Passwords, for external monitoring/SIEM tools. Supports the same filters as the admin screen.

= 1.3.0 =
* Added: events fired within the same request are now grouped into a single row in the log (with the rest listed in the expandable details) instead of producing several near-duplicate rows for one editorial save.
* Added: "last ran X ago" status under Data Retention and Brute-Force Alerts in Settings, to confirm those WP-Cron jobs are actually executing.
* Added: free-text search across event details (product/post titles, usernames, option names, coupon codes, etc.).
* Added: the `reslab_al_viewable_object_types` filter is now implemented, letting you restrict which event types a role/user can see in the log and CSV export.
* Schema change: new `request_id` column (+ index); migrates automatically via `dbDelta()` on upgrade, no manual action needed.

= 1.2.0 =
* Fixed: WooCommerce order transitions were logged twice — once correctly as an "order" event, once again as a generic "post" event mislabeled `(deleted)` (orders under HPOS don't live in `wp_posts`). The generic tracker now leaves WooCommerce order/refund post types alone entirely; order deletion is tracked separately via `woocommerce_delete_order`.
* Fixed: the "order" object link now resolves through `wc_get_order()->get_edit_order_url()`, which works correctly for both HPOS and legacy post-based orders (the old link pointed at `post.php?post=`, which 404s under HPOS).
* Fixed: `auto-draft` post-status transitions (WordPress reserving a post ID before the editor even opens) no longer create a `status_changed_to_auto-draft` log entry.
* Changed: "Anonymize IP addresses" is now enabled by default on new/unconfigured installs, for GDPR compliance out of the box.
* Changed: brute-force email alerts are now opt-in — a new "Enable brute-force alerts" checkbox (disabled by default) gates the hourly check; the threshold/window settings only take effect once it's turned on.

= 1.1.0 =
* Security: fixed a CSV/Formula injection vector in the "Export CSV" action — fields starting with `=`, `+`, `-` or `@` are now neutralised.
* Renamed the database table from `{prefix}activity_log` to `{prefix}reslab_activity_log` to avoid collisions with other logging plugins; existing data is migrated automatically via `RENAME TABLE` on upgrade.
* Added a composite index on `(action, created_at)` to speed up the brute-force detection query on large logs.
* CSV export now streams results in batches instead of loading the entire (filtered) log into memory.
* Log purge now caps the number of batches processed per WP-Cron run and reschedules itself to finish large retention-period drops instead of risking a request timeout.
* Added a dedicated `reslab_al_manage_settings` capability for the Settings page, replacing the hardcoded `manage_options` check, for consistency with `reslab_al_view_log` / `reslab_al_clear_log`.
* The table-version check on `plugins_loaded` no longer re-runs `dbDelta()` on every front-end request while a mismatch persists; it is now throttled with a short-lived lock.

= 1.0.0 =
* Initial release.
* Tracks authentication, content, users, plugins, themes, settings, and navigation menus.
* WooCommerce integration: order status, product meta, coupons, refunds.
* Polylang integration: language assignments.
* Brute-force email alert via hourly WP-Cron.
* CSV export with active filter support.
* GDPR features: IP anonymisation, configurable retention period, email hashing.
* Custom capabilities `reslab_al_view_log` and `reslab_al_clear_log`.
* Full `uninstall.php` for clean removal.

== Upgrade Notice ==

= 1.4.1 =
Fixes login/logout events being attributed to "Guest" and user-deletion events logging an empty username. Recommended for everyone on 1.4.0. No breaking changes.

= 1.4.0 =
Adds mass-deletion alerts, webhook notifications, pre-purge archiving, and a REST API — all opt-in/off by default. No breaking changes.

= 1.3.0 =
Adds a database column (auto-migrated, no action needed) and groups multi-event log entries into single rows for readability. No breaking changes.

= 1.2.0 =
Fixes duplicate/mislabeled log entries for WooCommerce orders and enables IP anonymization by default. If you were relying on brute-force email alerts, re-enable them on the Settings page — they're now opt-in and off by default.

= 1.1.0 =
Renames the log table and adds a new capability; both are migrated automatically on the first request after upgrading — no manual steps required. Also fixes a CSV export security issue, so updating is recommended.

= 1.0.0 =
Initial release — no upgrade steps required.
