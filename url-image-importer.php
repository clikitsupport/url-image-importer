<?php
/**
 *
 * Plugin Name: URL Image Importer
 * Description: A plugin to import multiple images into the WordPress Media Library from URLs.
 * Version: 1.2.3
 * Author: Infinite Uploads
 * Author URI: https://infiniteuploads.com
 * Text Domain: url-image-importer
 * License: GPL2
 *
 * @package UrlImageImporter
 * @version 1.2.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$upload_dir = wp_upload_dir();

define( 'UIMPTR_PATH', plugin_dir_path( __FILE__ ) );
define( 'UIMPTR_VERSION', '1.2.3' );

// Guard against redefinition. On multisite, WordPress core already defines UPLOADBLOGSDIR
// (see ms_upload_constants()), and other plugins may define it too; a second unguarded
// define() throws a "Constant UPLOADBLOGSDIR already defined" warning on every request,
// cron run, and background task. Defer to any existing definition.
if ( ! defined( 'UPLOADBLOGSDIR' ) ) {
	define( 'UPLOADBLOGSDIR', $upload_dir['basedir'] );  // Use basedir for root uploads folder, not path (current month)
}
define( 'UIMPTR_AJAX_NONCE_ACTION', 'uimptr_ajax' );
define( 'UIMPTR_AJAX_NONCE_FIELD', 'nonce' );

/**
 * Get the shared AJAX nonce action name.
 *
 * @return string
 */
function uimptr_get_ajax_nonce_action() {
	return UIMPTR_AJAX_NONCE_ACTION;
}

/**
 * Get the shared AJAX nonce field name.
 *
 * @return string
 */
function uimptr_get_ajax_nonce_field() {
	return UIMPTR_AJAX_NONCE_FIELD;
}

/**
 * Get the current user ID used for isolating per-user import state.
 *
 * @return int
 */
function uimptr_get_state_user_id() {
	$user_id = get_current_user_id();

	return $user_id > 0 ? (int) $user_id : 0;
}

/**
 * Create a shared AJAX nonce for plugin requests.
 *
 * @return string
 */
function uimptr_create_ajax_nonce() {
	return wp_create_nonce( uimptr_get_ajax_nonce_action() );
}

/**
 * Create a cryptographically strong batch ID seed for admin-side fallback use.
 *
 * @return string
 */
function uimptr_create_batch_id_seed() {
	try {
		return bin2hex( random_bytes( 16 ) );
	} catch ( Exception $exception ) {
		return strtolower( wp_generate_password( 32, false, false ) );
	}
}

/**
 * Get a sanitized AJAX nonce value from the current request.
 *
 * @param string|null $field Optional nonce field name.
 * @return string
 */
function uimptr_get_ajax_request_nonce( $field = null ) {
	$field = $field ? $field : uimptr_get_ajax_nonce_field();

	if ( ! isset( $_REQUEST[ $field ] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) );
}

/**
 * Verify the shared AJAX nonce from the current request.
 *
 * @param string|null $field Optional nonce field name.
 * @return bool
 */
function uimptr_verify_ajax_request_nonce( $field = null ) {
	return (bool) wp_verify_nonce( uimptr_get_ajax_request_nonce( $field ), uimptr_get_ajax_nonce_action() );
}

/**
 * Enforce the shared AJAX nonce for a request.
 *
 * @param string|null $field Optional nonce field name.
 * @return void
 */
function uimptr_check_ajax_request( $field = null ) {
	check_ajax_referer( uimptr_get_ajax_nonce_action(), $field ? $field : uimptr_get_ajax_nonce_field() );
}

/**
 * Delete a file while logging failures instead of suppressing them.
 *
 * @param string $file_path Absolute file path.
 * @param string $context   Cleanup context for diagnostics.
 * @return bool
 */
function uimptr_delete_file_with_logging( $file_path, $context = '' ) {
	$file_path = (string) $file_path;

	if ( '' === $file_path ) {
		return false;
	}

	clearstatcache( true, $file_path );
	if ( ! file_exists( $file_path ) ) {
		return true;
	}

	$unlink_error = '';

	set_error_handler(
		function( $severity, $message ) use ( &$unlink_error ) {
			$unlink_error = (string) $message;
			return true;
		}
	);

	try {
		$deleted = unlink( $file_path );
	} finally {
		restore_error_handler();
	}

	clearstatcache( true, $file_path );
	if ( $deleted || ! file_exists( $file_path ) ) {
		return true;
	}

	$context = trim( (string) $context );
	$details = '' !== $unlink_error ? $unlink_error : 'Unknown filesystem error.';
	error_log(
		sprintf(
			'URL Image Importer: Failed to delete file%s: %s. %s',
			'' !== $context ? ' during ' . $context : '',
			$file_path,
			$details
		)
	);

	return false;
}

// Composer autoload for PSR-4 classes
$autoload_loaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $autoload_loaded = true;
    
    // Initialize the Plugin class to enable action links and other features
    if (class_exists('\UrlImageImporter\Core\Plugin')) {
        try {
            \UrlImageImporter\Core\Plugin::get_instance();
        } catch (Exception $e) {
            // If PSR-4 Plugin class fails, fallback menu will be used
            error_log('URL Image Importer: Plugin class initialization failed: ' . $e->getMessage());
        }
    }
} else {
    // Simple PSR-4 autoloader fallback when Composer autoloader is not available
    spl_autoload_register(function ($class) {
        // Project-specific namespace prefix
        $prefix = 'UrlImageImporter\\';
        
        // Base directory for the namespace prefix
        $base_dir = __DIR__ . '/src/';
        
        // Does the class use the namespace prefix?
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            // No, move to the next registered autoloader
            return;
        }
        
        // Get the relative class name
        $relative_class = substr($class, $len);
        
        // Replace the namespace prefix with the base directory, replace namespace
        // separators with directory separators in the relative class name, append
        // with .php
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        // If the file exists, require it
        if (file_exists($file)) {
            require $file;
        }
    });
    $autoload_loaded = true;
    
    // Initialize the Plugin class to enable action links and other features
    if (class_exists('\UrlImageImporter\Core\Plugin')) {
        try {
            \UrlImageImporter\Core\Plugin::get_instance();
        } catch (Exception $e) {
            // If PSR-4 Plugin class fails, fallback menu will be used
            error_log('URL Image Importer: Plugin class initialization failed: ' . $e->getMessage());
        }
    }
}

// Check if Big File Uploads plugin exists and is active
$big_file_uploads_active = function_exists('is_plugin_active') && is_plugin_active('tuxedo-big-file-uploads/tuxedo_big_file_uploads.php');
$big_file_uploads_exists = file_exists(WP_PLUGIN_DIR . '/tuxedo-big-file-uploads/tuxedo_big_file_uploads.php');

// URL Image Importer uses completely independent classes to avoid conflicts
// Different class names, namespaces, and prefixes ensure no collisions with Big File Uploads

// Load legacy classes only if Big File Uploads plugin is NOT active AND autoloader is working
// This prevents constant and class collisions
if (!$big_file_uploads_active && $autoload_loaded) {
    // Only load file scan class if not already loaded
    if (!class_exists('Ui_Big_File_Uploads_File_Scan')) {
        require_once UIMPTR_PATH . '/classes/class-ui-big-file-uploads-file-scan.php';
    }
    
    // Only load legacy BFU functionality if the actual plugin isn't active
    if (!class_exists('UrlBigFileUploads')) {
        require_once UIMPTR_PATH . '/classes/tuxedo_big_file_uploads.php';
    }
}

// Check for plugin conflicts and display admin notice if needed
add_action('admin_notices', 'uimptr_check_plugin_conflicts');

/**
 * Check for plugin compatibility and display friendly notice
 */
function uimptr_check_plugin_conflicts() {
    // Only show on admin pages
    if (!is_admin()) {
        return;
    }
    
    // Removed compatibility notice - plugins work together without notification needed
    
    // Check for potential class conflicts (shouldn't happen with proper namespacing)
    $conflicts = [];
    if (class_exists('TuxedoBigFileUploads') && class_exists('UrlBigFileUploads')) {
        // This is actually OK - they're different classes with different names
    }
    
    // If there are any conflicts, show a warning (though this should never happen)
    if (!empty($conflicts)) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>URL Image Importer:</strong> ';
        echo esc_html__('Potential compatibility issue detected. Please check that only one version of file upload functionality is active.', 'url-image-importer');
        echo '</p></div>';
    }
}

/**
 * Plugin menu page callback.
 * NOTE: Menu registration moved to Plugin class (src/Core/Plugin.php)
 * Fallback registration in case PSR-4 system fails
 */
function uimptr_admin_menu() {
	add_media_page(
		'Import Images from URLs',
		'Import Images',
		'upload_files',
		'import-images-url',
		'uimptr_import_images_url_page'
	);
}

// Enable SVG uploads for the plugin
add_filter( 'upload_mimes', 'uimptr_add_svg_mime_type' );
function uimptr_add_svg_mime_type( $mimes ) {
	// Only add SVG support if not already present
	if ( ! isset( $mimes['svg'] ) ) {
		$mimes['svg'] = 'image/svg+xml';
	}
	return $mimes;
}

// Add SVG to allowed file types for wp_check_filetype
add_filter( 'wp_check_filetype_and_ext', 'uimptr_check_svg_filetype', 10, 4 );
function uimptr_check_svg_filetype( $data, $file, $filename, $mimes ) {
	$filetype = wp_check_filetype( $filename, $mimes );
	
	// If it's an SVG file, ensure proper detection
	if ( $filetype['ext'] === 'svg' ) {
		$data['ext'] = 'svg';
		$data['type'] = 'image/svg+xml';
		$data['proper_filename'] = $filename;
	}
	
	return $data;
}

/**
 * Sanitize SVG content for security using whitelist-based sanitization
 * 
 * Uses the enshrined/svg-sanitize library when available.
 * Falls back to a DOM-based whitelist sanitizer when the library is unavailable.
 * 
 * @param string $content The raw SVG content to sanitize
 * @return string|false The sanitized SVG content, or false if sanitization fails
 */
function uimptr_sanitize_svg_content( $content ) {
	// Try to use the enshrined/svg-sanitize library (recommended approach)
	if ( class_exists( '\\enshrined\\svgSanitize\\Sanitizer' ) ) {
		try {
			$sanitizer = new \enshrined\svgSanitize\Sanitizer();
			$sanitizer->minify( true );
			$sanitized = $sanitizer->sanitize( $content );
			
			// The library returns false if sanitization fails
			if ( $sanitized === false ) {
				return false;
			}
			
			return $sanitized;
		} catch ( Throwable $exception ) {
			return uimptr_sanitize_svg_content_with_dom( $content );
		}
	}

	return uimptr_sanitize_svg_content_with_dom( $content );
}

/**
 * DOM-based SVG sanitizer for environments where the svg-sanitize library is unavailable.
 *
 * @param string $content Raw SVG content.
 * @return string|false
 */
function uimptr_sanitize_svg_content_with_dom( $content ) {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return false;
	}

	$document = uimptr_load_svg_document_securely( $content );
	if ( is_wp_error( $document ) ) {
		return false;
	}

	$root = $document->documentElement;
	if ( ! $root || 'svg' !== strtolower( $root->localName ) ) {
		return false;
	}

	if ( ! uimptr_is_allowed_svg_namespace( $root->namespaceURI ) ) {
		return false;
	}

	uimptr_sanitize_svg_element_node( $root );

	$sanitized = $document->saveXML( $document->documentElement );

	return is_string( $sanitized ) && '' !== trim( $sanitized ) ? $sanitized : false;
}

/**
 * Load an SVG document securely for DOM-based sanitization.
 *
 * @param string $content Raw SVG content.
 * @return DOMDocument|WP_Error
 */
function uimptr_load_svg_document_securely( $content ) {
	$content = (string) $content;

	if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $content ) ) {
		return new WP_Error( 'unsafe_svg', 'SVG documents with DOCTYPE or ENTITY declarations are not allowed.' );
	}

	$document                               = new DOMDocument();
	$document->preserveWhiteSpace           = false;
	$document->formatOutput                 = false;
	$previous_use_internal_errors           = libxml_use_internal_errors( true );
	$restore_entity_loader                  = false;
	$previous_entity_loader_state           = null;
	$libxml_options                         = LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING;

	if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
		$previous_entity_loader_state = libxml_disable_entity_loader( true );
		$restore_entity_loader        = true;
	}

	$loaded = $document->loadXML( $content, $libxml_options );

	libxml_clear_errors();
	libxml_use_internal_errors( $previous_use_internal_errors );

	if ( $restore_entity_loader ) {
		libxml_disable_entity_loader( $previous_entity_loader_state );
	}

	if ( ! $loaded ) {
		return new WP_Error( 'invalid_svg', 'Failed to parse SVG file.' );
	}

	return $document;
}

/**
 * Sanitize an SVG element node recursively using a whitelist.
 *
 * @param DOMElement $element Element to sanitize.
 * @return void
 */
function uimptr_sanitize_svg_element_node( DOMElement $element ) {
	$allowed_elements = uimptr_get_allowed_svg_elements();
	$allowed_attrs    = uimptr_get_allowed_svg_attributes();

	$children = array();
	foreach ( $element->childNodes as $child_node ) {
		$children[] = $child_node;
	}

	foreach ( $children as $child_node ) {
		if ( XML_ELEMENT_NODE === $child_node->nodeType ) {
			$child_name = strtolower( $child_node->localName );
			if ( ! uimptr_is_allowed_svg_namespace( $child_node->namespaceURI ) || ! isset( $allowed_elements[ $child_name ] ) ) {
				$element->removeChild( $child_node );
				continue;
			}

			uimptr_sanitize_svg_attributes( $child_node, $allowed_attrs );
			uimptr_sanitize_svg_element_node( $child_node );
			continue;
		}

		if ( XML_COMMENT_NODE === $child_node->nodeType || XML_PI_NODE === $child_node->nodeType || XML_DOCUMENT_TYPE_NODE === $child_node->nodeType ) {
			$element->removeChild( $child_node );
		}
	}

	uimptr_sanitize_svg_attributes( $element, $allowed_attrs );
}

/**
 * Sanitize attributes on an SVG element using a whitelist.
 *
 * @param DOMElement $element       SVG element.
 * @param array      $allowed_attrs Allowed attribute lookup table.
 * @return void
 */
function uimptr_sanitize_svg_attributes( DOMElement $element, array $allowed_attrs ) {
	if ( ! $element->hasAttributes() ) {
		return;
	}

	$attributes = array();
	foreach ( $element->attributes as $attribute ) {
		$attributes[] = $attribute;
	}

	foreach ( $attributes as $attribute ) {
		$attr_name       = strtolower( $attribute->nodeName );
		$attr_local_name = strtolower( $attribute->localName ? $attribute->localName : $attribute->nodeName );
		$attr_value      = $attribute->value;

		if ( 0 === strpos( $attr_name, 'on' ) || 0 === strpos( $attr_local_name, 'on' ) ) {
			$element->removeAttributeNode( $attribute );
			continue;
		}

		if ( ! isset( $allowed_attrs[ $attr_name ] ) && ! isset( $allowed_attrs[ $attr_local_name ] ) ) {
			$element->removeAttributeNode( $attribute );
			continue;
		}

		if ( ! uimptr_is_safe_svg_attribute_value( $attr_name, $attr_value ) ) {
			$element->removeAttributeNode( $attribute );
			continue;
		}

		if ( 'style' === $attr_local_name ) {
			$sanitized_style = uimptr_sanitize_svg_style_attribute( $attr_value );
			if ( '' === $sanitized_style ) {
				$element->removeAttributeNode( $attribute );
			} else {
				$attribute->value = $sanitized_style;
			}
		}
	}
}

/**
 * Get the whitelist of safe SVG elements for DOM sanitization.
 *
 * @return array
 */
function uimptr_get_allowed_svg_elements() {
	static $allowed_elements = null;

	if ( null === $allowed_elements ) {
		$allowed_elements = array_fill_keys(
			array(
				'svg',
				'g',
				'defs',
				'title',
				'desc',
				'symbol',
				'use',
				'path',
				'rect',
				'circle',
				'ellipse',
				'line',
				'polyline',
				'polygon',
				'clippath',
				'mask',
				'lineargradient',
				'radialgradient',
				'stop',
				'pattern',
				'marker',
				'text',
				'tspan',
				'textpath',
			),
			true
		);
	}

	return $allowed_elements;
}

/**
 * Get the whitelist of safe SVG attributes for DOM sanitization.
 *
 * @return array
 */
function uimptr_get_allowed_svg_attributes() {
	static $allowed_attributes = null;

	if ( null === $allowed_attributes ) {
		$allowed_attributes = array_fill_keys(
			array(
				'id',
				'class',
				'xmlns',
				'xmlns:xlink',
				'viewbox',
				'version',
				'width',
				'height',
				'x',
				'y',
				'x1',
				'y1',
				'x2',
				'y2',
				'cx',
				'cy',
				'r',
				'rx',
				'ry',
				'd',
				'points',
				'transform',
				'fill',
				'fill-opacity',
				'fill-rule',
				'stroke',
				'stroke-opacity',
				'stroke-width',
				'stroke-linecap',
				'stroke-linejoin',
				'stroke-miterlimit',
				'stroke-dasharray',
				'stroke-dashoffset',
				'opacity',
				'display',
				'visibility',
				'preserveaspectratio',
				'gradientunits',
				'gradienttransform',
				'spreadmethod',
				'offset',
				'stop-color',
				'stop-opacity',
				'patternunits',
				'patterncontentunits',
				'patterntransform',
				'markerwidth',
				'markerheight',
				'markerunits',
				'refx',
				'refy',
				'orient',
				'clippathunits',
				'maskunits',
				'maskcontentunits',
				'href',
				'xlink:href',
				'clip-path',
				'mask',
				'style',
				'font-family',
				'font-size',
				'font-style',
				'font-weight',
				'text-anchor',
				'dominant-baseline',
				'letter-spacing',
				'textlength',
				'lengthadjust',
				'startoffset',
				'role',
				'aria-hidden',
				'focusable',
			),
			true
		);
	}

	return $allowed_attributes;
}

/**
 * Get the whitelist of safe CSS properties for SVG style attributes.
 *
 * @return array
 */
function uimptr_get_allowed_svg_style_properties() {
	static $allowed_properties = null;

	if ( null === $allowed_properties ) {
		$allowed_properties = array_fill_keys(
			array(
				'fill',
				'fill-opacity',
				'fill-rule',
				'stroke',
				'stroke-opacity',
				'stroke-width',
				'stroke-linecap',
				'stroke-linejoin',
				'stroke-miterlimit',
				'stroke-dasharray',
				'stroke-dashoffset',
				'opacity',
				'display',
				'visibility',
				'stop-color',
				'stop-opacity',
				'font-family',
				'font-size',
				'font-style',
				'font-weight',
				'text-anchor',
				'dominant-baseline',
				'letter-spacing',
			),
			true
		);
	}

	return $allowed_properties;
}

/**
 * Check whether an SVG attribute value is safe.
 *
 * @param string $attr_name  Attribute name.
 * @param string $attr_value Attribute value.
 * @return bool
 */
function uimptr_is_safe_svg_attribute_value( $attr_name, $attr_value ) {
	$attr_name  = strtolower( (string) $attr_name );
	$attr_value = (string) $attr_value;
	$decoded    = html_entity_decode( $attr_value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded ) ) {
		return false;
	}

	if ( in_array( $attr_name, array( 'xmlns', 'xmlns:xlink' ), true ) ) {
		$namespace_whitelist = array(
			'xmlns'       => 'http://www.w3.org/2000/svg',
			'xmlns:xlink' => 'http://www.w3.org/1999/xlink',
		);

		return isset( $namespace_whitelist[ $attr_name ] ) && $namespace_whitelist[ $attr_name ] === trim( $decoded );
	}

	if ( in_array( $attr_name, array( 'href', 'xlink:href' ), true ) ) {
		return uimptr_is_safe_local_svg_reference( $decoded );
	}

	if ( 'style' === $attr_name ) {
		return true;
	}

	if ( preg_match( '/(?:javascript|vbscript|data)\s*:/i', $decoded ) ) {
		return false;
	}

	if ( preg_match( '/expression\s*\(|@import/i', $decoded ) ) {
		return false;
	}

	if ( false !== stripos( $decoded, 'url(' ) ) {
		return (bool) preg_match( '/^\s*url\(\s*[\'"]?#[-A-Za-z0-9_:.]+[\'"]?\s*\)\s*$/i', $decoded );
	}

	return true;
}

/**
 * Check whether an SVG reference points only to a local fragment.
 *
 * @param string $value Reference value.
 * @return bool
 */
function uimptr_is_safe_local_svg_reference( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return false;
	}

	return (bool) preg_match( '/^#[-A-Za-z0-9_:.]+$/', $value );
}

/**
 * Sanitize an inline SVG style attribute value using a CSS property whitelist.
 *
 * @param string $style_value Raw style attribute.
 * @return string
 */
function uimptr_sanitize_svg_style_attribute( $style_value ) {
	$allowed_properties = uimptr_get_allowed_svg_style_properties();
	$sanitized_rules    = array();
	$rules              = explode( ';', (string) $style_value );

	foreach ( $rules as $rule ) {
		$rule = trim( $rule );
		if ( '' === $rule || false === strpos( $rule, ':' ) ) {
			continue;
		}

		list( $property, $value ) = array_map( 'trim', explode( ':', $rule, 2 ) );
		$property = strtolower( $property );

		if ( '' === $property || ! isset( $allowed_properties[ $property ] ) ) {
			continue;
		}

		if ( ! uimptr_is_safe_svg_attribute_value( $property, $value ) ) {
			continue;
		}

		$sanitized_rules[] = $property . ':' . $value;
	}

	return implode( ';', $sanitized_rules );
}

/**
 * Check whether an element namespace is permitted for SVG sanitization.
 *
 * @param string|null $namespace_uri Namespace URI.
 * @return bool
 */
function uimptr_is_allowed_svg_namespace( $namespace_uri ) {
	return null === $namespace_uri || '' === $namespace_uri || 'http://www.w3.org/2000/svg' === $namespace_uri;
}

// Fallback menu registration - only add if PSR-4 Plugin class didn't register it
add_action( 'admin_menu', function() {
	// Check if PSR-4 Plugin class successfully registered the menu
	global $submenu;
	$psr4_menu_exists = false;
	
	if (isset($submenu['upload.php'])) {
		foreach ($submenu['upload.php'] as $item) {
			if (isset($item[2]) && $item[2] === 'import-images-url') {
				$psr4_menu_exists = true;
				break;
			}
		}
	}
	
	// If PSR-4 menu doesn't exist, register fallback
	if (!$psr4_menu_exists) {
		uimptr_admin_menu();
	}
}, 20); // Priority 20 to run after PSR-4 Plugin class

/**
 * Enqueue scripts and styles
 */
function uimptr_admin_styles() {
	if ( isset( $_GET['page'] ) && 'import-images-url' === $_GET['page'] ) {
		wp_enqueue_style( 'uimptr-bootstrap', plugins_url( 'assets/bootstrap/css/bootstrap.min.css', __FILE__ ), '', UIMPTR_VERSION );
		// Version admin.css by file mtime so CSS edits bust the browser cache
		// even when the plugin version is unchanged.
		$uimptr_admin_css     = plugin_dir_path( __FILE__ ) . 'assets/css/admin.css';
		$uimptr_admin_css_ver = file_exists( $uimptr_admin_css ) ? filemtime( $uimptr_admin_css ) : UIMPTR_VERSION;
		wp_enqueue_style( 'uimptr-styles', plugins_url( 'assets/css/admin.css', __FILE__ ), '', $uimptr_admin_css_ver );
		wp_enqueue_script( 'uimptr-chartjs', plugins_url( 'assets/js/Chart.min.js', __FILE__ ), '', UIMPTR_VERSION, true );
		wp_enqueue_script( 'bfu-bootstrap', plugins_url( 'assets/bootstrap/js/bootstrap.bundle.min.js', __FILE__ ), '', UIMPTR_VERSION, true );
		$uimptr_admin_js     = plugin_dir_path( __FILE__ ) . 'assets/js/admin.js';
		$uimptr_admin_js_ver = file_exists( $uimptr_admin_js ) ? filemtime( $uimptr_admin_js ) : UIMPTR_VERSION;
		wp_enqueue_script( 'uimptr-js', plugins_url( 'assets/js/admin.js', __FILE__ ), '', $uimptr_admin_js_ver, true );
	}
	$data                            = array();
		$data['strings']             = array(
			'leave_confirm'      => esc_html__( 'Are you sure you want to leave this tab? The current bulk action will be canceled and you will need to continue where it left off later.', 'url-image-importer' ),
			'ajax_error'         => esc_html__( 'Too many server errors. Please try again.', 'url-image-importer' ),
			'leave_confirmation' => esc_html__( 'If you leave this page the sync will be interrupted and you will have to continue where you left off later.', 'url-image-importer' ),
		);
		$data['ajax_url']            = admin_url( 'admin-ajax.php' );
		$data['local_types']         = uimptr_get_filetypes( true );
		$data['default_upload_size'] = wp_max_upload_size();
		$data['uimptr_nonce']        = wp_create_nonce( 'ajax-nonce' );
		wp_localize_script( 'uimptr-js', 'bfu_data', $data );
		
		// Add AJAX data for import functionality
		$uimptr_ajax_data = array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'admin_post_url' => admin_url( 'admin-post.php' ),
			'nonce'          => uimptr_create_ajax_nonce(),
			'nonce_field'    => uimptr_get_ajax_nonce_field(),
			'batch_seed'     => uimptr_create_batch_id_seed(),
		);
		wp_localize_script( 'uimptr-js', 'uimptr_ajax', $uimptr_ajax_data );
		
		// Add inline script to verify AJAX object is loaded
		$inline_script = '
		jQuery(document).ready(function($) {
			console.log("URL Image Importer scripts loaded");
			console.log("uimptr_ajax object available:", typeof uimptr_ajax !== "undefined");
			if (typeof uimptr_ajax !== "undefined") {
				console.log("uimptr_ajax contents:", uimptr_ajax);
			} else {
				console.error("ERROR: uimptr_ajax object is not defined!");
			}
		});
		';
		wp_add_inline_script( 'uimptr-js', $inline_script );
}
add_action( 'admin_enqueue_scripts', 'uimptr_admin_styles' );

