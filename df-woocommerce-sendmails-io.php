<?php
/*
Plugin Name: DF - Woocommerce Sendmails.io
Description: Integrates WooCommerce products with sendmails.io mailing lists.
Version: 0.13
Author: radialmonster
GitHub Plugin URI: https://github.com/radialmonster/woocommerce-sendmails.io
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Add admin menu and submenus
 */
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
    add_submenu_page(
        'df-wc-sendmailsio',
        'Product List Mapping',
        'Product List Mapping',
        'read', // all logged-in users
        'df-wc-sendmailsio-product-mapping',
        'df_wc_sendmailsio_product_mapping_page'
    );
}

/**
 * Settings page with tabs (only Settings for now)
 */
function df_wc_sendmailsio_settings_page() {
    ?>
    <div class="wrap">
        <h1>DF - Woocommerce Sendmails.io</h1>
        <h2 class="nav-tab-wrapper">
            <a href="#" class="nav-tab nav-tab-active">Settings</a>
            <a href="<?php echo admin_url('admin.php?page=df-wc-sendmailsio-product-mapping'); ?>" class="nav-tab">Product List Mapping</a>
        </h2>
        <?php df_wc_sendmailsio_settings_form(); ?>
    </div>
    <?php
}

/**
 * Fetch all sendmails.io lists using API key/endpoint from settings
 * @return array|WP_Error
 */
function df_wc_sendmailsio_get_all_lists() {
    $api_key = get_option('df_wc_sendmailsio_api_key', '');
    $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
    if (!$api_key) {
        return new WP_Error('no_api_key', 'No sendmails.io API key set.');
    }
    $url = trailingslashit($api_endpoint) . 'lists';
    $args = array(
        'headers' => array('Accept' => 'application/json'),
        'timeout' => 15,
    );
    $url = add_query_arg('api_token', $api_key, $url);
    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code !== 200) {
        return new WP_Error('api_error', 'API error: ' . $body);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return new WP_Error('api_error', 'Invalid API response.');
    }
    return $data;
}

/**
 * Product List Mapping Page
 */
