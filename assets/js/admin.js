/**
 * WP Backup & Migration Admin JavaScript
 */
(function($) {
    'use strict';

    var WPBM = {

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Create backup
            $(document).on('click', '.wpbm-create-backup', this.createBackup);

            // Restore backup
            $(document).on('click', '.wpbm-restore-backup', this.restoreBackup);

            // Delete backup
            $(document).on('click', '.wpbm-delete-backup', this.deleteBackup);

            // Download backup
            $(document).on('click', '.wpbm-download-backup', this.downloadBackup);

            // Schedule modal
            $(document).on('click', '.wpbm-add-schedule', this.openScheduleModal);
            $(document).on('click', '.wpbm-edit-schedule', this.editSchedule);
            $(document).on('submit', '#wpbm-schedule-form', this.saveSchedule);

            // Delete schedule
            $(document).on('click', '.wpbm-delete-schedule', this.deleteSchedule);

            // Toggle schedule
            $(document).on('click', '.wpbm-toggle-schedule', this.toggleSchedule);

            // Storage configuration
            $(document).on('click', '.wpbm-configure-storage', this.configureStorage);
            $(document).on('click', '.wpbm-test-storage', this.testStorage);

            // Modal controls
            $(document).on('click', '.wpbm-modal-close, .wpbm-modal-cancel', this.closeModal);

            // Migration forms
            $(document).on('submit', '#wpbm-export-form', this.exportSite);
            $(document).on('submit', '#wpbm-import-form', this.importSite);
            $(document).on('submit', '#wpbm-clone-form', this.cloneSite);
        },

        /**
         * Create backup
         */
        createBackup: function(e) {
            e.preventDefault();

            var $button = $(this);
            var backupType = $button.data('type') || 'full';

            // Show progress modal
            WPBM.showProgressModal(wpbm.strings.creating_backup);

            // Start backup
            $.post(wpbm.ajax_url, {
                action: 'bmr_create_backup',
                nonce: wpbm.nonce,
                backup_type: backupType
            }, function(response) {
                if (response.success) {
                    // Start progress monitoring
                    WPBM.monitorProgress(response.backup_id, 'backup');
                } else {
                    WPBM.hideProgressModal();
                    alert(response.message || wpbm.strings.error);
                }
            });
        },

        /**
         * Monitor progress
         */
        monitorProgress: function(id, type) {
            var progressInterval = setInterval(function() {
                $.post(wpbm.ajax_url, {
                    action: 'bmr_get_' + type + '_progress',
                    nonce: wpbm.nonce,
                    backup_id: id
                }, function(response) {
                    if (response.success && response.data) {
                        var progress = response.data;

                        // Update progress bar
                        $('.wpbm-progress-fill').css('width', progress.percentage + '%');
                        $('.wpbm-progress-percentage').text(progress.percentage + '%');
                        $('.wpbm-progress-message').text(progress.message);

                        // Check if completed
                        if (progress.status === 'completed' || progress.status === 'failed') {
                            clearInterval(progressInterval);

                            setTimeout(function() {
                                WPBM.hideProgressModal();

                                if (progress.status === 'completed') {
                                    location.reload();
                                } else {
                                    alert(progress.message);
                                }
                            }, 1000);
                        }
                    }
                });
            }, 2000);
        },

        /**
         * Restore backup
         */
        restoreBackup: function(e) {
            e.preventDefault();

            if (!confirm(wpbm.strings.confirm_restore)) {
                return;
            }

            var $button = $(this);
            var backupId = $button.data('backup-id');

            // Show progress modal
            WPBM.showProgressModal(wpbm.strings.restoring_backup);

            // Start restore
            $.post(wpbm.ajax_url, {
                action: 'bmr_restore_backup',
                nonce: wpbm.nonce,
                backup_id: backupId,
                create_restore_point: 1,
                update_urls: 1
            }, function(response) {
                WPBM.hideProgressModal();

                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert(response.message || wpbm.strings.error);
                }
            });
        },

        /**
         * Delete backup
         */
        deleteBackup: function(e) {
            e.preventDefault();

            if (!confirm(wpbm.strings.confirm_delete)) {
                return;
            }

            var $button = $(this);
            var backupId = $button.data('backup-id');

            $button.prop('disabled', true);

            $.post(wpbm.ajax_url, {
                action: 'bmr_delete_backup',
                nonce: wpbm.nonce,
                backup_id: backupId
            }, function(response) {
                if (response.success) {
                    $button.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    $button.prop('disabled', false);
                    alert(response.data || wpbm.strings.error);
                }
            });
        },

        /**
         * Download backup
         */
        downloadBackup: function(e) {
            e.preventDefault();

            var backupId = $(this).data('backup-id');

            window.location.href = wpbm.ajax_url + '?action=bmr_download_backup&nonce=' + wpbm.nonce + '&backup_id=' + backupId;
        },

        /**
         * Open schedule modal
         */
        openScheduleModal: function(e) {
            e.preventDefault();

            $('#wpbm-schedule-form')[0].reset();
            $('#wpbm-schedule-form input[name="schedule_id"]').val('');
            $('#wpbm-schedule-modal').show();
        },

        /**
         * Edit schedule
         */
        editSchedule: function(e) {
            e.preventDefault();

            var scheduleId = $(this).data('schedule-id');

            // Load schedule data (implement as needed)
            $('#wpbm-schedule-modal').show();
        },

        /**
         * Save schedule
         */
        saveSchedule: function(e) {
            e.preventDefault();

            var formData = $(this).serialize();

            $.post(wpbm.ajax_url, formData + '&action=bmr_save_schedule&nonce=' + wpbm.nonce, function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert(response.data || wpbm.strings.error);
                }
            });
        },

        /**
         * Delete schedule
         */
        deleteSchedule: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to delete this schedule?')) {
                return;
            }

            var $button = $(this);
            var scheduleId = $button.data('schedule-id');

            $.post(wpbm.ajax_url, {
                action: 'bmr_delete_schedule',
                nonce: wpbm.nonce,
                schedule_id: scheduleId
            }, function(response) {
                if (response.success) {
                    $button.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data || wpbm.strings.error);
                }
            });
        },

        /**
         * Toggle schedule
         */
        toggleSchedule: function(e) {
            e.preventDefault();

            var $button = $(this);
            var scheduleId = $button.data('schedule-id');
            var isActive = $button.data('active');

            // Implement toggle functionality
        },

        /**
         * Configure storage
         */
        configureStorage: function(e) {
            e.preventDefault();

            var storageType = $(this).data('storage-type');

            // Load storage configuration form
            $('#wpbm-storage-modal').show();
        },

        /**
         * Test storage connection
         */
        testStorage: function(e) {
            e.preventDefault();

            var $button = $(this);
            var storageType = $button.data('storage-type');

            $button.prop('disabled', true).text('Testing...');

            $.post(wpbm.ajax_url, {
                action: 'bmr_test_storage',
                nonce: wpbm.nonce,
                storage_type: storageType
            }, function(response) {
                $button.prop('disabled', false).text('Test Connection');

                if (response.success) {
                    alert(response.data);
                } else {
                    alert(response.data || 'Connection failed');
                }
            });
        },

        /**
         * Export site
         */
        exportSite: function(e) {
            e.preventDefault();

            var formData = $(this).serialize();

            WPBM.showProgressModal('Creating migration package...');

            $.post(wpbm.ajax_url, formData + '&action=bmr_export_site&nonce=' + wpbm.nonce, function(response) {
                WPBM.hideProgressModal();

                if (response.success) {
                    // Show migration token and instructions
                    alert('Migration Token: ' + response.migration_token + '\nBackup ID: ' + response.backup_id);
                } else {
                    alert(response.message || wpbm.strings.error);
                }
            });
        },

        /**
         * Import site
         */
        importSite: function(e) {
            e.preventDefault();

            var formData = $(this).serialize();

            if (!confirm('This will overwrite your current site. Are you sure?')) {
                return;
            }

            WPBM.showProgressModal('Importing site...');

            $.post(wpbm.ajax_url, formData + '&action=bmr_import_site&nonce=' + wpbm.nonce, function(response) {
                WPBM.hideProgressModal();

                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert(response.message || wpbm.strings.error);
                }
            });
        },

        /**
         * Clone site
         */
        cloneSite: function(e) {
            e.preventDefault();

            var formData = $(this).serialize();

            WPBM.showProgressModal('Creating clone...');

            $.post(wpbm.ajax_url, formData + '&action=bmr_clone_site&nonce=' + wpbm.nonce, function(response) {
                WPBM.hideProgressModal();

                if (response.success) {
                    // Show clone instructions
                    alert(response.instructions);
                } else {
                    alert(response.message || wpbm.strings.error);
                }
            });
        },

        /**
         * Show progress modal
         */
        showProgressModal: function(title) {
            $('#wpbm-progress-modal .wpbm-modal-title').text(title);
            $('#wpbm-progress-modal .wpbm-progress-fill').css('width', '0%');
            $('#wpbm-progress-modal .wpbm-progress-percentage').text('0%');
            $('#wpbm-progress-modal .wpbm-progress-message').text('Initializing...');
            $('#wpbm-progress-modal').show();
        },

        /**
         * Hide progress modal
         */
        hideProgressModal: function() {
            $('#wpbm-progress-modal').hide();
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $(this).closest('.wpbm-modal').hide();
        }
    };

    // Initialize when ready
    $(document).ready(function() {
        WPBM.init();
    });

})(jQuery);