/**
 * Handle XML file import
 */
function uimptr_handle_xml_import() {
	if ( !isset( $_FILES['xml_file'] ) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK ) {
		return array( 'errors' => 1, 'messages' => array( 'No file uploaded or upload error occurred.' ) );
	}

	$uploaded_file = $_FILES['xml_file'];
	$file_extension = strtolower( pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) );

	if ( $file_extension !== 'xml' ) {
		return array( 'errors' => 1, 'messages' => array( 'Please upload a valid XML file.' ) );
	}

	// SECURITY: Validate uploaded file type before processing
	// XML files may have various mime types: text/xml, application/xml, text/plain
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$detected_mime = finfo_file( $finfo, $uploaded_file['tmp_name'] );
	finfo_close( $finfo );
	
	$allowed_xml_mimes = array( 'text/xml', 'application/xml', 'text/plain' );
	if ( ! in_array( $detected_mime, $allowed_xml_mimes, true ) ) {
		return array( 'errors' => 1, 'messages' => array( 'Invalid file type. Only XML files are allowed.' ) );
	}
	
	// Additional check: Verify the file actually contains XML content
	$file_content = file_get_contents( $uploaded_file['tmp_name'], false, null, 0, 2048 );
	// Remove BOM if present
	$file_content = preg_replace('/^\xEF\xBB\xBF/', '', $file_content);
	if ( stripos( $file_content, '<?xml' ) === false && stripos( $file_content, '<rss' ) === false ) {
		return array( 'errors' => 1, 'messages' => array( 'File does not appear to be valid XML content.' ) );
	}

	// Move uploaded file to temporary location
	$temp_file = wp_tempnam( $uploaded_file['name'] );
	if ( !move_uploaded_file( $uploaded_file['tmp_name'], $temp_file ) ) {
		return array( 'errors' => 1, 'messages' => array( 'Failed to process uploaded file.' ) );
	}

		// Import options
		$options = array(
			'images_only'      => isset( $_POST['images_only'] ),
			'force_reimport'  => isset( $_POST['force_reimport'] ),
		);

	// Process XML import
	$xml_importer = new \UrlImageImporter\Importer\WordPressXmlImporter();
	$results = $xml_importer->process_xml_import( $temp_file, $options );

	// Clean up temporary file
	uimptr_delete_file_with_logging( $temp_file, 'legacy XML import temp file cleanup' );

	return $results;
}

/**
 * Parse the raw "Image URLs" textarea input into a clean list of URLs.
 *
 * Accepts URLs separated by newlines and/or commas (in any combination), trims
 * surrounding whitespace from each entry, and drops empty entries. Commas are a
 * safe separator because a comma inside a URL is percent-encoded as %2C.
 *
 * @param string $raw Raw textarea value.
 * @return string[] List of non-empty, trimmed URLs.
 */
function uimptr_parse_image_urls_input( $raw ) {
	$parts = preg_split( '/[\r\n,]+/', (string) $raw );

	if ( false === $parts ) {
		return array();
	}

	return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
}

/**
 * Render the Infinite Uploads Pro feature upsell grid.
 *
 * Rendered once per page, beneath the storage analysis section (not inside the
 * import tabs). Clicking a feature card opens a modal describing that feature
 * with a "Try Infinite Uploads" call to action that opens the pricing page.
 */
function uimptr_render_upsell_bar() {
	// Feather Icons (MIT) rendered inline so no extra assets are required.
	$features = array(
		array(
			'title'    => __( 'Folders', 'url-image-importer' ),
			'desc'     => __( 'Organize your media files with folders.', 'url-image-importer' ),
			'heading'  => __( 'Organize your media library with smart folders', 'url-image-importer' ),
			'subtitle' => __( 'Try Infinite Uploads for free for 7 days and manage your media files with ease.', 'url-image-importer' ),
			'icon'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
		),
		array(
			'title'    => __( 'Smart Organization', 'url-image-importer' ),
			'desc'     => __( 'Drag & drop media to keep your library organized.', 'url-image-importer' ),
			'heading'  => __( 'Keep your media library effortlessly organized', 'url-image-importer' ),
			'subtitle' => __( 'Try Infinite Uploads for free for 7 days and drag & drop your media into order.', 'url-image-importer' ),
			'icon'     => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
		),
		array(
			'title'    => __( 'Cloud Storage', 'url-image-importer' ),
			'desc'     => __( 'Store your media securely in the cloud.', 'url-image-importer' ),
			'heading'  => __( 'Offload your media to the cloud with Infinite Uploads', 'url-image-importer' ),
			'subtitle' => __( 'Try Infinite Uploads for free for 7 days and reduce server load while improving performance.', 'url-image-importer' ),
			'icon'     => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
		),
		array(
			'title'    => __( 'CDN Delivery', 'url-image-importer' ),
			'desc'     => __( 'Deliver media via global CDN for faster sites.', 'url-image-importer' ),
			'heading'  => __( 'Deliver your media faster worldwide with Global CDN', 'url-image-importer' ),
			'subtitle' => __( 'Try Infinite Uploads for free for 7 days and serve your media through a global CDN.', 'url-image-importer' ),
			'icon'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		),
		array(
			'title'    => __( 'Advanced Search', 'url-image-importer' ),
			'desc'     => __( 'Find the right media instantly with advanced search.', 'url-image-importer' ),
			'heading'  => __( 'Find any file instantly with advanced media search', 'url-image-importer' ),
			'subtitle' => __( 'Try Infinite Uploads for free for 7 days and locate the right media in seconds.', 'url-image-importer' ),
			'icon'     => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
		),
		array(
			'title'    => __( 'Media Scalability', 'url-image-importer' ),
			'desc'     => __( 'Handle unlimited growth without limits.', 'url-image-importer' ),
			'heading'  => __( 'Scale your media storage without limits', 'url-image-importer' ),
			'subtitle' => __( 'Try Infinite Uploads for free for 7 days and handle unlimited growth effortlessly.', 'url-image-importer' ),
			'icon'     => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
		),
	);

	// Feather "lock" icon reused for every Pro badge.
	$lock_icon = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>';

	// Promotional CTAs point at the pricing page so the visitor can choose a
	// plan, rather than triggering the plugin install/activate flow.
	$pricing_url = class_exists( 'UrlImageImporter\\Admin\\PromoNotices' )
		? UrlImageImporter\Admin\PromoNotices::get_pricing_url( 'upsell_banner' )
		: 'https://infiniteuploads.com/pricing/';

	// Official Infinite Uploads brand marks (bundled with the plugin).
	$iu_logo_mark = plugins_url( 'assets/img/iu-logo-blue.svg', __FILE__ );
	$iu_wordmark  = plugins_url( 'assets/img/iu-logo-words.svg', __FILE__ );
	?>
	<div class="uimptr-upsell-grid">
		<?php foreach ( $features as $index => $feature ) : ?>
			<button type="button" class="uimptr-upsell-card" data-uimptr-feature="<?php echo esc_attr( $index ); ?>" aria-haspopup="dialog">
				<span class="uimptr-upsell-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $feature['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG path markup. ?></svg>
				</span>
				<span class="uimptr-upsell-text">
					<span class="uimptr-upsell-title"><?php echo esc_html( $feature['title'] ); ?></span>
					<span class="uimptr-upsell-desc"><?php echo esc_html( $feature['desc'] ); ?></span>
				</span>
				<span class="uimptr-upsell-badge">
					<?php esc_html_e( 'Pro', 'url-image-importer' ); ?>
					<svg class="uimptr-upsell-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $lock_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG path markup. ?></svg>
				</span>
			</button>
		<?php endforeach; ?>
	</div>

	<div class="uimptr-cloud-banner">
		<span class="uimptr-cloud-banner__badge" aria-hidden="true">
			<img src="<?php echo esc_url( $iu_logo_mark ); ?>" alt="" width="38" height="29" />
		</span>
		<span class="uimptr-cloud-banner__art" aria-hidden="true">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
		</span>
		<span class="uimptr-cloud-banner__content">
			<span class="uimptr-cloud-banner__title"><?php esc_html_e( 'You can move your storage to Cloud on Infinite Uploads', 'url-image-importer' ); ?></span>
			<span class="uimptr-cloud-banner__subtitle"><?php esc_html_e( 'Try out Infinite Uploads for Free for 7 days and upload your storage to the cloud.', 'url-image-importer' ); ?></span>
				<a class="uimptr-cloud-banner__cta" href="<?php echo esc_url( $pricing_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Start Free', 'url-image-importer' ); ?>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
				</a>
		</span>
		<button type="button" class="uimptr-cloud-banner__dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'url-image-importer' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
		</button>
	</div>
	<?php
	// The feature modal, dismiss handler and click wiring only need to exist once,
	// even though this helper renders on all three import tabs.
	static $singletons_printed = false;
	if ( $singletons_printed ) {
		return;
	}
	$singletons_printed = true;

	// Feature data for the modal, keyed by the card index. Only presentational
	// text and the icon differ per feature; the install action is shared.
	$modal_features = array();
	foreach ( $features as $index => $feature ) {
		$modal_features[ $index ] = array(
			'heading'  => $feature['heading'],
			'subtitle' => $feature['subtitle'],
			'icon'     => $feature['icon'],
		);
	}
	?>
	<div class="uimptr-feature-modal" id="uimptr-feature-modal" aria-hidden="true">
		<div class="uimptr-feature-modal__overlay" data-uimptr-close></div>
		<div class="uimptr-feature-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="uimptr-feature-modal-heading">
			<button type="button" class="uimptr-feature-modal__close" data-uimptr-close aria-label="<?php esc_attr_e( 'Close', 'url-image-importer' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
			</button>
			<img class="uimptr-feature-modal__brand" src="<?php echo esc_url( $iu_wordmark ); ?>" alt="Infinite Uploads" width="132" height="33" />
			<span class="uimptr-feature-modal__icon" id="uimptr-feature-modal-icon" aria-hidden="true"></span>
			<h2 class="uimptr-feature-modal__heading" id="uimptr-feature-modal-heading"></h2>
			<p class="uimptr-feature-modal__subtitle" id="uimptr-feature-modal-subtitle"></p>
			<div class="uimptr-feature-modal__actions">
				<a class="btn text-nowrap btn-primary btn-lg" href="<?php echo esc_url( $pricing_url ); ?>" role="button" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Try Infinite Uploads', 'url-image-importer' ); ?>
				</a>
			</div>
			<p class="uimptr-feature-modal__note">
				<?php
					// translators: %s is a cloud icon.
					printf( esc_html__( 'Get 7 days of %s storage, bandwidth, media folders, and more for FREE. Plans starting at just $8.25/mo.', 'url-image-importer' ), '<span class="dashicons dashicons-cloud" aria-hidden="true"></span>' );
				?>
			</p>
		</div>
	</div>
	<script>
	( function () {
		var FEATURES = <?php echo wp_json_encode( $modal_features ); ?>;

		var modal      = document.getElementById( 'uimptr-feature-modal' );
		var iconEl     = document.getElementById( 'uimptr-feature-modal-icon' );
		var headingEl  = document.getElementById( 'uimptr-feature-modal-heading' );
		var subtitleEl = document.getElementById( 'uimptr-feature-modal-subtitle' );
		var lastFocus  = null;

		// The modal is printed inside the first import tab; move it to <body> so
		// hiding that tab (display:none) never hides the fixed-position modal.
		if ( modal && modal.parentNode !== document.body ) {
			document.body.appendChild( modal );
		}

		function openModal( key ) {
			var data = FEATURES[ key ];
			if ( ! data || ! modal ) {
				return;
			}
			iconEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + data.icon + '</svg>';
			headingEl.textContent  = data.heading;
			subtitleEl.textContent = data.subtitle;
			modal.classList.add( 'is-open' );
			modal.setAttribute( 'aria-hidden', 'false' );
			document.body.classList.add( 'uimptr-modal-open' );
			var cta = modal.querySelector( '.uimptr-feature-modal__actions .btn' );
			if ( cta ) {
				cta.focus();
			}
		}

		function closeModal() {
			if ( ! modal ) {
				return;
			}
			modal.classList.remove( 'is-open' );
			modal.setAttribute( 'aria-hidden', 'true' );
			document.body.classList.remove( 'uimptr-modal-open' );
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}

		// Cloud banner dismissal (persisted per browser).
		var BANNER_KEY = 'uimptrCloudBannerDismissed';
		function hideBanners() {
			var banners = document.querySelectorAll( '.uimptr-cloud-banner' );
			for ( var i = 0; i < banners.length; i++ ) {
				banners[ i ].style.display = 'none';
			}
		}
		try {
			if ( window.localStorage && localStorage.getItem( BANNER_KEY ) === '1' ) {
				hideBanners();
			}
		} catch ( err ) {}

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest ) {
				return;
			}

			var card = e.target.closest( '[data-uimptr-feature]' );
			if ( card ) {
				e.preventDefault();
				lastFocus = card;
				openModal( card.getAttribute( 'data-uimptr-feature' ) );
				return;
			}

			if ( e.target.closest( '[data-uimptr-close]' ) ) {
				e.preventDefault();
				closeModal();
				return;
			}

			if ( e.target.closest( '.uimptr-cloud-banner__dismiss' ) ) {
				try {
					if ( window.localStorage ) {
						localStorage.setItem( BANNER_KEY, '1' );
					}
				} catch ( err ) {}
				hideBanners();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && modal && modal.classList.contains( 'is-open' ) ) {
				closeModal();
			}
		} );
	} )();
	</script>
	<?php
}

/**
 * Import Image Form HTML
 */
