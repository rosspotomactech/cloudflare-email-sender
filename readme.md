# Cloudflare Email Sender for WordPress

**Version:** 1.6

**Tested up to:** WordPress 7.0.1

**Author:** Potomac Technologies, LLC

## Overview

Cloudflare Email Sender is a lightweight WordPress plugin that overrides the native `wp_mail()` function to route all outbound website emails through the **Cloudflare Email Service REST API**.

This plugin is specifically designed for WordPress sites hosted on servers that lack outbound SMTP capabilities or local Mail Transfer Agents (MTAs). It ensures reliable delivery for system notices, password resets, and advanced form builder submissions (like Elementor, Gravity Forms, and Contact Form 7).

---

## Key Features

* **Direct REST API Integration:** Bypasses local server mail protocols completely, sending payloads securely via HTTPS to Cloudflare.
* **Smart Content Detection:** Automatically detects whether WordPress is sending Plain Text or HTML emails. It gracefully converts Plain Text line breaks (`\n`) to HTML (`<br>`) so emails always render perfectly in the recipient's inbox.
* **Outbound Email Copy (BCC/CC):** Copy one or more email addresses on email sent from the site. Ideal for client handoffs where the client is designated as the primary site administrator but developers or agencies still require critical site notifications. By default only email addressed to the site administrator address is copied, and email from authentication plugins and core account-recovery email is never copied (see Security considerations).
* **Advanced Header Parsing:** Intelligently extracts `Cc`, `Bcc`, and `Reply-To` parameters from form builders and maps them to Cloudflare's strict REST API schema. Unrecognized or restricted headers are safely discarded to prevent payload rejection.
* **Dynamic Reply-To Logic:** Automatically respects `Reply-To` addresses set by contact forms (so you can reply directly to visitors). Includes an optional setting to force a default "Reply-To" address across the entire site.
* **Debug Alerts & Loop Protection:** If the Cloudflare API rejects a payload (e.g., schema error), the plugin sends a diagnostic alert to a designated developer email. It utilizes a static recursion guard to guarantee the alert system never triggers an infinite loop.
* **Admin Error Notifications:** If a hard connection or authentication error occurs (like an invalid API key), a dismissible red banner appears in the WordPress admin dashboard.
* **Automated GitHub Updates:** Integrates with the Plugin Update Checker library to automatically pull new releases directly from GitHub.
* **Core Hook Compatibility:** Applies the standard `wp_mail` filter before sending and fires `wp_mail_failed` on any failure, so email logging, auditing and monitoring plugins continue to work.
* **Secure Token Storage:** The API token can be defined as a constant in `wp-config.php` rather than stored in the database, and the stored token is never echoed back into the settings form.

---

## Requirements

* WordPress 5.0 or higher.
* PHP 7.4 or higher.
* A Cloudflare account with the **Email Routing / Email Sending** feature enabled.
* A Cloudflare API Token with explicit permissions to send emails.
* A verified sending domain within Cloudflare.

---

## Installation

1. Download the latest `cloudflare-email-sender.zip` release from this repository.
2. Log in to your WordPress Admin dashboard.
3. Deactivate any existing SMTP or email routing plugins (e.g., WP Mail SMTP, Post SMTP) to prevent conflicts with `wp_mail()`.
4. Navigate to **Plugins** > **Add New** > **Upload Plugin**.
5. Upload the `.zip` file, install, and click **Activate**.

---

## Configuration

Navigate to **Settings** > **Cloudflare Email** in the WordPress admin dashboard to configure the plugin:

