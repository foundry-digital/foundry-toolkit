<?php
/**
 * The admin lock (ticket 072, ADR 0036): the wp-admin tools and settings a
 * stolen administrator login would use, and taking over administrators,
 * are refused unless wp-config.php unlocks them for a moment.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Stands in for the Customizer, whose global exists only in Customizer requests. */
final class WP_Customize_Manager {
}

/** Thrown by the wp_die stub, so a refusal stops the test like it stops WordPress. */
final class SM_Admin_Lock_Halt extends RuntimeException {
}

/**
 * @covers Foundry_Toolkit_Admin_Lock
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AdminLockTest extends TestCase {

	private const ADMIN       = 1;
	private const OTHER_ADMIN = 2;
	private const SHOP_MGR    = 3;
	private const SITE_BOSS   = 4;
	private const EDITOR      = 5;
	private const VIP         = 6;
	private const AUTHOR      = 8;
	private const SUBSCRIBER  = 9;

	/** @var int */
	private $current = self::ADMIN;

	/** @var array<int, WP_User> */
	private $users = array();

	/** @var array<int, string> */
	private $settings_errors = array();

	/** @var array<string, mixed> */
	private $options = array( 'admin_email' => 'office@example.test' );

	/** @var array<int, array<string, mixed>> */
	private $caps_meta = array();

	/** @var bool */
	private $cron = false;

	/** @var bool */
	private $installing = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'plugin_basename' )->justReturn( 'foundry-toolkit/foundry-toolkit.php' );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->current );
		Functions\when( 'get_option' )->alias( fn( string $name, $fallback = false ) => $this->options[ $name ] ?? $fallback );
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message ): void {
				$this->settings_errors[] = $message;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title = '', $args = array() ): void {
				throw new SM_Admin_Lock_Halt( ( is_array( $args ) ? (string) ( $args['response'] ?? '' ) : '' ) . ': ' . $message );
			}
		);
		Functions\when( 'get_user_meta' )->alias( fn( int $id, string $key, bool $single ) => $this->caps_meta[ $id ] ?? '' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->alias( fn() => $this->cron );
		Functions\when( 'wp_installing' )->alias( fn() => $this->installing );
		$GLOBALS['wpdb'] = new wpdb();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
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
		$roles = array(
			self::ADMIN       => 'administrator',
			self::OTHER_ADMIN => 'administrator',
			self::SHOP_MGR    => 'shop_manager',
			self::SITE_BOSS   => 'site_boss',
			self::EDITOR      => 'editor',
			self::VIP         => 'vip',
			self::AUTHOR      => 'author',
			self::SUBSCRIBER  => 'subscriber',
		);
		foreach ( $roles as $id => $role ) {
			$user               = new WP_User( $id, 'user' . $id, 'user' . $id . '@example.test', array( $role ) );
			$user->user_pass    = 'hash-' . $id;
			$this->users[ $id ] = $user;
		}
		Functions\when( 'get_userdata' )->alias( fn( int $id ) => $this->users[ $id ] ?? false );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		require_once __DIR__ . '/../includes/class-foundry-toolkit-admin-lock.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * What WordPress would get back from map_meta_cap for one check by the
	 * current user.
	 *
	 * @param string            $cap  Capability.
	 * @param array<int, mixed> $args Arguments, such as the target user.
	 * @return string[]
	 */
	private function caps( string $cap, array $args = array() ): array {
		return Foundry_Toolkit_Admin_Lock::map_meta_cap( array( $cap ), $cap, $this->current, $args );
	}

	private function refused( string $cap, array $args = array() ): bool {
		return array( 'do_not_allow' ) === $this->caps( $cap, $args );
	}

	/** Run a callable and return the wp_die message, or '' when it went through. */
	private function halt( callable $run ): string {
		try {
			$run();
		} catch ( SM_Admin_Lock_Halt $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_tools_are_refused_only_while_locked(): void {
		$tools = array( 'export', 'import', 'export_others_personal_data', 'erase_others_personal_data', 'view_site_health_checks' );
		Foundry_Toolkit_Admin_Lock::boot( true, false, false );
		foreach ( $tools as $cap ) {
			$this->assertTrue( $this->refused( $cap ), $cap . ' locked' );
		}
		Foundry_Toolkit_Admin_Lock::boot( false, true, true );
		foreach ( $tools as $cap ) {
			$this->assertFalse( $this->refused( $cap ), $cap . ' with FOUNDRY_ALLOW_TOOLS' );
		}
	}

	/** @return array<string, array{0: string, 1: array<string, string>, 2: bool}> */
	public function woocommerce_exports(): array {
		return array(
			'export page'          => array( 'admin.php', array( 'page' => 'product_exporter' ), true ),
			'export step (AJAX)'   => array( 'admin-ajax.php', array( 'action' => 'woocommerce_do_ajax_product_export' ), true ),
			'CSV download'         => array( 'edit.php', array( 'action' => 'download_product_csv' ), true ),
			'products list button' => array( 'edit.php', array( 'post_type' => 'product' ), true ),
			'core export'          => array( 'export.php', array(), false ),
			'core export, faked'   => array( 'export.php', array( 'page' => 'product_exporter' ), false ),
			'posts list'           => array( 'edit.php', array(), false ),
		);
	}

	/**
	 * WooCommerce's product CSV export checks export (ADR 0036): allowed
	 * only on its own requests, never on core's Tools, Export.
	 *
	 * @dataProvider woocommerce_exports
	 * @param string                $page    The admin page.
	 * @param array<string, string> $request The query.
	 * @param bool                  $allowed Whether the export is allowed.
	 */
	public function test_woocommerce_product_export_still_works( string $page, array $request, bool $allowed ): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$GLOBALS['pagenow'] = $page;
		$_GET               = $request;
		$_REQUEST           = $request;
		Foundry_Toolkit_Admin_Lock::boot( true, true, true );
		$this->assertSame( ! $allowed, $this->refused( 'export' ) );
		$this->assertTrue( $this->refused( 'import' ), 'import stays locked' );
	}

	public function test_settings_caps_are_refused_only_while_locked(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, true, false );
		// Additional CSS stays open everywhere, the Customizer included
		// (James, 2026-10-18: he uses it from time to time).
		$this->assertFalse( $this->refused( 'edit_css' ), 'edit_css outside the Customizer' );
		$GLOBALS['wp_customize'] = new WP_Customize_Manager();
		$this->assertFalse( $this->refused( 'edit_css' ), 'Additional CSS in the Customizer' );
		unset( $GLOBALS['wp_customize'] );
		$this->assertTrue( $this->refused( 'switch_themes' ) );
		// Menus, widgets and the Customizer itself stay.
		$this->assertFalse( $this->refused( 'edit_theme_options' ) );
		$this->assertFalse( $this->refused( 'customize' ) );
		Foundry_Toolkit_Admin_Lock::boot( true, false, true );
		$this->assertFalse( $this->refused( 'switch_themes' ) );
	}

	/** @return array<string, array{0: string, 1: mixed, 2: mixed, 3: int, 4: bool}> */
	public function option_writes(): array {
		return array(
			'site address'                => array( 'siteurl', 'https://old.test', 'https://evil.test', self::ADMIN, false ),
			'home'                        => array( 'home', 'https://old.test', 'https://evil.test', self::ADMIN, false ),
			'admin email'                 => array( 'admin_email', 'office@example.test', 'evil@example.test', self::ADMIN, false ),
			'new admin email'             => array( 'new_admin_email', '', 'evil@example.test', self::ADMIN, false ),
			'new admin email, unchanged'  => array( 'new_admin_email', 'stale@example.test', 'office@example.test', self::ADMIN, true ),
			'template'                    => array( 'template', 'astra', 'evil', self::ADMIN, false ),
			'stylesheet'                  => array( 'stylesheet', 'astra', 'evil', self::ADMIN, false ),
			'upload path'                 => array( 'upload_path', '', '/tmp', self::ADMIN, false ),
			'upload url path'             => array( 'upload_url_path', '', 'https://evil.test', self::ADMIN, false ),
			'unchanged site address'      => array( 'siteurl', 'https://old.test', 'https://old.test', self::ADMIN, true ),
			'cron, CLI or core'           => array( 'siteurl', 'https://old.test', 'https://new.test', 0, true ),
			'theme fallback with no user' => array( 'template', 'gone', 'twentytwentyfive', 0, true ),
		);
	}

	/**
	 * @dataProvider option_writes
	 * @param string $option Option name.
	 * @param mixed  $old    Stored value.
	 * @param mixed  $new    Value being saved.
	 * @param int    $user   The current user ID.
	 * @param bool   $saves  Whether the new value is kept.
	 */
	public function test_listed_options_keep_their_value_for_a_logged_in_user( string $option, $old, $new, int $user, bool $saves ): void {
		$this->current = $user;
		Foundry_Toolkit_Admin_Lock::boot( false, true, false );
		$got = Foundry_Toolkit_Admin_Lock::keep_option( $new, $old, $option );
		$this->assertSame( $saves ? $new : $old, $got );
		if ( $saves ) {
			$this->assertSame( array(), $this->settings_errors, 'no notice when nothing was refused' );
		} else {
			$this->assertCount( 1, $this->settings_errors );
			$this->assertStringContainsString( 'FOUNDRY_ALLOW_SETTINGS', $this->settings_errors[0] );
		}
	}

	public function test_the_additional_css_section_stays(): void {
		Foundry_Toolkit_Admin_Lock::boot( true, true, true );
		$this->assertFalse( has_action( 'customize_register' ) );
	}

	public function test_only_the_listed_options_are_guarded(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, true, false );
		$this->assertNotFalse( has_filter( 'pre_update_option_siteurl', array( Foundry_Toolkit_Admin_Lock::class, 'keep_option' ) ) );
		$this->assertNotFalse( has_filter( 'pre_update_option_new_admin_email', array( Foundry_Toolkit_Admin_Lock::class, 'keep_option' ) ) );
		$this->assertFalse( has_filter( 'pre_update_option_blogname', array( Foundry_Toolkit_Admin_Lock::class, 'keep_option' ) ) );
		$this->assertFalse( has_filter( 'pre_update_option_users_can_register', array( Foundry_Toolkit_Admin_Lock::class, 'keep_option' ) ) );
	}

	public function test_all_settings_is_refused_but_settings_pages_still_save(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, true, false );
		// GET wp-admin/options.php: the raw All Settings screen.
		$this->assertStringContainsString( 'FOUNDRY_ALLOW_SETTINGS', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_all_settings' ) ) );
		// POST from All Settings itself.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'option_page' => 'options' );
		$this->assertStringStartsWith( '403', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_all_settings' ) ) );
		// A POST with no option_page is core's legacy path, which saves any
		// option named in page_options: refused.
		$_POST = array( 'page_options' => 'wp_user_roles' );
		$this->assertStringStartsWith( '403', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_all_settings' ) ) );
		// option_page only in the query string, as core reads $_REQUEST.
		$_GET     = array( 'option_page' => 'general' );
		$_REQUEST = $_GET;
		$this->assertStringStartsWith( '403', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_all_settings' ) ) );
		$_GET     = array();
		$_REQUEST = array();
		// POST from Settings, General: saves (blogname is not guarded).
		$_POST    = array(
			'option_page' => 'general',
			'blogname'    => 'New title',
		);
		$_REQUEST = $_POST;
		$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_all_settings' ) ) );
	}

	/**
	 * Only James adds administrators (James, 2026-10-23, ticket 075): no
	 * administrator-level role in any role picker while
	 * FOUNDRY_ALLOW_ADMIN_CHANGES is locked. Everyone else is still offered.
	 */
	public function test_the_administrator_role_is_not_offered(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$roles = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'site_boss'     => array( 'name' => 'Site boss' ), // A custom role with manage_options.
			'editor'        => array( 'name' => 'Editor' ),
			'shop_manager'  => array( 'name' => 'Shop manager' ),
			'author'        => array( 'name' => 'Author' ),
			'subscriber'    => array( 'name' => 'Subscriber' ),
			'customer'      => array( 'name' => 'Customer' ),
			'vip'           => array( 'name' => 'VIP' ),
			'exhibitor'     => array( 'name' => 'Exhibitor' ),
		);
		$this->assertSame(
			array( 'editor', 'shop_manager', 'author', 'subscriber', 'customer', 'vip', 'exhibitor' ),
			array_keys( Foundry_Toolkit_Admin_Lock::editable_roles( $roles ) )
		);
		$this->assertNotFalse( has_filter( 'editable_roles', array( Foundry_Toolkit_Admin_Lock::class, 'editable_roles' ) ) );
	}

	/** @return array<string, array{0: string, 1: array<string, string>, 2: array<string, string>}> */
	public function admin_role_requests(): array {
		return array(
			'new user'          => array( 'user-new.php', array( 'role' => 'administrator' ), array() ),
			'profile update'    => array( 'user-edit.php', array( 'role' => 'administrator' ), array( 'user_id' => (string) self::VIP ) ),
			'bulk change role'  => array( 'users.php', array(), array( 'new_role' => 'administrator' ) ),
			'bulk, bottom bar'  => array( 'users.php', array(), array( 'new_role2' => 'administrator' ) ),
			'custom admin role' => array( 'user-new.php', array( 'role' => 'site_boss' ), array() ),
		);
	}

	/**
	 * @dataProvider admin_role_requests
	 * @param string                $page    The admin page.
	 * @param array<string, string> $post    The form.
	 * @param array<string, string> $request The query string.
	 */
	public function test_giving_the_administrator_role_is_refused( string $page, array $post, array $request ): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$_POST    = $post;
		$_GET     = $request;
		$_REQUEST = $request + $post;
		$this->assertNotFalse( has_action( 'load-' . $page, array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
		$this->assertStringContainsString( 'FOUNDRY_ALLOW_ADMIN_CHANGES', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
	}

	/** @return array<string, array{0: string}> */
	public function everyday_roles(): array {
		return array(
			'editor'       => array( 'editor' ),
			'shop manager' => array( 'shop_manager' ),
			'author'       => array( 'author' ),
			'customer'     => array( 'customer' ),
			'VIP portal'   => array( 'vip' ),
		);
	}

	/** @dataProvider everyday_roles */
	public function test_everyday_roles_can_still_be_given( string $role ): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		foreach ( array( array( 'role' => $role ), array( 'new_role' => $role ), array( 'new_role2' => $role ) ) as $form ) {
			$_POST    = $form;
			$_REQUEST = $form;
			$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ), (string) key( $form ) );
		}
		$this->assertNull( Foundry_Toolkit_Admin_Lock::refuse_admin_role_meta( null, self::VIP, 'wp_capabilities', array( $role => true ) ) );
	}

	/**
	 * WooCommerce gives shop managers edit_users only at run time, through
	 * user_has_cap, and only for customers (checked on achper.local,
	 * WooCommerce 11.2.1). The stored role is what counts, so they stay
	 * creatable.
	 */
	public function test_shop_managers_stay_creatable(): void {
		Functions\when( 'user_can' )->alias(
			static function ( $user, string $cap ): bool {
				return in_array( 'shop_manager', (array) $user->roles, true ) && in_array( $cap, array( 'edit_users', 'list_users' ), true );
			}
		);
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$this->assertArrayHasKey( 'shop_manager', Foundry_Toolkit_Admin_Lock::editable_roles( array( 'shop_manager' => array( 'name' => 'Shop manager' ) ) ) );
		$_POST    = array( 'role' => 'shop_manager' );
		$_REQUEST = $_POST;
		$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
		$this->assertNull( Foundry_Toolkit_Admin_Lock::refuse_admin_role_meta( null, self::VIP, 'wp_capabilities', array( 'shop_manager' => true ) ) );
	}

	public function test_portal_roles_and_the_admin_filter_view_go_through(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$_POST    = array( 'role' => 'vip' );
		$_GET     = array( 'user_id' => (string) self::SUBSCRIBER );
		$_REQUEST = $_GET + $_POST;
		$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
		// users.php?role=administrator only lists the administrators.
		$_POST    = array();
		$_GET     = array( 'role' => 'administrator' );
		$_REQUEST = $_GET;
		$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
	}

	public function test_another_admins_profile_says_why_it_is_locked(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$_GET     = array( 'user_id' => (string) self::OTHER_ADMIN );
		$_REQUEST = $_GET;
		$this->assertStringContainsString( 'FOUNDRY_ALLOW_ADMIN_CHANGES', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
		$_GET     = array( 'user_id' => (string) self::ADMIN ); // Themselves.
		$_REQUEST = $_GET;
		$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'refuse_user_screen' ) ) );
	}

	/** @return array<string, array{0: int, 1: bool}> */
	public function targets(): array {
		return array(
			'another administrator'             => array( self::OTHER_ADMIN, true ),
			'a shop manager'                    => array( self::SHOP_MGR, true ),
			'a custom role with manage_options' => array( self::SITE_BOSS, true ),
			'an editor'                         => array( self::EDITOR, true ),
			'themselves'                        => array( self::ADMIN, false ),
			'a VIP portal user'                 => array( self::VIP, false ),
			'an author'                         => array( self::AUTHOR, false ),
			'a subscriber'                      => array( self::SUBSCRIBER, false ),
			'a user that does not exist'        => array( 404, false ),
		);
	}

	/** @dataProvider targets */
	public function test_held_accounts_other_than_your_own_cannot_be_taken_over( int $target, bool $refused ): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		foreach ( array( 'edit_user', 'delete_user', 'remove_user', 'promote_user' ) as $cap ) {
			$this->assertSame( $refused, $this->refused( $cap, array( $target ) ), $cap );
		}
		Foundry_Toolkit_Admin_Lock::boot( true, true, false );
		$this->assertFalse( $this->refused( 'edit_user', array( $target ) ), 'with FOUNDRY_ALLOW_ADMIN_CHANGES' );
	}

	public function test_a_user_object_as_the_argument_is_not_read_as_user_1(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$this->current = self::OTHER_ADMIN;
		$this->assertFalse( $this->refused( 'edit_user', array( $this->users[ self::VIP ] ) ) );
	}

	public function test_no_administrator_level_capability_is_ever_refused(): void {
		Foundry_Toolkit_Admin_Lock::boot( true, true, true );
		foreach ( Foundry_Toolkit_Privilege::HELD_CAPS as $cap ) {
			$this->assertFalse( $this->refused( $cap ), $cap );
			$this->assertFalse( $this->refused( $cap, array( self::OTHER_ADMIN ) ), $cap . ' with a target' );
		}
	}

	/** @return array<string, array{0: int, 1: mixed, 2: mixed, 3: bool}> */
	public function role_meta_writes(): array {
		return array(
			'made administrator'            => array( self::ADMIN, array( 'vip' => true ), array( 'administrator' => true ), false ),
			'new user as administrator'     => array( self::ADMIN, '', array( 'administrator' => true ), false ),
			'already administrator'         => array( self::ADMIN, array( 'administrator' => true ), array( 'administrator' => true ), true ),
			'portal role'                   => array( self::ADMIN, array( 'subscriber' => true ), array( 'vip' => true ), true ),
			'custom admin role'             => array( self::ADMIN, array( 'vip' => true ), array( 'site_boss' => true ), false ),
			'manage_options given directly' => array(
				self::ADMIN,
				array( 'vip' => true ),
				array(
					'vip'            => true,
					'manage_options' => true,
				),
				false,
			),
			'a grant stored as false'       => array( self::ADMIN, array( 'vip' => true ), array( 'administrator' => false ), true ),
			'editor'                        => array( self::ADMIN, array( 'author' => true ), array( 'editor' => true ), true ),
			'shop manager'                  => array( self::ADMIN, '', array( 'shop_manager' => true ), true ),
			'nobody logged in, admin'       => array( 0, '', array( 'administrator' => true ), false ),
			'nobody logged in, customer'    => array( 0, '', array( 'customer' => true ), true ),
			'nobody logged in, portal role' => array( 0, '', array( 'exhibitor' => true ), true ),
		);
	}

	/**
	 * The backstop for a plugin that sets a role in its own code, a visitor's
	 * request included: a registration bug cannot make an administrator.
	 *
	 * @dataProvider role_meta_writes
	 * @param int   $user  The logged-in user, 0 for nobody.
	 * @param mixed $old   Stored capabilities.
	 * @param mixed $new   Capabilities being saved.
	 * @param bool  $saves Whether the write goes through.
	 */
	public function test_the_administrator_role_cannot_be_written_another_way( int $user, $old, $new, bool $saves ): void {
		$this->current                = $user;
		$this->caps_meta[ self::VIP ] = $old;
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$this->assertNotFalse( has_filter( 'update_user_metadata', array( Foundry_Toolkit_Admin_Lock::class, 'refuse_admin_role_meta' ) ) );
		$this->assertNotFalse( has_filter( 'add_user_metadata', array( Foundry_Toolkit_Admin_Lock::class, 'refuse_admin_role_meta' ) ) );
		$got = Foundry_Toolkit_Admin_Lock::refuse_admin_role_meta( null, self::VIP, 'wp_capabilities', $new );
		$this->assertSame( $saves ? null : false, $got );
		// Any other user meta goes through untouched.
		$this->assertNull( Foundry_Toolkit_Admin_Lock::refuse_admin_role_meta( null, self::VIP, 'nickname', array( 'administrator' => true ) ) );
	}

	/** @return array<string, array{0: string}> */
	public function trusted_contexts(): array {
		return array(
			'WP-CLI'  => array( 'cli' ),
			'cron'    => array( 'cron' ),
			'install' => array( 'install' ),
		);
	}

	/**
	 * James adds administrators with WP-CLI; cron and WordPress's own
	 * install are left alone too.
	 *
	 * @dataProvider trusted_contexts
	 */
	public function test_wp_cli_cron_and_install_can_give_administrator( string $context ): void {
		if ( 'cli' === $context ) {
			define( 'WP_CLI', true );
		}
		$this->cron       = 'cron' === $context;
		$this->installing = 'install' === $context;
		$this->current    = 'cli' === $context ? self::ADMIN : 0; // wp --user=1.
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$this->assertNull( Foundry_Toolkit_Admin_Lock::refuse_admin_role_meta( null, self::VIP, 'wp_capabilities', array( 'administrator' => true ) ) );
		$this->assertSame( $this->roles_with_admin_powers(), Foundry_Toolkit_Admin_Lock::keep_role_definitions( $this->roles_with_admin_powers(), 'wp_user_roles', sm_test_roles_option() ) );
	}

	/** @return array<string, array{name: string, capabilities: array<string, bool>}> */
	private function roles_with_admin_powers(): array {
		$roles = sm_test_roles_option();
		$roles['editor']['capabilities']['manage_options']     = true;
		$roles['editor']['capabilities']['promote_users']      = true;
		$roles['editor']['capabilities']['edit_theme_options'] = true;
		$roles['site_owner']                                   = array(
			'name'         => 'Site owner',
			'capabilities' => array(
				'read'         => true,
				'create_users' => true,
			),
		);
		return $roles;
	}

	/**
	 * Ticket 074's M2, back with ticket 075: a role editor plugin cannot turn
	 * Editor into an administrator, or make a new role with administrator
	 * powers. The role's other changes save.
	 */
	public function test_a_role_cannot_gain_administrator_level_capabilities(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$this->assertNotFalse( has_filter( 'pre_update_option', array( Foundry_Toolkit_Admin_Lock::class, 'keep_role_definitions' ) ) );
		$stored = sm_test_roles_option();
		$saved  = Foundry_Toolkit_Admin_Lock::keep_role_definitions( $this->roles_with_admin_powers(), 'wp_user_roles', $stored );
		$this->assertArrayNotHasKey( 'manage_options', $saved['editor']['capabilities'] );
		$this->assertArrayNotHasKey( 'promote_users', $saved['editor']['capabilities'] );
		$this->assertTrue( $saved['editor']['capabilities']['edit_theme_options'], 'other changes keep' );
		$this->assertSame( array( 'read' => true ), $saved['site_owner']['capabilities'], 'a new role keeps the rest' );
		$this->assertSame( $stored['administrator'], $saved['administrator'] );
		// Nobody logged in: still stripped.
		$this->current = 0;
		$saved         = Foundry_Toolkit_Admin_Lock::keep_role_definitions( $this->roles_with_admin_powers(), 'wp_user_roles', $stored );
		$this->assertArrayNotHasKey( 'manage_options', $saved['editor']['capabilities'] );
		// Another option is never touched.
		$this->assertSame( $this->roles_with_admin_powers(), Foundry_Toolkit_Admin_Lock::keep_role_definitions( $this->roles_with_admin_powers(), 'blogname', $stored ) );
	}

	public function test_woocommerce_adding_its_capabilities_saves_untouched(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$stored = sm_test_roles_option();
		$new    = $stored;
		foreach ( array( 'manage_woocommerce', 'view_woocommerce_reports', 'edit_shop_orders', 'edit_products' ) as $cap ) {
			$new['shop_manager']['capabilities'][ $cap ]  = true;
			$new['administrator']['capabilities'][ $cap ] = true;
		}
		$new['customer'] = array(
			'name'         => 'Customer',
			'capabilities' => array( 'read' => true ),
		);
		$this->assertSame( $new, Foundry_Toolkit_Admin_Lock::keep_role_definitions( $new, 'wp_user_roles', $stored ) );
	}

	/**
	 * A plugin that removes then re-adds its own role in one request, as some
	 * do on activation, is compared with the roles as first stored, so a role
	 * that already had administrator powers keeps them.
	 */
	public function test_a_role_rebuilt_in_one_request_keeps_what_it_had(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$stored = sm_test_roles_option();
		$gone   = $stored;
		unset( $gone['site_boss'] );
		$this->assertSame( $gone, Foundry_Toolkit_Admin_Lock::keep_role_definitions( $gone, 'wp_user_roles', $stored ) );
		$this->assertSame( $stored, Foundry_Toolkit_Admin_Lock::keep_role_definitions( $stored, 'wp_user_roles', $gone ) );
	}

	public function test_a_held_accounts_password_and_email_stay_when_another_user_saves_it(): void {
		Foundry_Toolkit_Admin_Lock::boot( false, false, true );
		$data = array(
			'user_pass'    => 'attacker-hash',
			'user_email'   => 'attacker@example.test',
			'display_name' => 'Renamed',
		);
		$kept = Foundry_Toolkit_Admin_Lock::keep_held_login( $data, true, self::OTHER_ADMIN );
		$this->assertSame( 'hash-' . self::OTHER_ADMIN, $kept['user_pass'] );
		$this->assertSame( 'user' . self::OTHER_ADMIN . '@example.test', $kept['user_email'] );
		$this->assertSame( 'Renamed', $kept['display_name'] );
		// Themselves, a portal user, a new user and no logged-in user: unchanged.
		$this->assertSame( $data, Foundry_Toolkit_Admin_Lock::keep_held_login( $data, true, self::ADMIN ) );
		$this->assertSame( $data, Foundry_Toolkit_Admin_Lock::keep_held_login( $data, true, self::VIP ) );
		$this->assertSame( $data, Foundry_Toolkit_Admin_Lock::keep_held_login( $data, false, null ) );
		$this->current = 0;
		$this->assertSame( $data, Foundry_Toolkit_Admin_Lock::keep_held_login( $data, true, self::OTHER_ADMIN ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function locked_pages(): array {
		return array(
			'export'               => array( 'export.php', 'FOUNDRY_ALLOW_TOOLS' ),
			'import'               => array( 'import.php', 'FOUNDRY_ALLOW_TOOLS' ),
			'site health'          => array( 'site-health.php', 'FOUNDRY_ALLOW_TOOLS' ),
			'export personal data' => array( 'export-personal-data.php', 'FOUNDRY_ALLOW_TOOLS' ),
			'erase personal data'  => array( 'erase-personal-data.php', 'FOUNDRY_ALLOW_TOOLS' ),
		);
	}

	/**
	 * WordPress refuses a page whose menu capability is gone with "Sorry, you
	 * are not allowed to access this page"; ours says which switch.
	 *
	 * @dataProvider locked_pages
	 */
	public function test_a_locked_tools_page_names_its_switch( string $page, string $constant ): void {
		Foundry_Toolkit_Admin_Lock::boot( true, false, false );
		$GLOBALS['pagenow'] = $page;
		$this->assertStringContainsString( $constant, $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'access_denied' ) ) );
		$GLOBALS['pagenow'] = 'plugins.php';
		$this->assertSame( '', $this->halt( array( Foundry_Toolkit_Admin_Lock::class, 'access_denied' ) ) );
	}

	public function test_hardening_boots_every_group_by_default(): void {
		Foundry_Toolkit_Hardening::boot();
		$this->assertTrue( $this->refused( 'export' ) );
		$this->assertTrue( $this->refused( 'switch_themes' ) );
		$this->assertTrue( $this->refused( 'edit_user', array( self::OTHER_ADMIN ) ) );
		$this->assertNotFalse( has_filter( 'pre_update_option_siteurl', array( Foundry_Toolkit_Admin_Lock::class, 'keep_option' ) ) );
		$this->assertNotFalse( has_filter( 'wp_pre_insert_user_data', array( Foundry_Toolkit_Admin_Lock::class, 'keep_held_login' ) ) );
		$this->assertNotFalse( has_filter( 'editable_roles', array( Foundry_Toolkit_Admin_Lock::class, 'editable_roles' ) ) );
		$this->assertNotFalse( has_filter( 'pre_update_option', array( Foundry_Toolkit_Admin_Lock::class, 'keep_role_definitions' ) ) );
	}

	public function test_wp_config_unlocks_each_group(): void {
		define( 'FOUNDRY_ALLOW_TOOLS', true );
		define( 'FOUNDRY_ALLOW_SETTINGS', true );
		define( 'FOUNDRY_ALLOW_ADMIN_CHANGES', true );
		Foundry_Toolkit_Hardening::boot();
		$this->assertFalse( has_filter( 'pre_update_option_siteurl', array( Foundry_Toolkit_Admin_Lock::class, 'keep_option' ) ) );
		$this->assertFalse( has_filter( 'wp_pre_insert_user_data', array( Foundry_Toolkit_Admin_Lock::class, 'keep_held_login' ) ) );
		$this->assertFalse( has_filter( 'map_meta_cap', array( Foundry_Toolkit_Admin_Lock::class, 'map_meta_cap' ) ) );
		$this->assertFalse( has_filter( 'editable_roles', array( Foundry_Toolkit_Admin_Lock::class, 'editable_roles' ) ) );
		$this->assertFalse( has_filter( 'update_user_metadata', array( Foundry_Toolkit_Admin_Lock::class, 'refuse_admin_role_meta' ) ) );
		$this->assertFalse( has_filter( 'pre_update_option', array( Foundry_Toolkit_Admin_Lock::class, 'keep_role_definitions' ) ) );
	}

	public function test_only_true_unlocks(): void {
		define( 'FOUNDRY_ALLOW_TOOLS', 'false' ); // A typo must not unlock.
		Foundry_Toolkit_Hardening::boot();
		$this->assertTrue( $this->refused( 'export' ) );
	}

	public function test_one_switch_unlocks_only_its_group(): void {
		define( 'FOUNDRY_ALLOW_ADMIN_CHANGES', true );
		Foundry_Toolkit_Hardening::boot();
		$this->assertTrue( $this->refused( 'export' ) );
		$this->assertFalse( $this->refused( 'edit_user', array( self::OTHER_ADMIN ) ) );
		$this->assertFalse( has_filter( 'wp_pre_insert_user_data', array( Foundry_Toolkit_Admin_Lock::class, 'keep_held_login' ) ) );
	}
}
