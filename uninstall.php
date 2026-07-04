<?php
/**
 * Fired when the plugin is uninstalled (deleted via the WordPress admin).
 * Removes the custom database table, all plugin options, and capabilities.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the activity log table — both the current name and the pre-1.1.0
// name, in case a site is deleted before ever loading the upgrade routine.
foreach ( [ 'reslab_activity_log', 'activity_log' ] as $table_name ) {
	$table = $wpdb->prefix . $table_name;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove plugin options.
$options = [
	'reslab_al_db_version',
	'reslab_al_retention_days',
	'reslab_al_anonymize_ip',
	'reslab_al_bruteforce_alerts_enabled',
	'reslab_al_bruteforce_threshold',
	'reslab_al_bruteforce_window_hours',
	'reslab_al_deletion_alerts_enabled',
	'reslab_al_deletion_threshold',
	'reslab_al_deletion_window_hours',
	'reslab_al_alert_webhook_url',
	'reslab_al_archive_before_purge',
	'reslab_al_last_purge_run',
	'reslab_al_last_bruteforce_check',
	'reslab_al_last_deletion_check',
	'reslab_al_upgrade_lock',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove transients.
delete_transient( 'reslab_al_bruteforce_alerted' );
delete_transient( 'reslab_al_deletion_alerted' );

// Remove pre-purge archive files (kept inline rather than requiring the main
// plugin file for reslab_al_archive_dir(), matching how the table name is
// also duplicated here rather than pulled from the plugin's own constants).
$archive_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'reslab-al-archives/';
if ( is_dir( $archive_dir ) ) {
	foreach ( glob( $archive_dir . '*' ) ?: [] as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup only; WP_Filesystem init is unwarranted overhead for removing one now-empty directory during uninstall.
	@rmdir( $archive_dir );
}

// Remove custom capabilities from all roles.
foreach ( wp_roles()->roles as $role_name => $role_data ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'reslab_al_view_log' );
		$role->remove_cap( 'reslab_al_clear_log' );
		$role->remove_cap( 'reslab_al_manage_settings' );
	}
}
