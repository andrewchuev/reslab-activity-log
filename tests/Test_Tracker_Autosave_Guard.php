<?php
/**
 * Covers the DOING_AUTOSAVE guard in Reslab_AL_Tracker::on_post_updated().
 * The actual check runs in tests/isolated/check-autosave-guard.php, as its
 * own PHP process — see that file for why.
 */
final class Test_Tracker_Autosave_Guard extends Reslab_AL_TestCase {

	public function test_autosave_updates_are_not_logged(): void {
		$php    = defined( 'PHP_BINARY' ) && PHP_BINARY !== '' ? PHP_BINARY : 'php';
		$script = __DIR__ . '/isolated/check-autosave-guard.php';

		$output     = [];
		$exit_code  = null;
		exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $exit_code );

		$this->assertSame( 0, $exit_code, "Autosave guard check failed:\n" . implode( "\n", $output ) );
	}
}