function uimptr_import_images_url_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
	}

	// Debug feature: Clear scan cache (add ?clear_scan=1 to URL)
	if ( isset( $_GET['clear_scan'] ) && current_user_can( 'manage_options' ) ) {
		delete_site_option( 'uimptr_file_scan' );
		echo '<div class="notice notice-success is-dismissible"><p>';
		echo '<strong>✓ URL Image Importer:</strong> ';
		echo esc_html__('Scan cache has been cleared. Click "Scan Media Library" to start a fresh scan.', 'url-image-importer');
		echo '</p></div>';
	}
	
	// Debug feature: Reset all dismissed notices for testing (add ?undismiss=1 to URL)
	if ( isset( $_GET['undismiss'] ) && current_user_can( 'manage_options' ) ) {
		// Reset URL Image Importer specific notices
		delete_user_meta( get_current_user_id(), 'uimptr_notice_infinite_uploads_promo' );
		delete_user_meta( get_current_user_id(), 'uimptr_notice_big_file_form_uploads_promo' );
		
		// Reset legacy notices if they exist
		delete_user_option( get_current_user_id(), 'bfu_notice_dismissed' );
		delete_user_option( get_current_user_id(), 'bfu_upgrade_notice_dismissed' );
		delete_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed' );
		
		// Show confirmation message
		echo '<div class="notice notice-success is-dismissible"><p>';
		echo '<strong>✓ URL Image Importer:</strong> ';
		echo esc_html__('All dismissed notices have been reset. Refresh the page to see banners again.', 'url-image-importer');
		echo '</p></div>';
	}
	
	$results = array();

	// Handle URL Import
	if ( isset( $_POST['image_urls'] ) ) {
		check_admin_referer( 'uimptr-form-field', '_wpnonce_select_form' );
		$image_urls = uimptr_parse_image_urls_input( sanitize_textarea_field( wp_unslash( $_POST['image_urls'] ) ) );

		foreach ( $image_urls as $image_url ) {
			if ( filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
				$attachment_id = uimptr_import_image_from_url( $image_url );

				if ( is_wp_error( $attachment_id ) ) {
					$results[] = '<div class="error"><p>' . esc_html( $attachment_id->get_error_message() ) . ' (URL: ' . esc_url( $image_url ) . ')</p></div>';
				} else {
					$results[] = '<div class="updated"><p>Image imported successfully from ' . esc_url( $image_url ) . '! <a href="' . esc_url( get_edit_post_link( $attachment_id ) ) . '">Edit Image</a></p></div>';
				}
			} else {
				$results[] = '<div class="error"><p>Invalid URL: ' . esc_url( $image_url ) . '</p></div>';
			}
		}
	}

	// Handle XML Import
	if ( isset( $_POST['xml_import_submit'] ) && isset( $_POST['_wpnonce_xml_import'] ) && wp_verify_nonce( $_POST['_wpnonce_xml_import'], 'uimptr-xml-import' ) ) {
		$xml_results = uimptr_handle_xml_import();
		if ( $xml_results ) {
			$results[] = '<div class="updated"><p><strong>XML Import Results:</strong><br/>' .
				'Imported: ' . intval( $xml_results['imported'] ) . ' images<br/>' .
				'Skipped: ' . intval( $xml_results['skipped'] ) . ' items<br/>' .
				'Errors: ' . intval( $xml_results['errors'] ) . ' items</p></div>';
			
			if ( !empty( $xml_results['messages'] ) && $xml_results['errors'] > 0 ) {
				$error_messages = array_filter( $xml_results['messages'], function( $msg ) {
					return strpos( $msg, 'Failed' ) !== false || strpos( $msg, 'Error' ) !== false;
				});
				if ( !empty( $error_messages ) ) {
					$results[] = '<div class="error"><p><strong>Import Errors:</strong></p><ul>';
					foreach ( array_slice( $error_messages, 0, 5 ) as $message ) {
						$results[] = '<li>' . esc_html( $message ) . '</li>';
					}
					if ( count( $error_messages ) > 5 ) {
						$results[] = '<li>... and ' . ( count( $error_messages ) - 5 ) . ' more errors</li>';
					}
					$results[] = '</ul></div>';
				}
			}
		}
	}

	if ( !empty( $results ) ) {
		$allowed_tags = array(
			'div' => array(
				'class' => array(),
			),
			'p'   => array(),
			'a'   => array(
				'href' => array(),
			),
		);
		foreach ( $results as $result ) {
			echo wp_kses( $result, $allowed_tags );
		}
	}

	?>
	<div id="container" class="wrap">
		<h1>
			<img src="<?php echo esc_url( plugins_url( 'assets/img/infiniteuploads.svg', __FILE__ ) ); ?>" height="50"> 
			<?php echo esc_html( 'URL Image Importer' ); ?>
		</h1>
		
	</div>
	
	<!-- Import Method Tabs -->
	<div class="nav-tab-wrapper" style="margin-bottom: 20px;">
		<a href="#url-import" class="nav-tab nav-tab-active" id="url-tab">URL Import</a>
		<a href="#xml-import" class="nav-tab" id="xml-tab">WordPress XML Import</a>
		<a href="#csv-import" class="nav-tab" id="csv-tab">CSV Import</a>
	</div>

	<!-- URL Import Form -->
	<div id="url-import" class="import-method">
		<form method="post">
			<?php wp_nonce_field( 'uimptr-form-field', '_wpnonce_select_form' ); ?>
			<div class="card upload">
				<div class="card-header">
					<div class="d-flex align-items-center">
						<h5 class="m-0 mr-auto p-0"><?php echo esc_html( 'Image URLs (one per line or comma-separated)' ); ?></h5>
					</div>
				</div>
				<div class="card-body p-md-1">
					<div class="row justify-content-center mb-3 mt-3">
						<div class="col text-center">
							<textarea name="image_urls" id="image_urls" class="large-text" rows="10"></textarea>
						</div>
					</div>
					<div class="row mb-3">
						<div class="col text-center">
								<label style="display: inline-flex; align-items: center; font-size: 14px; cursor: pointer;">
									<input type="checkbox" name="url_preserve_dates" id="url_preserve_dates" style="margin-right: 8px;">
									<?php esc_html_e( 'Preserve original dates (if available) instead of importing as current date', 'url-image-importer' ); ?>
								</label>
						</div>
					</div>
					<div class="row justify-content-center mb-2">
						<div class="col-md-6 col-md-5 col-xl-4 text-center">
							<button type="button" id="start-url-import" class="btn text-nowrap btn-primary btn-lg"><?php esc_html_e( 'Import Images from URLs', 'url-image-importer' ); ?></button>
						</div>
					</div>
						<p class="description" style="text-align: center; margin-bottom: 10px;">
							<?php esc_html_e( 'For dedicated high-speed servers, import in runs of 500-2,000 URLs for the best balance of speed and reliability.', 'url-image-importer' ); ?>
						</p>
						<p class="description" style="text-align: center; margin: 0 0 12px;">
							<?php esc_html_e( 'Want unlimited storage space, CDN, video hosting, folders, and enhanced media library search?', 'url-image-importer' ); ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=big_file_uploads#upgrade-modal' ) ); ?>"><?php esc_html_e( 'Move your media files to the Infinite Uploads cloud.', 'url-image-importer' ); ?></a>
						</p>
						
						<!-- Progress Bar for URL Import -->
					<div id="url-progress-container" style="display: none; margin-top: 20px;">
						<div class="progress-info">
							<span id="url-progress-text">Starting import...</span>
							<span id="url-progress-count" style="float: right;">0/0</span>
						</div>
						<div class="progress-bar-container" style="background: #f1f1f1; border-radius: 4px; height: 20px; margin: 10px 0;">
							<div id="url-progress-bar" style="background: #0073aa; height: 100%; width: 0%; border-radius: 4px; transition: width 0.3s;"></div>
						</div>
						<div class="progress-stats" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px; font-size: 14px;">
							<span style="color: #28a745; margin-right: 15px;"><strong>✓ Success:</strong> <span id="url-success-count">0</span></span>
							<span style="color: #dc3545; margin-right: 15px;"><strong>✗ Failed:</strong> <span id="url-failed-count">0</span></span>
							<span style="color: #6c757d;" title="<?php esc_attr_e( 'Files may be skipped if: 1) File already exists in Media Library (unless Force Reimport is checked), 2) URL is empty or invalid, 3) Not an image when Images Only is selected', 'url-image-importer' ); ?>">
								<strong>⊘ Skipped:</strong> <span id="url-skipped-count">0</span>
								<span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle; cursor: help;"></span>
							</span>
						</div>
						<div class="progress-actions">
							<button type="button" id="cancel-url-import" class="btn text-nowrap btn-primary btn-lg" title="<?php esc_attr_e( 'Stop the import process immediately', 'url-image-importer' ); ?>"><?php esc_html_e( 'Stop Import', 'url-image-importer' ); ?></button>
						</div>
						<div id="url-import-results" style="margin-top: 15px;"></div>
					</div>
				</div>
			</div>
		</form>
	</div>

	<!-- XML Import Form -->
	<div id="xml-import" class="import-method" style="display: none;">
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'uimptr-xml-import', '_wpnonce_xml_import' ); ?>
			<div class="card upload">
				<div class="card-header">
					<div class="d-flex align-items-center">
						<h5 class="m-0 mr-auto p-0"><?php echo esc_html( 'WordPress XML Export File' ); ?></h5>
					</div>
				</div>
				<div class="card-body p-md-1">
					<div class="row justify-content-center mb-3 mt-3">
						<div class="col">
							<p><?php esc_html_e( 'Upload a WordPress XML export file to import images from another WordPress site.', 'url-image-importer' ); ?></p>
							<input type="file" name="xml_file" id="xml_file" accept=".xml" required />
							<p class="description">
								<?php esc_html_e( 'Select a .xml file exported from WordPress (Tools → Export → Media).', 'url-image-importer' ); ?>
							</p>
						</div>
					</div>
					
					<div class="row mb-3">
						<div class="col">
							<h6><?php esc_html_e( 'Import Options:', 'url-image-importer' ); ?></h6>
							<label>
								<input type="checkbox" name="images_only" value="1" checked />
								<?php esc_html_e( 'Import images only (skip other attachment types)', 'url-image-importer' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="force_reimport" value="1" />
								<?php esc_html_e( 'Force re-import (import even if files already exist)', 'url-image-importer' ); ?>
							</label><br />
								<label>
									<input type="checkbox" name="xml_preserve_dates" id="xml_preserve_dates" />
									<?php esc_html_e( 'Preserve original dates instead of importing as current date', 'url-image-importer' ); ?>
								</label>
						</div>
					</div>
					
					<div class="row justify-content-center mb-2">
						<div class="col-md-6 col-md-5 col-xl-4 text-center">
							<button type="button" id="start-xml-import" class="btn text-nowrap btn-primary btn-lg"><?php esc_html_e( 'Import from XML File', 'url-image-importer' ); ?></button>
						</div>
					</div>
						<p class="description" style="text-align: center; margin-bottom: 10px;">
							<?php esc_html_e( 'For dedicated high-speed servers, import in runs of 500-2,000 URLs for the best balance of speed and reliability.', 'url-image-importer' ); ?>
						</p>
						
						<!-- Progress Bar for XML Import -->
					<div id="xml-progress-container" style="display: none; margin-top: 20px;">
						<div class="progress-info">
							<span id="xml-progress-text">Processing XML file...</span>
							<span id="xml-progress-count" style="float: right;">0/0</span>
						</div>
						<div class="progress-bar-container" style="background: #f1f1f1; border-radius: 4px; height: 20px; margin: 10px 0;">
							<div id="xml-progress-bar" style="background: #0073aa; height: 100%; width: 0%; border-radius: 4px; transition: width 0.3s;"></div>
						</div>
						<div class="progress-stats" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px; font-size: 14px;">
							<span style="color: #28a745; margin-right: 15px;"><strong>✓ Success:</strong> <span id="xml-success-count">0</span></span>
							<span style="color: #dc3545; margin-right: 15px;"><strong>✗ Failed:</strong> <span id="xml-failed-count">0</span></span>
							<span style="color: #6c757d;" title="<?php esc_attr_e( 'Files may be skipped if: 1) File already exists in Media Library (unless Force Reimport is checked), 2) URL is empty or invalid, 3) Not an image when Images Only is selected', 'url-image-importer' ); ?>">
								<strong>⊘ Skipped:</strong> <span id="xml-skipped-count">0</span>
								<span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle; cursor: help;"></span>
							</span>
						</div>
						<div class="progress-actions">
							<button type="button" id="cancel-xml-import" class="btn text-nowrap btn-primary btn-lg" title="<?php esc_attr_e( 'Stop the import process immediately', 'url-image-importer' ); ?>"><?php esc_html_e( 'Stop Import', 'url-image-importer' ); ?></button>
						</div>
						<div id="xml-import-results" style="margin-top: 15px;"></div>
					</div>
				</div>
			</div>
		</form>
	</div>

	<!-- CSV Import Section -->
	<div id="csv-import" class="import-method" style="display: none;">
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'uimptr-csv-import', '_wpnonce_csv_import' ); ?>
			<div class="card upload">
				<div class="card-header">
					<div class="d-flex align-items-center">
						<h5 class="m-0 mr-auto p-0"><?php echo esc_html( 'CSV Import File' ); ?></h5>
					</div>
				</div>
				<div class="card-body p-md-1">
					<div class="row justify-content-center mb-3 mt-3">
						<div class="col">
							<p><?php esc_html_e( 'Upload a CSV file containing image URLs and metadata.', 'url-image-importer' ); ?></p>
							<input type="file" id="csv_file" name="csv_file" accept=".csv" required />
							<p class="description">
								<?php esc_html_e( 'Select a .csv file with image URLs and optional metadata columns.', 'url-image-importer' ); ?>
							</p>
							<div style="text-align: center; margin-top: 10px;">
								<a href="#" id="download-sample-csv" class="button button-secondary"><?php esc_html_e( 'Download Sample CSV', 'url-image-importer' ); ?></a>
							</div>
						</div>
					</div>
					
					<div class="row mb-3">
						<div class="col">
							<h6><?php esc_html_e( 'Import Options:', 'url-image-importer' ); ?></h6>
							<label>
								<input type="checkbox" name="csv_images_only" value="1" checked />
								<?php esc_html_e( 'Import images only (skip other file types)', 'url-image-importer' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="csv_force_reimport" value="1" />
								<?php esc_html_e( 'Force re-import (import even if files already exist)', 'url-image-importer' ); ?>
							</label><br />
								<label>
									<input type="checkbox" name="csv_preserve_dates" id="csv_preserve_dates" />
									<?php esc_html_e( 'Preserve original dates instead of importing as current date', 'url-image-importer' ); ?>
								</label>
						</div>
					</div>
				</div>
			</div>
				
				
				<div class="row justify-content-center mb-2">
					<div class="col-md-6 col-md-5 col-xl-4 text-center">
						<button type="button" id="start-csv-import" class="btn text-nowrap btn-primary btn-lg"><?php esc_html_e( 'Import from CSV File', 'url-image-importer' ); ?></button>
					</div>
				</div>
					<p class="description" style="text-align: center; margin-bottom: 10px;">
						<?php esc_html_e( 'For dedicated high-speed servers, import in runs of 500-2,000 URLs for the best balance of speed and reliability.', 'url-image-importer' ); ?>
					</p>
					<p class="description" style="text-align: center; margin: 0 0 12px;">
						<?php esc_html_e( 'Want unlimited storage space, CDN, video hosting, folders, and enhanced media library search?', 'url-image-importer' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=big_file_uploads#upgrade-modal' ) ); ?>"><?php esc_html_e( 'Move your media files to the Infinite Uploads cloud.', 'url-image-importer' ); ?></a>
					</p>
					
					<!-- Progress Bar for CSV Import -->
				<div id="csv-progress-container" style="display: none; margin-top: 20px;">
					<div class="progress-wrapper">
						<div class="progress-info">
							<span id="csv-progress-text">Starting import...</span>
							<span id="csv-progress-count" style="float: right;">0/0</span>
						</div>
						<div class="progress-bar-container">
							<div class="progress-bar" id="csv-progress-bar" style="width: 0%"></div>
						</div>
						<div class="progress-stats" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px; font-size: 14px;">
							<span style="color: #28a745; margin-right: 15px;"><strong>✓ Success:</strong> <span id="csv-success-count">0</span></span>
							<span style="color: #dc3545; margin-right: 15px;"><strong>✗ Failed:</strong> <span id="csv-failed-count">0</span></span>
							<span style="color: #6c757d;" title="<?php esc_attr_e( 'Files may be skipped if: 1) File already exists in Media Library (unless Force Reimport is checked), 2) URL is empty or invalid, 3) Not an image when Images Only is selected', 'url-image-importer' ); ?>">
								<strong>⊘ Skipped:</strong> <span id="csv-skipped-count">0</span>
								<span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle; cursor: help;"></span>
							</span>
						</div>
						<div class="progress-actions">
							<button type="button" id="cancel-csv-import" class="btn text-nowrap btn-primary btn-lg" title="<?php esc_attr_e( 'Stop the import process immediately', 'url-image-importer' ); ?>"><?php esc_html_e( 'Stop Import', 'url-image-importer' ); ?></button>
						</div>
						<div id="csv-import-results" style="margin-top: 15px;"></div>
					</div>
				</div>
			</div>
		</form>
	</div>

	<!-- Import Preview Modal -->
	<div id="import-preview-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">
		<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; width: 90%; max-width: 800px; max-height: 80%; border-radius: 8px; overflow: hidden;">
			<div style="padding: 20px; border-bottom: 1px solid #ddd; background: #f9f9f9;">
				<h3 style="margin: 0; display: inline-block;">Import Preview</h3>
				<button type="button" id="close-preview" style="float: right; background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
			</div>
			<div id="preview-content" style="padding: 20px; max-height: 400px; overflow-y: auto;">
				<!-- Preview content will be loaded here -->
			</div>
			<div style="padding: 20px; border-top: 1px solid #ddd; text-align: right; background: #f9f9f9;">
				<button type="button" id="cancel-import-preview" class="btn text-nowrap btn-primary btn-lg">Cancel</button>
				<button type="button" id="confirm-import" class="btn text-nowrap btn-primary btn-lg" style="margin-left: 10px;">Import Selected Items</button>
			</div>
		</div>
	</div>

	<style>
	.spinner {
		border: 4px solid #f3f3f3;
		border-top: 4px solid #0073aa;
		border-radius: 50%;
		width: 30px;
		height: 30px;
		animation: spin 2s linear infinite;
		margin: 0 auto;
	}
	
	@keyframes spin {
		0% { transform: rotate(0deg); }
		100% { transform: rotate(360deg); }
	}
	
	#import-preview-modal .notice {
		margin: 10px 0;
	}
	
	.url-checkbox, .xml-checkbox, .csv-checkbox {
		transform: scale(1.2);
		margin-right: 10px !important;
	}
	</style>

	<script>
	jQuery(document).ready(function($) {
		// Force center alignment on all import buttons to override WordPress admin styles
		setTimeout(function() {
			$('.button-primary').each(function() {
				var $button = $(this);
				var $container = $button.closest('.row');
				
				// Apply aggressive centering styles
				$container.css({
					'display': 'flex !important',
					'justify-content': 'center !important',
					'align-items': 'center !important',
					'text-align': 'center !important'
				});
				
				$button.parent().css({
					'display': 'flex !important',
					'justify-content': 'center !important',
					'align-items': 'center !important',
					'text-align': 'center !important'
				});
				
				$button.css({
					'margin': '0 auto !important',
					'display': 'inline-block !important'
				});
			});
		}, 100);
		
		// Define AJAX data if not already available
		if (typeof uimptr_ajax === 'undefined') {
			window.uimptr_ajax = {
				ajax_url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
				nonce: '<?php echo esc_js( uimptr_create_ajax_nonce() ); ?>',
				nonce_field: '<?php echo esc_js( uimptr_get_ajax_nonce_field() ); ?>',
				batch_seed: '<?php echo esc_js( uimptr_create_batch_id_seed() ); ?>'
			};
		}
		
		console.log('uimptr_ajax initialized:', uimptr_ajax);
		
		var importCanceled = false;
		var currentImportType = '';
		
		// Tab switching
		$('.nav-tab').click(function(e) {
			e.preventDefault();
			
			// Remove active class from all tabs
			$('.nav-tab').removeClass('nav-tab-active');
			$('.import-method').hide();
			
			// Add active class to clicked tab
			$(this).addClass('nav-tab-active');
			
			// Show corresponding form
			var target = $(this).attr('href');
			$(target).show();
		});
		
		// URL Import with Preview
		$('#start-url-import').click(function() {
			var urls = $('#image_urls').val().trim();
			if (!urls) {
				alert('<?php esc_html_e( 'Please enter at least one image URL.', 'url-image-importer' ); ?>');
				return;
			}
			
			var urlList = urls.split(/[\r\n,]+/).map(function(url) {
				return url.trim();
			}).filter(function(url) {
				return url !== '';
			});
			
			// Show preview first
			showUrlPreview(urlList);
		});
		
		// XML Import with Preview
		$('#start-xml-import').click(function() {
			console.log('XML Import button clicked');
			var xmlFile = $('#xml_file')[0].files[0];
			if (!xmlFile) {
				alert('<?php esc_html_e( 'Please select an XML file to import.', 'url-image-importer' ); ?>');
				return;
			}
			
			console.log('XML File selected:', xmlFile.name, xmlFile.size, 'bytes');
			// Show XML preview
			showXmlPreview(xmlFile);
		});
		
		// Global variables for tracking active imports
		var activeImportBatchId = null;
		var activeImportType = null;
		var previewData = null;
		var batchIdCounter = 0;

		function generateBatchId(prefix) {
			var normalizedPrefix = String(prefix || 'batch').toLowerCase().replace(/[^a-z0-9_-]/g, '');
			var randomPart = '';
			var i;

			if (window.crypto && typeof window.crypto.randomUUID === 'function') {
				randomPart = window.crypto.randomUUID().replace(/-/g, '').toLowerCase();
			} else if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
				var bytes = new Uint8Array(16);
				window.crypto.getRandomValues(bytes);
				for (i = 0; i < bytes.length; i++) {
					randomPart += ('0' + bytes[i].toString(16)).slice(-2);
				}
			} else {
				batchIdCounter += 1;
				randomPart = String(uimptr_ajax.batch_seed || 'batchseed') + '-' + Date.now() + '-' + batchIdCounter;
				randomPart = randomPart.toLowerCase().replace(/[^a-z0-9_-]/g, '');
			}

			if (!normalizedPrefix) {
				normalizedPrefix = 'batch';
			}

			return normalizedPrefix + '-' + randomPart;
		}
		
		// Preview modal handlers
		$('#close-preview, #cancel-import-preview').click(function() {
			$('#import-preview-modal').hide();
			previewData = null;
		});
		
		$('#confirm-import').click(function() {
			if (!previewData) return;
			
			var selectedItems = [];
			
			if (previewData.type === 'url') {
				$('.url-checkbox:checked').each(function() {
					selectedItems.push({
						url: $(this).data('url'),
						metadata: {}
					});
				});
			} else if (previewData.type === 'xml') {
				$('.xml-checkbox:checked').each(function() {
					var index = $(this).data('index');
					selectedItems.push(previewData.urls[index]);
				});
			} else if (previewData.type === 'csv') {
				$('.csv-checkbox:checked').each(function() {
					var index = $(this).data('index');
					selectedItems.push(previewData.urls[index]);
				});
			}
			
			if (selectedItems.length === 0) {
				alert('<?php esc_html_e( 'Please select at least one item to import.', 'url-image-importer' ); ?>');
				return;
			}
			
			// Hide preview and start import
			$('#import-preview-modal').hide();
			
			// Generate batch ID
			activeImportBatchId = generateBatchId(previewData.type);
			activeImportType = previewData.type;
			
			// Show appropriate progress container and reset stats
			resetStats(previewData.type);
			$('#' + previewData.type + '-progress-container').show();
			$('#start-' + previewData.type + '-import').prop('disabled', true);
			// Show the cancel button when import starts
			$('#cancel-' + previewData.type + '-import').show();
			
			// Start the import
			updateProgress(previewData.type, 0, selectedItems.length, '<?php esc_html_e( 'Starting import...', 'url-image-importer' ); ?>');
			processBatchImport(activeImportBatchId, selectedItems, 0, previewData.type);
			
			previewData = null;
		});
		
		// Cancel buttons with confirmation
		$('#cancel-url-import').click(function() {
			if (!confirm('<?php esc_html_e( 'Are you sure you want to stop the import? This will cancel all remaining imports.', 'url-image-importer' ); ?>')) {
				return;
			}
			
			if (activeImportBatchId && activeImportType === 'url') {
				stopImport(activeImportBatchId, 'url');
			} else {
				cancelImport('url');
			}
		});
		
		$('#cancel-xml-import').click(function() {
			if (!confirm('<?php esc_html_e( 'Are you sure you want to stop the import? This will cancel all remaining imports.', 'url-image-importer' ); ?>')) {
				return;
			}
			
			if (activeImportBatchId && activeImportType === 'xml') {
				stopImport(activeImportBatchId, 'xml');
			} else {
				cancelImport('xml');
			}
		});
		
		function startUrlImport(urls) {
			resetStats('url');
			$('#url-progress-container').show();
			$('#start-url-import').prop('disabled', true);
			// Show the cancel button when import starts
			$('#cancel-url-import').show();
			
			// Generate batch ID and set active import tracking
			activeImportBatchId = generateBatchId('url');
			activeImportType = 'url';
			
			updateProgress('url', 0, urls.length, '<?php esc_html_e( 'Starting URL import...', 'url-image-importer' ); ?>');
			
			// Convert URLs to the format expected by batch processor
			var urlsData = urls.map(function(url) {
				return { url: url, metadata: {} };
			});
			
			// Use the batch import system for consistent stop functionality
			processBatchImport(activeImportBatchId, urlsData, 0, 'url');
		}
		
			function getImportOptions(type) {
				var forceReimport = false;
				if (type === 'xml') {
					forceReimport = $('#xml-import input[name="force_reimport"]:checked').length > 0;
				} else if (type === 'csv') {
					forceReimport = $('#csv-import input[name="csv_force_reimport"]:checked').length > 0;
				}

				return {
					preserveDates: $('#' + type + '_preserve_dates').is(':checked'),
					forceReimport: forceReimport
				};
			}

			function processBatchImport(batchId, urls, startIndex, type, importOptions) {
				importOptions = importOptions || getImportOptions(type);

				var requestData = {
					action: 'uimptr_batch_import',
					nonce: uimptr_ajax.nonce,
					batch_id: batchId,
					start_index: startIndex,
					batch_size: 3, // Smaller batch for stability
					import_type: type,
					preserve_dates: importOptions.preserveDates,
					force_reimport: importOptions.forceReimport
				};

			// Send URL payload only on the first request to reduce request size for large imports.
			if (startIndex === 0 && Array.isArray(urls)) {
				requestData.urls = JSON.stringify(urls);
			}
			
				$.ajax({
					url: uimptr_ajax.ajax_url,
					type: 'POST',
					data: requestData,
					success: function(response) {
					if (response.success) {
						var data = response.data;
						
						updateProgress(type, data.processed, data.total, '<?php esc_html_e( 'Processed:', 'url-image-importer' ); ?> ' + data.processed + '/' + data.total, data.stats);
						
						if (data.is_complete) {
							// Show results from final batch only (for display)
							var finalResults = data.results || [];
							var finalErrors = data.errors || [];
							var finalSkipped = data.skipped_messages || [];
							var results = [];
							
							// Only show individual success messages for URL imports, not CSV or XML
							if (type === 'url') {
								finalResults.forEach(function(result) {
									results.push('<div class="notice notice-success"><p><?php esc_html_e( 'Success:', 'url-image-importer' ); ?> ' + escapeHtml(result.url) + '</p></div>');
								});
							}
							
							// Always show errors
							finalErrors.forEach(function(error) {
								results.push('<div class="notice notice-error"><p>' + escapeHtml(error) + '</p></div>');
							});

							finalSkipped.forEach(function(message) {
								results.push('<div class="notice notice-warning"><p>' + escapeHtml(message) + '</p></div>');
							});
							
							// Use cumulative stats for completion message, not just final batch
							var totalImported = data.stats ? data.stats.success : finalResults.length;
							var totalErrors = data.stats ? data.stats.failed : finalErrors.length;

							var mappingData = {
								available: !!data.mapping_available,
								rows: data.mapping_rows || 0,
								batchId: data.mapping_batch_id || '',
								skipped: data.stats ? data.stats.skipped : finalSkipped.length
							};
							
							finishImport(type, totalImported, totalErrors, results, mappingData);
							} else {
								// Continue with next batch
								setTimeout(function() {
									processBatchImport(batchId, null, data.next_index, type, importOptions);
								}, 200);
							}
					} else {
						// Handle cancellation or error
						var errorMsg = response.data;
						if (typeof errorMsg === 'object' && errorMsg.message) {
							errorMsg = errorMsg.message;
						}
						
						$('#' + type + '-progress-text').text('<?php esc_html_e( 'Import stopped:', 'url-image-importer' ); ?> ' + errorMsg);
						$('#start-' + type + '-import').prop('disabled', false);
						$('#cancel-' + type + '-import').prop('disabled', false).text('<?php esc_html_e( 'Cancel Import', 'url-image-importer' ); ?>');
						activeImportBatchId = null;
						activeImportType = null;
					}
				},
				error: function() {
					$('#' + type + '-progress-text').text('<?php esc_html_e( 'Network error occurred', 'url-image-importer' ); ?>');
					$('#start-' + type + '-import').prop('disabled', false);
					$('#cancel-' + type + '-import').prop('disabled', false).text('<?php esc_html_e( 'Cancel Import', 'url-image-importer' ); ?>');
					activeImportBatchId = null;
					activeImportType = null;
				}
			});
		}
		
		function startXmlImport() {
			resetStats('xml');
			$('#xml-progress-container').show();
			$('#start-xml-import').prop('disabled', true);
			// Show the cancel button when import starts
			$('#cancel-xml-import').show();
			
			updateProgress('xml', 0, 1, '<?php esc_html_e( 'Uploading and processing XML file...', 'url-image-importer' ); ?>');
			
			var formData = new FormData();
			formData.append('action', 'uimptr_process_xml_import');
			formData.append('xml_file', $('#xml_file')[0].files[0]);
			formData.append('images_only', $('#xml-import input[name="images_only"]:checked').val() || '');
			formData.append('force_reimport', $('#xml-import input[name="force_reimport"]:checked').val() || '');
			formData.append('nonce', uimptr_ajax.nonce);
			
			$.ajax({
				url: uimptr_ajax.ajax_url,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					if (response.success && response.data.urls) {
						// Store the batch ID for potential cancellation
						var xmlBatchId = response.data.batch_id || generateBatchId('xml');
						activeImportBatchId = xmlBatchId;
						activeImportType = 'xml';
						
						// Start importing the URLs from XML using batch system
						updateProgress('xml', 0, response.data.urls.length, '<?php esc_html_e( 'Starting import from XML...', 'url-image-importer' ); ?>');
						processBatchImport(xmlBatchId, response.data.urls, 0, 'xml');
					} else {
						finishImport('xml', 0, 1, ['<div class="notice notice-error"><p>' + (response.data || '<?php esc_html_e( 'Failed to process XML file', 'url-image-importer' ); ?>') + '</p></div>']);
					}
				},
				error: function() {
					finishImport('xml', 0, 1, ['<div class="notice notice-error"><p><?php esc_html_e( 'Network error processing XML file', 'url-image-importer' ); ?></p></div>']);
				}
			});
		}
		

		
		function resetStats(type) {
			$('#' + type + '-success-count').text('0');
			$('#' + type + '-failed-count').text('0');
			$('#' + type + '-skipped-count').text('0');
		}

		function updateProgress(type, current, total, message, stats) {
			var percent = total > 0 ? Math.round((current / total) * 100) : 0;
			
			$('#' + type + '-progress-bar').css('width', percent + '%');
			$('#' + type + '-progress-text').text(message);
			$('#' + type + '-progress-count').text(current + '/' + total);
			
			// Update stats if provided
			if (stats) {
				if (typeof stats.success !== 'undefined') {
					$('#' + type + '-success-count').text(stats.success);
				}
				if (typeof stats.failed !== 'undefined') {
					$('#' + type + '-failed-count').text(stats.failed);
				}
				if (typeof stats.skipped !== 'undefined') {
					$('#' + type + '-skipped-count').text(stats.skipped);
				}
			}
		}

		function escapeHtml(value) {
			return $('<div>').text(value || '').html();
		}

		function stopImport(batchId, type) {
			// Disable the cancel button and show stopping message
			$('#cancel-' + type + '-import').prop('disabled', true).text('<?php esc_html_e( 'Stopping...', 'url-image-importer' ); ?>');
			$('#' + type + '-progress-text').text('<?php esc_html_e( 'Stopping import, please wait...', 'url-image-importer' ); ?>');
			
			// Send stop command to server
			$.ajax({
				url: uimptr_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'uimptr_cancel_import',
					nonce: uimptr_ajax.nonce,
					batch_id: batchId
				},
				success: function(response) {
					if (response.success) {
						$('#' + type + '-progress-text').text('<?php esc_html_e( 'Import stopped by user.', 'url-image-importer' ); ?>');
						// Hide the cancel button when import is stopped
						$('#cancel-' + type + '-import').hide();
						$('#start-' + type + '-import').prop('disabled', false);
						activeImportBatchId = null;
						activeImportType = null;
					} else {
						$('#' + type + '-progress-text').text('<?php esc_html_e( 'Failed to stop import. Please refresh the page.', 'url-image-importer' ); ?>');
					}
				},
				error: function() {
					$('#' + type + '-progress-text').text('<?php esc_html_e( 'Network error while stopping import.', 'url-image-importer' ); ?>');
				},
				complete: function() {
					$('#cancel-' + type + '-import').prop('disabled', false);
				}
			});
		}
		
		function cancelImport(type) {
			importCanceled = true;
			$('#' + type + '-progress-text').text('<?php esc_html_e( 'Import canceled by user.', 'url-image-importer' ); ?>');
			// Hide the cancel button when import is canceled
			$('#cancel-' + type + '-import').hide();
			$('#start-' + type + '-import').prop('disabled', false);
			activeImportBatchId = null;
			activeImportType = null;
		}
		
		function checkIfCancelled(batchId) {
			// This will be checked on the server side during batch processing
			// The client-side check is mainly for immediate UI feedback
			return false; // Server handles the real cancellation check
		}

		function getMappingDownloadUrl(batchId) {
			var downloadBaseUrl = uimptr_ajax.admin_post_url || uimptr_ajax.ajax_url;
			return downloadBaseUrl + '?action=uimptr_download_url_mapping_csv&nonce=' + encodeURIComponent(uimptr_ajax.nonce) + '&batch_id=' + encodeURIComponent(batchId);
		}
		
		// Preview Functions
		function showUrlPreview(urls) {
			var previewHtml = '<h4>URLs to Import (' + urls.length + ' total)</h4>';
			previewHtml += '<div style="margin: 15px 0;"><label><input type="checkbox" id="select-all-urls" checked> Select All</label></div>';
			previewHtml += '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
			
			urls.forEach(function(url, index) {
				var filename = url.split('/').pop().split('?')[0];
				previewHtml += '<div style="margin: 5px 0; padding: 10px; border-bottom: 1px solid #eee;">';
				previewHtml += '<label style="display: block; cursor: pointer;">';
				previewHtml += '<input type="checkbox" class="url-checkbox" data-url="' + url + '" checked style="margin-right: 10px;">';
				previewHtml += '<strong>' + filename + '</strong><br>';
				previewHtml += '<small style="color: #666; word-break: break-all;">' + url + '</small>';
				previewHtml += '</label></div>';
			});
			
			previewHtml += '</div>';
			previewHtml += '<p style="margin-top: 15px; color: #666;"><em>Review the URLs above and uncheck any you don\'t want to import.</em></p>';
			
			previewData = { type: 'url', urls: urls };
			$('#preview-content').html(previewHtml);
			$('#import-preview-modal').show();
			
			// Select All functionality
			$('#select-all-urls').change(function() {
				$('.url-checkbox').prop('checked', this.checked);
			});
		}
		
		function showXmlPreview(xmlFile) {
			$('#preview-content').html('<div style="text-align: center; padding: 40px;"><div class="spinner"></div><p>Analyzing XML file...</p></div>');
			$('#import-preview-modal').show();
			
			// Debug the uimptr_ajax object
			console.log('uimptr_ajax object:', uimptr_ajax);
			if (typeof uimptr_ajax === 'undefined') {
				console.error('ERROR: uimptr_ajax object is not defined!');
				$('#preview-content').html('<div style="color: red; text-align: center; padding: 40px;">Error: AJAX configuration missing. Please reload the page.</div>');
				return;
			}
			
			var formData = new FormData();
			formData.append('action', 'uimptr_process_xml_import');
			formData.append('xml_file', xmlFile);
			formData.append('images_only', $('#xml-import input[name="images_only"]:checked').val() || '');
			formData.append('force_reimport', $('#xml-import input[name="force_reimport"]:checked').val() || '');
			formData.append('xml_preserve_dates', $('#xml_preserve_dates').is(':checked') ? '1' : '');
			formData.append('nonce', uimptr_ajax.nonce);
			
			console.log('Starting XML Preview AJAX call to:', uimptr_ajax.ajax_url);
			console.log('Nonce:', uimptr_ajax.nonce);
			console.log('File object:', xmlFile);
			
			$.ajax({
				url: uimptr_ajax.ajax_url,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				timeout: 30000, // 30 second timeout
				success: function(response) {
					console.log('XML Preview Response:', response);
					if (response.success) {
						var urls = response.data.urls;
						var count = response.data.count;
						
						var previewHtml = '<h4>XML File Analysis (' + count + ' items found)</h4>';
						previewHtml += '<div style="margin: 15px 0;"><label><input type="checkbox" id="select-all-xml" checked> Select All</label></div>';
						previewHtml += '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
						
						if (count > 0) {
							urls.forEach(function(item, index) {
								var filename = item.url.split('/').pop().split('?')[0];
								var title = item.metadata && item.metadata.title ? item.metadata.title : filename;
								
								previewHtml += '<div class="checkbox-item">';
								previewHtml += '<label style="display: block; cursor: pointer;">';
								previewHtml += '<input type="checkbox" class="xml-checkbox" data-index="' + index + '" checked>';
								previewHtml += '<div class="item-title">' + title + '</div>';
								previewHtml += '<div class="item-url">' + item.url + '</div>';
								if (item.metadata && item.metadata.date) {
									previewHtml += '<div class="item-meta">Date: ' + item.metadata.date + '</div>';
								}
								previewHtml += '</label></div>';
							});
						} else {
							previewHtml += '<p>No importable items found in the XML file.</p>';
						}
						
						previewHtml += '</div>';
						previewHtml += '<p style="margin-top: 15px; color: #666;"><em>Review the items above and uncheck any you don\'t want to import.</em></p>';
						
						previewData = { 
							type: 'xml', 
							urls: urls, 
							batch_id: response.data.batch_id 
						};
						
						$('#preview-content').html(previewHtml);
						
						// Select All functionality
						$('#select-all-xml').change(function() {
							$('.xml-checkbox').prop('checked', this.checked);
						});
						
					} else {
						$('#preview-content').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
					}
				},
				error: function(xhr, status, error) {
					console.error('XML Preview AJAX Error:', {xhr: xhr, status: status, error: error});
					var errorMessage = 'Network error while processing XML file.';
					if (status === 'timeout') {
						errorMessage = 'Request timed out. Please try a smaller XML file.';
					} else if (xhr.status === 0) {
						errorMessage = 'Network connection failed. Please check your internet connection.';
					} else if (xhr.status >= 400) {
						errorMessage = 'Server error (' + xhr.status + '). Please check server logs.';
					}
					$('#preview-content').html('<div class="notice notice-error"><p>' + errorMessage + '</p><p><small>Details: ' + status + ' - ' + error + '</small></p></div>');
				}
			});
		}
		
		function finishImport(type, imported, errors, results, mappingData) {
			var message = '<?php esc_html_e( 'Import completed!', 'url-image-importer' ); ?> ' + 
				'<?php esc_html_e( 'Imported:', 'url-image-importer' ); ?> ' + imported + ', ' +
				'<?php esc_html_e( 'Errors:', 'url-image-importer' ); ?> ' + errors;
				
			if (importCanceled) {
				message = '<?php esc_html_e( 'Import canceled.', 'url-image-importer' ); ?> ' + 
					'<?php esc_html_e( 'Imported:', 'url-image-importer' ); ?> ' + imported + ', ' +
					'<?php esc_html_e( 'Errors:', 'url-image-importer' ); ?> ' + errors;
			}

			if (mappingData && typeof mappingData.skipped !== 'undefined') {
				message += ', <?php esc_html_e( 'Skipped:', 'url-image-importer' ); ?> ' + mappingData.skipped;
			}

			if (mappingData && mappingData.available) {
				message += ', <?php esc_html_e( 'Mapped URLs:', 'url-image-importer' ); ?> ' + mappingData.rows;
			}
			
			$('#' + type + '-progress-text').text(message);
			var $resultsContainer = $('#' + type + '-import-results');
			
			// Only show detailed results if there are any
			if (results.length > 0) {
				$resultsContainer.html(results.slice(0, 10).join(''));
				
				if (results.length > 10) {
					$resultsContainer.append('<div class="notice notice-info"><p><?php esc_html_e( 'Showing first 10 results. Total:', 'url-image-importer' ); ?> ' + results.length + '</p></div>');
				}
			} else {
				// Clear results if none to display
				$resultsContainer.html('');
			}

			if (mappingData && mappingData.available && mappingData.batchId && mappingData.rows > 0) {
				var downloadUrl = getMappingDownloadUrl(mappingData.batchId);
				var exportHtml = '<div class="notice notice-success"><p>' +
					'<?php esc_html_e( 'URL mapping export is ready:', 'url-image-importer' ); ?> ' +
					mappingData.rows + ' <?php esc_html_e( 'rows', 'url-image-importer' ); ?>.' +
					' <a class="button button-secondary" style="margin-left: 10px;" href="' + downloadUrl + '">' +
					'<?php esc_html_e( 'Download URL Mapping CSV', 'url-image-importer' ); ?>' +
					'</a></p></div>';
				$resultsContainer.append(exportHtml);
			}
			
			$('#start-' + type + '-import').prop('disabled', false);
			// Hide the cancel button when import is completed
			$('#cancel-' + type + '-import').hide();
			
			// Clear active import tracking
			activeImportBatchId = null;
			activeImportType = null;
		}
		
		// CSV Import functionality
		$('#start-csv-import').click(function() {
			console.log('CSV Import button clicked');
			var csvFile = $('#csv_file')[0].files[0];
			if (!csvFile) {
				alert('<?php esc_html_e( 'Please select a CSV file to import.', 'url-image-importer' ); ?>');
				return;
			}
			
			console.log('CSV File selected:', csvFile.name, csvFile.size, 'bytes');
			showCsvPreview(csvFile);
		});
		
		$('#cancel-csv-import').click(function() {
			if (!confirm('<?php esc_html_e( 'Are you sure you want to stop the import? This will cancel all remaining imports.', 'url-image-importer' ); ?>')) {
				return;
			}
			
			if (activeImportBatchId && activeImportType === 'csv') {
				stopImport(activeImportBatchId, 'csv');
			} else {
				cancelImport('csv');
			}
		});
		
		// Download sample CSV
		$('#download-sample-csv').click(function(e) {
			e.preventDefault();
			var csvContent = 'url,title,description,alt_text,date\n';
			csvContent += 'https://i0.wp.com/wordpress.org/files/2024/04/photo-community-1.png?w=1216&ssl=1,WordPress Community Photo,Official WordPress community photo showcasing collaboration,WordPress community collaboration,2024-01-15\n';
			csvContent += '"https://elementor.com/cdn-cgi/image/f=auto,w=1370/wp-content/uploads/2024/06/drag-and-drop.webp",Elementor Image,Elementor drag and drop interface demonstration,Elementor interface demo,2024-02-20\n';
			csvContent += 'https://s.w.org/images/core/emoji/15.0.3/72x72/1f680.png,Rocket Emoji,WordPress rocket emoji representing speed and performance,WordPress rocket emoji,2024-03-10\n';
			
			var blob = new Blob([csvContent], { type: 'text/csv' });
			var url = window.URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.setAttribute('hidden', '');
			a.setAttribute('href', url);
			a.setAttribute('download', 'sample-import.csv');
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
		});
		
		function showCsvPreview(csvFile) {
			$('#preview-content').html('<div style="text-align: center; padding: 40px;"><div class="spinner"></div><p>Analyzing CSV file...</p></div>');
			$('#import-preview-modal').show();
			
			var formData = new FormData();
			formData.append('action', 'uimptr_process_csv_import');
			formData.append('csv_file', csvFile);
			formData.append('images_only', $('#csv-import input[name="csv_images_only"]:checked').val() || '');
			formData.append('force_reimport', $('#csv-import input[name="csv_force_reimport"]:checked').val() || '');
			formData.append('csv_preserve_dates', $('#csv_preserve_dates').is(':checked') ? '1' : '');
			formData.append('nonce', uimptr_ajax.nonce);
			
			console.log('Starting CSV Preview AJAX call to:', uimptr_ajax.ajax_url);
			
			$.ajax({
				url: uimptr_ajax.ajax_url,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				timeout: 30000,
				success: function(response) {
					console.log('CSV Preview Response:', response);
					if (response.success) {
						var urls = response.data.urls;
						var count = response.data.count;
						
						var previewHtml = '<h4>CSV File Analysis (' + count + ' items found)</h4>';
						previewHtml += '<div style="margin: 15px 0;"><label><input type="checkbox" id="select-all-csv" checked> Select All</label></div>';
						previewHtml += '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
						
						if (count > 0) {
							urls.forEach(function(item, index) {
								var filename = item.url.split('/').pop().split('?')[0];
								var title = item.metadata && item.metadata.title ? item.metadata.title : filename;
								
								previewHtml += '<div class="checkbox-item">';
								previewHtml += '<label style="display: block; cursor: pointer;">';
								previewHtml += '<input type="checkbox" class="csv-checkbox" data-index="' + index + '" checked>';
								previewHtml += '<div class="item-title">' + title + '</div>';
								previewHtml += '<div class="item-url">' + item.url + '</div>';
								if (item.metadata && item.metadata.description) {
									previewHtml += '<div class="item-meta">Description: ' + item.metadata.description + '</div>';
								}
								if (item.metadata && item.metadata.date) {
									previewHtml += '<div class="item-meta">Date: ' + item.metadata.date + '</div>';
								}
								previewHtml += '</label></div>';
							});
						} else {
							previewHtml += '<p>No importable items found in the CSV file.</p>';
						}
						
						previewHtml += '</div>';
						previewHtml += '<p style="margin-top: 15px; color: #666;"><em>Review the items above and uncheck any you don\'t want to import.</em></p>';
						
						previewData = { 
							type: 'csv', 
							urls: urls, 
							batch_id: response.data.batch_id 
						};
						
						$('#preview-content').html(previewHtml);
						
						// Select All functionality
						$('#select-all-csv').change(function() {
							$('.csv-checkbox').prop('checked', this.checked);
						});
						
					} else {
						$('#preview-content').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
					}
				},
				error: function(xhr, status, error) {
					console.error('CSV Preview AJAX Error:', {xhr: xhr, status: status, error: error});
					var errorMessage = 'Network error while processing CSV file.';
					if (status === 'timeout') {
						errorMessage = 'Request timed out. Please try a smaller CSV file.';
					} else if (xhr.status === 0) {
						errorMessage = 'Network connection failed. Please check your internet connection.';
					} else if (xhr.status >= 400) {
						errorMessage = 'Server error (' + xhr.status + '). Please check server logs.';
					}
					$('#preview-content').html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
				}
			});
		}
	});
	</script>
	<?php
	require_once UIMPTR_PATH . '/templates/modal-scan.php';

	// Only include the subscribe modal until the user has subscribed or
	// dismissed it, so re-running the scan does not ask for the email again.
	$dismissed = get_user_option( 'bfu_subscribe_notice_dismissed', get_current_user_id() );
	if ( ! $dismissed ) {
		require_once UIMPTR_PATH . '/templates/modal-subscribe.php';
	}
	$scan_results = get_site_option( 'uimptr_file_scan' );
	if ( isset( $scan_results['scan_finished'] ) && $scan_results['scan_finished'] ) {
		if ( isset( $scan_results['types'] ) ) {
			$total_files   = array_sum( wp_list_pluck( $scan_results['types'], 'files' ) );
			$total_storage = array_sum( wp_list_pluck( $scan_results['types'], 'size' ) );
		} else {
			$total_files   = 0;
			$total_storage = 0;
		}
		require_once UIMPTR_PATH . '/templates/scan-results.php';
	} else {
		require_once UIMPTR_PATH . '/templates/scan-start.php';
	}

	// Infinite Uploads Pro upsell: rendered once here, beneath the storage
	// analysis section, instead of being repeated inside each import tab.
	uimptr_render_upsell_bar();

	require_once UIMPTR_PATH . '/templates/modal-upgrade.php';
	require_once UIMPTR_PATH . '/templates/footer.php';
}

