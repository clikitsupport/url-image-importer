<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use WP_Error;

class XmlCsvExtractionTest extends WpTestCase {
	public function test_secure_xml_loader_rejects_xxe_payloads(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss>&xxe;</rss>';

		$result = \uimptr_load_xml_string_securely( $xml );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsafe_xml', $result->get_error_code() );
	}

	public function test_extract_urls_from_xml_content_includes_metadata_and_sorts_when_preserving_dates(): void {
		$xml = $this->wordpressExportXml(
			array(
				array(
					'title' => 'Older Image',
					'url'   => 'https://cdn.example.test/uploads/older.jpg',
					'date'  => '2020-01-01 10:00:00',
					'body'  => 'Older description',
				),
				array(
					'title' => 'Newer Image',
					'url'   => 'https://cdn.example.test/uploads/newer.png',
					'date'  => '2024-05-01 09:30:00',
					'body'  => 'Newer description',
				),
			)
		);

		$urls = \uimptr_extract_urls_from_xml_content( $xml, true );

		$this->assertIsArray( $urls );
		$this->assertCount( 2, $urls );
		$this->assertSame( 'https://cdn.example.test/uploads/newer.png', $urls[0]['url'] );
		$this->assertSame( 'Newer Image', $urls[0]['metadata']['title'] );
		$this->assertSame( 'Newer description', $urls[0]['metadata']['description'] );
		$this->assertSame( '2024-05-01 09:30:00', $urls[0]['metadata']['date'] );
		$this->assertSame( 'https://cdn.example.test/uploads/older.jpg', $urls[1]['url'] );
	}

	public function test_extract_urls_from_xml_content_filters_non_images_when_requested(): void {
		$_POST['images_only'] = '1';

		$xml = $this->wordpressExportXml(
			array(
				array(
					'title' => 'PDF',
					'url'   => 'https://cdn.example.test/uploads/file.pdf',
					'date'  => '2023-01-01 00:00:00',
					'body'  => 'Document',
				),
				array(
					'title' => 'Placeholder',
					'url'   => 'https://picsum.photos/600/400',
					'date'  => '2023-01-02 00:00:00',
					'body'  => 'Image service URL',
				),
			)
		);

		$urls = \uimptr_extract_urls_from_xml_content( $xml );

		$this->assertCount( 1, $urls );
		$this->assertSame( 'https://picsum.photos/600/400', $urls[0]['url'] );
	}

	public function test_extract_urls_from_xml_content_skips_existing_source_urls_unless_forced(): void {
		$GLOBALS['wpdb']->source_url_matches['https://cdn.example.test/uploads/existing.jpg'] = 123;

		$xml = $this->wordpressExportXml(
			array(
				array(
					'title' => 'Existing',
					'url'   => 'https://cdn.example.test/uploads/existing.jpg',
					'date'  => '2023-01-01 00:00:00',
					'body'  => 'Existing body',
				),
			)
		);

		$this->assertSame( array(), \uimptr_extract_urls_from_xml_content( $xml, false, false ) );

		$forced = \uimptr_extract_urls_from_xml_content( $xml, false, true );
		$this->assertCount( 1, $forced );
		$this->assertSame( 'https://cdn.example.test/uploads/existing.jpg', $forced[0]['url'] );
	}

	public function test_extract_urls_from_xml_content_reports_empty_invalid_and_attachmentless_xml(): void {
		$this->assertSame( 'empty_content', \uimptr_extract_urls_from_xml_content( '' )->get_error_code() );
		$this->assertSame( 'invalid_xml', \uimptr_extract_urls_from_xml_content( '<rss><broken></rss>' )->get_error_code() );
		$this->assertSame(
			'no_attachments',
			\uimptr_extract_urls_from_xml_content( '<?xml version="1.0"?><rss><channel><item><title>Post</title></item></channel></rss>' )->get_error_code()
		);
	}

	public function test_extract_urls_from_csv_content_handles_bom_header_aliases_and_quoted_metadata(): void {
		$csv = "\xEF\xBB\xBFImage URL,Title,Description,Alt Text,Date\n"
			. "\" https://cdn.example.test/a.jpg \",\"A title\",\"A description, with comma\",\"Alt text\",\"2024-01-02 03:04:05\"\n"
			. ",Blank URL,Ignored,Ignored,2024-01-03\n";

		$urls = \uimptr_extract_urls_from_csv_content( $csv );

		$this->assertCount( 1, $urls );
		$this->assertSame( 'https://cdn.example.test/a.jpg', $urls[0]['url'] );
		$this->assertSame(
			array(
				'title'       => 'A title',
				'description' => 'A description, with comma',
				'alt_text'    => 'Alt text',
				'date'        => '2024-01-02 03:04:05',
			),
			$urls[0]['metadata']
		);
	}

