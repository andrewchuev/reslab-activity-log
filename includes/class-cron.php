<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-Cron jobs: log rotation and brute-force alert.
 */
class Reslab_AL_Cron {

	private const HOOK_PURGE          = 'reslab_al_purge_old_logs';
	private const HOOK_PURGE_CONTINUE = 'reslab_al_purge_old_logs_continue';
	private const HOOK_ALERT          = 'reslab_al_bruteforce_check';
	private const HOOK_DELETION_ALERT = 'reslab_al_deletion_check';
	private const SCHEDULE            = 'daily';

	/** Caps rows deleted per cron invocation to (BATCH_SIZE * this) so a huge
	 *  retention drop can't run past the request's max_execution_time. */
	private const MAX_BATCHES_PER_RUN = 50;
	private const BATCH_SIZE          = 500;

	/** @var resource|null */
	private $archive_handle = null;

	public function __construct() {
		add_action( self::HOOK_PURGE, [ $this, 'purge' ] );
		add_action( self::HOOK_PURGE_CONTINUE, [ $this, 'purge' ] );
		add_action( self::HOOK_ALERT, [ $this, 'check_bruteforce' ] );
		add_action( self::HOOK_DELETION_ALERT, [ $this, 'check_mass_deletion' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_PURGE ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK_PURGE );
		}
		if ( ! wp_next_scheduled( self::HOOK_ALERT ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK_ALERT );
		}
		if ( ! wp_next_scheduled( self::HOOK_DELETION_ALERT ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK_DELETION_ALERT );
		}
	}

	public static function unschedule(): void {
		foreach ( [ self::HOOK_PURGE, self::HOOK_PURGE_CONTINUE, self::HOOK_ALERT, self::HOOK_DELETION_ALERT ] as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Purge
	// -------------------------------------------------------------------------

	public function purge(): void {
		global $wpdb;

		$table     = reslab_al_table();
		$days      = (int) get_option( 'reslab_al_retention_days', 30 );
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$batch     = self::BATCH_SIZE;
		$deleted   = 0;
		$db_error  = '';
		$batches   = 0;
		$rows      = 0;

		$archive      = (bool) get_option( 'reslab_al_archive_before_purge', false );
		$archive_file = null;
		$archive_path = '';

		do {
			if ( $archive ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$to_archive = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
					$threshold,
					$batch
				) );
				if ( $to_archive ) {
					if ( $archive_file === null ) {
						$archive_path = $this->open_archive();
						$archive_file = $this->archive_handle;
					}
					if ( $archive_file ) {
						$this->write_archive_rows( $archive_file, $to_archive );
					}
				}
			}

			// Same ORDER BY as the archive SELECT above, so the two always
			// agree on exactly which rows a batch covers.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
					$threshold,
					$batch
				)
			);

			if ( $rows === false ) {
				$db_error = $wpdb->last_error;
				break;
			}

