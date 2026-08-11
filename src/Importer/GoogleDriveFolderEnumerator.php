<?php
/**
 * Google Drive public folder enumerator.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

use WP_Error;

/**
 * Lists the files inside a publicly shared Google Drive folder.
 *
 * This reads Google's `embeddedfolderview` endpoint -- the same surface Drive
 * exposes for iframe embeds -- so no API key, OAuth client, or Google Cloud
 * project is required. The trade-off is that the response is HTML intended for
 * display rather than a documented data API, so every parse result is treated
 * as untrusted and the caller is told explicitly when a listing could not be
 * read (see the `drive_folder_parse_failed` error).
 *
 * The folder must be shared as "Anyone with the link". Folders that are private
 * or do not exist both return HTTP 404.
 *
 * @since 1.3.0
 */
class GoogleDriveFolderEnumerator {

	/**
	 * Folder listing endpoint.
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://drive.google.com/embeddedfolderview';

	/**
	 * Entry counts at or above this are reported as possibly truncated.
	 *
	 * The endpoint has no pagination -- whatever a single request returns is all
	 * that can be retrieved -- and community reports put the ceiling near 500.
	 * Rather than hard-coding a limit we do not control, a listing that lands on
	 * a suspiciously round count is flagged so the user can split the folder.
	 *
	 * @var int
	 */
	const TRUNCATION_SUSPECT_AT = 500;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * Extract the folder ID from a Google Drive folder URL.
	 *
	 * Accepts the share URLs Drive produces, for example:
	 * - https://drive.google.com/drive/folders/ID?usp=sharing
	 * - https://drive.google.com/drive/u/0/folders/ID
	 * - https://drive.google.com/open?id=ID
	 * - https://drive.google.com/embeddedfolderview?id=ID
	 * A bare folder ID is also accepted.
	 *
	 * @param string $url Folder URL or ID.
	 * @return string|WP_Error Folder ID, or WP_Error when it cannot be read.
	 */
	public static function extract_folder_id( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error(
				'drive_folder_invalid_url',
				__( 'Enter a Google Drive folder link.', 'url-image-importer' )
			);
		}

		// A bare ID with no URL structure around it.
		if ( preg_match( '#^[A-Za-z0-9_-]{10,}$#', $url ) ) {
			return $url;
		}

		if ( preg_match( '#/folders/([A-Za-z0-9_-]+)#', $url, $matches ) ) {
			return $matches[1];
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			$args = array();
			wp_parse_str( $query, $args );
			if ( ! empty( $args['id'] ) && preg_match( '#^[A-Za-z0-9_-]+$#', $args['id'] ) ) {
				return $args['id'];
			}
		}

