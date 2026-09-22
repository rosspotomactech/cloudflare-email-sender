<?php
/**
 * Plugin Name: Cloudflare Email Sender
 * Description: Routes WordPress emails through the Cloudflare Email Service REST API.
 * Version: 1.6
 * Tested up to: 7.0.2
 * Requires PHP: 7.4
 * Author: Potomac Technologies, LLC
 * Author URI:  https://potomactech.net
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Require the library to allow for automatic updates
	require 'plugin-update-checker/plugin-update-checker.php';
	use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

	// Initialize the update checker
	$myUpdateChecker = PucFactory::buildUpdateChecker(
		'https://github.com/rosspotomactech/cloudflare-email-sender',
		__FILE__,
		'cloudflare-email-sender'
	);

	// Install updates from the release ZIP built by the GitHub Action
	$myUpdateChecker->getVcsApi()->enableReleaseAssets();

	// Helper Function: Extracts pure email address from "Name <email@domain.com>" formats
	if ( ! function_exists( 'cf_email_extract_pure_address' ) ) {
		function cf_email_extract_pure_address( $string ) {
			if ( preg_match( '/<([^>]+)>/', $string, $matches ) ) {
				return sanitize_email( trim( $matches[1] ) );
			}
			return sanitize_email( trim( $string ) );
		}
	}

	// Helper Function: Parses and extracts pure email addresses from comma- or newline-separated strings
	if ( ! function_exists( 'cf_email_parse_address_list' ) ) {
		function cf_email_parse_address_list( $string ) {
			if ( empty( $string ) || ! is_string( $string ) ) {
				return array();
			}
			$raw   = preg_split( '/[\r\n,;]+/', $string );
			$clean = array();
			foreach ( $raw as $entry ) {
				$entry = trim( $entry );
				if ( '' === $entry ) {
					continue;
				}
				$pure = cf_email_extract_pure_address( $entry );
				if ( is_email( $pure ) ) {
					$clean[] = $pure;
				}
			}
			return array_values( array_unique( $clean ) );
		}
	}

	// Sanitization callback for copy addresses
	if ( ! function_exists( 'cf_email_sanitize_copy_addresses' ) ) {
		function cf_email_sanitize_copy_addresses( $input ) {
			if ( empty( $input ) || ! is_string( $input ) ) {
				return '';
			}
			$raw            = preg_split( '/[\r\n,;]+/', $input );
			$valid_emails   = array();
			$invalid_emails = array();
			foreach ( $raw as $entry ) {
				$entry = trim( $entry );
				if ( '' === $entry ) {
					continue;
				}
				$pure = cf_email_extract_pure_address( $entry );
				if ( is_email( $pure ) ) {
					$valid_emails[] = $pure;
				} else {
					$invalid_emails[] = sanitize_text_field( $entry );
				}
			}
			if ( ! empty( $invalid_emails ) ) {
				add_settings_error(
					'cf_email_messages',
					'cf_email_invalid_copy_emails',
					'Some copy email addresses were invalid and omitted: ' . esc_html( implode( ', ', $invalid_emails ) ),
					'error'
				);
			}
			return implode( "\n", array_unique( $valid_emails ) );
		}
	}

	// Helper Function: Returns the API token, preferring a wp-config.php constant over the stored option
	if ( ! function_exists( 'cf_email_get_api_token' ) ) {
		function cf_email_get_api_token() {
			if ( cf_email_token_is_constant() ) {
				return (string) CF_EMAIL_API_TOKEN;
			}
			return (string) get_option( 'cf_email_api_token', '' );
		}
	}

	// Helper Function: True when the token is supplied by the CF_EMAIL_API_TOKEN constant
	if ( ! function_exists( 'cf_email_token_is_constant' ) ) {
		function cf_email_token_is_constant() {
			return defined( 'CF_EMAIL_API_TOKEN' ) && '' !== CF_EMAIL_API_TOKEN;
		}
	}

	// Sanitization callback for the API token. An empty submission keeps the existing token,
	// so the stored value never has to be echoed back into the settings form.
	if ( ! function_exists( 'cf_email_sanitize_api_token' ) ) {
		function cf_email_sanitize_api_token( $input ) {
			$input = sanitize_text_field( (string) $input );
			if ( '' === $input ) {
				return (string) get_option( 'cf_email_api_token', '' );
			}
			return $input;
		}
	}

	// Helper Function: Detects messages that carry account-recovery or confirmation links.
	// These are never copied to the configured copy addresses, because the links grant
	// access to the recipient's account.
	if ( ! function_exists( 'cf_email_is_sensitive_message' ) ) {
		function cf_email_is_sensitive_message( $to, $subject, $message ) {
			$patterns  = apply_filters(
				'cf_email_sensitive_patterns',
				array(
					'wp-login.php?action=rp',       // Password reset and new-user set-password links
					'action=confirmaction',         // Privacy (export/erasure) request confirmations
					'profile.php?newuseremail=',    // User email-change confirmation
					'options.php?adminhash=',       // Site admin email-change confirmation
					'options-general.php?adminhash=',
				)
			);
			$sensitive = false;
			foreach ( (array) $patterns as $pattern ) {
				if ( '' !== $pattern && false !== stripos( $message, $pattern ) ) {
					$sensitive = true;
					break;
				}
			}
			return (bool) apply_filters( 'cf_email_skip_copy', $sensitive, $to, $subject, $message );
		}
	}

	// Sanitization callback for copy scope
	if ( ! function_exists( 'cf_email_sanitize_copy_scope' ) ) {
		function cf_email_sanitize_copy_scope( $scope ) {
			$scope = strtolower( trim( (string) $scope ) );
			return in_array( $scope, array( 'admin', 'all' ), true ) ? $scope : 'admin';
		}
	}

	// Plugin directory names whose email is never copied. These plugins send one-time
	// login codes, device-verification links or account-unlock links.
	if ( ! function_exists( 'cf_email_default_excluded_plugins' ) ) {
		function cf_email_default_excluded_plugins() {
			return array(
				'wp-2fa',
				'two-factor',
				'two-factor-authentication',
				'miniorange-2-factor-authentication',
				'google-authenticator',
				'wordfence',
				'wordfence-login-security',
				'patchstack',
				'better-wp-security',
				'ithemes-security-pro',
				'all-in-one-wp-security-and-firewall',
				'wp-simple-firewall',
				'wp-cerber',
				'sucuri-scanner',
				'limit-login-attempts-reloaded',
				'loginizer',
			);
		}
	}

	// Sanitization callback for the excluded-plugins list (one directory name per line)
	if ( ! function_exists( 'cf_email_sanitize_excluded_plugins' ) ) {
		function cf_email_sanitize_excluded_plugins( $input ) {
			if ( ! is_string( $input ) ) {
				return '';
			}
			$slugs = array();
			foreach ( preg_split( '/[\r\n,;\s]+/', strtolower( $input ) ) as $slug ) {
				$slug = trim( $slug, " \t/" );
				if ( '' !== $slug && preg_match( '/^[a-z0-9._-]+$/', $slug ) ) {
					$slugs[] = $slug;
				}
			}
			return implode( "\n", array_values( array_unique( $slugs ) ) );
		}
	}

	// Returns the effective excluded-plugins list. The defaults apply until the setting is saved.
	if ( ! function_exists( 'cf_email_get_excluded_plugins' ) ) {
		function cf_email_get_excluded_plugins() {
			$stored = get_option( 'cf_email_copy_exclude_plugins', false );
			if ( false === $stored ) {
				$list = cf_email_default_excluded_plugins();
			} else {
				$list = array_filter( array_map( 'trim', explode( "\n", (string) $stored ) ) );
			}
			return array_values( array_unique( (array) apply_filters( 'cf_email_excluded_plugins', $list ) ) );
		}
	}

	// Returns the directory names of the plugins and mu-plugins on the current call stack,
	// i.e., the plugin(s) responsible for the wp_mail() call being processed.
	if ( ! function_exists( 'cf_email_sending_plugin_slugs' ) ) {
		function cf_email_sending_plugin_slugs() {
			$roots = array();
			if ( defined( 'WP_PLUGIN_DIR' ) ) {
				$roots[] = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
			}
			if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
				$roots[] = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
			}
			$slugs = array();
		  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- intentional: identifies the plugin that called wp_mail().
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 80 ) as $frame ) {
				if ( empty( $frame['file'] ) ) {
					continue;
				}
				$file = wp_normalize_path( $frame['file'] );
				foreach ( $roots as $root ) {
					if ( 0 === strpos( $file, $root ) ) {
						$segment = strtolower( strtok( substr( $file, strlen( $root ) ), '/' ) );
						$segment = preg_replace( '/\.php$/', '', $segment ); // single-file mu-plugins
						if ( '' !== $segment ) {
							$slugs[ $segment ] = true;
						}
					}
				}
			}
			return array_keys( $slugs );
		}
	}

	// Decides whether the configured copy addresses receive a copy of this message.
	if ( ! function_exists( 'cf_email_should_copy' ) ) {
		function cf_email_should_copy( $final_to, $to, $subject, $message ) {
			$copy   = false;
			$reason = '';

			if ( 1 !== (int) get_option( 'cf_email_copy_enabled', 0 ) ) {
				$reason = 'disabled';
			} elseif ( empty( cf_email_parse_address_list( get_option( 'cf_email_copy_addresses', '' ) ) ) ) {
				$reason = 'no_copy_addresses';
			} else {
				$copy  = true;
				$scope = cf_email_sanitize_copy_scope( get_option( 'cf_email_copy_scope', 'admin' ) );

				if ( 'admin' === $scope ) {
					$admin_addresses = array_map( 'strtolower', array_filter( (array) apply_filters( 'cf_email_admin_addresses', array( get_option( 'admin_email' ) ) ) ) );
					$recipients      = array_map( 'strtolower', (array) $final_to );
					if ( empty( array_intersect( $recipients, $admin_addresses ) ) ) {
						$copy   = false;
						$reason = 'not_admin_notification';
					}
				}

				if ( $copy && cf_email_is_sensitive_message( $to, $subject, $message ) ) {
					$copy   = false;
					$reason = 'sensitive_message';
				}

				if ( $copy ) {
					$excluded = cf_email_get_excluded_plugins();
					if ( ! empty( $excluded ) && array_intersect( cf_email_sending_plugin_slugs(), $excluded ) ) {
						$copy   = false;
						$reason = 'excluded_plugin';
					}
				}
			}

			return (bool) apply_filters(
				'cf_email_should_copy',
				$copy,
				array(
					'reason'  => $reason,
					'to'      => $to,
					'subject' => $subject,
					'message' => $message,
				)
			);
		}
	}

	// Sanitization callback for copy method
	if ( ! function_exists( 'cf_email_sanitize_copy_method' ) ) {
		function cf_email_sanitize_copy_method( $method ) {
			$method = strtolower( trim( (string) $method ) );
			return in_array( $method, array( 'bcc', 'cc' ), true ) ? $method : 'bcc';
		}
	}

	// 1. Register Settings Page & Handle Test Email
	add_action( 'admin_menu', 'cf_email_add_admin_menu' );
	function cf_email_add_admin_menu() {
		add_options_page( 'Cloudflare Email', 'Cloudflare Email', 'manage_options', 'cloudflare-email-sender', 'cf_email_settings_page' );
	}

	add_action( 'admin_init', 'cf_email_settings_init' );
	function cf_email_settings_init() {
		register_setting( 'cf_email_plugin_page', 'cf_email_account_id', 'sanitize_text_field' );
		register_setting( 'cf_email_plugin_page', 'cf_email_api_token', 'cf_email_sanitize_api_token' );
		register_setting( 'cf_email_plugin_page', 'cf_email_from_address', 'sanitize_email' );
		register_setting( 'cf_email_plugin_page', 'cf_email_from_name', 'sanitize_text_field' );
		register_setting( 'cf_email_plugin_page', 'cf_email_reply_to', 'sanitize_email' );
		register_setting( 'cf_email_plugin_page', 'cf_email_reply_to_override', 'absint' );
		register_setting( 'cf_email_plugin_page', 'cf_email_copy_enabled', 'absint' );
		register_setting( 'cf_email_plugin_page', 'cf_email_copy_addresses', 'cf_email_sanitize_copy_addresses' );
		register_setting( 'cf_email_plugin_page', 'cf_email_copy_method', 'cf_email_sanitize_copy_method' );
		register_setting( 'cf_email_plugin_page', 'cf_email_copy_scope', 'cf_email_sanitize_copy_scope' );
		register_setting( 'cf_email_plugin_page', 'cf_email_copy_exclude_plugins', 'cf_email_sanitize_excluded_plugins' );
		register_setting( 'cf_email_plugin_page', 'cf_email_debug_mode', 'absint' );
		register_setting( 'cf_email_plugin_page', 'cf_email_debug_email', 'sanitize_email' );

		if ( isset( $_POST['cf_email_send_test'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'cf_email_test_action', 'cf_email_test_nonce' );

			$to_email = sanitize_email( $_POST['cf_email_test_to'] );

			if ( is_email( $to_email ) ) {
				$subject = 'Test Email: Cloudflare Email Sender';
				$message = '<h1>Success!</h1><p>If you are reading this, your Cloudflare Email Service REST API configuration is working correctly in WordPress.</p>';

				$sent = wp_mail( $to_email, $subject, $message );

				if ( $sent ) {
					$success_msg = 'Test email sent successfully to ' . esc_html( $to_email );
					if ( cf_email_should_copy( array( $to_email ), $to_email, $subject, $message ) ) {
						$success_msg .= ' (with copies sent to the configured copy address(es))';
					}
					add_settings_error( 'cf_email_messages', 'cf_email_message', $success_msg, 'updated' );
				} else {
					add_settings_error( 'cf_email_messages', 'cf_email_message', 'Failed to send test email. Please check your configuration and server error logs.', 'error' );
				}
			} else {
				add_settings_error( 'cf_email_messages', 'cf_email_message', 'Invalid email address provided for the test.', 'error' );
			}
		}
	}

	// Dismiss handler for the error notice (runs before any output so the redirect succeeds)
	add_action( 'admin_init', 'cf_email_handle_dismiss_error' );
	function cf_email_handle_dismiss_error() {
		if ( ! isset( $_GET['cf_email_dismiss_error'] ) || '1' !== $_GET['cf_email_dismiss_error'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'cf_email_dismiss_error' );
		delete_option( 'cf_email_last_error' );
		wp_safe_redirect( remove_query_arg( array( 'cf_email_dismiss_error', '_wpnonce' ) ) );
		exit;
	}

	// Admin Notice for Configuration/Connection Failures
	add_action( 'admin_notices', 'cf_email_admin_notices' );
	function cf_email_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = get_option( 'cf_email_last_error' );
		if ( $error ) {
			$dismiss_url = wp_nonce_url( add_query_arg( 'cf_email_dismiss_error', '1' ), 'cf_email_dismiss_error' );
			echo '<div class="notice notice-error"><p><strong>Cloudflare Email Sender Error:</strong> ' . esc_html( $error ) . ' <a href="' . esc_url( $dismiss_url ) . '" style="float:right; text-decoration:none;">Dismiss &times;</a></p></div>';
		}
	}

	// Debug Alert Mailer
	function cf_email_send_debug_alert( $error_details, $original_subject, $original_to ) {
		static $is_sending_alert = false;
		if ( $is_sending_alert ) {
			return;
		}

		$debug_mode = get_option( 'cf_email_debug_mode', 1 );
		if ( ! $debug_mode ) {
			return;
		}

		$debug_email = get_option( 'cf_email_debug_email', get_option( 'admin_email' ) );
		if ( ! is_email( $debug_email ) ) {
			return;
		}

		$site_url      = get_site_url();
		$alert_subject = "CF Email Error: {$site_url}";

		$orig_to_str = is_array( $original_to ) ? implode( ', ', $original_to ) : $original_to;

		$alert_body  = "An email failed to send via the Cloudflare Email REST API.\n\n";
		$alert_body .= "Site: {$site_url}\n";
		$alert_body .= "Original To: {$orig_to_str}\n";
		$alert_body .= "Original Subject: {$original_subject}\n\n";
		$alert_body .= "Error Details:\n{$error_details}\n\n";
		$alert_body .= 'Please check the WordPress error logs for more information.';

		$is_sending_alert = true;
		wp_mail( $debug_email, $alert_subject, $alert_body );
		$is_sending_alert = false;
	}

	function cf_email_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$debug_mode     = get_option( 'cf_email_debug_mode', 1 );
		$debug_email    = get_option( 'cf_email_debug_email', get_option( 'admin_email' ) );
		$copy_enabled   = get_option( 'cf_email_copy_enabled', 0 );
		$copy_addresses = get_option( 'cf_email_copy_addresses', '' );
		$copy_method    = get_option( 'cf_email_copy_method', 'bcc' );
		$copy_scope     = cf_email_sanitize_copy_scope( get_option( 'cf_email_copy_scope', 'admin' ) );
		$excluded_raw   = get_option( 'cf_email_copy_exclude_plugins', false );
		$excluded_list  = ( false === $excluded_raw ) ? implode( "\n", cf_email_default_excluded_plugins() ) : (string) $excluded_raw;
		$admin_email    = get_option( 'admin_email' );
		?>
		<div class="wrap">
			<h2>Cloudflare Email Settings</h2>
  
			<form action="options.php" method="post">
				<?php
				settings_fields( 'cf_email_plugin_page' );
				do_settings_sections( 'cf_email_plugin_page' );
				?>
				<table class="form-table">
					<tr>
						<th>Cloudflare Account ID</th>
						<td><input type="text" name="cf_email_account_id" value="<?php echo esc_attr( get_option( 'cf_email_account_id' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>API Token</th>
						<td>
							<?php if ( cf_email_token_is_constant() ) : ?>
								<input type="password" value="" class="regular-text" disabled placeholder="Defined in wp-config.php" />
								<p class="description">The API token is set by the <code>CF_EMAIL_API_TOKEN</code> constant in <code>wp-config.php</code> and cannot be changed here.</p>
							<?php else : ?>
								<input type="password" name="cf_email_api_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'cf_email_api_token' ) ? 'Token is set. Enter a new value to replace it.' : 'Enter your Cloudflare API token'; ?>" />
								<p class="description">The stored token is never displayed. Leave this field blank to keep the current token. For production sites, define <code>CF_EMAIL_API_TOKEN</code> in <code>wp-config.php</code> instead of storing the token in the database.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>From Address</th>
						<td><input type="email" name="cf_email_from_address" value="<?php echo esc_attr( get_option( 'cf_email_from_address' ) ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th>From Name (Optional)</th>
						<td>
							<input type="text" name="cf_email_from_name" value="<?php echo esc_attr( get_option( 'cf_email_from_name' ) ); ?>" class="regular-text" />
							<p class="description">If provided, this will force the sender name for all outgoing emails (e.g., "My Website").</p>
						</td>
					</tr>
					<tr>
						<th>Default Reply-To (Optional)</th>
						<td>
							<input type="email" name="cf_email_reply_to" value="<?php echo esc_attr( get_option( 'cf_email_reply_to' ) ); ?>" class="regular-text" />
							<p class="description">Email address to receive replies if no other Reply-To is specified by WordPress or a contact form.</p>
						</td>
					</tr>
					<tr>
						<th>Force Reply-To Override</th>
						<td>
							<label>
								<input type="checkbox" name="cf_email_reply_to_override" value="1" <?php checked( 1, get_option( 'cf_email_reply_to_override' ), true ); ?> />
								Always use the Default Reply-To address above, overwriting any Reply-To addresses set by plugins (like contact forms).
							</label>
						</td>
					</tr>
					<tr>
						<th colspan="2"><hr></th>
					</tr>
					<tr>
						<th>Copy Outbound Emails</th>
						<td>
							<label>
								<input type="checkbox" name="cf_email_copy_enabled" value="1" <?php checked( 1, $copy_enabled, true ); ?> />
								Send a copy of outbound site emails to the address(es) below.
							</label>
							<div class="notice notice-warning inline" style="margin:12px 0 0; padding:8px 12px;">
								<p><strong>Security notice.</strong> Copied email may contain confidential information, including contact form submissions and, when the scope is set to all outbound email, one-time login codes, device-verification links and account-unlock links generated by authentication and security plugins. Any mailbox that receives copies can be used to complete a login on this site.</p>
								<p>Configure copies only to mailboxes protected to the same standard as an administrator account. Use the administrator-notifications scope unless there is a specific requirement for all email, and keep every plugin that sends login codes or links listed in the excluded sending plugins field. Core password reset, set-password, email-change and privacy-request emails are never copied under either scope.</p>
							</div>
						</td>
					</tr>
					<tr>
						<th>Copy Scope</th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="cf_email_copy_scope" value="admin" <?php checked( 'admin', $copy_scope ); ?> />
									<strong>Administrator notifications only (Recommended)</strong>
								</label>
								<p class="description">Copies only email addressed to the site administrator address (<?php echo esc_html( $admin_email ); ?>): update, Site Health, recovery-mode and plugin notices. Email addressed to individual users and customers is not copied.</p>
								<br />
								<label>
									<input type="radio" name="cf_email_copy_scope" value="all" <?php checked( 'all', $copy_scope ); ?> />
									<strong>All outbound email</strong>
								</label>
								<p class="description">Copies every email the site sends, subject to the exclusions below. Select this scope only when the copy mailbox is required to receive form submissions or user-facing email.</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th>Excluded Sending Plugins</th>
						<td>
							<textarea name="cf_email_copy_exclude_plugins" rows="6" cols="50" class="large-text code"><?php echo esc_textarea( $excluded_list ); ?></textarea>
							<p class="description">One plugin directory name per line, e.g., <code>wp-2fa</code>. Email sent by these plugins is never copied under either scope. The list is prefilled with common authentication and security plugins. Add any plugin that sends one-time codes, login links or unlock links. Clear the field to disable this exclusion.</p>
						</td>
					</tr>
					<tr>
						<th>Copy Email Addresses</th>
						<td>
							<textarea name="cf_email_copy_addresses" rows="3" cols="50" class="large-text" placeholder="admin@example.com&#10;developer@example.com"><?php echo esc_textarea( $copy_addresses ); ?></textarea>
							<p class="description">Enter one or more email addresses separated by commas or new lines. Useful when a client is the primary site administrator but developers or agencies also need to receive site notifications.</p>
						</td>
					</tr>
					<tr>
						<th>Copy Method</th>
						<td>
							<select name="cf_email_copy_method">
								<option value="bcc" <?php selected( 'bcc', $copy_method ); ?>>BCC (Blind Carbon Copy - Recommended)</option>
								<option value="cc" <?php selected( 'cc', $copy_method ); ?>>CC (Carbon Copy)</option>
							</select>
							<p class="description">BCC ensures recipients cannot see the copied email addresses, preserving privacy.</p>
						</td>
					</tr>
					<tr>
						<th colspan="2"><hr></th>
					</tr>
					<tr>
						<th>Debug Mode (Alerts)</th>
						<td>
							<label>
								<input type="checkbox" name="cf_email_debug_mode" value="1" <?php checked( 1, $debug_mode, true ); ?> />
								Enable email alerts when a payload is rejected by Cloudflare.
							</label>
						</td>
					</tr>
					<tr>
						<th>Debug Email Address</th>
						<td>
							<input type="email" name="cf_email_debug_email" value="<?php echo esc_attr( $debug_email ); ?>" class="regular-text" />
							<p class="description">Where should error alerts be sent? Defaults to the site admin email.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>
  
			<hr>
  
			<h2>Send a Test Email</h2>
			<form action="" method="post">
				<?php wp_nonce_field( 'cf_email_test_action', 'cf_email_test_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th>Send To:</th>
						<td>
							<input type="email" name="cf_email_test_to" value="" class="regular-text" required placeholder="recipient@example.com" />
						</td>
					</tr>
				</table>
				<?php submit_button( 'Send Test Email', 'secondary', 'cf_email_send_test' ); ?>
			</form>
		</div>
		<?php
	}

	// 2. Override wp_mail() securely
	if ( ! function_exists( 'wp_mail' ) ) {
		function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {

			// Apply the core wp_mail filter so logging, auditing and other mail plugins keep working
			$atts = apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );
			if ( isset( $atts['to'] ) ) {
				$to = $atts['to']; }
			if ( isset( $atts['subject'] ) ) {
				$subject = $atts['subject']; }
			if ( isset( $atts['message'] ) ) {
				$message = $atts['message']; }
			if ( isset( $atts['headers'] ) ) {
				$headers = $atts['headers']; }
			if ( isset( $atts['attachments'] ) ) {
				$attachments = $atts['attachments']; }

			$mail_data = compact( 'to', 'subject', 'message', 'headers', 'attachments' );

			$account_id = get_option( 'cf_email_account_id' );
			$api_token  = cf_email_get_api_token();
			$from_email = sanitize_email( get_option( 'cf_email_from_address' ) );
			$from_name  = sanitize_text_field( get_option( 'cf_email_from_name' ) );

			if ( empty( $account_id ) || empty( $api_token ) || empty( $from_email ) ) {
				$error_msg = 'Missing configuration settings.';
				error_log( 'Cloudflare Email Sender: ' . $error_msg );
				update_option( 'cf_email_last_error', $error_msg );
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error_msg, $mail_data ) );
				return false;
			}

			if ( ! empty( $attachments ) ) {
				error_log( 'Cloudflare Email Sender: attachments are not supported by the Cloudflare Email Service API and were discarded for message "' . sanitize_text_field( $subject ) . '".' );
			}

			$to_array = is_array( $to ) ? $to : explode( ',', $to );
			$final_to = array();
			foreach ( $to_array as $addr ) {
				$clean_addr = cf_email_extract_pure_address( $addr );
				if ( is_email( $clean_addr ) ) {
					$final_to[] = $clean_addr;
				}
			}

			if ( empty( $final_to ) ) {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'No valid recipient address.', $mail_data ) );
				return false;
			}

			$formatted_from = $from_email;
			if ( ! empty( $from_name ) ) {
				$clean_from_name = trim( str_replace( array( '"', '<', '>', "\r", "\n" ), '', $from_name ) );
				$formatted_from  = sprintf( '"%s" <%s>', $clean_from_name, $from_email );
			}

			$parsed_reply_to = '';
			$cc_array        = array();
			$bcc_array       = array();

			$content_type = apply_filters( 'wp_mail_content_type', 'text/plain' );
			$is_html      = ( 'text/html' === $content_type );

			if ( ! empty( $headers ) ) {
				if ( ! is_array( $headers ) ) {
					$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
				}
				foreach ( $headers as $header ) {
					if ( empty( trim( $header ) ) ) {
						continue;
					}

					$parts = explode( ':', $header, 2 );
					if ( count( $parts ) === 2 ) {
						$header_name  = trim( $parts[0] );
						$header_value = trim( $parts[1] );
						$header_lower = strtolower( $header_name );

						if ( $header_lower === 'content-type' ) {
							if ( stripos( $header_value, 'text/html' ) !== false ) {
								$is_html = true;
							}
						} elseif ( $header_lower === 'reply-to' ) {
							$parsed_reply_to = cf_email_extract_pure_address( $header_value );
						} elseif ( $header_lower === 'cc' ) {
							$cc_emails = explode( ',', $header_value );
							foreach ( $cc_emails as $email ) {
								$clean_email = cf_email_extract_pure_address( $email );
								if ( is_email( $clean_email ) ) {
									$cc_array[] = $clean_email;
								}
							}
						} elseif ( $header_lower === 'bcc' ) {
							$bcc_emails = explode( ',', $header_value );
							foreach ( $bcc_emails as $email ) {
								$clean_email = cf_email_extract_pure_address( $email );
								if ( is_email( $clean_email ) ) {
									$bcc_array[] = $clean_email;
								}
							}
						}
					}
				}
			}

			if ( cf_email_should_copy( $final_to, $to, $subject, $message ) ) {
				$copy_raw    = get_option( 'cf_email_copy_addresses', '' );
				$copy_emails = cf_email_parse_address_list( $copy_raw );
				$copy_method = get_option( 'cf_email_copy_method', 'bcc' );

				if ( ! empty( $copy_emails ) ) {
					if ( 'cc' === $copy_method ) {
						foreach ( $copy_emails as $copy_email ) {
							$copy_lower = strtolower( $copy_email );
							$in_to      = in_array( $copy_lower, array_map( 'strtolower', $final_to ), true );
							$in_cc      = in_array( $copy_lower, array_map( 'strtolower', $cc_array ), true );
							if ( ! $in_to && ! $in_cc ) {
								$cc_array[] = $copy_email;
							}
						}
					} else {
						foreach ( $copy_emails as $copy_email ) {
							$copy_lower = strtolower( $copy_email );
							$in_to      = in_array( $copy_lower, array_map( 'strtolower', $final_to ), true );
							$in_cc      = in_array( $copy_lower, array_map( 'strtolower', $cc_array ), true );
							$in_bcc     = in_array( $copy_lower, array_map( 'strtolower', $bcc_array ), true );
							if ( ! $in_to && ! $in_cc && ! $in_bcc ) {
								$bcc_array[] = $copy_email;
							}
						}
					}
				}
			}

			$final_html = $is_html ? wp_kses_post( $message ) : wp_kses_post( nl2br( $message ) );
			$final_text = $is_html ? wp_strip_all_tags( $message ) : $message;

			$settings_reply_to = sanitize_email( get_option( 'cf_email_reply_to' ) );
			$force_override    = 1 === (int) get_option( 'cf_email_reply_to_override' );

			$final_reply_to = '';
			if ( ! empty( $parsed_reply_to ) ) {
				$final_reply_to = ( $force_override && ! empty( $settings_reply_to ) ) ? $settings_reply_to : $parsed_reply_to;
			} elseif ( ! empty( $settings_reply_to ) ) {
				$final_reply_to = $settings_reply_to;
			}

			$url = 'https://api.cloudflare.com/client/v4/accounts/' . sanitize_text_field( $account_id ) . '/email/sending/send';

			$body = array(
				'to'      => count( $final_to ) === 1 ? $final_to[0] : $final_to,
				'from'    => $formatted_from,
				'subject' => sanitize_text_field( $subject ),
				'html'    => $final_html,
				'text'    => $final_text,
			);

			if ( ! empty( $final_reply_to ) ) {
				$body['reply_to'] = $final_reply_to;
			}

			if ( ! empty( $cc_array ) ) {
				$body['cc'] = array_values( array_unique( $cc_array ) );
			}

			if ( ! empty( $bcc_array ) ) {
				$body['bcc'] = array_values( array_unique( $bcc_array ) );
			}

			$args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . sanitize_text_field( $api_token ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			);

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				$wp_error_msg = $response->get_error_message();
				error_log( 'Cloudflare Email Sender WP_Error: ' . $wp_error_msg );
				update_option( 'cf_email_last_error', 'Connection failed: ' . $wp_error_msg );
				cf_email_send_debug_alert( 'Connection failed: ' . $wp_error_msg, $subject, $to );
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Connection failed: ' . $wp_error_msg, $mail_data ) );
				return false;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code >= 200 && $response_code < 300 ) {
				delete_option( 'cf_email_last_error' );
				return true;
			} else {
				$api_error = wp_strip_all_tags( wp_remote_retrieve_body( $response ) );
				error_log( 'Cloudflare Email Sender API Error (Code ' . sanitize_text_field( $response_code ) . '): ' . $api_error );

				if ( in_array( (int) $response_code, array( 401, 403, 404 ), true ) ) {
					update_option( 'cf_email_last_error', 'Configuration/Authentication Error (' . $response_code . '). Please check your API Token and Account ID.' );
				}

				cf_email_send_debug_alert( 'API Error ' . sanitize_text_field( $response_code ) . ': ' . $api_error, $subject, $to );
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'API Error ' . $response_code . ': ' . $api_error, $mail_data ) );
				return false;
			}
		}
	}