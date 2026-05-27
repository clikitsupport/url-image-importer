<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;

class PluginFunctionsTest extends WpTestCase {
	public function test_ajax_nonce_helpers_use_shared_action_and_field(): void {
		$this->assertSame( 'uimptr_ajax', \uimptr_get_ajax_nonce_action() );
		$this->assertSame( 'nonce', \uimptr_get_ajax_nonce_field() );
		$this->assertSame( 'nonce-uimptr_ajax', \uimptr_create_ajax_nonce() );

		$_REQUEST['nonce'] = 'nonce-uimptr_ajax';

		$this->assertTrue( \uimptr_verify_ajax_request_nonce() );
	}

	public function test_ajax_request_nonce_is_unslashed_and_sanitized(): void {
		$_REQUEST['custom_nonce'] = ' nonce-\\<b>uimptr_ajax</b> ';

		$this->assertSame( 'nonce-uimptr_ajax', \uimptr_get_ajax_request_nonce( 'custom_nonce' ) );
	}

	public function test_batch_seed_is_hex_encoded_random_seed(): void {
		$seed = \uimptr_create_batch_id_seed();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $seed );
	}

	public function test_image_mime_type_mapping_handles_case_and_parameters(): void {
		$this->assertSame( 'jpg', \uimptr_get_image_extension_from_mime_type( ' Image/JPEG; charset=binary ' ) );
		$this->assertSame( 'svg', \uimptr_get_image_extension_from_mime_type( 'image/svg+xml' ) );
		$this->assertSame( '', \uimptr_get_image_extension_from_mime_type( 'application/pdf' ) );
	}

	public function test_titles_strip_only_trailing_image_extensions(): void {
		$this->assertSame( 'Hero Image', \uimptr_maybe_strip_image_extension_from_title( 'Hero Image.PNG' ) );
		$this->assertSame( 'archive.zip', \uimptr_maybe_strip_image_extension_from_title( 'archive.zip' ) );
		$this->assertSame( '.jpg', \uimptr_maybe_strip_image_extension_from_title( '.jpg' ) );
	}

	public function test_attachment_slug_replaces_dot_runs_before_title_sanitization(): void {
		$this->assertSame( 'my-photo-final', \uimptr_sanitize_attachment_slug_from_title( 'My.Photo...Final' ) );
		$this->assertSame( '', \uimptr_sanitize_attachment_slug_from_title( '   ' ) );
	}

	public function test_content_disposition_filename_parsing_supports_rfc5987_and_quoted_values(): void {
		$this->assertSame(
			'hero-image.png',
			\uimptr_get_filename_from_content_disposition( "attachment; filename*=UTF-8''hero%20image.png" )
		);
		$this->assertSame(
			'fallback-name.jpg',
			\uimptr_get_filename_from_content_disposition( 'attachment; filename="fallback name.jpg"' )
		);
		$this->assertSame( '', \uimptr_get_filename_from_content_disposition( '' ) );
	}

	public function test_google_drive_helpers_resolve_file_links_and_canonical_source_urls(): void {
		$url = 'https://drive.google.com/file/d/abc_123-XYZ/view?usp=sharing&resourcekey=0-key';

		$this->assertTrue( \uimptr_is_google_drive_url( $url ) );
		$this->assertSame( 'abc_123-XYZ', \uimptr_extract_google_drive_file_id( $url ) );
		$this->assertSame( 'https://drive.google.com/file/d/abc_123-XYZ/view', \uimptr_normalize_source_url( $url ) );
		$this->assertFalse( \uimptr_url_supports_filename_dedupe( $url ) );

		$download_url = \uimptr_get_google_drive_download_url( $url );
		parse_str( (string) parse_url( $download_url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://drive.google.com/uc?', $download_url );
		$this->assertSame( 'download', $query['export'] );
		$this->assertSame( 'abc_123-XYZ', $query['id'] );
		$this->assertSame( '0-key', $query['resourcekey'] );
	}

	public function test_google_drive_helpers_reject_folders_and_workspace_links(): void {
		$folder = \uimptr_extract_google_drive_file_id( 'https://drive.google.com/drive/u/0/folders/folder123' );
		$doc = \uimptr_extract_google_drive_file_id( 'https://docs.google.com/document/d/doc123/edit' );

		$this->assertInstanceOf( \WP_Error::class, $folder );
		$this->assertSame( 'google_drive_folder_not_supported', $folder->get_error_code() );
		$this->assertTrue( \uimptr_is_skippable_import_error( $folder ) );

		$this->assertInstanceOf( \WP_Error::class, $doc );
		$this->assertSame( 'google_drive_workspace_not_supported', $doc->get_error_code() );
		$this->assertTrue( \uimptr_is_skippable_import_error( $doc ) );
	}

	public function test_csv_image_candidates_accept_drive_without_changing_plain_image_detection(): void {
		$drive_url = 'https://drive.google.com/open?id=abc123';

		$this->assertFalse( \uimptr_is_image_url( $drive_url ) );
		$this->assertTrue( \uimptr_is_csv_image_import_candidate_url( $drive_url ) );
		$this->assertFalse( \uimptr_is_csv_image_import_candidate_url( 'https://example.test/file.pdf' ) );
	}

	public function test_per_user_transient_keys_are_sanitized_and_namespaced(): void {
		$GLOBALS['uimptr_test_current_user_id'] = 42;

		$this->assertSame( 'uimptr_urls_42_batch1', \uimptr_get_batch_urls_transient_key( 'Batch 1!' ) );
		$this->assertSame( 'uimptr_mapping_42_batch1', \uimptr_get_mapping_transient_key( 'Batch 1!' ) );
		$this->assertSame( 'uimptr_stats_42_batch1', \uimptr_get_batch_stats_transient_key( 'Batch 1!' ) );
		$this->assertSame( 'uimptr_cancel_42_batch1', \uimptr_get_batch_cancel_transient_key( 'Batch 1!' ) );
		$this->assertSame( 'uimptr_temp_file_42_file1', \uimptr_get_temp_file_transient_key( 'File 1!' ) );
		$this->assertSame( 'uimptr_import_progress_42_import1', \uimptr_get_legacy_import_progress_transient_key( 'Import 1!' ) );
		$this->assertSame( 'uimptr_import_urls_42_import1', \uimptr_get_legacy_import_urls_transient_key( 'Import 1!' ) );
	}

	public function test_mapping_download_url_contains_action_nonce_and_batch_id(): void {
		$url = \uimptr_get_mapping_download_url( 'batch 99', 'known-nonce' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://example.test/wp-admin/admin-post.php', $url );
		$this->assertSame( 'uimptr_download_url_mapping_csv', $query['action'] );
		$this->assertSame( 'known-nonce', $query['nonce'] );
		$this->assertSame( 'batch 99', $query['batch_id'] );
	}

	public function test_spreadsheet_formula_cells_are_escaped_for_csv_exports(): void {
		$this->assertSame( "'=cmd|calc", \uimptr_escape_csv_cell_for_spreadsheet( '=cmd|calc' ) );
		$this->assertSame( "'  +SUM(A1:A2)", \uimptr_escape_csv_cell_for_spreadsheet( '  +SUM(A1:A2)' ) );
		$this->assertSame( "'\t@bad", \uimptr_escape_csv_cell_for_spreadsheet( "\t@bad" ) );
		$this->assertSame( 'https://example.test/image.png', \uimptr_escape_csv_cell_for_spreadsheet( 'https://example.test/image.png' ) );
	}

	public function test_source_url_helpers_normalize_and_limit_filename_dedupe(): void {
		$this->assertSame( 'https://example.test/image.png?size=large', \uimptr_normalize_source_url( ' https://example.test/image.png?size=large ' ) );
		$this->assertTrue( \uimptr_url_supports_filename_dedupe( 'https://example.test/image.png' ) );
		$this->assertFalse( \uimptr_url_supports_filename_dedupe( 'https://example.test/image.png?cache=1' ) );
		$this->assertFalse( \uimptr_url_supports_filename_dedupe( 'https://example.test/image.png#hero' ) );
	}

	public function test_existing_attachment_lookup_prefers_source_url_and_limits_filename_fallback(): void {
		$GLOBALS['wpdb']->source_url_matches['https://cdn.example.test/image.png?size=large'] = 11;
		$GLOBALS['wpdb']->filename_matches['image.png'] = 22;

		$this->assertSame( 11, \uimptr_get_existing_attachment_id_for_url( 'https://cdn.example.test/image.png?size=large' ) );
		$this->assertSame( 0, \uimptr_get_existing_attachment_id_for_url( 'https://other.example.test/image.png?different=1' ) );
		$this->assertSame( 22, \uimptr_get_existing_attachment_id_for_url( 'https://other.example.test/uploads/image.png' ) );
		$this->assertSame( 0, \uimptr_get_existing_attachment_id_for_url( 'https://other.example.test/uploads/' ) );
	}

	public function test_svg_mime_helpers_add_and_detect_svg(): void {
		$mimes = \uimptr_add_svg_mime_type( array( 'jpg' => 'image/jpeg' ) );
		$this->assertSame( 'image/svg+xml', $mimes['svg'] );

		$data = \uimptr_check_svg_filetype(
			array( 'ext' => false, 'type' => false, 'proper_filename' => false ),
			'/tmp/icon.svg',
			'icon.svg',
			$mimes
		);

		$this->assertSame( 'svg', $data['ext'] );
		$this->assertSame( 'image/svg+xml', $data['type'] );
		$this->assertSame( 'icon.svg', $data['proper_filename'] );
	}

	public function test_file_type_helpers_return_expected_labels_and_categories(): void {
		$this->assertSame( 'image', \uimptr_get_file_type( 'PHOTO.JPEG' ) );
		$this->assertSame( 'archive', \uimptr_get_file_type( 'backup.7z' ) );
		$this->assertSame( 'code', \uimptr_get_file_type( 'theme.php' ) );
		$this->assertSame( 'other', \uimptr_get_file_type( 'extensionless' ) );
		$this->assertSame( '#26A9E0', \uimptr_get_file_type_format( 'image', 'color' ) );
		$this->assertSame( 'Other', \uimptr_get_file_type_format( 'unknown', 'label' ) );
	}
}
