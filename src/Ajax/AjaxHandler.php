<?php
/**
 * Ajax Handler Class
 *
 * @package UrlImageImporter\Ajax
 */

namespace UrlImageImporter\Ajax;

use UrlImageImporter\FileScan\FileScan;
use UrlImageImporter\Importer\ImageImporter;
use UrlImageImporter\Importer\WordPressXmlImporter;
use UrlImageImporter\Importer\CsvImporter;
use UrlImageImporter\Utils\FileSystem;
use UrlImageImporter\Utils\Validation;

/**
 * Class AjaxHandler
 *
 * Handles all AJAX requests for the plugin.
 */
class AjaxHandler {

	/**
	 * Register AJAX handlers
	 */
	public static function register() {
		// File scan
		\add_action( 'wp_ajax_uimptr_bfu_file_scan', array( __CLASS__, 'handle_file_scan' ) );
		
		// Import operations
		\add_action( 'wp_ajax_uimptr_import_single_url', array( __CLASS__, 'handle_import_single_url' ) );
		\add_action( 'wp_ajax_uimptr_batch_import', array( __CLASS__, 'handle_batch_import' ) );
		\add_action( 'wp_ajax_uimptr_cancel_import', array( __CLASS__, 'handle_cancel_import' ) );
		
		// XML import
		\add_action( 'wp_ajax_uimptr_start_xml_import', array( __CLASS__, 'handle_start_xml_import' ) );
		\add_action( 'wp_ajax_uimptr_process_xml_import', array( __CLASS__, 'handle_process_xml_import' ) );
		
		// CSV import
		\add_action( 'wp_ajax_uimptr_start_csv_import', array( __CLASS__, 'handle_start_csv_import' ) );
		\add_action( 'wp_ajax_uimptr_process_csv_import', array( __CLASS__, 'handle_process_csv_import' ) );
		
		// Progress tracking
		\add_action( 'wp_ajax_uimptr_get_import_progress', array( __CLASS__, 'handle_get_import_progress' ) );
		\add_action( 'wp_ajax_uimptr_stop_import', array( __CLASS__, 'handle_stop_import' ) );
		
		// Subscribe notice
		\add_action( 'wp_ajax_uimptr_subscribe_dismiss', array( __CLASS__, 'handle_subscribe_dismiss' ) );
		
		// Testing
		\add_action( 'wp_ajax_uimptr_test_connection', array( __CLASS__, 'handle_test_ajax_connection' ) );
	}

	/**
	 * Handle file scan AJAX request
	 */
	public static function handle_file_scan() {
		$path = FileSystem::get_upload_dir_root();
		$remaining_dirs = array();
		$nonce = isset( $_POST['js_nonce'] ) ? sanitize_text_field( \wp_unslash( $_POST['js_nonce'] ) ) : '';
		
		if ( ! \wp_verify_nonce( $nonce, 'ajax-nonce' ) ) {
			\wp_die( 'Nonce Verification Failed!' );
		}
		
		if ( isset( $_POST['remaining_dirs'] ) && is_array( $_POST['remaining_dirs'] ) ) {
			foreach ( \wp_unslash( $_POST['remaining_dirs'] ) as $dir ) {
				$dir = sanitize_text_field( $dir );
				$realpath = realpath( $path . $dir );
				if ( 0 === strpos( $realpath, $path ) ) {
					$remaining_dirs[] = $dir;
				}
			}
		}
		
		$file_scan = new FileScan( $path, 20, $remaining_dirs );
		$file_scan->start();
		$file_count = \number_format_i18n( $file_scan->get_total_files() );
		$file_size = \size_format( $file_scan->get_total_size(), 2 );
		$remaining_dirs = $file_scan->get_paths_left();
		$is_done = $file_scan->is_done();

		$data = compact( 'file_count', 'file_size', 'is_done', 'remaining_dirs' );

		\wp_send_json_success( $data );
	}

