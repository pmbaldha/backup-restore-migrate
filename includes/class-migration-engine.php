<?php

/**
 * Migration engine class
 */
class BRM_Migration_Engine
{

	/**
	 * Logger instance
	 */
	private $logger;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->logger = new BRM_Logger();
	}

	/**
	 * Export for migration
	 */
	public function export_for_migration($options = array())
	{
		$options = wp_parse_args($options, array(
			'include_users' => true,
			'include_plugins' => true,
			'include_themes' => true,
			'include_uploads' => true,
			'include_settings' => true,
			'storage_destination' => 'local',
		));

		// Create full backup with migration metadata
		$backup_options = array(
			'backup_type' => 'full',
			'storage_destinations' => array($options['storage_destination']),
			'migration_export' => true,
			'migration_options' => $options,
		);

		$backup_engine = new BRM_Backup_Engine($backup_options);
		$result = $backup_engine->create_backup();

		if ($result['success']) {
			// Generate migration token
			$token = $this->generate_migration_token($result['backup_id']);

			return array(
				'success' => true,
				'backup_id' => $result['backup_id'],
				'migration_token' => $token,
				'message' => __('Migration package created successfully', 'backup-restore-migrate'),
			);
		}

		return $result;
	}

	/**
	 * Import from migration
	 */
	public function import_from_migration($backup_id, $token, $options = array())
	{
		// Verify migration token
		if (!$this->verify_migration_token($backup_id, $token)) {
			return array(
				'success' => false,
				'message' => __('Invalid migration token', 'backup-restore-migrate'),
			);
		}

		$options = wp_parse_args($options, array(
			'update_urls' => true,
			'update_paths' => true,
			'preserve_users' => false,
			'preserve_settings' => false,
			'test_mode' => false,
		));

		// Perform restore with migration options
		$restore_engine = new BRM_Restore_Engine();
		$result = $restore_engine->restore_backup($backup_id, $options);

		if ($result['success']) {
			// Additional migration tasks
			$this->post_migration_tasks($options);

			return array(
				'success' => true,
				'message' => __('Migration completed successfully', 'backup-restore-migrate'),
			);
		}

		return $result;
	}

	/**
	 * Clone site
	 */
	public function clone_site($destination_url, $options = array())
	{
		$options = wp_parse_args($options, array(
			'clone_type' => 'full', // full, staging, development
			'update_robots' => true,
			'disable_emails' => true,
			'change_prefix' => false,
			'new_prefix' => '',
		));

		// Create backup
		$backup_result = $this->export_for_migration(array(
			'storage_destination' => 'local',
		));

		if (!$backup_result['success']) {
			return $backup_result;
		}

		// Generate clone package
		$clone_data = array(
			'backup_id' => $backup_result['backup_id'],
			'source_url' => get_site_url(),
			'destination_url' => $destination_url,
			'options' => $options,
			'created_at' => current_time('mysql'),
		);

		// Save clone data
		update_option('brm_clone_data_' . $backup_result['backup_id'], $clone_data);

		return array(
			'success' => true,
			'backup_id' => $backup_result['backup_id'],
			'clone_token' => $backup_result['migration_token'],
			'instructions' => $this->get_clone_instructions($destination_url),
		);
	}

	/**
	 * Generate migration token
	 */
	private function generate_migration_token($backup_id)
	{
		$token = wp_generate_password(32, false);

		set_transient('brm_migration_token_' . $backup_id, $token, DAY_IN_SECONDS * 7);

		return $token;
	}

	/**
	 * Verify migration token
	 */
	private function verify_migration_token($backup_id, $token)
	{
		$stored_token = get_transient('brm_migration_token_' . $backup_id);

		return $stored_token && $stored_token === $token;
	}

	/**
	 * Post migration tasks
	 */
	private function post_migration_tasks($options)
	{
		// Update .htaccess
		$this->update_htaccess();

		// Update wp-config.php if needed
		if ($options['update_paths']) {
			$this->update_config_paths();
		}

		// Regenerate salts
		$this->regenerate_salts();

		// Clear transients
		$this->clear_transients();

		// Update cron jobs
		$this->update_cron_jobs();

		// Trigger migration complete action
		do_action('brm_migration_complete', $options);
	}

	/**
	 * Update .htaccess
	 */
	private function update_htaccess()
	{
		if (!function_exists('save_mod_rewrite_rules')) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		save_mod_rewrite_rules();
	}

	/**
	 * Update config paths
	 */
	private function update_config_paths()
	{
		// This would need to be implemented based on specific requirements
		// as wp-config.php modifications require special handling
	}

	/**
	 * Regenerate salts
	 */
	private function regenerate_salts()
	{
		// Generate new salts
		$salts = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		foreach ($salts as $salt) {
			if (!defined($salt)) {
				define($salt, wp_generate_password(64, true, true));
			}
		}
	}

	/**
	 * Clear transients
	 */
	private function clear_transients()
	{
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_%' 
			OR option_name LIKE '_site_transient_%'"
		);
	}

	/**
	 * Update cron jobs
	 */
	private function update_cron_jobs()
	{
		// Clear all cron jobs
		$cron = _get_cron_array();

		foreach ($cron as $timestamp => $cronhooks) {
			foreach ($cronhooks as $hook => $keys) {
				foreach ($keys as $k => $v) {
					wp_unschedule_event($timestamp, $hook, $v['args']);
				}
			}
		}

		// Re-schedule default WordPress cron jobs
		wp_schedule_event(time(), 'twicedaily', 'wp_version_check');
		wp_schedule_event(time(), 'twicedaily', 'wp_update_plugins');
		wp_schedule_event(time(), 'twicedaily', 'wp_update_themes');
		wp_schedule_event(time(), 'daily', 'wp_scheduled_delete');

		// Re-schedule plugin cron jobs
		do_action('brm_reschedule_cron_jobs');
	}

	/**
	 * Get clone instructions
	 */
	private function get_clone_instructions($destination_url)
	{
		return sprintf(
			__('1. Install WordPress on %s
2. Install and activate Backup Migration Restore plugin
3. Go to Tools > Backup & Restore > Clone Import
4. Enter the clone token and follow the instructions
5. The cloning process will handle URL updates automatically', 'backup-restore-migrate'),
			$destination_url
		);
	}

	/**
	 * Search and replace in database
	 */
	public function search_replace_database($search, $replace, $tables = array())
	{
		global $wpdb;

		if (empty($tables)) {
			$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
			$tables = array_map(function ($table) {
				return $table[0];
			}, $tables);
		}

		$report = array(
			'tables' => 0,
			'rows' => 0,
			'changes' => 0,
			'errors' => array(),
		);

		foreach ($tables as $table) {
			$report['tables']++;

			// Get columns
			$columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`");
			$text_columns = array();

			foreach ($columns as $column) {
				if (preg_match('/text|varchar|char|blob/i', $column->Type)) {
					$text_columns[] = $column->Field;
				}
			}

			if (empty($text_columns)) {
				continue;
			}

			// Process rows in chunks
			$offset = 0;
			$chunk_size = 1000;

			do {
				$rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $offset, $chunk_size");

				if (empty($rows)) {
					break;
				}

				foreach ($rows as $row) {
					$report['rows']++;
					$updates = array();
					$where = array();

					foreach ($text_columns as $column) {
						if (isset($row->$column)) {
							$value = $row->$column;
							$new_value = $this->recursive_unserialize_replace($search, $replace, $value);

							if ($value !== $new_value) {
								$updates[$column] = $new_value;
								$report['changes']++;
							}
						}
					}

					if (!empty($updates)) {
						// Build where clause
						$primary_keys = $this->get_primary_keys($table);

						foreach ($primary_keys as $key) {
							if (isset($row->$key)) {
								$where[$key] = $row->$key;
							}
						}

						if (!empty($where)) {
							$result = $wpdb->update($table, $updates, $where);

							if ($result === false) {
								$report['errors'][] = sprintf(
									__('Failed to update table %s: %s', 'backup-restore-migrate'),
									$table,
									$wpdb->last_error
								);
							}
						}
					}
				}

				$offset += $chunk_size;

			} while (count($rows) === $chunk_size);
		}

		return $report;
	}

	/**
	 * Recursive unserialize replace
	 */
	private function recursive_unserialize_replace($search, $replace, $data)
	{
		try {
			if (is_string($data) && is_serialized($data)) {
				$unserialized = unserialize($data);
				$data = $this->recursive_unserialize_replace($search, $replace, $unserialized);
				return serialize($data);
			} elseif (is_array($data)) {
				foreach ($data as $key => $value) {
					$data[$key] = $this->recursive_unserialize_replace($search, $replace, $value);
				}
			} elseif (is_object($data)) {
				foreach ($data as $key => $value) {
					$data->$key = $this->recursive_unserialize_replace($search, $replace, $value);
				}
			} elseif (is_string($data)) {
				$data = str_replace($search, $replace, $data);
			}
		} catch (Exception $e) {
			// If unserialize fails, treat as string
			if (is_string($data)) {
				$data = str_replace($search, $replace, $data);
			}
		}

		return $data;
	}

	/**
	 * Get primary keys for table
	 */
	private function get_primary_keys($table)
	{
		global $wpdb;

		$keys = array();
		$results = $wpdb->get_results("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");

		foreach ($results as $key) {
			$keys[] = $key->Column_name;
		}

		// If no primary key, try to use first unique key
		if (empty($keys)) {
			$results = $wpdb->get_results("SHOW KEYS FROM `$table` WHERE Non_unique = 0");

			if (!empty($results)) {
				$keys[] = $results[0]->Column_name;
			}
		}

		// If still no keys, use all columns (last resort)
		if (empty($keys)) {
			$columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`");

			foreach ($columns as $column) {
				$keys[] = $column->Field;
			}
		}

		return $keys;
	}
}