<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use UrlImageImporter\Importer\GoogleDriveFolderEnumerator;
use UrlImageImporter\Importer\GoogleDriveFolderSync;
use WP_Error;

/**
 * Enumerator that returns canned listings instead of calling Google.
 */
class FakeDriveEnumerator extends GoogleDriveFolderEnumerator {
	/** @var mixed */
	public $result;

	/** @var int */
	public $calls = 0;

	public function __construct( $result = null ) {
		$this->result = $result;
	}

	public function list_files( $folder_url ) {
		$this->calls++;

		return $this->result;
	}
}

/**
 * Sync with the Media Library side effects replaced by in-memory doubles.
 */
class TestableDriveSync extends GoogleDriveFolderSync {
	/** @var string[] URLs handed to the importer, in order. */
	public $imported = array();

	/** @var string[] URLs that should fail to import. */
	public $failing = array();

	/** @var string[] URLs that already exist in the Media Library. */
	public $existing = array();

	/** @var int */
	protected $next_id = 100;

	/** @var int Seconds each simulated import takes. */
	public $import_seconds = 0;

	protected function import_file( $url ) {
		$this->imported[] = $url;

		if ( $this->import_seconds > 0 ) {
			sleep( $this->import_seconds );
		}

		if ( in_array( $url, $this->failing, true ) ) {
			return new WP_Error( 'invalid_image', 'File failed content validation.' );
		}

		return $this->next_id++;
	}

	protected function existing_attachment_id( $url ) {
		return in_array( $url, $this->existing, true ) ? 55 : 0;
	}
}

/**
 * Counts how often progress is persisted during a run.
 */
class CheckpointObservingSync extends TestableDriveSync {
	/** @var int */
	public $checkpoints = 0;

	protected function checkpoint( $key, $folder ) {
		$this->checkpoints++;
		parent::checkpoint( $key, $folder );
	}
}

class GoogleDriveFolderSyncTest extends WpTestCase {

