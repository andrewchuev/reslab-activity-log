<?php
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercepts WordPress hooks and writes events to the activity log table.
 */
class Reslab_AL_Tracker {

	/**
	 * wp_options keys that are worth logging; everything else is noise.
	 */
	private const TRACKED_OPTIONS = [
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'users_can_register',
		'default_role',
		'timezone_string',
		'date_format',
		'time_format',
		'permalink_structure',
		'woocommerce_currency',
		'woocommerce_default_country',
	];

	public function __construct() {
		$this->register_hooks();
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	private function register_hooks(): void {
		// Auth.
		add_action( 'wp_login',        [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_logout',       [ $this, 'on_logout' ], 10, 1 );
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ] );

		// Content.
		add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );
		add_action( 'post_updated',           [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'deleted_post',           [ $this, 'on_post_deleted' ], 10, 2 );

		// Plugins & themes.
		add_action( 'activated_plugin',          [ $this, 'on_plugin_activated' ] );
		add_action( 'deactivated_plugin',        [ $this, 'on_plugin_deactivated' ] );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'switch_theme',              [ $this, 'on_theme_switched' ], 10, 2 );

		// Users.
		add_action( 'user_register',  [ $this, 'on_user_registered' ] );
		add_action( 'profile_update', [ $this, 'on_profile_updated' ], 10, 2 );
		add_action( 'deleted_user',   [ $this, 'on_user_deleted' ], 10, 3 );

		// Settings.
		add_action( 'update_option',      [ $this, 'on_option_updated' ], 10, 3 );
		add_action( 'wp_update_nav_menu', [ $this, 'on_nav_menu_updated' ] );

		// Polylang — only when active.
		if ( function_exists( 'pll_languages_list' ) ) {
			add_action( 'pll_save_post', [ $this, 'on_pll_save_post' ], 10, 3 );
		}
	}

	// -------------------------------------------------------------------------
	// Auth handlers
	// -------------------------------------------------------------------------

	public function on_login( string $user_login, WP_User $user ): void {
		// $user->ID must be passed explicitly as the acting user: 'wp_login'
		// fires from wp_signon() right after wp_set_auth_cookie(), before
		// wp_set_current_user() ever runs for this request (that only
		// happens on the *next* page load, once the auth cookie is read).
		// get_current_user_id() is therefore still 0 here, and log()'s
		// fallback to it would wrongly attribute every login to "Guest".
		$this->log( 'logged_in', 'user', $user->ID, [
			'login' => $user_login,
		], $user->ID );
	}

	public function on_logout( int $user_id = 0 ): void {
		// $user_id must be passed explicitly as the acting user: wp_logout()
		// calls wp_set_current_user( 0 ) *before* firing this hook, so
		// get_current_user_id() is already 0 here regardless of who logged
		// out — log()'s fallback to it would wrongly attribute the event to
		// "Guest" instead of the user who logged out.
		$this->log( 'logged_out', 'user', $user_id, [], $user_id );
	}

	public function on_login_failed( string $username ): void {
		$this->log( 'login_failed', 'user', 0, [
			'attempted_login' => $username,
		], 0 );
	}

	// -------------------------------------------------------------------------
	// Content handlers
	// -------------------------------------------------------------------------

	public function on_post_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! $this->is_trackable_post_type( $post->post_type ) ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		// 'new' -> 'auto-draft' is WordPress reserving a post ID before the
		// user has even opened the editor — not an audit-worthy event. But
		// 'auto-draft' -> anything else (e.g. 'publish') is a real one: it's
		// exactly what happens when a post is published without an
		// intermediate "Save Draft" click (the common Gutenberg flow), and
		// skipping it here would silently drop every such publish/draft/etc.
		if ( $new_status === 'auto-draft' ) {
			return;
		}

		$action_map = [
			'publish' => 'published',
			'draft'   => 'drafted',
			'pending' => 'set_pending',
			'private' => 'set_private',
			'trash'   => 'trashed',
			'future'  => 'scheduled',
		];

		$action = $action_map[ $new_status ] ?? "status_changed_to_{$new_status}";

