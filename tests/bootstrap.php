<?php
/**
 * PHPUnit bootstrap for URL Image Importer.
 *
 * The plugin is written for WordPress, so these tests provide focused stubs for
 * the WordPress APIs used by the unit-tested code paths.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/wp/' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures/wp/wp-content/plugins' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

date_default_timezone_set( 'UTC' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $errors = array();
		private $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function add_data( $data, $code = '' ) {
			$code = '' !== $code ? $code : $this->get_error_code();
			if ( '' !== $code ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			$code = '' !== $code ? $code : $this->get_error_code();
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_messages( $code = '' ) {
			if ( '' !== $code ) {
				return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
			}

			$messages = array();
			foreach ( $this->errors as $error_messages ) {
				$messages = array_merge( $messages, $error_messages );
			}

			return $messages;
		}

		public function get_error_data( $code = '' ) {
			$code = '' !== $code ? $code : $this->get_error_code();
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}

class Uimptr_Test_Json_Response extends Exception {
	public $success;
	public $data;

	public function __construct( $success, $data = null ) {
		parent::__construct( $success ? 'wp_send_json_success' : 'wp_send_json_error' );
		$this->success = $success;
		$this->data    = $data;
	}
}

class Uimptr_Test_Wp_Die extends Exception {
	public $title;
	public $args;

	public function __construct( $message = '', $title = '', $args = array() ) {
		parent::__construct( (string) $message );
		$this->title = $title;
		$this->args  = $args;
	}
}

class Uimptr_Test_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $usermeta = 'wp_usermeta';
	public $last_query = '';
	public $last_prepare_args = array();
	public $queries = array();
	public $get_var_queue = array();
	public $get_results_queue = array();
	public $get_col_queue = array();
	public $source_url_matches = array();
	public $filename_matches = array();
	public $get_var_callback = null;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$this->last_prepare_args = $args;
		$index                   = 0;
		$prepared                = preg_replace_callback(
			'/%(?:\d+\$)?[sdf]/',
			function( $matches ) use ( $args, &$index ) {
				$value = array_key_exists( $index, $args ) ? $args[ $index ] : '';
				$index++;

				if ( 'd' === substr( $matches[0], -1 ) ) {
					return (string) intval( $value );
				}

				if ( 'f' === substr( $matches[0], -1 ) ) {
					return (string) (float) $value;
				}

				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);

		$this->last_query = $prepared;

		return $prepared;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_var( $query = null ) {
		$this->last_query = null === $query ? $this->last_query : $query;
		$this->queries[]  = $this->last_query;

		if ( is_callable( $this->get_var_callback ) ) {
			return call_user_func( $this->get_var_callback, $this->last_query, $this );
		}

		if ( ! empty( $this->get_var_queue ) ) {
			return array_shift( $this->get_var_queue );
		}

		foreach ( $this->source_url_matches as $url => $id ) {
			if ( false !== strpos( $this->last_query, "_uimptr_source_url" ) && false !== strpos( $this->last_query, (string) $url ) ) {
				return $id;
			}
		}

		foreach ( $this->filename_matches as $filename => $id ) {
			$is_filename_lookup = false !== strpos( $this->last_query, '_wp_attached_file' )
				|| false !== strpos( $this->last_query, 'SUBSTRING_INDEX' )
				|| false !== strpos( $this->last_query, 'guid LIKE' );

			if ( $is_filename_lookup && false !== strpos( $this->last_query, (string) $filename ) ) {
				return $id;
			}
		}

		return null;
	}

	public function get_results( $query = null, $output = null ) {
		$this->last_query = null === $query ? $this->last_query : $query;
		$this->queries[]  = $this->last_query;

		return ! empty( $this->get_results_queue ) ? array_shift( $this->get_results_queue ) : array();
	}

	public function get_col( $query = null ) {
		$this->last_query = null === $query ? $this->last_query : $query;
		$this->queries[]  = $this->last_query;

		return ! empty( $this->get_col_queue ) ? array_shift( $this->get_col_queue ) : array();
	}

	public function query( $query ) {
		$this->last_query = $query;
		$this->queries[]  = $query;

		return true;
	}
}

function uimptr_tests_base_temp_dir() {
	return sys_get_temp_dir() . '/url-image-importer-phpunit-' . getmypid();
}

function uimptr_tests_rrmdir( $path ) {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		@unlink( $path );
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}

	@rmdir( $path );
}

function uimptr_tests_reset_environment() {
	$base_dir = uimptr_tests_base_temp_dir();
	uimptr_tests_rrmdir( $base_dir );

	$upload_base = $base_dir . '/uploads';
	$upload_path = $upload_base . '/2026/05';
	$temp_dir    = $base_dir . '/tmp';

	mkdir( $upload_path, 0777, true );
	mkdir( $temp_dir, 0777, true );
	ini_set( 'error_log', $base_dir . '/phpunit-error.log' );

	$GLOBALS['wpdb']                         = new Uimptr_Test_WPDB();
	$GLOBALS['uimptr_test_actions']          = array();
	$GLOBALS['uimptr_test_filters']          = array();
	$GLOBALS['uimptr_test_transients']       = array();
	$GLOBALS['uimptr_test_options']          = array( 'upload_path' => '' );
	$GLOBALS['uimptr_test_site_options']     = array();
	$GLOBALS['uimptr_test_user_meta']        = array();
	$GLOBALS['uimptr_test_user_options']     = array();
	$GLOBALS['uimptr_test_current_user_id']  = 7;
	$GLOBALS['uimptr_test_current_user_can'] = true;
	$GLOBALS['uimptr_test_nonce_valid']      = true;
	$GLOBALS['uimptr_test_active_plugins']   = array();
	$GLOBALS['uimptr_test_upload_dir']       = array(
		'basedir' => $upload_base,
		'path'    => $upload_path,
		'baseurl' => 'https://example.test/wp-content/uploads',
		'url'     => 'https://example.test/wp-content/uploads/2026/05',
		'error'   => false,
	);
	$GLOBALS['uimptr_test_temp_dir']         = $temp_dir . '/';
	$GLOBALS['uimptr_test_http_responses']   = array();
	$GLOBALS['uimptr_test_http_callback']    = null;
	$GLOBALS['uimptr_test_safe_remote_get_calls'] = array();
	// Host => array of IPs for the SSRF resolver. Unmapped non-IP hosts resolve to
	// a public TEST-NET address so ordinary import tests are not blocked.
	$GLOBALS['uimptr_test_host_ips']         = array();
	add_filter(
		'uimptr_resolve_host_ips',
		function( $pre, $host ) {
			// Let the plugin handle IP literals itself.
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return $pre;
			}
			if ( isset( $GLOBALS['uimptr_test_host_ips'][ $host ] ) ) {
				return $GLOBALS['uimptr_test_host_ips'][ $host ];
			}
			return array( '203.0.113.10' ); // TEST-NET-3: a routable, non-reserved default.
		},
		10,
		2
	);
	$GLOBALS['uimptr_test_inserted_posts']   = array();
	$GLOBALS['uimptr_test_post_meta']        = array();
	$GLOBALS['uimptr_test_attachment_meta']  = array();
	$GLOBALS['uimptr_test_media_handle_upload_calls'] = array();
	$GLOBALS['uimptr_test_next_post_id']     = 1000;
	$GLOBALS['uimptr_test_enqueued']         = array( 'styles' => array(), 'scripts' => array(), 'localized' => array() );
	$GLOBALS['uimptr_test_redirect']         = null;
	$GLOBALS['uimptr_test_scheduled']        = array();
	$GLOBALS['uimptr_test_allowed_mimes']    = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'bmp'          => 'image/bmp',
		'tif|tiff'     => 'image/tiff',
		'ico'          => 'image/x-icon',
		'svg'          => 'image/svg+xml',
	);

	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_FILES   = array();
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, "/\\" );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return trailingslashit( plugins_url( '', $file ) );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		$base = 'https://example.test/wp-content/plugins/url-image-importer';
		return '' === $path ? $base : $base . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return 'url-image-importer/' . basename( $file );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['uimptr_test_actions'][ $hook_name ][] = compact( 'callback', 'priority', 'accepted_args' );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['uimptr_test_filters'][ $hook_name ][] = compact( 'callback', 'priority', 'accepted_args' );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) {
		if ( empty( $GLOBALS['uimptr_test_filters'][ $hook_name ] ) ) {
			return $value;
		}

		$callbacks = $GLOBALS['uimptr_test_filters'][ $hook_name ];
		usort(
			$callbacks,
			function( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		foreach ( $callbacks as $registration ) {
			$callback_args = array_merge( array( $value ), $args );
			$value         = call_user_func_array( $registration['callback'], array_slice( $callback_args, 0, $registration['accepted_args'] ) );
		}

		return $value;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		$GLOBALS['uimptr_test_deactivation_hook'] = array( $file, $callback );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return isset( $GLOBALS['uimptr_test_scheduled'][ $hook ] ) ? $GLOBALS['uimptr_test_scheduled'][ $hook ]['timestamp'] : false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['uimptr_test_scheduled'][ $hook ] = compact( 'timestamp', 'recurrence', 'hook' );
		return true;
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return ! empty( $GLOBALS['uimptr_test_active_plugins'][ $plugin ] );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		$upload_dir = $GLOBALS['uimptr_test_upload_dir'];
		if ( ! is_dir( $upload_dir['path'] ) ) {
			mkdir( $upload_dir['path'], 0777, true );
		}

		return $upload_dir;
	}
}

if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir() {
		if ( ! is_dir( $GLOBALS['uimptr_test_temp_dir'] ) ) {
			mkdir( $GLOBALS['uimptr_test_temp_dir'], 0777, true );
		}

		return trailingslashit( $GLOBALS['uimptr_test_temp_dir'] );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return esc_url_raw( $url );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		return preg_replace( '/[\x00-\x20]+/', '', $url );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		return strip_tags( (string) $content, '<a><br><em><strong><p><span>' );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $content, $allowed_html = array() ) {
		return wp_kses_post( $content );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		$filename = basename( str_replace( '\\', '/', rawurldecode( (string) $filename ) ) );
		$filename = preg_replace( '/[^A-Za-z0-9._-]+/', '-', trim( $filename ) );
		$filename = preg_replace( '/-+/', '-', $filename );
		return trim( $filename, '.-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		$value = strip_tags( (string) $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		$value = preg_replace( '/[ \t\r\n]+/', ' ', $value );
		return trim( $value );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		$value = strip_tags( (string) $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		return trim( $value );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( strip_tags( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}

		return addslashes( (string) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $key, $url = '' ) {
		$keys = is_array( $key ) ? $key : array( $key );

		$parts = parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return $url;
		}

		$query = array();
		parse_str( $parts['query'], $query );
		foreach ( $keys as $k ) {
			unset( $query[ $k ] );
		}

		$base = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
			. ( isset( $parts['host'] ) ? $parts['host'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '' );

		return $query ? $base . '?' . http_build_query( $query ) : $base;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_parse_str' ) ) {
	function wp_parse_str( $input_string, &$result ) {
		parse_str( (string) $input_string, $result );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $value = null, $url = null ) {
		if ( is_array( $args ) ) {
			$params = $args;
			$url    = null === $value ? '' : $value;
		} else {
			$params = array( $args => $value );
			$url    = null === $url ? '' : $url;
		}

		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}

		$base  = $url;
		$query = array();
		if ( false !== strpos( $url, '?' ) ) {
			list( $base, $query_string ) = explode( '?', $url, 2 );
			parse_str( $query_string, $query );
		}

		foreach ( $params as $key => $param_value ) {
			$query[ $key ] = $param_value;
		}

		return $base . ( $query ? '?' . http_build_query( $query ) : '' ) . $fragment;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		if ( 'version' === $show ) {
			return '6.8';
		}
		if ( 'url' === $show ) {
			return home_url();
		}

		return 'URL Image Importer Test';
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['uimptr_test_current_user_id'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return (bool) $GLOBALS['uimptr_test_current_user_can'];
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return true;
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return isset( $GLOBALS['uimptr_test_current_screen'] ) ? $GLOBALS['uimptr_test_current_screen'] : (object) array( 'id' => 'media_page_import-images-url' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return (bool) $GLOBALS['uimptr_test_nonce_valid'] && 'nonce-' . $action === $nonce;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		$field = $query_arg ? $query_arg : '_ajax_nonce';
		$nonce = isset( $_REQUEST[ $field ] ) ? $_REQUEST[ $field ] : 'nonce-' . $action;
		$valid = wp_verify_nonce( $nonce, $action );
		if ( ! $valid && $die ) {
			wp_die( 'Nonce Verification Failed!' );
		}

		return $valid;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null ) {
		throw new Uimptr_Test_Json_Response( true, $data );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null ) {
		throw new Uimptr_Test_Json_Response( false, $data );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		throw new Uimptr_Test_Wp_Die( $message, $title, $args );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['uimptr_test_redirect'] = compact( 'location', 'status' );
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['uimptr_test_enqueued']['styles'][ $handle ] = compact( 'src', 'deps', 'ver', 'media' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['uimptr_test_enqueued']['scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		$GLOBALS['uimptr_test_enqueued']['localized'][ $handle ][ $object_name ] = $l10n;
		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['uimptr_test_enqueued']['inline'][ $handle ][] = compact( 'data', 'position' );
		return true;
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
		return true;
	}
}

if ( ! function_exists( 'add_media_page' ) ) {
	function add_media_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
		$GLOBALS['uimptr_test_media_pages'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
		return 'media_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
		if ( $display ) {
			echo $field;
		}

		return $field;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$GLOBALS['uimptr_test_transients'][ $transient ] = array(
			'value'      => $value,
			'expiration' => $expiration ? time() + (int) $expiration : 0,
		);

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		if ( empty( $GLOBALS['uimptr_test_transients'][ $transient ] ) ) {
			return false;
		}

		$entry = $GLOBALS['uimptr_test_transients'][ $transient ];
		if ( ! empty( $entry['expiration'] ) && $entry['expiration'] < time() ) {
			unset( $GLOBALS['uimptr_test_transients'][ $transient ] );
			return false;
		}

		return $entry['value'];
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['uimptr_test_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['uimptr_test_options'] ) ? $GLOBALS['uimptr_test_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value ) {
		$GLOBALS['uimptr_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['uimptr_test_site_options'] ) ? $GLOBALS['uimptr_test_site_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( $option, $value ) {
		$GLOBALS['uimptr_test_site_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() {
		return home_url();
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'is_main_network' ) ) {
	function is_main_network() {
		return true;
	}
}

if ( ! function_exists( 'is_main_site' ) ) {
	function is_main_site() {
		return true;
	}
}

if ( ! function_exists( 'ms_is_switched' ) ) {
	function ms_is_switched() {
		return false;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		static $counter = 0;
		$counter++;
		return substr( str_pad( base_convert( (string) $counter, 10, 36 ), $length, 'a' ), 0, $length );
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '', $dir = '' ) {
		$dir = $dir ? $dir : get_temp_dir();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		return tempnam( $dir, 'uimptr_' );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		$ext   = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpe'  => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'bmp'  => 'image/bmp',
			'tif'  => 'image/tiff',
			'tiff' => 'image/tiff',
			'ico'  => 'image/x-icon',
			'svg'  => 'image/svg+xml',
			'csv'  => 'text/csv',
			'xml'  => 'text/xml',
			'txt'  => 'text/plain',
		);

		return array(
			'ext'  => isset( $types[ $ext ] ) ? $ext : false,
			'type' => isset( $types[ $ext ] ) ? $types[ $ext ] : false,
		);
	}
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( $file, $filename, $mimes = null, $real_mime = false ) {
		$filetype = wp_check_filetype( $filename, $mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
		}

		if ( 'svg' === $filetype['ext'] ) {
			$content = is_readable( $file ) ? file_get_contents( $file, false, null, 0, 2048 ) : '';
			return false !== stripos( (string) $content, '<svg' )
				? array_merge( $filetype, array( 'proper_filename' => false ) )
				: array( 'ext' => false, 'type' => false, 'proper_filename' => false );
		}

		return array_merge( $filetype, array( 'proper_filename' => false ) );
	}
}

if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types() {
		return $GLOBALS['uimptr_test_allowed_mimes'];
	}
}

if ( ! function_exists( 'wp_unique_filename' ) ) {
	function wp_unique_filename( $dir, $filename ) {
		$filename = sanitize_file_name( $filename );
		$ext      = pathinfo( $filename, PATHINFO_EXTENSION );
		$name     = '' !== $ext ? substr( $filename, 0, -1 * ( strlen( $ext ) + 1 ) ) : $filename;
		$number   = 1;
		$unique   = $filename;

		while ( file_exists( trailingslashit( $dir ) . $unique ) ) {
			$unique = $name . '-' . $number . ( '' !== $ext ? '.' . $ext : '' );
			$number++;
		}

		return $unique;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		if ( isset( $GLOBALS['uimptr_test_http_callback'] ) && is_callable( $GLOBALS['uimptr_test_http_callback'] ) ) {
			return call_user_func( $GLOBALS['uimptr_test_http_callback'], $url, $args );
		}

		return isset( $GLOBALS['uimptr_test_http_responses'][ $url ] )
			? $GLOBALS['uimptr_test_http_responses'][ $url ]
			: new WP_Error( 'http_not_mocked', 'No mocked response for URL: ' . $url );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		if ( empty( $response['headers'] ) || ! is_array( $response['headers'] ) ) {
			return '';
		}

		foreach ( $response['headers'] as $key => $value ) {
			if ( strtolower( $key ) === strtolower( $header ) ) {
				return $value;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	function wp_insert_attachment( $attachment, $file = false, $parent = 0, $wp_error = false ) {
		if ( isset( $GLOBALS['uimptr_test_insert_attachment_callback'] ) && is_callable( $GLOBALS['uimptr_test_insert_attachment_callback'] ) ) {
			return call_user_func( $GLOBALS['uimptr_test_insert_attachment_callback'], $attachment, $file, $parent, $wp_error );
		}

		$id = ++$GLOBALS['uimptr_test_next_post_id'];
		$GLOBALS['uimptr_test_inserted_posts'][ $id ] = array_merge(
			$attachment,
			array(
				'ID'   => $id,
				'file' => $file,
			)
		);

		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id <= 0 ) {
			return 0;
		}

		$GLOBALS['uimptr_test_inserted_posts'][ $id ] = array_merge(
			isset( $GLOBALS['uimptr_test_inserted_posts'][ $id ] ) ? $GLOBALS['uimptr_test_inserted_posts'][ $id ] : array(),
			$postarr
		);

		return $id;
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( $attachment_id, $file ) {
		return array(
			'file'     => basename( $file ),
			'filesize' => file_exists( $file ) ? filesize( $file ) : 0,
		);
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( $attachment_id, $data ) {
		$GLOBALS['uimptr_test_attachment_meta'][ $attachment_id ] = $data;
		return true;
	}
}

if ( ! function_exists( 'media_handle_upload' ) ) {
	function media_handle_upload( $file_id, $post_id = 0, $post_data = array(), $overrides = array() ) {
		$GLOBALS['uimptr_test_media_handle_upload_calls'][] = compact( 'file_id', 'post_id', 'post_data', 'overrides' );

		if ( empty( $_FILES[ $file_id ] ) || ! empty( $_FILES[ $file_id ]['error'] ) ) {
			return new WP_Error( 'upload_error', 'No sideloaded file available.' );
		}

		$file = $_FILES[ $file_id ];
		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', 'Sideloaded file is not readable.' );
		}

		$upload_dir = wp_upload_dir();
		$filename   = wp_unique_filename( $upload_dir['path'], $file['name'] );
		$file_path  = trailingslashit( $upload_dir['path'] ) . $filename;

		$moved = @rename( $file['tmp_name'], $file_path );
		if ( ! $moved ) {
			$moved = @copy( $file['tmp_name'], $file_path );
			if ( $moved ) {
				@unlink( $file['tmp_name'] );
			}
		}

		if ( ! $moved ) {
			return new WP_Error( 'upload_error', 'Failed to move sideloaded file.' );
		}

		$filetype = wp_check_filetype_and_ext( $file_path, $filename );
		$attachment = array_merge(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$post_data
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path, $post_id, true );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		return $attachment_id;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		$GLOBALS['uimptr_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return isset( $GLOBALS['uimptr_test_post_meta'][ $post_id ] ) ? $GLOBALS['uimptr_test_post_meta'][ $post_id ] : array();
		}

		$value = isset( $GLOBALS['uimptr_test_post_meta'][ $post_id ][ $key ] ) ? $GLOBALS['uimptr_test_post_meta'][ $post_id ][ $key ] : '';
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		return isset( $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ]['file'] )
			? $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ]['file']
			: '';
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id ) {
		return isset( $GLOBALS['uimptr_test_attachment_meta'][ $attachment_id ] )
			? $GLOBALS['uimptr_test_attachment_meta'][ $attachment_id ]
			: array();
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		if ( isset( $GLOBALS['uimptr_test_attachment_urls'][ $attachment_id ] ) ) {
			return $GLOBALS['uimptr_test_attachment_urls'][ $attachment_id ];
		}

		$file = get_attached_file( $attachment_id );
		return $file ? trailingslashit( $GLOBALS['uimptr_test_upload_dir']['url'] ) . basename( $file ) : '';
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date_string ) {
		$timestamp = strtotime( $date_string );
		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$unit  = 0;
		while ( $bytes >= 1024 && $unit < count( $units ) - 1 ) {
			$bytes /= 1024;
			$unit++;
		}

		return number_format( $bytes, (int) $decimals ) . ' ' . $units[ $unit ];
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$values = array();
		foreach ( $list as $item ) {
			if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
				$values[] = $item[ $field ];
			} elseif ( is_object( $item ) && isset( $item->{$field} ) ) {
				$values[] = $item->{$field};
			}
		}

		return $values;
	}
}

if ( ! function_exists( 'update_user_option' ) ) {
	function update_user_option( $user_id, $option_name, $newvalue ) {
		$GLOBALS['uimptr_test_user_options'][ $user_id ][ $option_name ] = $newvalue;
		return true;
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value ) {
		$GLOBALS['uimptr_test_user_meta'][ $user_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $meta_key, $single = false ) {
		$value = isset( $GLOBALS['uimptr_test_user_meta'][ $user_id ][ $meta_key ] )
			? $GLOBALS['uimptr_test_user_meta'][ $user_id ][ $meta_key ]
			: '';

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		return true;
	}
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() {
		return 104857600;
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) {
		// Record safe-fetch usage so tests can assert the SSRF-protected variant is used.
		$GLOBALS['uimptr_test_safe_remote_get_calls'][] = $url;
		return wp_remote_get( $url, $args );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {
		$GLOBALS['uimptr_test_nocache_headers'] = true;
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code, $description = '' ) {
		$GLOBALS['uimptr_test_status_header'] = array( $code, $description );
	}
}

uimptr_tests_reset_environment();
$GLOBALS['uimptr_test_active_plugins']['tuxedo-big-file-uploads/tuxedo_big_file_uploads.php'] = true;

require_once dirname( __DIR__ ) . '/url-image-importer.php';

require_once __DIR__ . '/Support/WpTestCase.php';
