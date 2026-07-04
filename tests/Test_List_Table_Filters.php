<?php
/**
 * Covers Reslab_AL_List_Table::get_request_filters() and
 * build_where_from_request(): $_GET sanitization and the resulting SQL
 * clauses/values, including the reslab_al_viewable_object_types restriction
 * shared by the admin list table, CSV export, and the REST API.
 */
final class Test_List_Table_Filters extends Reslab_AL_TestCase {

	private array $get_backup;

	public function setUp(): void {
		parent::setUp();
		$this->get_backup = $_GET;
		$_GET             = [];
	}

	public function tearDown(): void {
		$_GET = $this->get_backup;
		remove_all_filters( 'reslab_al_viewable_object_types' );
		parent::tearDown();
	}

	public function test_defaults_are_empty_when_no_get_params(): void {
		$f = Reslab_AL_List_Table::get_request_filters();

		$this->assertSame( 0, $f['user'] );
		$this->assertSame( '', $f['action'] );
		$this->assertSame( '', $f['object_type'] );
		$this->assertSame( '', $f['ip'] );
		$this->assertSame( '', $f['search'] );
	}

	public function test_action_and_object_type_are_sanitized_as_keys(): void {
		$_GET['filter_action']      = 'Some Action!! 123';
		$_GET['filter_object_type'] = 'Post-Type Two';

		$f = Reslab_AL_List_Table::get_request_filters();

		$this->assertSame( sanitize_key( 'Some Action!! 123' ), $f['action'] );
		$this->assertSame( sanitize_key( 'Post-Type Two' ), $f['object_type'] );
	}

	public function test_invalid_ip_is_dropped(): void {
		$_GET['filter_ip'] = 'not-an-ip-address';

		$this->assertSame( '', Reslab_AL_List_Table::get_request_filters()['ip'] );
	}

	public function test_valid_ip_is_kept(): void {
		$_GET['filter_ip'] = '203.0.113.9';

		$this->assertSame( '203.0.113.9', Reslab_AL_List_Table::get_request_filters()['ip'] );
	}

	public function test_where_clause_has_no_filters_by_default(): void {
		$filter = Reslab_AL_List_Table::build_where_from_request();

		$this->assertSame( [ '1=1' ], $filter['clauses'] );
		$this->assertSame( [], $filter['values'] );
	}

	public function test_where_clause_includes_each_active_filter(): void {
		$_GET['filter_user']        = '7';
		$_GET['filter_action']      = 'deleted';
		$_GET['filter_object_type'] = 'post';
		$_GET['filter_ip']          = '203.0.113.9';
		$_GET['filter_date_from']   = '2024-01-01';
		$_GET['filter_date_to']     = '2024-01-31';
		$_GET['filter_search']      = 'hello';

		$filter = Reslab_AL_List_Table::build_where_from_request();

		$this->assertSame(
			[ '1=1', 'context LIKE %s', 'user_id = %d', 'action = %s', 'object_type = %s', 'ip_address = %s', 'created_at >= %s', 'created_at <= %s' ],
			$filter['clauses']
		);
		$this->assertSame(
			[ '%hello%', 7, 'deleted', 'post', '203.0.113.9', '2024-01-01 00:00:00', '2024-01-31 23:59:59' ],
			$filter['values']
		);
	}

	public function test_viewable_object_types_filter_restricts_the_where_clause(): void {
		add_filter( 'reslab_al_viewable_object_types', static function () {
			return [ 'post', 'order' ];
		} );

		$filter = Reslab_AL_List_Table::build_where_from_request();

		$this->assertContains( 'object_type IN (%s,%s)', $filter['clauses'] );
		$this->assertSame( [ 'post', 'order' ], $filter['values'] );
	}

	public function test_get_viewable_object_types_dedupes_and_sanitizes(): void {
		add_filter( 'reslab_al_viewable_object_types', static function () {
			return [ 'Post', 'post', 'order!!' ];
		} );

		$this->assertSame(
			[ 'post', 'order' ],
			array_values( Reslab_AL_List_Table::get_viewable_object_types() )
		);
	}

	public function test_get_viewable_object_types_is_unrestricted_by_default(): void {
		$this->assertSame( [], Reslab_AL_List_Table::get_viewable_object_types() );
	}
}
