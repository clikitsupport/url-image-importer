<?php
/**
 * Dropbox shared folder enumerator.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

use WP_Error;

/**
 * Lists the files inside a Dropbox shared folder link.
 *
 * Dropbox renders shared folders in the browser rather than in the page HTML,
 * so the listing is read from the same JSON endpoint the folder page itself
 * calls. That endpoint is CSRF protected: a request has to present the token
 * Dropbox sets as a cookie on the folder page, both as a form field and as a
 * header. This class performs that two-step exchange.
 *
 * Because this is an internal endpoint rather than a published interface, it
 * carries no stability guarantee. Every failure is therefore surfaced as an
 * explicit error -- never as an empty listing -- so a folder that stops working
 * cannot be mistaken for a folder with nothing new in it.
 *
 * @since 1.3.0
 */
class DropboxFolderEnumerator {

	/**
	 * Listing endpoint used by the shared folder page.
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://www.dropbox.com/list_shared_link_folder_entries';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * Maximum pages to walk before giving up.
	 *
	 * Dropbox returns roughly 30 entries per page, so this allows a very large
	 * folder while still bounding a runaway pagination loop.
	 *
	 * @var int
	 */
	const MAX_PAGES = 40;

	/**
	 * Browser-like user agent, required for the folder page to hand back cookies.
	 *
	 * @var string
	 */
	const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

	/**
	 * Whether a URL is a Dropbox shared folder link.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_folder_url( $url ) {
		return function_exists( 'uimptr_is_dropbox_url' )
			&& uimptr_is_dropbox_url( $url )
			&& uimptr_is_dropbox_folder_url( $url );
	}

	/**
	 * Pull the link key, secure hash, and rlkey out of a shared folder URL.
	 *
	 * @param string $url Shared folder URL.
	 * @return array|WP_Error {
	 *     @type string $link_key    Folder link key.
	 *     @type string $secure_hash Folder secure hash.
	 *     @type string $rlkey       Link access key.
	 * }
	 */
	public static function parse_folder_url( $url ) {
		$url  = trim( (string) $url );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( ! preg_match( '#^/scl/fo/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)#', $path, $matches ) ) {
			return new WP_Error(
				'dropbox_folder_invalid_url',
				__( 'That does not look like a Dropbox shared folder link. Open the folder in Dropbox, choose Share, and copy the link.', 'url-image-importer' )
			);
		}

		$args  = array();
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			wp_parse_str( $query, $args );
		}

