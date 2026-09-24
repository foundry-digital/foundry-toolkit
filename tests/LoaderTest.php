<?php
/**
 * The must-use loader (ADR 0025).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** @covers Foundry_Toolkit_Loader */
final class LoaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::clean();
	}

	protected function tearDown(): void {
		self::clean();
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function clean(): void {
		foreach ( glob( WPMU_PLUGIN_DIR . '/{,.}*', GLOB_BRACE ) ?: array() as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
	}

	public function test_install_writes_a_current_loader(): void {
		$this->assertFalse( Foundry_Toolkit_Loader::is_current(), 'nothing there yet' );
		$this->assertTrue( Foundry_Toolkit_Loader::install() );
		$this->assertFileExists( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php' );
		$this->assertTrue( Foundry_Toolkit_Loader::is_current() );
		$body = (string) file_get_contents( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php' );
		$this->assertStringContainsString( "'foundry-toolkit/foundry-toolkit.php'", $body );
		$this->assertStringContainsString( Foundry_Toolkit_Loader::MARKER, $body );
		$this->assertSame( array( '0-foundry-toolkit.php' ), array_map( 'basename', glob( WPMU_PLUGIN_DIR . '/{,.}*.php', GLOB_BRACE ) ?: array() ), 'no temporary file left behind' );
	}

	public function test_loader_is_valid_php(): void {
		Foundry_Toolkit_Loader::install();
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php' ) . ' 2>&1', $out, $code );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
	}

	public function test_repair_rewrites_a_changed_or_missing_loader(): void {
		Foundry_Toolkit_Loader::install();
		file_put_contents( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php', "<?php\n// " . Foundry_Toolkit_Loader::MARKER . ", old version\n" );
		$this->assertFalse( Foundry_Toolkit_Loader::is_current() );
		Foundry_Toolkit_Loader::repair();
		$this->assertTrue( Foundry_Toolkit_Loader::is_current(), 'changed loader rewritten' );

		unlink( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php' );
		Foundry_Toolkit_Loader::repair();
		$this->assertTrue( Foundry_Toolkit_Loader::is_current(), 'deleted loader rewritten' );
	}

	public function test_remove_deletes_only_our_loader(): void {
		Foundry_Toolkit_Loader::install();
		Foundry_Toolkit_Loader::remove();
		$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php' );

		file_put_contents( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php', "<?php\n// someone else's file\n" );
		Foundry_Toolkit_Loader::remove();
		$this->assertFileExists( WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php', 'a file without our marker is left alone' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loader_includes_the_plugin_only_while_active(): void {
		$main = WP_PLUGIN_DIR . '/foundry-toolkit/foundry-toolkit.php';
		if ( ! is_dir( dirname( $main ) ) ) {
			mkdir( dirname( $main ), 0755, true );
		}
		file_put_contents( $main, "<?php\n\$GLOBALS['ft_loaded'] = ( \$GLOBALS['ft_loaded'] ?? 0 ) + 1;\n" );
		Foundry_Toolkit_Loader::install();
		Functions\when( 'is_multisite' )->justReturn( false );

		$active = array();
		Functions\when( 'get_option' )->alias(
			function ( string $name ) use ( &$active ) {
				return 'active_plugins' === $name ? $active : false;
			}
		);
		include WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php';
		$this->assertArrayNotHasKey( 'ft_loaded', $GLOBALS, 'inactive: nothing loaded' );

		$active = array( 'akismet/akismet.php', 'foundry-toolkit/foundry-toolkit.php' );
		include WPMU_PLUGIN_DIR . '/0-foundry-toolkit.php';
		$this->assertSame( 1, $GLOBALS['ft_loaded'] ?? 0, 'active: loaded once' );
		unlink( $main );
	}
}
