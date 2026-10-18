<?php
/**
 * Booting the toolkit, and stepping aside for old must-use copies.
 *
 * Each test runs in its own process: booting defines constants such as
 * DISALLOW_FILE_EDIT that would leak into the report tests.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Foundry_Toolkit
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BootTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'plugin_basename' )->justReturn( 'foundry-toolkit/foundry-toolkit.php' );
		$found = glob( WPMU_PLUGIN_DIR . '/*.php' );
		foreach ( false === $found ? array() : $found as $f ) {
			unlink( $f );
		}
	}

	protected function tearDown(): void {
		$found = glob( WPMU_PLUGIN_DIR . '/*.php' );
		foreach ( false === $found ? array() : $found as $f ) {
			unlink( $f );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function old_file( string $name ): void {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0755, true );
		}
		file_put_contents( WPMU_PLUGIN_DIR . '/' . $name, "<?php\n" );
	}

	public function test_fresh_site_runs_both_modules(): void {
		Foundry_Toolkit::boot();
		$this->assertTrue( Foundry_Toolkit::$agent );
		$this->assertTrue( Foundry_Toolkit::$hardening );
		$this->assertSame( array(), Foundry_Toolkit::$leftovers );
		$this->assertTrue( Foundry_Toolkit_Hardening::$booted );
		$this->assertTrue( DISALLOW_FILE_EDIT );
		$this->assertNotFalse( has_action( 'admin_init', array( 'Foundry_Toolkit_Loader', 'repair' ) ) );
	}

	public function test_old_hardening_file_wins_until_deleted(): void {
		self::old_file( 'foundry-hardening.php' );
		Foundry_Toolkit::boot();
		$this->assertFalse( Foundry_Toolkit::$hardening, 'hardening must not run twice' );
		$this->assertFalse( Foundry_Toolkit_Hardening::$booted );
		$this->assertTrue( Foundry_Toolkit::$agent );
		$this->assertSame( array( 'foundry-hardening.php' ), Foundry_Toolkit::$leftovers );
		$fields = Foundry_Toolkit::report_fields();
		$this->assertFalse( $fields['hardening'] );
	}

	/**
	 * Ticket 074, M3: since 1.16.0 the toolkit never defines FDHARDEN_IS_LOCAL,
	 * so a line left in wp-config.php from the 1.x days must not switch off
	 * every lock and the 2FA hold. The old must-use copy is found by its file.
	 */
	public function test_an_old_constant_alone_switches_nothing_off(): void {
		define( 'FDHARDEN_IS_LOCAL', true );
		Foundry_Toolkit::boot();
		$this->assertTrue( Foundry_Toolkit::$hardening );
		$this->assertTrue( Foundry_Toolkit_Hardening::$booted );
		$this->assertTrue( Foundry_Toolkit_Two_Factor::$booted );
		$this->assertSame( array(), Foundry_Toolkit::$leftovers );
	}

	public function test_old_agent_file_wins_until_deleted(): void {
		self::old_file( 'foundry-sitemanager.php' );
		Foundry_Toolkit::boot();
		$this->assertFalse( Foundry_Toolkit::$agent, 'two agents must never answer' );
		$this->assertTrue( Foundry_Toolkit::$hardening );
		$this->assertSame( array( 'foundry-sitemanager.php' ), Foundry_Toolkit::$leftovers );
	}

	public function test_notice_names_each_leftover(): void {
		self::old_file( 'foundry-hardening.php' );
		Foundry_Toolkit::boot();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		ob_start();
		Foundry_Toolkit::notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'mu-plugins/foundry-hardening.php', $html );
		$this->assertStringContainsString( 'could not write its loader', $html, 'no loader written in this test' );
	}
}
