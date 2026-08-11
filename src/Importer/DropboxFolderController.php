<?php
/**
 * Admin wiring for Dropbox folder syncing.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

/**
 * Dropbox folder sync controller.
 *
 * @since 1.3.0
 */
class DropboxFolderController extends CloudFolderController {

	/**
	 * Slug used for element IDs and AJAX action names.
	 *
	 * @var string
	 */
	const SLUG = 'dropbox';

	/**
	 * Cron hook fired on the sync schedule.
	 *
	 * @var string
	 */
	const CRON_HOOK = DropboxFolderSync::CRON_HOOK;

	/**
	 * Option storing the sync interval.
	 *
	 * @var string
	 */
	const OPTION_INTERVAL = 'uimptr_dropbox_sync_interval';

	/**
	 * Sync class for this provider.
	 *
	 * @return string
	 */
	protected static function sync_class() {
		return DropboxFolderSync::class;
	}

	/**
	 * Panel copy.
	 *
	 * The sharing note steers people to a view link on purpose. The link is
	 * stored in the database and used by a background job, and an edit link
	 * would hand that job the ability to change or delete the Dropbox folder
	 * when all it ever needs to do is read from it.
	 *
	 * @return array
	 */
	protected static function copy() {
		return array(
			'heading'     => __( 'Sync a Dropbox Folder', 'url-image-importer' ),
			'intro'       => __( 'Watch a Dropbox folder and import new images into your Media Library automatically. No Dropbox account connection, app key, or API setup is required.', 'url-image-importer' ),
			'sharing'     => __( 'In Dropbox, choose Share on the folder and copy the "Anyone with the link — can view" link. An edit link is not needed, and grants more access than importing requires.', 'url-image-importer' ),
			'placeholder' => 'https://www.dropbox.com/scl/fo/...',
		);
	}
}
