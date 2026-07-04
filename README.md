# Reslab Activity Log

A lightweight, privacy-aware audit log plugin for WordPress. Tracks every meaningful change on your site — authentication, content, users, plugins, WooCommerce orders, and more — and stores it in a dedicated database table for maximum performance.

## Features

### Core tracking
| Area | Events |
|---|---|
| **Authentication** | Login, logout, failed login attempts |
| **Content** | Post creation, publishing, trashing, deletion; slug/title diffs |
| **Users** | Registration, profile changes (email, name, password, **role changes**), deletion |
| **Plugins & Themes** | Activation, deactivation, installation, updates, theme switches |
| **Settings** | Changes to key `wp_options` keys (site URL, admin email, permalinks, etc.) |
| **Navigation** | Menu updates |

### WooCommerce integration
- Order status transitions — **HPOS-compatible** (`woocommerce_order_status_changed`)
- Product price and stock changes with before/after diff
- Coupon application and removal
- Refund creation (amount, reason, refunded_by)

### Polylang integration
- Language assignments logged when a post is saved via Polylang (`pll_save_post`)

### Security alerts *(both opt-in, disabled by default)*
Two independent hourly WP-Cron checks, each with its own threshold/window:
- **Brute-force** — too many `login_failed` events from the same IP.
- **Mass deletion** — one user logging an unusually large number of `deleted` events in a short window (compromised/malicious account bulk-deleting content, orders, or users).

Alerts are emailed to the admin; optionally also POSTed as JSON to a **webhook URL** (Slack/Discord incoming webhooks, Zapier, Make, n8n, or any custom endpoint) — see `reslab_al_alert_webhook_url` below. Duplicate alerts within the same window are suppressed via transient. A `reslab_al_alert_{$type}` action hook (`bruteforce` or `mass_deletion`) fires for custom PHP integrations beyond a generic webhook.

### Privacy & GDPR
- **IP anonymisation** — enabled by default; mask last IPv4 octet (e.g. `192.168.1.0`)
- **Email hashing** — user email stored as SHA-256 hash, never plain text
- **Configurable retention** — auto-delete entries older than N days (default: 30)
- **Archive before purge** *(opt-in)* — save a gzip CSV snapshot of entries before the nightly purge deletes them; downloadable from Settings (nonce + capability gated, no public URL)
- **Custom capabilities** — `reslab_al_view_log` / `reslab_al_clear_log` / `reslab_al_manage_settings` for granular role access; `reslab_al_viewable_object_types` filter further restricts *which* event types a role sees
- **Full uninstall** — `uninstall.php` drops the table, options, transients, archive files, and capabilities

### Admin UI
- Located at **Tools → Activity Log** (not polluting the main menu)
- Filter by action, object type, user, IP address, date range, or free-text search over event details
- Events fired within the same request are grouped into one row (with a `+N` badge), instead of one row per hook
- Inline before/after diff, collapsed by default (`<details>/<summary>`)
- **Export CSV** with active filters applied, or pull events via the read-only REST API (`GET /wp-json/reslab-al/v1/events`, Application-Password authenticated) for external monitoring/SIEM tools
- Screen Options for per-page count
- **Tools → Activity Log Settings** — retention (+ archiving), IP anonymisation, both alert types, webhook URL, and "last ran" status for every background job

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WooCommerce *(optional)* | 7.0+ |
| Polylang *(optional)* | 3.0+ |

## Installation

```bash
# Via Composer (if registered)
composer require reslab/activity-log

# Or manually — copy to your plugins directory
cp -r reslab-activity-log /path/to/wp-content/plugins/
```

Then activate via **Plugins → Installed Plugins**.

## Configuration

### Trusted proxy (Cloudflare / reverse proxy)

Add to `wp-config.php`:

```php
define( 'RESLAB_AL_TRUSTED_PROXIES', '10.0.0.1' ); // IP of your proxy
```

Or use the filter:

```php
add_filter( 'reslab_al_trusted_proxies', function( array $ips ): array {
    $ips[] = '10.0.0.1';
    return $ips;
} );
```

Without this, only `REMOTE_ADDR` is used — no IP spoofing possible from forged headers.

### Granting log access to editors or shop managers

Preferred: let the plugin grant it itself on activation/upgrade, no role-editor plugin needed —

```php
add_filter( 'reslab_al_default_roles', function ( array $roles ): array {
    $roles[] = 'shop_manager';
    return $roles;
} );
```

Or, for a one-off/ad-hoc grant instead:

```php
// Run once, e.g. in a one-time migration or via a role-editor plugin.
$role = get_role( 'shop_manager' );
$role->add_cap( 'reslab_al_view_log' ); // can view
// $role->add_cap( 'reslab_al_clear_log' ); // can also clear
```

Pair this with `reslab_al_viewable_object_types` to limit *what* that role sees once it can view the log at all — e.g. a shop manager who shouldn't see user-management or plugin/theme events:

