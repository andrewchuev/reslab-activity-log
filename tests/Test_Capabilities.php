<?php
/**
 * Covers reslab_al_add_capabilities() and the 'reslab_al_default_roles' filter.
 */
final class Test_Capabilities extends Reslab_AL_TestCase {

	private const CAPS = [ 'reslab_al_view_log', 'reslab_al_clear_log', 'reslab_al_manage_settings' ];

	public function tearDown(): void {
		// Roles are global state that WP_UnitTestCase's DB transaction
		// rollback does not cover (wp_roles() caches capabilities in memory
		// for the whole process). Blanket-stripping these caps from every
		// role would also strip administrator's — which the real bootstrap
		// install grants once, before any test runs, and every other test
		// class assumes administrator still has. Instead, explicitly restore
		// the real default (administrator only) that reslab_al_add_capabilities()
		// establishes with no filter attached.
		remove_all_filters( 'reslab_al_default_roles' );
		foreach ( wp_roles()->roles as $role_name => $data ) {
			if ( $role_name === 'administrator' ) {
				continue;
			}
			$role = get_role( $role_name );
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		reslab_al_add_capabilities();
		parent::tearDown();
	}

	public function test_administrator_gets_capabilities_by_default(): void {
		reslab_al_add_capabilities();

		$admin = get_role( 'administrator' );
		foreach ( self::CAPS as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), "administrator should have {$cap}" );
		}
	}

	public function test_other_roles_do_not_get_capabilities_by_default(): void {
		reslab_al_add_capabilities();

		$editor = get_role( 'editor' );
		foreach ( self::CAPS as $cap ) {
			$this->assertFalse( $editor->has_cap( $cap ), "editor should not have {$cap} without the filter" );
		}
	}

	public function test_default_roles_filter_extends_capability_grants(): void {
		add_filter( 'reslab_al_default_roles', static function () {
			return [ 'administrator', 'shop_manager' ];
		} );

		reslab_al_add_capabilities();

		$shop_manager = get_role( 'shop_manager' );
		$this->assertNotNull( $shop_manager, 'WooCommerce should have registered the shop_manager role.' );
		foreach ( self::CAPS as $cap ) {
			$this->assertTrue( $shop_manager->has_cap( $cap ) );
		}
	}

	/**
	 * reslab_al_add_capabilities() only ever *adds* capabilities (add_cap()),
	 * never revokes them — so a role not in the filter's list simply never
	 * gains the capability in the first place, rather than losing one it
	 * already had. 'subscriber' is used here (never granted these caps
	 * through any other path) rather than 'administrator', which the real
	 * bootstrap install already grants independently of this filter.
	 */
	public function test_default_roles_filter_only_grants_listed_roles(): void {
		add_filter( 'reslab_al_default_roles', static function () {
			return [ 'editor' ];
		} );

		reslab_al_add_capabilities();

		$this->assertTrue( get_role( 'editor' )->has_cap( 'reslab_al_view_log' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'reslab_al_view_log' ) );
	}

	public function test_unknown_role_in_filter_is_silently_ignored(): void {
		add_filter( 'reslab_al_default_roles', static function () {
			return [ 'administrator', 'this_role_does_not_exist' ];
		} );

		// Must not throw/warn when get_role() returns null for the bogus role.
		reslab_al_add_capabilities();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'reslab_al_view_log' ) );
	}
}
