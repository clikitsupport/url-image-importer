<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use UrlImageImporter\FileScan\FileScan;
use UrlImageImporter\FileScan\UiBigFileUploadsFileScan;
use UrlImageImporter\FileScan\Utils;

class FileScanTest extends WpTestCase {
	public function test_file_scan_counts_readable_files_by_category_and_exclusions(): void {
		$root = \uimptr_tests_base_temp_dir() . '/scan-root';
		mkdir( $root . '/nested', 0777, true );
		mkdir( $root . '/skip', 0777, true );
		file_put_contents( $root . '/photo.jpg', 'abc' );
		file_put_contents( $root . '/document.pdf', '12345' );
		file_put_contents( $root . '/nested/theme.php', 'abcdefg' );
		file_put_contents( $root . '/nested/empty.png', '' );
		file_put_contents( $root . '/skip/ignored.jpg', 'ignored' );

		add_filter(
			'uimptr_sync_exclusions',
			function( $exclusions ) use ( $root ) {
				$exclusions[] = $root . '/skip';
				return $exclusions;
			}
		);

		$scan = new FileScan( $root );
		$scan->start();

		$this->assertTrue( $scan->is_done() );
		$this->assertSame( array(), $scan->get_paths_left() );
		$this->assertSame( 3, $scan->get_total_files() );
		$this->assertSame( 15, $scan->get_total_size() );

		$results = get_site_option( 'uimptr_file_scan' );
		$this->assertNotEmpty( $results['scan_finished'] );
		$this->assertSame( 1, $results['types']['image']->files );
		$this->assertSame( 3, $results['types']['image']->size );
		$this->assertSame( 1, $results['types']['document']->files );
		$this->assertSame( 5, $results['types']['document']->size );
		$this->assertSame( 1, $results['types']['code']->files );
		$this->assertSame( 7, $results['types']['code']->size );
		$this->assertArrayNotHasKey( 'other', $results['types'] );
	}

	public function test_file_scan_resumes_from_remaining_paths_and_preserves_cached_totals(): void {
		$root = \uimptr_tests_base_temp_dir() . '/resume-root';
		mkdir( $root . '/remaining', 0777, true );
		file_put_contents( $root . '/remaining/audio.mp3', '1234' );
		update_site_option(
			'uimptr_file_scan',
			array(
				'scan_finished' => false,
				'types'         => array(
					'image' => (object) array(
						'files' => 1,
						'size'  => 3,
					),
				),
			)
		);

		$scan = new FileScan( $root, 25.0, array( '/remaining' ) );
		$scan->start();

		$this->assertSame( 2, $scan->get_total_files() );
		$this->assertSame( 7, $scan->get_total_size() );
		$this->assertSame( 1, get_site_option( 'uimptr_file_scan' )['types']['audio']->files );
	}

	public function test_file_type_classification_matches_public_helper(): void {
		$scan = new UiBigFileUploadsFileScan( '/tmp' );

		foreach (
			array(
				'photo.webp' => 'image',
				'song.FLAC'  => 'audio',
				'movie.webm' => 'video',
				'sheet.xlsx' => 'document',
				'archive.tgz' => 'archive',
				'index.html' => 'code',
				'unknown.zzz' => 'other',
			) as $filename => $type
		) {
			$this->assertSame( $type, $scan->get_file_type( $filename ) );
			$this->assertSame( $type, \uimptr_get_file_type( $filename ) );
		}
	}

	public function test_filescan_utils_return_image_mimes_and_upload_root(): void {
		$this->assertSame( 'image/svg+xml', Utils::get_filetypes()['svg'] );
		$this->assertSame( trailingslashit( $GLOBALS['uimptr_test_upload_dir']['basedir'] ), Utils::get_upload_dir_root() );
	}
}