function df_wc_sendmailsio_product_mapping_page() {
    // Handle mapping save
    if (!empty($_POST['df_wc_sendmailsio_save_mapping']) && !empty($_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        if (isset($_POST['df_wc_sendmailsio_nonce_' . $product_id]) && wp_verify_nonce($_POST['df_wc_sendmailsio_nonce_' . $product_id], 'df_wc_sendmailsio_save_mapping_' . $product_id)) {
            $list_uid = sanitize_text_field($_POST['sendmailsio_list_uid']);
            if ($list_uid) {
                update_post_meta($product_id, '_sendmailsio_list_uid', $list_uid);
                echo '<div class="notice notice-success"><p>List mapping saved for product.</p></div>';
            } else {
                delete_post_meta($product_id, '_sendmailsio_list_uid');
                echo '<div class="notice notice-warning"><p>List mapping removed for product.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
        }
    }

    // Handle add custom field to list
    if (!empty($_POST['df_wc_sendmailsio_add_field']) && !empty($_POST['list_uid']) && !empty($_POST['product_id'])) {
        $list_uid = sanitize_text_field($_POST['list_uid']);
        $product_id = intval($_POST['product_id']);
        $api_key = get_option('df_wc_sendmailsio_api_key', '');
        $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
        if ($api_key && $list_uid) {
            $add_field_url = trailingslashit($api_endpoint) . 'lists/' . urlencode($list_uid) . '/add-field';
            $add_field_url = add_query_arg('api_token', $api_key, $add_field_url);
            $field_data = array(
                'type' => sanitize_text_field($_POST['field_type']),
                'label' => sanitize_text_field($_POST['field_label']),
                'tag' => sanitize_text_field($_POST['field_tag']),
            );
            if (!empty($_POST['field_default_value'])) {
                $field_data['default_value'] = sanitize_text_field($_POST['field_default_value']);
            }
            // Handle options for dropdown, multiselect, radio
            $type_upper = strtoupper(sanitize_text_field($_POST['field_type']));
            if (in_array($type_upper, array('DROPDOWN', 'MULTISELECT', 'RADIO')) && !empty($_POST['field_options'])) {
                // Send as comma-separated or array, depending on API
                $options_raw = trim($_POST['field_options']);
                $options = array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $options_raw)));
                if (!empty($options)) {
                    $field_data['options'] = $options;
                }
            }
            // Required and visible flags
            $field_data['required'] = !empty($_POST['field_required']) ? 1 : 0;
            $field_data['visible'] = isset($_POST['field_visible']) ? 1 : 0;
            $add_field_response = wp_remote_post($add_field_url, array(
                'headers' => array('Accept' => 'application/json'),
                'body' => $field_data,
                'timeout' => 15,
            ));
            if (is_wp_error($add_field_response)) {
                echo '<div class="notice notice-error"><p>API error: ' . esc_html($add_field_response->get_error_message()) . '</p></div>';
            } else {
                $code = wp_remote_retrieve_response_code($add_field_response);
                $body = wp_remote_retrieve_body($add_field_response);
                if ($code === 200) {
                    echo '<div class="notice notice-success"><p>Custom field added successfully.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to add field: ' . esc_html($body) . '</p></div>';
                }
            }
            // Fetch and display updated fields
            $list_api_url = trailingslashit($api_endpoint) . 'lists/' . urlencode($list_uid);
            $list_api_url = add_query_arg('api_token', $api_key, $list_api_url);
            $list_response = wp_remote_get($list_api_url, array('headers' => array('Accept' => 'application/json'), 'timeout' => 15));
            if (!is_wp_error($list_response) && wp_remote_retrieve_response_code($list_response) === 200) {
                $list_info = json_decode(wp_remote_retrieve_body($list_response), true);
                if (is_array($list_info)) {
                    echo '<fieldset style="border:1px solid #ccc;padding:8px;margin-top:16px;"><legend style="font-weight:bold;">List Fields</legend>';
                    echo '<div><strong>Fields:</strong></div>';
                    if (!empty($list_info['fields']) && is_array($list_info['fields'])) {
                        echo '<ul>';
                        foreach ($list_info['fields'] as $field) {
                            echo '<li><ul style="margin-bottom:8px;">';
                            echo '<li><strong>Label:</strong> ' . (isset($field['label']) ? esc_html($field['label']) : '<em>n/a</em>') . '</li>';
                            echo '<li><strong>Type:</strong> ' . (isset($field['type']) ? esc_html($field['type']) : '<em>n/a</em>') . '</li>';
                            echo '<li><strong>Tag:</strong> ' . (isset($field['tag']) ? esc_html($field['tag']) : '<em>n/a</em>') . '</li>';
                            echo '<li><strong>Required:</strong> ' . (isset($field['required']) && $field['required'] ? 'Yes' : 'No') . '</li>';
                            echo '<li><strong>Visible:</strong> ' . (isset($field['visible']) && $field['visible'] ? 'Yes' : 'No') . '</li>';
                            if (isset($field['default_value']) && $field['default_value'] !== '') {
                                echo '<li><strong>Default Value:</strong> ' . esc_html($field['default_value']) . '</li>';
                            }
                            if (isset($field['options']) && is_array($field['options']) && count($field['options'])) {
                                echo '<li><strong>Options:</strong> ' . esc_html(implode(', ', $field['options'])) . '</li>';
                            }
                            // Show any other properties
                            foreach ($field as $k => $v) {
                                if (in_array($k, array('label','type','tag','required','visible','default_value','options'))) continue;
                                if (is_array($v)) $v = json_encode($v);
                                echo '<li><strong>' . esc_html(ucwords(str_replace('_',' ',$k))) . ':</strong> ' . esc_html($v) . '</li>';
                            }
                            echo '</ul></li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<em>No fields found.</em>';
                    }
                    // Add custom field form
                    ?>
                    <form method="post" style="margin-top:12px;">
                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                        <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                        <label>Type
                            <select name="field_type" required>
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="datetime">Datetime</option>
                            </select>
                        </label>
                        <label>Label
                            <input type="text" name="field_label" required />
                        </label>
                        <label>Tag
                            <input type="text" name="field_tag" required />
                        </label>
                        <label>Default Value
                            <input type="text" name="field_default_value" />
                        </label>
                        <input type="submit" name="df_wc_sendmailsio_add_field" class="button" value="Add Field" />
                    </form>
                    <?php
                    echo '</fieldset>';
                }
            }
        }
    }

    // Handle create new list and assign
    if (!empty($_POST['df_wc_sendmailsio_create_list']) && !empty($_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        if (isset($_POST['df_wc_sendmailsio_create_list_nonce_' . $product_id]) && wp_verify_nonce($_POST['df_wc_sendmailsio_create_list_nonce_' . $product_id], 'df_wc_sendmailsio_create_list_' . $product_id)) {
            // Collect and sanitize fields
            $fields = array(
                'name' => sanitize_text_field($_POST['new_list_name']),
                'from_email' => sanitize_email($_POST['from_email']),
                'from_name' => sanitize_text_field($_POST['from_name']),
                'subscribe_confirmation' => 1,
                'send_welcome_email' => 1,
                'unsubscribe_notification' => 1,
            );
            // Optional fields: only include if not empty
            if (!empty($_POST['company'])) {
                $fields['contact[company]'] = sanitize_text_field($_POST['company']);
            }
            if (!empty($_POST['contact_email'])) {
                $fields['contact[email]'] = sanitize_email($_POST['contact_email']);
            }
            if (!empty($_POST['country_id'])) {
                $fields['contact[country_id]'] = sanitize_text_field($_POST['country_id']);
            }
            if (!empty($_POST['city'])) {
                $fields['contact[city]'] = sanitize_text_field($_POST['city']);
            }
            if (!empty($_POST['state'])) {
                $fields['contact[state]'] = sanitize_text_field($_POST['state']);
            }
            if (!empty($_POST['address_1'])) {
                $fields['contact[address_1]'] = sanitize_text_field($_POST['address_1']);
            }
            if (!empty($_POST['address_2'])) {
                $fields['contact[address_2]'] = sanitize_text_field($_POST['address_2']);
            }
            if (!empty($_POST['zip'])) {
                $fields['contact[zip]'] = sanitize_text_field($_POST['zip']);
            }
            if (!empty($_POST['phone'])) {
                $fields['contact[phone]'] = sanitize_text_field($_POST['phone']);
            }
            if (!empty($_POST['url'])) {
                $fields['contact[url]'] = esc_url_raw($_POST['url']);
            }
            $api_key = get_option('df_wc_sendmailsio_api_key', '');
            $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
            if ($api_key) {
                $url = trailingslashit($api_endpoint) . 'lists';
                $url = add_query_arg('api_token', $api_key, $url);
                $args = array(
                    'headers' => array('Accept' => 'application/json'),
                    'body' => $fields,
                    'timeout' => 20,
                );
                $response = wp_remote_post($url, $args);
                if (is_wp_error($response)) {
                    echo '<div class="notice notice-error"><p>API error: ' . esc_html($response->get_error_message()) . '</p></div>';
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    $new_uid = null;
                    if ($code === 200) {
                        if (isset($data['uid'])) {
                            $new_uid = $data['uid'];
                        } elseif (isset($data['data']['uid'])) {
                            $new_uid = $data['data']['uid'];
                        }
                    }
                    // If UID is not found but message indicates success, treat as success
                    $success_message = '';
                    if (!$new_uid && isset($data['message']) && stripos($data['message'], 'success') !== false) {
                        $success_message = $data['message'];
                        // Try to find the new list by name
                        $lists = df_wc_sendmailsio_get_all_lists();
                        if (is_array($lists)) {
                            foreach ($lists as $list) {
                                if (
                                    (isset($list['name']) && $list['name'] === $_POST['new_list_name']) ||
                                    (isset($list['list_name']) && $list['list_name'] === $_POST['new_list_name'])
                                ) {
                                    $new_uid = isset($list['uid']) ? $list['uid'] : (isset($list['id']) ? $list['id'] : null);
                                    break;
                                }
                            }
                        }
                    }
                    if ($new_uid) {
                        update_post_meta($product_id, '_sendmailsio_list_uid', $new_uid);
                        // Redirect to refresh the page and show the new mapping in the Current List column
                        wp_safe_redirect(add_query_arg(array('page' => 'df-wc-sendmailsio-product-mapping'), admin_url('admin.php')));
                        exit;
                    } elseif ($success_message) {
                        echo '<div class="notice notice-success"><p>' . esc_html($success_message) . '</p></div>';
                    } else {
                        $msg = isset($data['message']) ? $data['message'] : $body;
                        echo '<div class="notice notice-error"><p>Failed to create list: ' . esc_html($msg) . '</p></div>';
                    }
                }
            } else {
                echo '<div class="notice notice-error"><p>No API key set.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>WooCommerce Product to Sendmails.io List Mapping</h1>
        <p>Associate each WooCommerce product with a sendmails.io list. If a list does not exist, you can create one inline.</p>
        <?php
        $lists = df_wc_sendmailsio_get_all_lists();
        if (is_wp_error($lists)) {
            echo '<div class="notice notice-error"><p>' . esc_html($lists->get_error_message()) . '</p></div>';
        }
        ?>
        <style>
        .df-wc-sendmailsio-list-setup { max-width: 600px; min-width: 400px; width: 100%; }
        .df-wc-sendmailsio-list-setup input[type="text"],
        .df-wc-sendmailsio-list-setup input[type="email"],
        .df-wc-sendmailsio-list-setup input[type="url"] { width: 95%; min-width: 250px; }
        .df-wc-sendmailsio-current-list-col { width: 120px; max-width: 160px; }
        </style>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="df-wc-sendmailsio-current-list-col">Current List ID</th>
                    <th>Assigned List</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch all WooCommerce products (excluding variations)
                if (class_exists('WC_Product')) {
                    $args = array(
                        'post_type'      => 'product',
                        'post_status'    => 'publish',
                        'posts_per_page' => 50,
                        'fields'         => 'ids',
                    );
                    $products = get_posts($args);
                    foreach ($products as $product_id) {
                        // Exclude only product_variation post type (not variable products)
                        if (get_post_type($product_id) === 'product_variation') continue;
                        $product = wc_get_product($product_id);
                        if (!$product) continue;
                        // Show only parent products (simple or variable)
                        if ($product->is_type('variation')) continue;
                        $list_uid = get_post_meta($product_id, '_sendmailsio_list_uid', true);
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo get_edit_post_link($product_id); ?>" target="_blank">
                                    <?php echo esc_html($product->get_name()); ?>
                                </a>
                            </td>
                            <td>
                                <?php echo $list_uid ? esc_html($list_uid) : '<em>Not set</em>'; ?>
                            </td>
                            <td>
                                <?php if (!is_wp_error($lists) && is_array($lists)): ?>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('df_wc_sendmailsio_save_mapping_' . $product_id, 'df_wc_sendmailsio_nonce_' . $product_id); ?>
                                        <select name="sendmailsio_list_uid">
                                            <option value="">-- Select List --</option>
                                            <?php
                                            foreach ($lists as $list) {
                                                $uid = isset($list['uid']) ? $list['uid'] : (isset($list['id']) ? $list['id'] : '');
                                                $name = isset($list['name']) ? $list['name'] : (isset($list['list_name']) ? $list['list_name'] : '');
                                                if (!$uid || !$name) continue;
                                                $selected = ($list_uid === $uid) ? 'selected' : '';
                                                echo '<option value="' . esc_attr($uid) . '" ' . $selected . '>' . esc_html($name) . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                        <input type="submit" name="df_wc_sendmailsio_save_mapping" class="button" value="Save" />
                                    </form>
                                    <?php if ($list_uid): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                            <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                                            <input type="submit" name="df_wc_sendmailsio_show_fields" class="button" value="Manage List Fields" />
                                        </form>
                                    <?php endif; ?>
                                    <br>
                                    <details>
                                        <summary style="cursor:pointer;">Create new list</summary>
                                        <form method="post" style="margin-top:8px;">
                                            <?php wp_nonce_field('df_wc_sendmailsio_create_list_' . $product_id, 'df_wc_sendmailsio_create_list_nonce_' . $product_id); ?>
                                            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                            <fieldset class="df-wc-sendmailsio-list-setup" style="border:1px solid #ccc;padding:8px;margin-bottom:8px;">
                                                <legend style="font-weight:bold;">List Setup</legend>
                                                <label>List Name*<br>
                                                    <input type="text" name="new_list_name" placeholder="List Name" required value="<?php echo esc_attr($product->get_name()); ?>" />
                                                </label><br>
                                                <label>From Email*<br>
                                                    <input type="email" name="from_email" placeholder="From Email" required />
                                                </label><br>
                                                <label>From Name*<br>
                                                    <input type="text" name="from_name" placeholder="From Name" required />
                                                </label><br>
                                                <label>Company<br>
                                                    <input type="text" name="company" placeholder="Company" />
                                                </label><br>
                                                <label>Contact Email<br>
                                                    <input type="text" name="contact_email" placeholder="Contact Email" />
                                                </label><br>
                                                <label>Country ID<br>
                                                    <input type="text" name="country_id" placeholder="Country ID" />
                                                </label><br>
                                                <label>City<br>
                                                    <input type="text" name="city" placeholder="City" />
                                                </label><br>
                                                <label>State<br>
                                                    <input type="text" name="state" placeholder="State" />
                                                </label><br>
                                                <label>Address 1<br>
                                                    <input type="text" name="address_1" placeholder="Address 1" />
                                                </label><br>
                                                <label>Address 2<br>
                                                    <input type="text" name="address_2" placeholder="Address 2" />
                                                </label><br>
                                                <label>Zip<br>
                                                    <input type="text" name="zip" placeholder="Zip" />
                                                </label><br>
                                                <label>Phone<br>
                                                    <input type="text" name="phone" placeholder="Phone" />
                                                </label><br>
                                                <label>Website<br>
                                                    <input type="url" name="url" placeholder="Website" />
                                                </label>
                                            </fieldset>
                                            <input type="hidden" name="subscribe_confirmation" value="1" />
                                            <input type="hidden" name="send_welcome_email" value="1" />
                                            <input type="hidden" name="unsubscribe_notification" value="1" />
                                            <input type="submit" name="df_wc_sendmailsio_create_list" class="button" value="Create List" />
                                        </form>
                                        <!-- List Fields section will be rendered after list creation -->
                                    </details>
                                    <?php
                                    // Show List Fields section if requested
                                    if (!empty($_POST['df_wc_sendmailsio_show_fields']) && !empty($_POST['list_uid']) && intval($_POST['product_id']) === $product_id) {
                                        $api_key = get_option('df_wc_sendmailsio_api_key', '');
                                        $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
                                        $list_api_url = trailingslashit($api_endpoint) . 'lists/' . urlencode($list_uid);
                                        $list_api_url = add_query_arg('api_token', $api_key, $list_api_url);
                                        $list_response = wp_remote_get($list_api_url, array('headers' => array('Accept' => 'application/json'), 'timeout' => 15));
                                        if (!is_wp_error($list_response) && wp_remote_retrieve_response_code($list_response) === 200) {
                                            $list_info = json_decode(wp_remote_retrieve_body($list_response), true);
                                            if (is_array($list_info)) {
                                                echo '<fieldset style="border:1px solid #ccc;padding:8px;margin-top:16px;"><legend style="font-weight:bold;">List Fields</legend>';
                                                echo '<div><strong>Fields:</strong></div>';
                                                // Debug printout of the list JSON fields (hidden by default, toggle with button)
                                                ?>
                                                <button type="button" onclick="var dbg=document.getElementById('df-wc-sendmailsio-json-debug-<?php echo esc_attr($list_uid); ?>');if(dbg.style.display==='none'){dbg.style.display='block';this.textContent='Hide API JSON Debug';}else{dbg.style.display='none';this.textContent='Show API JSON Debug';}">Show API JSON Debug</button>
                                                <div id="df-wc-sendmailsio-json-debug-<?php echo esc_attr($list_uid); ?>" style="display:none;">
                                                    <pre style="background:#f8f8f8;border:1px solid #eee;padding:4px;font-size:11px;">API fields debug: <?php echo esc_html(print_r($list_info, true)); ?></pre>
                                                </div>
                                                <?php
                                                $fields_array = null;
                                                if (isset($list_info['fields']) && is_array($list_info['fields'])) {
                                                    $fields_array = $list_info['fields'];
                                                } elseif (isset($list_info['data']['fields']) && is_array($list_info['data']['fields'])) {
                                                    $fields_array = $list_info['data']['fields'];
                                                } elseif (isset($list_info['list']['fields']) && is_array($list_info['list']['fields'])) {
                                                    $fields_array = $list_info['list']['fields'];
                                                }
                                                if (!empty($fields_array)) {
                                                    echo '<ul>';
                                                    foreach ($fields_array as $field) {
                                                        $label = isset($field['label']) ? esc_html($field['label']) : '';
                                                        $tag = isset($field['tag']) ? esc_html($field['tag']) : '';
                                                        $type = isset($field['type']) ? esc_html($field['type']) : '';
                                                        echo "<li><strong>$label</strong> ($tag) [$type]</li>";
                                                    }
                                                    echo '</ul>';
                                                } else {
                                                    echo '<em>No fields found.</em>';
                                                }
                                                // Add WooCommerce customer fields section
                                                $wc_fields = array(
                                                    'billing_first_name' => array('label' => 'First Name', 'tag' => 'FIRST_NAME', 'type' => 'string'),
                                                    'billing_last_name' => array('label' => 'Last Name', 'tag' => 'LAST_NAME', 'type' => 'string'),
                                                    'billing_email' => array('label' => 'Email', 'tag' => 'EMAIL', 'type' => 'string'),
                                                    'billing_phone' => array('label' => 'Phone Number', 'tag' => 'PHONENUMBER', 'type' => 'string'),
                                                    'billing_company' => array('label' => 'Company', 'tag' => 'BILLING_COMPANY', 'type' => 'string'),
                                                    'billing_address_1' => array('label' => 'Address 1', 'tag' => 'BILLING_ADDRESS_1', 'type' => 'string'),
                                                    'billing_address_2' => array('label' => 'Address 2', 'tag' => 'BILLING_ADDRESS_2', 'type' => 'string'),
                                                    'billing_city' => array('label' => 'City', 'tag' => 'BILLING_CITY', 'type' => 'string'),
                                                    'billing_state' => array('label' => 'State', 'tag' => 'BILLING_STATE', 'type' => 'string'),
                                                    'billing_postcode' => array('label' => 'Postcode', 'tag' => 'BILLING_POSTCODE', 'type' => 'string'),
                                                    'billing_country' => array('label' => 'Country', 'tag' => 'BILLING_COUNTRY', 'type' => 'string'),
                                                    'shipping_first_name' => array('label' => 'Shipping First Name', 'tag' => 'SHIPPING_FIRST_NAME', 'type' => 'string'),
                                                    'shipping_last_name' => array('label' => 'Shipping Last Name', 'tag' => 'SHIPPING_LAST_NAME', 'type' => 'string'),
                                                    'shipping_company' => array('label' => 'Shipping Company', 'tag' => 'SHIPPING_COMPANY', 'type' => 'string'),
                                                    'shipping_address_1' => array('label' => 'Shipping Address 1', 'tag' => 'SHIPPING_ADDRESS_1', 'type' => 'string'),
                                                    'shipping_address_2' => array('label' => 'Shipping Address 2', 'tag' => 'SHIPPING_ADDRESS_2', 'type' => 'string'),
                                                    'shipping_city' => array('label' => 'Shipping City', 'tag' => 'SHIPPING_CITY', 'type' => 'string'),
                                                    'shipping_state' => array('label' => 'Shipping State', 'tag' => 'SHIPPING_STATE', 'type' => 'string'),
                                                    'shipping_postcode' => array('label' => 'Shipping Postcode', 'tag' => 'SHIPPING_POSTCODE', 'type' => 'string'),
                                                    'shipping_country' => array('label' => 'Shipping Country', 'tag' => 'SHIPPING_COUNTRY', 'type' => 'string'),
                                                );
                                                // Handle add WooCommerce fields form submission
                                                if (!empty($_POST['df_wc_sendmailsio_add_wc_fields']) && !empty($_POST['product_id']) && !empty($_POST['list_uid'])) {
                                                    $product_id = intval($_POST['product_id']);
                                                    $list_uid = sanitize_text_field($_POST['list_uid']);
                                                    $api_key = get_option('df_wc_sendmailsio_api_key', '');
                                                    $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
                                                    $added = array();
                                                    $errors = array();
                                                    if ($api_key && $list_uid && !empty($_POST['wc_fields'])) {
                                                        foreach ($_POST['wc_fields'] as $field_key) {
                                                            if (!isset($wc_fields[$field_key])) continue;
                                                            $f = $wc_fields[$field_key];
                                                            $field_data = array(
                                                                'type' => $f['type'],
                                                                'label' => $f['label'],
                                                                'tag' => $f['tag'],
                                                                'required' => !empty($_POST['wc_field_required'][$field_key]) ? 1 : 0,
                                                                'visible' => isset($_POST['wc_field_visible'][$field_key]) ? 1 : 0,
                                                            );
                                                            $add_field_url = trailingslashit($api_endpoint) . 'lists/' . urlencode($list_uid) . '/add-field';
                                                            $add_field_url = add_query_arg('api_token', $api_key, $add_field_url);
                                                            $add_field_response = wp_remote_post($add_field_url, array(
                                                                'headers' => array('Accept' => 'application/json'),
                                                                'body' => $field_data,
                                                                'timeout' => 15,
                                                            ));
                                                            if (!is_wp_error($add_field_response) && wp_remote_retrieve_response_code($add_field_response) === 200) {
                                                                $added[] = $f['label'];
                                                            } else {
                                                                $errors[] = $f['label'];
                                                            }
                                                        }
                                                        if ($added) {
                                                            echo '<div class="notice notice-success"><p>Added: ' . esc_html(implode(', ', $added)) . '</p></div>';
                                                        }
                                                        if ($errors) {
                                                            echo '<div class="notice notice-error"><p>Failed: ' . esc_html(implode(', ', $errors)) . '</p></div>';
                                                        }
                                                    }
                                                }
                                                // Add WooCommerce fields form
                                                ?>
                                                <form method="post" style="margin-bottom:18px; border:1px solid #ccc; padding:10px; background:#f9f9f9;">
                                                    <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                                    <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                                                    <strong>Add Fields from WooCommerce Customer Data</strong>
                                                    <?php
                                                    // Fetch WooCommerce customers for sample data
                                                    $customer_users = get_users(array('role' => 'customer', 'number' => 100, 'fields' => array('ID')));
                                                    $sample_customer_index = isset($_POST['sample_customer_index']) ? intval($_POST['sample_customer_index']) : 0;
                                                    $sample_customer_count = count($customer_users);
                                                    if ($sample_customer_count > 0) {
                                                        if ($sample_customer_index < 0) $sample_customer_index = 0;
                                                        if ($sample_customer_index >= $sample_customer_count) $sample_customer_index = $sample_customer_count - 1;
                                                        $sample_customer_id = $customer_users[$sample_customer_index]->ID;
                                                        $sample_customer = new WC_Customer($sample_customer_id);
                                                    } else {
                                                        $sample_customer = null;
                                                    }
                                                    ?>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                                        <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                                                        <input type="hidden" name="sample_customer_index" value="<?php echo max(0, $sample_customer_index - 1); ?>" />
                                                        <button type="submit" name="df_wc_sendmailsio_sample_prev" class="button" <?php if ($sample_customer_index <= 0) echo 'disabled'; ?>>Previous</button>
                                                    </form>
                                                    <span style="margin:0 8px;">Sample Customer <?php echo $sample_customer_count > 0 ? ($sample_customer_index + 1) . ' of ' . $sample_customer_count : 'N/A'; ?></span>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                                        <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                                                        <input type="hidden" name="sample_customer_index" value="<?php echo min($sample_customer_count - 1, $sample_customer_index + 1); ?>" />
                                                        <button type="submit" name="df_wc_sendmailsio_sample_next" class="button" <?php if ($sample_customer_index >= $sample_customer_count - 1) echo 'disabled'; ?>>Next</button>
                                                    </form>
                                                    <table style="width:100%;margin-top:8px;">
                                                        <tr>
                                                            <th style="text-align:left;">Select</th>
                                                            <th style="text-align:left;">Field</th>
                                                            <th>Required</th>
                                                            <th>Visible</th>
                                                            <th>Sample Data</th>
                                                        </tr>
                                                        <?php foreach ($wc_fields as $key => $f): 
                                                            $is_core = in_array($key, array('billing_email', 'billing_first_name', 'billing_last_name'));
                                                            $sample_value = '';
                                                            if ($sample_customer) {
                                                                // Map field key to WC_Customer getter
                                                                switch ($key) {
                                                                    case 'billing_first_name': $sample_value = $sample_customer->get_billing_first_name(); break;
                                                                    case 'billing_last_name': $sample_value = $sample_customer->get_billing_last_name(); break;
                                                                    case 'billing_email': $sample_value = $sample_customer->get_billing_email(); break;
                                                                    case 'billing_phone': $sample_value = $sample_customer->get_billing_phone(); break;
                                                                    case 'billing_company': $sample_value = $sample_customer->get_billing_company(); break;
                                                                    case 'billing_address_1': $sample_value = $sample_customer->get_billing_address_1(); break;
                                                                    case 'billing_address_2': $sample_value = $sample_customer->get_billing_address_2(); break;
                                                                    case 'billing_city': $sample_value = $sample_customer->get_billing_city(); break;
                                                                    case 'billing_state': $sample_value = $sample_customer->get_billing_state(); break;
                                                                    case 'billing_postcode': $sample_value = $sample_customer->get_billing_postcode(); break;
                                                                    case 'billing_country': $sample_value = $sample_customer->get_billing_country(); break;
                                                                    case 'shipping_first_name': $sample_value = $sample_customer->get_shipping_first_name(); break;
                                                                    case 'shipping_last_name': $sample_value = $sample_customer->get_shipping_last_name(); break;
                                                                    case 'shipping_company': $sample_value = $sample_customer->get_shipping_company(); break;
                                                                    case 'shipping_address_1': $sample_value = $sample_customer->get_shipping_address_1(); break;
                                                                    case 'shipping_address_2': $sample_value = $sample_customer->get_shipping_address_2(); break;
                                                                    case 'shipping_city': $sample_value = $sample_customer->get_shipping_city(); break;
                                                                    case 'shipping_state': $sample_value = $sample_customer->get_shipping_state(); break;
                                                                    case 'shipping_postcode': $sample_value = $sample_customer->get_shipping_postcode(); break;
                                                                    case 'shipping_country': $sample_value = $sample_customer->get_shipping_country(); break;
                                                                }
                                                            }
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <input type="checkbox" name="wc_fields[]" value="<?php echo esc_attr($key); ?>" id="wc_field_<?php echo esc_attr($key); ?>"
                                                                <?php if ($is_core): ?> checked disabled <?php endif; ?> />
                                                            </td>
                                                            <td>
                                                                <label for="wc_field_<?php echo esc_attr($key); ?>"><?php echo esc_html($f['label']); ?></label>
                                                                <?php if ($is_core): ?>
                                                                    <span style="color:#888;font-size:11px;">(Required by Sendmails.io, mapped to WooCommerce)</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td style="text-align:center;">
                                                                <input type="checkbox" name="wc_field_required[<?php echo esc_attr($key); ?>]" value="1"
                                                                <?php if ($is_core): ?> checked disabled <?php endif; ?> />
                                                            </td>
                                                            <td style="text-align:center;">
                                                                <input type="checkbox" name="wc_field_visible[<?php echo esc_attr($key); ?>]" value="1" 
                                                                <?php if ($is_core): ?> checked disabled <?php else: ?> checked <?php endif; ?> />
                                                            </td>
                                                            <td style="text-align:center;">
                                                                <?php echo $sample_value !== '' ? esc_html($sample_value) : '<span style="color:#aaa;">(empty)</span>'; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </table>
                                                    <input type="submit" name="df_wc_sendmailsio_add_wc_fields" class="button" value="Add Selected Fields" style="margin-top:8px;" />
                                                </form>
                                                <?php
                                                // Add custom field form
                                                ?>
                                                <form method="post" style="margin-top:12px;" id="df-wc-sendmailsio-add-field-form">
                                                    <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                                    <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                                                    <label>Type
                                                        <select name="field_type" id="df-wc-sendmailsio-field-type" required onchange="dfWcSendmailsioToggleOptions(this.value)">
                                                            <option value="EMAIL">Email</option>
                                                            <option value="FIRST_NAME">First Name</option>
                                                            <option value="LAST_NAME">Last Name</option>
                                                            <option value="TEXT">Text</option>
                                                            <option value="NUMBER">Number</option>
                                                            <option value="DROPDOWN">Dropdown</option>
                                                            <option value="MULTISELECT">Multiselect</option>
                                                            <option value="CHECKBOX">Checkbox</option>
                                                            <option value="RADIO">Radio</option>
                                                            <option value="DATE">Date</option>
                                                            <option value="DATETIME">Datetime</option>
                                                            <option value="TEXTAREA">Textarea</option>
                                                            <option value="PHONENUMBER">Phone Number</option>
                                                        </select>
                                                    </label>
                                                    <label>Label
                                                        <input type="text" name="field_label" required />
                                                    </label>
                                                    <label>Tag
                                                        <input type="text" name="field_tag" required />
                                                    </label>
                                                    <label>Default Value
                                                        <input type="text" name="field_default_value" />
                                                    </label>
                                                    <div id="df-wc-sendmailsio-options-row" style="display:none;">
                                                        <label>Options<br>
                                                            <textarea name="field_options" rows="2" placeholder="Enter one option per line"></textarea>
                                                        </label>
                                                    </div>
                                                    <label>
                                                        <input type="checkbox" name="field_required" value="1" /> Required
                                                    </label>
                                                    <label>
                                                        <input type="checkbox" name="field_visible" value="1" checked /> Visible
                                                    </label>
                                                    <input type="submit" name="df_wc_sendmailsio_add_field" class="button" value="Add Field" />
                                                </form>
                                                <script>
                                                function dfWcSendmailsioToggleOptions(type) {
                                                    var optRow = document.getElementById('df-wc-sendmailsio-options-row');
                                                    if (['DROPDOWN','MULTISELECT','RADIO'].indexOf(type) !== -1) {
                                                        optRow.style.display = '';
                                                    } else {
                                                        optRow.style.display = 'none';
                                                    }
                                                }
                                                document.addEventListener('DOMContentLoaded', function() {
                                                    var sel = document.getElementById('df-wc-sendmailsio-field-type');
                                                    if (sel) dfWcSendmailsioToggleOptions(sel.value);
                                                });
                                                </script>
                                                <?php
                                                echo '</fieldset>';
                                            }
                                        }
                                    }
                                    ?>
                                <?php else: ?>
                                    <em>Cannot load lists</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    echo '<tr><td colspan="3">WooCommerce is not active.</td></tr>';
                }
                ?>
            </tbody>
        </table>
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
