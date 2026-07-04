<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table implementation for the activity log.
 */
class Reslab_AL_List_Table extends WP_List_Table {

	/** @var array<int, WP_User|false> */
	private array $users_cache = [];

	/**
	 * When several events share a request_id (grouped in prepare_items()),
	 * this ranks which one becomes the visible row; anything not listed
	 * falls back to 0, so a handful of "headline" actions always win over
	 * incidental side effects like language_assigned.
	 */
	private const GROUP_PRIORITY = [
		'updated'              => 100,
		'order_status_changed' => 100,
		'profile_updated'      => 100,
		'deleted'              => 95,
		'published'            => 90,
		'drafted'              => 90,
		'trashed'              => 90,
		'scheduled'            => 90,
		'set_pending'          => 90,
		'set_private'          => 90,
		'refund_created'       => 90,
	];

	public function __construct() {
		parent::__construct( [
			'singular' => 'log_entry',
			'plural'   => 'log_entries',
			'ajax'     => false,
		] );
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return [
			'created_at' => __( 'Date', 'reslab-activity-log' ),
			'user'       => __( 'User', 'reslab-activity-log' ),
			'ip_address' => __( 'IP Address', 'reslab-activity-log' ),
			'action'     => __( 'Action', 'reslab-activity-log' ),
			'object'     => __( 'Object', 'reslab-activity-log' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'created_at'  => [ 'created_at', true ],
			'action'      => [ 'action', false ],
			'object_type' => [ 'object_type', false ],
		];
	}

	// -------------------------------------------------------------------------
	// Screen options (per page)
	// -------------------------------------------------------------------------

	public function get_per_page(): int {
		$per_page = get_user_option( 'reslab_al_per_page' );
		return ( $per_page && (int) $per_page > 0 ) ? (int) $per_page : 30;
	}

	// -------------------------------------------------------------------------
	// Data retrieval
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		global $wpdb;

		$table        = reslab_al_table();
		$per_page     = $this->get_per_page();
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$filter        = self::build_where_from_request();
		$where_values  = $filter['values'];
		$where         = implode( ' AND ', $filter['clauses'] );

		// Sorting.
		$orderby_map = [
			'created_at'  => 'created_at',
			'action'      => 'action',
			'object_type' => 'object_type',
		];
		$orderby = $orderby_map[ sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) ) ] ?? 'created_at';
		$order   = strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? '' ) ) ) === 'ASC' ? 'ASC' : 'DESC';

		// Total count.
		if ( $where_values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$where_values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// Rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query        = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_values = array_merge( $where_values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->items = $wpdb->get_results( $wpdb->prepare( $query, ...$query_values ) );

		$this->prime_users_cache();
		$this->items = $this->group_by_request( $this->items );

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}

	/**
	 * Reads and sanitizes the current filter values from the request. The
	 * single point where $_GET is read for filters — list rendering, CSV
	 * export, and the filter bar all share it.
	 *
	 * @return array{user: int, action: string, object_type: string, date_from: string, date_to: string, ip: string, search: string}
	 */
	public static function get_request_filters(): array {
		$ip = sanitize_text_field( wp_unslash( $_GET['filter_ip'] ?? '' ) );

		return [
			'user'        => absint( $_GET['filter_user'] ?? 0 ),
			'action'      => sanitize_key( wp_unslash( $_GET['filter_action'] ?? '' ) ),
			'object_type' => sanitize_key( wp_unslash( $_GET['filter_object_type'] ?? '' ) ),
			'date_from'   => sanitize_text_field( wp_unslash( $_GET['filter_date_from'] ?? '' ) ),
			'date_to'     => sanitize_text_field( wp_unslash( $_GET['filter_date_to'] ?? '' ) ),
			'ip'          => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			'search'      => sanitize_text_field( wp_unslash( $_GET['filter_search'] ?? '' ) ),
		];
	}

	/**
	 * Builds current filter values for use in WHERE clauses (shared with CSV export).
	 *
	 * @return array{clauses: string[], values: mixed[]}
	 */
	public static function build_where_from_request(): array {
		global $wpdb;

		$f       = self::get_request_filters();
		$clauses = [ '1=1' ];
		$values  = [];

		if ( $f['search'] !== '' ) {
			// Substring match over the JSON context blob — covers product/post
			// titles, usernames, option names, coupon codes, etc. Simple LIKE
			// rather than a FULLTEXT index: fine at activity-log volumes, and
			// avoids a schema change just for search.
			$clauses[] = 'context LIKE %s';
			$values[]  = '%' . $wpdb->esc_like( $f['search'] ) . '%';
		}
		if ( $f['user'] > 0 ) {
			$clauses[] = 'user_id = %d';
			$values[]  = $f['user'];
		}
		if ( $f['action'] !== '' ) {
			$clauses[] = 'action = %s';
			$values[]  = $f['action'];
		}
		if ( $f['object_type'] !== '' ) {
			$clauses[] = 'object_type = %s';
			$values[]  = $f['object_type'];
		}
		if ( $f['ip'] !== '' ) {
			$clauses[] = 'ip_address = %s';
			$values[]  = $f['ip'];
		}
		if ( $f['date_from'] !== '' ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = $f['date_from'] . ' 00:00:00';
		}
		if ( $f['date_to'] !== '' ) {
			$clauses[] = 'created_at <= %s';
			$values[]  = $f['date_to'] . ' 23:59:59';
		}

		$viewable = self::get_viewable_object_types();
		if ( ! empty( $viewable ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $viewable ), '%s' ) );
			$clauses[]    = "object_type IN ({$placeholders})";
			array_push( $values, ...$viewable );
		}

		return [ 'clauses' => $clauses, 'values' => $values ];
	}

	/**
	 * Object types the current user is allowed to see, via the
	 * `reslab_al_viewable_object_types` filter. Applies to both the list
	 * table and CSV export (both go through build_where_from_request()).
	 * Empty array (default) means unrestricted — every role granted
	 * reslab_al_view_log sees every object type.
	 *
	 * @return string[]
	 */
	public static function get_viewable_object_types(): array {
		/**
		 * @param string[] $types   Allowed object_type values; empty = unrestricted.
		 * @param int      $user_id Current user ID.
		 */
		$types = apply_filters( 'reslab_al_viewable_object_types', [], get_current_user_id() );

		return array_values( array_unique( array_map( 'sanitize_key', (array) $types ) ) );
	}

	private function prime_users_cache(): void {
		$ids = [];
		foreach ( $this->items as $item ) {
			if ( (int) $item->user_id > 0 ) {
				$ids[] = (int) $item->user_id;
			}
			if ( $item->object_type === 'user' && (int) $item->object_id > 0 ) {
				$ids[] = (int) $item->object_id;
			}
		}
		$ids = array_unique( $ids );
		if ( empty( $ids ) ) {
			return;
		}
		$users = get_users( [ 'include' => $ids, 'number' => count( $ids ) ] );
		foreach ( $users as $user ) {
			$this->users_cache[ $user->ID ] = $user;
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $this->users_cache[ $id ] ) ) {
				$this->users_cache[ $id ] = false;
			}
		}
	}

	/**
	 * Collapses rows that share a request_id (i.e. fired from the same
	 * editorial save or checkout) into one visible row. A single Gutenberg
	 * save + Polylang language assignment used to produce 3-6 near-duplicate
	 * rows; this picks the most meaningful one via GROUP_PRIORITY and stashes
	 * the rest on ->grouped_events for the details panel, so nothing is lost.
	 *
	 * Grouping only considers rows already fetched for the current page, so
	 * a request that straddles a page boundary won't fully merge — an
	 * accepted trade-off to avoid a much more complex grouped-pagination query.
	 *
	 * @param object[] $items
	 * @return object[]
	 */
	private function group_by_request( array $items ): array {
		$buckets = [];
		$order   = [];

		foreach ( $items as $item ) {
			$key = $item->request_id !== '' ? $item->request_id : 'row_' . $item->id;
			if ( ! isset( $buckets[ $key ] ) ) {
				$order[]          = $key;
				$buckets[ $key ]  = [];
			}
			$buckets[ $key ][] = $item;
		}

		$grouped = [];
		foreach ( $order as $key ) {
			$bucket = $buckets[ $key ];
			if ( count( $bucket ) === 1 ) {
				$bucket[0]->grouped_events = [];
				$grouped[] = $bucket[0];
				continue;
			}

			usort( $bucket, static function ( $a, $b ) {
				$pa = self::GROUP_PRIORITY[ $a->action ] ?? 0;
				$pb = self::GROUP_PRIORITY[ $b->action ] ?? 0;
				return $pa === $pb ? $b->id <=> $a->id : $pb <=> $pa;
			} );

			$primary                  = array_shift( $bucket );
			$primary->grouped_events  = $bucket;
			$grouped[]                = $primary;
		}

		return $grouped;
	}

	private function get_cached_user( int $user_id ): WP_User|false {
		return $this->users_cache[ $user_id ] ?? get_userdata( $user_id );
	}

	// -------------------------------------------------------------------------
	// Column renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		return esc_html( $item->$column_name ?? '' );
	}

	public function column_created_at( object $item ): string {
		$ts   = strtotime( $item->created_at );
		$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		$ago  = human_time_diff( $ts ) . ' ' . __( 'ago', 'reslab-activity-log' );
		return sprintf( '<span title="%s">%s</span>', esc_attr( $ago ), esc_html( $date ) );
	}

	public function column_user( object $item ): string {
		$user_id = (int) $item->user_id;
		if ( $user_id === 0 ) {
			return '<span class="reslab-al-guest">' . esc_html__( 'Guest', 'reslab-activity-log' ) . '</span>';
		}
		$user = $this->get_cached_user( $user_id );
		if ( ! $user ) {
			return sprintf(
				/* translators: %d: user ID */
				'<span class="reslab-al-deleted-user">' . esc_html__( 'Deleted user #%d', 'reslab-activity-log' ) . '</span>',
				$user_id
			);
		}
		$avatar = get_avatar( $user_id, 24, '', '', [ 'class' => 'reslab-al-avatar' ] );
		$url    = esc_url( get_edit_user_link( $user_id ) );
		$name   = esc_html( $user->display_name );
		return "<a href=\"{$url}\">{$avatar} {$name}</a>";
	}

	public function column_ip_address( object $item ): string {
		$ip = esc_html( $item->ip_address );
		if ( $ip === '' ) {
			return '—';
		}
		// Link to internal IP filter instead of external service.
		$url = esc_url( add_query_arg( [
			'page'      => 'reslab-activity-log',
			'filter_ip' => $item->ip_address,
		], admin_url( 'tools.php' ) ) );
		return "<a href=\"{$url}\">{$ip}</a>";
	}

	public function column_action( object $item ): string {
		$action = esc_html( $item->action );
		$class  = 'reslab-al-action reslab-al-action--' . sanitize_html_class( $item->action );
		$html   = "<span class=\"{$class}\">{$action}</span>";

		$grouped_count = count( $item->grouped_events ?? [] );
		if ( $grouped_count > 0 ) {
			$html .= sprintf(
				' <span class="reslab-al-grouped-count" title="%s">+%d</span>',
				esc_attr__( 'Additional events logged in the same request', 'reslab-activity-log' ),
				$grouped_count
			);
		}

		return $html;
	}

	public function column_object( object $item ): string {
		$type      = esc_html( $item->object_type );
		$object_id = (int) $item->object_id;
		$context   = [];

		if ( $item->context ) {
			$decoded = json_decode( $item->context, true );
			if ( is_array( $decoded ) ) {
				$context = $decoded;
			}
		}

		if ( $object_id > 0 ) {
			$link   = $this->get_object_link( $item->object_type, $object_id, $context );
			$header = $link ? "{$type} &mdash; {$link}" : "{$type} #{$object_id}";
		} else {
			if ( ! empty( $context['plugins'] ) && is_array( $context['plugins'] ) ) {
				$name = implode( ', ', $context['plugins'] );
			} elseif ( ! empty( $context['themes'] ) && is_array( $context['themes'] ) ) {
				$name = implode( ', ', $context['themes'] );
			} else {
				$name = $context['plugin'] ?? $context['option'] ?? $context['menu_name'] ?? $context['new_theme'] ?? $context['coupon_code'] ?? '';
			}
			$header = $name ? "{$type} &mdash; <em>" . esc_html( $name ) . '</em>' : $type;
		}

		$diff    = $this->render_context_diff( $context );
		$grouped = $this->render_grouped_events( $item->grouped_events ?? [] );
		$extra   = $diff . $grouped;

		if ( $extra === '' ) {
			return $header;
		}

		// Wrap diff in <details> — collapsible without JS.
		return $header . '<details class="reslab-al-details"><summary>' . esc_html__( 'details', 'reslab-activity-log' ) . '</summary>' . $extra . '</details>';
	}

	/**
	 * Renders the events folded into this row by group_by_request(), so
	 * expanding "details" shows everything that happened, not just the
	 * headline event.
	 *
	 * @param object[] $events
	 */
	private function render_grouped_events( array $events ): string {
		if ( empty( $events ) ) {
			return '';
		}

		$rows = [];
		foreach ( $events as $event ) {
			$context = [];
			if ( $event->context ) {
				$decoded = json_decode( $event->context, true );
				if ( is_array( $decoded ) ) {
					$context = $decoded;
				}
			}

			$label = '<span class="reslab-al-action reslab-al-action--' . sanitize_html_class( $event->action ) . '">'
				. esc_html( $event->action ) . '</span>';

			$diff    = $this->render_context_diff( $context );
			$rows[] = '<div class="reslab-al-grouped-row">' . $label . $diff . '</div>';
		}

		return '<div class="reslab-al-grouped">'
			. '<p class="reslab-al-grouped-label">' . esc_html__( 'Also in this request:', 'reslab-activity-log' ) . '</p>'
			. implode( '', $rows )
			. '</div>';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function render_context_diff( array $context ): string {
		$rows = [];

		if ( isset( $context['changed'] ) && is_array( $context['changed'] ) ) {
			foreach ( $context['changed'] as $field => $value ) {
				if ( $value === true ) {
					$rows[] = $this->diff_row( $field, esc_html__( '(content changed)', 'reslab-activity-log' ) );
				} elseif ( is_array( $value ) && isset( $value['from'], $value['to'] ) ) {
					$rows[] = $this->diff_row(
						$field,
						'<span class="reslab-al-diff-from">' . esc_html( $value['from'] ) . '</span>'
						. '<span class="reslab-al-diff-arrow">→</span>'
						. '<span class="reslab-al-diff-to">' . esc_html( $value['to'] ) . '</span>'
					);
				} elseif ( is_string( $value ) ) {
					$rows[] = $this->diff_row( $field, esc_html( $value ) );
				}
			}
		}

		if ( isset( $context['option'], $context['old_value'], $context['new_value'] ) ) {
			$rows[] = $this->diff_row(
				$context['option'],
				'<span class="reslab-al-diff-from">' . esc_html( (string) $context['old_value'] ) . '</span>'
				. '<span class="reslab-al-diff-arrow">→</span>'
				. '<span class="reslab-al-diff-to">' . esc_html( (string) $context['new_value'] ) . '</span>'
			);
		}

		if ( isset( $context['old_status'], $context['new_status'] ) ) {
			$rows[] = $this->diff_row(
				'status',
				'<span class="reslab-al-diff-from">' . esc_html( $context['old_status'] ) . '</span>'
				. '<span class="reslab-al-diff-arrow">→</span>'
				. '<span class="reslab-al-diff-to">' . esc_html( $context['new_status'] ) . '</span>'
			);
		}

		if ( isset( $context['attempted_login'] ) ) {
			$rows[] = $this->diff_row( 'login', esc_html( $context['attempted_login'] ) );
		}

		if ( isset( $context['deleted_rows'] ) ) {
			$rows[] = $this->diff_row(
				'deleted rows',
				esc_html( (string) $context['deleted_rows'] )
				/* translators: %d: retention period in days */
				. ' ' . esc_html( sprintf( __( '(older than %d days)', 'reslab-activity-log' ), $context['older_than_days'] ?? 30 ) )
			);
		}

		// WooCommerce order status.
		if ( isset( $context['from'], $context['to'], $context['order_total'] ) ) {
			$rows[] = $this->diff_row(
				'status',
				'<span class="reslab-al-diff-from">' . esc_html( $context['from'] ) . '</span>'
				. '<span class="reslab-al-diff-arrow">→</span>'
				. '<span class="reslab-al-diff-to">' . esc_html( $context['to'] ) . '</span>'
			);
			$rows[] = $this->diff_row( 'total', esc_html( $context['order_total'] . ' ' . ( $context['order_currency'] ?? '' ) ) );
		}

		// WooCommerce product meta.
		if ( isset( $context['meta_key'], $context['old_value'], $context['new_value'] ) ) {
			$rows[] = $this->diff_row(
				$context['meta_key'],
				'<span class="reslab-al-diff-from">' . esc_html( (string) $context['old_value'] ) . '</span>'
				. '<span class="reslab-al-diff-arrow">→</span>'
				. '<span class="reslab-al-diff-to">' . esc_html( (string) $context['new_value'] ) . '</span>'
			);
		}

		// Refund.
		if ( isset( $context['amount'], $context['refund_id'] ) ) {
			$rows[] = $this->diff_row( 'amount', esc_html( (string) $context['amount'] ) );
			if ( ! empty( $context['reason'] ) ) {
				$rows[] = $this->diff_row( 'reason', esc_html( $context['reason'] ) );
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		return '<dl class="reslab-al-diff">' . implode( '', $rows ) . '</dl>';
	}

	private function diff_row( string $label, string $value_html ): string {
		return '<div class="reslab-al-diff-row">'
			. '<dt>' . esc_html( $label ) . '</dt>'
			. '<dd>' . $value_html . '</dd>'
			. '</div>';
	}

	/** @param array<string, mixed> $context */
	private function get_object_link( string $type, int $id, array $context ): string {
		switch ( $type ) {
			case 'post':
			case 'product':
				$post = get_post( $id );
				if ( ! $post || $post->post_status === 'trash' ) {
					$label = esc_html( $context['post_title'] ?? $context['product_name'] ?? "#$id" );
					return "<em>{$label} (" . esc_html__( 'deleted', 'reslab-activity-log' ) . ')</em>';
				}
				$url   = esc_url( get_edit_post_link( $id ) );
				$label = esc_html( $post->post_title ?: "#$id" );
				return "<a href=\"{$url}\">{$label}</a>";

			case 'order':
				// wc_get_order()->get_edit_order_url() resolves correctly for
				// both HPOS and legacy post-based orders; a plain post.php
				// link is wrong (and 404s) once HPOS storage is active.
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
				if ( ! $order ) {
					return '<em>#' . esc_html( (string) $id ) . ' (' . esc_html__( 'deleted', 'reslab-activity-log' ) . ')</em>';
				}
				$url = esc_url( $order->get_edit_order_url() );
				return "<a href=\"{$url}\">#" . esc_html( (string) $id ) . '</a>';

			case 'user':
				$user = $this->get_cached_user( $id );
				if ( ! $user ) {
					return esc_html__( 'Deleted user', 'reslab-activity-log' );
				}
				$url   = esc_url( get_edit_user_link( $id ) );
				$label = esc_html( $user->display_name );
				return "<a href=\"{$url}\">{$label}</a>";

			case 'nav_menu':
				$menu = wp_get_nav_menu_object( $id );
				if ( ! $menu ) {
					return "#$id";
				}
				$url   = esc_url( admin_url( "nav-menus.php?action=edit&menu={$id}" ) );
				$label = esc_html( $menu->name );
				return "<a href=\"{$url}\">{$label}</a>";

			default:
				return '';
		}
	}
}

// -------------------------------------------------------------------------
// Admin page wrapper
// -------------------------------------------------------------------------

class Reslab_AL_Admin {

	public function __construct() {
		add_action( 'admin_menu',           [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'admin_init',           [ $this, 'handle_clear' ] );
		add_action( 'admin_init',           [ $this, 'handle_export' ] );
		add_action( 'admin_init',           [ $this, 'handle_archive_download' ] );
		add_action( 'current_screen',       [ $this, 'setup_screen_options' ] );
		add_filter( 'set-screen-option',    [ $this, 'save_screen_option' ], 10, 3 );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'Activity Log', 'reslab-activity-log' ),
			__( 'Activity Log', 'reslab-activity-log' ),
			'reslab_al_view_log',
			'reslab-activity-log',
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Screen options
	// -------------------------------------------------------------------------

	public function setup_screen_options( WP_Screen $screen ): void {
		if ( strpos( $screen->id, 'reslab-activity-log' ) === false ) {
			return;
		}
		add_screen_option( 'per_page', [
			'label'   => __( 'Entries per page', 'reslab-activity-log' ),
			'default' => 30,
			'option'  => 'reslab_al_per_page',
		] );
	}

	public function save_screen_option( mixed $status, string $option, mixed $value ): mixed {
		if ( $option === 'reslab_al_per_page' ) {
			return (int) $value;
		}
		return $status;
	}

	// -------------------------------------------------------------------------
	// Clear
	// -------------------------------------------------------------------------

	public function handle_clear(): void {
		if ( empty( $_POST['reslab_al_clear'] ) ) {
			return;
		}
		check_admin_referer( 'reslab_al_clear_log' );
		if ( ! current_user_can( 'reslab_al_clear_log' ) ) {
			wp_die( esc_html__( 'Access denied.', 'reslab-activity-log' ) );
		}

		global $wpdb;
		$table = reslab_al_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );

		wp_safe_redirect( add_query_arg( [
			'page'    => 'reslab-activity-log',
			'cleared' => '1',
		], admin_url( 'tools.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// CSV Export
	// -------------------------------------------------------------------------

	public function handle_export(): void {
		if ( empty( $_GET['reslab_al_export'] ) || $_GET['reslab_al_export'] !== 'csv' ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'reslab_al_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'reslab-activity-log' ) );
		}
		if ( ! current_user_can( 'reslab_al_view_log' ) ) {
			wp_die( esc_html__( 'Access denied.', 'reslab-activity-log' ) );
		}

		global $wpdb;
		$table  = reslab_al_table();
		$filter = Reslab_AL_List_Table::build_where_from_request();
		$where  = implode( ' AND ', $filter['clauses'] );

		$filename = 'activity-log-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'ID', 'Date', 'User ID', 'IP Address', 'Action', 'Object Type', 'Object ID', 'Context' ] );

		// Stream in batches instead of loading the whole (potentially huge) log
		// into memory, and reset the time limit for large exports.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$batch  = 1000;
		$offset = 0;

		do {
			$query_values = array_merge( $filter['values'], [ $batch, $offset ] );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$query_values
			) );

			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( [ self::class, 'csv_safe' ], [
					$row->id,
					$row->created_at,
					$row->user_id,
					$row->ip_address,
					$row->action,
					$row->object_type,
					$row->object_id,
					$row->context,
				] ) );
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV to php://output, not a real file; WP_Filesystem has no equivalent.
		exit;
	}

	/**
	 * Neutralizes values that spreadsheet apps (Excel, LibreOffice, Sheets)
	 * would interpret as formulas when the CSV is opened, e.g. a login
	 * attempt for a username like `=cmd|'/c calc'!A0`. Prevents CSV/Formula
	 * injection via exported, attacker-controlled fields (attempted_login,
	 * context, etc.).
	 */
	public static function csv_safe( mixed $value ): string {
		$value = (string) $value;
		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			return "'" . $value;
		}
		return $value;
	}

	// -------------------------------------------------------------------------
	// Archive download
	// -------------------------------------------------------------------------

	/**
	 * Streams a pre-purge archive file. Files live outside the URL-guessable
	 * uploads listing (random filename + index.php stub blocking directory
	 * listing), and this handler additionally requires a valid nonce and
	 * reslab_al_view_log — so even a leaked/logged filename alone isn't
	 * enough to fetch the archive.
	 */
	public function handle_archive_download(): void {
		if ( empty( $_GET['reslab_al_download_archive'] ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'reslab_al_download_archive' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'reslab-activity-log' ) );
		}
		if ( ! current_user_can( 'reslab_al_view_log' ) ) {
			wp_die( esc_html__( 'Access denied.', 'reslab-activity-log' ) );
		}

		$filename = basename( sanitize_file_name( wp_unslash( $_GET['reslab_al_download_archive'] ) ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}-[A-Za-z0-9]{10}\.csv\.gz$/', $filename ) ) {
			wp_die( esc_html__( 'Invalid archive file.', 'reslab-activity-log' ) );
		}

		$path = reslab_al_archive_dir() . $filename;
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Archive file not found.', 'reslab-activity-log' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a gzip download to the browser; WP_Filesystem has no streaming-output equivalent.
		exit;
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'reslab_al_view_log' ) ) {
			wp_die( esc_html__( 'Access denied.', 'reslab-activity-log' ) );
		}

		$table = new Reslab_AL_List_Table();
		$table->prepare_items();

		// Built from sanitized filter values rather than the raw $_GET
		// superglobal, so the export link only ever carries known, validated
		// query args.
		$f           = Reslab_AL_List_Table::get_request_filters();
		$export_args = array_filter( [
			'page'               => 'reslab-activity-log',
			'reslab_al_export'   => 'csv',
			'filter_user'        => $f['user'] > 0 ? $f['user'] : null,
			'filter_action'      => $f['action'] !== '' ? $f['action'] : null,
			'filter_object_type' => $f['object_type'] !== '' ? $f['object_type'] : null,
			'filter_date_from'   => $f['date_from'] !== '' ? $f['date_from'] : null,
			'filter_date_to'     => $f['date_to'] !== '' ? $f['date_to'] : null,
			'filter_ip'          => $f['ip'] !== '' ? $f['ip'] : null,
			'filter_search'      => $f['search'] !== '' ? $f['search'] : null,
		], static fn ( $value ) => $value !== null );

		$export_url = wp_nonce_url(
			add_query_arg( $export_args, admin_url( 'tools.php' ) ),
			'reslab_al_export'
		);
		?>
		<div class="wrap reslab-al-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity Log', 'reslab-activity-log' ); ?></h1>

			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Activity log cleared successfully.', 'reslab-activity-log' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="reslab-al-toolbar">
				<form method="get" class="reslab-al-filters">
					<input type="hidden" name="page" value="reslab-activity-log">
					<?php $this->render_filters(); ?>
				</form>

				<div class="reslab-al-toolbar-actions">
					<a href="<?php echo esc_url( $export_url ); ?>" class="button">
						<?php esc_html_e( 'Export CSV', 'reslab-activity-log' ); ?>
					</a>

					<?php if ( current_user_can( 'reslab_al_clear_log' ) ) : ?>
					<form method="post"
						onsubmit="return confirm('<?php echo esc_js( __( 'Delete all log entries? This cannot be undone.', 'reslab-activity-log' ) ); ?>')">
						<?php wp_nonce_field( 'reslab_al_clear_log' ); ?>
						<input type="hidden" name="reslab_al_clear" value="1">
						<button type="submit" class="button reslab-al-btn-danger">
							<?php esc_html_e( 'Clear log', 'reslab-activity-log' ); ?>
						</button>
					</form>
					<?php endif; ?>
				</div>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="reslab-activity-log">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Filters bar
	// -------------------------------------------------------------------------

	private function render_filters(): void {
		global $wpdb;

		$table = reslab_al_table();
		$f     = Reslab_AL_List_Table::get_request_filters();

		$filter_action      = $f['action'];
		$filter_user        = $f['user'];
		$filter_object_type = $f['object_type'];
		$filter_date_from   = $f['date_from'];
		$filter_date_to     = $f['date_to'];
		$filter_search      = $f['search'];

		// Actions dropdown.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action ASC" );
		echo '<select name="filter_action">';
		echo '<option value="">' . esc_html__( 'All actions', 'reslab-activity-log' ) . '</option>';
		foreach ( (array) $actions as $action ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $action ), selected( $filter_action, $action, false ), esc_html( $action ) );
		}
		echo '</select>';

		// Object type dropdown — narrowed to reslab_al_viewable_object_types
		// when that filter restricts the current user, so it doesn't leak the
		// existence of object types they're not allowed to see.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$object_types = $wpdb->get_col( "SELECT DISTINCT object_type FROM {$table} ORDER BY object_type ASC" );
		$viewable     = Reslab_AL_List_Table::get_viewable_object_types();
		if ( ! empty( $viewable ) ) {
			$object_types = array_values( array_intersect( (array) $object_types, $viewable ) );
		}
		echo '<select name="filter_object_type">';
		echo '<option value="">' . esc_html__( 'All types', 'reslab-activity-log' ) . '</option>';
		foreach ( (array) $object_types as $ot ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $ot ), selected( $filter_object_type, $ot, false ), esc_html( $ot ) );
		}
		echo '</select>';

		// Users dropdown — batched.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_ids  = array_map( 'intval', (array) $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0 ORDER BY user_id ASC"
		) );
		$users_map = [];
		if ( ! empty( $user_ids ) ) {
			foreach ( get_users( [ 'include' => $user_ids, 'number' => count( $user_ids ) ] ) as $u ) {
				$users_map[ $u->ID ] = $u->display_name;
			}
		}
		echo '<select name="filter_user">';
		echo '<option value="0">' . esc_html__( 'All users', 'reslab-activity-log' ) . '</option>';
		foreach ( $user_ids as $uid ) {
			/* translators: %d: user ID */
			$name = $users_map[ $uid ] ?? sprintf( __( 'Deleted #%d', 'reslab-activity-log' ), $uid );
			printf( '<option value="%d"%s>%s</option>', (int) $uid, selected( $filter_user, $uid, false ), esc_html( $name ) );
		}
		echo '</select>';

		// Date range.
		printf(
			'<input type="date" name="filter_date_from" value="%s" title="%s">',
			esc_attr( $filter_date_from ),
			esc_attr__( 'From date', 'reslab-activity-log' )
		);
		printf(
			'<input type="date" name="filter_date_to" value="%s" title="%s">',
			esc_attr( $filter_date_to ),
			esc_attr__( 'To date', 'reslab-activity-log' )
		);

		// Free-text search over the event context (product/post titles,
		// usernames, option names, coupon codes, etc.).
		printf(
			'<input type="search" name="filter_search" value="%s" placeholder="%s" class="reslab-al-search-input">',
			esc_attr( $filter_search ),
			esc_attr__( 'Search…', 'reslab-activity-log' )
		);

		submit_button( __( 'Filter', 'reslab-activity-log' ), 'secondary', '', false );

		$has_filters = $filter_action || $filter_user || $filter_object_type || $filter_date_from || $filter_date_to || $filter_search;
		if ( $has_filters ) {
			printf(
				' <a href="%s" class="button">%s</a>',
				esc_url( admin_url( 'tools.php?page=reslab-activity-log' ) ),
				esc_html__( 'Reset', 'reslab-activity-log' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Styles
	// -------------------------------------------------------------------------

	public function enqueue_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'reslab-activity-log' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'reslab-al-admin',
			RESLAB_AL_URL . 'assets/css/admin.css',
			[],
			RESLAB_AL_VERSION
		);
	}
}
