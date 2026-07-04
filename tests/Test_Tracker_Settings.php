<?php
/**
 * Covers Reslab_AL_Tracker's settings hooks: tracked wp_options changes,
 * plugin/theme activation, and nav menu updates.
 */
final class Test_Tracker_Settings extends Reslab_AL_TestCase {

	public function test_tracked_option_change_is_logged(): void {
		$old_name = get_option( 'blogname' );

		update_option( 'blogname', 'A Brand New Name' );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'updated', $row->action );
		$this->assertSame( 'option', $row->object_type );
		$this->assertSame( 'blogname', $context['option'] );
		$this->assertSame( $old_name, $context['old_value'] );
		$this->assertSame( 'A Brand New Name', $context['new_value'] );
	}

	public function test_untracked_option_change_is_not_logged(): void {
		$before = $this->count_log_rows();

		update_option( 'this_is_not_a_tracked_option', 'whatever' );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_setting_option_to_same_value_logs_nothing(): void {
		update_option( 'blogdescription', 'Same description' );
		$before = $this->count_log_rows();

		update_option( 'blogdescription', 'Same description' );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_plugin_activation_is_logged(): void {
		do_action( 'activated_plugin', 'hello.php' );

		$row = $this->get_last_log_row();
		$this->assertSame( 'activated', $row->action );
		$this->assertSame( 'plugin', $row->object_type );
		$this->assertSame( 'hello.php', json_decode( $row->context, true )['plugin'] );
	}

	public function test_plugin_deactivation_is_logged(): void {
		do_action( 'deactivated_plugin', 'hello.php' );

		$row = $this->get_last_log_row();
		$this->assertSame( 'deactivated', $row->action );
		$this->assertSame( 'hello.php', json_decode( $row->context, true )['plugin'] );
	}

	/**
	 * wp_create_nav_menu() fires its own 'wp_create_nav_menu' action, not
	 * 'wp_update_nav_menu' — creating a menu is intentionally not logged
	 * here (mirrors the 'auto-draft' post-reservation case), only edits to
	 * an existing one are, via wp_update_nav_menu_object().
	 */
	public function test_nav_menu_edit_is_logged(): void {
		$menu_id = wp_create_nav_menu( 'Primary Menu' );
		$before  = $this->count_log_rows();

		wp_update_nav_menu_object( $menu_id, [ 'menu-name' => 'Renamed Menu' ] );

		$this->assertSame( $before + 1, $this->count_log_rows() );

		$row = $this->get_last_log_row();
		$this->assertSame( 'updated', $row->action );
		$this->assertSame( 'nav_menu', $row->object_type );
		$this->assertSame( $menu_id, (int) $row->object_id );
		$this->assertSame( 'Renamed Menu', json_decode( $row->context, true )['menu_name'] );
	}
}
