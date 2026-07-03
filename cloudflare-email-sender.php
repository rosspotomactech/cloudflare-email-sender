<?php
/**
 * Plugin Name: Cloudflare Email Sender
 * Description: Routes WordPress emails through the Cloudflare Email Service REST API.
 * Version: 1.1
 * Author: Potomac Technologies, LLC
 * Author URI:  https://potomactech.net
 */

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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// 1. Register Settings Page & Handle Test Email
add_action('admin_menu', 'cf_email_add_admin_menu');
function cf_email_add_admin_menu() {
	add_options_page('Cloudflare Email', 'Cloudflare Email', 'manage_options', 'cloudflare-email-sender', 'cf_email_settings_page');
}

add_action('admin_init', 'cf_email_settings_init');
function cf_email_settings_init() {
	// Register settings
	register_setting('cf_email_plugin_page', 'cf_email_account_id');
	register_setting('cf_email_plugin_page', 'cf_email_api_token');
	register_setting('cf_email_plugin_page', 'cf_email_from_address');

	// Process Test Email Submission
	if ( isset($_POST['cf_email_send_test']) && current_user_can('manage_options') ) {
		// Verify nonce for security
		check_admin_referer('cf_email_test_action', 'cf_email_test_nonce');
		
		$to_email = sanitize_email($_POST['cf_email_test_to']);
		
		if ( is_email($to_email) ) {
			$subject = 'Test Email: Cloudflare Email Sender';
			$message = '<h1>Success!</h1><p>If you are reading this, your Cloudflare Email Service REST API configuration is working correctly in WordPress.</p>';
			
			// Trigger wp_mail, which will use our custom override below
			$sent = wp_mail($to_email, $subject, $message);
			
			if ( $sent ) {
				add_settings_error('cf_email_messages', 'cf_email_message', 'Test email sent successfully to ' . $to_email, 'updated');
			} else {
				add_settings_error('cf_email_messages', 'cf_email_message', 'Failed to send test email. Please check your configuration and server error logs.', 'error');
			}
		} else {
			add_settings_error('cf_email_messages', 'cf_email_message', 'Invalid email address provided for the test.', 'error');
		}
	}
}

function cf_email_settings_page() {
	// Check user capabilities
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
					<td><input type="password" name="cf_email_api_token" value="<?php echo esc_attr(get_option('cf_email_api_token')); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th>From Address</th>
					<td><input type="email" name="cf_email_from_address" value="<?php echo esc_attr(get_option('cf_email_from_address')); ?>" class="regular-text" /></td>
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

// 2. Override wp_mail()
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		
		$account_id = get_option('cf_email_account_id');
		$api_token  = get_option('cf_email_api_token');
		$from_email = get_option('cf_email_from_address');

		// Fail gracefully if settings are missing
		if ( empty($account_id) || empty($api_token) || empty($from_email) ) {
			error_log('Cloudflare Email Sender: Missing configuration settings.');
			return false;
		}

		// Determine if multiple recipients are passed as an array or comma-separated string
		if ( is_array( $to ) ) {
			$to_address = $to[0];
		} else {
			$to_address = explode(',', $to)[0]; 
		}

		// Construct the Cloudflare API URL
		$url = 'https://api.cloudflare.com/client/v4/accounts/' . $account_id . '/email/sending/send';

		// Build the JSON payload
		$body = array(
			'to'      => trim($to_address),
			'from'    => $from_email,
			'subject' => $subject,
			'html'    => $message, 
			'text'    => wp_strip_all_tags($message)
		);

		// Prepare the HTTP request arguments
		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json'
			),
			'body'    => wp_json_encode($body),
			'timeout' => 15,
		);

		// Send the HTTP request using WordPress's native function
		$response = wp_remote_post( $url, $args );

		// Error handling
		if ( is_wp_error( $response ) ) {
			error_log('Cloudflare Email Sender WP_Error: ' . $response->get_error_message());
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code >= 200 && $response_code < 300 ) {
			return true;
		} else {
			error_log('Cloudflare Email Sender API Error (Code ' . $response_code . '): ' . $response_body);
			return false;
		}
	}
}