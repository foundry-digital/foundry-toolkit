<?php
/**
 * No video uploads (ticket 076 in the Site Manager repository, ADR 0039).
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minute of phone video is 100 MB or more: it fills the host's disk and
 * backups and plays badly from a shared host, so video goes to YouTube or
 * Vimeo instead (James, 2026-10-25). WordPress's upload handler refuses any
 * video, the media library, the editor, WooCommerce's downloadable files,
 * the REST media route and files fetched from a URL included, for everyone
 * until FOUNDRY_ALLOW_VIDEO_UPLOADS is true.
 *
 * The upload_mimes list is left alone on purpose: Gravity Forms checks its file
 * fields against that list, and they never use this handler, so forms keep
 * their own allowed types. Existing videos and embeds are untouched.
 */
final class Foundry_Toolkit_Upload_Lock {

	/**
	 * Register the hooks. Called from hardening, which reads the switch.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'refuse_video' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( __CLASS__, 'refuse_video' ) );
	}

	/**
	 * Filter wp_handle_upload_prefilter and wp_handle_sideload_prefilter: a
	 * video gets an error, which WordPress shows instead of saving the file.
	 * A file that already has an error keeps it.
	 *
	 * @param array<string, mixed> $file The file, as $_FILES holds it.
	 * @return array<string, mixed>
	 */
	public static function refuse_video( $file ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || ! self::is_video( $file ) ) {
			return $file;
		}
		$file['error'] = __( 'Video files can\'t be uploaded to this site. Upload the video to YouTube or Vimeo and embed it, or ask Foundry Digital.', 'foundry-toolkit' );
		return $file;
	}

	/**
	 * Whether a file is a video: its extension is one WordPress maps to a
	 * video type, or the browser sent a video type. Read from WordPress's
	 * own list, so a video type it adds later is covered.
	 *
	 * @param array<string, mixed> $file The file, as $_FILES holds it.
	 * @return bool
	 */
	public static function is_video( $file ) {
		$type = isset( $file['type'] ) && is_string( $file['type'] ) ? strtolower( $file['type'] ) : '';
		if ( 0 === strpos( $type, 'video/' ) ) {
			return true;
		}
		$name = isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}
		foreach ( wp_get_mime_types() as $exts => $mime ) {
			if ( 0 === strpos( $mime, 'video/' ) && in_array( $ext, explode( '|', $exts ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