/**
 * Get an image extension from a mime type.
 *
 * @param string $mime_type Mime type.
 * @return string
 */
function uimptr_get_image_extension_from_mime_type( $mime_type ) {
	$mime_map = array(
		'image/jpeg'    => 'jpg',
		'image/png'     => 'png',
		'image/gif'     => 'gif',
		'image/webp'    => 'webp',
		'image/bmp'     => 'bmp',
		'image/tiff'    => 'tiff',
		'image/x-icon'  => 'ico',
		'image/vnd.microsoft.icon' => 'ico',
		'image/svg+xml' => 'svg',
		'image/avif'    => 'avif',
	);

	$mime_type = strtolower( trim( (string) $mime_type ) );
	if ( false !== strpos( $mime_type, ';' ) ) {
		$mime_type = trim( strtok( $mime_type, ';' ) );
	}

	return $mime_map[ $mime_type ] ?? '';
}

/**
 * Remove a trailing image file extension from a title.
 *
 * @param string $title Attachment title source.
 * @param bool   $_deprecated_strip_extension Deprecated. Image extensions are always stripped.
 * @return string
 */
function uimptr_maybe_strip_image_extension_from_title( $title, $_deprecated_strip_extension = true ) {
	$title = trim( (string) $title );

	if ( '' === $title ) {
		return $title;
	}

	if ( ! preg_match( '/\.([A-Za-z0-9]{2,5})$/', $title, $matches ) ) {
		return $title;
	}

	$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'tiff', 'tif', 'ico', 'avif', 'heic', 'heif' );
	$extension        = strtolower( $matches[1] );

	if ( ! in_array( $extension, $image_extensions, true ) ) {
		return $title;
	}

	$title_without_extension = trim( substr( $title, 0, -1 * ( strlen( $matches[1] ) + 1 ) ) );

	return '' !== $title_without_extension ? $title_without_extension : $title;
}

/**
 * Sanitize an attachment slug from the normalized title.
 *
 * @param string $title Attachment title source.
 * @return string
 */
function uimptr_sanitize_attachment_slug_from_title( $title ) {
	$title = trim( (string) $title );

	if ( '' === $title ) {
		return '';
	}

	return sanitize_title( preg_replace( '/\.+/', '-', $title ) );
}

/**
 * Persist the computed attachment title and slug after attachment creation.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $title         Computed title.
 * @return void
 */
function uimptr_update_attachment_title_and_slug( $attachment_id, $title ) {
	$attachment_id = intval( $attachment_id );
	$title         = sanitize_text_field( $title );

	if ( $attachment_id <= 0 || '' === $title ) {
		return;
	}

	wp_update_post(
		array(
			'ID'         => $attachment_id,
			'post_title' => $title,
			'post_name'  => uimptr_sanitize_attachment_slug_from_title( $title ),
		)
	);
}

/**
 * Extract a filename from a Content-Disposition header.
 *
 * @param string $content_disposition Content-Disposition header value.
 * @return string
 */
function uimptr_get_filename_from_content_disposition( $content_disposition ) {
	$content_disposition = (string) $content_disposition;
	if ( empty( $content_disposition ) ) {
		return '';
	}

	if ( preg_match( '/filename\*=UTF-8\'\'([^;]+)/i', $content_disposition, $matches ) ) {
		return sanitize_file_name( rawurldecode( trim( $matches[1], "\"' " ) ) );
	}

	if ( preg_match( '/filename="?([^\";]+)"?/i', $content_disposition, $matches ) ) {
		return sanitize_file_name( trim( $matches[1], "\"' " ) );
	}

	return '';
}

/**
 * Determine whether a URL points at a supported Google Drive host.
 *
 * @param string $url URL to inspect.
 * @return bool
 */
function uimptr_is_google_drive_url( $url ) {
	$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
	$host = preg_replace( '/^www\./', '', $host );

	return in_array( $host, array( 'drive.google.com', 'docs.google.com', 'drive.usercontent.google.com' ), true );
}

/**
 * Sanitize a Google Drive file ID.
 *
 * @param string $file_id Raw Drive file ID.
 * @return string
 */
function uimptr_sanitize_google_drive_file_id( $file_id ) {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', rawurldecode( (string) $file_id ) );
}

/**
 * Extract query parameters from a URL.
 *
 * @param string $url URL to parse.
 * @return array
 */
function uimptr_get_url_query_params( $url ) {
	$query = (string) parse_url( (string) $url, PHP_URL_QUERY );

	if ( '' === $query ) {
		return array();
	}

	$params = array();
	parse_str( $query, $params );

	return is_array( $params ) ? $params : array();
}

/**
 * Extract a Google Drive file ID or return a clear unsupported-link error.
 *
 * @param string $url Google Drive URL.
 * @return string|WP_Error
 */
function uimptr_extract_google_drive_file_id( $url ) {
	if ( ! uimptr_is_google_drive_url( $url ) ) {
		return new WP_Error( 'google_drive_malformed_url', 'This is not a supported Google Drive URL.' );
	}

	$path = rawurldecode( (string) parse_url( (string) $url, PHP_URL_PATH ) );

	if ( preg_match( '#/(?:drive/(?:u/\d+/)?)?folders/[^/?\#]+#i', $path ) || preg_match( '#/folderview#i', $path ) ) {
		return new WP_Error( 'google_drive_folder_not_supported', 'Google Drive folders are not supported. Please link directly to a public image file.' );
	}

	if ( preg_match( '#^/(document|spreadsheets|presentation|forms|drawings)/#i', $path ) ) {
		return new WP_Error( 'google_drive_workspace_not_supported', 'Google Docs, Sheets, Slides, Forms, and Drawings are not supported. Please link directly to a public image file.' );
	}

	if ( preg_match( '#/file/d/([^/?\#]+)#i', $path, $matches ) ) {
		$file_id = uimptr_sanitize_google_drive_file_id( $matches[1] );
		if ( '' !== $file_id ) {
			return $file_id;
		}
	}

	$params = uimptr_get_url_query_params( $url );
	if ( ! empty( $params['id'] ) && ! is_array( $params['id'] ) ) {
		$file_id = uimptr_sanitize_google_drive_file_id( $params['id'] );
		if ( '' !== $file_id ) {
			return $file_id;
		}
	}

	return new WP_Error( 'google_drive_malformed_url', 'Google Drive URL does not contain a file ID. Please use a share link to a public image file.' );
}

/**
 * Get a Google Drive resource key from a URL when present.
 *
 * @param string $url Google Drive URL.
 * @return string
 */
function uimptr_get_google_drive_resource_key( $url ) {
	$params = uimptr_get_url_query_params( $url );

	if ( empty( $params['resourcekey'] ) || is_array( $params['resourcekey'] ) ) {
		return '';
	}

	return sanitize_text_field( (string) $params['resourcekey'] );
}

/**
 * Build a public Google Drive direct-download URL for a file ID.
 *
 * @param string $file_id      Google Drive file ID.
 * @param string $resource_key Optional Drive resource key.
 * @return string
 */
function uimptr_build_google_drive_download_url( $file_id, $resource_key = '' ) {
	$args = array(
		'export' => 'download',
		'id'     => $file_id,
	);

	if ( '' !== $resource_key ) {
		$args['resourcekey'] = $resource_key;
	}

	return add_query_arg( $args, 'https://drive.google.com/uc' );
}

/**
 * Resolve a Google Drive URL to a public direct-download URL.
 *
 * @param string $url Google Drive URL.
 * @return string|WP_Error
 */
function uimptr_get_google_drive_download_url( $url ) {
	$file_id = uimptr_extract_google_drive_file_id( $url );
	if ( is_wp_error( $file_id ) ) {
		return $file_id;
	}

	return uimptr_build_google_drive_download_url( $file_id, uimptr_get_google_drive_resource_key( $url ) );
}

/**
 * Get the canonical source URL used for Google Drive dedupe.
 *
 * @param string $url Google Drive URL.
 * @return string
 */
function uimptr_get_google_drive_canonical_url( $url ) {
	$file_id = uimptr_extract_google_drive_file_id( $url );
	if ( is_wp_error( $file_id ) ) {
		return '';
	}

	return 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/view';
}

/**
 * Whether an HTTP response body appears to be an HTML page.
 *
 * @param string $body         Response body.
 * @param string $content_type Response content type header.
 * @return bool
 */
function uimptr_response_looks_like_html( $body, $content_type = '' ) {
	$content_type = strtolower( trim( (string) strtok( (string) $content_type, ';' ) ) );
	if ( in_array( $content_type, array( 'text/html', 'application/xhtml+xml' ), true ) ) {
		return true;
	}

	$probe = strtolower( ltrim( substr( (string) $body, 0, 512 ) ) );

	return 0 === strpos( $probe, '<!doctype html' ) || 0 === strpos( $probe, '<html' );
}

/**
 * Determine if an import error should count as a skipped item.
 *
 * @param WP_Error $error Import error.
 * @return bool
 */
function uimptr_is_skippable_import_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return false;
	}

	return in_array(
		$error->get_error_code(),
		array(
			'google_drive_folder_not_supported',
			'google_drive_workspace_not_supported',
			'google_drive_malformed_url',
			'google_drive_not_public_image',
			'google_drive_non_image',
		),
		true
	);
}

/**
 * Format a user-facing skipped import message.
 *
 * @param string   $url   Original source URL.
 * @param WP_Error $error Import error.
 * @return string
 */
function uimptr_format_import_skip_message( $url, WP_Error $error ) {
	return sprintf( 'Skipped %1$s: %2$s', esc_url_raw( $url ), $error->get_error_message() );
}

