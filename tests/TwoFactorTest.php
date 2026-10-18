<?php
/**
 * Required 2FA for administrators (ADR 0035 in the Site Manager repository,
 * ticket 070): who is held at the setup wizard, what gets through while they
 * are, and the wizard saving the Two Factor plugin's TOTP key only after a
 * right code.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Thrown where the gate would exit. */
final class SM_Two_Factor_Halt extends Exception {
}

/**
 * @covers Foundry_Toolkit_Two_Factor
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TwoFactorTest extends TestCase {

	private const WIZARD = 'https://example.test/wp-admin/admin.php?page=foundry-two-factor';

	/** @var WP_User|null */
	private $current = null;

	/** @var string[] */
	private $redirects = array();

	/** @var array<int, array<string, mixed>> User meta writes, by user. */
	private $meta = array();

	/** @var bool */
	private $multisite = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		$_GET  = array();
		$_POST = array();
		Functions\when( 'admin_url' )->alias( fn( string $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ): bool {
				$this->redirects[] = $url;
				return true;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title = '', $args = array() ): void {
				throw new SM_Two_Factor_Halt( 'die ' . ( is_array( $args ) ? (string) ( $args['response'] ?? '' ) : '' ) . ': ' . $message );
			}
		);
		Functions\when( 'wp_get_current_user' )->alias( fn() => $this->current ?? new WP_User( 0, '', '', array() ) );
		Functions\when( 'is_user_logged_in' )->alias( fn() => null !== $this->current );
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'is_super_admin' )->alias( fn( int $id ) => 7 === $id );
		$caps = sm_test_role_caps();
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
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias( fn( $nonce, $action ) => 'good' === $nonce && Foundry_Toolkit_Two_Factor::NONCE === $action ? 1 : false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( fn( $s ) => trim( (string) $s ) );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_print_styles' )->justReturn( array() );
		Functions\when( 'wp_print_scripts' )->justReturn( array() );
		Functions\when( 'language_attributes' )->justReturn( null );
		Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
		Functions\when( 'sanitize_title' )->alias( fn( $s ) => strtolower( (string) $s ) );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'plugins_url' )->alias( fn( string $path = '' ) => 'https://example.test/wp-content/plugins/foundry-toolkit/' . $path );
		Functions\when( 'wp_logout_url' )->justReturn( 'https://example.test/wp-login.php?action=logout&_wpnonce=x' );
		Foundry_Toolkit_Two_Factor::$halt = static function (): void {
			throw new SM_Two_Factor_Halt( 'halt' );
		};
	}

	protected function tearDown(): void {
		Foundry_Toolkit_Two_Factor::$halt   = null;
		Foundry_Toolkit_Two_Factor::$booted = false;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Set the constants and, unless told otherwise, make the Two Factor
	 * plugin active with each test user's providers.
	 *
	 * @param bool $plugin Whether the Two Factor plugin is active.
	 */
	private function enable( bool $plugin = true ): void {
		if ( $plugin ) {
			require_once __DIR__ . '/TwoFactorFakes.php';
			Two_Factor_Core::$enabled = array(
				2  => array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ),
				6  => array( 'Two_Factor_Email' ),
				8  => array( 'Two_Factor_Totp' ),
				10 => array( 'Two_Factor_Email' ),
				11 => array( 'Two_Factor_Totp' ),
			);
			Two_Factor_Totp::$keys    = array(
				2  => Two_Factor_Totp::KEY,
				10 => Two_Factor_Totp::KEY,
				11 => Two_Factor_Totp::KEY,
			);
		}
		Foundry_Toolkit_Two_Factor::boot();
	}

	/** @return array<string, array{0: WP_User, 1: bool, 2?: bool}> */
	public function users(): array {
		return array(
			'administrator without TOTP'           => array( new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) ), true ),
			'administrator with TOTP'              => array( new WP_User( 2, 'sam', 'sam@example.test', array( 'administrator' ) ), false ),
			'editor without TOTP'                  => array( new WP_User( 3, 'ed', 'ed@example.test', array( 'editor' ) ), true ),
			'editor with TOTP'                     => array( new WP_User( 11, 'eve', 'eve@example.test', array( 'editor' ) ), false ),
			// By capability since ticket 071: shop managers can edit others'
			// content and run the shop, so they are held too.
			'shop manager'                         => array( new WP_User( 12, 'shop', 'shop@example.test', array( 'shop_manager' ) ), true ),
			'custom role with manage_options'      => array( new WP_User( 13, 'boss', 'boss@example.test', array( 'site_boss' ) ), true ),
			'author'                               => array( new WP_User( 14, 'au', 'au@example.test', array( 'author' ) ), false ),
			'shop customer'                        => array( new WP_User( 15, 'cu', 'cu@example.test', array( 'customer' ) ), false ),
			'AMA gallery'                          => array( new WP_User( 16, 'ga', 'ga@example.test', array( 'gallery' ) ), false ),
			'AMA exhibitor and VIP'                => array( new WP_User( 17, 'ex', 'ex@example.test', array( 'exhibitor', 'vip' ) ), false ),
			'subscriber'                           => array( new WP_User( 4, 'sub', 'sub@example.test', array( 'subscriber' ) ), false ),
			'host support login, no exemptions'    => array( new WP_User( 5, 'wpengine', 'wpe@example.test', array( 'administrator' ) ), true ),
			'administrator with email codes alone' => array( new WP_User( 6, 'mo', 'mo@example.test', array( 'administrator' ) ), true ),
			'TOTP enabled but no key saved'        => array( new WP_User( 8, 'lee', 'lee@example.test', array( 'administrator' ) ), true ),
			'key saved but only email enabled'     => array( new WP_User( 10, 'pat', 'pat@example.test', array( 'administrator' ) ), true ),
			'super admin without the role'         => array( new WP_User( 7, 'kim', 'kim@example.test', array() ), true, true ),
			'super admin id on a single site'      => array( new WP_User( 7, 'kim', 'kim@example.test', array() ), false ),
		);
	}

	/**
	 * @dataProvider users
	 */
	public function test_who_is_held( WP_User $user, bool $held, bool $multisite = false ): void {
		$this->multisite = $multisite;
		$this->enable();
		$this->current = $user;
		$this->assertSame( $held, Foundry_Toolkit_Two_Factor::is_held( $user ) );

		$this->assertSame( $held ? self::WIZARD : 'https://example.test/wp-admin/', Foundry_Toolkit_Two_Factor::login_redirect( 'https://example.test/wp-admin/', '', $user ), 'after login' );

		foreach ( array( 'admin_init', 'template_redirect' ) as $hook ) {
			$this->redirects = array();
			$halted          = false;
			try {
				Foundry_Toolkit_Two_Factor::$hook();
			} catch ( SM_Two_Factor_Halt $e ) {
				$halted = true;
			}
			$this->assertSame( $held, $halted, $hook . ' halts' );
			$this->assertSame( $held ? array( self::WIZARD ) : array(), $this->redirects, $hook . ' redirects' );
		}
	}

	public function test_login_redirect_leaves_a_failed_login_alone(): void {
		$this->enable();
		$error = new WP_Error( 'incorrect_password' );
		$this->assertSame( '/wp-admin/', Foundry_Toolkit_Two_Factor::login_redirect( '/wp-admin/', '', $error ) );
	}

	public function test_allowed_requests_get_through_and_other_rest_is_refused(): void {
		$this->enable();
		$this->current = new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) );

		// The Two Factor plugin's own REST routes.
		$this->assertNull( Foundry_Toolkit_Two_Factor::rest_pre_dispatch( null, null, new WP_REST_Request( 'POST', '/two-factor/1.0/totp', array(), '' ) ) );
		// Anything else, including a route that only starts with the same letters.
		foreach ( array( '/wp/v2/users/me', '/wp/v2/posts', '/two-factor/1.0x/totp', '/wc/store/v1/cart' ) as $route ) {
			$res = Foundry_Toolkit_Two_Factor::rest_pre_dispatch( null, null, new WP_REST_Request( 'GET', $route, array(), '' ) );
			$this->assertInstanceOf( WP_Error::class, $res, $route );
			$this->assertSame( 'foundry_toolkit_2fa_setup_required', $res->get_error_code() );
			$this->assertSame( array( 'status' => 403 ), $res->get_error_data() );
			$this->assertStringContainsString( 'two-factor', $res->get_error_message() );
		}
		// An earlier filter's answer is kept for a user who is not held.
		$this->current = new WP_User( 2, 'sam', 'sam@example.test', array( 'administrator' ) );
		$this->assertNull( Foundry_Toolkit_Two_Factor::rest_pre_dispatch( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts', array(), '' ) ) );
		$this->assertSame( 'kept', Foundry_Toolkit_Two_Factor::rest_pre_dispatch( 'kept', null, new WP_REST_Request( 'GET', '/wp/v2/posts', array(), '' ) ) );

		// Heartbeat on admin-ajax.php.
		$this->current = new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$_POST['action'] = 'heartbeat';
		Foundry_Toolkit_Two_Factor::admin_init();
		$this->assertSame( array(), $this->redirects, 'heartbeat gets through' );

		// Any other admin-ajax.php action is refused, not redirected.
		$_POST['action'] = 'save-widget';
		try {
			Foundry_Toolkit_Two_Factor::admin_init();
			$this->fail( 'other ajax should be refused' );
		} catch ( SM_Two_Factor_Halt $e ) {
			$this->assertStringStartsWith( 'die 403', $e->getMessage() );
		}
		$this->assertSame( array(), $this->redirects );
	}

	public function test_the_wizard_itself_is_not_redirected(): void {
		$this->enable();
		$this->current = new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) );
		$_GET['page']  = 'foundry-two-factor';
		ob_start();
		try {
			Foundry_Toolkit_Two_Factor::admin_init();
			$this->fail( 'the wizard renders and stops' );
		} catch ( SM_Two_Factor_Halt $e ) {
			$this->assertSame( 'halt', $e->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
		}
		$this->assertSame( array(), $this->redirects );
		$this->assertStringContainsString( Two_Factor_Totp::KEY, $html, 'the key as text' );
		$this->assertStringContainsString( 'two-factor-qr-code', $html );
		$this->assertStringContainsString( 'action=logout', $html, 'a way to log out' );
		$this->assertStringContainsString( 'foundry-toolkit/templates/foundry-logo.svg', $html, 'Foundry Digital branding' );
		foreach ( array( 'Google Authenticator', 'Microsoft Authenticator', '1Password' ) as $app ) {
			$this->assertStringContainsString( $app, $html );
		}
	}

	public function test_the_wizard_sends_a_user_who_is_not_held_to_the_dashboard(): void {
		$this->enable();
		$this->current = new WP_User( 2, 'sam', 'sam@example.test', array( 'administrator' ) );
		$_GET['page']  = 'foundry-two-factor';
		try {
			Foundry_Toolkit_Two_Factor::admin_init();
			$this->fail( 'should redirect' );
		} catch ( SM_Two_Factor_Halt $e ) {
			$this->assertSame( array( 'https://example.test/wp-admin/' ), $this->redirects );
		}
	}

	public function test_the_wizard_is_a_registered_hidden_page(): void {
		// wp-admin refuses an unregistered admin.php?page= before admin_init.
		$this->enable();
		$this->assertNotFalse( has_action( 'admin_menu', array( Foundry_Toolkit_Two_Factor::class, 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'network_admin_menu', array( Foundry_Toolkit_Two_Factor::class, 'register_page' ) ) );
		$calls = array();
		Functions\when( 'add_submenu_page' )->alias(
			function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
				return 'admin_page_foundry-two-factor';
			}
		);
		Foundry_Toolkit_Two_Factor::register_page();
		$this->assertCount( 1, $calls );
		$this->assertSame( '', $calls[0][0], 'no parent: hidden from the menu' );
		$this->assertSame( 'read', $calls[0][3] );
		$this->assertSame( Foundry_Toolkit_Two_Factor::PAGE, $calls[0][4] );
	}

	public function test_logging_out_is_never_gated(): void {
		$this->enable();
		// wp-login.php fires neither admin_init nor template_redirect, and
		// the gate hooks nothing that runs there.
		$this->assertFalse( has_action( 'login_init' ) );
		$this->assertFalse( has_action( 'wp_logout' ) );
		$this->assertNotFalse( has_filter( 'login_redirect', array( Foundry_Toolkit_Two_Factor::class, 'login_redirect' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( Foundry_Toolkit_Two_Factor::class, 'admin_init' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( Foundry_Toolkit_Two_Factor::class, 'template_redirect' ) ) );
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', array( Foundry_Toolkit_Two_Factor::class, 'rest_pre_dispatch' ) ) );
	}

	public function test_nobody_is_held_while_the_two_factor_plugin_is_inactive(): void {
		$this->enable( false );
		$user          = new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) );
		$this->current = $user;
		$this->assertFalse( Foundry_Toolkit_Two_Factor::is_held( $user ) );
		$this->assertSame( '/wp-admin/', Foundry_Toolkit_Two_Factor::login_redirect( '/wp-admin/', '', $user ) );
		Foundry_Toolkit_Two_Factor::admin_init();
		Foundry_Toolkit_Two_Factor::template_redirect();
		$this->assertSame( array(), $this->redirects );
		$this->assertNull( Foundry_Toolkit_Two_Factor::rest_pre_dispatch( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts', array(), '' ) ) );
		$this->assertSame(
			array(
				'two_factor_required'       => false,
				'two_factor_plugin_missing' => true,
			),
			Foundry_Toolkit_Two_Factor::report_fields()
		);
	}

	public function test_nobody_is_held_where_the_site_has_filtered_out_totp(): void {
		$this->enable();
		Two_Factor_Core::$totp_supported = false;
		$this->assertFalse( Foundry_Toolkit_Two_Factor::is_held( new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) ) ) );
	}

	public function test_on_by_default_but_holds_nobody_until_the_plugin_is_active(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Foundry_Toolkit_Hardening::define_toggles();
		Foundry_Toolkit_Two_Factor::boot();
		$this->assertFalse( FOUNDRY_ALLOW_LOGIN_WITHOUT_2FA );
		$this->assertNotFalse( has_action( 'admin_init', array( Foundry_Toolkit_Two_Factor::class, 'admin_init' ) ) );
		$this->assertFalse( Foundry_Toolkit_Two_Factor::is_held( new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) ) ) );
		$this->assertSame(
			array(
				'two_factor_required'       => false,
				'two_factor_plugin_missing' => true,
			),
			Foundry_Toolkit_Two_Factor::report_fields()
		);
	}

	public function test_switched_off_in_wp_config(): void {
		define( 'FOUNDRY_ALLOW_LOGIN_WITHOUT_2FA', true );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Foundry_Toolkit_Hardening::define_toggles();
		Foundry_Toolkit_Two_Factor::boot();
		$this->assertFalse( has_action( 'admin_init' ) );
		$this->assertFalse( has_filter( 'login_redirect' ) );
		$this->assertSame(
			array(
				'two_factor_required'       => false,
				'two_factor_plugin_missing' => false,
			),
			Foundry_Toolkit_Two_Factor::report_fields()
		);
	}

	public function test_report_says_required_while_the_gate_is_active(): void {
		$this->enable();
		$this->assertSame(
			array(
				'two_factor_required'       => true,
				'two_factor_plugin_missing' => false,
			),
			Foundry_Toolkit_Two_Factor::report_fields()
		);
	}

	/** @return array<string, array{0: array<string, string>, 1: string}> */
	public function refused_posts(): array {
		return array(
			'wrong code'  => array(
				array(
					'_wpnonce' => 'good',
					'key'      => 'JBSWY3DPEHPK3PXP',
					'code'     => '654321',
				),
				'That code did not match',
			),
			'no code'     => array(
				array(
					'_wpnonce' => 'good',
					'key'      => 'JBSWY3DPEHPK3PXP',
				),
				'That code did not match',
			),
			'bad nonce'   => array(
				array(
					'_wpnonce' => 'stale',
					'key'      => 'JBSWY3DPEHPK3PXP',
					'code'     => '123456',
				),
				'expired',
			),
			'invalid key' => array(
				array(
					'_wpnonce' => 'good',
					'key'      => 'not-base32!',
					'code'     => '123456',
				),
				'That code did not match',
			),
		);
	}

	/**
	 * @dataProvider refused_posts
	 *
	 * @param array<string, string> $post  Form post.
	 * @param string                $error Part of the message shown.
	 */
	public function test_a_refused_post_saves_nothing( array $post, string $error ): void {
		$this->enable();
		$user  = new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) );
		$state = Foundry_Toolkit_Two_Factor::process( $user, $post );
		$this->assertSame( 'setup', $state['step'] );
		$this->assertStringContainsString( $error, $state['error'] );
		$this->assertArrayNotHasKey( 1, Two_Factor_Totp::$keys );
		$this->assertArrayNotHasKey( 1, Two_Factor_Core::$enabled );
		$this->assertArrayNotHasKey( 1, $this->meta );
		$this->assertTrue( Foundry_Toolkit_Two_Factor::is_held( $user ) );
		// A valid key is kept so the user need not scan again.
		if ( 'JBSWY3DPEHPK3PXP' === $post['key'] ) {
			$this->assertSame( Two_Factor_Totp::KEY, $state['key'] );
		}
	}

	public function test_a_right_code_enables_totp_and_moves_to_backup_codes(): void {
		$this->enable();
		$user  = new WP_User( 1, 'jo', 'jo@example.test', array( 'administrator' ) );
		$state = Foundry_Toolkit_Two_Factor::process(
			$user,
			array(
				'_wpnonce' => 'good',
				'key'      => Two_Factor_Totp::KEY,
				'code'     => '123 456',
			)
		);
		$this->assertSame( 'codes', $state['step'] );
		$this->assertSame( '', $state['error'] );
		$this->assertSame( Two_Factor_Totp::KEY, Two_Factor_Totp::$keys[1] );
		$this->assertSame( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ), Two_Factor_Core::$enabled[1] );
		$this->assertSame( 'Two_Factor_Totp', $this->meta[1]['_two_factor_provider'], 'TOTP is the primary provider' );
		$this->assertSame( array( '11112222', '33334444', '55556666' ), $state['codes'] );
		$this->assertFalse( Foundry_Toolkit_Two_Factor::is_held( $user ) );

		ob_start();
		Foundry_Toolkit_Two_Factor::render( $user, $state );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '33334444', $html );
		$this->assertStringContainsString( 'download=', $html );
		$this->assertStringContainsString( 'https://example.test/wp-admin/', $html, 'on to the dashboard' );
	}

	public function test_a_repeated_post_after_setup_shows_no_new_codes(): void {
		$this->enable();
		$user  = new WP_User( 2, 'sam', 'sam@example.test', array( 'administrator' ) );
		$state = Foundry_Toolkit_Two_Factor::process(
			$user,
			array(
				'_wpnonce' => 'good',
				'key'      => Two_Factor_Totp::KEY,
				'code'     => '123456',
			)
		);
		$this->assertSame( 'done', $state['step'] );
		$this->assertArrayNotHasKey( 2, Two_Factor_Backup_Codes::$generated );
	}
}