			$deleted += $rows;
			$batches++;
		} while ( $rows === $batch && $batches < self::MAX_BATCHES_PER_RUN );

		if ( $archive_file ) {
			gzclose( $archive_file );
			$this->archive_handle = null;
		}

		// Hit the per-run cap with rows still left to delete: continue shortly
		// instead of blocking this cron request (or timing out) indefinitely.
		if ( $db_error === '' && $batches >= self::MAX_BATCHES_PER_RUN && $rows === $batch ) {
			if ( ! wp_next_scheduled( self::HOOK_PURGE_CONTINUE ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_PURGE_CONTINUE );
			}
		}

		if ( $deleted > 0 || $db_error !== '' ) {
			$context = [
				'deleted_rows'    => $deleted,
				'older_than_days' => $days,
				'threshold'       => $threshold,
			];
			if ( $db_error !== '' ) {
				$context['db_error'] = $db_error;
			}
			if ( $archive_path !== '' ) {
				$context['archive_file'] = basename( $archive_path );
			}

			$wpdb->insert(
				reslab_al_table(),
				[
					'user_id'     => 0,
					'ip_address'  => '',
					'action'      => 'purged',
					'object_type' => 'activity_log',
					'object_id'   => 0,
					'context'     => wp_json_encode( $context ),
					'request_id'  => reslab_al_request_id(),
				],
				[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
		}

		update_option( 'reslab_al_last_purge_run', time(), false );
	}

	// -------------------------------------------------------------------------
	// Pre-purge archive
	// -------------------------------------------------------------------------

	/**
	 * Opens a new gzip-compressed CSV in the archive directory (creating the
	 * directory and its directory-listing-blocking index.php on first use)
	 * and writes the header row. Filename is random — nothing links to it
	 * directly; access is only through the nonce+capability gated download
	 * handler in Reslab_AL_Admin::handle_archive_download().
	 */
	private function open_archive(): string {
		$dir = reslab_al_archive_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		$filename = gmdate( 'Y-m-d' ) . '-' . wp_generate_password( 10, false, false ) . '.csv.gz';
		$path     = $dir . $filename;

		$this->archive_handle = gzopen( $path, 'wb9' );
		if ( $this->archive_handle ) {
			$this->write_archive_rows( $this->archive_handle, [], [ 'ID', 'Date', 'User ID', 'IP Address', 'Action', 'Object Type', 'Object ID', 'Context', 'Request ID' ] );
		}

		return $path;
	}

	/**
	 * @param resource      $handle
	 * @param object[]      $rows
	 * @param string[]|null $header Written verbatim (no csv_safe escaping needed for static column names).
	 */
	private function write_archive_rows( $handle, array $rows, ?array $header = null ): void {
		if ( $header !== null ) {
			gzwrite( $handle, implode( ',', $header ) . "\r\n" );
			return;
		}

		foreach ( $rows as $row ) {
			$fields = array_map( [ 'Reslab_AL_Admin', 'csv_safe' ], [
				$row->id,
				$row->created_at,
				$row->user_id,
				$row->ip_address,
				$row->action,
				$row->object_type,
				$row->object_id,
				$row->context,
				$row->request_id,
			] );
			$quoted = array_map( static function ( string $field ): string {
				return preg_match( '/[",\r\n]/', $field ) ? '"' . str_replace( '"', '""', $field ) . '"' : $field;
			}, $fields );
			gzwrite( $handle, implode( ',', $quoted ) . "\r\n" );
		}
	}

	// -------------------------------------------------------------------------
	// Brute-force alert
	// -------------------------------------------------------------------------

	/**
	 * Checks for IPs with too many login_failed events in the last hour.
	 * Fires hourly; sends a single email per IP per detection window.
	 */
	public function check_bruteforce(): void {
		// Recorded regardless of the toggle below, so Settings can show
		// "last ran X ago" as proof WP-Cron itself is actually firing.
		update_option( 'reslab_al_last_bruteforce_check', time(), false );

		if ( ! get_option( 'reslab_al_bruteforce_alerts_enabled', false ) ) {
			return;
		}

		global $wpdb;

		$threshold = (int) get_option( 'reslab_al_bruteforce_threshold', 10 );
		$window    = (int) get_option( 'reslab_al_bruteforce_window_hours', 1 );
		$since     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$window} hours" ) );
		$table     = reslab_al_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip_address, COUNT(*) AS attempts
				 FROM {$table}
				 WHERE action = 'login_failed'
				   AND created_at >= %s
				   AND ip_address != ''
				 GROUP BY ip_address
				 HAVING attempts >= %d",
				$since,
				$threshold
			)
		);

		if ( empty( $results ) ) {
			return;
		}

		// Avoid sending duplicate alerts — track sent IPs in a transient.
		$alerted = (array) get_transient( 'reslab_al_bruteforce_alerted' );

		foreach ( $results as $row ) {
			$ip       = $row->ip_address;
			$attempts = (int) $row->attempts;

			if ( in_array( $ip, $alerted, true ) ) {
				continue;
			}

			$this->send_bruteforce_alert( $ip, $attempts, $window );
			$alerted[] = $ip;
		}

		// Remember which IPs we already notified; expires after the window.
		set_transient( 'reslab_al_bruteforce_alerted', $alerted, $window * HOUR_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Mass-deletion alert
	// -------------------------------------------------------------------------

	/**
	 * Checks for a single user logging an unusually large number of
	 * "deleted" events in a short window — the pattern a compromised
	 * account or a malicious insider leaves (bulk order/content/user
	 * deletion), which brute-force detection alone doesn't catch.
	 */
	public function check_mass_deletion(): void {
		update_option( 'reslab_al_last_deletion_check', time(), false );

		if ( ! get_option( 'reslab_al_deletion_alerts_enabled', false ) ) {
			return;
		}

		global $wpdb;

		$threshold = (int) get_option( 'reslab_al_deletion_threshold', 5 );
		$window    = (int) get_option( 'reslab_al_deletion_window_hours', 1 );
		$since     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$window} hours" ) );
		$table     = reslab_al_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS deletions
				 FROM {$table}
				 WHERE action = 'deleted'
				   AND created_at >= %s
				   AND user_id > 0
				 GROUP BY user_id
				 HAVING deletions >= %d",
				$since,
				$threshold
			)
		);

		if ( empty( $results ) ) {
			return;
		}

		// Avoid sending duplicate alerts — track already-alerted users in a transient.
		$alerted = (array) get_transient( 'reslab_al_deletion_alerted' );

		foreach ( $results as $row ) {
			$user_id   = (int) $row->user_id;
			$deletions = (int) $row->deletions;

			if ( in_array( $user_id, $alerted, true ) ) {
				continue;
			}

			$this->send_deletion_alert( $user_id, $deletions, $window );
			$alerted[] = $user_id;
		}

		set_transient( 'reslab_al_deletion_alerted', $alerted, $window * HOUR_IN_SECONDS );
	}

	private function send_deletion_alert( int $user_id, int $deletions, int $window_hours ): void {
		$user      = get_userdata( $user_id );
		$user_name = $user ? $user->user_login : sprintf( 'user #%d', $user_id );
		$site_name = get_option( 'blogname' );
		$log_url   = admin_url( 'tools.php?page=reslab-activity-log&filter_action=deleted&filter_user=' . $user_id );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Unusually high number of deletions detected', 'reslab-activity-log' ),
			$site_name
		);

		$message = sprintf(
			/* translators: 1: username, 2: number of deletions, 3: hours, 4: log URL */
			__( "Reslab Activity Log has detected an unusually high number of deletions by one user.\n\nUser: %1\$s\nDeleted objects: %2\$d in the last %3\$d hour(s)\n\nView the full log:\n%4\$s", 'reslab-activity-log' ),
			$user_name,
			$deletions,
			$window_hours,
			$log_url
		);

		$this->send_alert( 'mass_deletion', $subject, $message, [
			'user_id'      => $user_id,
			'user_login'   => $user ? $user->user_login : null,
			'deletions'    => $deletions,
			'window_hours' => $window_hours,
			'log_url'      => $log_url,
		] );
	}

	private function send_bruteforce_alert( string $ip, int $attempts, int $window_hours ): void {
		$site_name = get_option( 'blogname' );
		$log_url   = admin_url( 'tools.php?page=reslab-activity-log&filter_action=login_failed' );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Possible brute-force attack detected', 'reslab-activity-log' ),
			$site_name
		);

		$message = sprintf(
			/* translators: 1: IP address, 2: number of attempts, 3: hours, 4: log URL */
			__( "Reslab Activity Log has detected a possible brute-force attack.\n\nIP address: %1\$s\nFailed login attempts: %2\$d in the last %3\$d hour(s)\n\nView the full log:\n%4\$s", 'reslab-activity-log' ),
			$ip,
			$attempts,
			$window_hours,
			$log_url
		);

		$this->send_alert( 'bruteforce', $subject, $message, [
			'ip'           => $ip,
			'attempts'     => $attempts,
			'window_hours' => $window_hours,
			'log_url'      => $log_url,
		] );
	}

	// -------------------------------------------------------------------------
	// Shared alert dispatch
	// -------------------------------------------------------------------------

	/**
	 * Central alert dispatch for every anomaly check (brute-force, mass
	 * deletion, ...): emails the admin, fires a generic action hook for
	 * custom integrations, and — if a webhook URL is configured — POSTs a
	 * JSON payload to it. A plain webhook POST covers Slack/Discord
	 * incoming webhooks and most no-code automation tools (Zapier, Make,
	 * n8n) without the plugin needing service-specific SDKs or credentials.
	 *
	 * @param string               $type    Short machine name, e.g. 'bruteforce', 'mass_deletion'.
	 * @param string               $subject Email subject (already translated/formatted).
	 * @param string               $message Email body (already translated/formatted).
	 * @param array<string, mixed> $payload Structured data for the webhook/action hook.
	 */
	private function send_alert( string $type, string $subject, string $message, array $payload ): void {
		wp_mail( get_option( 'admin_email' ), $subject, $message );

		/**
		 * Fires after an alert email is sent. Use this for integrations that
		 * need more than a generic webhook POST (Telegram bot API, a Slack
		 * SDK with a signed request, writing to another system, etc.).
		 *
		 * @param array<string, mixed> $payload
		 */
		do_action( "reslab_al_alert_{$type}", $payload );

		$webhook_url = get_option( 'reslab_al_alert_webhook_url', '' );
		if ( $webhook_url === '' ) {
			return;
		}

		wp_remote_post( $webhook_url, [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( array_merge( [
				'type'     => $type,
				'site'     => get_option( 'blogname' ),
				'site_url' => home_url(),
				'message'  => $message,
			], $payload ) ),
		] );
	}
}
