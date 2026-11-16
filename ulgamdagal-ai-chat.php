<?php
/**
 * Plugin Name: Ulgamdagal AI Chat
 * Description: Умный ИИ-чат для сайта с функцией вызова живого оператора в бот. Искусственный интеллект отвечает на вопросы клиентов 24/7, а сложные диалоги одним кликом переводит на менеджера. Для получения API-ключа напишите в Telegram: @ulgamdagal_support
 * Version: 1.1.1
 * Author: Ulgamdagal Team
 * Author URI: https://api.ulgamdagal.com
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: ulgamdagal-ai-chat
 */

defined('ABSPATH') || exit;
require_once __DIR__ . '/operator-sync.php';
require_once __DIR__ . '/telegram-bridge.php';
require_once __DIR__ . '/service-guard.php';
require_once __DIR__ . '/site-policy.php';
require_once __DIR__ . '/dialog-journal.php';
require_once __DIR__ . '/server-history.php';
require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/widget-settings.php';
require_once __DIR__ . '/material-documents.php';
require_once __DIR__ . '/knowledge-sync.php';

final class Ulgamdagal_AI_Chat {
    const OPTION = 'ulgamdagal_ai_settings';

    public static function init() {
        add_action('admin_init', array('Ulgamdagal_Widget_Settings', 'migrate'), 0);
        add_action('admin_notices', array('Ulgamdagal_Widget_Settings', 'notice'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_action('admin_init', array(__CLASS__, 'maybe_install_logs'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
        add_action('admin_post_ulgamdagal_block_ip', array(__CLASS__, 'block_ip_from_log'));
        add_action('ulgamdagal_ai_cleanup_logs', array(__CLASS__, 'cleanup_logs'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_shortcode('ulgamdagal_ai_chat', array(__CLASS__, 'chat'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_floating_assets'));
        add_action('wp_footer', array(__CLASS__, 'floating_chat'));
    }

    public static function menu() {
        add_options_page('Ulgamdagal AI Chat', 'Ulgamdagal AI Chat', 'manage_options', 'ulgamdagal-ai-chat', array(__CLASS__, 'page'));
        add_management_page('Журнал AI-чата', 'Журнал AI-чата', current_user_can('manage_options') ? 'manage_options' : 'ulgamdagal_ai_manage_chats', 'ulgamdagal-ai-logs', array(__CLASS__, 'logs_page'));
        if (!self::can_manage_chats()) { remove_submenu_page('tools.php', 'ulgamdagal-ai-logs'); }
    }

    public static function settings() {
        register_setting('ulgamdagal_ai', self::OPTION, array('sanitize_callback' => array(__CLASS__, 'sanitize'), 'show_in_rest' => false));
    }

    public static function sanitize($input) {
        $old = get_option(self::OPTION, array());
        if (!is_array($input)) { return $old; }
        $url = $old['url'] ?? 'https://api.ulgamdagal.com/v1/ai/chat';
        if ('https' !== wp_parse_url($url, PHP_URL_SCHEME) || !wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_QUERY) || wp_parse_url($url, PHP_URL_FRAGMENT)) {
            add_settings_error(self::OPTION, 'url', 'Укажите полный публичный HTTPS URL endpoint без query-параметров.');
            return $old;
        }
        $key = isset($input['key']) && is_string($input['key']) ? trim($input['key']) : '';
        if ($key !== '' && !preg_match('/^[\x21-\x7E]{1,512}$/D', $key)) {
            add_settings_error(self::OPTION, 'key', 'Ключ должен содержать только печатные ASCII-символы без пробелов.');
            return $old;
        }
        $assistant_name = self::public_name($input);
        $store_info = isset($input['store_info']) && is_string($input['store_info']) ? sanitize_textarea_field($input['store_info']) : '';
        if (strlen($store_info) > 10000) {
            add_settings_error(self::OPTION, 'store_info', 'Информация о магазине должна быть не длиннее 10000 байт.');
            return $old;
        }
        $knowledge_pages = isset($input['knowledge_pages']) && is_array($input['knowledge_pages']) ? array_map('absint', $input['knowledge_pages']) : array();
        $knowledge_pages = array_slice(array_values(array_unique(array_filter($knowledge_pages, function ($id) {
            return get_post_type($id) === 'page' && get_post_status($id) === 'publish';
        }))), 0, 20);
        $overrides=array();
        foreach($knowledge_pages as $page_id){
            $value=$input['knowledge_overrides'][$page_id] ?? '';
            if(is_string($value)){$value=sanitize_textarea_field($value);if($value!=='')$overrides[$page_id]=Ulgamdagal_Knowledge_Sync::limit_chars($value,2800);}
        }
        $documents=Ulgamdagal_Material_Documents::clean($input['documents'] ?? null,$old['documents'] ?? array());
        if($documents===null){add_settings_error(self::OPTION,'documents','Проверьте материалы: название, текст, ссылку, предел 20 записей / 1 МБ.');return $old;}
        $avatar_id = isset($input['avatar_id']) ? absint($input['avatar_id']) : 0;
        if ($avatar_id > 0 && !wp_attachment_is_image($avatar_id)) { $avatar_id = 0; }
        $blocked_networks = self::sanitize_blocklist($input['blocked_networks'] ?? '');
        $operator_users = isset($input['operator_users']) && is_array($input['operator_users']) ? array_values(array_unique(array_filter(array_map('absint', $input['operator_users']), 'get_userdata'))) : array();
        self::sync_operator_users($old['operator_users'] ?? array(), $operator_users);
        delete_option('ulgamdagal_operator_review_notice');
        return array_merge(Ulgamdagal_Widget_Settings::clean($input), array(
            'allow_remote_edits' => !empty($input['allow_remote_edits']) ? 1 : 0,
            'link_domains' => implode("\n",Ulgamdagal_Site_Policy::domains($input['link_domains']??'')),
            'colors' => Ulgamdagal_Chat_Appearance::clean($input['colors'] ?? array(), $old['colors'] ?? array()),
            'url' => $url,
            'key' => !empty($input['delete_key']) ? '' : ($key !== '' ? $key : ($old['key'] ?? '')),
            'telegram_enabled' => !empty($input['telegram_enabled']) ? 1 : 0,
            'woocommerce' => !empty($input['woocommerce']) ? 1 : 0,
            'assistant_name' => $assistant_name,
            'store_info' => $store_info,
            'knowledge_pages' => $knowledge_pages,
            'knowledge_overrides' => $overrides,
            'documents' => $documents,
            'public_chat' => !empty($input['public_chat']) ? 1 : 0,
            'rate_limit' => isset($input['rate_limit']) ? max(1, min(30, absint($input['rate_limit']))) : 6,
            'floating_chat' => !empty($input['floating_chat']) ? 1 : 0,
            'avatar_id' => $avatar_id,
            'remember_chat' => !empty($input['remember_chat']) ? 1 : 0,
            'history_hours' => isset($input['history_hours']) ? max(1, min(168, absint($input['history_hours']))) : 24,
            'log_chats' => !empty($input['log_chats']) ? 1 : 0,
            'retention_days' => isset($input['retention_days']) ? max(1, min(30, absint($input['retention_days']))) : 30,
            'blocked_networks' => $blocked_networks,
            'operator_users' => $operator_users,
            'operator_timeout' => isset($input['operator_timeout']) ? max(2, min(60, absint($input['operator_timeout']))) : 10,
        ));
    }

    public static function public_name($settings) {
        $name = isset($settings['assistant_name']) && is_string($settings['assistant_name']) ? sanitize_text_field($settings['assistant_name']) : '';
        $name = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
        $characters = is_string($name) ? preg_split('//u', trim($name), -1, PREG_SPLIT_NO_EMPTY) : false;
        $name = is_array($characters) ? implode('', array_slice($characters, 0, 80)) : '';
        return $name !== '' ? $name : 'Ulgamdagal AI';
    }

    private static function can_manage_chats() {
        return Ulgamdagal_Widget_Settings::can_manage();
    }

    private static function sync_operator_users($old_ids, $new_ids) {
        $old_ids = is_array($old_ids) ? array_map('absint', $old_ids) : array();
        foreach (array_diff($old_ids, $new_ids) as $user_id) {
            $user = get_userdata($user_id);
            if ($user) { $user->remove_cap('ulgamdagal_ai_manage_chats'); }
        }
        foreach ($new_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) { $user->add_cap('ulgamdagal_ai_manage_chats'); }
        }
    }

    private static function sanitize_blocklist($value) {
        if (!is_string($value)) { return ''; }
        $valid = array();
        foreach (preg_split('/\r\n|\r|\n/', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') { continue; }
            if (filter_var($entry, FILTER_VALIDATE_IP)) {
                $valid[] = $entry;
                continue;
            }
            if (preg_match('/^(.+)\/(\d{1,3})$/D', $entry, $parts) && filter_var($parts[1], FILTER_VALIDATE_IP)) {
                $packed = inet_pton($parts[1]);
                $maximum = strlen($packed) === 4 ? 32 : 128;
                $prefix = (int) $parts[2];
                if ($prefix >= 0 && $prefix <= $maximum) {
                    $valid[] = $parts[1] . '/' . $prefix;
                    continue;
                }
            }
            add_settings_error(self::OPTION, 'blocked_networks', 'Пропущена некорректная запись блокировки: ' . esc_html($entry));
        }
        return implode("\n", array_values(array_unique($valid)));
    }

    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function ip_matches_network($ip, $network) {
        $ip=Ulgamdagal_Site_Policy::normalize_ip($ip);
        $network=Ulgamdagal_Site_Policy::normalize_network($network);
        if (strpos($network, '/') === false) { return hash_equals($network, $ip); }
        list($base, $prefix) = explode('/', $network, 2);
        $ip_binary = inet_pton($ip);
        $base_binary = inet_pton($base);
        if ($ip_binary === false || $base_binary === false || strlen($ip_binary) !== strlen($base_binary)) { return false; }
        $bits = (int) $prefix;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ip_binary, 0, $bytes) !== substr($base_binary, 0, $bytes)) { return false; }
        if ($remainder === 0) { return true; }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ip_binary[$bytes]) & $mask) === (ord($base_binary[$bytes]) & $mask);
    }

    private static function ip_is_blocked($ip, $blocklist) {
        if ($ip === '' || !is_string($blocklist)) { return false; }
        foreach (preg_split('/\r\n|\r|\n/', $blocklist) as $network) {
            if ($network !== '' && self::ip_matches_network($ip, trim($network))) { return true; }
        }
        return false;
    }

    public static function admin_assets($hook) {
        if (($hook === 'settings_page_ulgamdagal-ai-chat' && current_user_can('manage_options')) || ($hook === 'tools_page_ulgamdagal-ai-logs' && self::can_manage_chats())) {
            wp_enqueue_style('ulgamdagal-admin-ui',plugins_url('assets/admin-ui.css',__FILE__),array(),'1.1.1');
        }
        if ($hook === 'settings_page_ulgamdagal-ai-chat' && current_user_can('manage_options')) {
            wp_enqueue_script('ulgamdagal-admin-ui',plugins_url('assets/admin-ui.js',__FILE__),array(),'1.1.1',true);
        }
        if ($hook === 'settings_page_ulgamdagal-ai-chat') {
            wp_enqueue_media();
            wp_enqueue_script('ulgamdagal-ai-colors', plugins_url('assets/appearance.js', __FILE__), array(), '1.1.1', true);
            wp_enqueue_script('ulgamdagal-ai-chat-admin', plugins_url('assets/admin.js', __FILE__), array(), '1.1.1', true);
        }
        if ($hook === 'tools_page_ulgamdagal-ai-logs' && self::can_manage_chats()) {
            wp_enqueue_script('ulgamdagal-ai-chat-logs', plugins_url('assets/logs.js', __FILE__), array(), '1.1.1-sound-1', true);
        }
    }

    private static function key() {
        if (defined('ULGAMDAGAL_AI_API_KEY')) { return (string) ULGAMDAGAL_AI_API_KEY; }
        $settings = get_option(self::OPTION, array());
        return $settings['key'] ?? '';
    }

    public static function gateway_timeout($timeout) {
        return 60;
    }

    public static function gateway_curl_timeout($handle, $request, $url) {
        $settings = get_option(self::OPTION, array());
        if (!empty($settings['url']) && untrailingslashit($url) === untrailingslashit($settings['url'])) {
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($handle, CURLOPT_TIMEOUT, 60);
        }
    }

    public static function diagnostic_curl_options($handle, $request, $url) {
        $settings = get_option(self::OPTION, array());
        if (!empty($settings['url']) && untrailingslashit($url) === untrailingslashit($settings['url'])) {
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($handle, CURLOPT_TIMEOUT, 20);
        }
    }

    private static function ip_diagnostics() {
        if (!current_user_can('manage_options')) { return; }
        $raw=isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $normalized=Ulgamdagal_Site_Policy::normalize_ip($raw);
        $public=filter_var($normalized,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
        foreach (array('0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4','100::/64','2001:db8::/32','fc00::/7','fe80::/10','ff00::/8') as $network) {
            if (self::ip_matches_network($normalized,$network)) { $public=false; break; }
        }
        echo '<h2>Диагностика адреса</h2><p>Ваш IP по версии PHP: <code>'.esc_html($raw===''?'не определён':$raw).'</code></p>';
        echo '<p>Группа ограничения: <code>'.esc_html(Ulgamdagal_Service_Guard::ip_identity($raw)).'</code>. IPv4 — отдельный адрес; IPv6 — подсеть /64.</p>';
        if (!$public) { echo '<p><strong>PHP видит локальный, частный, зарезервированный или некорректный адрес. Проверьте настройки доверенного прокси: посетители могут делить общий лимит.</strong></p>'; }
        echo '<p>Это адрес текущего администратора, а не проверка всех посетителей. Сравните IP в журнале с разных сетей. Общий адрес бывает нормальным для офиса или мобильного оператора. Заголовки прокси здесь не используются.</p>';
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $settings = get_option(self::OPTION, array());
        $diagnostic = '';
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ulgamdagal_check'])) {
            check_admin_referer('ulgamdagal_check_connection');
            $diagnostic = self::check_connection();
        }
        ?>
        <div class="wrap ulg-admin ulg-admin--settings">
        <header class="ulg-hero"><div><span class="ulg-eyebrow">Ulgamdagal · рабочее пространство</span><h1>Ваш ассистент на сайте <span class="ulg-version">1.1.1</span></h1><p>Всё для общения с клиентами: от первого ответа до помощи оператора.</p></div><a href="<?php echo esc_url(admin_url('tools.php?page=ulgamdagal-ai-logs')); ?>">Открыть журнал →</a></header>
        <nav class="ulg-section-nav" aria-label="Разделы настроек"><a href="#ulg-connect">Подключение</a></nav>
        <section class="ulg-card" id="ulg-connect"><h2>Подключение к сервису</h2><p class="ulg-card-intro">Сохраните API-ключ и проверьте доступ к сервису.</p>
        <span class="ulg-key-state"><?php echo self::key() !== '' ? 'Ключ сохранён · соединение проверяется отдельно' : 'Для подключения нужен API-ключ'; ?></span>
        <p class="ulg-placement">Для размещения чата внутри страницы используйте шорткод <code>[ulgamdagal_ai_chat]</code>.</p>
        <table class="form-table"><tr><th><label for="ulgamdagal-key">API-ключ</label></th><td>
        <input form="ulgamdagal-settings" type="password" id="ulgamdagal-key" name="ulgamdagal_ai_settings[key]" value="" autocomplete="new-password">
        <p class="description"><?php echo self::key() !== '' ? 'Ключ задан. Оставьте поле пустым, чтобы сохранить его.' : 'Введите ключ gateway.'; ?> Сохранённое значение не выводится в HTML.</p>
        <label><input form="ulgamdagal-settings" type="checkbox" name="ulgamdagal_ai_settings[delete_key]" value="1"> Удалить ключ из настроек</label>
        <?php if (defined('ULGAMDAGAL_AI_API_KEY')) { echo '<p>Используется ключ из константы ULGAMDAGAL_AI_API_KEY в wp-config.php.</p>'; } ?>
        </td></tr></table>
        <p><button type="submit" class="button button-primary" form="ulgamdagal-settings">Сохранить настройки</button></p>
        <div class="ulg-connection-check"><h2>Проверка соединения</h2>
        <p>Проверка соединения и сохранённого API-ключа без обращения к ИИ. После ввода ключа сначала сохраните настройки. Ожидание до 20 секунд.</p>
        <form method="post" action="<?php echo esc_url(admin_url('options-general.php?page=ulgamdagal-ai-chat')); ?>">
        <?php wp_nonce_field('ulgamdagal_check_connection'); ?>
        <input type="hidden" name="ulgamdagal_check" value="1">
        <?php submit_button('Проверить соединение', 'secondary', 'submit', false); ?>
        </form>
        <?php if ($diagnostic !== '') { ?>
            <div class="notice notice-info inline" role="status"><p style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html($diagnostic); ?></p></div>
        <?php } ?>

        </div></section>
        <form id="ulgamdagal-settings" action="options.php" method="post">
        <?php settings_fields('ulgamdagal_ai'); ?>
        <table class="form-table"><?php Ulgamdagal_Chat_Appearance::fields($settings); Ulgamdagal_Widget_Settings::fields($settings); Ulgamdagal_Site_Policy::fields($settings); ?><tr><th><label for="ulgamdagal-assistant-name">Имя ассистента</label></th><td>
        <input class="regular-text" type="text" maxlength="80" id="ulgamdagal-assistant-name" name="ulgamdagal_ai_settings[assistant_name]" value="<?php echo esc_attr($settings['assistant_name'] ?? 'Ulgamdagal AI'); ?>">
        <p class="description">Публичное имя: отображается в чате и используется, когда ИИ представляется. Основные правила поведения задаются администратором сервиса.</p>
        </td></tr><tr><th>Аватар ассистента</th><td>
        <?php
        $avatar_id = absint($settings['avatar_id'] ?? 0);
        $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
        $default_avatar_url = plugins_url('assets/avatar-default.png', __FILE__);
        ?>
        <img id="ulgamdagal-avatar-preview" src="<?php echo esc_url($avatar_url ?: $default_avatar_url); ?>" data-default="<?php echo esc_url($default_avatar_url); ?>" alt="" style="display:block;width:72px;height:72px;object-fit:cover;border-radius:50%;margin-bottom:10px">
        <input type="hidden" id="ulgamdagal-avatar-id" name="ulgamdagal_ai_settings[avatar_id]" value="<?php echo esc_attr($avatar_id); ?>">
        <button type="button" class="button" id="ulgamdagal-avatar-select">Выбрать изображение</button>
        <button type="button" class="button" id="ulgamdagal-avatar-default">Вернуть стандартный аватар</button>
        <p class="description">Выберите квадратное изображение из медиатеки WordPress. Если изображение не выбрано, используется стандартный аватар девушки.</p>
        </td></tr><tr><th><label for="ulgamdagal-store-info">Информация о магазине</label></th><td>
        <textarea class="large-text" rows="10" maxlength="10000" id="ulgamdagal-store-info" name="ulgamdagal_ai_settings[store_info]" placeholder="Рабочее время: ...&#10;Адрес: ...&#10;Телефон: ...&#10;Доставка: ...&#10;Оплата: ..."><?php echo esc_textarea($settings['store_info'] ?? ''); ?></textarea>
        <p class="description">Добавьте актуальные сведения: рабочее время, адрес, контакты, доставку, оплату и возврат. Эти данные добавляются к запросу на сервере.</p>
        </td></tr><tr><th>Страницы базы знаний</th><td>
        <fieldset style="max-height:280px;overflow:auto;border:1px solid #c3c4c7;padding:12px;background:#fff">
        <?php
        $selected_pages = isset($settings['knowledge_pages']) && is_array($settings['knowledge_pages']) ? array_map('absint', $settings['knowledge_pages']) : array();
        $knowledge_pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'ASC', 'number' => 200, 'post_status' => 'publish'));
        if (!$knowledge_pages) { echo '<p>Опубликованные страницы не найдены.</p>'; }
        foreach ($knowledge_pages as $knowledge_page) {
            ?>
            <label style="display:block;margin:0 0 8px">
                <input type="checkbox" name="ulgamdagal_ai_settings[knowledge_pages][]" value="<?php echo esc_attr($knowledge_page->ID); ?>" <?php checked(in_array((int) $knowledge_page->ID, $selected_pages, true)); ?>>
                <?php echo esc_html($knowledge_page->post_title ?: ('Страница #' . $knowledge_page->ID)); ?>
            </label>
            <?php
            if(in_array((int)$knowledge_page->ID,$selected_pages,true)){
                echo '<details><summary>Текст этой страницы для ИИ</summary><p>До 2800 символов. Пустое поле — использовать начало текста страницы. Публичная страница не меняется.</p><textarea class="large-text" rows="6" maxlength="2800" name="ulgamdagal_ai_settings[knowledge_overrides]['.esc_attr($knowledge_page->ID).']">'.esc_textarea($settings['knowledge_overrides'][$knowledge_page->ID] ?? '').'</textarea></details>';
            }
        }
        ?>
        </fieldset>
        <p class="description">Выберите до 20 страниц с контактами, графиком, доставкой, оплатой, возвратом и другой справочной информацией. В запрос попадут фрагменты максимум трёх наиболее подходящих страниц.</p>
        <?php Ulgamdagal_Material_Documents::render($settings); ?>
        </td></tr><tr><th>WooCommerce</th><td>
        <label><input type="checkbox" name="ulgamdagal_ai_settings[woocommerce]" value="1" <?php checked(!empty($settings['woocommerce'])); ?>> Добавлять в запрос данные подходящих опубликованных товаров</label>
        <p class="description">Передаются название, отображаемая цена, наличие и ссылка — не заказы и не данные покупателей.</p>
        </td></tr><tr><th>Доступ посетителей</th><td>
        <label><input type="checkbox" name="ulgamdagal_ai_settings[public_chat]" value="1" <?php checked(!empty($settings['public_chat'])); ?>> Разрешить публичный чат</label>
        <p class="description">После включения шорткод и серверный прокси станут доступны посетителям без входа. API-ключ останется на сервере.</p>
        </td></tr><tr><th><label for="ulgamdagal-rate-limit">Лимит запросов</label></th><td>
        <input type="number" min="1" max="30" id="ulgamdagal-rate-limit" name="ulgamdagal_ai_settings[rate_limit]" value="<?php echo esc_attr($settings['rate_limit'] ?? 6); ?>"> в минуту с одного IP
        <p class="description">Защищает сайт от частых сообщений. Обычно достаточно 6 в минуту. Расход ИИ дополнительно ограничивается в кабинете сервиса.</p>
        </td></tr><tr><th>Плавающее окно</th><td>
        <label><input type="checkbox" name="ulgamdagal_ai_settings[floating_chat]" value="1" <?php checked(!empty($settings['floating_chat'])); ?>> Показывать плавающую кнопку чата на всём сайте</label>
        <p class="description">По нажатию открывается окно живого чата. Шорткод на отдельной странице после этого не обязателен.</p>
        </td></tr><tr><th>История диалога</th><td>
        <label><input type="checkbox" name="ulgamdagal_ai_settings[remember_chat]" value="1" <?php checked(!empty($settings['remember_chat'])); ?>> Сохранять диалог в браузере посетителя</label>
        <p><label>Срок хранения: <input type="number" min="1" max="168" name="ulgamdagal_ai_settings[history_hours]" value="<?php echo esc_attr($settings['history_hours'] ?? 24); ?>"> часов</label></p>
        <p class="description">В браузере сохраняются последние 10 пар сообщений. Запись в журнал WordPress настраивается отдельно ниже.</p>
        </td></tr><tr><th>Журнал переписки</th><td>
        <label><input type="checkbox" name="ulgamdagal_ai_settings[log_chats]" value="1" <?php checked(!empty($settings['log_chats'])); ?>> Сохранять вопросы и ответы в WordPress</label>
        <p><label>Хранить: <input type="number" min="1" max="30" name="ulgamdagal_ai_settings[retention_days]" value="<?php echo esc_attr($settings['retention_days'] ?? 30); ?>"> дней</label></p>
        <p class="description">Журнал доступен в «Инструменты → Журнал AI-чата». Вопросы, ответы и IP хранятся указанное число дней.</p>
        </td></tr><tr><th>Операторы чата</th><td>
        <fieldset style="max-height:220px;overflow:auto;border:1px solid #c3c4c7;padding:12px;background:#fff">
        <?php
        ?><p><label><input type="checkbox" name="ulgamdagal_ai_settings[telegram_enabled]" value="1" <?php checked(!empty($settings['telegram_enabled'])); ?>> Операторы через Telegram (после запуска серверного worker)</label></p>
        <p>В этом режиме принятие диалогов и ответы выполняются в Telegram. Перед переключением завершите текущие диалоги операторов.</p><?php
        $selected_operators = isset($settings['operator_users']) && is_array($settings['operator_users']) ? array_map('absint', $settings['operator_users']) : array();
        foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC', 'number' => 200)) as $operator_user) {
            ?>
            <label style="display:block;margin-bottom:8px"><input type="checkbox" name="ulgamdagal_ai_settings[operator_users][]" value="<?php echo esc_attr($operator_user->ID); ?>" <?php checked(in_array((int) $operator_user->ID, $selected_operators, true)); ?>> <?php echo esc_html($operator_user->display_name . ' (' . $operator_user->user_login . ')'); ?></label>
            <?php
        }
        ?>
        </fieldset>
        <p class="description">Отмеченные пользователи получают только журнал, IP-блокировку и управление диалогами. Администраторы сайта имеют доступ всегда и не обязаны быть отмечены.</p>
        <p><label>Автоматически вернуть AI после <input type="number" min="2" max="60" name="ulgamdagal_ai_settings[operator_timeout]" value="<?php echo esc_attr($settings['operator_timeout'] ?? 10); ?>"> минут без ответа оператора</label></p>
        <p class="description">Отсчёт начинается при перехвате и начинается заново после каждого ответа оператора.</p>
        </td></tr><tr><th><label for="ulgamdagal-blocked-networks">Блокировка IP</label></th><td>
        <textarea class="large-text code" rows="7" id="ulgamdagal-blocked-networks" name="ulgamdagal_ai_settings[blocked_networks]" placeholder="192.0.2.15&#10;198.51.100.0/24&#10;2001:db8::/32"><?php echo esc_textarea($settings['blocked_networks'] ?? ''); ?></textarea>
        <p class="description">Одна запись на строку: отдельный IPv4/IPv6 или CIDR-диапазон. Пример диапазона: 192.0.2.0/24. Блокировка применяется только к AI-чату.</p>
        </td></tr></table><?php submit_button(); ?></form>
        <details class="ulg-advanced"><summary>Диагностика IP и ограничения</summary><?php self::ip_diagnostics(); ?></details>
        <section class="ulg-card ulg-sync"><?php Ulgamdagal_Knowledge_Sync::render(); ?></section>
        </div>
        <?php
    }

