<?php
/**
 * The plugin lock (1.12.0): nobody but Foundry's own accounts can add,
 * upload, delete or edit plugins and themes, while every kind of update
 * keeps working (James, 2026-10-17, after a stolen admin login uploaded a
 * file manager plugin to Sydney Contemporary).
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

	private const CLIENT  = 7;
	private const FOUNDRY = 1;
	private const TOOLKIT = 'foundry-toolkit/foundry-toolkit.php';

	/** @var int */
	private $current = self::CLIENT;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		define( 'FDHARDEN_LOCK_PLUGINS', true );
		define( 'FDHARDEN_LOCK_EXEMPT', ' James , foundry-support ' );
		$users = array(
			self::FOUNDRY => new WP_User( self::FOUNDRY, 'james', 'james@example.com', array( 'administrator' ) ),
			self::CLIENT  => new WP_User( self::CLIENT, 'client', 'client@example.com', array( 'administrator' ) ),
		);
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) use ( $users ) {
				return $users[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'get_current_user_id' )->alias(
			function (): int {
				return $this->current;
			}
		);
		Functions\when( 'plugin_basename' )->justReturn( self::TOOLKIT );
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
	 * @param int               $user User ID.
	 * @param array<int, mixed> $args Arguments, such as the plugin or user.
	 * @return string[]
	 */
	private function caps( string $cap, int $user, array $args = array() ): array {
		return Foundry_Toolkit_Plugin_Lock::map_meta_cap( array( $cap ), $cap, $user, $args );
	}

	public function test_only_adding_and_editing_code_is_refused(): void {
		$tests = array(
			// Refused for a client administrator.
			array( 'install_plugins', self::CLIENT, array(), false ),
			array( 'upload_plugins', self::CLIENT, array(), false ),
			array( 'delete_plugins', self::CLIENT, array(), false ),
			array( 'edit_plugins', self::CLIENT, array(), false ),
			array( 'install_themes', self::CLIENT, array(), false ),
			array( 'upload_themes', self::CLIENT, array(), false ),
			array( 'delete_themes', self::CLIENT, array(), false ),
			array( 'edit_themes', self::CLIENT, array(), false ),
			// Updates and everything else still work for them (James, 2026-10-17).
			array( 'update_plugins', self::CLIENT, array(), true ),
			array( 'update_themes', self::CLIENT, array(), true ),
			array( 'update_core', self::CLIENT, array(), true ),
			array( 'update_languages', self::CLIENT, array(), true ),
			array( 'activate_plugins', self::CLIENT, array(), true ),
			array( 'activate_plugin', self::CLIENT, array( 'akismet/akismet.php' ), true ),
			array( 'deactivate_plugin', self::CLIENT, array( 'akismet/akismet.php' ), true ),
			array( 'switch_themes', self::CLIENT, array(), true ),
			array( 'edit_posts', self::CLIENT, array(), true ),
			// Foundry's own accounts are not locked.
			array( 'install_plugins', self::FOUNDRY, array(), true ),
			array( 'upload_themes', self::FOUNDRY, array(), true ),
			array( 'edit_plugins', self::FOUNDRY, array(), true ),
		);
		foreach ( $tests as list( $cap, $user, $args, $allowed ) ) {
			$this->assertSame(
				$allowed ? array( $cap ) : array( 'do_not_allow' ),
				$this->caps( $cap, $user, $args ),
				sprintf( '%s for user %d', $cap, $user )
			);
		}
	}

	public function test_the_toolkit_cannot_be_switched_off_by_a_client(): void {
		$this->assertSame( array( 'do_not_allow' ), $this->caps( 'deactivate_plugin', self::CLIENT, array( self::TOOLKIT ) ), 'deactivating it would undo the lock' );
		$this->assertSame( array( 'deactivate_plugin' ), $this->caps( 'deactivate_plugin', self::FOUNDRY, array( self::TOOLKIT ) ) );

		$links = array(
			'deactivate' => '<a>Deactivate</a>',
			'settings'   => '<a>Settings</a>',
		);
		$this->assertSame( array( 'settings' => '<a>Settings</a>' ), Foundry_Toolkit_Plugin_Lock::action_links( $links ) );
		$this->current = self::FOUNDRY;
		$this->assertSame( $links, Foundry_Toolkit_Plugin_Lock::action_links( $links ) );
	}

	public function test_a_client_cannot_take_over_a_foundry_account(): void {
		foreach ( array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ) as $cap ) {
			$this->assertSame( array( 'do_not_allow' ), $this->caps( $cap, self::CLIENT, array( self::FOUNDRY ) ), $cap . ': a new password would get past the lock' );
			$this->assertSame( array( $cap ), $this->caps( $cap, self::CLIENT, array( self::CLIENT ) ), $cap . ' on their own account' );
			$this->assertSame( array( $cap ), $this->caps( $cap, self::FOUNDRY, array( self::FOUNDRY ) ) );
		}
		$this->assertSame( array( 'edit_user' ), $this->caps( 'edit_user', self::FOUNDRY, array( self::CLIENT ) ), 'Foundry can still edit clients' );
	}

	public function test_a_client_cannot_create_a_missing_foundry_login(): void {
		$this->assertSame( array( 'admin', 'james', 'foundry-support' ), Foundry_Toolkit_Plugin_Lock::illegal_logins( array( 'admin' ) ) );
		$this->current = 0;
		$this->assertSame( array( 'james', 'foundry-support' ), Foundry_Toolkit_Plugin_Lock::illegal_logins( array() ), 'nor a visitor registering' );
		$this->current = self::FOUNDRY;
		$this->assertSame( array( 'admin' ), Foundry_Toolkit_Plugin_Lock::illegal_logins( array( 'admin' ) ) );
	}

	public function test_hardening_boots_the_lock_when_it_is_on(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Foundry_Toolkit_Hardening::boot();
		$this->assertNotFalse( has_filter( 'map_meta_cap', array( Foundry_Toolkit_Plugin_Lock::class, 'map_meta_cap' ) ) );
	}

	public function test_boot_registers_the_filters(): void {
		Foundry_Toolkit_Plugin_Lock::boot();
		$this->assertNotFalse( has_filter( 'map_meta_cap', array( Foundry_Toolkit_Plugin_Lock::class, 'map_meta_cap' ) ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_' . self::TOOLKIT, array( Foundry_Toolkit_Plugin_Lock::class, 'action_links' ) ) );
		$this->assertNotFalse( has_filter( 'network_admin_plugin_action_links_' . self::TOOLKIT, array( Foundry_Toolkit_Plugin_Lock::class, 'action_links' ) ) );
		$this->assertNotFalse( has_filter( 'illegal_user_logins', array( Foundry_Toolkit_Plugin_Lock::class, 'illegal_logins' ) ) );
	}
}
