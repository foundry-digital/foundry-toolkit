<?php
/**
 * Who counts as privileged (ticket 071 in the Site Manager repository).
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decided by capability, never by role name, so a role with another name
 * that has been given administrator-level powers counts, and portal roles,
 * shop customers and members (subscriber capabilities) never do. Loaded with
 * the plugin: the agent, hardening and 2FA each use it and boot on their own.
 */
final class Foundry_Toolkit_Privilege {

	/**
	 * Administrator-level: what the report counts. None is a capability the
	 * plugin lock maps to do_not_allow, so the lock never hides an account.
	 */
	public const ADMIN_CAPS = array(
		'manage_options',
		'promote_users',
		'edit_users',
		'create_users',
		'delete_users',
		'activate_plugins',
	);

	/**
	 * Held at the 2FA wizard, and refused as the default role: the
	 * administrator-level ones plus edit_others_posts, which editors and
	 * shop managers have and authors, contributors and subscribers do not
	 * (ADR 0035 as amended).
	 */
	public const HELD_CAPS = array(
		'manage_options',
		'promote_users',
		'edit_users',
		'create_users',
		'delete_users',
		'activate_plugins',
		'edit_others_posts',
	);

	/**
	 * Whether a role can do any of the held capabilities. A role that does
	 * not exist is not held.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function role_is_held( $role ) {
		$object = get_role( $role );
		if ( ! $object instanceof WP_Role ) {
			return false;
		}
		foreach ( self::HELD_CAPS as $cap ) {
			if ( $object->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a user can do any of the held capabilities, or is a super
	 * admin on multisite.
	 *
	 * @param WP_User $user The user.
	 * @return bool
	 */
	public static function is_held_user( $user ) {
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}
		foreach ( self::HELD_CAPS as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The IDs of every account with an administrator-level capability, from
	 * its role or granted to the account itself, and super admins on
	 * multisite. Sorted ascending, no repeats.
	 *
	 * @return int[]
	 */
	public static function privileged_user_ids() {
		$ids = array_map(
			'intval',
			(array) get_users(
				array(
					'capability__in' => self::ADMIN_CAPS,
					'fields'         => 'ID',
					'number'         => -1,
				)
			)
		);
		if ( is_multisite() ) {
			foreach ( (array) get_super_admins() as $login ) {
				$user = get_user_by( 'login', (string) $login );
				if ( $user instanceof WP_User ) {
					$ids[] = (int) $user->ID;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}
}
