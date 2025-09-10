<?php
/*
Plugin Name: BYGI MetForm → GetResponse
Description: Sending data from MetForm to GetResponse via API.
Version: 1.3
Author: Mikalai Kazak
*/


if (!defined('ABSPATH')) {
    exit;
}

// --- Страница настроек ---
add_action('admin_menu', function() {
    add_options_page('Metform GetResponse', 'Metform GetResponse', 'manage_options', 'bygi-getresponse', 'bygi_getresponse_settings_page');
});

// --- Регистрация опций ---
add_action('admin_init', function() {
    register_setting('bygi_getresponse_group', 'bygi_getresponse_api_key', [
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('bygi_getresponse_group', 'bygi_getresponse_campaign_id', [
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('bygi_getresponse_group', 'bygi_getresponse_debug_mode', [
        'sanitize_callback' => function($val) { return $val ? 1 : 0; }
    ]);
});

function bygi_getresponse_settings_page() {
    // Проверка nonce при сохранении
    if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
        echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены!</p></div>';
    }
    
    // Проверка соединения с API
    $api_key = get_option('bygi_getresponse_api_key');
    if (!empty($api_key)) {
        $connection_status = bygi_test_getresponse_connection($api_key);
        if ($connection_status['success']) {
            echo '<div class="notice notice-success"><p>✅ Connection to GetResponse: successful</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Connection error with GetResponse: ' . esc_html($connection_status['error']) . '</p></div>';
        }
    }
    
    ?>
    <div class="wrap">
        <h1>GetResponse Settings</h1>
        <form method="post" action="options.php">
            <?php 
            settings_fields('bygi_getresponse_group'); 
            do_settings_sections('bygi_getresponse_group'); 
            wp_nonce_field('bygi_getresponse_settings', 'bygi_getresponse_nonce');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td>
                        <input type="password" name="bygi_getresponse_api_key" value="<?php echo esc_attr(get_option('bygi_getresponse_api_key')); ?>" style="width:400px;" required>
                        <p class="description">Your API key from GetResponse</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Campaign ID</th>
                    <td>
                        <input type="text" name="bygi_getresponse_campaign_id" value="<?php echo esc_attr(get_option('bygi_getresponse_campaign_id')); ?>" style="width:400px;" required>
                        <p class="description">GetResponse Campaign ID</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="bygi_getresponse_debug_mode" value="1" <?php checked(1, get_option('bygi_getresponse_debug_mode'), true); ?>>
                            Enable debugging (writing to debug.log)
                        </label>
                        <p class="description">If enabled - all requests and responses will be written to debug.log</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <h2>Information</h2>
        <p>The plugin automatically sends contacts from MetForm forms to GetResponse when the form is submitted.</p>
        <p><strong>Supported Form Fields:</strong> mf-name, mf-email</p>
    </div>
    <?php
}


function bygi_log($message, $level = 'info') {
    if (get_option('bygi_getresponse_debug_mode')) {
        error_log("BYGI GetResponse [$level]: " . $message);
    }
}


// --- Проверка соединения с API ---
function bygi_test_getresponse_connection($api_key) {
    $url = 'https://api.getresponse.com/v3/accounts';
    
    $response = wp_remote_get($url, [
        'headers' => [
            'X-Auth-Token' => 'api-key ' . $api_key,
        ],
        'timeout' => 10,
    ]);
    
    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'HTTP код: ' . $code];
    }
}

// --- Получение реального IP адреса ---
function bygi_get_real_ip() {
    $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            // Для X-Forwarded-For берем первый IP
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

// --- Основной хук MetForm ---
add_action('metform_after_store_form_data', 'bygi_metform_send_to_getresponse', 10, 3);

function bygi_metform_send_to_getresponse($entry_id, $form_id, $form_data) {
    // --- Проверка настроек ---
    $api_key = get_option('bygi_getresponse_api_key');
    $campaign_id = get_option('bygi_getresponse_campaign_id');
    
    if (empty($api_key) || empty($campaign_id)) {
        bygi_log('BYGI GetResponse: API Key or Campaign ID is not configured');
        return;
    }
    
    // --- Логируем форму ---
    $form_id_log = is_array($form_id) ? json_encode($form_id) : $form_id;
    bygi_log('MetForm hook fired! Form ID: ' . $form_id_log . ' Entry ID: ' . $entry_id);
    
    // --- Берём имя и email ---
    $name  = isset($form_id['mf-name']) ? sanitize_text_field($form_id['mf-name']) : '';
    $email = isset($form_id['mf-email']) ? sanitize_email($form_id['mf-email']) : '';
    
    bygi_log('Form submitted: name=' . $name . ', email=' . $email);
    
    // --- Проверка email ---
    if (empty($email) || !is_email($email)) {
        bygi_log('BYGI GetResponse: invalid or missing email, skip it');
        return;
    }
    
    // --- Проверка имени ---
    if (empty($name)) {
        $name = 'Not specified';
    }
    
    // --- URL API GetResponse ---
    $url = 'https://api.getresponse.com/v3/contacts';
    
    // --- Формируем тело запроса ---
    $body = [
        'name'     => $name,
        'email'    => $email,
        'campaign' => ['campaignId' => $campaign_id],
        'ipAddress'=> bygi_get_real_ip(),
    ];
    
    // --- Отправка запроса ---
    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Auth-Token' => 'api-key ' . $api_key,
        ],
        'body' => wp_json_encode($body),
        'timeout' => 15,
    ]);
    
    // --- Обработка результата ---
    if (is_wp_error($response)) {
        bygi_log('ERROR: ' . $response->get_error_message(), 'error');
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body_resp = wp_remote_retrieve_body($response);
        
        if ($code === 201) {
            bygi_log('SUCCESS: Contact added (' . $code . ')', 'info');
        } elseif ($code === 202) {
            bygi_log('SUCCESS: Contact accepted for processing (' . $code . ')', 'info');
        } elseif ($code === 409) {
            bygi_log('INFO: The contact already exists (' . $code . ')', 'info');
        } else {
            bygi_log('ERROR (' . $code . '): ' . $body_resp, 'error');
        }
    }
}

// --- Хук деактивации ---
register_deactivation_hook(__FILE__, function() {
    // Удаляем настройки плагина
    delete_option('bygi_getresponse_api_key');
    delete_option('bygi_getresponse_campaign_id');
    delete_option('bygi_getresponse_debug_mode');
    
    // Логируем деактивацию
    bygi_log('BYGI GetResponse Plugin: deactivated and settings cleared');
});