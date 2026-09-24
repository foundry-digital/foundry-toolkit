<?php
/**
 * PHPUnit bootstrap: Brain Monkey plus the few WordPress classes the agent
 * touches, so no WordPress install is needed (docs/testing.md T21).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

define( 'SM_AGENT_TESTING', true );
define( 'ABSPATH', sys_get_temp_dir() . '/sm-agent-tests/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'FOUNDRY_TOOLKIT_VERSION', '1.1.0' );
define( 'FOUNDRY_TOOLKIT_FILE', WP_PLUGIN_DIR . '/foundry-toolkit/foundry-toolkit.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DB_NAME', 'wp_test' );
define( 'SM_VECTORS', json_decode( (string) file_get_contents( __DIR__ . '/fixtures/protocol/vectors.json' ), true ) );
define( 'SITEMANAGER_PUBLIC_KEY', SM_VECTORS['keys']['public_key_base64'] );

/** Minimal WP_Error. */
class WP_Error {
	/** @var string */
	public $code;
	/** @var string */
	public $message;
	/** @var mixed */
	public $data;

	/**
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param mixed  $data    Data.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	/** @return string[] */
	public function get_error_codes(): array {
		return '' === $this->code ? array() : array( $this->code );
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}

/** Minimal WP_REST_Request: headers are stored the way WordPress does, lower case with underscores. */
class WP_REST_Request {
	/** @var string */
	private $method;
	/** @var string */
	private $route;
	/** @var array<string, string> */
	private $headers = array();
	/** @var string */
	private $body;

	/**
	 * @param string                $method  Method.
	 * @param string                $route   Route.
	 * @param array<string, string> $headers Headers as sent.
	 * @param string                $body    Body.
	 */
	public function __construct( string $method, string $route, array $headers, string $body ) {
		$this->method = $method;
		$this->route  = $route;
		$this->body   = $body;
		foreach ( $headers as $k => $v ) {
			$this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] = $v;
		}
	}

	public function get_method(): string {
		return $this->method;
	}

	public function get_route(): string {
		return $this->route;
	}

	public function get_body(): string {
		return $this->body;
	}

	/** @return string|null */
	public function get_header( string $key ) {
		$key = strtolower( str_replace( '-', '_', $key ) );
		return $this->headers[ $key ] ?? null;
	}
}

/** Minimal WP_REST_Response. */
class WP_REST_Response {
	/** @var mixed */
	public $data;
	/** @var array<string, string> */
	public $headers = array();

	/** @param mixed $data Data. */
	public function __construct( $data = null ) {
		$this->data = $data;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	/** @return mixed */
	public function get_data() {
		return $this->data;
	}
}

/** Minimal WP_Theme. */
class WP_Theme {
	/** @var string */
	public $stylesheet;
	/** @var array<string, string> */
	public $data;
	/** @var WP_Theme|false */
	public $parentTheme; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * @param string                $stylesheet Slug.
	 * @param array<string, string> $data       Header data.
	 * @param WP_Theme|false        $parent     Parent theme.
	 */
	public function __construct( string $stylesheet, array $data, $parent = false ) {
		$this->stylesheet  = $stylesheet;
		$this->data        = $data;
		$this->parentTheme = $parent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	}

	public function get_stylesheet(): string {
		return $this->stylesheet;
	}

	public function get( string $key ): string {
		return $this->data[ $key ] ?? '';
	}

	/** @return WP_Theme|false */
	public function parent() {
		return $this->parentTheme; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	}
}

/** Minimal WP_User. */
class WP_User {
	/** @var int */
	public $ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	/** @var string */
	public $user_login;
	/** @var string */
	public $user_email;
	/** @var string[] */
	public $roles;

