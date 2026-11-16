<?php
defined('ABSPATH') || exit;
final class Ulgamdagal_Server_History {
    public static function render($number) {
        // Never expose history through the visitor-facing REST routes.
        if (!Ulgamdagal_Widget_Settings::can_manage()) { return; }
        $settings = get_option('ulgamdagal_ai_settings', array());
        $key = defined('ULGAMDAGAL_AI_API_KEY') ? (string)ULGAMDAGAL_AI_API_KEY : ($settings['key'] ?? '');
        $url = preg_replace('~/chat/?$~', '/conversations/history', $settings['url'] ?? '');
        echo '<div class="card" style="max-width:none"><h2>Полная история диалога ' . esc_html($number) . '</h2>';
        if (!$key || !$url || $url === ($settings['url'] ?? '') || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            echo '<p>Проверьте настройки подключения API.</p></div>'; return;
        }
        $payload = array('number' => $number, 'origin' => untrailingslashit(home_url()));
        if (isset($_GET['history_cursor']) && is_string($_GET['history_cursor']) && strlen($_GET['history_cursor']) < 1024) {
            $decoded = base64_decode(wp_unslash($_GET['history_cursor']), true);
            $cursor = $decoded !== false ? json_decode($decoded,true) : null;
            if (is_array($cursor) && isset($cursor['created_at'],$cursor['event_id']) && is_string($cursor['created_at']) && is_string($cursor['event_id'])) { $payload['cursor'] = $cursor; }
        }
        $response = wp_safe_remote_post($url, array('timeout' => 12, 'redirection' => 0, 'limit_response_size' => 4194304,
            'headers' => array('Content-Type' => 'application/json','X-API-Key' => $key), 'body' => wp_json_encode($payload)));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            echo '<p>История недоступна: проверьте код диалога и подключение API. Данные ниже — локальный журнал сайта.</p></div>'; return;
        }
        $data = json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($data) || (string)($data['number'] ?? '') !== (string)$number || !isset($data['messages']) || !is_array($data['messages'])) {
            echo '<p>Не удалось загрузить историю.</p></div>'; return;
        }
        echo '<p>' . esc_html(($data['visitor_name'] ?? 'Посетитель') . ' · ' . (($data['status'] ?? '') === 'closed' ? 'Диалог завершён' : 'Диалог открыт')) . '</p>';
        echo '<p>Переписка с сервера: посетитель, ИИ и оператор. Часовой пояс: ' . esc_html(Ulgamdagal_Widget_Settings::zone()->getName()) . '. История доступна в пределах срока хранения; удаление темы Telegram её не удаляет.</p>';
        $roles = array('user'=>'Посетитель','assistant'=>'ИИ','operator'=>'Оператор','system'=>'Система');
        if (!$data['messages']) { echo '<p>Сохранённых сообщений на этой странице нет. История могла быть очищена по сроку хранения.</p>'; }
        foreach ($data['messages'] as $message) {
            if (!is_array($message) || !isset($message['role'],$message['content'],$message['created_at']) || !is_string($message['content'])) { continue; }
            echo '<article style="padding:10px;border-bottom:1px solid #ddd"><strong>' . esc_html($roles[$message['role']] ?? 'Сообщение') . '</strong> <small>' . esc_html(Ulgamdagal_Widget_Settings::date($message['created_at'], true)) . '</small><div style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html($message['content']) . '</div></article>';
        }
        $base = add_query_arg(array('page'=>'ulgamdagal-ai-logs','number'=>$number),admin_url('tools.php'));
        echo '<p><a href="' . esc_url($base) . '">К началу истории / обновить</a>';
        if (!empty($data['next_cursor']) && is_array($data['next_cursor'])) {
            echo ' · <a href="' . esc_url(add_query_arg('history_cursor',base64_encode(wp_json_encode($data['next_cursor'])),$base)) . '">Следующие сообщения →</a>';
        }
        echo '</p></div>';
    }
}
