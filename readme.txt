=== MailBridge SMTP ===
Contributors: glacayo
Tags: smtp, email, mail, wp-mail, email-delivery
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Route WordPress email through your SMTP provider with encrypted password storage and built-in delivery testing.

== Description ==

MailBridge SMTP helps WordPress send email through your chosen SMTP provider instead of relying on the default server mail configuration.

It applies to WordPress core email, store notifications, membership emails, form submissions, and any plugin or theme that sends messages through `wp_mail()`.

Features include:

* Configure SMTP host, port, encryption, authentication, sender email, and sender name.
* Send a test email from the WordPress admin before relying on live delivery.
* Run mail diagnostics from the same settings screen.
* Store SMTP passwords encrypted using keys derived from WordPress salts.
* Restrict settings and test actions to administrators with the required capability.
* Protect admin actions with nonce verification and short rate limits for test requests.

MailBridge SMTP is intentionally focused on general WordPress SMTP delivery. It does not require plugin-specific setup when other plugins use the standard WordPress mail flow.

== Installation ==

1. Upload the `mailbridge-smtp` folder to the `/wp-content/plugins/` directory.
2. Activate **MailBridge SMTP** through the **Plugins** screen in WordPress.
3. Go to **Tools > MailBridge SMTP**.
4. Enter your SMTP provider settings.
5. Save the settings.
6. Send a test email to confirm delivery.

== Frequently Asked Questions ==

= Which emails does MailBridge SMTP handle? =

MailBridge SMTP handles email sent through WordPress's `wp_mail()` function. This includes WordPress core email and most plugin or theme-generated email.

= Do I need separate configuration for form plugins? =

No. If the form plugin sends email through `wp_mail()`, MailBridge SMTP applies automatically.

= Is my SMTP password stored securely? =

SMTP passwords are encrypted before storage using keys derived from WordPress salts. The password field is not intentionally displayed in plain text after saving.

= Who can change SMTP settings? =

Only users with the required WordPress administrator capability can access and change the settings.

= Are test email actions protected? =

Yes. Admin test actions use nonce verification and short rate limits to reduce accidental or repeated requests.

= Which SMTP providers are supported? =

MailBridge SMTP works with standard SMTP providers that support common host, port, encryption, and authentication settings.

== Screenshots ==

1. SMTP settings screen under Tools.
2. Test email and diagnostics tools.
3. Saved SMTP configuration with encrypted password storage.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added general WordPress SMTP configuration for `wp_mail()` delivery.
* Added encrypted SMTP password storage.
* Added admin-only settings, nonce-protected test actions, and request rate limiting.
* Added test email and diagnostic tools.

== Upgrade Notice ==

= 1.0.0 =
Initial release of MailBridge SMTP.
