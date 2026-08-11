<?php
/**
 * Admin wiring for Google Drive folder syncing.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

/**
 * Registers the scheduled sync, its AJAX endpoints, and the settings panel.
 *
 * @since 1.3.0
 */
class GoogleDriveFolderController {

	/**
	 * Capability required to manage watched folders.
	 *
	 * Matches the capability guarding the importer page itself.
	 *
	 * @var string
	 */
	const CAPABILITY = 'upload_files';

	/**
	 * Option storing the sync interval.
	 *
	 * @var string
	 */
	const OPTION_INTERVAL = 'uimptr_drive_sync_interval';

	/**
	 * Singleton instance.
	 *
	 * @var GoogleDriveFolderController|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return GoogleDriveFolderController
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( GoogleDriveFolderSync::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_failure_notice' ) );

		// Re-arm the schedule if it was lost (cron cleared, interval changed, or
		// the plugin was reactivated) so syncing does not stop silently.
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );

		add_action( 'wp_ajax_uimptr_drive_add_folder', array( $this, 'ajax_add_folder' ) );
		add_action( 'wp_ajax_uimptr_drive_remove_folder', array( $this, 'ajax_remove_folder' ) );
		add_action( 'wp_ajax_uimptr_drive_toggle_folder', array( $this, 'ajax_toggle_folder' ) );
		add_action( 'wp_ajax_uimptr_drive_sync_now', array( $this, 'ajax_sync_now' ) );
	}

	/**
	 * Allowed sync intervals.
	 *
	 * @return array Interval slug => label.
	 */
	public static function get_intervals() {
		return array(
			'hourly'     => __( 'Every hour', 'url-image-importer' ),
			'twicedaily' => __( 'Twice a day', 'url-image-importer' ),
			'daily'      => __( 'Once a day', 'url-image-importer' ),
		);
	}

	/**
	 * Get the configured sync interval.
	 *
	 * @return string
	 */
	public static function get_interval() {
		$interval = get_option( self::OPTION_INTERVAL, 'hourly' );

		return array_key_exists( $interval, self::get_intervals() ) ? $interval : 'hourly';
	}

	/**
	 * Schedule the sync only when there is something to sync.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$folders = GoogleDriveFolderSync::get_folders();

		if ( empty( $folders ) ) {
			self::unschedule();
			return;
		}

		self::schedule();
	}

	/**
	 * Ensure the sync event is scheduled at the configured interval.
	 *
	 * @return void
	 */
	public static function schedule() {
		$hook     = GoogleDriveFolderSync::CRON_HOOK;
		$interval = self::get_interval();
		$next     = wp_next_scheduled( $hook );

		if ( $next ) {
			$event = wp_get_scheduled_event( $hook );
			if ( $event && isset( $event->schedule ) && $event->schedule === $interval ) {
				return;
			}
			wp_unschedule_event( $next, $hook );
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, $hook );
	}

	/**
	 * Remove the scheduled sync event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( GoogleDriveFolderSync::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, GoogleDriveFolderSync::CRON_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled_sync() {
		$sync = new GoogleDriveFolderSync();
		$sync->sync_all();
	}

	/**
	 * Warn in the admin when a watched folder stopped working.
	 *
	 * A background sync must never fail quietly: "no new images" and "the
	 * folder could not be read" look identical from the outside otherwise.
	 *
	 * @return void
	 */
	public function maybe_show_failure_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$failing = GoogleDriveFolderSync::get_failing_folders();

		if ( empty( $failing ) ) {
			return;
		}

