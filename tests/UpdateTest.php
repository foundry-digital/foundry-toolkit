<?php
/**
 * The update endpoint: P32 to P41, S1, S2.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';

/** @covers SiteManager_Agent */
final class UpdateTest extends UpdateSupport {

	/**
	 * P35 steps 1 to 8, in order, with the exact codes and statuses.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: int}>
	 */
	public function invalid_requests(): array {
		return array(
			'not json'                         => array( '{nope', 'sm_bad_request', 400 ),
			'json but not an object'           => array( '[1,2]', 'sm_bad_request', 400 ),
			'type missing'                     => array(
				array(
					'item'             => 'hello.php',
					'expected_version' => '1.8',
				),
				'sm_bad_request',
				400,
			),
			'type unknown'                     => array(
				array(
					'type'             => 'widget',
					'item'             => 'x',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'item missing'                     => array(
				array(
					'type'             => 'plugin',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'item empty'                       => array(
				array(
					'type'             => 'plugin',
					'item'             => '',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'plugin item not a php file'       => array(
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'plugin item with path escape'     => array(
				array(
					'type'             => 'plugin',
					'item'             => '../x/y.php',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'theme item with slash'            => array(
				array(
					'type'             => 'theme',
					'item'             => 'a/b',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'core item not core'               => array(
				array(
					'type'             => 'core',
					'item'             => '6.9',
					'expected_version' => '6.9',
				),
				'sm_bad_request',
				400,
			),
			'translation item not all'         => array(
				array(
					'type' => 'translation',
					'item' => 'en_AU',
				),
				'sm_bad_request',
				400,
			),
			'expected version missing'         => array(
				array(
					'type' => 'plugin',
					'item' => 'hello.php',
				),
				'sm_bad_request',
				400,
			),
			'expected version for translation' => array(
				array(
					'type'             => 'translation',
					'item'             => 'all',
					'expected_version' => '1',
				),
				'sm_bad_request',
				400,
			),
			'plugin not installed'             => array(
				array(
					'type'             => 'plugin',
					'item'             => 'missing/missing.php',
					'expected_version' => '1',
				),
				'sm_not_installed',
				422,
			),
			'theme not installed'              => array(
				array(
					'type'             => 'theme',
					'item'             => 'nope',
					'expected_version' => '1',
				),
				'sm_not_installed',
				422,
			),
			'no update offered'                => array(
				array(
					'type'             => 'plugin',
					'item'             => 'hello.php',
					'expected_version' => '1.8',
				),
				'sm_no_update_available',
				409,
			),
			'version mismatch'                 => array(
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.99',
				),
				'sm_version_mismatch',
				409,
			),
		);
	}

	/**
	 * @dataProvider invalid_requests
	 * @param mixed  $body   Request body.
	 * @param string $code   Expected code.
	 * @param int    $status Expected status.
	 */
	public function test_validation_order( $body, string $code, int $status ): void {
		$res = SiteManager_Agent::update( $this->post( 'update', $body ) );
		$this->assertSame( $code, $this->code( $res ) );
		$this->assertSame( $status, $this->status( $res ) );
		$this->assertNull( $this->upgrader, 'nothing may be upgraded when validation fails' );
		$this->assertArrayNotHasKey( 'sm_update_lock', $this->transients, 'the lock is never left behind' );
	}

	public function test_lock_held_returns_busy(): void {
		$this->transients['sm_update_lock'] = 'someone';
		$res                                = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertSame( 'sm_busy', $this->code( $res ) );
		$this->assertSame( 409, $this->status( $res ) );
		$this->assertSame( 'someone', $this->transients['sm_update_lock'], 'a held lock is not touched' );
	}

	/** S1: the agent installs what WordPress offers and only confirms the version. */
	public function test_update_ignores_version_and_installs_offered(): void {
		$body = array(
			'type'             => 'plugin',
			'item'             => 'gravityforms/gravityforms.php',
			'expected_version' => '2.9.13',
		);
		$this->site_transients['update_plugins']->response['gravityforms/gravityforms.php']->new_version = '2.9.14';
		$res = SiteManager_Agent::update( $this->post( 'update', $body ) );
		$this->assertSame( 'sm_version_mismatch', $this->code( $res ) );
		$this->assertSame( '2.9.14', $res->get_error_data()['offered_version'] );
		$this->assertNull( $this->upgrader );

		$this->site_transients['update_plugins']->response['gravityforms/gravityforms.php']->new_version = '2.9.13';
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader             = new SM_Test_Upgrader( $skin );
			$this->upgrader->on_upgrade = fn() => $this->write_plugin( 'gravityforms/gravityforms.php', '2.9.13' );
			$skin->messages             = array( 'Downloading update from https://example.test/gf.zip?key=SECRET', 'Plugin updated successfully.' );
			return $this->upgrader;
		};
		$res                                 = SiteManager_Agent::update( $this->post( 'update', $body ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$data = $res->get_data();
		$this->assertSame( array( array( 'gravityforms/gravityforms.php' ) ), $this->upgrader->calls, 'the upgrader is asked for the item, never a version or a URL' );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( '2.9.12', $data['from_version'] );
		$this->assertSame( '2.9.13', $data['to_version'], 'to_version is read back from the file header' );
		$this->assertArrayNotHasKey( 'rollback_id', $data, 'no snapshots since agent 1.0.7' );
		$this->assertSame( array( 'Downloading update from https://example.test/gf.zip', 'Plugin updated successfully.' ), $data['messages'], 'query strings carry licence keys and are stripped' );
		$this->assertIsInt( $data['duration_ms'] );
		$this->assertArrayNotHasKey( 'sm_update_lock', $this->transients, 'the lock is released' );
	}

	/**
	 * WordPress loads its updater classes only inside wp-admin. A REST request
	 * must load them before making the skin, or the update dies with "Class
	 * Automatic_Upgrader_Skin not found" (Fortivium, 2026-09-24).
	 */
	public function test_update_loads_wordpress_updater_code_before_the_skin(): void {
		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertSame( array( 'load', 'skin' ), array_slice( Automatic_Upgrader_Skin::$log, 0, 2 ), 'the updater code loads before the skin is created' );
	}

	/**
	 * Plugins update the way the "update now" link on wp-admin's Plugins
	 * screen does: Plugin_Upgrader::bulk_upgrade, which leaves an active
	 * plugin switched on and puts the site in maintenance mode for the few
	 * seconds the files take. Plugin_Upgrader::upgrade switches it off first
	 * (P39a). Themes keep Theme_Upgrader::upgrade, which never switches a
	 * theme off.
	 */
	public function test_plugin_updates_use_the_update_now_path(): void {
		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertSame( array( 'bulk_upgrade' ), $this->upgrader->methods );
		$this->assertSame( array(), $this->activations, 'nothing was switched off, so nothing is switched back on' );
		$this->assertFalse( $res->get_data()['reactivated'] );
	}

	/**
	 * Safety net: if the plugin is off after the update anyway, WordPress's
	 * doing or another plugin's, the agent switches it back on. Outside
	 * wp-admin's own update screen, WordPress can deactivate a plugin
	 * before updating it and leaves reactivation to that screen. The agent
	 * must switch it back on, silently, or an update leaves it off
	 * (Gravity SMTP on Fortivium, 2026-09-24).
	 */
	public function test_update_keeps_an_active_plugin_active(): void {
		$this->make_upgrader_deactivate();
		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertSame( array( array( 'gravityforms/gravityforms.php', false, true ) ), $this->activations, 'reactivated once, not network-wide, silently' );
		$this->assertTrue( $this->active['gravityforms/gravityforms.php'] );
		$this->assertTrue( $res->get_data()['reactivated'] );
	}

	public function test_update_leaves_an_inactive_plugin_inactive(): void {
		$this->active = array();
		$this->make_upgrader_deactivate();
		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertSame( array(), $this->activations, 'a plugin that was off stays off' );
		$this->assertFalse( $res->get_data()['reactivated'] );
	}

	public function test_update_keeps_a_network_active_plugin_network_active(): void {
		$this->active         = array();
		$this->network_active = array( 'gravityforms/gravityforms.php' => true );
		$this->make_upgrader_deactivate();
		SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertSame( array( array( 'gravityforms/gravityforms.php', true, true ) ), $this->activations );
	}

	public function test_failed_update_still_reactivates(): void {
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader             = new SM_Test_Upgrader( $skin );
			$this->upgrader->result     = false;
			$this->upgrader->on_upgrade = function () {
				unset( $this->active['gravityforms/gravityforms.php'] );
			};
			return $this->upgrader;
		};
		$res                                 = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertSame( 'sm_upgrade_failed', $this->code( $res ) );
		$this->assertSame( array( array( 'gravityforms/gravityforms.php', false, true ) ), $this->activations );
	}

	/** The fake upgrader does what WordPress does: switches the plugin off. */
	private function make_upgrader_deactivate(): void {
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader             = new SM_Test_Upgrader( $skin );
			$this->upgrader->on_upgrade = function () {
				unset( $this->active['gravityforms/gravityforms.php'], $this->network_active['gravityforms/gravityforms.php'] );
				$this->write_plugin( 'gravityforms/gravityforms.php', '2.9.13' );
			};
			return $this->upgrader;
		};
	}

	/**
	 * Agent 1.0.7 keeps no copies of plugins or themes on the site: backups
	 * are the host's job (ADR 0011, ADR 0023).
	 */
	public function test_update_keeps_no_copy_on_the_site(): void {
		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/uploads', 'nothing is written under uploads' );
		$this->assertSame( array( 'plugins', 'themes' ), $this->entries( $this->root . '/wp-content' ) );
	}

	/**
	 * Older agents kept snapshots in uploads/foundry-site-manager (1.0.1 to
	 * 1.0.6) and wp-content/sitemanager-rollback (1.0.0). The first update
	 * after installing 1.0.7 removes both, so no plugin copies are left
	 * where a web server might serve them. It runs only inside an update,
	 * which only a person's click sends (A7).
	 */
	public function test_update_removes_the_old_snapshot_stores(): void {
		$old = array( $this->root . '/wp-content/uploads/foundry-site-manager', $this->root . '/wp-content/sitemanager-rollback' );
		foreach ( $old as $dir ) {
			mkdir( $dir . '/nested', 0777, true );
			file_put_contents( $dir . '/rollback-manifest.json', '{}' );
			file_put_contents( $dir . '/nested/plugin-x-0123456789abcdef.zip', 'zip' );
		}
		SiteManager_Agent::$old_store_dirs = $old;
		$res                               = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		foreach ( $old as $dir ) {
			$this->assertDirectoryDoesNotExist( $dir );
		}
		$this->assertDirectoryExists( $this->root . '/wp-content/uploads', 'only the agent\'s own folder goes' );
	}

	public function test_update_leaves_old_stores_alone_when_the_request_is_refused(): void {
		$dir = $this->root . '/wp-content/uploads/foundry-site-manager';
		mkdir( $dir, 0777, true );
		SiteManager_Agent::$old_store_dirs = array( $dir );
		SiteManager_Agent::update( $this->post( 'update', '{nope' ) );
		$this->assertDirectoryExists( $dir );
	}

	public function test_upgrader_failure_envelope_flags_licence_problems(): void {
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader         = new SM_Test_Upgrader( $skin );
			$this->upgrader->result = new WP_Error( 'download_failed', 'Package not available' );
			$skin->messages         = array( 'Downloading update from https://example.test/gf.zip?key=SECRET', 'Your license key is not valid for this site (403).' );
			return $this->upgrader;
		};
		$res                                 = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => 'gravityforms/gravityforms.php',
					'expected_version' => '2.9.13',
				)
			)
		);
		$this->assertSame( 'sm_upgrade_failed', $this->code( $res ) );
		$this->assertSame( 500, $this->status( $res ) );
		$data = $res->get_error_data();
		$this->assertSame( '2.9.12', $data['from_version'] );
		$this->assertSame( '2.9.12', $data['installed_version'] );
		$this->assertArrayNotHasKey( 'rollback_id', $data );
		$this->assertTrue( $data['likely_licence_problem'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode_test( $data ) );
		$this->assertArrayNotHasKey( 'sm_update_lock', $this->transients );
	}

	public function test_core_and_translation_update(): void {
		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'core',
					'item'             => 'core',
					'expected_version' => '6.9',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertSame( 'upgrade', $this->upgrader->calls[0]->response, 'core gets the offer object from get_core_updates' );

		$res = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type' => 'translation',
					'item' => 'all',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$this->assertTrue( $res->get_data()['ok'] );
	}

	public function test_theme_update(): void {
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader             = new SM_Test_Upgrader( $skin );
			$this->upgrader->on_upgrade = fn() => $this->write_theme( 'achk27', '1.5.0' );
			return $this->upgrader;
		};
		$res                                 = SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'theme',
					'item'             => 'achk27',
					'expected_version' => '1.5.0',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		$data = $res->get_data();
		$this->assertSame( '1.4.0', $data['from_version'] );
		$this->assertSame( '1.5.0', $data['to_version'] );
	}

	/** S2: without the opt-in the POST routes are never registered. */
	public function test_post_routes_absent_without_opt_in(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( string $ns, string $route ) use ( &$routes ): bool {
				$routes[] = $route;
				return true;
			}
		);
		SiteManager_Agent::$updates_override = false;
		SiteManager_Agent::register_routes();
		$this->assertSame( array( '/report' ), $routes );

		$routes                              = array();
		SiteManager_Agent::$updates_override = true;
		SiteManager_Agent::register_routes();
		$this->assertSame( array( '/report', '/update' ), $routes, 'no cache route since 1.0.4, no rollback route since 1.0.7' );
	}
}
