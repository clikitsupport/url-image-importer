<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use WP_Error;

class ImageImportTest extends WpTestCase {
	public function test_import_stops_before_http_when_batch_is_cancelled(): void {
		set_transient( \uimptr_get_batch_cancel_transient_key( 'batch-a' ), true, 300 );

		$result = \uimptr_import_image_from_url( 'https://cdn.example.test/image.png', 'batch-a' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'import_cancelled', $result->get_error_code() );
	}

	public function test_import_wraps_remote_errors_and_non_200_statuses(): void {
		$result = \uimptr_import_image_from_url( 'https://cdn.example.test/not-mocked.png' );
		$this->assertSame( 'image_download_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'No mocked response', $result->get_error_message() );

		$this->mockHttpResponse( 'https://cdn.example.test/404.png', 'not found', 404 );
		$result = \uimptr_import_image_from_url( 'https://cdn.example.test/404.png' );
		$this->assertSame( 'image_download_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'HTTP status: 404', $result->get_error_message() );
	}

	public function test_import_rejects_empty_and_invalid_image_bodies(): void {
		$this->mockHttpResponse( 'https://cdn.example.test/empty.png', '', 200, array( 'content-type' => 'image/png' ) );
		$this->assertSame( 'invalid_image', \uimptr_import_image_from_url( 'https://cdn.example.test/empty.png' )->get_error_code() );

		$this->mockHttpResponse( 'https://cdn.example.test/not-image.png', 'plain text', 200, array( 'content-type' => 'image/png' ) );
		$this->assertSame( 'invalid_image', \uimptr_import_image_from_url( 'https://cdn.example.test/not-image.png' )->get_error_code() );
	}

	public function test_import_uses_content_disposition_filename_and_persists_attachment_metadata(): void {
		$url = 'https://cdn.example.test/render?id=abc';
		$this->mockHttpResponse(
			$url,
			$this->pngBytes(),
			200,
			array(
				'content-type'        => 'image/png; charset=binary',
				'content-disposition' => "attachment; filename*=UTF-8''hero%20image.png",
			)
		);

		$attachment_id = \uimptr_import_image_from_url(
			$url,
			null,
			array(
				'title'       => 'Original.File.PNG',
				'description' => '<b>Caption</b> body',
				'alt_text'    => 'Alt <em>text</em>',
				'date'        => '2021-07-08 09:10:11',
			),
			true
		);

		$this->assertSame( 1001, $attachment_id );
		$post = $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ];

		$this->assertSame( 'image/png', $post['post_mime_type'] );
		$this->assertSame( 'Original.File', $post['post_title'] );
		$this->assertSame( 'original-file', $post['post_name'] );
		$this->assertSame( 'Caption body', $post['post_content'] );
		$this->assertSame( 'Caption body', $post['post_excerpt'] );
		$this->assertSame( '2021-07-08 09:10:11', $post['post_date'] );
		$this->assertSame( '2021-07-08 09:10:11', $post['post_date_gmt'] );
		$this->assertFileExists( $post['file'] );
		$this->assertSame( 'hero-image.png', basename( $post['file'] ) );
		$this->assertSame( $url, $GLOBALS['uimptr_test_post_meta'][ $attachment_id ]['_uimptr_source_url'] );
		$this->assertSame( 'Alt text', $GLOBALS['uimptr_test_post_meta'][ $attachment_id ]['_wp_attachment_image_alt'] );
		$this->assertSame( 'hero-image.png', $GLOBALS['uimptr_test_attachment_meta'][ $attachment_id ]['file'] );
	}

	public function test_import_fetches_over_ssrf_safe_http_client(): void {
		// Regression guard for the SSRF fix: the user-supplied URL must be fetched with
		// wp_safe_remote_get() (reject_unsafe_urls), never the unprotected wp_remote_get().
		$url = 'https://cdn.example.test/photo.png';
		$this->mockHttpResponse( $url, $this->pngBytes(), 200, array( 'content-type' => 'image/png' ) );

		\uimptr_import_image_from_url( $url );

		$this->assertContains( $url, $GLOBALS['uimptr_test_safe_remote_get_calls'] );
	}

	public function test_google_drive_chunked_download_uses_ssrf_safe_http_client(): void {
		$GLOBALS['uimptr_test_active_plugins']['tuxedo-big-file-uploads/tuxedo_big_file_uploads.php'] = true;

		$url          = 'https://drive.google.com/file/d/safe_drive_image_123/view?usp=sharing';
		$download_url = \uimptr_get_google_drive_download_url( $url );
		$this->mockHttpResponse(
			$download_url,
			$this->pngBytes(),
			200,
			array(
				'content-type'        => 'image/png',
				'content-disposition' => 'attachment; filename="Drive Image.png"',
			)
		);

		\uimptr_import_image_from_url( $url, null, array( 'title' => 'Drive Image.png' ) );

		$this->assertContains( $download_url, $GLOBALS['uimptr_test_safe_remote_get_calls'] );
	}

	public function test_ip_is_blocked_covers_internal_and_reserved_ranges(): void {
		$blocked = array( '169.254.169.254', '127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.1.1', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1' );
		foreach ( $blocked as $ip ) {
			$this->assertTrue( \uimptr_ip_is_blocked( $ip ), "$ip should be blocked" );
		}

		$allowed = array( '8.8.8.8', '203.0.113.10', '1.1.1.1', '2606:4700:4700::1111' );
		foreach ( $allowed as $ip ) {
			$this->assertFalse( \uimptr_ip_is_blocked( $ip ), "$ip should be allowed" );
		}
	}

	public function test_validate_remote_url_blocks_link_local_metadata_endpoint(): void {
		// The headline PoC vector. wp_safe_remote_get() alone does not catch this.
		$result = \uimptr_validate_remote_url_is_safe( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ssrf_blocked_url', $result->get_error_code() );
	}

	public function test_validate_remote_url_rejects_non_http_schemes_and_unresolvable_hosts(): void {
		$this->assertInstanceOf( WP_Error::class, \uimptr_validate_remote_url_is_safe( 'file:///etc/passwd' ) );
		$this->assertInstanceOf( WP_Error::class, \uimptr_validate_remote_url_is_safe( 'ftp://example.test/x' ) );

		// Fail closed when a host cannot be resolved.
		$GLOBALS['uimptr_test_host_ips']['unresolvable.example.test'] = array();
		$this->assertInstanceOf( WP_Error::class, \uimptr_validate_remote_url_is_safe( 'http://unresolvable.example.test/x.png' ) );
	}

	public function test_validate_remote_url_allows_public_hosts(): void {
		$GLOBALS['uimptr_test_host_ips']['cdn.example.test'] = array( '203.0.113.10' );
		$this->assertTrue( \uimptr_validate_remote_url_is_safe( 'https://cdn.example.test/photo.png' ) );
	}

	public function test_import_blocks_url_resolving_to_internal_ip(): void {
		// A public-looking hostname that (maliciously) resolves to the metadata endpoint.
		$GLOBALS['uimptr_test_host_ips']['metadata.example.test'] = array( '169.254.169.254' );

		$result = \uimptr_import_image_from_url( 'https://metadata.example.test/logo.png' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'internal or reserved', $result->get_error_message() );
		$this->assertNotContains( 'https://metadata.example.test/logo.png', $GLOBALS['uimptr_test_safe_remote_get_calls'] );
	}

	public function test_import_blocks_redirect_hop_to_internal_ip(): void {
		// Public entry point that 302-redirects to an internal target: each hop is re-validated.
		$this->mockHttpResponse(
			'https://redirector.example.test/go.png',
			'',
			302,
			array( 'location' => 'http://internal.example.test/latest/meta-data/' )
		);
		$GLOBALS['uimptr_test_host_ips']['redirector.example.test'] = array( '203.0.113.10' );
		$GLOBALS['uimptr_test_host_ips']['internal.example.test']   = array( '169.254.169.254' );

		$result = \uimptr_import_image_from_url( 'https://redirector.example.test/go.png' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'internal or reserved', $result->get_error_message() );
	}

	public function test_import_uses_big_file_uploads_sideload_path_when_available(): void {
		$GLOBALS['uimptr_test_active_plugins']['tuxedo-big-file-uploads/tuxedo_big_file_uploads.php'] = true;

		$url          = 'https://drive.google.com/file/d/big_drive_image_123/view?usp=sharing';
		$download_url = \uimptr_get_google_drive_download_url( $url );
		$this->mockHttpResponse(
			$download_url,
			$this->pngBytes(),
			200,
			array(
				'content-type'        => 'image/png',
				'content-disposition' => 'attachment; filename="Large Drive Image.png"',
			)
		);

		$attachment_id = \uimptr_import_image_from_url( $url, null, array( 'title' => 'Large Drive Image.png' ) );
		$post          = $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ];

		$this->assertSame( 1001, $attachment_id );
		$this->assertCount( 1, $GLOBALS['uimptr_test_media_handle_upload_calls'] );
		$this->assertSame( 'async-upload', $GLOBALS['uimptr_test_media_handle_upload_calls'][0]['file_id'] );
		$this->assertSame(
			array(
				'action'    => 'wp_handle_sideload',
				'test_form' => false,
			),
			$GLOBALS['uimptr_test_media_handle_upload_calls'][0]['overrides']
		);
		$this->assertSame( 'Large-Drive-Image.png', basename( $post['file'] ) );
		$this->assertSame( 'Large Drive Image', $post['post_title'] );
		$this->assertSame(
			'https://drive.google.com/file/d/big_drive_image_123/view',
			$GLOBALS['uimptr_test_post_meta'][ $attachment_id ]['_uimptr_source_url']
		);
	}

	public function test_google_drive_big_file_uploads_path_downloads_in_range_chunks(): void {
		$GLOBALS['uimptr_test_active_plugins']['tuxedo-big-file-uploads/tuxedo_big_file_uploads.php'] = true;

		$url          = 'https://drive.google.com/file/d/chunked_drive_image_123/view?usp=sharing';
		$download_url = \uimptr_get_google_drive_download_url( $url );
		$image        = $this->pngBytes() . str_repeat( 'a', 600 );
		$ranges       = array();

		\add_filter(
			'uimptr_google_drive_download_chunk_size',
			function() {
				return 128;
			}
		);

		$GLOBALS['uimptr_test_http_callback'] = function( $request_url, $args ) use ( $download_url, $image, &$ranges ) {
			$this->assertSame( $download_url, $request_url );
			$range = $args['headers']['Range'] ?? '';
			$this->assertMatchesRegularExpression( '/^bytes=\d+-\d+$/', $range );

			$ranges[] = $range;
			preg_match( '/bytes=(\d+)-(\d+)/', $range, $matches );

			$start = (int) $matches[1];
			$end   = min( (int) $matches[2], strlen( $image ) - 1 );
			$body  = substr( $image, $start, $end - $start + 1 );

			return array(
				'response' => array( 'code' => 206 ),
				'headers'  => array(
					'content-type'        => 'image/png',
					'content-disposition' => 'attachment; filename="Chunked Drive Image.png"',
					'content-range'       => sprintf( 'bytes %d-%d/%d', $start, $end, strlen( $image ) ),
					'content-length'      => strlen( $body ),
				),
				'body'     => $body,
			);
		};

		$attachment_id = \uimptr_import_image_from_url( $url );
		$post          = $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ];

		$this->assertGreaterThan( 1, count( $ranges ) );
		$this->assertSame( 'bytes=0-127', $ranges[0] );
		$this->assertSame( 'async-upload', $GLOBALS['uimptr_test_media_handle_upload_calls'][0]['file_id'] );
		$this->assertSame( 'Chunked-Drive-Image.png', basename( $post['file'] ) );
	}

	public function test_import_infers_extensionless_png_from_content_type(): void {
		$url = 'https://images.unsplash.com/photo-123';
		$this->mockHttpResponse( $url, $this->pngBytes(), 200, array( 'content-type' => 'image/png' ) );

		$attachment_id = \uimptr_import_image_from_url( $url, null, array( 'title' => 'Unsplash Hero' ) );
		$post          = $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ];

		$this->assertSame( 'photo-123.png', basename( $post['file'] ) );
		$this->assertSame( 'Unsplash Hero', $post['post_title'] );
		$this->assertSame( 'Unsplash Hero', $GLOBALS['uimptr_test_post_meta'][ $attachment_id ]['_wp_attachment_image_alt'] );
	}

