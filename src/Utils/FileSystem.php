<?php
/**
 * FileSystem Utility Class
 *
 * @package UrlImageImporter\Utils
 */

namespace UrlImageImporter\Utils;

/**
 * Class FileSystem
 *
 * Handles file system operations including temp file management.
 */
class FileSystem {

	/**
	 * Delete a temp file while logging failures instead of suppressing them.
	 *
	 * @param string $file_path Absolute file path.
	 * @param string $context   Cleanup context for diagnostics.
	 * @return bool
	 */
	private static function delete_file_with_logging( $file_path, $context = '' ) {
		if ( \function_exists( '\uimptr_delete_file_with_logging' ) ) {
			return \uimptr_delete_file_with_logging( $file_path, $context );
		}

		$file_path = (string) $file_path;
		if ( '' === $file_path ) {
			return false;
		}

		\clearstatcache( true, $file_path );
		if ( ! \file_exists( $file_path ) ) {
			return true;
		}

		$unlink_error = '';
		\set_error_handler(
			function( $severity, $message ) use ( &$unlink_error ) {
				$unlink_error = (string) $message;
				return true;
			}
		);

		try {
			$deleted = \unlink( $file_path );
		} finally {
			\restore_error_handler();
		}

		\clearstatcache( true, $file_path );
		if ( $deleted || ! \file_exists( $file_path ) ) {
			return true;
		}

		$context = trim( (string) $context );
		$details = '' !== $unlink_error ? $unlink_error : 'Unknown filesystem error.';
		\error_log(
			sprintf(
				'URL Image Importer: Failed to delete file%s: %s. %s',
				'' !== $context ? ' during ' . $context : '',
				$file_path,
				$details
			)
		);

		return false;
	}

	/**
	 * Get the local temp directory path (bypasses cloud storage)
	 *
	 * @return string
	 */
	public static function get_local_temp_dir() {
		$upload_dir = \wp_upload_dir();
		$base_dir = $upload_dir['basedir'];
		
		// Create a temp directory in uploads that won't be synced to cloud
		return $base_dir . '/uimptr-temp';
	}

	/**
	 * Store uploaded file in local temp directory
	 *
	 * @param array $uploaded_file The uploaded file array from $_FILES
	 * @return array|\WP_Error Array with file_id and path, or WP_Error on failure
	 */
	public static function store_temp_file( $uploaded_file ) {
		// Get local temp directory (bypasses cloud storage)
		$temp_dir = self::get_local_temp_dir();
		
		// Create temp directory if it doesn't exist
		if ( ! file_exists( $temp_dir ) ) {
			\wp_mkdir_p( $temp_dir );
			
			// Add .htaccess to prevent direct access
			$htaccess_content = "Order Deny,Allow\nDeny from all\n";
			file_put_contents( $temp_dir . '/.htaccess', $htaccess_content );
			
			// Add index.php for extra security
			file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden' );
		}
		
		// Verify directory is writable
		if ( ! is_writable( $temp_dir ) ) {
			return new \WP_Error( 'temp_dir_not_writable', 'Temporary directory is not writable: ' . $temp_dir );
		}
		
		// Generate unique filename with proper extension
		$file_extension = pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION );
		$file_extension = sanitize_file_name( $file_extension ); // Sanitize extension
		$temp_filename = 'import_' . \wp_generate_password( 12, false ) . '_' . time();
		if ( ! empty( $file_extension ) ) {
			$temp_filename .= '.' . $file_extension;
		}
		$temp_file_path = $temp_dir . '/' . $temp_filename;
		
		// Move uploaded file to temp location
		if ( ! move_uploaded_file( $uploaded_file['tmp_name'], $temp_file_path ) ) {
			return new \WP_Error( 'temp_file_error', 'Failed to store temporary file' );
		}
		
		// Store file info in transient for cleanup
		$file_info = array(
			'path' => $temp_file_path,
			'original_name' => $uploaded_file['name'],
			'created' => time()
		);
		
		$file_id = \wp_generate_password( 16, false );
		$transient_key = \function_exists( '\uimptr_get_temp_file_transient_key' )
			? \uimptr_get_temp_file_transient_key( $file_id )
			: 'uimptr_temp_file_' . (int) \get_current_user_id() . '_' . sanitize_key( (string) $file_id );
		\set_transient( $transient_key, $file_info, 2 * HOUR_IN_SECONDS );
		
		return array(
			'file_id' => $file_id,
			'path' => $temp_file_path
		);
	}

	/**
	 * Cleanup temporary files (local storage only)
	 *
	 * @return void
	 */
	public static function cleanup_temp_files() {
		// Get the current temp directory
		$temp_dir = self::get_local_temp_dir();
		
		if ( ! file_exists( $temp_dir ) ) {
			return;
		}
		
		// Get all import files (xml, csv, or any extension)
		$files = glob( $temp_dir . '/import_*' );
		if ( ! $files ) {
			return;
		}
		
		// Delete files older than 2 hours
		$cutoff_time = time() - ( 2 * HOUR_IN_SECONDS );
		
		foreach ( $files as $file ) {
			if ( file_exists( $file ) && filemtime( $file ) < $cutoff_time ) {
				self::delete_file_with_logging( $file, 'utility temp file cleanup' );
			}
		}
	}

	/**
	 * Exclude temp files from cloud storage sync
	 *
	 * @param bool $exclude Whether to exclude
	 * @param string $file File path
	 * @return bool
	 */
	public static function exclude_temp_files_from_cloud( $exclude, $file ) {
		$temp_dir = self::get_local_temp_dir();
		
		// Exclude if file is in our temp directory
		if ( strpos( $file, $temp_dir ) !== false ) {
			return true;
		}
		
		return $exclude;
	}

	/**
	 * Get upload directory root path
	 *
	 * @return string
	 */
	public static function get_upload_dir_root() {
		$upload_path = trim( \get_option( 'upload_path' ) );

		if ( empty( $upload_path ) || 'wp-content/uploads' === $upload_path ) {
			$dir = defined( 'UPLOADBLOGSDIR' ) ? UPLOADBLOGSDIR : \wp_upload_dir()['path'];
		} else {
			$dir = $upload_path;
		}

		// If multisite (and if not the main site in a post-MU network).
		if ( \is_multisite() && ! ( \is_main_network() && \is_main_site() && defined( 'MULTISITE' ) ) ) {
			if ( \get_site_option( 'ms_files_rewriting' ) && defined( 'UPLOADS' ) && ! \ms_is_switched() ) {
				$dir = ABSPATH . untrailingslashit( defined( 'UPLOADBLOGSDIR' ) ? UPLOADBLOGSDIR : '' );
			}
		}

		return $dir;
	}
}
