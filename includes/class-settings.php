<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page: retention period, IP anonymization, brute-force alert config.
 */
class Reslab_AL_Settings {

	private const OPTION_GROUP = 'reslab_al_settings';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// options.php checks manage_options by default regardless of the menu
		// page's own capability; align it with reslab_al_manage_settings so a
		// user granted only that cap can actually save the form.
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, static fn () => 'reslab_al_manage_settings' );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'Activity Log Settings', 'reslab-activity-log' ),
			__( 'Activity Log Settings', 'reslab-activity-log' ),
			'reslab_al_manage_settings',
			'reslab-activity-log-settings',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		// ---- Retention ----
		register_setting( self::OPTION_GROUP, 'reslab_al_retention_days', [
			'type'              => 'integer',
			'default'           => 30,
			'sanitize_callback' => static fn( $v ) => max( 1, (int) $v ),
		] );
		register_setting( self::OPTION_GROUP, 'reslab_al_archive_before_purge', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		// ---- IP anonymization ----
		register_setting( self::OPTION_GROUP, 'reslab_al_anonymize_ip', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );

		// ---- Brute-force alert ----
		register_setting( self::OPTION_GROUP, 'reslab_al_bruteforce_alerts_enabled', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::OPTION_GROUP, 'reslab_al_bruteforce_threshold', [
			'type'              => 'integer',
			'default'           => 10,
			'sanitize_callback' => static fn( $v ) => max( 1, (int) $v ),
		] );
		register_setting( self::OPTION_GROUP, 'reslab_al_bruteforce_window_hours', [
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => static fn( $v ) => max( 1, (int) $v ),
		] );

		// ---- Mass-deletion alert ----
		register_setting( self::OPTION_GROUP, 'reslab_al_deletion_alerts_enabled', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( self::OPTION_GROUP, 'reslab_al_deletion_threshold', [
			'type'              => 'integer',
			'default'           => 5,
			'sanitize_callback' => static fn( $v ) => max( 1, (int) $v ),
		] );
		register_setting( self::OPTION_GROUP, 'reslab_al_deletion_window_hours', [
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => static fn( $v ) => max( 1, (int) $v ),
		] );

		// ---- Notifications ----
		register_setting( self::OPTION_GROUP, 'reslab_al_alert_webhook_url', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => static fn( $v ) => esc_url_raw( trim( (string) $v ) ),
		] );

		// ---- Sections ----
		add_settings_section( 'reslab_al_section_data',
			__( 'Data Retention', 'reslab-activity-log' ),
			[ $this, 'section_data_intro' ], 'reslab-activity-log-settings'
		);
		add_settings_section( 'reslab_al_section_privacy',
			__( 'Privacy (GDPR)', 'reslab-activity-log' ),
			'__return_false', 'reslab-activity-log-settings'
		);
		add_settings_section( 'reslab_al_section_alerts',
			__( 'Brute-Force Alerts', 'reslab-activity-log' ),
			[ $this, 'section_alerts_intro' ], 'reslab-activity-log-settings'
		);
		add_settings_section( 'reslab_al_section_deletion',
			__( 'Mass Deletion Alerts', 'reslab-activity-log' ),
			[ $this, 'section_deletion_intro' ], 'reslab-activity-log-settings'
		);
		add_settings_section( 'reslab_al_section_notifications',
			__( 'Notifications', 'reslab-activity-log' ),
			'__return_false', 'reslab-activity-log-settings'
		);

		// ---- Fields ----
		add_settings_field( 'reslab_al_retention_days',
			__( 'Keep logs for (days)', 'reslab-activity-log' ),
			[ $this, 'field_retention_days' ],
			'reslab-activity-log-settings', 'reslab_al_section_data'
		);
		add_settings_field( 'reslab_al_archive_before_purge',
			__( 'Archive before purge', 'reslab-activity-log' ),
			[ $this, 'field_archive_before_purge' ],
			'reslab-activity-log-settings', 'reslab_al_section_data'
		);
		add_settings_field( 'reslab_al_anonymize_ip',
			__( 'Anonymize IP addresses', 'reslab-activity-log' ),
			[ $this, 'field_anonymize_ip' ],
			'reslab-activity-log-settings', 'reslab_al_section_privacy'
		);
		add_settings_field( 'reslab_al_bruteforce_alerts_enabled',
			__( 'Enable brute-force alerts', 'reslab-activity-log' ),
			[ $this, 'field_bruteforce_enabled' ],
			'reslab-activity-log-settings', 'reslab_al_section_alerts'
		);
		add_settings_field( 'reslab_al_bruteforce_threshold',
			__( 'Alert after N failed logins', 'reslab-activity-log' ),
			[ $this, 'field_bruteforce_threshold' ],
			'reslab-activity-log-settings', 'reslab_al_section_alerts'
		);
		add_settings_field( 'reslab_al_bruteforce_window_hours',
			__( 'Detection window (hours)', 'reslab-activity-log' ),
			[ $this, 'field_bruteforce_window' ],
			'reslab-activity-log-settings', 'reslab_al_section_alerts'
		);
		add_settings_field( 'reslab_al_deletion_alerts_enabled',
			__( 'Enable mass-deletion alerts', 'reslab-activity-log' ),
			[ $this, 'field_deletion_enabled' ],
			'reslab-activity-log-settings', 'reslab_al_section_deletion'
		);
		add_settings_field( 'reslab_al_deletion_threshold',
			__( 'Alert after N deletions', 'reslab-activity-log' ),
			[ $this, 'field_deletion_threshold' ],
			'reslab-activity-log-settings', 'reslab_al_section_deletion'
		);
		add_settings_field( 'reslab_al_deletion_window_hours',
			__( 'Detection window (hours)', 'reslab-activity-log' ),
			[ $this, 'field_deletion_window' ],
			'reslab-activity-log-settings', 'reslab_al_section_deletion'
		);
		add_settings_field( 'reslab_al_alert_webhook_url',
			__( 'Webhook URL', 'reslab-activity-log' ),
			[ $this, 'field_alert_webhook_url' ],
			'reslab-activity-log-settings', 'reslab_al_section_notifications'
		);
	}

	// -------------------------------------------------------------------------
	// Section intros — "last ran" status, so an admin can tell WP-Cron is
	// actually alive without waiting for a purge/alert-worthy event to show
	// up in the log.
	// -------------------------------------------------------------------------

	public function section_data_intro(): void {
		$this->render_last_run( 'reslab_al_last_purge_run', __( 'Log purge', 'reslab-activity-log' ) );
	}

	public function section_alerts_intro(): void {
		$this->render_last_run( 'reslab_al_last_bruteforce_check', __( 'Brute-force check', 'reslab-activity-log' ) );
	}

	public function section_deletion_intro(): void {
		$this->render_last_run( 'reslab_al_last_deletion_check', __( 'Mass-deletion check', 'reslab-activity-log' ) );
	}

	private function render_last_run( string $option, string $label ): void {
		$ts = (int) get_option( $option, 0 );

		$text = $ts > 0
			? sprintf(
				/* translators: 1: job name, 2: human-readable time difference, e.g. "3 hours" */
				__( '%1$s last ran %2$s ago.', 'reslab-activity-log' ),
				$label,
				human_time_diff( $ts )
			)
			: sprintf(
				/* translators: %s: job name */
				__( '%s has not run yet.', 'reslab-activity-log' ),
				$label
			);

		echo '<p class="reslab-al-last-run">' . esc_html( $text ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public function field_retention_days(): void {
		$value = (int) get_option( 'reslab_al_retention_days', 30 );
		printf(
			'<input type="number" name="reslab_al_retention_days" value="%d" min="1" max="365" class="small-text"> %s',
			(int) $value,
			esc_html__( 'days', 'reslab-activity-log' )
		);
		echo '<p class="description">' . esc_html__( 'Log entries older than this will be deleted automatically every night.', 'reslab-activity-log' ) . '</p>';
	}

	public function field_archive_before_purge(): void {
		$checked = (bool) get_option( 'reslab_al_archive_before_purge', false );
		printf(
			'<label><input type="checkbox" name="reslab_al_archive_before_purge" value="1"%s> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Save a CSV copy of entries before the nightly purge deletes them.', 'reslab-activity-log' )
		);
		echo '<p class="description">' . esc_html__( 'Archives are gzip-compressed and only downloadable by users who can view the log (see below).', 'reslab-activity-log' ) . '</p>';
	}

	public function field_anonymize_ip(): void {
		$checked = (bool) get_option( 'reslab_al_anonymize_ip', true );
		printf(
			'<label><input type="checkbox" name="reslab_al_anonymize_ip" value="1"%s> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Mask the last octet of IPv4 addresses (e.g. 192.168.1.0)', 'reslab-activity-log' )
		);
		echo '<p class="description">' . esc_html__( 'Enabled by default for GDPR compliance. Applies to new entries only.', 'reslab-activity-log' ) . '</p>';
	}

	public function field_bruteforce_enabled(): void {
		$checked = (bool) get_option( 'reslab_al_bruteforce_alerts_enabled', false );
		printf(
			'<label><input type="checkbox" name="reslab_al_bruteforce_alerts_enabled" value="1"%s> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Email the site administrator when a possible brute-force attack is detected.', 'reslab-activity-log' )
		);
		echo '<p class="description">' . esc_html__( 'Disabled by default. The hourly check below only runs, and only sends mail, while this is enabled.', 'reslab-activity-log' ) . '</p>';
	}

	public function field_bruteforce_threshold(): void {
		$value = (int) get_option( 'reslab_al_bruteforce_threshold', 10 );
		printf(
			'<input type="number" name="reslab_al_bruteforce_threshold" value="%d" min="1" max="1000" class="small-text">',
			(int) $value
		);
		echo '<p class="description">' . esc_html__( 'Send an email alert when this many failed login attempts are detected from the same IP.', 'reslab-activity-log' ) . '</p>';
	}

	public function field_bruteforce_window(): void {
		$value = (int) get_option( 'reslab_al_bruteforce_window_hours', 1 );
		printf(
			'<input type="number" name="reslab_al_bruteforce_window_hours" value="%d" min="1" max="24" class="small-text"> %s',
			(int) $value,
			esc_html__( 'hours', 'reslab-activity-log' )
		);
	}

	public function field_deletion_enabled(): void {
		$checked = (bool) get_option( 'reslab_al_deletion_alerts_enabled', false );
		printf(
			'<label><input type="checkbox" name="reslab_al_deletion_alerts_enabled" value="1"%s> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Email the site administrator when one user deletes an unusually large number of objects in a short window.', 'reslab-activity-log' )
		);
		echo '<p class="description">' . esc_html__( 'Disabled by default. Catches mass content/order deletion from a compromised or malicious account.', 'reslab-activity-log' ) . '</p>';
	}

	public function field_deletion_threshold(): void {
		$value = (int) get_option( 'reslab_al_deletion_threshold', 5 );
		printf(
			'<input type="number" name="reslab_al_deletion_threshold" value="%d" min="1" max="1000" class="small-text">',
			(int) $value
		);
		echo '<p class="description">' . esc_html__( 'Send an email alert when the same user logs this many "deleted" events.', 'reslab-activity-log' ) . '</p>';
	}

	public function field_deletion_window(): void {
		$value = (int) get_option( 'reslab_al_deletion_window_hours', 1 );
		printf(
			'<input type="number" name="reslab_al_deletion_window_hours" value="%d" min="1" max="24" class="small-text"> %s',
			(int) $value,
			esc_html__( 'hours', 'reslab-activity-log' )
		);
	}

	public function field_alert_webhook_url(): void {
		$value = (string) get_option( 'reslab_al_alert_webhook_url', '' );
		printf(
			'<input type="url" name="reslab_al_alert_webhook_url" value="%s" class="regular-text" placeholder="https://hooks.slack.com/services/…">',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Every brute-force and mass-deletion alert is also POSTed as JSON to this URL — works with Slack/Discord incoming webhooks or any automation tool (Zapier, Make, n8n). Leave blank to use email only.', 'reslab-activity-log' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'reslab_al_manage_settings' ) ) {
			wp_die( esc_html__( 'Access denied.', 'reslab-activity-log' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Activity Log Settings', 'reslab-activity-log' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'reslab-activity-log-settings' );
				submit_button();
				?>
			</form>
			<?php $this->render_archives_section(); ?>
		</div>
		<?php
	}

	/**
	 * Lists pre-purge archive files with a nonce-gated download link. Shown
	 * only to users who can view the log — separate from (and possibly
	 * narrower than) reslab_al_manage_settings, which gates this page itself.
	 */
	private function render_archives_section(): void {
		if ( ! current_user_can( 'reslab_al_view_log' ) ) {
			return;
		}

		$dir   = reslab_al_archive_dir();
		$files = is_dir( $dir ) ? glob( $dir . '*.csv.gz' ) : [];
		if ( empty( $files ) ) {
			return;
		}

		rsort( $files ); // Filenames are date-prefixed, so this sorts newest first.
		?>
		<hr>
		<h2><?php esc_html_e( 'Purge Archives', 'reslab-activity-log' ); ?></h2>
		<p class="description"><?php esc_html_e( 'CSV snapshots saved before the nightly purge deleted those entries (only created while "Archive before purge" is enabled).', 'reslab-activity-log' ); ?></p>
		<table class="widefat striped" style="max-width: 600px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'reslab-activity-log' ); ?></th>
					<th><?php esc_html_e( 'Size', 'reslab-activity-log' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $files as $file ) :
				$filename     = basename( $file );
				$download_url = wp_nonce_url(
					add_query_arg(
						[
							'page'                       => 'reslab-activity-log-settings',
							'reslab_al_download_archive' => $filename,
						],
						admin_url( 'tools.php' )
					),
					'reslab_al_download_archive'
				);
				?>
				<tr>
					<td><?php echo esc_html( $filename ); ?></td>
					<td><?php echo esc_html( size_format( (int) filesize( $file ) ) ); ?></td>
					<td><a href="<?php echo esc_url( $download_url ); ?>" class="button button-small"><?php esc_html_e( 'Download', 'reslab-activity-log' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
