<?php
/**
 * Covers Reslab_AL_Admin::csv_safe(): neutralizing values spreadsheet apps
 * would interpret as formulas (CSV/Formula injection protection).
 */
final class Test_Csv_Safe extends Reslab_AL_TestCase {

	/**
	 * @dataProvider dangerous_value_provider
	 */
	public function test_formula_leading_characters_are_neutralized( string $input ): void {
		$this->assertSame( "'" . $input, Reslab_AL_Admin::csv_safe( $input ) );
	}

	public static function dangerous_value_provider(): array {
		return [
			'equals formula'    => [ "=cmd|'/c calc'!A0" ],
			'plus formula'      => [ '+1+1' ],
			'minus formula'     => [ '-1+1' ],
			'at formula'        => [ '@SUM(A1:A2)' ],
			'leading tab'       => [ "\t=malicious" ],
			'leading CR'        => [ "\rmalicious" ],
		];
	}

	/**
	 * @dataProvider safe_value_provider
	 */
	public function test_ordinary_values_are_returned_unchanged( string $input ): void {
		$this->assertSame( $input, Reslab_AL_Admin::csv_safe( $input ) );
	}

	public static function safe_value_provider(): array {
		return [
			'plain word'          => [ 'admin' ],
			'email'               => [ 'user@example.com' ],
			'sentence'            => [ 'Hello, world!' ],
			'number as string'    => [ '12345' ],
			'empty string'        => [ '' ],
			'equals not at start' => [ 'a=b' ],
		];
	}

	public function test_non_string_scalars_are_cast_before_checking(): void {
		$this->assertSame( '42', Reslab_AL_Admin::csv_safe( 42 ) );
		$this->assertSame( '0', Reslab_AL_Admin::csv_safe( 0 ) );
	}
}
