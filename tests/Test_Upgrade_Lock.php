<?php
/**
 * Covers reslab_al_maybe_upgrade_table()'s add_option()-based lock
 * (reslab-activity-log.php): skips re-running dbDelta() when the db_version
 * option is already current, and the stale-lock takeover path.
 */
final class Test_Upgrade_Lock extends Reslab_AL_TestCase {

	public function tearDown(): void {
		delete_option( 'reslab_al_upgrade_lock' );
		update_option( 'reslab_al_db_version', RESLAB_AL_VERSION );
		parent::tearDown();
	}

	public function test_noop_when_db_version_already_current(): void {
		update_option( 'reslab_al_db_version', RESLAB_AL_VERSION );
		delete_option( 'reslab_al_upgrade_lock' );

		reslab_al_maybe_upgrade_table();

		// No lock should ever have been touched: the function must return
		// before getting anywhere near add_option().
		$this->assertFalse( get_option( 'reslab_al_upgrade_lock' ) );
	}

	public function test_upgrades_and_records_new_version_when_outdated(): void {
		update_option( 'reslab_al_db_version', '0.0.1' );
		delete_option( 'reslab_al_upgrade_lock' );

		reslab_al_maybe_upgrade_table();

		$this->assertSame( RESLAB_AL_VERSION, get_option( 'reslab_al_db_version' ) );
		// The lock is released once the upgrade completes.
		$this->assertFalse( get_option( 'reslab_al_upgrade_lock' ) );
	}

	public function test_fresh_lock_held_by_another_request_blocks_the_upgrade(): void {
		update_option( 'reslab_al_db_version', '0.0.1' );
		add_option( 'reslab_al_upgrade_lock', time(), '', false );

		reslab_al_maybe_upgrade_table();

		// A concurrent request "owns" the upgrade; this call must back off
		// without bumping db_version itself.
		$this->assertSame( '0.0.1', get_option( 'reslab_al_db_version' ) );
	}

	public function test_stale_lock_is_taken_over_and_upgrade_proceeds(): void {
		update_option( 'reslab_al_db_version', '0.0.1' );
		// A lock older than 5 minutes is considered abandoned (e.g. the
		// request that acquired it crashed before releasing it).
		add_option( 'reslab_al_upgrade_lock', time() - 10 * MINUTE_IN_SECONDS, '', false );

		reslab_al_maybe_upgrade_table();

		$this->assertSame( RESLAB_AL_VERSION, get_option( 'reslab_al_db_version' ) );
	}

	public function test_table_helper_matches_the_table_actually_created(): void {
		global $wpdb;
		update_option( 'reslab_al_db_version', '0.0.1' );
		delete_option( 'reslab_al_upgrade_lock' );

		reslab_al_maybe_upgrade_table();

		$this->assertSame(
			reslab_al_table(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', reslab_al_table() ) )
		);
	}
}