    private static function check_connection() {
        if (!current_user_can('manage_options')) { return 'Недостаточно прав.'; }
        $settings = get_option(self::OPTION, array());
        $key = self::key();
        if ($key === '') { return 'API-ключ не задан. Введите ключ, сохраните настройки и повторите проверку.'; }
        $url = preg_replace('~/chat/?$~', '/dialog-numbers', $settings['url'] ?? '');
        if (!$url || $url === ($settings['url'] ?? '') || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return 'Адрес подключения некорректен. Обратитесь к администратору сервиса.';
        }
        $limit = 'ulgamdagal_check_' . get_current_user_id();
        if (get_transient($limit)) { return 'Повторите проверку через 25 секунд.'; }
        set_transient($limit, 1, 25);
        // Authenticated, read-only lookup of a fresh random ID: no AI call, writes or message quota.
        $response = wp_safe_remote_post($url, array('timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 4096,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-API-Key' => $key),
            'body' => wp_json_encode(array('conversation_ids' => array(wp_generate_uuid4())))));
        if (is_wp_error($response)) { return 'Соединение не подтверждено: сервер недоступен или не ответил вовремя. Попробуйте позже. Ключ пока не проверен.'; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status === 200 && is_array($data) && isset($data['dialogs']) && is_array($data['dialogs']) && count($data['dialogs']) === 0) {
            return 'Соединение установлено. API-ключ принят сервером, доступ к сервису подтверждён. Проверено сейчас без отправки вопроса ИИ. Генерация ответов и оставшийся лимит этой проверкой не проверяются.';
        }
        if ($status === 401) { return 'Сервер ответил, но доступ не подтверждён (HTTP 401). Проверьте сохранённый API-ключ: он может быть неверным, отключённым или просроченным.'; }
        if ($status === 403) { return 'Сервер ответил, но доступ запрещён (HTTP 403). Обратитесь к администратору сервиса; действительность ключа не подтверждена.'; }
        return 'Подключение не подтверждено: неожиданный ответ сервера (HTTP '.$status.'). Повторите позже или обратитесь к администратору сервиса.';
    }

    public static function telegram_request($request) {
        $error=self::validate_chat_request($request);
        if (is_wp_error($error)) { return $error; }
        return Ulgamdagal_Service_Guard::run($request, 'request', function() use ($request) { return self::telegram_request_guarded($request); });
    }

    private static function telegram_request_guarded($request) {
        $error = self::validate_chat_request($request);
        if (is_wp_error($error)) { return $error; }
        $settings = get_option(self::OPTION, array());
        if (self::ip_is_blocked(self::client_ip(), $settings['blocked_networks'] ?? '')) { return new WP_Error('blocked', 'Доступ ограничен.', array('status' => 403)); }
        $limit = self::check_rate_limit($settings);
        if (is_wp_error($limit)) { return $limit; }
        return Ulgamdagal_Telegram_Bridge::request($request, 'request');
    }

    public static function close_dialog($request) {
        $error=self::validate_chat_request($request);
        if (is_wp_error($error)) { return $error; }
        return Ulgamdagal_Service_Guard::run($request, 'close', function() use ($request) { return self::close_dialog_guarded($request); });
    }

    private static function close_dialog_guarded($request) {
        $error = self::validate_chat_request($request);
        if (is_wp_error($error)) { return $error; }
        $hash = self::session_hash($request->get_param('session_id'));
        $result = Ulgamdagal_Telegram_Bridge::request($request, 'close');
        if ($result instanceof WP_REST_Response && !empty($result->get_data()['closed'])) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'ulgamdagal_ai_sessions', array('active'=>0), array('session_hash'=>$hash), array('%d'), array('%s'));
        }
        return $result;
    }

    public static function request_status($request) {
        $error=self::validate_chat_request($request);
        if (is_wp_error($error)) { return $error; }
        return Ulgamdagal_Service_Guard::run($request,'status',function() use ($request) { return self::proxy($request,true); });
    }

    public static function routes() {
        register_rest_route('ulgamdagal-ai/v1', '/request-status', array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'chat_permission'),
            'callback'=>array(__CLASS__,'request_status'),
        ));
        register_rest_route('ulgamdagal-ai/v1', '/conversation-close', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'chat_permission'),
            'callback' => array(__CLASS__, 'close_dialog'),
        ));
        register_rest_route('ulgamdagal-ai/v1', '/operator-request', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'chat_permission'),
            'callback' => array(__CLASS__, 'telegram_request'),
        ));
        register_rest_route('ulgamdagal-ai/v1', '/chat', array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'chat_permission'),
            'callback' => array(__CLASS__, 'proxy'),
        ));
        register_rest_route('ulgamdagal-ai/v1', '/operator-messages', array(
            'methods' => 'POST', 'permission_callback' => array(__CLASS__, 'chat_permission'),
            'callback' => array(__CLASS__, 'operator_messages'),
        ));
    }

    public static function chat_permission() {
        if (current_user_can('manage_options')) { return true; }
        $settings = get_option(self::OPTION, array());
        return !empty($settings['public_chat']) ? true : new WP_Error('chat_private', 'Чат пока доступен только администратору.', array('status' => 403));
    }

    public static function proxy($request, $status_only = false) {
        if ($status_only) { return self::proxy_guarded($request,true); }
        return Ulgamdagal_Service_Guard::chat(function() use ($request) {
            return self::proxy_guarded($request,false);
        });
    }

    private static function proxy_guarded($request, $status_only = false) {
        $security_error = self::validate_chat_request($request);
        if (is_wp_error($security_error)) { return Ulgamdagal_Service_Guard::not_sent($security_error); }
        $message = $request->get_param('message');
        if (!is_string($message) || trim($message) === '' || strlen($message) > 8000) {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_message', 'Введите сообщение до 8000 байт.', array('status' => 400)));
        }
        if (wp_strip_all_tags($message) !== $message || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_message', 'HTML-теги и управляющие символы в сообщениях запрещены.', array('status' => 400)));
        }
        $settings = get_option(self::OPTION, array());
        $key = self::key();
        $client_ip = self::client_ip();
        if (self::ip_is_blocked($client_ip, $settings['blocked_networks'] ?? '')) {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('access_denied', 'Доступ к чату ограничен.', array('status' => 403)));
        }
        if ((string) $request->get_param('website') !== '') {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_request', 'Не удалось отправить сообщение.', array('status' => 400)));
        }
        if (!$status_only) {
        $limit_result = self::check_rate_limit($settings);
        if (is_wp_error($limit_result)) { return Ulgamdagal_Service_Guard::not_sent($limit_result); }
        }
        $session_hash = self::session_hash($request->get_param('session_id'));
        if ($session_hash === '') {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_session', 'Некорректный диалог.', array('status' => 400)));
        }
        $browser_request_id = $request->get_param('request_id');
        if ($status_only && $browser_request_id === null) { return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_request_id','Нужен ID ожидающего сообщения.',array('status'=>400))); }
        if ($browser_request_id === null) { $browser_request_id = wp_generate_uuid4(); }
        if (!is_string($browser_request_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $browser_request_id)) {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_request_id', 'Некорректный идентификатор сообщения.', array('status' => 400)));
        }
        // Scope idempotency to the visitor session, not just the shared API key.
        $rid = hash_hmac('sha256', $session_hash . '|' . strtolower($browser_request_id), wp_salt('auth'));
        $api_request_id = substr($rid,0,8) . '-' . substr($rid,8,4) . '-' . substr($rid,12,4) . '-' . substr($rid,16,4) . '-' . substr($rid,20,12);
        $conversation_id = null;
        $token = $request->get_param('conversation_token');
        if ($token !== null && $token !== '') {
            if (!is_string($token) || !preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/D', $token, $parts)) {
                return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_conversation', 'Некорректный диалог. Начните новый.', array('status' => 400)));
            }
            $expected = hash_hmac('sha256', $session_hash . '|' . $parts[1], wp_salt('auth'));
            if (!hash_equals($expected, $parts[2])) {
                return Ulgamdagal_Service_Guard::not_sent(new WP_Error('invalid_conversation', 'Диалог не принадлежит этой сессии. Начните новый.', array('status' => 403)));
            }
            $conversation_id = $parts[1];
        }
        if ($status_only) {
            return self::recovery_status($request,$settings,$key,$session_hash,$conversation_id,$api_request_id,$browser_request_id,$message,$client_ip);
        }
        if ($conversation_id === null) {
            global $wpdb;
            $mapped=$wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM '.$wpdb->prefix.'ulgamdagal_sync_mapping WHERE session_hash=%s',$session_hash));
            if ($wpdb->last_error) { return Ulgamdagal_Service_Guard::not_sent(new WP_Error('session_unavailable','Не удалось проверить диалог.',array('status'=>503))); }
            if ($mapped) { return Ulgamdagal_Service_Guard::not_sent(new WP_Error('conversation_token_required','Для продолжения нужен токен диалога. Начните новый диалог.',array('status'=>403))); }
        }
        if ($conversation_id !== null) {
            $binding = Ulgamdagal_Operator_Sync::bind($session_hash, $conversation_id);
            if (is_wp_error($binding)) { return Ulgamdagal_Service_Guard::not_sent($binding); }
        }
        if (self::operator_active($session_hash)) {
            $queued = Ulgamdagal_Operator_Sync::enqueue($session_hash, 'user:' . $api_request_id, 'user', trim($message));
            if (is_wp_error($queued)) { return $queued; }
            if ($queued && !empty($settings['log_chats'])) { self::write_log(trim($message), '', $request->get_param('session_id'), $client_ip); }
            Ulgamdagal_Operator_Sync::flush($session_hash);
            return new WP_REST_Response(array('operator_mode' => true));
        }
        $synced = Ulgamdagal_Operator_Sync::flush($session_hash);
        if (is_wp_error($synced)) { return Ulgamdagal_Service_Guard::not_sent($synced); }
        if (empty($settings['url']) || $key === '') {
            return Ulgamdagal_Service_Guard::not_sent(new WP_Error('not_configured', 'Сначала сохраните URL и ключ в настройках.', array('status' => 503)));
        }
        // Gateway contract supplied by the site owner: message -> success, answer.
        $current_message = trim($message);
        $gateway_facts = array('format' => 'ulgamdagal-facts-v1', 'public_name' => self::public_name($settings));
        $store_info = isset($settings['store_info']) && is_string($settings['store_info']) ? trim($settings['store_info']) : '';
        if ($store_info !== '') {
            $gateway_facts['store_info'] = $store_info;
        }
        $knowledge_context = self::knowledge_context(trim($message), $settings['knowledge_pages'] ?? array());
        $document_context=Ulgamdagal_Material_Documents::fragments(trim($message),$settings['documents'] ?? array());
        if($document_context!==''){$knowledge_context.=($knowledge_context!==''?"\n\n---\n\n":'').$document_context;}
        if ($knowledge_context !== '') {
            $gateway_facts['pages'] = $knowledge_context;
        }
        $catalog_ids = array();
        if (!empty($settings['woocommerce'])) {
            // Read only server-selected IDs belonging to this authenticated conversation.
            $previous_ids = $conversation_id === null ? array() : get_transient('ulgamdagal_products_' . hash('sha256', $session_hash . '|' . $conversation_id));
            $previous_ids = is_array($previous_ids) ? array_slice(array_map('absint', $previous_ids), 0, 4) : array();
            $catalog_context = self::woocommerce_context(trim($message), absint($request->get_param('product_id')), $previous_ids, $catalog_ids);
            if ($catalog_context !== '') {
                $gateway_facts['catalog'] = $catalog_context;
            }
        }
        // Server stores the original message; WordPress context is ephemeral.
        $payload = array('message' => $current_message, 'gateway_context' => wp_json_encode($gateway_facts, JSON_UNESCAPED_UNICODE), 'request_id' => $api_request_id);
        if ($conversation_id !== null) { $payload['conversation_id'] = $conversation_id; }
        $payload = apply_filters('ulgamdagal_ai_gateway_payload', $payload);
        $payload['request_id'] = $api_request_id;
        // Some hosts/plugins override WordPress HTTP timeouts to 10 seconds.
        // Scope both WordPress and cURL timeout overrides to this request only.
        add_filter('http_request_timeout', array(__CLASS__, 'gateway_timeout'), PHP_INT_MAX);
        add_action('http_api_curl', array(__CLASS__, 'gateway_curl_timeout'), PHP_INT_MAX, 3);
        $request_args = array(
            'timeout' => 60, 'redirection' => 0, 'limit_response_size' => 131072,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-API-Key' => $key),
            'body' => wp_json_encode($payload),
        );
        if (!empty($settings['woocommerce'])) { set_transient('ulgamdagal_pending_products_'.str_replace('-','',$api_request_id),$catalog_ids,2*DAY_IN_SECONDS); }
        $response = wp_safe_remote_post($settings['url'], $request_args);
        $retried_connection = false;
        // No automatic retry: a lost response can already be committed on the API.
        remove_action('http_api_curl', array(__CLASS__, 'gateway_curl_timeout'), PHP_INT_MAX);
        remove_filter('http_request_timeout', array(__CLASS__, 'gateway_timeout'), PHP_INT_MAX);
        if (is_wp_error($response)) {
            $notice = 'Сейчас не удаётся связаться с ИИ-агентом и операторами. Попробуйте немного позже.';
            return new WP_Error('gateway_unavailable', $notice, array('status' => 502));
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status === 403) {
            $error_data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($error_data) && ($error_data['detail'] ?? null) === 'client_service_paused') {
                return new WP_Error('service_paused', 'Приём сообщений временно приостановлен. Попробуйте позже.', array('status' => 403));
            }
        }
        if ($status === 429) {
            return new WP_Error('server_rate_limit', 'Достигнут серверный лимит запросов. Повторите позже.', array('status' => 429));
        }
        if ($status === 409) {
            $conflict=json_decode(wp_remote_retrieve_body($response),true);
            if (is_array($conflict) && ($conflict['detail']??'')==='request_id was already used for another message') {
                return new WP_Error('request_conflict','Идентификатор уже использован для другого сообщения. Автоматический повтор остановлен.',array('status'=>409));
            }
            return new WP_Error('request_pending', 'Сообщение ещё обрабатывается или результат требует проверки. Повторите то же сообщение позже; не начинайте новый диалог для обхода ошибки.', array('status' => 409));
        }
        if ($status < 200 || $status >= 300) {
            return new WP_Error('gateway_error', 'Чат временно недоступен. Попробуйте немного позже.', array('status' => 502));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['success']) || !is_bool($data['success'])) {
            return new WP_Error('gateway_format', 'Чат временно недоступен. Попробуйте немного позже.', array('status' => 502));
        }
        if ($data['success'] !== true) {
            return new WP_Error('gateway_failed', 'Чат временно недоступен. Попробуйте немного позже.', array('status' => 502));
        }
        return self::gateway_result($data,$settings,$conversation_id,$session_hash,$api_request_id,$catalog_ids,$message,$request,$client_ip);
    }

    private static function gateway_result($data,$settings,$conversation_id,$session_hash,$api_request_id,$catalog_ids,$message,$request,$client_ip) {
        if (!is_array($data) || ($data['success'] ?? null) !== true) {
            return new WP_Error('gateway_format','Не удалось получить сохранённый ответ.',array('status'=>502));
        }
        $reply = apply_filters('ulgamdagal_ai_gateway_reply', $data['answer'] ?? null, $data);
        if (!is_string($reply) || trim($reply) === '') {
            return new WP_Error('gateway_format', 'Чат временно недоступен. Попробуйте немного позже.', array('status' => 502));
        }
        $reply = html_entity_decode($reply, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $returned_id = $data['conversation_id'] ?? null;
        if (!is_string($returned_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $returned_id)) {
            return new WP_Error('gateway_format', 'Чат временно недоступен. Попробуйте немного позже.', array('status' => 502));
        }
        if ($conversation_id !== null && $conversation_id !== $returned_id) {
            return new WP_Error('gateway_format', 'Чат временно недоступен. Попробуйте немного позже.', array('status' => 502));
        }
        $binding = Ulgamdagal_Operator_Sync::bind($session_hash, $returned_id);
        if (is_wp_error($binding)) { return $binding; }
        $signed_token = $returned_id . '.' . hash_hmac('sha256', $session_hash . '|' . $returned_id, wp_salt('auth'));
        $result = new WP_REST_Response(array('reply' => $reply, 'conversation_token' => $signed_token, 'operator_mode' => ($data['operator_mode'] ?? false) === true));
        $result->header('Cache-Control', 'no-store');
        $done_key = 'ulgamdagal_done_' . str_replace('-', '', $api_request_id);
        if (!get_transient($done_key)) {
            if (!empty($settings['woocommerce']) && is_array($catalog_ids)) {
                set_transient('ulgamdagal_products_' . hash('sha256', $session_hash . '|' . $returned_id), $catalog_ids, DAY_IN_SECONDS);
            }
            if (!empty($settings['log_chats'])) {
                self::write_log(trim($message), !empty($data['operator_mode']) ? '' : $reply, $request->get_param('session_id'), $client_ip);
            }
            set_transient($done_key, 1, 2 * DAY_IN_SECONDS);
        }
        delete_transient('ulgamdagal_pending_products_'.str_replace('-','',$api_request_id));
        return $result;
    }

    private static function recovery_status($request, $settings, $key, $session_hash, $conversation_id, $api_request_id, $browser_request_id, $message, $client_ip) {
        // Read-only local receipt: never enqueue a second operator message during a probe.
        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare('SELECT session_hash,role,content_hash FROM ' . $wpdb->prefix . 'ulgamdagal_sync_events WHERE event_id=%s', 'user:' . $api_request_id));
        if ($wpdb->last_error) { return new WP_Error('status_unavailable', 'Проверка состояния временно недоступна.', array('status'=>503)); }
        if ($event) {
            if ($event->session_hash !== $session_hash || $event->role !== 'user' || !hash_equals($event->content_hash, hash('sha256', trim($message)))) {
                return new WP_Error('status_conflict', 'Сообщение не совпадает с ожидающим.', array('status'=>409));
            }
            $result = new WP_REST_Response(array('state'=>'completed','request_id'=>$browser_request_id,'result'=>array('operator_mode'=>true)));
            $result->header('Cache-Control','no-store'); return $result;
        }
        if (self::operator_active($session_hash)) {
            $result = new WP_REST_Response(array('state'=>'pending','request_id'=>$browser_request_id));
            $result->header('Cache-Control','no-store'); return $result;
        }
        $url = preg_replace('~/chat/?$~', '/request-status', $settings['url'] ?? '');
        if (!$key || !$url || $url === ($settings['url'] ?? '') || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return new WP_Error('status_unavailable','Проверьте подключение API.',array('status'=>503));
        }
        $payload = array('request_id'=>$api_request_id,'message'=>trim($message));
        if ($conversation_id !== null) { $payload['conversation_id']=$conversation_id; }
        $payload = apply_filters('ulgamdagal_ai_gateway_payload',$payload);
        $payload['request_id']=$api_request_id;
        $response = wp_safe_remote_post($url,array('timeout'=>12,'redirection'=>0,'limit_response_size'=>131072,
            'headers'=>array('Content-Type'=>'application/json','X-API-Key'=>$key),'body'=>wp_json_encode($payload)));
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response)===409) {
            $conflict=json_decode(wp_remote_retrieve_body($response),true);
            if (is_array($conflict) && ($conflict['detail']??'')==='Request does not match the supplied context') {
                return new WP_Error('status_conflict','Запрос не соответствует сохранённому сообщению.',array('status'=>409));
            }
        }
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) {
            return new WP_Error('status_unavailable','Проверка состояния временно недоступна.',array('status'=>503));
        }
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($data) || ($data['request_id'] ?? null)!==$api_request_id || !in_array($data['state'] ?? '',array('pending','completed','recovered','failed'),true)) {
            return new WP_Error('status_format','Не удалось подтвердить состояние.',array('status'=>502));
        }
        $out=array('state'=>$data['state'],'request_id'=>$browser_request_id);
        if ($data['state']==='completed') {
            $products=get_transient('ulgamdagal_pending_products_'.str_replace('-','',$api_request_id));
            $formatted=self::gateway_result($data['result'] ?? null,$settings,$conversation_id,$session_hash,$api_request_id,is_array($products)?$products:null,$message,$request,$client_ip);
            if (is_wp_error($formatted)) { return $formatted; }
            $out['result']=$formatted->get_data();
        }
        if ($data['state']!=='pending') { delete_transient('ulgamdagal_pending_products_'.str_replace('-','',$api_request_id)); }
        $result=new WP_REST_Response($out);$result->header('Cache-Control','no-store');return $result;
    }


    private static function session_hash($session_id) {
        if (!is_string($session_id) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $session_id)) { return ''; }
        return hash_hmac('sha256', $session_id, wp_salt('auth'));
    }

    private static function operator_active($session_hash) {
        if (Ulgamdagal_Telegram_Bridge::enabled()) { return false; }
        if ($session_hash === '') { return false; }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT active, updated_at FROM ' . $wpdb->prefix . 'ulgamdagal_ai_sessions WHERE session_hash = %s', $session_hash));
        if (!$row || !(int) $row->active) { return false; }
        $settings = get_option(self::OPTION, array());
        $timeout = max(2, min(60, absint($settings['operator_timeout'] ?? 10))) * MINUTE_IN_SECONDS;
        if ((current_time('timestamp') - strtotime($row->updated_at)) < $timeout) { return true; }
        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'ulgamdagal_ai_sessions SET active = 0, updated_at = %s WHERE session_hash = %s AND active = 1', current_time('mysql'), $session_hash));
        if ($changed) {
            $notice = 'Оператор сейчас недоступен. Диалог продолжит ИИ-агент.';
            $wpdb->insert($wpdb->prefix . 'ulgamdagal_ai_operator_messages', array('created_at' => current_time('mysql'), 'session_hash' => $session_hash, 'message' => $notice), array('%s', '%s', '%s'));
            $last_ip = (string) $wpdb->get_var($wpdb->prepare('SELECT ip_address FROM ' . self::logs_table() . ' WHERE session_hash = %s ORDER BY id DESC LIMIT 1', $session_hash));
            $wpdb->insert(self::logs_table(), array('created_at' => Ulgamdagal_Widget_Settings::storage_time(), 'session_hash' => $session_hash, 'ip_address' => filter_var($last_ip, FILTER_VALIDATE_IP) ? $last_ip : '', 'user_message' => '', 'assistant_message' => 'Система: ' . $notice), array('%s', '%s', '%s', '%s', '%s'));
        }
        return false;
    }

    public static function operator_messages($request) {
        $error=self::validate_chat_request($request);
        if (is_wp_error($error)) { return $error; }
        return Ulgamdagal_Service_Guard::run($request, 'state', function() use ($request) { return self::operator_messages_guarded($request); });
    }

    private static function operator_messages_guarded($request) {
        if (Ulgamdagal_Telegram_Bridge::enabled()) {
            $error = self::validate_chat_request($request);
            return is_wp_error($error) ? $error : Ulgamdagal_Telegram_Bridge::request($request, 'state');
        }
        $security_error = self::validate_chat_request($request);
        if (is_wp_error($security_error)) { return $security_error; }
        $hash = self::session_hash($request->get_param('session_id'));
        if ($hash === '') { return new WP_Error('invalid_session', 'Некорректный диалог.', array('status' => 400)); }
        global $wpdb;
        $after = absint($request->get_param('after_id'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, message FROM ' . $wpdb->prefix . 'ulgamdagal_ai_operator_messages WHERE session_hash = %s AND id > %d ORDER BY id ASC LIMIT 20', $hash, $after));
        $result = new WP_REST_Response(array('active' => self::operator_active($hash), 'messages' => $rows));
        $result->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $result;
    }

    private static function validate_chat_request($request) {
        $content_type = strtolower((string) $request->get_header('content-type'));
        if (strpos($content_type, 'application/json') !== 0) {
            return new WP_Error('json_required', 'Разрешены только JSON-запросы.', array('status' => 415));
        }
        if (!empty($_FILES)) {
            return new WP_Error('files_forbidden', 'Отправка файлов в чат запрещена.', array('status' => 400));
        }
        $body = (string) $request->get_body();
        if (strlen($body) > 40000) {
            return new WP_Error('request_too_large', 'Запрос слишком большой.', array('status' => 413));
        }
        return true;
    }

    private static function check_rate_limit($settings) {
        $identity = get_current_user_id() > 0 ? 'user:' . get_current_user_id() : 'ip:' . Ulgamdagal_Service_Guard::ip_identity(self::client_ip());
        return Ulgamdagal_Service_Guard::chat_rate($identity,max(1,min(30,absint($settings['rate_limit']??6))));
    }

    private static function catalog_public($id) {
        $post=get_post($id);
        if (!$post || !in_array($post->post_type,array('product','product_variation'),true) || $post->post_status!=='publish' || $post->post_password!=='') { return false; }
        if ($post->post_type==='product_variation') {
            $parent=get_post($post->post_parent);
            return $parent && $parent->post_type==='product' && $parent->post_status==='publish' && $parent->post_password==='';
        }
        return true;
    }

    private static function catalog_text($text) {
        return sanitize_text_field(html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8'));
    }

    private static function catalog_stock($product) {
        if ($product->get_stock_status() === 'onbackorder') { return 'под заказ'; }
        return $product->is_in_stock() ? 'в наличии' : 'нет в наличии';
    }

    private static function catalog_price($product) {
        return $product->get_price() === '' ? 'цена не указана' : self::catalog_text(wc_price(wc_get_price_to_display($product)));
    }

    private static function catalog_followup($message) {
        $terms = self::product_search_terms($message);
        $allowed = array('цвет', 'цвета', 'цвете', 'цветов', 'черный', 'чёрный', 'белый', 'синий', 'память', 'памяти', 'объем', 'объём', 'размер', 'размеры', 'его', 'него', 'этого', 'этот', 'этой', 'она', 'другой', 'вариант', 'варианты', 'доступен', 'доступны', 'доступно', 'наличие', 'стоимость', 'будет', 'тогда', 'есть', 'доставка', 'доставкой');
        foreach ($terms as $term) {
            if (!preg_match('/^[0-9]+(?:gb|tb)?$/iD', $term) && !in_array($term, $allowed, true)) { return false; }
        }
        return true;
    }

    private static function woocommerce_context($message, $current_product_id, $previous_ids = array(), &$selected_ids = array()) {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) { return ''; }
        $normalized = self::catalog_normalize($message);
        $terms = self::product_search_terms($normalized);
        // Model identifiers outrank brands; capacity is checked against variations below.
        $models = array_values(array_filter($terms, function ($term) {
            return preg_match('/[a-z]/i', $term) && preg_match('/[0-9]/', $term)
                && !preg_match('/^[0-9]+(?:gb|tb)$/i', $term);
        }));
        // A separated model number ('Redmi 15') is meaningful, unlike a capacity.
        $families = array('redmi', 'xiaomi', 'poco', 'samsung', 'galaxy', 'iphone', 'honor', 'huawei', 'realme', 'oppo', 'vivo', 'nokia', 'infinix', 'tecno', 'note');
        foreach ($terms as $i => $term) {
            if ($i > 0 && preg_match('/^[0-9]+$/D', $term) && in_array($terms[$i - 1], $families, true)) { $models[] = $term; }
        }
        $models = array_values(array_unique($models));
        // Search the complete normalized identity first, before broad brand matches.
        $searches = array_values(array_unique(array_merge(array(implode(' ', $terms), $normalized), $models, $terms, array($message))));
        $followup = !empty($previous_ids) && self::catalog_followup($message);
        $ids = $followup ? $previous_ids : array();
        // A capacity-only follow-up must not search unrelated products by '256'.
        if ($followup) { $searches = array(); }
        if (!$followup && $current_product_id > 0) { $ids[] = $current_product_id; }
        foreach (array_slice($searches, 0, 7) as $search) {
            $query = new WP_Query(array(
                'post_type' => 'product', 'post_status' => 'publish', 'has_password' => false, 's' => $search,
                'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true,
                'orderby' => 'relevance', 'order' => 'DESC',
            ));
            $ids = array_merge($ids, $query->posts);
            $sku_id = wc_get_product_id_by_sku($search);
            if ($sku_id) {
                $sku_product = wc_get_product($sku_id);
                if ($sku_product) { $ids[] = $sku_product->is_type('variation') ? $sku_product->get_parent_id() : $sku_id; }
            }
        }
        $ranked = array();
        foreach (array_unique($ids) as $id) {
            $product = wc_get_product($id);
            if (!self::catalog_public($id) || !$product || get_post_type($id) !== 'product' || get_post_status($id) !== 'publish' || !$product->is_visible()) { continue; }
            $haystack = self::catalog_normalize($product->get_name() . ' ' . $product->get_sku());
            $score = $id === $current_product_id ? 1 : 0;
            $matched_models = array();
            foreach ($terms as $term) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $haystack)) {
                    $score += in_array($term, $models, true) ? 100 : 5;
                    if (in_array($term, $models, true)) { $matched_models[] = $term; }
                }
            }
            // Do not substitute a different model when the visitor names one explicitly.
            if (array_diff($models, $matched_models)) { continue; }
            // Keep named families distinct: Redmi 15 must not become an iPhone 15.
            $identity = array_intersect($terms, array('redmi', 'poco', 'iphone', 'galaxy', 'honor', 'huawei', 'realme', 'oppo', 'vivo', 'nokia', 'infinix', 'tecno', 'note', 'pro', 'plus', 'ultra', 'max'));
            $identity_matches = true;
            foreach ($identity as $term) {
                if (!preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $haystack)) { $identity_matches = false; break; }
            }
            if (!$identity_matches) { continue; }
            if (in_array('redmi', $terms, true) && $models && !in_array('note', $terms, true) && preg_match('/\bnote\b/i', $haystack)) { continue; }
            $ranked[] = array('product' => $product, 'score' => $score);
        }
        usort($ranked, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $lines = array('Это ограниченная поисковая подборка, не весь каталог. Отсутствие совпадения НЕ означает отсутствие товара в магазине. Если нужная модель или вариант не подтверждены, скажи, что поиск не подтвердил наличие, и предложи уточнить ссылку или вариант. Не переноси цену и наличие одного варианта на другой.');
        $selected_ids = array();
        if ($followup) { $lines[] = 'Ниже актуальные данные товаров из предыдущего вопроса этого диалога. Уточнение объёма памяти относится к ним. Если товаров несколько и выбор неоднозначен, уточни модель.'; }
        foreach (array_slice($ranked, 0, 4) as $item) {
            $product = $item['product'];
            $selected_ids[] = $product->get_id();
            $lines[] = '- Товар: ' . self::catalog_text($product->get_name()) . '; ссылка: ' . esc_url_raw(get_permalink($product->get_id()));
            if (!$product->is_type('variable')) {
                $lines[] = 'Цена: ' . self::catalog_price($product) . '; ' . self::catalog_stock($product);
                continue;
            }
            $variants = array();
            $children = $product->get_children();
            foreach (array_slice($children, 0, 100) as $child_id) {
                $variant = wc_get_product($child_id);
                if (!self::catalog_public($child_id) || !$variant || $variant->get_status() !== 'publish' || !$variant->variation_is_visible()) { continue; }
                $attributes = self::catalog_text(wc_get_formatted_variation($variant, true, true, false));
                $score = 0;
                foreach (self::product_search_terms($attributes) as $attribute_term) {
                    if (in_array($attribute_term, $terms, true)) { $score++; }
                }
                $variants[] = array('score' => $score, 'text' => '  Вариант: ' . $attributes . '; SKU: ' . self::catalog_text($variant->get_sku()) . '; цена: ' . self::catalog_price($variant) . '; ' . self::catalog_stock($variant));
            }
            usort($variants, function ($a, $b) { return $b['score'] <=> $a['score']; });
            foreach (array_slice($variants, 0, 12) as $variant) { $lines[] = $variant['text']; }
            if (!$variants) { $lines[] = 'Доступные варианты не подтверждены; цену и наличие не утверждай.'; }
            if (count($children) > 100 || count($variants) > 12) { $lines[] = 'Показана только часть вариантов; отсутствие варианта в подборке не подтверждает его отсутствие в магазине.'; }
        }
        if (!$ranked) { $lines[] = 'Поиск не нашёл подтверждённых совпадений.'; }
        return implode("\n", $lines);
    }

    private static function catalog_normalize($message) {
        $message = (string) $message;
        $aliases = array(
            'редми|редмі' => 'redmi', 'сяоми|ксяоми|ксиаоми|сяомі' => 'xiaomi',
            'самсунг|самсунґ' => 'samsung', 'галакси|галаксі' => 'galaxy',
            'айфон|айфоны|айфона|айфоне' => 'iphone', 'эппл|эпл' => 'apple',
            'поко' => 'poco', 'хонор|онор' => 'honor', 'хуавей|хуавэй' => 'huawei',
            'реалми|рилми' => 'realme', 'оппо' => 'oppo', 'виво' => 'vivo',
            'нокиа|нокия' => 'nokia', 'инфиникс' => 'infinix', 'текно' => 'tecno',
            'ноте' => 'note', 'про' => 'pro', 'плюс' => 'plus', 'ультра' => 'ultra', 'макс' => 'max',
        );
        foreach ($aliases as $pattern => $replacement) {
            $message = preg_replace('/(?<![\p{L}\p{N}])(?:' . $pattern . ')(?![\p{L}])/iu', $replacement, $message);
        }
        $message = preg_replace('/([0-9]+)\s*(?:гб|gb)(?![\p{L}])/iu', '$1gb', $message);
        $message = preg_replace('/([0-9]+)\s*(?:тб|tb)(?![\p{L}])/iu', '$1tb', $message);
        $message = preg_replace('/\b(redmi|xiaomi|poco|samsung|galaxy|iphone|honor|huawei|realme|oppo|vivo|nokia|infinix|tecno|note)\s*-?\s*(?=[0-9])/i', '$1 ', $message);
        return function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtr($message, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private static function product_search_terms($message) {
        $message = self::catalog_normalize($message);
        $words = preg_split('/[^\p{L}\p{N}]+/u', (string) $message, -1, PREG_SPLIT_NO_EMPTY);
        $stop = array(
            'сколько', 'стоит', 'цена', 'какая', 'какой', 'какие', 'товар', 'товары', 'есть', 'наличии', 'магазине', 'вас', 'мне', 'покажи',
            'näçe', 'bahasy', 'haryt', 'harytlar', 'barmy', 'dükanda', 'görkez',
            'how', 'much', 'price', 'what', 'which', 'product', 'products', 'have', 'store', 'show', 'the', 'you',
        );
        $terms = array();
        foreach ($words as $word) {
            $word = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtr($word, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
            $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            if (($length < 3 && !preg_match('/^[a-z0-9]*[0-9][a-z0-9]*$/iD', $word)) || in_array($word, $stop, true)) { continue; }
            $terms[] = $word;
        }
        return array_slice(array_values(array_unique($terms)), 0, 12);
    }

    private static function knowledge_context($message, $page_ids) {
        if (!is_array($page_ids) || empty($page_ids)) { return ''; }
        $page_ids = array_slice(array_values(array_unique(array_map('absint', $page_ids))), 0, 20);
        $terms = preg_split('/[^\p{L}\p{N}]+/u', (string) $message, -1, PREG_SPLIT_NO_EMPTY);
        $stop = array('что', 'как', 'где', 'когда', 'какой', 'какая', 'какие', 'есть', 'ваш', 'ваша', 'ваши', 'siz', 'nähili', 'haçan', 'nirede', 'näme', 'bar', 'what', 'how', 'where', 'when', 'which', 'your', 'the', 'and');
        $terms = array_values(array_filter(array_map(function ($term) {
            return function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
        }, $terms), function ($term) use ($stop) {
            $length = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
            return $length >= 3 && !in_array($term, $stop, true);
        }));
        $ranked = array();
        foreach ($page_ids as $position => $page_id) {
            $page = get_post($page_id);
            if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish' || !empty($page->post_password)) { continue; }
            $text = Ulgamdagal_Knowledge_Sync::page_text($page,get_option(self::OPTION,array()));
            if ($text === '') { continue; }
            $haystack = (function_exists('mb_strtolower') ? mb_strtolower($page->post_title . ' ' . $text, 'UTF-8') : strtolower($page->post_title . ' ' . $text));
            $score = 0;
            foreach ($terms as $term) {
                $score += function_exists('mb_substr_count') ? mb_substr_count($haystack, $term, 'UTF-8') : substr_count($haystack, $term);
            }
            $excerpt = function_exists('mb_substr') ? mb_substr($text, 0, 2800, 'UTF-8') : substr($text, 0, 2800);
            $ranked[] = array('score' => $score, 'position' => $position, 'text' => 'Страница: ' . sanitize_text_field($page->post_title) . "\nURL: " . esc_url_raw(get_permalink($page_id)) . "\n" . $excerpt);
        }
        usort($ranked, function ($a, $b) {
            if ($a['score'] === $b['score']) { return $a['position'] <=> $b['position']; }
            return $b['score'] <=> $a['score'];
        });
        $ranked = array_slice($ranked, 0, 3);
        return implode("\n\n---\n\n", array_column($ranked, 'text'));
    }

    private static function conversation_history($history) {
        if (!is_array($history)) { return ''; }
        $history = array_slice($history, -20);
        $lines = array();
        $total_bytes = 0;
        foreach ($history as $item) {
            if (!is_array($item) || !isset($item['role'], $item['content']) || !is_string($item['role']) || !is_string($item['content'])) { continue; }
            if (!in_array($item['role'], array('user', 'assistant'), true)) { continue; }
            $content = trim(wp_strip_all_tags($item['content']));
            if ($content === '') { continue; }
            if (strlen($content) > 4000) { $content = function_exists('mb_strcut') ? mb_strcut($content, 0, 4000, 'UTF-8') : substr($content, 0, 4000); }
            if ($total_bytes + strlen($content) > 24000) { break; }
            $total_bytes += strlen($content);
            $lines[] = ($item['role'] === 'user' ? 'Посетитель: ' : 'Ассистент: ') . $content;
        }
        return implode("\n", $lines);
    }

    private static function logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'ulgamdagal_ai_logs';
    }

    public static function maybe_install_logs() {
        if (!wp_next_scheduled('ulgamdagal_ai_cleanup_logs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ulgamdagal_ai_cleanup_logs');
        }
        if (get_option('ulgamdagal_ai_db_version') === '5') { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::logs_table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            session_hash char(64) NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_message text NOT NULL,
            assistant_message longtext NOT NULL,
            conversation_id char(36) NULL,
            dialog_code char(16) NULL,
            KEY dialog_code (dialog_code),
            KEY conversation_id (conversation_id),
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY session_hash (session_hash)
        ) {$charset};");
        dbDelta("CREATE TABLE {$wpdb->prefix}ulgamdagal_ai_sessions (
            session_hash char(64) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY (session_hash)
        ) {$charset};");
        dbDelta("CREATE TABLE {$wpdb->prefix}ulgamdagal_ai_operator_messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            session_hash char(64) NOT NULL,
            message text NOT NULL,
            PRIMARY KEY (id),
            KEY session_hash (session_hash)
        ) {$charset};");
        update_option('ulgamdagal_ai_db_version', '5', false);
    }

    private static function write_log($question, $answer, $session_id, $ip_address) {
        global $wpdb;
        $session_id = is_string($session_id) && preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $session_id) ? $session_id : wp_generate_uuid4();
        $hash = hash_hmac('sha256', $session_id, wp_salt('auth'));
        $cid = $wpdb->get_var($wpdb->prepare('SELECT conversation_id FROM ' . $wpdb->prefix . 'ulgamdagal_sync_mapping WHERE session_hash=%s', $hash));
        $number = $cid ? $wpdb->get_var($wpdb->prepare('SELECT dialog_code FROM ' . self::logs_table() . ' WHERE conversation_id=%s AND dialog_code IS NOT NULL LIMIT 1', $cid)) : null;
        $wpdb->insert(self::logs_table(), array(
            'conversation_id' => $cid ?: null,
            'dialog_code' => $number ?: null,
            'created_at' => Ulgamdagal_Widget_Settings::storage_time(),
            'session_hash' => hash_hmac('sha256', $session_id, wp_salt('auth')),
            'ip_address' => filter_var($ip_address, FILTER_VALIDATE_IP) ? $ip_address : '',
            'user_message' => $question,
            'assistant_message' => $answer,
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s'));
    }

    public static function cleanup_logs() {
        global $wpdb;
        $settings = get_option(self::OPTION, array());
        $days = max(1, min(30, absint($settings['retention_days'] ?? 30)));
        $cutoff = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $log_cutoff = Ulgamdagal_Widget_Settings::storage_time(time() - ($days * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::logs_table() . ' WHERE created_at < %s', $log_cutoff));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'ulgamdagal_ai_operator_messages WHERE created_at < %s', $cutoff));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'ulgamdagal_ai_sessions WHERE updated_at < %s', $cutoff));
    }

    public static function logs_page() {
        if (!self::can_manage_chats()) { return; }
        global $wpdb;
        $table = self::logs_table();
        if (isset($_POST['ulgamdagal_delete_logs'])) {
            if (!current_user_can('manage_options')) { wp_die('Недостаточно прав.', '', array('response' => 403)); }
            check_admin_referer('ulgamdagal_delete_logs');
            $wpdb->query('TRUNCATE TABLE ' . $table);
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'ulgamdagal_ai_operator_messages');
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'ulgamdagal_ai_sessions');
            echo '<div class="notice notice-success"><p>Журнал очищен.</p></div>';
        }
        if (isset($_POST['ulgamdagal_operator_action'])) {
            if (Ulgamdagal_Telegram_Bridge::enabled()) { wp_die('На этом сайте оператор отвечает через Telegram.'); }
            check_admin_referer('ulgamdagal_operator_action');
            $hash = isset($_POST['session_hash']) ? preg_replace('/[^a-f0-9]/i', '', wp_unslash($_POST['session_hash'])) : '';
            $action = sanitize_key(wp_unslash($_POST['ulgamdagal_operator_action']));
            if (strlen($hash) === 64 && in_array($action, array('take', 'release', 'reply'), true)) {
                if ($action === 'reply') {
                    $reply = isset($_POST['operator_reply']) ? trim(sanitize_textarea_field(wp_unslash($_POST['operator_reply']))) : '';
                    if (strlen($reply) > 4000) {
                        echo '<div class="notice notice-error"><p>Ответ оператора не должен превышать 4000 байт.</p></div>';
                    } elseif ($reply !== '') {
                        $event = isset($_POST['ulgamdagal_operator_event']) ? (string) wp_unslash($_POST['ulgamdagal_operator_event']) : '';
                        if (!preg_match('/^[0-9a-f-]{36}$/iD', $event)) { wp_die('Обновите страницу журнала перед отправкой ответа.', '', array('response' => 400)); }
                        $event_id = 'operator:' . hash_hmac('sha256', $hash . '|' . $event, wp_salt('auth'));
                        $wpdb->query('START TRANSACTION');
                        $queued = Ulgamdagal_Operator_Sync::enqueue($hash, $event_id, 'operator', $reply);
                        if (is_wp_error($queued)) {
                            $wpdb->query('ROLLBACK');
                            wp_die(esc_html($queued->get_error_message()), '', array('response' => 503));
                        }
                        if ($queued) {
                            $saved_reply = $wpdb->insert($wpdb->prefix . 'ulgamdagal_ai_operator_messages', array('created_at' => current_time('mysql'), 'session_hash' => $hash, 'message' => $reply), array('%s', '%s', '%s'));
                            if ($saved_reply === false) { $wpdb->query('ROLLBACK'); wp_die('Не удалось сохранить ответ оператора.', '', array('response' => 503)); }
                        }
                        $wpdb->query('COMMIT');
                        $sync_result = Ulgamdagal_Operator_Sync::flush($hash);
                        if (is_wp_error($sync_result)) { echo '<div class="notice notice-warning"><p>Ответ сохранён. Центральная история ожидает синхронизации.</p></div>'; }
                        $wpdb->update($wpdb->prefix . 'ulgamdagal_ai_sessions', array('updated_at' => current_time('mysql')), array('session_hash' => $hash, 'active' => 1), array('%s'), array('%s', '%d'));
                        $last_ip = (string) $wpdb->get_var($wpdb->prepare('SELECT ip_address FROM ' . self::logs_table() . ' WHERE session_hash = %s ORDER BY id DESC LIMIT 1', $hash));
                        $wpdb->insert(self::logs_table(), array(
                            'created_at' => Ulgamdagal_Widget_Settings::storage_time(),
                            'session_hash' => $hash,
                            'ip_address' => filter_var($last_ip, FILTER_VALIDATE_IP) ? $last_ip : '',
                            'user_message' => '',
                            'assistant_message' => 'Ответил оператор: ' . $reply,
                        ), array('%s', '%s', '%s', '%s', '%s'));
                    }
                } else {
                    $wpdb->replace($wpdb->prefix . 'ulgamdagal_ai_sessions', array('session_hash' => $hash, 'active' => $action === 'take' ? 1 : 0, 'updated_at' => current_time('mysql')), array('%s', '%d', '%s'));
                }
            }
        }
        $date_from = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $number_filter = isset($_GET['number']) && is_string($_GET['number']) ? strtolower(trim(wp_unslash($_GET['number']))) : '';
        if (!preg_match('/^[0-9a-f]{16}$/D', $number_filter)) { $number_filter = ''; }
        $journal_notice = Ulgamdagal_Dialog_Journal::sync();
        $dialog = isset($_GET['dialog']) ? preg_replace('/[^a-f0-9]/i', '', wp_unslash($_GET['dialog'])) : '';
        $dialog = substr($dialog, 0, 64);
        $ip_filter = isset($_GET['ip']) ? trim(sanitize_text_field(wp_unslash($_GET['ip']))) : '';
        if ($ip_filter !== '' && !filter_var($ip_filter, FILTER_VALIDATE_IP)) { $ip_filter = ''; }
        $where = array('1=1');
        $params = array();
        if ($date_from !== '' && ($boundary = Ulgamdagal_Widget_Settings::boundary($date_from)) !== null) { $where[] = 'created_at >= %s'; $params[] = $boundary; }
        if ($date_to !== '' && ($boundary = Ulgamdagal_Widget_Settings::boundary($date_to, true)) !== null) { $where[] = 'created_at < %s'; $params[] = $boundary; }
        if ($number_filter !== '') { $where[] = 'dialog_code = %s'; $params[] = $number_filter; }
        if ($dialog !== '') { $where[] = 'session_hash LIKE %s'; $params[] = $wpdb->esc_like($dialog) . '%'; }
        if ($ip_filter !== '') { $where[] = 'ip_address = %s'; $params[] = $ip_filter; }
        $sql = 'SELECT id, created_at, session_hash, dialog_code, ip_address, user_message, assistant_message FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 500';
        if ($params) { $sql = $wpdb->prepare($sql, $params); }
        $rows = $wpdb->get_results($sql);
        $selected_hash = (($dialog !== '' || $number_filter !== '') && $rows) ? $rows[0]->session_hash : '';
        $operator_active = $selected_hash !== '' ? self::operator_active($selected_hash) : false;
        ?>
        <div class="wrap ulg-admin ulg-admin--logs"><header class="ulg-hero"><div><span class="ulg-eyebrow">Ulgamdagal · переписка</span><h1>Журнал AI-чата</h1><p>Вопросы клиентов, ответы ассистента и работа операторов в одном месте.</p></div><?php if (current_user_can('manage_options')) { ?><a href="<?php echo esc_url(admin_url('options-general.php?page=ulgamdagal-ai-chat')); ?>">Настройки чата →</a><?php } ?></header><p class="ulg-journal-note">Часовой пояс сообщений и фильтров: <strong><?php echo esc_html(Ulgamdagal_Widget_Settings::zone()->getName()); ?></strong></p>
        <?php if ($number_filter !== '' && !isset($_GET['_ulgamdagal_refresh'])) { Ulgamdagal_Server_History::render($number_filter); } ?>
        <?php if ($journal_notice) { ?><p role="status"><?php echo esc_html($journal_notice); ?></p><?php } ?>
        <div class="ulg-log-tools"><p>До 500 последних записей с учётом фильтров. IP берётся из REMOTE_ADDR соединения с WordPress. <span id="ulgamdagal-log-refresh-status">Автообновление включено.</span> <button type="button" class="button button-small" id="ulgamdagal-sound-toggle">Включить звук</button></p>
        <form method="get" class="ulg-filters">
        <input type="hidden" name="page" value="ulgamdagal-ai-logs">
        <label>С даты<br><input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"></label>
        <label>По дату<br><input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>"></label>
        <label>Код диалога<br><input type="text" maxlength="16" name="number" value="<?php echo esc_attr($number_filter); ?>" placeholder="3bef9ef4a7c280d1"></label>
        <label>IP<br><input type="text" name="ip" value="<?php echo esc_attr($ip_filter); ?>" placeholder="192.0.2.15"></label>
        <?php submit_button('Фильтровать', 'secondary', 'submit', false); ?>
        <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=ulgamdagal-ai-logs')); ?>">Сбросить</a>
        </form>
        </div>
        <?php if ($selected_hash !== '' && !Ulgamdagal_Telegram_Bridge::enabled()) { ?>
        <div class="card" style="max-width:none;padding:16px;margin:16px 0"><h2>Ответ оператора — диалог <?php echo esc_html($number_filter !== '' ? $number_filter : '—'); ?></h2>
        <p>Статус: <strong><?php echo $operator_active ? 'оператор ведёт диалог, AI отключён' : 'отвечает AI'; ?></strong></p>
        <form method="post"><?php wp_nonce_field('ulgamdagal_operator_action'); ?><input type="hidden" name="ulgamdagal_operator_event" value="<?php echo esc_attr(wp_generate_uuid4()); ?>"><input type="hidden" name="session_hash" value="<?php echo esc_attr($selected_hash); ?>">
        <?php if ($operator_active) { ?><textarea class="large-text" rows="3" name="operator_reply" placeholder="Ответ посетителю"></textarea><p><button class="button button-primary" name="ulgamdagal_operator_action" value="reply">Отправить ответ</button> <button class="button" name="ulgamdagal_operator_action" value="release">Вернуть диалог AI</button></p>
        <?php } else { ?><button class="button button-primary" name="ulgamdagal_operator_action" value="take">Взять диалог</button><?php } ?>
        </form></div>
        <?php } ?>
        <div class="ulg-table-scroll"><table class="widefat striped" style="margin-top:16px"><thead><tr><th>Дата</th><th>Диалог</th><th>Вопрос</th><th>Ответ</th><th>IP</th></tr></thead><tbody id="ulgamdagal-log-body">
        <?php if (!$rows) { ?><tr><td colspan="5">Записи по выбранным фильтрам не найдены.</td></tr><?php } ?>
        <?php foreach ($rows as $row) { ?>
        <tr<?php echo strpos($row->assistant_message, 'Ответил оператор: ') === 0 ? ' style="background:#eef7ff"' : ''; ?>><td><?php echo esc_html(Ulgamdagal_Widget_Settings::date($row->created_at)); ?></td><td><a href="<?php echo esc_url(add_query_arg(array('page' => 'ulgamdagal-ai-logs', 'number' => $row->dialog_code ?: null, 'dialog' => $row->dialog_code ? null : substr($row->session_hash, 0, 10)), admin_url('tools.php'))); ?>"><code><?php echo esc_html($row->dialog_code ? $row->dialog_code : '—'); ?></code></a></td><td style="white-space:pre-wrap"><?php echo esc_html(wp_html_excerpt($row->user_message, 1000, '…')); ?></td><td style="white-space:pre-wrap"><?php echo esc_html(wp_html_excerpt($row->assistant_message, 1500, '…')); ?></td><td><?php if ($row->ip_address !== '') { ?><a href="<?php echo esc_url(add_query_arg(array('page' => 'ulgamdagal-ai-logs', 'ip' => $row->ip_address), admin_url('tools.php'))); ?>"><code><?php echo esc_html($row->ip_address); ?></code></a><br><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'ulgamdagal_block_ip', 'ip' => $row->ip_address), admin_url('admin-post.php')), 'ulgamdagal_block_ip_' . $row->ip_address)); ?>" onclick="return confirm('Заблокировать этот IP для AI-чата?');">Заблокировать</a><?php } ?></td></tr>
        <?php } ?>
        </tbody></table></div>
        <?php if (current_user_can('manage_options')) { ?><details class="ulg-danger"><summary>Управление журналом</summary><p>Очистка удалит весь локальный журнал. Это действие нельзя отменить.</p><form method="post" onsubmit="return confirm('Удалить весь журнал без возможности восстановления?');">
        <?php wp_nonce_field('ulgamdagal_delete_logs'); ?>
        <input type="hidden" name="ulgamdagal_delete_logs" value="1">
        <?php submit_button('Очистить весь журнал', 'delete', 'submit', false); ?>
        </form></details>
        <?php } ?>
        </div>
        <?php
    }

    public static function block_ip_from_log() {
        if (!self::can_manage_chats()) { wp_die('Недостаточно прав.', 403); }
        $ip = isset($_GET['ip']) ? trim(sanitize_text_field(wp_unslash($_GET['ip']))) : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { wp_die('Некорректный IP.', 400); }
        check_admin_referer('ulgamdagal_block_ip_' . $ip);
        $settings = get_option(self::OPTION, array());
        $entries = isset($settings['blocked_networks']) ? preg_split('/\r\n|\r|\n/', $settings['blocked_networks']) : array();
        if (!in_array($ip, $entries, true)) { $entries[] = $ip; }
        $settings['blocked_networks'] = self::sanitize_blocklist(implode("\n", $entries));
        update_option(self::OPTION, $settings);
        wp_safe_redirect(admin_url('tools.php?page=ulgamdagal-ai-logs&blocked=1'));
        exit;
    }

    public static function chat() {
        static $rendered = false;
        if ($rendered) { return ''; }
        $settings = get_option(self::OPTION, array());
        if (!current_user_can('manage_options') && empty($settings['public_chat'])) { return ''; }
        $rendered = true;
        $floating = !empty($settings['floating_chat']);
        $assistant_name = self::public_name($settings);
        $avatar_id = absint($settings['avatar_id'] ?? 0);
        $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
        if (!$avatar_url) { $avatar_url = plugins_url('assets/avatar-default.png', __FILE__); }
        wp_enqueue_style('ulgamdagal-ai-chat', plugins_url('assets/chat.css', __FILE__), array(), '1.1.1');
        wp_enqueue_script('ulgamdagal-link-policy', plugins_url('assets/link-policy.js', __FILE__), array(), '1.1.1', true);
        wp_enqueue_script('ulgamdagal-ai-chat', plugins_url('assets/chat.js', __FILE__), array('ulgamdagal-link-policy'), '1.1.1-operator-1', true);
        ob_start(); ?>
        <div style="<?php echo esc_attr(Ulgamdagal_Chat_Appearance::style($settings['colors'] ?? array()) . Ulgamdagal_Widget_Settings::style($settings)); ?>" class="ulgamdagal-chat-shell<?php echo $floating ? ' ulgamdagal-chat-shell--floating is-closed ulgamdagal-side-' . (($settings['widget_side'] ?? '') === 'left' ? 'left' : 'right') : ''; ?>">
        <?php if ($floating) { ?><button class="ulgamdagal-launcher" type="button" aria-expanded="false" aria-label="Открыть чат"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4.75h16v11.5H9l-5 3v-14.5Z"/></svg></button><?php } ?>
        <section class="ulgamdagal-chat" data-link-hosts="<?php echo esc_attr(wp_json_encode(Ulgamdagal_Site_Policy::hosts($settings))); ?>" data-telegram="<?php echo !empty($settings['telegram_enabled']) ? '1' : '0'; ?>" data-endpoint="<?php echo esc_url(rest_url('ulgamdagal-ai/v1/chat')); ?>" data-nonce="<?php echo esc_attr(is_user_logged_in() ? wp_create_nonce('wp_rest') : ''); ?>" data-product-id="<?php echo esc_attr(function_exists('is_product') && is_product() ? get_the_ID() : 0); ?>" data-assistant-name="<?php echo esc_attr($assistant_name); ?>" data-remember="<?php echo !empty($settings['remember_chat']) ? '1' : '0'; ?>" data-history-hours="<?php echo esc_attr(max(1, min(168, absint($settings['history_hours'] ?? 24)))); ?>" aria-label="<?php echo esc_attr($assistant_name); ?>">
        <header><img class="ulgamdagal-avatar" src="<?php echo esc_url($avatar_url); ?>" alt=""><span class="ulgamdagal-identity"><h2><?php echo esc_html($assistant_name); ?></h2><small class="ulgamdagal-mode-status">Отвечает ИИ-агент</small></span><button class="ulgamdagal-clear" type="button" aria-label="Новый диалог" title="Новый диалог">↻</button><?php if ($floating) { ?><button class="ulgamdagal-close" type="button" aria-label="Закрыть чат">×</button><?php } ?></header>
        <div class="ulgamdagal-messages" role="log" aria-live="polite"></div>
        <p class="ulgamdagal-privacy">Переписка сохраняется на сервере.<?php if (!empty($settings['log_chats'])) { ?> Журнал сайта также сохраняет IP.<?php } ?> Не отправляйте пароли и платёжные данные.</p>
        <form><label class="screen-reader-text">Ваш вопрос</label><textarea name="message" rows="2" maxlength="2000" placeholder="Напишите ваш вопрос…" required></textarea><input class="ulgamdagal-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"><button type="submit">Отправить</button></form>
        <?php if (!empty($settings['telegram_enabled'])) { ?><div class="ulgamdagal-operator-name" hidden><label>Как к вам обращаться?<input class="ulgamdagal-visitor-name" type="text" maxlength="60" autocomplete="given-name" placeholder="Ваше имя"></label><small>Имя увидит оператор.</small></div><button class="ulgamdagal-request-operator" type="button">Позвать оператора</button><?php } ?>
        <p class="ulgamdagal-status" role="status"></p></section></div>
        <?php return ob_get_clean();
    }

    public static function floating_chat() {
        $settings = get_option(self::OPTION, array());
        if (!empty($settings['floating_chat'])) { echo self::chat(); }
    }

    public static function enqueue_floating_assets() {
        $settings = get_option(self::OPTION, array());
        if (empty($settings['floating_chat'])) { return; }
        if (!current_user_can('manage_options') && empty($settings['public_chat'])) { return; }
        wp_enqueue_style('ulgamdagal-ai-chat', plugins_url('assets/chat.css', __FILE__), array(), '1.1.1');
        wp_enqueue_script('ulgamdagal-link-policy', plugins_url('assets/link-policy.js', __FILE__), array(), '1.1.1', true);
        wp_enqueue_script('ulgamdagal-ai-chat', plugins_url('assets/chat.js', __FILE__), array('ulgamdagal-link-policy'), '1.1.1-operator-1', true);
    }
}
Ulgamdagal_AI_Chat::init();