* **Cloudflare Account ID:** Found in the URL of your Cloudflare dashboard or on the domain overview page.
* **API Token:** A secure token generated in Cloudflare with Email Sending privileges. The saved token is never displayed; leave the field blank to keep the current token, or enter a new value to replace it. If the `CF_EMAIL_API_TOKEN` constant is defined in `wp-config.php`, it takes precedence and the field is disabled.
* **From Address:** The verified email address you are sending from (e.g., `noreply@yourdomain.com`).
* **From Name (Optional):** The display name for your emails (e.g., `My Awesome Website`).
* **Default Reply-To (Optional):** The fallback email address for replies if a form plugin doesn't specify one.
* **Force Reply-To Override:** When checked, always forces the Default Reply-To address, overriding form headers.
* **Copy Outbound Emails (Optional):** When enabled, copies outgoing email to one or more designated email addresses, subject to the scope and exclusions below. A security notice on the settings screen describes the implications.
* **Copy Scope:** **Administrator notifications only (Recommended)** copies email addressed to the site administrator address, i.e., update, Site Health, recovery-mode and plugin notices. **All outbound email** copies every message the site sends, including email to individual users and customers.
* **Excluded Sending Plugins:** Plugin directory names, one per line, whose email is never copied under either scope. Prefilled with common authentication and security plugins.
* **Copy Email Addresses:** Comma- or newline-separated list of email addresses to copy.
* **Copy Method:** Choose between **BCC (Blind Carbon Copy - Recommended)** to protect address privacy from recipients, or **CC (Carbon Copy)**.
* **Debug Mode:** Enable this to receive diagnostic alerts if an email fails to send.
* **Debug Email Address:** The email address where error alerts should be sent (defaults to site admin).

*Tip: Use the built-in "Send a Test Email" form at the bottom of the settings page to verify your API connection.*

---

## Security considerations

### API token storage

The recommended configuration for production sites is to define the token as a constant in `wp-config.php`:

```php
define( 'CF_EMAIL_API_TOKEN', 'your_cloudflare_api_token' );
```

When the constant is defined, the plugin ignores the `cf_email_api_token` option and the settings field is disabled. This keeps the token out of the database, out of database backups and out of the admin HTML. Create the Cloudflare API token with the minimum permission required (Email Sending) and scope it to the single account.

If the constant is not defined, the token is stored in the `wp_options` table. The settings form never outputs the stored value.

### Outbound email copy and authentication email

A copied email that contains a one-time login code, a device-verification link, an account-unlock link or a password reset link gives the copy mailbox the ability to complete a login on the site. The copy feature therefore applies three independent controls, and a message is copied only if it passes all of them.

**1. Copy scope.** The default scope, *Administrator notifications only*, copies email only when a recipient is the site administrator address (`admin_email`). This captures update, Site Health, recovery-mode and plugin notices, which is the purpose of the feature, and excludes email sent to individual users and customers, which is where authentication codes are delivered. The *All outbound email* scope copies every message and is intended only for cases with a specific requirement to receive form submissions or user-facing email at the copy address. Additional addresses can be treated as administrator addresses with the `cf_email_admin_addresses` filter.

**2. Excluded sending plugins.** At send time the plugin identifies which plugin directories are on the call stack for the `wp_mail()` call and skips the copy if any of them is in the excluded list. This does not depend on message wording, so it is reliable for the listed plugins regardless of language or customized templates. The list is prefilled with common authentication and security plugins (WP 2FA, Two-Factor, miniOrange, Google Authenticator, Wordfence, Patchstack, Solid Security, All-In-One Security, Shield, WP Cerber, Sucuri, Limit Login Attempts Reloaded, Loginizer) and is editable on the settings screen. Add the directory name of any plugin that sends codes or login links. The list can also be adjusted with the `cf_email_excluded_plugins` filter.

**3. Core account-recovery exclusion.** Regardless of scope, email containing the following WordPress core links is never copied, because the link itself grants access to the recipient's account:

* Password reset and new-user set-password emails (`wp-login.php?action=rp`).
* User email-change confirmations (`profile.php?newuseremail=`).
* Site administration email-change confirmations (`options.php?adminhash=`).
* Privacy export and erasure request confirmations (`action=confirmaction`).

These patterns are stable across WordPress versions. Additional body patterns can be added with `cf_email_sensitive_patterns`, and a per-message decision can be made with `cf_email_skip_copy` or, after all controls have run, `cf_email_should_copy`:

```php
// Add a body pattern to the core exclusion list.
add_filter( 'cf_email_sensitive_patterns', function ( $patterns ) {
	$patterns[] = 'action=magic_login';
	return $patterns;
} );

// Treat a second address as an administrator address for the copy scope.
add_filter( 'cf_email_admin_addresses', function ( $addresses ) {
	$addresses[] = 'alerts@example.com';
	return $addresses;
} );

// Final decision, with the reason the plugin reached it.
add_filter( 'cf_email_should_copy', function ( $copy, $context ) {
	return $copy && false === stripos( $context['subject'], 'verification code' );
}, 10, 2 );
```

