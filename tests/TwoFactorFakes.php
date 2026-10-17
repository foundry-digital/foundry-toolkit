<?php
/**
 * In-memory stand-ins for the Two Factor plugin 0.17.0 classes the gate and
 * wizard call, with the same names and signatures. TwoFactorTest loads this
 * file only when a test wants the plugin active.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/** Base provider. */
abstract class Two_Factor_Provider {
	/** @var array<string, Two_Factor_Provider> */
	private static $instances = array();

	/** @return static */
	public static function get_instance() {
		$class = static::class;
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}

	final protected function __construct() {
	}

	public function get_key(): string {
		return static::class;
	}

	/**
	 * @param WP_User $user User.
	 */
	abstract public function is_available_for_user( $user ): bool;
}

/** Email codes: configured for everyone, as in the plugin. */
class Two_Factor_Email extends Two_Factor_Provider {
	/** @param WP_User $user User. */
	public function is_available_for_user( $user ): bool {
		return true;
	}
}

/** TOTP: a saved key per user; one code is right. */
class Two_Factor_Totp extends Two_Factor_Provider {
	/** The one code is_valid_authcode accepts. */
	public const GOOD_CODE = '123456';
	/** The key generate_key hands out. */
	public const KEY = 'JBSWY3DPEHPK3PXP';

	/** @var array<int, string> */
	public static $keys = array();

	/** @param WP_User $user User. */
	public function is_available_for_user( $user ): bool {
		return ! empty( self::$keys[ $user->ID ] );
	}

	public static function generate_key(): string {
		return self::KEY;
	}

	/**
	 * @param WP_User $user User.
	 * @param string  $key  Key.
	 */
	public static function generate_qr_code_url( $user, $key ): string {
		return 'otpauth://totp/Test:' . $user->user_login . '?secret=' . $key;
	}

	public function is_valid_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Z2-7]+$/', $key );
	}

	public static function is_valid_authcode( string $key, string $code ): bool {
		return self::KEY === $key && self::GOOD_CODE === $code;
	}

	/**
	 * @param int    $user_id User.
	 * @param string $key     Key.
	 * @return bool
	 */
	public function set_user_totp_key( $user_id, $key ) {
		self::$keys[ $user_id ] = $key;
		return true;
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ): void {
	}
}

/** Backup codes: counts what was generated. */
class Two_Factor_Backup_Codes extends Two_Factor_Provider {
	/** @var array<int, int> */
	public static $generated = array();

	/** @param WP_User $user User. */
	public function is_available_for_user( $user ): bool {
		return ! empty( self::$generated[ $user->ID ] );
	}

	/**
	 * @param WP_User $user User.
	 * @return string[]
	 */
	public function generate_codes( $user ): array {
		self::$generated[ $user->ID ] = 10;
		return array( '11112222', '33334444', '55556666' );
	}
}

/** The core: enabled providers per user, the primary in user meta. */
class Two_Factor_Core {
	public const PROVIDER_USER_META_KEY = '_two_factor_provider';

	/** @var array<int, string[]> */
	public static $enabled = array();

	/** @var bool Whether TOTP is a supported provider (a site can filter it out). */
	public static $totp_supported = true;

	/**
	 * @param int|WP_User $user User.
	 * @return array<string, Two_Factor_Provider>
	 */
	public static function get_supported_providers_for_user( $user = null ): array {
		$out = array(
			'Two_Factor_Email'        => Two_Factor_Email::get_instance(),
			'Two_Factor_Backup_Codes' => Two_Factor_Backup_Codes::get_instance(),
		);
		if ( self::$totp_supported ) {
			$out['Two_Factor_Totp'] = Two_Factor_Totp::get_instance();
		}
		return $out;
	}

	/**
	 * @param int|WP_User $user User.
	 * @return string[]
	 */
	public static function get_enabled_providers_for_user( $user = null ): array {
		$id = $user instanceof WP_User ? $user->ID : (int) $user;
		return array_values( array_intersect( self::$enabled[ $id ] ?? array(), array_keys( self::get_supported_providers_for_user( $id ) ) ) );
	}

	/**
	 * @param int    $user_id  User.
	 * @param string $provider Provider key.
	 */
	public static function enable_provider_for_user( $user_id, $provider ): bool {
		if ( ! array_key_exists( $provider, self::get_supported_providers_for_user( $user_id ) ) ) {
			return false;
		}
		self::$enabled[ $user_id ][] = $provider;
		return true;
	}
}
