<?php
/**
 * Covers Reslab_AL_Tracker's user lifecycle hooks: registration, profile
 * updates (including role changes and password-change masking), and deletion.
 */
final class Test_Tracker_Users extends Reslab_AL_TestCase {

	public function test_user_registration_is_logged_with_hashed_email_not_plaintext(): void {
		$user_id = wp_insert_user( [
			'user_login' => 'newbie',
			'user_pass'  => 'irrelevant',
			'user_email' => 'newbie@example.com',
		] );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );

		$this->assertSame( 'registered', $row->action );
		$this->assertSame( $user_id, (int) $row->object_id );
		$this->assertSame( 'newbie', $context['login'] );
		$this->assertArrayNotHasKey( 'email', $context, 'Plaintext email must never be stored (GDPR).' );
		$this->assertSame( hash( 'sha256', 'newbie@example.com' ), $context['email_hash'] );
	}

	public function test_profile_field_change_is_logged_with_diff(): void {
		$user_id = self::factory()->user->create( [ 'display_name' => 'Old Name' ] );
		$old     = get_userdata( $user_id );

		wp_update_user( [ 'ID' => $user_id, 'display_name' => 'New Name' ] );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'profile_updated', $row->action );
		$this->assertSame( [ 'from' => 'Old Name', 'to' => 'New Name' ], $context['changed']['display_name'] );
	}

	public function test_password_change_is_masked_not_logged_in_plaintext(): void {
		$user_id = self::factory()->user->create();

		wp_update_user( [ 'ID' => $user_id, 'user_pass' => 'a-brand-new-password' ] );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( '(password changed)', $context['changed']['user_pass'] );
		$this->assertStringNotContainsString( 'a-brand-new-password', $row->context );
	}

	/**
	 * WP_User::set_role()/add_role() change capabilities directly via user
	 * meta and never fire 'profile_update' — only wp_update_user() (used by
	 * the actual Edit User admin screen) does, so that's what this test
	 * exercises.
	 */
	public function test_role_change_is_logged(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_update_user( [ 'ID' => $user_id, 'role' => 'editor' ] );

		$row     = $this->get_last_log_row();
		$context = json_decode( $row->context, true );
		$this->assertSame( 'profile_updated', $row->action );
		$this->assertSame( 'subscriber', $context['changed']['roles']['from'] );
		$this->assertSame( 'editor', $context['changed']['roles']['to'] );
	}

	public function test_saving_profile_without_changes_logs_nothing(): void {
		$user_id = self::factory()->user->create( [ 'display_name' => 'Stays The Same' ] );
		$before  = $this->count_log_rows();

		wp_update_user( [ 'ID' => $user_id, 'display_name' => 'Stays The Same' ] );

		$this->assertSame( $before, $this->count_log_rows() );
	}

	public function test_user_deletion_is_logged(): void {
		$user_id = self::factory()->user->create( [ 'user_login' => 'doomed-user' ] );

		wp_delete_user( $user_id );

		$row = $this->get_last_log_row();
		$this->assertSame( 'deleted', $row->action );
		$this->assertSame( 'user', $row->object_type );
		$this->assertSame( $user_id, (int) $row->object_id );
		$this->assertSame( 'doomed-user', json_decode( $row->context, true )['login'] );
	}
}
