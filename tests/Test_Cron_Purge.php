<?php
/**
 * Covers Reslab_AL_Cron::purge(): retention-based deletion, the purge
 * summary row, the pre-purge CSV archive, and the no-continuation-needed path.
 */
final class Test_Cron_Purge extends Reslab_AL_TestCase {

	public function tearDown(): void {
		$ts = wp_next_scheduled( 'reslab_al_purge_old_logs_continue' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'reslab_al_purge_old_logs_continue' );
		}
		$dir = reslab_al_archive_dir();
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '*.csv.gz' ) ?: [] as $file ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	private function cron(): Reslab_AL_Cron {
		return $this->instantiate_without_constructor( Reslab_AL_Cron::class );
	}

	public function test_purge_deletes_only_rows_older_than_retention(): void {
		update_option( 'reslab_al_retention_days', 30 );
		update_option( 'reslab_al_archive_before_purge', false );

		$old_id = $this->insert_log_row( [ 'action' => 'old_row', 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ] );
		$new_id = $this->insert_log_row( [ 'action' => 'new_row', 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ) ] );

		$this->cron()->purge();

		global $wpdb;
		$remaining_ids = $wpdb->get_col( 'SELECT id FROM ' . reslab_al_table() . ' WHERE action IN (\'old_row\',\'new_row\')' );
		$this->assertNotContains( (string) $old_id, $remaining_ids );
		$this->assertContains( (string) $new_id, $remaining_ids );
	}

	/**
	 * Counts the deleted_rows summary against a dynamic baseline (rows older
	 * than the threshold already present) rather than assuming the table is
	 * empty of old rows: other tests exercising real DDL (e.g.
	 * Test_Table_Helper's legacy-table RENAME TABLE) commit outside the
	 * per-test transaction by MySQL's own implicit-commit-before-DDL rule,
	 * so an empty table isn't a safe assumption across the whole run.
	 */
	public function test_purge_records_a_summary_row_with_deleted_count(): void {
		update_option( 'reslab_al_retention_days', 30 );
		update_option( 'reslab_al_archive_before_purge', false );

		global $wpdb;
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$expected  = 2 + (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . reslab_al_table() . ' WHERE created_at < %s', $threshold
		) );

		$id_1 = $this->insert_log_row( [ 'action' => 'old_1', 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ] );
		$id_2 = $this->insert_log_row( [ 'action' => 'old_2', 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ] );

		$this->cron()->purge();

		$row = $this->get_last_log_row();
		$this->assertSame( 'purged', $row->action );
		$context = json_decode( $row->context, true );
		$this->assertSame( $expected, $context['deleted_rows'] );
		$this->assertSame( 30, $context['older_than_days'] );

		$still_present = $wpdb->get_col( "SELECT id FROM " . reslab_al_table() . " WHERE id IN ({$id_1},{$id_2})" );
		$this->assertSame( [], $still_present, 'Both rows this test created must have been purged.' );
	}

	public function test_purge_does_not_delete_recent_rows(): void {
		update_option( 'reslab_al_retention_days', 30 );
		update_option( 'reslab_al_archive_before_purge', false );
		$id = $this->insert_log_row( [ 'action' => 'recent', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		$this->cron()->purge();

		global $wpdb;
		$this->assertSame(
			(string) $id,
			$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . reslab_al_table() . ' WHERE id = %d', $id ) )
		);
	}

	public function test_purge_does_not_schedule_continuation_for_a_small_batch(): void {
		update_option( 'reslab_al_retention_days', 30 );
		update_option( 'reslab_al_archive_before_purge', false );
		$this->insert_log_row( [ 'action' => 'old_row', 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ] );

		$this->cron()->purge();

		$this->assertFalse( wp_next_scheduled( 'reslab_al_purge_old_logs_continue' ) );
	}

	public function test_purge_updates_last_run_timestamp(): void {
		update_option( 'reslab_al_retention_days', 30 );
		$before = time();

		$this->cron()->purge();

		$this->assertGreaterThanOrEqual( $before, (int) get_option( 'reslab_al_last_purge_run' ) );
	}

	public function test_archive_before_purge_writes_a_gzip_csv_with_the_deleted_rows(): void {
		update_option( 'reslab_al_retention_days', 30 );
		update_option( 'reslab_al_archive_before_purge', true );

		$this->insert_log_row( [
			'action'      => 'archived_row',
			'object_type' => 'post',
			'created_at'  => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ),
		] );

		$this->cron()->purge();

		$dir   = reslab_al_archive_dir();
		$files = glob( $dir . '*.csv.gz' );
		$this->assertNotEmpty( $files, 'Expected an archive file to be created.' );

		$csv = gzfile( $files[0] );
		$csv = implode( '', $csv );
		$this->assertStringContainsString( 'ID,Date,User ID,IP Address,Action,Object Type,Object ID,Context,Request ID', $csv );
		$this->assertStringContainsString( 'archived_row', $csv );
		$this->assertStringContainsString( 'post', $csv );

		$context = json_decode( $this->get_last_log_row()->context, true );
		$this->assertSame( basename( $files[0] ), $context['archive_file'] );
	}

	public function test_no_archive_file_written_when_archiving_disabled(): void {
		update_option( 'reslab_al_retention_days', 30 );
		update_option( 'reslab_al_archive_before_purge', false );
		$this->insert_log_row( [ 'action' => 'old_row', 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ] );

		$this->cron()->purge();

		$dir = reslab_al_archive_dir();
		$this->assertEmpty( is_dir( $dir ) ? glob( $dir . '*.csv.gz' ) : [] );
	}
}
