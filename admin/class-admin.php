<?php
/**
 * Admin class
 */
class BRM_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add action hooks
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Backup Restore Migrate', 'backup-restore-migrate' ),
			__( 'Backup & Restore', 'backup-restore-migrate' ),
			'manage_options',
			'backup-restore-migrate',
			array( $this, 'render_dashboard_page' ),
			'dashicons-backup',
			75
		);

		add_submenu_page(
			'backup-restore-migrate',
			__( 'Dashboard', 'backup-restore-migrate' ),
			__( 'Dashboard', 'backup-restore-migrate' ),
			'manage_options',
			'backup-restore-migrate',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'backup-restore-migrate',
			__( 'Backups', 'backup-restore-migrate' ),
			__( 'Backups', 'backup-restore-migrate' ),
			'manage_options',
			'bmr-backups',
			array( $this, 'render_backups_page' )
		);

		add_submenu_page(
			'backup-restore-migrate',
			__( 'Schedule', 'backup-restore-migrate' ),
			__( 'Schedule', 'backup-restore-migrate' ),
			'manage_options',
			'bmr-schedule',
			array( $this, 'render_schedule_page' )
		);

		add_submenu_page(
			'backup-restore-migrate',
			__( 'Migration', 'backup-restore-migrate' ),
			__( 'Migration', 'backup-restore-migrate' ),
			'manage_options',
			'bmr-migration',
			array( $this, 'render_migration_page' )
		);

		add_submenu_page(
			'backup-restore-migrate',
			__( 'Settings', 'backup-restore-migrate' ),
			__( 'Settings', 'backup-restore-migrate' ),
			'manage_options',
			'bmr-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue scripts
	 */
	public function enqueue_scripts( $hook ) {
		// Only load on our plugin pages
		if ( strpos( $hook, 'backup-restore-migrate' ) === false && strpos( $hook, 'wpbm-' ) === false ) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'wpbm-admin',
			BRM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BRM_VERSION
		);

		// Enqueue scripts
		wp_enqueue_script(
			'wpbm-admin',
			BRM_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-util' ),
			BRM_VERSION,
			true
		);

		// Localize script
		wp_localize_script( 'wpbm-admin', 'wpbm', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'bmr_ajax' ),
			'strings' => array(
				'confirm_delete' => __( 'Are you sure you want to delete this backup?', 'backup-restore-migrate' ),
				'confirm_restore' => __( 'Are you sure you want to restore this backup? This will overwrite your current site!', 'backup-restore-migrate' ),
				'creating_backup' => __( 'Creating backup...', 'backup-restore-migrate' ),
				'restoring_backup' => __( 'Restoring backup...', 'backup-restore-migrate' ),
				'error' => __( 'An error occurred. Please try again.', 'backup-restore-migrate' ),
			),
		) );
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page() {
		global $wpdb;

		// Get statistics
		$total_backups = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bmr_backups WHERE status = 'completed'" );
		$total_size = $wpdb->get_var( "SELECT SUM(backup_size) FROM {$wpdb->prefix}bmr_backups WHERE status = 'completed'" );
		$last_backup = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}bmr_backups WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1" );
		$active_schedules = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bmr_schedules WHERE is_active = 1" );

		?>
		<div class="wrap wpbm-wrap">
			<h1><?php esc_html_e( 'Backup Restore Migrate Dashboard', 'backup-restore-migrate' ); ?></h1>

			<div class="wpbm-dashboard">
				<!-- Quick Actions -->
				<div class="wpbm-card">
					<h2><?php esc_html_e( 'Quick Actions', 'backup-restore-migrate' ); ?></h2>
					<div class="wpbm-actions">
						<button class="button button-primary wpbm-create-backup" data-type="full">
							<span class="dashicons dashicons-backup"></span>
							<?php esc_html_e( 'Full Backup', 'backup-restore-migrate' ); ?>
						</button>
						<button class="button wpbm-create-backup" data-type="database">
							<span class="dashicons dashicons-database"></span>
							<?php esc_html_e( 'Database Backup', 'backup-restore-migrate' ); ?>
						</button>
						<button class="button wpbm-create-backup" data-type="files">
							<span class="dashicons dashicons-media-default"></span>
							<?php esc_html_e( 'Files Backup', 'backup-restore-migrate' ); ?>
						</button>
					</div>
				</div>

				<!-- Statistics -->
				<div class="wpbm-stats">
					<div class="wpbm-stat-card">
						<div class="wpbm-stat-icon">
							<span class="dashicons dashicons-backup"></span>
						</div>
						<div class="wpbm-stat-content">
							<h3><?php echo esc_html( $total_backups ); ?></h3>
							<p><?php esc_html_e( 'Total Backups', 'backup-restore-migrate' ); ?></p>
						</div>
					</div>

					<div class="wpbm-stat-card">
						<div class="wpbm-stat-icon">
							<span class="dashicons dashicons-admin-generic"></span>
						</div>
						<div class="wpbm-stat-content">
							<h3><?php echo esc_html( size_format( $total_size ?: 0 ) ); ?></h3>
							<p><?php esc_html_e( 'Total Size', 'backup-restore-migrate' ); ?></p>
						</div>
					</div>

					<div class="wpbm-stat-card">
						<div class="wpbm-stat-icon">
							<span class="dashicons dashicons-calendar-alt"></span>
						</div>
						<div class="wpbm-stat-content">
							<h3><?php echo $last_backup ? esc_html( human_time_diff( strtotime( $last_backup->created_at ) ) ) : esc_html__( 'Never', 'backup-restore-migrate' ); ?></h3>
							<p><?php esc_html_e( 'Last Backup', 'backup-restore-migrate' ); ?></p>
						</div>
					</div>

					<div class="wpbm-stat-card">
						<div class="wpbm-stat-icon">
							<span class="dashicons dashicons-clock"></span>
						</div>
						<div class="wpbm-stat-content">
							<h3><?php echo esc_html( $active_schedules ); ?></h3>
							<p><?php esc_html_e( 'Active Schedules', 'backup-restore-migrate' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Recent Backups -->
				<div class="wpbm-card">
					<h2><?php esc_html_e( 'Recent Backups', 'backup-restore-migrate' ); ?></h2>
					<?php
					$recent_backups = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bmr_backups ORDER BY created_at DESC LIMIT 5" );

					if ( $recent_backups ) {
						?>
						<table class="wp-list-table widefat striped">
							<thead>
							<tr>
								<th><?php esc_html_e( 'Backup Name', 'backup-restore-migrate' ); ?></th>
								<th><?php esc_html_e( 'Type', 'backup-restore-migrate' ); ?></th>
								<th><?php esc_html_e( 'Size', 'backup-restore-migrate' ); ?></th>
								<th><?php esc_html_e( 'Date', 'backup-restore-migrate' ); ?></th>
								<th><?php esc_html_e( 'Status', 'backup-restore-migrate' ); ?></th>
							</tr>
							</thead>
							<tbody>
							<?php foreach ( $recent_backups as $backup ) : ?>
								<tr>
									<td><?php echo esc_html( $backup->backup_name ); ?></td>
									<td><?php echo esc_html( ucfirst( $backup->backup_type ) ); ?></td>
									<td><?php echo esc_html( size_format( $backup->backup_size ) ); ?></td>
									<td><?php echo esc_html( human_time_diff( strtotime( $backup->created_at ) ) . ' ' . __( 'ago', 'backup-restore-migrate' ) ); ?></td>
									<td>
											<span class="wpbm-status wpbm-status-<?php echo esc_attr( $backup->status ); ?>">
												<?php echo esc_html( ucfirst( $backup->status ) ); ?>
											</span>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbm-backups' ) ); ?>" class="button"><?php esc_html_e( 'View All Backups', 'backup-restore-migrate' ); ?></a></p>
						<?php
					} else {
						?>
						<p><?php esc_html_e( 'No backups found. Create your first backup now!', 'backup-restore-migrate' ); ?></p>
						<?php
					}
					?>
				</div>
			</div>

			<!-- Progress Modal -->
			<div id="wpbm-progress-modal" class="wpbm-modal" style="display: none;">
				<div class="wpbm-modal-content">
					<h2 class="wpbm-modal-title"></h2>
					<div class="wpbm-progress-bar">
						<div class="wpbm-progress-fill" style="width: 0%;"></div>
					</div>
					<div class="wpbm-progress-message"></div>
					<div class="wpbm-progress-percentage">0%</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render backups page
	 */
	public function render_backups_page() {
		global $wpdb;

		// Handle bulk actions
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'delete' && ! empty( $_POST['backup_ids'] ) ) {
			check_admin_referer( 'wpbm_bulk_action' );

			foreach ( $_POST['backup_ids'] as $backup_id ) {
				$this->delete_backup( intval( $backup_id ) );
			}

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Selected backups deleted successfully.', 'backup-restore-migrate' ) . '</p></div>';
		}

		// Get backups
		$backups = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpbm_backups ORDER BY created_at DESC" );

		?>
		<div class="wrap wpbm-wrap">
			<h1>
				<?php esc_html_e( 'Backups', 'backup-restore-migrate' ); ?>
				<a href="#" class="page-title-action wpbm-create-backup" data-type="full"><?php esc_html_e( 'Create New Backup', 'backup-restore-migrate' ); ?></a>
			</h1>

			<?php if ( $backups ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'wpbm_bulk_action' ); ?>

					<div class="tablenav top">
						<div class="alignleft actions bulkactions">
							<select name="action">
								<option value=""><?php esc_html_e( 'Bulk Actions', 'backup-restore-migrate' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'backup-restore-migrate' ); ?></option>
							</select>
							<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'backup-restore-migrate' ); ?>">
						</div>
					</div>

					<table class="wp-list-table widefat fixed striped">
						<thead>
						<tr>
							<td class="manage-column column-cb check-column">
								<input type="checkbox" />
							</td>
							<th><?php esc_html_e( 'Backup Name', 'backup-restore-migrate' ); ?></th>
							<th><?php esc_html_e( 'Type', 'backup-restore-migrate' ); ?></th>
							<th><?php esc_html_e( 'Size', 'backup-restore-migrate' ); ?></th>
							<th><?php esc_html_e( 'Storage', 'backup-restore-migrate' ); ?></th>
							<th><?php esc_html_e( 'Date', 'backup-restore-migrate' ); ?></th>
							<th><?php esc_html_e( 'Status', 'backup-restore-migrate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'backup-restore-migrate' ); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $backups as $backup ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="backup_ids[]" value="<?php echo esc_attr( $backup->id ); ?>" />
								</th>
								<td>
									<strong><?php echo esc_html( $backup->backup_name ); ?></strong>
									<?php if ( $backup->incremental_parent ) : ?>
										<br><small><?php esc_html_e( 'Incremental', 'backup-restore-migrate' ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ucfirst( $backup->backup_type ) ); ?></td>
								<td><?php echo esc_html( size_format( $backup->backup_size ?: 0 ) ); ?></td>
								<td>
									<?php
									$locations = json_decode( $backup->backup_location, true );
									if ( $locations ) {
										$storage_types = array_keys( $locations );
										echo esc_html( implode( ', ', array_map( 'ucfirst', $storage_types ) ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td><?php echo esc_html( human_time_diff( strtotime( $backup->created_at ) ) . ' ' . __( 'ago', 'backup-restore-migrate' ) ); ?></td>
								<td>
										<span class="wpbm-status wpbm-status-<?php echo esc_attr( $backup->status ); ?>">
											<?php echo esc_html( ucfirst( $backup->status ) ); ?>
										</span>
								</td>
								<td>
									<?php if ( $backup->status === 'completed' ) : ?>
										<a href="#" class="button button-small wpbm-download-backup" data-backup-id="<?php echo esc_attr( $backup->id ); ?>">
											<?php esc_html_e( 'Download', 'backup-restore-migrate' ); ?>
										</a>
										<a href="#" class="button button-small wpbm-restore-backup" data-backup-id="<?php echo esc_attr( $backup->id ); ?>">
											<?php esc_html_e( 'Restore', 'backup-restore-migrate' ); ?>
										</a>
									<?php endif; ?>
									<a href="#" class="button button-small wpbm-delete-backup" data-backup-id="<?php echo esc_attr( $backup->id ); ?>">
										<?php esc_html_e( 'Delete', 'backup-restore-migrate' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'No backups found. Create your first backup now!', 'backup-restore-migrate' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render schedule page
	 */
	public function render_schedule_page() {
		global $wpdb;

		// Get schedules
		$schedules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpbm_schedules ORDER BY schedule_name ASC" );

		?>
		<div class="wrap wpbm-wrap">
			<h1>
				<?php esc_html_e( 'Backup Schedules', 'backup-restore-migrate' ); ?>
				<a href="#" class="page-title-action wpbm-add-schedule"><?php esc_html_e( 'Add New Schedule', 'backup-restore-migrate' ); ?></a>
			</h1>

			<?php if ( $schedules ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Schedule Name', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Type', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Frequency', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Storage', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Next Run', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Last Run', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Status', 'backup-restore-migrate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'backup-restore-migrate' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $schedules as $schedule ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $schedule->schedule_name ); ?></strong>
								<?php if ( $schedule->incremental_enabled ) : ?>
									<br><small><?php esc_html_e( 'Incremental', 'backup-restore-migrate' ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( ucfirst( $schedule->backup_type ) ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $schedule->frequency ) ) ); ?></td>
							<td>
								<?php
								$destinations = json_decode( $schedule->storage_destinations, true );
								echo esc_html( implode( ', ', array_map( 'ucfirst', $destinations ) ) );
								?>
							</td>
							<td>
								<?php
								if ( $schedule->next_run ) {
									echo esc_html( human_time_diff( strtotime( $schedule->next_run ) ) );
								} else {
									echo '—';
								}
								?>
							</td>
							<td>
								<?php
								if ( $schedule->last_run ) {
									echo esc_html( human_time_diff( strtotime( $schedule->last_run ) ) . ' ' . __( 'ago', 'backup-restore-migrate' ) );
								} else {
									echo __( 'Never', 'backup-restore-migrate' );
								}
								?>
							</td>
							<td>
								<?php if ( $schedule->is_active ) : ?>
									<span class="wpbm-status wpbm-status-active"><?php esc_html_e( 'Active', 'backup-restore-migrate' ); ?></span>
								<?php else : ?>
									<span class="wpbm-status wpbm-status-inactive"><?php esc_html_e( 'Inactive', 'backup-restore-migrate' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a href="#" class="button button-small wpbm-edit-schedule" data-schedule-id="<?php echo esc_attr( $schedule->id ); ?>">
									<?php esc_html_e( 'Edit', 'backup-restore-migrate' ); ?>
								</a>
								<a href="#" class="button button-small wpbm-toggle-schedule" data-schedule-id="<?php echo esc_attr( $schedule->id ); ?>" data-active="<?php echo esc_attr( $schedule->is_active ); ?>">
									<?php echo $schedule->is_active ? esc_html__( 'Pause', 'backup-restore-migrate' ) : esc_html__( 'Resume', 'backup-restore-migrate' ); ?>
								</a>
								<a href="#" class="button button-small wpbm-delete-schedule" data-schedule-id="<?php echo esc_attr( $schedule->id ); ?>">
									<?php esc_html_e( 'Delete', 'backup-restore-migrate' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No schedules found. Create your first backup schedule now!', 'backup-restore-migrate' ); ?></p>
			<?php endif; ?>

			<!-- Schedule Modal -->
			<div id="wpbm-schedule-modal" class="wpbm-modal" style="display: none;">
				<div class="wpbm-modal-content">
					<span class="wpbm-modal-close">&times;</span>
					<h2><?php esc_html_e( 'Create Backup Schedule', 'backup-restore-migrate' ); ?></h2>

					<form id="wpbm-schedule-form">
						<input type="hidden" name="schedule_id" value="">

						<table class="form-table">
							<tr>
								<th><label for="schedule_name"><?php esc_html_e( 'Schedule Name', 'backup-restore-migrate' ); ?></label></th>
								<td><input type="text" name="schedule_name" id="schedule_name" class="regular-text" required></td>
							</tr>

							<tr>
								<th><label for="backup_type"><?php esc_html_e( 'Backup Type', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<select name="backup_type" id="backup_type">
										<option value="full"><?php esc_html_e( 'Full Backup', 'backup-restore-migrate' ); ?></option>
										<option value="database"><?php esc_html_e( 'Database Only', 'backup-restore-migrate' ); ?></option>
										<option value="files"><?php esc_html_e( 'Files Only', 'backup-restore-migrate' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="frequency"><?php esc_html_e( 'Frequency', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<select name="frequency" id="frequency">
										<option value="hourly"><?php esc_html_e( 'Hourly', 'backup-restore-migrate' ); ?></option>
										<option value="twice_daily"><?php esc_html_e( 'Twice Daily', 'backup-restore-migrate' ); ?></option>
										<option value="daily"><?php esc_html_e( 'Daily', 'backup-restore-migrate' ); ?></option>
										<option value="weekly"><?php esc_html_e( 'Weekly', 'backup-restore-migrate' ); ?></option>
										<option value="monthly"><?php esc_html_e( 'Monthly', 'backup-restore-migrate' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Storage Destinations', 'backup-restore-migrate' ); ?></th>
								<td>
									<label><input type="checkbox" name="storage_destinations[]" value="local" checked> <?php esc_html_e( 'Local', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="storage_destinations[]" value="ftp"> <?php esc_html_e( 'FTP', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="storage_destinations[]" value="s3"> <?php esc_html_e( 'Amazon S3', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="storage_destinations[]" value="google_drive"> <?php esc_html_e( 'Google Drive', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="storage_destinations[]" value="dropbox"> <?php esc_html_e( 'Dropbox', 'backup-restore-migrate' ); ?></label>
								</td>
							</tr>

							<tr>
								<th><label for="retention_count"><?php esc_html_e( 'Keep Backups', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<input type="number" name="retention_count" id="retention_count" value="5" min="1" max="100" class="small-text">
									<span class="description"><?php esc_html_e( 'Number of backups to keep', 'backup-restore-migrate' ); ?></span>
								</td>
							</tr>

							<tr>
								<th><label for="incremental_enabled"><?php esc_html_e( 'Incremental Backups', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<input type="checkbox" name="incremental_enabled" id="incremental_enabled" value="1">
									<span class="description"><?php esc_html_e( 'Enable incremental backups to save space', 'backup-restore-migrate' ); ?></span>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'backup-restore-migrate' ); ?></button>
							<button type="button" class="button wpbm-modal-cancel"><?php esc_html_e( 'Cancel', 'backup-restore-migrate' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render migration page
	 */
	public function render_migration_page() {
		?>
		<div class="wrap wpbm-wrap">
			<h1><?php esc_html_e( 'Migration', 'backup-restore-migrate' ); ?></h1>

			<div class="wpbm-migration-options">
				<!-- Export Section -->
				<div class="wpbm-card">
					<h2><?php esc_html_e( 'Export Site', 'backup-restore-migrate' ); ?></h2>
					<p><?php esc_html_e( 'Create a migration package to move your site to another location.', 'backup-restore-migrate' ); ?></p>

					<form id="bmr-export-form">
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Export Options', 'backup-restore-migrate' ); ?></th>
								<td>
									<label><input type="checkbox" name="include_users" value="1" checked> <?php esc_html_e( 'Include Users', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="include_plugins" value="1" checked> <?php esc_html_e( 'Include Plugins', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="include_themes" value="1" checked> <?php esc_html_e( 'Include Themes', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="include_uploads" value="1" checked> <?php esc_html_e( 'Include Media', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="include_settings" value="1" checked> <?php esc_html_e( 'Include Settings', 'backup-restore-migrate' ); ?></label>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Migration Package', 'backup-restore-migrate' ); ?></button>
						</p>
					</form>
				</div>

				<!-- Import Section -->
				<div class="wpbm-card">
					<h2><?php esc_html_e( 'Import Site', 'backup-restore-migrate' ); ?></h2>
					<p><?php esc_html_e( 'Import a migration package from another site.', 'backup-restore-migrate' ); ?></p>

					<form id="bmr-import-form">
						<table class="form-table">
							<tr>
								<th><label for="migration_token"><?php esc_html_e( 'Migration Token', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<input type="text" name="migration_token" id="migration_token" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'Enter the migration token from the source site.', 'backup-restore-migrate' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><label for="backup_id"><?php esc_html_e( 'Backup ID', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<input type="text" name="backup_id" id="backup_id" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'Enter the backup ID from the source site.', 'backup-restore-migrate' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Import Options', 'backup-restore-migrate' ); ?></th>
								<td>
									<label><input type="checkbox" name="update_urls" value="1" checked> <?php esc_html_e( 'Update URLs', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="update_paths" value="1" checked> <?php esc_html_e( 'Update Paths', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="preserve_users" value="1"> <?php esc_html_e( 'Preserve Current Users', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="preserve_settings" value="1"> <?php esc_html_e( 'Preserve Current Settings', 'backup-restore-migrate' ); ?></label>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import Site', 'backup-restore-migrate' ); ?></button>
						</p>
					</form>
				</div>

				<!-- Clone Section -->
				<div class="wpbm-card">
					<h2><?php esc_html_e( 'Clone Site', 'backup-restore-migrate' ); ?></h2>
					<p><?php esc_html_e( 'Create a clone of your site for staging or development.', 'backup-restore-migrate' ); ?></p>

					<form id="bmr-clone-form">
						<table class="form-table">
							<tr>
								<th><label for="destination_url"><?php esc_html_e( 'Destination URL', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<input type="url" name="destination_url" id="destination_url" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'Enter the URL where the clone will be installed.', 'backup-restore-migrate' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><label for="clone_type"><?php esc_html_e( 'Clone Type', 'backup-restore-migrate' ); ?></label></th>
								<td>
									<select name="clone_type" id="clone_type">
										<option value="full"><?php esc_html_e( 'Full Clone', 'backup-restore-migrate' ); ?></option>
										<option value="staging"><?php esc_html_e( 'Staging Site', 'backup-restore-migrate' ); ?></option>
										<option value="development"><?php esc_html_e( 'Development Site', 'backup-restore-migrate' ); ?></option>
									</select>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Clone Options', 'backup-restore-migrate' ); ?></th>
								<td>
									<label><input type="checkbox" name="update_robots" value="1" checked> <?php esc_html_e( 'Discourage search engines', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="disable_emails" value="1" checked> <?php esc_html_e( 'Disable email notifications', 'backup-restore-migrate' ); ?></label><br>
									<label><input type="checkbox" name="change_prefix" value="1"> <?php esc_html_e( 'Change database prefix', 'backup-restore-migrate' ); ?></label>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Clone', 'backup-restore-migrate' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Save settings if submitted
		if ( isset( $_POST['wpbm_save_settings'] ) ) {
			check_admin_referer( 'wpbm_settings' );

			// Save general settings
			update_option( 'wpbm_backup_directory', sanitize_text_field( $_POST['backup_directory'] ) );
			update_option( 'wpbm_max_execution_time', intval( $_POST['max_execution_time'] ) );
			update_option( 'wpbm_memory_limit', sanitize_text_field( $_POST['memory_limit'] ) );
			update_option( 'wpbm_chunk_size', intval( $_POST['chunk_size'] ) );
			update_option( 'wpbm_compression_level', intval( $_POST['compression_level'] ) );
			update_option( 'wpbm_email_notifications', isset( $_POST['email_notifications'] ) );
			update_option( 'wpbm_notification_email', sanitize_email( $_POST['notification_email'] ) );
			update_option( 'wpbm_retain_local_backups', intval( $_POST['retain_local_backups'] ) );
			update_option( 'wpbm_enable_debug_log', isset( $_POST['enable_debug_log'] ) );

			// Save exclude options
			$exclude_tables = isset( $_POST['exclude_tables'] ) ? array_map( 'sanitize_text_field', $_POST['exclude_tables'] ) : array();
			$exclude_files = isset( $_POST['exclude_files'] ) ? array_map( 'sanitize_text_field', explode( "\n", $_POST['exclude_files'] ) ) : array();

			update_option( 'wpbm_exclude_tables', $exclude_tables );
			update_option( 'wpbm_exclude_files', array_filter( $exclude_files ) );

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'backup-restore-migrate' ) . '</p></div>';
		}

		// Get current settings
		$backup_directory = get_option( 'wpbm_backup_directory', 'backup-restore-migrate' );
		$max_execution_time = get_option( 'wpbm_max_execution_time', 300 );
		$memory_limit = get_option( 'wpbm_memory_limit', '256M' );
		$chunk_size = get_option( 'wpbm_chunk_size', 2048 );
		$compression_level = get_option( 'wpbm_compression_level', 5 );
		$email_notifications = get_option( 'wpbm_email_notifications', true );
		$notification_email = get_option( 'wpbm_notification_email', get_option( 'admin_email' ) );
		$retain_local_backups = get_option( 'wpbm_retain_local_backups', 5 );
		$enable_debug_log = get_option( 'wpbm_enable_debug_log', false );
		$exclude_tables = get_option( 'wpbm_exclude_tables', array() );
		$exclude_files = get_option( 'wpbm_exclude_files', array() );

		// Get active tab
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';

		?>
		<div class="wrap wpbm-wrap">
			<h1><?php esc_html_e( 'Backup Migration Restore Settings', 'backup-restore-migrate' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=wpbm-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'backup-restore-migrate' ); ?>
				</a>
				<a href="?page=wpbm-settings&tab=storage" class="nav-tab <?php echo $active_tab === 'storage' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Storage', 'backup-restore-migrate' ); ?>
				</a>
				<a href="?page=wpbm-settings&tab=exclude" class="nav-tab <?php echo $active_tab === 'exclude' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Exclusions', 'backup-restore-migrate' ); ?>
				</a>
			</h2>

			<form method="post" action="">
				<?php wp_nonce_field( 'wpbm_settings' ); ?>

				<?php if ( $active_tab === 'general' ) : ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="backup_directory"><?php esc_html_e( 'Backup Directory', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="text" name="backup_directory" id="backup_directory" value="<?php echo esc_attr( $backup_directory ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Directory name inside wp-content/uploads/', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="max_execution_time"><?php esc_html_e( 'Max Execution Time', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="number" name="max_execution_time" id="max_execution_time" value="<?php echo esc_attr( $max_execution_time ); ?>" class="small-text" /> <?php esc_html_e( 'seconds', 'backup-restore-migrate' ); ?>
								<p class="description"><?php esc_html_e( 'Maximum time for backup operations', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="memory_limit"><?php esc_html_e( 'Memory Limit', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="text" name="memory_limit" id="memory_limit" value="<?php echo esc_attr( $memory_limit ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'PHP memory limit for backup operations', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="chunk_size"><?php esc_html_e( 'Chunk Size', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="number" name="chunk_size" id="chunk_size" value="<?php echo esc_attr( $chunk_size ); ?>" class="small-text" /> KB
								<p class="description"><?php esc_html_e( 'File chunk size for uploads', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="compression_level"><?php esc_html_e( 'Compression Level', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="range" name="compression_level" id="compression_level" value="<?php echo esc_attr( $compression_level ); ?>" min="0" max="9" />
								<span id="compression_level_value"><?php echo esc_html( $compression_level ); ?></span>
								<p class="description"><?php esc_html_e( '0 = No compression, 9 = Maximum compression', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Email Notifications', 'backup-restore-migrate' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="email_notifications" value="1" <?php checked( $email_notifications ); ?> />
									<?php esc_html_e( 'Send email notifications for backup events', 'backup-restore-migrate' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="notification_email"><?php esc_html_e( 'Notification Email', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="email" name="notification_email" id="notification_email" value="<?php echo esc_attr( $notification_email ); ?>" class="regular-text" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="retain_local_backups"><?php esc_html_e( 'Retain Local Backups', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<input type="number" name="retain_local_backups" id="retain_local_backups" value="<?php echo esc_attr( $retain_local_backups ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'backup-restore-migrate' ); ?>
								<p class="description"><?php esc_html_e( 'Number of days to keep local backups', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Debug Mode', 'backup-restore-migrate' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_debug_log" value="1" <?php checked( $enable_debug_log ); ?> />
									<?php esc_html_e( 'Enable debug logging', 'backup-restore-migrate' ); ?>
								</label>
							</td>
						</tr>
					</table>

				<?php elseif ( $active_tab === 'storage' ) : ?>
					<div class="wpbm-storage-settings">
						<?php
						$storage_types = array(
							'ftp' => __( 'FTP', 'backup-restore-migrate' ),
							'sftp' => __( 'SFTP/SSH', 'backup-restore-migrate' ),
							's3' => __( 'Amazon S3', 'backup-restore-migrate' ),
							'google_drive' => __( 'Google Drive', 'backup-restore-migrate' ),
							'dropbox' => __( 'Dropbox', 'backup-restore-migrate' ),
							'google_cloud' => __( 'Google Cloud Storage', 'backup-restore-migrate' ),
							'backblaze' => __( 'Backblaze B2', 'backup-restore-migrate' ),
							'custom_s3' => __( 'Custom S3-Compatible', 'backup-restore-migrate' ),
						);

						foreach ( $storage_types as $type => $label ) :
							$settings = get_option( 'wpbm_storage_' . $type, array() );
							?>
							<div class="wpbm-storage-type">
								<h3><?php echo esc_html( $label ); ?></h3>
								<button type="button" class="button wpbm-configure-storage" data-storage-type="<?php echo esc_attr( $type ); ?>">
									<?php esc_html_e( 'Configure', 'backup-restore-migrate' ); ?>
								</button>
								<button type="button" class="button wpbm-test-storage" data-storage-type="<?php echo esc_attr( $type ); ?>">
									<?php esc_html_e( 'Test Connection', 'backup-restore-migrate' ); ?>
								</button>
							</div>
						<?php endforeach; ?>
					</div>

				<?php elseif ( $active_tab === 'exclude' ) : ?>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Tables', 'backup-restore-migrate' ); ?></th>
							<td>
								<?php
								global $wpdb;
								$tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );

								foreach ( $tables as $table ) :
									$table_name = $table[0];
									?>
									<label>
										<input type="checkbox" name="exclude_tables[]" value="<?php echo esc_attr( $table_name ); ?>" <?php checked( in_array( $table_name, $exclude_tables ) ); ?> />
										<?php echo esc_html( $table_name ); ?>
									</label><br>
								<?php endforeach; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="exclude_files"><?php esc_html_e( 'Exclude Files/Directories', 'backup-restore-migrate' ); ?></label></th>
							<td>
								<textarea name="exclude_files" id="exclude_files" rows="10" cols="50" class="large-text"><?php echo esc_textarea( implode( "\n", $exclude_files ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Enter one path per line. Paths are relative to WordPress root.', 'backup-restore-migrate' ); ?></p>
								<p class="description"><?php esc_html_e( 'Default exclusions: cache directories, backup directories, .git, node_modules', 'backup-restore-migrate' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<p class="submit">
					<input type="submit" name="wpbm_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'backup-restore-migrate' ); ?>" />
				</p>
			</form>

			<!-- Storage Configuration Modal -->
			<div id="wpbm-storage-modal" class="wpbm-modal" style="display: none;">
				<div class="wpbm-modal-content">
					<span class="wpbm-modal-close">&times;</span>
					<h2 class="wpbm-storage-modal-title"></h2>
					<div class="wpbm-storage-modal-content"></div>
				</div>
			</div>
		</div>

		<script>
            jQuery(document).ready(function($) {
                // Update compression level display
                $('#compression_level').on('input', function() {
                    $('#compression_level_value').text($(this).val());
                });
            });
		</script>
		<?php
	}

	/**
	 * Admin notices
	 */
	public function admin_notices() {
		// Check if backup directory is writable
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/backup-restore-migrate';

		if ( ! is_writable( $backup_dir ) ) {
			?>
			<div class="notice notice-error">
				<p><?php printf( esc_html__( 'The backup directory %s is not writable. Please check file permissions.', 'backup-restore-migrate' ), '<code>' . esc_html( $backup_dir ) . '</code>' ); ?></p>
			</div>
			<?php
		}

		// Check PHP version
		if ( version_compare( PHP_VERSION, '5.6', '<' ) ) {
			?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Backup Migration Restore requires PHP 5.6 or higher. Some features may not work correctly.', 'backup-restore-migrate' ); ?></p>
			</div>
			<?php
		}

		// Check if ZipArchive is available
		if ( ! class_exists( 'ZipArchive' ) ) {
			?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'ZipArchive extension is not installed. Backup compression may not work.', 'backup-restore-migrate' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Delete backup
	 */
	private function delete_backup( $backup_id ) {
		global $wpdb;

		// Get backup details
		$backup = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bmr_backups WHERE id = %d",
			$backup_id
		) );

		if ( ! $backup ) {
			return false;
		}

		// Delete from storage
		$locations = json_decode( $backup->backup_location, true );

		if ( $locations ) {
			foreach ( $locations as $type => $location ) {
				try {
					$storage = BRM_Storage_Factory::create( $type );
					if ( $storage ) {
						$storage->delete( $location );
					}
				} catch ( Exception $e ) {
					// Log error but continue
					error_log( 'Failed to delete backup from storage: ' . $e->getMessage() );
				}
			}
		}

		// Delete database record
		$wpdb->delete(
			$wpdb->prefix . 'bmr_backups',
			array( 'id' => $backup_id )
		);

		// Delete related logs
		$wpdb->delete(
			$wpdb->prefix . 'bmr_logs',
			array( 'backup_id' => $backup_id )
		);

		return true;
	}
}