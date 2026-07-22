# MailBridge SMTP

MailBridge SMTP routes WordPress email through your SMTP provider. It applies to WordPress core mail, form plugins, ecommerce notifications, and any plugin that sends through `wp_mail()`.

## Features

- **General WordPress SMTP**: Configure SMTP delivery for all WordPress emails.
- **Form plugin friendly**: Works with form plugins that use `wp_mail()`.
- **Security first**: Encrypted password storage, input sanitization, nonce verification, and capability checks.
- **Easy setup**: Settings live under **Tools → MailBridge SMTP**.
- **Built-in testing**: Send SMTP test emails and run mail diagnostics from the admin screen.
- **Multiple encryption options**: Supports no encryption, SSL, and TLS.

## Installation

1. Upload the `mailbridge-smtp` folder to `/wp-content/plugins/`.
2. Activate **MailBridge SMTP** from the WordPress Plugins screen.
3. Go to **Tools → MailBridge SMTP**.
4. Enter your SMTP provider settings and save.
5. Use **Send Test Email** to verify delivery.

## Configuration

| Setting | Description | Example |
|---------|-------------|---------|
| **Enable SMTP** | Turn on SMTP for WordPress emails | ☑️ |
| **SMTP Host** | Your SMTP server address | `smtp.gmail.com` |
| **SMTP Port** | Server port | `587` |
| **Encryption** | Connection security | `TLS` |
| **Authentication** | Enable SMTP login | ☑️ |
| **Username** | SMTP account username | `user@example.com` |
| **Password** | SMTP account password or app password | •••••••• |
| **From Email** | Sender email address | `noreply@example.com` |
| **From Name** | Sender display name | `My Website` |
| **Debug Mode** | Log SMTP debug output when WordPress debug logging is enabled | ☐ |

## Common SMTP Providers

### Gmail

```text
Host: smtp.gmail.com
Port: 587
Encryption: TLS
Authentication: Yes
```

Gmail accounts with 2FA usually require an App Password.

### Outlook / Microsoft 365

```text
Host: smtp.office365.com
Port: 587
Encryption: TLS
Authentication: Yes
```

### SendGrid

```text
Host: smtp.sendgrid.net
Port: 587
Encryption: TLS
Authentication: Yes
Username: apikey
Password: your_sendgrid_api_key
```

## How it works with WordPress email

MailBridge SMTP hooks into WordPress's `wp_mail()` flow. WordPress core, ecommerce extensions, form plugins, and many other tools use that same mail function, so no plugin-specific configuration is required.

## Testing

### Admin panel testing

1. Go to **Tools → MailBridge SMTP**.
2. Enter an email address in **Test Email Address**.
3. Click **Send Test Email**.
4. Check the recipient inbox.

### CLI SMTP connection tests

The `tests/` directory contains a standalone CLI test suite that validates SMTP connections without loading WordPress. It is useful for diagnosing provider or network issues before configuring the plugin.

```bash
# Copy the local test env template and fill in credentials
cp .env.example .env

# Test all configured servers without sending email
php tests/test-smtp-connection.php

# Show SMTP protocol output
php tests/test-smtp-connection.php --verbose

# Test one configured server
php tests/test-smtp-connection.php --server=1

# Send a test email using TEST_RECIPIENT from .env
php tests/test-smtp-connection.php --send-email
```

Test results are saved as JSON files in `tests/results/`.

#### What Each Test Checks

| Step | Description |
|------|-------------|
| DNS Resolve | Resolves SMTP hostname to IP |
| TCP Connect | Opens socket to host:port |
| SMTP Banner | Reads server greeting (220) |
| EHLO | Sends EHLO, checks 250 response |
| AUTH Support | Verifies AUTH methods advertised |
| STARTTLS | TLS handshake (if encryption=tls) |
| AUTH LOGIN | Authenticates with credentials |
| Test Email | Sends email via SMTP protocol (optional) |

#### Test Results

Results are saved as JSON in `tests/results/` with timestamps. Minimal example:

```json
{
  "timestamp": "2026-04-06 19:30:00",
  "servers": {
    "1": {
      "host": "smtp.gmail.com",
      "success": true,
      "steps": {
        "dns": { "success": true, "detail": "Resolved to 142.250.x.x" },
        "connect": { "success": true },
        "auth_login": { "success": true }
      }
    }
  }
}
```

## Security notes

- SMTP passwords are encrypted using AES-256-GCM with keys derived from WordPress salts.
- Admin actions require the `manage_options` capability.
- AJAX requests use nonce verification.
- `.env` is only for local CLI tests and should never be committed.
- Release ZIP packages should exclude local-only files such as `tests/`, `.env`, and `.env.*`; keep `.distignore` aligned with release tooling.
- Disable debug mode in production unless actively troubleshooting.

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

## Frequently Asked Questions

### Which WordPress emails does this affect?

MailBridge SMTP is a general WordPress SMTP plugin. It works with any email sent through `wp_mail()`, including WordPress core emails, ecommerce notifications, and form plugin submissions.

### Is my password secure?

Passwords are encrypted before storage and are never intentionally displayed in plain text.

### Can I use this on multisite?

MailBridge SMTP is currently designed for single-site installations. Multisite support may be added in a future release.

## Support

For issues, feature requests, or contributions, use https://github.com/glacayo/mailbridge-smtp.

## License

This plugin is licensed under the GPL-2.0-or-later license.

---

**Security note**: Use strong SMTP credentials and enable SSL/TLS encryption whenever possible.
