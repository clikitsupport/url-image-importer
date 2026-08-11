<?php
/**
 * Google Drive folder sync engine.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

use WP_Error;

/**
 * Watches public Google Drive folders and imports new images from them.
 *
 * The sync is deliberately **add-only**: it never deletes or modifies anything
 * already in the Media Library. Removing a file from Drive does not remove the
 * imported attachment, because a sync that could delete media would break live
 * posts whenever someone tidied a Drive folder.
 *
 * Each folder keeps a ledger of Drive file IDs it has already handled. That
 * ledger -- rather than the Media Library -- decides what is "new", so deleting
 * an imported attachment in WordPress does not cause it to reappear on the next
 * run.
 *
 * @since 1.3.0
 */
class GoogleDriveFolderSync {

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
	 * Maximum images imported per sync run, across all folders.
	 *
	 * Downloads are slow, so a large folder is spread over several runs rather
	 * than risking a timeout on the first one.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_LIMIT = 20;

	/**
	 * Give up on a file after this many failed import attempts.
	 *
	 * @var int
	 */
	const MAX_IMPORT_ATTEMPTS = 3;

	/**
	 * Folder enumerator.
	 *
	 * @var GoogleDriveFolderEnumerator
	 */
	protected $enumerator;

	/**
	 * Constructor.
	 *
	 * @param GoogleDriveFolderEnumerator|null $enumerator Optional enumerator.
	 */
	public function __construct( $enumerator = null ) {
		$this->enumerator = $enumerator ? $enumerator : new GoogleDriveFolderEnumerator();
	}

	/**
	 * Default shape of a stored folder record.
	 *
	 * @return array
	 */
	protected static function folder_defaults() {
		return array(
			'key'         => '',
			'url'         => '',
			'folder_id'   => '',
			'label'       => '',
			'enabled'     => true,
			'seen'        => array(),
			'failed'      => array(),
			'last_sync'   => 0,
			'last_status' => 'never',
			'last_error'  => '',
			'imported'    => 0,
			'truncated'   => false,
		);
	}

	/**
	 * Get all watched folders.
	 *
	 * @return array[] Folder records keyed by folder key.
	 */
	public static function get_folders() {
		$folders = get_option( self::OPTION_FOLDERS, array() );

		if ( ! is_array( $folders ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $folders as $key => $folder ) {
			if ( ! is_array( $folder ) ) {
				continue;
			}
			$folder          = array_merge( self::folder_defaults(), $folder );
			$folder['key']   = (string) $key;
			$normalized[ $key ] = $folder;
		}

		return $normalized;
	}

	/**
	 * Persist the folder list.
	 *
	 * @param array[] $folders Folder records.
	 * @return void
	 */
	public static function save_folders( $folders ) {
		update_option( self::OPTION_FOLDERS, $folders, false );
	}

	/**
	 * Get a single folder record.
	 *
	 * @param string $key Folder key.
	 * @return array|null
	 */
	public static function get_folder( $key ) {
		$folders = self::get_folders();

		return isset( $folders[ $key ] ) ? $folders[ $key ] : null;
	}

	/**
	 * Add a folder to the watch list.
	 *
	 * The folder is enumerated once up front so an unreachable or private link
	 * is rejected while the user is still looking at the screen, rather than
	 * failing silently on a later cron run.
	 *
	 * @param string $url   Folder share URL.
	 * @param string $label Optional display label.
	 * @return array|WP_Error The stored folder record, or an error.
	 */
	public function add_folder( $url, $label = '' ) {
		$folder_id = GoogleDriveFolderEnumerator::extract_folder_id( $url );
		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		$folders = self::get_folders();

		foreach ( $folders as $existing ) {
			if ( $existing['folder_id'] === $folder_id ) {
				return new WP_Error(
					'drive_folder_duplicate',
					__( 'That folder is already being watched.', 'url-image-importer' )
				);
			}
		}

		// Verify the folder is reachable before storing it.
		$listing = $this->enumerator->list_files( $url );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$key    = 'gdf_' . substr( md5( $folder_id ), 0, 12 );
		$record = array_merge(
			self::folder_defaults(),
			array(
				'key'       => $key,
				'url'       => esc_url_raw( $url ),
				'folder_id' => $folder_id,
				'label'     => '' !== $label ? sanitize_text_field( $label ) : $folder_id,
				'truncated' => ! empty( $listing['truncated'] ),
			)
		);

		$folders[ $key ] = $record;
		self::save_folders( $folders );

		return $record;
	}

	/**
	 * Remove a folder from the watch list.
	 *
	 * Imported attachments are left untouched.
	 *
	 * @param string $key Folder key.
	 * @return bool Whether a folder was removed.
	 */
	public static function remove_folder( $key ) {
		$folders = self::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return false;
		}

		unset( $folders[ $key ] );
		self::save_folders( $folders );

		return true;
	}

	/**
	 * Enable or disable syncing for a folder.
	 *
	 * @param string $key     Folder key.
	 * @param bool   $enabled Whether the folder should sync.
	 * @return bool Whether the folder was updated.
	 */
	public static function set_enabled( $key, $enabled ) {
		$folders = self::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return false;
		}

		$folders[ $key ]['enabled'] = (bool) $enabled;
		self::save_folders( $folders );

