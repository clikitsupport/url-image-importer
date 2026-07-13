<?php
/**
 * Storage Usage Analysis (scan results).
 *
 * @package UrlImageImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uimptr_iu_cloud    = plugins_url( '/assets/img/iu-logo-blue.svg', dirname( __FILE__ ) );
$uimptr_pricing_url = class_exists( 'UrlImageImporter\\Admin\\PromoNotices' )
	? UrlImageImporter\Admin\PromoNotices::get_pricing_url( 'scan_results' )
	: 'https://infiniteuploads.com/pricing/';
?>
<div class="card uimptr-results-card">
	<div class="uimptr-results__header"><?php esc_html_e( 'Storage Usage Analysis', 'url-image-importer' ); ?></div>
	<div class="uimptr-results">

		<div class="uimptr-results__visual">
			<div class="uimptr-results__ring">
				<svg class="uimptr-results__ring-track" viewBox="0 0 200 200" fill="none" aria-hidden="true">
					<circle cx="100" cy="100" r="82" fill="#ffffff"/>
					<circle cx="100" cy="100" r="86" stroke="#eaf3fb" stroke-width="10"/>
					<circle cx="100" cy="16" r="4" fill="#cfe6f6"/>
					<circle cx="26" cy="64" r="3" fill="#dcecf8"/>
					<circle cx="174" cy="64" r="3" fill="#dcecf8"/>
					<circle cx="40" cy="152" r="3.5" fill="#cfe6f6"/>
					<circle cx="160" cy="152" r="3.5" fill="#cfe6f6"/>
				</svg>
				<div class="uimptr-results__ring-inner">
					<span class="uimptr-results__ring-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
					</span>
					<span class="uimptr-results__total"><?php echo esc_html( size_format( $total_storage, 2 ) ); ?><small> / <?php echo esc_html( number_format_i18n( $total_files ) ); ?></small></span>
					<span class="uimptr-results__total-label"><?php esc_html_e( 'Total Bytes / Files', 'url-image-importer' ); ?></span>
				</div>
			</div>
		</div>

		<div class="uimptr-results__main">
			<div class="uimptr-results__meta">
				<span class="uimptr-results__scanned">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					<?php printf( esc_html__( 'Scanned %s ago', 'url-image-importer' ), esc_html( human_time_diff( $scan_results['scan_finished'] ) ) ); ?>
				</span>
				<a href="#" class="uimptr-results__refresh" data-toggle="modal" data-target="#scan-modal" title="<?php esc_attr_e( 'Run a new scan to detect recently uploaded files.', 'url-image-importer' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
					<?php esc_html_e( 'Refresh', 'url-image-importer' ); ?>
				</a>
			</div>

			<div class="uimptr-results__breakdown">
				<?php foreach ( uimptr_get_filetypes( false ) as $ftype ) : ?>
					<?php if ( empty( $ftype->files ) ) { continue; } ?>
					<div class="uimptr-results__type">
						<span class="uimptr-results__type-dot" style="background-color: <?php echo esc_attr( $ftype->color ); ?>;"></span>
						<span class="uimptr-results__type-label"><?php echo esc_html( $ftype->label ); ?></span>
						<span class="uimptr-results__type-value"><?php echo esc_html( size_format( $ftype->size, 2 ) ); ?> / <?php echo esc_html( number_format_i18n( $ftype->files ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="uimptr-results__upgrade">
				<span class="uimptr-results__upgrade-icon" aria-hidden="true">
					<img src="<?php echo esc_url( $uimptr_iu_cloud ); ?>" alt="" width="42" height="42" />
				</span>
				<div class="uimptr-results__upgrade-text">
					<h4><?php esc_html_e( 'Want unlimited storage space?', 'url-image-importer' ); ?></h4>
					<p><?php esc_html_e( 'Move your media files to the Infinite Uploads cloud to save storage space, bandwidth, improve performance, and free you from hosting limits.', 'url-image-importer' ); ?></p>
				</div>
				<a class="btn text-nowrap btn-primary btn-lg" href="<?php echo esc_url( $uimptr_pricing_url ); ?>" target="_blank" rel="noopener noreferrer" role="button"><?php esc_html_e( 'More Info', 'url-image-importer' ); ?></a>
			</div>
		</div>

	</div>
</div>
