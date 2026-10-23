<?php
/**
 * The admin lock (ticket 072 in the Site Manager repository, ADR 0036).
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Sydney Contemporary attacker used a client administrator's leaked
 * password to add a hidden administrator, hide it with Additional CSS and
 * upload backdoors. This refuses the wp-admin tools and settings such a
 * login would use and our sites don't need day to day, in three groups,
 * each unlocked for a moment by its own wp-config.php switch:
 *
 * - FOUNDRY_ALLOW_TOOLS: export, import, the personal-data tools, Site Health.
 * - FOUNDRY_ALLOW_SETTINGS: the site address, admin email, theme and upload
 *   path options, switching theme, Settings, All Settings. Additional CSS
 *   stays open (James, 2026-10-18).
 * - FOUNDRY_ALLOW_ADMIN_CHANGES: giving anyone an administrator-level role
 *   or capability, a role gaining one, and editing, deleting or demoting
 *   another privileged account. Only James adds administrators (James,
 *   2026-10-23, ticket 075). Editors, shop managers, customers and portal
 *   users can still be created.
 *
 * It refuses actions and targets, never the administrator-level
 * capabilities themselves (Foundry_Toolkit_Privilege::HELD_CAPS), so the
 * 2FA hold, the privileged-account count and AMA Portals, which gates its
 * screens on manage_options, behave as before. The option and account
 * guards apply only with a logged-in user: WP-CLI without --user, cron, core's own
 * writes and Site Manager's signed requests are untouched. The two backstops
 * on roles (refuse_admin_role_meta, keep_role_definitions) also cover
 * visitors, so a registration bug cannot make an administrator; only WP-CLI,
 * cron and WordPress's install are left alone.
 */
final class Foundry_Toolkit_Admin_Lock {

	/** FOUNDRY_ALLOW_TOOLS: what nobody may do. */
	public const TOOL_CAPS = array(
		'export',
		'import',
		'export_others_personal_data',
		'erase_others_personal_data',
		'view_site_health_checks',
	);

	/** FOUNDRY_ALLOW_TOOLS: the screens, so their refusal names the switch. */
	public const TOOL_PAGES = array(
		'export.php',
		'import.php',
		'site-health.php',
		'export-personal-data.php',
		'erase-personal-data.php',
	);

	/**
	 * FOUNDRY_ALLOW_SETTINGS: switching theme. Additional CSS (edit_css) is
	 * never refused: James uses it, and WordPress 7.0 strips block-level
	 * custom CSS from any post saved by a user without it.
	 */
	public const SETTINGS_CAPS = array( 'switch_themes' );

