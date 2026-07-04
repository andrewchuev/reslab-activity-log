<?php
/**
 * Covers Reslab_AL_Tracker's auth hooks (wp_login / wp_logout /
 * wp_login_failed), client-IP resolution with the trusted-proxy allowlist,
 * and IP anonymization. The tracker singleton is already registered on the
 * real WordPress hooks by the plugin bootstrap, so these tests fire the
 * actual core actions and assert against the row that lands in the DB.
 */
final class Test_Tracker_Auth extends Reslab_AL_TestCase {

	private array $server_backup;

	public function setUp(): void {
		parent::setUp();
		$this->server_backup = $_SERVER;
		remove_all_filters( 'reslab_al_trusted_proxies' );
	}

	public function tearDown(): void {
		$_SERVER = $this->server_backup;
		remove_all_filters( 'reslab_al_trusted_proxies' );
		parent::tearDown();
	}

	public function test_login_is_logged_with_user_and_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		update_option( 'reslab_al_anonymize_ip', false );

		$user = self::factory()->user->create_and_get( [ 'user_login' => 'alice' ] );
		do_action( 'wp_login', $user->user_login, $user );

		$row = $this->get_last_log_row();
		$this->assertSame( 'logged_in', $row->action );
		$this->assertSame( 'user', $row->object_type );
		$this->assertSame( $user->ID, (int) $row->user_id );
		$this->assertSame( '203.0.113.10', $row->ip_address );
		$this->assertSame( 'alice', json_decode( $row->context, true )['login'] );
	}

	public function test_logout_is_logged_for_given_user_id(): void {
		$user_id = self::factory()->user->create();
		do_action( 'wp_logout', $user_id );

		$row = $this->get_last_log_row();
		$this->assertSame( 'logged_out', $row->action );
		$this->assertSame( $user_id, (int) $row->user_id );
	}

	public function test_failed_login_is_logged_as_guest_with_attempted_username(): void {
		wp_set_current_user( self::factory()->user->create() ); // must be ignored; failed logins are never attributed to the logged-in user.

		do_action( 'wp_login_failed', 'not-a-real-user' );

		$row = $this->get_last_log_row();
		$this->assertSame( 'login_failed', $row->action );
		$this->assertSame( 0, (int) $row->user_id );
		$this->assertSame( 'not-a-real-user', json_decode( $row->context, true )['attempted_login'] );
	}

	// -------------------------------------------------------------------------
	// Client IP resolution
	// -------------------------------------------------------------------------

	public function test_forwarded_headers_are_ignored_without_a_trusted_proxy_list(): void {
		update_option( 'reslab_al_anonymize_ip', false );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '203.0.113.10', $this->get_last_log_row()->ip_address );
	}

	public function test_forwarded_header_is_used_when_remote_addr_is_a_trusted_proxy(): void {
		update_option( 'reslab_al_anonymize_ip', false );
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 10.0.0.1';

		add_filter( 'reslab_al_trusted_proxies', static function () {
			return [ '10.0.0.1' ];
		} );

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '198.51.100.99', $this->get_last_log_row()->ip_address );
	}

	public function test_forwarded_header_from_untrusted_remote_addr_is_still_ignored(): void {
		update_option( 'reslab_al_anonymize_ip', false );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10'; // not in the trusted list below
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		add_filter( 'reslab_al_trusted_proxies', static function () {
			return [ '10.0.0.1' ];
		} );

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '203.0.113.10', $this->get_last_log_row()->ip_address );
	}

	public function test_invalid_remote_addr_is_stored_as_empty_string(): void {
		update_option( 'reslab_al_anonymize_ip', false );
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '', $this->get_last_log_row()->ip_address );
	}

	public function test_ipv4_is_anonymized_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '203.0.113.0', $this->get_last_log_row()->ip_address );
	}

	public function test_ipv6_is_anonymized_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:9abc:def0:1234:5678';

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '2001:db8:1234:0:0:0:0:0', $this->get_last_log_row()->ip_address );
	}

	public function test_ip_anonymization_can_be_disabled(): void {
		update_option( 'reslab_al_anonymize_ip', false );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		do_action( 'wp_login_failed', 'someone' );

		$this->assertSame( '203.0.113.42', $this->get_last_log_row()->ip_address );
	}
}
