<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use UrlImageImporter\Importer\WordPressXmlImporter;

class WordPressXmlImporterTest extends WpTestCase {
	public function test_process_xml_import_reports_missing_invalid_and_empty_files(): void {
		$importer = new WordPressXmlImporter();

		$missing = $importer->process_xml_import( \uimptr_tests_base_temp_dir() . '/missing.xml' );
		$this->assertSame( 0, $missing['imported'] );
		$this->assertStringContainsString( 'XML file not found', $missing['messages'][0] );

		$invalid_path = $this->writeXmlFixture( '<rss><broken></rss>' );
		$invalid = $importer->process_xml_import( $invalid_path );
		$this->assertSame( 0, $invalid['imported'] );
		$this->assertStringContainsString( 'Failed to parse XML file', $invalid['messages'][0] );

		$empty_path = $this->writeXmlFixture( '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel /></rss>' );
		$empty = $importer->process_xml_import( $empty_path );
		$this->assertSame( 0, $empty['imported'] );
		$this->assertSame( 'No attachments found in the XML file.', $empty['messages'][0] );
	}

	public function test_process_xml_import_downloads_attachment_and_applies_metadata(): void {
		$url = 'https://cdn.example.test/wp-content/uploads/2024/01/imported.png';
		$this->mockHttpResponse( $url, $this->pngBytes(), 200, array( 'content-type' => 'image/png' ) );

		$path = $this->writeXmlFixture(
			$this->wordpressExportXml(
				array(
					array(
						'title'       => 'Imported Title.PNG',
						'url'         => $url,
						'description' => 'Attachment description',
						'pub_date'    => 'Mon, 01 Jan 2024 10:00:00 +0000',
					),
				)
			)
		);

		$results = ( new WordPressXmlImporter() )->process_xml_import( $path );

		$this->assertSame( 1, $results['imported'] );
		$this->assertSame( 0, $results['skipped'] );
		$this->assertSame( 0, $results['errors'] );
		$this->assertSame( 'Successfully imported: imported.png', $results['messages'][0] );

		$post = $GLOBALS['uimptr_test_inserted_posts'][1001];
		$this->assertSame( 'Imported Title', $post['post_title'] );
		$this->assertSame( 'imported-title', $post['post_name'] );
		$this->assertSame( 'Attachment description', $post['post_content'] );
		$this->assertSame( '2024-01-01 10:00:00', $post['post_date'] );
		$this->assertSame( '2024-01-01 10:00:00', $post['post_date_gmt'] );
	}

	public function test_process_xml_import_skips_existing_filename_without_force_reimport(): void {
		$GLOBALS['wpdb']->filename_matches['existing.jpg'] = 22;
		$path = $this->writeXmlFixture(
			$this->wordpressExportXml(
				array(
					array(
						'title'       => 'Existing',
						'url'         => 'https://cdn.example.test/existing.jpg',
						'description' => '',
						'pub_date'    => '2024-01-01 00:00:00',
					),
				)
			)
		);

		$results = ( new WordPressXmlImporter() )->process_xml_import( $path );

		$this->assertSame( 0, $results['imported'] );
		$this->assertSame( 1, $results['skipped'] );
		$this->assertSame( 'Skipped existing file: existing.jpg', $results['messages'][0] );

		$forced_url = 'https://cdn.example.test/existing.jpg';
		$this->mockHttpResponse( $forced_url, $this->pngBytes(), 200, array( 'content-type' => 'image/jpeg' ) );
		$forced = ( new WordPressXmlImporter() )->process_xml_import( $path, array( 'force_reimport' => true ) );
		$this->assertSame( 1, $forced['imported'] );
	}

	public function test_process_xml_import_images_only_skips_non_image_attachment_urls(): void {
		$path = $this->writeXmlFixture(
			$this->wordpressExportXml(
				array(
					array(
						'title'       => 'PDF',
						'url'         => 'https://cdn.example.test/file.pdf',
						'description' => '',
						'pub_date'    => '2024-01-01 00:00:00',
					),
				)
			)
		);

		$results = ( new WordPressXmlImporter() )->process_xml_import( $path, array( 'images_only' => true ) );

		$this->assertSame( 0, $results['imported'] );
		$this->assertSame( 1, $results['skipped'] );
		$this->assertSame( array(), $results['messages'] );
	}

	public function test_process_xml_import_records_import_errors(): void {
		$url = 'https://cdn.example.test/broken.png';
		$this->mockHttpResponse( $url, 'not an image', 200, array( 'content-type' => 'image/png' ) );
		$path = $this->writeXmlFixture(
			$this->wordpressExportXml(
				array(
					array(
						'title'       => 'Broken',
						'url'         => $url,
						'description' => '',
						'pub_date'    => '2024-01-01 00:00:00',
					),
				)
			)
		);

		$results = ( new WordPressXmlImporter() )->process_xml_import( $path );

		$this->assertSame( 0, $results['imported'] );
		$this->assertSame( 1, $results['errors'] );
		$this->assertStringContainsString( 'Failed to import broken.png', $results['messages'][0] );
	}

	private function writeXmlFixture( string $xml ): string {
		$path = \uimptr_tests_base_temp_dir() . '/fixture-' . uniqid( '', true ) . '.xml';
		file_put_contents( $path, $xml );

		return $path;
	}

	private function wordpressExportXml( array $attachments ): string {
		$items = '';
		foreach ( $attachments as $attachment ) {
			$items .= sprintf(
				'<item>
					<title>%s</title>
					<guid>%s</guid>
					<description>%s</description>
					<pubDate>%s</pubDate>
					<wp:post_type>attachment</wp:post_type>
					<wp:attachment_url>%s</wp:attachment_url>
				</item>',
				htmlspecialchars( $attachment['title'], ENT_XML1 ),
				htmlspecialchars( $attachment['url'], ENT_XML1 ),
				htmlspecialchars( $attachment['description'], ENT_XML1 ),
				htmlspecialchars( $attachment['pub_date'], ENT_XML1 ),
				htmlspecialchars( $attachment['url'], ENT_XML1 )
			);
		}

		return '<?xml version="1.0" encoding="UTF-8"?>
			<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
				<channel>' . $items . '</channel>
			</rss>';
	}
}