	/** FOUNDRY_ALLOW_SETTINGS: options that keep their value for a logged-in user. */
	public const SETTINGS_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'template',
		'stylesheet',
		'upload_path',
		'upload_url_path',
	);

	/** FOUNDRY_ALLOW_ADMIN_CHANGES: refused when the target is another held account. */
	public const USER_CAPS = array( 'edit_user', 'delete_user', 'remove_user', 'promote_user' );

	/** FOUNDRY_ALLOW_ADMIN_CHANGES: the screens that give a role or open another account. */
	public const USER_PAGES = array( 'user-new.php', 'user-edit.php', 'profile.php', 'users.php' );

	/**
	 * Which groups are locked in this request.
	 *
	 * @var array{tools: bool, settings: bool, admins: bool}
	 */
	private static $locked = array(
		'tools'    => false,
		'settings' => false,
		'admins'   => false,
	);

	/**
	 * The roles as stored when this request first saved them, so a role
	 * removed and re-added in one request is compared with what it had.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $roles_at_start = null;

	/**
	 * Register the hooks for the groups that are locked. Called from
	 * hardening, which reads the switches.
	 *
	 * @param bool $tools    Lock the tools.
	 * @param bool $settings Lock the settings.
	 * @param bool $admins   Lock administrator changes.
	 * @return void
	 */
	public static function boot( $tools, $settings, $admins ) {
		self::$locked = array(
			'tools'    => (bool) $tools,
			'settings' => (bool) $settings,
			'admins'   => (bool) $admins,
		);
		if ( $tools || $settings || $admins ) {
			add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		}
		if ( $tools ) {
			add_action( 'admin_page_access_denied', array( __CLASS__, 'access_denied' ) );
		}
		if ( $settings ) {
			foreach ( self::SETTINGS_OPTIONS as $option ) {
				add_filter( 'pre_update_option_' . $option, array( __CLASS__, 'keep_option' ), 10, 3 );
			}
			add_action( 'load-options.php', array( __CLASS__, 'refuse_all_settings' ) );
		}
		if ( $admins ) {
			add_filter( 'editable_roles', array( __CLASS__, 'editable_roles' ) );
			foreach ( self::USER_PAGES as $page ) {
				add_action( 'load-' . $page, array( __CLASS__, 'refuse_user_screen' ) );
			}
			add_filter( 'update_user_metadata', array( __CLASS__, 'refuse_admin_role_meta' ), 10, 4 );
			add_filter( 'add_user_metadata', array( __CLASS__, 'refuse_admin_role_meta' ), 10, 4 );
			add_filter( 'pre_update_option', array( __CLASS__, 'keep_role_definitions' ), 10, 3 );
			add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'keep_held_login' ), 10, 3 );
		}
	}

	/**
	 * The refusal, naming the switch that unlocks it.
	 *
	 * @param string $constant The switch.
	 * @return string
	 */
	public static function message( $constant ) {
		return sprintf(
			/* translators: %s: a wp-config.php constant such as FOUNDRY_ALLOW_TOOLS. */
			__( 'Locked by Foundry Toolkit. To do this, define %s as true in wp-config.php for a moment, then take the line out.', 'foundry-toolkit' ),
			$constant
		);
	}

	/**
	 * Refuse the locked capabilities, and the user capabilities when the
	 * target is another held account.
	 *
	 * @param string[]          $caps    The primitive capabilities WordPress will require.
	 * @param string            $cap     The capability being checked.
	 * @param int               $user_id The user it is checked for.
	 * @param array<int, mixed> $args    The object, such as the target user's ID.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::$locked['tools'] && in_array( $cap, self::TOOL_CAPS, true ) && ! ( 'export' === $cap && self::is_woocommerce_export() ) ) {
			return array( 'do_not_allow' );
		}
		if ( self::$locked['settings'] && in_array( $cap, self::SETTINGS_CAPS, true ) ) {
			return array( 'do_not_allow' );
		}
		if ( self::$locked['admins'] && in_array( $cap, self::USER_CAPS, true ) && isset( $args[0] ) && is_numeric( $args[0] ) && self::is_other_held( (int) $args[0], (int) $user_id ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Whether this request is WooCommerce's product CSV export, which checks
	 * export (ADR 0036): its page, its AJAX step, the download, and the
	 * products list that shows its button. Core's Tools, Export is never one.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_export() {
		global $pagenow;
		if ( ! is_admin() || 'export.php' === $pagenow ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- only decides a capability; WooCommerce checks its own nonces.
		$page      = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		$action    = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		// phpcs:enable
		return 'product_exporter' === $page
			|| in_array( $action, array( 'woocommerce_do_ajax_product_export', 'download_product_csv' ), true )
			|| ( 'edit.php' === $pagenow && 'product' === $post_type );
	}

	/**
	 * Whether a role, or a capability given to an account directly, is
	 * administrator-level (Foundry_Toolkit_Privilege::ADMIN_CAPS). The stored
	 * role is read, not user_can(), so shop managers, whom WooCommerce gives
	 * edit_users only at run time and only for customers, are not: they can
	 * still be created, like editors.
	 *
	 * @param string $name Role slug or capability.
	 * @return bool
	 */
	private static function is_admin_level( $name ) {
		if ( 'administrator' === $name || in_array( $name, Foundry_Toolkit_Privilege::ADMIN_CAPS, true ) ) {
			return true;
		}
		$role = get_role( $name );
		if ( ! $role instanceof WP_Role ) {
			return false;
		}
		foreach ( Foundry_Toolkit_Privilege::ADMIN_CAPS as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this request is one the role backstops leave alone: WP-CLI
	 * (how James adds an administrator), cron, or WordPress's own install.
	 *
	 * @return bool
	 */
	private static function is_trusted_context() {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || wp_installing();
	}

	/**
	 * Whether a user is a held account other than the acting user.
	 *
	 * @param int $target The user being acted on.
	 * @param int $actor  The user acting.
	 * @return bool
	 */
	private static function is_other_held( $target, $actor ) {
		if ( $target <= 0 || $target === $actor ) {
			return false;
		}
		$user = get_userdata( $target );
		return $user instanceof WP_User && Foundry_Toolkit_Privilege::is_held_user( $user );
	}

	/**
	 * Name the switch on a tools screen WordPress is about to refuse.
	 *
	 * @return void
	 */
	public static function access_denied() {
		global $pagenow;
		if ( is_string( $pagenow ) && in_array( $pagenow, self::TOOL_PAGES, true ) ) {
			wp_die( esc_html( self::message( 'FOUNDRY_ALLOW_TOOLS' ) ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Filter pre_update_option_{name}: a logged-in user cannot change a
	 * listed option. The admin email field posts as new_admin_email, so
	 * saving it unchanged (the admin email itself) goes through.
	 *
	 * @param mixed  $value     The value being saved.
	 * @param mixed  $old_value The stored value.
	 * @param string $option    The option name.
	 * @return mixed
	 */
	public static function keep_option( $value, $old_value, $option ) {
		if ( get_current_user_id() <= 0 || self::same( $value, $old_value ) ) {
			return $value;
		}
		if ( 'new_admin_email' === $option && self::same( $value, get_option( 'admin_email' ) ) ) {
			return $value;
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'general', 'foundry_allow_settings', self::message( 'FOUNDRY_ALLOW_SETTINGS' ) );
		}
		return $old_value;
	}

	/**
	 * Whether two option values are the same, as the settings form posts
	 * them (strings).
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return $a === $b;
	}

	/**
	 * On load-options.php: refuse the All Settings screen and its form.
	 * Every other settings page posts here with its own option_page and
	 * still saves.
	 *
	 * @return void
	 */
	public static function refuse_all_settings() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:disable WordPress.Security.NonceVerification -- only refuses; options.php checks the nonce itself.
		// Read the page as core does: from the request, 'options' when empty.
		// A form that posts no option_page takes core's legacy path, which
		// saves any option it names, so it is refused too.
		$page   = empty( $_REQUEST['option_page'] ) ? 'options' : sanitize_key( wp_unslash( $_REQUEST['option_page'] ) );
		$posted = isset( $_POST['option_page'] );
		// phpcs:enable
		if ( 'POST' !== $method || 'options' === $page || ! $posted ) {
			wp_die( esc_html( self::message( 'FOUNDRY_ALLOW_SETTINGS' ) ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Filter editable_roles: no administrator-level role in any role picker
	 * (administrator, or a custom role with manage_options and the like),
	 * which also makes core refuse it on a new user, a profile save, the
	 * bulk change and the REST API.
	 *
	 * @param array<string, mixed> $roles Roles by slug.
	 * @return array<string, mixed>
	 */
	public static function editable_roles( $roles ) {
		foreach ( array_keys( $roles ) as $slug ) {
			if ( self::is_admin_level( (string) $slug ) ) {
				unset( $roles[ $slug ] );
			}
		}
		return $roles;
	}

	/**
	 * On the user screens: refuse a request that gives an administrator-level
	 * role, or opens another held account's profile, with the switch named
	 * (core's own refusals say only "Sorry, you are not allowed").
	 *
	 * @return void
	 */
	public static function refuse_user_screen() {
		// phpcs:disable WordPress.Security.NonceVerification -- only refuses; core checks the nonce before it saves.
		$roles  = array(
			isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '',
			isset( $_REQUEST['new_role'] ) ? sanitize_key( wp_unslash( $_REQUEST['new_role'] ) ) : '',
			isset( $_REQUEST['new_role2'] ) ? sanitize_key( wp_unslash( $_REQUEST['new_role2'] ) ) : '',
		);
		$target = isset( $_REQUEST['user_id'] ) ? (int) $_REQUEST['user_id'] : 0;
		// phpcs:enable
		foreach ( $roles as $role ) {
			if ( '' !== $role && self::is_admin_level( $role ) ) {
				wp_die( esc_html( self::message( 'FOUNDRY_ALLOW_ADMIN_CHANGES' ) ), '', array( 'response' => 403 ) );
			}
		}
		if ( self::is_other_held( $target, get_current_user_id() ) ) {
			wp_die( esc_html( self::message( 'FOUNDRY_ALLOW_ADMIN_CHANGES' ) ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Filter update_user_metadata and add_user_metadata: the backstop for a
	 * plugin that sets a role in its own code. A request, a visitor's
	 * included, cannot add an administrator-level role, or an
	 * administrator-level capability given directly, to an account that does
	 * not already have it. A new user written this way is left with no role.
	 *
	 * @param mixed  $check      Null to let the write happen.
	 * @param int    $object_id  The user.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The value being written.
	 * @return mixed False refuses the write.
	 */
	public static function refuse_admin_role_meta( $check, $object_id, $meta_key, $meta_value ) {
		if ( ! is_string( $meta_key ) || 'capabilities' !== substr( $meta_key, -12 ) || ! is_array( $meta_value ) || self::is_trusted_context() ) {
			return $check;
		}
		$old = get_user_meta( (int) $object_id, $meta_key, true );
		$old = is_array( $old ) ? $old : array();
		foreach ( $meta_value as $name => $granted ) {
			if ( $granted && empty( $old[ $name ] ) && self::is_admin_level( (string) $name ) ) {
				return false;
			}
		}
		return $check;
	}

	/**
	 * Filter pre_update_option, for the roles option ({prefix}user_roles):
	 * a role cannot gain an administrator-level capability, and a new role
	 * cannot be made with one, so a role editor plugin cannot turn Editor
	 * into an administrator (ticket 074's M2, back with ticket 075). Each role
	 * is compared with the roles as this request first found them stored;
	 * every other change saves, WooCommerce adding its own capabilities
	 * included.
	 *
	 * @param mixed  $value     The value being saved.
	 * @param string $option    The option name.
	 * @param mixed  $old_value The stored value.
	 * @return mixed
	 */
	public static function keep_role_definitions( $value, $option, $old_value ) {
		global $wpdb;
		if ( ! is_array( $value ) || ! isset( $wpdb->prefix ) || $wpdb->prefix . 'user_roles' !== $option || self::is_trusted_context() ) {
			return $value;
		}
		if ( null === self::$roles_at_start ) {
			self::$roles_at_start = is_array( $old_value ) ? $old_value : array();
		}
		foreach ( $value as $slug => $role ) {
			if ( ! is_array( $role ) || ! isset( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
				continue;
			}
			$had = self::$roles_at_start[ $slug ]['capabilities'] ?? array();
			$had = is_array( $had ) ? $had : array();
			foreach ( Foundry_Toolkit_Privilege::ADMIN_CAPS as $cap ) {
				if ( ! empty( $role['capabilities'][ $cap ] ) && empty( $had[ $cap ] ) ) {
					unset( $value[ $slug ]['capabilities'][ $cap ] );
				}
			}
		}
		return $value;
	}

	/**
	 * Filter wp_pre_insert_user_data: the backstop for a plugin that updates
	 * users in its own code, such as a CSV import. Another held account's
	 * password and email stay as they are; everything else saves.
	 *
	 * @param array<string, mixed> $data    The users table row being written.
	 * @param bool                 $update  Whether the user exists already.
	 * @param int|null             $user_id The user, on an update.
	 * @return array<string, mixed>
	 */
	public static function keep_held_login( $data, $update, $user_id ) {
		$actor = get_current_user_id();
		if ( ! $update || null === $user_id || $actor <= 0 || ! self::is_other_held( (int) $user_id, $actor ) ) {
			return $data;
		}
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof WP_User ) {
			$data['user_pass']  = $user->user_pass;
			$data['user_email'] = $user->user_email;
		}
		return $data;
	}
}
