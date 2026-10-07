<?php
/**
 * A persistent object cache (Object Cache Pro's Redis on Rocket.net) is
 * flushed in every cache clear, inside an update and from POST /caches
 * (Foundry Toolkit 1.11.0, P39b, P63).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';

/** @covers SiteManager_Agent */
final class ObjectCacheTest extends UpdateSupport {

	/** @var int */
	private $flushes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->flushes                  = 0;
		SiteManager_Agent::$cache_tools = null; // the real tools: only the object cache is here
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		// Transients live in a persistent object cache, so a flush takes them.
		Functions\when( 'wp_cache_flush' )->alias(
			function (): bool {
				++$this->flushes;
				$this->transients = array();
				return true;
			}
		);
	}

	/** @return array<int, array{name: string, status: string, detail: string}> */
	private function clear(): array {
		$res = SiteManager_Agent::caches( $this->post( 'caches', '{}' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		return $res->get_data()['caches'];
	}

	public function test_flushes_the_object_cache(): void {
		$this->assertSame(
			array(
				array(
					'name'   => 'object_cache',
					'status' => 'cleared',
					'detail' => '',
				),
			),
			$this->clear()
		);
		$this->assertSame( 1, $this->flushes );
	}

	/** P38, P18: the flush takes neither the update lock nor the used nonce. */
	public function test_the_lock_and_the_used_nonce_survive_the_flush(): void {
		$this->transients['sm_update_lock']                   = str_repeat( 'a', 32 );
		$this->assertTrue( SiteManager_Agent::flush_object_cache() );
		$this->assertSame( str_repeat( 'a', 32 ), $this->transients['sm_update_lock'] ?? null, 'the lock still holds for the steps that follow' );
		$this->assertArrayHasKey( 'sm_nonce_' . str_repeat( 'a', 32 ), $this->transients, 'the request cannot be replayed' );
	}

	public function test_a_flush_that_fails_is_reported(): void {
		Functions\when( 'wp_cache_flush' )->justReturn( false );
		$caches = $this->clear();
		$this->assertSame( 'failed', $caches[0]['status'] );
		$this->assertSame( 'The object cache did not confirm the flush.', $caches[0]['detail'] );
	}

	public function test_no_persistent_cache_no_step(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		$this->assertSame( array(), $this->clear() );
		$this->assertSame( 0, $this->flushes );
	}

	/** On Kinsta its own step clears the object cache, so it is not flushed twice. */
	public function test_kinsta_clears_its_own_object_cache(): void {
		$GLOBALS['kinsta_cache'] = (object) array( 'kinsta_cache_purge' => new stdClass() );
		try {
			$this->assertSame( array( 'kinsta' ), array_column( $this->clear(), 'name' ) );
			$this->assertSame( 0, $this->flushes );
		} finally {
			unset( $GLOBALS['kinsta_cache'] );
		}
	}
}
