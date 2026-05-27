<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use Uimptr_Test_Json_Response;
use UrlImageImporter\Ajax\AjaxHandler;

class AjaxHandlerTest extends WpTestCase {
	public function test_register_attaches_expected_ajax_hooks(): void {
		AjaxHandler::register();

		foreach (
			array(
				'wp_ajax_uimptr_bfu_file_scan'          => 'handle_file_scan',
				'wp_ajax_uimptr_import_single_url'      => 'handle_import_single_url',
				'wp_ajax_uimptr_batch_import'           => 'handle_batch_import',
				'wp_ajax_uimptr_cancel_import'          => 'handle_cancel_import',
				'wp_ajax_uimptr_start_xml_import'       => 'handle_start_xml_import',
				'wp_ajax_uimptr_process_xml_import'     => 'handle_process_xml_import',
				'wp_ajax_uimptr_start_csv_import'       => 'handle_start_csv_import',
				'wp_ajax_uimptr_process_csv_import'     => 'handle_process_csv_import',
				'wp_ajax_uimptr_get_import_progress'    => 'handle_get_import_progress',
				'wp_ajax_uimptr_stop_import'            => 'handle_stop_import',
				'wp_ajax_uimptr_subscribe_dismiss'      => 'handle_subscribe_dismiss',
				'wp_ajax_uimptr_test_connection'        => 'handle_test_ajax_connection',
			) as $hook => $method
		) {
			$this->assertArrayHasKey( $hook, $GLOBALS['uimptr_test_actions'] );
			$this->assertSame( array( AjaxHandler::class, $method ), $GLOBALS['uimptr_test_actions'][ $hook ][0]['callback'] );
		}
	}

	public function test_get_import_progress_requires_import_id(): void {
		$_POST['import_id'] = '';

		try {
			AjaxHandler::handle_get_import_progress();
			$this->fail( 'Expected JSON error response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertFalse( $response->success );
			$this->assertSame( array( 'message' => 'Invalid import ID' ), $response->data );
		}
	}

	public function test_get_import_progress_returns_stored_progress(): void {
		$_POST['import_id'] = 'import-1';
		$progress = array(
			'total'     => 3,
			'processed' => 1,
			'status'    => 'in_progress',
		);
		set_transient( \uimptr_get_legacy_import_progress_transient_key( 'import-1' ), $progress, HOUR_IN_SECONDS );

		try {
			AjaxHandler::handle_get_import_progress();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( $progress, $response->data );
		}
	}

	public function test_stop_import_deletes_legacy_progress_and_url_transients(): void {
		$_POST['import_id'] = 'import-2';
		set_transient( \uimptr_get_legacy_import_progress_transient_key( 'import-2' ), array( 'status' => 'in_progress' ), HOUR_IN_SECONDS );
		set_transient( \uimptr_get_legacy_import_urls_transient_key( 'import-2' ), array( 'https://example.test/a.jpg' ), HOUR_IN_SECONDS );

		try {
			AjaxHandler::handle_stop_import();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( array( 'message' => 'Import stopped' ), $response->data );
		}

		$this->assertFalse( get_transient( \uimptr_get_legacy_import_progress_transient_key( 'import-2' ) ) );
		$this->assertFalse( get_transient( \uimptr_get_legacy_import_urls_transient_key( 'import-2' ) ) );
	}

	public function test_subscribe_dismiss_updates_current_user_option(): void {
		try {
			AjaxHandler::handle_subscribe_dismiss();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertNull( $response->data );
		}

		$this->assertSame( 1, $GLOBALS['uimptr_test_user_options'][7]['bfu_subscribe_notice_dismissed'] );
	}

	public function test_ajax_connection_test_returns_success_message(): void {
		try {
			AjaxHandler::handle_test_ajax_connection();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( array( 'message' => 'AJAX connection successful' ), $response->data );
		}
	}
}
