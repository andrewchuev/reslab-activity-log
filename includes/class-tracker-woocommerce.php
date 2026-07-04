<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce-specific event tracker.
 * Loaded only when WooCommerce is active.
 */
class Reslab_AL_Tracker_WooCommerce {

	private const TRACKED_PRODUCT_META = [
		'_price',
		'_regular_price',
		'_sale_price',
		'_stock',
		'_stock_status',
	];

	/**
	 * Pre-write meta values, keyed by "{object_id}:{meta_key}". Populated by
	 * capture_product_meta_before() (fires before the DB write) and consumed
	 * by on_product_meta_updated() (fires after) — updated_post_meta only
	 * hands you the new value, and by the time it fires get_post_meta()
	 * already reflects it too, so there is no other way to know what changed.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta_before = [];

	public function __construct() {
		$this->register_hooks();
	}

	private function register_hooks(): void {
		// Order status transitions — works reliably with both HPOS and legacy post-based orders.
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );

		// Product price/stock changes. update_post_metadata fires *before* the
		// write (used only to capture the old value); updated_post_meta
		// fires after (used to actually log).
		add_filter( 'update_post_metadata', [ $this, 'capture_product_meta_before' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'on_product_meta_updated' ], 10, 4 );

		// Coupons.
		add_action( 'woocommerce_applied_coupon',       [ $this, 'on_coupon_applied' ] );
		add_action( 'woocommerce_removed_coupon',       [ $this, 'on_coupon_removed' ] );

		// Refunds.
		add_action( 'woocommerce_refund_created',       [ $this, 'on_refund_created' ], 10, 2 );

		// Permanent deletion — the generic post tracker deliberately ignores
		// WC order post types (see Reslab_AL_Tracker::is_trackable_post_type()),
		// so deletion has to be tracked here to not lose the event entirely.
		add_action( 'woocommerce_delete_order', [ $this, 'on_order_deleted' ] );
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	public function on_order_status_changed( int $order_id, string $from, string $to, WC_Order $order ): void {
		$this->log( 'order_status_changed', 'order', $order_id, [
			'from'           => $from,
			'to'             => $to,
			'order_total'    => $order->get_total(),
			'order_currency' => $order->get_currency(),
			'customer_id'    => $order->get_customer_id(),
		] );
	}

	/**
	 * Runs before update_post_meta() writes to the DB (it's a short-circuit
	 * filter: returning anything other than null there would replace the
	 * write itself, so this always returns $check unchanged and only uses
	 * the call as a chance to read the value while it's still the old one).
	 */
	public function capture_product_meta_before( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		if ( in_array( $meta_key, self::TRACKED_PRODUCT_META, true ) && get_post_type( $object_id ) === 'product' ) {
			$this->meta_before[ "{$object_id}:{$meta_key}" ] = get_post_meta( $object_id, $meta_key, true );
		}

		return $check;
	}

	/**
	 * Fires on any post meta update, after the write; we filter only WC
	 * product price/stock keys and compare against the value stashed by
	 * capture_product_meta_before().
	 */
	public function on_product_meta_updated( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( ! in_array( $meta_key, self::TRACKED_PRODUCT_META, true ) ) {
			return;
		}

		if ( get_post_type( $object_id ) !== 'product' ) {
			return;
		}

		$cache_key = "{$object_id}:{$meta_key}";
		$old_value = $this->meta_before[ $cache_key ] ?? '';
		unset( $this->meta_before[ $cache_key ] );

		if ( (string) $old_value === (string) $meta_value ) {
			return;
		}

		// wc_get_product() returns WC_Product|false, not null, so a plain
		// ternary is used here rather than the nullsafe operator.
		$product = wc_get_product( $object_id );

		$this->log( 'product_meta_updated', 'product', $object_id, [
			'product_name' => $product ? $product->get_name() : '',
			'meta_key'     => $meta_key,
			'old_value'    => $old_value,
			'new_value'    => $meta_value,
		] );
	}

	public function on_coupon_applied( string $coupon_code ): void {
		$this->log( 'coupon_applied', 'coupon', 0, [
			'coupon_code' => $coupon_code,
		] );
	}

	public function on_coupon_removed( string $coupon_code ): void {
		$this->log( 'coupon_removed', 'coupon', 0, [
			'coupon_code' => $coupon_code,
		] );
	}

	public function on_refund_created( int $refund_id, array $args ): void {
		$this->log( 'refund_created', 'order', $args['order_id'] ?? 0, [
			'refund_id'     => $refund_id,
			'amount'        => $args['amount']     ?? '',
			'reason'        => $args['reason']     ?? '',
			'refunded_by'   => $args['refunded_by'] ?? get_current_user_id(),
		] );
	}

	/**
	 * woocommerce_delete_order fires after the order row is already gone
	 * (permanent delete, HPOS or legacy), so only the ID is available —
	 * unlike WordPress's deleted_post, WooCommerce doesn't pass the object.
	 */
	public function on_order_deleted( int $order_id ): void {
		$this->log( 'deleted', 'order', $order_id );
	}

	// -------------------------------------------------------------------------
	// Core write method (mirrors Reslab_AL_Tracker::log)
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $context
	 */
	private function log(
		string $action,
		string $object_type,
		int $object_id,
		array $context = []
	): void {
		global $wpdb;

		$wpdb->insert(
			reslab_al_table(),
			[
				'user_id'     => get_current_user_id(),
				'ip_address'  => $this->get_client_ip(),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'context'     => wp_json_encode( $context ),
				'request_id'  => reslab_al_request_id(),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	private function get_client_ip(): string {
		$remote_addr     = $_SERVER['REMOTE_ADDR'] ?? '';
		$trusted_proxies = apply_filters( 'reslab_al_trusted_proxies',
			defined( 'RESLAB_AL_TRUSTED_PROXIES' )
				? array_map( 'trim', explode( ',', RESLAB_AL_TRUSTED_PROXIES ) )
				: []
		);

		if ( ! empty( $trusted_proxies ) && in_array( $remote_addr, $trusted_proxies, true ) ) {
			foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ] as $key ) {
				$value = $_SERVER[ $key ] ?? '';
				if ( $value !== '' ) {
					$ip = trim( explode( ',', $value )[0] );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}

		return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
	}
}
