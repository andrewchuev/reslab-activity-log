<?php
/**
 * Shared base test case: small DB helpers reused by every test class, all
 * built around the plugin's real activity-log table (not mocks).
 */
abstract class Reslab_AL_TestCase extends WP_UnitTestCase {

	/**
	 * @return object[]
	 */
	protected function get_log_rows( string $order_by = 'id ASC' ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . reslab_al_table() . ' ORDER BY ' . $order_by );
	}

	protected function get_last_log_row(): ?object {
		$rows = $this->get_log_rows( 'id DESC' );
		return $rows[0] ?? null;
	}

	/**
	 * @return object[] Rows matching $action, oldest first.
	 */
	protected function get_log_rows_for_action( string $action ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . reslab_al_table() . ' WHERE action = %s ORDER BY id ASC',
			$action
		) );
	}

	protected function count_log_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . reslab_al_table() );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	protected function insert_log_row( array $overrides = [] ): int {
		global $wpdb;

		$defaults = [
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'user_id'     => 0,
			'ip_address'  => '',
			'action'      => 'test_action',
			'object_type' => 'test',
			'object_id'   => 0,
			'context'     => '',
			'request_id'  => '',
		];

		$wpdb->insert( reslab_al_table(), array_merge( $defaults, $overrides ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Invokes a private/protected method for classes that intentionally don't
	 * expose pure logic (grouping, IP anonymization, client IP resolution)
	 * publicly — reflection is preferred here over widening visibility just
	 * for tests.
	 *
	 * @param array<int, mixed> $args
	 */
	protected function call_private_method( object $object, string $method, array $args = [] ): mixed {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $object, $args );
	}

	/**
	 * Builds an instance without running its constructor — needed for
	 * classes like Reslab_AL_Tracker whose constructor has the side effect of
	 * registering real WordPress hooks; a second real instance would double
	 * every hook callback for the rest of the test run.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	protected function instantiate_without_constructor( string $class ): object {
		return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}
}
