<?php
/**
 * Dropbox folder sync.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

/**
 * Watches Dropbox shared folders.
 *
 * All sync behaviour lives in {@see CloudFolderSync}; this class only supplies
 * the Dropbox specifics.
 *
 * @since 1.3.0
 */
class DropboxFolderSync extends CloudFolderSync {

	/**
	 * Option storing the watched folders.
	 *
	 * @var string
	 */
	const OPTION_FOLDERS = 'uimptr_dropbox_folders';

	/**
	 * Cron hook fired on the sync schedule.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'uimptr_dropbox_folder_sync';

	/**
	 * Human-readable provider name.
	 *
	 * @var string
	 */
	const PROVIDER_LABEL = 'Dropbox';

	/**
	 * Build the Dropbox folder enumerator.
	 *
	 * @return DropboxFolderEnumerator
	 */
	protected function make_enumerator() {
		return new DropboxFolderEnumerator();
	}

	/**
	 * Derive a stable identifier from a Dropbox shared folder URL.
	 *
	 * The link key and secure hash identify the folder; the rlkey is left out
	 * because it can be reissued without the folder changing.
	 *
	 * @param string $url Folder URL.
	 * @return string|\WP_Error
	 */
	public static function identify_folder( $url ) {
		$parts = DropboxFolderEnumerator::parse_folder_url( $url );

		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		return $parts['link_key'] . '/' . $parts['secure_hash'];
	}
}