/**
 * Determine whether Big File Uploads is available for media sideload handling.
 *
 * @return bool
 */
function uimptr_is_big_file_uploads_active() {
	if ( class_exists( 'TuxedoBigFileUploads' ) || class_exists( 'UrlBigFileUploads' ) ) {
		return true;
	}

	return function_exists( 'is_plugin_active' )
		&& is_plugin_active( 'tuxedo-big-file-uploads/tuxedo_big_file_uploads.php' );
}

/**
 * Load WordPress media upload helpers when they are not already loaded.
 *
 * @return bool
 */
function uimptr_load_media_upload_dependencies() {
	$includes = array(
		'wp-admin/includes/file.php',
		'wp-admin/includes/media.php',
		'wp-admin/includes/image.php',
	);

	foreach ( $includes as $include ) {
		$path = ABSPATH . $include;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	return function_exists( 'media_handle_upload' );
}

/**
 * Whether the importer can hand a validated file to the Big File Uploads media path.
 *
 * @return bool
 */
function uimptr_can_use_big_file_uploads_sideload() {
	return uimptr_is_big_file_uploads_active() && uimptr_load_media_upload_dependencies();
}

/**
 * Get the Big File Uploads temporary directory path.
 *
 * @return string
 */
function uimptr_get_big_file_uploads_temp_dir() {
	$default_temp_dir = defined( 'WP_CONTENT_DIR' )
		? WP_CONTENT_DIR . '/bfu-temp'
		: trailingslashit( wp_upload_dir()['basedir'] ) . 'bfu-temp';

	return apply_filters( 'bfu_temp_dir', $default_temp_dir );
}

/**
 * Get the chunk size used for Google Drive downloads via the BFU path.
 *
 * @return int
 */
function uimptr_get_big_file_uploads_download_chunk_size() {
	$kb = defined( 'KB_IN_BYTES' ) ? KB_IN_BYTES : 1024;
	$mb = defined( 'MB_IN_BYTES' ) ? MB_IN_BYTES : 1048576;

	$chunk_size = defined( 'BIG_FILE_UPLOADS_CHUNK_SIZE_KB' )
		? (int) BIG_FILE_UPLOADS_CHUNK_SIZE_KB * $kb
		: 5 * $mb;

	$chunk_size = max( $kb, min( $chunk_size, 5 * $mb ) );

	return (int) apply_filters( 'uimptr_google_drive_download_chunk_size', $chunk_size );
}

/**
 * Parse the total size from a Content-Range header.
 *
 * @param string $content_range Content-Range header value.
 * @return int
 */
function uimptr_parse_content_range_total_size( $content_range ) {
	if ( preg_match( '#/(\d+)\s*$#', (string) $content_range, $matches ) ) {
		return (int) $matches[1];
	}

	return 0;
}

/**
 * Download a Google Drive file in BFU-sized range chunks to the BFU temp dir.
 *
 * @param string $download_url Direct Google Drive download URL.
 * @param string $source_url   Original source URL.
 * @param array  $metadata     Optional import metadata.
 * @return array|WP_Error
 */
function uimptr_download_google_drive_file_to_big_file_uploads_temp( $download_url, $source_url, $metadata = array() ) {
	// SECURITY (SSRF): validate the resolved download target before opening any
	// handles or issuing chunk requests. Defense in depth alongside wp_safe_remote_get().
	$safe = uimptr_validate_remote_url_is_safe( $download_url );
	if ( is_wp_error( $safe ) ) {
		return $safe;
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}

	$temp_dir = uimptr_get_big_file_uploads_temp_dir();
	$dir_ok   = uimptr_ensure_temp_directory( $temp_dir );
	if ( is_wp_error( $dir_ok ) ) {
		return $dir_ok;
	}

	$chunk_size = uimptr_get_big_file_uploads_download_chunk_size();
	if ( $chunk_size <= 0 ) {
		$chunk_size = 1048576;
	}

	$temp_file = trailingslashit( $temp_dir ) . sprintf(
		'%d-%s.part',
		function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
		sha1( $download_url . microtime( true ) )
	);

	$handle = @fopen( $temp_file, 'wb' );
	if ( false === $handle ) {
		return new WP_Error( 'file_save_failed', 'Failed to open Big File Uploads temporary file.' );
	}

	$content_type        = '';
	$content_disposition = '';
	$total_size          = 0;
	$offset              = 0;
	$download_error      = null;

	try {
		do {
			$range_end = $total_size > 0 ? min( $offset + $chunk_size - 1, $total_size - 1 ) : $offset + $chunk_size - 1;
			// SECURITY (SSRF): $download_url was vetted by uimptr_validate_remote_url_is_safe()
			// at the top of this function; wp_safe_remote_get() adds core's per-hop RFC1918 guard.
			$response  = wp_safe_remote_get(
				$download_url,
				array(
					'timeout'     => 60,
					'redirection' => 5,
					'headers'     => array(
						'Range' => 'bytes=' . $offset . '-' . $range_end,
					),
					'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$download_error = new WP_Error( 'image_download_failed', 'Failed to download Google Drive image chunk: ' . $response->get_error_message() );
				break;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $response_code, array( 200, 206 ), true ) ) {
				$download_error = new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
				break;
			}

			$body = wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				$download_error = new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
				break;
			}

			if ( 0 === $offset ) {
				$content_type        = (string) wp_remote_retrieve_header( $response, 'content-type' );
				$content_disposition = (string) wp_remote_retrieve_header( $response, 'content-disposition' );

				if ( uimptr_response_looks_like_html( $body, $content_type ) ) {
					$download_error = new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
					break;
				}

				$total_size = uimptr_parse_content_range_total_size( (string) wp_remote_retrieve_header( $response, 'content-range' ) );
				if ( 0 === $total_size && 200 === $response_code ) {
					$total_size = strlen( $body );
				}
			}

			$written = fwrite( $handle, $body );
			if ( false === $written || $written !== strlen( $body ) ) {
				$download_error = new WP_Error( 'file_save_failed', 'Failed to write Google Drive image chunk to Big File Uploads temporary file.' );
				break;
			}

			$offset += strlen( $body );
		} while ( $total_size > 0 && $offset < $total_size );
	} finally {
		fclose( $handle );
	}

	if ( is_wp_error( $download_error ) ) {
		uimptr_delete_file_with_logging( $temp_file, 'failed Google Drive BFU chunk download cleanup' );
		return $download_error;
	}

	clearstatcache( true, $temp_file );
	if ( ! file_exists( $temp_file ) || filesize( $temp_file ) <= 0 ) {
		uimptr_delete_file_with_logging( $temp_file, 'empty Google Drive BFU temp file cleanup' );
		return new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
	}

	$filename_url_path = is_string( $source_url ) ? parse_url( $source_url, PHP_URL_PATH ) : false;
	$filename          = $filename_url_path && is_string( $filename_url_path ) ? basename( $filename_url_path ) : '';
	if ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
		$header_filename = uimptr_get_filename_from_content_disposition( $content_disposition );
		if ( ! empty( $header_filename ) ) {
			$filename = $header_filename;
		}
	}

	if ( ! $filename ) {
		$filename = !empty($metadata['title']) ? sanitize_file_name( $metadata['title'] ) : 'imported_image_' . time();
	}

	return array(
		'temp_file'           => $temp_file,
		'filename'            => sanitize_file_name( $filename ),
		'content_type'        => $content_type,
		'content_disposition' => $content_disposition,
	);
}

/**
 * Build attachment post data shared by normal and Big File Uploads imports.
 *
 * @param string $title         Attachment title.
 * @param string $description   Attachment description/caption.
 * @param string $date          Optional source date.
 * @param bool   $preserve_dates Whether to preserve source dates.
 * @param string $mime_type     Attachment mime type.
 * @return array
 */
function uimptr_build_attachment_post_data( $title, $description, $date, $preserve_dates, $mime_type ) {
	$attachment = array(
		'post_mime_type' => $mime_type,
		'post_title'     => $title,
		'post_name'      => uimptr_sanitize_attachment_slug_from_title( $title ),
		'post_content'   => $description,
		'post_excerpt'   => $description,
		'post_status'    => 'inherit',
	);

	if ( $preserve_dates && $date ) {
		$timestamp = strtotime( $date );
		if ( false !== $timestamp ) {
			$formatted_date              = date( 'Y-m-d H:i:s', $timestamp );
			$attachment['post_date']     = $formatted_date;
			$attachment['post_date_gmt'] = get_gmt_from_date( $formatted_date );
		} else {
			error_log( "URL Image Importer: Failed to parse date: {$date}" );
		}
	}

	return $attachment;
}

/**
 * Apply source URL, title/slug, and alt text metadata after attachment creation.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $title         Attachment title.
 * @param array  $metadata      Import metadata.
 * @param string $image_url     Original source URL.
 * @return void
 */
function uimptr_finalize_imported_attachment( $attachment_id, $title, $metadata, $image_url ) {
	if ( is_wp_error( $attachment_id ) ) {
		return;
	}

	uimptr_update_attachment_title_and_slug( $attachment_id, $title );

	$normalized_source_url = uimptr_normalize_source_url( $image_url );
	if ( '' !== $normalized_source_url ) {
		update_post_meta( $attachment_id, '_uimptr_source_url', $normalized_source_url );
	}

	$alt_text = '';
	if ( !empty($metadata['alt_text']) ) {
		$alt_text = sanitize_text_field($metadata['alt_text']);
	} elseif ( !empty($metadata['title']) ) {
		$alt_text = $title;
	}

	if ( !empty($alt_text) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
	}
}

/**
 * Hand a validated temp file to the same sideload path Big File Uploads uses.
 *
 * @param string $temp_file  Validated temp file path.
 * @param string $filename   Final filename.
 * @param array  $file_type  Validated file type array.
 * @param array  $post_data  Attachment post data.
 * @return int|WP_Error
 */
function uimptr_import_validated_file_with_big_file_uploads( $temp_file, $filename, array $file_type, array $post_data ) {
	$previous_files = $_FILES;

	clearstatcache( true, $temp_file );
	$_FILES['async-upload'] = array(
		'name'     => $filename,
		'type'     => $file_type['type'],
		'tmp_name' => $temp_file,
		'error'    => UPLOAD_ERR_OK,
		'size'     => file_exists( $temp_file ) ? filesize( $temp_file ) : 0,
	);

	try {
		$attachment_id = media_handle_upload(
			'async-upload',
			0,
			$post_data,
			array(
				'action'    => 'wp_handle_sideload',
				'test_form' => false,
			)
		);
	} finally {
		$_FILES = $previous_files;
	}

	if ( is_wp_error( $attachment_id ) && file_exists( $temp_file ) ) {
		uimptr_delete_file_with_logging( $temp_file, 'failed Big File Uploads sideload cleanup' );
	}

	return $attachment_id;
}

/**
 * Determine whether an IP address is one a user-supplied image URL must never reach.
 *
 * SSRF guard. Blocks loopback, RFC1918 private, link-local (including the
 * 169.254.169.254 cloud metadata endpoint), carrier-grade NAT, and other
 * reserved ranges for both IPv4 and IPv6. WordPress core's own
 * wp_http_validate_url() does not cover the link-local or CGNAT ranges, which is
 * why this explicit check is required in addition to wp_safe_remote_get().
 *
 * @param string $ip IP address.
 * @return bool True when the IP is in a blocked range.
 */
function uimptr_ip_is_blocked( $ip ) {
	// Rejects private (10/8, 172.16/12, 192.168/16, fc00::/7) and reserved
	// (0/8, 127/8, 169.254/16, ::1, fe80::/10, ...) ranges in one pass.
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return true;
	}

	// filter_var() does not treat carrier-grade NAT (100.64.0.0/10) as reserved.
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$long = ip2long( $ip );
		if ( false !== $long && $long >= ip2long( '100.64.0.0' ) && $long <= ip2long( '100.127.255.255' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Resolve a hostname to the IP addresses a request to it could reach.
 *
 * Filterable via `uimptr_resolve_host_ips` so tests (and advanced site owners)
 * can override resolution. IP literals are returned as-is.
 *
 * @param string $host Hostname or IP literal (without IPv6 brackets).
 * @return string[] Resolved IP addresses; empty array when resolution fails.
 */
function uimptr_resolve_host_ips( $host ) {
	$filtered = apply_filters( 'uimptr_resolve_host_ips', null, $host );
	if ( is_array( $filtered ) ) {
		return $filtered;
	}

	// An IP literal needs no DNS resolution.
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return array( $host );
	}

	$ips = array();

	$v4 = gethostbynamel( $host );
	if ( is_array( $v4 ) ) {
		$ips = array_merge( $ips, $v4 );
	}

	if ( function_exists( 'dns_get_record' ) ) {
		$aaaa = @dns_get_record( $host, DNS_AAAA );
		if ( is_array( $aaaa ) ) {
			foreach ( $aaaa as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
	}

	return array_values( array_unique( $ips ) );
}

/**
 * Validate that a URL is safe to fetch (SSRF guard).
 *
 * Requires an http(s) scheme, resolves the host, and rejects the request when
 * the host resolves to any internal/reserved address. Fails closed: an
 * unresolvable host is treated as unsafe.
 *
 * @param string $url URL to validate.
 * @return true|WP_Error True when safe, WP_Error otherwise.
 */
function uimptr_validate_remote_url_is_safe( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return new WP_Error( 'ssrf_blocked_url', 'The provided URL is not valid.' );
	}

	$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
	if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'ssrf_blocked_url', 'Only http and https URLs can be imported.' );
	}
	if ( empty( $parts['host'] ) ) {
		return new WP_Error( 'ssrf_blocked_url', 'The provided URL has no host.' );
	}

	$host = trim( $parts['host'], '[]' ); // Strip IPv6 brackets, e.g. [::1].
	$ips  = uimptr_resolve_host_ips( $host );

	if ( empty( $ips ) ) {
		// Fail closed: never fetch a host we cannot resolve and vet.
		return new WP_Error( 'ssrf_blocked_url', 'The URL host could not be resolved.' );
	}

	foreach ( $ips as $ip ) {
		if ( uimptr_ip_is_blocked( $ip ) ) {
			return new WP_Error( 'ssrf_blocked_url', 'Refusing to fetch a URL that resolves to an internal or reserved address.' );
		}
	}

	return true;
}

/**
 * SSRF-safe replacement for wp_safe_remote_get() for user-supplied URLs.
 *
 * Validates the initial target and every redirect hop against internal/reserved
 * IP ranges before each request. Redirects are followed manually (rather than by
 * the HTTP client) so each Location can be re-validated, closing the link-local
 * and CGNAT gap that WordPress core's redirect validation leaves open.
 *
 * @param string $url  URL to fetch.
 * @param array  $args wp_remote_get() args. 'redirection' caps the hop count (default 5).
 * @return array|WP_Error Response array on success, WP_Error otherwise.
 */
function uimptr_ssrf_safe_remote_get( $url, $args = array() ) {
	$max_redirects       = isset( $args['redirection'] ) ? (int) $args['redirection'] : 5;
	$args['redirection'] = 0; // Follow redirects ourselves so each hop is validated.

	$current = $url;

	for ( $hop = 0; $hop <= $max_redirects; $hop++ ) {
		$safe = uimptr_validate_remote_url_is_safe( $current );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$response = wp_safe_remote_get( $current, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return $response; // Not a redirect: final response.
		}

		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( '' === $location ) {
			return $response; // Redirect without a target; hand back untouched.
		}

		if ( class_exists( 'WP_Http' ) && method_exists( 'WP_Http', 'make_absolute_url' ) ) {
			$current = WP_Http::make_absolute_url( $location, $current );
		} else {
			$current = $location;
		}
	}

	return new WP_Error( 'http_request_failed', 'Too many redirects.' );
}

/**
 * Function to import the image from a URL
 *
 * @param url   $image_url       URL of the image to import.
 * @param mixed $batch_id        Batch ID for cancel tracking.
 * @param array $metadata        Optional attachment metadata.
 * @param bool  $preserve_dates  Whether to preserve metadata dates.
 * @param bool  $_deprecated_strip_extension Deprecated. Attachment titles always strip image extensions.
 * @param bool  $allow_google_drive Whether Google Drive share URLs should be resolved before download.
 * */
function uimptr_import_image_from_url( $image_url, $batch_id = null, $metadata = array(), $preserve_dates = false, $_deprecated_strip_extension = true, $allow_google_drive = true ) {
	// Check for stop command if batch_id is provided
	if ( $batch_id ) {
		$cancel_flag = get_transient( uimptr_get_batch_cancel_transient_key( $batch_id ) );
		if ( $cancel_flag ) {
			return new WP_Error( 'import_cancelled', 'Import was cancelled by user' );
		}
	}

	$download_url            = $image_url;
	$is_google_drive_source = $allow_google_drive && uimptr_is_google_drive_url( $image_url );

	if ( $is_google_drive_source ) {
		$download_url = uimptr_get_google_drive_download_url( $image_url );
		if ( is_wp_error( $download_url ) ) {
			return $download_url;
		}
	}
	$upload_dir = wp_upload_dir();
	$filename_url_path = is_string( $image_url ) ? parse_url( $image_url, PHP_URL_PATH ) : false;
	$filename = '';
	$temp_file = '';
	$response_content_type = '';
	$response_content_disposition = '';

	if ( $is_google_drive_source && uimptr_can_use_big_file_uploads_sideload() ) {
		$downloaded_file = uimptr_download_google_drive_file_to_big_file_uploads_temp( $download_url, $image_url, $metadata );
		if ( is_wp_error( $downloaded_file ) ) {
			return $downloaded_file;
		}

		$temp_file                    = $downloaded_file['temp_file'];
		$filename                     = $downloaded_file['filename'];
		$response_content_type        = $downloaded_file['content_type'];
		$response_content_disposition = $downloaded_file['content_disposition'];
	} else {
		// SECURITY (SSRF): The target URL is user-supplied (upload_files capability). Fetch it
		// through uimptr_ssrf_safe_remote_get(), which validates the URL and every redirect hop
		// against internal/private/loopback/link-local/reserved ranges (127.0.0.1, RFC1918, the
		// 169.254.169.254 cloud metadata endpoint, etc.) before each request. wp_safe_remote_get()
		// alone is insufficient: WordPress core's validation does not cover the link-local range.
		$response = uimptr_ssrf_safe_remote_get( $download_url, array(
			'timeout' => 30,
			'redirection' => 5,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' )
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'image_download_failed', 'Failed to download image: ' . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			if ( $is_google_drive_source ) {
				return new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
			}

			return new WP_Error( 'image_download_failed', sprintf( 'Failed to download image. HTTP status: %d', $response_code ) );
		}

		$image_data = wp_remote_retrieve_body( $response );

		if ( empty( $image_data ) ) {
			if ( $is_google_drive_source ) {
				return new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
			}

			return new WP_Error( 'invalid_image', 'No data received from URL.' );
		}

		$response_content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$response_content_disposition = (string) wp_remote_retrieve_header( $response, 'content-disposition' );

		if ( $is_google_drive_source && uimptr_response_looks_like_html( $image_data, $response_content_type ) ) {
			return new WP_Error( 'google_drive_not_public_image', 'Google Drive file is not publicly downloadable as an image.' );
		}

		if ( $filename_url_path && is_string( $filename_url_path ) ) {
			$filename = basename( $filename_url_path );
		}

		// Prefer a real filename from the HTTP response when the URL path has no extension.
		if ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$header_filename = uimptr_get_filename_from_content_disposition( $response_content_disposition );
			if ( ! empty( $header_filename ) ) {
				$filename = $header_filename;
			}
		}

		// Sanitize filename and ensure it has a base name
		if ( ! $filename ) {
			$filename = !empty($metadata['title']) ? sanitize_file_name( $metadata['title'] ) : 'imported_image_' . time();
		}

		// Sanitize the filename
		$filename = sanitize_file_name( $filename );
		$filename_base = pathinfo( $filename, PATHINFO_FILENAME );
		if ( empty( $filename_base ) ) {
			$filename_base = !empty($metadata['title']) ? sanitize_file_name( $metadata['title'] ) : 'imported_image_' . time();
		}

		// Create a temporary file first for validation
		$temp_file = wp_tempnam( $filename );
		$saved = file_put_contents( $temp_file, $image_data );

		if ( $saved === false ) {
			return new WP_Error( 'file_save_failed', 'Failed to save temporary file.' );
		}
	}

	$filename = sanitize_file_name( $filename );
	$filename_base = pathinfo( $filename, PATHINFO_FILENAME );
	if ( empty( $filename_base ) ) {
		$filename_base = !empty($metadata['title']) ? sanitize_file_name( $metadata['title'] ) : 'imported_image_' . time();
	}
	
	// SECURITY: Validate the actual file content using WordPress's image validation
	$wp_filetype = wp_check_filetype_and_ext( $temp_file, $filename );

	// Some image services use extensionless URLs. If needed, infer the extension from
	// the response headers or the downloaded image bytes and validate again.
	if (
		( ! $wp_filetype['type'] || ! $wp_filetype['ext'] ) &&
		empty( pathinfo( $filename, PATHINFO_EXTENSION ) )
	) {
		$detected_mime = $response_content_type;
		$detected_ext  = uimptr_get_image_extension_from_mime_type( $detected_mime );

		if ( empty( $detected_ext ) ) {
			$image_info = @getimagesize( $temp_file );
			if ( false !== $image_info && ! empty( $image_info['mime'] ) ) {
				$detected_mime = $image_info['mime'];
				$detected_ext  = uimptr_get_image_extension_from_mime_type( $detected_mime );
			}
		}

		if ( empty( $detected_ext ) ) {
			$svg_probe = file_get_contents( $temp_file, false, null, 0, 1024 );
			if ( false !== $svg_probe && false !== stripos( $svg_probe, '<svg' ) ) {
				$detected_mime = 'image/svg+xml';
				$detected_ext  = 'svg';
			}
		}

		if ( ! empty( $detected_ext ) ) {
			$filename    = sanitize_file_name( $filename_base . '.' . $detected_ext );
			$wp_filetype = wp_check_filetype_and_ext( $temp_file, $filename );

			if ( ( ! $wp_filetype['type'] || ! $wp_filetype['ext'] ) && ! empty( $detected_mime ) ) {
				$wp_filetype = array(
					'ext'  => $detected_ext,
					'type' => $detected_mime,
				);
			}
		}
	}
	
	// Clean up and reject if validation fails
	if ( ! $wp_filetype['type'] || ! $wp_filetype['ext'] ) {
		uimptr_delete_file_with_logging( $temp_file, 'invalid image validation cleanup' );
		if ( $is_google_drive_source ) {
			return new WP_Error( 'google_drive_non_image', 'Google Drive file is not a supported image.' );
		}

		return new WP_Error( 'invalid_image', 'File failed content validation. Not a valid image file.' );
	}
	
	// SECURITY: Ensure the detected type is an image mime type
	if ( strpos( $wp_filetype['type'], 'image/' ) !== 0 ) {
		uimptr_delete_file_with_logging( $temp_file, 'non-image mime cleanup' );
		if ( $is_google_drive_source ) {
			return new WP_Error( 'google_drive_non_image', 'Google Drive file is not a supported image.' );
		}

		return new WP_Error( 'invalid_image', 'File must be an image type.' );
	}
	
	// Special handling for SVG files (getimagesize doesn't work with SVG)
	$is_svg = ( $wp_filetype['ext'] === 'svg' || $wp_filetype['type'] === 'image/svg+xml' );
	
	if ( $is_svg ) {
		// Validate and sanitize SVG content
		$svg_content = file_get_contents( $temp_file );
		if ( $svg_content === false || strpos( $svg_content, '<svg' ) === false ) {
			uimptr_delete_file_with_logging( $temp_file, 'invalid SVG cleanup' );
			if ( $is_google_drive_source ) {
				return new WP_Error( 'google_drive_non_image', 'Google Drive file is not a supported image.' );
			}

			return new WP_Error( 'invalid_svg', 'File is not a valid SVG file.' );
		}
		
		// SECURITY: Sanitize SVG content to remove potential XSS
		$svg_content = uimptr_sanitize_svg_content( $svg_content );
		
		// Write sanitized content back to temp file
		if ( file_put_contents( $temp_file, $svg_content ) === false ) {
			uimptr_delete_file_with_logging( $temp_file, 'SVG sanitization cleanup' );
			return new WP_Error( 'svg_sanitization_failed', 'Failed to sanitize SVG file.' );
		}
		
		// SECURITY: Validate that SVG mime type is in allowed list
		$allowed_mime_types = get_allowed_mime_types();
		if ( ! in_array( 'image/svg+xml', $allowed_mime_types, true ) ) {
			uimptr_delete_file_with_logging( $temp_file, 'disallowed SVG mime cleanup' );
			return new WP_Error( 'svg_not_allowed', 'SVG files are not allowed on this site.' );
		}
	} else {
		// Verify it's actually an image by checking if we can get image info (raster images only)
		$image_info = @getimagesize( $temp_file );
		if ( $image_info === false ) {
			uimptr_delete_file_with_logging( $temp_file, 'invalid raster image cleanup' );
			if ( $is_google_drive_source ) {
				return new WP_Error( 'google_drive_non_image', 'Google Drive file is not a supported image.' );
			}

			return new WP_Error( 'invalid_image', 'File is not a valid image format.' );
		}
		
		// SECURITY: Validate that mime type from content is in allowed list
		$allowed_mime_types = get_allowed_mime_types();
		if ( ! in_array( $image_info['mime'], $allowed_mime_types, true ) ) {
			uimptr_delete_file_with_logging( $temp_file, 'disallowed raster mime cleanup' );
			return new WP_Error( 'invalid_image_mime', 'Image mime type is not allowed.' );
		}
	}
	
	// Build filename with validated extension from actual content
	$filename_base = pathinfo( $filename, PATHINFO_FILENAME );
	if ( empty( $filename_base ) ) {
		$filename_base = !empty($metadata['title']) ? sanitize_file_name( $metadata['title'] ) : 'imported_image_' . time();
	}
	$filename = $filename_base . '.' . $wp_filetype['ext'];
	$filename = sanitize_file_name( $filename );

	// Use the validated file type.
	$file_type = array(
		'ext'  => $wp_filetype['ext'],
		'type' => $wp_filetype['type']
	);

	// Use provided metadata when available; otherwise mirror WordPress upload title behavior.
	if ( !empty($metadata['title']) ) {
		$title = sanitize_text_field( uimptr_maybe_strip_image_extension_from_title( $metadata['title'] ) );
	} else {
		$title_source = $filename;
		$title_source_without_extension = pathinfo( $filename, PATHINFO_FILENAME );
		if ( '' !== $title_source_without_extension ) {
			$title_source = $title_source_without_extension;
		}
		$title = sanitize_file_name( $title_source );
	}
	$description = !empty($metadata['description']) ? sanitize_textarea_field($metadata['description']) : '';
	$date = !empty($metadata['date']) ? $metadata['date'] : null;
	$attachment = uimptr_build_attachment_post_data( $title, $description, $date, $preserve_dates, $file_type['type'] );

	if ( uimptr_can_use_big_file_uploads_sideload() ) {
		$attachment_id = uimptr_import_validated_file_with_big_file_uploads( $temp_file, $filename, $file_type, $attachment );
		if ( ! is_wp_error( $attachment_id ) ) {
			uimptr_finalize_imported_attachment( $attachment_id, $title, $metadata, $image_url );
		}

		return $attachment_id;
	}
	
	// Generate unique filename to prevent overwrites
	$filename = wp_unique_filename( $upload_dir['path'], $filename );
	$file_path = $upload_dir['path'] . '/' . $filename;

	if ( empty( $metadata['title'] ) ) {
		$title_source = $filename;
		$title_source_without_extension = pathinfo( $filename, PATHINFO_FILENAME );
		if ( '' !== $title_source_without_extension ) {
			$title_source = $title_source_without_extension;
		}
		$title      = sanitize_file_name( $title_source );
		$attachment = uimptr_build_attachment_post_data( $title, $description, $date, $preserve_dates, $file_type['type'] );
	}
	
	// Move the validated temp file to final location
	// Use copy + unlink instead of rename for cross-filesystem compatibility (cloud storage)
	$moved = @rename( $temp_file, $file_path );
	
	// If rename fails (different filesystems, e.g., cloud storage), use copy + unlink
	if ( ! $moved ) {
		$moved = @copy( $temp_file, $file_path );
		if ( $moved ) {
			uimptr_delete_file_with_logging( $temp_file, 'post-copy temp file cleanup' );
		}
	}
	
	if ( ! $moved ) {
		uimptr_delete_file_with_logging( $temp_file, 'failed file move cleanup' );
		return new WP_Error( 'file_move_failed', 'Failed to move validated file to uploads directory.' );
	}
	
	// Verify the file was actually saved and is readable
	if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
		return new WP_Error( 'file_not_accessible', 'Saved file is not accessible.' );
	}

	$attachment_id = wp_insert_attachment( $attachment, $file_path );

	if ( ! is_wp_error( $attachment_id ) ) {
		uimptr_update_attachment_title_and_slug( $attachment_id, $title );

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$normalized_source_url = uimptr_normalize_source_url( $image_url );
		if ( '' !== $normalized_source_url ) {
			update_post_meta( $attachment_id, '_uimptr_source_url', $normalized_source_url );
		}
		
		// Generate attachment metadata (thumbnails, etc.)
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		
		// Debug log to check if metadata was generated
		if ( empty( $attach_data ) ) {
			error_log( "URL Image Importer: Failed to generate attachment metadata for {$filename}" );
		} else {
			error_log( "URL Image Importer: Generated metadata for {$filename}: " . print_r( $attach_data, true ) );
		}
		
		wp_update_attachment_metadata( $attachment_id, $attach_data );
		uimptr_update_attachment_title_and_slug( $attachment_id, $title );
		
		// Set alt text from alt_text field, or fall back to title if available
		$alt_text = '';
		if ( !empty($metadata['alt_text']) ) {
			$alt_text = sanitize_text_field($metadata['alt_text']);
		} elseif ( !empty($metadata['title']) ) {
			$alt_text = $title;
		}
		
		if ( !empty($alt_text) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}
	} else {
		// Log attachment creation failure
		error_log( "URL Image Importer: Failed to create attachment for {$image_url}: " . $attachment_id->get_error_message() );
	}

	return $attachment_id;
}

