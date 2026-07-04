<?php
/**
 * PHPUnit bootstrap for the Reslab Activity Log test suite.
 *
 * Boots the real, already-installed WordPress core (via wp-phpunit/wp-phpunit)
 * against a dedicated `reslab_al_wp_test` database, then loads this plugin
 * the same way WordPress would (on muplugins_loaded, before init).
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );

require $_wp_phpunit_dir . '/includes/functions.php';

/**
 * Loads WooCommerce (already installed alongside this plugin on the real
 * site — see wp-content/plugins/woocommerce) plus this plugin under test,
 * mirroring how the real `reslab_al_init()` bootstrap runs in production
 * (plugins_loaded -> tracker/admin/cron/settings/REST classes instantiated,
 * WooCommerce tracker only if WooCommerce is active). Loading the real
 * WooCommerce plugin — rather than stubbing its classes — is what lets
 * tests/Test_Tracker_WooCommerce.php exercise the actual order/coupon/refund
 * hooks instead of asserting against a fake.
 */
function _reslab_al_manually_load_plugin(): void {
	$woocommerce_main_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $woocommerce_main_file ) ) {
		require $woocommerce_main_file;
	}

	require dirname( __DIR__ ) . '/reslab-activity-log.php';
}
tests_add_filter( 'muplugins_loaded', '_reslab_al_manually_load_plugin' );

require $_wp_phpunit_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/helpers/class-test-case.php';

// WooCommerce's own DB tables/roles are normally created by its activation
// hook, which never ran against this fresh test database — only its plugin
// file was require()'d above. WC_Install::check_version(), hooked on 'init'
// priority 5, is *meant* to self-heal this, but in a CLI/test context it can
// run before $wp_rewrite / registered taxonomies are in the state it
// expects, logging (non-fatal) DB errors for tables it hasn't created yet.
// Running create_tables()/create_roles() explicitly here — after the whole
// request lifecycle in wp-phpunit's bootstrap has already completed once —
// guarantees a consistent starting schema for every WooCommerce-dependent
// test, regardless of what WC's own init-time hook managed to self-heal.
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::create_tables();
	WC_Install::create_roles();
	global $wp_roles;
	$wp_roles = new WP_Roles();
}

// Reslab_AL_Settings::register_settings() only runs on 'admin_init', which a
// CLI/PHPUnit process never fires on its own. Firing it once here means
// register_setting()'s defaults and sanitize_callbacks (e.g. rest_sanitize_boolean
// for the checkbox options) are active for every test, exactly as they would
// be for any real wp-admin request — without this, update_option() calls in
// tests hit a well-known WP gotcha where updating a never-before-saved option
// to `false` silently no-ops (the "old value" and "new value" both resolve to
// the same falsy sentinel). Reslab_AL_Admin's own admin_init handlers
// (handle_clear/handle_export/handle_archive_download) are safe to fire here
// too: each checks for its own $_GET/$_POST key first and no-ops otherwise.
do_action( 'admin_init' );