	/**
	 * @param int      $id    Id.
	 * @param string   $login Login.
	 * @param string   $email Email.
	 * @param string[] $roles Roles.
	 */
	public function __construct( int $id, string $login, string $email, array $roles ) {
		$this->ID         = $id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
		$this->user_login = $login;
		$this->user_email = $email;
		$this->roles      = $roles;
	}
}

/** Minimal wpdb: answers the handful of queries the agent runs from a canned map. */
class wpdb {
 // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital
	/** @var string */
	public $prefix = 'wp_';
	/** @var array<string, mixed> */
	public $vars = array();
	/** @var array<string, array<int, mixed>> */
	public $cols = array();
	/** @var string[] */
	public $queries = array();

	/**
	 * @param string $query Query.
	 * @param mixed  ...$args Args.
	 */
	public function prepare( string $query, ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[sdi]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$v = $args[ $i++ ] ?? '';
				switch ( $m[0] ) {
					case '%d':
						return (string) (int) $v;
					case '%i':
						return '`' . $v . '`';
					default:
						return "'" . $v . "'";
				}
			},
			$query
		);
	}

	public function esc_like( string $s ): string {
		return $s;
	}

	/** @return mixed */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		foreach ( $this->vars as $needle => $value ) {
			if ( false !== strpos( $query, $needle ) ) {
				return $value;
			}
		}
		return null;
	}

	/** @return array<int, mixed> */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		foreach ( $this->cols as $needle => $value ) {
			if ( false !== strpos( $query, $needle ) ) {
				return $value;
			}
		}
		return array();
	}
}

/** Stand-in for WP_Paused_Extensions_Storage. */
class SM_Test_Paused {
	/** @var array<string, mixed> */
	public $all;

	/** @param array<string, mixed> $all Paused items. */
	public function __construct( array $all ) {
		$this->all = $all;
	}

	/** @return array<string, mixed> */
	public function get_all(): array {
		return $this->all;
	}
}

/** Minimal Automatic_Upgrader_Skin: collects messages. */
class Automatic_Upgrader_Skin {
 // phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital
	/** @var string[] Order of events, so a test can check the updater code loads before a skin is made. */
	public static $log = array();

	/** @var string[] */
	public $messages = array();

	/** Records that a skin was created. */
	public function __construct() {
		self::$log[] = 'skin';
	}

	/** @return string[] */
	public function get_upgrade_messages(): array {
		return $this->messages;
	}
}

/**
 * Fake upgrader for tests: records what it was asked to upgrade and returns
 * a canned result, optionally changing the installed version on disk.
 */
class SM_Test_Upgrader {
	/** @var Automatic_Upgrader_Skin */
	public $skin;
	/** @var mixed */
	public $result = true;
	/** @var array<int, mixed> */
	public $calls = array();
	/** @var string[] Which upgrader method each call used. */
	public $methods = array();
	/** @var callable|null */
	public $on_upgrade = null;

	public function __construct( Automatic_Upgrader_Skin $skin ) {
		$this->skin = $skin;
	}

	/**
	 * @param mixed $what What to upgrade.
	 * @return mixed
	 */
	public function upgrade( $what ) {
		$this->calls[]   = $what;
		$this->methods[] = 'upgrade';
		if ( is_callable( $this->on_upgrade ) ) {
			call_user_func( $this->on_upgrade, $what );
		}
		return $this->result;
	}

	/**
	 * @param array<int, mixed> $what Plugin files or language packs.
	 * @return mixed
	 */
	public function bulk_upgrade( $what ) {
		$this->calls[]   = $what;
		$this->methods[] = 'bulk_upgrade';
		if ( is_callable( $this->on_upgrade ) ) {
			call_user_func( $this->on_upgrade, $what );
		}
		// Plugin_Upgrader::bulk_upgrade answers per plugin file; language
		// packs, passed as objects, get the plain result.
		if ( array() !== $what && count( array_filter( $what, 'is_string' ) ) === count( $what ) ) {
			return array_fill_keys( $what, $this->result );
		}
		return $this->result;
	}
}

require __DIR__ . '/../includes/class-foundry-toolkit.php';
require __DIR__ . '/../includes/class-foundry-toolkit-loader.php';
require __DIR__ . '/../includes/class-sitemanager-agent.php';
require __DIR__ . '/../includes/class-foundry-toolkit-hardening.php';
