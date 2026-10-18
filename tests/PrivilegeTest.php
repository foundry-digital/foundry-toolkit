<?php
/**
 * Who counts as privileged (ticket 071): never a portal role, shop customer
 * or member, whatever the role is called.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Foundry_Toolkit_Privilege
 */
final class PrivilegeTest extends TestCase {

	/** @var bool */
	private $multisite = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$caps = sm_test_role_caps();
		Functions\when( 'get_role' )->alias(
			static function ( string $role ) use ( $caps ) {
				return isset( $caps[ $role ] ) ? new WP_Role( $role, $caps[ $role ] ) : null;
			}
		);
		Functions\when( 'user_can' )->alias(
			static function ( $user, string $cap ) use ( $caps ): bool {
				foreach ( (array) $user->roles as $role ) {
					if ( ! empty( $caps[ $role ][ $cap ] ) ) {
						return true;
					}
				}
				return false;
			}
		);
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'is_super_admin' )->alias( fn( int $id ) => 7 === $id );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public function roles(): array {
		return array(
			'administrator'                   => array( 'administrator', true ),
			'editor'                          => array( 'editor', true ),
			'shop manager'                    => array( 'shop_manager', true ),
			'custom role with manage_options' => array( 'site_boss', true ),
			'author'                          => array( 'author', false ),
			'subscriber'                      => array( 'subscriber', false ),
			'shop customer'                   => array( 'customer', false ),
			'AMA exhibitor'                   => array( 'exhibitor', false ),
			'AMA gallery'                     => array( 'gallery', false ),
			'AMA VIP'                         => array( 'vip', false ),
			'AMA partner'                     => array( 'partner', false ),
			'AMA art pass'                    => array( 'art-pass', false ),
			'a role that does not exist'      => array( 'nonsense', false ),
		);
	}

	/**
	 * @dataProvider roles
	 */
	public function test_which_roles_are_held( string $role, bool $held ): void {
		$this->assertSame( $held, Foundry_Toolkit_Privilege::role_is_held( $role ) );
		$this->assertSame( $held, Foundry_Toolkit_Privilege::is_held_user( new WP_User( 3, 'u', 'u@example.test', array( $role ) ) ) );
	}

	public function test_a_super_admin_is_held_only_on_multisite(): void {
		$kim = new WP_User( 7, 'kim', 'kim@example.test', array() );
		$this->assertFalse( Foundry_Toolkit_Privilege::is_held_user( $kim ) );
		$this->multisite = true;
		$this->assertTrue( Foundry_Toolkit_Privilege::is_held_user( $kim ) );
	}

	public function test_privileged_ids_are_asked_for_by_capability_and_sorted(): void {
		$asked = array();
		Functions\when( 'get_users' )->alias(
			static function ( array $args ) use ( &$asked ): array {
				$asked = $args;
				return array( '12', '3', 12, '40' );
			}
		);
		$this->assertSame( array( 3, 12, 40 ), Foundry_Toolkit_Privilege::privileged_user_ids() );
		$this->assertSame( Foundry_Toolkit_Privilege::ADMIN_CAPS, $asked['capability__in'] );
		$this->assertSame( 'ID', $asked['fields'] );
	}

	public function test_super_admins_count_as_privileged_on_multisite(): void {
		$this->multisite = true;
		Functions\when( 'get_users' )->justReturn( array( 3 ) );
		Functions\when( 'get_super_admins' )->justReturn( array( 'kim', 'gone' ) );
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, string $login ) {
				return 'kim' === $login ? new WP_User( 7, 'kim', 'kim@example.test', array() ) : false;
			}
		);
		$this->assertSame( array( 3, 7 ), Foundry_Toolkit_Privilege::privileged_user_ids() );
	}

	public function test_no_capability_the_plugin_lock_refuses_is_used(): void {
		require_once __DIR__ . '/../includes/class-foundry-toolkit-plugin-lock.php';
		// The lock maps these to do_not_allow for everyone, so asking for
		// them would hide every administrator from the count.
		$this->assertSame( array(), array_intersect( Foundry_Toolkit_Privilege::ADMIN_CAPS, Foundry_Toolkit_Plugin_Lock::LOCKED ) );
		$this->assertSame( array(), array_intersect( Foundry_Toolkit_Privilege::HELD_CAPS, Foundry_Toolkit_Plugin_Lock::LOCKED ) );
	}
}
