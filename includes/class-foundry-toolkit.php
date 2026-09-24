<?php
/**
 * Boots the toolkit's modules and steps aside for old copies of them.
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The toolkit. Decides which modules run, registers the loader's hooks and
 * tells the admin about anything left over from the must-use days.
 */
final class Foundry_Toolkit {

	/**
	 * Old must-use files that do the agent's job. While one is there it wins,
	 * so a site is never answered by two agents or declares the class twice.
	 */
	public const OLD_AGENT_FILES = array( 'foundry-sitemanager.php', 'sitemanager-agent.php' );

	/** The old must-use hardening file. */
	public const OLD_HARDENING_FILE = 'foundry-hardening.php';

	/**
	 * Whether the agent module is running.
	 *
	 * @var bool
	 */
	public static $agent = false;

	/**
	 * Whether the hardening module is running.
	 *
	 * @var bool
	 */
	public static $hardening = false;

	/**
	 * Old must-use files found at boot, for the admin notice.
	 *
	 * @var string[]
	 */
	public static $leftovers = array();

	/**
	 * Start the modules and register the loader's hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		self::$leftovers = array();

		// The constants mean an old copy already ran, unless they are ours.
		$old_agent = defined( 'SITEMANAGER_AGENT_VERSION' ) && FOUNDRY_TOOLKIT_VERSION !== SITEMANAGER_AGENT_VERSION;
		foreach ( self::OLD_AGENT_FILES as $f ) {
			if ( is_file( Foundry_Toolkit_Loader::mu_dir() . '/' . $f ) ) {
				$old_agent         = true;
				self::$leftovers[] = $f;
			}
		}
		$old_hardening = defined( 'FDHARDEN_IS_LOCAL' ) && ! ( class_exists( 'Foundry_Toolkit_Hardening', false ) && Foundry_Toolkit_Hardening::$booted );
		if ( is_file( Foundry_Toolkit_Loader::mu_dir() . '/' . self::OLD_HARDENING_FILE ) ) {
			$old_hardening     = true;
			self::$leftovers[] = self::OLD_HARDENING_FILE;
		}

		if ( ! $old_agent ) {
			require_once __DIR__ . '/class-sitemanager-agent.php';
			SiteManager_Agent::boot();
			self::$agent = true;
		}
		if ( ! $old_hardening ) {
			require_once __DIR__ . '/class-foundry-toolkit-hardening.php';
			Foundry_Toolkit_Hardening::boot();
			self::$hardening = true;
		}

		register_activation_hook( FOUNDRY_TOOLKIT_FILE, array( 'Foundry_Toolkit_Loader', 'activate' ) );
		register_deactivation_hook( FOUNDRY_TOOLKIT_FILE, array( 'Foundry_Toolkit_Loader', 'remove' ) );
		add_action( 'admin_init', array( 'Foundry_Toolkit_Loader', 'repair' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * What the report says about the toolkit (P26 agent block).
	 *
	 * @return array{toolkit: bool, loader: bool, hardening: bool}
	 */
	public static function report_fields() {
		return array(
			'toolkit'   => true,
			'loader'    => Foundry_Toolkit_Loader::is_current(),
			'hardening' => self::$hardening,
		);
	}

	/**
	 * Admin notice: old must-use files to delete, and a loader that could not
	 * be written.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( self::$leftovers as $f ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: %s: file name in wp-content/mu-plugins. */
					esc_html__( 'Foundry Toolkit replaces wp-content/mu-plugins/%s. Delete that file: until you do, Foundry Toolkit leaves that job to it.', 'foundry-toolkit' ),
					esc_html( $f )
				)
			);
		}
		if ( ! Foundry_Toolkit_Loader::is_current() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Foundry Toolkit could not write its loader to wp-content/mu-plugins, so its hardening loads after other plugins. Make that folder writable, then reload this page.', 'foundry-toolkit' )
			);
		}
	}
}
