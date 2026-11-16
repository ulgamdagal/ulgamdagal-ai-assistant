<?php
defined('ABSPATH') || exit;
final class Ulgamdagal_Telegram_Bridge {
    public static function enabled() {
        $settings = get_option('ulgamdagal_ai_settings', array());
        return !empty($settings['telegram_enabled']);
    }
    public static function request($request, $action) {
        if ($action !== 'close' && !self::enabled()) { return new WP_Error('disabled', 'Telegram не включён.', array('status' => 400)); }
        $session = $request->get_param('session_id');
        $token = $request->get_param('conversation_token');
        if (!is_string($session) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $session)) { return new WP_Error('invalid_session', 'Некорректная сессия.', array('status' => 400)); }
        if (!$token && $action === 'state') { return new WP_REST_Response(array('active' => false, 'messages' => array())); }
        if (!is_string($token) || !preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/D', $token, $parts)) { return new WP_Error('invalid_conversation', 'Начните диалог.', array('status' => 400)); }
        $hash = hash_hmac('sha256', $session, wp_salt('auth'));
        if (!hash_equals(hash_hmac('sha256', $hash . '|' . $parts[1], wp_salt('auth')), $parts[2])) { return new WP_Error('invalid_conversation', 'Нет доступа к диалогу.', array('status' => 403)); }
        $settings = get_option('ulgamdagal_ai_settings', array());
        $key = defined('ULGAMDAGAL_AI_API_KEY') ? (string) ULGAMDAGAL_AI_API_KEY : ($settings['key'] ?? '');
        $url = preg_replace('~/chat/?$~', ($action === 'close' ? '/conversations/close' : '/telegram/' . $action), $settings['url'] ?? '');
        if (!$key || !$url || $url === ($settings['url'] ?? '') || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') { return new WP_Error('not_configured', 'Проверьте настройки API.', array('status' => 503)); }
        if ($action === 'close' && !self::enabled()) {
            $synced = Ulgamdagal_Operator_Sync::flush($hash);
            if (is_wp_error($synced)) { return $synced; }
        }
        $response = wp_safe_remote_post($url, array('timeout' => 12, 'redirection' => 0, 'limit_response_size' => 1048576,
            'headers' => array('Content-Type' => 'application/json', 'X-API-Key' => $key),
            'body' => wp_json_encode(array('conversation_id' => $parts[1], 'after_id' => absint($request->get_param('after_id')), 'visitor_name' => is_string($request->get_param('visitor_name')) ? sanitize_text_field($request->get_param('visitor_name')) : ''))));
        if (is_wp_error($response)) { return new WP_Error('telegram_unavailable', 'Сервер оператора недоступен. Повторите позже.', array('status' => 503)); }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 403 && is_array($data) && ($data['detail'] ?? null) === 'client_service_paused') {
            return new WP_Error('service_paused', 'Приём сообщений временно приостановлен. Попробуйте позже.', array('status' => 403));
        }
        if ($code !== 200 || !is_array($data)) {
            if ($action === 'close') {
                return new WP_Error($code === 409 ? 'close_pending' : 'close_unavailable', 'Не удалось завершить диалог.', array('status' => $code >= 400 && $code <= 599 ? $code : 502));
            }
            $detail = 'Сейчас не удаётся связаться с оператором. Попробуйте немного позже.';
            return new WP_Error('telegram_error', $detail, array('status' => $code >= 400 && $code <= 599 ? $code : 502));
        }
        $data=Ulgamdagal_Service_Guard::filter($data,$action,$parts[1]);
        if (is_wp_error($data)) { return $data; }
        $result = new WP_REST_Response($data);
        $result->header('Cache-Control', 'no-store');
        return $result;
    }
}
