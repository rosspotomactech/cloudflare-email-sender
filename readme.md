# Cloudflare Email Sender for WordPress

**Version:** 1.5.6

**Tested up to:** WordPress 7.0.1

**Author:** Potomac Technologies, LLC

## Overview

Cloudflare Email Sender is a lightweight WordPress plugin that overrides the native `wp_mail()` function to route all outbound website emails through the **Cloudflare Email Service REST API**.

This plugin is specifically designed for WordPress sites hosted on servers that lack outbound SMTP capabilities or local Mail Transfer Agents (MTAs). It ensures reliable delivery for system notices, password resets, and advanced form builder submissions (like Elementor, Gravity Forms, and Contact Form 7).

---

## Key Features

* **Direct REST API Integration:** Bypasses local server mail protocols completely, sending payloads securely via HTTPS to Cloudflare.
* **Smart Content Detection:** Automatically detects whether WordPress is sending Plain Text or HTML emails. It gracefully converts Plain Text line breaks (`\n`) to HTML (`<br>`) so emails always render perfectly in the recipient's inbox.
* **Advanced Header Parsing:** Intelligently extracts `Cc`, `Bcc`, and `Reply-To` parameters from form builders and maps them to Cloudflare's strict REST API schema. Unrecognized or restricted headers are safely discarded to prevent payload rejection.
* **Dynamic Reply-To Logic:** Automatically respects `Reply-To` addresses set by contact forms (so you can reply directly to visitors). Includes an optional setting to force a default "Reply-To" address across the entire site.
* **Debug Alerts & Loop Protection:** If the Cloudflare API rejects a payload (e.g., schema error), the plugin sends a diagnostic alert to a designated developer email. It utilizes a static recursion guard to guarantee the alert system never triggers an infinite loop.
* **Admin Error Notifications:** If a hard connection or authentication error occurs (like an invalid API key), a dismissible red banner appears in the WordPress admin dashboard.
* **Automated GitHub Updates:** Integrates with the Plugin Update Checker library to automatically pull new releases directly from GitHub.

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
* **API Token:** A secure token generated in Cloudflare with Email Sending privileges.
* **From Address:** The verified email address you are sending from (e.g., `noreply@yourdomain.com`).
* **From Name (Optional):** The display name for your emails (e.g., `My Awesome Website`).
* **Default Reply-To (Optional):** The fallback email address for replies if a form plugin doesn't specify one.
* **Debug Mode:** Enable this to receive diagnostic alerts if an email fails to send.

*Tip: Use the built-in "Send a Test Email" form at the bottom of the settings page to verify your API connection.*

---

## WP-CLI Support (Automated Deployments)

For server administrators and agencies, this plugin fully supports configuration via WP-CLI. You can easily script the deployment and setup across multiple environments using standard WordPress option updates:

```bash
# 1. Set Authentication
wp option update cf_email_account_id "your_cloudflare_account_id"
wp option update cf_email_api_token "your_cloudflare_api_token"

# 2. Set Sender Details
wp option update cf_email_from_address "noreply@yourdomain.com"
wp option update cf_email_from_name "Test Corp, Inc."

# 3. Configure Reply-To (Optional)
wp option update cf_email_reply_to "support@yourdomain.com"
wp option update cf_email_reply_to_override 1 # Set to 1 to force override, 0 to disable

# 4. Configure Debugging (Optional)
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
