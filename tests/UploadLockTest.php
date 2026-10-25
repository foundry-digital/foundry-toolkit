<?php
/**
 * No video uploads (ticket 076, ADR 0039): WordPress's upload handler
 * refuses video files unless wp-config.php unlocks them for a moment.
 * Gravity Forms file fields never use that handler and are untouched.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Foundry_Toolkit_Upload_Lock
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class UploadLockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'plugin_basename' )->justReturn( 'foundry-toolkit/foundry-toolkit.php' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		// A slice of WordPress's own list, keyed as wp_get_mime_types() keys it.
		Functions\when( 'wp_get_mime_types' )->justReturn(
			array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'asf|asx'      => 'video/x-ms-asf',
				'wmv'          => 'video/x-ms-wmv',
				'avi'          => 'video/avi',
				'mov|qt'       => 'video/quicktime',
				'mpeg|mpg|mpe' => 'video/mpeg',
				'mp4|m4v'      => 'video/mp4',
				'ogv'          => 'video/ogg',
				'webm'         => 'video/webm',
				'mkv'          => 'video/x-matroska',
				'3gp|3gpp'     => 'video/3gpp',
				'mp3|m4a|m4b'  => 'audio/mpeg',
				'pdf'          => 'application/pdf',
				'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'zip'          => 'application/zip',
			)
		);
		require_once __DIR__ . '/../includes/class-foundry-toolkit-upload-lock.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A file as $_FILES holds it.
	 *
	 * @param string $name The file name.
	 * @param string $type The type the browser sent.
	 * @return array<string, mixed>
	 */
	private function file( string $name, string $type = 'application/octet-stream' ): array {
		return array(
			'name'     => $name,
			'type'     => $type,
			'tmp_name' => '/tmp/php123',
			'error'    => 0,
			'size'     => 1024,
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function videos(): array {
		return array(
			'mp4'                      => array( 'clip.mp4', 'video/mp4' ),
			'mov from a phone'         => array( 'IMG_0042.MOV', 'video/quicktime' ),
			'm4v'                      => array( 'clip.m4v', 'video/x-m4v' ),
			'webm'                     => array( 'clip.webm', 'video/webm' ),
			'avi'                      => array( 'clip.avi', 'video/x-msvideo' ),
			'mkv'                      => array( 'clip.mkv', 'video/x-matroska' ),
			'wmv'                      => array( 'clip.wmv', 'video/x-ms-wmv' ),
			'3gp'                      => array( 'clip.3gp', 'video/3gpp' ),
			'renamed, sent as a video' => array( 'holiday.dat', 'video/quicktime' ),
			'no type from the browser' => array( 'clip.mp4', '' ),
		);
	}

	/** @dataProvider videos */
	public function test_videos_are_refused( string $name, string $type ): void {
		$got = Foundry_Toolkit_Upload_Lock::refuse_video( $this->file( $name, $type ) );
		$this->assertIsString( $got['error'] );
		$this->assertStringContainsString( 'YouTube or Vimeo', $got['error'] );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function everything_else(): array {
		return array(
			'jpg'         => array( 'photo.jpg', 'image/jpeg' ),
			'png'         => array( 'logo.PNG', 'image/png' ),
			'pdf'         => array( 'report.pdf', 'application/pdf' ),
			'docx'        => array( 'letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			'mp3'         => array( 'podcast.mp3', 'audio/mpeg' ),
			'm4a'         => array( 'voice.m4a', 'audio/x-m4a' ),
			'zip'         => array( 'download.zip', 'application/zip' ),
			'no dot'      => array( 'README', '' ),
			'mp4 in name' => array( 'mp4-guide.pdf', 'application/pdf' ),
		);
	}

	/** @dataProvider everything_else */
	public function test_everything_else_goes_through( string $name, string $type ): void {
		$file = $this->file( $name, $type );
		$this->assertSame( $file, Foundry_Toolkit_Upload_Lock::refuse_video( $file ) );
	}

	public function test_an_earlier_error_is_kept(): void {
		$file          = $this->file( 'clip.mp4', 'video/mp4' );
		$file['error'] = 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
		$this->assertSame( $file, Foundry_Toolkit_Upload_Lock::refuse_video( $file ) );
	}

	public function test_both_upload_handlers_are_guarded_but_not_the_type_list(): void {
		Foundry_Toolkit_Upload_Lock::boot();
		$this->assertNotFalse( has_filter( 'wp_handle_upload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
		$this->assertNotFalse( has_filter( 'wp_handle_sideload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
		// Gravity Forms checks files against WordPress's list of allowed types.
		$this->assertFalse( has_filter( 'upload_mimes' ) );
	}

	public function test_hardening_locks_video_by_default(): void {
		Foundry_Toolkit_Hardening::boot();
		$this->assertFalse( FOUNDRY_ALLOW_VIDEO_UPLOADS );
		$this->assertNotFalse( has_filter( 'wp_handle_upload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
	}

	public function test_a_local_site_is_locked_too(): void {
		Functions\when( 'home_url' )->justReturn( 'https://achper.local' );
		Foundry_Toolkit_Hardening::boot();
		$this->assertNotFalse( has_filter( 'wp_handle_upload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
	}

	public function test_wp_config_unlocks_video(): void {
		define( 'FOUNDRY_ALLOW_VIDEO_UPLOADS', true );
		Foundry_Toolkit_Hardening::boot();
		$this->assertFalse( has_filter( 'wp_handle_upload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
	}

	public function test_only_true_unlocks(): void {
		define( 'FOUNDRY_ALLOW_VIDEO_UPLOADS', 'true' ); // A string must not unlock.
		Foundry_Toolkit_Hardening::boot();
		$this->assertNotFalse( has_filter( 'wp_handle_upload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
	}

	public function test_wp_cli_is_left_alone(): void {
		define( 'WP_CLI', true );
		Foundry_Toolkit_Hardening::boot();
		$this->assertFalse( has_filter( 'wp_handle_upload_prefilter', array( Foundry_Toolkit_Upload_Lock::class, 'refuse_video' ) ) );
	}
}