/**
 * Scan Media Library database for file statistics.
 * Used when cloud storage (like Infinite Uploads) is active.
 * 
 * @return array Array with total_files, total_size, and types breakdown
 */
function uimptr_scan_media_library_database() {
	global $wpdb;
	
	// Get all attachments from the media library
	$attachments = $wpdb->get_results(
		"SELECT ID, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'",
		ARRAY_A
	);
	
	$results = array(
		'total_files' => 0,
		'total_size' => 0,
		'types' => array()
	);
	
	$debug_sample = 0;
	foreach ( $attachments as $attachment ) {
		$attachment_id = $attachment['ID'];
		$mime_type = $attachment['post_mime_type'];
		
		// Get file path and metadata
		$file_path = get_attached_file( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		
		// Debug first 3 attachments
		if ( $debug_sample < 3 ) {
			error_log( sprintf( 
				'URL Image Importer Debug: ID=%d, mime=%s, path=%s, has_metadata=%s',
				$attachment_id,
				$mime_type,
				$file_path ? basename($file_path) : 'NULL',
				$metadata ? 'yes' : 'no'
			));
			$debug_sample++;
		}
		
		// Calculate original file size
		$file_size = 0;
		if ( $file_path && file_exists( $file_path ) ) {
			// Local file exists
			$file_size = filesize( $file_path );
		} elseif ( isset( $metadata['filesize'] ) ) {
			// Use metadata filesize (for remote files)
			$file_size = $metadata['filesize'];
		} else {
			// Estimate based on dimensions for images
			if ( strpos( $mime_type, 'image/' ) === 0 && isset( $metadata['width'], $metadata['height'] ) ) {
				// Very rough estimate: width * height * 3 bytes (RGB)
				$file_size = $metadata['width'] * $metadata['height'] * 3;
			}
		}
		
		// Determine file type category using the same logic as FileScan
		$file_type = 'other';
		
		// Get file extension
		$extension = '';
		if ( $file_path ) {
			$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		} elseif ( $mime_type ) {
			// Map mime type to extension
			$mime_to_ext = array(
				'image/jpeg' => 'jpg',
				'image/png' => 'png',
				'image/gif' => 'gif',
				'image/webp' => 'webp',
				'image/svg+xml' => 'svg',
				'image/bmp' => 'bmp',
				'image/tiff' => 'tiff',
				'application/pdf' => 'pdf',
				'video/mp4' => 'mp4',
				'video/quicktime' => 'mov',
				'video/mpeg' => 'mpg',
				'video/webm' => 'webm',
				'audio/mpeg' => 'mp3',
				'audio/wav' => 'wav',
				'audio/ogg' => 'ogg',
			);
			$extension = isset( $mime_to_ext[$mime_type] ) ? $mime_to_ext[$mime_type] : '';
		}
		
		// Categorize by extension (must match FileScan categories)
		if ( $extension ) {
			$categories = array(
				'image'    => array( 'jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico', 'svg', 'svgz', 'webp' ),
				'audio'    => array( 'aac', 'ac3', 'aif', 'aiff', 'flac', 'm3a', 'm4a', 'm4b', 'mka', 'mp1', 'mp2', 'mp3', 'ogg', 'oga', 'ram', 'wav', 'wma' ),
				'video'    => array( '3g2', '3gp', '3gpp', 'asf', 'avi', 'divx', 'dv', 'flv', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'mpv', 'ogm', 'ogv', 'qt', 'rm', 'vob', 'wmv', 'webm' ),
				'document' => array( 'log', 'asc', 'csv', 'tsv', 'txt', 'doc', 'docx', 'docm', 'dotm', 'odt', 'pages', 'pdf', 'xps', 'oxps', 'rtf', 'wp', 'wpd', 'psd', 'xcf', 'swf', 'key', 'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'sldx', 'sldm', 'odp', 'numbers', 'ods', 'xls', 'xlsx', 'xlsm', 'xlsb' ),
				'archive'  => array( 'bz2', 'cab', 'dmg', 'gz', 'rar', 'sea', 'sit', 'sqx', 'tar', 'tgz', 'zip', '7z', 'data', 'bin', 'bak' ),
				'code'     => array( 'css', 'htm', 'html', 'php', 'js', 'md' ),
			);
			
			foreach ( $categories as $category => $extensions ) {
				if ( in_array( $extension, $extensions, true ) ) {
					$file_type = $category;
					break;
				}
			}
		}
		
		// Initialize type object if needed (must match FileScan structure)
		if ( ! isset( $results['types'][$file_type] ) ) {
			$results['types'][$file_type] = (object) array(
				'files' => 0,
				'size' => 0
			);
		}
		
		// Count and add original file
		$results['total_files']++;
		$results['total_size'] += $file_size;
		$results['types'][$file_type]->files++;
		$results['types'][$file_type]->size += $file_size;
		
		// Count and add all thumbnail/resized versions as separate files
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = $file_path ? trailingslashit( dirname( $file_path ) ) : '';
			
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( isset( $size_data['file'] ) ) {
					$thumb_size = 0;
					$thumb_path = $base_dir . $size_data['file'];
					
					if ( $base_dir && file_exists( $thumb_path ) ) {
						// Local thumbnail exists
						$thumb_size = filesize( $thumb_path );
					} elseif ( isset( $size_data['filesize'] ) ) {
						// Remote thumbnail filesize in metadata
						$thumb_size = $size_data['filesize'];
					} elseif ( isset( $size_data['width'], $size_data['height'] ) ) {
						// Estimate thumbnail size
						$thumb_size = $size_data['width'] * $size_data['height'] * 3;
					}
					
					// Count each thumbnail as a separate file
					$results['total_files']++;
					$results['total_size'] += $thumb_size;
					$results['types'][$file_type]->files++;
					$results['types'][$file_type]->size += $thumb_size;
				}
			}
		}
	}
	
	error_log('URL Image Importer: Media Library scan found ' . $results['total_files'] . ' files totaling ' . size_format($results['total_size']));
	
	return $results;
}

/**
 * Scan files to analyze storage usage by file type.
 */
function uimptr_ajax_file_scan() {
	$nonce = isset( $_POST['js_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['js_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'ajax-nonce' ) ) {
		error_log('URL Image Importer: Nonce verification failed during scan.');
		wp_send_json_error( 'Nonce Verification Failed!' );
		return;
	}
	
	try {
		// Check if Infinite Uploads or similar cloud storage is active
		$using_cloud_storage = function_exists( 'infinite_uploads_init' ) || class_exists( 'Infinite_Uploads' );
		
		if ( $using_cloud_storage ) {
			// Scan Media Library database instead of local files
			error_log('URL Image Importer: Infinite Uploads detected - scanning Media Library database');
			$results = uimptr_scan_media_library_database();
			
			$file_count = number_format_i18n( $results['total_files'] );
			$file_size  = size_format( $results['total_size'], 2 );
			$is_done    = true;
			$remaining_dirs = array();
			
			// Update the site option with results
			update_site_option( 'uimptr_file_scan', array(
				'scan_finished' => time(),
				'types' => $results['types']
			));
			
		} else {
			// Scan local file system
			$path           = uimptr_get_upload_dir_root();
			$remaining_dirs = array();
			
			if ( isset( $_POST['remaining_dirs'] ) ) {
				$dirs_raw = wp_unslash( $_POST['remaining_dirs'] );
				$dirs_arr = is_array($dirs_raw) ? $dirs_raw : explode(',', (string)$dirs_raw);
				foreach ( $dirs_arr as $dir ) {
					$dir = sanitize_text_field( $dir );
					$realpath = realpath( $path . $dir );
					if ( $realpath && 0 === strpos( $realpath, $path ) ) {
						$remaining_dirs[] = $dir;
					}
				}
			}
			
			error_log('URL Image Importer: Scanning local files at ' . $path);
			$file_scan = new \UrlImageImporter\FileScan\FileScan( $path, 20, $remaining_dirs );
			$file_scan->start();
			$file_count     = number_format_i18n( $file_scan->get_total_files() );
			$file_size      = size_format( $file_scan->get_total_size(), 2 );
			$remaining_dirs = $file_scan->get_paths_left();
			$is_done        = $file_scan->is_done();
		}

		$data = compact( 'file_count', 'file_size', 'is_done', 'remaining_dirs' );
		error_log('URL Image Importer: Scan complete - ' . $file_count . ' files, ' . $file_size . ', done: ' . ($is_done ? 'yes' : 'no'));
		wp_send_json_success( $data );
	} catch (Throwable $e) {
		error_log('URL Image Importer: Scan failed with error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
		wp_send_json_error( array('message' => $e->getMessage()) );
	}
}
add_action( 'wp_ajax_uimptr_bfu_file_scan', 'uimptr_ajax_file_scan' );

/**
 * AJAX handler for single URL import
 */
function uimptr_ajax_import_single_url() {
	uimptr_check_ajax_request();
	
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Permission denied' );
	}
	
	$url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
	$metadata = isset( $_POST['metadata'] ) ? json_decode( stripslashes( $_POST['metadata'] ), true ) : array();
	$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( $_POST['batch_id'] ) : null;
	
	if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		wp_send_json_error( 'Invalid URL provided' );
	}
	
	// Check if we should preserve dates
	$preserve_dates = isset( $_POST['preserve_dates'] ) && $_POST['preserve_dates'] === 'true';
	
	// Pass metadata to the import function so it handles dates properly during initial creation
	$attachment_id = uimptr_import_image_from_url( $url, $batch_id, $metadata, $preserve_dates );
	
	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error( $attachment_id->get_error_message() );
	}
	
	// Metadata (including dates) is now handled in uimptr_import_image_from_url()
	
	wp_send_json_success( array( 
		'attachment_id' => $attachment_id,
		'edit_link' => get_edit_post_link( $attachment_id )
	) );
}
add_action( 'wp_ajax_uimptr_import_single_url', 'uimptr_ajax_import_single_url' );

/**
 * AJAX handler for XML processing (extract URLs)
 */
function uimptr_ajax_process_xml_import() {
	uimptr_check_ajax_request();
	
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Permission denied' );
	}
	
	if ( !isset( $_FILES['xml_file'] ) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'No file uploaded or upload error occurred.' );
	}
	
	$uploaded_file = $_FILES['xml_file'];
	$file_extension = strtolower( pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) );
	
	if ( $file_extension !== 'xml' ) {
		wp_send_json_error( 'Please upload a valid XML file.' );
	}
	
	// SECURITY: Validate uploaded file type before processing
	// XML files may have various mime types: text/xml, application/xml, text/plain
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$detected_mime = finfo_file( $finfo, $uploaded_file['tmp_name'] );
	finfo_close( $finfo );
	
	$allowed_xml_mimes = array( 'text/xml', 'application/xml', 'text/plain' );
	if ( ! in_array( $detected_mime, $allowed_xml_mimes, true ) ) {
		wp_send_json_error( 'Invalid file type. Only XML files are allowed.' );
	}
	
	// Additional check: Verify the file actually contains XML content
	$file_content = file_get_contents( $uploaded_file['tmp_name'], false, null, 0, 2048 );
	// Remove BOM if present
	$file_content = preg_replace('/^\xEF\xBB\xBF/', '', $file_content);
	if ( stripos( $file_content, '<?xml' ) === false && stripos( $file_content, '<rss' ) === false ) {
		wp_send_json_error( 'File does not appear to be valid XML content.' );
	}
	
	// Store the file temporarily
	$temp_file_result = uimptr_store_temp_file( $uploaded_file );
	if ( is_wp_error( $temp_file_result ) ) {
		wp_send_json_error( $temp_file_result->get_error_message() );
	}
	
	// Read and parse XML content
	$xml_content = file_get_contents( $temp_file_result['path'] );
	if ( $xml_content === false ) {
		wp_send_json_error( 'Failed to read uploaded file.' );
	}
	
	// Check if we should preserve dates
	$preserve_dates = isset( $_POST['xml_preserve_dates'] ) && $_POST['xml_preserve_dates'];
	
	// Check if we should force reimport (same logic as batch import)
	$force_reimport = isset( $_POST['force_reimport'] ) && ( 
		$_POST['force_reimport'] === 'true' || 
		$_POST['force_reimport'] === '1' || 
		$_POST['force_reimport'] === 1 || 
		$_POST['force_reimport'] === true 
	);
	
	// Parse XML and extract URLs from content
	$urls_data = uimptr_extract_urls_from_xml_content( $xml_content, $preserve_dates, $force_reimport );
	
	if ( is_wp_error( $urls_data ) ) {
		// Clean up temp file on error
		if ( file_exists( $temp_file_result['path'] ) ) {
			uimptr_delete_file_with_logging( $temp_file_result['path'], 'XML preview temp file cleanup' );
		}
		wp_send_json_error( $urls_data->get_error_message() );
	}
	
	// Store file info for batch processing
	$batch_id = $temp_file_result['file_id'];
	
	wp_send_json_success( array( 
		'urls' => $urls_data,
		'count' => count( $urls_data ),
		'batch_id' => $batch_id
	) );
}
add_action( 'wp_ajax_uimptr_process_xml_import', 'uimptr_ajax_process_xml_import' );

// Test endpoint to verify AJAX is working
function uimptr_test_ajax_connection() {
	uimptr_check_ajax_request();

	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Permission denied - user not logged in' );
	}
	
	wp_send_json_success( 'AJAX connection working' );
}
add_action( 'wp_ajax_uimptr_test_connection', 'uimptr_test_ajax_connection' );

// CSV Import AJAX endpoint
function uimptr_ajax_process_csv_import() {
	uimptr_check_ajax_request();
	
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Permission denied' );
	}
	
	if ( !isset( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'No file uploaded or upload error occurred.' );
	}
	
	$uploaded_file = $_FILES['csv_file'];
	$file_extension = strtolower( pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) );
	
	if ( $file_extension !== 'csv' ) {
		wp_send_json_error( 'Please upload a valid CSV file.' );
	}
	
	// SECURITY: Validate uploaded file is actually a text/CSV file
	// CSV files should be plain text, check mime type
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$mime_type = finfo_file( $finfo, $uploaded_file['tmp_name'] );
	finfo_close( $finfo );
	
	// Accept text/plain, text/csv, and text/x-csv (different systems report differently)
	$allowed_csv_mimes = array( 'text/plain', 'text/csv', 'text/x-csv', 'application/csv', 'application/vnd.ms-excel' );
	if ( ! in_array( $mime_type, $allowed_csv_mimes, true ) ) {
		wp_send_json_error( 'Invalid file type. Only CSV files are allowed.' );
	}
	
	// Store the file temporarily
	$temp_file_result = uimptr_store_temp_file( $uploaded_file );
	if ( is_wp_error( $temp_file_result ) ) {
		wp_send_json_error( $temp_file_result->get_error_message() );
	}
	
	// Read and parse CSV content
	$csv_content = file_get_contents( $temp_file_result['path'] );
	if ( $csv_content === false ) {
		wp_send_json_error( 'Failed to read uploaded file.' );
	}
	
	// Check if we should preserve dates
	$preserve_dates = isset( $_POST['csv_preserve_dates'] ) && $_POST['csv_preserve_dates'];
	
	// Check if we should force reimport (same logic as batch import)
	$force_reimport = isset( $_POST['force_reimport'] ) && ( 
		$_POST['force_reimport'] === 'true' || 
		$_POST['force_reimport'] === '1' || 
		$_POST['force_reimport'] === 1 || 
		$_POST['force_reimport'] === true 
	);
	
	// Parse CSV and extract URLs from content
	$urls_data = uimptr_extract_urls_from_csv_content( $csv_content, $preserve_dates, $force_reimport );
	
	if ( is_wp_error( $urls_data ) ) {
		// Clean up temp file on error
		if ( file_exists( $temp_file_result['path'] ) ) {
			uimptr_delete_file_with_logging( $temp_file_result['path'], 'CSV preview temp file cleanup' );
		}
		wp_send_json_error( $urls_data->get_error_message() );
	}
	
	// Store file info for batch processing
	$batch_id = $temp_file_result['file_id'];
	
	wp_send_json_success( array( 
		'urls' => $urls_data,
		'count' => count( $urls_data ),
		'batch_id' => $batch_id
	) );
}
add_action( 'wp_ajax_uimptr_process_csv_import', 'uimptr_ajax_process_csv_import' );

/**
 * Get transient key used for cached batch URLs.
 *
 * @param string $batch_id Batch ID.
 * @return string
 */
function uimptr_get_batch_urls_transient_key( $batch_id ) {
	return 'uimptr_urls_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $batch_id );
}

/**
 * Get transient key used for mapping export metadata.
 *
 * @param string $batch_id Batch ID.
 * @return string
 */
function uimptr_get_mapping_transient_key( $batch_id ) {
	return 'uimptr_mapping_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $batch_id );
}

/**
 * Get transient key used for batch stats.
 *
 * @param string $batch_id Batch ID.
 * @return string
 */
function uimptr_get_batch_stats_transient_key( $batch_id ) {
	return 'uimptr_stats_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $batch_id );
}

/**
 * Get transient key used for batch cancel state.
 *
 * @param string $batch_id Batch ID.
 * @return string
 */
function uimptr_get_batch_cancel_transient_key( $batch_id ) {
	return 'uimptr_cancel_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $batch_id );
}

/**
 * Get transient key used for uploaded temp file metadata.
 *
 * @param string $file_id File ID / batch ID.
 * @return string
 */
function uimptr_get_temp_file_transient_key( $file_id ) {
	return 'uimptr_temp_file_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $file_id );
}

/**
 * Get transient key used for legacy import progress state.
 *
 * @param string $import_id Import ID.
 * @return string
 */
function uimptr_get_legacy_import_progress_transient_key( $import_id ) {
	return 'uimptr_import_progress_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $import_id );
}

/**
 * Get transient key used for legacy import URL state.
 *
 * @param string $import_id Import ID.
 * @return string
 */
function uimptr_get_legacy_import_urls_transient_key( $import_id ) {
	return 'uimptr_import_urls_' . uimptr_get_state_user_id() . '_' . sanitize_key( (string) $import_id );
}

/**
 * Ensure temp directory exists and is writable.
 *
 * @param string $temp_dir Absolute temp directory path.
 * @return true|WP_Error
 */
function uimptr_ensure_temp_directory( $temp_dir ) {
	if ( empty( $temp_dir ) ) {
		return new WP_Error( 'temp_dir_missing', 'Temporary directory path is missing.' );
	}

	if ( ! file_exists( $temp_dir ) ) {
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'temp_dir_create_failed', 'Failed to create temporary directory.' );
		}

		// Add .htaccess to prevent direct access.
		@file_put_contents( trailingslashit( $temp_dir ) . '.htaccess', "Order Deny,Allow\nDeny from all\n" );
		// Add index.php for extra security.
		@file_put_contents( trailingslashit( $temp_dir ) . 'index.php', '<?php // Silence is golden' );
	}

	if ( ! is_writable( $temp_dir ) ) {
		return new WP_Error( 'temp_dir_not_writable', 'Temporary directory is not writable.' );
	}

	return true;
}

/**
 * Initialize mapping export for a batch.
 *
 * @param string $batch_id Batch ID.
 * @return array|WP_Error
 */
