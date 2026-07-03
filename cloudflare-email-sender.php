<?php
/**
 * Plugin Name: Cloudflare Email Sender
 * Description: Routes WordPress emails through the Cloudflare Email Service REST API.
 * Version: 1.3
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
	 'https://github.com/rosspotomactech/cloudflare-email-sender', // GitHub URL
	 __FILE__,
	 'cloudflare-email-sender'
 );
 
 // Authentication token to access Github library
 $myUpdateChecker->setAuthentication('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');

 // 1. Register Settings Page & Handle Test Email
 add_action('admin_menu', 'cf_email_add_admin_menu');
 function cf_email_add_admin_menu() {
	 add_options_page('Cloudflare Email', 'Cloudflare Email', 'manage_options', 'cloudflare-email-sender', 'cf_email_settings_page');
 }
 
 add_action('admin_init', 'cf_email_settings_init');
 function cf_email_settings_init() {
	 // Register settings WITH strict sanitization callbacks
	 register_setting('cf_email_plugin_page', 'cf_email_account_id', 'sanitize_text_field');
	 register_setting('cf_email_plugin_page', 'cf_email_api_token', 'sanitize_text_field');
	 register_setting('cf_email_plugin_page', 'cf_email_from_address', 'sanitize_email');
	 // Register the new optional From Name setting
	 register_setting('cf_email_plugin_page', 'cf_email_from_name', 'sanitize_text_field');
 
	 // Process Test Email Submission
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
 
 function cf_email_settings_page() {
	 if ( ! current_user_can( 'manage_options' ) ) {
		 return;
	 }
	 ?>
	 <div class="wrap">
		 <h2>Cloudflare Email Settings</h2>
		 
		 <?php settings_errors('cf_email_messages'); ?>
 
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
			 </table>
			 <?php submit_button('Save Settings'); ?>
		 </form>
 
		 <hr>
 
		 <h2>Send a Test Email</h2>
		 <p>Enter an email address below to verify your Cloudflare REST API connection.</p>
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
			 error_log('Cloudflare Email Sender: Missing configuration settings.');
			 return false;
		 }
 
		 // Securely parse and sanitize the recipient email address
		 if ( is_array( $to ) ) {
			 $to_address = sanitize_email( $to[0] );
		 } else {
			 $to_address = sanitize_email( explode(',', $to)[0] ); 
		 }
 
		 if ( ! is_email( $to_address ) ) {
			  error_log('Cloudflare Email Sender: Invalid recipient email address.');
			  return false;
		 }
 
		 // Format the sender correctly based on whether a name was provided
		 $formatted_from = $from_email;
		 if ( ! empty( $from_name ) ) {
			 $formatted_from = sprintf( '%s <%s>', $from_name, $from_email );
		 }
 
		 $url = 'https://api.cloudflare.com/client/v4/accounts/' . sanitize_text_field($account_id) . '/email/sending/send';
 
		 $body = array(
			 'to'      => $to_address,
			 'from'    => $formatted_from, // Utilizes the optionally formatted name and address string
			 'subject' => sanitize_text_field($subject), 
			 'html'    => wp_kses_post($message),
			 'text'    => wp_strip_all_tags($message)
		 );
 
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
			 error_log('Cloudflare Email Sender WP_Error: ' . $response->get_error_message());
			 return false;
		 }
 
		 $response_code = wp_remote_retrieve_response_code( $response );
		 $response_body = wp_remote_retrieve_body( $response );
 
		 if ( $response_code >= 200 && $response_code < 300 ) {
			 return true;
		 } else {
			 error_log('Cloudflare Email Sender API Error (Code ' . sanitize_text_field($response_code) . '): ' . wp_strip_all_tags($response_body));
			 return false;
		 }
	 }
 }