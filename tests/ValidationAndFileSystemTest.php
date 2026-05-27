<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use UrlImageImporter\Utils\FileSystem;
use UrlImageImporter\Utils\Validation;

class ValidationAndFileSystemTest extends WpTestCase {
	public function test_validation_identifies_image_urls_by_extension_and_known_hosts(): void {
		$this->assertTrue( Validation::is_image_url( 'https://example.test/path/photo.JPG?size=large' ) );
		$this->assertTrue( Validation::is_image_url( 'https://images.unsplash.com/photo-123' ) );
		$this->assertFalse( Validation::is_image_url( 'https://example.test/file.pdf' ) );
	}

	public function test_validation_uses_filter_var_for_url_format(): void {
		$this->assertTrue( Validation::is_valid_url( 'https://example.test/image.png' ) );
		$this->assertFalse( Validation::is_valid_url( 'not a url' ) );
	}

	public function test_attachment_exists_queries_wpdb_for_filename(): void {
		$GLOBALS['wpdb']->get_var_queue[] = 555;
		$this->assertTrue( Validation::attachment_exists( 'photo.jpg' ) );
		$this->assertStringContainsString( 'guid LIKE', $GLOBALS['wpdb']->last_query );

		$GLOBALS['wpdb']->get_var_queue[] = null;
		$this->assertFalse( Validation::attachment_exists( 'missing.jpg' ) );
	}

	public function test_filesystem_temp_directory_lives_under_uploads(): void {
		$this->assertSame(
			$GLOBALS['uimptr_test_upload_dir']['basedir'] . '/uimptr-temp',
			FileSystem::get_local_temp_dir()
		);
	}

	public function test_filesystem_cloud_exclusion_only_matches_temp_directory(): void {
		$temp_dir = FileSystem::get_local_temp_dir();

		$this->assertTrue( FileSystem::exclude_temp_files_from_cloud( false, $temp_dir . '/import.csv' ) );
		$this->assertFalse( FileSystem::exclude_temp_files_from_cloud( false, dirname( $temp_dir ) . '/other/import.csv' ) );
		$this->assertTrue( FileSystem::exclude_temp_files_from_cloud( true, dirname( $temp_dir ) . '/other/import.csv' ) );
	}

	public function test_filesystem_upload_root_uses_default_or_custom_upload_path(): void {
		$this->assertSame( UPLOADBLOGSDIR, FileSystem::get_upload_dir_root() );

		update_option( 'upload_path', '/custom/uploads' );

		$this->assertSame( '/custom/uploads', FileSystem::get_upload_dir_root() );
	}

	public function test_filesystem_cleanup_temp_files_removes_only_expired_import_files(): void {
		$temp_dir = FileSystem::get_local_temp_dir();
		wp_mkdir_p( $temp_dir );
		$old_file    = $temp_dir . '/import_old.csv';
		$recent_file = $temp_dir . '/import_recent.csv';
		$other_file  = $temp_dir . '/other.txt';

		file_put_contents( $old_file, 'old' );
		file_put_contents( $recent_file, 'recent' );
		file_put_contents( $other_file, 'other' );
		touch( $old_file, time() - 3 * HOUR_IN_SECONDS );
		touch( $recent_file, time() );
		touch( $other_file, time() - 3 * HOUR_IN_SECONDS );

		FileSystem::cleanup_temp_files();

		$this->assertFileDoesNotExist( $old_file );
		$this->assertFileExists( $recent_file );
		$this->assertFileExists( $other_file );
	}
}
