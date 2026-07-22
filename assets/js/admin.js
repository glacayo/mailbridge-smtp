/**
 * MailBridge SMTP - Admin JavaScript
 *
 * @package MailBridge_SMTP
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * MailBridge SMTP Admin Class
     */
    class MailBridgeSmtpAdmin {
        
        /**
         * Constructor
         */
        constructor() {
            this.init();
        }
        
        /**
         * Initialize admin functionality
         */
        init() {
            this.bindEvents();
            this.toggleAuthFields();
            this.toggleDebugInfo();
        }
        
        /**
         * Bind DOM events
         */
        bindEvents() {
            // Test connection button
            $('#mailbridge_smtp_test_btn').on('click', (e) => this.testConnection(e));
            
            // Toggle authentication fields
            $('#mailbridge_smtp_auth').on('change', () => this.toggleAuthFields());
            
            // Toggle debug info
            $('#mailbridge_smtp_debug').on('change', () => this.toggleDebugInfo());
            
            // Validate host on blur
            $('#mailbridge_smtp_host').on('blur', () => this.validateHost());
            
            // Validate port on change
            $('#mailbridge_smtp_port').on('change', () => this.validatePort());
            
            // Warn about insecure settings
            $('#mailbridge_smtp_encryption').on('change', () => this.checkEncryption());

            // Diagnostic test buttons
            $('#mailbridge_diagnostic_wp_btn').on('click', (e) => this.testDiagnostic(e, 'native_wp_mail'));
            $('#mailbridge_diagnostic_mail_btn').on('click', (e) => this.testDiagnostic(e, 'direct_mail'));
        }
        
        /**
         * Toggle authentication fields visibility
         */
        toggleAuthFields() {
            const isChecked = $('#mailbridge_smtp_auth').is(':checked');
            const authFields = $('#mailbridge_smtp_username, #mailbridge_smtp_password').closest('tr');
            
            if (isChecked) {
                authFields.fadeIn(200);
            } else {
                authFields.fadeOut(200);
            }
        }
        
        /**
         * Toggle debug mode information
         */
        toggleDebugInfo() {
            const isChecked = $('#mailbridge_smtp_debug').is(':checked');
            const debugInfo = $('.debug-info');
            
            if (isChecked && debugInfo.length === 0) {
                const warning = $('<div class="debug-info"><p>' + 
                    '<strong>Security Notice:</strong> Debug mode will log sensitive information. ' +
                    'Only enable this for testing and disable it in production.</p></div>');
                $('#mailbridge_smtp_debug').closest('td').append(warning);
            } else if (!isChecked) {
                debugInfo.remove();
            }
        }
        
        /**
         * Validate SMTP host format
         * @return {boolean} Whether the host is valid
         */
        validateHost() {
            const host = $('#mailbridge_smtp_host').val().trim();
            const hostRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;

            if (host.toLowerCase() === 'localhost') {
                this.clearFieldError('mailbridge_smtp_host');
                return true;
            }

            if (host && !hostRegex.test(host) && !this.isValidIpAddress(host)) {
                this.showFieldError('mailbridge_smtp_host', 'Invalid host format. Please enter a valid domain or IP address.');
                return false;
            }
            
            this.clearFieldError('mailbridge_smtp_host');
            return true;
        }

        /**
         * Validate IPv4 and IPv6 host values, including private and reserved ranges.
         * @param {string} host Host value to validate
         * @return {boolean} Whether the host is a valid IP address
         */
        isValidIpAddress(host) {
            const ipv4Regex = /^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/;
            const ipv6Regex = /^((?:[a-fA-F0-9]{1,4}:){7}[a-fA-F0-9]{1,4}|(?:[a-fA-F0-9]{1,4}:){1,7}:|(?:[a-fA-F0-9]{1,4}:){1,6}:[a-fA-F0-9]{1,4}|(?:[a-fA-F0-9]{1,4}:){1,5}(?::[a-fA-F0-9]{1,4}){1,2}|(?:[a-fA-F0-9]{1,4}:){1,4}(?::[a-fA-F0-9]{1,4}){1,3}|(?:[a-fA-F0-9]{1,4}:){1,3}(?::[a-fA-F0-9]{1,4}){1,4}|(?:[a-fA-F0-9]{1,4}:){1,2}(?::[a-fA-F0-9]{1,4}){1,5}|[a-fA-F0-9]{1,4}:(?:(?::[a-fA-F0-9]{1,4}){1,6})|:(?:(?::[a-fA-F0-9]{1,4}){1,7}|:))$/;

            return ipv4Regex.test(host) || ipv6Regex.test(host);
        }
        
        /**
         * Validate port number
         * @return {boolean} Whether the port is valid
         */
        validatePort() {
            const port = parseInt($('#mailbridge_smtp_port').val(), 10);
            
            if (isNaN(port) || port < 1 || port > 65535) {
                this.showFieldError('mailbridge_smtp_port', 'Port must be between 1 and 65535.');
                return false;
            }
            
            this.clearFieldError('mailbridge_smtp_port');
            return true;
        }
        
        /**
         * Check encryption settings and warn about security
         */
        checkEncryption() {
            const encryption = $('#mailbridge_smtp_encryption').val();
            const existingWarning = $('.encryption-warning');
            
            if (encryption === 'none') {
                existingWarning.remove();
                const warning = $('<div class="security-notice encryption-warning"><p>' +
                    '<strong>⚠️ Security Warning:</strong> No encryption means your SMTP credentials ' +
                    'will be sent in plain text. This is not recommended for production use.</p></div>');
                $('#mailbridge_smtp_encryption').closest('td').append(warning);
            } else {
                existingWarning.remove();
            }
        }
        
        /**
         * Show error for a specific field
         * @param {string} fieldId - The ID of the field
         * @param {string} message - Error message to display
         */
        showFieldError(fieldId, message) {
            const $field = $('#' + fieldId);
            this.clearFieldError(fieldId);
            $field.addClass('error');
            $field.after($('<span class="mailbridge-smtp-error">').text(message).css({color: '#dc3232', fontSize: '12px'}));
        }
        
        /**
         * Clear error for a specific field
         * @param {string} fieldId - The ID of the field
         */
        clearFieldError(fieldId) {
            const $field = $('#' + fieldId);
            $field.removeClass('error');
            $field.siblings('.mailbridge-smtp-error').remove();
        }
        
        /**
         * Test SMTP connection via AJAX
         * @param {Event} e - Click event
         */
        testConnection(e) {
            e.preventDefault();
            
            // Validate before testing
            if (!this.validateHost() || !this.validatePort()) {
                return;
            }
            
            const $btn = $('#mailbridge_smtp_test_btn');
            const $result = $('#mailbridge_smtp_test_result');
            const testEmail = $('#mailbridge_smtp_test_email').val().trim();
            
            // Validate test email
            if (!this.isValidEmail(testEmail)) {
                this.showResult('error', 'Please enter a valid email address for testing.');
                return;
            }
            
            // Disable button during test
            $btn.prop('disabled', true);
            this.showResult('loading', mailbridgeSmtp.testing);
            
            // Make AJAX request
            $.ajax({
                url: mailbridgeSmtp.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mailbridge_smtp_test',
                    nonce: mailbridgeSmtp.nonce,
                    test_email: testEmail
                },
                success: (response) => {
                    if (response.success) {
                        this.showResult('success', mailbridgeSmtp.success + ' ' + response.data);
                    } else {
                        this.showResult('error', mailbridgeSmtp.error + ' ' + (response.data || ''));
                    }
                },
                error: (xhr, status, error) => {
                    this.showResult('error', mailbridgeSmtp.error + ' (' + error + ')');
                },
                complete: () => {
                    $btn.prop('disabled', false);
                }
            });
        }
        
        /**
         * Show result message
         * @param {string} type - Message type (success, error, loading)
         * @param {string} message - Message to display
         * @param {jQuery} $result - Optional result element (defaults to SMTP test result)
         */
        showResult(type, message, $result) {
            const $target = $result || $('#mailbridge_smtp_test_result');
            $target.removeClass('success error loading')
                   .addClass(type)
                   .text(message)
                   .show();

            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    $target.fadeOut();
                }, 5000);
            }
        }

        /**
         * Test mail diagnostic via AJAX
         * @param {Event} e - Click event
         * @param {string} testType - Test type (native_wp_mail or direct_mail)
         */
        testDiagnostic(e, testType) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const $result = $('#mailbridge_diagnostic_result');
            const testEmail = $('#mailbridge_diagnostic_email').val().trim();

            // Validate email
            if (!this.isValidEmail(testEmail)) {
                this.showResult('error', 'Please enter a valid email address.', $result);
                return;
            }

            // Disable button during test
            $btn.prop('disabled', true);
            this.showResult('loading', mailbridgeSmtpDiagnostic.testing, $result);

            // Make AJAX request
            $.ajax({
                url: mailbridgeSmtpDiagnostic.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mailbridge_smtp_diagnostic',
                    nonce: mailbridgeSmtpDiagnostic.nonce,
                    test_type: testType,
                    test_email: testEmail
                },
                success: (response) => {
                    if (response.success) {
                        this.showResult('success', response.data, $result);
                    } else {
                        this.showResult('error', response.data || mailbridgeSmtpDiagnostic.error, $result);
                    }
                },
                error: (xhr, status, error) => {
                    this.showResult('error', mailbridgeSmtpDiagnostic.error + ' (' + error + ')', $result);
                },
                complete: () => {
                    $btn.prop('disabled', false);
                }
            });
        }
        
        /**
         * Validate email format
         * @param {string} email - Email to validate
         * @return {boolean} Whether the email is valid
         */
        isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
    }
    
    // Initialize when DOM is ready
    $(document).ready(() => {
        new MailBridgeSmtpAdmin();
        
        // Initial checks
        $('#mailbridge_smtp_encryption').trigger('change');
    });
    
})(jQuery);
