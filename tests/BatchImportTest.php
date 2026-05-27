<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use Uimptr_Test_Json_Response;

class BatchImportTest extends WpTestCase {
	public function test_batch_import_imports_url_updates_stats_and_mapping_export(): void {
		$url = 'https://cdn.example.test/batch/photo.png';
		$this->mockHttpResponse( $url, $this->pngBytes(), 200, array( 'content-type' => 'image/png' ) );
		$_POST = array(
			'batch_id'       => 'batch-1',
			'start_index'    => '0',
			'batch_size'     => '1',
			'urls'           => wp_slash(
				wp_json_encode(
					array(
						array(
							'url'      => $url,
							'metadata' => array( 'title' => 'Batch Photo' ),
						),
					)
				)
			),
			'preserve_dates' => 'false',
		);

		try {
			\uimptr_ajax_batch_import();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( 'batch-1', $response->data['batch_id'] );
			$this->assertSame( 1, $response->data['processed'] );
			$this->assertSame( 1, $response->data['total'] );
			$this->assertTrue( $response->data['is_complete'] );
			$this->assertSame( array( 'success' => 1, 'failed' => 0, 'skipped' => 0 ), $response->data['stats'] );
			$this->assertTrue( $response->data['mapping_available'] );
			$this->assertSame( 1, $response->data['mapping_rows'] );
		}

		$this->assertFalse( get_transient( \uimptr_get_batch_urls_transient_key( 'batch-1' ) ) );
		$this->assertFalse( get_transient( \uimptr_get_batch_stats_transient_key( 'batch-1' ) ) );
		$mapping = \uimptr_get_mapping_export_info( 'batch-1' );
		$this->assertIsArray( $mapping );
		$this->assertSame( 1, $mapping['row_count'] );
	}

	public function test_batch_import_skips_existing_attachment_and_records_mapping(): void {
		$url = 'https://cdn.example.test/existing.jpg';
		$GLOBALS['wpdb']->source_url_matches[ $url ] = 55;
		$GLOBALS['uimptr_test_attachment_urls'][55] = 'https://example.test/wp-content/uploads/existing.jpg';
		$_POST = array(
			'batch_id'    => 'batch-skip',
			'start_index' => '0',
			'batch_size'  => '1',
			'urls'        => wp_json_encode(
				array(
					array(
						'url'      => $url,
						'metadata' => array(),
					),
				)
			),
		);

		try {
			\uimptr_ajax_batch_import();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( array( 'success' => 0, 'failed' => 0, 'skipped' => 1 ), $response->data['stats'] );
			$this->assertSame( 1, $response->data['mapping_rows'] );
		}
	}

	public function test_batch_import_treats_unsupported_google_drive_rows_as_skipped(): void {
		$url = 'https://docs.google.com/document/d/doc123/edit';
		$_POST = array(
			'batch_id'    => 'batch-drive-skip',
			'start_index' => '0',
			'batch_size'  => '1',
			'import_type' => 'csv',
			'urls'        => wp_json_encode(
				array(
					array(
						'url'      => $url,
						'metadata' => array(),
					),
				)
			),
		);

		try {
			\uimptr_ajax_batch_import();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( array( 'success' => 0, 'failed' => 0, 'skipped' => 1 ), $response->data['stats'] );
			$this->assertSame( 0, $response->data['mapping_rows'] );
			$this->assertCount( 1, $response->data['skipped_messages'] );
			$this->assertStringContainsString( 'Google Docs, Sheets, Slides, Forms, and Drawings are not supported.', $response->data['skipped_messages'][0] );
		}
	}

	public function test_batch_import_reports_cached_session_expiry_on_subsequent_request(): void {
		$_POST = array(
			'batch_id'    => 'expired',
			'start_index' => '1',
			'batch_size'  => '1',
		);

		try {
			\uimptr_ajax_batch_import();
			$this->fail( 'Expected JSON error response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertFalse( $response->success );
			$this->assertSame( 'Import session expired. Please restart the import.', $response->data );
		}
	}

	public function test_cancel_import_sets_cancel_flag_and_cleans_batch_artifacts(): void {
		$temp_file = \uimptr_tests_base_temp_dir() . '/uploaded.csv';
		file_put_contents( $temp_file, 'temporary' );
		set_transient(
			\uimptr_get_temp_file_transient_key( 'batch-cancel' ),
			array( 'path' => $temp_file ),
			HOUR_IN_SECONDS
		);
		set_transient( \uimptr_get_batch_urls_transient_key( 'batch-cancel' ), array( 'url' ), HOUR_IN_SECONDS );
		$mapping = \uimptr_initialize_mapping_export( 'batch-cancel' );
		$_POST['batch_id'] = 'batch-cancel';

		try {
			\uimptr_ajax_cancel_import();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( array( 'message' => 'Import cancellation requested' ), $response->data );
		}

		$this->assertTrue( get_transient( \uimptr_get_batch_cancel_transient_key( 'batch-cancel' ) ) );
		$this->assertFalse( get_transient( \uimptr_get_temp_file_transient_key( 'batch-cancel' ) ) );
		$this->assertFalse( get_transient( \uimptr_get_batch_urls_transient_key( 'batch-cancel' ) ) );
		$this->assertFileDoesNotExist( $temp_file );
		$this->assertFileDoesNotExist( $mapping['path'] );
		$this->assertNull( \uimptr_get_mapping_export_info( 'batch-cancel' ) );
	}
}
