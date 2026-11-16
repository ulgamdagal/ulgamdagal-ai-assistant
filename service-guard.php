<?php
defined('ABSPATH') || exit;

final class Ulgamdagal_Service_Guard {
    private static function error($code='service_rate', $status=429) {
        return new WP_Error($code, $status===503 ? 'Защита запросов временно недоступна. Обратитесь к администратору сайта.' : 'Подождите несколько секунд и повторите попытку.', array('status'=>$status));
    }
    private static function lock_name($scope) {
        global $wpdb;
        return 'ua35_'.substr(hash('sha256', DB_NAME.'|'.$wpdb->prefix.'|'.$scope),0,58);
    }
    private static $held=array();
    private static $shutdown=false;
    public static function ip_identity($ip) {
        $packed=is_string($ip) && filter_var($ip,FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
        if ($packed===false) { return 'unknown'; }
        if (strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") { $packed=substr($packed,12); }
        return strlen($packed)===16 ? 'v6:'.bin2hex(substr($packed,0,8)).'/64' : 'v4:'.bin2hex($packed);
    }
    private static function lock($name) {
        global $wpdb;
        $result=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name));
        if ((string)$result==='0') { return false; }
        if ((string)$result!=='1') { return self::error('service_lock_unavailable',503); }
        self::$held[$name]=true;
        if (!self::$shutdown) {
            register_shutdown_function(array(__CLASS__,'release_held'));
            self::$shutdown=true;
        }
        return true;
    }
    private static function unlock($name) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        unset(self::$held[$name]);
    }
    public static function release_held() {
        foreach (array_keys(self::$held) as $name) { self::unlock($name); }
    }
    public static function not_sent($error) {
        if (is_wp_error($error)) {
            $data=$error->get_error_data();
            if (!is_array($data)) { $data=array(); }
            $data['request_not_sent']=true;
            $error->add_data($data);
        }
        return $error;
    }
    // One slow chat per normalized IP; shared NAT visitors also share this limit.
    public static function chat($callback) {
        $visitor=self::lock_name('chat-visitor|'.self::ip_identity($_SERVER['REMOTE_ADDR']??''));
        $acquired=self::lock($visitor);
        if (is_wp_error($acquired)) { return self::not_sent($acquired); }
        if (!$acquired) { return self::not_sent(new WP_Error('chat_visitor_busy','Предыдущее сообщение ещё обрабатывается.',array('status'=>429))); }
        $slot=null;
        try {
            $maximum=defined('ULGAMDAGAL_AI_CHAT_SLOTS') && is_int(ULGAMDAGAL_AI_CHAT_SLOTS) ? max(1,min(16,ULGAMDAGAL_AI_CHAT_SLOTS)) : 4;
            for ($i=0;$i<$maximum;$i++) {
                $candidate=self::lock_name('chat-slot|'.$i);
                $acquired=self::lock($candidate);
                if (is_wp_error($acquired)) { return self::not_sent($acquired); }
                if (!$acquired) { continue; }
                $slot=$candidate;
                global $wpdb;
                if ((string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$visitor))!=='1') {
                    return self::not_sent(self::error('service_lock_unavailable',503));
                }
                return call_user_func($callback);
            }
            return self::not_sent(new WP_Error('chat_busy','Чат сейчас занят. Повторите сообщение через несколько секунд.',array('status'=>429)));
        } finally {
            if ($slot!==null) { self::unlock($slot); }
            self::unlock($visitor);
        }
    }

    public static function chat_rate($identity,$maximum) {
        $key='ua101_chat_rate_'.hash_hmac('sha256',$identity,wp_salt('auth'));
        $lock=self::lock_name($key);
        $acquired=self::lock($lock);
        if (is_wp_error($acquired)) { return $acquired; }
        if (!$acquired) { return self::error(); }
        try {
            // Older MySQL versions release the previous named lock on GET_LOCK.
            global $wpdb;
            foreach (array_keys(self::$held) as $held) {
                if ((string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$held))!=='1') {
                    return self::error('service_lock_unavailable',503);
                }
            }
            $now=time();
            $state=get_transient($key);
            if (!is_array($state) || ($state['until']??0)<=$now) {
                $state=array('until'=>$now+60,'count'=>0,'last'=>0);
            }
            if ($state['last']+2>$now || $state['count']>=$maximum) {
                return new WP_Error('rate_limit','Достигнут лимит сообщений. Подождите и повторите попытку.',array('status'=>429));
            }
            $state['count']++;
            $state['last']=$now;
            if (!set_transient($key,$state,max(1,$state['until']-$now))) {
                return self::error('service_counter_unavailable',503);
            }
            return true;
        } finally { self::unlock($lock); }
    }

    private static function budget($scope,$maximum) {
        $key='ua35_rate_'.hash_hmac('sha256',$scope,wp_salt('auth'));
        $lock=self::lock_name($key);
        $acquired=self::lock($lock);
        if (is_wp_error($acquired)) { return $acquired; }
        if (!$acquired) { return false; }
        try {
            $value=get_transient($key);
            if (!is_array($value) || ($value['until']??0)<=time()) { $value=array('until'=>time()+60,'count'=>0); }
            if ($value['count'] >= $maximum) { return false; }
            $value['count']++;
            return set_transient($key,$value,max(1,$value['until']-time())) ? true : self::error('service_counter_unavailable',503);
        } finally { self::unlock($lock); }
    }
    public static function run($request,$action,$callback) {
        if (!in_array($action,array('state','close','request','status'),true)) { return self::error('invalid_action',400); }
        // Never trust forwarded IP headers without an explicitly configured proxy.
        $ip=$_SERVER['REMOTE_ADDR']??'';
        $identity=self::ip_identity($ip);
        $budget=self::budget('ip|'.$identity.'|'.$action,$action==='state'?180:($action==='status'?60:30));
        if (is_wp_error($budget)) { return $budget; }
        if (!$budget) { return self::error(); }
        $session=$request->get_param('session_id');
        $token=$request->get_param('conversation_token');
        if (!is_string($session) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$session)) { return self::error('invalid_session',400); }
        $hash=hash_hmac('sha256',$session,wp_salt('auth'));
        $parts=array('', 'initial');
        if ($action!=='status' || ($token!==null && $token!=='')) {
            if (!is_string($token) || !preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/D',$token,$parts) ||
                !hash_equals(hash_hmac('sha256',$hash.'|'.$parts[1],wp_salt('auth')),$parts[2])) { return self::error('invalid_conversation',403); }
        }
        $status_key='';
        if ($action==='status') {
            $rid=$request->get_param('request_id'); $message=$request->get_param('message');
            if (!is_string($rid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$rid) || !is_string($message) || trim($message)==='' || strlen($message)>32000) { return self::error('invalid_status',400); }
            // A lost first response has no conversation token yet. Its random request ID and
            // original fingerprint are checked by the gateway; never create a new request here.
            $status_key=hash('sha256',$rid.'|'.$message.'|'.($token??''));
        }
        $after=$request->get_param('after_id');
        if ($after===null) { $after=0; }
        if (!is_int($after) || $after<0 || $after>9007199254740991) { return self::error('invalid_cursor',400); }
        $budget=self::budget('session|'.$hash.'|'.$action,$action==='state'?30:($action==='status'?12:6));
        if (is_wp_error($budget)) { return $budget; }
        if (!$budget) { return self::error(); }
        $lock=self::lock_name('session|'.$hash);
        $acquired=self::lock($lock);
        if (is_wp_error($acquired)) { return $acquired; }
        if (!$acquired) { return self::error('service_busy'); }
        $slot=null;
        try {
            $generation=get_transient('ua35_generation_'.$hash) ?: '0';
            $cache='ua35_response_'.hash('sha256',$action.'|'.$hash.'|'.$parts[1].'|'.$after.'|'.$generation.'|'.$status_key);
            $cached=get_transient($cache);
            if (in_array($action,array('state','status'),true) && $cached!==false) { return self::response($cached); }
            // Separate pools: two recovery callbacks and six operator/close callbacks.
            for ($i=0;$i<($action==='status'?2:6);$i++) {
                $candidate=self::lock_name(($action==='status'?'recovery-slot|':'slot|').$i);
                $acquired=self::lock($candidate);
                if (is_wp_error($acquired)) { return $acquired; }
                if ($acquired) { $slot=$candidate; break; }
            }
            if ($slot===null) { return self::error('service_busy'); }
            global $wpdb;
            // Fail closed if this database cannot hold more than one named lock.
            if ((string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $lock))!=='1') {
                return self::error('service_lock_unavailable',503);
            }
            $result=call_user_func($callback);
            if ($result instanceof WP_REST_Response) {
                $data=$action==='status' ? $result->get_data() : self::filter($result->get_data(),$action,$parts[1]);
                if (is_wp_error($data)) { $result=$data; }
                else { $result=self::response($data); }
            }
            if (in_array($action,array('state','status'),true)) {
                // Cache failures briefly as well: an unavailable gateway must not cause a retry storm.
                set_transient($cache,is_wp_error($result)?$result:$result->get_data(),is_wp_error($result)?2:($action==='status'?5:3));
            } elseif (!is_wp_error($result)) {
                set_transient('ua35_generation_'.$hash,wp_generate_uuid4(),120);
            }
            return $result;
        } finally {
            if ($slot!==null) { self::unlock($slot); }
            self::unlock($lock);
        }
    }
    private static function response($data) {
        if (is_wp_error($data)) { return $data; }
        $response=new WP_REST_Response($data);
        $response->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }
    public static function filter($data,$action,$conversation) {
        $invalid=new WP_Error('invalid_service_response','Некорректный ответ сервера оператора.',array('status'=>502));
        if (!is_array($data)) { return $invalid; }
        if ($action==='close') {
            if (($data['closed']??null)!==true || ($data['conversation_id']??null)!==$conversation) { return $invalid; }
            return array('closed'=>true,'conversation_id'=>$conversation);
        }
        if ($action==='request') {
            if (($data['success']??null)!==true || !in_array($data['state']??null,array('waiting','active'),true)) { return $invalid; }
            return array('success'=>true,'state'=>$data['state']);
        }
        if (!isset($data['active'],$data['messages']) || !is_bool($data['active']) || !is_array($data['messages']) || count($data['messages'])>100) { return $invalid; }
        if (isset($data['waiting']) && !is_bool($data['waiting'])) { return $invalid; }
        $messages=array();
        foreach ($data['messages'] as $row) {
            $row=(array)$row;
            $id=$row['id']??null;
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (float)$id<1 || (float)$id>9007199254740991 || !isset($row['message']) || !is_string($row['message']) || strlen($row['message'])>64000) { return $invalid; }
            $messages[]=array('id'=>(int)$id,'message'=>$row['message'],'system'=>($row['system']??false)===true);
        }
        return array('active'=>$data['active'],'waiting'=>$data['waiting']??false,'messages'=>$messages);
    }
}