		return true;
	}

	/**
	 * Sync every enabled folder.
	 *
	 * @param int|null $batch_limit Maximum imports across all folders.
	 * @return array Summary of the run.
	 */
	public function sync_all( $batch_limit = null ) {
		$batch_limit = null === $batch_limit ? self::DEFAULT_BATCH_LIMIT : max( 1, (int) $batch_limit );

		$summary = array(
			'imported'  => 0,
			'failed'    => 0,
			'remaining' => 0,
			'errors'    => array(),
			'folders'   => 0,
		);

		foreach ( self::get_folders() as $key => $folder ) {
			if ( empty( $folder['enabled'] ) ) {
				continue;
			}

			if ( $summary['imported'] >= $batch_limit ) {
				// Out of budget for this run; report what is still outstanding.
				$summary['remaining'] += 1;
				continue;
			}

			$result = $this->sync_folder( $key, $batch_limit - $summary['imported'] );
			$summary['folders']++;

			if ( is_wp_error( $result ) ) {
				$summary['errors'][ $key ] = $result->get_error_message();
				continue;
			}

			$summary['imported']  += $result['imported'];
			$summary['failed']    += $result['failed'];
			$summary['remaining'] += $result['remaining'];
		}

		return $summary;
	}

	/**
	 * Sync a single folder.
	 *
	 * @param string   $key         Folder key.
	 * @param int|null $batch_limit Maximum imports for this folder.
	 * @return array|WP_Error Result summary, or an error if the folder could not be read.
	 */
	public function sync_folder( $key, $batch_limit = null ) {
		$folders = self::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return new WP_Error(
				'drive_folder_missing',
				__( 'That folder is no longer being watched.', 'url-image-importer' )
			);
		}

		$batch_limit = null === $batch_limit ? self::DEFAULT_BATCH_LIMIT : max( 1, (int) $batch_limit );
		$folder      = $folders[ $key ];

		$listing = $this->enumerator->list_files( '' !== $folder['url'] ? $folder['url'] : $folder['folder_id'] );

		if ( is_wp_error( $listing ) ) {
			// Record the failure loudly. A sync that cannot read its folder must
			// never look like a clean run that simply found nothing new.
			$folder['last_sync']   = time();
			$folder['last_status'] = 'error';
			$folder['last_error']  = $listing->get_error_message();
			$folders[ $key ]       = $folder;
			self::save_folders( $folders );

			return $listing;
		}

		$imported  = 0;
		$failed    = 0;
		$remaining = 0;

		foreach ( $listing['entries'] as $entry ) {
			$file_id = $entry['id'];

			// Already handled by a previous run.
			if ( isset( $folder['seen'][ $file_id ] ) ) {
				continue;
			}

			// Repeatedly unimportable (corrupt file, permission change, etc).
			if ( isset( $folder['failed'][ $file_id ] ) && $folder['failed'][ $file_id ] >= self::MAX_IMPORT_ATTEMPTS ) {
				continue;
			}

			if ( $imported >= $batch_limit ) {
				$remaining++;
				continue;
			}

			// Adopt anything already imported by hand so it is not duplicated.
			if ( $this->existing_attachment_id( $entry['url'] ) ) {
				$folder['seen'][ $file_id ] = time();
				continue;
			}

			$result = $this->import_file( $entry['url'] );

			if ( is_wp_error( $result ) ) {
				$attempts                     = isset( $folder['failed'][ $file_id ] ) ? (int) $folder['failed'][ $file_id ] : 0;
				$folder['failed'][ $file_id ] = $attempts + 1;
				$failed++;
				continue;
			}

			unset( $folder['failed'][ $file_id ] );
			$folder['seen'][ $file_id ] = time();
			$folder['imported']         = (int) $folder['imported'] + 1;
			$imported++;
		}

		$folder['last_sync']   = time();
		$folder['last_status'] = 'ok';
		$folder['last_error']  = '';
		$folder['truncated']   = ! empty( $listing['truncated'] );
		$folders[ $key ]       = $folder;
		self::save_folders( $folders );

		return array(
			'imported'  => $imported,
			'failed'    => $failed,
			'remaining' => $remaining,
			'total'     => $listing['total'],
			'skipped'   => count( $listing['skipped'] ),
			'truncated' => ! empty( $listing['truncated'] ),
		);
	}

	/**
	 * Look up an existing attachment for a source URL.
	 *
	 * Wraps the shared dedupe helper, which already canonicalizes Google Drive
	 * URLs, so a file imported by hand is not imported again here. Exposed as a
	 * method so tests can substitute it.
	 *
	 * @param string $url Source URL.
	 * @return int Attachment ID, or 0.
	 */
	protected function existing_attachment_id( $url ) {
		if ( ! function_exists( 'uimptr_get_existing_attachment_id_for_url' ) ) {
			return 0;
		}

		return (int) uimptr_get_existing_attachment_id_for_url( $url );
	}

	/**
	 * Import a single file into the Media Library.
	 *
	 * Delegates to the shared importer, which resolves the Drive URL, validates
	 * the downloaded bytes, and skips anything that is not a real image.
	 * Exposed as a method so tests can substitute it.
	 *
	 * @param string $url Drive file URL.
	 * @return int|WP_Error Attachment ID, or an error.
	 */
	protected function import_file( $url ) {
		if ( ! function_exists( 'uimptr_import_image_from_url' ) ) {
			return new WP_Error(
				'drive_importer_unavailable',
				__( 'The image importer is not available.', 'url-image-importer' )
			);
		}

		return uimptr_import_image_from_url( $url );
	}

	/**
	 * Whether any watched folder is currently in an error state.
	 *
	 * @return array[] Folders with a failing last run.
	 */
	public static function get_failing_folders() {
		$failing = array();

		foreach ( self::get_folders() as $key => $folder ) {
			if ( ! empty( $folder['enabled'] ) && 'error' === $folder['last_status'] ) {
				$failing[ $key ] = $folder;
			}
		}

		return $failing;
	}
}
