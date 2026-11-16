<?php
defined('ABSPATH') || exit;
final class Ulgamdagal_Site_Policy {
    public static function normalize_ip($ip) {
        if (!is_string($ip) || !filter_var($ip,FILTER_VALIDATE_IP)) { return $ip; }
        $packed=inet_pton($ip);
        if (strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") { $packed=substr($packed,12); }
        return inet_ntop($packed);
    }
    public static function normalize_network($network) {
        $parts=explode('/',$network,2); $ip=self::normalize_ip($parts[0]);
        if (count($parts)===1) { return $ip; }
        $bits=(int)$parts[1];
        if (strpos($parts[0],':')!==false && strpos($ip,':')===false) {
            if ($bits<96) { return $network; } // Broad IPv6 networks retain their original family.
            $bits-=96;
        }
        return $ip.'/'.$bits;
    }
    public static function domains($value) {
        if (!is_string($value)) { return array(); }
        $out=array();
        foreach (preg_split('/\s+/',strtolower(trim($value))) as $host) {
            $host=rtrim($host,'.');
            if (strlen($host)<=253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)) { $out[]=$host; }
        }
        return array_slice(array_values(array_unique($out)),0,50);
    }
    public static function hosts($settings) {
        $site=strtolower(rtrim((string)wp_parse_url(home_url(),PHP_URL_HOST),'.'));
        return array_values(array_unique(array_merge(array($site),self::domains($settings['link_domains']??''))));
    }
    public static function remote_allowed($observed) {
        $current=get_option('ulgamdagal_ai_settings',array());
        return !empty($observed['allow_remote_edits']) && !empty($current['allow_remote_edits']);
    }
    public static function fields($settings) {
        ?><tr><th>Правки знаний из кабинета сервиса</th><td>
        <label><input type="checkbox" name="ulgamdagal_ai_settings[allow_remote_edits]" value="1" <?php checked(!empty($settings['allow_remote_edits'])); ?>> Разрешить удалённое редактирование знаний</label>
        <p class="description">По умолчанию выключено. Разрешает изменения сведений о компании, текстов для ИИ и материалов из кабинета владельца сервиса. Просмотр и синхронизация знаний продолжают работать. Публичные страницы не изменяются.</p></td></tr>
        <tr><th>Разрешённые домены ссылок</th><td>
        <textarea class="large-text code" rows="4" name="ulgamdagal_ai_settings[link_domains]" placeholder="t.me&#10;wa.me"><?php echo esc_textarea($settings['link_domains']??''); ?></textarea>
        <p class="description">Домен этого сайта разрешён автоматически. Дополнительные домены — по одному в строке, без https://, путей и *. Поддомены добавляются отдельно; международные имена — в punycode. До 50 доменов. Остальные ссылки в сообщениях ИИ и оператора скрываются. Адрес назначения после перенаправления на внешнем сайте плагин не проверяет.</p></td></tr><?php
    }
}
