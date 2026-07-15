<?php
/**
 * Plugin Name: Cloudflare Email Sender
 * Description: Routes WordPress emails through the Cloudflare Email Service REST API.
 * Version: 1.5.6
 * Tested up to: 7.0.1
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
 
 // Authentication token to access Github library
 $myUpdateChecker->setAuthentication('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');

 // Helper Function: Extracts pure email address from "Name <email@domain.com>" formats
 if ( ! function_exists('cf_email_extract_pure_address') ) {
	 function cf_email_extract_pure_address($string) {
		 if ( preg_match( '/<([^>]+)>/', $string, $matches ) ) {
			 return sanitize_email( trim( $matches[1] ) );
		 }
		 return sanitize_email( trim( $string ) );
	 }
 }

 // 1. Register Settings Page & Handle Test Email
 add_action('admin_menu', 'cf_email_add_admin_menu');
 function cf_email_add_admin_menu() {
	 add_options_page('Cloudflare Email', 'Cloudflare Email', 'manage_options', 'cloudflare-email-sender', 'cf_email_settings_page');
 }
 
 add_action('admin_init', 'cf_email_settings_init');
 function cf_email_settings_init() {
	 register_setting('cf_email_plugin_page', 'cf_email_account_id', 'sanitize_text_field');
	 register_setting('cf_email_plugin_page', 'cf_email_api_token', 'sanitize_text_field');
	 register_setting('cf_email_plugin_page', 'cf_email_from_address', 'sanitize_email');
	 register_setting('cf_email_plugin_page', 'cf_email_from_name', 'sanitize_text_field');
	 register_setting('cf_email_plugin_page', 'cf_email_reply_to', 'sanitize_email');
	 register_setting('cf_email_plugin_page', 'cf_email_reply_to_override', 'absint'); 
	 register_setting('cf_email_plugin_page', 'cf_email_debug_mode', 'absint'); 
	 register_setting('cf_email_plugin_page', 'cf_email_debug_email', 'sanitize_email'); 
 
	 if ( isset($_POST['cf_email_send_test']) && current_user_can('manage_options') ) {
		 check_admin_referer('cf_email_test_action', 'cf_email_test_nonce');
		 
		 $to_email = sanitize_email($_POST['cf_email_test_to']);
		 
		 if ( is_email($to_email) ) {
			 $subject = 'Test Email: Cloudflare Email Sender';
			 $message = '<h1>Success!</h1><p>If you are reading this, your Cloudflare Email Service REST API configuration is working correctly in WordPress.</p>';
			 
			 $sent = wp_mail($to_email, $subject, $message);
			 
			 if ( $sent ) {
				 add_settings_error('cf_email_messages', 'cf_email_message', 'Test email sent successfully to ' . esc_html($to_email), 'updated');
			 } else {
				 add_settings_error('cf_email_messages', 'cf_email_message', 'Failed to send test email. Please check your configuration and server error logs.', 'error');
			 }
		 } else {
			 add_settings_error('cf_email_messages', 'cf_email_message', 'Invalid email address provided for the test.', 'error');
		 }
	 }
 }

 // Admin Notice for Configuration/Connection Failures
 add_action('admin_notices', 'cf_email_admin_notices');
 function cf_email_admin_notices() {
	 if ( ! current_user_can( 'manage_options' ) ) return;

	 if ( isset( $_GET['cf_email_dismiss_error'] ) && $_GET['cf_email_dismiss_error'] == '1' ) {
		 delete_option( 'cf_email_last_error' );
	 }

	 $error = get_option('cf_email_last_error');
	 if ( $error ) {
		 $dismiss_url = add_query_arg( 'cf_email_dismiss_error', '1' );
		 echo '<div class="notice notice-error"><p><strong>Cloudflare Email Sender Error:</strong> ' . esc_html($error) . ' <a href="' . esc_url($dismiss_url) . '" style="float:right; text-decoration:none;">Dismiss &times;</a></p></div>';
	 }
 }

 // Debug Alert Mailer
 function cf_email_send_debug_alert($error_details, $original_subject, $original_to) {
	 static $is_sending_alert = false;
	 if ( $is_sending_alert ) return; 

	 $debug_mode = get_option('cf_email_debug_mode', 1);
	 if ( ! $debug_mode ) return;

	 $debug_email = get_option('cf_email_debug_email', 'tools@potomactech.net');
	 if ( ! is_email($debug_email) ) return;

	 $site_url = get_site_url();
	 $alert_subject = "CF Email Error: {$site_url}";
	 
	 $orig_to_str = is_array($original_to) ? implode(', ', $original_to) : $original_to;

	 $alert_body = "An email failed to send via the Cloudflare Email REST API.\n\n";
	 $alert_body .= "Site: {$site_url}\n";
	 $alert_body .= "Original To: {$orig_to_str}\n";
	 $alert_body .= "Original Subject: {$original_subject}\n\n";
	 $alert_body .= "Error Details:\n{$error_details}\n\n";
	 $alert_body .= "Please check the WordPress error logs for more information.";

	 $is_sending_alert = true;
	 wp_mail($debug_email, $alert_subject, $alert_body);
	 $is_sending_alert = false;
 }
 
 function cf_email_settings_page() {
	 if ( ! current_user_can( 'manage_options' ) ) {
		 return;
	 }

	 $debug_mode = get_option('cf_email_debug_mode', 1);
	 $debug_email = get_option('cf_email_debug_email', 'tools@potomactech.net');
	 ?>
	 <div class="wrap">
		 <h2>Cloudflare Email Settings</h2>
 
		 <form action="options.php" method="post">
			 <?php
			 settings_fields('cf_email_plugin_page');
			 do_settings_sections('cf_email_plugin_page');
			 ?>
			 <table class="form-table">
				 <tr>
					 <th>Cloudflare Account ID</th>
					 <td><input type="text" name="cf_email_account_id" value="<?php echo esc_attr(get_option('cf_email_account_id')); ?>" class="regular-text" /></td>
				 </tr>
				 <tr>
					 <th>API Token</th>
					 <td><input type="password" name="cf_email_api_token" value="<?php echo esc_attr(get_option('cf_email_api_token')); ?>" class="regular-text" autocomplete="new-password" /></td>
				 </tr>
				 <tr>
					 <th>From Address</th>
					 <td><input type="email" name="cf_email_from_address" value="<?php echo esc_attr(get_option('cf_email_from_address')); ?>" class="regular-text" required /></td>
				 </tr>
				 <tr>
					 <th>From Name (Optional)</th>
					 <td>
						 <input type="text" name="cf_email_from_name" value="<?php echo esc_attr(get_option('cf_email_from_name')); ?>" class="regular-text" />
						 <p class="description">If provided, this will force the sender name for all outgoing emails (e.g., "My Website").</p>
					 </td>
				 </tr>
				 <tr>
					 <th>Default Reply-To (Optional)</th>
					 <td>
						 <input type="email" name="cf_email_reply_to" value="<?php echo esc_attr(get_option('cf_email_reply_to')); ?>" class="regular-text" />
						 <p class="description">Email address to receive replies if no other Reply-To is specified by WordPress or a contact form.</p>
					 </td>
				 </tr>
				 <tr>
					 <th>Force Reply-To Override</th>
					 <td>
						 <label>
							 <input type="checkbox" name="cf_email_reply_to_override" value="1" <?php checked(1, get_option('cf_email_reply_to_override'), true); ?> />
							 Always use the Default Reply-To address above, overwriting any Reply-To addresses set by plugins (like contact forms).
						 </label>
					 </td>
				 </tr>
				 <tr>
					 <th colspan="2"><hr></th>
				 </tr>
				 <tr>
					 <th>Debug Mode (Alerts)</th>
					 <td>
						 <label>
							 <input type="checkbox" name="cf_email_debug_mode" value="1" <?php checked(1, $debug_mode, true); ?> />
							 Enable email alerts when a payload is rejected by Cloudflare.
						 </label>
					 </td>
				 </tr>
				 <tr>
					 <th>Debug Email Address</th>
					 <td>
						 <input type="email" name="cf_email_debug_email" value="<?php echo esc_attr($debug_email); ?>" class="regular-text" />
						 <p class="description">Where should error alerts be sent? Defaults to tools@potomactech.net.</p>
					 </td>
				 </tr>
			 </table>
			 <?php submit_button('Save Settings'); ?>
		 </form>
 
		 <hr>
 
		 <h2>Send a Test Email</h2>
		 <form action="" method="post">
			 <?php wp_nonce_field('cf_email_test_action', 'cf_email_test_nonce'); ?>
			 <table class="form-table">
				 <tr>
					 <th>Send To:</th>
					 <td>
						 <input type="email" name="cf_email_test_to" value="" class="regular-text" required placeholder="recipient@example.com" />
					 </td>
				 </tr>
			 </table>
			 <?php submit_button('Send Test Email', 'secondary', 'cf_email_send_test'); ?>
		 </form>
	 </div>
	 <?php
 }
 
 // 2. Override wp_mail() securely
 if ( ! function_exists( 'wp_mail' ) ) {
	 function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		 
		 $account_id = get_option('cf_email_account_id');
		 $api_token  = get_option('cf_email_api_token');
		 $from_email = sanitize_email(get_option('cf_email_from_address'));
		 $from_name  = sanitize_text_field(get_option('cf_email_from_name'));
 
		 if ( empty($account_id) || empty($api_token) || empty($from_email) ) {
			 $error_msg = 'Missing configuration settings.';
			 error_log('Cloudflare Email Sender: ' . $error_msg);
			 update_option('cf_email_last_error', $error_msg);
			 return false;
		 }
 
		 $to_array = is_array( $to ) ? $to : explode( ',', $to );
		 $final_to = array();
		 foreach ( $to_array as $addr ) {
			 // NEW: Smart extraction to strip names
			 $clean_addr = cf_email_extract_pure_address( $addr );
			 if ( is_email( $clean_addr ) ) {
				 $final_to[] = $clean_addr;
			 }
		 }
 
		 if ( empty( $final_to ) ) {
			  return false;
		 }
 
		 $formatted_from = $from_email;
		 if ( ! empty( $from_name ) ) {
			 $clean_from_name = str_replace( '"', '', $from_name );
			 $formatted_from = sprintf( '"%s" <%s>', $clean_from_name, $from_email );
		 }
 
		 $parsed_reply_to = '';
		 $cc_array = array();
		 $bcc_array = array();

		 $content_type = apply_filters( 'wp_mail_content_type', 'text/plain' );
		 $is_html = ( 'text/html' === $content_type );
 
		 if ( ! empty( $headers ) ) {
			 if ( ! is_array( $headers ) ) {
				 $headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
			 }
			 foreach ( $headers as $header ) {
				 if ( empty( trim( $header ) ) ) continue;
				 
				 $parts = explode( ':', $header, 2 );
				 if ( count( $parts ) === 2 ) {
					 $header_name = trim( $parts[0] );
					 $header_value = trim( $parts[1] );
					 $header_lower = strtolower($header_name);
					 
					 if ( $header_lower === 'content-type' ) {
						 if ( stripos( $header_value, 'text/html' ) !== false ) {
							 $is_html = true;
						 }
					 } elseif ( $header_lower === 'reply-to' ) {
						 // NEW: Smart extraction to strip names
						 $parsed_reply_to = cf_email_extract_pure_address( $header_value );
					 } elseif ( $header_lower === 'cc' ) {
						 $cc_emails = explode( ',', $header_value );
						 foreach ( $cc_emails as $email ) {
							 // NEW: Smart extraction to strip names
							 $clean_email = cf_email_extract_pure_address( $email );
							 if ( is_email( $clean_email ) ) {
								 $cc_array[] = $clean_email;
							 }
						 }
					 } elseif ( $header_lower === 'bcc' ) {
						 $bcc_emails = explode( ',', $header_value );
						 foreach ( $bcc_emails as $email ) {
							 // NEW: Smart extraction to strip names
							 $clean_email = cf_email_extract_pure_address( $email );
							 if ( is_email( $clean_email ) ) {
								 $bcc_array[] = $clean_email;
							 }
						 }
					 }
				 }
			 }
		 }

		 $final_html = $is_html ? wp_kses_post($message) : wp_kses_post(nl2br($message));
		 $final_text = $is_html ? wp_strip_all_tags($message) : $message;
 
		 $settings_reply_to = sanitize_email( get_option('cf_email_reply_to') );
		 $force_override    = get_option('cf_email_reply_to_override') == 1;
 
		 $final_reply_to = '';
		 if ( ! empty( $parsed_reply_to ) ) {
			 $final_reply_to = ( $force_override && ! empty( $settings_reply_to ) ) ? $settings_reply_to : $parsed_reply_to;
		 } elseif ( ! empty( $settings_reply_to ) ) {
			 $final_reply_to = $settings_reply_to;
		 }
 
		 $url = 'https://api.cloudflare.com/client/v4/accounts/' . sanitize_text_field($account_id) . '/email/sending/send';
 
		 $body = array(
			 'to'      => count($final_to) === 1 ? $final_to[0] : $final_to, 
			 'from'    => $formatted_from, 
			 'subject' => sanitize_text_field($subject), 
			 'html'    => $final_html, 
			 'text'    => $final_text
		 );
 
		 if ( ! empty( $final_reply_to ) ) {
			 $body['reply_to'] = $final_reply_to;
		 }

		 if ( ! empty( $cc_array ) ) {
			 $body['cc'] = $cc_array;
		 }

		 if ( ! empty( $bcc_array ) ) {
			 $body['bcc'] = $bcc_array;
		 }
 
		 $args = array(
			 'method'  => 'POST',
			 'headers' => array(
				 'Authorization' => 'Bearer ' . sanitize_text_field($api_token),
				 'Content-Type'  => 'application/json'
			 ),
			 'body'    => wp_json_encode($body),
			 'timeout' => 15,
		 );
 
		 $response = wp_remote_post( $url, $args );
 
		 if ( is_wp_error( $response ) ) {
			 $wp_error_msg = $response->get_error_message();
			 error_log('Cloudflare Email Sender WP_Error: ' . $wp_error_msg);
			 update_option('cf_email_last_error', 'Connection failed: ' . $wp_error_msg);
			 cf_email_send_debug_alert('Connection failed: ' . $wp_error_msg, $subject, $to);
			 return false;
		 }
 
		 $response_code = wp_remote_retrieve_response_code( $response );
		 if ( $response_code >= 200 && $response_code < 300 ) {
			 delete_option('cf_email_last_error');
			 return true;
		 } else {
			 $api_error = wp_strip_all_tags(wp_remote_retrieve_body( $response ));
			 error_log('Cloudflare Email Sender API Error (Code ' . sanitize_text_field($response_code) . '): ' . $api_error);
			 
			 if ( in_array( $response_code, array( 401, 403, 404 ) ) ) {
				 update_option('cf_email_last_error', 'Configuration/Authentication Error (' . $response_code . '). Please check your API Token and Account ID.');
			 }

			 cf_email_send_debug_alert('API Error ' . sanitize_text_field($response_code) . ': ' . $api_error, $subject, $to);
			 return false;
		 }
	 }
 }