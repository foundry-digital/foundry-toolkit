<?php
/**
 * Security hardening, ported from Foundry Security Hardening 1.6.3.
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opinionated hardening: XML-RPC, comments, file editors, author enumeration,
 * anonymous user endpoints, security headers, SSL for admin, emojis, oEmbed
 * and feeds. Every sharp edge is opt-out per site with the FDHARDEN_*
 * constants in wp-config.php, the same names as the old must-use plugin.
 *
 * Nothing here may break a WooCommerce or Gravity Forms payment (James,
 * 2026-09-24): the tests in HardeningTest guard the parts that could.
 */
final class Foundry_Toolkit_Hardening {

	/**
	 * Core routes hidden from anonymous callers. Only core /wp/v2 and /oembed:
	 * plugin namespaces (the WooCommerce Store API, payment webhooks, Gravity
	 * Forms) are never touched.
	 */
	public const ANON_BLOCKED_ROUTES = array(
		'/wp/v2/users',
		'/wp/v2/users/(?P<id>[\\d]+)',
		'/wp/v2/comments',
		'/wp/v2/search',
		'/oembed/1.0/embed',
	);

	/** Core comment blocks rendered as nothing while comments are off. */
	public const COMMENT_BLOCKS = array(
		'core/latest-comments',
		'core/comments',
		'core/post-comments',
		'core/comments-query-loop',
		'core/post-comments-form',
	);

	/** Login error codes that reveal whether an account exists. */
	public const ENUMERATING_LOGIN_ERRORS = array(
		'invalid_username',
		'invalid_email',
		'incorrect_password',
		'invalidcombo',
		'empty_username',
		'empty_password',
	);

	/**
	 * Whether boot() has run in this request.
	 *
	 * @var bool
	 */
	public static $booted = false;

	/**
	 * Login error codes seen while the current login screen renders.
	 *
	 * @var string[]
	 */
	public static $login_error_codes = array();

	/**
	 * Test seam: whether Jetpack is connected, instead of asking Jetpack.
	 *
	 * @var bool|null
	 */
	public static $jetpack_override = null;

