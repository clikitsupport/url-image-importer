<?php
/**
 * Popup for Subscribe (shown once the free scan results are ready).
 *
 * @package UrlImageImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="modal fade" id="subscribe-modal" tabindex="-1" role="dialog" aria-labelledby="subscribe-modal-label" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content uimptr-subscribe">
			<button type="button" class="uimptr-subscribe__close" data-dismiss="modal" aria-label="<?php esc_attr_e( 'Close', 'url-image-importer' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
			</button>
			<div class="modal-body">
				<form action="https://infiniteuploads.us10.list-manage.com/subscribe/post?u=c50f189b795383e791f477637&amp;id=4f5e536a46&amp;SOURCE=BFU_Plugin" method="post" id="mc-embedded-subscribe-form" name="mc-embedded-subscribe-form" class="validate" target="_blank" novalidate>

					<h4 class="uimptr-subscribe__title" id="subscribe-modal-label"><?php esc_html_e( 'Get Media Management Tips & Tricks', 'url-image-importer' ); ?></h4>
					<p class="uimptr-subscribe__lead"><?php esc_html_e( 'Subscribe to receive tips for managing large files in WordPress and making your media library infinitely scalable with cloud storage from Infinite Uploads.', 'url-image-importer' ); ?></p>

					<div id="mce-responses" class="uimptr-subscribe__responses">
						<div id="mce-error-response" class="response alert alert-warning alert-dismissible fade show" role="alert" style="display:none"></div>
						<div id="mce-success-response" class="response alert alert-success alert-dismissible fade show" role="alert" style="display:none"></div>
					</div>

					<div class="uimptr-subscribe__field">
						<span class="uimptr-subscribe__field-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
						</span>
						<div style="position: absolute; left: -5000px;" aria-hidden="true">
							<input type="text" name="b_c50f189b795383e791f477637_4f5e536a46" tabindex="-1" value="">
						</div>
						<label for="mce-EMAIL" class="sr-only"><?php esc_html_e( 'Email Address', 'url-image-importer' ); ?></label>
						<input type="email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" name="EMAIL" class="required email bfu-input-subscribe" id="mce-EMAIL" placeholder="<?php esc_attr_e( 'Enter your email', 'url-image-importer' ); ?>">
					</div>

					<p class="uimptr-subscribe__fine">
						<?php esc_html_e( 'Optional – no spam, unsubscribe at any time!', 'url-image-importer' ); ?>
						<a href="<?php echo esc_url( 'https://infiniteuploads.com/privacy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'url-image-importer' ); ?></a>
					</p>

					<div class="uimptr-subscribe__actions">
						<button id="bfu-subscribe-button" class="btn text-nowrap btn-primary btn-lg" type="submit"><?php esc_html_e( 'Subscribe & View Results', 'url-image-importer' ); ?></button>
					</div>

					<p class="uimptr-subscribe__skip">
						<a id="bfu-view-results" role="button"><?php esc_html_e( 'No thanks, view results without subscribing.', 'url-image-importer' ); ?></a>
					</p>

				</form>
			</div>
		</div>
	</div>
</div>
