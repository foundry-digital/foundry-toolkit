<?php
/**
 * The plugin lock (1.12.0).
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * With FDHARDEN_LOCK_PLUGINS, nobody but the logins in FDHARDEN_LOCK_EXEMPT
 * can add, upload, delete or edit plugins and themes. A stolen client
 * administrator login uploaded a file manager plugin to Sydney Contemporary
 * and used it to install malware (James, 2026-10-17).
 *
 * Updates are untouched: the Update buttons in wp-admin, WordPress's own
 * automatic updates (which run with no user) and Site Manager's signed
 * updates all keep working. So that an administrator cannot simply undo the
 * lock, they also cannot deactivate this plugin, or edit, delete or demote
 * an exempt account, or create one under an exempt login.
 */
final class Foundry_Toolkit_Plugin_Lock {

	/** What only exempt accounts may do. */
	public const LOCKED = array(
		'install_plugins',
		'upload_plugins',
		'delete_plugins',
		'edit_plugins',
		'install_themes',
		'upload_themes',
		'delete_themes',
		'edit_themes',
	);

	/** Checks on another user that would let a client take over an exempt account. */
	public const USER_CAPS = array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' );

	/**
	 * Register the filters. Called from hardening only when the lock is on.
	 *
	 * @return void
	 */
	public static function boot() {
		$basename = plugin_basename( FOUNDRY_TOOLKIT_FILE );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_filter( 'plugin_action_links_' . $basename, array( __CLASS__, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( __CLASS__, 'action_links' ) );
		add_filter( 'illegal_user_logins', array( __CLASS__, 'illegal_logins' ) );
	}

	/**
	 * The exempt logins, lower case: FDHARDEN_LOCK_EXEMPT, comma separated.
	 *
	 * @return string[]
	 */
	public static function exempt_logins() {
		$list   = defined( 'FDHARDEN_LOCK_EXEMPT' ) ? (string) FDHARDEN_LOCK_EXEMPT : '';
		$logins = array_map( 'trim', explode( ',', strtolower( $list ) ) );
		return array_values(
			array_filter(
				$logins,
				static function ( $login ) {
					return '' !== $login;
				}
			)
		);
	}

	/**
	 * Whether a user is one of Foundry's own accounts.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_exempt( $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user instanceof WP_User && in_array( strtolower( $user->user_login ), self::exempt_logins(), true );
	}

	/**
	 * Refuse the locked checks for anyone not exempt.
	 *
	 * @param string[]          $caps    The primitive capabilities WordPress will require.
	 * @param string            $cap     The capability being checked.
	 * @param int               $user_id The user it is checked for.
	 * @param array<int, mixed> $args    The object, such as a plugin file or a user ID.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::is_exempt( (int) $user_id ) ) {
			return $caps;
		}
		if ( in_array( $cap, self::LOCKED, true ) ) {
			return array( 'do_not_allow' );
		}
		$object = isset( $args[0] ) && is_scalar( $args[0] ) ? (string) $args[0] : '';
		if ( 'deactivate_plugin' === $cap && plugin_basename( FOUNDRY_TOOLKIT_FILE ) === $object ) {
			return array( 'do_not_allow' ); // Deactivating it would undo the lock.
		}
		if ( in_array( $cap, self::USER_CAPS, true ) && (int) $object !== (int) $user_id && self::is_exempt( (int) $object ) ) {
			return array( 'do_not_allow' ); // A new password would get past the lock.
		}
		return $caps;
	}

	/**
	 * No Deactivate link on this plugin's row for anyone not exempt.
	 *
	 * @param array<string, string> $links Action links by key.
	 * @return array<string, string>
	 */
	public static function action_links( $links ) {
		if ( ! self::is_exempt( get_current_user_id() ) ) {
			unset( $links['deactivate'] );
		}
		return $links;
	}

	/**
	 * Nobody but an exempt account may create a user under an exempt login,
	 * so a client cannot make the account the lock trusts.
	 *
	 * @param string[] $logins Logins WordPress refuses.
	 * @return string[]
	 */
	public static function illegal_logins( $logins ) {
		if ( self::is_exempt( get_current_user_id() ) ) {
			return $logins;
		}
		return array_values( array_merge( (array) $logins, self::exempt_logins() ) );
	}
}
