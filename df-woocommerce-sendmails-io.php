<?php
/*
Plugin Name: DF - Woocommerce Sendmails.io
Description: Integrates WooCommerce products with sendmails.io mailing lists. Adds a settings page for API key management.
Version: 0.01
Author: radialmonster
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add admin menu
add_action('admin_menu', 'df_wc_sendmailsio_add_admin_menu');
function df_wc_sendmailsio_add_admin_menu() {
    add_menu_page(
        'DF - Woocommerce Sendmails.io',
        'DF - Woocommerce Sendmails.io',
        'manage_options',
        'df-wc-sendmailsio',
        'df_wc_sendmailsio_settings_page',
        'dashicons-email-alt2',
        56
    );
}

// Settings page with tabs (only Settings for now)
function df_wc_sendmailsio_settings_page() {
    ?>
    <div class="wrap">
        <h1>DF - Woocommerce Sendmails.io</h1>
        <h2 class="nav-tab-wrapper">
            <a href="#" class="nav-tab nav-tab-active">Settings</a>
        </h2>
        <?php df_wc_sendmailsio_settings_form(); ?>
    </div>
    <?php
}

// Settings form
function df_wc_sendmailsio_settings_form() {
    $api_key_option = 'df_wc_sendmailsio_api_key';
    $endpoint_option = 'df_wc_sendmailsio_api_endpoint';
    $default_endpoint = 'https://app.sendmails.io/api/v1';

    // Handle form submission BEFORE retrieving the values for display
    if (isset($_POST['df_wc_sendmailsio_save'])) {
        if (!isset($_POST['df_wc_sendmailsio_nonce']) || !wp_verify_nonce($_POST['df_wc_sendmailsio_nonce'], 'df_wc_sendmailsio_save_api_key')) {
            echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
        } else {
            $new_key = isset($_POST['df_wc_sendmailsio_api_key']) ? sanitize_text_field($_POST['df_wc_sendmailsio_api_key']) : '';
            $new_endpoint = isset($_POST['df_wc_sendmailsio_api_endpoint']) ? esc_url_raw(trim($_POST['df_wc_sendmailsio_api_endpoint'])) : '';
            $updated = false;
            if ($new_key) {
                update_option($api_key_option, $new_key);
                $updated = true;
            }
            if ($new_endpoint) {
                update_option($endpoint_option, $new_endpoint);
                $updated = true;
            }
            if ($updated) {
                echo '<div class="notice notice-success"><p>Settings updated successfully.</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>Please enter valid values to update.</p></div>';
            }
        }
    }

    // Now retrieve the latest values
    $api_key = get_option($api_key_option, '');
    $masked_key = $api_key ? str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4) : '';
    $api_endpoint = get_option($endpoint_option, $default_endpoint);
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('df_wc_sendmailsio_save_api_key', 'df_wc_sendmailsio_nonce'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Sendmails.io API Key</th>
                <td>
                    <?php if ($api_key): ?>
                        <span style="font-family:monospace;"><?php echo esc_html($masked_key); ?></span>
                        <br><small>Only the last 4 characters are shown. Enter a new key to overwrite.</small>
                    <?php else: ?>
                        <small>No API key set. Please enter your key below.</small>
                    <?php endif; ?>
                    <br>
                    <input type="password" name="df_wc_sendmailsio_api_key" value="" style="width:300px;" autocomplete="off" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Sendmails.io API Endpoint</th>
                <td>
                    <input type="text" name="df_wc_sendmailsio_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" style="width:300px;" autocomplete="off" />
                    <br><small>Default: <?php echo esc_html($default_endpoint); ?></small>
                </td>
            </tr>
        </table>
        <p>
            <input type="submit" name="df_wc_sendmailsio_save" class="button button-primary" value="Save Settings" />
        </p>
    </form>
    <?php
}