		return new WP_Error(
			'drive_folder_invalid_url',
			__( 'That does not look like a Google Drive folder link. Open the folder in Drive and copy the link from your browser.', 'url-image-importer' )
		);
	}

	/**
	 * Extract the resource key from a folder URL, when present.
	 *
	 * Newer Drive share links may carry a resource key alongside the ID.
	 *
	 * @param string $url Folder URL.
	 * @return string Resource key, or an empty string.
	 */
	public static function extract_resource_key( $url ) {
		$query = wp_parse_url( (string) $url, PHP_URL_QUERY );

		if ( ! $query ) {
			return '';
		}

		$args = array();
		wp_parse_str( $query, $args );

		if ( empty( $args['resourcekey'] ) || ! preg_match( '#^[A-Za-z0-9_-]+$#', $args['resourcekey'] ) ) {
			return '';
		}

		return $args['resourcekey'];
	}

	/**
	 * Build the listing URL for a folder.
	 *
	 * @param string $folder_id    Folder ID.
	 * @param string $resource_key Optional resource key.
	 * @return string
	 */
	public static function build_listing_url( $folder_id, $resource_key = '' ) {
		$args = array( 'id' => $folder_id );

		if ( '' !== $resource_key ) {
			$args['resourcekey'] = $resource_key;
		}

		return add_query_arg( $args, self::ENDPOINT );
	}

	/**
	 * Parse a folder listing page into file entries.
	 *
	 * The file ID is read from the entry container, falling back to the file
	 * link, so a change to either one alone does not break enumeration.
	 *
	 * @param string $html Listing HTML.
	 * @return array[]|WP_Error List of entries, or WP_Error if unparseable.
	 */
	public static function parse_listing( $html ) {
		$html = (string) $html;

		preg_match_all( '#<div class="flip-entry"[^>]*id="entry-([A-Za-z0-9_-]+)"#', $html, $id_matches );
		$ids = ! empty( $id_matches[1] ) ? $id_matches[1] : array();

		if ( empty( $ids ) ) {
			// Fall back to the per-file links before declaring failure.
			preg_match_all( '#/file/d/([A-Za-z0-9_-]+)/view#', $html, $href_matches );
			$ids = ! empty( $href_matches[1] ) ? array_values( array_unique( $href_matches[1] ) ) : array();
		}

		if ( empty( $ids ) ) {
			// A real listing always renders its container, even when empty.
			if ( false !== strpos( $html, 'flip-entries' ) || false !== strpos( $html, 'id="flip-contents"' ) ) {
				return array();
			}

			return new WP_Error(
				'drive_folder_parse_failed',
				__( 'The folder listing could not be read. Google may have changed the page, or the folder may no longer be shared publicly.', 'url-image-importer' )
			);
		}

		preg_match_all( '#flip-entry-title">([^<]*)<#', $html, $name_matches );
		$names = ! empty( $name_matches[1] ) ? $name_matches[1] : array();

		$entries = array();
		foreach ( $ids as $index => $file_id ) {
			$name = isset( $names[ $index ] ) ? html_entity_decode( $names[ $index ], ENT_QUOTES, 'UTF-8' ) : '';

			$entries[] = array(
				'id'   => $file_id,
				'name' => trim( $name ),
				// The exact URL shape uimptr_extract_google_drive_file_id() parses,
				// so entries feed straight into the existing import path.
				'url'  => 'https://drive.google.com/file/d/' . $file_id . '/view',
			);
		}

		return $entries;
	}

	/**
	 * Whether a filename looks like an image this plugin can import.
	 *
	 * This is a cheap pre-filter only. Downloaded files are still validated by
	 * their actual contents during import.
	 *
	 * @param string $filename Filename from the listing.
	 * @return bool
	 */
	public static function is_image_filename( $filename ) {
		$extension = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

		if ( '' === $extension ) {
			return false;
		}

		$image_extensions = array(
			'jpg',
			'jpeg',
			'jpe',
			'png',
			'gif',
			'bmp',
			'tif',
			'tiff',
			'ico',
			'svg',
			'svgz',
			'webp',
			'avif',
			'heic',
			'heif',
		);

		return in_array( $extension, $image_extensions, true );
	}

	/**
	 * List the image files in a public Drive folder.
	 *
	 * @param string $folder_url Folder URL or ID.
	 * @return array|WP_Error {
	 *     @type array[] $entries   Image entries (id, name, url).
	 *     @type array[] $skipped   Non-image entries that were filtered out.
	 *     @type int     $total     Total entries returned by Google.
	 *     @type bool    $truncated Whether the listing may have been cut off.
	 * }
	 */
	public function list_files( $folder_url ) {
		$folder_id = self::extract_folder_id( $folder_url );
		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		$listing_url = self::build_listing_url( $folder_id, self::extract_resource_key( $folder_url ) );

		$response = wp_remote_get(
			$listing_url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'drive_folder_request_failed',
				sprintf(
					/* translators: %s: error message from the HTTP request. */
					__( 'Could not reach Google Drive: %s', 'url-image-importer' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return new WP_Error(
				'drive_folder_not_accessible',
				__( 'Google Drive could not find that folder. Check the link, and make sure the folder is shared with "Anyone with the link".', 'url-image-importer' )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'drive_folder_request_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google Drive returned an unexpected response (HTTP %d). This is usually temporary.', 'url-image-importer' ),
					$code
				)
			);
		}

		$entries = self::parse_listing( wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$images  = array();
		$skipped = array();
		foreach ( $entries as $entry ) {
			if ( self::is_image_filename( $entry['name'] ) ) {
				$images[] = $entry;
			} else {
				$skipped[] = $entry;
			}
		}

		return array(
			'entries'   => $images,
			'skipped'   => $skipped,
			'total'     => count( $entries ),
			'truncated' => count( $entries ) >= self::TRUNCATION_SUSPECT_AT,
		);
	}
}
