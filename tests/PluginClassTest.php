<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use Uimptr_Test_Json_Response;
use UrlImageImporter\Core\Plugin;

class PluginClassTest extends WpTestCase {
	public function test_plugin_exposes_path_and_url(): void {
		$plugin = Plugin::get_instance();

		$this->assertSame( UIMPTR_PATH, $plugin->get_plugin_path() );
		$this->assertStringEndsWith( '/url-image-importer/', $plugin->get_plugin_url() );
	}

	public function test_admin_styles_only_enqueue_on_plugin_page(): void {
		$plugin = Plugin::get_instance();

		$plugin->admin_styles();
		$this->assertSame( array(), $GLOBALS['uimptr_test_enqueued']['styles'] );

		$_GET['page'] = 'import-images-url';
		$plugin->admin_styles();

		$this->assertArrayHasKey( 'uimptr-bootstrap', $GLOBALS['uimptr_test_enqueued']['styles'] );
		$this->assertArrayHasKey( 'uimptr-js', $GLOBALS['uimptr_test_enqueued']['scripts'] );
		$this->assertSame( 'https://example.test/wp-admin/admin-ajax.php', $GLOBALS['uimptr_test_enqueued']['localized']['uimptr-js']['bfu_data']['ajax_url'] );
		$this->assertSame( 'image/svg+xml', $GLOBALS['uimptr_test_enqueued']['localized']['uimptr-js']['bfu_data']['local_types']['svg'] );
		$this->assertSame( 'nonce-ajax-nonce', $GLOBALS['uimptr_test_enqueued']['localized']['uimptr-js']['bfu_data']['uimptr_nonce'] );
	}

	public function test_plugin_action_links_prepend_settings_support_and_upgrade(): void {
		$links = Plugin::get_instance()->plugin_action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertArrayHasKey( 'settings', $links );
		$this->assertArrayHasKey( 'support', $links );
		$this->assertArrayHasKey( 'upgrade', $links );
		$this->assertArrayHasKey( 'deactivate', $links );
		$this->assertStringContainsString( 'upload.php?page=import-images-url', $links['settings'] );
		$this->assertStringContainsString( 'utm_campaign=plugin_links', $links['upgrade'] );
	}

	public function test_ajax_stop_import_sets_stop_signal_and_stopped_progress(): void {
		$_REQUEST['nonce'] = 'nonce-uimptr_ajax';
		$site_hash         = substr( md5( home_url() ), 0, 8 );
		$progress_key      = 'uimptr_progress_' . $site_hash . '_7';
		$stop_key          = 'uimptr_import_stop_' . $site_hash . '_7';
		set_transient(
			$progress_key,
			array(
				'total'     => 5,
				'processed' => 2,
				'success'   => 2,
			),
			HOUR_IN_SECONDS
		);

		try {
			Plugin::get_instance()->ajax_stop_import();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( 'Import stop signal sent', $response->data['message'] );
			$this->assertSame( $progress_key, $response->data['progress_key'] );
		}

		$this->assertIsArray( get_transient( $stop_key ) );
		$progress = get_transient( $progress_key );
		$this->assertSame( 'stopped', $progress['status'] );
		$this->assertTrue( $progress['stopped'] );
		$this->assertSame( 5, $progress['total'] );
		$this->assertSame( 2, $progress['processed'] );
	}

	public function test_ajax_get_import_progress_reports_stop_signal_even_without_active_progress(): void {
		$_REQUEST['nonce'] = 'nonce-uimptr_ajax';
		$site_hash         = substr( md5( home_url() ), 0, 8 );
		$progress_key      = 'uimptr_progress_' . $site_hash . '_7';
		$stop_key          = 'uimptr_import_stop_' . $site_hash . '_7';
		set_transient( $stop_key, array( 'requested_at' => time() ), HOUR_IN_SECONDS );

		try {
			Plugin::get_instance()->ajax_get_import_progress();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertSame( 'stopped', $response->data['status'] );
			$this->assertTrue( $response->data['stopped'] );
			$this->assertSame( 'Import stopped by user', $response->data['status_text'] );
		}

		$this->assertSame( 'stopped', get_transient( $progress_key )['status'] );
	}
}
