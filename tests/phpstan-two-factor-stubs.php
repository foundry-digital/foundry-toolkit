<?php
/**
 * The Two Factor plugin symbols the 2FA gate and wizard call, declared for
 * static analysis only. Checked against Two Factor 0.17.0.
 *
 * @package FoundryToolkit
 */

/** The plugin's base provider. */
abstract class Two_Factor_Provider {
	/** @return static */
	public static function get_instance() {
		return new static();
	}

	/**
	 * @param WP_User $user The user.
	 * @return bool
	 */
	abstract public function is_available_for_user( $user );
}

/** Authenticator app codes. */
class Two_Factor_Totp extends Two_Factor_Provider {
	/**
	 * @param int $bitsize Key size.
	 * @return string
	 */
	public static function generate_key( $bitsize = 160 ) {
		return '';
	}

	/**
	 * @param WP_User $user       The user.
	 * @param string  $secret_key The key.
	 * @return string
	 */
	public static function generate_qr_code_url( $user, $secret_key ) {
		return '';
	}

	/**
	 * @param string $key The key.
	 * @return bool
	 */
	public function is_valid_key( $key ) {
		return true;
	}

	/**
	 * @param string $key       The key.
	 * @param string $authcode  The code.
	 * @param string $hash      Hash.
	 * @param int    $time_step Step.
	 * @return bool
	 */
	public static function is_valid_authcode( $key, $authcode, $hash = 'sha1', $time_step = 30 ) {
		return false;
	}

	/**
	 * @param int    $user_id The user.
	 * @param string $key     The key.
	 * @return int|bool
	 */
	public function set_user_totp_key( $user_id, $key ) {
		return true;
	}

	/**
	 * @param WP_User $user The user.
	 * @return bool
	 */
	public function is_available_for_user( $user ) {
		return true;
	}

	/**
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
	}
}

/** Backup codes. */
class Two_Factor_Backup_Codes extends Two_Factor_Provider {
	/**
	 * @param WP_User              $user The user.
	 * @param array<string, mixed> $args Options.
	 * @return array<int, string>
	 */
	public function generate_codes( $user, $args = array() ) {
		return array();
	}

	/**
	 * @param WP_User $user The user.
	 * @return bool
	 */
	public function is_available_for_user( $user ) {
		return true;
	}
}

/** The plugin's core. */
class Two_Factor_Core {
	const PROVIDER_USER_META_KEY = '_two_factor_provider';

	/**
	 * @param int|WP_User|null $user The user.
	 * @return array<string, Two_Factor_Provider>
	 */
	public static function get_supported_providers_for_user( $user = null ) {
		return array();
	}

	/**
	 * @param int|WP_User|null $user The user.
	 * @return string[]
	 */
	public static function get_enabled_providers_for_user( $user = null ) {
		return array();
	}

	/**
	 * @param int    $user_id      The user.
	 * @param string $new_provider Provider class.
	 * @return bool
	 */
	public static function enable_provider_for_user( $user_id, $new_provider ) {
		return true;
	}
}
