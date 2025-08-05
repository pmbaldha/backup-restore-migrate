<?php
/**
 * Plugin deactivation handler
 */
class BRM_Deactivator {

	/**
	 * Deactivate the plugin
	 */
	public static function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'bmr_scheduled_backup' );
		wp_clear_scheduled_hook( 'bmr_cleanup_old_backups' );

		// Clear any temporary data
		delete_transient( 'bmr_running_backup' );
	}
}