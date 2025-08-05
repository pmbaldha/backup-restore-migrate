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
            $(document).on('click', '.brm-create-backup', this.createBackup);
            $(document).on('submit', '#brm-backup-form', this.submitBackupForm);

            // Restore backup
            $(document).on('click', '.brm-restore-backup', this.restoreBackup);

            // Delete backup
            $(document).on('click', '.brm-delete-backup', this.deleteBackup);

            // Download backup
            $(document).on('click', '.brm-download-backup', this.downloadBackup);

            // Schedule modal
            $(document).on('click', '.brm-add-schedule', this.openScheduleModal);
            $(document).on('click', '.brm-edit-schedule', this.editSchedule);
            $(document).on('submit', '#brm-schedule-form', this.saveSchedule);

            // Delete schedule
            $(document).on('click', '.brm-delete-schedule', this.deleteSchedule);

            // Toggle schedule
            $(document).on('click', '.brm-toggle-schedule', this.toggleSchedule);

            // Storage configuration
            $(document).on('click', '.brm-configure-storage', this.configureStorage);
            $(document).on('click', '.brm-test-storage', this.testStorage);

            // Modal controls
            $(document).on('click', '.brm-modal-close, .brm-modal-cancel', this.closeModal);

            // Migration forms
            $(document).on('submit', '#brm-export-form', this.exportSite);
            $(document).on('submit', '#brm-import-form', this.importSite);
            $(document).on('submit', '#brm-clone-form', this.cloneSite);
        },

        /**
         * Create backup
         */
        createBackup: function(e) {
            e.preventDefault();

            var $button = $(this);
            var backupType = $button.data('type') || 'full';

            // Show backup modal instead of immediately creating backup
            WPBM.showBackupModal(backupType);
        },
        
        /**
         * Show backup modal
         */
        showBackupModal: function(backupType) {
            var $modal = $('#brm-backup-modal');
            var $form = $('#brm-backup-form');
            
            if ($form.length > 0) {
                $form[0].reset();
                $form.find('input[name="backup_type"][value="' + backupType + '"]').prop('checked', true);
            }
            
            if ($modal.length > 0) {
                $modal.fadeIn();
            }
        },
        
        /**
         * Submit backup form
         */
        submitBackupForm: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var formData = $form.serializeArray();
            var data = {
                action: 'brm_create_backup',
                nonce: wpbm.nonce
            };
            
            // Convert form data to object
            $.each(formData, function(i, field) {
                data[field.name] = field.value;
            });
            
            // Close modal and show progress
            $('#brm-backup-modal').fadeOut();
            WPBM.showProgressModal(wpbm.strings.creating_backup);
            
            // Start backup
            $.post(wpbm.ajax_url, data, function(response) {
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
                    action: 'brm_get_' + type + '_progress',
                    nonce: wpbm.nonce,
                    backup_id: id
                }, function(response) {
                    if (response.success && response.data) {
                        var progress = response.data;

                        // Update progress bar
                        $('.brm-progress-fill').css('width', progress.percentage + '%');
                        $('.brm-progress-percentage').text(progress.percentage + '%');
                        $('.brm-progress-message').text(progress.message);

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
                action: 'brm_restore_backup',
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
                action: 'brm_delete_backup',
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

            window.location.href = wpbm.ajax_url + '?action=brm_download_backup&nonce=' + wpbm.nonce + '&backup_id=' + backupId;
        },

        /**
         * Open schedule modal
         */
        openScheduleModal: function(e) {
            e.preventDefault();

            var $form = $('#brm-schedule-form');
            var $modal = $('#brm-schedule-modal');
            
            if ($form.length > 0) {
                $form[0].reset();
                $form.find('input[name="schedule_id"]').val('');
            }
            
            if ($modal.length > 0) {
                $modal.fadeIn();
            }
        },

        /**
         * Edit schedule
         */
        editSchedule: function(e) {
            e.preventDefault();

            var scheduleId = $(this).data('schedule-id');

            // Load schedule data (implement as needed)
            $('#brm-schedule-modal').show();
        },

        /**
         * Save schedule
         */
        saveSchedule: function(e) {
            e.preventDefault();

            var formData = $(this).serialize();

            $.post(wpbm.ajax_url, formData + '&action=brm_save_schedule&nonce=' + wpbm.nonce, function(response) {
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
                action: 'brm_delete_schedule',
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
            $('#brm-storage-modal').show();
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
                action: 'brm_test_storage',
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

            $.post(wpbm.ajax_url, formData + '&action=brm_export_site&nonce=' + wpbm.nonce, function(response) {
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

            $.post(wpbm.ajax_url, formData + '&action=brm_import_site&nonce=' + wpbm.nonce, function(response) {
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

            $.post(wpbm.ajax_url, formData + '&action=brm_clone_site&nonce=' + wpbm.nonce, function(response) {
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
            $('#brm-progress-modal .brm-modal-title').text(title);
            $('#brm-progress-modal .brm-progress-fill').css('width', '0%');
            $('#brm-progress-modal .brm-progress-percentage').text('0%');
            $('#brm-progress-modal .brm-progress-message').text('Initializing...');
            $('#brm-progress-modal').show();
        },

        /**
         * Hide progress modal
         */
        hideProgressModal: function() {
            $('#brm-progress-modal').hide();
        },

        /**
         * Close modal
         */
        closeModal: function(e) {
            if (e) {
                e.preventDefault();
            }
            
            var $modal = $(this).closest('.brm-modal');
            if ($modal.length > 0) {
                $modal.css('display', 'none');
            } else {
                // If clicked on close button, find parent modal differently
                $('.brm-modal:visible').css('display', 'none');
            }
        }
    };

    // Initialize when ready
    $(document).ready(function() {
        WPBM.init();
    });

})(jQuery);