<?php
/**
 * Covers Reslab_AL_Tracker's post/content hooks: status transitions, field
 * edits, deletion, and which post types are excluded from generic tracking.
 */
final class Test_Tracker_Content extends Reslab_AL_TestCase {

	public function test_publishing_a_draft_logs_published_with_status_diff(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Draft post' ] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$row = $this->get_last_log_row();
		$this->assertSame( 'published', $row->action );
		$this->assertSame( 'post', $row->object_type );
		$this->assertSame( $post_id, (int) $row->object_id );

		$context = json_decode( $row->context, true );
		$this->assertSame( 'draft', $context['old_status'] );
		$this->assertSame( 'publish', $context['new_status'] );
	}

	/**
	 * WordPress reserves a post ID by inserting an 'auto-draft' row before the
	 * user has even opened the editor. That transition ('new' -> 'auto-draft')
	 * is not audit-worthy and must produce no log row at all.
	 */
	public function test_auto_draft_reservation_is_not_logged(): void {
		$before = $this->count_log_rows();

		self::factory()->post->create( [ 'post_status' => 'auto-draft' ] );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	/**
	 * 'auto-draft' -> anything else *is* audit-worthy: it's exactly what
	 * happens when a brand-new post is published without an intermediate
	 * "Save Draft" click (the common Gutenberg flow).
	 */
	public function test_auto_draft_to_publish_is_logged_as_published(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'auto-draft' ] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'published', $row->action );
		$this->assertSame( 'auto-draft', $context['old_status'] );
	}

	public function test_trashing_a_post_logs_trashed(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		wp_trash_post( $post_id );

		$row = $this->get_last_log_row();
		$this->assertSame( 'trashed', $row->action );
		$this->assertSame( $post_id, (int) $row->object_id );
	}

	public function test_editing_title_and_content_without_status_change_logs_updated_with_diff(): void {
		$post_id = self::factory()->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Original title',
			'post_content' => 'Original content',
		] );

		wp_update_post( [
			'ID'           => $post_id,
			'post_title'   => 'New title',
			'post_content' => 'New content',
		] );

		$row = $this->get_last_log_row();
		$this->assertSame( 'updated', $row->action );

		$changed = json_decode( $row->context, true )['changed'];
		$this->assertSame( [ 'from' => 'Original title', 'to' => 'New title' ], $changed['title'] );
		$this->assertTrue( $changed['content'] );
	}

	public function test_saving_without_any_field_change_logs_nothing(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Same' ] );
		$before  = $this->count_log_rows();

		// A no-op save: same title, same content, same status.
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Same' ] );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_deleting_a_post_logs_deleted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Gone soon' ] );

		wp_delete_post( $post_id, true );

		$row = $this->get_last_log_row();
		$this->assertSame( 'deleted', $row->action );
		$this->assertSame( $post_id, (int) $row->object_id );
		$this->assertSame( 'Gone soon', json_decode( $row->context, true )['post_title'] );
	}

	/**
	 * @dataProvider excluded_post_type_provider
	 */
	public function test_internal_post_types_are_excluded_from_generic_tracking( string $post_type ): void {
		$tracker = $this->instantiate_without_constructor( Reslab_AL_Tracker::class );
		$this->assertFalse( $this->call_private_method( $tracker, 'is_trackable_post_type', [ $post_type ] ) );
	}

	public static function excluded_post_type_provider(): array {
		return [
			'revisions'            => [ 'revision' ],
			'nav menu items'       => [ 'nav_menu_item' ],
			'customize changesets' => [ 'customize_changeset' ],
		];
	}

	/**
	 * WooCommerce orders get their own HPOS-aware tracker
	 * (Reslab_AL_Tracker_WooCommerce); the generic post tracker must stay out
	 * of the way or every order transition would be logged twice.
	 */
	public function test_woocommerce_order_post_types_are_excluded_from_generic_tracking(): void {
		$this->assertTrue( function_exists( 'wc_get_order_types' ), 'This test requires WooCommerce to be loaded.' );

		$tracker = $this->instantiate_without_constructor( Reslab_AL_Tracker::class );
		foreach ( wc_get_order_types() as $order_post_type ) {
			$this->assertFalse(
				$this->call_private_method( $tracker, 'is_trackable_post_type', [ $order_post_type ] ),
				"{$order_post_type} should be excluded from generic post tracking."
			);
		}
	}

	public function test_ordinary_post_types_remain_trackable(): void {
		$tracker = $this->instantiate_without_constructor( Reslab_AL_Tracker::class );
		$this->assertTrue( $this->call_private_method( $tracker, 'is_trackable_post_type', [ 'post' ] ) );
		$this->assertTrue( $this->call_private_method( $tracker, 'is_trackable_post_type', [ 'page' ] ) );
	}
}
