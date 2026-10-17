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
 * While FDHARDEN_LOCK_PLUGINS is on (every site but a local one, unless
 * wp-config.php turns it off), nobody can add, upload, delete or edit
 * plugins and themes in wp-admin. A stolen client administrator login
 * uploaded a file manager plugin to Sydney Contemporary and used it to
 * install malware (James, 2026-10-17). No account is exempt: to add a
 * plugin, James turns the lock off in wp-config.php for a moment.
 *
 * Updates are untouched: the Update buttons in wp-admin, WordPress's own
 * automatic updates (which run with no user) and Site Manager's signed
 * updates all keep working. So that an administrator cannot simply undo the
 * lock, nobody can deactivate this plugin from wp-admin either.
 */
final class Foundry_Toolkit_Plugin_Lock {

	/** What nobody may do while the lock is on. */
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
	}

	/**
	 * Refuse the locked checks, and deactivating this plugin.
	 *
	 * @param string[]          $caps    The primitive capabilities WordPress will require.
	 * @param string            $cap     The capability being checked.
	 * @param int               $user_id The user it is checked for.
	 * @param array<int, mixed> $args    The object, such as a plugin file.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, self::LOCKED, true ) ) {
			return array( 'do_not_allow' );
		}
		if ( 'deactivate_plugin' === $cap && isset( $args[0] ) && plugin_basename( FOUNDRY_TOOLKIT_FILE ) === $args[0] ) {
			return array( 'do_not_allow' ); // Deactivating it would undo the lock.
		}
		return $caps;
	}

	/**
	 * No Deactivate link on this plugin's row.
	 *
	 * @param array<string, string> $links Action links by key.
	 * @return array<string, string>
	 */
	public static function action_links( $links ) {
		unset( $links['deactivate'] );
		return $links;
	}
}
