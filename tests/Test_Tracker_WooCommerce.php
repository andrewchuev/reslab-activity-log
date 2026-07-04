<?php
/**
 * Covers Reslab_AL_Tracker_WooCommerce: order status changes, product
 * price/stock meta changes, coupons, refunds, and permanent order deletion.
 * Exercises the real WooCommerce CRUD API (wc_create_order(), WC_Product,
 * wc_create_refund()) rather than firing hooks by hand, so it works
 * regardless of whether HPOS or legacy post-based order storage is active.
 */
final class Test_Tracker_WooCommerce extends Reslab_AL_TestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
	}

	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_status( 'pending' );
		$order->save();
		return $order;
	}

	private function create_product( string $regular_price = '10.00' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test Widget' );
		$product->set_regular_price( $regular_price );
		$product->save();
		return $product;
	}

	public function test_order_status_change_is_logged(): void {
		$order = $this->create_order();

		$order->update_status( 'processing' );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'order_status_changed', $row->action );
		$this->assertSame( 'order', $row->object_type );
		$this->assertSame( $order->get_id(), (int) $row->object_id );
		$this->assertSame( 'pending', $context['from'] );
		$this->assertSame( 'processing', $context['to'] );
	}

	public function test_product_price_change_is_logged_with_old_and_new_value(): void {
		$product = $this->create_product( '10.00' );

		update_post_meta( $product->get_id(), '_regular_price', '25.00' );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'product_meta_updated', $row->action );
		$this->assertSame( 'product', $row->object_type );
		$this->assertSame( '_regular_price', $context['meta_key'] );
		$this->assertSame( '10.00', $context['old_value'] );
		$this->assertSame( '25.00', $context['new_value'] );
	}

	public function test_product_meta_update_to_same_value_logs_nothing(): void {
		$product = $this->create_product( '10.00' );
		$before  = $this->count_log_rows();

		update_post_meta( $product->get_id(), '_regular_price', '10.00' );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_untracked_product_meta_is_not_logged(): void {
		$product = $this->create_product();
		$before  = $this->count_log_rows();

		update_post_meta( $product->get_id(), '_some_unrelated_meta_key', 'anything' );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_meta_update_on_a_non_product_post_is_not_logged(): void {
		$post_id = self::factory()->post->create();
		$before  = $this->count_log_rows();

		// Same meta key WooCommerce uses for prices, but on a plain post —
		// must not be mistaken for a product price change.
		update_post_meta( $post_id, '_regular_price', '10.00' );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_coupon_applied_is_logged(): void {
		do_action( 'woocommerce_applied_coupon', 'SAVE10' );

		$row = $this->get_last_log_row();
		$this->assertSame( 'coupon_applied', $row->action );
		$this->assertSame( 'SAVE10', json_decode( $row->context, true )['coupon_code'] );
	}

	public function test_coupon_removed_is_logged(): void {
		do_action( 'woocommerce_removed_coupon', 'SAVE10' );

		$row = $this->get_last_log_row();
		$this->assertSame( 'coupon_removed', $row->action );
		$this->assertSame( 'SAVE10', json_decode( $row->context, true )['coupon_code'] );
	}

	public function test_refund_created_is_logged(): void {
		$order = $this->create_order();
		$order->set_total( 50 );
		$order->save();

		$refund = wc_create_refund( [
			'order_id' => $order->get_id(),
			'amount'   => '12.50',
			'reason'   => 'Customer request',
		] );
		$this->assertNotInstanceOf( WP_Error::class, $refund );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'refund_created', $row->action );
		$this->assertSame( 'order', $row->object_type );
		$this->assertSame( $order->get_id(), (int) $row->object_id );
		$this->assertSame( '12.50', $context['amount'] );
		$this->assertSame( 'Customer request', $context['reason'] );
	}

	public function test_order_permanent_deletion_is_logged(): void {
		$order    = $this->create_order();
		$order_id = $order->get_id();

		$order->delete( true );

		$row = $this->get_last_log_row();
		$this->assertSame( 'deleted', $row->action );
		$this->assertSame( 'order', $row->object_type );
		$this->assertSame( $order_id, (int) $row->object_id );
	}

	/**
	 * The generic Reslab_AL_Tracker deliberately excludes WC order post
	 * types (see Test_Tracker_Content::test_woocommerce_order_post_types_are_excluded_from_generic_tracking).
	 * Confirms the two trackers together produce exactly one row per order
	 * event, not a duplicate "post" row alongside the "order" one.
	 */
	public function test_order_deletion_produces_exactly_one_log_row(): void {
		$order    = $this->create_order();
		$before   = $this->count_log_rows();

		$order->delete( true );

		$this->assertSame( $before + 1, $this->count_log_rows() );
	}
}
