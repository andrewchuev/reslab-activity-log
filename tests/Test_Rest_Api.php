<?php
/**
 * Covers Reslab_AL_REST_API: capability-gated permission checks, pagination
 * headers, and that it shares the same filters/restrictions as the admin
 * list table via Reslab_AL_List_Table::build_where_from_request().
 */
final class Test_Rest_Api extends Reslab_AL_TestCase {

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

	private function request( array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/reslab-al/v1/events' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$server = rest_get_server();
		return $server->dispatch( $request );
	}

	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/reslab-al/v1/events', $routes );
	}

	public function test_unauthenticated_request_is_denied(): void {
		wp_set_current_user( 0 );

		$response = $this->request();

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_user_without_capability_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->request();

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_user_with_capability_can_read_events(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->insert_log_row( [ 'action' => 'rest_probe' ] );

		$response = $this->request();

		$this->assertSame( 200, $response->get_status() );
		$actions = array_column( $response->get_data(), 'action' );
		$this->assertContains( 'rest_probe', $actions );
	}

	public function test_response_includes_pagination_headers(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->insert_log_row( [ 'action' => 'rest_probe_1' ] );
		$this->insert_log_row( [ 'action' => 'rest_probe_2' ] );

		$response = $this->request( [ 'per_page' => 1, 'page' => 1 ] );
		$headers  = $response->get_headers();

		$this->assertCount( 1, $response->get_data() );
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertGreaterThanOrEqual( 2, (int) $headers['X-WP-Total'] );
	}

	/**
	 * build_where_from_request() reads filter_* straight from $_GET (shared
	 * with the admin list table and CSV export), not from the REST request's
	 * own declared args — so, same as a real HTTP request, the filter has to
	 * arrive as an actual query-string param.
	 */
	public function test_filters_are_applied_same_as_admin_list_table(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->insert_log_row( [ 'action' => 'rest_filtered_a' ] );
		$this->insert_log_row( [ 'action' => 'rest_filtered_b' ] );

		$_GET['filter_action'] = 'rest_filtered_a';
		$response              = $this->request();

		$actions = array_column( $response->get_data(), 'action' );
		$this->assertSame( [ 'rest_filtered_a' ], array_unique( $actions ) );
	}

	public function test_viewable_object_types_restriction_applies_to_rest_too(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->insert_log_row( [ 'action' => 'visible_event', 'object_type' => 'post' ] );
		$this->insert_log_row( [ 'action' => 'hidden_event', 'object_type' => 'secret_type' ] );

		add_filter( 'reslab_al_viewable_object_types', static function () {
			return [ 'post' ];
		} );

		$response = $this->request();

		$actions = array_column( $response->get_data(), 'action' );
		$this->assertContains( 'visible_event', $actions );
		$this->assertNotContains( 'hidden_event', $actions );
	}

	public function test_context_is_returned_as_decoded_array(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->insert_log_row( [ 'action' => 'rest_context_probe', 'context' => wp_json_encode( [ 'foo' => 'bar' ] ) ] );

		$_GET['filter_action'] = 'rest_context_probe';
		$response              = $this->request();
		$data                  = $response->get_data();

		$this->assertSame( [ 'foo' => 'bar' ], $data[0]['context'] );
	}
}
