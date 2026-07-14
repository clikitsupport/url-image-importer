<?php
/**
 * Main Plugin Class
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Core;

use UrlImageImporter\Admin\AdminPage;
use UrlImageImporter\Importer\ImageImporter;
use UrlImageImporter\FileScan\FileScan;

/**
 * Main plugin class that bootstraps the entire plugin.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private static $instance = null;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '1.2.2';

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Admin page handler.
	 *
	 * @var AdminPage
	 */
	private $admin_page;

	/**
	 * Image importer handler.
	 *
	 * @var ImageImporter
	 */
	private $image_importer;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->plugin_path = UIMPTR_PATH;
		$this->plugin_url  = \trailingslashit( \plugins_url( '', UIMPTR_PATH . 'url-image-importer.php' ) );
		$this->init();
	}

	/**
	 * Initialize the plugin.
	 */
	private function init() {
		\add_action( 'init', array( $this, 'load_textdomain' ) );
		\add_action( 'init', array( $this, 'init_components' ) );
		\add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		\add_filter( 'plugin_action_links_url-image-importer/url-image-importer.php', array( $this, 'plugin_action_links' ) );
		
		$this->register_ajax_handlers();
	}

	/**
	 * Initialize plugin components.
	 */
	public function init_components() {
		// Initialize promotional notices
		\UrlImageImporter\Admin\PromoNotices::get_instance();
	}

	/**
	 * Register AJAX handlers.
	 */
	private function register_ajax_handlers() {
		// AJAX handlers are registered in url-image-importer.php (procedural code)
		// Not registering duplicate actions to avoid conflicts
		// Plugin class methods are kept for backward compatibility but not hooked
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain() {
		\load_plugin_textdomain( 'url-image-importer', false, dirname( \plugin_basename( $this->plugin_path . 'url-image-importer.php' ) ) . '/languages/' );
	}

	/**
	 * Add admin menu page.
	 */
	public function admin_menu() {
		\add_media_page(
			'Import Images from URLs',
			'Import Images',
			'upload_files',
			'import-images-url',
			'uimptr_import_images_url_page'
		);
	}

	/**
	 * Enqueue admin styles and scripts.
	 */
	public function admin_styles() {
		if ( ! isset( $_GET['page'] ) || 'import-images-url' !== $_GET['page'] ) {
			return;
		}

		\wp_enqueue_style( 'uimptr-bootstrap', $this->plugin_url . 'assets/bootstrap/css/bootstrap.min.css', '', self::VERSION );
		// Version admin.css by file mtime so CSS edits bust the browser cache
		// even when the plugin version is unchanged.
		$admin_css_path = $this->plugin_path . 'assets/css/admin.css';
		$admin_css_ver  = \file_exists( $admin_css_path ) ? \filemtime( $admin_css_path ) : self::VERSION;
		\wp_enqueue_style( 'uimptr-styles', $this->plugin_url . 'assets/css/admin.css', '', $admin_css_ver );
		\wp_enqueue_script( 'uimptr-chartjs', $this->plugin_url . 'assets/js/Chart.min.js', '', self::VERSION, true );
		\wp_enqueue_script( 'bfu-bootstrap', $this->plugin_url . 'assets/bootstrap/js/bootstrap.bundle.min.js', '', self::VERSION, true );
		$admin_js_path = $this->plugin_path . 'assets/js/admin.js';
		$admin_js_ver  = \file_exists( $admin_js_path ) ? \filemtime( $admin_js_path ) : self::VERSION;
		\wp_enqueue_script( 'uimptr-js', $this->plugin_url . 'assets/js/admin.js', '', $admin_js_ver, true );

		$this->localize_scripts();
	}

	/**
	 * Localize scripts with data.
	 */
	private function localize_scripts() {
		$data = array(
			'strings' => array(
				'leave_confirm'      => \esc_html__( 'Are you sure you want to leave this tab? The current bulk action will be canceled and you will need to continue where it left off later.', 'url-image-importer' ),
				'ajax_error'         => \esc_html__( 'Too many server errors. Please try again.', 'url-image-importer' ),
				'leave_confirmation' => \esc_html__( 'If you leave this page the sync will be interrupted and you will have to continue where you left off later.', 'url-image-importer' ),
			),
			'ajax_url'            => \admin_url( 'admin-ajax.php' ),
			   'local_types'         => \UrlImageImporter\FileScan\Utils::get_filetypes( true ),
			'default_upload_size' => \wp_max_upload_size(),
			'uimptr_nonce'        => \wp_create_nonce( 'ajax-nonce' ),
		);
		
		\wp_localize_script( 'uimptr-js', 'bfu_data', $data );
	}

	/**
	 * Add custom action links to plugin list.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function plugin_action_links( $links ) {
		$custom_links = array(
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				\esc_url( \admin_url( 'upload.php?page=import-images-url' ) ),
				\esc_html__( 'Settings', 'url-image-importer' )
			),
			'support' => sprintf(
				'<a href="%s" target="_blank">%s</a>',
				\esc_url( 'https://infiniteuploads.com/support/?utm_source=url_image_importer&utm_medium=plugin&utm_campaign=plugin_links' ),
				\esc_html__( 'Support', 'url-image-importer' )
			),
			'upgrade' => sprintf(
				'<a href="%s" target="_blank" style="color: #93003f; font-weight: bold;">%s</a>',
				\esc_url( \UrlImageImporter\Admin\PromoNotices::get_upgrade_url( 'plugin_links' ) ),
				\esc_html__( 'Go Pro', 'url-image-importer' )
			),
		);

		return array_merge( $custom_links, $links );
	}

	/**
	 * AJAX handler for file scanning.
	 */
	public function ajax_file_scan() {
		$nonce = isset( $_POST['js_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['js_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'ajax-nonce' ) ) {
			\wp_die( 'Nonce Verification Failed!' );
		}

		$path           = \UrlImageImporter\FileScan\Utils::get_upload_dir_root();
		$remaining_dirs = array();

		if ( isset( $_POST['remaining_dirs'] ) && is_array( $_POST['remaining_dirs'] ) ) {
			foreach ( $_POST['remaining_dirs'] as $dir ) {
				$sanitized_dir = \sanitize_text_field( \wp_unslash( $dir ) );
				$realpath      = realpath( $path . $sanitized_dir );
				if ( $realpath && 0 === strpos( $realpath, $path ) ) {
					$remaining_dirs[] = $sanitized_dir;
				}
			}
		}

		$file_scan = new FileScan( $path, 20, $remaining_dirs );
		$file_scan->start();
		
		$file_count     = \number_format_i18n( $file_scan->get_total_files() );
		$file_size      = \size_format( $file_scan->get_total_size(), 2 );
		$remaining_dirs = $file_scan->get_paths_left();
		$is_done        = $file_scan->is_done();

		$data = compact( 'file_count', 'file_size', 'is_done', 'remaining_dirs' );
		\wp_send_json_success( $data );
	}

	/**
	 * AJAX handler for subscribe modal dismissal.
	 */
	public function ajax_subscribe_dismiss() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		\update_user_option( \get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );
		\wp_send_json_success();
	}

	/**
	 * Get plugin path.
	 *
	 * @return string
	 */
	public function get_plugin_path() {
		return $this->plugin_path;
	}

	/**
	 * Get plugin URL.
	 *
	 * @return string
	 */
	public function get_plugin_url() {
		return $this->plugin_url;
	}

	/**
	 * Get admin page instance.
	 *
	 * @return AdminPage
	 */
	public function get_admin_page() {
		return $this->admin_page;
	}

	/**
	 * Get the transient keys used for legacy import progress and stop state.
	 *
	 * @return array
	 */
	private function get_import_state_keys() {
		$user_id     = \get_current_user_id();
		$site_hash   = substr( md5( \home_url() ), 0, 8 );
		$progress_key = 'uimptr_progress_' . $site_hash . '_' . $user_id;
		$stop_key     = 'uimptr_import_stop_' . $site_hash . '_' . $user_id;

		return array(
			'user_id'      => $user_id,
			'site_hash'    => $site_hash,
			'progress_key' => $progress_key,
			'stop_key'     => $stop_key,
		);
	}

	/**
	 * Clear any outstanding stop request for the legacy import flow.
	 *
	 * @param string $stop_key Stop transient key.
	 * @return void
	 */
	private function clear_import_stop_request( $stop_key ) {
		\delete_transient( $stop_key );
	}

	/**
	 * Record a stop request for the legacy import flow.
	 *
	 * @param string $stop_key Stop transient key.
	 * @return void
	 */
	private function request_import_stop( $stop_key ) {
		\set_transient(
			$stop_key,
			array(
				'requested_at' => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Whether the legacy import flow has received a stop request.
	 *
	 * @param string $stop_key Stop transient key.
	 * @return bool
	 */
	private function is_import_stop_requested( $stop_key ) {
		return (bool) \get_transient( $stop_key );
	}

	/**
	 * Build a stopped progress payload while preserving any known counters.
	 *
	 * @param array|null $progress Existing progress payload.
	 * @return array
	 */
	private function get_stopped_progress_payload( $progress = null ) {
		$progress = is_array( $progress ) ? $progress : array();

		$progress['total']       = isset( $progress['total'] ) ? (int) $progress['total'] : 0;
		$progress['processed']   = isset( $progress['processed'] ) ? (int) $progress['processed'] : 0;
		$progress['success']     = isset( $progress['success'] ) ? (int) $progress['success'] : 0;
		$progress['failed']      = isset( $progress['failed'] ) ? (int) $progress['failed'] : 0;
		$progress['skipped']     = isset( $progress['skipped'] ) ? (int) $progress['skipped'] : 0;
		$progress['errors']      = isset( $progress['errors'] ) && is_array( $progress['errors'] ) ? $progress['errors'] : array();
		$progress['status']      = 'stopped';
		$progress['stopped']     = true;
		$progress['status_text'] = 'Import stopped by user';

		return $progress;
	}

	/**
	 * AJAX handler to start XML import with progress tracking.
	 */
	public function ajax_start_xml_import() {
		// Set proper resource limits for production deployment
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			@ini_set( 'max_execution_time', 300 ); // 5 minutes max
			@ini_set( 'memory_limit', '512M' ); // Increase memory limit
		}
		
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		// Check user permissions
		if ( ! \current_user_can( 'upload_files' ) ) {
			\wp_send_json_error( 'Insufficient permissions' );
		}
		
		// Rate limiting: Check if user has started an import in the last 30 seconds
		$user_id = \get_current_user_id();
		$rate_limit_key = 'uimptr_rate_limit_' . $user_id;
		if ( \get_transient( $rate_limit_key ) ) {
			\wp_send_json_error( 'Please wait before starting another import.' );
		}
		\set_transient( $rate_limit_key, true, 30 ); // 30 second rate limit

		// Get stored media data from transient
		$stored_media_data = \get_transient( 'uimptr_xml_import_' . \get_current_user_id() );
		if ( ! $stored_media_data ) {
			\wp_send_json_error( 'Import session expired. Please upload the XML file again.' );
		}

		// Sanitize input
		$skip_existing = isset( $_POST['skip_existing'] ) ? \sanitize_text_field( $_POST['skip_existing'] ) : '1';
		
		// Initialize progress tracking with site-specific keys before creating options.
		$state_keys   = $this->get_import_state_keys();
		$progress_key = $state_keys['progress_key'];
		$stop_key     = $state_keys['stop_key'];
		$this->clear_import_stop_request( $stop_key );
		
		$options = array(
			'skip_existing' => (bool) intval( $skip_existing ),
			'progress_key' => $progress_key, // Pass the key to the importer
		);
		
		$initial_progress = array(
			'total' => count( $stored_media_data ),
			'processed' => 0,
			'success' => 0,
			'failed' => 0,
			'skipped' => 0,
			'errors' => array(),
			'status' => 'in_progress',
			'stopped' => false
		);
		
		\set_transient( $progress_key, $initial_progress, 1800 ); // 30 minutes expiry (shorter for production)

		// Process the import with progress tracking
		$xml_importer = new \UrlImageImporter\Importer\WordPressXmlImporter();
		
		// Set up progress callback
		$progress_callback = function( $results, $status_text ) use ( $progress_key, $stop_key ) {
			$is_stopped = $this->is_import_stop_requested( $stop_key );

			$progress = array(
				'total' => $results['total'],
				'processed' => $results['processed'],
				'success' => $results['success'],
				'failed' => $results['failed'],
				'skipped' => $results['skipped'],
				'errors' => $results['errors'],
				'status' => $is_stopped ? 'stopped' : 'in_progress',
				'status_text' => $is_stopped ? 'Import stopped by user' : $status_text,
				'stopped' => $is_stopped,
			);
			\set_transient( $progress_key, $progress, 3600 );
		};

		$options['progress_callback'] = $progress_callback;
		
		try {
			$import_results = $xml_importer->process_xml_import( $stored_media_data, $options );
		} catch ( \Exception $e ) {
			// Log error and cleanup (only if WP_DEBUG is enabled)
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'URL Image Importer Error: ' . $e->getMessage() );
			}
			\delete_transient( $progress_key );
			$this->clear_import_stop_request( $stop_key );
			\delete_transient( 'uimptr_xml_import_' . \get_current_user_id() );
			\wp_send_json_error( 'Import failed: ' . $e->getMessage() );
		}
		
		// Check if import was stopped using the dedicated stop signal.
		$was_stopped = $this->is_import_stop_requested( $stop_key );
		
		// Update final progress
		$final_progress = array(
			'total' => $import_results['total'],
			'processed' => $import_results['processed'],
			'success' => $import_results['success'],
			'failed' => $import_results['failed'],
			'skipped' => $import_results['skipped'],
			'errors' => $import_results['errors'],
			'status' => $was_stopped ? 'stopped' : 'completed',
			'stopped' => $was_stopped,
			'status_text' => $was_stopped ? 'Import stopped by user' : 'Import completed',
		);
		\set_transient( $progress_key, $final_progress, 3600 );

		if ( ! $was_stopped ) {
			$this->clear_import_stop_request( $stop_key );
		}
		
		// Clean up the media transient
		\delete_transient( 'uimptr_xml_import_' . \get_current_user_id() );

		\wp_send_json_success( $import_results );
	}

	/**
	 * AJAX handler to get import progress.
	 */
	public function ajax_get_import_progress() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		$state_keys   = $this->get_import_state_keys();
		$progress_key = $state_keys['progress_key'];
		$stop_key     = $state_keys['stop_key'];
		$progress     = \get_transient( $progress_key );
		
		if ( ! $progress && ! $this->is_import_stop_requested( $stop_key ) ) {
			\wp_send_json_error( 'No active import found' );
		}

		if ( $this->is_import_stop_requested( $stop_key ) ) {
			$progress = $this->get_stopped_progress_payload( $progress );
			\set_transient( $progress_key, $progress, 3600 );
		}
		
		\wp_send_json_success( $progress );
	}

	/**
	 * AJAX handler to stop import.
	 */
	public function ajax_stop_import() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'URL Image Importer: ajax_stop_import called' );
		}
		
		if ( function_exists( '\uimptr_verify_ajax_request_nonce' ) ) {
			if ( ! \uimptr_verify_ajax_request_nonce() ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'URL Image Importer: Nonce verification failed' );
				}
				\wp_send_json_error( 'Security check failed' );
			}
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		// Check user permissions
		if ( ! \current_user_can( 'upload_files' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'URL Image Importer: Permission check failed' );
			}
			\wp_send_json_error( 'Insufficient permissions' );
		}

		$state_keys   = $this->get_import_state_keys();
		$progress_key = $state_keys['progress_key'];
		$stop_key     = $state_keys['stop_key'];

		$this->request_import_stop( $stop_key );
		$progress = $this->get_stopped_progress_payload( \get_transient( $progress_key ) );
		\set_transient( $progress_key, $progress, 3600 );
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'URL Image Importer: Stop signal set for key: ' . $progress_key );
			error_log( 'URL Image Importer: Stop transient key set: ' . $stop_key );
			$verify = \get_transient( $stop_key );
			error_log( 'URL Image Importer: Stop flag verified: ' . ( $verify ? 'YES' : 'NO' ) );
		}
		
		\wp_send_json_success( array( 
			'message' => 'Import stop signal sent',
			'progress_key' => $progress_key
		) );
	}

	/**
	 * AJAX handler to start CSV import with progress tracking.
	 */
	public function ajax_start_csv_import() {
		if ( function_exists( '\uimptr_check_ajax_request' ) ) {
			\uimptr_check_ajax_request();
		} else {
			\check_ajax_referer( 'uimptr_ajax', 'nonce' );
		}

		// Check user permissions
		if ( ! \current_user_can( 'upload_files' ) ) {
			\wp_send_json_error( 'Insufficient permissions' );
		}

		// Rate limiting
		$user_id = \get_current_user_id();
		$rate_limit_key = 'uimptr_rate_limit_' . $user_id;
		if ( \get_transient( $rate_limit_key ) ) {
			\wp_send_json_error( 'Please wait before starting another import.' );
		}
		\set_transient( $rate_limit_key, true, 30 ); // 30 second rate limit

		// Get stored CSV data and mappings from transient
		$user_id   = \get_current_user_id();
		$site_hash = substr( md5( \home_url() ), 0, 8 );
		$csv_key   = 'uimptr_csv_' . $site_hash . '_' . $user_id;
		
		$stored_data = \get_transient( $csv_key );
		if ( ! $stored_data || ! isset( $stored_data['csv_data'] ) || ! isset( $stored_data['mappings'] ) ) {
			\wp_send_json_error( 'Import session expired. Please upload the CSV file again.' );
		}
		
		$stored_csv_data = $stored_data['csv_data'];
		$mappings = $stored_data['mappings'];

		// Sanitize input
		$skip_existing = isset( $_POST['skip_existing'] ) ? \sanitize_text_field( $_POST['skip_existing'] ) : '1';
		
		$state_keys   = $this->get_import_state_keys();
		$progress_key = $state_keys['progress_key'];
		$stop_key     = $state_keys['stop_key'];
		$this->clear_import_stop_request( $stop_key );
		
		$options = array(
			'skip_existing' => (bool) intval( $skip_existing ),
			'progress_key' => $progress_key,
		);
		
		$initial_progress = array(
			'total' => count( $stored_csv_data ),
			'processed' => 0,
			'success' => 0,
			'failed' => 0,
			'skipped' => 0,
			'errors' => array(),
			'status' => 'in_progress',
			'stopped' => false
		);
		
		\set_transient( $progress_key, $initial_progress, 1800 );

		// Process the CSV import with progress tracking
		$csv_importer = new \UrlImageImporter\Importer\CsvImporter();
		
		// Set up progress callback
		$progress_callback = function( $results ) use ( $progress_key, $stop_key ) {
			$is_stopped = $this->is_import_stop_requested( $stop_key );

			$progress = array(
				'total' => $results['total'],
				'processed' => $results['processed'],
				'success' => $results['success'],
				'failed' => $results['failed'],
				'skipped' => $results['skipped'],
				'errors' => $results['errors'],
				'status' => $is_stopped ? 'stopped' : 'in_progress',
				'stopped' => $is_stopped,
				'status_text' => $is_stopped ? 'Import stopped by user' : 'Import in progress',
			);
			\set_transient( $progress_key, $progress, 3600 );
		};

		$options['progress_callback'] = $progress_callback;
		
		try {
			// Increase limits for large imports
			@ini_set( 'memory_limit', '512M' );
			@set_time_limit( 300 );
			
			$import_results = $csv_importer->process_csv_import( $stored_csv_data, $mappings, $options );
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'URL Image Importer Error: ' . $e->getMessage() );
			}
			\delete_transient( $progress_key );
			$this->clear_import_stop_request( $stop_key );
			\delete_transient( $csv_key );
			\wp_send_json_error( 'Import failed: ' . $e->getMessage() );
		}
		
		$was_stopped = $this->is_import_stop_requested( $stop_key );
		
		// Update final progress
		$final_progress = array(
			'total' => $import_results['total'],
			'processed' => $import_results['processed'],
			'success' => $import_results['success'],
			'failed' => $import_results['failed'],
			'skipped' => $import_results['skipped'],
			'errors' => $import_results['errors'],
			'status' => $was_stopped ? 'stopped' : 'completed',
			'stopped' => $was_stopped,
			'status_text' => $was_stopped ? 'Import stopped by user' : 'Import completed',
		);
		\set_transient( $progress_key, $final_progress, 3600 );

		if ( ! $was_stopped ) {
			$this->clear_import_stop_request( $stop_key );
		}
		
		// Clean up the CSV transient
		\delete_transient( $csv_key );

		\wp_send_json_success( $import_results );
	}
}
