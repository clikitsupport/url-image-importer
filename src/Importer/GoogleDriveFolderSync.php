<?php
/**
 * Google Drive folder sync.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

/**
 * Watches publicly shared Google Drive folders.
 *
 * All sync behaviour lives in {@see CloudFolderSync}; this class only supplies
 * the Drive specifics.
 *
 * @since 1.3.0
 */
class GoogleDriveFolderSync extends CloudFolderSync {

	/**
	 * Option storing the watched folders.
	 *
	 * @var string
	 */
	const OPTION_FOLDERS = 'uimptr_drive_folders';

	/**
	 * Cron hook fired on the sync schedule.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'uimptr_drive_folder_sync';

	/**
	 * Human-readable provider name.
	 *
	 * @var string
	 */
	const PROVIDER_LABEL = 'Google Drive';

	/**
	 * Build the Google Drive folder enumerator.
	 *
	 * @return GoogleDriveFolderEnumerator
	 */
	protected function make_enumerator() {
		return new GoogleDriveFolderEnumerator();
	}

	/**
	 * Derive the Drive folder ID from a share URL.
	 *
	 * @param string $url Folder URL.
	 * @return string|\WP_Error
	 */
	public static function identify_folder( $url ) {
		return GoogleDriveFolderEnumerator::extract_folder_id( $url );
	}
}
