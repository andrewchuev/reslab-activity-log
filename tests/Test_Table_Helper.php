<?php
/**
 * Covers reslab_al_table(), table creation, and the legacy-table migration
 * (reslab-activity-log.php).
 */
final class Test_Table_Helper extends Reslab_AL_TestCase {

	public function test_table_helper_returns_prefixed_name(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'reslab_activity_log', reslab_al_table() );
	}

	public function test_table_has_expected_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'DESCRIBE ' . reslab_al_table() );

		$this->assertSame(
			[ 'id', 'created_at', 'user_id', 'ip_address', 'action', 'object_type', 'object_id', 'context', 'request_id' ],
			$columns
		);
	}

	public function test_create_table_records_db_version(): void {
		reslab_al_create_table();
		$this->assertSame( RESLAB_AL_VERSION, get_option( 'reslab_al_db_version' ) );
	}

	public function test_create_table_is_idempotent(): void {
		// dbDelta() must tolerate being run against an already-current schema
		// without erroring or losing data (this is exactly what
		// reslab_al_maybe_upgrade_table() relies on).
		$id = $this->insert_log_row( [ 'action' => 'idempotency_probe' ] );

		reslab_al_create_table();
		reslab_al_create_table();

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . reslab_al_table() . ' WHERE id = %d', $id
		) );
		$this->assertNotNull( $row );
		$this->assertSame( 'idempotency_probe', $row->action );
	}

	/**
	 * reslab_al_migrate_legacy_table() must rename the pre-1.1.0
	 * `{prefix}activity_log` table to `{prefix}reslab_activity_log`,
	 * preserving its rows, and must be a no-op when the legacy table doesn't
	 * exist. RENAME TABLE is DDL (implicit commit in MySQL), so this test
	 * restores the schema and deletes its own probe row explicitly instead
	 * of relying on WP_UnitTestCase's transaction rollback.
	 */
	public function test_migrate_legacy_table_renames_and_preserves_rows(): void {
		global $wpdb;

		$current = reslab_al_table();
		$legacy  = $wpdb->prefix . RESLAB_AL_LEGACY_TABLE;

		$wpdb->query( "RENAME TABLE {$current} TO {$legacy}" );

		$wpdb->insert( $legacy, [
			'created_at'  => '2020-01-01 00:00:00',
			'user_id'     => 0,
			'ip_address'  => '',
			'action'      => 'legacy_migration_probe',
			'object_type' => 'x',
			'object_id'   => 0,
			'context'     => '',
			'request_id'  => '',
		] );

		try {
			reslab_al_migrate_legacy_table();

			$this->assertSame(
				$current,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $current ) ),
				'Table should be renamed back to the current name.'
			);
			$this->assertNull(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ),
				'Legacy-named table must no longer exist after migration.'
			);

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$current} WHERE action = %s", 'legacy_migration_probe'
			) );
			$this->assertNotNull( $row, 'Row inserted under the legacy table name must survive the rename.' );
			$this->assertSame( '2020-01-01 00:00:00', $row->created_at );
		} finally {
			// Belt-and-braces cleanup regardless of assertion outcome, since
			// the RENAME TABLE already committed and won't be rolled back.
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" );
			$wpdb->delete( $current, [ 'action' => 'legacy_migration_probe' ] );
		}
	}

	public function test_migrate_legacy_table_is_noop_when_legacy_table_absent(): void {
		global $wpdb;
		$current = reslab_al_table();

		reslab_al_migrate_legacy_table();

		$this->assertSame( $current, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $current ) ) );
	}
}
