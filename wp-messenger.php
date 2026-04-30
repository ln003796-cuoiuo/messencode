<?php
/**
 * Plugin Name: WP Messenger
 * Description: SPA messenger shortcode with encrypted messages and REST API.
 * Version: 0.1.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WPMessenger
{
    private const OPTION_KEY = 'wp_messenger_key';
    private const NONCE_ACTION = 'wp_rest';

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_shortcode('wp_messenger', [$this, 'renderShortcode']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $messagesTable = $wpdb->prefix . 'mess_messages';
        $dialogsTable = $wpdb->prefix . 'mess_dialogs';

        dbDelta("CREATE TABLE {$dialogsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL DEFAULT 'private',
            title VARCHAR(255) NOT NULL DEFAULT '',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY type (type),
            KEY updated_at (updated_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$messagesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dialog_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            encrypted_text LONGTEXT NOT NULL,
            iv VARBINARY(32) NOT NULL,
            tag VARBINARY(32) NOT NULL,
            is_silent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY dialog_id (dialog_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset};");

        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, base64_encode(random_bytes(32)), '', false);
        }
    }

    public function enqueueAssets(): void
    {
        wp_register_style('wp-messenger-style', plugins_url('wp-messenger.css', __FILE__), [], '0.1.0');
        wp_register_script('wp-messenger-script', plugins_url('wp-messenger.js', __FILE__), [], '0.1.0', true);

        wp_localize_script('wp-messenger-script', 'WPMessengerConfig', [
            'root' => esc_url_raw(rest_url('wp-messenger/v1')),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'userId' => get_current_user_id(),
        ]);
    }

    public function renderShortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Для использования мессенджера войдите в аккаунт WordPress.</p>';
        }

        wp_enqueue_style('wp-messenger-style');
        wp_enqueue_script('wp-messenger-script');

        ob_start();
        ?>
        <div id="wp-messenger-app" class="wp-messenger">
            <aside class="wp-messenger__nav">
                <button data-tab="chats">Чаты</button>
                <button data-tab="contacts">Контакты</button>
                <button data-tab="channels">Каналы</button>
                <button data-tab="bots">Боты</button>
                <button data-tab="settings">Настройки</button>
            </aside>
            <section class="wp-messenger__chats">
                <div class="wp-messenger__header">Список чатов</div>
                <ul id="wp-messenger-dialogs"></ul>
            </section>
            <section class="wp-messenger__dialog">
                <div class="wp-messenger__header" id="wp-messenger-title">Выберите чат</div>
                <ul id="wp-messenger-messages"></ul>
                <form id="wp-messenger-form">
                    <input type="text" id="wp-messenger-text" maxlength="1000" placeholder="Сообщение" required />
                    <label><input type="checkbox" id="wp-messenger-silent" /> Тихо</label>
                    <button type="submit">Отправить</button>
                </form>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function registerRoutes(): void
    {
        register_rest_route('wp-messenger/v1', '/dialogs', [
            'methods' => 'GET',
            'callback' => [$this, 'listDialogs'],
            'permission_callback' => fn() => is_user_logged_in(),
        ]);

        register_rest_route('wp-messenger/v1', '/dialogs', [
            'methods' => 'POST',
            'callback' => [$this, 'createDialog'],
            'permission_callback' => fn() => is_user_logged_in(),
        ]);

        register_rest_route('wp-messenger/v1', '/dialogs/(?P<id>\d+)/messages', [
            'methods' => 'GET',
            'callback' => [$this, 'listMessages'],
            'permission_callback' => fn() => is_user_logged_in(),
        ]);

        register_rest_route('wp-messenger/v1', '/dialogs/(?P<id>\d+)/messages', [
            'methods' => 'POST',
            'callback' => [$this, 'sendMessage'],
            'permission_callback' => fn() => is_user_logged_in(),
        ]);
    }

    public function listDialogs(): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mess_dialogs';
        $rows = $wpdb->get_results("SELECT id, type, title, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT 100", ARRAY_A);
        return new WP_REST_Response(['dialogs' => $rows], 200);
    }

    public function createDialog(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mess_dialogs';

        $title = sanitize_text_field((string) $request->get_param('title'));
        $type = sanitize_text_field((string) ($request->get_param('type') ?: 'private'));
        $type = in_array($type, ['private', 'group', 'channel', 'saved'], true) ? $type : 'private';

        $now = current_time('mysql');
        $wpdb->insert($table, [
            'type' => $type,
            'title' => $title,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%d', '%s', '%s']);

        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public function listMessages(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mess_messages';
        $dialogId = (int) $request['id'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, encrypted_text, iv, tag, is_silent, created_at FROM {$table} WHERE dialog_id = %d ORDER BY id DESC LIMIT 200",
            $dialogId
        ), ARRAY_A);

        $rows = array_reverse($rows);
        $messages = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'text' => $this->decrypt($row['encrypted_text'], $row['iv'], $row['tag']),
                'is_silent' => (bool) $row['is_silent'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return new WP_REST_Response(['messages' => $messages], 200);
    }

    public function sendMessage(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $dialogTable = $wpdb->prefix . 'mess_dialogs';
        $messagesTable = $wpdb->prefix . 'mess_messages';

        $dialogId = (int) $request['id'];
        $text = trim((string) $request->get_param('text'));
        $silent = (bool) $request->get_param('is_silent');

        if ($text === '') {
            return new WP_REST_Response(['error' => 'Пустое сообщение'], 422);
        }

        $dialogExists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$dialogTable} WHERE id = %d", $dialogId));
        if ($dialogExists === 0) {
            return new WP_REST_Response(['error' => 'Чат не найден'], 404);
        }

        [$ciphertext, $iv, $tag] = $this->encrypt($text);
        $now = current_time('mysql');

        $wpdb->insert($messagesTable, [
            'dialog_id' => $dialogId,
            'user_id' => get_current_user_id(),
            'encrypted_text' => $ciphertext,
            'iv' => $iv,
            'tag' => $tag,
            'is_silent' => $silent ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']);

        $wpdb->update($dialogTable, ['updated_at' => $now], ['id' => $dialogId], ['%s'], ['%d']);

        return new WP_REST_Response(['ok' => true, 'id' => (int) $wpdb->insert_id], 201);
    }

    private function encrypt(string $plainText): array
    {
        $key = base64_decode((string) get_option(self::OPTION_KEY), true);
        if ($key === false || strlen($key) !== 32) {
            $key = random_bytes(32);
            update_option(self::OPTION_KEY, base64_encode($key), false);
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return [base64_encode((string) $ciphertext), base64_encode($iv), base64_encode($tag)];
    }

    private function decrypt(string $cipherText, string $iv, string $tag): string
    {
        $key = base64_decode((string) get_option(self::OPTION_KEY), true);
        if ($key === false || strlen($key) !== 32) {
            return '[Ошибка ключа шифрования]';
        }

        $decodedCipher = base64_decode($cipherText, true);
        $decodedIv = base64_decode($iv, true);
        $decodedTag = base64_decode($tag, true);

        if ($decodedCipher === false || $decodedIv === false || $decodedTag === false) {
            return '[Ошибка декодирования]';
        }

        $plain = openssl_decrypt($decodedCipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $decodedIv, $decodedTag);
        return $plain === false ? '[Ошибка расшифровки]' : $plain;
    }
}

new WPMessenger();
