<?php
defined('ABSPATH') || exit;
final class Ulgamdagal_Knowledge_Sync {
    const EVENT = 'ulgamdagal_knowledge_sync';
    const LOCK = 'ulgamdagal_knowledge_sync_lock';
    public static function init() {
        add_filter('cron_schedules',array(__CLASS__,'intervals'));
        add_action('admin_init', array(__CLASS__, 'schedule'));
        add_action('updated_option', array(__CLASS__, 'changed'), 10, 3);
        add_action('save_post_page', array(__CLASS__, 'queue'));
        add_action('deleted_post', array(__CLASS__, 'queue'));
        add_action(self::EVENT, array(__CLASS__, 'send'));
        add_action('ulgamdagal_knowledge_sync_hourly', array(__CLASS__, 'send'));
        add_action('admin_post_ulgamdagal_knowledge_sync', array(__CLASS__, 'manual'));
    }
    public static function intervals($s) { $s['ulgamdagal_five_minutes']=array('interval'=>300,'display'=>'Every five minutes');return $s; }
    public static function schedule() {
        if (!wp_next_scheduled('ulgamdagal_knowledge_sync_hourly')) {
            wp_schedule_event(time()+60,'ulgamdagal_five_minutes','ulgamdagal_knowledge_sync_hourly');
        }
    }
    public static function changed($name,$old,$new) {
        if ($name==='ulgamdagal_ai_settings') { self::queue(); }
    }
    public static function queue() {
        if (!wp_next_scheduled(self::EVENT)) { wp_schedule_single_event(time()+10,self::EVENT); }
    }
    public static function text($value,$limit) {
        return wp_check_invalid_utf8(substr((string)$value,0,$limit),true);
    }
    public static function page_text($page,$settings) {
        $custom=$settings['knowledge_overrides'][(int)$page->ID] ?? '';
        if(is_string($custom) && $custom!=='')return self::limit_chars($custom,2800);
        $text=html_entity_decode(wp_strip_all_tags(strip_shortcodes((string)$page->post_content),true),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return self::limit_chars(preg_replace('/\s+/u',' ',trim($text)),2800);
    }
    public static function limit_chars($text,$n) {
        $chars=preg_split('//u',(string)$text,-1,PREG_SPLIT_NO_EMPTY);return is_array($chars)?implode('',array_slice($chars,0,$n)):'';
    }
    public static function payload($settings,$revision) {
        $pages=array();
        foreach (array_slice(array_unique(array_map('absint',$settings['knowledge_pages'] ?? array())),0,20) as $id) {
            $p=get_post($id);
            if (!$p || $p->post_type!=='page' || $p->post_status!=='publish' || !empty($p->post_password)) { continue; }
            $pages[]=array('id'=>(int)$id,'title'=>self::text(sanitize_text_field($p->post_title),1000),'url'=>self::text(esc_url_raw(get_permalink($id)),2000),'text'=>self::page_text($p,$settings),'custom'=>!empty($settings['knowledge_overrides'][$id]));
        }
        $payload=array('origin'=>untrailingslashit(home_url()),'revision'=>$revision,'store_info'=>self::text($settings['store_info'] ?? '',10000),'pages'=>$pages,'documents_version'=>1,'documents'=>array_values($settings['documents'] ?? array()));
        $receipt=get_option('ulgamdagal_knowledge_receipt',null);
        // Ignore malformed acknowledgements left by older versions; do not send null IDs.
        if (is_array($receipt) && isset($receipt['id'],$receipt['state']) && is_string($receipt['id']) &&
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$receipt['id']) &&
            in_array($receipt['state'],array('applied','conflict'),true)) {
            $payload['receipt']=array('id'=>$receipt['id'],'state'=>$receipt['state']);
        }
        return $payload;
    }
    private static function release($token) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",self::LOCK,$token));
        wp_cache_delete(self::LOCK,'options');
    }
    public static function send() {
        $old=get_option(self::LOCK,'');
        if ($old && (int)explode(':',$old)[0]<time()-300) { self::release($old); }
        $lock=time().':'.wp_generate_uuid4();
        if (!add_option(self::LOCK,$lock,'',false)) { self::queue(); return; }
        try {
            $s=get_option('ulgamdagal_ai_settings',array());
            $key=defined('ULGAMDAGAL_AI_API_KEY')?(string)ULGAMDAGAL_AI_API_KEY:($s['key'] ?? '');
            $url=preg_replace('~/chat/?$~','/wordpress-materials',$s['url'] ?? '');
            $ok=false;
            if ($key && $url && $url!==($s['url'] ?? '') && wp_parse_url($url,PHP_URL_SCHEME)==='https') {
                for($pass=0;$pass<2;$pass++){
                $s=get_option('ulgamdagal_ai_settings',array());
                $revision=sprintf('%.0f',max(microtime(true)*1000000,(float)get_option('ulgamdagal_knowledge_revision','0')+1));
                update_option('ulgamdagal_knowledge_revision',$revision,false);
                $ok=false;
                $response=wp_safe_remote_post($url,array('timeout'=>15,'redirection'=>0,'limit_response_size'=>2097152,'headers'=>array('Content-Type'=>'application/json','X-API-Key'=>$key),'body'=>wp_json_encode(self::payload($s,$revision),JSON_UNESCAPED_UNICODE)));
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200) {
                    $body=json_decode(wp_remote_retrieve_body($response),true);
                    $ok=is_array($body) && ($body['status'] ?? '')==='ok' && (string)($body['revision'] ?? '')===$revision;
                }
                if(!$ok)break;
                if(empty($body['edit'])){delete_option('ulgamdagal_knowledge_receipt');break;}
                self::apply_edit($body['edit'],$s);
                }
            }
            $status=get_option('ulgamdagal_knowledge_sync_status',array());
            $status['ok']=$ok;$status['attempt']=time();
            if ($ok) { $status['success']=time(); }
            update_option('ulgamdagal_knowledge_sync_status',$status,false);
            if (!$ok && !wp_next_scheduled(self::EVENT)) { wp_schedule_single_event(time()+900,self::EVENT); }
        } finally { self::release($lock); }
    }
    public static function apply_edit($edit,$observed) {
        global $wpdb;
        if(!is_array($edit) || !isset($edit['id'],$edit['before_text'],$edit['after_text']) || !is_string($edit['id']) || !preg_match('/^[0-9a-f-]{36}$/D',$edit['id']) || !is_string($edit['before_text']) || !is_string($edit['after_text']))return;
        if (!Ulgamdagal_Site_Policy::remote_allowed($observed)) {
            update_option('ulgamdagal_knowledge_receipt',array('id'=>$edit['id'],'state'=>'conflict'),false); return;
        }
        $receipt=get_option('ulgamdagal_knowledge_receipt',null);
        if(is_array($receipt) && ($receipt['id'] ?? '')===$edit['id'])return;
        if(($edit['target'] ?? '')==='document'){
            $state=Ulgamdagal_Material_Documents::apply($edit,$observed);
            update_option('ulgamdagal_knowledge_receipt',array('id'=>$edit['id'],'state'=>$state),false);return;
        }
        $state='conflict';$text=$edit['after_text'];$target=$edit['target'] ?? 'store_info';
        $before=(string)($observed['store_info'] ?? '');$new=$observed;$extra='';$args=array();$valid=true;$already=false;
        if($target==='page'){
            $id=(int)($edit['page_id'] ?? 0);$page=get_post($id);
            $valid=$page && $page->post_type==='page' && $page->post_status==='publish' && empty($page->post_password) && in_array($id,array_map('intval',$observed['knowledge_pages'] ?? array()),true);
            if($valid){
                $before=self::page_text($page,$observed);$custom=!empty($observed['knowledge_overrides'][$id]);
                $valid=$custom===(bool)($edit['before_custom'] ?? false) && self::limit_chars($text,2800)===$text;
                $already=!empty($edit['reset_source'])?!$custom:($custom && ($observed['knowledge_overrides'][$id] ?? '')===$text);
                if(!empty($edit['reset_source']))unset($new['knowledge_overrides'][$id]);else $new['knowledge_overrides'][$id]=$text;
                $extra=" AND EXISTS(SELECT 1 FROM {$wpdb->posts} WHERE ID=%d AND post_content=%s AND post_status='publish' AND post_password='')";
                $args=array($id,$page->post_content);
            }
        } elseif($target==='store_info'){$new['store_info']=$text;$already=$before===$text;}
        else {$valid=false;}
        if($already){$state='applied';}
        elseif($valid && strlen($text)<=10000 && sanitize_textarea_field($text)===$text && $before===$edit['before_text']){
            $params=array_merge(array(maybe_serialize($new),'ulgamdagal_ai_settings',maybe_serialize($observed)),$args);
            $updated=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s".$extra,$params));
            if($updated===1){
                wp_cache_delete('ulgamdagal_ai_settings','options');wp_cache_delete('alloptions','options');$state='applied';
            }
        }
        update_option('ulgamdagal_knowledge_receipt',array('id'=>$edit['id'],'state'=>$state),false);
    }
    public static function manual() {
        if (!current_user_can('manage_options')) { wp_die('Нет доступа.'); }
        check_admin_referer('ulgamdagal_knowledge_sync');
        self::send();
        wp_safe_redirect(admin_url('admin.php?page=ulgamdagal-ai-chat'));exit;
    }
    public static function render() {
        if (!current_user_can('manage_options')) { return; }
        $s=get_option('ulgamdagal_knowledge_sync_status',array());
        echo '<h2>Копия материалов для владельца сервиса</h2><p>Информация о компании и выбранные публичные страницы передаются в закрытый кабинет владельца сервиса для просмотра. Если вы разрешили удалённое редактирование в разделе защиты, владелец сервиса может изменить сведения о компании; правки сохранятся здесь, в WordPress. Для страниц можно исправить отдельный текст для ИИ. Публичные страницы не меняются. Цены и наличие берутся из каталога.</p>';
        echo '<p>Обновление после сохранения и каждые пять минут зависит от запуска WordPress Cron. Кнопка ниже отправляет сохранённые данные сразу.</p>';
        if (!empty($s['success'])) { echo '<p>Последняя успешная передача: '.esc_html(wp_date('d.m.Y H:i:s',$s['success'])).'</p>'; }
        if (isset($s['ok']) && !$s['ok']) { echo '<p>Последняя передача не подтверждена. Повторим автоматически. Можно попробовать ещё раз кнопкой ниже.</p>'; }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ulgamdagal_knowledge_sync">';
        wp_nonce_field('ulgamdagal_knowledge_sync');submit_button('Передать сохранённые материалы','secondary');echo '</form>';
    }
}
Ulgamdagal_Knowledge_Sync::init();