function uimptr_initialize_mapping_export( $batch_id ) {
	$transient_key = uimptr_get_mapping_transient_key( $batch_id );
	$existing      = get_transient( $transient_key );

	if ( is_array( $existing ) && ! empty( $existing['path'] ) && file_exists( $existing['path'] ) ) {
		return $existing;
	}

	$temp_dir = uimptr_get_local_temp_dir();
	$dir_ok   = uimptr_ensure_temp_directory( $temp_dir );
	if ( is_wp_error( $dir_ok ) ) {
		return $dir_ok;
	}

	$filename = 'url_mapping_' . sanitize_file_name( $batch_id ) . '_' . time() . '.csv';
	$file_path = trailingslashit( $temp_dir ) . $filename;

	$handle = @fopen( $file_path, 'w' );
	if ( false === $handle ) {
		return new WP_Error( 'mapping_file_create_failed', 'Failed to create mapping export file.' );
	}

	$header_written = fputcsv( $handle, array( 'Old URL (external)', 'New URL (local WP)' ), ',', '"', '\\' );
	fclose( $handle );

	if ( false === $header_written ) {
		uimptr_delete_file_with_logging( $file_path, 'mapping export initialization cleanup' );
		return new WP_Error( 'mapping_header_write_failed', 'Failed to initialize mapping export header.' );
	}

	$mapping_info = array(
		'path'      => $file_path,
		'row_count' => 0,
		'created'   => time(),
		'batch_id'  => $batch_id,
	);

	set_transient( $transient_key, $mapping_info, DAY_IN_SECONDS );

	return $mapping_info;
}

/**
 * Get mapping export metadata.
 *
 * @param string $batch_id Batch ID.
 * @return array|null
 */
function uimptr_get_mapping_export_info( $batch_id ) {
	$mapping_info = get_transient( uimptr_get_mapping_transient_key( $batch_id ) );
	return is_array( $mapping_info ) ? $mapping_info : null;
}

/**
 * Neutralize spreadsheet formula prefixes before writing CSV cells.
 *
 * @param string $value CSV cell value.
 * @return string
 */
function uimptr_escape_csv_cell_for_spreadsheet( $value ) {
	$value = (string) $value;

	// Excel/Sheets can treat leading tabs and formula prefixes as executable.
	if ( preg_match( '/^(?:\t|[\s]*[=+\-@])/', $value ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Append a row to mapping export CSV.
 *
 * @param string $batch_id Batch ID.
 * @param string $old_url Original external URL.
 * @param string $new_url Local WordPress URL.
 * @return array|WP_Error Updated mapping metadata or WP_Error on failure.
 */
function uimptr_append_mapping_export_row( $batch_id, $old_url, $new_url ) {
	if ( empty( $old_url ) || empty( $new_url ) ) {
		return uimptr_get_mapping_export_info( $batch_id );
	}

	$mapping_info = uimptr_get_mapping_export_info( $batch_id );
	if ( empty( $mapping_info ) || empty( $mapping_info['path'] ) || ! file_exists( $mapping_info['path'] ) ) {
		$mapping_info = uimptr_initialize_mapping_export( $batch_id );
		if ( is_wp_error( $mapping_info ) ) {
			return $mapping_info;
		}
	}

	$handle = @fopen( $mapping_info['path'], 'a' );
	if ( false === $handle ) {
		return new WP_Error( 'mapping_file_open_failed', 'Failed to open mapping export file for appending.' );
	}

	$row_written = fputcsv(
		$handle,
		array(
			uimptr_escape_csv_cell_for_spreadsheet( $old_url ),
			uimptr_escape_csv_cell_for_spreadsheet( $new_url ),
		),
		',',
		'"',
		'\\'
	);
	fclose( $handle );

	if ( false === $row_written ) {
		return new WP_Error( 'mapping_row_write_failed', 'Failed to write URL mapping row.' );
	}

	$mapping_info['row_count'] = intval( $mapping_info['row_count'] ?? 0 ) + 1;
	$mapping_info['created']   = intval( $mapping_info['created'] ?? time() );
	$mapping_info['batch_id']  = $batch_id;

	set_transient( uimptr_get_mapping_transient_key( $batch_id ), $mapping_info, DAY_IN_SECONDS );

	return $mapping_info;
}

/**
 * Delete mapping export metadata and optional file.
 *
 * @param string $batch_id Batch ID.
 * @param bool   $delete_file Whether to delete the CSV file.
 */
function uimptr_cleanup_mapping_export( $batch_id, $delete_file = true ) {
	$mapping_info = uimptr_get_mapping_export_info( $batch_id );

	if ( $delete_file && is_array( $mapping_info ) && ! empty( $mapping_info['path'] ) && file_exists( $mapping_info['path'] ) ) {
		uimptr_delete_file_with_logging( $mapping_info['path'], 'mapping export cleanup' );
	}

	delete_transient( uimptr_get_mapping_transient_key( $batch_id ) );
}

/**
 * AJAX handler for batch import with progress tracking
 */
function uimptr_ajax_batch_import() {
	uimptr_check_ajax_request();
	
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Permission denied' );
	}
	
	$batch_id    = sanitize_text_field( $_POST['batch_id'] ?? '' );
	$start_index = intval( $_POST['start_index'] ?? 0 );
	$batch_size  = intval( $_POST['batch_size'] ?? 5 ); // Process 5 URLs at a time
	$import_type = sanitize_key( $_POST['import_type'] ?? 'url' );
	$urls_raw    = isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : '';
	$urls_payload = array();
	if ( ! empty( $urls_raw ) ) {
		$decoded_payload = json_decode( $urls_raw, true );
		if ( is_array( $decoded_payload ) ) {
			$urls_payload = $decoded_payload;
		}
	}
	$preserve_dates = isset( $_POST['preserve_dates'] ) && ( $_POST['preserve_dates'] === 'true' || $_POST['preserve_dates'] === '1' || $_POST['preserve_dates'] === true );
	// Handle force_reimport: could be boolean true, string "true", "1", or checkbox value "1"
	$force_reimport = isset( $_POST['force_reimport'] ) && ( 
		$_POST['force_reimport'] === 'true' || 
		$_POST['force_reimport'] === '1' || 
		$_POST['force_reimport'] === 1 || 
		$_POST['force_reimport'] === true 
	);
	
	if ( empty( $batch_id ) ) {
		wp_send_json_error( 'Invalid batch ID' );
	}

	$urls_transient_key = uimptr_get_batch_urls_transient_key( $batch_id );

	// Cache all URLs on first request; fetch from transient on subsequent requests.
	if ( 0 === $start_index ) {
		if ( empty( $urls_payload ) ) {
			wp_send_json_error( 'Invalid batch data' );
		}

		$urls = $urls_payload;
		$urls_cached = set_transient( $urls_transient_key, $urls, 2 * HOUR_IN_SECONDS );
		if ( false === $urls_cached && empty( get_transient( $urls_transient_key ) ) ) {
			wp_send_json_error( 'Failed to initialize batch URL cache.' );
		}

		$mapping_initialized = uimptr_initialize_mapping_export( $batch_id );
		if ( is_wp_error( $mapping_initialized ) ) {
			wp_send_json_error( array(
				'message' => 'Failed to initialize URL mapping export: ' . $mapping_initialized->get_error_message(),
			) );
		}
	} else {
		$urls = get_transient( $urls_transient_key );

		// Backward compatibility fallback if URLs are still posted by older clients.
		if ( empty( $urls ) && ! empty( $urls_payload ) ) {
			$urls = $urls_payload;
			set_transient( $urls_transient_key, $urls, 2 * HOUR_IN_SECONDS );
		}

		if ( ! is_array( $urls ) || empty( $urls ) ) {
			wp_send_json_error( 'Import session expired. Please restart the import.' );
		}
	}

	// Check if import was cancelled
	$cancel_flag = get_transient( uimptr_get_batch_cancel_transient_key( $batch_id ) );
	if ( $cancel_flag ) {
		delete_transient( uimptr_get_batch_cancel_transient_key( $batch_id ) );
		wp_send_json_error( 'Import cancelled by user' );
	}
	
	$total_urls = count( $urls );
	$end_index = min( $start_index + $batch_size, $total_urls );
	$results = array();
	$errors = array();
	
	// Get cumulative counters from transients
	$batch_stats = get_transient( uimptr_get_batch_stats_transient_key( $batch_id ) ) ?: array(
		'success' => 0,
		'failed' => 0,
		'skipped' => 0,
		'skipped_messages' => array()
	);

	if ( ! isset( $batch_stats['skipped_messages'] ) || ! is_array( $batch_stats['skipped_messages'] ) ) {
		$batch_stats['skipped_messages'] = array();
	}

	$allow_google_drive = in_array( $import_type, array( 'url', 'csv' ), true );
	
	// Initialize batch counters
	$batch_success = 0;
	$batch_failed = 0;
	$batch_skipped = 0;
	
	// Process batch
	for ( $i = $start_index; $i < $end_index; $i++ ) {
		// Check for stop command before processing each URL
		$cancel_flag = get_transient( uimptr_get_batch_cancel_transient_key( $batch_id ) );
		if ( $cancel_flag ) {
			delete_transient( uimptr_get_batch_cancel_transient_key( $batch_id ) );
			wp_send_json_error( array( 
				'message' => 'Import stopped by user',
				'processed' => $i,
				'stopped_at' => $i
			) );
		}
		
		if ( !isset( $urls[$i] ) ) {
			$batch_skipped++;
			continue;
		}
		
		$url_data = $urls[$i];
		$url = $url_data['url'] ?? '';
		$metadata = $url_data['metadata'] ?? array();
		
		if ( empty( $url ) ) {
			$errors[] = "Empty URL at index {$i}";
			$batch_failed++;
			continue;
		}
		
		// Check if file already exists (unless force_reimport is enabled).
		if ( ! $force_reimport ) {
			$existing_attachment_id = uimptr_get_existing_attachment_id_for_url( $url );
			if ( $existing_attachment_id ) {
				error_log( "URL Image Importer: Skipping existing file from URL: {$url}" );
				$existing_url = wp_get_attachment_url( $existing_attachment_id );
				if ( ! empty( $existing_url ) ) {
					$mapping_result = uimptr_append_mapping_export_row( $batch_id, $url, $existing_url );
					if ( is_wp_error( $mapping_result ) ) {
						wp_send_json_error( array(
							'message' => 'URL mapping export failed while handling skipped file: ' . $mapping_result->get_error_message(),
						) );
					}
				}
				$batch_skipped++;
				continue;
			}
		}
		
		// Import the image with metadata
		$attachment_id = uimptr_import_image_from_url( $url, $batch_id, $metadata, $preserve_dates, true, $allow_google_drive );
		
		if ( is_wp_error( $attachment_id ) ) {
			if ( uimptr_is_skippable_import_error( $attachment_id ) ) {
				$skip_message = uimptr_format_import_skip_message( $url, $attachment_id );
				$batch_stats['skipped_messages'][] = $skip_message;
				$batch_stats['skipped_messages'] = array_slice( $batch_stats['skipped_messages'], 0, 50 );
				$batch_skipped++;
				continue;
			}

			$errors[] = "Failed to import {$url}: " . $attachment_id->get_error_message();
			$batch_failed++;
			continue;
		}
		
		$batch_success++;
		
		// Note: Metadata (title, description, date) is already set in uimptr_import_image_from_url()
		// No additional updates needed here to avoid overriding the original date
		
		$results[] = array(
			'url' => $url,
			'attachment_id' => $attachment_id,
			'edit_link' => get_edit_post_link( $attachment_id )
		);

		$new_local_url = wp_get_attachment_url( $attachment_id );
		if ( ! empty( $new_local_url ) ) {
			$mapping_result = uimptr_append_mapping_export_row( $batch_id, $url, $new_local_url );
			if ( is_wp_error( $mapping_result ) ) {
				wp_send_json_error( array(
					'message' => 'URL mapping export failed while recording imported file: ' . $mapping_result->get_error_message(),
				) );
			}
		}
		
		// Small delay to prevent overwhelming the server and ensure proper ordering
		usleep( 300000 ); // 0.3 second
	}
	
	// Update cumulative stats
	$batch_stats['success'] += $batch_success;
	$batch_stats['failed'] += $batch_failed;
	$batch_stats['skipped'] += $batch_skipped;
	
	$processed = $end_index;
	$progress = ( $processed / $total_urls ) * 100;
	$is_complete = $processed >= $total_urls;
	
	// Save updated stats
	if ( !$is_complete ) {
		set_transient( uimptr_get_batch_stats_transient_key( $batch_id ), $batch_stats, 3600 ); // 1 hour
	}
	
	// Clean up temporary file if import is complete
	if ( $is_complete ) {
		$temp_file_info = get_transient( uimptr_get_temp_file_transient_key( $batch_id ) );
		if ( $temp_file_info && isset( $temp_file_info['path'] ) ) {
			if ( file_exists( $temp_file_info['path'] ) ) {
				uimptr_delete_file_with_logging( $temp_file_info['path'], 'completed batch temp file cleanup' );
			}
			delete_transient( uimptr_get_temp_file_transient_key( $batch_id ) );
		}
		
		// Clean up stats transient
		delete_transient( uimptr_get_batch_stats_transient_key( $batch_id ) );
		delete_transient( $urls_transient_key );
	}

	$mapping_info      = uimptr_get_mapping_export_info( $batch_id );
	$mapping_available = is_array( $mapping_info ) && ! empty( $mapping_info['path'] ) && file_exists( $mapping_info['path'] );
	$mapping_rows      = $mapping_available ? intval( $mapping_info['row_count'] ?? 0 ) : 0;
	
	wp_send_json_success( array(
		'batch_id' => $batch_id,
		'processed' => $processed,
		'total' => $total_urls,
		'progress' => round( $progress, 1 ),
		'is_complete' => $is_complete,
		'results' => $results,
		'errors' => $errors,
		'skipped_messages' => $batch_stats['skipped_messages'],
		'next_index' => $is_complete ? null : $end_index,
		'stats' => array(
			'success' => $batch_stats['success'],
			'failed' => $batch_stats['failed'],
			'skipped' => $batch_stats['skipped']
		),
		'mapping_available' => $mapping_available,
		'mapping_rows' => $mapping_rows,
		'mapping_batch_id' => $mapping_available ? $batch_id : '',
	) );
}
add_action( 'wp_ajax_uimptr_batch_import', 'uimptr_ajax_batch_import' );

/**
 * AJAX handler for cancelling batch import
 */
function uimptr_ajax_cancel_import() {
	uimptr_check_ajax_request();
	
	$batch_id = sanitize_text_field( $_POST['batch_id'] ?? '' );
	
	if ( empty( $batch_id ) ) {
		wp_send_json_error( 'Invalid batch ID' );
	}
	
	// Set cancel flag
	set_transient( uimptr_get_batch_cancel_transient_key( $batch_id ), true, 300 ); // 5 minutes
	
	// Clean up temporary file if this is an XML import
	$temp_file_info = get_transient( uimptr_get_temp_file_transient_key( $batch_id ) );
	if ( $temp_file_info && isset( $temp_file_info['path'] ) ) {
		if ( file_exists( $temp_file_info['path'] ) ) {
			uimptr_delete_file_with_logging( $temp_file_info['path'], 'cancelled batch temp file cleanup' );
		}
		delete_transient( uimptr_get_temp_file_transient_key( $batch_id ) );
	}

	// Clean up batch URL cache and mapping export for canceled imports.
	delete_transient( uimptr_get_batch_urls_transient_key( $batch_id ) );
	uimptr_cleanup_mapping_export( $batch_id, true );
	
	wp_send_json_success( array( 'message' => 'Import cancellation requested' ) );
}
add_action( 'wp_ajax_uimptr_cancel_import', 'uimptr_ajax_cancel_import' );

/**
 * Get the URL for downloading a mapping export.
 *
 * @param string $batch_id Batch ID.
 * @param string $nonce    Optional nonce to reuse.
 * @return string
 */
function uimptr_get_mapping_download_url( $batch_id, $nonce = '' ) {
	$nonce = '' !== $nonce ? $nonce : uimptr_create_ajax_nonce();

	return add_query_arg(
		array(
			'action'                      => 'uimptr_download_url_mapping_csv',
			uimptr_get_ajax_nonce_field() => $nonce,
			'batch_id'                    => sanitize_text_field( $batch_id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Get the requested mapping download batch ID.
 *
 * @return string
 */
function uimptr_get_mapping_download_batch_id() {
	$batch_id = '';
	if ( isset( $_REQUEST['batch_id'] ) ) {
		$batch_id = sanitize_text_field( wp_unslash( $_REQUEST['batch_id'] ) );
	}

	return $batch_id;
}

/**
 * Validate access to a mapping download request.
 *
 * @return true|WP_Error
 */
function uimptr_validate_mapping_download_request() {
	if ( ! uimptr_verify_ajax_request_nonce() ) {
		return new WP_Error(
			'invalid_mapping_download_nonce',
			__( 'The mapping download link has expired. Please run the import again.', 'url-image-importer' ),
			array( 'status' => 403 )
		);
	}

	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error(
			'mapping_download_permission_denied',
			__( 'Permission denied.', 'url-image-importer' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Return a WP_Error response for mapping downloads.
 *
 * @param WP_Error $error Error to display.
 * @return void
 */
function uimptr_die_with_mapping_download_error( WP_Error $error ) {
	$error_data = $error->get_error_data();
	$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? intval( $error_data['status'] ) : 500;

	wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => $status ) );
}

/**
 * Clear removable output buffers before streaming a download.
 *
 * @return void
 */
function uimptr_clean_download_output_buffers() {
	while ( ob_get_level() > 0 ) {
		$status = ob_get_status();
		$flags  = isset( $status['flags'] ) ? intval( $status['flags'] ) : 0;

		if ( $flags && defined( 'PHP_OUTPUT_HANDLER_REMOVABLE' ) && ! ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
			if ( defined( 'PHP_OUTPUT_HANDLER_CLEANABLE' ) && ( $flags & PHP_OUTPUT_HANDLER_CLEANABLE ) ) {
				@ob_clean();
			}
			break;
		}

		if ( ! @ob_end_clean() ) {
			break;
		}
	}
}

/**
 * Stream a URL mapping export as CSV.
 *
 * @param string $batch_id Batch ID.
 * @return void|WP_Error
 */
function uimptr_stream_mapping_csv_download( $batch_id ) {
	if ( empty( $batch_id ) ) {
		return new WP_Error(
			'invalid_mapping_download_batch',
			__( 'Invalid batch ID.', 'url-image-importer' ),
			array( 'status' => 400 )
		);
	}

	$mapping_info = uimptr_get_mapping_export_info( $batch_id );
	if ( ! is_array( $mapping_info ) || empty( $mapping_info['path'] ) ) {
		return new WP_Error(
			'mapping_export_expired',
			__( 'Mapping export is not available or has expired.', 'url-image-importer' ),
			array( 'status' => 404 )
		);
	}

	$mapping_path = realpath( $mapping_info['path'] );
	$temp_dir     = realpath( uimptr_get_local_temp_dir() );
	$temp_dir_prefix = false !== $temp_dir ? trailingslashit( wp_normalize_path( $temp_dir ) ) : false;
	$mapping_path_normalized = false !== $mapping_path ? wp_normalize_path( $mapping_path ) : false;

	if (
		false === $mapping_path ||
		false === $temp_dir_prefix ||
		false === $mapping_path_normalized ||
		0 !== strpos( $mapping_path_normalized, $temp_dir_prefix ) ||
		! is_file( $mapping_path ) ||
		! is_readable( $mapping_path )
	) {
		return new WP_Error(
			'invalid_mapping_export_path',
			__( 'Invalid mapping export path.', 'url-image-importer' ),
			array( 'status' => 400 )
		);
	}

	$handle = fopen( $mapping_path, 'rb' );
	if ( false === $handle ) {
		return new WP_Error(
			'mapping_export_open_failed',
			__( 'Failed to open mapping export file.', 'url-image-importer' ),
			array( 'status' => 500 )
		);
	}

	$download_filename = 'url-mapping-' . sanitize_file_name( $batch_id ) . '.csv';
	$file_size         = filesize( $mapping_path );

	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', '1' );
	}
	@ini_set( 'zlib.output_compression', 'Off' );

	uimptr_clean_download_output_buffers();

	nocache_headers();
	status_header( 200 );
	header_remove( 'Content-Length' );
	header_remove( 'Content-Encoding' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $download_filename . '"; filename*=UTF-8\'\'' . rawurlencode( $download_filename ) );
	header( 'Content-Transfer-Encoding: binary' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Pragma: public' );
	header( 'Expires: 0' );

	if ( false !== $file_size ) {
		header( 'Content-Length: ' . $file_size );
	}

	while ( ! feof( $handle ) ) {
		echo fread( $handle, 8192 );
	}
	fclose( $handle );
	flush();
	exit;
}

/**
 * Redirect legacy admin-ajax download URLs to the normal admin-post download route.
 */
function uimptr_ajax_download_url_mapping_csv() {
	$access = uimptr_validate_mapping_download_request();
	if ( is_wp_error( $access ) ) {
		uimptr_die_with_mapping_download_error( $access );
	}

	$batch_id = uimptr_get_mapping_download_batch_id();
	wp_safe_redirect( uimptr_get_mapping_download_url( $batch_id, uimptr_get_ajax_request_nonce() ) );
	exit;
}
add_action( 'wp_ajax_uimptr_download_url_mapping_csv', 'uimptr_ajax_download_url_mapping_csv' );

/**
 * Download URL mapping export as CSV.
 */
function uimptr_admin_post_download_url_mapping_csv() {
	$access = uimptr_validate_mapping_download_request();
	if ( is_wp_error( $access ) ) {
		uimptr_die_with_mapping_download_error( $access );
	}

	$result = uimptr_stream_mapping_csv_download( uimptr_get_mapping_download_batch_id() );
	if ( is_wp_error( $result ) ) {
		uimptr_die_with_mapping_download_error( $result );
	}
}
add_action( 'admin_post_uimptr_download_url_mapping_csv', 'uimptr_admin_post_download_url_mapping_csv' );

/**
 * Safely load XML content without allowing external entities or network access.
 *
 * @param string $xml_content Raw XML content.
 * @return SimpleXMLElement|WP_Error
 */
function uimptr_load_xml_string_securely( $xml_content ) {
	$xml_content = (string) $xml_content;

	// Reject DTD/entity declarations up front so XXE payloads never reach the parser.
	if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $xml_content ) ) {
		return new WP_Error( 'unsafe_xml', 'XML documents with DOCTYPE or ENTITY declarations are not allowed.' );
	}

	$previous_use_internal_errors = libxml_use_internal_errors( true );
	$restore_entity_loader        = false;
	$previous_entity_loader_state = null;

	// libxml_disable_entity_loader() is deprecated in PHP 8+, but still matters on older installs.
	if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
		$previous_entity_loader_state = libxml_disable_entity_loader( true );
		$restore_entity_loader        = true;
	}

	$xml = simplexml_load_string( $xml_content, 'SimpleXMLElement', LIBXML_NONET );

	libxml_clear_errors();
	libxml_use_internal_errors( $previous_use_internal_errors );

	if ( $restore_entity_loader ) {
		libxml_disable_entity_loader( $previous_entity_loader_state );
	}

	if ( false === $xml ) {
		return new WP_Error( 'invalid_xml', 'Failed to parse XML file. Please ensure it\'s a valid WordPress export file.' );
	}

	return $xml;
}

/**
 * Extract URLs and metadata from XML content
 */
function uimptr_extract_urls_from_xml_content( $xml_content, $preserve_dates = false, $force_reimport = false ) {
	if ( empty( $xml_content ) ) {
		return new WP_Error( 'empty_content', 'XML content is empty' );
	}
	
	// Load XML with XXE protections enabled.
	$xml = uimptr_load_xml_string_securely( $xml_content );
	if ( is_wp_error( $xml ) ) {
		return $xml;
	}
	
	// Register namespaces
	$xml->registerXPathNamespace( 'wp', 'http://wordpress.org/export/1.2/' );
	$xml->registerXPathNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
	
	// Find all attachment items in the XML
	$attachments = $xml->xpath( '//item[wp:post_type="attachment"]' );
	
	if ( empty( $attachments ) ) {
		return new WP_Error( 'no_attachments', 'No attachments found in the XML file.' );
	}
	
	$urls_data = array();
	$images_only = isset( $_POST['images_only'] ) && $_POST['images_only'];
	
	foreach ( $attachments as $attachment ) {
		// Extract attachment data
		$title = (string) $attachment->title;
		$guid = (string) $attachment->guid;
		
		// Get description from multiple possible sources
		$description = '';
		if ( isset( $attachment->children( 'content', true )->encoded ) ) {
			$description = (string) $attachment->children( 'content', true )->encoded;
		} elseif ( isset( $attachment->children( 'wp', true )->post_content ) ) {
			$description = (string) $attachment->children( 'wp', true )->post_content;
		} else {
			$description = (string) $attachment->description;
		}
		
		// Get post date from wp:post_date (preferred) or fallback to pubDate
		$post_date = '';
		if ( isset( $attachment->children( 'wp', true )->post_date ) ) {
			$post_date = (string) $attachment->children( 'wp', true )->post_date;
		} else {
			$post_date = (string) $attachment->pubDate;
		}
		
		// Get attachment URL from wp:attachment_url or guid
		$attachment_url = '';
		if ( isset( $attachment->children( 'wp', true )->attachment_url ) ) {
			$attachment_url = (string) $attachment->children( 'wp', true )->attachment_url;
		} else {
			$attachment_url = $guid;
		}
		
		// Skip if not an image URL (when images_only is checked)
		if ( $images_only && !uimptr_is_image_url( $attachment_url ) ) {
			continue;
		}
		
		// Skip if already exists (unless force_reimport is enabled).
		$existing_attachment_id = uimptr_get_existing_attachment_id_for_url( $attachment_url );
		if ( $existing_attachment_id && ! $force_reimport ) {
			error_log( "URL Image Importer: Skipping existing XML file from URL: {$attachment_url}" );
			continue;
		}
		
		// Debug log to track date extraction
		if ( !empty( $post_date ) ) {
			error_log( "URL Image Importer: Extracted date for {$title}: {$post_date}" );
		}
		
		$urls_data[] = array(
			'url' => $attachment_url,
			'metadata' => array(
				'title' => $title,
				'description' => $description,
				'date' => $post_date
			)
		);
	}
	
	// Only sort by date when preserving original dates
	if ( $preserve_dates ) {
		// Sort by date (newest first) to maintain chronological order in media library
		usort( $urls_data, function( $a, $b ) {
			$date_a = strtotime( $a['metadata']['date'] );
			$date_b = strtotime( $b['metadata']['date'] );
			
			// If dates can't be parsed, maintain original order
			if ( $date_a === false || $date_b === false ) {
				return 0;
			}
			
			// Sort newest first (descending order)
			$result = $date_b - $date_a;
			
			// Debug log to verify sorting
			if ( $result !== 0 ) {
				error_log( sprintf( 
					"URL Image Importer: Sorting %s (%s) vs %s (%s) = %d",
					$a['metadata']['title'] ?? 'Unknown',
					date( 'Y-m-d H:i:s', $date_a ),
					$b['metadata']['title'] ?? 'Unknown', 
					date( 'Y-m-d H:i:s', $date_b ),
					$result
				) );
			}
			
			return $result;
		});
	}
	
	return $urls_data;
}

/**
 * Extract URLs and metadata from XML file (legacy compatibility)
 */
function uimptr_extract_urls_from_xml( $xml_file_path ) {
	if ( !file_exists( $xml_file_path ) ) {
		return new WP_Error( 'file_not_found', 'XML file not found' );
	}
	
	$xml_content = file_get_contents( $xml_file_path );
	if ( $xml_content === false ) {
		return new WP_Error( 'file_read_error', 'Could not read XML file' );
	}
	
	return uimptr_extract_urls_from_xml_content( $xml_content );
}

/**
 * Extract URLs and metadata from CSV content
 */
function uimptr_extract_urls_from_csv_content( $csv_content, $preserve_dates = false, $force_reimport = false ) {
	if ( empty( $csv_content ) ) {
		return new WP_Error( 'empty_content', 'CSV content is empty' );
	}
	
	// Parse CSV content using a temporary stream to properly handle quoted fields
	$stream = fopen( 'php://temp', 'r+' );
	fwrite( $stream, $csv_content );
	rewind( $stream );
	
	// Get header row
	$headers = fgetcsv( $stream, 0, ',', '"', '\\' );
	if ( $headers === false ) {
		fclose( $stream );
		return new WP_Error( 'invalid_csv', 'Failed to parse CSV file.' );
	}

	$headers = array_map(
		function( $header ) {
			$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
			$header = strtolower( trim( $header ) );
			$header = preg_replace( '/\s+/', '_', $header );
			return $header;
		},
		$headers
	);
	
	$url_index = false;
	foreach ( array( 'url', 'image_url' ) as $candidate ) {
		$index = array_search( $candidate, $headers, true );
		if ( false !== $index ) {
			$url_index = $index;
			break;
		}
	}
	
	if ( $url_index === false ) {
		fclose( $stream );
		return new WP_Error( 'missing_url_column', 'CSV file must contain a "url" or "image url" column.' );
	}
	
	// Find other column indexes
	$title_index = array_search( 'title', $headers, true );
	$description_index = array_search( 'description', $headers, true );
	$alt_text_index = array_search( 'alt_text', $headers, true );
	$date_index = array_search( 'date', $headers, true );
	
	$urls_data = array();
	$images_only = isset( $_POST['images_only'] ) && $_POST['images_only'];
	
	// Read each row
	while ( ( $data = fgetcsv( $stream, 0, ',', '"', '\\' ) ) !== false ) {
		// Skip if not enough columns
		if ( count( $data ) <= $url_index ) {
			continue;
		}
		
		$url = trim( $data[$url_index] );
		
		// Skip empty URLs
		if ( empty( $url ) ) {
			continue;
		}
		
		// Skip if not an image URL candidate (when images_only is checked).
		if ( $images_only && ! uimptr_is_csv_image_import_candidate_url( $url ) ) {
			continue;
		}

		// Keep existing source URLs in the preview so the batch importer can
		// record URL mapping rows for deduped media.
		
		// Extract metadata
		$metadata = array();
		
		if ( $title_index !== false && isset( $data[$title_index] ) ) {
			$metadata['title'] = trim( $data[$title_index] );
		}
		
		if ( $description_index !== false && isset( $data[$description_index] ) ) {
			$metadata['description'] = trim( $data[$description_index] );
		}
		
		if ( $alt_text_index !== false && isset( $data[$alt_text_index] ) ) {
			$metadata['alt_text'] = trim( $data[$alt_text_index] );
		}
		
		if ( $date_index !== false && isset( $data[$date_index] ) ) {
			$metadata['date'] = trim( $data[$date_index] );
		}
		
		$urls_data[] = array(
			'url' => $url,
			'metadata' => $metadata
		);
	}
	
	fclose( $stream );
	
	if ( empty( $urls_data ) ) {
		return new WP_Error( 'no_valid_urls', 'No valid URLs found in the CSV file.' );
	}
	
	return $urls_data;
}

/**
 * Check if URL is an image
 */
function uimptr_is_image_url( $url ) {
	$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'tiff', 'ico' );
	$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
	
	// Check file extension first
	if ( in_array( $extension, $image_extensions ) ) {
		return true;
	}
	
	// Check for common image hosting services (no file extension needed)
	$image_services = array(
		'picsum.photos',
		'images.unsplash.com', 
		'source.unsplash.com',
		'via.placeholder.com',
		'placehold.it',
		'dummyimage.com',
		'lorempixel.com'
	);
	
	$parsed_url = parse_url( $url );
	$host = isset( $parsed_url['host'] ) ? strtolower( $parsed_url['host'] ) : '';
	
	foreach ( $image_services as $service ) {
		if ( strpos( $host, $service ) !== false ) {
			return true;
		}
	}
	
	return false;
}

/**
 * Check if a CSV row is a candidate for image import.
 *
 * Google Drive links are validated after download because public share URLs do not
 * expose reliable image extensions.
 *
 * @param string $url URL to check.
 * @return bool
 */
function uimptr_is_csv_image_import_candidate_url( $url ) {
	return uimptr_is_image_url( $url ) || uimptr_is_google_drive_url( $url );
}

/**
 * Normalize a source URL before storing or looking it up.
 *
 * @param string $url Source URL.
 * @return string
 */
function uimptr_normalize_source_url( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	if ( uimptr_is_google_drive_url( $url ) ) {
		$canonical_google_drive_url = uimptr_get_google_drive_canonical_url( $url );
		if ( '' !== $canonical_google_drive_url ) {
			return $canonical_google_drive_url;
		}
	}

	$normalized = esc_url_raw( $url );

	return '' !== $normalized ? $normalized : $url;
}

/**
 * Whether a URL can safely fall back to filename-based dedupe.
 *
 * Query strings and fragments often identify distinct remote assets even when
 * the basename is the same, so only plain URLs should use filename matching.
 *
 * @param string $url Source URL.
 * @return bool
 */
function uimptr_url_supports_filename_dedupe( $url ) {
	if ( uimptr_is_google_drive_url( $url ) ) {
		return false;
	}

	$query    = parse_url( $url, PHP_URL_QUERY );
	$fragment = parse_url( $url, PHP_URL_FRAGMENT );

	return empty( $query ) && empty( $fragment );
}

/**
 * Get attachment ID by exact original source URL.
 *
 * @param string $image_url Source URL to search for.
 * @return int Attachment ID or 0 if no match.
 */
function uimptr_get_attachment_id_by_source_url( $image_url ) {
	$normalized_url = uimptr_normalize_source_url( $image_url );

	if ( '' === $normalized_url ) {
		return 0;
	}

	global $wpdb;

	$query = $wpdb->prepare(
		"SELECT pm.post_id FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = '_uimptr_source_url'
		AND pm.meta_value = %s
		AND p.post_type = 'attachment'
		LIMIT 1",
		$normalized_url
	);

	return intval( $wpdb->get_var( $query ) );
}

/**
 * Find an existing attachment for a source URL.
 *
 * Prefer exact source URL matches. Only fall back to filename matching for
 * plain URLs with no query string or fragment.
 *
 * @param string $url Source URL.
 * @return int Attachment ID or 0 if no match.
 */
function uimptr_get_existing_attachment_id_for_url( $url ) {
	$existing_attachment_id = uimptr_get_attachment_id_by_source_url( $url );
	if ( $existing_attachment_id ) {
		return $existing_attachment_id;
	}

	if ( ! uimptr_url_supports_filename_dedupe( $url ) ) {
		return 0;
	}

	$url_path = parse_url( $url, PHP_URL_PATH );
	$filename = $url_path ? basename( $url_path ) : '';
	$filename = preg_replace( '/\?.*$/', '', $filename );

	if ( empty( $filename ) ) {
		return 0;
	}

	return uimptr_get_attachment_id_by_filename( $filename );
}

/**
 * Get attachment ID by filename.
 * Checks both the _wp_attached_file meta and the guid field.
 *
 * @param string $filename Filename to search for.
 * @return int Attachment ID or 0 if no match.
 */
function uimptr_get_attachment_id_by_filename( $filename ) {
	if ( empty( $filename ) ) {
		return 0;
	}
	
	global $wpdb;
	
	// Match the exact basename so "photo.jpg" does not match "my-photo.jpg".
	$meta_query = $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = '_wp_attached_file' 
		AND SUBSTRING_INDEX( pm.meta_value, '/', -1 ) = %s
		AND p.post_type = 'attachment'
		LIMIT 1",
		$filename
	);
	
	$result = intval( $wpdb->get_var( $meta_query ) );
	
	if ( $result ) {
		return $result;
	}
	
	// Fallback: match the exact basename from guid, ignoring any query string.
	$guid_query = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} 
		WHERE post_type = 'attachment' 
		AND SUBSTRING_INDEX( SUBSTRING_INDEX( guid, '?', 1 ), '/', -1 ) = %s
		LIMIT 1",
		$filename
	);
	
	$result = intval( $wpdb->get_var( $guid_query ) );
	
	return $result ?: 0;
}

