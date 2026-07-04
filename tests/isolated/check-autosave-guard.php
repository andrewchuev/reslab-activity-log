<?php
/**
 * Standalone script (run as its own OS process, not via PHPUnit's
 * @runInSeparateProcess — WordPress registers closures as hook callbacks,
 * which PHPUnit's process isolation cannot serialize across the fork).
 *
 * DOING_AUTOSAVE is a real PHP constant that, once defined, cannot be
 * undefined; defining it inside the shared test process would silently
 * suppress post-update tracking for every test that runs afterwards. Running
 * the check in a disposable process sidesteps that entirely.
 *
 * Exits 0 and prints "OK" if Reslab_AL_Tracker::on_post_updated() correctly
 * skips logging while DOING_AUTOSAVE is true; exits 1 with a diagnostic
 * otherwise. Invoked from Test_Tracker_Autosave_Guard via a real subprocess.
 */

require __DIR__ . '/../bootstrap.php';

global $wpdb;

$post_id = wp_insert_post( [
	'post_status'  => 'publish',
	'post_title'   => 'Autosave source',
	'post_content' => 'Original content',
	'post_type'    => 'post',
], true );

if ( is_wp_error( $post_id ) ) {
	fwrite( STDERR, 'Could not create test post: ' . $post_id->get_error_message() . PHP_EOL );
	exit( 1 );
}

$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . reslab_al_table() );

define( 'DOING_AUTOSAVE', true );
wp_update_post( [ 'ID' => $post_id, 'post_content' => 'Changed while autosaving' ] );

$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . reslab_al_table() );

if ( $after !== $before ) {
	fwrite( STDERR, "Expected no new log rows during an autosave, before={$before} after={$after}" . PHP_EOL );
	exit( 1 );
}

echo 'OK' . PHP_EOL;
exit( 0 );
