<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use UrlImageImporter\Importer\DropboxFolderEnumerator;
use UrlImageImporter\Importer\DropboxFolderSync;
use WP_Error;

class DropboxImportTest extends WpTestCase {

	private const FOLDER = 'https://www.dropbox.com/scl/fo/rjq4a3npnww5l16obfwkl/ACOKIj8gArsSRaqfYt98Spw?rlkey=fv5j38apxuvwo8tao1do5ybfq&dl=0';
	private const FILE   = 'https://www.dropbox.com/scl/fi/0u86z0gv1se65duf7ndc3/Secure-Web-Form.jpg?rlkey=y0sjcilosd3n7qfgwz9xvtlc5&st=s9a55ajr&dl=0';
	// A file *inside* a shared folder: same /scl/fo/ prefix as the folder itself.
	private const FILE_IN_FOLDER = 'https://www.dropbox.com/scl/fo/rjq4a3npnww5l16obfwkl/AC7PyK5Kezuf94t9TTfe_cg/Hosting.jpg?rlkey=fv5j38apxuvwo8tao1do5ybfq&dl=0';

	public function test_recognizes_dropbox_urls(): void {
		$this->assertTrue( uimptr_is_dropbox_url( self::FILE ) );
		$this->assertTrue( uimptr_is_dropbox_url( self::FOLDER ) );
		$this->assertFalse( uimptr_is_dropbox_url( 'https://example.com/a.jpg' ) );
	}

	public function test_files_inside_a_shared_folder_are_not_treated_as_folders(): void {
		// Regression: files in a shared folder share the /scl/fo/ prefix, so a
		// prefix-only check rejected every file and imported nothing.
		$this->assertFalse( uimptr_is_dropbox_folder_url( self::FILE_IN_FOLDER ) );
		$this->assertTrue( uimptr_is_dropbox_folder_url( self::FOLDER ) );
		$this->assertFalse( uimptr_is_dropbox_folder_url( self::FILE ) );
	}

	public function test_file_in_folder_resolves_to_a_download_url(): void {
		$url = uimptr_get_dropbox_download_url( self::FILE_IN_FOLDER );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'raw=1', $url );
		$this->assertStringNotContainsString( 'dl=0', $url );
	}

	public function test_folder_link_is_rejected_for_single_file_import(): void {
		$result = uimptr_get_dropbox_download_url( self::FOLDER );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dropbox_folder_not_supported', $result->get_error_code() );
	}

	public function test_dedupe_key_survives_link_reissue(): void {
		// Dropbox rotates st and rlkey can be regenerated; neither means the
		// file changed, so both must produce the same dedupe key.
		$reissued = str_replace(
			array( 'rlkey=y0sjcilosd3n7qfgwz9xvtlc5', 'st=s9a55ajr' ),
			array( 'rlkey=BRANDNEWKEY', 'st=CHANGED' ),
			self::FILE
		);

		$this->assertSame(
			uimptr_normalize_source_url( self::FILE ),
			uimptr_normalize_source_url( $reissued )
		);
	}

	public function test_dropbox_files_do_not_fall_back_to_filename_dedupe(): void {
		$this->assertFalse( uimptr_url_supports_filename_dedupe( self::FILE ) );
	}

	public function test_dropbox_files_are_csv_import_candidates(): void {
		$this->assertTrue( uimptr_is_csv_image_import_candidate_url( self::FILE ) );
		$this->assertFalse( uimptr_is_csv_image_import_candidate_url( self::FOLDER ) );
	}

	public function test_parses_shared_folder_url(): void {
		$parts = DropboxFolderEnumerator::parse_folder_url( self::FOLDER );

		$this->assertSame( 'rjq4a3npnww5l16obfwkl', $parts['link_key'] );
		$this->assertSame( 'ACOKIj8gArsSRaqfYt98Spw', $parts['secure_hash'] );
		$this->assertSame( 'fv5j38apxuvwo8tao1do5ybfq', $parts['rlkey'] );
	}

	public function test_rejects_non_folder_urls(): void {
		$result = DropboxFolderEnumerator::parse_folder_url( 'https://example.com/nope' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dropbox_folder_invalid_url', $result->get_error_code() );
	}

	public function test_folder_identity_ignores_reissued_rlkey(): void {
		$a = DropboxFolderSync::identify_folder( self::FOLDER );
		$b = DropboxFolderSync::identify_folder(
			str_replace( 'rlkey=fv5j38apxuvwo8tao1do5ybfq', 'rlkey=DIFFERENT', self::FOLDER )
		);

		$this->assertSame( $a, $b );
	}

	public function test_dropbox_and_drive_folders_use_separate_storage(): void {
		$this->assertNotSame(
			DropboxFolderSync::OPTION_FOLDERS,
			\UrlImageImporter\Importer\GoogleDriveFolderSync::OPTION_FOLDERS
		);
		$this->assertNotSame(
			DropboxFolderSync::CRON_HOOK,
			\UrlImageImporter\Importer\GoogleDriveFolderSync::CRON_HOOK
		);
	}
}
