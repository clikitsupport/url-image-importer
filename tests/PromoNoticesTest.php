<?php

namespace Uimptr\Tests;

use Uimptr\Tests\Support\WpTestCase;
use Uimptr_Test_Json_Response;
use UrlImageImporter\Admin\PromoNotices;

class PromoNoticesTest extends WpTestCase {
	public function test_upgrade_url_contains_tracking_source(): void {
		$url = PromoNotices::get_upgrade_url( 'Plugin Links!' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://infiniteuploads.com/', $url );
		$this->assertStringNotContainsString( 'big-file-form-uploads', $url );
		$this->assertSame( 'url_image_importer', $query['utm_source'] );
		$this->assertSame( 'plugin', $query['utm_medium'] );
		$this->assertSame( 'pluginlinks', $query['utm_campaign'] );
	}

	public function test_pricing_url_points_to_pricing_page_with_tracking(): void {
		$url = PromoNotices::get_pricing_url( 'Admin Notice!' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://infiniteuploads.com/pricing/', $url );
		$this->assertSame( 'url_image_importer', $query['utm_source'] );
		$this->assertSame( 'plugin', $query['utm_medium'] );
		$this->assertSame( 'adminnotice', $query['utm_campaign'] );
	}

	public function test_display_notices_renders_promo_and_enqueues_script_on_allowed_screen(): void {
		$notices = new PromoNotices();

		ob_start();
		$notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-notice-id="infinite_uploads_promo"', $html );
		$this->assertStringContainsString( 'Scale Your WordPress Media Library. Upgrade to Infinite Uploads', $html );
		$this->assertStringContainsString( 'Infinite Uploads adds folders, smart organization, cloud storage, CDN delivery, and media scalability', $html );
		$this->assertStringContainsString( 'Start 7 Day Free Trial', $html );
		$this->assertStringContainsString( 'Try for Free', $html );
		$this->assertStringContainsString( 'Remind Me Later', $html );
		$this->assertStringContainsString( 'infiniteuploads.com/pricing/', $html );
		$this->assertStringNotContainsString( 'Big File Form Uploads', $html );
		$this->assertStringNotContainsString( 'uimptr-notice-content', $html );
		$this->assertStringNotContainsString( 'uimptr-notice-icon', $html );
		$this->assertArrayHasKey( 'uimptr-promo-notices', $GLOBALS['uimptr_test_enqueued']['scripts'] );
		$this->assertArrayHasKey( 'uimptr-promo-notice', $GLOBALS['uimptr_test_enqueued']['styles'] );
		$this->assertSame( 'nonce-uimptr_ajax', $GLOBALS['uimptr_test_enqueued']['localized']['uimptr-promo-notices']['uimptrPromo']['nonce'] );
	}

	public function test_display_notices_skips_when_user_dismissed_or_plugin_is_active(): void {
		update_user_meta( 7, 'uimptr_notice_infinite_uploads_promo', 'dismissed' );
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

		\uimptr_tests_reset_environment();
		$GLOBALS['uimptr_test_active_plugins']['infinite-uploads/infinite-uploads.php'] = true;
		$notices = new PromoNotices();
		ob_start();
		$notices->display_notices();
		$html = ob_get_clean();
		$this->assertSame( '', trim( $html ) );
	}

	public function test_handle_promo_action_records_delay_dismiss_and_link_actions(): void {
		$notices = new PromoNotices();

		$_POST['notice_id']   = 'infinite_uploads_promo';
		$_POST['action_type'] = 'delay';
		$this->callPromoActionExpectingSuccess( $notices );
		$delay = $GLOBALS['uimptr_test_user_meta'][7]['uimptr_notice_infinite_uploads_promo'];
		$this->assertSame( 'delay', $delay['action'] );
		$this->assertGreaterThan( time(), $delay['show_after'] );

		$_POST['action_type'] = 'dismiss';
		$this->callPromoActionExpectingSuccess( $notices );
		$this->assertSame( 'dismissed', $GLOBALS['uimptr_test_user_meta'][7]['uimptr_notice_infinite_uploads_promo'] );

		$_POST['action_type'] = 'link';
		$this->callPromoActionExpectingSuccess( $notices );
		$this->assertSame( 'visited', $GLOBALS['uimptr_test_user_meta'][7]['uimptr_notice_infinite_uploads_promo'] );
	}

	public function test_handle_promo_action_denies_users_without_manage_options(): void {
		$GLOBALS['uimptr_test_current_user_can'] = false;
		$notices = new PromoNotices();
		$_POST['notice_id']   = 'infinite_uploads_promo';
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