**Residual risk.** Under the *All outbound email* scope, authentication email from a plugin that is not in the excluded list is copied. Review the installed plugins when enabling that scope. Copied email may also contain personal data from form submissions and e-commerce notifications, so configure copy addresses only for mailboxes protected to the same standard as an administrator account, and prefer BCC.

### Automatic updates

Updates are downloaded from the public GitHub repository over HTTPS. Anyone with write access to that repository can publish code to every site running the plugin. The repository is protected accordingly (two-factor authentication, protected `main` branch and release tags, and a release ZIP built by GitHub Actions rather than uploaded by hand).

### Attachments

The Cloudflare Email Service API does not accept attachments. Any attachments passed to `wp_mail()` are discarded and a notice is written to the PHP error log. The message itself is still delivered.

---

## WP-CLI Support (Automated Deployments)

For server administrators and agencies, this plugin fully supports configuration via WP-CLI. You can easily script the deployment and setup across multiple environments using standard WordPress option updates:

```bash
# 1. Set Authentication
wp option update cf_email_account_id "your_cloudflare_account_id"
# Preferred: define CF_EMAIL_API_TOKEN in wp-config.php (see Security considerations).
# Alternative: store the token in the database.
wp option update cf_email_api_token "your_cloudflare_api_token"

# 2. Set Sender Details
wp option update cf_email_from_address "noreply@yourdomain.com"
wp option update cf_email_from_name "Test Corp, Inc."

# 3. Configure Reply-To (Optional)
wp option update cf_email_reply_to "support@yourdomain.com"
wp option update cf_email_reply_to_override 1 # Set to 1 to force override, 0 to disable

# 4. Configure Outbound Email Copy (Optional)
wp option update cf_email_copy_enabled 1 # Set to 1 to enable, 0 to disable
wp option update cf_email_copy_addresses "dev@potomactech.net, alerts@potomactech.net"
wp option update cf_email_copy_method "bcc" # Set to "bcc" (default) or "cc"
wp option update cf_email_copy_scope "admin" # "admin" (default: administrator notifications only) or "all"
# Excluded sending plugins: newline-separated directory names. Omit to keep the built-in defaults.
wp option update cf_email_copy_exclude_plugins $'wp-2fa\ntwo-factor\nwordfence\npatchstack'

# 5. Configure Debugging (Optional)
wp option update cf_email_debug_mode 1 # Set to 1 to enable, 0 to disable
wp option update cf_email_debug_email "tools@potomactech.net"
```

### Forcing Plugin Updates via WP-CLI

To force the plugin to check GitHub for an update and install it immediately:

```bash
wp transient delete update_plugins && wp plugin update cloudflare-email-sender

```

---

## Troubleshooting & Error Codes

If an email fails to send, check the WordPress `debug.log` or the diagnostic alert sent to your Debug Email address. Common Cloudflare REST API error codes include:

* **401 / 403:** Authentication error. Double-check your API Token and ensure it has Email Sending permissions.
* **10202:** Invalid Email Content. Usually caused if a form plugin tries to force an unsupported custom header or if the combined CC/BCC recipients exceed 50.
* **10001:** Invalid Request Schema. Check that the From Address and To Address are valid, properly formatted email strings.

---

## Changelog

### 1.6
* New: outbound email copy (BCC or CC) to one or more addresses, for agencies and developers that need site notifications after handoff.
* Copy scope defaults to email addressed to the site administrator, with an optional all-email scope.
* Email sent by authentication and security plugins is excluded from copying by plugin directory name, with a prefilled, editable list.
* Core account-recovery and confirmation emails are excluded from copying under either scope.
* A security notice on the settings screen describes the implications of the copy feature.
* The API token can be defined as the `CF_EMAIL_API_TOKEN` constant in `wp-config.php`; the stored token is no longer echoed into the settings form.
* The standard `wp_mail` filter is applied before sending and `wp_mail_failed` fires on failure.
* The error-notice dismiss link is nonce-protected.
* Angle brackets are stripped from the From Name.
* Attachments passed to `wp_mail()` are logged as discarded.
* New filters: `cf_email_admin_addresses`, `cf_email_excluded_plugins`, `cf_email_sensitive_patterns`, `cf_email_skip_copy`, `cf_email_should_copy`.
* Source reformatted to WordPress coding standards.

### 1.5.7
* Previous release.
