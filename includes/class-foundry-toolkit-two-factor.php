<?php
/**
 * Required two-factor authentication for administrators (ADR 0035 in the
 * Site Manager repository, ticket 070).
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds every administrator without an authenticator app at a setup wizard
 * until they set one up, on a site with FDHARDEN_REQUIRE_2FA. The 2FA itself
 * is the WordPress.org Two Factor plugin's: this class never stores a secret
 * or checks a code, it calls the plugin's TOTP provider and backup codes.
 *
 * With the constant set but the plugin inactive it holds nobody, rather than
 * lock everyone out. WP-CLI and cron never pass through it.
 */
final class Foundry_Toolkit_Two_Factor {

	/** The wizard's admin.php?page= slug. */
	public const PAGE = 'foundry-two-factor';

	/** Nonce action for the wizard's form. */
	public const NONCE = 'foundry_toolkit_2fa_setup';

	/** The Two Factor plugin's REST namespace, which a held user may reach. */
	public const REST_NAMESPACE = '/two-factor/1.0';

	/** The only provider that releases a held user (ADR 0035, point 4). */
	public const TOTP = 'Two_Factor_Totp';

	/** The backup codes provider, the way back in after a lost phone. */
	public const BACKUP_CODES = 'Two_Factor_Backup_Codes';

	/**
	 * Whether boot() has run in this request.
	 *
	 * @var bool
	 */
	public static $booted = false;

	/**
	 * Test seam: called where the gate would exit after a redirect or the
	 * wizard. Null means exit.
	 *
	 * @var callable|null
	 */
	public static $halt = null;

	/**
	 * Register the gate when the site asks for it. The Two Factor plugin may
	 * load after this, so whether it is active is asked on each request.
	 *
	 * @return void
	 */
	public static function boot() {
		self::$booted = true;
		if ( ! self::required() ) {
			return;
		}
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 999, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_pre_dispatch' ), 10, 3 );
	}

	/**
	 * Whether wp-config.php asks for 2FA.
	 *
	 * @return bool
	 */
	private static function required() {
		return defined( 'FDHARDEN_REQUIRE_2FA' ) && (bool) FDHARDEN_REQUIRE_2FA;
	}

	/**
	 * Whether the gate is holding anyone: asked for, and the plugin active.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$booted && self::required() && class_exists( 'Two_Factor_Core' );
	}

	/**
	 * What the report says (P26 users).
	 *
	 * @return array{two_factor_required: bool, two_factor_plugin_missing: bool}
	 */
	public static function report_fields() {
		$asked = self::$booted && self::required();
		return array(
			'two_factor_required'       => $asked && class_exists( 'Two_Factor_Core' ),
			'two_factor_plugin_missing' => $asked && ! class_exists( 'Two_Factor_Core' ),
		);
	}

	/**
	 * The wizard's address.
	 *
	 * @return string
	 */
	public static function wizard_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Whether a user must finish the wizard before anything else: an
	 * administrator (or super admin), not named in FDHARDEN_2FA_EXEMPT,
	 * without the plugin's TOTP enabled and a key saved. Email codes alone
	 * do not count.
	 *
	 * @param WP_User $user The user.
	 * @return bool
	 */
	public static function is_held( $user ) {
		if ( ! self::active() || ! $user instanceof WP_User || $user->ID <= 0 ) {
			return false;
		}
		$admin = in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
		if ( ! $admin || in_array( strtolower( $user->user_login ), self::exempt(), true ) ) {
			return false;
		}
		$supported = Two_Factor_Core::get_supported_providers_for_user( $user );
		if ( ! isset( $supported[ self::TOTP ] ) ) {
			// A site that filters TOTP out could never release anyone.
			return false;
		}
		$enabled = Two_Factor_Core::get_enabled_providers_for_user( $user );
		return ! ( in_array( self::TOTP, $enabled, true ) && $supported[ self::TOTP ]->is_available_for_user( $user ) );
	}