	/**
	 * Define the toggles and register every hook.
	 *
	 * @return void
	 */
	public static function boot() {
		self::define_toggles();
		self::file_editors();
		self::ssl();
		self::xmlrpc();
		if ( FDHARDEN_DISABLE_COMMENTS ) {
			self::comments();
		}
		add_action( 'init', array( __CLASS__, 'block_author_enumeration' ) );
		add_filter( 'rest_endpoints', array( __CLASS__, 'hide_anon_rest_routes' ) );
		add_action( 'init', array( __CLASS__, 'remove_emoji_and_oembed' ) );
		if ( FDHARDEN_DISABLE_FEEDS ) {
			foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom' ) as $hook ) {
				add_action( $hook, array( __CLASS__, 'no_feed' ), 1 );
			}
		}
		add_action( 'send_headers', array( __CLASS__, 'send_security_headers' ) );
		if ( FDHARDEN_DISABLE_APP_PASSWORDS ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}
		add_filter( 'wp_login_errors', array( __CLASS__, 'capture_login_errors' ), 999 );
		add_filter( 'lostpassword_errors', array( __CLASS__, 'capture_login_errors' ), 999 );
		add_filter( 'login_errors', array( __CLASS__, 'generic_login_error' ) );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		if ( FDHARDEN_LOCK_REGISTRATION ) {
			add_filter( 'pre_option_users_can_register', '__return_zero' );
			add_filter( 'pre_option_default_role', array( __CLASS__, 'subscriber' ) );
		}
		self::$booted = true;
	}

	/**
	 * Section 0: toggles. Define any of these in wp-config.php to opt a site
	 * out. "Local" means wp_get_environment_type() is 'local', from the
	 * site's WP_ENVIRONMENT_TYPE: request headers are attacker-controlled, so
	 * the hostname is never used. A site without the constant is production
	 * and fully locked down, which is the right way to fail.
	 *
	 * @return void
	 */
	public static function define_toggles() {
		if ( ! defined( 'FDHARDEN_IS_LOCAL' ) ) {
			define( 'FDHARDEN_IS_LOCAL', 'local' === wp_get_environment_type() );
		}
		$defaults = array(
			// The wider lock: also removes the installer, update buttons and
			// auto-updates, and so blocks Site Manager's updates. Only for a
			// site deployed exclusively by git.
			'FDHARDEN_DISALLOW_FILE_MODS'    => false,
			// Off if the site needs XML-RPC beyond Jetpack (the WordPress
			// mobile app, legacy publishing tools).
			'FDHARDEN_DISABLE_XMLRPC'        => true,
			// Off on a site that runs comments. WooCommerce product reviews
			// are kept either way while reviews are on in WooCommerce.
			'FDHARDEN_DISABLE_COMMENTS'      => true,
			// Off if anything reads the site's RSS (Mailchimp RSS campaigns,
			// podcasts, aggregators).
			'FDHARDEN_DISABLE_FEEDS'         => true,
			// Off if an integration signs in to the REST API with an
			// application password.
			'FDHARDEN_DISABLE_APP_PASSWORDS' => true,
			// Off on a site where visitors register through wp-login.php.
			// WooCommerce and Gravity Forms create accounts themselves and
			// are unaffected either way.
			'FDHARDEN_LOCK_REGISTRATION'     => true,
		);
		foreach ( $defaults as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- the names are FDHARDEN_*.
			}
		}
	}

	/**
	 * Section 1: no theme or plugin file editors, on every site. Installing,
	 * updating and activating plugins keep working.
	 *
	 * @return void
	 */
	public static function file_editors() {
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own constant, the point of this section.
		}
		if ( FDHARDEN_DISALLOW_FILE_MODS && ! defined( 'DISALLOW_FILE_MODS' ) ) {
			define( 'DISALLOW_FILE_MODS', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- as above.
		}
	}

	/**
	 * Section 2: HTTPS behind a proxy, SSL for admin, verified outbound TLS.
	 * Runs at load so is_ssl() is right for every code path. Local installs
	 * are often plain HTTP with self-signed certificates, so they are spared.
	 *
	 * @return void
	 */
	public static function ssl() {
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
			$_SERVER['HTTPS'] = 'on';
		}
		if ( ! defined( 'FORCE_SSL_ADMIN' ) && ! FDHARDEN_IS_LOCAL ) {
			define( 'FORCE_SSL_ADMIN', true );
		}
		if ( ! FDHARDEN_IS_LOCAL ) {
			add_filter( 'https_ssl_verify', '__return_true' );
		}
	}

	/**
	 * Section 3: XML-RPC off, pingbacks off. While Jetpack is connected (and
	 * so WooPayments, which runs on Jetpack's connection) only Jetpack's own
	 * signed methods stay reachable.
	 *
	 * @return void
	 */
	public static function xmlrpc() {
		if ( FDHARDEN_DISABLE_XMLRPC ) {
			add_filter( 'xmlrpc_enabled', array( __CLASS__, 'xmlrpc_enabled' ), 999 );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'xmlrpc_methods' ), 999 );
			add_filter( 'wp_headers', array( __CLASS__, 'remove_pingback_header' ), 999 );
			add_action( 'init', array( __CLASS__, 'block_xmlrpc_request' ), 1 );
		}
		add_filter( 'pre_option_default_pingback_flag', '__return_zero', 999 );
		add_filter( 'pre_option_default_ping_status', array( __CLASS__, 'closed' ), 999 );
		add_action( 'init', array( __CLASS__, 'remove_head_links' ), 0 );
	}

	/**
	 * Whether Jetpack's connection is up. WooPayments depends on it.
	 *
	 * @return bool
	 */
	public static function jetpack_connected() {
		if ( null !== self::$jetpack_override ) {
			return self::$jetpack_override;
		}
		$manager = 'Automattic\\Jetpack\\Connection\\Manager';
		if ( ! class_exists( $manager ) ) {
			return false;
		}
		$m = new $manager();
		foreach ( array( 'is_connected', 'is_active' ) as $method ) {
			$call = array( $m, $method );
			if ( is_callable( $call ) ) {
				return (bool) call_user_func( $call );
			}
		}
		return false;
	}

	/**
	 * Filter xmlrpc_enabled: off, unless Jetpack needs it.
	 *
	 * @return bool
	 */
	public static function xmlrpc_enabled() {
		return self::jetpack_connected();
	}

	/**
	 * Filter xmlrpc_methods: never pingbacks; with Jetpack connected, only
	 * Jetpack's methods, which check Jetpack's own signature.
	 *
	 * @param array<string, mixed> $methods Methods by name.
	 * @return array<string, mixed>
	 */
	public static function xmlrpc_methods( $methods ) {
		unset( $methods['pingback.ping'] );
		if ( ! self::jetpack_connected() ) {
			return $methods;
		}
		/**
		 * Method name prefixes kept while Jetpack is connected.
		 *
		 * @param string[] $prefixes Prefixes.
		 */
		$prefixes = (array) apply_filters( 'fdharden_jetpack_xmlrpc_prefixes', array( 'jetpack.' ) );
		foreach ( array_keys( $methods ) as $name ) {
			$keep = false;
			foreach ( $prefixes as $p ) {
				if ( 0 === strpos( (string) $name, (string) $p ) ) {
					$keep = true;
				}
			}
			if ( ! $keep ) {
				unset( $methods[ $name ] );
			}
		}
		return $methods;
	}

	/**
	 * Filter wp_headers: drop X-Pingback.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return array<string, string>
	 */
	public static function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * Action init: answer /xmlrpc.php with 403, unless Jetpack needs it.
	 *
	 * @return void
	 */
	public static function block_xmlrpc_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		if ( false === stripos( $uri, '/xmlrpc.php' ) || self::jetpack_connected() ) {
			return;
		}
		status_header( 403 );
		exit;
	}

	/**
	 * Filter pre_option_default_ping_status.
	 *
	 * @return string
	 */
	public static function closed() {
		return 'closed';
	}

	/**
	 * Action init: remove discovery links from wp_head.
	 *
	 * @return void
	 */
	public static function remove_head_links() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
	}

	/**
	 * Section 4: comments off everywhere, front end and admin. Order notes
	 * are untouched: WooCommerce writes them with wp_insert_comment().
	 *
	 * @return void
	 */
	public static function comments() {
		add_action( 'admin_init', array( __CLASS__, 'comments_admin' ) );
		add_action( 'init', array( __CLASS__, 'remove_comment_support' ), 100 );
		add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), 20, 2 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', array( __CLASS__, 'comments_array' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_comments_menu' ) );
		// The toolbar bubble is removed from the bar itself: core adds its
		// callback after init, so unhooking it on init does nothing.
		add_action( 'admin_bar_menu', array( __CLASS__, 'remove_comments_node' ), 999 );
		// Themes reach comments_template() when comments are open or the
		// count is above zero, so zeroing the count is what hides them.
		add_filter( 'get_comments_number', array( __CLASS__, 'comments_number' ), 20, 2 );
		// Backstop for a theme that calls comments_template() regardless.
		add_filter( 'comments_template', array( __CLASS__, 'comments_template' ), 20 );
		// The widget and the blocks query comments directly.
		add_action( 'widgets_init', array( __CLASS__, 'remove_comments_widget' ), 20 );
		add_filter( 'show_recent_comments_widget_style', '__return_false' );
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 20, 2 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'remove_comment_routes' ), 20 );
		add_filter( 'rest_pre_insert_comment', array( __CLASS__, 'refuse_rest_comment' ), 20 );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_action( 'template_redirect', array( __CLASS__, 'no_comment_feed' ), 1 );
	}

	/**
	 * Post types that keep comments while comments are off. WooCommerce
	 * product reviews are comments, so products keep them while reviews are
	 * switched on in WooCommerce.
	 *
	 * @return string[]
	 */
	public static function kept_comment_post_types() {
		$keep = array();
		if ( 'yes' === get_option( 'woocommerce_enable_reviews', 'no' ) ) {
			$keep[] = 'product';
		}
		/**
		 * Post types that keep their comments while FDHARDEN_DISABLE_COMMENTS is on.
		 *
		 * @param string[] $keep Post type names.
		 */
		return array_values( (array) apply_filters( 'fdharden_keep_comments_post_types', $keep ) );
	}

	/**
	 * Whether a post keeps its comments.
	 *
	 * @param int|WP_Post|null $post Post, ID or null for the current one.
	 * @return bool
	 */
	public static function post_keeps_comments( $post ) {
		$type = get_post_type( $post );
		return is_string( $type ) && in_array( $type, self::kept_comment_post_types(), true );
	}

	/**
	 * Action admin_init: no Comments screen, no dashboard widget. The
	 * WooCommerce reviews screen is separate and stays.
	 *
	 * @return void
	 */
	public static function comments_admin() {
		global $pagenow;
		if ( 'edit-comments.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}

	/**
	 * Action init: remove comment support from every post type except the
	 * kept ones.
	 *
	 * @return void
	 */
	public static function remove_comment_support() {
		$keep = self::kept_comment_post_types();
		foreach ( get_post_types() as $pt ) {
			if ( in_array( $pt, $keep, true ) ) {
				continue;
			}
			if ( post_type_supports( $pt, 'comments' ) ) {
				remove_post_type_support( $pt, 'comments' );
				remove_post_type_support( $pt, 'trackbacks' );
			}
		}
	}

	/**
	 * Filter comments_open.
	 *
	 * @param bool             $open Whether open.
	 * @param int|WP_Post|null $post Post.
	 * @return bool
	 */
	public static function comments_open( $open, $post = null ) {
		return self::post_keeps_comments( $post ) ? (bool) $open : false;
	}

	/**
	 * Filter comments_array.
	 *
	 * @param array<int, mixed> $comments Comments.
	 * @param int               $post_id  Post ID.
	 * @return array<int, mixed>
	 */
	public static function comments_array( $comments, $post_id = 0 ) {
		return self::post_keeps_comments( $post_id ) ? $comments : array();
	}

	/**
	 * Filter get_comments_number.
	 *
	 * @param int|string $count   Count.
	 * @param int        $post_id Post ID.
	 * @return int|string
	 */
	public static function comments_number( $count, $post_id = 0 ) {
		return self::post_keeps_comments( $post_id ) ? $count : 0;
	}

	/**
	 * Filter comments_template: an empty template, shipped with the plugin.
	 *
	 * @param string $template Theme template.
	 * @return string
	 */
	public static function comments_template( $template ) {
		if ( self::post_keeps_comments( null ) ) {
			return $template;
		}
		return dirname( __DIR__ ) . '/templates/no-comments.php';
	}

	/**
	 * Action admin_menu.
	 *
	 * @return void
	 */
	public static function remove_comments_menu() {
		remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * Action admin_bar_menu.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function remove_comments_node( $bar ) {
		$bar->remove_node( 'comments' );
	}

	/**
	 * Action widgets_init.
	 *
	 * @return void
	 */
	public static function remove_comments_widget() {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/**
	 * Filter render_block: the core comment blocks render nothing.
	 *
	 * @param string              $content Block HTML.
	 * @param array<string,mixed> $block   Block.
	 * @return string
	 */
	public static function render_block( $content, $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
		return in_array( $name, self::COMMENT_BLOCKS, true ) && ! self::post_keeps_comments( null ) ? '' : $content;
	}

	/**
	 * Filter rest_endpoints: the core comment routes are gone for everyone
	 * while comments are off, unless a post type keeps comments.
	 *
	 * @param array<string, mixed> $endpoints Routes.
	 * @return array<string, mixed>
	 */
	public static function remove_comment_routes( $endpoints ) {
		if ( array() !== self::kept_comment_post_types() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $key ) {
			if ( 0 === strpos( (string) $key, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $key ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Filter rest_pre_insert_comment: only core's comments controller runs
	 * it, so WooCommerce order notes are never affected.
	 *
	 * @param mixed $prepared Prepared comment.
	 * @return mixed
	 */
	public static function refuse_rest_comment( $prepared ) {
		if ( is_array( $prepared ) && isset( $prepared['comment_post_ID'] ) && self::post_keeps_comments( (int) $prepared['comment_post_ID'] ) ) {
			return $prepared;
		}
		return new WP_Error( 'fdharden_comments_closed', __( 'Comments are closed.', 'foundry-toolkit' ), array( 'status' => 403 ) );
	}

	/**
	 * Action template_redirect: comment feeds are 404.
	 *
	 * @return void
	 */
	public static function no_comment_feed() {
		if ( is_comment_feed() ) {
			self::no_feed();
		}
	}

	/**
	 * A 404 for a feed.
	 *
	 * @return void
	 */
	public static function no_feed() {
		status_header( 404 );
		wp_die( esc_html__( 'No feed available.', 'foundry-toolkit' ), '', array( 'response' => 404 ) );
	}

	/**
	 * Section 5, action init: a numeric ?author= on a front-end page goes to
	 * the home page. Never on admin, AJAX, cron, CLI or REST, so a plugin
	 * route that takes an author argument keeps working. $_GET only: a stray
	 * cookie called author must not redirect the whole site.
	 *
	 * @return void
	 */
	public static function block_author_enumeration() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| isset( $_GET['rest_route'] ) // phpcs:ignore WordPress.Security.NonceVerification -- read only.
			|| false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
		if ( $is_rest ) {
			return;
		}
		if ( isset( $_GET['author'] ) && is_numeric( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- only tested for a number.
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Section 6, filter rest_endpoints: hide the listed core routes from
	 * anonymous callers. Only routes matched exactly are removed.
	 *
	 * @param array<string, mixed> $endpoints Routes.
	 * @return array<string, mixed>
	 */
	public static function hide_anon_rest_routes( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		/**
		 * Core routes hidden from anonymous callers, as anchored patterns.
		 * A site that needs one back (a headless front end reading
		 * /wp/v2/search) drops it here rather than forking the plugin.
		 *
		 * @param string[] $block Route patterns.
		 */
		$block = (array) apply_filters( 'fdharden_blocked_anon_rest_routes', self::ANON_BLOCKED_ROUTES );
		foreach ( $block as $route ) {
			foreach ( array_keys( $endpoints ) as $key ) {
				// Exact first: route keys are themselves patterns, so
				// '/wp/v2/users/(?P<id>[\d]+)' never matches itself as a
				// regex. 1.6.3 missed that and left /wp/v2/users/1 public.
				if ( (string) $key === $route || preg_match( '#^' . $route . '$#', (string) $key ) ) {
					unset( $endpoints[ $key ] );
				}
			}
		}
		return $endpoints;
	}

	/**
	 * Section 7, action init: no emoji scripts, no oEmbed discovery.
	 *
	 * @return void
	 */
	public static function remove_emoji_and_oembed() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	}

	/**
	 * Section 9: the security headers, after the site's filter.
	 *
	 * Permissions-Policy leaves out payment and fullscreen on purpose.
	 * payment is the Payment Request API behind Apple Pay and Google Pay in
	 * Stripe, PayPal, Braintree and WooCommerce; those buttons render in the
	 * gateway's own frame, so even payment=(self) removes them without an
	 * error. fullscreen breaks embedded video. camera=(self) keeps QR
	 * scanning on our own pages while denying third-party frames.
	 *
	 * HSTS for a year, no subdomains or preload, when the request or the
	 * site's own address is HTTPS: behind a CDN that hides the scheme,
	 * is_ssl() is false though every visitor is on HTTPS. Browsers ignore
	 * HSTS sent over plain HTTP. Never on a local install.
	 *
	 * @return array<string, string> Header name to value.
	 */
	public static function security_headers() {
		$headers       = array(
			'X-Frame-Options'        => 'SAMEORIGIN',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(self)',
		);
		$site_is_https = 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );
		if ( ( is_ssl() || $site_is_https ) && ! FDHARDEN_IS_LOCAL ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000';
		}
		/**
		 * The hardening headers. Set a value to null or '' to drop it.
		 *
		 * @param array<string, string|null> $headers Header name to value.
		 */
		$headers = (array) apply_filters( 'fdharden_security_headers', $headers );
		$out     = array();
		foreach ( $headers as $name => $value ) {
			if ( null !== $value && '' !== $value ) {
				$out[ (string) $name ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Action send_headers.
	 *
	 * @return void
	 */
	public static function send_security_headers() {
		foreach ( self::security_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Section 11, filters wp_login_errors and lostpassword_errors: remember
	 * the error codes, which login_errors no longer sees.
	 *
	 * @param mixed $errors Errors.
	 * @return mixed
	 */
	public static function capture_login_errors( $errors ) {
		if ( is_wp_error( $errors ) ) {
			self::$login_error_codes = array_merge( self::$login_error_codes, $errors->get_error_codes() );
		}
		return $errors;
	}

	/**
	 * Filter login_errors: one message for anything that would reveal
	 * whether an account exists. Other messages (account suspended, two
	 * factor needed) come from users who proved the password, so they stay.
	 *
	 * @param string $message Message HTML.
	 * @return string
	 */
	public static function generic_login_error( $message ) {
		$codes = self::$login_error_codes;
		if ( array() !== $codes && array() === array_intersect( $codes, self::ENUMERATING_LOGIN_ERRORS ) ) {
			return $message;
		}
		return '<p>' . esc_html__( 'Login failed.', 'foundry-toolkit' ) . '</p>';
	}

	/**
	 * Filter pre_option_default_role.
	 *
	 * @return string
	 */
	public static function subscriber() {
		return 'subscriber';
	}
}
