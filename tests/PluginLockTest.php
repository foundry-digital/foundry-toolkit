<?php
/**
 * The plugin lock (1.12.0): on every site but a local one, nobody can add,
 * upload, delete or edit plugins and themes in wp-admin, while every kind of
 * update keeps working (James, 2026-10-17, after a stolen admin login
 * uploaded a file manager plugin to Sydney Contemporary). No account is
 * exempt: to add a plugin, James turns the lock off in wp-config.php.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Foundry_Toolkit_Plugin_Lock
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginLockTest extends TestCase {

	private const ADMIN   = 1;
	private const TOOLKIT = 'foundry-toolkit/foundry-toolkit.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'plugin_basename' )->justReturn( self::TOOLKIT );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		require_once __DIR__ . '/../includes/class-foundry-toolkit-plugin-lock.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * What WordPress would get back from map_meta_cap for one check.
	 *
	 * @param string            $cap  Capability.
	 * @param array<int, mixed> $args Arguments, such as the plugin file.
	 * @return string[]
	 */
	private function caps( string $cap, array $args = array() ): array {
		return Foundry_Toolkit_Plugin_Lock::map_meta_cap( array( $cap ), $cap, self::ADMIN, $args );
	}

	public function test_only_adding_and_editing_code_is_refused(): void {
		$tests = array(
			// Refused for every administrator.
			array( 'install_plugins', array(), false ),
			array( 'upload_plugins', array(), false ),
			array( 'delete_plugins', array(), false ),
			array( 'edit_plugins', array(), false ),
			array( 'install_themes', array(), false ),
			array( 'upload_themes', array(), false ),
			array( 'delete_themes', array(), false ),
			array( 'edit_themes', array(), false ),
			array( 'deactivate_plugin', array( self::TOOLKIT ), false ),
			// Updates and everything else still work (James, 2026-10-17).
			array( 'update_plugins', array(), true ),
			array( 'update_themes', array(), true ),
			array( 'update_core', array(), true ),
			array( 'update_languages', array(), true ),
			array( 'activate_plugins', array(), true ),
			array( 'activate_plugin', array( 'akismet/akismet.php' ), true ),
			array( 'deactivate_plugin', array( 'akismet/akismet.php' ), true ),
			array( 'switch_themes', array(), true ),
			array( 'edit_users', array(), true ),
			array( 'edit_posts', array(), true ),
		);
		foreach ( $tests as list( $cap, $args, $allowed ) ) {
			$this->assertSame(
				$allowed ? array( $cap ) : array( 'do_not_allow' ),
				$this->caps( $cap, $args ),
				$cap . ( $args ? ' ' . $args[0] : '' )
			);
		}
	}

	public function test_the_toolkit_has_no_deactivate_link(): void {
		$links = array(
			'deactivate' => '<a>Deactivate</a>',
			'settings'   => '<a>Settings</a>',
		);
		$this->assertSame( array( 'settings' => '<a>Settings</a>' ), Foundry_Toolkit_Plugin_Lock::action_links( $links ) );
	}

	/**
	 * The switch: on by default, off on a local site, and off when
	 * wp-config.php says so.
	 */
	public function test_hardening_boots_the_lock_unless_local(): void {
		Foundry_Toolkit_Hardening::boot();
		$this->assertTrue( FDHARDEN_LOCK_PLUGINS );
		$this->assertNotFalse( has_filter( 'map_meta_cap', array( Foundry_Toolkit_Plugin_Lock::class, 'map_meta_cap' ) ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_' . self::TOOLKIT, array( Foundry_Toolkit_Plugin_Lock::class, 'action_links' ) ) );
		$this->assertNotFalse( has_filter( 'network_admin_plugin_action_links_' . self::TOOLKIT, array( Foundry_Toolkit_Plugin_Lock::class, 'action_links' ) ) );
	}

	public function test_a_local_site_is_not_locked(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Foundry_Toolkit_Hardening::boot();
		$this->assertFalse( FDHARDEN_LOCK_PLUGINS );
		$this->assertFalse( has_filter( 'map_meta_cap', array( Foundry_Toolkit_Plugin_Lock::class, 'map_meta_cap' ) ) );
	}

	public function test_wp_config_can_turn_it_off(): void {
		define( 'FDHARDEN_LOCK_PLUGINS', false );
		Foundry_Toolkit_Hardening::boot();
		$this->assertFalse( has_filter( 'map_meta_cap', array( Foundry_Toolkit_Plugin_Lock::class, 'map_meta_cap' ) ) );
	}
}
