<?php
/*
Plugin Name: woo-sendmailsio
Description: Integrates WooCommerce products with sendmails.io mailing lists.
Version: 0.14
Author: dataforge
GitHub Plugin URI: https://github.com/dataforge/woo-sendmailsio
Update URI: https://video.dataforge.us/wp-json/git-updater/v1/update/?key=40d21d0bae413cf7b77b0219c19047c6
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_action('admin_menu', 'df_wc_sendmailsio_add_admin_menu');
function df_wc_sendmailsio_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'Woo SendmailsIO',
        'Woo SendmailsIO',
        'manage_options',
        'df-wc-sendmailsio',
        'df_wc_sendmailsio_settings_page'
    );
    add_submenu_page(
        'woocommerce',
        'Product List Mapping',
        'Product List Mapping',
        'read', // all logged-in users
        'df-wc-sendmailsio-product-mapping',
        'df_wc_sendmailsio_product_mapping_page'
    );
}
/**
 * Handle adding a custom field to a list.
 */
function df_wc_sendmailsio_settings_page() {
    ?>
    <div class="wrap">
        <h1>Woo SendmailsIO</h1>
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
 * Handle saving the product to list mapping.
 */
function df_wc_sendmailsio_handle_save_mapping() {
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
}

/**
 * Handle creating a new list and assigning it to a product.
 */
function df_wc_sendmailsio_handle_create_list() {
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
}

/**
 * Handle adding a custom field to a list.
 */
function df_wc_sendmailsio_handle_add_field() {
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
                $options_raw = trim($_POST['field_options']);
                $options = array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $options_raw)));
                if (!empty($options)) {
                    $field_data['options'] = $options;
                }
            }
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
}

/**
 * Product List Mapping Page
 */
