<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only REST API for external monitoring/SIEM integrations that want to
 * pull events instead of parsing the CSV export. Authenticate with WP
 * Application Passwords (core feature since WP 5.6) — no custom API key
 * scheme needed. Reuses Reslab_AL_List_Table::build_where_from_request() so
 * filtering and the reslab_al_viewable_object_types restriction behave
 * identically to the admin list table and CSV export.
 */
class Reslab_AL_REST_API {

	private const NAMESPACE = 'reslab-al/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/events', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_events' ],
			'permission_callback' => [ $this, 'permission_check' ],
			'args'                => [
				'page'     => [
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				],
				'per_page' => [
					'type'              => 'integer',
					'default'           => 50,
					'minimum'           => 1,
					'maximum'           => 200,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public function permission_check(): bool {
		return current_user_can( 'reslab_al_view_log' );
	}

	public function get_events( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table    = reslab_al_table();
		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Same $_GET-based filters (filter_action, filter_object_type,
		// filter_user, filter_date_from/to, filter_ip, filter_search) as the
		// admin list table and CSV export — and the same viewable-object-type
		// restriction for the authenticated user.
		$filter = Reslab_AL_List_Table::build_where_from_request();
		$where  = implode( ' AND ', $filter['clauses'] );

		// wpdb::prepare() requires at least one placeholder in the query; with
		// no filters active, $filter['values'] is empty and {$where} is just
		// '1=1', so prepare() must be skipped the same way prepare_items()
		// does in the admin list table.
		if ( $filter['values'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$where}",
				...$filter['values']
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		}

		$query_values = array_merge( $filter['values'], [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			...$query_values
		) );

		$response = new WP_REST_Response( array_map( [ $this, 'format_event' ], $rows ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function format_event( object $row ): array {
		$context = json_decode( (string) $row->context, true );

		return [
			'id'          => (int) $row->id,
			'created_at'  => $row->created_at,
			'user_id'     => (int) $row->user_id,
			'ip_address'  => $row->ip_address,
			'action'      => $row->action,
			'object_type' => $row->object_type,
			'object_id'   => (int) $row->object_id,
			'context'     => is_array( $context ) ? $context : [],
			'request_id'  => $row->request_id,
		];
	}
}
