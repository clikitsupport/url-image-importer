<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;

/**
 * Covers uimptr_parse_image_urls_input(), which splits the "Image URLs"
 * textarea on newlines and/or commas. Added when the field gained support for
 * pasted comma-separated lists.
 */
class UrlInputParsingTest extends WpTestCase {
	public function test_splits_newline_separated_urls(): void {
		$input = "https://example.com/a.jpg\nhttps://example.com/b.jpg";

		$this->assertSame(
			array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ),
			\uimptr_parse_image_urls_input( $input )
		);
	}

	public function test_splits_comma_separated_urls(): void {
		$input = 'https://example.com/a.jpg, https://example.com/b.jpg,https://example.com/c.jpg';

		$this->assertSame(
			array(
				'https://example.com/a.jpg',
				'https://example.com/b.jpg',
				'https://example.com/c.jpg',
			),
			\uimptr_parse_image_urls_input( $input )
		);
	}

	public function test_splits_mixed_comma_and_newline_separators(): void {
		$input = "https://example.com/a.jpg, https://example.com/b.jpg\nhttps://example.com/c.jpg";

		$this->assertSame(
			array(
				'https://example.com/a.jpg',
				'https://example.com/b.jpg',
				'https://example.com/c.jpg',
			),
			\uimptr_parse_image_urls_input( $input )
		);
	}

	public function test_trims_whitespace_and_drops_empty_entries(): void {
		// Trailing comma, blank lines, CRLF newlines and padding around each URL.
		$input = "  https://example.com/a.jpg  ,\r\n\r\n , https://example.com/b.jpg ,";

		$this->assertSame(
			array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ),
			\uimptr_parse_image_urls_input( $input )
		);
	}

	public function test_real_world_google_drive_share_links(): void {
		// The exact comma-separated paste shape from the support screenshot.
		$input = 'https://drive.google.com/file/d/1oYofi_W7Y9YfolQb-DzzMRcUmKGw0LB1/view?usp=drive_link, '
			. 'https://drive.google.com/file/d/1en1Gs2Yqjz7XDuD1Hmh7s-ClFdge7wpC/view?usp=drive_link, '
			. 'https://drive.google.com/file/d/1RF9cycDqd89ROZWGu3o7MuThKUrbyA5Q/view?usp=drive_link';

		$this->assertSame(
			array(
				'https://drive.google.com/file/d/1oYofi_W7Y9YfolQb-DzzMRcUmKGw0LB1/view?usp=drive_link',
				'https://drive.google.com/file/d/1en1Gs2Yqjz7XDuD1Hmh7s-ClFdge7wpC/view?usp=drive_link',
				'https://drive.google.com/file/d/1RF9cycDqd89ROZWGu3o7MuThKUrbyA5Q/view?usp=drive_link',
			),
			\uimptr_parse_image_urls_input( $input )
		);
	}

	public function test_preserves_percent_encoded_commas_within_a_url(): void {
		// A literal comma inside a URL is percent-encoded (%2C) and must not split.
		$input = 'https://example.com/img.jpg?list=a%2Cb%2Cc';

		$this->assertSame(
			array( 'https://example.com/img.jpg?list=a%2Cb%2Cc' ),
			\uimptr_parse_image_urls_input( $input )
		);
	}

	public function test_empty_input_returns_empty_list(): void {
		$this->assertSame( array(), \uimptr_parse_image_urls_input( '' ) );
		$this->assertSame( array(), \uimptr_parse_image_urls_input( "  ,\n , \r\n" ) );
	}
}