		$this->log( $action, 'post', $post->ID, [
			'post_type'  => $post->post_type,
			'post_title' => $post->post_title,
			'old_status' => $old_status,
			'new_status' => $new_status,
		] );
	}

	/**
	 * Fires on any post save where status didn't change (slug, title, content edits).
	 * transition_post_status handles status changes; we only log real field changes.
	 */
	public function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		// Skip autosaves — Gutenberg triggers one every 60 seconds, which
		// would otherwise flood the log with saves the user never made.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $this->is_trackable_post_type( $post_after->post_type ) ) {
			return;
		}
		if ( $post_after->post_status !== $post_before->post_status ) {
			return;
		}

		$changed = [];

		if ( $post_after->post_name !== $post_before->post_name ) {
			$changed['slug'] = [ 'from' => $post_before->post_name, 'to' => $post_after->post_name ];
		}
		if ( $post_after->post_title !== $post_before->post_title ) {
			$changed['title'] = [ 'from' => $post_before->post_title, 'to' => $post_after->post_title ];
		}
		if ( $post_after->post_content !== $post_before->post_content ) {
			$changed['content'] = true;
		}
		if ( $post_after->post_excerpt !== $post_before->post_excerpt ) {
			$changed['excerpt'] = true;
		}

		if ( empty( $changed ) ) {
			return;
		}

		$this->log( 'updated', 'post', $post_id, [
			'post_type'  => $post_after->post_type,
			'post_title' => $post_after->post_title,
			'changed'    => $changed,
		] );
	}

	public function on_post_deleted( int $post_id, WP_Post $post ): void {
		if ( ! $this->is_trackable_post_type( $post->post_type ) ) {
			return;
		}
		$this->log( 'deleted', 'post', $post_id, [
			'post_type'  => $post->post_type,
			'post_title' => $post->post_title,
		] );
	}

	/**
	 * Post types the generic content tracker should stay out of: WordPress-
	 * internal types, and WooCommerce orders/refunds — those get proper,
	 * HPOS-aware tracking via Reslab_AL_Tracker_WooCommerce instead. Without
	 * this, every order transition was logged twice (once correctly as an
	 * "order", once again as a generic "post" mislabeled "(deleted)" because
	 * HPOS orders don't live in wp_posts).
	 */
	private function is_trackable_post_type( string $post_type ): bool {
		static $excluded = null;

		if ( $excluded === null ) {
			$excluded = [ 'revision', 'nav_menu_item', 'customize_changeset' ];
			if ( function_exists( 'wc_get_order_types' ) ) {
				$excluded = array_merge( $excluded, wc_get_order_types() );
			}
			$excluded = array_unique( $excluded );
		}

		return ! in_array( $post_type, $excluded, true );
	}

	// -------------------------------------------------------------------------
	// Plugin & theme handlers
	// -------------------------------------------------------------------------

	public function on_plugin_activated( string $plugin ): void {
		$this->log( 'activated', 'plugin', 0, [ 'plugin' => $plugin ] );
	}

	public function on_plugin_deactivated( string $plugin ): void {
		$this->log( 'deactivated', 'plugin', 0, [ 'plugin' => $plugin ] );
	}

	public function on_upgrader_complete( WP_Upgrader $upgrader, array $hook_extra ): void {
		$type   = $hook_extra['type']   ?? '';
		$action = $hook_extra['action'] ?? '';

		if ( ! in_array( $action, [ 'install', 'update' ], true ) ) {
			return;
		}

		$object_type = match ( $type ) {
			'plugin' => 'plugin',
			'theme'  => 'theme',
			default  => 'core',
		};

		$context = [ 'action' => $action, 'type' => $type ];

		if ( $type === 'plugin' && ! empty( $hook_extra['plugins'] ) ) {
			$context['plugins'] = $hook_extra['plugins'];
		} elseif ( $type === 'theme' && ! empty( $hook_extra['themes'] ) ) {
			$context['themes'] = $hook_extra['themes'];
		}

		$this->log( $action === 'install' ? 'installed' : 'updated', $object_type, 0, $context );
	}

	public function on_theme_switched( string $new_name, WP_Theme $new_theme ): void {
		$this->log( 'switched', 'theme', 0, [
			'new_theme'  => $new_name,
			'stylesheet' => $new_theme->get_stylesheet(),
		] );
	}

	// -------------------------------------------------------------------------
	// User handlers
	// -------------------------------------------------------------------------

	public function on_user_registered( int $user_id ): void {
		$user = get_userdata( $user_id );
		$this->log( 'registered', 'user', $user_id, [
			'login' => $user?->user_login ?? '',
			// GDPR: store a hash rather than the plaintext email — enough to
			// correlate events without keeping personal data in the log.
			'email_hash' => $user ? hash( 'sha256', $user->user_email ) : '',
		] );
	}

	public function on_profile_updated( int $user_id, WP_User $old_user_data ): void {
		$new_user = get_userdata( $user_id );
		if ( ! $new_user ) {
			return;
		}

		$watched = [ 'user_email', 'display_name', 'user_url', 'user_nicename', 'user_pass' ];
		$changed = [];

		foreach ( $watched as $field ) {
			$old_val = $old_user_data->$field ?? '';
			$new_val = $new_user->$field ?? '';
			if ( $old_val !== $new_val ) {
				$changed[ $field ] = $field === 'user_pass'
					? '(password changed)'
					: [ 'from' => $old_val, 'to' => $new_val ];
			}
		}

		// Track role changes — important for sites with custom roles, where
		// a role change can silently grant or revoke significant capabilities.
		$old_roles = $old_user_data->roles ?? [];
		$new_roles = $new_user->roles ?? [];
		if ( $old_roles !== $new_roles ) {
			$changed['roles'] = [
				'from' => implode( ', ', $old_roles ),
				'to'   => implode( ', ', $new_roles ),
			];
		}

		if ( empty( $changed ) ) {
			return;
		}

		$this->log( 'profile_updated', 'user', $user_id, [ 'changed' => $changed ] );
	}

	/**
	 * $user is the WP_User object WordPress itself passes here (since 5.5.0)
	 * — it must be used instead of get_userdata( $user_id ): 'deleted_user'
	 * fires after the user row is already gone from wp_users, so re-fetching
	 * by ID would always return false and log an empty login.
	 */
	public function on_user_deleted( int $user_id, ?int $reassign, WP_User $user ): void {
		$this->log( 'deleted', 'user', $user_id, [
			'login' => $user->user_login,
		] );
	}

	// -------------------------------------------------------------------------
	// Settings handlers
	// -------------------------------------------------------------------------

	public function on_option_updated( string $option, mixed $old_value, mixed $new_value ): void {
		if ( ! in_array( $option, self::TRACKED_OPTIONS, true ) ) {
			return;
		}
		if ( $old_value === $new_value ) {
			return;
		}
		$this->log( 'updated', 'option', 0, [
			'option'    => $option,
			'old_value' => is_scalar( $old_value ) ? $old_value : wp_json_encode( $old_value ),
			'new_value' => is_scalar( $new_value ) ? $new_value : wp_json_encode( $new_value ),
		] );
	}

	public function on_nav_menu_updated( int $menu_id ): void {
		$menu = wp_get_nav_menu_object( $menu_id );
		$this->log( 'updated', 'nav_menu', $menu_id, [
			'menu_name' => $menu ? $menu->name : '',
		] );
	}

	// -------------------------------------------------------------------------
	// Core write method
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $context
	 */
	private function log(
		string $action,
		string $object_type,
		int $object_id,
		array $context = [],
		?int $user_id = null
	): void {
		global $wpdb;

		if ( $user_id === null ) {
			$user_id = get_current_user_id();
		}

		$wpdb->insert(
			reslab_al_table(),
			[
				'user_id'     => $user_id,
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

	/**
	 * X-Forwarded-For / CF-Connecting-IP headers are only trusted when
	 * REMOTE_ADDR matches a known proxy — otherwise any client could spoof
	 * its logged IP by sending that header directly. The trusted-proxy list
	 * comes from the 'reslab_al_trusted_proxies' filter or the
	 * RESLAB_AL_TRUSTED_PROXIES constant (comma-separated) in wp-config.php.
	 * Defaults to REMOTE_ADDR only.
	 */
	private function get_client_ip(): string {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

		$trusted_proxies = [];
		if ( defined( 'RESLAB_AL_TRUSTED_PROXIES' ) ) {
			$trusted_proxies = array_map( 'trim', explode( ',', RESLAB_AL_TRUSTED_PROXIES ) );
		}
		/** @var string[] $trusted_proxies */
		$trusted_proxies = apply_filters( 'reslab_al_trusted_proxies', $trusted_proxies );

		if ( ! empty( $trusted_proxies ) && in_array( $remote_addr, $trusted_proxies, true ) ) {
			// Only read forwarded headers when the direct connection is a known proxy.
			$forwarded_candidates = [
				'HTTP_CF_CONNECTING_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
			];
			foreach ( $forwarded_candidates as $key ) {
				$value = $_SERVER[ $key ] ?? '';
				if ( $value === '' ) {
					continue;
				}
				$ip = trim( explode( ',', $value )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		$ip = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
		return $this->maybe_anonymize_ip( $ip );
	}

	// -------------------------------------------------------------------------
	// Polylang handler
	// -------------------------------------------------------------------------

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param int[]   $translations Map of language_slug => post_id
	 */
	public function on_pll_save_post( int $post_id, WP_Post $post, array $translations ): void {
		if ( ! $this->is_trackable_post_type( $post->post_type ) ) {
			return;
		}
		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';
		$this->log( 'language_assigned', 'post', $post_id, [
			'language'     => $lang,
			'translations' => $translations,
		] );
	}

	// -------------------------------------------------------------------------
	// IP helpers
	// -------------------------------------------------------------------------

	private function maybe_anonymize_ip( string $ip ): string {
		if ( ! get_option( 'reslab_al_anonymize_ip', true ) ) {
			return $ip;
		}
		// IPv4: zero out the last octet.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return substr( $ip, 0, strrpos( $ip, '.' ) ) . '.0';
		}
		// IPv6: zero out the last 80 bits (last 5 groups).
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$parts = explode( ':', inet_ntop( inet_pton( $ip ) ) );
			return implode( ':', array_merge( array_slice( $parts, 0, 3 ), [ '0', '0', '0', '0', '0' ] ) );
		}
		return $ip;
	}
}
