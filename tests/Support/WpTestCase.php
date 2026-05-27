<?php

namespace Uimptr\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class WpTestCase extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\uimptr_tests_reset_environment();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();

		parent::tearDown();
	}

	protected function pngBytes(): string {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR42mNk+M8AAwUBAd7U0eUAAAAASUVORK5CYII=' );
	}

	protected function mockHttpResponse( string $url, string $body, int $status = 200, array $headers = array() ): void {
		$GLOBALS['uimptr_test_http_responses'][ $url ] = array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}
}
