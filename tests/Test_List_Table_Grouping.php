<?php
/**
 * Covers Reslab_AL_List_Table::group_by_request(): collapsing rows that
 * share a request_id into one "headline" row via GROUP_PRIORITY, keeping the
 * rest as ->grouped_events.
 */
final class Test_List_Table_Grouping extends Reslab_AL_TestCase {

	private function row( int $id, string $action, string $request_id ): object {
		return (object) [
			'id'          => $id,
			'action'      => $action,
			'request_id'  => $request_id,
			'object_type' => 'post',
			'object_id'   => 1,
			'context'     => '',
		];
	}

	private function group( array $items ): array {
		$table = $this->instantiate_without_constructor( Reslab_AL_List_Table::class );
		return $this->call_private_method( $table, 'group_by_request', [ $items ] );
	}

	public function test_rows_with_distinct_request_ids_are_not_grouped(): void {
		$items = [ $this->row( 1, 'updated', 'req-a' ), $this->row( 2, 'deleted', 'req-b' ) ];

		$grouped = $this->group( $items );

		$this->assertCount( 2, $grouped );
		$this->assertSame( [], $grouped[0]->grouped_events );
		$this->assertSame( [], $grouped[1]->grouped_events );
	}

	public function test_higher_priority_action_becomes_the_headline_row(): void {
		// 'language_assigned' has no entry in GROUP_PRIORITY (priority 0);
		// 'updated' is priority 100 and must win regardless of insertion order.
		$items = [
			$this->row( 1, 'language_assigned', 'req-a' ),
			$this->row( 2, 'updated', 'req-a' ),
		];

		$grouped = $this->group( $items );

		$this->assertCount( 1, $grouped );
		$this->assertSame( 'updated', $grouped[0]->action );
		$this->assertCount( 1, $grouped[0]->grouped_events );
		$this->assertSame( 'language_assigned', $grouped[0]->grouped_events[0]->action );
	}

	public function test_equal_priority_ties_are_broken_by_highest_id(): void {
		// Both unlisted in GROUP_PRIORITY (priority 0 each); the higher id
		// (the later event within the same request) should win the tie.
		$items = [
			$this->row( 5, 'language_assigned', 'req-a' ),
			$this->row( 9, 'some_other_event', 'req-a' ),
		];

		$grouped = $this->group( $items );

		$this->assertSame( 9, $grouped[0]->id );
	}

	public function test_rows_without_a_request_id_are_never_grouped_together(): void {
		// Empty request_id falls back to a unique per-row bucket key
		// ('row_' . id), so these must stay as two separate rows even though
		// their (empty) request_id values are identical.
		$items = [ $this->row( 1, 'updated', '' ), $this->row( 2, 'updated', '' ) ];

		$grouped = $this->group( $items );

		$this->assertCount( 2, $grouped );
	}

	public function test_output_order_follows_first_appearance_of_each_request_id(): void {
		$items = [
			$this->row( 1, 'updated', 'req-a' ),
			$this->row( 2, 'updated', 'req-b' ),
			$this->row( 3, 'deleted', 'req-a' ), // same request as the first row
		];

		$grouped = $this->group( $items );

		$this->assertCount( 2, $grouped );
		$this->assertSame( 'req-a', $grouped[0]->request_id );
		$this->assertSame( 'req-b', $grouped[1]->request_id );
	}
}
