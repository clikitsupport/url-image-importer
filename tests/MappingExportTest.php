<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use WP_Error;

class MappingExportTest extends WpTestCase {
	public function test_ensure_temp_directory_creates_directory_and_guard_files(): void {
		$temp_dir = \uimptr_get_local_temp_dir();

		$result = \uimptr_ensure_temp_directory( $temp_dir );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $temp_dir );
		$this->assertFileExists( $temp_dir . '/.htaccess' );
		$this->assertFileExists( $temp_dir . '/index.php' );
		$this->assertSame( 'temp_dir_missing', \uimptr_ensure_temp_directory( '' )->get_error_code() );
	}

	public function test_initialize_mapping_export_creates_header_and_stores_transient(): void {
		$mapping = \uimptr_initialize_mapping_export( 'batch-1' );

		$this->assertIsArray( $mapping );
		$this->assertFileExists( $mapping['path'] );
		$this->assertSame( 0, $mapping['row_count'] );
		$this->assertSame( 'batch-1', $mapping['batch_id'] );
		$this->assertSame( $mapping, \uimptr_get_mapping_export_info( 'batch-1' ) );

		$handle = fopen( $mapping['path'], 'r' );
		$this->assertSame( array( 'Old URL (external)', 'New URL (local WP)' ), fgetcsv( $handle, 0, ',', '"', '\\' ) );
		fclose( $handle );
	}

	public function test_initialize_mapping_export_reuses_existing_live_export(): void {
		$first = \uimptr_initialize_mapping_export( 'batch-2' );
		$again = \uimptr_initialize_mapping_export( 'batch-2' );

		$this->assertSame( $first, $again );
	}

	public function test_append_mapping_export_row_initializes_and_escapes_formula_values(): void {
		$mapping = \uimptr_append_mapping_export_row( 'batch-3', '=HYPERLINK("bad")', ' https://example.test/new.png' );

		$this->assertIsArray( $mapping );
		$this->assertSame( 1, $mapping['row_count'] );

		$handle = fopen( $mapping['path'], 'r' );
		$this->assertSame( array( 'Old URL (external)', 'New URL (local WP)' ), fgetcsv( $handle, 0, ',', '"', '\\' ) );
		$this->assertSame( array( '\'=HYPERLINK("bad")', ' https://example.test/new.png' ), fgetcsv( $handle, 0, ',', '"', '\\' ) );
		fclose( $handle );

		$mapping = \uimptr_append_mapping_export_row( 'batch-3', 'https://example.test/old.png', '@local' );
		$this->assertSame( 2, $mapping['row_count'] );
	}

	public function test_append_mapping_export_row_with_empty_values_returns_existing_info(): void {
		$mapping = \uimptr_initialize_mapping_export( 'batch-empty' );

		$this->assertSame( $mapping, \uimptr_append_mapping_export_row( 'batch-empty', '', 'https://example.test/new.png' ) );
		$this->assertSame( $mapping, \uimptr_append_mapping_export_row( 'batch-empty', 'https://example.test/old.png', '' ) );
	}

	public function test_cleanup_mapping_export_deletes_file_and_transient(): void {
		$mapping = \uimptr_initialize_mapping_export( 'batch-clean' );

		\uimptr_cleanup_mapping_export( 'batch-clean', true );

		$this->assertFileDoesNotExist( $mapping['path'] );
		$this->assertNull( \uimptr_get_mapping_export_info( 'batch-clean' ) );
	}

	public function test_stream_mapping_download_returns_errors_for_invalid_requests(): void {
		$this->assertSame( 'invalid_mapping_download_batch', \uimptr_stream_mapping_csv_download( '' )->get_error_code() );
		$this->assertSame( 'mapping_export_expired', \uimptr_stream_mapping_csv_download( 'missing' )->get_error_code() );

		$temp_dir = \uimptr_get_local_temp_dir();
		\uimptr_ensure_temp_directory( $temp_dir );
		$outside = dirname( $temp_dir ) . '/outside.csv';
		file_put_contents( $outside, "old,new\n" );
		set_transient(
			\uimptr_get_mapping_transient_key( 'outside' ),
			array(
				'path'      => $outside,
				'row_count' => 1,
			),
			DAY_IN_SECONDS
		);

		$result = \uimptr_stream_mapping_csv_download( 'outside' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_mapping_export_path', $result->get_error_code() );
	}

	public function test_mapping_download_request_validation_checks_nonce_and_capability(): void {
		$_REQUEST['nonce'] = 'nonce-uimptr_ajax';
		$this->assertTrue( \uimptr_validate_mapping_download_request() );

		$GLOBALS['uimptr_test_nonce_valid'] = false;
		$result = \uimptr_validate_mapping_download_request();
		$this->assertSame( 'invalid_mapping_download_nonce', $result->get_error_code() );

		$GLOBALS['uimptr_test_nonce_valid']      = true;
		$GLOBALS['uimptr_test_current_user_can'] = false;
		$result = \uimptr_validate_mapping_download_request();
		$this->assertSame( 'mapping_download_permission_denied', $result->get_error_code() );
	}

	public function test_mapping_download_batch_id_is_sanitized_from_request(): void {
		$_REQUEST['batch_id'] = ' Batch\\ <b>99</b> ';

		$this->assertSame( 'Batch 99', \uimptr_get_mapping_download_batch_id() );
	}
}
