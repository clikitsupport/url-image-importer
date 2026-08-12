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
abstract class CloudFolderSync {

	/**
	 * Option storing the watched folders. Subclasses must override.
	 *
	 * @var string
	 */
	const OPTION_FOLDERS = '';

	/**
	 * Cron hook fired on the sync schedule. Subclasses must override.
	 *
	 * @var string
	 */
	const CRON_HOOK = '';

	/**
	 * Human-readable provider name, used in messages. Subclasses must override.
	 *
	 * @var string
	 */
	const PROVIDER_LABEL = '';

	/**
	 * Maximum images imported per sync run, across all folders.
	 *
	 * Set high enough that the time budget, not this count, is what normally
	 * ends a run -- importing an image (download plus thumbnail generation)
	 * takes a few seconds, so time is the real limit. This count is a backstop
	 * against a folder of very small, very fast images running unbounded.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_LIMIT = 200;

	/**
	 * Give up on a file after this many failed import attempts.
	 *
	 * @var int
	 */
	const MAX_IMPORT_ATTEMPTS = 3;

	/**
	 * Seconds a scheduled run may spend importing.
	 *
	 * @var int
	 */
	const CRON_TIME_BUDGET = 45;

	/**
	 * Seconds an interactive ("Check now") run may spend importing.
	 *
	 * Deliberately short: the browser auto-continues chunk after chunk until the
	 * folder is done, so a short budget makes the on-screen count climb every
	 * few seconds (responsive) instead of after one long, silent request. The
	 * cached listing keeps each chunk cheap, and progress is checkpointed as it
	 * goes, so a chunk that is interrupted loses nothing.
	 *
	 * @var int
	 */
	const INTERACTIVE_TIME_BUDGET = 12;

	/**
	 * Persist progress after this many imports.
	 *
	 * A run can be killed part way through by a request timeout, so the ledger
	 * is written as it goes rather than only at the end. Without this, an
	 * interrupted run loses every file it just imported and downloads them all
	 * again on the next pass.
	 *
	 * @var int
	 */
	const CHECKPOINT_EVERY = 5;

	/**
	 * How long a folder's sync lock is held, in seconds.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 600;

	/**
	 * Folder enumerator.
	 *
	 * @var GoogleDriveFolderEnumerator
	 */
	protected $enumerator;

	/**
	 * Constructor.
	 *
	 * @param object|null $enumerator Optional enumerator, mainly for tests.
	 */
	public function __construct( $enumerator = null ) {
		$this->enumerator = $enumerator ? $enumerator : $this->make_enumerator();
	}

	/**
	 * Build the provider's folder enumerator.
	 *
	 * @return object An object exposing list_files( $folder_url ).
	 */
	abstract protected function make_enumerator();

	/**
	 * Derive a stable identifier for a folder URL.
	 *
	 * Used to key stored folders and to reject duplicates, so it must stay the
	 * same when a share link is reissued.
	 *
	 * @param string $url Folder URL.
	 * @return string|\WP_Error Identifier, or an error if the URL is unusable.
	 */
	abstract public static function identify_folder( $url );

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
			'last_sync'    => 0,
			'last_status'  => 'never',
			'total_images' => 0,
			'remaining'    => 0,
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
		$folders = get_option( static::OPTION_FOLDERS, array() );

		if ( ! is_array( $folders ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $folders as $key => $folder ) {
			if ( ! is_array( $folder ) ) {
				continue;
			}
			$folder          = array_merge( static::folder_defaults(), $folder );
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
		update_option( static::OPTION_FOLDERS, $folders, false );
	}

	/**
	 * Get a single folder record.
	 *
	 * @param string $key Folder key.
	 * @return array|null
	 */
	public static function get_folder( $key ) {
		$folders = static::get_folders();

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
		$folder_id = static::identify_folder( $url );
		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		$folders = static::get_folders();

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

		$key    = 'f_' . substr( md5( static::OPTION_FOLDERS . '|' . $folder_id ), 0, 14 );
		$record = array_merge(
			static::folder_defaults(),
			array(
				'key'       => $key,
				'url'       => esc_url_raw( $url ),
				'folder_id' => $folder_id,
				'label'     => '' !== $label ? sanitize_text_field( $label ) : $folder_id,
				'truncated' => ! empty( $listing['truncated'] ),
			)
		);

		$folders[ $key ] = $record;
		static::save_folders( $folders );

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
		$folders = static::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return false;
		}

		unset( $folders[ $key ] );
		static::save_folders( $folders );

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
		$folders = static::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return false;
		}

