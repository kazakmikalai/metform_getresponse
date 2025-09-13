<?php
/*
Plugin Name: MetForm → GetResponse
Description: Sending data from MetForm to GetResponse via API.
Version: 1.
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
                        <p class="description">GetResponse Campaign ID (default list)</p>
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
        <p><strong>Supported Form Fields:</strong> mf-name, mf-email, mf-chapters</p>
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
            if (strpos($ip, ',') !== false) { // берём первый IP из X-Forwarded-For
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * bygi: отправка контакта в кампанию GetResponse
 */
function bygi_send_to_campaign($api_key, $campaign_id, $name, $email) {
    $url  = 'https://api.getresponse.com/v3/contacts';
    $body = [
        'name'      => $name ?: 'Not specified',
        'email'     => $email,
        'campaign'  => ['campaignId' => $campaign_id],
        'ipAddress' => bygi_get_real_ip(),
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Auth-Token' => 'api-key ' . $api_key,
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        bygi_log('Send [' . $campaign_id . '] ERROR: ' . $response->get_error_message(), 'error');
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $resp = wp_remote_retrieve_body($response);

    if ($code === 201) {
        bygi_log('Send [' . $campaign_id . '] SUCCESS: Contact added (201)');
        return true;
    } elseif ($code === 202) {
        bygi_log('Send [' . $campaign_id . '] SUCCESS: Accepted for processing (202)');
        return true;
    } elseif ($code === 409) {
        bygi_log('Send [' . $campaign_id . '] INFO: Contact already exists (409)');
        return true; // дубликат — норм
    } else {
        bygi_log('Send [' . $campaign_id . '] ERROR (' . $code . '): ' . $resp, 'error');
        return false;
    }
}

// --- Основной хук MetForm ---
add_action('metform_after_store_form_data', 'bygi_metform_send_to_getresponse', 10, 3);

function bygi_metform_send_to_getresponse($entry_id, $form_id, $form_data) {
    // --- Проверка настроек ---
    $api_key     = get_option('bygi_getresponse_api_key');
    $campaign_id = get_option('bygi_getresponse_campaign_id'); // дефолтный лист

    // Привязки по главам
    $campaign_id_finanse            = '';
    $campaign_id_kariera_i_praca    = '';
    $campaign_id_it                 = '';
    $campaign_id_mieszkanie         = '';
    $campaign_id_relacje_i_zdrowie  = '';

    if (empty($api_key) || empty($campaign_id)) {
        bygi_log('BYGI GetResponse: API Key or Campaign ID is not configured');
        return;
    }

    // --- Берём имя и email (у MetForm поля приходят во 2м аргументе как массив) ---
    $name  = isset($form_id['mf-name'])  ? sanitize_text_field($form_id['mf-name'])  : '';
    $email = isset($form_id['mf-email']) ? sanitize_email($form_id['mf-email'])     : '';

    // --- Универсальный парсер чекбоксов (массив или CSV-строка) ---
    $chapters_raw = $form_id['mf-chapters'] ?? []; // может быть array или string

    if (is_array($chapters_raw)) {
        $chapters = array_map('sanitize_text_field', $chapters_raw);
    } else {
        // строка вида "a,b,c" -> в массив
        $chapters = array_filter(array_map(
            'sanitize_text_field',
            array_map('trim', explode(',', (string) $chapters_raw))
        ));
    }

    // --- Лог ---
    bygi_log(
        'Form submitted: name=' . $name .
        ', email=' . $email .
        ', chapters_raw_type=' . gettype($chapters_raw) .
        ', chapters_json=' . json_encode($chapters, JSON_UNESCAPED_UNICODE)
    );

    // --- Валидации ---
    if (empty($email) || !is_email($email)) {
        bygi_log('BYGI GetResponse: invalid or missing email, skip it');
        return;
    }
    if (empty($name)) {
        $name = 'Not specified';
    }

    // --- Маппинг значений чекбоксов -> ID кампаний ---
    $chapter_to_campaign = [
        'finanse'            => $campaign_id_finanse,
        'kariera-i-praca'    => $campaign_id_kariera_i_praca,
        'it'                 => $campaign_id_it,
        'mieszkanie'         => $campaign_id_mieszkanie,
        'relacje-i-zdrowie'  => $campaign_id_relacje_i_zdrowie,
    ];

    // --- Формируем финальный набор кампаний: дефолтная + по главам ---
    $target_campaigns = [$campaign_id]; // дефолт
    foreach ($chapters as $slug) {
        if (isset($chapter_to_campaign[$slug])) {
            $target_campaigns[] = $chapter_to_campaign[$slug];
        } else {
            bygi_log('Unknown chapter slug (skip): ' . $slug);
        }
    }
    $target_campaigns = array_values(array_unique(array_filter($target_campaigns)));

    // --- Отправляем контакт во все целевые кампании ---
    $sent_ok = true;
    foreach ($target_campaigns as $cid) {
        $ok = bygi_send_to_campaign($api_key, $cid, $name, $email);
        $sent_ok = $sent_ok && $ok;
    }

    if ($sent_ok) {
        bygi_log('FINAL: sent to ' . implode(',', $target_campaigns));
    } else {
        bygi_log('FINAL: some campaigns failed, see errors above', 'error');
    }
}

// --- Хук деактивации ---
register_deactivation_hook(__FILE__, function() {
    delete_option('bygi_getresponse_api_key');
    delete_option('bygi_getresponse_campaign_id');
    delete_option('bygi_getresponse_debug_mode');
    bygi_log('BYGI GetResponse Plugin: deactivated and settings cleared');
});
