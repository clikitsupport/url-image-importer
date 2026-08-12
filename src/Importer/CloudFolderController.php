<?php
/**
 * Admin wiring for cloud folder syncing.
 *
 * @package UrlImageImporter
 */

namespace UrlImageImporter\Importer;

/**
 * Registers the scheduled sync, its AJAX endpoints, and the settings panel.
 *
 * @since 1.3.0
 */
abstract class CloudFolderController {

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
	const OPTION_INTERVAL = '';

	/**
	 * Singleton instances, keyed by concrete class.
	 *
	 * Keyed rather than a single slot so each provider gets its own controller;
	 * a shared instance would register only the first provider's hooks.
	 *
	 * @var array<string, CloudFolderController>
	 */
	private static $instances = array();

	/**
	 * Get the singleton instance for this provider.
	 *
	 * @return CloudFolderController
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new $class();
		}

		return self::$instances[ $class ];
	}

	/**
	 * Slug used for element IDs and AJAX action names.
	 *
	 * @var string
	 */
	const SLUG = '';

	/**
	 * Sync class name for this provider.
	 *
	 * @return string
	 */
	abstract protected static function sync_class();

	/**
	 * Build a sync instance for this provider.
	 *
	 * @return CloudFolderSync
	 */
	protected static function new_sync() {
		$class = static::sync_class();

		return new $class();
	}

	/**
	 * Copy shown above the add-folder form.
	 *
	 * @return array {
	 *     @type string $heading      Panel heading.
	 *     @type string $intro        One-line description.
	 *     @type string $sharing      How to share the folder correctly.
	 *     @type string $placeholder  Example folder URL.
	 * }
	 */
	abstract protected static function copy();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( static::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_failure_notice' ) );

		// Re-arm the schedule if it was lost (cron cleared, interval changed, or
		// the plugin was reactivated) so syncing does not stop silently.
		add_action( 'admin_init', array( get_called_class(), 'maybe_schedule' ) );

		add_action( 'wp_ajax_uimptr_' . static::SLUG . '_add_folder', array( $this, 'ajax_add_folder' ) );
		add_action( 'wp_ajax_uimptr_' . static::SLUG . '_remove_folder', array( $this, 'ajax_remove_folder' ) );
		add_action( 'wp_ajax_uimptr_' . static::SLUG . '_toggle_folder', array( $this, 'ajax_toggle_folder' ) );
		add_action( 'wp_ajax_uimptr_' . static::SLUG . '_sync_now', array( $this, 'ajax_sync_now' ) );
		add_action( 'wp_ajax_uimptr_' . static::SLUG . '_set_interval', array( $this, 'ajax_set_interval' ) );
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
		$interval = get_option( static::OPTION_INTERVAL, 'hourly' );

		return array_key_exists( $interval, static::get_intervals() ) ? $interval : 'hourly';
	}

