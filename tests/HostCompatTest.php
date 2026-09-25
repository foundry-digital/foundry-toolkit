<?php
/**
 * Hosts switch off PHP functions with disable_functions (Kinsta disables
 * getmypid). On PHP 8 calling a disabled function is a fatal error, so the
 * plugin must never call one directly. A test cannot disable a built-in
 * function, so this reads the source instead.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class HostCompatTest extends TestCase {

	/**
	 * Every PHP file the plugin ships.
	 *
	 * @return array<string, string> Path to source.
	 */
	private static function sources(): array {
		$root  = dirname( __DIR__ );
		$files = array_merge( array( $root . '/foundry-toolkit.php' ), glob( $root . '/includes/*.php' ) ?: array(), glob( $root . '/templates/*.php' ) ?: array() );
		$out   = array();
		foreach ( $files as $f ) {
			$out[ $f ] = (string) file_get_contents( $f );
		}
		return $out;
	}

	/**
	 * Functions the plugin must never call: it has no need for them and some
	 * hosts disable them.
	 *
	 * @return array<string, array{string}>
	 */
	public static function forbidden(): array {
		return array(
			'getmypid (disabled on Kinsta)' => array( 'getmypid' ),
			'getmyuid'                      => array( 'getmyuid' ),
			'exec'                          => array( 'exec' ),
			'shell_exec'                    => array( 'shell_exec' ),
			'proc_open'                     => array( 'proc_open' ),
			'posix_getpid'                  => array( 'posix_getpid' ),
		);
	}

	/**
	 * @dataProvider forbidden
	 */
	public function test_never_calls( string $fn ): void {
		foreach ( self::sources() as $path => $src ) {
			$this->assertDoesNotMatchRegularExpression( '/(?<![\w>:$])' . preg_quote( $fn, '/' ) . '\s*\(/', $src, basename( $path ) . " calls $fn()" );
		}
	}

	/**
	 * Functions the plugin may use, but only through a function_exists check,
	 * because hosts commonly disable them.
	 *
	 * @return array<string, array{string}>
	 */
	public static function guarded(): array {
		return array(
			'set_time_limit'    => array( 'set_time_limit' ),
			'ignore_user_abort' => array( 'ignore_user_abort' ),
		);
	}

	/**
	 * @dataProvider guarded
	 */
	public function test_calls_only_behind_function_exists( string $fn ): void {
		foreach ( self::sources() as $path => $src ) {
			$calls   = preg_match_all( '/(?<![\w>:$\'])' . preg_quote( $fn, '/' ) . '\s*\(/', $src );
			$guarded = preg_match_all( "/if \\( function_exists\\( '" . preg_quote( $fn, '/' ) . "' \\) \\) \\{[^\\n]*\\n\\s*" . preg_quote( $fn, '/' ) . '\s*\(/', $src );
			$this->assertSame( $guarded, $calls, basename( $path ) . " calls $fn() without a function_exists check" );
		}
	}
}
