<?php
/**
 * Kinsta: on a site with Kinsta's MU plugin, every cache clear (inside an
 * update, and POST /caches) ends by clearing Kinsta's object cache, page
 * cache and CDN (Foundry Toolkit 1.6.0, P39b, P63).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';

/** Kinsta's purger, as its MU plugin 3.6.1 exposes it, recording each call. */
final class Fake_Kinsta_Purge {

	/** @var string[] */
	public $calls = array();

	/** @var array<string, mixed> */
	public $answers = array();

	public function purge_complete_object_cache(): bool {
		$this->calls[] = 'object';
		return $this->answers['object'] ?? true;
	}

	/** @return array<string, mixed>|WP_Error */
	public function purge_complete_site_cache() {
		$this->calls[] = 'site';
		return $this->answers['site'] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => 'Cache has been cleared.',
		);
	}

	/** @return array<string, mixed>|WP_Error */
	public function purge_complete_cdn_cache() {
		$this->calls[] = 'cdn';
		return $this->answers['cdn'] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => 'CDN cache has been cleared.',
		);
	}
}

/** @covers SiteManager_Agent */
final class KinstaCacheTest extends UpdateSupport {

	/** @var Fake_Kinsta_Purge */
	private $purge;

	protected function setUp(): void {
		parent::setUp();
		$this->purge                    = new Fake_Kinsta_Purge();
		$GLOBALS['kinsta_cache']        = (object) array( 'kinsta_cache_purge' => $this->purge );
		SiteManager_Agent::$cache_tools = null; // the real tools: only Kinsta is installed here
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? '' ) : '' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['kinsta_cache'] );
		parent::tearDown();
	}

	/** @return array<int, array{name: string, status: string, detail: string}> */
	private function clear(): array {
		$res = SiteManager_Agent::caches( $this->post( 'caches', '{}' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		return $res->get_data()['caches'];
	}

	public function test_clears_object_page_and_cdn(): void {
		$caches = $this->clear();
		$this->assertSame(
			array(
				array(
					'name'   => 'kinsta',
					'status' => 'cleared',
					'detail' => '',
				),
			),
			$caches
		);
		$this->assertSame( array( 'object', 'site', 'cdn' ), $this->purge->calls );
	}

	public function test_names_what_failed(): void {
		$this->purge->answers = array(
			'site' => new WP_Error( 'http_request_failed', 'timed out' ),
			'cdn'  => array( 'response' => array( 'code' => 500 ) ),
		);
		$caches               = $this->clear();
		$this->assertSame( 'failed', $caches[0]['status'] );
		$this->assertSame( 'Kinsta did not clear its page cache, CDN.', $caches[0]['detail'] );
		$this->assertSame( array( 'object', 'site', 'cdn' ), $this->purge->calls, 'one failure does not stop the others' );
	}

	public function test_off_kinsta_there_is_no_kinsta_step(): void {
		unset( $GLOBALS['kinsta_cache'] );
		$this->assertSame( array(), $this->clear() );
	}
}