		$folder  = reset( $failing );
		$message = sprintf(
			/* translators: 1: folder label, 2: error message. */
			__( '%1$s could not be synced: %2$s', 'url-image-importer' ),
			'<strong>' . esc_html( $folder['label'] ) . '</strong>',
			esc_html( $folder['last_error'] )
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'URL Image Importer:', 'url-image-importer' ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Verify the request may manage folders.
	 *
	 * @return void
	 */
	protected function verify_request() {
		if ( function_exists( 'uimptr_check_ajax_request' ) ) {
			uimptr_check_ajax_request();
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage synced folders.', 'url-image-importer' ) ), 403 );
		}
	}

	/**
	 * AJAX: add a watched folder.
	 *
	 * @return void
	 */
	public function ajax_add_folder() {
		$this->verify_request();

		$url   = isset( $_POST['folder_url'] ) ? esc_url_raw( wp_unslash( $_POST['folder_url'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		$sync   = new GoogleDriveFolderSync();
		$result = $sync->add_folder( $url, $label );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( isset( $_POST['interval'] ) ) {
			$interval = sanitize_text_field( wp_unslash( $_POST['interval'] ) );
			if ( array_key_exists( $interval, self::get_intervals() ) ) {
				update_option( self::OPTION_INTERVAL, $interval );
			}
		}

		self::schedule();

		wp_send_json_success(
			array(
				'message' => __( 'Folder added. New images will be imported automatically.', 'url-image-importer' ),
				'folders' => $this->folders_payload(),
			)
		);
	}

	/**
	 * AJAX: stop watching a folder.
	 *
	 * @return void
	 */
	public function ajax_remove_folder() {
		$this->verify_request();

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		GoogleDriveFolderSync::remove_folder( $key );

		wp_send_json_success(
			array(
				'message' => __( 'Folder removed. Images already imported were left in your Media Library.', 'url-image-importer' ),
				'folders' => $this->folders_payload(),
			)
		);
	}

	/**
	 * AJAX: enable or disable a folder.
	 *
	 * @return void
	 */
	public function ajax_toggle_folder() {
		$this->verify_request();

		$key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$enabled = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];

		GoogleDriveFolderSync::set_enabled( $key, $enabled );

		wp_send_json_success( array( 'folders' => $this->folders_payload() ) );
	}

	/**
	 * AJAX: run a sync immediately.
	 *
	 * @return void
	 */
	public function ajax_sync_now() {
		$this->verify_request();

		$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$sync = new GoogleDriveFolderSync();

		if ( '' !== $key ) {
			$result = $sync->sync_folder( $key );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
						'folders' => $this->folders_payload(),
					)
				);
			}

			$message = sprintf(
				/* translators: 1: number imported, 2: number still queued. */
				__( 'Imported %1$d new image(s). %2$d still queued.', 'url-image-importer' ),
				(int) $result['imported'],
				(int) $result['remaining']
			);

			wp_send_json_success(
				array(
					'message' => $message,
					'folders' => $this->folders_payload(),
				)
			);
		}

		$summary = $sync->sync_all();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of images imported. */
					__( 'Imported %d new image(s).', 'url-image-importer' ),
					(int) $summary['imported']
				),
				'folders' => $this->folders_payload(),
			)
		);
	}

	/**
	 * Build the folder list payload for the UI.
	 *
	 * @return array[]
	 */
	protected function folders_payload() {
		$payload = array();

		foreach ( GoogleDriveFolderSync::get_folders() as $key => $folder ) {
			$payload[] = array(
				'key'       => $key,
				'label'     => $folder['label'],
				'url'       => $folder['url'],
				'enabled'   => (bool) $folder['enabled'],
				'imported'  => (int) $folder['imported'],
				'status'    => $folder['last_status'],
				'error'     => $folder['last_error'],
				'truncated' => ! empty( $folder['truncated'] ),
				'last_sync' => $folder['last_sync']
					? sprintf(
						/* translators: %s: human readable time difference. */
						__( '%s ago', 'url-image-importer' ),
						human_time_diff( (int) $folder['last_sync'], time() )
					)
					: __( 'Never', 'url-image-importer' ),
			);
		}

		return $payload;
	}

	/**
	 * Render the Google Drive tab panel.
	 *
	 * @return void
	 */
	public function render_tab() {
		$folders   = $this->folders_payload();
		$intervals = self::get_intervals();
		$interval  = self::get_interval();
		?>
		<div id="drive-sync" class="import-method" style="display:none;">
			<div class="card upload">
				<h2><?php esc_html_e( 'Sync a Google Drive Folder', 'url-image-importer' ); ?></h2>
				<p>
					<?php esc_html_e( 'Watch a Google Drive folder and import new images into your Media Library automatically. No Google account connection, API key, or app setup is required.', 'url-image-importer' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'The folder must be shared as "Anyone with the link".', 'url-image-importer' ); ?></strong>
					<?php esc_html_e( 'In Google Drive, right-click the folder, choose Share, then set General access to "Anyone with the link".', 'url-image-importer' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="uimptr-drive-url"><?php esc_html_e( 'Folder link', 'url-image-importer' ); ?></label></th>
						<td>
							<input type="url" id="uimptr-drive-url" class="regular-text" placeholder="https://drive.google.com/drive/folders/..." />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uimptr-drive-label"><?php esc_html_e( 'Name (optional)', 'url-image-importer' ); ?></label></th>
						<td>
							<input type="text" id="uimptr-drive-label" class="regular-text" placeholder="<?php esc_attr_e( 'Finished web images', 'url-image-importer' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uimptr-drive-interval"><?php esc_html_e( 'Check for new images', 'url-image-importer' ); ?></label></th>
						<td>
							<select id="uimptr-drive-interval">
								<?php foreach ( $intervals as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $interval ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" id="uimptr-drive-add" class="btn text-nowrap btn-primary btn-lg">
						<?php esc_html_e( 'Watch This Folder', 'url-image-importer' ); ?>
					</button>
					<span id="uimptr-drive-feedback" style="margin-left:10px;"></span>
				</p>

				<p class="description">
					<?php esc_html_e( 'Images are only ever added. Removing a file from Google Drive never deletes anything from your Media Library.', 'url-image-importer' ); ?>
				</p>
			</div>

			<div class="card upload" id="uimptr-drive-list-card" <?php echo empty( $folders ) ? 'style="display:none;"' : ''; ?>>
				<h2><?php esc_html_e( 'Watched Folders', 'url-image-importer' ); ?></h2>
				<table class="widefat striped" id="uimptr-drive-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Folder', 'url-image-importer' ); ?></th>
							<th><?php esc_html_e( 'Imported', 'url-image-importer' ); ?></th>
							<th><?php esc_html_e( 'Last checked', 'url-image-importer' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'url-image-importer' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var folders = <?php echo wp_json_encode( $folders ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( uimptr_get_ajax_nonce_action() ) ); ?>;
			var strings = <?php echo wp_json_encode(
				array(
					'confirmRemove' => __( 'Stop watching this folder? Images already imported stay in your Media Library.', 'url-image-importer' ),
					'syncing'       => __( 'Checking…', 'url-image-importer' ),
					'syncNow'       => __( 'Check now', 'url-image-importer' ),
					'remove'        => __( 'Remove', 'url-image-importer' ),
					'enabled'       => __( 'Syncing', 'url-image-importer' ),
					'paused'        => __( 'Paused', 'url-image-importer' ),
					'truncated'     => __( 'This folder is large enough that Google may not be listing all of it. Consider splitting it into smaller folders.', 'url-image-importer' ),
					'adding'        => __( 'Checking folder…', 'url-image-importer' ),
				)
			); ?>;

			function post(action, data, done) {
				$.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, data || {}), done);
			}

			function render() {
				var $body = $('#uimptr-drive-list tbody').empty();
				$('#uimptr-drive-list-card').toggle(folders.length > 0);

				$.each(folders, function(i, f) {
					var status = '';
					if (f.status === 'error') {
						status = $('<div/>').css('color', '#b32d2e').text(f.error);
					} else if (f.truncated) {
						status = $('<div/>').css('color', '#996800').text(strings.truncated);
					}

					var $row = $('<tr/>');
					$('<td/>').append($('<strong/>').text(f.label)).append(status).appendTo($row);
					$('<td/>').text(f.imported).appendTo($row);
					$('<td/>').text(f.last_sync).appendTo($row);

					var $actions = $('<td/>');
					$('<button type="button" class="button"/>')
						.text(strings.syncNow)
						.on('click', function() {
							var $b = $(this).prop('disabled', true).text(strings.syncing);
							post('uimptr_drive_sync_now', { key: f.key }, function(r) {
								if (r && r.data && r.data.folders) { folders = r.data.folders; }
								render();
								if (r && !r.success && r.data) { window.alert(r.data.message); }
							}).always(function() { $b.prop('disabled', false).text(strings.syncNow); });
						})
						.appendTo($actions);

					$('<button type="button" class="button"/>')
						.css('margin-left', '6px')
						.text(f.enabled ? strings.enabled : strings.paused)
						.on('click', function() {
							post('uimptr_drive_toggle_folder', { key: f.key, enabled: f.enabled ? 'false' : 'true' }, function(r) {
								if (r && r.data && r.data.folders) { folders = r.data.folders; render(); }
							});
						})
						.appendTo($actions);

					$('<button type="button" class="button-link-delete button-link"/>')
						.css('margin-left', '10px')
						.text(strings.remove)
						.on('click', function() {
							if (!window.confirm(strings.confirmRemove)) { return; }
							post('uimptr_drive_remove_folder', { key: f.key }, function(r) {
								if (r && r.data && r.data.folders) { folders = r.data.folders; render(); }
							});
						})
						.appendTo($actions);

					$actions.appendTo($row);
					$body.append($row);
				});
			}

			$('#uimptr-drive-add').on('click', function() {
				var url = $.trim($('#uimptr-drive-url').val());
				if (!url) { return; }

				var $btn = $(this).prop('disabled', true);
				$('#uimptr-drive-feedback').css('color', '').text(strings.adding);

				post('uimptr_drive_add_folder', {
					folder_url: url,
					label: $.trim($('#uimptr-drive-label').val()),
					interval: $('#uimptr-drive-interval').val()
				}, function(r) {
					if (r && r.success) {
						folders = r.data.folders;
						$('#uimptr-drive-url, #uimptr-drive-label').val('');
						$('#uimptr-drive-feedback').css('color', '#008a20').text(r.data.message);
						render();
					} else {
						$('#uimptr-drive-feedback').css('color', '#b32d2e')
							.text(r && r.data ? r.data.message : 'Could not add that folder.');
					}
				}).always(function() { $btn.prop('disabled', false); });
			});

			render();
		});
		</script>
		<?php
	}
}
