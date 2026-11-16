<?php
defined('ABSPATH') || exit;

final class Ulgamdagal_Widget_Settings {
    public static function migrate() {
        // Freeze the timezone of legacy local rows before owners change display settings.
        add_option('ulgamdagal_log_storage_timezone', wp_timezone_string(), '', false);
        if (get_option('ulgamdagal_operator_security_v2') === '1') { return; }
        foreach (get_users(array('capability' => 'ulgamdagal_ai_manage_chats')) as $user) {
            if (!$user->has_cap('manage_options')) { $user->remove_cap('ulgamdagal_ai_manage_chats'); }
        }
        $settings = get_option('ulgamdagal_ai_settings', array());
        $settings['operator_users'] = array();
        update_option('ulgamdagal_ai_settings', $settings);
        update_option('ulgamdagal_operator_security_v2', '1', false);
        update_option('ulgamdagal_operator_review_notice', '1', false);
    }

    public static function can_manage() {
        if (current_user_can('manage_options')) { return true; }
        if (get_option('ulgamdagal_operator_security_v2') !== '1') { return false; }
        $settings = get_option('ulgamdagal_ai_settings', array());
        return current_user_can('ulgamdagal_ai_manage_chats') &&
            in_array(get_current_user_id(), array_map('intval', $settings['operator_users'] ?? array()), true);
    }

    public static function notice() {
        if (current_user_can('manage_options') && get_option('ulgamdagal_operator_review_notice')) {
            echo '<div class="notice notice-warning"><p>Ulgamdagal AI: прежние назначения локальных операторов сброшены из-за ошибки автоматической выдачи доступа. Выберите нужных пользователей в настройках плагина и сохраните изменения. Администраторы сохраняют доступ; Telegram-операторы не изменены.</p></div>';
        }
    }

    public static function clean($input) {
        $zone = $input['journal_timezone'] ?? '';
        if (!is_string($zone) || !in_array($zone, timezone_identifiers_list(), true)) { $zone = ''; }
        return array('journal_timezone' => $zone,
            'widget_side' => ($input['widget_side'] ?? '') === 'left' ? 'left' : 'right',
            'widget_bottom_desktop' => self::offset($input['widget_bottom_desktop'] ?? 24),
            'widget_bottom_mobile' => self::offset($input['widget_bottom_mobile'] ?? 10));
    }
    private static function offset($value) { return is_scalar($value) ? max(0, min(400, (int)$value)) : 0; }
    public static function zone() {
        $settings = get_option('ulgamdagal_ai_settings', array());
        $zone = $settings['journal_timezone'] ?? '';
        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? new DateTimeZone($zone) : wp_timezone();
    }
    public static function storage_zone() {
        try { return new DateTimeZone(get_option('ulgamdagal_log_storage_timezone', wp_timezone_string())); }
        catch (Exception $e) { return wp_timezone(); }
    }
    public static function storage_time($timestamp = null) {
        return wp_date('Y-m-d H:i:s', $timestamp === null ? time() : $timestamp, self::storage_zone());
    }
    public static function date($value, $server = false) {
        if (!is_string($value) || $value === '') { return '—'; }
        try {
            $date = new DateTimeImmutable($value, $server ? new DateTimeZone('UTC') : self::storage_zone());
            return $date->setTimezone(self::zone())->format('d.m.Y H:i:s P');
        } catch (Exception $e) { return '—'; }
    }
    public static function boundary($day, $end = false) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, self::zone());
        if (!$date || $date->format('Y-m-d') !== $day) { return null; }
        if ($end) { $date = $date->modify('+1 day'); }
        return $date->setTimezone(self::storage_zone())->format('Y-m-d H:i:s');
    }
    public static function style($settings) {
        $s = self::clean($settings);
        return ';--ua-bottom-desktop:'.$s['widget_bottom_desktop'].'px;--ua-bottom-mobile:'.$s['widget_bottom_mobile'].'px';
    }
    public static function fields($s) {
        $s = self::clean($s);
        ?>
        <tr><th>Часовой пояс журнала</th><td><select name="ulgamdagal_ai_settings[journal_timezone]">
        <option value="">Из WordPress (<?php echo esc_html(wp_timezone_string()); ?>)</option>
        <?php foreach (timezone_identifiers_list() as $zone) { ?><option value="<?php echo esc_attr($zone); ?>" <?php selected($s['journal_timezone'], $zone); ?>><?php echo esc_html($zone); ?></option><?php } ?>
        </select><p class="description">Для Ашхабада выберите Asia/Ashgabat (UTC+05:00). В этом поясе показываются сообщения и работают фильтры дат. История не переписывается.</p></td></tr>
        <tr><th>Положение виджета</th><td><select name="ulgamdagal_ai_settings[widget_side]">
        <option value="right" <?php selected($s['widget_side'], 'right'); ?>>Справа</option>
        <option value="left" <?php selected($s['widget_side'], 'left'); ?>>Слева</option></select>
        <p><label>Отступ снизу на компьютере: <input type="number" min="0" max="400" name="ulgamdagal_ai_settings[widget_bottom_desktop]" value="<?php echo esc_attr($s['widget_bottom_desktop']); ?>"> px</label></p>
        <p><label>Отступ снизу на телефоне: <input type="number" min="0" max="400" name="ulgamdagal_ai_settings[widget_bottom_mobile]" value="<?php echo esc_attr($s['widget_bottom_mobile']); ?>"> px</label></p>
        <p class="description">Телефон — ширина экрана до 600 px. Отступ применяется к кнопке и окну чата; на низком экране ограничивается, чтобы окно оставалось доступным.</p></td></tr>
        <?php
    }
}
