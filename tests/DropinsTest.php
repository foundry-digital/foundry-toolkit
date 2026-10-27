<?php
/**
 * Our own pages for a database outage and maintenance mode (Site Manager
 * ticket 080).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/** @covers Foundry_Toolkit_Dropins */
final class DropinsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::clean();
	}

	protected function tearDown(): void {
		self::clean();
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function clean(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$found = glob( WP_CONTENT_DIR . '/{,.}*', GLOB_BRACE );
		foreach ( false === $found ? array() : $found as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
	}

	public function test_install_writes_both_files_current(): void {
		$this->assertFalse( Foundry_Toolkit_Dropins::is_current( 'db-error.php' ), 'nothing there yet' );
		$this->assertTrue( Foundry_Toolkit_Dropins::install() );
		$this->assertTrue( Foundry_Toolkit_Dropins::install(), 'a second install is harmless' );
		foreach ( array_keys( Foundry_Toolkit_Dropins::FILES ) as $file ) {
			$this->assertTrue( Foundry_Toolkit_Dropins::is_current( $file ), $file );
			$body = (string) file_get_contents( WP_CONTENT_DIR . '/' . $file );
			$this->assertStringContainsString( Foundry_Toolkit_Loader::MARKER, $body, $file );
			$this->assertStringNotContainsString( '{{', $body, $file . ': every placeholder filled' );
			$this->assertStringNotContainsString( "\u{2014}", $body, $file . ': no em-dashes' );
		}
		$all   = glob( WP_CONTENT_DIR . '/{,.}*', GLOB_BRACE );
		$names = array_map( 'basename', array_filter( false === $all ? array() : $all, 'is_file' ) );
		sort( $names );
		$this->assertSame( array( 'db-error.php', 'maintenance.php' ), $names, 'no temporary file left behind' );
	}

	public function test_files_are_valid_php(): void {
		Foundry_Toolkit_Dropins::install();
		foreach ( array_keys( Foundry_Toolkit_Dropins::FILES ) as $file ) {
			exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( WP_CONTENT_DIR . '/' . $file ) . ' 2>&1', $out, $code );
			$this->assertSame( 0, $code, implode( "\n", $out ) );
		}
	}

	public function test_someone_elses_file_is_never_replaced_or_removed(): void {
		file_put_contents( WP_CONTENT_DIR . '/maintenance.php', "<?php\n// the host's page\n" );

		$this->assertFalse( Foundry_Toolkit_Dropins::install(), 'not every file is ours' );
		$this->assertSame( array( 'maintenance.php' ), Foundry_Toolkit_Dropins::blocked() );
		$this->assertTrue( Foundry_Toolkit_Dropins::is_current( 'db-error.php' ), 'the other file is still written' );

		Foundry_Toolkit_Dropins::repair();
		Foundry_Toolkit_Dropins::remove();
		$this->assertSame( "<?php\n// the host's page\n", file_get_contents( WP_CONTENT_DIR . '/maintenance.php' ) );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/db-error.php', 'ours removed' );
	}

	public function test_repair_rewrites_a_changed_or_missing_file(): void {
		Foundry_Toolkit_Dropins::install();
		file_put_contents( WP_CONTENT_DIR . '/db-error.php', "<?php\n// " . Foundry_Toolkit_Loader::MARKER . ", old version\n" );
		unlink( WP_CONTENT_DIR . '/maintenance.php' );

		Foundry_Toolkit_Dropins::repair();
		$this->assertTrue( Foundry_Toolkit_Dropins::is_current( 'db-error.php' ), 'changed file rewritten' );
		$this->assertTrue( Foundry_Toolkit_Dropins::is_current( 'maintenance.php' ), 'deleted file rewritten' );
		$this->assertSame( array(), Foundry_Toolkit_Dropins::blocked() );
	}

	/**
	 * What each page sends and says. The headers only exist under a web
	 * server, so this serves the written file with PHP's own.
	 *
	 * @return array<string, array{string, string, string[], string[]}>
	 */
	public static function pages(): array {
		return array(
			'database down' => array(
				'db-error.php',
				'database-error',
				array( '<title>Back shortly</title>', 'We&rsquo;ll be right back', 'This page is currently unavailable. Please check back shortly.', 'Site cared for by Foundry Digital' ),
				array( 'Just a moment', 'making some updates' ),
			),
			'maintenance'   => array(
				'maintenance.php',
				'maintenance',
				array( '<title>Just a moment</title>', '<h1>Just a moment</h1>', 'We&rsquo;re currently making some updates. Please check back soon.', 'Site cared for by Foundry Digital' ),
				array( 'right back', 'currently unavailable' ),
			),
		);
	}

	/**
	 * @dataProvider pages
	 *
	 * @param string   $file   Drop-in.
	 * @param string   $status X-Foundry-Status.
	 * @param string[] $says   Text the page shows.
	 * @param string[] $not    Text it must not show.
	 */
	public function test_page_answers_503_and_says_its_message( string $file, string $status, array $says, array $not ): void {
		Foundry_Toolkit_Dropins::install();
		$router = WP_CONTENT_DIR . '/router.php';
		file_put_contents( $router, "<?php\ndefine( 'ABSPATH', __DIR__ . '/' );\nrequire __DIR__ . '/" . $file . "';\n" );
		$port = random_int( 20000, 40000 );
		$proc = proc_open(
			array( PHP_BINARY, '-S', '127.0.0.1:' . $port, $router ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $proc );
		try {
			$body    = false;
			$headers = array();
			$ctx     = stream_context_create(
				array(
					'http' => array(
						'ignore_errors' => true,
						'timeout'       => 2,
					),
				)
			);
			for ( $i = 0; $i < 50 && false === $body; $i++ ) {
				usleep( 100000 );
				$body    = @file_get_contents( 'http://127.0.0.1:' . $port . '/', false, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the server may not be up yet.
				$headers = $http_response_header ?? array();
			}
		} finally {
			proc_terminate( $proc );
			array_map( 'fclose', $pipes );
			proc_close( $proc );
		}

		$this->assertIsString( $body, 'the server answered' );
		$this->assertStringContainsString( ' 503 ', $headers[0] );
		$want = array(
			'Retry-After: ' . Foundry_Toolkit_Dropins::FILES[ $file ]['retry'],
			'Cache-Control: no-store',
			'X-Foundry-Status: ' . $status,
			'Content-Type: text/html; charset=utf-8',
		);
		foreach ( $want as $h ) {
			$this->assertContains( $h, $headers );
		}
		foreach ( $says as $text ) {
			$this->assertStringContainsString( $text, $body );
		}
		foreach ( $not as $text ) {
			$this->assertStringNotContainsString( $text, $body );
		}
		$this->assertStringNotContainsString( 'Written by Foundry Toolkit', $body, 'the PHP comment stays out of the page' );
		$this->assertDoesNotMatchRegularExpression( '/(src|href)=["\']https?:/', $body, 'nothing loaded from outside the file' );
	}

	public function test_pages_call_no_wordpress_function(): void {
		Foundry_Toolkit_Dropins::install();
		foreach ( array_keys( Foundry_Toolkit_Dropins::FILES ) as $file ) {
			$php = (string) strstr( (string) file_get_contents( WP_CONTENT_DIR . '/' . $file ), '?>', true );
			preg_match_all( '/\b([a-z_]+)\s*\(/', $php, $m );
			$calls = array_values( array_unique( $m[1] ) );
			sort( $calls );
			$this->assertSame( array( 'defined', 'header', 'headers_sent', 'http_response_code', 'if' ), $calls, $file );
		}
	}
}