	public function test_import_sanitizes_svg_before_saving(): void {
		$url = 'https://cdn.example.test/icon.svg';
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="1" height="1"/></svg>';
		$this->mockHttpResponse( $url, $svg, 200, array( 'content-type' => 'image/svg+xml' ) );

		$attachment_id = \uimptr_import_image_from_url( $url, null, array( 'title' => 'Icon.svg' ) );
		$post          = $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ];
		$saved         = file_get_contents( $post['file'] );

		$this->assertSame( 'image/svg+xml', $post['post_mime_type'] );
		$this->assertStringNotContainsString( '<script', $saved );
		$this->assertStringNotContainsString( 'onload', $saved );
		$this->assertSame( 'Icon', $post['post_title'] );
	}

	public function test_import_rejects_allowed_mime_mismatch(): void {
		$GLOBALS['uimptr_test_allowed_mimes'] = array( 'jpg|jpeg|jpe' => 'image/jpeg' );
		$url = 'https://cdn.example.test/disallowed.png';
		$this->mockHttpResponse( $url, $this->pngBytes(), 200, array( 'content-type' => 'image/png' ) );

		$result = \uimptr_import_image_from_url( $url );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_image_mime', $result->get_error_code() );
	}

	public function test_import_resolves_google_drive_link_and_stores_canonical_source_url(): void {
		$drive_url    = 'https://drive.google.com/file/d/drive_image_123/view?usp=sharing&resourcekey=0-key';
		$download_url = \uimptr_get_google_drive_download_url( $drive_url );
		$this->mockHttpResponse(
			$download_url,
			$this->pngBytes(),
			200,
			array(
				'content-type'        => 'image/png',
				'content-disposition' => 'attachment; filename="Drive Hero.png"',
			)
		);

		$attachment_id = \uimptr_import_image_from_url( $drive_url, null, array( 'title' => 'Drive Hero.png' ) );
		$post          = $GLOBALS['uimptr_test_inserted_posts'][ $attachment_id ];

		$this->assertSame( 1001, $attachment_id );
		$this->assertSame( 'image/png', $post['post_mime_type'] );
		$this->assertSame( 'Drive Hero', $post['post_title'] );
		$this->assertSame( 'Drive-Hero.png', basename( $post['file'] ) );
		$this->assertSame(
			'https://drive.google.com/file/d/drive_image_123/view',
			$GLOBALS['uimptr_test_post_meta'][ $attachment_id ]['_uimptr_source_url']
		);
	}

	public function test_import_rejects_google_drive_login_or_permission_pages_as_not_public_images(): void {
		$drive_url    = 'https://drive.google.com/open?id=private_drive_image';
		$download_url = \uimptr_get_google_drive_download_url( $drive_url );
		$this->mockHttpResponse(
			$download_url,
			'<html><body>Sign in to continue</body></html>',
			200,
			array( 'content-type' => 'text/html; charset=utf-8' )
		);

		$result = \uimptr_import_image_from_url( $drive_url );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'google_drive_not_public_image', $result->get_error_code() );
	}

	public function test_import_rejects_public_google_drive_non_images_as_skippable_non_images(): void {
		$drive_url    = 'https://drive.google.com/uc?id=drive_pdf_123&export=download';
		$download_url = \uimptr_get_google_drive_download_url( $drive_url );
		$this->mockHttpResponse(
			$download_url,
			'%PDF-1.7',
			200,
			array( 'content-type' => 'application/pdf' )
		);

		$result = \uimptr_import_image_from_url( $drive_url );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'google_drive_non_image', $result->get_error_code() );
		$this->assertTrue( \uimptr_is_skippable_import_error( $result ) );
	}
}
