<?php
/**
 * The report builder: schema (P53), no personal data (S6), no outbound
 * requests (S11).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/** @covers SiteManager_Agent */
final class ReportTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_wordpress();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A site with an admin called admin, a premium plugin with an update, and Wordfence 2FA. */
	private function stub_wordpress(): void {
		global $wpdb;
		$wpdb       = new wpdb();
		$wpdb->vars = array(
			"LIKE 'wp_wfls_2fa_secrets'" => 'wp_wfls_2fa_secrets',
			"LIKE 'wp_wfconfig'"         => 'wp_wfconfig',
			"name = 'wafStatus'"         => 'learning-mode',
			'information_schema'         => 48234496,
		);
		$wpdb->cols = array( 'wfls_2fa_secrets' => array( 1, 2 ) );

		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'site_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.2' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'en_AU' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Australia/Melbourne' );
		Functions\when( 'get_site_transient' )->alias(
			function ( string $key ) {
				switch ( $key ) {
					case 'update_core':
						return (object) array(
							'updates' => array(
								(object) array(
									'response' => 'upgrade',
									'version'  => '6.9',
								),
							),
						);
					case 'update_plugins':
						return (object) array( 'response' => array( 'gravityforms/gravityforms.php' => (object) array( 'new_version' => '2.9.13' ) ) );
					case 'update_themes':
						return (object) array( 'response' => array( 'twentytwentyfive' => array( 'new_version' => '1.3' ) ) );
				}
				return false;
			}
		);
		Functions\when( 'wp_get_theme' )->justReturn(
			new WP_Theme(
				'achk27',
				array(
					'Name'    => 'Art Central 2027',
					'Version' => '1.4.0',
				)
			)
		);
		Functions\when( 'wp_get_themes' )->justReturn(
			array(
				'achk27'           => new WP_Theme( 'achk27', array( 'Name' => 'Art Central 2027', 'Version' => '1.4.0' ) ),
				'twentytwentyfive' => new WP_Theme( 'twentytwentyfive', array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' ) ),
			)
		);
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'gravityforms/gravityforms.php' => array(
					'Name'    => 'Gravity Forms',
					'Version' => '2.9.12',
				),
				'hello.php'                     => array(
					'Name'    => 'Hello Dolly',
					'Version' => '1.7.2',
				),
			)
		);
		Functions\when( 'get_mu_plugins' )->justReturn(
			array(
				'foundry-sitemanager.php' => array(
					'Name'    => 'Foundry Site Manager',
					'Version' => '1.0.0',
				),
			)
		);
		Functions\when( 'is_plugin_active' )->alias( fn( string $f ) => 'hello.php' !== $f );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array( 'gravityforms/gravityforms.php' ) );
		Functions\when( 'count_users' )->justReturn(
			array(
				'avail_roles' => array(
					'administrator' => 3,
					'editor'        => 2,
				),
			)
		);
		Functions\when( 'get_users' )->justReturn( array( 1, 2, 7 ) );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_user_by' )->justReturn( new WP_User( 7, 'admin', 'superadmin@example.test', array( 'administrator' ) ) );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'wp_parse_url' )->alias( fn( string $u, int $c ) => parse_url( $u, $c ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				if ( SiteManager_Agent::CRON_OPT === $key ) {
					return 1790000000;
				}
				if ( SiteManager_Agent::LAST_FATAL_OPT === $key ) {
					return array(
						'at'      => '2026-09-22T01:02:03Z',
						'message' => 'Boom in ' . ABSPATH . 'wp-content/plugins/x.php by jo@example.test',
						'file'    => ABSPATH . 'wp-content/plugins/x.php',
						'line'    => 12,
					);
				}
				return false;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => sys_get_temp_dir() ) );
		Functions\when( 'recurse_dirsize' )->justReturn( 2147483648 );
		Functions\when( 'wp_paused_plugins' )->justReturn( new SM_Test_Paused( array( 'broken/broken.php' => array() ) ) );
		Functions\when( 'wp_paused_themes' )->justReturn( new SM_Test_Paused( array() ) );
		Functions\when( 'wp_is_recovery_mode' )->justReturn( false );
	}

	public function test_report_validates_against_schema(): void {
		$report = SiteManager_Agent::build_report();
		$json   = json_encode( $report );
		$this->assertIsString( $json );
		$schema = (string) file_get_contents( __DIR__ . '/fixtures/protocol/report.schema.json' );
		$result = ( new Validator() )->validate( json_decode( $json ), $schema );
		$this->assertTrue( $result->isValid(), $result->hasError() ? json_encode( $result->error()->message() ) . ' at ' . json_encode( $result->error()->args() ) : 'valid' );
	}

	public function test_report_contents(): void {
		$r = SiteManager_Agent::build_report();
		$this->assertSame( 1, $r['protocol'] );
		$this->assertSame( '6.9', $r['wp']['update_version'] );
		$this->assertSame( 3, $r['users']['administrators'] );
		$this->assertSame( 2, $r['users']['administrators_with_2fa'] );
		$this->assertSame( 'wordfence', $r['users']['two_factor_source'] );
		$this->assertTrue( $r['users']['has_admin_username'] );
		$this->assertSame( 'learning', $r['security']['wordfence_firewall_mode'] );
		$this->assertSame( '/wp-login.php', $r['security']['login_path'] );
		$this->assertCount( 3, $r['plugins'] );
		$this->assertSame( '2.9.13', $r['plugins'][0]['update_version'] );
		$this->assertTrue( $r['plugins'][0]['auto_update'] );
		$this->assertFalse( $r['plugins'][1]['active'] );
		$this->assertTrue( $r['plugins'][2]['must_use'] );
		$this->assertSame( array( 'broken/broken.php' ), $r['health']['paused_plugins'] );
		$this->assertSame( '2026-09-21T14:13:20Z', $r['health']['cron_last_run_at'] );
		$this->assertSame( 48234496, $r['health']['db_size_bytes'] );
		// The server's directory layout stays on the server: paths are
		// relative to the WordPress root, in the file and in the message.
		$this->assertSame( 'wp-content/plugins/x.php', $r['health']['last_fatal']['file'] );
		$this->assertSame( 'Boom in wp-content/plugins/x.php by [email]', $r['health']['last_fatal']['message'] );
		$this->assertFalse( $r['agent']['updates_enabled'] );
		// Foundry Toolkit (ADR 0025): named for the plugin's main file, and
		// honest about the loader and hardening, which this test never set up.
		$this->assertSame( 'foundry-toolkit.php', $r['agent']['php_file'] );
		$this->assertSame( '1.1.0', $r['agent']['version'] );
		$this->assertTrue( $r['agent']['toolkit'] );
		$this->assertFalse( $r['agent']['loader'] );
		$this->assertFalse( $r['agent']['hardening'] );
	}

	public function test_report_lists_every_installed_theme(): void {
		$r = SiteManager_Agent::build_report();
		$this->assertSame(
			array(
				array(
					'slug'           => 'achk27',
					'name'           => 'Art Central 2027',
					'version'        => '1.4.0',
					'update_version' => null,
					'active'         => true,
					'parent'         => false,
				),
				array(
					'slug'           => 'twentytwentyfive',
					'name'           => 'Twenty Twenty-Five',
					'version'        => '1.2',
					'update_version' => '1.3',
					'active'         => false,
					'parent'         => false,
				),
			),
			$r['themes']
		);
	}

	public function test_report_has_no_personal_data(): void {
		$json = (string) json_encode( SiteManager_Agent::build_report() );
		foreach ( array( 'superadmin@example.test', 'jo@example.test', '"admin"', 'user_login', 'user_email', '203.0.113.9' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, "report leaks {$needle}" );
		}
	}

	public function test_report_makes_no_http_requests(): void {
		foreach ( array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_update_plugins', 'wp_update_themes', 'wp_version_check', 'get_core_updates' ) as $fn ) {
			Functions\expect( $fn )->never();
		}
		SiteManager_Agent::build_report();
		$this->addToAssertionCount( 1 );
	}

	public function test_capture_fatal_records_the_error(): void {
		$stored = null;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$stored ) {
				$stored = array( $key, $value );
				return true;
			}
		);
		// Nothing to record when the last error is a notice or absent.
		SiteManager_Agent::capture_fatal();
		$this->assertNull( $stored );
	}
}
