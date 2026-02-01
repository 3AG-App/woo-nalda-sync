/**
 * Woo Nalda Sync Admin JavaScript
 */

(function ($) {
    'use strict';

    // Main object
    const WNS = {

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function (text) {
            if (text === null || text === undefined) {
                return '';
            }
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        },

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initLocalTimes();
        },

        /**
         * Convert timestamps to local timezone and relative time
         */
        initLocalTimes: function () {
            $('.wns-local-time').each(function () {
                const $el = $(this);
                const timestamp = parseInt($el.data('timestamp'), 10);

                if (!timestamp || isNaN(timestamp)) {
                    return;
                }

                // Convert Unix timestamp to milliseconds
                const date = new Date(timestamp * 1000);
                const now = new Date();

                // Calculate relative time
                const diffMs = now - date;

                // Handle negative differences (future dates or clock skew)
                if (diffMs < 0) {
                    $el.text('just now');
                    $el.attr('title', date.toLocaleString());
                    return;
                }

                const diffSec = Math.floor(diffMs / 1000);
                const diffMin = Math.floor(diffSec / 60);
                const diffHour = Math.floor(diffMin / 60);
                const diffDay = Math.floor(diffHour / 24);

                let relativeTime;
                if (diffSec < 60) {
                    relativeTime = diffSec <= 5 ? 'just now' : diffSec + ' sec ago';
                } else if (diffMin < 60) {
                    relativeTime = diffMin + ' min ago';
                } else if (diffHour < 24) {
                    relativeTime = diffHour + ' hour' + (diffHour !== 1 ? 's' : '') + ' ago';
                } else if (diffDay < 30) {
                    relativeTime = diffDay + ' day' + (diffDay !== 1 ? 's' : '') + ' ago';
                } else {
                    const months = Math.floor(diffDay / 30);
                    relativeTime = months + ' month' + (months !== 1 ? 's' : '') + ' ago';
                }

                // Update text
                $el.text(relativeTime);

                // Set title to local datetime
                const localDateTime = date.toLocaleString();
                $el.attr('title', localDateTime);
            });
        },

        /**
         * Bind events
         */
        bindEvents: function () {
            // Settings
            $('#wns-settings-form').on('submit', this.saveSettings.bind(this));
            $('#wns-test-sftp').on('click', this.testSftp.bind(this));
            $('#wns-test-nalda-api').on('click', this.testNaldaApi.bind(this));

            // Sync toggles
            $('.wns-toggle-sync').on('change', this.toggleSync.bind(this));

            // Run now buttons
            $('.wns-run-now').on('click', this.runNow.bind(this));

            // Logs
            $('.wns-view-log').on('click', this.viewLog.bind(this));
            $('#wns-clear-logs').on('click', this.clearLogs.bind(this));

            // License
            $('#wns-license-form').on('submit', this.activateLicense.bind(this));
            $('#wns-deactivate-license').on('click', this.deactivateLicense.bind(this));
            $('#wns-check-license').on('click', this.checkLicense.bind(this));

            // Updates
            $('#wns-check-update').on('click', this.checkUpdate.bind(this));
            $('#wns-install-update').on('click', this.installUpdate.bind(this));

            // Modal
            $('.wns-modal-close').on('click', this.closeModal.bind(this));
            $('.wns-modal').on('click', function (e) {
                if ($(e.target).hasClass('wns-modal')) {
                    WNS.closeModal();
                }
            });

            // ESC key to close modal
            $(document).on('keyup', function (e) {
                if (e.key === 'Escape') {
                    WNS.closeModal();
                }
            });
        },

        /**
         * Save settings
         */
        saveSettings: function (e) {
            e.preventDefault();

            const $form = $('#wns-settings-form');
            const $btn = $form.find('button[type="submit"]');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.saving);

            const data = {
                sftp_host: $('#wns-sftp-host').val(),
                sftp_port: $('#wns-sftp-port').val(),
                sftp_username: $('#wns-sftp-username').val(),
                sftp_password: $('#wns-sftp-password').val(),
                nalda_api_key: $('#wns-nalda-api-key').val(),
                product_export_interval: $('#wns-product-export-interval').val(),
                product_export_enabled: $('#wns-product-export-enabled').is(':checked'),
                product_default_behavior: $('#wns-product-default-behavior').val(),
                order_import_interval: $('#wns-order-import-interval').val(),
                order_import_enabled: $('#wns-order-import-enabled').is(':checked'),
                order_import_range: $('#wns-order-import-range').val(),
                order_status_export_interval: $('#wns-order-status-export-interval').val(),
                order_status_export_enabled: $('#wns-order-status-export-enabled').is(':checked'),
                default_country: $('#wns-default-country').val(),
                default_currency: $('#wns-default-currency').val(),
                default_delivery_days: $('#wns-default-delivery-days').val(),
                default_return_days: $('#wns-default-return-days').val()
            };

            this.ajax('wns_save_settings', data)
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WNS.toast(wns_admin.strings.error, 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Test SFTP connection
         */
        testSftp: function (e) {
            e.preventDefault();

            const $btn = $('#wns-test-sftp');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.testing);

            this.ajax('wns_test_sftp', {})
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WNS.toast(wns_admin.strings.connection_failed, 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Test Nalda API connection
         */
        testNaldaApi: function (e) {
            e.preventDefault();

            const $btn = $('#wns-test-nalda-api');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.testing);

            this.ajax('wns_test_nalda_api', {})
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WNS.toast(wns_admin.strings.connection_failed, 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Toggle sync enabled/disabled
         */
        toggleSync: function (e) {
            const $input = $(e.target);
            const enabled = $input.is(':checked');
            const syncType = $input.data('sync-type');
            let action = '';

            switch (syncType) {
                case 'product_export':
                    action = 'wns_toggle_product_export';
                    break;
                case 'order_import':
                    action = 'wns_toggle_order_import';
                    break;
                case 'order_status_export':
                    action = 'wns_toggle_order_status_export';
                    break;
            }

            if (!action) {
                return;
            }

            this.ajax(action, { enabled: enabled })
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                    } else {
                        WNS.toast(response.data.message, 'error');
                        // Revert toggle
                        $input.prop('checked', !enabled);
                    }
                })
                .fail(function () {
                    WNS.toast(wns_admin.strings.error, 'error');
                    $input.prop('checked', !enabled);
                });
        },

        /**
         * Run sync now
         */
        runNow: function (e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const syncType = $btn.data('sync-type');
            const originalHtml = $btn.html();
            let action = '';
            let confirmMsg = wns_admin.strings.confirm_sync;

            switch (syncType) {
                case 'product_export':
                    action = 'wns_run_product_export';
                    break;
                case 'order_import':
                    action = 'wns_run_order_import';
                    break;
                case 'order_status_export':
                    action = 'wns_run_order_status_export';
                    break;
            }

            if (!action) {
                return;
            }

            if (!confirm(confirmMsg)) {
                return;
            }

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.running);

            this.ajax(action, {})
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                        // Reload page to update stats
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        WNS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WNS.toast(wns_admin.strings.error, 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * View log details
         */
        viewLog: function (e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const logId = $btn.data('log-id');

            // Store original icon and show spinner
            const $icon = $btn.find('.dashicons');
            const originalClass = $icon.attr('class');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-update wns-spin');
            $btn.css('pointer-events', 'none');

            this.ajax('wns_get_log_details', { log_id: logId })
                .done(function (response) {
                    if (response.success) {
                        WNS.showLogModal(response.data.log);
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WNS.toast('Failed to load log details', 'error');
                })
                .always(function () {
                    // Restore original icon
                    $icon.attr('class', originalClass);
                    $btn.css('pointer-events', '');
                });
        },

        /**
         * Show log modal
         */
        showLogModal: function (log) {
            const esc = this.escapeHtml.bind(this);
            let html = '<div class="wns-log-detail">';

            // Status
            html += '<div class="wns-log-detail-row">';
            html += '<span class="wns-log-detail-label">Status</span>';
            html += '<span class="wns-log-detail-value">';
            html += '<span class="wns-status-badge wns-status-' + esc(log.status) + '">';
            if (log.status === 'success') {
                html += '<span class="dashicons dashicons-yes-alt"></span>';
            } else if (log.status === 'error') {
                html += '<span class="dashicons dashicons-dismiss"></span>';
            } else {
                html += '<span class="dashicons dashicons-warning"></span>';
            }
            html += '</span> ' + esc(log.status.charAt(0).toUpperCase() + log.status.slice(1));
            html += '</span></div>';

            // Type
            html += '<div class="wns-log-detail-row">';
            html += '<span class="wns-log-detail-label">Type</span>';
            html += '<span class="wns-log-detail-value">' + esc(this.formatLogType(log.type)) + '</span>';
            html += '</div>';

            // Trigger
            if (log.trigger_type) {
                html += '<div class="wns-log-detail-row">';
                html += '<span class="wns-log-detail-label">Trigger</span>';
                html += '<span class="wns-log-detail-value">' + esc(log.trigger_type.charAt(0).toUpperCase() + log.trigger_type.slice(1)) + '</span>';
                html += '</div>';
            }

            // Message
            html += '<div class="wns-log-detail-row">';
            html += '<span class="wns-log-detail-label">Message</span>';
            html += '<span class="wns-log-detail-value">' + esc(log.message) + '</span>';
            html += '</div>';

            // Duration
            if (log.duration && parseFloat(log.duration) > 0) {
                html += '<div class="wns-log-detail-row">';
                html += '<span class="wns-log-detail-label">Duration</span>';
                html += '<span class="wns-log-detail-value">' + parseFloat(log.duration).toFixed(2) + ' seconds</span>';
                html += '</div>';
            }

            // Date - convert to local timezone
            html += '<div class="wns-log-detail-row">';
            html += '<span class="wns-log-detail-label">Date</span>';
            const logDate = new Date(log.created_at.replace(' ', 'T') + 'Z'); // Parse as UTC
            const localDateStr = logDate.toLocaleString();
            html += '<span class="wns-log-detail-value">' + esc(localDateStr) + '</span>';
            html += '</div>';

            // Stats
            if (log.stats && typeof log.stats === 'object' && Object.keys(log.stats).length > 0) {
                html += '<div class="wns-log-detail-row">';
                html += '<span class="wns-log-detail-label">Statistics</span>';
                html += '<div class="wns-log-detail-value">';
                html += '<div class="wns-log-stats-grid">';

                if (log.stats.total !== undefined) {
                    html += '<div class="wns-log-stat-item"><strong>' + log.stats.total + '</strong><span>Total</span></div>';
                }
                if (log.stats.exported !== undefined) {
                    html += '<div class="wns-log-stat-item"><strong>' + log.stats.exported + '</strong><span>Exported</span></div>';
                }
                if (log.stats.imported !== undefined) {
                    html += '<div class="wns-log-stat-item"><strong>' + log.stats.imported + '</strong><span>Imported</span></div>';
                }
                if (log.stats.skipped !== undefined) {
                    html += '<div class="wns-log-stat-item"><strong>' + log.stats.skipped + '</strong><span>Skipped</span></div>';
                }
                if (log.stats.errors !== undefined && log.stats.errors > 0) {
                    html += '<div class="wns-log-stat-item"><strong>' + log.stats.errors + '</strong><span>Errors</span></div>';
                }

                html += '</div></div></div>';
            }

            // Errors list
            if (log.errors && Array.isArray(log.errors) && log.errors.length > 0) {
                html += '<div class="wns-log-detail-row">';
                html += '<span class="wns-log-detail-label">Error Details</span>';
                html += '<div class="wns-log-detail-value">';
                html += '<div class="wns-log-errors">';
                html += '<strong>Errors:</strong><ul>';
                log.errors.forEach(function (err) {
                    html += '<li>' + esc(err) + '</li>';
                });
                html += '</ul></div></div></div>';
            }

            html += '</div>';

            $('#wns-log-modal-body').html(html);
            $('#wns-log-modal').addClass('wns-modal-open');
        },

        /**
         * Format log type for display
         */
        formatLogType: function (type) {
            const types = {
                'product_export': 'Product Export',
                'order_import': 'Order Import',
                'order_status_export': 'Order Status Export',
                'license': 'License',
                'watchdog': 'Watchdog'
            };
            return types[type] || type.charAt(0).toUpperCase() + type.slice(1);
        },

        /**
         * Clear logs
         */
        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm(wns_admin.strings.confirm_clear_logs)) {
                return;
            }

            const $btn = $('#wns-clear-logs');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.clearing);

            this.ajax('wns_clear_logs', {})
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                        location.reload();
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Activate license
         */
        activateLicense: function (e) {
            e.preventDefault();

            const licenseKey = $('#wns-license-key').val().trim();
            if (!licenseKey) {
                WNS.toast('Please enter a license key', 'error');
                return;
            }

            const $form = $('#wns-license-form');
            const $btn = $form.find('button[type="submit"]');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.activating);

            this.ajax('wns_activate_license', { license_key: licenseKey })
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        WNS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WNS.toast('Failed to activate license', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Deactivate license
         */
        deactivateLicense: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to deactivate this license?')) {
                return;
            }

            const $btn = $('#wns-deactivate-license');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> ' + wns_admin.strings.deactivating);

            this.ajax('wns_deactivate_license', {})
                .done(function (response) {
                    WNS.toast(response.data.message, 'success');
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                })
                .fail(function () {
                    WNS.toast('Failed to deactivate license', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Check license
         */
        checkLicense: function (e) {
            e.preventDefault();

            const $btn = $('#wns-check-license');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> Checking...');

            this.ajax('wns_check_license', {})
                .done(function (response) {
                    if (response.success) {
                        if (response.data.activated) {
                            WNS.toast('License is valid and active', 'success');
                        } else {
                            WNS.toast('License is not active for this domain', 'error');
                        }
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WNS.toast('Failed to check license', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Check for plugin updates
         */
        checkUpdate: function (e) {
            e.preventDefault();

            const $btn = $('#wns-check-update');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> Checking...');

            this.ajax('wns_check_update', {})
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, response.data.has_update ? 'info' : 'success');

                        if (response.data.has_update) {
                            // Reload to show update button
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        WNS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WNS.toast('Failed to check for updates', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Install plugin update
         */
        installUpdate: function (e) {
            e.preventDefault();

            const $btn = $('#wns-install-update');
            const version = $btn.data('version');

            if (!confirm('Are you sure you want to update to version ' + version + '?')) {
                return;
            }

            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wns-spinner"></span> Updating...');

            // Disable other buttons during update
            $('#wns-check-update').prop('disabled', true);

            this.ajax('wns_install_update', {})
                .done(function (response) {
                    if (response.success) {
                        WNS.toast(response.data.message, 'success');

                        if (response.data.reload) {
                            setTimeout(function () {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        WNS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                        $('#wns-check-update').prop('disabled', false);
                    }
                })
                .fail(function () {
                    WNS.toast('Update failed. Please try again.', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                    $('#wns-check-update').prop('disabled', false);
                });
        },

        /**
         * Close modal
         */
        closeModal: function () {
            $('.wns-modal').removeClass('wns-modal-open');
        },

        /**
         * AJAX helper
         */
        ajax: function (action, data) {
            data = data || {};
            data.action = action;
            data.nonce = wns_admin.nonce;

            return $.ajax({
                url: wns_admin.ajax_url,
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        },

        /**
         * Toast notification
         */
        toast: function (message, type) {
            type = type || 'success';

            // Remove existing toasts
            $('.wns-toast').remove();

            const $toast = $('<div class="wns-toast wns-toast-' + type + '">' + message + '</div>');
            $('body').append($toast);

            // Auto remove after 4 seconds
            setTimeout(function () {
                $toast.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 4000);
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        WNS.init();
    });

})(jQuery);
