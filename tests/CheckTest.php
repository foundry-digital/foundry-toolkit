<?php
/**
 * POST /check: ask WordPress to check for updates now, and answer with a
 * fresh report (P33c), the way ManageWP's sync does.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';

/** @covers SiteManager_Agent */
final class CheckTest extends UpdateSupport {

	/** @var string[] */
	private $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->calls                       = array();
		SiteManager_Agent::$report_factory = static fn() => array( 'protocol' => 1 );
		foreach ( array( 'wp_update_plugins', 'wp_update_themes' ) as $fn ) {
			Functions\when( $fn )->alias(
				function () use ( $fn ) {
					$this->calls[] = $fn;
				}
			);
		}
		Functions\when( 'wp_version_check' )->alias(
			function ( $extra = array(), $force = false ) {
				$this->calls[] = 'wp_version_check' . ( $force ? ' forced' : '' );
			}
		);
		Functions\when( 'delete_site_transient' )->alias(
			function ( string $k ): bool {
				$this->calls[] = 'delete ' . $k;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		SiteManager_Agent::$report_factory = null;
		parent::tearDown();
	}

	public function test_check_refreshes_every_list_and_answers_with_a_report(): void {
		$r = SiteManager_Agent::check( $this->post( 'check', array() ) );
		$this->assertInstanceOf( WP_REST_Response::class, $r, $this->code( $r ) );
		$this->assertSame(
			array( 'delete foundry_toolkit_release', 'delete update_plugins', 'delete update_themes', 'wp_update_plugins', 'wp_update_themes', 'wp_version_check forced' ),
			$this->calls,
			'the toolkit asks GitHub again, and WordPress rebuilds its lists'
		);
		$data = $r->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( array( 'protocol' => 1 ), $data['report'] );
		$this->assertArrayHasKey( 'duration_ms', $data );
		$this->assertSame( 'no-store', $r->headers['Cache-Control'] ?? '' );
	}

	public function test_check_waits_for_a_running_update(): void {
		$this->transients['sm_update_lock'] = 'other';
		$r                                  = SiteManager_Agent::check( $this->post( 'check', array() ) );
		$this->assertSame( 'sm_busy', $this->code( $r ) );
		$this->assertSame( array(), $this->calls, 'nothing refreshed while an update runs' );
	}
}