	/**
	 * Explain when scheduled checks actually run on this site.
	 *
	 * WordPress fires scheduled events on site activity rather than on a real
	 * clock, so the chosen interval is a floor, not a guarantee. When WP-Cron is
	 * disabled outright the site depends entirely on a server cron job, which is
	 * worth saying plainly -- otherwise a folder that never syncs looks like a
	 * broken feature.
	 *
	 * @return array {
	 *     @type string $text  Message for the user.
	 *     @type bool   $warn  Whether this needs attention rather than context.
	 * }
	 */
	public static function get_schedule_note() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return array(
				'text' => __( "WordPress' built-in scheduler is disabled on this site, so these checks only run if a server cron job is set up to trigger them. If new images never appear on their own, ask your host whether WordPress cron is being run. You can always use Check now.", 'url-image-importer' ),
				'warn' => true,
			);
		}

		return array(
			'text' => __( 'WordPress runs scheduled checks when someone visits your site, so on a quiet site a check can happen later than the interval you pick. Use Check now any time you want images straight away.', 'url-image-importer' ),
			'warn' => false,
		);
	}

	/**
	 * Schedule the sync only when there is something to sync.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$sync_class = static::sync_class();
		$folders = $sync_class::get_folders();

		if ( empty( $folders ) ) {
			static::unschedule();
			return;
		}

		static::schedule();
	}

	/**
	 * Ensure the sync event is scheduled at the configured interval.
	 *
	 * @return void
	 */
	public static function schedule() {
		$hook     = static::CRON_HOOK;
		$interval = static::get_interval();
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
		$timestamp = wp_next_scheduled( static::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, static::CRON_HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled_sync() {
		$sync = static::new_sync();
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
		$sync_class = static::sync_class();
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$failing = $sync_class::get_failing_folders();

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

		$sync   = static::new_sync();
		$result = $sync->add_folder( $url, $label );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( isset( $_POST['interval'] ) ) {
			$interval = sanitize_text_field( wp_unslash( $_POST['interval'] ) );
			if ( array_key_exists( $interval, static::get_intervals() ) ) {
				update_option( static::OPTION_INTERVAL, $interval );
			}
		}

		static::schedule();

		wp_send_json_success(
			array(
				'message' => __( 'Folder added — importing your images now.', 'url-image-importer' ),
				'key'     => $result['key'],
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
		$sync_class = static::sync_class();
		$this->verify_request();

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		$sync_class::remove_folder( $key );

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
		$sync_class = static::sync_class();
		$this->verify_request();

		$key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$enabled = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];

		$sync_class::set_enabled( $key, $enabled );

		wp_send_json_success( array( 'folders' => $this->folders_payload() ) );
	}

	/**
	 * AJAX: change how often folders are checked.
	 *
	 * Applies immediately by re-arming the scheduled event, so the choice takes
	 * effect without having to add another folder.
	 *
	 * @return void
	 */
	public function ajax_set_interval() {
		$this->verify_request();

		$interval = isset( $_POST['interval'] ) ? sanitize_text_field( wp_unslash( $_POST['interval'] ) ) : '';

		if ( ! array_key_exists( $interval, static::get_intervals() ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a valid schedule.', 'url-image-importer' ) ) );
		}

		update_option( static::OPTION_INTERVAL, $interval );
		self::maybe_schedule();

		$labels = self::get_intervals();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: schedule label, e.g. "Every hour". */
					__( 'Schedule updated: %s.', 'url-image-importer' ),
					$labels[ $interval ]
				),
			)
		);
	}

	/**
	 * AJAX: run a sync immediately.
	 *
	 * @return void
	 */
	public function ajax_sync_now() {
		$this->verify_request();

		$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$sync        = static::new_sync();
		$sync_class  = static::sync_class();
		$budget      = $sync_class::INTERACTIVE_TIME_BUDGET;

		if ( '' !== $key ) {
			$result = $sync->sync_folder( $key, null, $budget );

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

		$summary = $sync->sync_all( null, $budget );

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
		$sync_class = static::sync_class();
		$payload = array();

		foreach ( $sync_class::get_folders() as $key => $folder ) {
			$payload[] = array(
				'key'       => $key,
				'label'     => $folder['label'],
				'url'       => $folder['url'],
				'enabled'   => (bool) $folder['enabled'],
				'imported'  => (int) $folder['imported'],
				'remaining' => (int) $folder['remaining'],
				'total'     => (int) $folder['total_images'],
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
		$copy      = static::copy();
		$folders   = $this->folders_payload();
		$intervals = self::get_intervals();
		$interval  = self::get_interval();
		?>
		<div id="<?php echo esc_attr( static::SLUG ); ?>-sync" class="import-method" style="display:none;">
			<div class="card upload">
				<h2><?php echo esc_html( $copy['heading'] ); ?></h2>
				<p>
					<?php echo esc_html( $copy['intro'] ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'The folder must be shared as "Anyone with the link".', 'url-image-importer' ); ?></strong>
					<?php echo esc_html( $copy['sharing'] ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="uimptr-<?php echo esc_attr( static::SLUG ); ?>-url"><?php esc_html_e( 'Folder link', 'url-image-importer' ); ?></label></th>
						<td>
							<input type="url" id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-url" class="regular-text" placeholder="<?php echo esc_attr( $copy['placeholder'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uimptr-<?php echo esc_attr( static::SLUG ); ?>-label"><?php esc_html_e( 'Name (optional)', 'url-image-importer' ); ?></label></th>
						<td>
							<input type="text" id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-label" class="regular-text" placeholder="<?php esc_attr_e( 'Finished web images', 'url-image-importer' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uimptr-<?php echo esc_attr( static::SLUG ); ?>-interval"><?php esc_html_e( 'Check for new images', 'url-image-importer' ); ?></label></th>
						<td>
							<select id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-interval">
								<?php foreach ( $intervals as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $interval ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Applies to every watched folder, and saves as soon as you change it.', 'url-image-importer' ); ?>
							</p>
							<?php $schedule_note = self::get_schedule_note(); ?>
							<p class="description"<?php echo $schedule_note['warn'] ? ' style="color:#996800;"' : ''; ?>>
								<?php if ( $schedule_note['warn'] ) : ?>
									<strong><?php esc_html_e( 'Heads up:', 'url-image-importer' ); ?></strong>
								<?php endif; ?>
								<?php echo esc_html( $schedule_note['text'] ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-add" class="btn text-nowrap btn-primary btn-lg">
						<?php esc_html_e( 'Watch This Folder', 'url-image-importer' ); ?>
					</button>
					<span id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-feedback" style="margin-left:10px;"></span>
				</p>

				<p class="description">
					<?php
					printf(
						/* translators: %s: cloud provider name, e.g. Google Drive or Dropbox. */
						esc_html__( 'Images are only ever added. Removing a file from %s never deletes anything from your Media Library.', 'url-image-importer' ),
						esc_html( $copy['provider'] )
					);
					?>
				</p>
			</div>

			<div class="card upload" id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-list-card" <?php echo empty( $folders ) ? 'style="display:none;"' : ''; ?>>
				<h2><?php esc_html_e( 'Watched Folders', 'url-image-importer' ); ?></h2>
				<table class="widefat striped" id="uimptr-<?php echo esc_attr( static::SLUG ); ?>-list">
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
			var slug    = <?php echo wp_json_encode( static::SLUG ); ?>;
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
					'truncated'     => __( 'This folder is large enough that it may not be listing in full. Consider splitting it into smaller folders.', 'url-image-importer' ),
					'adding'        => __( 'Checking folder…', 'url-image-importer' ),
					/* translators: %d: number of images still to import. */
					'queued'        => __( '%d more to import — checks run on a schedule, or use Check now.', 'url-image-importer' ),
					'caughtUp'      => __( 'All images imported.', 'url-image-importer' ),
					'importingNow'  => __( 'Importing…', 'url-image-importer' ),
					/* translators: 1: images imported so far, 2: total images in the folder. */
					'importingOf'   => __( 'Importing… %1$d of %2$d', 'url-image-importer' ),
				)
			); ?>;

			function post(action, data, done) {
				// Returned so callers can chain .always() to re-enable buttons.
				return $.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, data || {}), done);
			}

				// Folders currently mid auto-import, keyed by folder key.
				var importing = {};

				function findFolder(key) {
					for (var i = 0; i < folders.length; i++) {
						if (folders[i].key === key) { return folders[i]; }
					}
					return null;
				}

				// Import a folder in short chunks that continue automatically until
				// nothing is left, so the count climbs live instead of the user
				// waiting on one long request or re-clicking. Each chunk is a bounded
				// server run; the loop just keeps asking for the next one.
				function runImport(key) {
					if (importing[key]) { return; }
					importing[key] = true;
					render();

					(function chunk() {
						post('uimptr_'+slug+'_sync_now', { key: key }, function(r) {
							if (r && r.data && r.data.folders) { folders = r.data.folders; }
							var f = findFolder(key);
							var more = r && r.success && f && f.status !== 'error' && f.remaining > 0;
							if (more) {
								render();
								chunk();
							} else {
								importing[key] = false;
								render();
								if (r && !r.success && r.data && r.data.message) {
									$('#uimptr-'+slug+'-feedback').css('color', '#b32d2e').text(r.data.message);
								}
							}
						}).fail(function() {
							// A dropped chunk (host timeout) is safe: progress is
							// checkpointed server-side, so the schedule or another
							// Check now resumes from where it stopped.
							importing[key] = false;
							render();
						});
					})();
				}

			function render() {
				var $body = $('#uimptr-'+slug+'-list tbody').empty();
				$('#uimptr-'+slug+'-list-card').toggle(folders.length > 0);

				$.each(folders, function(i, f) {
					var status = '';
					if (f.status === 'error') {
						status = $('<div/>').css('color', '#b32d2e').text(f.error);
					} else if (f.truncated) {
						status = $('<div/>').css('color', '#996800').text(strings.truncated);
					}

					var $row = $('<tr/>');
					$('<td/>').append($('<strong/>').text(f.label)).append(status).appendTo($row);

					// Imported count, plus a progress note so a large folder that
					// is still catching up does not look stuck at a partial number.
					var $imported = $('<td/>').append($('<div/>').text(f.imported));
					if (importing[f.key]) {
						$imported.append($('<div/>').css({ color: '#996800', 'font-size': '90%' })
							.text(f.total > 0 ? strings.importingOf.replace('%1$d', f.imported).replace('%2$d', f.total)
											  : strings.importingNow));
					} else if (f.status !== 'error') {
						if (f.remaining > 0) {
							$imported.append($('<div/>').css({ color: '#996800', 'font-size': '90%' })
								.text(strings.queued.replace('%d', f.remaining)));
						} else if (f.total > 0) {
							$imported.append($('<div/>').css({ color: '#008a20', 'font-size': '90%' })
								.text(strings.caughtUp));
						}
					}
					$imported.appendTo($row);

					$('<td/>').text(f.last_sync).appendTo($row);

					var $actions = $('<td/>');
					var busy = !!importing[f.key];
					$('<button type="button" class="button"/>')
						.text(busy ? strings.syncing : strings.syncNow)
						.prop('disabled', busy)
						.on('click', function() { runImport(f.key); })
						.appendTo($actions);

					$('<button type="button" class="button"/>')
						.css('margin-left', '6px')
						.text(f.enabled ? strings.enabled : strings.paused)
						.on('click', function() {
							post('uimptr_'+slug+'_toggle_folder', { key: f.key, enabled: f.enabled ? 'false' : 'true' }, function(r) {
								if (r && r.data && r.data.folders) { folders = r.data.folders; render(); }
							});
						})
						.appendTo($actions);

					$('<button type="button" class="button-link-delete button-link"/>')
						.css('margin-left', '10px')
						.text(strings.remove)
						.on('click', function() {
							if (!window.confirm(strings.confirmRemove)) { return; }
							post('uimptr_'+slug+'_remove_folder', { key: f.key }, function(r) {
								if (r && r.data && r.data.folders) { folders = r.data.folders; render(); }
							});
						})
						.appendTo($actions);

					$actions.appendTo($row);
					$body.append($row);
				});
			}

			// The schedule is a global setting, so it saves on change rather than
			// only when a folder is added.
			$('#uimptr-'+slug+'-interval').on('change', function() {
				var $select = $(this).prop('disabled', true);
				post('uimptr_'+slug+'_set_interval', { interval: $select.val() }, function(r) {
					$('#uimptr-'+slug+'-feedback')
						.css('color', r && r.success ? '#008a20' : '#b32d2e')
						.text(r && r.data ? r.data.message : '');
				}).always(function() { $select.prop('disabled', false); });
			});

			$('#uimptr-'+slug+'-add').on('click', function() {
				var url = $.trim($('#uimptr-'+slug+'-url').val());
				if (!url) { return; }

				var $btn = $(this).prop('disabled', true);
				$('#uimptr-'+slug+'-feedback').css('color', '').text(strings.adding);

				post('uimptr_'+slug+'_add_folder', {
					folder_url: url,
					label: $.trim($('#uimptr-'+slug+'-label').val()),
					interval: $('#uimptr-'+slug+'-interval').val()
				}, function(r) {
					if (r && r.success) {
						folders = r.data.folders;
						$('#uimptr-'+slug+'-url, #uimptr-'+slug+'-label').val('');
						$('#uimptr-'+slug+'-feedback').css('color', '#008a20').text(r.data.message);
						render();
						// Start importing straight away so the folder fills in live.
						if (r.data.key) { runImport(r.data.key); }
					} else {
						$('#uimptr-'+slug+'-feedback').css('color', '#b32d2e')
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
