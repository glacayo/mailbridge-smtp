<?php
/**
 * MailBridge SMTP - Uninstall
 *
 * @package MailBridge_SMTP
 */

// Security: Prevent direct access
defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove MailBridge SMTP plugin options.
delete_option('mailbridge_smtp_options');

// Remove rate-limit transients created by MailBridge SMTP.
global $wpdb;

$transient_prefixes = [
    '_transient_mailbridge_smtp_test_',
    '_transient_timeout_mailbridge_smtp_test_',
    '_transient_mailbridge_smtp_diag_',
    '_transient_timeout_mailbridge_smtp_diag_',
    '_transient_mailbridge_smtp_last_error',
    '_transient_timeout_mailbridge_smtp_last_error',
];

$like_clauses = [];
$like_values  = [];

foreach ($transient_prefixes as $prefix) {
    $like_clauses[] = 'option_name LIKE %s';
    $like_values[]  = $wpdb->esc_like($prefix) . '%';
}

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE " . implode(' OR ', $like_clauses),
        $like_values
    )
);
