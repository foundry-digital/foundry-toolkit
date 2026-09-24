<?php
/**
 * Hardening, and the promise that it never breaks a WooCommerce or Gravity
 * Forms payment (James, 2026-09-24).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Foundry_Toolkit_Hardening
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class HardeningTest extends TestCase {

	/** @var array<string, mixed> */
	private $options = array();

	/** @var array<int, string> Post type by post ID. */
	private $types = array(
		1 => 'post',
		2 => 'product',
		3 => 'page',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array( 'woocommerce_enable_reviews' => 'yes' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			function ( $post = null ) {
				return $this->types[ (int) $post ] ?? false;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Foundry_Toolkit_Hardening::define_toggles();
	}

	protected function tearDown(): void {
		Foundry_Toolkit_Hardening::$jetpack_override = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_products_keep_reviews_while_woocommerce_reviews_are_on(): void {
		$this->assertSame( array( 'product' ), Foundry_Toolkit_Hardening::kept_comment_post_types() );
		$this->options['woocommerce_enable_reviews'] = 'no';
		$this->assertSame( array(), Foundry_Toolkit_Hardening::kept_comment_post_types() );
	}

	public function test_comment_support_removed_from_everything_but_products(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'product' ) );
		Functions\when( 'post_type_supports' )->justReturn( true );
		$removed = array();
		Functions\when( 'remove_post_type_support' )->alias(
			function ( string $type, string $feature ) use ( &$removed ) {
				$removed[] = $type . ':' . $feature;
			}
		);
		Foundry_Toolkit_Hardening::remove_comment_support();
		$this->assertSame( array( 'post:comments', 'post:trackbacks', 'page:comments', 'page:trackbacks' ), $removed );
	}

	public function test_comment_filters_leave_product_reviews_alone(): void {
		$this->assertTrue( Foundry_Toolkit_Hardening::comments_open( true, 2 ), 'product review form stays open' );
		$this->assertFalse( Foundry_Toolkit_Hardening::comments_open( true, 1 ), 'post comments closed' );
		$this->assertSame( array( 'r' ), Foundry_Toolkit_Hardening::comments_array( array( 'r' ), 2 ) );
		$this->assertSame( array(), Foundry_Toolkit_Hardening::comments_array( array( 'c' ), 1 ) );
		$this->assertSame( 4, Foundry_Toolkit_Hardening::comments_number( 4, 2 ), 'star rating count kept' );
		$this->assertSame( 0, Foundry_Toolkit_Hardening::comments_number( 4, 1 ) );
		$this->assertSame( array( 'comment_post_ID' => 2 ), Foundry_Toolkit_Hardening::refuse_rest_comment( array( 'comment_post_ID' => 2 ) ) );
		$this->assertInstanceOf( WP_Error::class, Foundry_Toolkit_Hardening::refuse_rest_comment( array( 'comment_post_ID' => 1 ) ) );
	}

	public function test_xmlrpc_without_jetpack_is_off_and_has_no_pingbacks(): void {
		Foundry_Toolkit_Hardening::$jetpack_override = false;
		$this->assertFalse( Foundry_Toolkit_Hardening::xmlrpc_enabled() );
		$methods = Foundry_Toolkit_Hardening::xmlrpc_methods(
			array(
				'pingback.ping' => 'x',
				'wp.getPosts'   => 'x',
			)
		);
		$this->assertSame( array( 'wp.getPosts' ), array_keys( $methods ) );
	}

	public function test_xmlrpc_with_jetpack_keeps_only_jetpack_methods(): void {
		Foundry_Toolkit_Hardening::$jetpack_override = true;
		$this->assertTrue( Foundry_Toolkit_Hardening::xmlrpc_enabled(), 'WooPayments runs on the Jetpack connection' );
		$methods = Foundry_Toolkit_Hardening::xmlrpc_methods(
			array(
				'pingback.ping'           => 'x',
				'wp.getUsersBlogs'        => 'x',
				'jetpack.testConnection'  => 'x',
				'jetpack.remoteAuthorize' => 'x',
			)
		);
		$this->assertSame( array( 'jetpack.testConnection', 'jetpack.remoteAuthorize' ), array_keys( $methods ) );
		$_SERVER['REQUEST_URI'] = '/xmlrpc.php';
		Foundry_Toolkit_Hardening::block_xmlrpc_request(); // Returns rather than exiting with 403.
		$this->addToAssertionCount( 1 );
	}

	public function test_permissions_policy_never_blocks_payments(): void {
		Functions\when( 'home_url' )->justReturn( 'https://shop.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_ssl' )->justReturn( true );
		$headers = Foundry_Toolkit_Hardening::security_headers();
		$this->assertArrayHasKey( 'Permissions-Policy', $headers );
		$this->assertStringNotContainsString( 'payment', $headers['Permissions-Policy'], 'Apple Pay and Google Pay run on the Payment Request API' );
		$this->assertStringNotContainsString( 'fullscreen', $headers['Permissions-Policy'] );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'], 'only our own pages are kept out of frames' );
		$this->assertSame( 'max-age=31536000', $headers['Strict-Transport-Security'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $headers, 'no CSP by default: it would block gateway scripts' );
	}

	public function test_anonymous_rest_block_never_touches_plugin_routes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$routes = array(
			'/wp/v2/users'                          => 1,
			'/wp/v2/users/(?P<id>[\\d]+)'           => 1,
			'/wp/v2/users/me'                       => 1,
			'/wp/v2/search'                         => 1,
			'/wp/v2/posts'                          => 1,
			'/oembed/1.0/embed'                     => 1,
			'/wc/store/v1/checkout'                 => 1,
			'/wc/store/v1/cart'                     => 1,
			'/wc/v3/orders'                         => 1,
			'/wc-stripe/v1/webhook'                 => 1,
			'/wc/v3/payments/webhook'               => 1,
			'/gf/v2/forms'                          => 1,
			'/gravityformsstripe/v1/payment-intent' => 1,
			'/sitemanager/v1/report'                => 1,
			'/jetpack/v4/connection'                => 1,
		);
		$left = array_keys( Foundry_Toolkit_Hardening::hide_anon_rest_routes( $routes ) );
		$this->assertSame(
			array(
				'/wp/v2/users/me',
				'/wp/v2/posts',
				'/wc/store/v1/checkout',
				'/wc/store/v1/cart',
				'/wc/v3/orders',
				'/wc-stripe/v1/webhook',
				'/wc/v3/payments/webhook',
				'/gf/v2/forms',
				'/gravityformsstripe/v1/payment-intent',
				'/sitemanager/v1/report',
				'/jetpack/v4/connection',
			),
			$left
		);
		foreach ( Foundry_Toolkit_Hardening::ANON_BLOCKED_ROUTES as $route ) {
			$this->assertMatchesRegularExpression( '#^/(wp/v2|oembed)/#', $route, 'only core routes may be blocked' );
		}

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->assertSame( $routes, Foundry_Toolkit_Hardening::hide_anon_rest_routes( $routes ), 'logged in: untouched' );
	}

	public function test_comment_routes_stay_while_products_keep_reviews(): void {
		$routes = array(
			'/wp/v2/comments' => 1,
			'/wp/v2/posts'    => 1,
		);
		$this->assertSame( $routes, Foundry_Toolkit_Hardening::remove_comment_routes( $routes ) );
		$this->options['woocommerce_enable_reviews'] = 'no';
		$this->assertSame( array( '/wp/v2/posts' ), array_keys( Foundry_Toolkit_Hardening::remove_comment_routes( $routes ) ) );
	}

	public function test_login_errors_are_generic_only_for_enumeration(): void {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias(
			function ( $e ) {
				return $e instanceof WP_Error;
			}
		);
		Foundry_Toolkit_Hardening::$login_error_codes = array();
		Foundry_Toolkit_Hardening::capture_login_errors( new WP_Error( 'incorrect_password', 'The password you entered for alice is incorrect.' ) );
		$this->assertSame( '<p>Login failed.</p>', Foundry_Toolkit_Hardening::generic_login_error( 'The password you entered for alice is incorrect.' ) );

		Foundry_Toolkit_Hardening::$login_error_codes = array();
		Foundry_Toolkit_Hardening::capture_login_errors( new WP_Error( 'account_suspended', 'Your account is suspended.' ) );
		$this->assertSame( 'Your account is suspended.', Foundry_Toolkit_Hardening::generic_login_error( 'Your account is suspended.' ) );
	}
}