	/**
	 * Handle single URL import AJAX request
	 */
	public static function handle_import_single_url() {
		// Delegate to existing procedural function for now
		// TODO: Move logic into ImageImporter class in future refactor
		if ( function_exists( 'uimptr_ajax_import_single_url' ) ) {
			\uimptr_ajax_import_single_url();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle batch import AJAX request
	 */
	public static function handle_batch_import() {
		// Delegate to existing procedural function
		if ( function_exists( 'uimptr_ajax_batch_import' ) ) {
			\uimptr_ajax_batch_import();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle cancel import AJAX request
	 */
	public static function handle_cancel_import() {
		// Delegate to existing procedural function
		if ( function_exists( 'uimptr_ajax_cancel_import' ) ) {
			\uimptr_ajax_cancel_import();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle XML import start
	 */
	public static function handle_start_xml_import() {
		// Delegate to existing procedural function
		if ( function_exists( 'uimptr_ajax_process_xml_import' ) ) {
			\uimptr_ajax_process_xml_import();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle XML import processing
	 */
	public static function handle_process_xml_import() {
		// Delegate to existing procedural function
		if ( function_exists( 'uimptr_ajax_process_xml_import' ) ) {
			\uimptr_ajax_process_xml_import();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle CSV import start
	 */
	public static function handle_start_csv_import() {
		// Delegate to existing procedural function
		if ( function_exists( 'uimptr_ajax_process_csv_import' ) ) {
			\uimptr_ajax_process_csv_import();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle CSV import processing
	 */
	public static function handle_process_csv_import() {
		// Delegate to existing procedural function
		if ( function_exists( 'uimptr_ajax_process_csv_import' ) ) {
			\uimptr_ajax_process_csv_import();
		} else {
			\wp_send_json_error( array( 'message' => 'Function not found' ) );
		}
	}

	/**
	 * Handle get import progress
	 */
	public static function handle_get_import_progress() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( \wp_unslash( $_POST['import_id'] ) ) : '';
		
		if ( empty( $import_id ) ) {
			\wp_send_json_error( array( 'message' => 'Invalid import ID' ) );
		}

		$progress_key = \function_exists( '\uimptr_get_legacy_import_progress_transient_key' )
			? \uimptr_get_legacy_import_progress_transient_key( $import_id )
			: 'uimptr_import_progress_' . (int) \get_current_user_id() . '_' . sanitize_key( (string) $import_id );
		$progress = \get_transient( $progress_key );

		if ( ! $progress ) {
			\wp_send_json_error( array( 'message' => 'Progress not found' ) );
		}

		\wp_send_json_success( $progress );
	}

	/**
	 * Handle stop import
	 */
	public static function handle_stop_import() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( \wp_unslash( $_POST['import_id'] ) ) : '';

		if ( ! empty( $import_id ) ) {
			$progress_key = \function_exists( '\uimptr_get_legacy_import_progress_transient_key' )
				? \uimptr_get_legacy_import_progress_transient_key( $import_id )
				: 'uimptr_import_progress_' . (int) \get_current_user_id() . '_' . sanitize_key( (string) $import_id );
			$urls_key = \function_exists( '\uimptr_get_legacy_import_urls_transient_key' )
				? \uimptr_get_legacy_import_urls_transient_key( $import_id )
				: 'uimptr_import_urls_' . (int) \get_current_user_id() . '_' . sanitize_key( (string) $import_id );
			\delete_transient( $progress_key );
			\delete_transient( $urls_key );
		}

		\wp_send_json_success( array( 'message' => 'Import stopped' ) );
	}

	/**
	 * Handle subscribe dismiss
	 */
	public static function handle_subscribe_dismiss() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		\update_user_option( \get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );
		\wp_send_json_success();
	}

	/**
	 * Handle AJAX connection test
	 */
	public static function handle_test_ajax_connection() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		\wp_send_json_success( array( 'message' => 'AJAX connection successful' ) );
	}
}
