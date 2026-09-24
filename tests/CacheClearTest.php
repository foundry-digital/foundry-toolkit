<?php
/**
 * Clearing caches after a plugin update: P39b.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

require_once __DIR__ . '/UpdateSupport.php';

/** @covers SiteManager_Agent */
final class CacheClearTest extends UpdateSupport {

	/** @var string[] */
	private $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->calls = array();
		$this->plugins['elementor/elementor.php'] = array(
			'Name'    => 'Elementor',
			'Version' => '3.30.2',
		);
		$this->write_plugin( 'elementor/elementor.php', '3.30.2' );
		$this->site_transients['update_plugins']->response['elementor/elementor.php'] = (object) array(
			'new_version' => '3.30.3',
			'package'     => 'https://example.test/el.zip',
		);
	}

	protected function tearDown(): void {
		SiteManager_Agent::$cache_tools = null;
		parent::tearDown();
	}

	/**
	 * Every tool present, each recording its call and answering $answers[name]
	 * (true when not given).
	 *
	 * @param array<string, mixed> $answers Name to answer.
	 * @param string[]             $absent  Names of tools not installed.
	 */
	private function tools( array $answers = array(), array $absent = array() ): void {
		$tools = array();
		foreach ( array( 'elementor_files', 'elementor_library', 'wp_rocket', 'rocket_cdn' ) as $name ) {
			$tools[ $name ] = in_array( $name, $absent, true ) ? null : function () use ( $name, $answers ) {
				$this->calls[] = $name;
				$answer        = $answers[ $name ] ?? true;
				if ( $answer instanceof Throwable ) {
					throw $answer;
				}
				return $answer;
			};
		}
		$tools['mute_cdn'] = function ( bool $mute ) {
			$this->calls[] = $mute ? 'mute' : 'unmute';
		};
		SiteManager_Agent::$cache_tools = $tools;
	}

	/** @return WP_REST_Response|WP_Error */
	private function update_plugin( string $item, string $version ) {
		return SiteManager_Agent::update(
			$this->post(
				'update',
				array(
					'type'             => 'plugin',
					'item'             => $item,
					'expected_version' => $version,
				)
			)
		);
	}

	/**
	 * @param WP_REST_Response|WP_Error $res Response.
	 * @return array<int, array{name: string, status: string, detail: string}>
	 */
	private function caches( $res ): array {
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		return $res->get_data()['caches'];
	}

	/**
	 * Updating Elementor clears its files and syncs its library, then WP
	 * Rocket, then the CDN last so it refills from fresh pages. The CDN
	 * plugin's own triggers are muted while Elementor and WP Rocket clear,
	 * so the CDN is purged once, not three times.
	 */
	public function test_elementor_update_clears_everything_in_order(): void {
		$this->tools();
		$caches = $this->caches( $this->update_plugin( 'elementor/elementor.php', '3.30.3' ) );
		$this->assertSame( array( 'mute', 'elementor_files', 'elementor_library', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
		$this->assertSame( array( 'elementor_files', 'elementor_library', 'wp_rocket', 'rocket_cdn' ), array_column( $caches, 'name' ) );
		$this->assertSame( array( 'cleared', 'cleared', 'cleared', 'cleared' ), array_column( $caches, 'status' ) );
	}

	/** James, 2026-09-24: Elementor Pro clears Elementor's caches too. */
	public function test_elementor_pro_update_clears_elementor(): void {
		$this->plugins['elementor-pro/elementor-pro.php'] = array(
			'Name'    => 'Elementor Pro',
			'Version' => '3.30.0',
		);
		$this->write_plugin( 'elementor-pro/elementor-pro.php', '3.30.0' );
		$this->site_transients['update_plugins']->response['elementor-pro/elementor-pro.php'] = (object) array(
			'new_version' => '3.30.1',
			'package'     => 'https://example.test/elp.zip',
		);
		$this->tools();
		$this->caches( $this->update_plugin( 'elementor-pro/elementor-pro.php', '3.30.1' ) );
		$this->assertSame( array( 'mute', 'elementor_files', 'elementor_library', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
	}

	public function test_other_plugins_skip_elementor(): void {
		$this->tools();
		$caches = $this->caches( $this->update_plugin( 'gravityforms/gravityforms.php', '2.9.13' ) );
		$this->assertSame( array( 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
		$this->assertSame( array( 'wp_rocket', 'rocket_cdn' ), array_column( $caches, 'name' ) );
	}

	/** Tools that are not installed are left out of the list altogether. */
	public function test_absent_tools_are_left_out(): void {
		$this->tools( array(), array( 'wp_rocket', 'rocket_cdn' ) );
		$caches = $this->caches( $this->update_plugin( 'gravityforms/gravityforms.php', '2.9.13' ) );
		$this->assertSame( array(), $caches );
		$this->assertSame( array(), $this->calls, 'no CDN plugin, nothing to mute' );
	}

	/**
	 * A clear that fails or throws is reported and the rest still run. The
	 * update itself worked, so the response is still a success.
	 */
	public function test_a_failed_clear_does_not_fail_the_update(): void {
		$this->tools(
			array(
				'elementor_library' => 'Elementor could not reach its library server.',
				'wp_rocket'         => new RuntimeException( 'disk full' ),
			)
		);
		$res    = $this->update_plugin( 'elementor/elementor.php', '3.30.3' );
		$caches = $this->caches( $res );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( array( 'cleared', 'failed', 'failed', 'cleared' ), array_column( $caches, 'status' ) );
		$this->assertSame( 'Elementor could not reach its library server.', $caches[1]['detail'] );
		$this->assertSame( 'disk full', $caches[2]['detail'] );
		$this->assertContains( 'unmute', $this->calls, 'the CDN plugin is always unmuted' );
	}

	/** Nothing is cleared when the upgrader fails: the old files are still there. */
	public function test_failed_update_clears_nothing(): void {
		$this->tools();
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader         = new SM_Test_Upgrader( $skin );
			$this->upgrader->result = false;
			return $this->upgrader;
		};
		$res = $this->update_plugin( 'gravityforms/gravityforms.php', '2.9.13' );
		$this->assertSame( 'sm_upgrade_failed', $this->code( $res ) );
		$this->assertSame( array(), $this->calls );
	}

	/** James, 2026-09-24: caches clear after plugin updates only. */
	public function test_theme_updates_clear_nothing(): void {
		$this->tools();
		$res = SiteManager_Agent::update(
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
		$this->assertSame( array(), $this->calls );
		$this->assertSame( array(), $res->get_data()['caches'] );
	}
}
