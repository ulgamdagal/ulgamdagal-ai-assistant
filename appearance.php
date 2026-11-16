<?php
defined('ABSPATH') || exit;

final class Ulgamdagal_Chat_Appearance {
    public static function defaults() {
        return array('accent' => '#0879c9', 'background' => '#f6f9f8', 'assistant' => '#ffffff', 'visitor' => '#0879c9', 'accent_text' => '#ffffff', 'assistant_text' => '#18384d', 'visitor_text' => '#ffffff');
    }
    public static function clean($input, $old = array()) {
        $input = is_array($input) ? $input : array();
        $old = is_array($old) ? $old : array();
        $result = array();
        foreach (self::defaults() as $key => $default) {
            $value = $input[$key] ?? ($old[$key] ?? $default);
            $fallback = isset($old[$key]) && is_string($old[$key]) && preg_match('/^#[0-9a-f]{6}$/iD', $old[$key]) ? $old[$key] : $default;
            $result[$key] = is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtolower($value) : $fallback;
        }
        $mode = $input['text_mode'] ?? ($old['text_mode'] ?? 'auto');
        $result['text_mode'] = $mode === 'manual' ? 'manual' : 'auto';
        return $result;
    }
    public static function ink($hex) {
        $channels = array();
        foreach (array(1, 3, 5) as $offset) {
            $v = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $v <= 0.04045 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        $l = $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
        return ($l + 0.05) / 0.05 >= 1.05 / ($l + 0.05) ? '#000000' : '#ffffff';
    }
    public static function style($colors) {
        $css = '';
        $colors = self::clean($colors);
        foreach (array('accent', 'background', 'assistant', 'visitor') as $key) {
            $value = $colors[$key];
            $ink = $colors['text_mode'] === 'manual' && isset($colors[$key . '_text']) ? $colors[$key . '_text'] : self::ink($value);
            $css .= '--ua-' . $key . ':' . $value . ';--ua-' . $key . '-ink:' . $ink . ';';
        }
        return $css;
    }
    public static function fields($settings) {
        $colors = self::clean($settings['colors'] ?? array());
        $labels = array('accent' => 'Шапка и кнопки', 'background' => 'Фон переписки', 'assistant' => 'Сообщения ассистента и оператора', 'visitor' => 'Сообщения посетителя');
        ?><tr><th>Цвета чата</th><td><div id="ulgamdagal-colors">
        <?php foreach ($labels as $key => $label) { ?>
            <p><label for="ulgamdagal-color-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label><br>
            <input type="color" id="ulgamdagal-color-<?php echo esc_attr($key); ?>" data-color="<?php echo esc_attr($key); ?>" data-default="<?php echo esc_attr(self::defaults()[$key]); ?>" name="ulgamdagal_ai_settings[colors][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($colors[$key]); ?>"></p>
        <?php } ?>
        <p><label for="ulgamdagal-text-mode">Цвет текста</label><br>
        <select id="ulgamdagal-text-mode" name="ulgamdagal_ai_settings[colors][text_mode]">
            <option value="auto" <?php selected($colors['text_mode'], 'auto'); ?>>Подбирать автоматически</option>
            <option value="manual" <?php selected($colors['text_mode'], 'manual'); ?>>Выбрать самостоятельно</option>
        </select></p>
        <div id="ulgamdagal-text-colors">
        <?php foreach (array('accent_text' => 'Текст шапки и кнопок', 'assistant_text' => 'Текст ассистента и оператора', 'visitor_text' => 'Текст посетителя') as $key => $label) { ?>
            <p><label for="ulgamdagal-color-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label><br>
            <input type="color" id="ulgamdagal-color-<?php echo esc_attr($key); ?>" data-color="<?php echo esc_attr($key); ?>" data-default="<?php echo esc_attr(self::defaults()[$key]); ?>" name="ulgamdagal_ai_settings[colors][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($colors[$key]); ?>"></p>
        <?php } ?>
        </div>
        <button type="button" class="button" id="ulgamdagal-colors-reset">Стандартные цвета</button>
        <p class="description">Выберите автоматический или ручной цвет текста. Предпросмотр не отправляет сообщения. Чтобы применить цвета на сайте, нажмите «Сохранить изменения».</p>
        <div id="ulgamdagal-color-preview" style="<?php echo esc_attr(self::style($colors)); ?>;max-width:340px;margin-top:16px;border:1px solid #aaa;border-radius:14px;overflow:hidden;font:14px/1.5 system-ui">
            <div style="padding:16px;background:var(--ua-accent);color:var(--ua-accent-ink)"><strong>Ваш ассистент</strong><br><small>Отвечает ИИ-агент</small></div>
            <div style="padding:16px;background:var(--ua-background)"><div style="padding:12px;margin-bottom:12px;border-radius:12px;background:var(--ua-assistant);color:var(--ua-assistant-ink)">Здравствуйте! Чем могу помочь?</div><div style="padding:12px;margin-left:40px;border-radius:12px;background:var(--ua-visitor);color:var(--ua-visitor-ink)">Расскажите о доставке</div></div>
            <div style="padding:12px;background:#fff;text-align:right"><span style="display:inline-block;padding:8px 14px;border-radius:8px;background:var(--ua-accent);color:var(--ua-accent-ink)">Отправить</span></div>
        </div></div></td></tr><?php
    }
}
