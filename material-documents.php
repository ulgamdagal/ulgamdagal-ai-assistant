<?php
defined('ABSPATH') || exit;
final class Ulgamdagal_Material_Documents {
    public static function valid($d) {
        if(!is_array($d) || array_diff(array_keys($d),array('id','title','url','text','enabled')))return false;
        foreach(array('id','title','url','text') as $k)if(!isset($d[$k]) || !is_string($d[$k]) || strpos($d[$k],"\0")!==false || !preg_match('//u',$d[$k]))return false;
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$d['id']))return false;
        if(trim($d['title'])==='' || preg_match_all('/./us',$d['title'])>200 || trim($d['text'])==='' || preg_match_all('/./us',$d['text'])>120000 || preg_match_all('/./us',$d['url'])>2000 || !isset($d['enabled']) || !is_bool($d['enabled']))return false;
        if($d['url']!==''){$u=wp_parse_url($d['url']);if(!$u || !in_array($u['scheme']??'',array('http','https'),true) || empty($u['host']) || isset($u['user']) || isset($u['pass']) || preg_match('/\s/u',$d['url']))return false;}
        return true;
    }
    public static function valid_list($docs) {
        if(!is_array($docs) || count($docs)>20)return false;
        $ids=array();$bytes=0;
        foreach($docs as $d){if(!self::valid($d) || isset($ids[$d['id']]))return false;$ids[$d['id']]=true;$bytes+=strlen($d['text']);}
        return $bytes<=1000000;
    }
    public static function clean($input,$old) {
        if(!is_array($input))return $old;
        $byid=array();foreach($old as $d)$byid[$d['id']]=$d;
        foreach($input as $d){
            if(!is_array($d))return null;
            if(empty($d['id']) && empty($d['title']) && empty($d['text']))continue;
            $d=array('id'=>!empty($d['id'])?$d['id']:wp_generate_uuid4(),'title'=>$d['title']??'','url'=>$d['url']??'','text'=>$d['text']??'','enabled'=>!empty($d['enabled']));
            if(!self::valid($d))return null;
            $byid[$d['id']]=$d;
        }
        $docs=array_values($byid);return self::valid_list($docs)?$docs:null;
    }
    public static function apply($edit,$observed) {
        if (!Ulgamdagal_Site_Policy::remote_allowed($observed)) { return 'conflict'; }
        global $wpdb;
        $after=$edit['after_doc']??null;$before=$edit['before_doc']??null;
        if(!self::valid($after) || ($before!==null&&!self::valid($before)))return 'conflict';
        $docs=$observed['documents']??array();$current=null;$position=null;
        foreach($docs as $i=>$d)if($d['id']===$after['id']){$current=$d;$position=$i;break;}
        if($current==$after)return 'applied';
        if($current!=$before)return 'conflict';
        if($position===null)$docs[]=$after;else $docs[$position]=$after;
        if(!self::valid_list($docs))return 'conflict';
        $new=$observed;$new['documents']=array_values($docs);
        $updated=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",array(maybe_serialize($new),'ulgamdagal_ai_settings',maybe_serialize($observed))));
        if($updated!==1)return 'conflict';
        wp_cache_delete('ulgamdagal_ai_settings','options');wp_cache_delete('alloptions','options');return 'applied';
    }
    public static function render($settings) {
        $docs=$settings['documents']??array();
        echo '<h3>Материалы компании</h3><p>Название, текст и ссылка на источник. Здесь появляются перенесённые серверные материалы. Их можно менять и отключать. До 20 материалов, общий объём текста — до 1 МБ. Публичные страницы не меняются.</p>';
        if(count($docs)<20)$docs[]=array('id'=>'','title'=>'','text'=>'','url'=>'','enabled'=>true);
        foreach($docs as $i=>$d){$name='ulgamdagal_ai_settings[documents]['.$i.']';
            echo '<details style="margin:12px 0"><summary>'.esc_html($d['title']!==''?$d['title']:'Добавить материал').'</summary><input type="hidden" name="'.esc_attr($name.'[id]').'" value="'.esc_attr($d['id']).'">';
            foreach(array('title'=>'Название','url'=>'Ссылка на источник') as $k=>$caption)echo '<p><label>'.esc_html($caption).'<input class="large-text" name="'.esc_attr($name.'['.$k.']').'" value="'.esc_attr($d[$k]).'" maxlength="'.($k==='title'?200:2000).'"></label></p>';
            echo '<textarea class="large-text" rows="10" maxlength="120000" name="'.esc_attr($name.'[text]').'">'.esc_textarea($d['text']).'</textarea><p><label><input type="checkbox" name="'.esc_attr($name.'[enabled]').'" value="1" '.(!empty($d['enabled'])?'checked':'').'> Использовать в ответах</label></p></details>';
        }
    }
    public static function fragments($message,$docs) {
        // Search all chunks, including the tail of long documents; never cut the stored original.
        $terms=preg_split('/[^\p{L}\p{N}]+/u',ulgamdagal_documents_lower($message),-1,PREG_SPLIT_NO_EMPTY);
        $terms=array_slice(array_unique(array_filter($terms?:array(),function($t){return preg_match_all('/./us',$t)>=3;})),0,24);
        $rows=array();$order=0;
        foreach($docs as $d){if(empty($d['enabled']))continue;
            $parts=preg_split('/(.{1,2200})(?:\s+|$)/us',$d['text'],-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
            if(!$parts)$parts=array($d['text']);
            foreach($parts as $text){$text=trim($text);if($text==='')continue;
                // Unbroken long strings are split safely too.
                $chunks=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);
                for($start=0;$start<count($chunks);$start+=2200){$part=implode('',array_slice($chunks,$start,2200));$haystack=ulgamdagal_documents_lower($d['title'].' '.$part);$score=0;foreach($terms as $t)$score+=substr_count($haystack,$t);
                    $rows[]=array('score'=>$score,'position'=>$order++,'id'=>$d['id'],'text'=>'Материал: '.$d['title']."\nURL: ".$d['url']."\n".$part);
                }
            }
        }
        usort($rows,function($a,$b){return $a['score']===$b['score']?$a['position']<=>$b['position']:$b['score']<=>$a['score'];});
        $selected=array();$counts=array();foreach($rows as $r){if(($counts[$r['id']]??0)>=2)continue;$selected[]=$r['text'];$counts[$r['id']]=($counts[$r['id']]??0)+1;if(count($selected)>=5)break;}
        return implode("\n\n---\n\n",$selected);
    }
}
function ulgamdagal_documents_lower($text) {
    if(function_exists('mb_strtolower'))return mb_strtolower($text,'UTF-8');
    return strtr(strtr($text,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯÄÇŇÖŞÜÝŽ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюяäçňöşüýž',-1,PREG_SPLIT_NO_EMPTY)));
}