```php
add_filter( 'reslab_al_viewable_object_types', function ( array $types, int $user_id ): array {
    $user = get_userdata( $user_id );
    if ( $user && in_array( 'shop_manager', $user->roles, true ) ) {
        return [ 'order', 'coupon', 'product' ];
    }
    return $types; // unrestricted for everyone else
}, 10, 2 );
```

## File structure

```
reslab-activity-log/
├── reslab-activity-log.php          # Plugin header, activation/deactivation, bootstrap
├── uninstall.php                    # Full cleanup on plugin deletion
├── readme.txt                       # WordPress.org readme
├── languages/                       # Translation files (.po/.mo)
├── assets/css/admin.css             # Admin list table + settings page styles
└── includes/
    ├── class-tracker.php            # Core WordPress hook interceptors
    ├── class-tracker-woocommerce.php # WooCommerce-specific hooks (loaded only if WC active)
    ├── class-list-table.php         # WP_List_Table UI + Admin page wrapper
    ├── class-cron.php               # Log rotation, purge, brute-force/mass-deletion alerts (WP-Cron)
    ├── class-settings.php           # Settings page (Tools → Activity Log Settings)
    └── class-rest-api.php           # Read-only REST API (/wp-json/reslab-al/v1/events)
```

Dev-only, not shipped in a release build (see [Development](#development)): `tests/`, `vendor/`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `.wordpress-org/` (wordpress.org listing assets — banners/icons/screenshots, not plugin code), `.gitignore`.

## Database

The plugin creates a single table `{prefix}reslab_activity_log` (renamed from
`{prefix}activity_log` in 1.1.0 — earlier installs are migrated automatically
via `RENAME TABLE` on the first request after upgrading):

```sql
CREATE TABLE wp_reslab_activity_log (
    id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    ip_address  VARCHAR(45)         NOT NULL DEFAULT '',
    action      VARCHAR(50)         NOT NULL DEFAULT '',
    object_type VARCHAR(50)         NOT NULL DEFAULT '',
    object_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    context     LONGTEXT,
    request_id  VARCHAR(20)         NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_created_at     (created_at),
    KEY idx_user_id        (user_id),
    KEY idx_action         (action),
    KEY idx_object_type    (object_type),
    KEY idx_action_created (action, created_at),
    KEY idx_request_id     (request_id)
);
```

`request_id` (added in 1.3.0) is a short random ID shared by every event logged within the same HTTP/cron request — it's what lets the admin UI collapse a single editorial save (which can fire several hooks: status transition, content diff, Polylang language assignment) into one row instead of 3-6 near-duplicate ones.

## Hooks reference

### Filters

| Filter | Description |
|---|---|
| `reslab_al_trusted_proxies` | Array of trusted proxy IP addresses for forwarded-header IP resolution |
| `reslab_al_viewable_object_types` | `array $types, int $user_id` — restrict which `object_type` values the current user can see in the log, export, and REST API. Empty (default) = unrestricted. |
| `reslab_al_default_roles` | `array $roles` — roles granted `reslab_al_view_log` / `reslab_al_clear_log` / `reslab_al_manage_settings` on activation and on every schema upgrade. Defaults to `[ 'administrator' ]`. |

### Actions

| Action | Description |
|---|---|
| `reslab_al_alert_bruteforce` | `array $payload` — fires after a brute-force alert email is sent. `$payload` has `ip`, `attempts`, `window_hours`, `log_url`. |
| `reslab_al_alert_mass_deletion` | `array $payload` — fires after a mass-deletion alert email is sent. `$payload` has `user_id`, `user_login`, `deletions`, `window_hours`, `log_url`. |

Use these for integrations a generic webhook POST can't handle (a Telegram bot API call, a signed Slack SDK request, writing to another system, etc.). For simpler cases, set `reslab_al_alert_webhook_url` in Settings instead — no code required.

### REST API

`GET /wp-json/reslab-al/v1/events` — read-only, paginated (`page`, `per_page`, max 200), authenticated via [WP Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/), gated by `reslab_al_view_log`. Accepts the same `filter_action` / `filter_object_type` / `filter_user` / `filter_date_from` / `filter_date_to` / `filter_ip` / `filter_search` query params as the admin screen, and respects `reslab_al_viewable_object_types`. Response includes `X-WP-Total` / `X-WP-TotalPages` headers.

## Security

- All database queries use `$wpdb->prepare()` or `$wpdb->insert()` with format arrays
- All output is escaped with `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_kses()`
- Forms protected with `wp_nonce_field()` / `check_admin_referer()`
- Capability checks on every admin action (`reslab_al_view_log`, `reslab_al_clear_log`, `reslab_al_manage_settings`)
- IP resolution only trusts forwarded headers from explicitly whitelisted proxy IPs
- CSV export neutralises cell values that would otherwise be interpreted as spreadsheet formulas (CSV/Formula injection)
- Archive downloads use random filenames + a directory-listing-blocking `index.php` stub, and are only ever served through a nonce + `reslab_al_view_log`-gated handler — never a public/guessable URL
- REST endpoint requires `reslab_al_view_log` via WordPress's standard Application Passwords auth; no custom API-key scheme to get wrong

## Development

Integration tests run against a real WordPress + MySQL install (via `wp-phpunit/wp-phpunit`), including WooCommerce loaded the same way it would be as an active plugin — see [tests/README.md](tests/README.md) for setup and how to run them.

`tests/`, `vendor/`, `composer.json`/`composer.lock`, and `phpunit.xml.dist` are dev-only and are never included in a release build (the WordPress.org submission ZIP / SVN `trunk` should only contain the runtime plugin files listed under [File structure](#file-structure) above, plus `readme.txt`).

## Changelog

### 1.4.1
- Fixed: successful logins and logouts were logged with the acting user recorded as "Guest" (`user_id = 0`) — `wp_login`/`wp_logout` fire before WordPress considers that user "current" for the request, so `get_current_user_id()` was still 0 at that point. The tracker now uses the user WordPress itself passes to those hooks.
- Fixed: user-deletion events always logged an empty `login` — `deleted_user` fires after the row is already gone from `wp_users`, so re-fetching by ID always failed. Now uses the `WP_User` object core passes as the hook's third argument (added in WP 5.5).
- Fixed: the REST API endpoint returned a `_doing_it_wrong()` notice (`wpdb::prepare()` called with no placeholders) when hit with no filters active.
- Fixed: the log table's `CREATE TABLE` SQL had inconsistent internal whitespace between column names and types. `dbDelta()`'s column-definition parser is picky about exactly one space there; the padding made it think every padded column had changed, reissuing 8 `ALTER TABLE ... CHANGE COLUMN` statements on *every* version-gated upgrade check — never actually a no-op like the code's own comments assumed. Harmless functionally, but unnecessary DDL against a potentially large log table.
- Added: `reslab_al_default_roles` filter — controls which roles get the plugin's capabilities on activation/upgrade (previously hardcoded to `administrator` only).
- Changed: the schema-upgrade lock (`reslab_al_maybe_upgrade_table()`) now uses an atomic `add_option()` instead of a `get_transient()`/`set_transient()` pair, closing a check-then-act race under concurrent requests.
- Internal: added a full PHPUnit integration test suite (`tests/`, dev-only, see [Development](#development) below) exercising every tracker, the cron jobs, list-table filtering/grouping, the REST API, and settings sanitization against a real WordPress + MySQL install.

### 1.4.0
- Added: Mass Deletion Alerts — a second, independent anomaly check (off by default) for one user deleting an unusual number of objects in a short window
- Added: optional webhook URL — alerts are POSTed as JSON alongside email (Slack/Discord/Zapier/Make/n8n); new `reslab_al_alert_{$type}` action hook for custom integrations
- Added: "Archive before purge" — gzip CSV snapshot of entries saved before the nightly purge deletes them, downloadable from Settings
- Added: read-only REST API (`GET /wp-json/reslab-al/v1/events`) for external monitoring/SIEM tools

### 1.3.0
- Added: events fired within the same request (e.g. a post save that triggers a status transition + content diff + Polylang language assignment) are now grouped into a single row in the admin list; the rest are shown in the row's expandable details instead of flooding the log
- Added: "last ran X ago" status line under Data Retention and Brute-Force Alerts in Settings, so it's visible whether WP-Cron is actually executing those jobs
- Added: free-text search (`filter_search`) over the event context — finds entries by product/post title, username, option name, coupon code, etc.
- Added: `reslab_al_viewable_object_types` filter is now implemented (was previously documented as planned) — restricts which `object_type` values a role/user can see in both the list table and CSV export
- Schema: new `request_id` column (+ index) on the log table, populated automatically; existing installs migrate via `dbDelta()` on upgrade

### 1.2.0
- Fixed WooCommerce orders being logged twice (once as `order`, once as a generic `post` mislabeled `(deleted)` under HPOS) — the generic tracker now ignores WC order/refund post types entirely; deletion is tracked via `woocommerce_delete_order` instead
- Fixed the `order` object link to resolve via `wc_get_order()->get_edit_order_url()` (HPOS-aware) instead of a `post.php?post=` link that 404s under HPOS
- Fixed `auto-draft` transitions creating noise (`status_changed_to_auto-draft`) in the log
- Changed: IP anonymisation now enabled by default on new/unconfigured installs
- Changed: brute-force email alerts are now opt-in (disabled by default) via a new Settings checkbox

### 1.1.0
- Fixed CSV/Formula injection in the export action
- Renamed the DB table to `{prefix}reslab_activity_log` (auto-migrated) to avoid collisions
- Added composite index `(action, created_at)`; capped and self-rescheduling log purge; throttled schema-version check
- Added `reslab_al_manage_settings` capability for the Settings page

### 1.0.0
- Initial release

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
