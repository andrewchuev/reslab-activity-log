<?php
/**
 * Plugin Name:       Reslab Activity Log
 * Plugin URI:        https://reslab.pro
 * Description:       Lightweight audit log for user and system activity.
 * Version:           1.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Reslab
 * License:           GPL-2.0-or-later
 * Text Domain:       reslab-activity-log
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RESLAB_AL_VERSION', '1.4.1' );
define( 'RESLAB_AL_FILE', __FILE__ );
define( 'RESLAB_AL_DIR', plugin_dir_path( __FILE__ ) );
define( 'RESLAB_AL_URL', plugin_dir_url( __FILE__ ) );
define( 'RESLAB_AL_TABLE', 'reslab_activity_log' );
define( 'RESLAB_AL_LEGACY_TABLE', 'activity_log' );

// -------------------------------------------------------------------------
// Activation / Deactivation
// -------------------------------------------------------------------------

register_activation_hook( RESLAB_AL_FILE, 'reslab_al_activate' );
register_deactivation_hook( RESLAB_AL_FILE, 'reslab_al_deactivate' );

function reslab_al_activate(): void {
	reslab_al_create_table(); // also grants capabilities — see reslab_al_add_capabilities().
	require_once RESLAB_AL_DIR . 'includes/class-cron.php';
	Reslab_AL_Cron::schedule();
}

function reslab_al_deactivate(): void {
	require_once RESLAB_AL_DIR . 'includes/class-cron.php';
	Reslab_AL_Cron::unschedule();
}

/**
 * Grants reslab_al_view_log, reslab_al_clear_log and reslab_al_manage_settings
 * to every role returned by the 'reslab_al_default_roles' filter (defaults to
 * administrator only). Use the filter to extend access to other roles (e.g.
 * shop_manager) without a separate role-editor plugin.
 */
function reslab_al_add_capabilities(): void {
	/**
	 * Roles granted reslab_al_view_log / reslab_al_clear_log /
	 * reslab_al_manage_settings on activation and on every schema upgrade.
	 *
	 * @param string[] $roles
	 */
	$roles = apply_filters( 'reslab_al_default_roles', [ 'administrator' ] );

	foreach ( array_unique( $roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( 'reslab_al_view_log' );
			$role->add_cap( 'reslab_al_clear_log' );
			$role->add_cap( 'reslab_al_manage_settings' );
		}
	}
}

/**
 * Renames the pre-1.1.0 table (`{prefix}activity_log`) to the collision-safe
 * name (`{prefix}reslab_activity_log`), preserving existing data.
 */
function reslab_al_migrate_legacy_table(): void {
	global $wpdb;

	$old_table = $wpdb->prefix . RESLAB_AL_LEGACY_TABLE;
	$new_table = reslab_al_table();

	$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
	$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );

	if ( $old_exists && ! $new_exists ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" );
	}
}

function reslab_al_create_table(): void {
	global $wpdb;

	reslab_al_migrate_legacy_table();

	$table   = reslab_al_table();
	$charset = $wpdb->get_charset_collate();

	// dbDelta() parses column definitions with a regex that expects exactly
	// one space between the column name and its type; visually aligning
	// these with extra padding spaces (as a previous version of this SQL
	// did) makes dbDelta() see every padded column as "changed" on every
	// single call, turning what should be a no-op into a real ALTER TABLE
	// each time — never keep more than one space here.
	$sql = "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		ip_address VARCHAR(45) NOT NULL DEFAULT '',
		action VARCHAR(50) NOT NULL DEFAULT '',
		object_type VARCHAR(50) NOT NULL DEFAULT '',
		object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		context LONGTEXT,
		request_id VARCHAR(20) NOT NULL DEFAULT '',
		PRIMARY KEY (id),
		KEY idx_created_at (created_at),
		KEY idx_user_id (user_id),
		KEY idx_action (action),
		KEY idx_object_type (object_type),
		KEY idx_action_created (action, created_at),
		KEY idx_request_id (request_id)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Re-run on every schema upgrade so sites updated in place also get new caps.
	reslab_al_add_capabilities();

	update_option( 'reslab_al_db_version', RESLAB_AL_VERSION );
}

/**
 * Ensures the table/capabilities are current after manual DB resets, without
 * running dbDelta() on every single front-end request while the mismatch
 * persists (dbDelta() queries INFORMATION_SCHEMA and is not cheap).
 */
function reslab_al_maybe_upgrade_table(): void {
	if ( get_option( 'reslab_al_db_version' ) === RESLAB_AL_VERSION ) {
		return;
	}

	// add_option() fails atomically — it relies on the unique key on
	// wp_options.option_name — if another request already holds the lock.
	// A get_transient()/set_transient() pair would instead have a
	// check-then-act race: two concurrent requests could both pass the
	// get_transient() check before either had a chance to set it.
	if ( ! add_option( 'reslab_al_upgrade_lock', time(), '', false ) ) {
		$locked_at = (int) get_option( 'reslab_al_upgrade_lock' );
		if ( $locked_at > time() - 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		update_option( 'reslab_al_upgrade_lock', time(), false );
	}

	reslab_al_create_table();
	delete_option( 'reslab_al_upgrade_lock' );
}

/**
 * Prefixed name of the activity log table. Single source of truth for the
 * `$wpdb->prefix . RESLAB_AL_TABLE` concatenation used throughout the plugin.
 */
function reslab_al_table(): string {
	global $wpdb;
	return $wpdb->prefix . RESLAB_AL_TABLE;
}

/**
 * One short random ID per HTTP/cron request, shared by every tracker's
 * log() call in that request. Lets the admin list table collapse the several
 * hooks a single editorial save (or checkout) fires into one visual row
 * instead of flooding the log with near-duplicate rows.
 */
function reslab_al_request_id(): string {
	static $id = null;

	if ( $id === null ) {
		$id = substr( wp_generate_password( 12, false, false ), 0, 12 );
	}

	return $id;
}

/**
 * Directory for pre-purge CSV archives (see "Archive before purge" in
 * Settings). Filenames are random and this directory ships an index.php
 * stub blocking directory listing — downloads only happen through the
 * nonce + capability gated handler, never a direct/guessable URL.
 */
function reslab_al_archive_dir(): string {
	$upload_dir = wp_upload_dir();
	return trailingslashit( $upload_dir['basedir'] ) . 'reslab-al-archives/';
}

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

add_action( 'plugins_loaded', 'reslab_al_init' );

function reslab_al_init(): void {
	load_plugin_textdomain( 'reslab-activity-log', false, dirname( plugin_basename( RESLAB_AL_FILE ) ) . '/languages' );
	reslab_al_maybe_upgrade_table();

	require_once RESLAB_AL_DIR . 'includes/class-tracker.php';
	require_once RESLAB_AL_DIR . 'includes/class-list-table.php';
	require_once RESLAB_AL_DIR . 'includes/class-cron.php';
	require_once RESLAB_AL_DIR . 'includes/class-settings.php';
	require_once RESLAB_AL_DIR . 'includes/class-rest-api.php';

	new Reslab_AL_Tracker();
	new Reslab_AL_Admin();
	new Reslab_AL_Cron();
	new Reslab_AL_Settings();
	new Reslab_AL_REST_API();

	if ( class_exists( 'WooCommerce' ) ) {
		require_once RESLAB_AL_DIR . 'includes/class-tracker-woocommerce.php';
		new Reslab_AL_Tracker_WooCommerce();
	}
}
