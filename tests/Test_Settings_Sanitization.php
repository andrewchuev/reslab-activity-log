<?php
/**
 * Covers the sanitize_callbacks registered in Reslab_AL_Settings::register_settings()
 * — these run for real because tests/bootstrap.php fires 'admin_init' once,
 * exactly like a real wp-admin request would.
 */
final class Test_Settings_Sanitization extends Reslab_AL_TestCase {

	public function test_retention_days_is_floored_at_one(): void {
		update_option( 'reslab_al_retention_days', -5 );
		$this->assertSame( 1, (int) get_option( 'reslab_al_retention_days' ) );
	}

	public function test_retention_days_accepts_a_normal_value(): void {
		update_option( 'reslab_al_retention_days', 45 );
		$this->assertSame( 45, (int) get_option( 'reslab_al_retention_days' ) );
	}

	public function test_bruteforce_threshold_is_floored_at_one(): void {
		update_option( 'reslab_al_bruteforce_threshold', 0 );
		$this->assertSame( 1, (int) get_option( 'reslab_al_bruteforce_threshold' ) );
	}

	public function test_deletion_threshold_is_floored_at_one(): void {
		update_option( 'reslab_al_deletion_threshold', -100 );
		$this->assertSame( 1, (int) get_option( 'reslab_al_deletion_threshold' ) );
	}

	/**
	 * @dataProvider boolean_option_provider
	 */
	public function test_boolean_options_are_sanitized_to_real_booleans( string $option ): void {
		update_option( $option, '1' );
		$this->assertTrue( get_option( $option ) );

		update_option( $option, '0' );
		$this->assertFalse( get_option( $option ) );
	}

	public static function boolean_option_provider(): array {
		return [
			[ 'reslab_al_archive_before_purge' ],
			[ 'reslab_al_anonymize_ip' ],
			[ 'reslab_al_bruteforce_alerts_enabled' ],
			[ 'reslab_al_deletion_alerts_enabled' ],
		];
	}

	public function test_webhook_url_is_escaped_and_non_url_is_stripped_to_empty(): void {
		update_option( 'reslab_al_alert_webhook_url', 'javascript:alert(1)' );
		$this->assertSame( '', get_option( 'reslab_al_alert_webhook_url' ) );

		update_option( 'reslab_al_alert_webhook_url', 'https://hooks.slack.com/services/x' );
		$this->assertSame( 'https://hooks.slack.com/services/x', get_option( 'reslab_al_alert_webhook_url' ) );
	}

	public function test_webhook_url_is_trimmed(): void {
		update_option( 'reslab_al_alert_webhook_url', '  https://example.test/hook  ' );
		$this->assertSame( 'https://example.test/hook', get_option( 'reslab_al_alert_webhook_url' ) );
	}

	public function test_option_page_capability_is_reslab_al_manage_settings(): void {
		$this->assertSame(
			'reslab_al_manage_settings',
			apply_filters( 'option_page_capability_reslab_al_settings', 'manage_options' )
		);
	}
}
