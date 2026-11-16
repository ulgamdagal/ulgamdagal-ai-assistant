<?php
defined('ABSPATH') || exit;

final class Ulgamdagal_Dialog_Journal {
    // Called only after the journal page's permission check. No public REST proxy.
    public static function sync() {
        global $wpdb;
        $logs = $wpdb->prefix . 'ulgamdagal_ai_logs';
        $mapping = $wpdb->prefix . 'ulgamdagal_sync_mapping';
        $settings = get_option('ulgamdagal_ai_settings', array());
        $key = defined('ULGAMDAGAL_AI_API_KEY') ? (string) ULGAMDAGAL_AI_API_KEY : ($settings['key'] ?? '');
        $url = preg_replace('~/chat/?$~', '/dialog-numbers', $settings['url'] ?? '');
        if (!$key || !$url || $url === ($settings['url'] ?? '') || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') { return 'Для получения кодов проверьте настройки API.'; }
        $throttle = 'ulgamdagal_codes_v1_' . substr(hash('sha256', $url . '|' . $key), 0, 24);
        $previous = get_transient($throttle);
        if (is_array($previous)) { return $previous['notice']; }
        $wpdb->query("UPDATE {$logs} l INNER JOIN {$mapping} m ON m.session_hash=l.session_hash SET l.conversation_id=m.conversation_id WHERE l.conversation_id IS NULL");
        $cursor_key = $throttle . '_cursor';
        $cursor = get_transient($cursor_key);
        $cursor = is_string($cursor) ? $cursor : '';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT conversation_id FROM {$logs} WHERE dialog_code IS NULL AND conversation_id IS NOT NULL AND conversation_id > %s ORDER BY conversation_id LIMIT 500", $cursor));
        if (!$ids) { delete_transient($cursor_key); return ''; }
        $notice = 'Коды диалогов пока не обновлены. Проверьте обновление API и повторите через 30 секунд. Журнал доступен; коды появятся после синхронизации.';
        set_transient($throttle, array('notice' => $notice), 30);
        $response = wp_safe_remote_post($url, array('timeout' => 12, 'redirection' => 0, 'limit_response_size' => 131072,
            'headers' => array('Content-Type' => 'application/json', 'X-API-Key' => $key),
            'body' => wp_json_encode(array('conversation_ids' => $ids))));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return $notice; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['dialogs']) || !is_array($data['dialogs'])) { return $notice; }
        foreach ($data['dialogs'] as $item) {
            if (!is_array($item) || !isset($item['conversation_id'], $item['number']) || !is_string($item['conversation_id']) ||
                !in_array($item['conversation_id'], $ids, true) || !is_string($item['number']) || !preg_match('/^[0-9a-f]{16}$/D', $item['number'])) { continue; }
            if ($wpdb->query($wpdb->prepare("UPDATE {$logs} SET dialog_code=%s WHERE conversation_id=%s AND dialog_code IS NULL", $item['number'], $item['conversation_id'])) === false) { return $notice; }
        }
        if (count($ids) === 500) { set_transient($cursor_key, end($ids), HOUR_IN_SECONDS); }
        else { delete_transient($cursor_key); }
        $notice = count($data['dialogs']) < count($ids) ? 'Часть старых диалогов недоступна на сервере; для них код не отображается.' : (count($ids) === 500 ? 'Коды обновляются партиями. Следующая партия — при обновлении журнала через 30 секунд.' : '');
        set_transient($throttle, array('notice' => $notice), 30);
        return $notice;
    }
}
