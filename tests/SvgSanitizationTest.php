<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use WP_Error;

class SvgSanitizationTest extends WpTestCase {
	public function test_dom_svg_sanitizer_removes_scripts_events_and_unsafe_links(): void {
		$svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">
	<title>Safe title</title>
	<script>alert(1)</script>
	<foreignObject><p>HTML</p></foreignObject>
	<use href="#safe-id" xlink:href="javascript:alert(1)" />
	<rect width="10" height="10" fill="red" onclick="alert(2)" style="fill:blue;stroke-width:2;behavior:url(http://evil.test/x);background:url(javascript:bad)" />
</svg>
SVG;

		$sanitized = \uimptr_sanitize_svg_content_with_dom( $svg );

		$this->assertIsString( $sanitized );
		$this->assertStringContainsString( '<title>Safe title</title>', $sanitized );
		$this->assertStringContainsString( 'href="#safe-id"', $sanitized );
		$this->assertStringContainsString( 'style="fill:blue;stroke-width:2"', $sanitized );
		$this->assertStringNotContainsString( '<script', $sanitized );
		$this->assertStringNotContainsString( 'foreignObject', $sanitized );
		$this->assertStringNotContainsString( 'onload', $sanitized );
		$this->assertStringNotContainsString( 'onclick', $sanitized );
		$this->assertStringNotContainsString( 'javascript:', $sanitized );
		$this->assertStringNotContainsString( 'behavior', $sanitized );
	}

	public function test_svg_loader_rejects_doctype_and_entity_declarations(): void {
		$doctype = '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>';

		$result = \uimptr_load_svg_document_securely( $doctype );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsafe_svg', $result->get_error_code() );
	}

	public function test_svg_loader_rejects_malformed_xml(): void {
		$result = \uimptr_load_svg_document_securely( '<svg><rect></svg>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_svg', $result->get_error_code() );
	}

	public function test_style_sanitizer_keeps_only_whitelisted_safe_properties(): void {
		$style = \uimptr_sanitize_svg_style_attribute(
			'fill:#fff; position:absolute; stroke-width:2; background:url(javascript:alert(1)); opacity:0.5;'
		);

		$this->assertSame( 'fill:#fff;stroke-width:2;opacity:0.5', $style );
	}

	public function test_svg_attribute_value_safety_blocks_script_protocols_and_external_urls(): void {
		$this->assertTrue( \uimptr_is_safe_svg_attribute_value( 'fill', 'url(#gradient)' ) );
		$this->assertFalse( \uimptr_is_safe_svg_attribute_value( 'fill', 'url(https://evil.test/gradient)' ) );
		$this->assertFalse( \uimptr_is_safe_svg_attribute_value( 'href', 'javascript:alert(1)' ) );
		$this->assertFalse( \uimptr_is_safe_svg_attribute_value( 'id', "safe\x01bad" ) );
		$this->assertTrue( \uimptr_is_safe_local_svg_reference( '#icon:1' ) );
		$this->assertFalse( \uimptr_is_safe_local_svg_reference( 'https://example.test/#icon' ) );
	}

	public function test_public_svg_sanitizer_uses_available_sanitizer_and_rejects_invalid_svg(): void {
		$sanitized = \uimptr_sanitize_svg_content( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>' );

		$this->assertIsString( $sanitized );
		$this->assertStringNotContainsString( '<script', $sanitized );
		$this->assertFalse( \uimptr_sanitize_svg_content( '<not-svg />' ) );
	}
}