		$folders[ $key ]['enabled'] = (bool) $enabled;
		static::save_folders( $folders );

		return true;
	}

	/**
	 * Sync every enabled folder.
	 *
	 * @param int|null $batch_limit Maximum imports across all folders.
	 * @param int|null $time_budget Seconds the whole run may spend importing.
	 * @return array Summary of the run.
	 */
	public function sync_all( $batch_limit = null, $time_budget = null ) {
		$batch_limit = null === $batch_limit ? static::DEFAULT_BATCH_LIMIT : max( 1, (int) $batch_limit );
		$time_budget = null === $time_budget ? static::CRON_TIME_BUDGET : max( 1, (int) $time_budget );
		$started_at  = time();

		$summary = array(
			'imported'  => 0,
			'failed'    => 0,
			'remaining' => 0,
			'errors'    => array(),
			'folders'   => 0,
		);

		foreach ( static::get_folders() as $key => $folder ) {
			if ( empty( $folder['enabled'] ) ) {
				continue;
			}

			$elapsed = time() - $started_at;

			if ( $summary['imported'] >= $batch_limit || $elapsed >= $time_budget ) {
				// Out of budget for this run; report what is still outstanding.
				$summary['remaining'] += 1;
				continue;
			}

			$result = $this->sync_folder(
				$key,
				$batch_limit - $summary['imported'],
				$time_budget - $elapsed
			);
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
	 * @param int|null $time_budget Seconds this run may spend importing.
	 * @return array|WP_Error Result summary, or an error if the folder could not be read.
	 */
	public function sync_folder( $key, $batch_limit = null, $time_budget = null ) {
		$folders = static::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return new WP_Error(
				'drive_folder_missing',
				__( 'That folder is no longer being watched.', 'url-image-importer' )
			);
		}

		$batch_limit = null === $batch_limit ? static::DEFAULT_BATCH_LIMIT : max( 1, (int) $batch_limit );
		$folder      = $folders[ $key ];

		if ( ! $this->acquire_lock( $key ) ) {
			return new WP_Error(
				'drive_folder_locked',
				__( 'This folder is already being checked. Try again in a moment.', 'url-image-importer' )
			);
		}

		$listing = $this->cached_listing( $key, $folder );

		if ( is_wp_error( $listing ) ) {
			$this->clear_listing_cache( $key );
			$this->release_lock( $key );
			// Record the failure loudly. A sync that cannot read its folder must
			// never look like a clean run that simply found nothing new.
			$folder['last_sync']   = time();
			$folder['last_status'] = 'error';
			$folder['last_error']  = $listing->get_error_message();
			$this->checkpoint( $key, $folder );

			return $listing;
		}

		$imported    = 0;
		$failed      = 0;
		$remaining   = 0;
		$time_budget = null === $time_budget ? static::CRON_TIME_BUDGET : max( 1, (int) $time_budget );
		$started_at  = time();

		foreach ( $listing['entries'] as $entry ) {
			$file_id = $entry['id'];

			// Already handled by a previous run.
			if ( isset( $folder['seen'][ $file_id ] ) ) {
				continue;
			}

			// Repeatedly unimportable (corrupt file, permission change, etc).
			if ( isset( $folder['failed'][ $file_id ] ) && $folder['failed'][ $file_id ] >= static::MAX_IMPORT_ATTEMPTS ) {
				continue;
			}

			// Stop on either budget. Downloads vary hugely in size, so a time
			// budget is what actually keeps a run inside the request timeout.
			if ( $imported >= $batch_limit || ( time() - $started_at ) >= $time_budget ) {
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

			// Checkpoint so a run killed by a request timeout keeps its progress.
			if ( 0 === $imported % static::CHECKPOINT_EVERY ) {
				$this->checkpoint( $key, $folder );
			}
		}

		$folder['last_sync']    = time();
		$folder['last_status']  = 'ok';
		$folder['last_error']   = '';
		$folder['truncated']    = ! empty( $listing['truncated'] );
		// Record progress so the UI can show "still importing" rather than a
		// bare count that looks stuck when a large folder is mid-catch-up. A
		// run is bounded by time and batch size, so a folder with more images
		// than one run can handle finishes over several scheduled checks.
		$folder['total_images'] = count( $listing['entries'] );
		$folder['remaining']    = $remaining;
		$this->checkpoint( $key, $folder );

		// Once the folder is fully imported, drop the cached listing so the next
		// scheduled check re-reads the folder and notices newly added files.
		if ( 0 === $remaining ) {
			$this->clear_listing_cache( $key );
		}

		$this->release_lock( $key );

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
	 * Claim the sync lock for a folder.
	 *
	 * A run that overruns its request can keep executing after the browser has
	 * given up. Without a lock, the next run reads the pre-run ledger and the
	 * two writes clobber each other, losing progress and re-downloading files.
	 *
	 * @param string $key Folder key.
	 * @return bool Whether the lock was acquired.
	 */
	protected function acquire_lock( $key ) {
		$lock = static::OPTION_FOLDERS . '_lock_' . $key;

		if ( get_transient( $lock ) ) {
			return false;
		}

		set_transient( $lock, time(), static::LOCK_TIMEOUT );

		return true;
	}

	/**
	 * Release a folder's sync lock.
	 *
	 * @param string $key Folder key.
	 * @return void
	 */
	protected function release_lock( $key ) {
		delete_transient( static::OPTION_FOLDERS . '_lock_' . $key );
	}

	/**
	 * Get the folder listing, reusing a short-lived cache within a catch-up.
	 *
	 * Importing a large folder happens in many quick, auto-continuing chunks.
	 * Re-listing the folder on every chunk would mean dozens of requests to
	 * Google or Dropbox in a couple of minutes, which is wasteful and risks
	 * rate limiting. The listing is cached for the duration of a catch-up and
	 * cleared once the folder is fully imported, so the next scheduled check
	 * still picks up newly added files.
	 *
	 * @param string $key    Folder key.
	 * @param array  $folder Folder record.
	 * @return array|\WP_Error
	 */
	protected function cached_listing( $key, $folder ) {
		$cache_key = static::OPTION_FOLDERS . '_listing_' . $key;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['entries'] ) ) {
			return $cached;
		}

		$listing = $this->enumerator->list_files( '' !== $folder['url'] ? $folder['url'] : $folder['folder_id'] );

		if ( ! is_wp_error( $listing ) ) {
			set_transient( $cache_key, $listing, 10 * MINUTE_IN_SECONDS );
		}

		return $listing;
	}

	/**
	 * Drop a folder's cached listing.
	 *
	 * @param string $key Folder key.
	 * @return void
	 */
	protected function clear_listing_cache( $key ) {
		delete_transient( static::OPTION_FOLDERS . '_listing_' . $key );
	}

	/**
	 * Merge this run's progress into the stored folder record.
	 *
	 * The record is re-read immediately before writing so a concurrent update
	 * to an unrelated folder is not lost.
	 *
	 * @param string $key    Folder key.
	 * @param array  $folder Folder record for this run.
	 * @return void
	 */
	protected function checkpoint( $key, $folder ) {
		$folders = static::get_folders();

		if ( ! isset( $folders[ $key ] ) ) {
			return;
		}

		$folders[ $key ] = $folder;
		static::save_folders( $folders );
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
	 * Count files in a folder that have been given up on.
	 *
	 * A file that fails to import on every attempt (corrupt, not actually an
	 * image, no longer downloadable) is abandoned rather than retried forever.
	 * Those files are why an imported count can settle just below the folder's
	 * total, so the UI can report them honestly instead of claiming everything
	 * imported.
	 *
	 * @param array $folder Folder record.
	 * @return int
	 */
	public static function count_permanent_failures( $folder ) {
		if ( empty( $folder['failed'] ) || ! is_array( $folder['failed'] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $folder['failed'] as $attempts ) {
			if ( (int) $attempts >= static::MAX_IMPORT_ATTEMPTS ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Whether any watched folder is currently in an error state.
	 *
	 * @return array[] Folders with a failing last run.
	 */
	public static function get_failing_folders() {
		$failing = array();

		foreach ( static::get_folders() as $key => $folder ) {
			if ( ! empty( $folder['enabled'] ) && 'error' === $folder['last_status'] ) {
				$failing[ $key ] = $folder;
			}
		}

		return $failing;
	}
}
