<?php
/**
 * Self-update from signed GitHub releases (S13, ADR 0025).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** @covers Foundry_Toolkit_Updater */
final class UpdaterTest extends TestCase {

	private const BASENAME = 'foundry-toolkit/foundry-toolkit.php';

	/** @var array<string, mixed> */
	private $transients = array();

	/** @var array<string, array{code: int, body: string}|WP_Error> Responses by URL. */
	private $http = array();

	/** @var string[] URLs requested. */
	private $requested = array();

	/** @var array<string, string> */
	private $fixture;

	/** @var string */
	private $zip_bytes;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->fixture   = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/protocol/release.json' ), true );
		$this->zip_bytes = (string) base64_decode( $this->fixture['zip_base64'], true );
		$this->transients = array();
		$this->requested  = array();
		$this->http       = array(
			'https://api.github.com/repos/foundry-digital/foundry-toolkit/releases/latest' => array(
				'code' => 200,
				'body' => (string) json_encode( self::release( 'v1.2.0' ) ),
			),
			self::zip_url( '1.2.0' ) . '.sig' => array(
				'code' => 200,
				'body' => $this->fixture['signature'] . "\n",
			),
		);
		Functions\when( 'get_site_transient' )->alias(
			function ( string $k ) {
				return $this->transients[ $k ] ?? false;
			}
		);
		Functions\when( 'set_site_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $v ): bool {
				return $v instanceof WP_Error;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ) {
				$this->requested[] = $url;
				return $this->http[ $url ] ?? array(
					'code' => 404,
					'body' => '',
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) {
				return $r['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) {
				return $r['body'];
			}
		);
		Functions\when( 'download_url' )->alias(
			function ( string $url ) {
				$this->requested[] = $url;
				$path = tempnam( sys_get_temp_dir(), 'ftzip' );
				file_put_contents( $path, $this->zip_bytes );
				return $path;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function zip_url( string $version ): string {
		return 'https://github.com/foundry-digital/foundry-toolkit/releases/download/v' . $version . '/foundry-toolkit.zip';
	}

	/**
	 * @return array<string, mixed> A GitHub release as the API returns it.
	 */
	private static function release( string $tag, bool $sig = true, string $host = 'https://github.com' ): array {
		$v      = ltrim( $tag, 'v' );
		$assets = array(
			array(
				'name'                 => 'foundry-toolkit.zip',
				'browser_download_url' => $host . '/foundry-digital/foundry-toolkit/releases/download/v' . $v . '/foundry-toolkit.zip',
			),
		);
		if ( $sig ) {
			$assets[] = array(
				'name'                 => 'foundry-toolkit.zip.sig',
				'browser_download_url' => $host . '/foundry-digital/foundry-toolkit/releases/download/v' . $v . '/foundry-toolkit.zip.sig',
			);
		}
		return array(
			'tag_name'   => $tag,
			'draft'      => false,
			'prerelease' => false !== strpos( $tag, '-' ),
			'html_url'   => 'https://github.com/foundry-digital/foundry-toolkit/releases/tag/' . $tag,
			'body'       => 'What changed.',
			'assets'     => $assets,
		);
	}

	private function latest_is( array $release ): void {
		$this->http['https://api.github.com/repos/foundry-digital/foundry-toolkit/releases/latest']['body'] = (string) json_encode( $release );
	}

	public function test_offers_the_latest_release_for_this_plugin_only(): void {
		$u = Foundry_Toolkit_Updater::offer( false, array(), self::BASENAME, array() );
		$this->assertIsArray( $u );
		$this->assertSame( '1.2.0', $u['version'] );
		$this->assertSame( self::zip_url( '1.2.0' ), $u['package'] );
		$this->assertSame( 'foundry-toolkit', $u['slug'] );
		$this->assertFalse( Foundry_Toolkit_Updater::offer( false, array(), 'other/other.php', array() ), 'another github.com plugin is not ours' );

		Foundry_Toolkit_Updater::offer( false, array(), self::BASENAME, array() );
		$this->assertCount( 1, $this->requested, 'the answer is cached' );
	}

	public function test_release_without_a_signature_is_not_offered(): void {
		$this->latest_is( self::release( 'v1.2.0', false ) );
		$this->assertFalse( Foundry_Toolkit_Updater::offer( false, array(), self::BASENAME, array() ) );
	}

	public function test_release_hosted_elsewhere_is_not_offered(): void {
		$this->latest_is( self::release( 'v1.2.0', true, 'https://evil.test' ) );
		$this->assertFalse( Foundry_Toolkit_Updater::offer( false, array(), self::BASENAME, array() ) );
	}

	public function test_github_down_means_no_offer_and_no_retry_storm(): void {
		$this->http['https://api.github.com/repos/foundry-digital/foundry-toolkit/releases/latest'] = new WP_Error( 'http_request_failed', 'down' );
		$this->assertFalse( Foundry_Toolkit_Updater::offer( false, array(), self::BASENAME, array() ) );
		Foundry_Toolkit_Updater::offer( false, array(), self::BASENAME, array() );
		$this->assertCount( 1, $this->requested, 'a failure is cached too' );
	}

	public function test_signed_release_installs(): void {
		$path = Foundry_Toolkit_Updater::verify_download( false, self::zip_url( '1.2.0' ), null, array( 'plugin' => self::BASENAME ) );
		$this->assertIsString( $path );
		$this->assertSame( $this->zip_bytes, file_get_contents( $path ) );
		unlink( $path );
	}

	public function test_a_second_signature_line_counts_during_a_rotation(): void {
		$this->http[ self::zip_url( '1.2.0' ) . '.sig' ]['body'] = base64_encode( str_repeat( 'x', 64 ) ) . "\n" . $this->fixture['signature'] . "\n";
		$path = Foundry_Toolkit_Updater::verify_download( false, self::zip_url( '1.2.0' ), null, array() );
		$this->assertIsString( $path );
		unlink( $path );
	}

	public function test_refuses_tampered_zip(): void {
		$this->zip_bytes .= 'backdoor';
		$r = Foundry_Toolkit_Updater::verify_download( false, self::zip_url( '1.2.0' ), null, array( 'plugin' => self::BASENAME ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'foundry_toolkit_bad_signature', $r->get_error_code() );
		$this->assertSame( array(), glob( sys_get_temp_dir() . '/ftzip*' ) ?: array(), 'the download is deleted' );
	}

	public function test_refuses_missing_signature(): void {
		unset( $this->http[ self::zip_url( '1.2.0' ) . '.sig' ] );
		$r = Foundry_Toolkit_Updater::verify_download( false, self::zip_url( '1.2.0' ), null, array( 'plugin' => self::BASENAME ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'foundry_toolkit_no_signature', $r->get_error_code() );
	}

	public function test_refuses_wrong_version(): void {
		// The 1.2.0 zip and signature, offered as 1.3.0.
		$this->http[ self::zip_url( '1.3.0' ) . '.sig' ] = $this->http[ self::zip_url( '1.2.0' ) . '.sig' ];
		$r = Foundry_Toolkit_Updater::verify_download( false, self::zip_url( '1.3.0' ), null, array( 'plugin' => self::BASENAME ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'foundry_toolkit_bad_signature', $r->get_error_code() );
	}

	public function test_our_plugin_from_anywhere_else_is_refused(): void {
		$r = Foundry_Toolkit_Updater::verify_download( false, 'https://evil.test/foundry-toolkit.zip', null, array( 'plugin' => self::BASENAME ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( array(), $this->requested, 'nothing is downloaded' );
	}

	public function test_other_packages_are_left_alone(): void {
		$this->assertFalse( Foundry_Toolkit_Updater::verify_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertSame( array(), $this->requested );
	}

	public function test_no_automatic_updates(): void {
		$this->assertFalse( Foundry_Toolkit_Updater::no_auto_update( true, (object) array( 'plugin' => self::BASENAME ) ) );
		$this->assertTrue( Foundry_Toolkit_Updater::no_auto_update( true, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertNull( Foundry_Toolkit_Updater::no_auto_update( null, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
	}
}