		return array(
			'link_key'    => $matches[1],
			'secure_hash' => $matches[2],
			'rlkey'       => isset( $args['rlkey'] ) ? preg_replace( '#[^A-Za-z0-9_-]#', '', $args['rlkey'] ) : '',
		);
	}

	/**
	 * Load the folder page to obtain session cookies and the CSRF token.
	 *
	 * @param string $folder_url Shared folder URL.
	 * @return array|WP_Error {
	 *     @type array  $cookies Cookies to replay on the listing request.
	 *     @type string $token   CSRF token.
	 * }
	 */
	protected function start_session( $folder_url ) {
		$response = wp_remote_get(
			$folder_url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'user-agent'  => self::USER_AGENT,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'dropbox_folder_request_failed',
				sprintf(
					/* translators: %s: error message from the HTTP request. */
					__( 'Could not reach Dropbox: %s', 'url-image-importer' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code || 403 === $code ) {
			return new WP_Error(
				'dropbox_folder_not_accessible',
				__( 'Dropbox would not open that folder. Check the link, and make sure it is shared with "Anyone with the link".', 'url-image-importer' )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'dropbox_folder_request_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Dropbox returned an unexpected response (HTTP %d). This is usually temporary.', 'url-image-importer' ),
					$code
				)
			);
		}

		$cookies = isset( $response['cookies'] ) && is_array( $response['cookies'] ) ? $response['cookies'] : array();
		$token   = '';

		foreach ( $cookies as $cookie ) {
			$name = isset( $cookie->name ) ? $cookie->name : '';
			if ( 't' === $name || '__Host-js_csrf' === $name ) {
				$token = isset( $cookie->value ) ? $cookie->value : '';
			}
		}

		if ( '' === $token ) {
			return new WP_Error(
				'dropbox_folder_no_token',
				__( 'Dropbox did not return the token needed to read this folder. This usually means Dropbox changed how shared folders are loaded.', 'url-image-importer' )
			);
		}

		return array(
			'cookies' => $cookies,
			'token'   => $token,
		);
	}

	/**
	 * Request a single page of folder entries.
	 *
	 * @param array  $link    Parsed folder link parts.
	 * @param array  $session Cookies and CSRF token.
	 * @param string $voucher Pagination voucher from the previous page.
	 * @return array|WP_Error Decoded response.
	 */
	protected function fetch_page( $link, $session, $voucher = '' ) {
		$body = array(
			'link_key'    => $link['link_key'],
			'link_type'   => 's',
			'secure_hash' => $link['secure_hash'],
			'rlkey'       => $link['rlkey'],
			'sub_path'    => '',
			't'           => $session['token'],
		);

		if ( '' !== $voucher ) {
			$body['voucher'] = $voucher;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => self::USER_AGENT,
				'cookies'     => $session['cookies'],
				'headers'     => array(
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'X-CSRF-Token'  => $session['token'],
					'Accept'        => 'application/json',
					'Referer'       => 'https://www.dropbox.com/scl/fo/' . $link['link_key'] . '/' . $link['secure_hash'],
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'dropbox_folder_request_failed',
				sprintf(
					/* translators: %s: error message from the HTTP request. */
					__( 'Could not reach Dropbox: %s', 'url-image-importer' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'dropbox_folder_listing_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Dropbox refused to list this folder (HTTP %d). The link may have been turned off, or Dropbox may have changed how shared folders are read.', 'url-image-importer' ),
					$code
				)
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
			return new WP_Error(
				'dropbox_folder_parse_failed',
				__( 'The folder listing could not be read. Dropbox may have changed how shared folders are loaded.', 'url-image-importer' )
			);
		}

		return $decoded;
	}

	/**
	 * Convert a raw Dropbox entry into an import candidate.
	 *
	 * @param array $entry Raw entry.
	 * @return array|null
	 */
	protected function normalize_entry( $entry ) {
		if ( ! is_array( $entry ) || ! empty( $entry['is_dir'] ) ) {
			return null;
		}

		$href = isset( $entry['href'] ) ? (string) $entry['href'] : '';
		$id   = isset( $entry['file_id'] ) ? (string) $entry['file_id'] : '';
		$name = isset( $entry['filename'] ) ? (string) $entry['filename'] : '';

		if ( '' === $href ) {
			return null;
		}

		return array(
			// Dropbox exposes a stable file id, which makes a far better ledger
			// key than the URL -- share links can be reissued.
			'id'   => '' !== $id ? $id : $href,
			'name' => $name,
			'url'  => $href,
		);
	}

	/**
	 * List the image files in a Dropbox shared folder.
	 *
	 * @param string $folder_url Shared folder URL.
	 * @return array|WP_Error {
	 *     @type array[] $entries   Image entries (id, name, url).
	 *     @type array[] $skipped   Non-image entries that were filtered out.
	 *     @type int     $total     Total entries seen.
	 *     @type bool    $truncated Whether pagination was cut short.
	 * }
	 */
	public function list_files( $folder_url ) {
		$link = self::parse_folder_url( $folder_url );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$session = $this->start_session( $folder_url );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$all       = array();
		$voucher   = '';
		$truncated = false;
		$pages     = 0;

		do {
			$page = $this->fetch_page( $link, $session, $voucher );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			foreach ( $page['entries'] as $entry ) {
				$normalized = $this->normalize_entry( $entry );
				if ( $normalized ) {
					$all[] = $normalized;
				}
			}

			$voucher  = ! empty( $page['next_request_voucher'] ) ? (string) $page['next_request_voucher'] : '';
			$has_more = ! empty( $page['has_more_entries'] ) && '' !== $voucher;
			$pages++;

			if ( $has_more && $pages >= self::MAX_PAGES ) {
				$truncated = true;
				break;
			}
		} while ( $has_more );

		$images  = array();
		$skipped = array();
		foreach ( $all as $entry ) {
			if ( GoogleDriveFolderEnumerator::is_image_filename( $entry['name'] ) ) {
				$images[] = $entry;
			} else {
				$skipped[] = $entry;
			}
		}

		return array(
			'entries'   => $images,
			'skipped'   => $skipped,
			'total'     => count( $all ),
			'truncated' => $truncated,
		);
	}
}