/**
 * Check if attachment already exists by filename.
 *
 * @param string $filename Filename to search for.
 * @return bool
 */
function uimptr_attachment_exists( $filename ) {
	return uimptr_get_attachment_id_by_filename( $filename ) > 0;
}

/**
 * Store uploaded file temporarily with proper cleanup
 * Uses local storage to avoid Infinite Uploads cloud storage
 */
function uimptr_store_temp_file( $uploaded_file ) {
	// Get local temp directory (bypasses cloud storage)
	$temp_dir = uimptr_get_local_temp_dir();
	
	// Create temp directory if it doesn't exist
	if ( ! file_exists( $temp_dir ) ) {
		wp_mkdir_p( $temp_dir );
		
		// Add .htaccess to prevent direct access
		$htaccess_content = "Order Deny,Allow\nDeny from all\n";
		file_put_contents( $temp_dir . '/.htaccess', $htaccess_content );
		
		// Add index.php for extra security
		file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden' );
	}
	
	// Verify directory is writable
	if ( ! is_writable( $temp_dir ) ) {
		return new WP_Error( 'temp_dir_not_writable', 'Temporary directory is not writable: ' . $temp_dir );
	}
	
	// Generate unique filename with proper extension
	$file_extension = pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION );
	$file_extension = sanitize_file_name( $file_extension ); // Sanitize extension
	$temp_filename = 'import_' . wp_generate_password( 12, false ) . '_' . time();
	if ( ! empty( $file_extension ) ) {
		$temp_filename .= '.' . $file_extension;
	}
	$temp_file_path = $temp_dir . '/' . $temp_filename;
	
	// Move uploaded file to temp location
	if ( ! move_uploaded_file( $uploaded_file['tmp_name'], $temp_file_path ) ) {
		return new WP_Error( 'temp_file_error', 'Failed to store temporary file' );
	}
	
	// Store file info in transient for cleanup
	$file_info = array(
		'path' => $temp_file_path,
		'original_name' => $uploaded_file['name'],
		'created' => time()
	);
	
	$file_id = wp_generate_password( 16, false );
	set_transient( uimptr_get_temp_file_transient_key( $file_id ), $file_info, 2 * HOUR_IN_SECONDS );
	
	return array(
		'file_id' => $file_id,
		'path' => $temp_file_path
	);
}

/**
 * Cleanup temporary files (local storage only)
 */
function uimptr_cleanup_temp_files() {
	// Get the current temp directory
	$temp_dir = uimptr_get_local_temp_dir();
	
	if ( ! file_exists( $temp_dir ) ) {
		$temp_dir = '';
	}
	
	$current_time = time();

	if ( ! empty( $temp_dir ) ) {
		$cleanup_patterns = array(
			'xml_import_*.xml' => 2 * HOUR_IN_SECONDS,
			'import_*'         => 2 * HOUR_IN_SECONDS,
			'url_mapping_*.csv' => DAY_IN_SECONDS,
		);

		foreach ( $cleanup_patterns as $pattern => $max_age ) {
			$files = glob( trailingslashit( $temp_dir ) . $pattern );
			if ( empty( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$file_time = filemtime( $file );
				if ( false === $file_time ) {
					continue;
				}

				if ( $current_time - $file_time > $max_age ) {
					uimptr_delete_file_with_logging( $file, 'scheduled temp file cleanup' );
				}
			}
		}
	}

	// Clean up expired URL cache and mapping export transients.
	global $wpdb;

	$expired_transient_timeouts = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			WHERE ( option_name LIKE %s OR option_name LIKE %s )
			AND option_value < %d",
			'_transient_timeout_uimptr_mapping_%',
			'_transient_timeout_uimptr_urls_%',
			$current_time
		)
	);

	if ( ! empty( $expired_transient_timeouts ) ) {
		foreach ( $expired_transient_timeouts as $timeout_name ) {
			$transient_name = str_replace( '_transient_timeout_', '', $timeout_name );
			delete_transient( $transient_name );
		}
	}
}

/**
 * Get local temp directory (bypasses cloud storage)
 */
function uimptr_get_local_temp_dir() {
	// Try WordPress temp directory first (usually /tmp or similar)
	$wp_temp_dir = get_temp_dir();
	if ( is_writable( $wp_temp_dir ) ) {
		return $wp_temp_dir . 'url-image-importer-temp';
	}
	
	// Fallback to plugin directory
	return UIMPTR_PATH . '/temp';
}

/**
 * Prevent Infinite Uploads from processing our temp files
 */
function uimptr_exclude_temp_files_from_cloud( $exclude, $file ) {
	// Exclude our temp files from cloud uploads
	if ( strpos( $file, 'url-image-importer-temp' ) !== false ) {
		return true;
	}
	
	if ( strpos( $file, '/temp/xml_import_' ) !== false ) {
		return true;
	}
	
	return $exclude;
}

// Hook into Infinite Uploads if it's active
if ( function_exists( 'infinite_uploads_init' ) || class_exists( 'Infinite_Uploads' ) ) {
	add_filter( 'infinite_uploads_exclude_file', 'uimptr_exclude_temp_files_from_cloud', 10, 2 );
}

// Schedule cleanup
add_action( 'uimptr_cleanup_temp_files', 'uimptr_cleanup_temp_files' );

// Register cleanup schedule if not already scheduled
if ( ! wp_next_scheduled( 'uimptr_cleanup_temp_files' ) ) {
	wp_schedule_event( time(), 'hourly', 'uimptr_cleanup_temp_files' );
}

/**
 * Get root path of the uploads directory.
 */
function uimptr_get_upload_dir_root() {
	$upload_path = trim( get_option( 'upload_path' ) );

	if ( empty( $upload_path ) || 'wp-content/uploads' === $upload_path ) {
		$dir = UPLOADBLOGSDIR;
	} else {
		$dir = $upload_path;
	}
	// If multisite (and if not the main site in a post-MU network).
	if ( is_multisite() && ! ( is_main_network() && is_main_site() && defined( 'MULTISITE' ) ) ) {

		if ( get_site_option( 'ms_files_rewriting' ) && defined( 'UPLOADS' ) && ! ms_is_switched() ) {
			/*
			 * Handle the old-form ms-files.php rewriting if the network still has that enabled.
			 * When ms-files rewriting is enabled, then we only listen to UPLOADS when:
			 * 1) We are not on the main site in a post-MU network, as wp-content/uploads is used
			 *    there, and
			 * 2) We are not switched, as ms_upload_constants() hardcodes these constants to reflect
			 *    the original blog ID.
			 *
			 * Rather than UPLOADS, we actually use BLOGUPLOADDIR if it is set, as it is absolute.
			 * (And it will be set, see ms_upload_constants().) Otherwise, UPLOADS can be used, as
			 * as it is relative to ABSPATH. For the final piece: when UPLOADS is used with ms-files
			 * rewriting in multisite, the resulting URL is /files. (#WP22702 for background.)
			 */

			$dir = ABSPATH . untrailingslashit( UPLOADBLOGSDIR );
		}
	}

	$basedir = $dir;

	return $basedir;
}

/**
 * Update option after dismiss modal.
 */
function uimptr_ajax_subscribe_dismiss() {
	uimptr_check_ajax_request();

	update_user_option( get_current_user_id(), 'bfu_subscribe_notice_dismissed', 1 );
	wp_send_json_success();
}
add_action( 'wp_ajax_uimptr_subscribe_dismiss', 'uimptr_ajax_subscribe_dismiss' );

/**
 * Get data array of filescan results.
 *
 * @param false $is_chart If data should be formatted for chart.
 * @return array
 */
function uimptr_get_filetypes( $is_chart = false ) {

	$results = get_site_option( 'uimptr_file_scan' );
	if ( isset( $results['types'] ) ) {
		$types = $results['types'];
	} else {
		$types = array();
	}

	$data = array();
	foreach ( $types as $type => $meta ) {
		$data[ $type ] = (object) array(
			'color' => uimptr_get_file_type_format( $type, 'color' ),
			'label' => uimptr_get_file_type_format( $type, 'label' ),
			'size'  => $meta->size,
			'files' => $meta->files,
		);
	}

	$chart = array();
	if ( $is_chart ) {
		foreach ( $data as $item ) {
			$chart['datasets'][0]['data'][]            = $item->size;
			$chart['datasets'][0]['backgroundColor'][] = $item->color;

			/*
			* translators: %s: Total Files
			* translators: %s: File name
			* translators: %s: Total Files
			* translators: %s: search term
			*/
			$chart['labels'][]                         = $item->label . ': ' . sprintf( _n( '%1$s file totalling %2$s', '%1$s files totalling %2$s', $item->files, 'url-image-importer' ), number_format_i18n( $item->files ), size_format( $item->size, 1 ) );
		}

		$total_size     = array_sum( wp_list_pluck( $data, 'size' ) );
		$total_files    = array_sum( wp_list_pluck( $data, 'files' ) );

		/*
		* translators: %s: Total Files
		* translators: %s: Total Size
		* translators: %s: Total Files
		* translators: %s: Total Size
		*/
		$chart['total'] = sprintf( _n( '%1$s / %2$s File', '%1$s / %2$s Files', $total_files, 'url-image-importer' ), size_format( $total_size, 2 ), number_format_i18n( $total_files ) );

		return $chart;
	}

	return $data;
}

/**
 * Get HTML format details for a filetype.
 *
 * @param string $type File type.
 * @param string $key File Index.
 *
 * @return mixed
 */
function uimptr_get_file_type_format( $type, $key ) {
	$labels = array(
		'image'    => array(
			'color' => '#26A9E0',
			'label' => esc_html__( 'Images', 'url-image-importer' ),
		),
		'audio'    => array(
			'color' => '#00A167',
			'label' => esc_html__( 'Audio', 'url-image-importer' ),
		),
		'video'    => array(
			'color' => '#C035E2',
			'label' => esc_html__( 'Video', 'url-image-importer' ),
		),
		'document' => array(
			'color' => '#EE7C1E',
			'label' => esc_html__( 'Documents', 'url-image-importer' ),
		),
		'archive'  => array(
			'color' => '#EC008C',
			'label' => esc_html__( 'Archives', 'url-image-importer' ),
		),
		'code'     => array(
			'color' => '#EFED27',
			'label' => esc_html__( 'Code', 'url-image-importer' ),
		),
		'other'    => array(
			'color' => '#F1F1F1',
			'label' => esc_html__( 'Other', 'url-image-importer' ),
		),
	);

	if ( isset( $labels[ $type ] ) ) {
		return $labels[ $type ][ $key ];
	} else {
		return $labels['other'][ $key ];
	}
}

/**
 * Get the file type category for a given extension.
 *
 * @param string $filename File name.
 * @return string
 */
function uimptr_get_file_type( $filename ) {
	$extensions = array(
		'image'    => array( 'jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico', 'svg', 'svgz', 'webp' ),
		'audio'    => array( 'aac', 'ac3', 'aif', 'aiff', 'flac', 'm3a', 'm4a', 'm4b', 'mka', 'mp1', 'mp2', 'mp3', 'ogg', 'oga', 'ram', 'wav', 'wma' ),
		'video'    => array( '3g2', '3gp', '3gpp', 'asf', 'avi', 'divx', 'dv', 'flv', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'mpv', 'ogm', 'ogv', 'qt', 'rm', 'vob', 'wmv', 'webm' ),
		'document' => array(
			'log',
			'asc',
			'csv',
			'tsv',
			'txt',
			'doc',
			'docx',
			'docm',
			'dotm',
			'odt',
			'pages',
			'pdf',
			'xps',
			'oxps',
			'rtf',
			'wp',
			'wpd',
			'psd',
			'xcf',
			'swf',
			'key',
			'ppt',
			'pptx',
			'pptm',
			'pps',
			'ppsx',
			'ppsm',
			'sldx',
			'sldm',
			'odp',
			'numbers',
			'ods',
			'xls',
			'xlsx',
			'xlsm',
			'xlsb',
		),
		'archive'  => array( 'bz2', 'cab', 'dmg', 'gz', 'rar', 'sea', 'sit', 'sqx', 'tar', 'tgz', 'zip', '7z', 'data', 'bin', 'bak' ),
		'code'     => array( 'css', 'htm', 'html', 'php', 'js', 'md' ),
	);

	$ext = preg_replace( '/^.+?\.([^.]+)$/', '$1', $filename );
	if ( ! empty( $ext ) ) {
		$ext = strtolower( $ext );
		foreach ( $extensions as $type => $exts ) {
			if ( in_array( $ext, $exts, true ) ) {
				return $type;
			}
		}
	}

	return 'other';
}

/**
 * Reset promotional notices when plugin is deactivated
 * This allows banners to show again when the plugin is reactivated
 */
function uimptr_plugin_deactivation() {
	// Get all users who have dismissed notices
	global $wpdb;
	
	// Delete URL Image Importer specific notice dismissals for all users
	$wpdb->query(
		"DELETE FROM {$wpdb->usermeta} 
		WHERE meta_key LIKE 'uimptr_notice_%'"
	);
	
	// Optionally delete legacy notice dismissals too
	delete_metadata( 'user', 0, 'bfu_notice_dismissed', '', true );
	delete_metadata( 'user', 0, 'bfu_upgrade_notice_dismissed', '', true );
	delete_metadata( 'user', 0, 'bfu_subscribe_notice_dismissed', '', true );
}
register_deactivation_hook( __FILE__, 'uimptr_plugin_deactivation' );
