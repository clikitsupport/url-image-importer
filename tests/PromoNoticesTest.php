<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use Uimptr_Test_Json_Response;
use UrlImageImporter\Admin\PromoNotices;

class PromoNoticesTest extends WpTestCase {
	public function test_upgrade_url_contains_tracking_source(): void {
		$url = PromoNotices::get_upgrade_url( 'Plugin Links!' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://infiniteuploads.com/big-file-form-uploads/', $url );
		$this->assertSame( 'url_image_importer', $query['utm_source'] );
		$this->assertSame( 'plugin', $query['utm_medium'] );
		$this->assertSame( 'pluginlinks', $query['utm_campaign'] );
	}

	public function test_display_notices_renders_promo_and_enqueues_script_on_allowed_screen(): void {
		$notices = new PromoNotices();

		ob_start();
		$notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-notice-id="big_file_form_uploads_promo"', $html );
		$this->assertStringContainsString( 'Complete Your File Management Setup', $html );
		$this->assertArrayHasKey( 'uimptr-promo-notices', $GLOBALS['uimptr_test_enqueued']['scripts'] );
		$this->assertSame( 'nonce-uimptr_ajax', $GLOBALS['uimptr_test_enqueued']['localized']['uimptr-promo-notices']['uimptrPromo']['nonce'] );
	}

	public function test_display_notices_skips_when_user_dismissed_or_plugin_is_active(): void {
		update_user_meta( 7, 'uimptr_notice_big_file_form_uploads_promo', 'dismissed' );
		$notices = new PromoNotices();

		ob_start();
		$notices->display_notices();
		$html = ob_get_clean();
		$this->assertSame( '', trim( $html ) );

		\uimptr_tests_reset_environment();
		$GLOBALS['uimptr_test_active_plugins']['tuxedo-big-file-uploads/tuxedo_big_file_uploads.php'] = true;
		$notices = new PromoNotices();
		ob_start();
		$notices->display_notices();
		$html = ob_get_clean();
		$this->assertSame( '', trim( $html ) );
	}

	public function test_handle_promo_action_records_delay_dismiss_and_link_actions(): void {
		$notices = new PromoNotices();

		$_POST['notice_id']   = 'big_file_form_uploads_promo';
		$_POST['action_type'] = 'delay';
		$this->callPromoActionExpectingSuccess( $notices );
		$delay = $GLOBALS['uimptr_test_user_meta'][7]['uimptr_notice_big_file_form_uploads_promo'];
		$this->assertSame( 'delay', $delay['action'] );
		$this->assertGreaterThan( time(), $delay['show_after'] );

		$_POST['action_type'] = 'dismiss';
		$this->callPromoActionExpectingSuccess( $notices );
		$this->assertSame( 'dismissed', $GLOBALS['uimptr_test_user_meta'][7]['uimptr_notice_big_file_form_uploads_promo'] );

		$_POST['action_type'] = 'link';
		$this->callPromoActionExpectingSuccess( $notices );
		$this->assertSame( 'visited', $GLOBALS['uimptr_test_user_meta'][7]['uimptr_notice_big_file_form_uploads_promo'] );
	}

	public function test_handle_promo_action_denies_users_without_manage_options(): void {
		$GLOBALS['uimptr_test_current_user_can'] = false;
		$notices = new PromoNotices();
		$_POST['notice_id']   = 'big_file_form_uploads_promo';
		$_POST['action_type'] = 'dismiss';

		try {
			$notices->handle_promo_action();
			$this->fail( 'Expected JSON error response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertFalse( $response->success );
			$this->assertSame( 'Permission denied', $response->data );
		}
	}

	private function callPromoActionExpectingSuccess( PromoNotices $notices ): void {
		try {
			$notices->handle_promo_action();
			$this->fail( 'Expected JSON success response.' );
		} catch ( Uimptr_Test_Json_Response $response ) {
			$this->assertTrue( $response->success );
			$this->assertNull( $response->data );
		}
	}
}