	private function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/google-drive/' . $name );
	}

	private function listing( array $entries, array $overrides = array() ): array {
		return array_merge(
			array(
				'entries'   => $entries,
				'skipped'   => array(),
				'total'     => count( $entries ),
				'truncated' => false,
			),
			$overrides
		);
	}

	private function entry( string $id, string $name ): array {
		return array(
			'id'   => $id,
			'name' => $name,
			'url'  => 'https://drive.google.com/file/d/' . $id . '/view',
		);
	}

	/* ---------------------------------------------------------------- */
	/* Folder URL parsing                                                */
	/* ---------------------------------------------------------------- */

	public function test_extracts_folder_id_from_share_url(): void {
		$this->assertSame(
			'1I2t3THjXgKj0e2_QPe34M83T_JkMsxEV',
			GoogleDriveFolderEnumerator::extract_folder_id( 'https://drive.google.com/drive/folders/1I2t3THjXgKj0e2_QPe34M83T_JkMsxEV?usp=sharing' )
		);
	}

	public function test_extracts_folder_id_from_account_scoped_and_open_urls(): void {
		$this->assertSame(
			'ABC123def',
			GoogleDriveFolderEnumerator::extract_folder_id( 'https://drive.google.com/drive/u/0/folders/ABC123def' )
		);
		$this->assertSame(
			'XYZ789ghi',
			GoogleDriveFolderEnumerator::extract_folder_id( 'https://drive.google.com/open?id=XYZ789ghi' )
		);
	}

	public function test_accepts_bare_folder_id(): void {
		$this->assertSame(
			'1I2t3THjXgKj0e2_QPe34M83T_JkMsxEV',
			GoogleDriveFolderEnumerator::extract_folder_id( '1I2t3THjXgKj0e2_QPe34M83T_JkMsxEV' )
		);
	}

	public function test_rejects_non_drive_url(): void {
		$result = GoogleDriveFolderEnumerator::extract_folder_id( 'https://example.com/photos' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'drive_folder_invalid_url', $result->get_error_code() );
	}

	public function test_extracts_resource_key_when_present(): void {
		$this->assertSame(
			'AbC-dEf',
			GoogleDriveFolderEnumerator::extract_resource_key( 'https://drive.google.com/drive/folders/ID?resourcekey=AbC-dEf' )
		);
		$this->assertSame(
			'',
			GoogleDriveFolderEnumerator::extract_resource_key( 'https://drive.google.com/drive/folders/ID' )
		);
	}

	/* ---------------------------------------------------------------- */
	/* Listing parser                                                    */
	/* ---------------------------------------------------------------- */

	public function test_parses_real_folder_listing(): void {
		$entries = GoogleDriveFolderEnumerator::parse_listing( $this->fixture( 'folder-listing.html' ) );

		$this->assertIsArray( $entries );
		$this->assertCount( 84, $entries );
		$this->assertSame( '1LX1Nz0MXCNa39P6dy11tuWLNcBlsFmGk', $entries[0]['id'] );
		$this->assertSame(
			'https://drive.google.com/file/d/1LX1Nz0MXCNa39P6dy11tuWLNcBlsFmGk/view',
			$entries[0]['url']
		);
	}

	public function test_parsed_urls_are_understood_by_the_existing_drive_helper(): void {
		$entries = GoogleDriveFolderEnumerator::parse_listing( $this->fixture( 'folder-listing.html' ) );

		// The enumerator must emit URLs the shared importer already parses.
		$this->assertSame(
			$entries[0]['id'],
			uimptr_extract_google_drive_file_id( $entries[0]['url'] )
		);
	}

	public function test_error_page_is_reported_as_parse_failure(): void {
		$result = GoogleDriveFolderEnumerator::parse_listing( $this->fixture( 'folder-404.html' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'drive_folder_parse_failed', $result->get_error_code() );
	}

	public function test_empty_folder_is_not_an_error(): void {
		$html   = '<html><body><div id="flip-contents"><div class="flip-entries"></div></div></body></html>';
		$result = GoogleDriveFolderEnumerator::parse_listing( $html );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	public function test_falls_back_to_file_links_when_entry_markup_changes(): void {
		// Simulates Google renaming the entry container but keeping file links.
		$html   = '<div class="flip-entries"><a href="https://drive.google.com/file/d/ABC123/view">x</a></div>';
		$result = GoogleDriveFolderEnumerator::parse_listing( $html );

		$this->assertCount( 1, $result );
		$this->assertSame( 'ABC123', $result[0]['id'] );
	}

	public function test_identifies_image_filenames(): void {
		$this->assertTrue( GoogleDriveFolderEnumerator::is_image_filename( 'photo.JPG' ) );
		$this->assertTrue( GoogleDriveFolderEnumerator::is_image_filename( 'logo.webp' ) );
		$this->assertFalse( GoogleDriveFolderEnumerator::is_image_filename( 'flier.pdf' ) );
		$this->assertFalse( GoogleDriveFolderEnumerator::is_image_filename( 'movie.mp4' ) );
		$this->assertFalse( GoogleDriveFolderEnumerator::is_image_filename( 'noextension' ) );
	}

	/* ---------------------------------------------------------------- */
	/* HTTP behaviour                                                    */
	/* ---------------------------------------------------------------- */

	public function test_list_files_filters_non_images_from_real_listing(): void {
		$url = 'https://drive.google.com/embeddedfolderview?id=FOLDER';
		$this->mockHttpResponse( $url, $this->fixture( 'folder-listing.html' ) );

		$result = ( new GoogleDriveFolderEnumerator() )->list_files( 'https://drive.google.com/drive/folders/FOLDER' );

		$this->assertSame( 84, $result['total'] );
		$this->assertCount( 83, $result['entries'] );
		$this->assertCount( 1, $result['skipped'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_missing_or_private_folder_reports_accessible_error(): void {
		$url = 'https://drive.google.com/embeddedfolderview?id=FOLDER';
		$this->mockHttpResponse( $url, $this->fixture( 'folder-404.html' ), 404 );

		$result = ( new GoogleDriveFolderEnumerator() )->list_files( 'https://drive.google.com/drive/folders/FOLDER' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'drive_folder_not_accessible', $result->get_error_code() );
	}

	/* ---------------------------------------------------------------- */
	/* Folder management                                                 */
	/* ---------------------------------------------------------------- */

	public function test_add_folder_rejects_invalid_url(): void {
		$sync   = new TestableDriveSync( new FakeDriveEnumerator( $this->listing( array() ) ) );
		$result = $sync->add_folder( 'https://example.com/nope' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'drive_folder_invalid_url', $result->get_error_code() );
		$this->assertSame( array(), GoogleDriveFolderSync::get_folders() );
	}

	public function test_add_folder_rejects_unreachable_folder_up_front(): void {
		$enumerator = new FakeDriveEnumerator( new WP_Error( 'drive_folder_not_accessible', 'nope' ) );
		$sync       = new TestableDriveSync( $enumerator );

		$result = $sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), GoogleDriveFolderSync::get_folders() );
	}

	public function test_add_folder_stores_record_and_rejects_duplicates(): void {
		$sync = new TestableDriveSync( new FakeDriveEnumerator( $this->listing( array() ) ) );

		$record = $sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1', 'Web images' );
		$this->assertSame( 'FOLDER1', $record['folder_id'] );
		$this->assertSame( 'Web images', $record['label'] );
		$this->assertCount( 1, GoogleDriveFolderSync::get_folders() );

		$duplicate = $sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1' );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'drive_folder_duplicate', $duplicate->get_error_code() );
		$this->assertCount( 1, GoogleDriveFolderSync::get_folders() );
	}

	public function test_removing_a_folder_only_drops_the_watch_entry(): void {
		$sync   = new TestableDriveSync( new FakeDriveEnumerator( $this->listing( array() ) ) );
		$record = $sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1' );

		$this->assertTrue( GoogleDriveFolderSync::remove_folder( $record['key'] ) );
		$this->assertSame( array(), GoogleDriveFolderSync::get_folders() );
		// Nothing was imported, so nothing could have been deleted.
		$this->assertSame( array(), $sync->imported );
	}

	/* ---------------------------------------------------------------- */
	/* Sync behaviour                                                    */
	/* ---------------------------------------------------------------- */

	private function seeded_sync( array $entries, array $overrides = array() ): array {
		$enumerator = new FakeDriveEnumerator( $this->listing( $entries, $overrides ) );
		$sync       = new TestableDriveSync( $enumerator );
		$record     = $sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1' );

		return array( $sync, $record['key'] );
	}

	public function test_sync_imports_new_files(): void {
		list( $sync, $key ) = $this->seeded_sync(
			array( $this->entry( 'F1', 'a.jpg' ), $this->entry( 'F2', 'b.png' ) )
		);

		$result = $sync->sync_folder( $key );

		$this->assertSame( 2, $result['imported'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertCount( 2, $sync->imported );
	}

	public function test_sync_does_not_reimport_on_second_run(): void {
		list( $sync, $key ) = $this->seeded_sync(
			array( $this->entry( 'F1', 'a.jpg' ), $this->entry( 'F2', 'b.png' ) )
		);

		$sync->sync_folder( $key );
		$second = $sync->sync_folder( $key );

		$this->assertSame( 0, $second['imported'] );
		$this->assertCount( 2, $sync->imported, 'The importer should not be called again for known files.' );
	}

	public function test_deleting_an_attachment_does_not_trigger_reimport(): void {
		// The ledger, not the Media Library, decides what is new -- so a file the
		// user deliberately deleted in WordPress stays deleted.
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );

		$sync->sync_folder( $key );
		$sync->existing = array(); // Attachment removed by the user.
		$second         = $sync->sync_folder( $key );

		$this->assertSame( 0, $second['imported'] );
	}

	public function test_sync_adopts_files_already_imported_by_hand(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );
		$sync->existing     = array( 'https://drive.google.com/file/d/F1/view' );

		$result = $sync->sync_folder( $key );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( array(), $sync->imported, 'An already-imported file must not be downloaded again.' );
	}

	public function test_sync_respects_batch_limit_and_reports_remaining(): void {
		list( $sync, $key ) = $this->seeded_sync(
			array(
				$this->entry( 'F1', 'a.jpg' ),
				$this->entry( 'F2', 'b.jpg' ),
				$this->entry( 'F3', 'c.jpg' ),
			)
		);

		$result = $sync->sync_folder( $key, 2 );

		$this->assertSame( 2, $result['imported'] );
		$this->assertSame( 1, $result['remaining'] );

		// The leftover file is picked up on the next run.
		$next = $sync->sync_folder( $key, 2 );
		$this->assertSame( 1, $next['imported'] );
	}

	public function test_sync_stops_when_the_time_budget_is_spent(): void {
		// Guards against gateway timeouts: slow downloads must end the run early
		// rather than running until the request is killed.
		list( $sync, $key ) = $this->seeded_sync(
			array(
				$this->entry( 'F1', 'a.jpg' ),
				$this->entry( 'F2', 'b.jpg' ),
				$this->entry( 'F3', 'c.jpg' ),
			)
		);
		$sync->import_seconds = 1;

		$result = $sync->sync_folder( $key, 50, 1 );

		$this->assertSame( 1, $result['imported'], 'The run should stop once the time budget is spent.' );
		$this->assertSame( 2, $result['remaining'] );
	}

	public function test_concurrent_run_is_refused_while_a_sync_is_in_flight(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );

		// Simulate a run that is still executing after its request timed out.
		set_transient( GoogleDriveFolderSync::OPTION_FOLDERS . '_lock_' . $key, time(), 600 );

		$result = $sync->sync_folder( $key );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'drive_folder_locked', $result->get_error_code() );
		$this->assertSame( array(), $sync->imported, 'A locked folder must not be imported twice.' );
	}

	public function test_lock_is_released_after_a_successful_run(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );

		$sync->sync_folder( $key );

		$this->assertFalse(
			get_transient( GoogleDriveFolderSync::OPTION_FOLDERS . '_lock_' . $key ),
			'A finished run must not leave its folder locked.'
		);
	}

	public function test_lock_is_released_when_the_folder_cannot_be_read(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );

		$broken = new TestableDriveSync( new FakeDriveEnumerator( new WP_Error( 'drive_folder_parse_failed', 'boom' ) ) );
		$broken->sync_folder( $key );

		$this->assertFalse(
			get_transient( GoogleDriveFolderSync::OPTION_FOLDERS . '_lock_' . $key ),
			'A failed run must not leave its folder locked forever.'
		);
	}

	public function test_progress_is_checkpointed_during_a_run(): void {
		// Guards against losing the whole ledger when a run is killed part way.
		$entries = array();
		for ( $i = 1; $i <= GoogleDriveFolderSync::CHECKPOINT_EVERY; $i++ ) {
			$entries[] = $this->entry( 'F' . $i, $i . '.jpg' );
		}

		$enumerator = new FakeDriveEnumerator( $this->listing( $entries ) );
		$sync       = new CheckpointObservingSync( $enumerator );
		$record     = $sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1' );

		$sync->sync_folder( $record['key'] );

		$this->assertGreaterThan(
			1,
			$sync->checkpoints,
			'Progress should be written during the run, not only at the end.'
		);
	}

	public function test_sync_gives_up_after_repeated_import_failures(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'bad.jpg' ) ) );
		$sync->failing      = array( 'https://drive.google.com/file/d/F1/view' );

		for ( $i = 0; $i < GoogleDriveFolderSync::MAX_IMPORT_ATTEMPTS + 2; $i++ ) {
			$sync->sync_folder( $key );
		}

		$this->assertCount(
			GoogleDriveFolderSync::MAX_IMPORT_ATTEMPTS,
			$sync->imported,
			'A file that keeps failing must stop being retried.'
		);
	}

	public function test_unreadable_folder_is_recorded_as_an_error_not_a_clean_run(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );

		// The folder becomes unreadable after it was added.
		$sync->sync_folder( $key );
		$broken = new FakeDriveEnumerator( new WP_Error( 'drive_folder_parse_failed', 'markup changed' ) );
		$sync   = new TestableDriveSync( $broken );

		$result = $sync->sync_folder( $key );
		$folder = GoogleDriveFolderSync::get_folder( $key );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'error', $folder['last_status'] );
		$this->assertSame( 'markup changed', $folder['last_error'] );
		$this->assertArrayHasKey( $key, GoogleDriveFolderSync::get_failing_folders() );
	}

	public function test_successful_sync_clears_a_previous_error(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );

		$broken = new TestableDriveSync( new FakeDriveEnumerator( new WP_Error( 'drive_folder_parse_failed', 'boom' ) ) );
		$broken->sync_folder( $key );
		$this->assertSame( 'error', GoogleDriveFolderSync::get_folder( $key )['last_status'] );

		$sync->sync_folder( $key );

		$this->assertSame( 'ok', GoogleDriveFolderSync::get_folder( $key )['last_status'] );
		$this->assertSame( '', GoogleDriveFolderSync::get_folder( $key )['last_error'] );
		$this->assertSame( array(), GoogleDriveFolderSync::get_failing_folders() );
	}

	public function test_truncated_listing_is_flagged(): void {
		list( $sync, $key ) = $this->seeded_sync(
			array( $this->entry( 'F1', 'a.jpg' ) ),
			array( 'truncated' => true )
		);

		$result = $sync->sync_folder( $key );

		$this->assertTrue( $result['truncated'] );
		$this->assertTrue( GoogleDriveFolderSync::get_folder( $key )['truncated'] );
	}

	public function test_disabled_folders_are_skipped_by_sync_all(): void {
		list( $sync, $key ) = $this->seeded_sync( array( $this->entry( 'F1', 'a.jpg' ) ) );
		GoogleDriveFolderSync::set_enabled( $key, false );

		$summary = $sync->sync_all();

		$this->assertSame( 0, $summary['imported'] );
		$this->assertSame( array(), $sync->imported );
	}

	public function test_sync_all_covers_multiple_folders(): void {
		$enumerator = new FakeDriveEnumerator( $this->listing( array( $this->entry( 'F1', 'a.jpg' ) ) ) );
		$sync       = new TestableDriveSync( $enumerator );

		$sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER1' );
		$sync->add_folder( 'https://drive.google.com/drive/folders/FOLDER2' );

		$summary = $sync->sync_all();

		$this->assertSame( 2, $summary['folders'] );
		$this->assertSame( 2, $summary['imported'] );
	}
}
