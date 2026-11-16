<?php
defined('ABSPATH') || exit;

final class Ulgamdagal_Operator_Sync {
    public static function init() {
        add_action('init', array(__CLASS__, 'install'));
        add_action('ulgamdagal_operator_sync_tick', array(__CLASS__, 'tick'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('cron_schedules', function ($schedules) {
            $schedules['ulgamdagal_five_minutes'] = array('interval' => 300, 'display' => 'Every five minutes');
            return $schedules;
        });
    }

    private static function table($suffix) {
        global $wpdb;
        return $wpdb->prefix . 'ulgamdagal_sync_' . $suffix;
    }

    public static function install() {
        global $wpdb;
        if (get_option('ulgamdagal_sync_db_version') !== '1') {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $mapping = self::table('mapping');
            $events = self::table('events');
            dbDelta("CREATE TABLE {$mapping} (
                session_hash char(64) NOT NULL,
                conversation_id char(36) NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (session_hash)
            ) ENGINE=InnoDB {$charset};");
            dbDelta("CREATE TABLE {$events} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_hash char(64) NOT NULL,
                event_id varchar(160) NOT NULL,
                role varchar(16) NOT NULL,
                content text NOT NULL,
                content_hash char(64) NOT NULL,
                event_time varchar(32) NOT NULL,
                created_at datetime NOT NULL,
                delivered tinyint(1) NOT NULL DEFAULT 0,
                last_attempt datetime NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_id (event_id),
                KEY pending (session_hash,delivered,id)
            ) ENGINE=InnoDB {$charset};");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($mapping))) !== $mapping ||
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($events))) !== $events) { return; }
            update_option('ulgamdagal_sync_db_version', '1', false);
        }
        if (!wp_next_scheduled('ulgamdagal_operator_sync_tick')) {
            wp_schedule_event(time() + 60, 'ulgamdagal_five_minutes', 'ulgamdagal_operator_sync_tick');
        }
    }

    public static function bind($hash, $conversation) {
        global $wpdb;
        $table = self::table('mapping');
        $previous = $wpdb->get_var($wpdb->prepare("SELECT conversation_id FROM {$table} WHERE session_hash=%s", $hash));
        if ($previous && $previous !== $conversation && self::pending($hash)) {
            return new WP_Error('sync_mapping_conflict', 'Есть недоставленные сообщения предыдущего диалога. Обратитесь к администратору.', array('status' => 409));
        }
        if ($wpdb->replace($table, array('session_hash' => $hash, 'conversation_id' => $conversation, 'updated_at' => gmdate('Y-m-d H:i:s')), array('%s','%s','%s')) === false) {
            return new WP_Error('sync_storage', 'Не удалось сохранить связь диалога.', array('status' => 503));
        }
        return true;
    }

    private static function pending($hash) {
        global $wpdb;
        $table = self::table('events');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT count(*) FROM {$table} WHERE session_hash=%s AND delivered=0", $hash));
    }

    public static function enqueue($hash, $id, $role, $content) {
        global $wpdb;
        $table = self::table('events');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT session_hash,role,content_hash,delivered FROM {$table} WHERE event_id=%s", $id));
        if ($wpdb->last_error) { return new WP_Error('sync_storage','Очередь временно недоступна.',array('status'=>503)); }
        if ($existing) {
            if ($existing->session_hash !== $hash || $existing->role !== $role || !hash_equals($existing->content_hash, hash('sha256', $content))) {
                return new WP_Error('sync_event_conflict', 'Идентификатор сообщения уже использован.', array('status' => 409));
            }
            return false;
        }
        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = $wpdb->insert($table, array(
            'session_hash' => $hash, 'event_id' => $id, 'role' => $role, 'content' => $content,
            'content_hash' => hash('sha256', $content),
            'event_time' => $utc->format('Y-m-d\TH:i:s.u\Z'),
            'created_at' => $utc->format('Y-m-d H:i:s'), 'delivered' => 0,
        ), array('%s','%s','%s','%s','%s','%s','%s','%d'));
        if ($result === false) {
            // A concurrent insert may have won the unique event_id race.
            $winner=$wpdb->get_row($wpdb->prepare("SELECT session_hash,role,content_hash FROM {$table} WHERE event_id=%s",$id));
            if (!$wpdb->last_error && $winner) {
                if ($winner->session_hash===$hash && $winner->role===$role && hash_equals($winner->content_hash,hash('sha256',$content))) { return false; }
                return new WP_Error('sync_event_conflict','Идентификатор сообщения уже использован.',array('status'=>409));
            }
            return new WP_Error('sync_storage', 'Не удалось сохранить сообщение в очередь.', array('status' => 503));
        }
        return true;
    }

    public static function flush($hash) {
        global $wpdb;
        $table = self::table('events');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE session_hash=%s AND delivered=0 ORDER BY id ASC LIMIT 50", $hash));
        if ($rows === null || $wpdb->last_error) { return new WP_Error('sync_storage', 'Очередь истории недоступна.', array('status' => 503)); }
        if (!$rows) { return true; }
        foreach ($rows as $row) {
            $wpdb->update($table, array('last_attempt' => gmdate('Y-m-d H:i:s')), array('id' => (int)$row->id), array('%s'), array('%d'));
        }
        $mapping = self::table('mapping');
        $conversation = $wpdb->get_var($wpdb->prepare("SELECT conversation_id FROM {$mapping} WHERE session_hash=%s", $hash));
        if (!$conversation) {
            update_option('ulgamdagal_sync_last_error', 'Есть сообщения без привязки к серверному диалогу. Нужна проверка администратором.', false);
            return new WP_Error('sync_unmapped', 'История ожидает привязки к серверному диалогу.', array('status' => 409));
        }
        $settings = get_option('ulgamdagal_ai_settings', array());
        $key = defined('ULGAMDAGAL_AI_API_KEY') ? (string) ULGAMDAGAL_AI_API_KEY : ($settings['key'] ?? '');
        $url = preg_replace('~/chat/?$~', '/operator-events', $settings['url'] ?? '');
        if ($key === '' || !$url || $url === ($settings['url'] ?? '') || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return new WP_Error('sync_config', 'Проверьте настройки API для синхронизации.', array('status' => 503));
        }
        $events = array();
        foreach ($rows as $row) {
            $events[] = array('event_id' => $row->event_id, 'role' => $row->role, 'content' => $row->content, 'created_at' => $row->event_time);
        }
        $response = wp_safe_remote_post($url, array(
            'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 32768,
            'headers' => array('Content-Type' => 'application/json', 'X-API-Key' => $key),
            'body' => wp_json_encode(array('conversation_id' => $conversation, 'events' => $events)),
        ));
        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($data) || ($data['success'] ?? false) !== true ||
            !isset($data['inserted'], $data['duplicates']) || !is_int($data['inserted']) || !is_int($data['duplicates']) ||
            $data['inserted'] < 0 || $data['duplicates'] < 0 || $data['inserted'] + $data['duplicates'] !== count($rows)) {
            update_option('ulgamdagal_sync_last_error', 'Синхронизация истории: HTTP ' . (int)$status . '. Сообщения сохранены в очереди.', false);
            return new WP_Error('sync_pending', 'История оператора ещё не доставлена. Повторите вопрос позже.', array('status' => 503));
        }
        foreach ($rows as $row) {
            // Keep the event ID for retry suppression; no second copy of delivered text.
            if ($wpdb->update($table, array('delivered' => 1, 'content' => ''), array('id' => (int)$row->id), array('%d','%s'), array('%d')) === false) {
                return new WP_Error('sync_storage', 'Не удалось подтвердить доставку истории.', array('status' => 503));
            }
        }
        delete_option('ulgamdagal_sync_last_error');
        if (self::pending($hash)) { return new WP_Error('sync_pending', 'Доставляется оставшаяся история. Повторите вопрос позже.', array('status' => 503)); }
        return true;
    }

    public static function tick() {
        global $wpdb;
        $table = self::table('events');
        // At most five network calls per cron execution.
        $hashes = $wpdb->get_col("SELECT session_hash FROM {$table} WHERE delivered=0 GROUP BY session_hash ORDER BY min(COALESCE(last_attempt,'1970-01-01 00:00:00')),min(id) LIMIT 5");
        foreach ((array)$hashes as $hash) { self::flush($hash); }
    }

    public static function notice() {
        if (!current_user_can('manage_options')) { return; }
        $error = get_option('ulgamdagal_sync_last_error', '');
        if ($error) { echo '<div class="notice notice-warning"><p>' . esc_html($error) . '</p></div>'; }
    }
}
Ulgamdagal_Operator_Sync::init();