	public function test_extract_urls_from_csv_content_requires_url_column(): void {
		$result = \uimptr_extract_urls_from_csv_content( "Title,Description\nNo URL,Nope\n" );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_url_column', $result->get_error_code() );
	}

	public function test_extract_urls_from_csv_content_filters_non_images_and_reports_when_none_remain(): void {
		$_POST['images_only'] = '1';

		$result = \uimptr_extract_urls_from_csv_content( "url,title\nhttps://example.test/file.pdf,PDF\n" );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_urls', $result->get_error_code() );
	}

	public function test_extract_urls_from_csv_content_keeps_existing_source_urls_for_batch_dedupe(): void {
		$GLOBALS['wpdb']->source_url_matches['https://cdn.example.test/existing.jpg'] = 456;
		$csv = "url,title\nhttps://cdn.example.test/existing.jpg,Existing\n";

		$urls = \uimptr_extract_urls_from_csv_content( $csv, false, false );

		$this->assertCount( 1, $urls );
		$this->assertSame( 'https://cdn.example.test/existing.jpg', $urls[0]['url'] );
		$this->assertSame( 'Existing', $urls[0]['metadata']['title'] );

		$forced = \uimptr_extract_urls_from_csv_content( $csv, false, true );
		$this->assertCount( 1, $forced );
		$this->assertSame( 'Existing', $forced[0]['metadata']['title'] );
	}

	public function test_extract_urls_from_csv_content_accepts_google_drive_candidates_with_images_only(): void {
		$_POST['images_only'] = '1';
		$drive_url = 'https://drive.google.com/file/d/drive_image_123/view?usp=sharing';
		$csv       = "url,title,description,alt_text,date\n"
			. "{$drive_url},Drive Image,Drive description,Drive alt,2026-05-27 10:11:12\n"
			. "https://example.test/file.pdf,PDF,Skip,Skip,2026-05-28\n";

		$urls = \uimptr_extract_urls_from_csv_content( $csv );

		$this->assertCount( 1, $urls );
		$this->assertSame( $drive_url, $urls[0]['url'] );
		$this->assertSame(
			array(
				'title'       => 'Drive Image',
				'description' => 'Drive description',
				'alt_text'    => 'Drive alt',
				'date'        => '2026-05-27 10:11:12',
			),
			$urls[0]['metadata']
		);
	}

	public function test_extract_urls_from_csv_content_keeps_existing_google_drive_source_urls_for_batch_dedupe(): void {
		$_POST['images_only'] = '1';
		$drive_url           = 'https://drive.google.com/open?id=drive_duplicate_123';
		$GLOBALS['wpdb']->source_url_matches['https://drive.google.com/file/d/drive_duplicate_123/view'] = 789;

		$urls = \uimptr_extract_urls_from_csv_content( "url,title\n{$drive_url},Duplicate Drive\n", false, false );

		$this->assertCount( 1, $urls );
		$this->assertSame( $drive_url, $urls[0]['url'] );
		$this->assertSame( 'Duplicate Drive', $urls[0]['metadata']['title'] );
	}

	private function wordpressExportXml( array $attachments ): string {
		$items = '';
		foreach ( $attachments as $attachment ) {
			$items .= sprintf(
				'<item>
					<title>%s</title>
					<guid>%s</guid>
					<pubDate>%s</pubDate>
					<content:encoded><![CDATA[%s]]></content:encoded>
					<wp:post_type>attachment</wp:post_type>
					<wp:post_date>%s</wp:post_date>
					<wp:attachment_url>%s</wp:attachment_url>
				</item>',
				htmlspecialchars( $attachment['title'], ENT_XML1 ),
				htmlspecialchars( $attachment['url'], ENT_XML1 ),
				htmlspecialchars( $attachment['date'], ENT_XML1 ),
				$attachment['body'],
				htmlspecialchars( $attachment['date'], ENT_XML1 ),
				htmlspecialchars( $attachment['url'], ENT_XML1 )
			);
		}

		return '<?xml version="1.0" encoding="UTF-8"?>
			<rss version="2.0"
				xmlns:content="http://purl.org/rss/1.0/modules/content/"
				xmlns:wp="http://wordpress.org/export/1.2/">
				<channel>' . $items . '</channel>
			</rss>';
	}
}
