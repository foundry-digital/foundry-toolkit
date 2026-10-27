<?php
/**
 * Our own pages for a database outage and maintenance mode: drop-ins in
 * wp-content that WordPress shows instead of its own screens.
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes, checks and removes wp-content/db-error.php and maintenance.php.
 * WordPress includes them before any plugin loads (dead_db() and
 * wp_maintenance()), so the toolkit writes them beforehand, the same way it
 * writes its loader. They cannot read the database, so they hold nothing per
 * site. Site Manager ticket 080.
 */
final class Foundry_Toolkit_Dropins {

	/**
	 * Each drop-in: its words, how long to tell browsers to wait (seconds,
	 * maintenance as WordPress's own page) and the X-Foundry-Status Site
	 * Manager's uptime monitor reads to name the cause.
	 */
	public const FILES = array(
		'db-error.php'    => array(
			'title'     => 'Back shortly',
			'heading'   => 'We&rsquo;ll be right back',
			'paragraph' => 'This page is currently unavailable. Please check back shortly.',
			'retry'     => 300,
			'status'    => 'database-error',
		),
		'maintenance.php' => array(
			'title'     => 'Just a moment',
			'heading'   => 'Just a moment',
			'paragraph' => 'We&rsquo;re currently making some updates. Please check back soon.',
			'retry'     => 600,
			'status'    => 'maintenance',
		),
	);

	/**
	 * Full path of a drop-in.
	 *
	 * @param string $file A key of FILES.
	 * @return string
	 */
	public static function path( $file ) {
		return WP_CONTENT_DIR . '/' . $file;
	}

	/**
	 * A drop-in's source: the headers, then the page. Every value comes from
	 * FILES, so nothing needs escaping.
	 *
	 * @param string $file A key of FILES.
	 * @return string
	 */
	public static function contents( $file ) {
		$page = self::FILES[ $file ];
		$html = (string) file_get_contents( dirname( __DIR__ ) . '/templates/unavailable-page.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file.
		$html = str_replace(
			array( '{{TITLE}}', '{{HEADING}}', '{{PARAGRAPH}}' ),
			array( $page['title'], $page['heading'], $page['paragraph'] ),
			$html
		);
		return '<?php
/**
 * Shown by WordPress instead of its own ' . $file . ' screen. ' . Foundry_Toolkit_Loader::MARKER . '; removed when Foundry Toolkit is deactivated. Do not edit.
 *
 * @package FoundryToolkit
 */

if ( ! defined( \'ABSPATH\' ) ) {
	exit;
}
if ( ! headers_sent() ) {
	http_response_code( 503 );
	header( \'Retry-After: ' . $page['retry'] . '\' );
	header( \'Content-Type: text/html; charset=utf-8\' );
	header( \'Cache-Control: no-store\' );
	header( \'X-Foundry-Status: ' . $page['status'] . '\' );
}
?>
' . $html;
	}

	/**
	 * Whether a drop-in is there and exactly what this version writes.
	 *
	 * @param string $file A key of FILES.
	 * @return bool
	 */
	public static function is_current( $file ) {
		$path = self::path( $file );
		return is_file( $path ) && file_get_contents( $path ) === self::contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file.
	}

	/**
	 * Whether a drop-in is there and was not written by us: a host or another
	 * plugin put it there.
	 *
	 * @param string $file A key of FILES.
	 * @return bool
	 */
	private static function foreign( $file ) {
		$path = self::path( $file );
		return is_file( $path ) && false === strpos( (string) file_get_contents( $path ), Foundry_Toolkit_Loader::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file.
	}

	/**
	 * Drop-ins someone else's file is in the way of, for the admin notice.
	 *
	 * @return string[]
	 */
	public static function blocked() {
		return array_values( array_filter( array_keys( self::FILES ), array( __CLASS__, 'foreign' ) ) );
	}

	/**
	 * Write every drop-in that is not someone else's.
	 *
	 * @return bool Whether every drop-in is now ours and current.
	 */
	public static function install() {
		$ok = true;
		foreach ( array_keys( self::FILES ) as $file ) {
			if ( self::foreign( $file ) || ! Foundry_Toolkit_Loader::write( self::path( $file ), self::contents( $file ) ) ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether activated for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide ); // One wp-content serves every site in a network.
		self::install();
	}

	/**
	 * Admin hook: rewrite our drop-ins when missing or out of date, for
	 * example after an update changed the page.
	 *
	 * @return void
	 */
	public static function repair() {
		foreach ( array_keys( self::FILES ) as $file ) {
			if ( ! self::foreign( $file ) && ! self::is_current( $file ) ) {
				Foundry_Toolkit_Loader::write( self::path( $file ), self::contents( $file ) );
			}
		}
	}

	/**
	 * Deactivation hook: remove our drop-ins, never someone else's.
	 *
	 * @return void
	 */
	public static function remove() {
		foreach ( array_keys( self::FILES ) as $file ) {
			if ( is_file( self::path( $file ) ) && ! self::foreign( $file ) ) {
				unlink( self::path( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- removing our own file.
			}
		}
	}
}
