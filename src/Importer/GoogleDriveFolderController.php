<?php
/**
 * Admin wiring for Google Drive folder syncing.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

/**
 * Google Drive folder sync controller.
 *
 * @since 1.3.0
 */
class GoogleDriveFolderController extends CloudFolderController {

	/**
	 * Slug used for element IDs and AJAX action names.
	 *
	 * @var string
	 */
	const SLUG = 'drive';

	/**
	 * Cron hook fired on the sync schedule.
	 *
	 * @var string
	 */
	const CRON_HOOK = GoogleDriveFolderSync::CRON_HOOK;

	/**
	 * Option storing the sync interval.
	 *
	 * @var string
	 */
	const OPTION_INTERVAL = 'uimptr_drive_sync_interval';

	/**
	 * Sync class for this provider.
	 *
	 * @return string
	 */
	protected static function sync_class() {
		return GoogleDriveFolderSync::class;
	}

	/**
	 * Panel copy.
	 *
	 * @return array
	 */
	protected static function copy() {
		return array(
			'provider'    => __( 'Google Drive', 'url-image-importer' ),
			'heading'     => __( 'Sync a Google Drive Folder', 'url-image-importer' ),
			'intro'       => __( 'Watch a Google Drive folder and import new images into your Media Library automatically. No Google account connection, API key, or app setup is required.', 'url-image-importer' ),
			'sharing'     => __( 'In Google Drive, right-click the folder, choose Share, then set General access to "Anyone with the link".', 'url-image-importer' ),
			'placeholder' => 'https://drive.google.com/drive/folders/...',
		);
	}
}