function df_wc_sendmailsio_product_mapping_page() {
    global $wpdb;
    // Handle mapping save
    df_wc_sendmailsio_handle_save_mapping();

    // Handle add custom field to list
    df_wc_sendmailsio_handle_add_field();

    // Handle create new list and assign
    df_wc_sendmailsio_handle_create_list();
    ?>
    <div class="wrap">
        <h1>Woo SendmailsIO - Product List Mapping</h1>
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
                                        <button type="button" class="button" onclick="toggleListFields('<?php echo esc_attr($product_id); ?>', '<?php echo esc_attr($list_uid); ?>')">Manage List Fields</button>
                                        <br>
                                        <button type="button" class="button button-secondary" id="sync-past-customers-<?php echo esc_attr($product_id); ?>" onclick="syncPastCustomers('<?php echo esc_attr($product_id); ?>', '<?php echo esc_attr($list_uid); ?>')">Sync Past Customers</button>
                                        <div id="sync-progress-<?php echo esc_attr($product_id); ?>" style="margin-top:5px;font-size:12px;color:#666;display:none;"></div>
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
                                    <div id="list-fields-<?php echo esc_attr($product_id); ?>" style="display:none;">
                                    <?php
                                    // Show List Fields section - now loaded via JavaScript
                                    if ($list_uid) {
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
                                                    'billing_first_name' => array('label' => 'First Name', 'tag' => 'FIRST_NAME', 'type' => 'text'),
                                                    'billing_last_name' => array('label' => 'Last Name', 'tag' => 'LAST_NAME', 'type' => 'text'),
                                                    'billing_email' => array('label' => 'Email', 'tag' => 'EMAIL', 'type' => 'text'),
                                                    'billing_phone' => array('label' => 'Phone Number', 'tag' => 'PHONE', 'type' => 'text'),
                                                    'billing_company' => array('label' => 'Company', 'tag' => 'BILLING_COMPANY', 'type' => 'text'),
                                                    'billing_address_1' => array('label' => 'Address 1', 'tag' => 'BILLING_ADDRESS_1', 'type' => 'text'),
                                                    'billing_address_2' => array('label' => 'Address 2', 'tag' => 'BILLING_ADDRESS_2', 'type' => 'text'),
                                                    'billing_city' => array('label' => 'City', 'tag' => 'BILLING_CITY', 'type' => 'text'),
                                                    'billing_state' => array('label' => 'State', 'tag' => 'BILLING_STATE', 'type' => 'text'),
                                                    'billing_postcode' => array('label' => 'Postcode', 'tag' => 'BILLING_POSTCODE', 'type' => 'text'),
                                                    'billing_country' => array('label' => 'Country', 'tag' => 'BILLING_COUNTRY', 'type' => 'text'),
                                                    'shipping_first_name' => array('label' => 'Shipping First Name', 'tag' => 'SHIPPING_FIRST_NAME', 'type' => 'text'),
                                                    'shipping_last_name' => array('label' => 'Shipping Last Name', 'tag' => 'SHIPPING_LAST_NAME', 'type' => 'text'),
                                                    'shipping_company' => array('label' => 'Shipping Company', 'tag' => 'SHIPPING_COMPANY', 'type' => 'text'),
                                                    'shipping_address_1' => array('label' => 'Shipping Address 1', 'tag' => 'SHIPPING_ADDRESS_1', 'type' => 'text'),
                                                    'shipping_address_2' => array('label' => 'Shipping Address 2', 'tag' => 'SHIPPING_ADDRESS_2', 'type' => 'text'),
                                                    'shipping_city' => array('label' => 'Shipping City', 'tag' => 'SHIPPING_CITY', 'type' => 'text'),
                                                    'shipping_state' => array('label' => 'Shipping State', 'tag' => 'SHIPPING_STATE', 'type' => 'text'),
                                                    'shipping_postcode' => array('label' => 'Shipping Postcode', 'tag' => 'SHIPPING_POSTCODE', 'type' => 'text'),
                                                    'shipping_country' => array('label' => 'Shipping Country', 'tag' => 'SHIPPING_COUNTRY', 'type' => 'text'),
                                                );
                                                // Handle add WooCommerce fields form submission
                                                if (!empty($_POST['df_wc_sendmailsio_add_wc_fields']) && !empty($_POST['product_id']) && !empty($_POST['list_uid'])) {
                                                    $product_id = intval($_POST['product_id']);
                                                    $list_uid = sanitize_text_field($_POST['list_uid']);
                                                    $api_key = get_option('df_wc_sendmailsio_api_key', '');
                                                    $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
                                                    $added = array();
                                                    $errors = array();
                                                    $skipped = array();
                                                    if ($api_key && $list_uid && !empty($_POST['wc_fields'])) {
                                                        foreach ($_POST['wc_fields'] as $field_key) {
                                                            if (!isset($wc_fields[$field_key])) continue;
                                                            $f = $wc_fields[$field_key];
                                                            
                                                            // Check if field already exists in the list
                                                            $field_already_exists = false;
                                                            if (isset($list_info['list']['fields']) && is_array($list_info['list']['fields'])) {
                                                                foreach ($list_info['list']['fields'] as $existing_field) {
                                                                    if (isset($existing_field['tag']) && $existing_field['tag'] === $f['tag']) {
                                                                        $field_already_exists = true;
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                            
                                                            // Skip if field already exists
                                                            if ($field_already_exists) {
                                                                $skipped[] = $f['label'];
                                                                continue;
                                                            }
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
                                                        if ($skipped) {
                                                            echo '<div class="notice notice-info"><p>Skipped (already exist): ' . esc_html(implode(', ', $skipped)) . '</p></div>';
                                                        }
                                                    }
                                                }
                                                // Add WooCommerce fields form
                                                ?>
                                                <form method="post" style="margin-bottom:18px; border:1px solid #ccc; padding:10px; background:#f9f9f9;" id="df-wc-sendmailsio-sample-form">
                                                    <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
                                                    <input type="hidden" name="list_uid" value="<?php echo esc_attr($list_uid); ?>" />
                                                    <input type="hidden" name="df_wc_sendmailsio_show_fields" value="1" /> <!-- Ensure the "Manage List Fields" section remains open -->
                                                    <strong>Add Fields from WooCommerce Customer Data</strong>
                                                    <?php
                                                    // Fetch WooCommerce customers for sample data
                                                    global $wpdb;
                                                    $customer_samples = array();
                                                    // Use wc_get_orders() to fetch the most recent 100 WooCommerce orders
                                                    $orders_wc_query = wc_get_orders(array(
                                                        'limit' => 100,
                                                        'orderby' => 'date',
                                                        'order' => 'DESC',
                                                        'return' => 'objects', // Return WC_Order objects
                                                        'status' => 'any', // Get orders of any status
                                                    ));

                                                    $order_ids_wc_query = array();
                                                    foreach ($orders_wc_query as $order_obj) {
                                                        $order_ids_wc_query[] = $order_obj->get_id();
                                                    }

                                                    // Use the found order IDs for navigation
                                                    $customer_samples = array();
                                                    $order_ids_to_use = $order_ids_wc_query;
                                                    foreach ($order_ids_to_use as $oid) {
                                                        $order = wc_get_order($oid);
                                                        if (!$order) continue;
                                                        $customer_samples[] = array(
                                                            'order_id' => $oid,
                                                            'order' => $order
                                                        );
                                                    }
                                                    $sample_customer_index = isset($_POST['sample_customer_index']) ? intval($_POST['sample_customer_index']) : 0;

                                                    // Handle navigation for sample customers
                                                    if (isset($_POST['df_wc_sendmailsio_sample_next'])) {
                                                        $sample_customer_index++;
                                                    } elseif (isset($_POST['df_wc_sendmailsio_sample_prev'])) {
                                                        $sample_customer_index--;
                                                    }

                                                    $sample_customer_count = count($customer_samples);
                                                    if ($sample_customer_count > 0) {
                                                        // Ensure index stays within bounds
                                                        if ($sample_customer_index < 0) $sample_customer_index = 0;
                                                        if ($sample_customer_index >= $sample_customer_count) $sample_customer_index = $sample_customer_count - 1;
                                                        $sample = $customer_samples[$sample_customer_index];
                                                        $sample_order = $sample['order'];
                                                        $sample_customer = null; // not used in this mode
                                                    } else {
                                                        $sample_customer = null;
                                                        $sample_order = null;
                                                    }
                                                    ?>
                                                    <div style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                                        <button type="button" id="df-wc-sendmailsio-sample-prev" class="button" style="min-width:70px;" <?php if ($sample_customer_index <= 0) echo 'disabled'; ?>>Previous</button>
                                                        <span id="df-wc-sendmailsio-sample-count">Sample Customer <?php echo $sample_customer_count > 0 ? ($sample_customer_index + 1) . ' of ' . $sample_customer_count : 'N/A'; ?></span>
                                                        <button type="button" id="df-wc-sendmailsio-sample-next" class="button" style="min-width:70px;" <?php if ($sample_customer_index >= $sample_customer_count - 1) echo 'disabled'; ?>>Next</button>
                                                        <input type="hidden" name="sample_customer_index" id="df-wc-sendmailsio-current-sample-index" value="<?php echo esc_attr($sample_customer_index); ?>" />
                                                    </div>
                                                    <table style="width:100%;margin-top:8px;">
                                                        <thead>
                                                            <tr>
                                                                <th style="text-align:left;">Select</th>
                                                                <th style="text-align:left;">Field</th>
                                                                <th>Required</th>
                                                                <th>Visible</th>
                                                                <th>Sample Data</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="df-wc-sendmailsio-sample-table-body">
                                                        <?php foreach ($wc_fields as $key => $f): 
                                                            $is_core = in_array($key, array('billing_email', 'billing_first_name', 'billing_last_name'));
                                                            // Check if this field already exists in the SendMails.io list
                                                            $field_exists = false;
                                                            if (isset($list_info['list']['fields']) && is_array($list_info['list']['fields'])) {
                                                                foreach ($list_info['list']['fields'] as $existing_field) {
                                                                    if (isset($existing_field['tag']) && $existing_field['tag'] === $f['tag']) {
                                                                        $field_exists = true;
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                            $sample_value = '';
                                                            if ($sample_order) {
                                                                // Always use WC_Order getter for all fields
                                                                switch ($key) {
                                                                    case 'billing_first_name': $sample_value = $sample_order->get_billing_first_name(); break;
                                                                    case 'billing_last_name': $sample_value = $sample_order->get_billing_last_name(); break;
                                                                    case 'billing_email': $sample_value = $sample_order->get_billing_email(); break;
                                                                    case 'billing_phone': $sample_value = $sample_order->get_billing_phone(); break;
                                                                    case 'billing_company': $sample_value = $sample_order->get_billing_company(); break;
                                                                    case 'billing_address_1': $sample_value = $sample_order->get_billing_address_1(); break;
                                                                    case 'billing_address_2': $sample_value = $sample_order->get_billing_address_2(); break;
                                                                    case 'billing_city': $sample_value = $sample_order->get_billing_city(); break;
                                                                    case 'billing_state': $sample_value = $sample_order->get_billing_state(); break;
                                                                    case 'billing_postcode': $sample_value = $sample_order->get_billing_postcode(); break;
                                                                    case 'billing_country': $sample_value = $sample_order->get_billing_country(); break;
                                                                    case 'shipping_first_name': $sample_value = $sample_order->get_shipping_first_name(); break;
                                                                    case 'shipping_last_name': $sample_value = $sample_order->get_shipping_last_name(); break;
                                                                    case 'shipping_company': $sample_value = $sample_order->get_shipping_company(); break;
                                                                    case 'shipping_address_1': $sample_value = $sample_order->get_shipping_address_1(); break;
                                                                    case 'shipping_address_2': $sample_value = $sample_order->get_shipping_address_2(); break;
                                                                    case 'shipping_city': $sample_value = $sample_order->get_shipping_city(); break;
                                                                    case 'shipping_state': $sample_value = $sample_order->get_shipping_state(); break;
                                                                    case 'shipping_postcode': $sample_value = $sample_order->get_shipping_postcode(); break;
                                                                    case 'shipping_country': $sample_value = $sample_order->get_shipping_country(); break;
                                                                }
                                                            }
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <input type="checkbox" name="wc_fields[]" value="<?php echo esc_attr($key); ?>" id="wc_field_<?php echo esc_attr($key); ?>"
                                                                <?php if ($is_core): ?> checked disabled <?php elseif ($field_exists): ?> checked <?php endif; ?> />
                                                            </td>
                                                            <td>
                                                                <label for="wc_field_<?php echo esc_attr($key); ?>"><?php echo esc_html($f['label']); ?></label>
                                                                <?php if ($is_core): ?>
                                                                    <span style="color:#888;font-size:11px;">(Required by Sendmails.io, mapped to WooCommerce)</span>
                                                                <?php elseif ($field_exists): ?>
                                                                    <span style="color:#080;font-size:11px;">(Already exists in list)</span>
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
                                                        </tbody>
                                                    </table>
                                                    <input type="submit" name="df_wc_sendmailsio_add_wc_fields" class="button" value="Add New Selected Fields" style="margin-top:8px;" />
                                                    <p style="font-size:12px;color:#666;margin-top:8px;"><strong>Note:</strong> Fields that already exist in the list cannot be removed via this interface. Unchecking existing fields will not delete them from your SendMails.io list.</p>
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

                                                    // AJAX for sample customer navigation
                                                    const sampleForm = document.getElementById('df-wc-sendmailsio-sample-form');
                                                    const prevButton = document.getElementById('df-wc-sendmailsio-sample-prev');
                                                    const nextButton = document.getElementById('df-wc-sendmailsio-sample-next');
                                                    const sampleIndexInput = document.getElementById('df-wc-sendmailsio-current-sample-index');
                                                    const sampleCountSpan = document.getElementById('df-wc-sendmailsio-sample-count');
                                                    const sampleTableBody = document.getElementById('df-wc-sendmailsio-sample-table-body');

                                                    if (sampleForm && prevButton && nextButton && sampleIndexInput && sampleCountSpan && sampleTableBody) {
                                                        const productId = sampleForm.querySelector('input[name="product_id"]').value;
                                                        const listUid = sampleForm.querySelector('input[name="list_uid"]').value;

                                                        function updateSampleCustomer(newIndex) {
                                                            const data = new FormData();
                                                            data.append('action', 'df_wc_sendmailsio_get_sample_customer');
                                                            data.append('product_id', productId);
                                                            data.append('list_uid', listUid); // Pass list_uid as well, though not strictly used by AJAX handler for customer data
                                                            data.append('sample_customer_index', newIndex);

                                                            fetch(ajaxurl, {
                                                                method: 'POST',
                                                                body: data
                                                            })
                                                            .then(response => response.json())
                                                            .then(result => {
                                                                if (result.success) {
                                                                    const responseData = result.data;
                                                                    sampleIndexInput.value = responseData.sample_customer_index;
                                                                    sampleCountSpan.textContent = `Sample Customer ${responseData.sample_customer_index + 1} of ${responseData.sample_customer_count}`;
                                                                    sampleTableBody.innerHTML = responseData.sample_data_html;

                                                                    // Update button disabled states
                                                                    prevButton.disabled = (responseData.sample_customer_index <= 0);
                                                                    nextButton.disabled = (responseData.sample_customer_index >= responseData.sample_customer_count - 1);
                                                                } else {
                                                                    console.error('AJAX Error:', result.data);
                                                                }
                                                            })
                                                            .catch(error => {
                                                                console.error('Fetch Error:', error);
                                                            });
                                                        }

                                                        prevButton.addEventListener('click', function(e) {
                                                            e.preventDefault();
                                                            let currentIndex = parseInt(sampleIndexInput.value);
                                                            updateSampleCustomer(currentIndex - 1);
                                                        });

                                                        nextButton.addEventListener('click', function(e) {
                                                            e.preventDefault();
                                                            let currentIndex = parseInt(sampleIndexInput.value);
                                                            updateSampleCustomer(currentIndex + 1);
                                                        });
                                                    }
                                                });

                                                // Sync Past Customers Function
                                                function syncPastCustomers(productId, listUid) {
                                                    const button = document.getElementById('sync-past-customers-' + productId);
                                                    const progressDiv = document.getElementById('sync-progress-' + productId);
                                                    
                                                    // Update UI
                                                    button.disabled = true;
                                                    button.textContent = 'Syncing...';
                                                    progressDiv.style.display = 'block';
                                                    progressDiv.innerHTML = 'Finding customers...';
                                                    
                                                    // AJAX request
                                                    const data = new FormData();
                                                    data.append('action', 'df_wc_sendmailsio_sync_past_customers');
                                                    data.append('product_id', productId);
                                                    data.append('list_uid', listUid);
                                                    data.append('nonce', '<?php echo wp_create_nonce("df_wc_sendmailsio_sync_nonce"); ?>');
                                                    
                                                    fetch(ajaxurl, {
                                                        method: 'POST',
                                                        body: data
                                                    })
                                                    .then(response => response.json())
                                                    .then(result => {
                                                        if (result.success) {
                                                            progressDiv.innerHTML = `<span style="color:#080;">✓ ${result.data.message}</span>`;
                                                        } else {
                                                            progressDiv.innerHTML = `<span style="color:#d00;">✗ ${result.data}</span>`;
                                                        }
                                                        
                                                        // Reset button
                                                        button.disabled = false;
                                                        button.textContent = 'Sync Past Customers';
                                                        
                                                        // Hide progress after 10 seconds
                                                        setTimeout(() => {
                                                            progressDiv.style.display = 'none';
                                                        }, 10000);
                                                    })
                                                    .catch(error => {
                                                        console.error('Sync Error:', error);
                                                        progressDiv.innerHTML = '<span style="color:#d00;">✗ Sync failed - check console</span>';
                                                        button.disabled = false;
                                                        button.textContent = 'Sync Past Customers';
                                                    });
                                                }
                                                </script>
                                                <?php
                                                echo '</fieldset>';
                                            }
                                        }
                                    }
                                    ?>
                                    </div>
                                    <script>
                                    function toggleListFields(productId, listUid) {
                                        var fieldsDiv = document.getElementById('list-fields-' + productId);
                                        if (fieldsDiv.style.display === 'none' || fieldsDiv.style.display === '') {
                                            fieldsDiv.style.display = 'block';
                                        } else {
                                            fieldsDiv.style.display = 'none';
                                        }
                                    }
                                    </script>
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


/**
 * AJAX handler to get sample customer data
 */
add_action('wp_ajax_df_wc_sendmailsio_get_sample_customer', 'df_wc_sendmailsio_ajax_get_sample_customer');
add_action('wp_ajax_nopriv_df_wc_sendmailsio_get_sample_customer', 'df_wc_sendmailsio_ajax_get_sample_customer'); // For non-logged-in users if needed

function df_wc_sendmailsio_ajax_get_sample_customer() {
    if (!isset($_POST['product_id']) || !isset($_POST['sample_customer_index'])) {
        wp_send_json_error('Missing parameters.');
    }

    $product_id = intval($_POST['product_id']);
    $sample_customer_index = intval($_POST['sample_customer_index']);

    // Re-fetch the sample customers logic
    $customer_samples = array();
    $orders_wc_query = wc_get_orders(array(
        'limit' => 100,
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
        'status' => 'any',
    ));

    $order_ids_wc_query = array();
    foreach ($orders_wc_query as $order_obj) {
        $order_ids_wc_query[] = $order_obj->get_id();
    }

    $order_ids_to_use = $order_ids_wc_query;

    foreach ($order_ids_to_use as $oid) {
        $order = wc_get_order($oid);
        if (!$order) continue;
        $customer_samples[] = array(
            'order_id' => $oid,
            'order' => $order
        );
    }

    $sample_customer_count = count($customer_samples);

    if ($sample_customer_count > 0) {
        // Ensure index stays within bounds
        if ($sample_customer_index < 0) $sample_customer_index = 0;
        if ($sample_customer_index >= $sample_customer_count) $sample_customer_index = $sample_customer_count - 1;

        $sample_order = $customer_samples[$sample_customer_index]['order'];

        // Prepare data to send back
        $response_data = array(
            'sample_customer_index' => $sample_customer_index,
            'sample_customer_count' => $sample_customer_count,
            'sample_data_html' => '', // This will hold the HTML for the table rows
        );

        // Re-generate the table rows for sample data
        ob_start(); // Start output buffering
        $wc_fields = array(
            'billing_first_name' => array('label' => 'First Name', 'tag' => 'FIRST_NAME', 'type' => 'text'),
            'billing_last_name' => array('label' => 'Last Name', 'tag' => 'LAST_NAME', 'type' => 'text'),
            'billing_email' => array('label' => 'Email', 'tag' => 'EMAIL', 'type' => 'text'),
            'billing_phone' => array('label' => 'Phone Number', 'tag' => 'PHONE', 'type' => 'text'),
            'billing_company' => array('label' => 'Company', 'tag' => 'BILLING_COMPANY', 'type' => 'text'),
            'billing_address_1' => array('label' => 'Address 1', 'tag' => 'BILLING_ADDRESS_1', 'type' => 'text'),
            'billing_address_2' => array('label' => 'Address 2', 'tag' => 'BILLING_ADDRESS_2', 'type' => 'text'),
            'billing_city' => array('label' => 'City', 'tag' => 'BILLING_CITY', 'type' => 'text'),
            'billing_state' => array('label' => 'State', 'tag' => 'BILLING_STATE', 'type' => 'text'),
            'billing_postcode' => array('label' => 'Postcode', 'tag' => 'BILLING_POSTCODE', 'type' => 'text'),
            'billing_country' => array('label' => 'Country', 'tag' => 'BILLING_COUNTRY', 'type' => 'text'),
            'shipping_first_name' => array('label' => 'Shipping First Name', 'tag' => 'SHIPPING_FIRST_NAME', 'type' => 'text'),
            'shipping_last_name' => array('label' => 'Shipping Last Name', 'tag' => 'SHIPPING_LAST_NAME', 'type' => 'text'),
            'shipping_company' => array('label' => 'Shipping Company', 'tag' => 'SHIPPING_COMPANY', 'type' => 'text'),
            'shipping_address_1' => array('label' => 'Shipping Address 1', 'tag' => 'SHIPPING_ADDRESS_1', 'type' => 'text'),
            'shipping_address_2' => array('label' => 'Shipping Address 2', 'tag' => 'SHIPPING_ADDRESS_2', 'type' => 'text'),
            'shipping_city' => array('label' => 'Shipping City', 'tag' => 'SHIPPING_CITY', 'type' => 'text'),
            'shipping_state' => array('label' => 'Shipping State', 'tag' => 'SHIPPING_STATE', 'type' => 'text'),
            'shipping_postcode' => array('label' => 'Shipping Postcode', 'tag' => 'SHIPPING_POSTCODE', 'type' => 'text'),
            'shipping_country' => array('label' => 'Shipping Country', 'tag' => 'SHIPPING_COUNTRY', 'type' => 'text'),
        );
        foreach ($wc_fields as $key => $f) {
            $is_core = in_array($key, array('billing_email', 'billing_first_name', 'billing_last_name'));
            $sample_value = '';
            // Always use WC_Order getter for all fields
            switch ($key) {
                case 'billing_first_name': $sample_value = $sample_order->get_billing_first_name(); break;
                case 'billing_last_name': $sample_value = $sample_order->get_billing_last_name(); break;
                case 'billing_email': $sample_value = $sample_order->get_billing_email(); break;
                case 'billing_phone': $sample_value = $sample_order->get_billing_phone(); break;
                case 'billing_company': $sample_value = $sample_order->get_billing_company(); break;
                case 'billing_address_1': $sample_value = $sample_order->get_billing_address_1(); break;
                case 'billing_address_2': $sample_value = $sample_order->get_billing_address_2(); break;
                case 'billing_city': $sample_value = $sample_order->get_billing_city(); break;
                case 'billing_state': $sample_value = $sample_order->get_billing_state(); break;
                case 'billing_postcode': $sample_value = $sample_order->get_billing_postcode(); break;
                case 'billing_country': $sample_value = $sample_order->get_billing_country(); break;
                case 'shipping_first_name': $sample_value = $sample_order->get_shipping_first_name(); break;
                case 'shipping_last_name': $sample_value = $sample_order->get_shipping_last_name(); break;
                case 'shipping_company': $sample_value = $sample_order->get_shipping_company(); break;
                case 'shipping_address_1': $sample_value = $sample_order->get_shipping_address_1(); break;
                case 'shipping_address_2': $sample_value = $sample_order->get_shipping_address_2(); break;
                case 'shipping_city': $sample_value = $sample_order->get_shipping_city(); break;
                case 'shipping_state': $sample_value = $sample_order->get_shipping_state(); break;
                case 'shipping_postcode': $sample_value = $sample_order->get_shipping_postcode(); break;
                case 'shipping_country': $sample_value = $sample_order->get_shipping_country(); break;
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
            <?php
        }
        $response_data['sample_data_html'] = ob_get_clean(); // Get buffered output and clean
        wp_send_json_success($response_data);
    } else {
        wp_send_json_error('No sample customers found.');
    }
}

// Hook into WooCommerce order events for automatic customer sync
add_action('woocommerce_order_status_completed', 'df_wc_sendmailsio_sync_order_customers');
add_action('woocommerce_order_status_processing', 'df_wc_sendmailsio_sync_order_customers');

/**
 * Sync customers to SendMails.io when order is completed/processing
 */
function df_wc_sendmailsio_sync_order_customers($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // Get all items in the order
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        
        // Check if product (or its parent if variation) has a mapped list
        $list_uid = get_post_meta($product_id, '_sendmailsio_list_uid', true);
        
        if ($list_uid) {
            df_wc_sendmailsio_sync_customer_to_list($order, $list_uid, $product_id);
        }
    }
}

/**
 * Sync a customer to a specific SendMails.io list
 */
function df_wc_sendmailsio_sync_customer_to_list($order, $list_uid, $product_id) {
    $api_key = get_option('df_wc_sendmailsio_api_key', '');
    $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
    
    if (!$api_key || !$list_uid) {
        error_log('Woo SendmailsIO: Missing API key or list UID for sync');
        return false;
    }
    
    // Get list configuration to see which fields are actually available
    $list_info = df_wc_sendmailsio_get_list_info($list_uid, $api_endpoint, $api_key);
    if (!$list_info) {
        error_log('Woo SendmailsIO: Could not retrieve list info for sync');
        return false;
    }
    
    // Extract customer data from order, filtered by available list fields
    $customer_data = df_wc_sendmailsio_extract_customer_data($order, $list_info);
    
    if (!$customer_data['EMAIL']) {
        error_log('Woo SendmailsIO: Missing customer email for sync');
        return false;
    }
    
    // Check if subscriber already exists
    $existing_subscriber = df_wc_sendmailsio_find_subscriber_by_email($customer_data['EMAIL'], $api_endpoint, $api_key);
    
    if ($existing_subscriber) {
        // Update existing subscriber
        return df_wc_sendmailsio_update_subscriber($existing_subscriber['uid'], $customer_data, $list_uid, $api_endpoint, $api_key);
    } else {
        // Create new subscriber
        return df_wc_sendmailsio_create_subscriber($customer_data, $list_uid, $api_endpoint, $api_key);
    }
}

/**
 * Extract customer data from WooCommerce order and map to SendMails.io fields
 * Only includes fields that exist in the target SendMails.io list
 */
function df_wc_sendmailsio_extract_customer_data($order, $list_info = null) {
    $customer_data = array();
    
    // Map WooCommerce order data to SendMails.io field tags
    $field_mapping = array(
        'EMAIL' => $order->get_billing_email(),
        'FIRST_NAME' => $order->get_billing_first_name(),
        'LAST_NAME' => $order->get_billing_last_name(),
        'PHONE' => $order->get_billing_phone(),
        'BILLING_COMPANY' => $order->get_billing_company(),
        'BILLING_ADDRESS_1' => $order->get_billing_address_1(),
        'BILLING_ADDRESS_2' => $order->get_billing_address_2(),
        'BILLING_CITY' => $order->get_billing_city(),
        'BILLING_STATE' => $order->get_billing_state(),
        'BILLING_POSTCODE' => $order->get_billing_postcode(),
        'BILLING_COUNTRY' => $order->get_billing_country(),
        'SHIPPING_FIRST_NAME' => $order->get_shipping_first_name(),
        'SHIPPING_LAST_NAME' => $order->get_shipping_last_name(),
        'SHIPPING_COMPANY' => $order->get_shipping_company(),
        'SHIPPING_ADDRESS_1' => $order->get_shipping_address_1(),
        'SHIPPING_ADDRESS_2' => $order->get_shipping_address_2(),
        'SHIPPING_CITY' => $order->get_shipping_city(),
        'SHIPPING_STATE' => $order->get_shipping_state(),
        'SHIPPING_POSTCODE' => $order->get_shipping_postcode(),
        'SHIPPING_COUNTRY' => $order->get_shipping_country(),
    );
    
    // Get available field tags from the list if provided
    $available_tags = array();
    if ($list_info && isset($list_info['list']['fields']) && is_array($list_info['list']['fields'])) {
        foreach ($list_info['list']['fields'] as $field) {
            if (isset($field['tag'])) {
                $available_tags[] = $field['tag'];
            }
        }
    }
    
    // Only include fields that have values AND exist in the target list
    foreach ($field_mapping as $tag => $value) {
        if (!empty($value)) {
            // If we have list info, only include fields that exist in the list
            if ($list_info && !in_array($tag, $available_tags)) {
                continue; // Skip fields that don't exist in the target list
            }
            $customer_data[$tag] = sanitize_text_field($value);
        }
    }
    
    return $customer_data;
}

/**
 * Get list information from SendMails.io API
 */
function df_wc_sendmailsio_get_list_info($list_uid, $api_endpoint, $api_key) {
    $url = trailingslashit($api_endpoint) . 'lists/' . urlencode($list_uid);
    $url = add_query_arg('api_token', $api_key, $url);
    
    $response = wp_remote_get($url, array(
        'headers' => array('Accept' => 'application/json'),
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        error_log('Woo SendmailsIO: Error getting list info - ' . $response->get_error_message());
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        return is_array($data) ? $data : false;
    }
    
    return false;
}

/**
 * Find subscriber by email using SendMails.io API
 */
function df_wc_sendmailsio_find_subscriber_by_email($email, $api_endpoint, $api_key) {
    $url = trailingslashit($api_endpoint) . 'subscribers/email/' . urlencode($email);
    $url = add_query_arg('api_token', $api_key, $url);
    
    $response = wp_remote_get($url, array(
        'headers' => array('Accept' => 'application/json'),
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        error_log('Woo SendmailsIO: Error finding subscriber - ' . $response->get_error_message());
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        return is_array($data) ? $data : false;
    }
    
    return false;
}

/**
 * Create new subscriber in SendMails.io
 */
function df_wc_sendmailsio_create_subscriber($customer_data, $list_uid, $api_endpoint, $api_key) {
    $url = trailingslashit($api_endpoint) . 'subscribers';
    $url = add_query_arg('api_token', $api_key, $url);
    
    // Prepare subscriber data
    $subscriber_data = array(
        'list_uid' => $list_uid,
        'EMAIL' => $customer_data['EMAIL'],
    );
    
    // Add other customer data fields
    foreach ($customer_data as $field_tag => $value) {
        if ($field_tag !== 'EMAIL' && !empty($value)) {
            $subscriber_data[$field_tag] = $value;
        }
    }
    
    $response = wp_remote_post($url, array(
        'headers' => array('Accept' => 'application/json'),
        'body' => $subscriber_data,
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        error_log('Woo SendmailsIO: Error creating subscriber - ' . $response->get_error_message());
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200 || $code === 201) {
        error_log('Woo SendmailsIO: Successfully created subscriber - ' . $customer_data['EMAIL']);
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        error_log('Woo SendmailsIO: Failed to create subscriber - ' . $body);
        return false;
    }
}

/**
 * Update existing subscriber in SendMails.io
 */
function df_wc_sendmailsio_update_subscriber($subscriber_uid, $customer_data, $list_uid, $api_endpoint, $api_key) {
    $url = trailingslashit($api_endpoint) . 'subscribers/' . urlencode($subscriber_uid);
    $url = add_query_arg('api_token', $api_key, $url);
    
    // Prepare update data (only non-empty fields)
    $update_data = array();
    foreach ($customer_data as $field_tag => $value) {
        if (!empty($value)) {
            $update_data[$field_tag] = $value;
        }
    }
    
    $response = wp_remote_request($url, array(
        'method' => 'PATCH',
        'headers' => array('Accept' => 'application/json'),
        'body' => $update_data,
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        error_log('Woo SendmailsIO: Error updating subscriber - ' . $response->get_error_message());
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        error_log('Woo SendmailsIO: Successfully updated subscriber - ' . $customer_data['EMAIL']);
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        error_log('Woo SendmailsIO: Failed to update subscriber - ' . $body);
        return false;
    }
}

/**
 * Bulk sync customers who previously purchased a specific product
 */
function df_wc_sendmailsio_bulk_sync_product_customers($product_id, $list_uid) {
    $stats = array(
        'success' => false,
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'details' => array()
    );

    try {
        // Check if product is mapped to this list
        $mapped_list_uid = get_post_meta($product_id, '_sendmailsio_list_uid', true);
        if ($mapped_list_uid !== $list_uid) {
            $stats['details'][] = "Product $product_id not mapped to list $list_uid (mapped to: $mapped_list_uid)";
            return $stats;
        }

        // Get SendMails.io settings
        $api_key = get_option('df_wc_sendmailsio_api_key', '');
        $api_endpoint = get_option('df_wc_sendmailsio_api_endpoint', 'https://app.sendmails.io/api/v1');
        
        // Debug logging for API key
        error_log("Bulk sync API key debug: '" . $api_key . "' (length: " . strlen($api_key) . ")");
        error_log("API endpoint: " . $api_endpoint);
        error_log("API key empty check: " . (empty($api_key) ? 'true' : 'false'));
        error_log("API key trim length: " . strlen(trim($api_key)));
        
        if (empty(trim($api_key))) {
            $stats['details'][] = 'SendMails.io API key not configured';
            return $stats;
        }
        
        error_log("API key check passed, proceeding with list fetch");

        // Get list fields from SendMails.io API to determine what fields exist
        error_log("Fetching list fields from: " . $api_endpoint . '/lists/' . $list_uid);
        $list_response = wp_remote_get($api_endpoint . '/lists/' . $list_uid, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            )
        ));

        if (is_wp_error($list_response)) {
            error_log("List API error: " . $list_response->get_error_message());
            $stats['details'][] = 'Failed to fetch list information from SendMails.io: ' . $list_response->get_error_message();
            return $stats;
        }

        $response_code = wp_remote_retrieve_response_code($list_response);
        $response_body = wp_remote_retrieve_body($list_response);
        error_log("List API response code: $response_code");
        error_log("List API response body: " . substr($response_body, 0, 500));

        $list_data = json_decode($response_body, true);
        if (!$list_data || !isset($list_data['data']['fields'])) {
            $stats['details'][] = 'Invalid list data from SendMails.io (response code: ' . $response_code . ')';
            return $stats;
        }

        $list_info = array('list' => array('fields' => $list_data['data']['fields']));

        // Get all orders containing this product (including variations)
        $product = wc_get_product($product_id);
        if (!$product) {
            $stats['details'][] = 'Product not found';
            return $stats;
        }

        // Build query args to find orders with this product
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-completed', 'wc-processing'),
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_customer_user',
                    'compare' => 'EXISTS'
                )
            )
        );

        $orders = get_posts($args);
        $unique_customers = array();

        // Find orders containing our product
        foreach ($orders as $order_post) {
            $order = wc_get_order($order_post->ID);
            if (!$order) continue;

            $has_product = false;
            foreach ($order->get_items() as $item) {
                $item_product_id = $item->get_product_id();
                $item_variation_id = $item->get_variation_id();
                
                // Check if this item matches our product (including variations)
                if ($item_product_id == $product_id || 
                    ($item_variation_id && $item_variation_id == $product_id) ||
                    ($product->is_type('variable') && $item_product_id == $product_id)) {
                    $has_product = true;
                    break;
                }
            }

            if ($has_product) {
                $customer_email = $order->get_billing_email();
                if ($customer_email && !in_array($customer_email, $unique_customers)) {
                    $unique_customers[] = $customer_email;
                }
            }
        }

        $stats['total'] = count($unique_customers);

        // If no customers found
        if (empty($unique_customers)) {
            $stats['success'] = true;
            $stats['details'][] = 'No customers found who purchased this product';
            return $stats;
        }

        // Sync each unique customer
        foreach ($unique_customers as $customer_email) {
            // Find the most recent order for this customer with this product
            $customer_orders = wc_get_orders(array(
                'billing_email' => $customer_email,
                'status' => array('completed', 'processing'),
                'limit' => -1
            ));

            $target_order = null;
            foreach ($customer_orders as $order) {
                foreach ($order->get_items() as $item) {
                    $item_product_id = $item->get_product_id();
                    $item_variation_id = $item->get_variation_id();
                    
                    if ($item_product_id == $product_id || 
                        ($item_variation_id && $item_variation_id == $product_id) ||
                        ($product->is_type('variable') && $item_product_id == $product_id)) {
                        $target_order = $order;
                        break 2;
                    }
                }
            }

            if (!$target_order) continue;

            // Extract customer data using existing function
            $customer_data = df_wc_sendmailsio_extract_customer_data($target_order, $list_info);
            
            if (empty($customer_data)) {
                $stats['skipped']++;
                continue;
            }

            // Sync to SendMails.io
            $sync_result = df_wc_sendmailsio_sync_customer_to_list($customer_data, $list_uid, $api_key);
            
            if ($sync_result === true) {
                $stats['created']++;
            } elseif ($sync_result === 'updated') {
                $stats['updated']++;  
            } elseif ($sync_result === 'skipped') {
                $stats['skipped']++;
            } else {
                $stats['errors']++;
                $stats['details'][] = "Error syncing {$customer_email}: " . $sync_result;
            }
        }

        $stats['success'] = true;
        
    } catch (Exception $e) {
        $stats['details'][] = 'Exception: ' . $e->getMessage();
        error_log('Bulk sync error: ' . $e->getMessage());
    }

    return $stats;
}

// Hook AJAX handler for past customer sync
add_action('wp_ajax_df_wc_sendmailsio_sync_past_customers', 'df_wc_sendmailsio_handle_sync_past_customers');

/**
 * AJAX handler for syncing past customers
 */
function df_wc_sendmailsio_handle_sync_past_customers() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'df_wc_sendmailsio_sync_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $list_uid = sanitize_text_field($_POST['list_uid']);
    
    if (!$product_id || !$list_uid) {
        wp_send_json_error('Missing product ID or list UID');
        return;
    }
    
    // Perform the bulk sync
    $result = df_wc_sendmailsio_bulk_sync_product_customers($product_id, $list_uid);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => sprintf(
                'Synced %d customers (%d new, %d updated, %d skipped, %d errors)',
                $result['total'],
                $result['created'],
                $result['updated'],
                $result['skipped'],
                $result['errors']
            )
        ));
    } else {
        $error_message = !empty($result['details']) ? implode(', ', $result['details']) : 'Sync failed';
        wp_send_json_error($error_message);
    }
}