	/**
	 * Lower-case logins from FDHARDEN_2FA_EXEMPT, such as a host's support
	 * account.
	 *
	 * @return string[]
	 */
	private static function exempt() {
		if ( ! defined( 'FDHARDEN_2FA_EXEMPT' ) || ! is_string( FDHARDEN_2FA_EXEMPT ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', FDHARDEN_2FA_EXEMPT ) ) ) ) );
	}

	/**
	 * Register the wizard as a hidden admin page. wp-admin refuses an
	 * unregistered admin.php?page= before admin_init runs, and admin_init
	 * prints the wizard, so the callback never does.
	 *
	 * @return void
	 */
	public static function register_page() {
		if ( ! self::active() ) {
			return;
		}
		$title = __( 'Set up two-factor authentication', 'foundry-toolkit' );
		add_submenu_page( '', $title, $title, 'read', self::PAGE, '__return_null' );
	}

	/**
	 * After a password login, send a held user straight to the wizard.
	 *
	 * @param string           $redirect_to Where WordPress would go.
	 * @param string           $requested   Where the login form asked to go.
	 * @param WP_User|WP_Error $user        The user, or the login error.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		return $user instanceof WP_User && self::is_held( $user ) ? self::wizard_url() : $redirect_to;
	}

	/**
	 * Every wp-admin request, admin-ajax.php and admin-post.php included:
	 * the wizard renders, heartbeat gets through, other Ajax is refused and
	 * anything else goes to the wizard.
	 *
	 * @return void
	 */
	public static function admin_init() {
		$user = wp_get_current_user();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a screen, nothing is changed.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE === $page && ! wp_doing_ajax() && self::active() ) {
			if ( ! self::is_held( $user ) && 'POST' !== self::method() ) {
				self::redirect( admin_url() );
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() checks the nonce.
			$post = 'POST' === self::method() ? (array) wp_unslash( $_POST ) : array();
			self::render( $user, self::process( $user, $post ) );
			self::halt();
			return;
		}
		if ( ! self::is_held( $user ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading the action name only.
			$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
			if ( 'heartbeat' === $action ) {
				return;
			}
			wp_die( esc_html( self::refusal() ), '', array( 'response' => 403 ) );
		}
		self::redirect( self::wizard_url() );
	}

	/**
	 * Every front-end page a held user loads goes to the wizard.
	 *
	 * @return void
	 */
	public static function template_redirect() {
		if ( self::is_held( wp_get_current_user() ) ) {
			self::redirect( self::wizard_url() );
		}
	}

	/**
	 * Refuse a held user's REST requests, other than the Two Factor plugin's.
	 *
	 * @param mixed                $result  An earlier filter's answer.
	 * @param mixed                $server  The REST server.
	 * @param WP_REST_Request|null $request The request.
	 * @return mixed
	 */
	public static function rest_pre_dispatch( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! self::is_held( wp_get_current_user() ) ) {
			return $result;
		}
		$route = $request->get_route();
		if ( self::REST_NAMESPACE === $route || 0 === strpos( $route, self::REST_NAMESPACE . '/' ) ) {
			return $result;
		}
		return new WP_Error( 'foundry_toolkit_2fa_setup_required', self::refusal(), array( 'status' => 403 ) );
	}

	/**
	 * The message for a refused request.
	 *
	 * @return string
	 */
	private static function refusal() {
		return __( 'Finish setting up two-factor authentication first: log in to the dashboard and follow the steps.', 'foundry-toolkit' );
	}

	/**
	 * The wizard's state after a GET or a form post.
	 *
	 * Setup: a fresh key from the plugin, or the posted one again after a
	 * wrong code so the user need not scan again. A right code saves the key
	 * and enables TOTP as the primary provider through the plugin, then makes
	 * backup codes, shown once. A post from a user who already has TOTP (a
	 * reload of the codes page) makes nothing.
	 *
	 * @param WP_User              $user The user.
	 * @param array<string, mixed> $post The form post, empty for a GET.
	 * @return array{step: string, key: string, error: string, codes: string[]}
	 */
	public static function process( $user, $post ) {
		$state = array(
			'step'  => 'setup',
			'key'   => '',
			'error' => '',
			'codes' => array(),
		);
		$totp  = Two_Factor_Totp::get_instance();
		$key   = isset( $post['key'] ) && is_string( $post['key'] ) ? strtoupper( trim( $post['key'] ) ) : '';
		$valid = '' !== $key && $totp->is_valid_key( $key );
		if ( array() === $post ) {
			$state['key'] = Two_Factor_Totp::generate_key();
			return $state;
		}
		if ( ! self::is_held( $user ) ) {
			$state['step'] = 'done';
			return $state;
		}
		$state['key'] = $valid ? $key : Two_Factor_Totp::generate_key();
		$nonce        = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? $post['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			$state['error'] = __( 'This page expired. Enter a new code from your app and try again.', 'foundry-toolkit' );
			return $state;
		}
		$code = isset( $post['code'] ) && is_string( $post['code'] ) ? (string) preg_replace( '/\s+/', '', $post['code'] ) : '';
		if ( ! $valid || '' === $code || ! Two_Factor_Totp::is_valid_authcode( $key, $code ) ) {
			$state['error'] = __( 'That code did not match. Check the time on your phone is set automatically, then enter the code your app shows now.', 'foundry-toolkit' );
			return $state;
		}
		if ( ! $totp->set_user_totp_key( $user->ID, $key ) || ! Two_Factor_Core::enable_provider_for_user( $user->ID, self::TOTP ) ) {
			$state['error'] = __( 'The key could not be saved. Reload this page, scan the new code and try again.', 'foundry-toolkit' );
			return $state;
		}
		update_user_meta( $user->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, self::TOTP );
		$state['step']  = 'codes';
		$state['codes'] = array_map( 'strval', Two_Factor_Backup_Codes::get_instance()->generate_codes( $user ) );
		Two_Factor_Core::enable_provider_for_user( $user->ID, self::BACKUP_CODES );
		return $state;
	}

	/**
	 * Print the wizard as a page of its own, in plain wp-admin styles.
	 *
	 * @param WP_User                                                          $user  The user.
	 * @param array{step: string, key: string, error: string, codes: string[]} $state From process().
	 * @return void
	 */
	public static function render( $user, $state ) {
		if ( 'done' === $state['step'] ) {
			self::redirect( admin_url() );
			return;
		}
		$qr_url = '';
		if ( 'setup' === $state['step'] ) {
			$qr_url = Two_Factor_Totp::generate_qr_code_url( $user, $state['key'] );
			// The plugin's own QR code script, drawn into #two-factor-qr-code a.
			Two_Factor_Totp::get_instance()->enqueue_assets( '' );
			wp_localize_script(
				'two-factor-totp-qrcode',
				'twoFactorTotpQrcode',
				array(
					'totpUrl'     => $qr_url,
					'qrCodeLabel' => __( 'Authenticator app QR code', 'foundry-toolkit' ),
				)
			);
		}
		$form_url   = self::wizard_url();
		$logout_url = wp_logout_url();
		$site_name  = get_bloginfo( 'name' );
		$logo_url   = plugins_url( 'templates/foundry-logo.svg', FOUNDRY_TOOLKIT_FILE );
		require dirname( __DIR__ ) . '/templates/two-factor-wizard.php';
	}

	/**
	 * The request method.
	 *
	 * @return string
	 */
	private static function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Where to.
	 * @return void
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		self::halt();
	}

	/**
	 * Stop the request.
	 *
	 * @return void
	 */
	private static function halt() {
		if ( is_callable( self::$halt ) ) {
			call_user_func( self::$halt );
			return;
		}
		exit;
	}
}
