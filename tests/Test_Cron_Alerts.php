<?php
/**
 * Covers Reslab_AL_Cron's anomaly checks: brute-force login detection and
 * mass-deletion detection, including the enabled/disabled toggle, the
 * per-window dedup transient, and the webhook POST.
 */
final class Test_Cron_Alerts extends Reslab_AL_TestCase {

	public function setUp(): void {
		parent::setUp();
		reset_phpmailer_instance();
		delete_transient( 'reslab_al_bruteforce_alerted' );
		delete_transient( 'reslab_al_deletion_alerted' );
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function cron(): Reslab_AL_Cron {
		return $this->instantiate_without_constructor( Reslab_AL_Cron::class );
	}

	private function insert_failed_logins( string $ip, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->insert_log_row( [
				'action'     => 'login_failed',
				'ip_address' => $ip,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Brute-force
	// -------------------------------------------------------------------------

	public function test_bruteforce_check_updates_last_run_even_when_disabled(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', false );
		$before = time();

		$this->cron()->check_bruteforce();

		$this->assertGreaterThanOrEqual( $before, (int) get_option( 'reslab_al_last_bruteforce_check' ) );
	}

	public function test_bruteforce_alert_not_sent_when_disabled(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', false );
		update_option( 'reslab_al_bruteforce_threshold', 3 );
		$this->insert_failed_logins( '203.0.113.5', 5 );

		$this->cron()->check_bruteforce();

		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_bruteforce_alert_sent_when_threshold_exceeded(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', true );
		update_option( 'reslab_al_bruteforce_threshold', 3 );
		update_option( 'reslab_al_bruteforce_window_hours', 1 );
		$this->insert_failed_logins( '203.0.113.5', 5 );

		$this->cron()->check_bruteforce();

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'brute-force', strtolower( $sent[0]['subject'] ) );
		$this->assertStringContainsString( '203.0.113.5', $sent[0]['body'] );
	}

	public function test_bruteforce_alert_not_sent_below_threshold(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', true );
		update_option( 'reslab_al_bruteforce_threshold', 10 );
		$this->insert_failed_logins( '203.0.113.5', 5 );

		$this->cron()->check_bruteforce();

		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_bruteforce_alert_is_not_duplicated_within_the_same_window(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', true );
		update_option( 'reslab_al_bruteforce_threshold', 3 );
		$this->insert_failed_logins( '203.0.113.5', 5 );

		$this->cron()->check_bruteforce();
		$this->cron()->check_bruteforce(); // Same IP, still over threshold, same window.

		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_bruteforce_alert_ignores_attempts_outside_the_window(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', true );
		update_option( 'reslab_al_bruteforce_threshold', 3 );
		update_option( 'reslab_al_bruteforce_window_hours', 1 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_log_row( [
				'action'     => 'login_failed',
				'ip_address' => '203.0.113.5',
				'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
			] );
		}

		$this->cron()->check_bruteforce();

		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_bruteforce_alert_posts_to_webhook_when_configured(): void {
		update_option( 'reslab_al_bruteforce_alerts_enabled', true );
		update_option( 'reslab_al_bruteforce_threshold', 3 );
		update_option( 'reslab_al_alert_webhook_url', 'https://example.test/webhook' );
		$this->insert_failed_logins( '203.0.113.5', 5 );

		$captured = null;
		add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = [ 'url' => $url, 'args' => $args ];
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );

		$this->cron()->check_bruteforce();

		$this->assertNotNull( $captured );
		$this->assertSame( 'https://example.test/webhook', $captured['url'] );
		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'bruteforce', $payload['type'] );
		$this->assertSame( '203.0.113.5', $payload['ip'] );
	}

	// -------------------------------------------------------------------------
	// Mass deletion
	// -------------------------------------------------------------------------

	public function test_mass_deletion_alert_sent_when_threshold_exceeded(): void {
		$user_id = self::factory()->user->create( [ 'user_login' => 'bulk-deleter' ] );
		update_option( 'reslab_al_deletion_alerts_enabled', true );
		update_option( 'reslab_al_deletion_threshold', 3 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_log_row( [ 'action' => 'deleted', 'user_id' => $user_id, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		}

		$this->cron()->check_mass_deletion();

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'bulk-deleter', $sent[0]['body'] );
	}

	public function test_mass_deletion_alert_ignores_guest_deletions(): void {
		update_option( 'reslab_al_deletion_alerts_enabled', true );
		update_option( 'reslab_al_deletion_threshold', 3 );

		// user_id = 0 (guest / system) rows must not trigger a "user X went on
		// a deletion spree" alert — there's no user to attribute it to.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_log_row( [ 'action' => 'deleted', 'user_id' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		}

		$this->cron()->check_mass_deletion();

		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_mass_deletion_alert_not_duplicated_within_the_same_window(): void {
		$user_id = self::factory()->user->create();
		update_option( 'reslab_al_deletion_alerts_enabled', true );
		update_option( 'reslab_al_deletion_threshold', 3 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_log_row( [ 'action' => 'deleted', 'user_id' => $user_id ] );
		}

		$this->cron()->check_mass_deletion();
		$this->cron()->check_mass_deletion();

		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
