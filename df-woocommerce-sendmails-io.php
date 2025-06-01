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
    $option_name = 'df_wc_sendmailsio_api_key';

    // Handle form submission BEFORE retrieving the key for display
    if (isset($_POST['df_wc_sendmailsio_save'])) {
        if (!isset($_POST['df_wc_sendmailsio_nonce']) || !wp_verify_nonce($_POST['df_wc_sendmailsio_nonce'], 'df_wc_sendmailsio_save_api_key')) {
            echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
        } else {
            $new_key = isset($_POST['df_wc_sendmailsio_api_key']) ? sanitize_text_field($_POST['df_wc_sendmailsio_api_key']) : '';
            if ($new_key) {
                update_option($option_name, $new_key);
                echo '<div class="notice notice-success"><p>API key updated successfully.</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>Please enter a valid API key.</p></div>';
            }
        }
    }

    // Now retrieve the latest value
    $api_key = get_option($option_name, '');
    $masked_key = $api_key ? str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4) : '';
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('df_wc_sendmailsio_save_api_key', 'df_wc_sendmailsio_nonce'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Sendmails.io API Key</th>
                <td>
                    <?php if ($api_key): ?>
                        <input type="text" value="<?php echo esc_attr($masked_key); ?>" disabled style="width:300px;" />
                        <br><small>Only the last 4 characters are shown. Enter a new key to overwrite.</small>
                    <?php else: ?>
                        <small>No API key set. Please enter your key below.</small>
                    <?php endif; ?>
                    <br>
                    <input type="password" name="df_wc_sendmailsio_api_key" value="" style="width:300px;" autocomplete="off" />
                </td>
            </tr>
        </table>
        <p>
            <input type="submit" name="df_wc_sendmailsio_save" class="button button-primary" value="Save API Key" />
        </p>
    </form>
    <?php
}
